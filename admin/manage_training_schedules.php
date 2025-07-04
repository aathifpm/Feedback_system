<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';
require_once 'includes/admin_functions.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

$success = '';
$error = '';
$edit_id = null;
$session = null;
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get current academic year
$query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$result = mysqli_query($conn, $query);
$academic_year = mysqli_fetch_assoc($result);
$academic_year_id = $academic_year['id'];

// Process add/edit form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $training_batch_id = $_POST['training_batch_id'];
    $venue_id = $_POST['venue_id'];
    $session_date = $_POST['session_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $recurring = isset($_POST['recurring']) ? $_POST['recurring'] : 'no';
    $repeat_until = isset($_POST['repeat_until']) ? $_POST['repeat_until'] : null;
    $topic = $_POST['topic'];
    $trainer_name = $_POST['trainer_name'];
    $is_cancelled = isset($_POST['is_cancelled']) ? 1 : 0;
    $skip_holidays = isset($_POST['skip_holidays']) ? 1 : 0;
    
    // Get batch information for holiday checking
    $batch_query = "SELECT tb.id, tb.department_id FROM training_batches tb WHERE tb.id = ?";
    $stmt = mysqli_prepare($conn, $batch_query);
    mysqli_stmt_bind_param($stmt, "i", $training_batch_id);
    mysqli_stmt_execute($stmt);
    $batch_result = mysqli_stmt_get_result($stmt);
    $batch_info = mysqli_fetch_assoc($batch_result);
    $department_id = $batch_info['department_id'];
    $batch_id = $batch_info['id'];
    
    // Validate time slots
    if (strtotime($end_time) <= strtotime($start_time)) {
        $error = "End time must be after start time";
    } else {
        // Check for conflicts (unless currently editing this same schedule)
        $conflict_query = "SELECT tss.* 
                          FROM training_session_schedule tss
                          JOIN venues v ON tss.venue_id = v.id
                          WHERE tss.session_date = ? 
                          AND tss.venue_id = ?
                          AND tss.is_cancelled = 0
                          AND ((tss.start_time < ? AND tss.end_time > ?) 
                               OR (tss.start_time < ? AND tss.end_time > ?)
                               OR (tss.start_time >= ? AND tss.end_time <= ?))";
        
        if (isset($_POST['id'])) {
            $conflict_query .= " AND tss.id != ?";
            $stmt = mysqli_prepare($conn, $conflict_query);
            mysqli_stmt_bind_param($stmt, "sissssssi", $session_date, $venue_id, $end_time, $start_time, 
                                 $start_time, $start_time, $start_time, $end_time, $_POST['id']);
        } else {
            $stmt = mysqli_prepare($conn, $conflict_query);
            mysqli_stmt_bind_param($stmt, "sissssss", $session_date, $venue_id, $end_time, $start_time, 
                                 $start_time, $start_time, $start_time, $end_time);
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $conflict = mysqli_fetch_assoc($result);
            $error = "Time slot conflict with another session in the same venue at " . 
                     date('h:i A', strtotime($conflict['start_time'])) . " - " . 
                     date('h:i A', strtotime($conflict['end_time']));
        } else {
            // Check if date is a holiday
            $holiday_info = is_holiday($conn, $session_date, $department_id, $batch_id);
            
            if ($holiday_info && !$skip_holidays && !isset($_POST['id'])) {
                $error = "Warning: " . $session_date . " is a holiday (" . $holiday_info['holiday_name'] . "). 
                        <form method='post' action=''>
                            <input type='hidden' name='training_batch_id' value='" . $training_batch_id . "'>
                            <input type='hidden' name='venue_id' value='" . $venue_id . "'>
                            <input type='hidden' name='session_date' value='" . $session_date . "'>
                            <input type='hidden' name='start_time' value='" . $start_time . "'>
                            <input type='hidden' name='end_time' value='" . $end_time . "'>
                            <input type='hidden' name='recurring' value='" . $recurring . "'>
                            <input type='hidden' name='repeat_until' value='" . $repeat_until . "'>
                            <input type='hidden' name='topic' value='" . $topic . "'>
                            <input type='hidden' name='trainer_name' value='" . $trainer_name . "'>
                            <input type='hidden' name='is_cancelled' value='" . $is_cancelled . "'>
                            <input type='hidden' name='skip_holidays' value='1'>
                            <button type='submit' class='btn btn-warning'>Schedule Anyway</button>
                        </form>";
            } else {
                // If no conflicts or holidays (or holiday override), proceed with save/update
                if (isset($_POST['id'])) {
                    // Update existing session
                    $query = "UPDATE training_session_schedule 
                              SET training_batch_id = ?, venue_id = ?, session_date = ?, 
                                  start_time = ?, end_time = ?, topic = ?, trainer_name = ?, is_cancelled = ?
                              WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iisssssii", $training_batch_id, $venue_id, $session_date, 
                                         $start_time, $end_time, $topic, $trainer_name, $is_cancelled, $_POST['id']);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success = "Training session updated successfully";
                        $filter_date = $session_date; // Set filter to the edited date
                    } else {
                        $error = "Failed to update session: " . mysqli_error($conn);
                    }
                } else {
                    // Create new session(s)
                    if ($recurring !== 'no' && $repeat_until) {
                        $current_date = new DateTime($session_date);
                        $end_date = new DateTime($repeat_until);
                        $success_count = 0;
                        $conflict_count = 0;
                        $conflict_dates = [];
                        $created_dates = [];
                        $skipped_holidays = 0;
                        $holiday_dates = [];
                        
                        // Get all holidays in the range
                        $holidays = get_holidays_in_range($conn, $session_date, $repeat_until, $department_id, $batch_id);
                        $holiday_date_map = [];
                        foreach ($holidays as $holiday) {
                            $holiday_date_map[$holiday['holiday_date']] = $holiday;
                        }

                        // Calculate the difference in days between start and end date
                        $date_diff = $current_date->diff($end_date);
                        $days_between = $date_diff->days;
                        error_log("Days between start and end date: " . $days_between);
                        
                        // Create the initial session first
                        $initial_date = $current_date->format('Y-m-d');
                        
                        // Check if initial date is a holiday
                        if (!$skip_holidays && isset($holiday_date_map[$initial_date])) {
                            $skipped_holidays++;
                            $holiday_dates[] = $initial_date;
                            error_log("Initial date is a holiday: " . $initial_date);
                        } else {
                            // Check for conflicts on the initial date
                            $conflict_query = "SELECT id FROM training_session_schedule 
                                               WHERE session_date = ? 
                                               AND venue_id = ?
                                               AND is_cancelled = 0
                                               AND ((start_time < ? AND end_time > ?) 
                                                   OR (start_time < ? AND end_time > ?)
                                                   OR (start_time >= ? AND end_time <= ?))";
                            
                            $stmt = mysqli_prepare($conn, $conflict_query);
                            mysqli_stmt_bind_param($stmt, "sissssss", $initial_date, $venue_id, $end_time, $start_time, 
                                                 $start_time, $start_time, $start_time, $end_time);
                            mysqli_stmt_execute($stmt);
                            $conflict_result = mysqli_stmt_get_result($stmt);
                            
                            if (mysqli_num_rows($conflict_result) == 0) {
                                $query = "INSERT INTO training_session_schedule 
                                          (training_batch_id, venue_id, session_date, start_time, end_time, topic, trainer_name, is_cancelled) 
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                $stmt = mysqli_prepare($conn, $query);
                                mysqli_stmt_bind_param($stmt, "iisssssi", $training_batch_id, $venue_id, $initial_date, 
                                                    $start_time, $end_time, $topic, $trainer_name, $is_cancelled);
                                
                                if (mysqli_stmt_execute($stmt)) {
                                    $success_count++;
                                    $created_dates[] = $initial_date;
                                    error_log("Created initial session for date: " . $initial_date);
                                } else {
                                    error_log("Failed to create initial session. Error: " . mysqli_error($conn));
                                }
                            } else {
                                $conflict_count++;
                                $conflict_dates[] = $initial_date;
                                error_log("Conflict found for initial date: " . $initial_date);
                            }
                        }
                        
                        // Now create all the recurring sessions
                        // Determine the interval based on recurrence type
                        $interval_days = ($recurring === 'daily') ? 1 : 7;
                        
                        // Move to the next day/week from initial date
                        $current_date->modify("+{$interval_days} days");
                        
                        // Continue creating sessions until we reach or exceed the end date
                        while ($current_date <= $end_date) {
                            $formatted_date = $current_date->format('Y-m-d');
                            
                            // Check if this date is a holiday
                            if (!$skip_holidays && isset($holiday_date_map[$formatted_date])) {
                                $skipped_holidays++;
                                $holiday_dates[] = $formatted_date;
                                error_log("Skipping holiday date: " . $formatted_date);
                            } else {
                                // Check for conflicts on this specific date
                                $conflict_query = "SELECT id FROM training_session_schedule 
                                                  WHERE session_date = ? 
                                                  AND venue_id = ?
                                                  AND is_cancelled = 0
                                                  AND ((start_time < ? AND end_time > ?) 
                                                      OR (start_time < ? AND end_time > ?)
                                                      OR (start_time >= ? AND end_time <= ?))";
                                
                                $stmt = mysqli_prepare($conn, $conflict_query);
                                mysqli_stmt_bind_param($stmt, "sissssss", $formatted_date, $venue_id, $end_time, $start_time, 
                                                    $start_time, $start_time, $start_time, $end_time);
                                mysqli_stmt_execute($stmt);
                                $conflict_result = mysqli_stmt_get_result($stmt);
                                
                                // Only insert if there's no conflict
                                if (mysqli_num_rows($conflict_result) == 0) {
                                    $query = "INSERT INTO training_session_schedule 
                                              (training_batch_id, venue_id, session_date, start_time, end_time, topic, trainer_name, is_cancelled) 
                                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                                    $stmt = mysqli_prepare($conn, $query);
                                    mysqli_stmt_bind_param($stmt, "iisssssi", $training_batch_id, $venue_id, $formatted_date, 
                                                        $start_time, $end_time, $topic, $trainer_name, $is_cancelled);
                                    
                                    if (mysqli_stmt_execute($stmt)) {
                                        $success_count++;
                                        $created_dates[] = $formatted_date;
                                        error_log("Created session for date: " . $formatted_date);
                                    } else {
                                        error_log("Failed to create session for date: " . $formatted_date . ". Error: " . mysqli_error($conn));
                                    }
                                } else {
                                    $conflict_count++;
                                    $conflict_dates[] = $formatted_date;
                                    error_log("Conflict found for date: " . $formatted_date);
                                }
                            }
                            
                            // Move to next interval
                            $current_date->modify("+{$interval_days} days");
                        }
                        
                        // Log all created dates
                        error_log("Successfully created sessions for dates: " . implode(', ', $created_dates));
                        
                        // Build success message
                        if ($success_count > 0) {
                            $success = "$success_count training sessions scheduled successfully";
                            if ($conflict_count > 0) {
                                $success .= " ($conflict_count date(s) skipped due to conflicts)";
                            }
                            if ($skipped_holidays > 0) {
                                $success .= " ($skipped_holidays date(s) skipped due to holidays)";
                            }
                        } else {
                            $error = "Failed to schedule any sessions.";
                            if ($conflict_count > 0) {
                                $error .= " $conflict_count date(s) had conflicts.";
                            }
                            if ($skipped_holidays > 0) {
                                $error .= " $skipped_holidays date(s) were holidays.";
                            }
                        }
                        
                        $recurrence_type = ($recurring === 'daily') ? 'daily' : 'weekly';
                        $success = "$success_count recurring $recurrence_type sessions scheduled successfully";
                        
                        if ($conflict_count > 0) {
                            $success .= " ($conflict_count sessions skipped due to conflicts)";
                        }
                        if ($skipped_holidays > 0) {
                            $success .= " ($skipped_holidays sessions skipped due to holidays)";
                        }
                        $filter_date = $session_date; // Set filter to the first date
                    } else {
                        // Single session
                        $query = "INSERT INTO training_session_schedule 
                                  (training_batch_id, venue_id, session_date, start_time, end_time, topic, trainer_name, is_cancelled) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "iisssssi", $training_batch_id, $venue_id, $session_date, 
                                            $start_time, $end_time, $topic, $trainer_name, $is_cancelled);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success = "Training session scheduled successfully";
                            $filter_date = $session_date; // Set filter to the new date
                        } else {
                            $error = "Failed to schedule session: " . mysqli_error($conn);
                        }
                    }
                }
            }
        }
    }
}

