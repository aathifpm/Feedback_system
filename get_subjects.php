<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hod') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;

if (!$academic_year || !$semester || !$section || !$department_id) {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing parameters');
}

$query = "SELECT id, code, name 
          FROM subjects 
          WHERE academic_year_id = ? 
          AND semester = ? 
          AND section = ? 
          AND department_id = ?
          AND is_active = TRUE
          ORDER BY code";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iisi", $academic_year, $semester, $section, $department_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

header('Content-Type: application/json');
echo json_encode($subjects);