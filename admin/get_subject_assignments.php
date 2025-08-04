<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

// Get subject ID from request
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

if (!$subject_id) {
    echo json_encode(['error' => 'Invalid subject ID']);
    exit();
}

// First check if subject belongs to a department the admin has access to
$check_query = "SELECT s.id, s.department_id 
               FROM subjects s 
               WHERE s.id = ?";

$stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($stmt, "i", $subject_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);

if (!$subject) {
    echo json_encode(['error' => 'Subject not found']);
    exit();
}

// Check if department admin has access to this department
if (!admin_has_department_access($subject['department_id'])) {
    echo json_encode(['error' => 'You don\'t have permission to view assignments for this subject.']);
    exit();
}

// Get assignments for this subject
$query = "SELECT 
    sa.id,
    sa.academic_year_id,
    sa.year,
    sa.semester,
    sa.section,
    sa.is_active,
    f.id as faculty_id,
    f.name as faculty_name,
    ay.year_range as academic_year,
    ay.is_current
FROM subject_assignments sa
JOIN faculty f ON sa.faculty_id = f.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
WHERE sa.subject_id = ?
ORDER BY sa.academic_year_id DESC, sa.year, sa.semester, sa.section";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $subject_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$assignments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assignments[] = $row;
}

echo json_encode(['success' => true, 'data' => $assignments]);
exit();
?>