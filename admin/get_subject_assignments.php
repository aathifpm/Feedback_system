<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Set headers for JSON response
header('Content-Type: application/json');

// Check if subject_id parameter is provided
if (!isset($_GET['subject_id']) || empty($_GET['subject_id'])) {
    echo json_encode(['success' => false, 'message' => 'Subject ID is required']);
    exit;
}

$subject_id = intval($_GET['subject_id']);

// Check department access before fetching assignments
$check_subject_query = "SELECT department_id FROM subjects WHERE id = ?";
$stmt = mysqli_prepare($conn, $check_subject_query);
mysqli_stmt_bind_param($stmt, "i", $subject_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject_data = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$subject_data) {
    echo json_encode(['success' => false, 'message' => 'Subject not found']);
    exit;
}

// Check if department admin has access to this department
if (!admin_has_department_access($subject_data['department_id'])) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to view assignments for this subject']);
    exit;
}

// Use the existing function to get assignments
$query = "SELECT 
    sa.id,
    sa.faculty_id,
    sa.academic_year_id,
    sa.year,
    sa.semester,
    sa.section,
    sa.is_active,
    f.name as faculty_name,
    f.faculty_id as faculty_code,
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
$result = mysqli_stmt_get_result($stmt);

$assignments = [];
while ($assignment = mysqli_fetch_assoc($result)) {
    $assignments[] = $assignment;
}

echo json_encode([
    'success' => true,
    'data' => $assignments
]);
exit;
?>