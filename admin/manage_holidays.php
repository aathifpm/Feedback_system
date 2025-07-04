<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add or Edit Holiday
    if (isset($_POST['action']) && ($_POST['action'] == 'add' || $_POST['action'] == 'edit')) {
        $holiday_name = mysqli_real_escape_string($conn, $_POST['holiday_name']);
        $holiday_date = mysqli_real_escape_string($conn, $_POST['holiday_date']);
        $description = mysqli_real_escape_string($conn, $_POST['description']);
        $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
        $recurring_year = ($is_recurring && isset($_POST['recurring_year'])) ? 
            mysqli_real_escape_string($conn, $_POST['recurring_year']) : NULL;
        
        // Handle applicable departments and batches
        $applicable_departments = isset($_POST['applicable_departments']) && !empty($_POST['applicable_departments']) ? 
            implode(',', $_POST['applicable_departments']) : NULL;
        $applicable_batches = isset($_POST['applicable_batches']) && !empty($_POST['applicable_batches']) ? 
            implode(',', $_POST['applicable_batches']) : NULL;
        
        if ($_POST['action'] == 'add') {
            $sql = "INSERT INTO holidays (holiday_name, holiday_date, description, is_recurring, recurring_year, 
                    applicable_departments, applicable_batches, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'sssisssi', $holiday_name, $holiday_date, $description, $is_recurring, 
                                $recurring_year, $applicable_departments, $applicable_batches, $_SESSION['user_id']);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success_msg'] = "Holiday added successfully!";
            } else {
                $_SESSION['error_msg'] = "Error adding holiday: " . mysqli_error($conn);
            }
        } else {
            // Edit existing holiday
            $holiday_id = mysqli_real_escape_string($conn, $_POST['holiday_id']);
            $sql = "UPDATE holidays SET holiday_name=?, holiday_date=?, description=?, is_recurring=?, 
                    recurring_year=?, applicable_departments=?, applicable_batches=?
                    WHERE id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, 'sssisssi', $holiday_name, $holiday_date, $description, $is_recurring, 
                                $recurring_year, $applicable_departments, $applicable_batches, $holiday_id);
            
            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success_msg'] = "Holiday updated successfully!";
            } else {
                $_SESSION['error_msg'] = "Error updating holiday: " . mysqli_error($conn);
            }
        }
        header("Location: manage_holidays.php");
        exit();
    }
    
    // Create Weekend Holidays
    if (isset($_POST['action']) && $_POST['action'] == 'create_weekend') {
        $start_date = mysqli_real_escape_string($conn, $_POST['weekend_start_date']);
        $end_date = mysqli_real_escape_string($conn, $_POST['weekend_end_date']);
        $weekend_day = mysqli_real_escape_string($conn, $_POST['weekend_day']);
        $weekend_name = mysqli_real_escape_string($conn, $_POST['weekend_name']);
        $weekend_description = mysqli_real_escape_string($conn, $_POST['weekend_description']);
        
        // Get applicable departments and batches
        $applicable_departments = isset($_POST['weekend_departments']) && !empty($_POST['weekend_departments']) ? 
            implode(',', $_POST['weekend_departments']) : NULL;
        $applicable_batches = isset($_POST['weekend_batches']) && !empty($_POST['weekend_batches']) ? 
            implode(',', $_POST['weekend_batches']) : NULL;
        
        $success_count = 0;
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);
        
        foreach ($period as $date) {
            // Check if this date matches the selected weekend day (0 = Sunday, 6 = Saturday)
            if ($date->format('w') == $weekend_day) {
                $formatted_date = $date->format('Y-m-d');
                
                // Check if holiday already exists for this date
                $check_query = "SELECT id FROM holidays WHERE holiday_date = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, 's', $formatted_date);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                                 if (mysqli_num_rows($check_result) == 0) {
                     // Insert the weekend holiday
                     $sql = "INSERT INTO holidays (holiday_name, holiday_date, description, is_recurring, 
                             applicable_departments, applicable_batches, created_by)
                             VALUES (?, ?, ?, 0, ?, ?, ?)";
                     $stmt = mysqli_prepare($conn, $sql);
                     mysqli_stmt_bind_param($stmt, 'sssssi', $weekend_name, $formatted_date, $weekend_description, 
                                           $applicable_departments, $applicable_batches, $_SESSION['user_id']);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_count++;
                    }
                }
            }
        }
        
        if ($success_count > 0) {
            $_SESSION['success_msg'] = "$success_count weekend holidays created successfully!";
        } else {
            $_SESSION['error_msg'] = "No weekend holidays were created. They may already exist.";
        }
        
        header("Location: manage_holidays.php");
        exit();
    }
    
    // Delete Holiday
    if (isset($_POST['action']) && $_POST['action'] == 'delete' && isset($_POST['holiday_id'])) {
        $holiday_id = mysqli_real_escape_string($conn, $_POST['holiday_id']);
        $sql = "DELETE FROM holidays WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $holiday_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success_msg'] = "Holiday deleted successfully!";
        } else {
            $_SESSION['error_msg'] = "Error deleting holiday: " . mysqli_error($conn);
        }
        header("Location: manage_holidays.php");
        exit();
    }
}

