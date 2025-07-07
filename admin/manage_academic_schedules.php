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
$schedule = null;
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get current academic year
$query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$result = mysqli_query($conn, $query);
$academic_year = mysqli_fetch_assoc($result);
$academic_year_id = $academic_year['id'];

// Process add/edit form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form data
    $assignment_id = $_POST['assignment_id'];
    $venue_id = $_POST['venue_id'];
    $class_date = $_POST['class_date'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $recurring = isset($_POST['recurring']) ? $_POST['recurring'] : 'no';
    $repeat_until = isset($_POST['repeat_until']) ? $_POST['repeat_until'] : null;
    $topic = $_POST['topic'];
    $is_cancelled = isset($_POST['is_cancelled']) ? 1 : 0;
    $skip_holidays = isset($_POST['skip_holidays']) ? 1 : 0;
    
    // Get department and batch information for holiday checking
    $assign_query = "SELECT sa.department_id, s.name as subject_name, 
                          (SELECT GROUP_CONCAT(DISTINCT by.id) 
                           FROM batch_years by 
                           WHERE by.current_year_of_study = sa.year) as batch_ids
                     FROM subject_assignments sa
                     JOIN subjects s ON sa.subject_id = s.id
                     WHERE sa.id = ?";
    $stmt = mysqli_prepare($conn, $assign_query);
    mysqli_stmt_bind_param($stmt, "i", $assignment_id);
    mysqli_stmt_execute($stmt);
    $assign_result = mysqli_stmt_get_result($stmt);
    $assignment_info = mysqli_fetch_assoc($assign_result);
    $department_id = $assignment_info['department_id'];
    $batch_ids = explode(',', $assignment_info['batch_ids']);
    
    // Validate time slots
    if (strtotime($end_time) <= strtotime($start_time)) {
        $error = "End time must be after start time";
    } else {
        // Check for conflicts (unless currently editing this same schedule)
        $conflict_query = "SELECT acs.* 
                          FROM academic_class_schedule acs
                          JOIN venues v ON acs.venue_id = v.id
                          WHERE acs.class_date = ? 
                          AND acs.venue_id = ?
                          AND acs.is_cancelled = 0
                          AND ((acs.start_time < ? AND acs.end_time > ?) 
                               OR (acs.start_time < ? AND acs.end_time > ?)
                               OR (acs.start_time >= ? AND acs.end_time <= ?))";
        
        if (isset($_POST['id'])) {
            $conflict_query .= " AND acs.id != ?";
            $stmt = mysqli_prepare($conn, $conflict_query);
            mysqli_stmt_bind_param($stmt, "sisssssssi", $class_date, $venue_id, $end_time, $start_time, 
                                 $start_time, $start_time, $start_time, $end_time, $_POST['id']);
        } else {
            $stmt = mysqli_prepare($conn, $conflict_query);
            mysqli_stmt_bind_param($stmt, "sissssss", $class_date, $venue_id, $end_time, $start_time, 
                                 $start_time, $start_time, $start_time, $end_time);
        }
        
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) > 0) {
            $conflict = mysqli_fetch_assoc($result);
            $error = "Time slot conflict with another class in the same venue at " . 
                     date('h:i A', strtotime($conflict['start_time'])) . " - " . 
                     date('h:i A', strtotime($conflict['end_time']));
        } else {
            // Check if date is a holiday
            $is_holiday_on_date = false;
            foreach ($batch_ids as $batch_id) {
                if (is_holiday($conn, $class_date, $department_id, $batch_id)) {
                    $is_holiday_on_date = true;
                    break;
                }
            }

            if ($is_holiday_on_date && !$skip_holidays && !isset($_POST['id'])) {
                $holiday_info = is_holiday($conn, $class_date, $department_id, $batch_ids[0]);
                $error = "Warning: " . $class_date . " is a holiday (" . $holiday_info['holiday_name'] . "). 
                        <form method='post' action=''>
                            <input type='hidden' name='assignment_id' value='" . $assignment_id . "'>
                            <input type='hidden' name='venue_id' value='" . $venue_id . "'>
                            <input type='hidden' name='class_date' value='" . $class_date . "'>
                            <input type='hidden' name='start_time' value='" . $start_time . "'>
                            <input type='hidden' name='end_time' value='" . $end_time . "'>
                            <input type='hidden' name='recurring' value='" . $recurring . "'>
                            <input type='hidden' name='repeat_until' value='" . $repeat_until . "'>
                            <input type='hidden' name='topic' value='" . $topic . "'>
                            <input type='hidden' name='is_cancelled' value='" . $is_cancelled . "'>
                            <input type='hidden' name='skip_holidays' value='1'>
                            <button type='submit' class='btn btn-warning'>Schedule Anyway</button>
                        </form>";
            } else {
                // If no conflicts or holidays (or holiday override), proceed with save/update
                if (isset($_POST['id'])) {
                    // Update existing schedule
                    $query = "UPDATE academic_class_schedule 
                              SET assignment_id = ?, venue_id = ?, class_date = ?, 
                                  start_time = ?, end_time = ?, topic = ?, is_cancelled = ?
                              WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iissssii", $assignment_id, $venue_id, $class_date, 
                                         $start_time, $end_time, $topic, $is_cancelled, $_POST['id']);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success = "Schedule updated successfully";
                        $filter_date = $class_date; // Set filter to the edited date
                    } else {
                        $error = "Failed to update schedule: " . mysqli_error($conn);
                    }
                } else {
                    // Create new schedule(s)
                    if ($recurring == 'yes' && $repeat_until) {
                        $current_date = new DateTime($class_date);
                        $end_date = new DateTime($repeat_until);
                        $interval = new DateInterval('P7D'); // 1 week interval
                        $period = new DatePeriod($current_date, $interval, $end_date);
                        
                        $success_count = 0;
                        $skipped_holidays = 0;
                        
                        // Get all holidays in the range
                        $holidays = get_holidays_in_range($conn, $class_date, $repeat_until, $department_id, null);
                        $holiday_dates = array_map(function($holiday) {
                            return $holiday['holiday_date'];
                        }, $holidays);
                        
                        foreach ($period as $date) {
                            $formatted_date = $date->format('Y-m-d');
                            
                            // Skip holidays unless overridden
                            $is_holiday_date = false;
                            foreach ($batch_ids as $batch_id) {
                                if (is_holiday($conn, $formatted_date, $department_id, $batch_id)) {
                                    $is_holiday_date = true;
                                    break;
                                }
                            }
                            
                            if ($is_holiday_date && !$skip_holidays) {
                                $skipped_holidays++;
                                continue;
                            }
                            
                            $query = "INSERT INTO academic_class_schedule 
                                      (assignment_id, venue_id, class_date, start_time, end_time, topic, is_cancelled) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "iissssi", $assignment_id, $venue_id, $formatted_date, 
                                                 $start_time, $end_time, $topic, $is_cancelled);
                            
                            if (mysqli_stmt_execute($stmt)) {
                                $success_count++;
                            }
                        }
                        
                        $success = "$success_count recurring classes scheduled successfully";
                        if ($skipped_holidays > 0) {
                            $success .= " ($skipped_holidays holiday dates skipped)";
                        }
                        $filter_date = $class_date; // Set filter to the first date
                    } else {
                        // Single class
                        $query = "INSERT INTO academic_class_schedule 
                                  (assignment_id, venue_id, class_date, start_time, end_time, topic, is_cancelled) 
                                  VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "iissssi", $assignment_id, $venue_id, $class_date, 
                                             $start_time, $end_time, $topic, $is_cancelled);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success = "Class scheduled successfully";
                            $filter_date = $class_date; // Set filter to the new date
                        } else {
                            $error = "Failed to schedule class: " . mysqli_error($conn);
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
    
    $query = "SELECT acs.*, sa.faculty_id, s.name as subject_name, s.code as subject_code,
                    sa.year, sa.semester, sa.section, 
                    f.name as faculty_name, v.name as venue_name
              FROM academic_class_schedule acs
              JOIN subject_assignments sa ON acs.assignment_id = sa.id
              JOIN subjects s ON sa.subject_id = s.id
              JOIN faculty f ON sa.faculty_id = f.id
              JOIN venues v ON acs.venue_id = v.id
              WHERE acs.id = ?";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $edit_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $schedule = $row;
    }
}

