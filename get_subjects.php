<?php
session_start();
require_once 'db_connection.php';

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    die(json_encode(['error' => 'Unauthorized access']));
}

if (!isset($_POST['academic_year_id']) || !isset($_POST['department_id'])) {
    die(json_encode(['error' => 'Missing parameters']));
}

$academic_year_id = intval($_POST['academic_year_id']);
$department_id = intval($_POST['department_id']);

// Get subjects for the department and academic year
$query = "SELECT DISTINCT s.id, s.code, s.name
          FROM subjects s
          JOIN subject_assignments sa ON s.id = sa.subject_id
          WHERE s.department_id = ?
          AND sa.academic_year_id = ?
          AND sa.is_active = TRUE
          ORDER BY s.code";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $department_id, $academic_year_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$subjects = [];
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = [
        'id' => $row['id'],
        'code' => $row['code'],
        'name' => $row['name']
    ];
}

header('Content-Type: application/json');
echo json_encode($subjects);