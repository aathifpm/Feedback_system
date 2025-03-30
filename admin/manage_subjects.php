<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$success_msg = $error_msg = '';

// Handle subject operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $code = mysqli_real_escape_string($conn, $_POST['code']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $department_id = intval($_POST['department_id']);
                    $credits = intval($_POST['credits']);
                    
                    // Begin transaction
                    mysqli_begin_transaction($conn);
                    
                    // Check for existing subject code
                    $check_query = "SELECT id FROM subjects WHERE code = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "s", $code);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Subject code already exists!");
                    }

                    // Insert the subject
                    $query = "INSERT INTO subjects (code, name, department_id, credits, is_active) 
                             VALUES (?, ?, ?, ?, TRUE)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssii", 
                        $code,
                        $name,
                        $department_id,
                        $credits
                    );
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error adding subject!");
                    }

                    $subject_id = mysqli_insert_id($conn);

                    // Add initial assignment if provided
                    if (isset($_POST['faculty_id']) && isset($_POST['academic_year_id'])) {
                        $faculty_id = intval($_POST['faculty_id']);
                        $academic_year_id = intval($_POST['academic_year_id']);
                        $year = intval($_POST['year']);
                        $semester = intval($_POST['semester']);
                        $section = mysqli_real_escape_string($conn, $_POST['section']);

                        $assignment_query = "INSERT INTO subject_assignments 
                                           (subject_id, faculty_id, academic_year_id, year, semester, section) 
                                           VALUES (?, ?, ?, ?, ?, ?)";
                        
                        $stmt = mysqli_prepare($conn, $assignment_query);
                        mysqli_stmt_bind_param($stmt, "iiiiss", 
                            $subject_id,
                            $faculty_id,
                            $academic_year_id,
                            $year,
                            $semester,
                            $section
                        );

                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Error adding subject assignment!");
                        }
                    }

                    mysqli_commit($conn);
                    $success_msg = "Subject added successfully!";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error_msg = $e->getMessage();
                }
                break;
            case 'edit':
                try {
                    $id = intval($_POST['id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $department_id = intval($_POST['department_id']);
                    $credits = intval($_POST['credits']);

                    // Begin transaction
                    mysqli_begin_transaction($conn);

                    // Update subject details
                    $update_query = "UPDATE subjects SET 
                                    name = ?,
                                    department_id = ?,
                                    credits = ?
                                    WHERE id = ?";
                    
                    $stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt, "siii", 
                        $name,
                        $department_id,
                        $credits,
                        $id
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error updating subject!");
                    }

                    // Handle assignments if provided
                    if (isset($_POST['assignments']) && is_array($_POST['assignments'])) {
                        foreach ($_POST['assignments'] as $assignment) {
                            $faculty_id = intval($assignment['faculty_id']);
                            $academic_year_id = intval($assignment['academic_year_id']);
                            $year = intval($assignment['year']);
                            $semester = intval($assignment['semester']);
                            $section = mysqli_real_escape_string($conn, $assignment['section']);

                            $assignment_query = "INSERT INTO subject_assignments 
                                               (subject_id, faculty_id, academic_year_id, year, semester, section)
                                               VALUES (?, ?, ?, ?, ?, ?)
                                               ON DUPLICATE KEY UPDATE
                                               faculty_id = VALUES(faculty_id)";
                            
                            $stmt = mysqli_prepare($conn, $assignment_query);
                            mysqli_stmt_bind_param($stmt, "iiiiss", 
                                $id,
                                $faculty_id,
                                $academic_year_id,
                                $year,
                                $semester,
                                $section
                            );

                            if (!mysqli_stmt_execute($stmt)) {
                                throw new Exception("Error updating subject assignments!");
                            }
                        }
                    }

                    mysqli_commit($conn);
                    $success_msg = "Subject updated successfully!";

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error_msg = $e->getMessage();
                }
                break;

            case 'toggle_status':
                try {
                    $id = intval($_POST['id']);
                    $status = $_POST['status'] === 'true';
                    
                    $query = "UPDATE subjects SET is_active = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $status, $id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                    VALUES (?, 'admin', ?, ?, ?, ?)";
                        $action = $status ? 'activate_subject' : 'deactivate_subject';
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode(['subject_id' => $id]);
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        
                        mysqli_stmt_bind_param($log_stmt, "issss", 
                            $_SESSION['user_id'], 
                            $action,
                            $details,
                            $ip_address,
                            $user_agent
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Subject status updated successfully!";
                    } else {
                        throw new Exception("Error updating subject status!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'toggle_assignment_status':
                try {
                    $assignment_id = intval($_POST['assignment_id']);
                    $status = $_POST['status'] === 'true';
                    
                    $query = "UPDATE subject_assignments SET is_active = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $status, $assignment_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', ?, ?, ?, ?)";
                        $action = $status ? 'activate_subject_assignment' : 'deactivate_subject_assignment';
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode(['assignment_id' => $assignment_id]);
                        
                        mysqli_stmt_bind_param($log_stmt, "issss", 
                            $_SESSION['user_id'], 
                            $action,
                            $details,
                            $_SERVER['REMOTE_ADDR'],
                            $_SERVER['HTTP_USER_AGENT']
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Assignment status updated successfully!";
                    } else {
                        throw new Exception("Error updating assignment status!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'add_assignment':
                header('Content-Type: application/json');
                try {
                    // Validate and sanitize inputs
                    $subject_id = filter_var($_POST['subject_id'], FILTER_VALIDATE_INT);
                    $faculty_id = filter_var($_POST['faculty_id'], FILTER_VALIDATE_INT);
                    $academic_year_id = filter_var($_POST['academic_year_id'], FILTER_VALIDATE_INT);
                    $year = filter_var($_POST['year'], FILTER_VALIDATE_INT);
                    $semester = filter_var($_POST['semester'], FILTER_VALIDATE_INT);
                    $section = mysqli_real_escape_string($conn, $_POST['section']);

                    // Validate all required fields
                    if (!$subject_id || !$faculty_id || !$academic_year_id || 
                        !$year || !$semester || empty($section)) {
                        throw new Exception("All fields are required and must be valid");
                    }

                    // Begin transaction
                    mysqli_begin_transaction($conn);

                    // Check if subject exists and is active
                    $check_subject = "SELECT id FROM subjects WHERE id = ? AND is_active = 1";
                    $stmt = mysqli_prepare($conn, $check_subject);
                    mysqli_stmt_bind_param($stmt, "i", $subject_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (!mysqli_fetch_assoc($result)) {
                        mysqli_stmt_close($stmt);
                        throw new Exception("Invalid subject selected");
                    }
                    mysqli_stmt_close($stmt);
                    mysqli_free_result($result);

                    // Check for duplicate assignment
                    $check_query = "SELECT id FROM subject_assignments 
                                   WHERE subject_id = ? AND academic_year_id = ? 
                                   AND year = ? AND semester = ? AND section = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "iiiis", 
                        $subject_id, $academic_year_id, $year, $semester, $section);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_fetch_assoc($result)) {
                        mysqli_stmt_close($stmt);
                        mysqli_free_result($result);
                        throw new Exception("Assignment already exists for this combination");
                    }
                    mysqli_stmt_close($stmt);
                    mysqli_free_result($result);

                    // Insert new assignment
                    $query = "INSERT INTO subject_assignments 
                             (subject_id, faculty_id, academic_year_id, year, semester, section, is_active) 
                             VALUES (?, ?, ?, ?, ?, ?, 1)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iiiiss", 
                        $subject_id, $faculty_id, $academic_year_id, $year, $semester, $section);

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error adding assignment: " . mysqli_error($conn));
                    }
                    
                    mysqli_stmt_close($stmt);
                    
                    // Log the action
                    $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                 VALUES (?, 'admin', 'add_subject_assignment', ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $log_query);
                    $details = json_encode([
                        'subject_id' => $subject_id,
                        'faculty_id' => $faculty_id,
                        'academic_year_id' => $academic_year_id,
                        'year' => $year,
                        'semester' => $semester,
                        'section' => $section
                    ]);
                    mysqli_stmt_bind_param($stmt, "isss", 
                        $_SESSION['user_id'], 
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    );
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);

                    mysqli_commit($conn);
                    echo json_encode(['success' => true, 'message' => 'Assignment added successfully!']);
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                }
                exit;
                break;
        }
    }
}