// Handle cancel/restore request
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $toggle_id = $_GET['toggle_status'];
    
    $query = "SELECT is_cancelled, class_date FROM academic_class_schedule WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $toggle_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $new_status = $row['is_cancelled'] ? 0 : 1;
        $status_text = $new_status ? "cancelled" : "restored";
        $class_date = $row['class_date'];
        
        $query = "UPDATE academic_class_schedule SET is_cancelled = ? WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $new_status, $toggle_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success = "Class $status_text successfully";
            $filter_date = $class_date;
        } else {
            $error = "Failed to update class status: " . mysqli_error($conn);
        }
    }
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_id = $_GET['delete'];
    
    // Get the class date first for filtering
    $query = "SELECT class_date FROM academic_class_schedule WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $delete_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $class_date = $row['class_date'];
        
        // Then delete the schedule
        $query = "DELETE FROM academic_class_schedule WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $delete_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $success = "Class deleted successfully";
            $filter_date = $class_date;
        } else {
            $error = "Failed to delete class: " . mysqli_error($conn);
        }
    }
}

// Get all available faculty assignments
$query = "SELECT sa.id, s.name as subject_name, s.code as subject_code, 
                 sa.year, sa.semester, sa.section, 
                 f.name as faculty_name
          FROM subject_assignments sa
          JOIN subjects s ON sa.subject_id = s.id
          JOIN faculty f ON sa.faculty_id = f.id
          WHERE sa.is_active = TRUE
          ORDER BY sa.year, sa.semester, sa.section, s.code";
