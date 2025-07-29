<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in as a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access']);
    exit();
}

// Get parameters
$year = isset($_POST['year']) ? intval($_POST['year']) : date('Y');
$month = isset($_POST['month']) ? intval($_POST['month']) : date('n');
$student_id = $_SESSION['user_id'];

// Create date ranges for the query
$start_date = date('Y-m-01', strtotime("$year-$month-01"));
$end_date = date('Y-m-t', strtotime("$year-$month-01"));

// Query to get academic attendance records for the specified month
$academic_query = "SELECT 
    acs.class_date, 
    aar.status,
    s.name as subject_name,
    s.code as subject_code,
    aar.remarks,
    TIME_FORMAT(acs.start_time, '%H:%i') as start_time,
    TIME_FORMAT(acs.end_time, '%H:%i') as end_time
FROM academic_attendance_records aar
JOIN academic_class_schedule acs ON aar.schedule_id = acs.id
JOIN subject_assignments sa ON acs.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
WHERE aar.student_id = ? 
AND acs.class_date BETWEEN ? AND ?
ORDER BY acs.class_date, acs.start_time";

$stmt = mysqli_prepare($conn, $academic_query);
mysqli_stmt_bind_param($stmt, "iss", $student_id, $start_date, $end_date);
mysqli_stmt_execute($stmt);
$academic_result = mysqli_stmt_get_result($stmt);
$academic_attendance = [];

while ($row = mysqli_fetch_assoc($academic_result)) {
    $date = $row['class_date'];
    if (!isset($academic_attendance[$date])) {
        $academic_attendance[$date] = [];
    }
    $academic_attendance[$date][] = $row;
}

// Query to get training attendance records for the specified month
$training_query = "SELECT 
    tss.session_date, 
    tar.status,
    tb.batch_name,
    tss.topic as subject_name,
    tar.remarks,
    TIME_FORMAT(tss.start_time, '%H:%i') as start_time,
    TIME_FORMAT(tss.end_time, '%H:%i') as end_time
FROM training_attendance_records tar
JOIN training_session_schedule tss ON tar.session_id = tss.id
JOIN training_batches tb ON tss.training_batch_id = tb.id
WHERE tar.student_id = ? 
AND tss.session_date BETWEEN ? AND ?
ORDER BY tss.session_date, tss.start_time";

$stmt = mysqli_prepare($conn, $training_query);
mysqli_stmt_bind_param($stmt, "iss", $student_id, $start_date, $end_date);
mysqli_stmt_execute($stmt);
$training_result = mysqli_stmt_get_result($stmt);
$training_attendance = [];

while ($row = mysqli_fetch_assoc($training_result)) {
    $date = $row['session_date'];
    if (!isset($training_attendance[$date])) {
        $training_attendance[$date] = [];
    }
    $training_attendance[$date][] = $row;
}

// Merge both attendance records
$all_attendance = [];
foreach ($academic_attendance as $date => $records) {
    if (!isset($all_attendance[$date])) {
        $all_attendance[$date] = [];
    }
    foreach ($records as $record) {
        $record['type'] = 'academic';
        $all_attendance[$date][] = $record;
    }
}

foreach ($training_attendance as $date => $records) {
    if (!isset($all_attendance[$date])) {
        $all_attendance[$date] = [];
    }
    foreach ($records as $record) {
        $record['type'] = 'training';
        $all_attendance[$date][] = $record;
    }
}

// Return the data as JSON
header('Content-Type: application/json');
echo json_encode([
    'status' => 'success',
    'data' => $all_attendance,
    'year' => $year,
    'month' => $month
]);
?> 