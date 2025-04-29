<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Get subject ID from request
$subject_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$subject_id) {
    echo json_encode(['error' => 'Invalid subject ID']);
    exit();
}

// Get subject details
$query = "SELECT s.*, d.id as department_id, d.name as department_name 
          FROM subjects s
          JOIN departments d ON s.department_id = d.id
          WHERE s.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $subject_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);

// Check if subject exists
if (!$subject) {
    echo json_encode(['error' => 'Subject not found']);
    exit();
}

// Check if department admin has access to this department
if (!admin_has_department_access($subject['department_id'])) {
    echo json_encode(['error' => 'You don\'t have permission to view this subject.']);
    exit();
}

// Return subject data
echo json_encode(['success' => true, 'data' => $subject]);
exit();
?> 