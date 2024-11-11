<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hod', 'faculty'])) {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

$year = $_GET['year'] ?? 'all';
$dept = $_GET['dept'] ?? 'all';

$query = "SELECT es.*, s.name as student_name, d.name as department_name 
          FROM exit_surveys es
          JOIN students s ON es.student_id = s.id
          JOIN departments d ON es.department_id = d.id
          WHERE 1=1";

if ($year !== 'all') {
    $query .= " AND YEAR(es.created_at) = ?";
}

if ($dept !== 'all') {
    $query .= " AND d.name = ?";
}

$stmt = mysqli_prepare($conn, $query);

if ($year !== 'all' && $dept !== 'all') {
    mysqli_stmt_bind_param($stmt, "is", $year, $dept);
} elseif ($year !== 'all') {
    mysqli_stmt_bind_param($stmt, "i", $year);
} elseif ($dept !== 'all') {
    mysqli_stmt_bind_param($stmt, "s", $dept);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$surveys = [];
while ($row = mysqli_fetch_assoc($result)) {
    $surveys[] = $row;
}

// Process data for charts (similar to main file)
function processRatings($surveys, $field) {
    $ratings = array_fill(1, 5, 0);
    foreach ($surveys as $survey) {
        $data = json_decode($survey[$field], true);
        foreach ($data as $rating) {
            $ratings[$rating]++;
        }
    }
    return $ratings;
}

$response = [
    'po_ratings' => processRatings($surveys, 'po_ratings'),
    'pso_ratings' => processRatings($surveys, 'pso_ratings'),
    'program_satisfaction' => processRatings($surveys, 'program_satisfaction'),
    'infrastructure_satisfaction' => processRatings($surveys, 'infrastructure_satisfaction'),
    'employment_status' => array_count_values(array_map(function($survey) {
        $emp_data = json_decode($survey['employment_status'], true);
        return $emp_data['status'];
    }, $surveys))
];

header('Content-Type: application/json');
echo json_encode($response); 