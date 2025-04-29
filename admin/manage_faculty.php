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

// Handle faculty operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $faculty_id = mysqli_real_escape_string($conn, $_POST['faculty_id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = intval($_POST['department_id']);
                    $designation = mysqli_real_escape_string($conn, $_POST['designation']);
                    $experience = intval($_POST['experience']);
                    $qualification = mysqli_real_escape_string($conn, $_POST['qualification']);
                    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);
                    
                    // Check department access for department admin
                    if (!admin_has_department_access($department_id)) {
                        throw new Exception("You don't have permission to add faculty to this department.");
                    }
                    
                    // Default password
                    $default_password = password_hash("Faculty@123", PASSWORD_DEFAULT);
                    
                    // Check for existing faculty_id or email
                    $check_query = "SELECT id FROM faculty WHERE faculty_id = ? OR email = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ss", $faculty_id, $email);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Faculty ID or Email already exists!");
                    }
                    
                    $query = "INSERT INTO faculty (faculty_id, name, email, password, department_id, designation, 
                             experience, qualification, specialization, is_active) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssssisiss", 
                        $faculty_id, $name, $email, $default_password, $department_id,
                        $designation, $experience, $qualification, $specialization
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', 'add_faculty', ?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'faculty_id' => $faculty_id,
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
                        
                        $success_msg = "Faculty added successfully!";
                    } else {
                        throw new Exception("Error adding faculty!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'edit':
                try {
                    $id = intval($_POST['id']);
                    $faculty_id = mysqli_real_escape_string($conn, $_POST['faculty_id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = intval($_POST['department_id']);
                    $designation = mysqli_real_escape_string($conn, $_POST['designation']);
                    $experience = intval($_POST['experience']);
                    $qualification = mysqli_real_escape_string($conn, $_POST['qualification']);
                    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);

                    // Check department access for department admin
                    if (!admin_has_department_access($department_id)) {
                        throw new Exception("You don't have permission to edit faculty in this department.");
                    }
                    
                    // Verify the faculty belongs to admin's department (for department admins)
                    if (!is_super_admin()) {
                        $check_dept_query = "SELECT department_id FROM faculty WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $check_dept_query);
                        mysqli_stmt_bind_param($stmt, "i", $id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $faculty_data = mysqli_fetch_assoc($result);
                        
                        if (!$faculty_data || $faculty_data['department_id'] != $_SESSION['department_id']) {
                            throw new Exception("You don't have permission to edit this faculty member.");
                        }
                    }

                    // Check if faculty_id or email already exists for other faculty
                    $check_query = "SELECT id FROM faculty WHERE (faculty_id = ? OR email = ?) AND id != ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ssi", $faculty_id, $email, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Faculty ID or Email already exists!");
                    }

                    $query = "UPDATE faculty SET 
                             faculty_id = ?, 
                             name = ?,
                             email = ?,
                             department_id = ?,
                             designation = ?,
                             experience = ?,
                             qualification = ?,
                             specialization = ?
                             WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssissssi",
                        $faculty_id, $name, $email, $department_id,
                        $designation, $experience, $qualification, 
                        $specialization, $id
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', 'edit_faculty', ?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'faculty_id' => $faculty_id,
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
                        
                        $success_msg = "Faculty updated successfully!";
                    } else {
                        throw new Exception("Error updating faculty!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'toggle_status':
                try {
                    $id = intval($_POST['id']);
                    $status = $_POST['status'] === 'true';
                    
                    // Verify the faculty belongs to admin's department (for department admins)
                    if (!is_super_admin()) {
                        $check_dept_query = "SELECT department_id FROM faculty WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $check_dept_query);
                        mysqli_stmt_bind_param($stmt, "i", $id);
                        mysqli_stmt_execute($stmt);
                        $result = mysqli_stmt_get_result($stmt);
                        $faculty_data = mysqli_fetch_assoc($result);
                        
                        if (!$faculty_data || $faculty_data['department_id'] != $_SESSION['department_id']) {
                            throw new Exception("You don't have permission to modify this faculty member.");
                        }
                    }
                    
                    $query = "UPDATE faculty SET is_active = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $status, $id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', ?, ?, ?, ?)";
                        $action = $status ? 'activate_faculty' : 'deactivate_faculty';
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode(['faculty_id' => $id]);
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
                        
                        $success_msg = "Faculty status updated successfully!";
                    } else {
                        throw new Exception("Error updating faculty status!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;
        }
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

// Get filter parameters from URL
$search_filter = "";
$search_params = [];
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = mysqli_real_escape_string($conn, $_GET['search']);
    $search_filter = " AND (f.name LIKE ? OR f.faculty_id LIKE ? OR f.email LIKE ?)";
    $search_params[] = "%$search_term%";
    $search_params[] = "%$search_term%";
    $search_params[] = "%$search_term%";
}

$designation_filter = "";
if (isset($_GET['designation']) && !empty($_GET['designation'])) {
    $designation = mysqli_real_escape_string($conn, $_GET['designation']);
    $designation_filter = " AND f.designation = ?";
    $designation_params = [$designation];
} else {
    $designation_params = [];
}

$experience_filter = "";
$experience_params = [];
if (isset($_GET['experience']) && !empty($_GET['experience'])) {
    $experience_range = $_GET['experience'];
    if (strpos($experience_range, '+') !== false) {
        // Handle ranges like "15+"
        $min_exp = intval($experience_range);
        $experience_filter = " AND f.experience >= ?";
        $experience_params = [$min_exp];
    } else if (strpos($experience_range, '-') !== false) {
        // Handle ranges like "6-10"
        list($min_exp, $max_exp) = explode('-', $experience_range);
        $experience_filter = " AND f.experience >= ? AND f.experience <= ?";
        $experience_params = [intval($min_exp), intval($max_exp)];
    }
}

$status_filter = "";
if (isset($_GET['status']) && $_GET['status'] !== '') {
    $status = ($_GET['status'] == '1') ? 1 : 0;
    $status_filter = " AND f.is_active = ?";
    $status_params = [$status];
} else {
    $status_params = [];
}

// Department filter
$department_filter = "";
$department_params = [];

// Override department filter if dept is specified in URL
if (isset($_GET['dept']) && $_GET['dept'] !== 'all' && !empty($_GET['dept'])) {
    $dept_id = intval($_GET['dept']);
    // Only apply if the admin has access to this department
    if (is_super_admin() || $_SESSION['department_id'] == $dept_id) {
        $department_filter = " AND f.department_id = ?";
        $department_params = [$dept_id];
    }
} else if (!is_super_admin()) {
    // If department admin, restrict data to their department
    $department_filter = " AND f.department_id = ?";
    $department_params = [$_SESSION['department_id']];
}

// Set up pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$items_per_page = 20;
$offset = ($page - 1) * $items_per_page;

// Combine all filters and params
$all_filters = $department_filter . $search_filter . $designation_filter . $experience_filter . $status_filter;
$all_params = array_merge($department_params, $search_params, $designation_params, $experience_params, $status_params);

// Add index recommendations for better performance:
// - Add index on faculty(name) for sorting
// - Add index on subject_assignments(faculty_id) for faster aggregation
// - Add index on feedback(assignment_id) for faster aggregation
// - Ensure indexes exist on all JOIN columns and filter columns
// - Add composite indexes for common filter combinations

// Cache key for count query results - based on filters
$count_cache_key = md5($all_filters . serialize($all_params));
$count_cache_file = '../cache/faculty_count_' . $count_cache_key . '.cache';
$count_cache_time = 3600; // 1 hour cache time

// Try to get the count from cache
$total_faculty = false;
if (file_exists($count_cache_file) && (time() - filemtime($count_cache_file)) < $count_cache_time) {
    $total_faculty = (int)file_get_contents($count_cache_file);
}

// If not in cache, run the count query
if ($total_faculty === false) {
    // Count total faculty for pagination
    $count_query = "SELECT COUNT(*) as total 
                    FROM faculty f 
                    JOIN departments d ON f.department_id = d.id
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

    $total_faculty = mysqli_fetch_assoc($count_result)['total'];
    
    // Save to cache
    if (!is_dir('../cache')) {
        mkdir('../cache', 0755, true);
    }
    file_put_contents($count_cache_file, $total_faculty);
}

$total_pages = ceil($total_faculty / $items_per_page);

// Fetch faculty with related information - Optimized for performance
$faculty_query = "SELECT 
    f.id, f.faculty_id, f.name, f.email, f.department_id, f.designation, 
    f.experience, f.qualification, f.specialization, f.is_active, 
    f.last_login, f.password_changed_at, f.created_at,
    d.name AS department_name
FROM faculty f
JOIN departments d ON f.department_id = d.id
WHERE 1=1" . $all_filters . "
ORDER BY f.name
LIMIT ? OFFSET ?";

if (!empty($all_params)) {
    $stmt = mysqli_prepare($conn, $faculty_query);
    $param_types = str_repeat('s', count($all_params)) . "ii";
    $bind_params = array_merge($all_params, [$items_per_page, $offset]);
    mysqli_stmt_bind_param($stmt, $param_types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $faculty_result = mysqli_stmt_get_result($stmt);
} else {
    $stmt = mysqli_prepare($conn, $faculty_query);
    mysqli_stmt_bind_param($stmt, "ii", $items_per_page, $offset);
    mysqli_stmt_execute($stmt);
    $faculty_result = mysqli_stmt_get_result($stmt);
}

// Fetch all faculty data into an array
$faculty_data = [];
$faculty_ids = [];
while ($faculty = mysqli_fetch_assoc($faculty_result)) {
    $faculty['subject_count'] = 0;
    $faculty['feedback_count'] = 0;
    $faculty['avg_rating'] = 'N/A';
    $faculty_data[$faculty['id']] = $faculty;
    $faculty_ids[] = $faculty['id'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Faculty - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
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
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
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
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Faculty</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Faculty
            </button>
        </div>

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
                    <input type="text" id="searchInput" placeholder="Search faculty..." class="form-control">
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <select id="designationFilter" class="form-control">
                        <option value="">All Designations</option>
                        <?php
                        $designation_query = "SELECT DISTINCT designation FROM faculty WHERE designation IS NOT NULL ORDER BY designation";
                        $designations = mysqli_query($conn, $designation_query);
                        while ($designation = mysqli_fetch_assoc($designations)): ?>
                            <option value="<?php echo htmlspecialchars($designation['designation']); ?>">
                                <?php echo htmlspecialchars($designation['designation']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="experienceFilter" class="form-control">
                        <option value="">All Experience</option>
                        <option value="0-5">0-5 years</option>
                        <option value="6-10">6-10 years</option>
                        <option value="11-15">11-15 years</option>
                        <option value="15+">15+ years</option>
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

        <div class="faculty-grid">
            <?php foreach ($faculty_data as $faculty): ?>
                <div class="faculty-card" 
                     data-faculty-id="<?php echo $faculty['id']; ?>"
                     data-department="<?php echo $faculty['department_id']; ?>"
                     data-designation="<?php echo htmlspecialchars($faculty['designation']); ?>"
                     data-experience="<?php echo $faculty['experience']; ?>"
                     data-status="<?php echo $faculty['is_active'] ? '1' : '0'; ?>">
                    <div class="faculty-header">
                        <div class="faculty-info">
                            <div class="faculty-name"><?php echo htmlspecialchars($faculty['name']); ?></div>
                            <div class="faculty-id"><?php echo htmlspecialchars($faculty['faculty_id']); ?></div>
                        </div>
                        <span class="status-badge <?php echo $faculty['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $faculty['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="faculty-details">
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Designation</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['designation']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Experience</span>
                            <span class="detail-value"><?php echo $faculty['experience']; ?> years</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Qualification</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['qualification']); ?></span>
                        </div>
                    </div>

                    <div class="faculty-stats">
                        <div class="stat-item">
                            <div class="stat-value">
                                <span class="subject-count">0</span>
                                <span class="stats-loading spinner-border spinner-border-sm text-primary" role="status" style="display:none;">
                                    <span class="visually-hidden">Loading...</span>
                                </span>
                            </div>
                            <div class="stat-label">Subjects</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <span class="feedback-count">0</span>
                                <span class="stats-loading spinner-border spinner-border-sm text-primary" role="status" style="display:none;">
                                    <span class="visually-hidden">Loading...</span>
                                </span>
                            </div>
                            <div class="stat-label">Feedbacks</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value">
                                <span class="avg-rating">N/A</span>
                                <span class="stats-loading spinner-border spinner-border-sm text-primary" role="status" style="display:none;">
                                    <span class="visually-hidden">Loading...</span>
                                </span>
                            </div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>

                    <div class="faculty-actions">
                        <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($faculty)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action" onclick="toggleStatus(<?php echo $faculty['id']; ?>, <?php echo $faculty['is_active'] ? 'false' : 'true'; ?>)">
                            <i class="fas fa-power-off"></i> <?php echo $faculty['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="btn-action" onclick="viewFeedback(<?php echo $faculty['id']; ?>)">
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
                    (<?php echo $total_faculty; ?> total faculty)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Faculty Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add Faculty</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="faculty_id">Faculty ID</label>
                    <input type="text" id="faculty_id" name="faculty_id" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="name">Name</label>
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
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="experience">Experience (years)</label>
                    <input type="number" id="experience" name="experience" class="form-control" required min="0">
                </div>

                <div class="form-group">
                    <label for="qualification">Qualification</label>
                    <input type="text" id="qualification" name="qualification" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="specialization">Specialization</label>
                    <input type="text" id="specialization" name="specialization" class="form-control" required>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Add Faculty</button>
                    <button type="button" class="btn" onclick="hideModal('addModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Faculty Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Faculty</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group">
                    <label for="edit_faculty_id">Faculty ID</label>
                    <input type="text" id="edit_faculty_id" name="faculty_id" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_name">Name</label>
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
                    <label for="edit_designation">Designation</label>
                    <input type="text" id="edit_designation" name="designation" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_experience">Experience (years)</label>
                    <input type="number" id="edit_experience" name="experience" class="form-control" required min="0">
                </div>

                <div class="form-group">
                    <label for="edit_qualification">Qualification</label>
                    <input type="text" id="edit_qualification" name="qualification" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_specialization">Specialization</label>
                    <input type="text" id="edit_specialization" name="specialization" class="form-control" required>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Update Faculty</button>
                    <button type="button" class="btn" onclick="hideModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function showEditModal(faculty) {
            document.getElementById('edit_id').value = faculty.id;
            document.getElementById('edit_faculty_id').value = faculty.faculty_id;
            document.getElementById('edit_name').value = faculty.name;
            document.getElementById('edit_email').value = faculty.email;
            document.getElementById('edit_department_id').value = faculty.department_id;
            document.getElementById('edit_designation').value = faculty.designation;
            document.getElementById('edit_experience').value = faculty.experience;
            document.getElementById('edit_qualification').value = faculty.qualification;
            document.getElementById('edit_specialization').value = faculty.specialization;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function toggleStatus(id, status) {
            if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this faculty?')) {
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
            window.location.href = `../view_faculty_feedback.php?faculty_id=${id}`;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Add active class to current nav link
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const facultyCards = document.querySelectorAll('.faculty-card');
            const searchInput = document.getElementById('searchInput');
            const designationFilter = document.getElementById('designationFilter');
            const experienceFilter = document.getElementById('experienceFilter');
            const statusFilter = document.getElementById('statusFilter');
            const departmentButtons = document.querySelectorAll('.filter-btn');

            // Get URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            const currentPage = urlParams.get('page') || 1;

            // Set filters from URL params if they exist
            if (urlParams.has('search')) {
                searchInput.value = urlParams.get('search');
            }
            if (urlParams.has('designation')) {
                designationFilter.value = urlParams.get('designation');
            }
            if (urlParams.has('experience')) {
                experienceFilter.value = urlParams.get('experience');
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
            function filterFaculty() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDept = document.querySelector('.filter-btn.active').dataset.dept;
                const selectedDesignation = designationFilter.value.toLowerCase();
                const selectedExperience = experienceFilter.value;
                const selectedStatus = statusFilter.value;

                facultyCards.forEach(card => {
                    const name = card.querySelector('.faculty-name').textContent.toLowerCase();
                    const facultyId = card.querySelector('.faculty-id').textContent.toLowerCase();
                    const department = card.dataset.department;
                    const designation = card.dataset.designation.toLowerCase();
                    const experience = parseInt(card.dataset.experience);
                    const status = card.dataset.status;

                    let showCard = true;

                    // Search term filter
                    if (searchTerm && !name.includes(searchTerm) && !facultyId.includes(searchTerm)) {
                        showCard = false;
                    }

                    // Department filter
                    if (selectedDept !== 'all' && department !== selectedDept) {
                        showCard = false;
                    }

                    // Designation filter
                    if (selectedDesignation && designation !== selectedDesignation) {
                        showCard = false;
                    }

                    // Experience filter
                    if (selectedExperience) {
                        const [min, max] = selectedExperience.split('-').map(num => num.replace('+', ''));
                        if (max) {
                            if (experience < parseInt(min) || experience > parseInt(max)) {
                                showCard = false;
                            }
                        } else {
                            if (experience < parseInt(min)) {
                                showCard = false;
                            }
                        }
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
                const selectedDesignation = designationFilter.value;
                const selectedExperience = experienceFilter.value;
                const selectedStatus = statusFilter.value;
                
                const params = new URLSearchParams();
                params.set('page', 1); // Reset to page 1 when filtering
                
                if (searchTerm) params.set('search', searchTerm);
                if (selectedDept !== 'all') params.set('dept', selectedDept);
                if (selectedDesignation) params.set('designation', selectedDesignation);
                if (selectedExperience) params.set('experience', selectedExperience);
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
                filterFaculty(); // Immediate local filtering
                delayedFilterApply(); // Delayed server-side filtering
            });
            
            designationFilter.addEventListener('change', applyFilters);
            experienceFilter.addEventListener('change', applyFilters);
            statusFilter.addEventListener('change', applyFilters);

            departmentButtons.forEach(button => {
                button.addEventListener('click', () => {
                    departmentButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');
                    applyFilters();
                });
            });
            
            // Initial filtering
            filterFaculty();
            
            // Load faculty statistics via AJAX
            loadFacultyStats();
        });

        function resetFilters() {
            window.location.href = '?page=1';
        }
        
        // JavaScript for AJAX loading of faculty statistics
        function loadFacultyStats(retryCount = 0, delay = 500) {
            // Show loading state only on first attempt
            if (retryCount === 0) {
                document.querySelectorAll('.stats-loading').forEach(el => {
                    el.style.display = 'inline-block';
                });
            }
            
            // Get all faculty IDs from the DOM
            const facultyCards = document.querySelectorAll('.faculty-card[data-faculty-id]');
            const facultyIds = Array.from(facultyCards).map(card => parseInt(card.dataset.facultyId));
            
            if (facultyIds.length === 0) {
                console.log('No faculty cards found on the page');
                return;
            }
            
            // Set up the AJAX request
            const xhr = new XMLHttpRequest();
            const timeoutId = setTimeout(() => {
                xhr.abort();
                console.log('Stats request timed out');
                if (retryCount < 3) {
                    console.log(`Retrying (${retryCount + 1}/3) after ${delay}ms delay`);
                    setTimeout(() => loadFacultyStats(retryCount + 1, delay * 2), delay);
                } else {
                    console.log('Max retry attempts reached, setting default values');
                    setDefaultValues();
                }
            }, 10000); // 10 second timeout
            
            xhr.open('POST', 'ajax/get_faculty_stats.php', true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            
            xhr.onload = function() {
                clearTimeout(timeoutId);
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        updateFacultyStats(response);
                    } catch (e) {
                        console.error('Error parsing JSON response:', e);
                        if (retryCount < 3) {
                            setTimeout(() => loadFacultyStats(retryCount + 1, delay * 2), delay);
                        } else {
                            setDefaultValues();
                        }
                    }
                } else {
                    console.error('Request failed with status:', xhr.status);
                    if (retryCount < 3) {
                        setTimeout(() => loadFacultyStats(retryCount + 1, delay * 2), delay);
                    } else {
                        setDefaultValues();
                    }
                }
            };
            
            xhr.onerror = function() {
                clearTimeout(timeoutId);
                console.error('Request error');
                if (retryCount < 3) {
                    setTimeout(() => loadFacultyStats(retryCount + 1, delay * 2), delay);
                } else {
                    setDefaultValues();
                }
            };
            
            // Send the faculty IDs in the request
            xhr.send(JSON.stringify({ faculty_ids: facultyIds }));
            
            function updateFacultyStats(data) {
                // Hide all loading indicators
                document.querySelectorAll('.stats-loading').forEach(el => {
                    el.style.display = 'none';
                });
                
                // Update each faculty card with the stats
                facultyCards.forEach(card => {
                    const facultyId = parseInt(card.dataset.facultyId);
                    const stats = data[facultyId] || {
                        subject_count: 0,
                        feedback_count: 0,
                        avg_rating: 'N/A'
                    };
                    
                    const subjectCount = card.querySelector('.subject-count');
                    const feedbackCount = card.querySelector('.feedback-count');
                    const avgRating = card.querySelector('.avg-rating');
                    
                    if (subjectCount) subjectCount.textContent = stats.subject_count;
                    if (feedbackCount) feedbackCount.textContent = stats.feedback_count;
                    if (avgRating) avgRating.textContent = stats.avg_rating;
                });
            }
            
            function setDefaultValues() {
                // Hide all loading indicators
                document.querySelectorAll('.stats-loading').forEach(el => {
                    el.style.display = 'none';
                });
                
                // Set default values for all faculty cards
                facultyCards.forEach(card => {
                    const subjectCount = card.querySelector('.subject-count');
                    const feedbackCount = card.querySelector('.feedback-count');
                    const avgRating = card.querySelector('.avg-rating');
                    
                    if (subjectCount) subjectCount.textContent = '0';
                    if (feedbackCount) feedbackCount.textContent = '0';
                    if (avgRating) avgRating.textContent = 'N/A';
                });
            }
        }
    </script>
</body>
</html>
