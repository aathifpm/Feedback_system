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
$training_batches = [];
$academic_years = [];
$departments = [];

// Get all academic years
$academic_years_query = "SELECT id, year_range, is_current FROM academic_years ORDER BY start_date DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);
while ($year = mysqli_fetch_assoc($academic_years_result)) {
    $academic_years[] = $year;
}

// Get departments
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments_result = mysqli_query($conn, $departments_query);
while ($dept = mysqli_fetch_assoc($departments_result)) {
    $departments[] = $dept;
}

// Get current academic year
$current_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);
$current_year_id = $current_year ? $current_year['id'] : 0;

// Fetch all training batches
function fetchTrainingBatches($conn) {
    $query = "SELECT tb.*, ay.year_range, d.name as department_name,
                     (SELECT COUNT(*) FROM student_training_batch stb WHERE stb.training_batch_id = tb.id) as student_count,
                     (SELECT COUNT(*) FROM training_session_schedule tss WHERE tss.training_batch_id = tb.id) as class_count
              FROM training_batches tb
              JOIN academic_years ay ON tb.academic_year_id = ay.id
              JOIN departments d ON tb.department_id = d.id
              ORDER BY tb.is_active DESC, tb.batch_name";
    $result = mysqli_query($conn, $query);
    
    $batches = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $batches[] = $row;
    }
    
    return $batches;
}

// Add new training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_batch'])) {
    $batch_name = trim($_POST['batch_name']);
    $description = trim($_POST['description']);
    $academic_year_id = $_POST['academic_year_id'];
    $department_id = $_POST['department_id'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($batch_name) || empty($department_id)) {
        $error = "Batch name and department are required.";
    } else {
        // Check if batch with the same name already exists for this department
        $check_query = "SELECT id FROM training_batches WHERE batch_name = ? AND department_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "si", $batch_name, $department_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A training batch with this name already exists in the selected department.";
        } else {
            // Insert new batch
            $insert_query = "INSERT INTO training_batches (batch_name, description, academic_year_id, department_id, is_active)
                            VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "ssiis", $batch_name, $description, $academic_year_id, $department_id, $is_active);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $success = "Training batch added successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'add_training_batch', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['batch_name' => $batch_name, 'department_id' => $department_id]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to add training batch: " . mysqli_error($conn);
            }
        }
    }
}

// Update training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_batch'])) {
    $batch_id = $_POST['batch_id'];
    $batch_name = trim($_POST['batch_name']);
    $description = trim($_POST['description']);
    $academic_year_id = $_POST['academic_year_id'];
    $department_id = $_POST['department_id'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($batch_name) || empty($department_id)) {
        $error = "Batch name and department are required.";
    } else {
        // Check if batch with the same name already exists (excluding the current batch)
        $check_query = "SELECT id FROM training_batches WHERE batch_name = ? AND department_id = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "sii", $batch_name, $department_id, $batch_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A training batch with this name already exists in the selected department.";
        } else {
            // Update batch
            $update_query = "UPDATE training_batches 
                           SET batch_name = ?, description = ?, academic_year_id = ?, department_id = ?, is_active = ? 
                           WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ssiiii", $batch_name, $description, $academic_year_id, $department_id, $is_active, $batch_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                // No need to update training_session_schedule as it references the batch by ID, not name
                
                $success = "Training batch updated successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'update_training_batch', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['batch_id' => $batch_id, 'batch_name' => $batch_name, 'department_id' => $department_id]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to update training batch: " . mysqli_error($conn);
            }
        }
    }
}

// Delete training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_batch'])) {
    $batch_id = $_POST['batch_id'];
    
    // Check if batch has students or classes
    $check_query = "SELECT 
                      (SELECT COUNT(*) FROM student_training_batch WHERE training_batch_id = ?) as student_count,
                      (SELECT COUNT(*) FROM training_session_schedule WHERE training_batch_id = ?) as class_count";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $batch_id, $batch_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $counts = mysqli_fetch_assoc($check_result);
    
    if ($counts['student_count'] > 0 || $counts['class_count'] > 0) {
        $error = "Cannot delete this training batch as it has associated students or scheduled classes.";
    } else {
        // Delete batch
        $delete_query = "DELETE FROM training_batches WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $batch_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $success = "Training batch deleted successfully.";
            
            // Log the action
            $admin_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                         VALUES (?, 'admin', 'delete_training_batch', ?, 'success', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $details = json_encode(['batch_id' => $batch_id]);
            mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to delete training batch: " . mysqli_error($conn);
        }
    }
}

