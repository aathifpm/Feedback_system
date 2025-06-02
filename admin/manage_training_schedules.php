<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';

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
            // If no conflicts, proceed with save/update
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
                    
                    // Calculate the difference in days between start and end date
                    $date_diff = $current_date->diff($end_date);
                    $days_between = $date_diff->days;
                    error_log("Days between start and end date: " . $days_between);
                    
                    // Create the initial session first
                    $initial_date = $current_date->format('Y-m-d');
                    error_log("Creating initial session for: " . $initial_date);
                    
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
                    
                    // Now create all the recurring sessions
                    // Determine the interval based on recurrence type
                    $interval_days = ($recurring === 'daily') ? 1 : 7;
                    
                    // Move to the next day/week from initial date
                    $current_date->modify("+{$interval_days} days");
                    
                    // Continue creating sessions until we reach or exceed the end date
                    while ($current_date <= $end_date) {
                        $formatted_date = $current_date->format('Y-m-d');
                        error_log("Processing " . $recurring . " date: " . $formatted_date);
                        
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
                        
                        // Move to next interval
                        $current_date->modify("+{$interval_days} days");
                    }
                    
                    // Log all created dates
                    error_log("Successfully created sessions for dates: " . implode(', ', $created_dates));
                    
                    $recurrence_type = ($recurring === 'daily') ? 'daily' : 'weekly';
                    $success = "$success_count recurring $recurrence_type sessions scheduled successfully";
                    
                    if ($conflict_count > 0) {
                        $success .= " ($conflict_count sessions skipped due to conflicts)";
                        
                        // Log detailed information about conflicts
                        error_log("Conflicts found on dates: " . implode(', ', $conflict_dates));
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Training Schedules - Admin Dashboard</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e3e6f0;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
        .btn-success:hover {
            background-color: #17a673;
            border-color: #17a673;
        }
        .btn-info {
            background-color: #36b9cc;
            border-color: #36b9cc;
        }
        .btn-info:hover {
            background-color: #2c9faf;
            border-color: #2c9faf;
        }
        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
        }
        .btn-danger:hover {
            background-color: #be2617;
            border-color: #be2617;
        }
        .session-item {
            border-left: 4px solid #1cc88a;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            transition: all 0.3s;
        }
        .session-item:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }
        .cancelled {
            background-color: #f8d7da !important;
            border-left-color: #e74a3b !important;
            text-decoration: line-through;
        }
        .timeline {
            position: relative;
            padding: 20px 0;
        }
        .timeline-item {
            position: relative;
            padding-left: 50px;
            margin-bottom: 20px;
        }
        .timeline-item:before {
            content: "";
            position: absolute;
            left: 20px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e3e6f0;
        }
        .timeline-badge {
            position: absolute;
            left: 10px;
            top: 15px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #1cc88a;
            border: 3px solid #ffffff;
        }
        .badge-cancelled {
            background-color: #e74a3b !important;
        }
        .time-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
            background-color: #1cc88a;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Manage Training Schedules</h1>
            <a href="manage_schedules.php" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
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
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-success">
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
                                        <label class="custom-control-label" for="is_cancelled">Cancelled</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-success">
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
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-success">
                            Training Sessions for <?php echo date('l, d M Y', strtotime($filter_date)); ?>
                        </h6>
                        <div>
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
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="mb-0">
                                                    <span class="time-badge">
                                                        <?php echo date('h:i A', strtotime($session['start_time'])) . ' - ' . 
                                                                date('h:i A', strtotime($session['end_time'])); ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($session['topic']); ?>
                                                </h5>
                                                <div>
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
                                            <p class="mb-1">
                                                <i class="fas fa-users"></i> Batch: <?php echo htmlspecialchars($session['batch_name']); ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-chalkboard-teacher"></i> Trainer: <?php echo htmlspecialchars($session['trainer_name']); ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($session['venue_name'] . 
                                                                                     (!empty($session['room_number']) ? ' (Room ' . $session['room_number'] . ')' : '')); ?>
                                            </p>
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
        });
    </script>

    <?php include '../footer.php'; ?>
</body>
</html> 