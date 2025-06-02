<?php
session_start();
require_once '../../functions.php';
require_once '../../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check for required parameters
if (!isset($_POST['department_id']) || !isset($_POST['batch_year_id']) || !isset($_POST['section']) || !isset($_POST['training_batch_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$department_id = $_POST['department_id'];
$batch_year_id = $_POST['batch_year_id'];
$section = $_POST['section'];
$training_batch_id = $_POST['training_batch_id'];

// Get count of students in this section who aren't already in the batch
$query = "SELECT COUNT(*) as student_count 
          FROM students 
          WHERE department_id = ? AND batch_id = ? AND section = ?
          AND id NOT IN (
              SELECT student_id FROM student_training_batch 
              WHERE training_batch_id = ? AND is_active = TRUE
          )";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iisi", $department_id, $batch_year_id, $section, $training_batch_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

// Return the count
header('Content-Type: application/json');
echo json_encode(['count' => $row['student_count']]);
exit();
?> 