$assignments_result = mysqli_query($conn, $query);
$assignments = [];
while ($row = mysqli_fetch_assoc($assignments_result)) {
    $assignments[] = $row;
}

// Get all venues
$query = "SELECT id, name, room_number, capacity FROM venues ORDER BY name";
$venues_result = mysqli_query($conn, $query);
$venues = [];
while ($row = mysqli_fetch_assoc($venues_result)) {
    $venues[] = $row;
}

// Get schedules for the selected date
$query = "SELECT acs.id, acs.class_date, acs.start_time, acs.end_time, 
                 acs.topic, acs.is_cancelled,
                 sa.id as assignment_id,
                 s.name as subject_name, s.code as subject_code,
                 sa.year, sa.semester, sa.section,
                 f.name as faculty_name,
                 v.name as venue_name, v.room_number
          FROM academic_class_schedule acs
          JOIN subject_assignments sa ON acs.assignment_id = sa.id
          JOIN subjects s ON sa.subject_id = s.id
          JOIN faculty f ON sa.faculty_id = f.id
          JOIN venues v ON acs.venue_id = v.id
          WHERE acs.class_date = ?
          ORDER BY acs.start_time";
          
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $filter_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$schedules = [];
while ($row = mysqli_fetch_assoc($result)) {
    $schedules[] = $row;
}

// Navigation to previous and next days
$prev_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($filter_date . ' +1 day'));