// Fetch departments for dropdown
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$departments = mysqli_query($conn, $dept_query);

// Fetch faculty for dropdown
$faculty_query = "SELECT id, name, faculty_id FROM faculty WHERE is_active = TRUE ORDER BY name";
$faculty_members = mysqli_query($conn, $faculty_query);

// Fetch academic years for dropdown
$academic_year_query = "SELECT id, year_range FROM academic_years ORDER BY start_date DESC";
$academic_years = mysqli_query($conn, $academic_year_query);

// Fetch subjects with related information
$subjects_query = "SELECT 
    s.id,
    s.code,
    s.name,
    s.department_id,
    s.credits,
    s.is_active,
    d.name as department_name,
    COUNT(DISTINCT sa.id) as assignment_count,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
FROM subjects s
LEFT JOIN departments d ON s.department_id = d.id
LEFT JOIN subject_assignments sa ON s.id = sa.subject_id AND sa.is_active = TRUE
LEFT JOIN feedback fb ON fb.assignment_id = sa.id
GROUP BY s.id, s.code, s.name, s.department_id, s.credits, s.is_active, d.name
ORDER BY s.code";

$subjects_result = mysqli_query($conn, $subjects_query);

function getSubjectAssignments($conn, $subject_id) {
    $query = "SELECT 
        sa.*,
        f.name as faculty_name,
        ay.year_range as academic_year,
        (SELECT COUNT(*) FROM feedback fb WHERE fb.assignment_id = sa.id) as feedback_count
    FROM subject_assignments sa
    JOIN faculty f ON sa.faculty_id = f.id
    JOIN academic_years ay ON sa.academic_year_id = ay.id
    WHERE sa.subject_id = ?
    ORDER BY sa.academic_year_id DESC, sa.year, sa.semester, sa.section";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #2ecc71;  /* Green theme for Subjects */
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

        /* Subject Grid Styles */
        .subject-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .subject-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 320px;
            max-width: 450px;
            margin: 0 auto;
            width: 100%;
        }

        .subject-card:hover {
            transform: translateY(-5px);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .subject-info {
            flex: 1;
        }

        .subject-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .subject-code {
            font-size: 0.9rem;
            color: #666;
            padding: 0.3rem 0.8rem;
            background: var(--bg-color);
            border-radius: 20px;
            box-shadow: var(--inner-shadow);
            display: inline-block;
        }

        .status-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .subject-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            flex: 1;
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

        .subject-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .stat-item {
            text-align: center;
            padding: 0.5rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.3rem;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #666;
        }

        .subject-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: auto;
            padding-top: 1rem;
        }

        .btn-action {
            flex: 1;
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
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
        }

        /* Filter Section Styles */
        .filter-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-family: inherit;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        /* Modal Styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }

        /* Form Row Style for better organization */
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .subject-grid {
                grid-template-columns: 1fr;
            }

            .subject-actions {
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
            }

            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .subject-card {
                max-width: 100%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                margin: 1rem;
                padding: 1.5rem;
            }
        }

        .assignment-row {
            position: relative;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 10px;
        }

        .input-group {
            display: flex;
            gap: 0.5rem;
        }

        .btn-remove-assignment {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
        }

        .assignments {
            margin-bottom: 1rem;
        }

        /* Add these styles to your existing CSS */
        .form-control.error {
            border: 1px solid #dc3545;
            box-shadow: var(--inner-shadow), 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
        }

        .form-control.valid {
            border: 1px solid #28a745;
            box-shadow: var(--inner-shadow), 0 0 0 0.2rem rgba(40, 167, 69, 0.25);
        }

        .validation-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
            padding-left: 0.5rem;
        }

        /* Add a tooltip for format explanation */
        .form-group {
            position: relative;
        }

        .form-group input[name="code"] {
            padding-right: 30px;
        }

        .form-group input[name="code"] + .validation-hint::after {
            content: "ℹ️";
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: help;
        }

        .form-group input[name="code"]:focus + .validation-hint {
            color: var(--primary-color);
        }

        .error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }

        .assignment-row.error {
            border-color: #dc3545;
        }

        .validation-message {
            color: #dc3545;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }

        /* Add to your existing CSS */
        .current-assignments {
            margin: 1.5rem 0;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .current-assignments h3 {
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .current-assignment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            margin-bottom: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        .assignment-details {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: center;
        }

        .assignment-details span {
            padding: 0.3rem 0.8rem;
            background: var(--bg-color);
            border-radius: 20px;
            font-size: 0.9rem;
            box-shadow: var(--inner-shadow);
        }

        .assignment-actions {
            display: flex;
            gap: 0.5rem;
        }

        .input-group {
            display: flex;
            gap: 0.5rem;
        }

        .btn-remove-assignment {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
        }

        .assignments {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Subjects</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Subject
            </button>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-row">
                <div class="filter-group">
                    <select id="departmentFilter" class="form-control">
                        <option value="">All Departments</option>
                        <?php
                        mysqli_data_seek($departments, 0);
                        while ($dept = mysqli_fetch_assoc($departments)): ?>
                            <option value="<?php echo $dept['id']; ?>">
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select id="facultyFilter" class="form-control">
                        <option value="">All Faculty</option>
                        <?php
                        mysqli_data_seek($faculty_members, 0);
                        while ($faculty = mysqli_fetch_assoc($faculty_members)): ?>
                            <option value="<?php echo $faculty['id']; ?>">
                                <?php echo htmlspecialchars($faculty['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="yearFilter" class="form-control">
                        <option value="">All Years</option>
                        <?php for($i = 1; $i <= 4; $i++): ?>
                            <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <input type="text" id="searchInput" placeholder="Search subjects..." class="form-control">
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <select id="semesterFilter" class="form-control">
                        <option value="">All Semesters</option>
                        <?php for($i = 1; $i <= 8; $i++): ?>
                            <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="sectionFilter" class="form-control">
                        <option value="">All Sections</option>
                        <?php for($i = 65; $i <= 70; $i++): // A to F ?>
                            <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="statusFilter" class="form-control">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <button class="btn btn-reset" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <!-- Subject Grid -->
        <div class="subject-grid">
            <?php while ($subject = mysqli_fetch_assoc($subjects_result)): ?>
                <div class="subject-card" 
                     data-department="<?php echo $subject['department_id']; ?>"
                     data-faculty="<?php echo $subject['faculty_id'] ?? ''; ?>"
                     data-year="<?php echo $subject['min_year'] ?? ''; ?>"
                     data-semester="<?php echo $subject['min_semester'] ?? ''; ?>"
                     data-section="<?php echo $subject['min_section'] ?? ''; ?>"
                     data-status="<?php echo $subject['is_active'] ? '1' : '0'; ?>">
                    <div class="subject-header">
                        <div class="subject-info">
                            <h3 class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></h3>
                            <span class="subject-code"><?php echo htmlspecialchars($subject['code']); ?></span>
                        </div>
                        <span class="status-badge <?php echo $subject['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $subject['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="subject-details">
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($subject['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Credits</span>
                            <span class="detail-value"><?php echo $subject['credits']; ?></span>
                        </div>
                    </div>

                    <div class="subject-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $subject['assignment_count']; ?></div>
                            <div class="stat-label">Assignments</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $subject['feedback_count'] ?: 0; ?></div>
                            <div class="stat-label">Feedbacks</div>
                        </div>
                    </div>

                    <?php 
                    $assignments = getSubjectAssignments($conn, $subject['id']);
                    if (mysqli_num_rows($assignments) > 0): 
                    ?>
                    <div class="current-assignments">
                        <h3>Current Assignments</h3>
                        <?php while($assignment = mysqli_fetch_assoc($assignments)): ?>
                            <div class="current-assignment-item">
                                <div class="assignment-details">
                                    <span>Year <?php echo $assignment['year']; ?></span>
                                    <span>Sem <?php echo $assignment['semester']; ?></span>
                                    <span>Section <?php echo $assignment['section']; ?></span>
                                    <span><?php echo htmlspecialchars($assignment['faculty_name']); ?></span>
                                </div>
                                <div class="assignment-actions">
                                    <button class="btn-action" onclick="toggleAssignmentStatus(<?php echo $assignment['id']; ?>, <?php echo $assignment['is_active']; ?>)">
                                        <i class="fas fa-<?php echo $assignment['is_active'] ? 'times' : 'check'; ?>"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                    <?php endif; ?>

                    <div class="subject-actions">
                        <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($subject)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action" onclick="toggleSubjectStatus(<?php echo $subject['id']; ?>, <?php echo $subject['is_active']; ?>)">
                            <i class="fas fa-power-off"></i> <?php echo $subject['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="btn-action" onclick="showAddAssignmentModal(<?php echo $subject['id']; ?>)">
                            <i class="fas fa-plus"></i> Add Assignment
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Add Subject</h2>
            <form method="POST" onsubmit="return validateForm(this);">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="code">Subject Code</label>
                        <input type="text" id="code" name="code" class="form-control" required pattern="^\d{2}[A-Z]{2,4}\d{4}$">
                        <div class="validation-hint">Format: YY + DEPT + NUMBER (e.g., 21AD1501)</div>
                    </div>
                    <div class="form-group">
                        <label for="name">Subject Name</label>
                        <input type="text" id="name" name="name" class="form-control" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="department_id">Department</label>
                        <select id="department_id" name="department_id" class="form-control" required>
                            <option value="">Select Department</option>
                            <?php 
                            mysqli_data_seek($departments, 0);
                            while ($dept = mysqli_fetch_assoc($departments)): 
                            ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="faculty_id">Faculty</label>
                        <select id="faculty_id" name="faculty_id" class="form-control" required>
                            <option value="">Select Faculty</option>
                            <?php 
                            mysqli_data_seek($faculty_members, 0);
                            while ($faculty = mysqli_fetch_assoc($faculty_members)): 
                            ?>
                                <option value="<?php echo $faculty['id']; ?>">
                                    <?php echo htmlspecialchars($faculty['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="academic_year_id">Academic Year</label>
                        <select id="academic_year_id" name="academic_year_id" class="form-control" required>
                            <option value="">Select Academic Year</option>
                            <?php 
                            mysqli_data_seek($academic_years, 0);
                            while ($year = mysqli_fetch_assoc($academic_years)): 
                            ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="year">Year</label>
                        <select id="year" name="year" class="form-control" required>
                            <option value="">Select Year</option>
                            <?php for($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="semester">Semester</label>
                        <select id="semester" name="semester" class="form-control" required>
                            <option value="">Select Semester</option>
                            <?php for($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="section">Section</label>
                        <select id="section" name="section" class="form-control" required>
                            <option value="">Select Section</option>
                            <?php for($i = 65; $i <= 70; $i++): ?>
                                <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="credits">Credits</label>
                        <input type="number" id="credits" name="credits" class="form-control" min="1" max="5" required>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Subject
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Subject</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <!-- Basic Subject Info -->
                <div class="form-group">
                    <label for="edit_code">Subject Code</label>
                    <input type="text" id="edit_code" name="code" class="form-control" readonly>
                    <div class="validation-hint">Subject code cannot be changed</div>
                </div>

                <div class="form-group">
                    <label for="edit_name">Subject Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>

                <!-- Current Assignments Section -->
                <div class="current-assignments">
                    <h3>Current Assignments</h3>
                    <div id="currentAssignmentsList">
                        <!-- Will be populated dynamically -->
                    </div>
                </div>

                <!-- Add New Assignment Section -->
                <div class="form-group">
                    <h3>Add New Assignment</h3>
                    <div class="assignments">
                        <div class="assignment-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Year & Semester</label>
                                    <div class="input-group">
                                        <select name="years[]" class="form-control" required>
                                            <option value="">Select Year</option>
                                            <?php for($i = 1; $i <= 4; $i++): ?>
                                                <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select name="semesters[]" class="form-control" required>
                                            <option value="">Select Semester</option>
                                            <?php for($i = 1; $i <= 8; $i++): ?>
                                                <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Section & Faculty</label>
                                    <div class="input-group">
                                        <select name="sections[]" class="form-control" required>
                                            <option value="">Select Section</option>
                                            <?php for($i = 65; $i <= 75; $i++): ?>
                                                <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                                            <?php endfor; ?>
                                        </select>
                                        <select name="faculty_ids[]" class="form-control" required>
                                            <option value="">Select Faculty</option>
                                            <?php 
                                            mysqli_data_seek($faculty_members, 0);
                                            while ($faculty = mysqli_fetch_assoc($faculty_members)): 
                                            ?>
                                                <option value="<?php echo $faculty['id']; ?>">
                                                    <?php echo htmlspecialchars($faculty['name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="addNewAssignment()">
                        <i class="fas fa-plus"></i> Add Another Assignment
                    </button>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Subject
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Assignment Modal -->
    <div id="addAssignmentModal" class="modal">
        <div class="modal-content">
            <h2>Add New Assignment</h2>
            <form id="newAssignmentForm">
                <input type="hidden" name="action" value="add_assignment">
                <div class="form-row">
                    <div class="form-group">
                        <label for="faculty_id">Faculty</label>
                        <select name="faculty_id" class="form-control" required>
                            <option value="">Select Faculty</option>
                            <?php 
                            mysqli_data_seek($faculty_members, 0);
                            while($faculty = mysqli_fetch_assoc($faculty_members)): 
                            ?>
                                <option value="<?php echo $faculty['id']; ?>">
                                    <?php echo htmlspecialchars($faculty['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="academic_year_id">Academic Year</label>
                        <select name="academic_year_id" class="form-control" required>
                            <option value="">Select Academic Year</option>
                            <?php 
                            mysqli_data_seek($academic_years, 0);
                            while($year = mysqli_fetch_assoc($academic_years)): 
                            ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="year">Year</label>
                        <select name="year" class="form-control" required>
                            <option value="">Select Year</option>
                            <?php for($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>">Year <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="semester">Semester</label>
                        <select name="semester" class="form-control" required>
                            <option value="">Select Semester</option>
                            <?php for($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="section">Section</label>
                        <select name="section" class="form-control" required>
                            <option value="">Select Section</option>
                            <?php foreach(range('A', 'E') as $section): ?>
                                <option value="<?php echo $section; ?>">Section <?php echo $section; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">Add Assignment</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('addAssignmentModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Modal handling functions
        function showAddModal() {
            const modal = document.getElementById('addModal');
            modal.style.display = 'flex';
            document.querySelector('#addModal form').reset();
        }

        function showEditModal(subject) {
            const modal = document.getElementById('editModal');
            
            // Set form values
            document.getElementById('edit_id').value = subject.id;
            document.getElementById('edit_code').value = subject.code;
            document.getElementById('edit_name').value = subject.name;
            document.getElementById('edit_department_id').value = subject.department_id;
            document.getElementById('edit_faculty_id').value = subject.faculty_id;
            document.getElementById('edit_academic_year_id').value = subject.academic_year_id;
            document.getElementById('edit_year').value = subject.year;
            document.getElementById('edit_semester').value = subject.semester;
            document.getElementById('edit_section').value = subject.section;
            document.getElementById('edit_credits').value = subject.credits;
            
            modal.style.display = 'flex';
        }

        function hideModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal').forEach(modal => {
                    modal.style.display = 'none';
                });
            }
        });

        function toggleStatus(id, status) {
            if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this subject?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewFeedback(id) {
            window.location.href = `view_subject_feedback.php?subject_id=${id}`;
        }

        // Filter functionality
        document.addEventListener('DOMContentLoaded', function() {
            const subjectCards = document.querySelectorAll('.subject-card');
            const searchInput = document.getElementById('searchInput');
            const departmentFilter = document.getElementById('departmentFilter');
            const facultyFilter = document.getElementById('facultyFilter');
            const yearFilter = document.getElementById('yearFilter');
            const semesterFilter = document.getElementById('semesterFilter');
            const sectionFilter = document.getElementById('sectionFilter');
            const statusFilter = document.getElementById('statusFilter');

            function filterSubjects() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDept = departmentFilter.value;
                const selectedFaculty = facultyFilter.value;
                const selectedYear = yearFilter.value;
                const selectedSemester = semesterFilter.value;
                const selectedSection = sectionFilter.value;
                const selectedStatus = statusFilter.value;

                subjectCards.forEach(card => {
                    const name = card.querySelector('.subject-name').textContent.toLowerCase();
                    const code = card.querySelector('.subject-code').textContent.toLowerCase();
                    const department = card.dataset.department;
                    const faculty = card.dataset.faculty;
                    const year = card.dataset.year;
                    const semester = card.dataset.semester;
                    const section = card.dataset.section;
                    const status = card.dataset.status;

                    let showCard = true;

                    if (searchTerm && !name.includes(searchTerm) && !code.includes(searchTerm)) {
                        showCard = false;
                    }
                    if (selectedDept && department !== selectedDept) showCard = false;
                    if (selectedFaculty && faculty !== selectedFaculty) showCard = false;
                    if (selectedYear && year && year !== selectedYear) showCard = false;
                    if (selectedSemester && semester && semester !== selectedSemester) showCard = false;
                    if (selectedSection && section && section !== selectedSection) showCard = false;
                    if (selectedStatus && status !== selectedStatus) showCard = false;

                    card.classList.toggle('hidden', !showCard);
                });
            }

            // Add event listeners
            [searchInput, departmentFilter, facultyFilter, yearFilter, 
             semesterFilter, sectionFilter, statusFilter].forEach(element => {
                element.addEventListener('change', filterSubjects);
            });
            searchInput.addEventListener('input', filterSubjects);
        });

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('departmentFilter').value = '';
            document.getElementById('facultyFilter').value = '';
            document.getElementById('yearFilter').value = '';
            document.getElementById('semesterFilter').value = '';
            document.getElementById('sectionFilter').value = '';
            document.getElementById('statusFilter').value = '';
            document.querySelectorAll('.subject-card').forEach(card => {
                card.classList.remove('hidden');
            });
        }

        function validateForm(form) {
            let isValid = true;
            const requiredFields = form.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('error');
                } else {
                    field.classList.remove('error');
                }
            });

            if (!isValid) {
                alert('Please fill in all required fields');
                return false;
            }

            // Validate subject code format
            const codeField = form.querySelector('[name="code"]');
            const codePattern = /^\d{2}[A-Z]{2,4}\d{4}$/;
            if (!codePattern.test(codeField.value)) {
                alert('Invalid subject code format. Please use format like 21AD1501 (YY + DEPT + NUMBER)');
                codeField.classList.add('error');
                return false;
            }

            // Add visual feedback for valid input
            codeField.classList.add('valid');
            return true;
        }

        // Add real-time validation for subject code
        document.addEventListener('DOMContentLoaded', function() {
            const codeInputs = document.querySelectorAll('input[name="code"]');
            
            codeInputs.forEach(input => {
                input.addEventListener('input', function() {
                    formatSubjectCode(this);
                });
                
                input.addEventListener('blur', function() {
                    if (this.value.length > 0 && !/^\d{2}[A-Z]{2,4}\d{4}$/.test(this.value)) {
                        this.classList.add('error');
                    }
                });
            });
        });

        function formatSubjectCode(input) {
            // Remove any non-alphanumeric characters
            let value = input.value.replace(/[^A-Z0-9]/gi, '');
            
            // Convert to uppercase
            value = value.toUpperCase();
            
            // Apply the format
            if (value.length >= 2) {
                // First 2 digits (year)
                let formatted = value.substr(0, 2);
                
                if (value.length >= 4) {
                    // Department code (2-4 letters)
                    const deptCode = value.substr(2).match(/[A-Z]+/)?.[0] || '';
                    formatted += deptCode.substr(0, 4);
                    
                    // Remaining digits
                    const remainingDigits = value.substr(2 + deptCode.length).match(/\d+/)?.[0] || '';
                    if (remainingDigits) {
                        formatted += remainingDigits.substr(0, 4);
                    }
                } else {
                    formatted += value.substr(2);
                }
                
                input.value = formatted;
            } else {
                input.value = value;
            }
        }

        // Show/Hide Modal Functions
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Toggle Subject Status
        function toggleSubjectStatus(id, currentStatus) {
            if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this subject?')) {
                const formData = new FormData();
                formData.append('action', 'toggle_status');
                formData.append('id', id);
                formData.append('status', !currentStatus);

                fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => window.location.reload())
                .catch(error => console.error('Error:', error));
            }
        }

        // Toggle Assignment Status
        function toggleAssignmentStatus(assignmentId, currentStatus) {
            if (confirm('Are you sure you want to ' + (currentStatus ? 'deactivate' : 'activate') + ' this assignment?')) {
                const formData = new FormData();
                formData.append('action', 'toggle_assignment_status');
                formData.append('assignment_id', assignmentId);
                formData.append('status', !currentStatus);

                fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(() => window.location.reload())
                .catch(error => console.error('Error:', error));
            }
        }

        // Show Edit Modal with Subject Data
        function showEditModal(subject) {
            document.getElementById('edit_id').value = subject.id;
            document.getElementById('edit_code').value = subject.code;
            document.getElementById('edit_name').value = subject.name;
            document.getElementById('edit_department_id').value = subject.department_id;
            document.getElementById('edit_credits').value = subject.credits;

            // Fetch current assignments
            fetch(`get_subject_assignments.php?code=${subject.code}`)
                .then(response => response.json())
                .then(assignments => {
                    const assignmentsList = document.getElementById('currentAssignmentsList');
                    assignmentsList.innerHTML = assignments.map(assignment => `
                        <div class="assignment-item">
                            <div class="assignment-info">
                                <span>Year ${assignment.year}</span>
                                <span>Semester ${assignment.semester}</span>
                                <span>Section ${assignment.section}</span>
                                <span>${assignment.faculty_name}</span>
                            </div>
                            <div class="assignment-status">
                                <button type="button" class="btn-action" 
                                        onclick="toggleAssignmentStatus(${assignment.id}, ${assignment.is_active})">
                                    <i class="fas fa-${assignment.is_active ? 'times' : 'check'}"></i>
                                </button>
                            </div>
                        </div>
                    `).join('');
                })
                .catch(error => console.error('Error:', error));

            showModal('editModal');
        }

        // Add New Assignment Row
        function addNewAssignment() {
            const container = document.getElementById('newAssignments');
            const assignmentRow = document.createElement('div');
            assignmentRow.className = 'assignment-row';
            assignmentRow.innerHTML = `
                <button type="button" class="btn-action btn-remove-assignment" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
                <div class="form-row">
                    <div class="form-group">
                        <label>Year</label>
                        <select name="assignments[][year]" class="form-control" required>
                            <option value="">Select Year</option>
                            ${[1,2,3,4].map(year => `<option value="${year}">Year ${year}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Semester</label>
                        <select name="assignments[][semester]" class="form-control" required>
                            <option value="">Select Semester</option>
                            ${[1,2,3,4,5,6,7,8].map(sem => `<option value="${sem}">Semester ${sem}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Section</label>
                        <select name="assignments[][section]" class="form-control" required>
                            <option value="">Select Section</option>
                            ${['A','B','C','D','E'].map(sec => `<option value="${sec}">Section ${sec}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Faculty</label>
                        <select name="assignments[][faculty_id]" class="form-control" required>
                            <option value="">Select Faculty</option>
                            ${Array.from(document.querySelector('[name="faculty_id"]').options)
                                .map(opt => `<option value="${opt.value}">${opt.text}</option>`).join('')}
                        </select>
                    </div>
                </div>
            `;
            container.appendChild(assignmentRow);
        }

        // Form Validation
        function validateForm(form) {
            const code = form.querySelector('[name="code"]');
            if (code && !code.readOnly) {
                const codePattern = /^\d{2}[A-Z]{2,4}\d{4}$/;
                if (!codePattern.test(code.value)) {
                    alert('Invalid subject code format. Please use format: YY + DEPT + NUMBER (e.g., 21AD1501)');
                    return false;
                }
            }
            return true;
        }

        function showAddAssignmentModal(subjectId) {
            const modal = document.getElementById('addAssignmentModal');
            const form = modal.querySelector('#newAssignmentForm');
            form.onsubmit = function(e) {
                e.preventDefault();
                submitNewAssignment(subjectId);
            };
            form.reset();
            showModal('addAssignmentModal');
        }

        function submitNewAssignment(subjectId) {
            const form = document.getElementById('newAssignmentForm');
            const formData = new FormData(form);
            
            // Clear previous error states
            form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
            form.querySelectorAll('.validation-message').forEach(el => el.remove());
            
            // Required fields based on DB structure
            const requiredFields = ['faculty_id', 'academic_year_id', 'year', 'semester', 'section'];
            let isValid = true;
            
            // Validate each required field
            requiredFields.forEach(field => {
                const input = form.querySelector(`[name="${field}"]`);
                if (!input || !input.value.trim()) {
                    isValid = false;
                    input.classList.add('error');
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'validation-message';
                    errorMsg.textContent = `${field.replace('_', ' ')} is required`;
                    input.parentNode.appendChild(errorMsg);
                }
            });
            
            if (!isValid) {
                return;
            }
            
            // Add subject_id to formData
            formData.append('subject_id', subjectId);
            formData.append('action', 'add_assignment');
            
            // Additional validation for numeric fields
            const year = parseInt(formData.get('year'));
            const semester = parseInt(formData.get('semester'));
            
            if (year < 1 || year > 4) {
                alert('Year must be between 1 and 4');
                return;
            }
            
            if (semester < 1 || semester > 8) {
                alert('Semester must be between 1 and 8');
                return;
            }

            // Send the request
            fetch('manage_subjects.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Assignment added successfully!');
                    hideModal('addAssignmentModal');
                    window.location.reload();
                } else {
                    throw new Error(data.message || 'Error adding assignment');
                }
            })
            .catch(error => {
                alert(error.message || 'Error adding assignment. Please try again.');
            });
        }
    </script>
</body>
</html>