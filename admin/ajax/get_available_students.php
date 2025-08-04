<?php
session_start();
require_once '../../functions.php';
require_once '../../db_connection.php';
require_once '../includes/admin_functions.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get parameters
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$filter_department = isset($_GET['filter_dept']) ? (int)$_GET['filter_dept'] : null;
$filter_batch = isset($_GET['filter_batch']) ? (int)$_GET['filter_batch'] : null;
$search = isset($_GET['search']) ? $_GET['search'] : null;

// Pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? min(100, max(10, (int)$_GET['per_page'])) : 20;

// Validate batch_id
if ($batch_id <= 0) {
    echo json_encode(['error' => 'Invalid batch ID']);
    exit();
}

// For department admins, force department filter to their department
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $filter_department = $_SESSION['department_id'];
}

// Fetch available students
function fetchAvailableStudentsForAjax($conn, $batch_id, $page = 1, $per_page = 20, $filter_department = null, $filter_batch = null, $search = null) {
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    $query = "SELECT s.id, s.roll_number, s.register_number, s.name,
                    d.name as department_name, b.batch_name,
                    (SELECT GROUP_CONCAT(tb.batch_name SEPARATOR ', ') 
                     FROM student_training_batch stb2 
                     JOIN training_batches tb ON stb2.training_batch_id = tb.id 
                     WHERE stb2.student_id = s.id 
                     AND stb2.training_batch_id != ? 
                     AND stb2.is_active = TRUE) as other_batches
              FROM students s
              JOIN departments d ON s.department_id = d.id
              JOIN batch_years b ON s.batch_id = b.id
              WHERE s.id NOT IN (
                  SELECT stb.student_id 
                  FROM student_training_batch stb 
                  WHERE stb.training_batch_id = ? AND stb.is_active = TRUE
              )";
              
    // Apply department filter for department admins
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $query .= " AND d.id = " . $_SESSION['department_id'];
    }
              
    $params = [$batch_id, $batch_id];
    $types = "ii";
    
    // Add department filter if provided
    if ($filter_department) {
        // For department admins, ensure they can only filter their own department
        if (!is_super_admin() && $filter_department != $_SESSION['department_id']) {
            $filter_department = $_SESSION['department_id'];
        }
        $query .= " AND d.id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }
    
    // Add batch filter if provided
    if ($filter_batch) {
        $query .= " AND b.id = ?";
        $params[] = $filter_batch;
        $types .= "i";
    }
    
    // Add search filter if provided
    if ($search) {
        $search = '%' . $search . '%';
        $query .= " AND (s.name LIKE ? OR s.roll_number LIKE ? OR s.register_number LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "sss";
    }
    
    $query .= " ORDER BY d.name, s.roll_number LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }
    
    return $students;
}

// Get count of available students (for pagination info)
function countAvailableStudentsForAjax($conn, $batch_id, $filter_department = null, $filter_batch = null, $search = null) {
    $query = "SELECT COUNT(*) as total
              FROM students s
              JOIN departments d ON s.department_id = d.id
              JOIN batch_years b ON s.batch_id = b.id
              WHERE s.id NOT IN (
                  SELECT stb.student_id 
                  FROM student_training_batch stb 
                  WHERE stb.training_batch_id = ? AND stb.is_active = TRUE
              )";
              
    // Apply department filter for department admins
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $query .= " AND d.id = " . $_SESSION['department_id'];
    }
              
    $params = [$batch_id];
    $types = "i";
    
    // Add department filter if provided
    if ($filter_department) {
        // For department admins, ensure they can only filter their own department
        if (!is_super_admin() && $filter_department != $_SESSION['department_id']) {
            $filter_department = $_SESSION['department_id'];
        }
        $query .= " AND d.id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }
    
    // Add batch filter if provided
    if ($filter_batch) {
        $query .= " AND b.id = ?";
        $params[] = $filter_batch;
        $types .= "i";
    }
    
    // Add search filter if provided
    if ($search) {
        $search = '%' . $search . '%';
        $query .= " AND (s.name LIKE ? OR s.roll_number LIKE ? OR s.register_number LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "sss";
    }
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    return $row['total'] ?? 0;
}

// Fetch data and return as JSON
$students = fetchAvailableStudentsForAjax($conn, $batch_id, $page, $per_page, $filter_department, $filter_batch, $search);
$total = countAvailableStudentsForAjax($conn, $batch_id, $filter_department, $filter_batch, $search);

// Calculate pagination info
$total_pages = ceil($total / $per_page);
$has_previous = ($page > 1);
$has_next = ($page < $total_pages);

// Return the results
echo json_encode([
    'students' => $students,
    'total' => $total,
    'filtered_count' => count($students),
    'pagination' => [
        'current_page' => $page,
        'per_page' => $per_page,
        'total_pages' => $total_pages,
        'has_previous' => $has_previous,
        'has_next' => $has_next,
        'previous_page' => $has_previous ? $page - 1 : null,
        'next_page' => $has_next ? $page + 1 : null
    ]
]); 