// Handle edit request
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    
    $query = "SELECT tss.*, tb.batch_name, v.name as venue_name
              FROM training_session_schedule tss
              JOIN training_batches tb ON tss.training_batch_id = tb.id
              JOIN venues v ON tss.venue_id = v.id
              WHERE tss.id = ?";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $session = $row;
    }
}

// Handle cancel/restore request
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $toggle_id = $_GET['toggle_status'];
    
    $query = "SELECT is_cancelled, session_date FROM training_session_schedule WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $toggle_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $new_status = $row['is_cancelled'] ? 0 : 1;
        $status_text = $new_status ? "cancelled" : "restored";
        $session_date = $row['session_date'];
        
        $query = "UPDATE training_session_schedule SET is_cancelled = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $new_status, $toggle_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success = "Session $status_text successfully";
            $filter_date = $session_date;
        } else {
            $error = "Failed to update session status: " . mysqli_error($conn);
        }
    }
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    
    // Get the session date first for filtering
    $query = "SELECT session_date FROM training_session_schedule WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $delete_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $session_date = $row['session_date'];
        
        // First delete any associated attendance records
        $query = "DELETE FROM training_attendance_records WHERE session_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $delete_id);
        
        if (mysqli_stmt_execute($stmt)) {
            // Then delete the schedule
            $query = "DELETE FROM training_session_schedule WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $delete_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $success = "Training session deleted successfully";
                $filter_date = $session_date;
            } else {
                $error = "Failed to delete session: " . mysqli_error($conn);
            }
        } else {
            $error = "Failed to delete associated attendance records: " . mysqli_error($conn);
        }
    }
}

