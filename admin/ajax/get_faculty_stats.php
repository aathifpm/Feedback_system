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
if (!isset($input_data['faculty_ids']) || !is_array($input_data['faculty_ids']) || empty($input_data['faculty_ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit();
}

// Sanitize faculty IDs
$faculty_ids = array_map('intval', $input_data['faculty_ids']);
$id_list = implode(',', $faculty_ids);

// Initialize response data with default values for all requested faculty
$response_data = [];
foreach ($faculty_ids as $faculty_id) {
    $response_data[$faculty_id] = [
        'subject_count' => 0,
        'feedback_count' => 0,
        'avg_rating' => 'N/A'
    ];
}

// Department access check for department admins
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    // First, get the list of faculty IDs that belong to the admin's department
    $dept_check_query = "SELECT id FROM faculty WHERE department_id = ? AND id IN ($id_list)";
    $stmt = mysqli_prepare($conn, $dept_check_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $valid_faculty_ids = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $valid_faculty_ids[] = $row['id'];
    }
    
    // If no valid faculty, return empty response
    if (empty($valid_faculty_ids)) {
        echo json_encode([]);
        exit();
    }
    
    // Filter response_data to include only authorized faculty
    foreach ($response_data as $faculty_id => $data) {
        if (!in_array($faculty_id, $valid_faculty_ids)) {
            unset($response_data[$faculty_id]);
        }
    }
    
    // Update the ID list with only the valid IDs
    $id_list = implode(',', $valid_faculty_ids);
}

// Query to get subject count
$subject_query = "SELECT faculty_id, COUNT(DISTINCT id) as subject_count
                 FROM subject_assignments
                 WHERE faculty_id IN ($id_list) AND is_active = 1
                 GROUP BY faculty_id";
                 
$subject_result = mysqli_query($conn, $subject_query);

// Update subject counts in the response data
while ($row = mysqli_fetch_assoc($subject_result)) {
    if (isset($response_data[$row['faculty_id']])) {
        $response_data[$row['faculty_id']]['subject_count'] = (int)$row['subject_count'];
    }
}

// Query to get feedback stats
$feedback_query = "SELECT sa.faculty_id, 
                      COUNT(DISTINCT fb.id) as feedback_count,
                      ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
                  FROM subject_assignments sa
                  JOIN feedback fb ON sa.id = fb.assignment_id
                  WHERE sa.faculty_id IN ($id_list) AND sa.is_active = 1
                  GROUP BY sa.faculty_id";

$feedback_result = mysqli_query($conn, $feedback_query);

// Update feedback stats in the response data
while ($row = mysqli_fetch_assoc($feedback_result)) {
    if (isset($response_data[$row['faculty_id']])) {
        $response_data[$row['faculty_id']]['feedback_count'] = (int)$row['feedback_count'];
        $response_data[$row['faculty_id']]['avg_rating'] = $row['avg_rating'] ? $row['avg_rating'] : 'N/A';
    }
}

// Set content type to JSON
header('Content-Type: application/json');

// Add cache control headers
header('Cache-Control: public, max-age=300'); // 5 minute cache
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 300) . ' GMT');

// Return the data
echo json_encode($response_data);
exit; 