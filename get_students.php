<?php
session_start();
include 'functions.php';

// Check if the user is logged in and has the appropriate role
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'faculty' && $_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods')) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Sanitize and validate input
$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : '';

// Validate input
if ($year < 1 || $year > 4 || $semester < 1 || $semester > 8 || empty($section)) {
    echo json_encode(['error' => 'Invalid input parameters']);
    exit();
}

// Prepare the query
$query = "SELECT s.id, s.name, s.roll_number, s.register_number 
          FROM students s
          WHERE s.department_id = ? 
          AND s.current_year = ? 
          AND s.current_semester = ? 
          AND s.section = ?
          AND s.is_active = TRUE";

// Add search condition if search term is provided
if (!empty($search)) {
    $query .= " AND (s.name LIKE ? OR s.roll_number LIKE ? OR s.register_number LIKE ?)";
}

$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    echo json_encode(['error' => 'Database error: ' . mysqli_error($conn)]);
    exit();
}

// Bind parameters and execute the query
if (!empty($search)) {
    $search_term = "%$search%";
    mysqli_stmt_bind_param($stmt, "iiissss", 
        $_SESSION['department_id'], 
        $year, 
        $semester, 
        $section, 
        $search_term, 
        $search_term,
        $search_term
    );
} else {
    mysqli_stmt_bind_param($stmt, "iiis", 
        $_SESSION['department_id'], 
        $year, 
        $semester, 
        $section
    );
}

$success = mysqli_stmt_execute($stmt);

if (!$success) {
    echo json_encode(['error' => 'Query execution failed: ' . mysqli_stmt_error($stmt)]);
    exit();
}

$result = mysqli_stmt_get_result($stmt);

// Fetch and format the results
$students = [];
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = [
        'id' => $row['id'],
        'text' => $row['name'] . ' (' . $row['roll_number'] . ')'
    ];
}

// Return the results as JSON
header('Content-Type: application/json');
echo json_encode($students);

// Close the statement and connection
mysqli_stmt_close($stmt);
mysqli_close($conn);