// Get all training batches
$query = "SELECT tb.id, tb.batch_name, tb.description
          FROM training_batches tb
          WHERE tb.academic_year_id = ? AND tb.is_active = TRUE
          ORDER BY tb.batch_name";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $academic_year_id);
mysqli_stmt_execute($stmt);
$batches_result = mysqli_stmt_get_result($stmt);

$batches = [];
while ($row = mysqli_fetch_assoc($batches_result)) {
    $batches[] = $row;
}

// Get all venues
$query = "SELECT id, name, room_number, capacity FROM venues ORDER BY name";
$venues_result = mysqli_query($conn, $query);
$venues = [];
while ($row = mysqli_fetch_assoc($venues_result)) {
    $venues[] = $row;
}

// Get sessions for the selected date
$query = "SELECT tss.id, tss.session_date, tss.start_time, tss.end_time, 
                 tss.topic, tss.trainer_name, tss.is_cancelled,
                 tb.batch_name, tb.description,
                 v.name as venue_name, v.room_number
          FROM training_session_schedule tss
          JOIN training_batches tb ON tss.training_batch_id = tb.id
          JOIN venues v ON tss.venue_id = v.id
          WHERE tss.session_date = ?
          ORDER BY tss.start_time";
          
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$sessions = [];
while ($row = mysqli_fetch_assoc($result)) {
    $sessions[] = $row;
}

