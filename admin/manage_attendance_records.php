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

// Get all departments
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments_result = mysqli_query($conn, $departments_query);
$departments = [];
while ($dept = mysqli_fetch_assoc($departments_result)) {
    $departments[] = $dept;
}

// Get all subjects
$subjects_query = "SELECT id, name, code FROM subjects ORDER BY name";
$subjects_result = mysqli_query($conn, $subjects_query);
$subjects = [];
while ($subject = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $subject;
}

// Get all training batches
$batches_query = "SELECT id, batch_name FROM training_batches WHERE is_active = TRUE ORDER BY batch_name";
$batches_result = mysqli_query($conn, $batches_query);
$training_batches = [];
while ($batch = mysqli_fetch_assoc($batches_result)) {
    $training_batches[] = $batch;
}

// Default filter values
$filter_department = isset($_GET['department_id']) ? $_GET['department_id'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$filter_subject = isset($_GET['subject_id']) ? $_GET['subject_id'] : '';
$filter_training_batch = isset($_GET['training_batch_id']) ? $_GET['training_batch_id'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Pagination variables
$records_per_page = 50; // Number of records per page
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $records_per_page;

// Prepare attendance records query
$query = "";
$count_query = "";
$params = [];
$types = "";

// Create UNION query to combine academic and training attendance records
if ($filter_type == 'all' || $filter_type == 'regular') {
    $query .= "
        SELECT 
            aar.id, 
            'academic' as record_type,
            aar.status, 
            aar.created_at, 
            aar.updated_at, 
            aar.remarks,
            s.roll_number, 
            s.name as student_name, 
            s.register_number,
            f.name as marked_by_name,
            acs.class_date, 
            acs.start_time, 
            acs.end_time, 
            acs.topic,
            '' as training_batch_name,
            v.name as venue_name, 
            v.room_number,
            d.name as department_name,
            subj.name as subject_name, 
            subj.code as subject_code
        FROM 
            academic_attendance_records aar
        JOIN 
            students s ON aar.student_id = s.id
        JOIN 
            faculty f ON aar.marked_by = f.id
        JOIN 
            academic_class_schedule acs ON aar.schedule_id = acs.id
        JOIN 
            venues v ON acs.venue_id = v.id
        JOIN 
            departments d ON s.department_id = d.id
        JOIN 
            subject_assignments sa ON acs.assignment_id = sa.id
        JOIN 
            subjects subj ON sa.subject_id = subj.id
        WHERE 1=1";

    // Apply filters specific to academic records
    if (!empty($filter_department)) {
        $query .= " AND s.department_id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }

    if (!empty($filter_date_from)) {
        $query .= " AND acs.class_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }

    if (!empty($filter_date_to)) {
        $query .= " AND acs.class_date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }

    if (!empty($filter_subject)) {
        $query .= " AND sa.subject_id = ?";
        $params[] = $filter_subject;
        $types .= "i";
    }

    if (!empty($filter_status)) {
        $query .= " AND aar.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    // Create a count query for academic records
    $count_query = "SELECT COUNT(*) as count FROM (" . $query . ") as academic_count";
}

// Add training attendance records if applicable
if (($filter_type == 'all' || $filter_type == 'placement') && empty($filter_subject)) {
    // If we already have academic query, add UNION
    if (!empty($query)) {
        $query .= " UNION ";
    }
    
    $training_query = "
        SELECT 
            tar.id, 
            'training' as record_type,
            tar.status, 
            tar.created_at, 
            tar.updated_at, 
            tar.remarks,
            s.roll_number, 
            s.name as student_name, 
            s.register_number,
            f.name as marked_by_name,
            tss.session_date as class_date, 
            tss.start_time, 
            tss.end_time, 
            tss.topic,
            tb.batch_name as training_batch_name,
            v.name as venue_name, 
            v.room_number,
            d.name as department_name,
            'Training' as subject_name, 
            '' as subject_code
        FROM 
            training_attendance_records tar
        JOIN 
            students s ON tar.student_id = s.id
        JOIN 
            faculty f ON tar.marked_by = f.id
        JOIN 
            training_session_schedule tss ON tar.session_id = tss.id
        JOIN 
            training_batches tb ON tss.training_batch_id = tb.id
        JOIN 
            venues v ON tss.venue_id = v.id
        JOIN 
            departments d ON s.department_id = d.id
        WHERE 1=1";

    // Apply filters specific to training records
    if (!empty($filter_department)) {
        $training_query .= " AND s.department_id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }

    if (!empty($filter_date_from)) {
        $training_query .= " AND tss.session_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }

    if (!empty($filter_date_to)) {
        $training_query .= " AND tss.session_date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }

    if (!empty($filter_training_batch)) {
        $training_query .= " AND tss.training_batch_id = ?";
        $params[] = $filter_training_batch;
        $types .= "i";
    }

    if (!empty($filter_status)) {
        $training_query .= " AND tar.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
    
    $query .= $training_query;
    
    // Create or append to the count query for training records
    if (empty($count_query)) {
        $count_query = "SELECT COUNT(*) as count FROM (" . $training_query . ") as training_count";
    } else {
        // If we have both academic and training, we need to count both
        $count_query = "SELECT ((" . $count_query . ") + (SELECT COUNT(*) FROM (" . $training_query . ") as t)) as count";
    }
}

// First, get the total count
$total_records = 0;
$stmt = mysqli_prepare($conn, $count_query);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$count_result = mysqli_stmt_get_result($stmt);
$count_row = mysqli_fetch_assoc($count_result);
$total_records = $count_row['count'];
$total_pages = ceil($total_records / $records_per_page);

// If current page is greater than total pages, reset to first page
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = 1;
    $offset = 0;
}

// Add ORDER BY and LIMIT to the main query
$query .= " ORDER BY class_date DESC, start_time DESC, roll_number ASC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$types .= "i";
$params[] = $offset;
$types .= "i";

// Execute query with prepared statement
$attendance_records = [];
$stmt = mysqli_prepare($conn, $query);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $attendance_records[] = $row;
}

// Process bulk update if submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_records'])) {
    $updated_count = 0;
    $record_ids = isset($_POST['record_ids']) ? $_POST['record_ids'] : [];
    $record_types = isset($_POST['record_types']) ? $_POST['record_types'] : [];
    $record_statuses = isset($_POST['record_status']) ? $_POST['record_status'] : [];
    $record_remarks = isset($_POST['record_remarks']) ? $_POST['record_remarks'] : [];
    
    foreach ($record_ids as $record_id) {
        if (isset($record_statuses[$record_id]) && isset($record_types[$record_id])) {
            $status = $record_statuses[$record_id];
            $remark = isset($record_remarks[$record_id]) ? $record_remarks[$record_id] : '';
            $record_type = $record_types[$record_id];
            
            if ($record_type == 'academic') {
                $update_query = "UPDATE academic_attendance_records 
                                SET status = ?, remarks = ?
                                WHERE id = ?";
            } else {
                $update_query = "UPDATE training_attendance_records 
                                SET status = ?, remarks = ?
                                WHERE id = ?";
            }
            
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ssi", $status, $remark, $record_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $updated_count++;
            }
        }
    }
    
    if ($updated_count > 0) {
        $success = "$updated_count attendance records updated successfully.";
        
        // Log the action
        $admin_id = $_SESSION['user_id'];
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                    VALUES (?, 'admin', 'update_attendance_records', ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $details = json_encode(['count' => $updated_count]);
        mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
        mysqli_stmt_execute($log_stmt);
    } else {
        $error = "No records were updated.";
    }
}

// Delete attendance record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_record'])) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    if ($record_type == 'academic') {
        $delete_query = "DELETE FROM academic_attendance_records WHERE id = ?";
    } else {
        $delete_query = "DELETE FROM training_attendance_records WHERE id = ?";
    }
    
    $delete_stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($delete_stmt, "i", $record_id);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        $success = "Attendance record deleted successfully.";
        
        // Log the action
        $admin_id = $_SESSION['user_id'];
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                     VALUES (?, 'admin', 'delete_attendance_record', ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $details = json_encode(['record_id' => $record_id, 'record_type' => $record_type]);
        mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
        mysqli_stmt_execute($log_stmt);
        
        // Refresh the page to update the records list - preserve the current page
        $page_param = isset($_GET['page']) ? "&page=" . intval($_GET['page']) : "";
        header("Location: manage_attendance_records.php?department_id=$filter_department&date_from=$filter_date_from&date_to=$filter_date_to&subject_id=$filter_subject&training_batch_id=$filter_training_batch&status=$filter_status&type=$filter_type$page_param");
        exit();
    } else {
        $error = "Failed to delete attendance record: " . mysqli_error($conn);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance Records - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        :root {
            --primary-color: #3498db;  /* Blue theme for Attendance */
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

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
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

        .card-header h3 {
            margin: 0;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .card-body {
            padding: 1.5rem;
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

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
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

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%23555' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 16px 12px;
            padding-right: 2.5rem;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .table-responsive {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .table {
            width: 100%;
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

        .table thead th {
            background-color: rgba(52, 152, 219, 0.1);
            border-bottom: 2px solid rgba(52, 152, 219, 0.2);
            color: var(--primary-color);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .table tbody tr:hover {
            background-color: rgba(52, 152, 219, 0.05);
        }

        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0,0,0,0.02);
        }

        .table-bordered {
            border: none;
        }

        .table-bordered th,
        .table-bordered td {
            border: 1px solid rgba(0,0,0,0.05);
        }

        .badge {
            display: inline-block;
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        .badge-primary {
            background-color: #3498db;
            color: white;
        }

        .badge-info {
            background-color: #00b8d4;
            color: white;
        }

        .badge-success {
            background-color: #27ae60;
            color: white;
        }

        .badge-warning {
            background-color: #f39c12;
            color: white;
        }

        .badge-danger {
            background-color: #e74c3c;
            color: white;
        }

        /* Attendance specific styles */
        .status-select {
            padding: 0.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            width: 100%;
            cursor: pointer;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .status-select:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .remarks-input {
            width: 100%;
            padding: 0.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .remarks-input:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        /* Modal styles */
        .modal-content {
            background-color: var(--bg-color);
            border: none;
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .modal-header {
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 1.5rem;
            background-color: var(--bg-color);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            border-top: 1px solid rgba(0,0,0,0.1);
            padding: 1.5rem;
            background-color: var(--bg-color);
        }

        .close {
            background-color: transparent;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            opacity: 0.5;
            transition: opacity 0.3s;
        }

        .close:hover {
            opacity: 1;
        }

        /* Responsive styles */
        @media (max-width: 992px) {
            .container-fluid {
                padding: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .container-fluid {
                padding: 1rem;
            }
            
            .table-responsive {
                border-radius: 10px;
            }
            
            .btn {
                padding: 0.6rem 1rem;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .card-header div {
                width: 100%;
                display: flex;
                justify-content: space-between;
            }
        }

        @media (max-width: 576px) {
            .btn-sm {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .card-body {
                padding: 1rem;
            }
            
            .table th,
            .table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.9rem;
            }
        }

        /* Modify the form group for filter section to be more compact */
        .filter-section .form-group {
            margin-bottom: 0.75rem;
        }

        .filter-section label {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .filter-section .form-control {
            padding: 0.5rem 0.75rem;
            height: auto;
            font-size: 0.9rem;
        }

        /* Make the filter layout more compact */
        .filter-section .row {
            margin-right: -10px;
            margin-left: -10px;
        }

        .filter-section [class*="col-"] {
            padding-right: 10px;
            padding-left: 10px;
        }

        /* Fix the attendance table display */
        #attendanceTable {
            width: 100% !important;
        }

        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Make table more compact */
        #attendanceTable th,
        #attendanceTable td {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }

        /* Make the status select and remarks more compact */
        .status-select {
            padding: 0.4rem;
            width: 100%;
            max-width: 120px;
        }

        .remarks-input {
            padding: 0.4rem;
            width: 100%;
            max-width: 150px;
        }

        /* Fix any spacing issues in the card body */
        .card-body {
            padding: 1.25rem;
            overflow: hidden;
        }

        /* End of the CSS updates */

        /* Add styles for more compact filter layout */
        .filter-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        /* Fix for modal display issue */
        .modal {
            display: none;
        }
        
        .modal.show {
            display: block;
        }
        
        /* Fix for modal backdrop */
        .modal-backdrop {
            z-index: 1040;
        }
        
        .modal {
            z-index: 1050;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-row:last-child {
            margin-bottom: 0;
        }

        .filter-group {
            flex: 1;
            min-width: 180px;
            max-width: 220px;
        }

        .filter-section .form-group {
            margin-bottom: 0.75rem;
        }

        .filter-section label {
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .filter-section .form-control {
            padding: 0.5rem 0.75rem;
            height: auto;
            font-size: 0.9rem;
        }

        .btn-reset {
            padding: 0.6rem 1rem;
            background: var(--bg-color);
            color: var(--primary-color);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
        }

        /* Fix the attendance table display */
        .table-responsive {
            border-radius: 15px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin-bottom: 1rem;
        }

        .table {
            width: 100% !important;
            margin-bottom: 0;
        }

        /* Status select and remarks styling */
        .status-select {
            padding: 0.4rem;
            width: 100%;
            max-width: 120px;
            font-size: 0.9rem;
        }

        .remarks-input {
            padding: 0.4rem;
            width: 100%;
            max-width: 150px;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .filter-group {
                min-width: 45%;
            }
        }

        @media (max-width: 576px) {
            .filter-group {
                min-width: 100%;
                max-width: 100%;
            }
        }

        /* Update card header and body for the attendance table */
        .card-body {
            padding: 1.25rem;
        }

        .card-header {
            padding: 1rem 1.25rem;
        }

        .table th, .table td {
            padding: 0.75rem 0.5rem;
            vertical-align: middle;
        }

        /* Add these styles for attendance status coloring */
        .present {
            background: rgba(46, 204, 113, 0.2) !important;
        }

        .absent {
            background: rgba(231, 76, 60, 0.2) !important;
        }

        .late {
            background: rgba(243, 156, 18, 0.2) !important;
        }

        .excused {
            background: rgba(52, 152, 219, 0.2) !important;
        }

        .status-updated {
            animation: pulse-highlight 2s !important;
        }

        @keyframes pulse-highlight {
            0% { background-color: transparent; }
            30% { background-color: rgba(46, 204, 113, 0.4); }
            100% { background-color: transparent; }
        }
        
        /* Make sure DataTables doesn't override our status colors */
        table.dataTable tbody tr.present,
        table.dataTable tbody tr.absent,
        table.dataTable tbody tr.late,
        table.dataTable tbody tr.excused {
            color: inherit;
        }
        
        table.dataTable tbody tr.present td,
        table.dataTable tbody tr.absent td,
        table.dataTable tbody tr.late td,
        table.dataTable tbody tr.excused td {
            border-bottom-color: rgba(0,0,0,0.07);
        }

        /* Pagination styles */
        .pagination-container {
            margin: 1.5rem 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 1rem;
            width: 100%;
        }

        .pagination-form {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            width: 100%;
        }

        .pagination-btn {
            min-width: 40px;
            height: 40px;
            border-radius: 8px;
            border: none;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin: 0.25rem;
        }

        .pagination-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .pagination-btn.active {
            background: var(--primary-color);
            color: white;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn.disabled:hover {
            transform: none;
            box-shadow: var(--shadow);
        }

        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.5rem;
            color: var(--text-color);
            height: 40px;
        }

        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 480px) {
            .pagination-btn {
                min-width: 36px;
                height: 36px;
                font-size: 0.85rem;
                margin: 0.15rem;
            }
            
            .pagination-ellipsis {
                padding: 0 0.25rem;
                height: 36px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-12">
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i> <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $error; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Filter Attendance Records</h3>
                    </div>
                    <div class="card-body">
                        <form method="get" action="">
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="department_id">Department</label>
                                    <select class="form-control" id="department_id" name="department_id">
                                        <option value="">All Departments</option>
                                        <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" <?php echo ($filter_department == $dept['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="date_from">From Date</label>
                                    <input type="date" class="form-control date-picker" id="date_from" name="date_from" value="<?php echo $filter_date_from; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="date_to">To Date</label>
                                    <input type="date" class="form-control date-picker" id="date_to" name="date_to" value="<?php echo $filter_date_to; ?>">
                                </div>
                                
                                <div class="filter-group">
                                    <label for="status">Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="present" <?php echo ($filter_status == 'present') ? 'selected' : ''; ?>>Present</option>
                                        <option value="absent" <?php echo ($filter_status == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                        <option value="late" <?php echo ($filter_status == 'late') ? 'selected' : ''; ?>>Late</option>
                                        <option value="excused" <?php echo ($filter_status == 'excused') ? 'selected' : ''; ?>>Excused</option>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="type">Class Type</label>
                                    <select class="form-control" id="type" name="type">
                                        <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>All Types</option>
                                        <option value="regular" <?php echo ($filter_type == 'regular') ? 'selected' : ''; ?>>Regular Classes</option>
                                        <option value="placement" <?php echo ($filter_type == 'placement') ? 'selected' : ''; ?>>Placement Training</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-row">
                                <div class="filter-group">
                                    <label for="subject_id">Subject</label>
                                    <select class="form-control" id="subject_id" name="subject_id">
                                        <option value="">All Subjects</option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?php echo $subject['id']; ?>" <?php echo ($filter_subject == $subject['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <label for="training_batch_id">Training Batch</label>
                                    <select class="form-control" id="training_batch_id" name="training_batch_id">
                                        <option value="">All Training Batches</option>
                                        <?php foreach ($training_batches as $batch): ?>
                                            <option value="<?php echo $batch['id']; ?>" <?php echo ($filter_training_batch == $batch['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="filter-group">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="fas fa-filter"></i> Apply
                                    </button>
                                </div>
                                
                                <div class="filter-group">
                                    <a href="manage_attendance_records.php" class="btn btn-secondary btn-block">
                                        <i class="fas fa-undo"></i> Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <h3 class="card-title">Attendance Records</h3>
                        <div>
                            <button type="button" class="btn btn-success btn-sm" onclick="document.getElementById('bulkUpdateForm').submit();">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" class="btn btn-info btn-sm" id="exportBtn">
                                <i class="fas fa-file-export"></i> Export to Excel
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($attendance_records) > 0): ?>
                            <form id="bulkUpdateForm" method="post" action="">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped" id="attendanceTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Roll Number</th>
                                                <th>Student Name</th>
                                                <th>Department</th>
                                                <th>Subject / Training</th>
                                                <th>Venue</th>
                                                <th>Status</th>
                                                <th>Remarks</th>
                                                <th>Marked By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attendance_records as $record): ?>
                                                <tr class="<?php echo $record['status']; ?>">
                                                    <td><?php echo date('d-m-Y', strtotime($record['class_date'])); ?></td>
                                                    <td>
                                                        <?php echo date('h:i A', strtotime($record['start_time'])) . ' - ' . 
                                                                  date('h:i A', strtotime($record['end_time'])); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['roll_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['department_name']); ?></td>
                                                    <td>
                                                        <?php if ($record['record_type'] == 'training'): ?>
                                                            <span class="badge badge-info">Training</span>
                                                            <?php echo htmlspecialchars($record['topic'] ?? $record['training_batch_name']); ?>
                                                        <?php else: ?>
                                                            <span class="badge badge-primary">Academic</span>
                                                            <?php echo htmlspecialchars($record['subject_name'] . ' (' . $record['subject_code'] . ')'); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($record['venue_name']); ?>
                                                        <?php if (!empty($record['room_number'])): ?>
                                                            (<?php echo htmlspecialchars($record['room_number']); ?>)
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="record_ids[]" value="<?php echo $record['id']; ?>">
                                                        <input type="hidden" name="record_types[<?php echo $record['id']; ?>]" value="<?php echo $record['record_type']; ?>">
                                                        <select name="record_status[<?php echo $record['id']; ?>]" class="status-select" data-status="<?php echo $record['status']; ?>">
                                                            <option value="present" <?php echo $record['status'] == 'present' ? 'selected' : ''; ?>>Present</option>
                                                            <option value="absent" <?php echo $record['status'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                            <option value="late" <?php echo $record['status'] == 'late' ? 'selected' : ''; ?>>Late</option>
                                                            <option value="excused" <?php echo $record['status'] == 'excused' ? 'selected' : ''; ?>>Excused</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="record_remarks[<?php echo $record['id']; ?>]" 
                                                              value="<?php echo htmlspecialchars($record['remarks'] ?? ''); ?>" 
                                                              class="remarks-input" placeholder="Remarks">
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['marked_by_name']); ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger delete-record" 
                                                               data-id="<?php echo $record['id']; ?>" 
                                                               data-type="<?php echo $record['record_type']; ?>"
                                                               data-roll="<?php echo htmlspecialchars($record['roll_number']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <input type="hidden" name="update_records" value="1">
                            </form>
                            
                            <!-- Pagination controls -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination-container">
                                <div class="pagination-info">
                                    Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $records_per_page, $total_records); ?> 
                                    of <?php echo $total_records; ?> records
                                </div>
                                
                                <form method="get" action="" class="pagination-form">
                                    <!-- Preserve all current filters in pagination -->
                                    <?php if (!empty($filter_department)): ?>
                                        <input type="hidden" name="department_id" value="<?php echo $filter_department; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_from)): ?>
                                        <input type="hidden" name="date_from" value="<?php echo $filter_date_from; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_date_to)): ?>
                                        <input type="hidden" name="date_to" value="<?php echo $filter_date_to; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_subject)): ?>
                                        <input type="hidden" name="subject_id" value="<?php echo $filter_subject; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_training_batch)): ?>
                                        <input type="hidden" name="training_batch_id" value="<?php echo $filter_training_batch; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_status)): ?>
                                        <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($filter_type)): ?>
                                        <input type="hidden" name="type" value="<?php echo $filter_type; ?>">
                                    <?php endif; ?>
                                    
                                    <div class="pagination">
                                        <!-- Previous page button -->
                                        <?php if ($current_page > 1): ?>
                                            <button type="submit" name="page" value="<?php echo $current_page - 1; ?>" class="pagination-btn">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="pagination-btn disabled">
                                                <i class="fas fa-chevron-left"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <!-- Page numbers -->
                                        <?php
                                        $start_page = max(1, $current_page - 2);
                                        $end_page = min($total_pages, $start_page + 4);
                                        if ($end_page - $start_page < 4 && $total_pages > 5) {
                                            $start_page = max(1, $end_page - 4);
                                        }
                                        
                                        if ($start_page > 1): ?>
                                            <button type="submit" name="page" value="1" class="pagination-btn">1</button>
                                            <?php if ($start_page > 2): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <button type="submit" name="page" value="<?php echo $i; ?>" 
                                                    class="pagination-btn <?php echo $i == $current_page ? 'active' : ''; ?>">
                                                <?php echo $i; ?>
                                            </button>
                                        <?php endfor; ?>
                                        
                                        <?php if ($end_page < $total_pages): ?>
                                            <?php if ($end_page < $total_pages - 1): ?>
                                                <span class="pagination-ellipsis">...</span>
                                            <?php endif; ?>
                                            <button type="submit" name="page" value="<?php echo $total_pages; ?>" class="pagination-btn">
                                                <?php echo $total_pages; ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <!-- Next page button -->
                                        <?php if ($current_page < $total_pages): ?>
                                            <button type="submit" name="page" value="<?php echo $current_page + 1; ?>" class="pagination-btn">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="pagination-btn disabled">
                                                <i class="fas fa-chevron-right"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i> No attendance records found matching the selected criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this attendance record? This action cannot be undone.</p>
                    <p class="font-weight-bold" id="deleteStudentInfo"></p>
                </div>
                <div class="modal-footer">
                    <form method="post" action="" id="deleteForm">
                        <input type="hidden" name="record_id" id="recordToDelete" value="">
                        <input type="hidden" name="record_type" id="recordTypeToDelete" value="">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_record" class="btn btn-danger">
                            <i class="fas fa-trash mr-1"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        $(document).ready(function() {
            // Make sure the delete modal is hidden by default
            $('#deleteModal').modal('hide');
            
            // Initialize DataTable
            var table = $('#attendanceTable').DataTable({
                "lengthMenu": [[50, 100, 200, -1], [50, 100, 200, "All"]],
                "order": [[0, "desc"], [1, "desc"]],
                "pageLength": 50,
                "responsive": true,
                "dom": 'Bfrtip',
                "buttons": [
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel"></i> Export Excel',
                        className: 'btn btn-success',
                        exportOptions: {
                            columns: [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]
                        },
                        filename: 'Attendance_Records_Export'
                    }
                ],
                "language": {
                    "search": "_INPUT_",
                    "searchPlaceholder": "Search records..."
                },
                "paging": false, // Disable DataTables paging as we're using custom pagination
                "drawCallback": function() {
                    // Reapply status classes to rows after DataTable redraws
                    $('#attendanceTable tbody tr').each(function() {
                        const statusSelect = $(this).find('.status-select');
                        if (statusSelect.length) {
                            const status = statusSelect.val();
                            $(this).removeClass('present absent late excused').addClass(status);
                        }
                    });
                }
            });
            
            // Hide default export button and use our custom one
            $('.dt-buttons').hide();
            
            $('#exportBtn').on('click', function() {
                $('.buttons-excel').click();
            });
            
            // Initialize flatpickr for date inputs
            $('.date-picker').flatpickr({
                dateFormat: "Y-m-d"
            });
            
            // Handle delete record modal
            $('.delete-record').click(function() {
                var recordId = $(this).data('id');
                var recordType = $(this).data('type');
                var rollNumber = $(this).data('roll');
                
                $('#recordToDelete').val(recordId);
                $('#recordTypeToDelete').val(recordType);
                $('#deleteStudentInfo').text('Roll Number: ' + rollNumber);
                
                // Show the modal programmatically
                $('#deleteModal').modal('show');
            });
            
            // Apply row background coloring based on status
            $('#attendanceTable tbody tr').each(function() {
                // Get status from either data attribute or select value
                const statusSelect = $(this).find('.status-select');
                if (statusSelect.length) {
                    const status = statusSelect.val();
                    $(this).removeClass('present absent late excused');
                    $(this).addClass(status);
                    
                    // Make sure the row color is visible by removing any conflicting classes
                    $(this).removeClass('odd even');
                }
            });
            
            // Event handler for status changes
            $('.status-select').on('change', function() {
                var value = $(this).val();
                var row = $(this).closest('tr');
                
                // Remove all status classes and add the new one
                row.removeClass('present absent late excused odd even');
                row.addClass(value);
                
                // Add animation class to highlight the change
                row.addClass('status-updated');
                setTimeout(function() {
                    row.removeClass('status-updated');
                }, 2000);
                
                // Update the select element color
                updateStatusColor($(this), value);
            });
            
            // Function to update status colors
            function updateStatusColor(element, value) {
                element.css('color', '');
                
                if (value === 'present') {
                    element.css('color', '#27ae60');
                } else if (value === 'absent') {
                    element.css('color', '#e74c3c');
                } else if (value === 'late') {
                    element.css('color', '#f39c12');
                } else if (value === 'excused') {
                    element.css('color', '#3498db');
                }
            }
            
            // Initialize status select colors
            $('.status-select').each(function() {
                var value = $(this).val();
                updateStatusColor($(this), value);
            });
        });
    </script>
</body>
</html> 