
<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

$subject_code = isset($_GET['code']) ? $_GET['code'] : '';

if (empty($subject_code)) {
    header('HTTP/1.1 400 Bad Request');
    exit('Subject code is required');
}

$query = "SELECT s.id, s.year, s.semester, s.section, s.faculty_id, 
          f.name as faculty_name, s.is_active
          FROM subjects s
          LEFT JOIN faculty f ON s.faculty_id = f.id
          WHERE s.code = ?
          ORDER BY s.year, s.semester, s.section";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $subject_code);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$assignments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $assignments[] = $row;
}

header('Content-Type: application/json');
echo json_encode($assignments);
?>