// Navigation to previous and next days
$prev_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($filter_date . ' +1 day'));

// Get holiday for the current filter date
$holiday = is_holiday($conn, $filter_date, null, null);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Training Schedules - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-color: #27ae60;  /* Green theme for Training */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --header-height: 90px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            min-height: 100vh;
            padding-top: var(--header-height);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px; /* Add margin for fixed sidebar */
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
            }
        }

        .container-fluid {
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px;
        }

        @media (max-width: 768px) {
            .container-fluid {
                margin-left: 0;
            }
        }

        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
            margin: 0;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background-color: #219653;
            border-color: #219653;
        }

        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
            color: white;
        }

        .btn-success:hover {
            background-color: #17a673;
            border-color: #17a673;
        }

        .btn-info {
            background-color: #36b9cc;
            border-color: #36b9cc;
            color: white;
        }

        .btn-info:hover {
            background-color: #2c9faf;
            border-color: #2c9faf;
        }

        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
            color: white;
        }

        .btn-danger:hover {
            background-color: #be2617;
            border-color: #be2617;
        }

        .btn-secondary {
            background-color: #858796;
            border-color: #858796;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #717384;
            border-color: #717384;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
        }

        .btn-outline-success {
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            background-color: transparent;
        }

        .btn-outline-success:hover {
            background-color: var(--primary-color);
            color: white;
        }

        .btn-outline-info {
            color: #36b9cc;
            border: 1px solid #36b9cc;
            background-color: transparent;
        }

        .btn-outline-info:hover {
            background-color: #36b9cc;
            color: white;
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            background: var(--bg-color);
            overflow: hidden;
        }

        .card-header {
            background-color: var(--bg-color);
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h6 {
            margin: 0;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23555' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .form-row .form-group {
            flex: 1;
            min-width: 200px;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        /* Session Timeline Styling */
        .timeline {
            position: relative;
            padding: 1rem 0;
        }

        .timeline-item {
            position: relative;
            padding-left: 50px;
            margin-bottom: 1.5rem;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-item:before {
            content: "";
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(39, 174, 96, 0.3);
        }

        .timeline-badge {
            position: absolute;
            left: 10px;
            top: 15px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary-color);
            border: 3px solid var(--bg-color);
            box-shadow: var(--shadow);
            z-index: 1;
        }

        .badge-cancelled {
            background-color: #e74a3b !important;
        }

        .session-item {
            border-radius: 15px;
            margin-bottom: 10px;
            padding: 1.5rem;
            background-color: var(--bg-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }

        .session-item:hover {
            transform: translateY(-5px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .cancelled {
            background-color: rgba(231, 74, 59, 0.1) !important;
            border-left-color: #e74a3b !important;
            text-decoration: line-through;
        }

        .time-badge {
            font-size: 0.8rem;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            background-color: var(--primary-color);
            color: white;
            box-shadow: var(--shadow);
            display: inline-block;
        }

        .cancelled .time-badge {
            background-color: #e74a3b;
        }

        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .session-title {
            margin: 0;
            font-weight: 600;
            color: var(--text-color);
        }

        .session-actions {
            display: flex;
            gap: 0.5rem;
        }

        .session-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--text-color);
        }

        /* Navigation Controls */
        .date-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .date-navigation .btn-group {
            display: flex;
            gap: 0.5rem;
        }

        /* Custom Switch Styling */
        .custom-control-input {
            position: absolute;
            left: -9999px;
        }

        .custom-control-label {
            position: relative;
            margin-bottom: 0;
            vertical-align: top;
            padding-left: 2.5rem;
            cursor: pointer;
            display: inline-block;
            line-height: 1.5;
        }

        .custom-control-label::before {
            position: absolute;
            top: 0.25rem;
            left: 0;
            display: block;
            width: 2rem;
            height: 1rem;
            background-color: #e0e5ec;
            border-radius: 1rem;
            box-shadow: var(--inner-shadow);
            content: "";
            transition: background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .custom-control-label::after {
            position: absolute;
            top: 0.25rem;
            left: 0.25rem;
            display: block;
            width: 1rem;
            height: 1rem;
            content: "";
            background: #fff;
            border-radius: 1rem;
            box-shadow: var(--shadow);
            transition: transform 0.15s ease-in-out, background-color 0.15s ease-in-out;
        }

        .custom-control-input:checked ~ .custom-control-label::before {
            background-color: var(--primary-color);
        }

        .custom-control-input:checked ~ .custom-control-label::after {
            transform: translateX(1rem);
            background-color: #fff;
        }

        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .row {
                flex-direction: column;
            }
            .col-lg-4, .col-lg-8 {
                width: 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .session-header {
                flex-direction: column;
                gap: 1rem;
            }
            .session-actions {
                width: 100%;
                justify-content: flex-end;
            }
        }

        /* Overlay for modals */
        .modal-backdrop {
            background: rgba(44, 62, 80, 0.5);
            backdrop-filter: blur(4px);
        }

        /* Custom Row Styles */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }

        .col-lg-4, .col-lg-8 {
            position: relative;
            width: 100%;
            padding-right: 15px;
            padding-left: 15px;
        }

        @media (min-width: 992px) {
            .col-lg-4 {
                flex: 0 0 33.333333%;
                max-width: 33.333333%;
            }
            .col-lg-8 {
                flex: 0 0 66.666667%;
                max-width: 66.666667%;
            }
        }

        /* Close button style */
        .close {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Manage Training Schedules</h1>
            <a href="manage_schedules.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <?php if ($holiday): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-calendar-times"></i> <strong>Holiday Alert:</strong> 
                <?php echo htmlspecialchars($holiday['holiday_name']); ?> on <?php echo date('d M Y', strtotime($filter_date)); ?>
                <?php if (!empty($holiday['description'])): ?>
                    - <?php echo htmlspecialchars($holiday['description']); ?>
                <?php endif; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Add/Edit Session Form -->
            <div class="col-lg-4">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold">
                            <?php echo $edit_id ? 'Edit Training Session' : 'Add New Training Session'; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <form action="" method="post">
                            <?php if ($edit_id): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="training_batch_id">Training Batch:</label>
                                <select name="training_batch_id" id="training_batch_id" class="form-control" required>
                                    <option value="">Select Training Batch</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?php echo $batch['id']; ?>" 
                                            <?php echo ($edit_id && $session['training_batch_id'] == $batch['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($batch['batch_name'] . 
                                                                    (!empty($batch['description']) ? ' - ' . $batch['description'] : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="venue_id">Venue:</label>
                                <select name="venue_id" id="venue_id" class="form-control" required>
                                    <option value="">Select Venue</option>
                                    <?php foreach ($venues as $venue): ?>
                                        <option value="<?php echo $venue['id']; ?>"
                                            <?php echo ($edit_id && $session['venue_id'] == $venue['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($venue['name'] . 
                                                                    (!empty($venue['room_number']) ? ' (Room ' . $venue['room_number'] . ')' : '') . 
                                                                    ' - Capacity: ' . $venue['capacity']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="session_date">Date:</label>
                                <input type="date" name="session_date" id="session_date" class="form-control" 
                                       value="<?php echo $edit_id ? $session['session_date'] : $filter_date; ?>" required>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="start_time">Start Time:</label>
                                    <input type="time" name="start_time" id="start_time" class="form-control" 
                                           value="<?php echo $edit_id ? $session['start_time'] : ''; ?>" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="end_time">End Time:</label>
                                    <input type="time" name="end_time" id="end_time" class="form-control" 
                                           value="<?php echo $edit_id ? $session['end_time'] : ''; ?>" required>
                                </div>
                            </div>
                            
                            <?php if (!$edit_id): ?>
                                <div class="form-group">
                                    <label for="recurring">Recurring Schedule:</label>
                                    <select name="recurring" id="recurring" class="form-control">
                                        <option value="no">No (Single Session)</option>
                                        <option value="daily">Daily (Every Day)</option>
                                        <option value="weekly">Weekly (Every 7 Days)</option>
                                    </select>
                                </div>
                                
                                <div class="form-group" id="repeatUntilGroup" style="display: none;">
                                    <label for="repeat_until">Repeat Until:</label>
                                    <input type="date" name="repeat_until" id="repeat_until" class="form-control" 
                                           value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="topic">Topic:</label>
                                <input type="text" name="topic" id="topic" class="form-control" 
                                       value="<?php echo $edit_id ? htmlspecialchars($session['topic']) : ''; ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="trainer_name">Trainer Name:</label>
                                <input type="text" name="trainer_name" id="trainer_name" class="form-control" 
                                       value="<?php echo $edit_id ? htmlspecialchars($session['trainer_name']) : ''; ?>" required>
                            </div>
                            
                            <?php if ($edit_id): ?>
                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="is_cancelled" name="is_cancelled" 
                                               <?php echo ($edit_id && $session['is_cancelled']) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="is_cancelled">Mark as Cancelled</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <div class="custom-control custom-switch">
                                    <input type="checkbox" class="custom-control-input" id="skip_holidays" name="skip_holidays" value="1">
                                    <label class="custom-control-label" for="skip_holidays">Schedule on holidays</label>
                                    <small class="form-text text-muted">Check this to schedule training sessions even on holidays</small>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $edit_id ? 'Update Session' : 'Create Session'; ?>
                                </button>
                                <?php if ($edit_id): ?>
                                    <a href="manage_training_schedules.php?date=<?php echo $filter_date; ?>" class="btn btn-secondary">
                                        Cancel Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Session List -->
            <div class="col-lg-8">
                <div class="card shadow">
                    <div class="card-header">
                        <h6 class="m-0 font-weight-bold">
                            Training Sessions for <?php echo date('l, d M Y', strtotime($filter_date)); ?>
                        </h6>
                        <div class="date-navigation">
                            <div class="btn-group">
                                <a href="?date=<?php echo $prev_date; ?>" class="btn btn-sm btn-outline-success">
                                    <i class="fas fa-chevron-left"></i> Previous Day
                                </a>
                                <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-info">
                                    Today
                                </a>
                                <a href="?date=<?php echo $next_date; ?>" class="btn btn-sm btn-outline-success">
                                    Next Day <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <!-- Date filter -->
                        <div class="form-group">
                            <label for="filter_date">Select Date:</label>
                            <input type="date" id="filter_date" class="form-control" value="<?php echo $filter_date; ?>">
                        </div>
                        
                        <?php if (empty($sessions)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No training sessions scheduled for this date.
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($sessions as $session): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-badge <?php echo $session['is_cancelled'] ? 'badge-cancelled' : ''; ?>"></div>
                                        <div class="session-item <?php echo $session['is_cancelled'] ? 'cancelled' : ''; ?>">
                                            <div class="session-header">
                                                <div>
                                                    <h5 class="session-title">
                                                        <span class="time-badge">
                                                            <?php echo date('h:i A', strtotime($session['start_time'])) . ' - ' . 
                                                                    date('h:i A', strtotime($session['end_time'])); ?>
                                                        </span>
                                                        <span class="ml-2"><?php echo htmlspecialchars($session['topic']); ?></span>
                                                    </h5>
                                                </div>
                                                <div class="session-actions">
                                                    <a href="?edit=<?php echo $session['id']; ?>&date=<?php echo $filter_date; ?>" 
                                                       class="btn btn-sm btn-info" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($session['is_cancelled']): ?>
                                                        <a href="?toggle_status=<?php echo $session['id']; ?>" 
                                                           class="btn btn-sm btn-success" title="Restore Session"
                                                           onclick="return confirm('Are you sure you want to restore this session?');">
                                                            <i class="fas fa-undo"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="?toggle_status=<?php echo $session['id']; ?>" 
                                                           class="btn btn-sm btn-warning" title="Cancel Session"
                                                           onclick="return confirm('Are you sure you want to cancel this session?');">
                                                            <i class="fas fa-ban"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="?delete=<?php echo $session['id']; ?>" 
                                                       class="btn btn-sm btn-danger" title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this session? This cannot be undone.');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="session-details">
                                                <div class="detail-item">
                                                    <span class="detail-label"><i class="fas fa-users"></i> Batch</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($session['batch_name']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label"><i class="fas fa-chalkboard-teacher"></i> Trainer</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($session['trainer_name']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Venue</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($session['venue_name'] . 
                                                                             (!empty($session['room_number']) ? ' (Room ' . $session['room_number'] . ')' : '')); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Toggle recurring options
            $('#recurring').change(function() {
                if($(this).val() !== 'no') {
                    $('#repeatUntilGroup').show();
                } else {
                    $('#repeatUntilGroup').hide();
                }
            });
            
            // Date picker for filter
            $('#filter_date').change(function() {
                window.location.href = 'manage_training_schedules.php?date=' + $(this).val();
            });
            
            // Initialize flatpickr for better date picking experience
            flatpickr("#filter_date", {
                dateFormat: "Y-m-d",
                onChange: function(selectedDates, dateStr) {
                    window.location.href = 'manage_training_schedules.php?date=' + dateStr;
                }
            });
            
            flatpickr("#session_date", {
                dateFormat: "Y-m-d"
            });
            
            flatpickr("#repeat_until", {
                dateFormat: "Y-m-d"
            });
            
            // Initialize any custom switches
            $('.custom-switch').on('click', function() {
                const checkbox = $(this).find('input[type="checkbox"]');
                checkbox.prop('checked', !checkbox.prop('checked'));
            });
        });
    </script>

    
</body>
</html> 