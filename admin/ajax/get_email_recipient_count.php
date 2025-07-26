<?php
session_start();
require_once '../../functions.php';
require_once '../../db_connection.php';

// For debugging - log all requests
$log_file = fopen(__DIR__ . "/recipient_count_log.txt", "a");
$time = date('Y-m-d H:i:s');
fwrite($log_file, "[$time] Request received\n");
fwrite($log_file, "POST data: " . print_r($_POST, true) . "\n");

// Set headers for AJAX
header('Content-Type: application/json');

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    fwrite($log_file, "[$time] Unauthorized access\n");
    fclose($log_file);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Initialize response
$response = ['success' => false, 'count' => 0];

try {
    // Check database connection
    if (!$conn) {
        throw new Exception("Database connection failed: " . mysqli_connect_error());
    }
    
    // Validate inputs
    if (!isset($_POST['recipient_type'])) {
        throw new Exception("Recipient type is required");
    }
    
    $recipient_type = $_POST['recipient_type'];
    fwrite($log_file, "[$time] Recipient type: $recipient_type\n");
    
    $department = isset($_POST['department']) && $_POST['department'] != 'all' ? intval($_POST['department']) : null;
    $batch = isset($_POST['batch']) && $_POST['batch'] != 'all' ? intval($_POST['batch']) : null;
    $section = isset($_POST['section']) && $_POST['section'] != 'all' ? mysqli_real_escape_string($conn, $_POST['section']) : null;
    
    $count = 0;
    
    switch ($recipient_type) {
        case 'faculty':
            $query = "SELECT COUNT(*) as total FROM faculty WHERE is_active = 1";
            if ($department) {
                $query .= " AND department_id = $department";
            }
            break;
            
        case 'students':
            $query = "SELECT COUNT(*) as total FROM students WHERE is_active = 1";
            if ($department) {
                $query .= " AND department_id = $department";
            }
            if ($batch) {
                $query .= " AND batch_id = $batch";
            }
            if ($section) {
                $query .= " AND section = '$section'";
            }
            break;
            
        case 'hods':
            $query = "SELECT COUNT(*) as total FROM hods WHERE is_active = 1";
            if ($department) {
                $query .= " AND department_id = $department";
            }
            break;
            
        case 'all':
            // Count faculty
            $faculty_query = "SELECT COUNT(*) as total FROM faculty WHERE is_active = 1";
            $faculty_result = mysqli_query($conn, $faculty_query);
            if (!$faculty_result) {
                throw new Exception("Faculty query failed: " . mysqli_error($conn));
            }
            $faculty_count = mysqli_fetch_assoc($faculty_result)['total'];
            
            // Count HODs
            $hods_query = "SELECT COUNT(*) as total FROM hods WHERE is_active = 1";
            $hods_result = mysqli_query($conn, $hods_query);
            if (!$hods_result) {
                throw new Exception("HODs query failed: " . mysqli_error($conn));
            }
            $hods_count = mysqli_fetch_assoc($hods_result)['total'];
            
            // Count students
            $students_query = "SELECT COUNT(*) as total FROM students WHERE is_active = 1";
            $students_result = mysqli_query($conn, $students_query);
            if (!$students_result) {
                throw new Exception("Students query failed: " . mysqli_error($conn));
            }
            $students_count = mysqli_fetch_assoc($students_result)['total'];
            
            $count = $faculty_count + $hods_count + $students_count;
            fwrite($log_file, "[$time] All users count: $count\n");
            fclose($log_file);
            $response['success'] = true;
            $response['count'] = $count;
            echo json_encode($response);
            exit();
            
        default:
            throw new Exception("Invalid recipient type: $recipient_type");
    }
    
    // If we're here, we're not handling 'all' user type
    fwrite($log_file, "[$time] Query: $query\n");
    
    $result = mysqli_query($conn, $query);
    if (!$result) {
        throw new Exception("Query failed: " . mysqli_error($conn));
    }
    
    $row = mysqli_fetch_assoc($result);
    $count = $row['total'];
    
    fwrite($log_file, "[$time] Count result: $count\n");
    
    $response['success'] = true;
    $response['count'] = $count;
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    fwrite($log_file, "[$time] Error: $error_message\n");
    $response['error'] = $error_message;
}

fclose($log_file);
echo json_encode($response);
exit(); 