<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['hod', 'faculty'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get degree from request
$degree = $_GET['degree'] ?? '';

// Prepare query
$query = "SELECT DISTINCT passing_year FROM alumni_survey";
$params = [];
$types = '';

if ($degree) {
    $query .= " WHERE degree = ?";
    $params[] = $degree;
    $types = 's';
}

$query .= " ORDER BY passing_year DESC";

// Execute query
$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Fetch results
$batches = [];
while ($row = mysqli_fetch_assoc($result)) {
    $batches[] = $row['passing_year'];
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($batches);
?> 