// Get training batches list
$training_batches = fetchTrainingBatches($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Training Batches - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/dataTables.bootstrap4.min.css">
    <style>
        :root {
            --primary-color: #3498db;  /* Blue theme for Training */
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
            transition: margin-left 0.3s ease;
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
                padding: 1rem;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .dashboard-header button {
                width: 100%;
                justify-content: center;
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
        
        @media (max-width: 768px) {
            .dashboard-header h1 {
                font-size: 1.4rem;
            }
        }

        .card {
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            background: var(--bg-color);
            border: none;
        }
        
        .card-header {
            background-color: var(--bg-color);
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .card-body {
                padding: 1rem;
                overflow-x: auto;
            }
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
        
        @media (max-width: 576px) {
            .btn {
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
            }
            
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .action-buttons .btn {
                width: 100%;
                justify-content: center;
                margin: 0 !important;
            }
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
            background-color: #2980b9;
            border-color: #2980b9;
        }
        .btn-success {
            background-color: #27ae60;
            border-color: #27ae60;
            color: white;
        }
        .btn-success:hover {
            background-color: #219653;
            border-color: #219653;
        }
        .btn-info {
            background-color: #00b8d4;
            border-color: #00b8d4;
            color: white;
        }
        .btn-info:hover {
            background-color: #0099b3;
            border-color: #0099b3;
        }
        .btn-danger {
            background-color: #e74c3c;
            border-color: #e74c3c;
            color: white;
        }
        .btn-danger:hover {
            background-color: #c0392b;
            border-color: #c0392b;
        }
        .btn-secondary {
            background-color: #95a5a6;
            border-color: #95a5a6;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #7f8c8d;
            border-color: #7f8c8d;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-weight: 500;
            font-size: 0.85rem;
        }
        .badge-success {
            background-color: #d4edda;
            color: #155724;
        }
        .badge-danger {
            background-color: #f8d7da;
            color: #721c24;
        }
        .badge-warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .badge-primary {
            background-color: #cce5ff;
            color: #004085;
        }

        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        
        .table {
            margin-bottom: 0;
            color: var(--text-color);
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table th,
        .table td {
            padding: 1rem;
            vertical-align: middle;
            border-top: 1px solid rgba(0,0,0,0.05);
        }
        
        @media (max-width: 768px) {
            .table th,
            .table td {
                padding: 0.75rem;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
        }
        
        .table thead th {
            background-color: rgba(52, 152, 219, 0.1);
            border-bottom: 2px solid rgba(52, 152, 219, 0.2);
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            border: none;
            border-radius: 10px;
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
        
        .modal-content {
            background: var(--bg-color);
            border-radius: 15px;
            border: none;
            box-shadow: var(--shadow);
        }
        
        .modal-header, .modal-footer {
            border-color: rgba(0,0,0,0.1);
            background: var(--bg-color);
        }
        
        .current-year {
            color: #27ae60;
            font-weight: 600;
        }

        .custom-control-input:checked ~ .custom-control-label::before {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
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
        
        /* Mobile-specific styles */
        @media (max-width: 576px) {
            .dataTables_length, 
            .dataTables_filter {
                width: 100%;
                text-align: left;
                margin-bottom: 0.5rem;
            }
            
            .dataTables_filter input {
                width: 100%;
                margin-left: 0 !important;
            }
            
            .dataTables_info,
            .dataTables_paginate {
                width: 100%;
                text-align: center;
                margin-top: 0.5rem;
            }
            
            .paginate_button {
                padding: 0.3rem 0.5rem !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    
    <div class="main-content">
        <div class="dashboard-header">
            <h1><i class="fas fa-users mr-2"></i>Manage Placement Training Batches</h1>
            <button class="btn btn-primary" data-toggle="modal" data-target="#addBatchModal">
                <i class="fas fa-plus-circle mr-2"></i>Add New Training Batch
            </button>
        </div>
        
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error; ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="batchesTable" width="100%" cellspacing="0">
                        <thead class="thead-light">
                            <tr>
                                <th>Batch Name</th>
                                <th>Department</th>
                                <th>Academic Year</th>
                                <th>Description</th>
                                <th>Students</th>
                                <th>Classes</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($training_batches as $batch): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                <td><?php echo htmlspecialchars($batch['department_name']); ?></td>
                                <td>
                                    <?php if ($batch['academic_year_id'] == $current_year_id): ?>
                                        <span class="current-year"><?php echo htmlspecialchars($batch['year_range']); ?> (Current)</span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($batch['year_range']); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($batch['description']); ?></td>
                                <td>
                                    <?php echo $batch['student_count']; ?>
                                    <?php if ($batch['student_count'] > 0): ?>
                                        <a href="view_batch_students.php?batch_id=<?php echo $batch['id']; ?>" class="ml-2 btn btn-sm btn-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $batch['class_count']; ?>
                                    <?php if ($batch['class_count'] > 0): ?>
                                        <a href="view_batch_classes.php?batch_id=<?php echo $batch['id']; ?>" class="ml-2 btn btn-sm btn-info">
                                            <i class="fas fa-calendar"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($batch['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="manage_batch_students.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-primary btn-sm mb-1">
                                            <i class="fas fa-user-plus"></i> Manage Students
                                        </a>
                                        <button class="btn btn-info btn-sm mb-1 edit-batch" 
                                                data-id="<?php echo $batch['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($batch['batch_name']); ?>"
                                                data-description="<?php echo htmlspecialchars($batch['description']); ?>"
                                                data-academic-year="<?php echo $batch['academic_year_id']; ?>"
                                                data-department="<?php echo $batch['department_id']; ?>"
                                                data-active="<?php echo $batch['is_active']; ?>"
                                                data-toggle="modal" data-target="#editBatchModal">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        
                                        <?php if ($batch['student_count'] == 0 && $batch['class_count'] == 0): ?>
                                        <button class="btn btn-danger btn-sm mb-1 delete-batch" 
                                                data-id="<?php echo $batch['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($batch['batch_name']); ?>"
                                                data-toggle="modal" data-target="#deleteBatchModal">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Batch Modal -->
    <div class="modal fade" id="addBatchModal" tabindex="-1" role="dialog" aria-labelledby="addBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBatchModalLabel">Add New Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="department_id">Department <span class="text-danger">*</span></label>
                            <select class="form-control" id="department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="batch_name">Batch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="batch_name" name="batch_name" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="academic_year_id">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="academic_year_id" name="academic_year_id" required>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['is_current']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                    <?php if ($year['is_current']): ?> (Current) <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" checked>
                                <label class="custom-control-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" name="add_batch" class="btn btn-primary">Add Batch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Batch Modal -->
    <div class="modal fade" id="editBatchModal" tabindex="-1" role="dialog" aria-labelledby="editBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBatchModalLabel">Edit Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_batch_id" name="batch_id">
                        <div class="form-group">
                            <label for="edit_department_id">Department <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_batch_name">Batch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_batch_name" name="batch_name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_description">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="edit_academic_year_id">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_academic_year_id" name="academic_year_id" required>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                    <?php if ($year['is_current']): ?> (Current) <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active">
                                <label class="custom-control-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" name="update_batch" class="btn btn-primary">Update Batch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Batch Modal -->
    <div class="modal fade" id="deleteBatchModal" tabindex="-1" role="dialog" aria-labelledby="deleteBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteBatchModalLabel">Delete Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_batch_id" name="batch_id">
                        <p>Are you sure you want to delete the training batch <span id="delete_batch_name" class="font-weight-bold"></span>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_batch" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#batchesTable').DataTable({
                "order": [[1, "asc"], [0, "asc"]],
                "language": {
                    "search": "Search batches:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ training batches",
                    "infoEmpty": "Showing 0 to 0 of 0 training batches",
                    "infoFiltered": "(filtered from _MAX_ total training batches)"
                },
                responsive: true,
                autoWidth: false
            });
            
            // Edit batch modal
            $('.edit-batch').click(function() {
                $('#edit_batch_id').val($(this).data('id'));
                $('#edit_batch_name').val($(this).data('name'));
                $('#edit_description').val($(this).data('description'));
                $('#edit_academic_year_id').val($(this).data('academic-year'));
                $('#edit_department_id').val($(this).data('department'));
                $('#edit_is_active').prop('checked', $(this).data('active') == 1);
            });
            
            // Delete batch modal
            $('.delete-batch').click(function() {
                $('#delete_batch_id').val($(this).data('id'));
                $('#delete_batch_name').text($(this).data('name'));
            });
            
            // Check if we're on a mobile device and adjust table display
            function checkMobileView() {
                if (window.innerWidth < 768) {
                    // Add scrolling hint if needed
                    if (!$('.table-scroll-hint').length) {
                        $('.table-responsive').before('<div class="alert alert-info table-scroll-hint mb-2" role="alert"><small><i class="fas fa-info-circle"></i> Swipe horizontally to see more data</small></div>');
                    }
                } else {
                    $('.table-scroll-hint').remove();
                }
            }
            
            // Run on page load
            checkMobileView();
            
            // Run when window is resized
            $(window).resize(function() {
                checkMobileView();
            });
        });
    </script>
</body>
</html> 