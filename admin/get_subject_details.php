<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Get subject code from request
$code = isset($_GET['code']) ? mysqli_real_escape_string($conn, $_GET['code']) : '';

if (empty($code)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing subject code');
}

// Query to get subject details
$query = "SELECT 
    s.id,
    s.code,
    s.name,
    s.department_id,
    s.faculty_id,
    s.academic_year_id,
    s.year,
    s.semester,
    s.section,
    s.credits,
    s.is_active,
    d.name as department_name,
    f.name as faculty_name,
    ay.year_range as academic_year
FROM subjects s
JOIN departments d ON s.department_id = d.id
JOIN faculty f ON s.faculty_id = f.id
JOIN academic_years ay ON s.academic_year_id = ay.id
WHERE s.code = ?
LIMIT 1";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);

if (!$subject) {
    header('HTTP/1.1 404 Not Found');
    exit('Subject not found');
}

// Get feedback statistics
$stats_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating
FROM feedback f
WHERE f.subject_id = ?";

$stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stmt, "i", $subject['id']);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Combine subject details and stats
$response = array_merge($subject, ['feedback_stats' => $stats]);

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);

mysqli_close($conn);
?> 