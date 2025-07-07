<?php
session_start();
require_once '../../functions.php';
require_once '../../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_POST['year']) || !isset($_POST['month'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing year or month parameter']);
    exit();
}

$year = intval($_POST['year']);
$month = intval($_POST['month']);

// Validate year and month
if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid year or month']);
    exit();
}

// Format month for SQL query
$month_padded = sprintf("%02d", $month);
$start_date = "$year-$month_padded-01";
$end_date = date('Y-m-t', strtotime($start_date)); // t gives last day of month

// Get all holidays for this month
$query = "SELECT h.*, 
          (SELECT GROUP_CONCAT(name) FROM departments 
           WHERE FIND_IN_SET(id, h.applicable_departments)) AS department_names,
          (SELECT GROUP_CONCAT(batch_name) FROM batch_years 
           WHERE FIND_IN_SET(id, h.applicable_batches)) AS batch_names
          FROM holidays h
          WHERE (
              (h.holiday_date BETWEEN ? AND ?) OR 
              (h.is_recurring = 1 AND MONTH(h.holiday_date) = ? AND 
               (h.recurring_year IS NULL OR h.recurring_year = ?))
          )
          ORDER BY MONTH(h.holiday_date), DAY(h.holiday_date)";
          
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ssii", $start_date, $end_date, $month, $year);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$holidays = [];
while ($row = mysqli_fetch_assoc($result)) {
    $holidays[] = $row;
}

echo json_encode(['status' => 'success', 'data' => $holidays]);
?> 