// Get holidays for the current filter date
$holiday = is_holiday($conn, $filter_date, null, null);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Schedules - Admin Dashboard</title>
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
        .schedule-item {
            border-left: 4px solid #4e73df;
            margin-bottom: 10px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            transition: all 0.3s;
        }
        .schedule-item:hover {
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
            background: #4e73df;
            border: 3px solid #ffffff;
        }
        .badge-cancelled {
            background-color: #e74a3b !important;
        }
        .time-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
            border-radius: 10px;
            background-color: #4e73df;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">Manage Academic Schedules</h1>
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
            <!-- Add/Edit Schedule Form -->
            <div class="col-lg-4">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <?php echo $edit_id ? 'Edit Schedule' : 'Add New Schedule'; ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <form action="" method="post">
                            <?php if ($edit_id): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="assignment_id">Subject & Class:</label>
                                <select name="assignment_id" id="assignment_id" class="form-control" required>
                                    <option value="">Select Subject Assignment</option>
                                    <?php foreach ($assignments as $assignment): ?>
                                        <option value="<?php echo $assignment['id']; ?>" 
                                            <?php echo ($edit_id && $schedule['assignment_id'] == $assignment['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($assignment['subject_code'] . ' - ' . $assignment['subject_name'] . 
                                                                       ' (Year ' . $assignment['year'] . ', Sem ' . $assignment['semester'] . ', Sec ' . $assignment['section'] . ')' .
                                                                       ' - ' . $assignment['faculty_name']); ?>
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
                                            <?php echo ($edit_id && $schedule['venue_id'] == $venue['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($venue['name'] . 
                                                                     (!empty($venue['room_number']) ? ' (Room ' . $venue['room_number'] . ')' : '') . 
                                                                     ' - Capacity: ' . $venue['capacity']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="class_date">Date:</label>
                                <input type="date" name="class_date" id="class_date" class="form-control" 
                                       value="<?php echo $edit_id ? $schedule['class_date'] : $filter_date; ?>" required>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label for="start_time">Start Time:</label>
                                    <input type="time" name="start_time" id="start_time" class="form-control" 
                                           value="<?php echo $edit_id ? $schedule['start_time'] : ''; ?>" required>
                                </div>
                                <div class="form-group col-md-6">
                                    <label for="end_time">End Time:</label>
                                    <input type="time" name="end_time" id="end_time" class="form-control" 
                                           value="<?php echo $edit_id ? $schedule['end_time'] : ''; ?>" required>
                                </div>
                            </div>
                            
                            <?php if (!$edit_id): ?>
                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="recurring" name="recurring" value="yes">
                                        <label class="custom-control-label" for="recurring">Recurring Weekly</label>
                                    </div>
                                </div>
                                
                                <div class="form-group" id="repeatUntilGroup" style="display: none;">
                                    <label for="repeat_until">Repeat Until:</label>
                                    <input type="date" name="repeat_until" id="repeat_until" class="form-control" 
                                           value="<?php echo date('Y-m-d', strtotime('+2 months')); ?>">
                                </div>

                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="skip_holidays" name="skip_holidays" value="1">
                                        <label class="custom-control-label" for="skip_holidays">Schedule on holidays</label>
                                        <small class="form-text text-muted">Check this to schedule classes even on holidays</small>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <label for="topic">Topic (Optional):</label>
                                <input type="text" name="topic" id="topic" class="form-control" 
                                       value="<?php echo $edit_id ? htmlspecialchars($schedule['topic']) : ''; ?>">
                            </div>
                            
                            <?php if ($edit_id): ?>
                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="is_cancelled" name="is_cancelled" 
                                               <?php echo ($edit_id && $schedule['is_cancelled']) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="is_cancelled">Cancelled</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary">
                                    <?php echo $edit_id ? 'Update Schedule' : 'Create Schedule'; ?>
                                </button>
                                <?php if ($edit_id): ?>
                                    <a href="manage_academic_schedules.php?date=<?php echo $filter_date; ?>" class="btn btn-secondary">
                                        Cancel Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Schedule List -->
            <div class="col-lg-8">
                <div class="card shadow mb-4">
                    <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                        <h6 class="m-0 font-weight-bold text-primary">
                            Schedule for <?php echo date('l, d M Y', strtotime($filter_date)); ?>
                        </h6>
                        <div>
                            <a href="?date=<?php echo $prev_date; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-chevron-left"></i> Previous Day
                            </a>
                            <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn-sm btn-outline-info">
                                Today
                            </a>
                            <a href="?date=<?php echo $next_date; ?>" class="btn btn-sm btn-outline-primary">
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
                        
                        <?php if (empty($schedules)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> No classes scheduled for this date.
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($schedules as $schedule): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-badge <?php echo $schedule['is_cancelled'] ? 'badge-cancelled' : ''; ?>"></div>
                                        <div class="schedule-item <?php echo $schedule['is_cancelled'] ? 'cancelled' : ''; ?>">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <h5 class="mb-0">
                                                    <span class="time-badge">
                                                        <?php echo date('h:i A', strtotime($schedule['start_time'])) . ' - ' . 
                                                                date('h:i A', strtotime($schedule['end_time'])); ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($schedule['subject_code'] . ' - ' . $schedule['subject_name']); ?>
                                                </h5>
                                                <div>
                                                    <a href="?edit=<?php echo $schedule['id']; ?>&date=<?php echo $filter_date; ?>" 
                                                       class="btn btn-sm btn-info" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($schedule['is_cancelled']): ?>
                                                        <a href="?toggle_status=<?php echo $schedule['id']; ?>" 
                                                           class="btn btn-sm btn-success" title="Restore Class"
                                                           onclick="return confirm('Are you sure you want to restore this class?');">
                                                            <i class="fas fa-undo"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="?toggle_status=<?php echo $schedule['id']; ?>" 
                                                           class="btn btn-sm btn-warning" title="Cancel Class"
                                                           onclick="return confirm('Are you sure you want to cancel this class?');">
                                                            <i class="fas fa-ban"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="?delete=<?php echo $schedule['id']; ?>" 
                                                       class="btn btn-sm btn-danger" title="Delete"
                                                       onclick="return confirm('Are you sure you want to delete this schedule? This cannot be undone.');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                            <p class="mb-1">
                                                <i class="fas fa-users"></i> Year <?php echo $schedule['year']; ?>, 
                                                Semester <?php echo $schedule['semester']; ?>, 
                                                Section <?php echo $schedule['section']; ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-chalkboard-teacher"></i> <?php echo htmlspecialchars($schedule['faculty_name']); ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($schedule['venue_name'] . 
                                                                                     (!empty($schedule['room_number']) ? ' (Room ' . $schedule['room_number'] . ')' : '')); ?>
                                            </p>
                                            <?php if (!empty($schedule['topic'])): ?>
                                                <p class="mb-0">
                                                    <i class="fas fa-book"></i> Topic: <?php echo htmlspecialchars($schedule['topic']); ?>
                                                </p>
                                            <?php endif; ?>
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
                if($(this).is(':checked')) {
                    $('#repeatUntilGroup').show();
                } else {
                    $('#repeatUntilGroup').hide();
                }
            });
            
            // Date picker for filter
            $('#filter_date').change(function() {
                window.location.href = 'manage_academic_schedules.php?date=' + $(this).val();
            });
        });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html> 