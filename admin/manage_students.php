<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

$success_msg = $error_msg = '';

// Get filter parameters from URL
$search_filter = "";
$search_params = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = mysqli_real_escape_string($conn, $_GET['search']);
    $search_filter = " AND (s.name LIKE ? OR s.roll_number LIKE ? OR s.register_number LIKE ?)";
    $search_params[] = "%$search_term%";
    $search_params[] = "%$search_term%";
    $search_params[] = "%$search_term%";
}

$batch_filter = "";
if (isset($_GET['batch']) && !empty($_GET['batch'])) {
    $batch_id = intval($_GET['batch']);
    $batch_filter = " AND s.batch_id = ?";
    $batch_params = [$batch_id];
} else {
    $batch_params = [];
}

$section_filter = "";
if (isset($_GET['section']) && !empty($_GET['section'])) {
    $section = mysqli_real_escape_string($conn, $_GET['section']);
    $section_filter = " AND s.section = ?";
    $section_params = [$section];
} else {
    $section_params = [];
}

$status_filter = "";
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = ($_GET['status'] == '1') ? 1 : 0;
    $status_filter = " AND s.is_active = ?";
    $status_params = [$status];
} else {
    $status_params = [];
}

// Department filter based on admin type
$department_filter = "";
$department_params = [];

// If department admin, restrict data to their department
if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
    $department_filter = " AND s.department_id = ?";
    $department_params[] = $_SESSION['department_id'];
}

// Override department filter if dept is specified in URL
if (isset($_GET['dept']) && $_GET['dept'] !== 'all' && !empty($_GET['dept'])) {
    $dept_id = intval($_GET['dept']);
    // Only apply if the admin has access to this department
    if (is_super_admin() || $_SESSION['department_id'] == $dept_id) {
        $department_filter = " AND s.department_id = ?";
        $department_params = [$dept_id];
    }
}

// Fetch departments for dropdown - department admins only see their department
if (is_super_admin()) {
    $dept_query = "SELECT id, name FROM departments ORDER BY name";
    $departments = mysqli_query($conn, $dept_query);
} else {
    $dept_query = "SELECT id, name FROM departments WHERE id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $departments = mysqli_stmt_get_result($stmt);
}

// Fetch batches for dropdown
$batch_query = "SELECT id, batch_name FROM batch_years WHERE is_active = TRUE ORDER BY admission_year DESC";
$batches = mysqli_query($conn, $batch_query);

// Fetch students with department filtering - with pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$items_per_page = 20;
$offset = ($page - 1) * $items_per_page;

// Combine all filters and params
$all_filters = $department_filter . $search_filter . $batch_filter . $section_filter . $status_filter;
$all_params = array_merge($department_params, $search_params, $batch_params, $section_params, $status_params);

// Add index recommendations for better performance:
// - Add index on students(name) for sorting
// - Add index on feedback(student_id) for faster aggregation
// - Ensure indexes exist on all JOIN columns and filter columns
// - Add composite indexes for common filter combinations

// Cache key for count query results - based on filters
$count_cache_key = md5($all_filters . serialize($all_params));
$count_cache_file = '../cache/student_count_' . $count_cache_key . '.cache';
$count_cache_time = 3600; // 1 hour cache time

// Try to get the count from cache
$total_students = false;
if (file_exists($count_cache_file) && (time() - filemtime($count_cache_file)) < $count_cache_time) {
    $total_students = (int)file_get_contents($count_cache_file);
}

