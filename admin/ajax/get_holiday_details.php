<?php
session_start();
require_once '../../functions.php';
require_once '../../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_POST['holiday_id']) || !is_numeric($_POST['holiday_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid holiday ID']);
    exit();
}

$holiday_id = mysqli_real_escape_string($conn, $_POST['holiday_id']);

$query = "SELECT * FROM holidays WHERE id = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $holiday_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($holiday = mysqli_fetch_assoc($result)) {
    echo json_encode(['status' => 'success', 'data' => $holiday]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Holiday not found']);
}
?> 