// Get holidays list
$current_year = date('Y');
$sql = "SELECT h.*, 
        (SELECT GROUP_CONCAT(name) FROM departments 
         WHERE FIND_IN_SET(id, h.applicable_departments)) AS department_names,
        (SELECT GROUP_CONCAT(batch_name) FROM batch_years 
         WHERE FIND_IN_SET(id, h.applicable_batches)) AS batch_names
        FROM holidays h
        ORDER BY 
            CASE 
                WHEN h.recurring_year IS NULL THEN 1
                ELSE 2
            END,
            MONTH(h.holiday_date), 
            DAY(h.holiday_date)";
$result = mysqli_query($conn, $sql);

// Get all departments for form dropdowns
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$dept_result = mysqli_query($conn, $dept_query);
$departments = [];
while ($row = mysqli_fetch_assoc($dept_result)) {
    $departments[] = $row;
}

// Get all batches for form dropdowns
$batch_query = "SELECT id, batch_name, CONCAT(admission_year, '-', graduation_year) AS batch_range 
                FROM batch_years WHERE is_active = 1 
                ORDER BY admission_year DESC";
$batch_result = mysqli_query($conn, $batch_query);
$batches = [];
while ($row = mysqli_fetch_assoc($batch_result)) {
    $batches[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Holidays - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #e74a3b;  /* Red theme for Holidays */
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
        }

        .card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border: none;
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding-bottom: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h6 {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1.1rem;
            margin: 0;
        }

        .btn {
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
        }

        .btn-primary {
            color: #fff;
            background: var(--primary-color);
        }
        
        .btn-success {
            color: #fff;
            background: #1cc88a;
        }
        
        .btn-danger {
            color: #fff;
            background: #e74a3b;
        }

        .btn-sm {
            padding: 0.5rem 0.7rem;
            font-size: 0.8rem;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        /* Custom styles for holiday calendar */
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 8px;
            margin-bottom: 20px;
        }
        
        .calendar-day {
            text-align: center;
            font-weight: bold;
            padding: 8px 0;
            background-color: var(--bg-color);
            border-radius: 5px;
            box-shadow: var(--shadow);
            margin-bottom: 8px;
        }
        
        .calendar-date {
            min-height: 90px;
            padding: 8px;
            border-radius: 8px;
            box-shadow: var(--inner-shadow);
            position: relative;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .calendar-date:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }
        
        .calendar-date.current-month {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .calendar-date.other-month {
            background-color: rgba(200, 200, 200, 0.2);
            color: #999;
            min-height: 60px;
        }
        
        .calendar-date.holiday {
            background-color: rgba(231, 74, 59, 0.15);
            border-left: 3px solid var(--primary-color);
        }
        
        .date-number {
            font-weight: bold;
            margin-bottom: 8px;
            text-align: right;
            font-size: 1.1rem;
            position: relative;
            z-index: 1;
        }
        
        .holiday-name {
            font-size: 0.8rem;
            color: var(--primary-color);
            word-break: break-word;
            overflow: hidden;
            text-overflow: ellipsis;
            background-color: rgba(255, 255, 255, 0.6);
            padding: 3px 5px;
            border-radius: 3px;
            margin-bottom: 3px;
            font-weight: 500;
        }
        
        .current-month-display {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .prev-month, .next-month {
            padding: 8px 15px;
            background-color: var(--bg-color);
            box-shadow: var(--shadow);
            border: none;
            border-radius: 8px;
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        .prev-month:hover, .next-month:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
        }
        
        .w-100 {
            width: 100%;
            display: block;
            margin: 0;
            padding: 0;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background-color: rgba(28, 200, 138, 0.2);
            color: #1cc88a;
        }
        
        .alert-danger {
            background-color: rgba(231, 74, 59, 0.2);
            color: #e74a3b;
        }
        
        /* Form styles */
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 0.8rem;
            border-radius: 8px;
            border: none;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
        }
        
        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }
        
        .form-check {
            margin-bottom: 1rem;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25em 0.6em;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.25rem;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }
        
        .badge-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .badge-info {
            background-color: #36b9cc;
            color: white;
        }
        
        /* Tab styles */
        .nav-tabs {
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 20px;
        }
        
        .nav-tabs .nav-link {
            color: var(--text-color);
            background: var(--bg-color);
            border: none;
            border-radius: 8px 8px 0 0;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .nav-tabs .nav-link:hover {
            background: rgba(231, 74, 59, 0.1);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            border-bottom: 2px solid var(--primary-color);
        }
        
        /* Modal styles */
        .modal-content {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            border: none;
        }
        
        .modal-header {
            border-bottom: 1px solid rgba(0,0,0,0.1);
            background: transparent;
        }
        
        .modal-footer {
            border-top: 1px solid rgba(0,0,0,0.1);
            background: transparent;
        }
        
        .close {
            color: var(--primary-color);
            text-shadow: none;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }
        
        .close:hover {
            opacity: 1;
        }
        
        @media (max-width: 768px) {
            .calendar-grid {
                gap: 4px;
            }
            
            .calendar-date {
                min-height: 70px;
                padding: 4px;
            }
            
            .holiday-name {
                font-size: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Holidays</h1>
            <button class="btn btn-primary" data-toggle="modal" data-target="#addHolidayModal">
                <i class="fas fa-plus"></i> Add Holiday
            </button>
        </div>
        
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_msg']; unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Holidays List -->
        <div class="card">
            <div class="card-header">
                <h6 class="font-weight-bold">Holidays List</h6>
                <div>
                    <button class="btn btn-sm" id="view-toggle" data-view="list">
                        <i class="fas fa-calendar-alt"></i> Switch to Calendar View
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="list-view">
                    <div class="table-responsive">
                        <table class="table table-hover" id="holidaysTable">
                            <thead>
                                <tr>
                                    <th>Holiday Name</th>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th>Type</th>
                                    <th>Applicable To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (mysqli_num_rows($result) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['holiday_name']); ?></td>
                                            <td>
                                                <?php 
                                                    $date = new DateTime($row['holiday_date']);
                                                    echo $date->format('d M Y');
                                                    if ($row['is_recurring']) {
                                                        echo $row['recurring_year'] ? " (Yearly in {$row['recurring_year']})" : " (Yearly)";
                                                    }
                                                ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['description']); ?></td>
                                            <td>
                                                <?php if ($row['is_recurring']): ?>
                                                    <span class="badge badge-info">Recurring</span>
                                                <?php else: ?>
                                                    <span class="badge badge-primary">One-time</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($row['applicable_departments']): ?>
                                                    <strong>Departments:</strong> <?php echo htmlspecialchars($row['department_names']); ?><br>
                                                <?php else: ?>
                                                    <span class="badge badge-info">All Departments</span><br>
                                                <?php endif; ?>
                                                
                                                <?php if ($row['applicable_batches']): ?>
                                                    <strong>Batches:</strong> <?php echo htmlspecialchars($row['batch_names']); ?>
                                                <?php else: ?>
                                                    <span class="badge badge-info">All Batches</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-primary edit-holiday" data-id="<?php echo $row['id']; ?>">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger delete-holiday" data-id="<?php echo $row['id']; ?>" 
                                                        data-name="<?php echo htmlspecialchars($row['holiday_name']); ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center">No holidays found</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div id="calendar-view" style="display: none;">
                    <!-- Calendar view will be populated with JavaScript -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <button class="btn btn-sm prev-month"><i class="fas fa-chevron-left"></i> Previous</button>
                        <h5 class="current-month-display mb-0">Loading...</h5>
                        <button class="btn btn-sm next-month">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="calendar-container"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Holiday Modal -->
    <div class="modal fade" id="addHolidayModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Holiday</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <ul class="nav nav-tabs" id="holidayTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="single-tab" data-toggle="tab" href="#single-holiday" role="tab">
                                Single Holiday
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="weekend-tab" data-toggle="tab" href="#weekend-holiday" role="tab">
                                Weekend Holidays
                            </a>
                        </li>
                    </ul>
                    
                    <div class="tab-content mt-3" id="holidayTabContent">
                        <!-- Single Holiday Form -->
                        <div class="tab-pane fade show active" id="single-holiday" role="tabpanel">
                            <form id="holidayForm" method="POST" action="">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="holiday_id" id="holiday_id">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Holiday Name*</label>
                                            <input type="text" class="form-control" name="holiday_name" id="holiday_name" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Date*</label>
                                            <input type="date" class="form-control" name="holiday_date" id="holiday_date" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="description" id="description" rows="3"></textarea>
                                </div>
                                
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="is_recurring" name="is_recurring">
                                    <label class="form-check-label" for="is_recurring">Recurring Holiday (Yearly)</label>
                                </div>
                                
                                <div id="recurring_year_container" style="display: none;">
                                    <div class="form-group">
                                        <label class="form-label">Specific Year (leave empty for every year)</label>
                                        <input type="number" class="form-control" name="recurring_year" id="recurring_year" min="2000" max="2100">
                                        <small class="form-text text-muted">Leave empty if this holiday occurs every year</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Applicable Departments</label>
                                            <select class="form-control" name="applicable_departments[]" id="applicable_departments" multiple>
                                                <option value="">All Departments</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Leave empty for all departments</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Applicable Batches</label>
                                            <select class="form-control" name="applicable_batches[]" id="applicable_batches" multiple>
                                                <option value="">All Batches</option>
                                                <?php foreach($batches as $batch): ?>
                                                    <option value="<?php echo $batch['id']; ?>"><?php echo htmlspecialchars($batch['batch_name'] . ' (' . $batch['batch_range'] . ')'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Leave empty for all batches</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-right mt-4">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Save Holiday</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Weekend Holiday Form -->
                        <div class="tab-pane fade" id="weekend-holiday" role="tabpanel">
                            <form id="weekendForm" method="POST" action="">
                                <input type="hidden" name="action" value="create_weekend">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Holiday Name*</label>
                                            <input type="text" class="form-control" name="weekend_name" required 
                                                   placeholder="e.g., Sunday Holiday">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Weekend Day*</label>
                                            <select class="form-control" name="weekend_day" required>
                                                <option value="0">Sunday</option>
                                                <option value="6">Saturday</option>
                                                <option value="5">Friday</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Start Date*</label>
                                            <input type="date" class="form-control" name="weekend_start_date" required>
                                            <small class="form-text text-muted">First date to check for weekends</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">End Date*</label>
                                            <input type="date" class="form-control" name="weekend_end_date" required>
                                            <small class="form-text text-muted">Last date to check for weekends</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Description</label>
                                    <textarea class="form-control" name="weekend_description" rows="3" 
                                              placeholder="e.g., Regular weekend holiday"></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Applicable Departments</label>
                                            <select class="form-control" name="weekend_departments[]" multiple>
                                                <option value="">All Departments</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Leave empty for all departments</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label class="form-label">Applicable Batches</label>
                                            <select class="form-control" name="weekend_batches[]" multiple>
                                                <option value="">All Batches</option>
                                                <?php foreach($batches as $batch): ?>
                                                    <option value="<?php echo $batch['id']; ?>"><?php echo htmlspecialchars($batch['batch_name'] . ' (' . $batch['batch_range'] . ')'); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="form-text text-muted">Leave empty for all batches</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-right mt-4">
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">Create Weekend Holidays</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteHolidayModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Deletion</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the holiday "<span id="delete-holiday-name"></span>"?</p>
                    <form id="deleteHolidayForm" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="holiday_id" id="delete_holiday_id">
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirm-delete">Delete Holiday</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTable
            $('#holidaysTable').DataTable({
                "ordering": true,
                "info": true,
                "lengthChange": true,
                "pageLength": 10,
                "language": {
                    "search": "Search holidays:",
                    "lengthMenu": "Show _MENU_ holidays",
                    "info": "Showing _START_ to _END_ of _TOTAL_ holidays"
                }
            });
            
            // Initialize Bootstrap tabs
            $('#holidayTabs a').on('click', function(e) {
                e.preventDefault();
                $(this).tab('show');
            });
            
            // Make sure modals are hidden initially
            $('.modal').modal('hide');
            
            // Toggle recurring year input based on checkbox
            $('#is_recurring').change(function() {
                if ($(this).is(':checked')) {
                    $('#recurring_year_container').show();
                } else {
                    $('#recurring_year_container').hide();
                    $('#recurring_year').val('');
                }
            });
            
            // Edit holiday
            $('.edit-holiday').click(function() {
                const holidayId = $(this).data('id');
                
                // Show the single holiday tab when editing
                $('#holidayTabs a[href="#single-holiday"]').tab('show');
                
                // Fetch holiday data via AJAX
                $.ajax({
                    url: 'ajax/get_holiday_details.php',
                    method: 'POST',
                    data: { holiday_id: holidayId },
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            const holiday = response.data;
                            
                            // Update form fields
                            $('#holiday_id').val(holiday.id);
                            $('#holiday_name').val(holiday.holiday_name);
                            $('#holiday_date').val(holiday.holiday_date);
                            $('#description').val(holiday.description);
                            $('#is_recurring').prop('checked', holiday.is_recurring == 1);
                            
                            if (holiday.is_recurring == 1) {
                                $('#recurring_year_container').show();
                                $('#recurring_year').val(holiday.recurring_year);
                            } else {
                                $('#recurring_year_container').hide();
                            }
                            
                            // Update applicable departments
                            if (holiday.applicable_departments) {
                                const deptIds = holiday.applicable_departments.split(',');
                                $('#applicable_departments').val(deptIds);
                            } else {
                                $('#applicable_departments').val('');
                            }
                            
                            // Update applicable batches
                            if (holiday.applicable_batches) {
                                const batchIds = holiday.applicable_batches.split(',');
                                $('#applicable_batches').val(batchIds);
                            } else {
                                $('#applicable_batches').val('');
                            }
                            
                            // Update form action
                            $('input[name="action"]').val('edit');
                            
                            // Update modal title
                            $('.modal-title').text('Edit Holiday');
                            
                            // Show modal
                            $('#addHolidayModal').modal('show');
                        } else {
                            alert('Failed to fetch holiday details');
                        }
                    },
                    error: function() {
                        alert('An error occurred while fetching holiday details');
                    }
                });
            });
            
            // Show delete confirmation modal
            $('.delete-holiday').click(function() {
                const holidayId = $(this).data('id');
                const holidayName = $(this).data('name');
                
                $('#delete_holiday_id').val(holidayId);
                $('#delete-holiday-name').text(holidayName);
                $('#deleteHolidayModal').modal('show');
            });
            
            // Confirm delete
            $('#confirm-delete').click(function() {
                $('#deleteHolidayForm').submit();
            });
            
            // Toggle view (list/calendar)
            $('#view-toggle').click(function() {
                const currentView = $(this).data('view');
                
                if (currentView === 'list') {
                    $(this).data('view', 'calendar');
                    $(this).html('<i class="fas fa-list"></i> Switch to List View');
                    $('#list-view').hide();
                    $('#calendar-view').show();
                    loadCalendarView();
                } else {
                    $(this).data('view', 'list');
                    $(this).html('<i class="fas fa-calendar-alt"></i> Switch to Calendar View');
                    $('#calendar-view').hide();
                    $('#list-view').show();
                }
            });
            
            // Calendar navigation
            let currentDate = new Date();
            
            $('.prev-month').click(function() {
                currentDate.setMonth(currentDate.getMonth() - 1);
                loadCalendarView();
            });
            
            $('.next-month').click(function() {
                currentDate.setMonth(currentDate.getMonth() + 1);
                loadCalendarView();
            });
            
            // Function to load calendar view
            function loadCalendarView() {
                const year = currentDate.getFullYear();
                const month = currentDate.getMonth();
                
                // Update header
                $('.current-month-display').text(new Date(year, month, 1).toLocaleString('default', { month: 'long', year: 'numeric' }));
                
                // Fetch holidays for this month via AJAX
                $.ajax({
                    url: 'ajax/get_holidays_by_month.php',
                    method: 'POST',
                    data: { year: year, month: month + 1 }, // Month is 0-indexed in JS but 1-indexed for PHP
                    dataType: 'json',
                    success: function(response) {
                        if (response.status === 'success') {
                            generateCalendar(year, month, response.data);
                        } else {
                            alert('Failed to fetch holidays for calendar');
                        }
                    },
                    error: function() {
                        alert('An error occurred while fetching calendar data');
                    }
                });
            }
            
            // Function to generate calendar
            function generateCalendar(year, month, holidays) {
                const container = $('.calendar-container');
                container.empty();
                
                // Create headers
                const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                let calendarHTML = '<div class="calendar-grid">';
                
                // Add day headers
                daysOfWeek.forEach(day => {
                    calendarHTML += `<div class="calendar-day">${day}</div>`;
                });
                
                // Get first day of month and total days
                const firstDayOfMonth = new Date(year, month, 1).getDay();
                const lastDayOfMonth = new Date(year, month + 1, 0).getDate();
                
                // Get last day of previous month
                const lastDayOfPrevMonth = new Date(year, month, 0).getDate();
                
                // Convert holidays array to map for faster lookup
                const holidayMap = {};
                holidays.forEach(holiday => {
                    const holidayDate = new Date(holiday.holiday_date);
                    // Only consider holidays for current month and year we're viewing
                    if (holidayDate.getMonth() + 1 === month + 1 && holidayDate.getFullYear() === year) {
                        const day = holidayDate.getDate();
                        if (!holidayMap[day]) {
                            holidayMap[day] = [];
                        }
                        holidayMap[day].push(holiday);
                    }
                });
                
                // Calculate rows needed (either 5 or 6 depending on month layout)
                const totalDays = firstDayOfMonth + lastDayOfMonth;
                const rowsNeeded = Math.ceil(totalDays / 7);
                
                // Generate rows
                let day = 1;
                for (let row = 0; row < rowsNeeded; row++) {
                    for (let col = 0; col < 7; col++) {
                        // Determine if we're in previous month, current month, or next month
                        let dateNum, dateClass;
                        
                        if ((row === 0 && col < firstDayOfMonth) || day > lastDayOfMonth) {
                            if (row === 0 && col < firstDayOfMonth) {
                                // Previous month
                                dateNum = lastDayOfPrevMonth - (firstDayOfMonth - col - 1);
                            } else {
                                // Next month
                                dateNum = day - lastDayOfMonth;
                                day++;
                            }
                            dateClass = 'other-month';
                        } else {
                            // Current month
                            dateNum = day;
                            dateClass = 'current-month';
                            
                            // Check if this day is a holiday
                            if (holidayMap[dateNum]) {
                                dateClass += ' holiday';
                            }
                            
                            day++;
                        }
                        
                        // Create the calendar cell
                        calendarHTML += `<div class="calendar-date ${dateClass}">`;
                        calendarHTML += `<div class="date-number">${dateNum}</div>`;
                        
                        // Add holiday names if present
                        if (holidayMap[dateNum]) {
                            holidayMap[dateNum].forEach(holiday => {
                                calendarHTML += `<div class="holiday-name" title="${holiday.description || ''}">${holiday.holiday_name}</div>`;
                            });
                        }
                        
                        calendarHTML += '</div>';
                    }
                }
                
                calendarHTML += '</div>';
                container.html(calendarHTML);
            }
            
            // Add new holiday button
            $('#addHolidayModal').on('show.bs.modal', function(event) {
                if (!$(event.relatedTarget) || !$(event.relatedTarget).hasClass('edit-holiday')) {
                    // Clear forms for new holiday
                    $('#holidayForm')[0].reset();
                    $('#weekendForm')[0].reset();
                    $('input[name="action"]').val('add');
                    $('#holiday_id').val('');
                    $('#recurring_year_container').hide();
                    $('.modal-title').text('Add New Holiday');
                    
                    // Set default dates for weekend form
                    const today = new Date();
                    const oneYearLater = new Date();
                    oneYearLater.setFullYear(today.getFullYear() + 1);
                    
                    $('input[name="weekend_start_date"]').val(formatDate(today));
                    $('input[name="weekend_end_date"]').val(formatDate(oneYearLater));
                    
                    // Show first tab by default
                    $('#holidayTabs a[href="#single-holiday"]').tab('show');
                }
            });
            
            // Helper function to format date as YYYY-MM-DD
            function formatDate(date) {
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                return `${year}-${month}-${day}`;
            }
        });
    </script>

    <?php include '../footer.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

</body>
</html> 