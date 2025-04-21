<?php
// Include database connection and required files
require_once '../../db_connection.php';
require_once '../../functions.php';
require_once '../includes/admin_functions.php';

// Start session and check admin authentication
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check for POST request with JSON content
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Get input data
$input_data = json_decode(file_get_contents('php://input'), true);
if (!isset($input_data['student_ids']) || !is_array($input_data['student_ids']) || empty($input_data['student_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit();
}

// Sanitize student IDs
$student_ids = array_map('intval', $input_data['student_ids']);
$id_list = implode(',', $student_ids);

// Initialize response data with default values for all requested students
$response_data = [];
foreach ($student_ids as $student_id) {
    $response_data[$student_id] = [
        'feedback_count' => 0,
        'avg_rating' => 'N/A'
    ];
}

// Query to get feedback stats
$feedback_query = "SELECT fb.student_id, 
                     COUNT(DISTINCT fb.id) as feedback_count,
                     ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
                   FROM feedback fb
                   WHERE fb.student_id IN ($id_list)
                   GROUP BY fb.student_id";

// Department access check for department admins
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    // First, get the list of student IDs that belong to the admin's department
    $dept_check_query = "SELECT id FROM students WHERE department_id = ? AND id IN ($id_list)";
    $stmt = mysqli_prepare($conn, $dept_check_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $valid_student_ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $valid_student_ids[] = $row['id'];
    }
    
    // If no valid students, return empty response
    if (empty($valid_student_ids)) {
        echo json_encode([]);
        exit();
    }
    
    // Filter response_data to include only authorized students
    foreach ($response_data as $student_id => $data) {
        if (!in_array($student_id, $valid_student_ids)) {
            unset($response_data[$student_id]);
        }
    }
    
    // Update the ID list with only the valid IDs
    $id_list = implode(',', $valid_student_ids);
    $feedback_query = "SELECT fb.student_id, 
                         COUNT(DISTINCT fb.id) as feedback_count,
                         ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
                       FROM feedback fb
                       WHERE fb.student_id IN ($id_list)
                       GROUP BY fb.student_id";
}

// Execute the query
$result = mysqli_query($conn, $feedback_query);

// If query fails
if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit();
}

// Process results - override defaults with actual data
while ($row = mysqli_fetch_assoc($result)) {
    $response_data[$row['student_id']] = [
        'feedback_count' => (int)$row['feedback_count'],
        'avg_rating' => $row['avg_rating'] ? $row['avg_rating'] : 'N/A'
    ];
}

// Set content type to JSON
header('Content-Type: application/json');

// Add cache control headers
header('Cache-Control: public, max-age=300'); // 5 minute cache
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');

// Return the data
echo json_encode($response_data);
exit; 