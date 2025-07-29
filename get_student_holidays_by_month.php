<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// Get parameters
$year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
$month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
$student_id = $_SESSION['user_id'];

// Get student's department and batch for holiday filtering
$student_query = "SELECT s.department_id, s.batch_id 
                 FROM students s 
                 WHERE s.id = ? AND s.is_active = TRUE";
$stmt = mysqli_prepare($conn, $student_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$student_result = mysqli_stmt_get_result($stmt);
$student_data = mysqli_fetch_assoc($student_result);

$department_id = $student_data['department_id'] ?? 0;
$batch_id = $student_data['batch_id'] ?? 0;

// Create date ranges for the query
$start_date = date('Y-m-01', strtotime("$year-$month-01"));
$end_date = date('Y-m-t', strtotime("$year-$month-01"));

// Query to get holidays for the month
// Include holidays that are:
// 1. For all departments and all batches (null in both fields)
// 2. For student's specific department (either for all batches or specific batch)
// 3. For student's specific batch (either for all departments or specific department)
$holidays_query = "SELECT 
    holiday_name,
    holiday_date,
    description,
    is_recurring,
    recurring_year
FROM holidays 
WHERE (
    (holiday_date BETWEEN ? AND ?) 
    OR (
        is_recurring = 1 
        AND (recurring_year IS NULL OR recurring_year = ?)
        AND DATE_FORMAT(holiday_date, '%m-%d') BETWEEN DATE_FORMAT(?, '%m-%d') AND DATE_FORMAT(?, '%m-%d')
    )
) 
AND (
    (applicable_departments IS NULL AND applicable_batches IS NULL)
    OR (FIND_IN_SET(?, applicable_departments) OR applicable_departments IS NULL)
    AND (FIND_IN_SET(?, applicable_batches) OR applicable_batches IS NULL)
)
ORDER BY holiday_date";

$stmt = mysqli_prepare($conn, $holidays_query);
$current_year = date('Y');
mysqli_stmt_bind_param($stmt, "sssssis", $start_date, $end_date, $current_year, $start_date, $end_date, $department_id, $batch_id);
mysqli_stmt_execute($stmt);
$holidays_result = mysqli_stmt_get_result($stmt);

$holidays = [];
while ($row = mysqli_fetch_assoc($holidays_result)) {
    $holiday_date = $row['holiday_date'];
    
    // For recurring holidays, update the year to current year
    if ($row['is_recurring'] == 1) {
        $date_parts = explode('-', $holiday_date);
        $holiday_date = $year . '-' . $date_parts[1] . '-' . $date_parts[2];
    }
    
    $holidays[$holiday_date] = $row;
}

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => $holidays,
    'year' => $year,
    'month' => $month
]);
?> 