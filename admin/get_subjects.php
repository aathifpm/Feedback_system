<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    die('Unauthorized access');
}

if (!isset($_POST['semester'])) {
    die('Semester not specified');
}

$semester = intval($_POST['semester']);

// Build the query with department filtering
$department_filter = "";
$params = [$semester];

// Check if user is department admin and has department access
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $department_filter = " AND s.department_id = ?";
    $params[] = $_SESSION['department_id'];
}

// Get subjects for the specified semester with department filtering
$query = "SELECT DISTINCT s.id, s.name, s.code, d.name as department_name 
          FROM subjects s
          JOIN subject_assignments sa ON s.id = sa.subject_id
          JOIN departments d ON s.department_id = d.id
          WHERE sa.semester = ?" . $department_filter . "
          ORDER BY d.name, s.code";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, str_repeat('i', count($params)), ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Generate options HTML
echo '<option value="">Select Subject</option>';
while ($subject = mysqli_fetch_assoc($result)) {
    // Include department name for super admin
    $display_text = is_super_admin() 
        ? htmlspecialchars($subject['code'] . ' - ' . $subject['name'] . ' (' . $subject['department_name'] . ')')
        : htmlspecialchars($subject['code'] . ' - ' . $subject['name']);
    
    echo '<option value="' . $subject['id'] . '">' . $display_text . '</option>';
}
?> 