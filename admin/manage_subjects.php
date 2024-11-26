<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
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
                    $academic_year_id = intval($_POST['academic_year_id']);
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

                    // Create assignment array from form data
                    $assignments = [];
                    $years = isset($_POST['years']) ? $_POST['years'] : [];
                    $semesters = isset($_POST['semesters']) ? $_POST['semesters'] : [];
                    $sections = isset($_POST['sections']) ? $_POST['sections'] : [];
                    $faculty_ids = isset($_POST['faculty_ids']) ? $_POST['faculty_ids'] : [];

                    // Validate that we have all required arrays and they have the same length
                    if (empty($years) || empty($semesters) || empty($sections) || empty($faculty_ids) ||
                        count($years) !== count($semesters) || 
                        count($years) !== count($sections) || 
                        count($years) !== count($faculty_ids)) {
                        throw new Exception("Invalid assignment data provided!");
                    }

                    // Create assignments array
                    for ($i = 0; $i < count($years); $i++) {
                        $assignments[] = [
                            'year' => intval($years[$i]),
                            'semester' => intval($semesters[$i]),
                            'section' => $sections[$i],
                            'faculty_id' => intval($faculty_ids[$i])
                        ];
                    }
                    
                    // Insert each assignment as a separate subject entry
                    foreach ($assignments as $assignment) {
                        $query = "INSERT INTO subjects (
                            code, name, department_id, faculty_id, 
                            academic_year_id, year, semester, section, 
                            credits, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                        
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "ssiiiiiis", 
                            $code, 
                            $name, 
                            $department_id,
                            $assignment['faculty_id'],
                            $academic_year_id,
                            $assignment['year'],
                            $assignment['semester'],
                            $assignment['section'],
                            $credits
                        );
                        
                        if (!mysqli_stmt_execute($stmt)) {
                            throw new Exception("Error adding subject assignment!");
                        }
                    }
                    
                    // Log the action
                    $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                  VALUES (?, 'admin', 'add_subject', ?, ?, ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $details = json_encode([
                        'code' => $code,
                        'name' => $name,
                        'department_id' => $department_id,
                        'assignments_count' => count($assignments)
                    ]);
                    
                    mysqli_stmt_bind_param($log_stmt, "isss", 
                        $_SESSION['user_id'], 
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    );
                    mysqli_stmt_execute($log_stmt);
                    
                    // Commit transaction
                    mysqli_commit($conn);
                    $success_msg = "Subject added successfully with " . count($assignments) . " assignments!";
                    
                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error_msg = $e->getMessage();
                }
                break;
            case 'edit':
                try {
                    // Validate required fields exist
                    $required_fields = ['id', 'code', 'name', 'department_id', 'faculty_id', 
                                     'academic_year_id', 'year', 'semester', 'section', 'credits'];
                    foreach($required_fields as $field) {
                        if(!isset($_POST[$field])) {
                            throw new Exception("Missing required field: $field");
                        }
                    }

                    // Sanitize and validate inputs
                    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
                    $code = mysqli_real_escape_string($conn, $_POST['code']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $department_id = filter_var($_POST['department_id'], FILTER_VALIDATE_INT);
                    $faculty_id = filter_var($_POST['faculty_id'], FILTER_VALIDATE_INT);
                    $academic_year_id = filter_var($_POST['academic_year_id'], FILTER_VALIDATE_INT);
                    $year = filter_var($_POST['year'], FILTER_VALIDATE_INT);
                    $semester = filter_var($_POST['semester'], FILTER_VALIDATE_INT);
                    $section = mysqli_real_escape_string($conn, $_POST['section']);
                    $credits = filter_var($_POST['credits'], FILTER_VALIDATE_INT);

                    // Validate all fields have valid values
                    if(!$id || !$department_id || !$faculty_id || !$academic_year_id || 
                       !$year || !$semester || !$credits || empty($code) || empty($name) || empty($section)) {
                        throw new Exception("Invalid input values provided");
                    }

                    // Check for existing subject code excluding current subject
                    $check_query = "SELECT id FROM subjects WHERE code = ? AND id != ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "si", $code, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Subject code already exists!");
                    }

                    // Start transaction
                    mysqli_begin_transaction($conn);

                    $query = "UPDATE subjects SET 
                             code = ?, 
                             name = ?,
                             department_id = ?,
                             faculty_id = ?,
                             academic_year_id = ?,
                             year = ?,
                             semester = ?,
                             section = ?,
                             credits = ?
                             WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssiiiiiisi",
                        $code, $name, $department_id, $faculty_id,
                        $academic_year_id, $year, $semester, $section,
                        $credits, $id
                    );

                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error updating subject!");
                    }

                    // Log the action
                    $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                VALUES (?, 'admin', 'edit_subject', ?, ?, ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $details = json_encode([
                        'subject_id' => $id,
                        'code' => $code,
                        'name' => $name,
                        'department_id' => $department_id,
                        'faculty_id' => $faculty_id,
                        'academic_year_id' => $academic_year_id,
                        'year' => $year,
                        'semester' => $semester,
                        'section' => $section,
                        'credits' => $credits
                    ]);
                    
                    mysqli_stmt_bind_param($log_stmt, "isss", 
                        $_SESSION['user_id'], 
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    );
                    
                    if (!mysqli_stmt_execute($log_stmt)) {
                        throw new Exception("Error logging subject update!");
                    }

                    // Commit transaction
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
                    
                    $query = "UPDATE subjects SET is_active = ? WHERE id = ?";
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
                try {
                    // Validate required fields
                    $required_fields = ['subject_code', 'year', 'semester', 'section', 'faculty_id'];
                    foreach($required_fields as $field) {
                        if(!isset($_POST[$field])) {
                            throw new Exception("Missing required field: $field");
                        }
                    }

                    $subject_code = mysqli_real_escape_string($conn, $_POST['subject_code']);
                    $year = filter_var($_POST['year'], FILTER_VALIDATE_INT);
                    $semester = filter_var($_POST['semester'], FILTER_VALIDATE_INT);
                    $section = mysqli_real_escape_string($conn, $_POST['section']);
                    $faculty_id = filter_var($_POST['faculty_id'], FILTER_VALIDATE_INT);
                    
                    if(!$year || !$semester || !$faculty_id || empty($subject_code) || empty($section)) {
                        throw new Exception("Invalid input values provided");
                    }

                    // Get existing subject details
                    $subject_query = "SELECT name, department_id, academic_year_id, credits 
                                     FROM subjects WHERE code = ? LIMIT 1";
                    $stmt = mysqli_prepare($conn, $subject_query);
                    mysqli_stmt_bind_param($stmt, "s", $subject_code);
                    mysqli_stmt_execute($stmt);
                    $subject_result = mysqli_stmt_get_result($stmt);
                    $subject_details = mysqli_fetch_assoc($subject_result);
                    
                    if (!$subject_details) {
                        throw new Exception("Subject not found!");
                    }
                    
                    // Check if assignment already exists
                    $check_query = "SELECT id FROM subjects 
                                   WHERE code = ? AND year = ? AND semester = ? AND section = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "siis", $subject_code, $year, $semester, $section);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Assignment already exists for this section!");
                    }
                    
                    // Start transaction
                    mysqli_begin_transaction($conn);

                    // Insert new assignment
                    $query = "INSERT INTO subjects (
                        code, name, department_id, faculty_id, academic_year_id,
                        year, semester, section, credits, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssiiiiiis", 
                        $subject_code,
                        $subject_details['name'],
                        $subject_details['department_id'],
                        $faculty_id,
                        $subject_details['academic_year_id'],
                        $year,
                        $semester,
                        $section,
                        $subject_details['credits']
                    );
                    
                    if (!mysqli_stmt_execute($stmt)) {
                        throw new Exception("Error adding new assignment!");
                    }

                    // Log the action
                    $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                 VALUES (?, 'admin', 'add_subject_assignment', ?, ?, ?)";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    $details = json_encode([
                        'subject_code' => $subject_code,
                        'year' => $year,
                        'semester' => $semester,
                        'section' => $section,
                        'faculty_id' => $faculty_id
                    ]);
                    
                    mysqli_stmt_bind_param($log_stmt, "isss", 
                        $_SESSION['user_id'], 
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    );
                    
                    if (!mysqli_stmt_execute($log_stmt)) {
                        throw new Exception("Error logging new assignment!");
                    }

                    // Commit transaction
                    mysqli_commit($conn);
                    $success_msg = "New assignment added successfully!";

                } catch (Exception $e) {
                    mysqli_rollback($conn);
                    $error_msg = $e->getMessage();
                }
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
    s.code,
    s.name,
    s.department_id,
    s.year,
    s.semester,
    s.section,
    s.is_active,
    s.id,
    d.name as department_name,
    GROUP_CONCAT(DISTINCT CONCAT(f.name, ' (', s.section, ')') SEPARATOR ', ') as faculty_assignments,
    GROUP_CONCAT(DISTINCT CONCAT('Year ', s.year, ' Sem ', s.semester) SEPARATOR ', ') as year_sem_info,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as avg_rating,
    MIN(s.is_active) as is_active,
    MIN(s.year) as min_year,
    MIN(s.semester) as min_semester,
    MIN(s.section) as min_section