// If not in cache, run the count query
if ($total_students === false) {
    // Count total students for pagination
    $count_query = "SELECT COUNT(*) as total 
                    FROM students s 
                    JOIN departments d ON s.department_id = d.id
                    WHERE 1=1" . $all_filters;

    if (!empty($all_params)) {
        $stmt = mysqli_prepare($conn, $count_query);
        $param_types = str_repeat('s', count($all_params));
        mysqli_stmt_bind_param($stmt, $param_types, ...$all_params);
        mysqli_stmt_execute($stmt);
        $count_result = mysqli_stmt_get_result($stmt);
    } else {
        $count_result = mysqli_query($conn, $count_query);
    }

    $total_students = mysqli_fetch_assoc($count_result)['total'];
    
    // Save to cache
    if (!is_dir('../cache')) {
        mkdir('../cache', 0755, true);
    }
    file_put_contents($count_cache_file, $total_students);
}

$total_pages = ceil($total_students / $items_per_page);

// Modified query with LIMIT and OFFSET for pagination - Optimized for performance
$students_query = "SELECT s.id, s.roll_number, s.register_number, s.name, s.email, s.department_id, 
                        s.batch_id, s.section, s.phone, s.address, s.is_active, s.last_login, 
                        s.password_changed_at, s.created_at, s.profile_image_path, d.name as department_name, 
                        b.batch_name, CONCAT(b.admission_year, '-', b.graduation_year) as batch_years,
                        b.current_year_of_study
                    FROM students s 
                    JOIN departments d ON s.department_id = d.id
                    JOIN batch_years b ON s.batch_id = b.id
                    WHERE 1=1" . $all_filters . "
                    ORDER BY s.name
                    LIMIT ? OFFSET ?";

