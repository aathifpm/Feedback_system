<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';

if ($faculty_id && $year && $semester && $section) {
    $query = "SELECT id, name FROM subjects 
              WHERE faculty_id = ? 
              AND year = ? 
              AND semester = ? 
              AND section = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "iiis", $faculty_id, $year, $semester, $section);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $subjects = array();
    while ($row = mysqli_fetch_assoc($result)) {
        $subjects[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($subjects);
} else {
    header('HTTP/1.1 400 Bad Request');
    exit('Missing required parameters');
}