FROM subjects s
LEFT JOIN departments d ON s.department_id = d.id
LEFT JOIN faculty f ON s.faculty_id = f.id
LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
LEFT JOIN feedback fb ON s.id = fb.subject_id
GROUP BY s.code, s.name, s.department_id
ORDER BY s.code";

$subjects_result = mysqli_query($conn, $subjects_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subjects - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #2ecc71;  /* Green theme for Subjects */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
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
            display: flex;
        }

        .sidebar {
            width: 280px;
            background: var(--bg-color);
            padding: 2rem;
            box-shadow: var(--shadow);
            border-radius: 0 20px 20px 0;
            z-index: 1000;
        }

        .sidebar h2 {
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1.5rem;
            text-align: center;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
            text-decoration: none;
            margin-bottom: 0.5rem;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .nav-link i {
            margin-right: 1rem;
            color: var(--primary-color);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
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
            .sidebar {
                position: fixed;
                left: -280px;
                height: 100vh;
                transition: all 0.3s ease;
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

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
    <div class="sidebar">
        <h2>Admin Panel</h2>
        <nav>
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="manage_departments.php" class="nav-link">
                <i class="fas fa-building"></i> Departments
            </a>
            <a href="manage_faculty.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i> Faculty
            </a>
            <a href="manage_students.php" class="nav-link">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <a href="manage_subjects.php" class="nav-link active">
                <i class="fas fa-book"></i> Subjects
            </a>
            <a href="manage_feedback.php" class="nav-link">
                <i class="fas fa-comments"></i> Feedback
            </a>
            <a href="reports.php" class="nav-link">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="settings.php" class="nav-link">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>

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
                            <div class="subject-name"><?php echo htmlspecialchars($subject['name']); ?></div>
                            <div class="subject-code"><?php echo htmlspecialchars($subject['code']); ?></div>
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
                            <span class="detail-label">Faculty Assignments</span>
                            <span class="detail-value"><?php echo htmlspecialchars($subject['faculty_assignments']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Year & Semester</span>
                            <span class="detail-value"><?php echo htmlspecialchars($subject['year_sem_info']); ?></span>
                        </div>
                    </div>

                    <div class="subject-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $subject['feedback_count']; ?></div>
                            <div class="stat-label">Feedbacks</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $subject['avg_rating'] ?? 'N/A'; ?></div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>

                    <div class="subject-actions">
                        <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($subject)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action" onclick="toggleStatus(<?php echo $subject['id']; ?>, <?php echo $subject['is_active'] ? 'false' : 'true'; ?>)">
                            <i class="fas fa-power-off"></i> <?php echo $subject['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="btn-action" onclick="viewFeedback(<?php echo $subject['id']; ?>)">
                            <i class="fas fa-comments"></i> View Feedback
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

                <div class="form-group" id="assignmentContainer">
                    <label>Subject Assignments</label>
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
                                            <?php for($i = 65; $i <= 70; $i++): ?>
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
                            <button type="button" class="btn btn-danger btn-remove-assignment" onclick="removeAssignment(this)">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="addAssignment()">
                        <i class="fas fa-plus"></i> Add Another Assignment
                    </button>
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
                                            <?php for($i = 65; $i <= 70; $i++): ?>
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

    <script>
        // Modal handling functions
        function showAddModal() {
            const modal = document.getElementById('addModal');
            modal.style.display = 'flex';
            // Reset form when opening
            document.querySelector('#addModal form').reset();
        }

        function showEditModal(subject) {
            const modal = document.getElementById('editModal');
            
            // Set basic subject info
            document.getElementById('edit_id').value = subject.id;
            document.getElementById('edit_code').value = subject.code;
            document.getElementById('edit_name').value = subject.name;
            
            // Fetch and display current assignments
            fetchCurrentAssignments(subject.code);
            
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
            const codePattern = /^\d{2}[A-Z]{2,4}\d{4}$/;

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

        function addAssignment() {
            const template = document.querySelector('.assignment-row').cloneNode(true);
            // Reset values in the cloned template
            template.querySelectorAll('select').forEach(select => select.value = '');
            document.querySelector('.assignments').appendChild(template);
        }

        function removeAssignment(button) {
            const assignments = document.querySelector('.assignments');
            if (assignments.children.length > 1) {
                button.closest('.assignment-row').remove();
            }
        }

        // Modify the form submission handler
        function handleSubjectSubmission(form) {
            // Validate that at least one assignment exists
            const assignments = form.querySelectorAll('.assignment-row');
            if (assignments.length === 0) {
                alert('Please add at least one subject assignment');
                return false;
            }

            // Validate each assignment
            let isValid = true;
            assignments.forEach(assignment => {
                const selects = assignment.querySelectorAll('select');
                selects.forEach(select => {
                    if (!select.value) {
                        isValid = false;
                        select.classList.add('error');
                    } else {
                        select.classList.remove('error');
                    }
                });
            });

            if (!isValid) {
                alert('Please fill in all assignment fields');
                return false;
            }

            return true;
        }

        // Add this to your existing form event listener
        document.querySelector('#addModal form').addEventListener('submit', function(e) {
            if (!handleSubjectSubmission(this)) {
                e.preventDefault();
            }
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

        // Add event listeners for auto-formatting
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

        function fetchCurrentAssignments(subjectCode) {
            fetch(`get_subject_assignments.php?code=${subjectCode}`)
                .then(response => response.json())
                .then(assignments => {
                    displayCurrentAssignments(assignments);
                })
                .catch(error => console.error('Error:', error));
        }

        function displayCurrentAssignments(assignments) {
            const container = document.getElementById('currentAssignmentsList');
            container.innerHTML = '';
            
            assignments.forEach(assignment => {
                const assignmentDiv = document.createElement('div');
                assignmentDiv.className = 'current-assignment-item';
                assignmentDiv.innerHTML = `
                    <div class="assignment-details">
                        <span>Year ${assignment.year} - Semester ${assignment.semester}</span>
                        <span>Section ${assignment.section}</span>
                        <span>Faculty: ${assignment.faculty_name}</span>
                        <span class="status-badge ${assignment.is_active ? 'status-active' : 'status-inactive'}">
                            ${assignment.is_active ? 'Active' : 'Inactive'}
                        </span>
                    </div>
                    <div class="assignment-actions">
                        <button type="button" class="btn-action" 
                                onclick="toggleAssignmentStatus(${assignment.id}, ${!assignment.is_active})">
                            <i class="fas fa-power-off"></i>
                            ${assignment.is_active ? 'Deactivate' : 'Activate'}
                        </button>
                    </div>
                `;
                container.appendChild(assignmentDiv);
            });
        }

        function addNewAssignment() {
            const template = document.querySelector('.assignment-row').cloneNode(true);
            template.querySelectorAll('select').forEach(select => select.value = '');
            document.querySelector('.assignments').appendChild(template);
        }

        function toggleAssignmentStatus(id, status) {
            if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this assignment?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_assignment_status">
                    <input type="hidden" name="assignment_id" value="${id}">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function validateNewAssignment(form) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;
            
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
            
            // Check if year and semester combination is valid
            const year = parseInt(form.querySelector('[name="year"]').value);
            const semester = parseInt(form.querySelector('[name="semester"]').value);
            
            if (semester > year * 2) {
                alert('Invalid year and semester combination');
                return false;
            }
            
            return confirm('Are you sure you want to add this new assignment?');
        }

        // Add event listener for new assignment form
        document.querySelector('#addAssignmentForm').addEventListener('submit', function(e) {
            if (!validateNewAssignment(this)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>