if (!empty($all_params)) {
    $stmt = mysqli_prepare($conn, $students_query);
    $param_types = str_repeat('s', count($all_params)) . "ii";
    $bind_params = array_merge($all_params, [$items_per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $param_types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $students_result = mysqli_stmt_get_result($stmt);
} else {
    $stmt = mysqli_prepare($conn, $students_query);
    mysqli_stmt_bind_param($stmt, "ii", $items_per_page, $offset);
    mysqli_stmt_execute($stmt);
    $students_result = mysqli_stmt_get_result($stmt);
}

// Fetch all student data into an array
$student_data = [];
$student_ids = [];
while ($student = mysqli_fetch_assoc($students_result)) {
    $student['feedback_count'] = 0;
    $student['avg_rating'] = 'N/A';
    $student_data[$student['id']] = $student;
    $student_ids[] = $student['id'];
}

// Only fetch feedback stats if we have students to display
if (!empty($student_ids)) {
    // Prepare the IN clause for student IDs
    $id_list = implode(',', array_map('intval', $student_ids));
    
    // Fetch feedback stats in a separate optimized query
    $feedback_query = "SELECT fb.student_id, 
                           COUNT(DISTINCT fb.id) as feedback_count,
                           ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
                       FROM feedback fb
                       WHERE fb.student_id IN ($id_list)
                       GROUP BY fb.student_id";
                       
    $feedback_result = mysqli_query($conn, $feedback_query);
    
    // Associate feedback data with students
    while ($feedback = mysqli_fetch_assoc($feedback_result)) {
        if (isset($student_data[$feedback['student_id']])) {
            $student_data[$feedback['student_id']]['feedback_count'] = $feedback['feedback_count'];
            $student_data[$feedback['student_id']]['avg_rating'] = $feedback['avg_rating'];
        }
    }
}

// Handle student operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                // Check department access before adding
                $department_id = intval($_POST['department_id']);
                if (!admin_has_department_access($department_id)) {
                    $error_msg = "You don't have permission to add students to this department.";
                    break;
                }
                try {
                    $roll_number = mysqli_real_escape_string($conn, $_POST['roll_number']);
                    $register_number = mysqli_real_escape_string($conn, $_POST['register_number']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = intval($_POST['department_id']);
                    $batch_id = intval($_POST['batch_id']);
                    $section = mysqli_real_escape_string($conn, $_POST['section']);
                    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
                    $address = mysqli_real_escape_string($conn, $_POST['address']);
                    
                    // Default password (e.g., register number)
                    $default_password = password_hash("Student@123", PASSWORD_DEFAULT);
                    
                    // Check for existing roll number, register number or email
                    $check_query = "SELECT id FROM students WHERE roll_number = ? OR register_number = ? OR email = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "sss", $roll_number, $register_number, $email);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Roll number, Register number or Email already exists!");
                    }
                    
                    $query = "INSERT INTO students (roll_number, register_number, name, email, password, 
                             department_id, batch_id, section, phone, address, is_active) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssssiisss", 
                        $roll_number, $register_number, $name, $email, $default_password,
                        $department_id, $batch_id, $section, $phone, $address
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                      VALUES (?, 'admin', 'add_student', ?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'roll_number' => $roll_number,
                            'name' => $name,
                            'department_id' => $department_id
                        ]);
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        
                        mysqli_stmt_bind_param($log_stmt, "isss", 
                            $_SESSION['user_id'], 
                            $details,
                            $ip_address,
                            $user_agent
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Student added successfully!";
                    } else {
                        throw new Exception("Error adding student!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'edit':
                // Check department access before editing
                $department_id = intval($_POST['department_id']);
                if (!admin_has_department_access($department_id)) {
                    $error_msg = "You don't have permission to edit students in this department.";
                    break;
                }
                // Verify the student belongs to admin's department (for department admins)
                if (!is_super_admin()) {
                    $student_id = intval($_POST['id']);
                    $check_dept_query = "SELECT department_id FROM students WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $check_dept_query);
                    mysqli_stmt_bind_param($stmt, "i", $student_id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $student_data = mysqli_fetch_assoc($result);
                    
                    if (!$student_data || $student_data['department_id'] != $_SESSION['department_id']) {
                        $error_msg = "You don't have permission to edit this student.";
                        break;
                    }
                }
                try {
                    $id = intval($_POST['id']);
                    $roll_number = mysqli_real_escape_string($conn, $_POST['roll_number']);
                    $register_number = mysqli_real_escape_string($conn, $_POST['register_number']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = intval($_POST['department_id']);
                    $batch_id = intval($_POST['batch_id']);
                    $section = mysqli_real_escape_string($conn, $_POST['section']);
                    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
                    $address = mysqli_real_escape_string($conn, $_POST['address']);

                    // Check for duplicates excluding current student
                    $check_query = "SELECT id FROM students WHERE 
                                  (roll_number = ? OR register_number = ? OR email = ?) 
                                  AND id != ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "sssi", $roll_number, $register_number, $email, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Roll number, Register number or Email already exists!");
                    }

                    $query = "UPDATE students SET 
                             roll_number = ?, 
                             register_number = ?,
                             name = ?,
                             email = ?,
                             department_id = ?,
                             batch_id = ?,
                             section = ?,
                             phone = ?,
                             address = ?
                             WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssssiisssi",
                        $roll_number, $register_number, $name, $email,
                        $department_id, $batch_id, $section, $phone, 
                        $address, $id
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                      VALUES (?, 'admin', 'edit_student', ?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'student_id' => $id,
                            'roll_number' => $roll_number,
                            'name' => $name
                        ]);
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        
                        mysqli_stmt_bind_param($log_stmt, "isss", 
                            $_SESSION['user_id'], 
                            $details,
                            $ip_address,
                            $user_agent
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Student updated successfully!";
                    } else {
                        throw new Exception("Error updating student!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'toggle_status':
                try {
                    $id = intval($_POST['id']);
                    $status = $_POST['status'] === 'true';
                    
                    $query = "UPDATE students SET is_active = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $status, $id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                      VALUES (?, 'admin', ?, ?, ?, ?)";
                        $action = $status ? 'activate_student' : 'deactivate_student';
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode(['student_id' => $id]);
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
                        
                        $success_msg = "Student status updated successfully!";
                    } else {
                        throw new Exception("Error updating student status!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <!-- Include the same CSS as manage_faculty.php but change primary color to a different shade -->
    <style>
        /* Copy the CSS from manage_faculty.php and change --primary-color to a different color */
        :root {
            --primary-color: #9b59b6;  /* Purple theme for Students */
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

        .faculty-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .faculty-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .faculty-card:hover {
            transform: translateY(-5px);
        }

        .faculty-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .faculty-info {
            flex: 1;
        }

        .faculty-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .faculty-id {
            font-size: 0.9rem;
            color: #666;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
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

        .faculty-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #666;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .faculty-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .faculty-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .modal {
            display: none;
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
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 650px;
            max-height: 90vh;
            overflow-y: auto;
        }

        /* Profile Info Section Styles */
        .profile-info-section {
            display: flex;
            gap: 2rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 12px;
            box-shadow: var(--inner-shadow);
        }

        .profile-photo-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }

        .profile-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-color);
            box-shadow: var(--shadow);
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 500;
            box-shadow: var(--shadow);
            text-transform: uppercase;
        }

        .profile-photo-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .login-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .info-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .info-value {
            font-size: 0.95rem;
            color: var(--text-color);
            font-weight: 400;
        }

        @media (max-width: 768px) {
            .profile-info-section {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 1rem;
            }
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
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

            .faculty-grid {
                grid-template-columns: 1fr;
            }
        }

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

        .filter-row:last-child {
            margin-bottom: 0;
        }

        .department-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            flex: 1;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .search-box {
            min-width: 300px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .btn-reset {
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            color: var(--primary-color);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
        }

        .hidden {
            display: none !important;
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 2rem 0;
            gap: 0.5rem;
        }
        
        .pagination {
            display: flex;
            gap: 0.5rem;
        }
        
        .page-link {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-color);
            color: var(--text-color);
            text-decoration: none;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 12px rgb(163,177,198,0.7),
                       -6px -6px 12px rgba(255,255,255, 0.6);
        }
        
        .page-link.active {
            background: var(--primary-color);
            color: white;
        }
        
        .page-info {
            color: var(--text-color);
            font-size: 0.9rem;
        }
        
        @media (max-width: 768px) {
            .pagination {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        /* Grid layout for student cards */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Student card styling */
        .student-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 320px; /* Set minimum height */
            max-width: 450px; /* Set maximum width */
            margin: 0 auto; /* Center cards in grid */
            width: 100%;
        }

        .student-card:hover {
            transform: translateY(-5px);
        }

        /* Student card header */
        .student-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .student-info {
            flex: 1;
        }

        .student-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .student-id {
            font-size: 0.9rem;
            color: #666;
        }

        /* Student details section */
        .student-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            flex: 1; /* Allow details section to grow */
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

        /* Student stats section */
        .student-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .stat-item {
            text-align: center;
            padding: 0.5rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            cursor: help;
            position: relative;
        }

        .stat-item:hover {
            transform: translateY(-1px);
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

        /* Student actions section */
        .student-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: auto; /* Push actions to bottom */
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

        /* Status badge styling */
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

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .student-grid {
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .student-grid {
                grid-template-columns: 1fr;
            }

            .student-card {
                max-width: 100%;
            }

            .student-actions {
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
            }

            .student-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Students</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Student
            </button>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <div class="filter-row">
                <div class="department-filters">
                    <button class="filter-btn active" data-dept="all">All Departments</button>
                    <?php
                    mysqli_data_seek($departments, 0);
                    while ($dept = mysqli_fetch_assoc($departments)): ?>
                        <button class="filter-btn" data-dept="<?php echo $dept['id']; ?>">
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </button>
                    <?php endwhile; ?>
                </div>
                
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search students..." class="form-control">
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <select id="batchFilter" class="form-control">
                        <option value="">All Batches</option>
                        <?php
                        mysqli_data_seek($batches, 0);
                        while ($batch = mysqli_fetch_assoc($batches)): ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="sectionFilter" class="form-control">
                        <option value="">All Sections</option>
                        <?php for($i = 65; $i <= 81; $i++): // A to F ?>
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

        <!-- Student Grid -->
        <div class="student-grid">
            <?php foreach ($student_data as $student): ?>
                <div class="student-card" 
                     data-student-id="<?php echo $student['id']; ?>"
                     data-department="<?php echo $student['department_id']; ?>"
                     data-batch="<?php echo $student['batch_id']; ?>"
                     data-section="<?php echo $student['section']; ?>"
                     data-status="<?php echo $student['is_active'] ? '1' : '0'; ?>">
                    <div class="student-header">
                        <div class="student-info">
                            <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                            <div class="student-id">
                                Roll No: <?php echo htmlspecialchars($student['roll_number']); ?>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $student['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $student['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="student-details">
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Batch</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['batch_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Section</span>
                            <span class="detail-value">Section <?php echo htmlspecialchars($student['section']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Register No</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['register_number']); ?></span>
                        </div>
                    </div>

                    <div class="student-stats">
                        <div class="stat-item" title="Includes subject feedback and class committee responses">
                            <div class="stat-value feedback-count">0</div>
                            <div class="stat-label">Total Feedbacks</div>
                        </div>
                        <div class="stat-item" title="Average rating from subject feedback only">
                            <div class="stat-value avg-rating">N/A</div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>

                    <div class="student-actions">
                        <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($student)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action" onclick="toggleStatus(<?php echo $student['id']; ?>, <?php echo $student['is_active'] ? 'false' : 'true'; ?>)">
                            <i class="fas fa-power-off"></i> <?php echo $student['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="btn-action" onclick="viewFeedback(<?php echo $student['id']; ?>)">
                            <i class="fas fa-comments"></i> View Feedback
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination Controls -->
        <div class="pagination-container">
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php 
                    // Preserve all filter parameters in pagination links
                    $params = $_GET;
                    
                    // First and previous page links
                    if ($page > 1): 
                        $params['page'] = 1;
                        $first_link = '?' . http_build_query($params);
                        
                        $params['page'] = $page - 1;
                        $prev_link = '?' . http_build_query($params);
                    ?>
                        <a href="<?php echo $first_link; ?>" class="page-link first">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="<?php echo $prev_link; ?>" class="page-link prev">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    // Display limited page links with current page in the middle when possible
                    $start_page = max(1, min($page - 2, $total_pages - 4));
                    $end_page = min($total_pages, max($page + 2, 5));
                    
                    // Ensure we always show at least 5 pages when available
                    if ($end_page - $start_page + 1 < 5 && $total_pages >= 5) {
                        if ($start_page == 1) {
                            $end_page = min(5, $total_pages);
                        }
                        if ($end_page == $total_pages) {
                            $start_page = max(1, $total_pages - 4);
                        }
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++): 
                        $params['page'] = $i;
                        $page_link = '?' . http_build_query($params);
                    ?>
                        <a href="<?php echo $page_link; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php 
                    // Next and last page links
                    if ($page < $total_pages): 
                        $params['page'] = $page + 1;
                        $next_link = '?' . http_build_query($params);
                        
                        $params['page'] = $total_pages;
                        $last_link = '?' . http_build_query($params);
                    ?>
                        <a href="<?php echo $next_link; ?>" class="page-link next">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="<?php echo $last_link; ?>" class="page-link last">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="page-info">
                    Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> 
                    (<?php echo $total_students; ?> total students)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit modals similar to manage_faculty.php but with student fields -->
    
    <!-- Similar JavaScript as manage_faculty.php but adapted for students -->

    <!-- Add Student Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add Student</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="roll_number">Roll Number</label>
                    <input type="text" id="roll_number" name="roll_number" class="form-control" required>
                    <div class="validation-hint">Format: YYYYBRXXXX (e.g., 2023CS001)</div>
                </div>

                <div class="form-group">
                    <label for="register_number">Register Number</label>
                    <input type="text" id="register_number" name="register_number" class="form-control" required>
                    <div class="validation-hint">University Register Number</div>
                </div>

                <div class="form-group">
                    <label for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                </div>

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
                    <label for="batch_id">Batch</label>
                    <select id="batch_id" name="batch_id" class="form-control" required>
                        <option value="">Select Batch</option>
                        <?php 
                        mysqli_data_seek($batches, 0);
                        while ($batch = mysqli_fetch_assoc($batches)): 
                        ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="section">Section</label>
                    <select id="section" name="section" class="form-control" required>
                        <option value="">Select Section</option>
                        <?php for($i = 65; $i <= 81; $i++): ?>
                            <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" pattern="[0-9]{10}" required>
                    <div class="validation-hint">10-digit mobile number</div>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Add Student</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Student</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <!-- Profile Photo and Last Login Section -->
                <div class="profile-info-section">
                    <div class="profile-photo-container">
                        <img id="edit_profile_photo" src="../assets/images/default-avatar.png" alt="Profile Photo" class="profile-photo" style="display: none;">
                        <div id="edit_profile_avatar" class="profile-avatar" style="display: none;"></div>
                        <div class="profile-photo-label">Profile Photo</div>
                    </div>
                    <div class="login-info">
                        <div class="info-item">
                            <span class="info-label">Last Login:</span>
                            <span id="edit_last_login" class="info-value">Never logged in</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Account Created:</span>
                            <span id="edit_created_at" class="info-value">-</span>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="edit_roll_number">Roll Number</label>
                    <input type="text" id="edit_roll_number" name="roll_number" class="form-control" required>
                    <div class="validation-hint">Format: YYYYBRXXXX (e.g., 2023CS001)</div>
                </div>

                <div class="form-group">
                    <label for="edit_register_number">Register Number</label>
                    <input type="text" id="edit_register_number" name="register_number" class="form-control" required>
                    <div class="validation-hint">University Register Number</div>
                </div>

                <div class="form-group">
                    <label for="edit_name">Full Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_department_id">Department</label>
                    <select id="edit_department_id" name="department_id" class="form-control" required>
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
                    <label for="edit_batch_id">Batch</label>
                    <select id="edit_batch_id" name="batch_id" class="form-control" required>
                        <option value="">Select Batch</option>
                        <?php 
                        mysqli_data_seek($batches, 0);
                        while ($batch = mysqli_fetch_assoc($batches)): 
                        ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_section">Section</label>
                    <select id="edit_section" name="section" class="form-control" required>
                        <option value="">Select Section</option>
                        <?php for($i = 65; $i <= 81; $i++): ?>
                            <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_phone">Phone Number</label>
                    <input type="tel" id="edit_phone" name="phone" class="form-control" pattern="[0-9]{10}" required>
                    <div class="validation-hint">10-digit mobile number</div>
                </div>

                <div class="form-group">
                    <label for="edit_address">Address</label>
                    <textarea id="edit_address" name="address" class="form-control" rows="3" required></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Update Student</button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function showEditModal(student) {
            document.getElementById('edit_id').value = student.id;
            document.getElementById('edit_roll_number').value = student.roll_number;
            document.getElementById('edit_register_number').value = student.register_number;
            document.getElementById('edit_name').value = student.name;
            document.getElementById('edit_email').value = student.email;
            document.getElementById('edit_department_id').value = student.department_id;
            document.getElementById('edit_batch_id').value = student.batch_id;
            document.getElementById('edit_section').value = student.section;
            document.getElementById('edit_phone').value = student.phone;
            document.getElementById('edit_address').value = student.address;
            
            // Set profile photo or avatar
            const profilePhoto = document.getElementById('edit_profile_photo');
            const profileAvatar = document.getElementById('edit_profile_avatar');
            
            // Reset both elements
            profilePhoto.style.display = 'none';
            profileAvatar.style.display = 'none';
            
            if (student.profile_image_path && student.profile_image_path.trim() !== '') {
                profilePhoto.src = '../' + student.profile_image_path + '?v=' + new Date().getTime();
                profilePhoto.style.display = 'block';
                profilePhoto.onerror = function() {
                    // Hide image and show avatar instead
                    this.style.display = 'none';
                    profileAvatar.textContent = student.name.charAt(0).toUpperCase();
                    profileAvatar.style.display = 'flex';
                };
            } else {
                // Show avatar with first letter of name
                profileAvatar.textContent = student.name.charAt(0).toUpperCase();
                profileAvatar.style.display = 'flex';
            }
            
            // Set last login info
            const lastLoginElement = document.getElementById('edit_last_login');
            if (student.last_login && student.last_login !== null) {
                const lastLoginDate = new Date(student.last_login);
                lastLoginElement.textContent = lastLoginDate.toLocaleString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } else {
                lastLoginElement.textContent = 'Never logged in';
            }
            
            // Set created date
            const createdAtElement = document.getElementById('edit_created_at');
            if (student.created_at) {
                const createdDate = new Date(student.created_at);
                createdAtElement.textContent = createdDate.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
            } else {
                createdAtElement.textContent = '-';
            }
            
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function toggleStatus(id, status) {
            if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this student?')) {
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
            window.location.href = `../view_student_feedback.php?student_id=${id}`;
        }


        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const studentCards = document.querySelectorAll('.student-card');
            const searchInput = document.getElementById('searchInput');
            const batchFilter = document.getElementById('batchFilter');
            const sectionFilter = document.getElementById('sectionFilter');
            const statusFilter = document.getElementById('statusFilter');
            const departmentButtons = document.querySelectorAll('.filter-btn');

            // Get URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = urlParams.get('page') || 1;

            // Set filters from URL params if they exist
            if (urlParams.has('search')) {
                searchInput.value = urlParams.get('search');
            }
            if (urlParams.has('batch')) {
                batchFilter.value = urlParams.get('batch');
            }
            if (urlParams.has('section')) {
                sectionFilter.value = urlParams.get('section');
            }
            if (urlParams.has('status')) {
                statusFilter.value = urlParams.get('status');
            }
            if (urlParams.has('dept')) {
                const deptId = urlParams.get('dept');
                departmentButtons.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.dept === deptId) {
                        btn.classList.add('active');
                    }
                });
            }

            // Apply local filtering (for current page)
            function filterStudents() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDept = document.querySelector('.filter-btn.active').dataset.dept;
                const selectedBatch = batchFilter.value;
                const selectedSection = sectionFilter.value;
                const selectedStatus = statusFilter.value;

                studentCards.forEach(card => {
                    const name = card.querySelector('.student-name').textContent.toLowerCase();
                    const rollNo = card.querySelector('.student-id').textContent.toLowerCase();
                    const department = card.dataset.department;
                    const batch = card.dataset.batch;
                    const section = card.dataset.section;
                    const status = card.dataset.status;

                    let showCard = true;

                    // Search term filter
                    if (searchTerm && !name.includes(searchTerm) && !rollNo.includes(searchTerm)) {
                        showCard = false;
                    }

                    // Department filter
                    if (selectedDept !== 'all' && department !== selectedDept) {
                        showCard = false;
                    }

                    // Batch filter
                    if (selectedBatch && batch !== selectedBatch) {
                        showCard = false;
                    }

                    // Section filter
                    if (selectedSection && section !== selectedSection) {
                        showCard = false;
                    }

                    // Status filter
                    if (selectedStatus !== '' && status !== selectedStatus) {
                        showCard = false;
                    }

                    card.classList.toggle('hidden', !showCard);
                });
            }

            // Function to apply filters and redirect
            function applyFilters() {
                const searchTerm = searchInput.value.trim();
                const selectedDept = document.querySelector('.filter-btn.active').dataset.dept;
                const selectedBatch = batchFilter.value;
                const selectedSection = sectionFilter.value;
                const selectedStatus = statusFilter.value;
                
                const params = new URLSearchParams();
                params.set('page', 1); // Reset to page 1 when filtering
                
                if (searchTerm) params.set('search', searchTerm);
                if (selectedDept !== 'all') params.set('dept', selectedDept);
                if (selectedBatch) params.set('batch', selectedBatch);
                if (selectedSection) params.set('section', selectedSection);
                if (selectedStatus) params.set('status', selectedStatus);
                
                window.location.href = '?' + params.toString();
            }
            
            // Apply server-side filtering with delay
            const filterDelay = 500; // ms
            let filterTimeout;
            
            function delayedFilterApply() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(applyFilters, filterDelay);
            }

            // Modified event listeners to apply server-side filtering
            searchInput.addEventListener('input', function() {
                filterStudents(); // Immediate local filtering
                delayedFilterApply(); // Delayed server-side filtering
            });
            
            batchFilter.addEventListener('change', applyFilters);
            sectionFilter.addEventListener('change', applyFilters);
            statusFilter.addEventListener('change', applyFilters);

            departmentButtons.forEach(button => {
                button.addEventListener('click', () => {
                    departmentButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                    applyFilters();
                });
            });
            
            // Initial filtering
            filterStudents();

            // Load feedback statistics via AJAX
            loadFeedbackStats();
        });

        function resetFilters() {
            window.location.href = '?page=1';
        }

        // JavaScript for AJAX loading of feedback statistics
        // Note: Feedback count includes both regular subject feedback and class committee responses
        function loadFeedbackStats(retryCount = 0) {
            const studentCards = document.querySelectorAll('.student-card');
            const studentIds = Array.from(studentCards).map(card => card.dataset.studentId);
            
            if (studentIds.length === 0) return;
            
            // Only show loading indicators on first attempt
            if (retryCount === 0) {
                studentCards.forEach(card => {
                    card.querySelector('.feedback-count').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                    card.querySelector('.avg-rating').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                });
            }
            
            // Set a timeout for the AJAX request
            const timeoutPromise = new Promise((_, reject) => {
                setTimeout(() => reject(new Error('Request timed out')), 10000); // 10 second timeout
            });
            
            // Make AJAX request
            const fetchPromise = fetch('ajax/get_student_feedback_stats.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ student_ids: studentIds })
            });
            
            // Race between fetch and timeout
            Promise.race([fetchPromise, timeoutPromise])
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    // Update the DOM with the feedback data
                    studentCards.forEach(card => {
                        const studentId = card.dataset.studentId;
                        if (data[studentId]) {
                            card.querySelector('.feedback-count').textContent = data[studentId].feedback_count;
                            card.querySelector('.avg-rating').textContent = data[studentId].avg_rating;
                        } else {
                            // Set default values for any students not in the response
                            card.querySelector('.feedback-count').textContent = '0';
                            card.querySelector('.avg-rating').textContent = 'N/A';
                        }
                    });
                })
                .catch(error => {
                    console.error(`Error loading feedback stats (attempt ${retryCount + 1}):`, error);
                    
                    // Try again up to 3 times with increasing delay
                    if (retryCount < 2) {
                        const retryDelay = Math.pow(2, retryCount) * 1000; // Exponential backoff: 1s, 2s
                        console.log(`Retrying in ${retryDelay/1000} seconds...`);
                        setTimeout(() => loadFeedbackStats(retryCount + 1), retryDelay);
                    } else {
                        // After 3 attempts, reset to default values
                        console.log('Maximum retry attempts reached. Using default values.');
                        studentCards.forEach(card => {
                            card.querySelector('.feedback-count').textContent = '0';
                            card.querySelector('.avg-rating').textContent = 'N/A';
                        });
                    }
            });
        }
    </script>
</body>
</html>