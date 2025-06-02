<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';
require_once 'includes/admin_functions.php';

// Require the PhpSpreadsheet library
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

$success = '';
$error = '';
$batch = null;
$batch_students = [];
$available_students = [];

// Check if batch_id is provided
if (!isset($_GET['batch_id'])) {
    header('Location: manage_training_batches.php');
    exit();
}

$batch_id = $_GET['batch_id'];

// Get batch details
$batch_query = "SELECT tb.*, ay.year_range, d.name as department_name
               FROM training_batches tb
               JOIN academic_years ay ON tb.academic_year_id = ay.id
               JOIN departments d ON tb.department_id = d.id
               WHERE tb.id = ?";
$batch_stmt = mysqli_prepare($conn, $batch_query);
mysqli_stmt_bind_param($batch_stmt, "i", $batch_id);
mysqli_stmt_execute($batch_stmt);
$batch_result = mysqli_stmt_get_result($batch_stmt);

if ($batch_row = mysqli_fetch_assoc($batch_result)) {
    $batch = $batch_row;
    
    // Check department access for department admins
    if (!admin_has_department_access($batch['department_id'])) {
        $_SESSION['error_message'] = "You don't have permission to manage students in this training batch.";
        header('Location: manage_training_batches.php');
        exit();
    }
} else {
    header('Location: manage_training_batches.php');
    exit();
}

// Get students in this batch
function fetchBatchStudents($conn, $batch_id) {
    $query = "SELECT s.id, s.roll_number, s.register_number, s.name,
                    d.name as department_name, s.phone, s.email, 
                    b.batch_name, stb.assigned_date
              FROM students s
              JOIN departments d ON s.department_id = d.id
              JOIN batch_years b ON s.batch_id = b.id
              JOIN student_training_batch stb ON s.id = stb.student_id
              WHERE stb.training_batch_id = ? AND stb.is_active = TRUE
              ORDER BY d.name, s.roll_number";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $batch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }
    
    return $students;
}

// Get available students (students not in this batch)
function fetchAvailableStudents($conn, $batch_id, $page = 1, $per_page = 50, $filter_department = null, $filter_batch = null) {
    // Calculate offset
    $offset = ($page - 1) * $per_page;
    
    $query = "SELECT s.id, s.roll_number, s.register_number, s.name,
                    d.name as department_name, b.batch_name,
                    (SELECT GROUP_CONCAT(tb.batch_name SEPARATOR ', ') 
                     FROM student_training_batch stb2 
                     JOIN training_batches tb ON stb2.training_batch_id = tb.id 
                     WHERE stb2.student_id = s.id 
                     AND stb2.training_batch_id != ? 
                     AND stb2.is_active = TRUE) as other_batches
              FROM students s
              JOIN departments d ON s.department_id = d.id
              JOIN batch_years b ON s.batch_id = b.id
              WHERE s.id NOT IN (
                  SELECT stb.student_id 
                  FROM student_training_batch stb 
                  WHERE stb.training_batch_id = ? AND stb.is_active = TRUE
              )";
              
    // Apply department filter for department admins
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $query .= " AND d.id = " . $_SESSION['department_id'];
    }
              
    $params = [$batch_id, $batch_id];
    $types = "ii";
    
    // Add department filter if provided
    if ($filter_department) {
        // For department admins, ensure they can only filter their own department
        if (!is_super_admin() && $filter_department != $_SESSION['department_id']) {
            $filter_department = $_SESSION['department_id'];
        }
        $query .= " AND d.id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }
    
    // Add batch filter if provided
    if ($filter_batch) {
        $query .= " AND b.id = ?";
        $params[] = $filter_batch;
        $types .= "i";
    }
    
    $query .= " ORDER BY d.name, s.roll_number LIMIT ? OFFSET ?";
    $params[] = $per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $students = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $students[] = $row;
    }
    
    return $students;
}

function countAvailableStudents($conn, $batch_id, $filter_department = null, $filter_batch = null) {
    $query = "SELECT COUNT(*) as total
              FROM students s
              JOIN departments d ON s.department_id = d.id
              JOIN batch_years b ON s.batch_id = b.id
              WHERE s.id NOT IN (
                  SELECT stb.student_id 
                  FROM student_training_batch stb 
                  WHERE stb.training_batch_id = ? AND stb.is_active = TRUE
              )";
              
    // Apply department filter for department admins
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $query .= " AND d.id = " . $_SESSION['department_id'];
    }
              
    $params = [$batch_id];
    $types = "i";
    
    // Add department filter if provided
    if ($filter_department) {
        // For department admins, ensure they can only filter their own department
        if (!is_super_admin() && $filter_department != $_SESSION['department_id']) {
            $filter_department = $_SESSION['department_id'];
        }
        $query .= " AND d.id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }
    
    // Add batch filter if provided
    if ($filter_batch) {
        $query .= " AND b.id = ?";
        $params[] = $filter_batch;
        $types .= "i";
    }
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    
    return $row['total'] ?? 0;
}

// Get section list for filtering
$sections_query = "SELECT DISTINCT section FROM students";
// Apply department filter for department admins
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $sections_query .= " WHERE department_id = " . $_SESSION['department_id'];
}
$sections_query .= " ORDER BY section";
$sections_result = mysqli_query($conn, $sections_query);
$sections = [];
while ($section = mysqli_fetch_assoc($sections_result)) {
    $sections[] = $section['section'];
}

// Handle add students to batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_students'])) {
    $student_ids = isset($_POST['add_student_ids']) ? $_POST['add_student_ids'] : [];
    
    if (empty($student_ids)) {
        $error = "No students selected to add.";
    } else {
        $added_count = 0;
        
        foreach ($student_ids as $student_id) {
            // If department admin, verify student belongs to their department
            if (!is_super_admin()) {
                $verify_query = "SELECT department_id FROM students WHERE id = ?";
                $verify_stmt = mysqli_prepare($conn, $verify_query);
                mysqli_stmt_bind_param($verify_stmt, "i", $student_id);
                mysqli_stmt_execute($verify_stmt);
                $verify_result = mysqli_stmt_get_result($verify_stmt);
                $student_data = mysqli_fetch_assoc($verify_result);
                
                if (!$student_data || $student_data['department_id'] != $_SESSION['department_id']) {
                    continue; // Skip students from other departments for department admins
                }
            }
            
            // Check if student is already in the batch
            $check_query = "SELECT id FROM student_training_batch 
                           WHERE student_id = ? AND training_batch_id = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "ii", $student_id, $batch_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);
            
            if ($existing = mysqli_fetch_assoc($check_result)) {
                // Update existing record if inactive
                $update_query = "UPDATE student_training_batch 
                               SET is_active = TRUE, assigned_date = CURRENT_TIMESTAMP
                               WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($update_stmt, "i", $existing['id']);
                
                if (mysqli_stmt_execute($update_stmt)) {
                    $added_count++;
                }
            } else {
                // Insert new record
                $insert_query = "INSERT INTO student_training_batch (student_id, training_batch_id)
                               VALUES (?, ?)";
                $insert_stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($insert_stmt, "ii", $student_id, $batch_id);
                
                if (mysqli_stmt_execute($insert_stmt)) {
                    $added_count++;
                }
            }
        }
        
        if ($added_count > 0) {
            $success = "$added_count student(s) added to the batch successfully.";
            
            // Log the action
            $admin_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                         VALUES (?, 'admin', 'add_batch_students', ?, 'success', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $details = json_encode(['batch_id' => $batch_id, 'count' => $added_count, 'student_ids' => $student_ids]);
            mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to add students to the batch.";
        }
    }
}

// Handle remove students from batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_students'])) {
    $student_ids = isset($_POST['remove_student_ids']) ? $_POST['remove_student_ids'] : [];
    
    if (empty($student_ids)) {
        $error = "No students selected to remove.";
    } else {
        $removed_count = 0;
        
        foreach ($student_ids as $student_id) {
            // If department admin, verify student belongs to their department
            if (!is_super_admin()) {
                $verify_query = "SELECT department_id FROM students WHERE id = ?";
                $verify_stmt = mysqli_prepare($conn, $verify_query);
                mysqli_stmt_bind_param($verify_stmt, "i", $student_id);
                mysqli_stmt_execute($verify_stmt);
                $verify_result = mysqli_stmt_get_result($verify_stmt);
                $student_data = mysqli_fetch_assoc($verify_result);
                
                if (!$student_data || $student_data['department_id'] != $_SESSION['department_id']) {
                    continue; // Skip students from other departments for department admins
                }
            }
            
            // Set student as inactive in the batch
            $update_query = "UPDATE student_training_batch 
                           SET is_active = FALSE 
                           WHERE student_id = ? AND training_batch_id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ii", $student_id, $batch_id);
            
            if (mysqli_stmt_execute($update_stmt) && mysqli_affected_rows($conn) > 0) {
                $removed_count++;
            }
        }
        
        if ($removed_count > 0) {
            $success = "$removed_count student(s) removed from the batch successfully.";
            
            // Log the action
            $admin_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                         VALUES (?, 'admin', 'remove_batch_students', ?, 'success', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $details = json_encode(['batch_id' => $batch_id, 'count' => $removed_count, 'student_ids' => $student_ids]);
            mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to remove students from the batch.";
        }
    }
}

// Handle bulk add students by section
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_add_section'])) {
    $department_id = isset($_POST['bulk_department_id']) ? $_POST['bulk_department_id'] : '';
    $batch_year_id = isset($_POST['bulk_batch_year_id']) ? $_POST['bulk_batch_year_id'] : '';
    $section = isset($_POST['bulk_section']) ? $_POST['bulk_section'] : '';
    
    // Department access check for department admins
    if (!is_super_admin() && $department_id != $_SESSION['department_id']) {
        $error = "You don't have permission to add students from other departments.";
    }
    else if (empty($department_id) || empty($batch_year_id) || empty($section)) {
        $error = "Please select department, batch year and section to bulk add students.";
    } else {
        // Check if department matches batch department
        if ($department_id != $batch['department_id']) {
            $warning = "Note: You're adding students from a different department than the batch's department (" . htmlspecialchars($batch['department_name']) . ").";
        }
        
        // Get students by department, batch and section
        $students_query = "SELECT id FROM students 
                          WHERE department_id = ? AND batch_id = ? AND section = ?
                          AND id NOT IN (
                              SELECT student_id FROM student_training_batch 
                              WHERE training_batch_id = ? AND is_active = TRUE
                          )";
        $students_stmt = mysqli_prepare($conn, $students_query);
        mysqli_stmt_bind_param($students_stmt, "iisi", $department_id, $batch_year_id, $section, $batch_id);
        mysqli_stmt_execute($students_stmt);
        $students_result = mysqli_stmt_get_result($students_stmt);
        
        $added_count = 0;
        $student_ids = [];
        
        while ($student = mysqli_fetch_assoc($students_result)) {
            $student_ids[] = $student['id'];
        }
        
        if (empty($student_ids)) {
            $error = "No students found with the selected criteria or they are already in this batch.";
        } else {
            foreach ($student_ids as $student_id) {
                // Check if student is already in the batch but inactive
                $check_query = "SELECT id FROM student_training_batch 
                               WHERE student_id = ? AND training_batch_id = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "ii", $student_id, $batch_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if ($existing = mysqli_fetch_assoc($check_result)) {
                    // Update existing record if inactive
                    $update_query = "UPDATE student_training_batch 
                                   SET is_active = TRUE, assigned_date = CURRENT_TIMESTAMP
                                   WHERE id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($update_stmt, "i", $existing['id']);
                    
                    if (mysqli_stmt_execute($update_stmt)) {
                        $added_count++;
                    }
                } else {
                    // Insert new record
                    $insert_query = "INSERT INTO student_training_batch (student_id, training_batch_id)
                                   VALUES (?, ?)";
                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($insert_stmt, "ii", $student_id, $batch_id);
                    
                    if (mysqli_stmt_execute($insert_stmt)) {
                        $added_count++;
                    }
                }
            }
            
            if ($added_count > 0) {
                $success = "$added_count student(s) added to the batch successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'bulk_add_students_section', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode([
                    'batch_id' => $batch_id, 
                    'count' => $added_count, 
                    'department_id' => $department_id,
                    'batch_year_id' => $batch_year_id,
                    'section' => $section
                ]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to add students to the batch.";
            }
        }
    }
}

// Handle bulk add students by Excel import
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_add_excel'])) {
    // Check if file was uploaded without errors
    if (isset($_FILES["student_list_file"]) && $_FILES["student_list_file"]["error"] == 0) {
        $allowed = ["csv" => "text/csv", "xlsx" => "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"];
        $filename = $_FILES["student_list_file"]["name"];
        $filetype = $_FILES["student_list_file"]["type"];
        $filesize = $_FILES["student_list_file"]["size"];

        // Validate file extension
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        if(!array_key_exists($ext, $allowed)) {
            $error = "Error: Please select a valid file format (CSV or XLSX).";
        } else {
            $maxsize = 5 * 1024 * 1024; // 5MB
            if($filesize > $maxsize) {
                $error = "Error: File size is larger than the allowed limit (5MB).";
            } else {
                $temp = $_FILES["student_list_file"]["tmp_name"];
                $student_ids = [];
                $id_type = $_POST['id_type']; // roll_number or register_number
                
                if($ext == "csv") {
                    // Process CSV file
                    if(($handle = fopen($temp, "r")) !== FALSE) {
                        // Get header row
                        $header = fgetcsv($handle);
                        
                        // Validate header
                        if (!$header || strtolower(trim($header[0])) !== 'student_id') {
                            $error = "Invalid file format. The first column header must be 'student_id'.";
                        } else {
                            while(($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                                if(isset($data[0]) && !empty($data[0])) {
                                    $student_id_value = trim($data[0]);
                                    
                                    // Find student by roll number or register number
                                    $find_query = "SELECT id FROM students WHERE $id_type = ?";
                                    $find_stmt = mysqli_prepare($conn, $find_query);
                                    mysqli_stmt_bind_param($find_stmt, "s", $student_id_value);
                                    mysqli_stmt_execute($find_stmt);
                                    $find_result = mysqli_stmt_get_result($find_stmt);
                                    
                                    if($student = mysqli_fetch_assoc($find_result)) {
                                        $student_ids[] = $student['id'];
                                    }
                                }
                            }
                        }
                        fclose($handle);
                    }
                } else {
                    // Process XLSX file using PHPSpreadsheet
                    try {
                        $spreadsheet = IOFactory::load($temp);
                        $worksheet = $spreadsheet->getActiveSheet();
                        $rows = $worksheet->toArray();
                        
                        // Get header row
                        $header = isset($rows[0]) ? $rows[0] : [];
                        
                        // Validate header
                        if (empty($header) || strtolower(trim($header[0])) !== 'student_id') {
                            $error = "Invalid file format. The first column header must be 'student_id'.";
                        } else {
                            // Skip header row
                            array_shift($rows);
                            
                            foreach($rows as $row) {
                                if(isset($row[0]) && !empty($row[0])) {
                                    $student_id_value = trim($row[0]);
                                    
                                    // Find student by roll number or register number
                                    $find_query = "SELECT id FROM students WHERE $id_type = ?";
                                    $find_stmt = mysqli_prepare($conn, $find_query);
                                    mysqli_stmt_bind_param($find_stmt, "s", $student_id_value);
                                    mysqli_stmt_execute($find_stmt);
                                    $find_result = mysqli_stmt_get_result($find_stmt);
                                    
                                    if($student = mysqli_fetch_assoc($find_result)) {
                                        $student_ids[] = $student['id'];
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        $error = "Error processing Excel file: " . $e->getMessage();
                    }
                }
                
                if(!empty($student_ids)) {
                    $added_count = 0;
                    
                    // Filter out students already in the batch
                    $existing_query = "SELECT student_id FROM student_training_batch 
                                      WHERE training_batch_id = ? AND is_active = TRUE";
                    $existing_stmt = mysqli_prepare($conn, $existing_query);
                    mysqli_stmt_bind_param($existing_stmt, "i", $batch_id);
                    mysqli_stmt_execute($existing_stmt);
                    $existing_result = mysqli_stmt_get_result($existing_stmt);
                    
                    $existing_students = [];
                    while($row = mysqli_fetch_assoc($existing_result)) {
                        $existing_students[] = $row['student_id'];
                    }
                    
                    $student_ids = array_diff($student_ids, $existing_students);
                    
                    foreach($student_ids as $student_id) {
                        // Check if student is already in the batch but inactive
                        $check_query = "SELECT id FROM student_training_batch 
                                       WHERE student_id = ? AND training_batch_id = ?";
                        $check_stmt = mysqli_prepare($conn, $check_query);
                        mysqli_stmt_bind_param($check_stmt, "ii", $student_id, $batch_id);
                        mysqli_stmt_execute($check_stmt);
                        $check_result = mysqli_stmt_get_result($check_stmt);
                        
                        if($existing = mysqli_fetch_assoc($check_result)) {
                            // Update existing record if inactive
                            $update_query = "UPDATE student_training_batch 
                                           SET is_active = TRUE, assigned_date = CURRENT_TIMESTAMP
                                           WHERE id = ?";
                            $update_stmt = mysqli_prepare($conn, $update_query);
                            mysqli_stmt_bind_param($update_stmt, "i", $existing['id']);
                            
                            if(mysqli_stmt_execute($update_stmt)) {
                                $added_count++;
                            }
                        } else {
                            // Insert new record
                            $insert_query = "INSERT INTO student_training_batch (student_id, training_batch_id)
                                           VALUES (?, ?)";
                            $insert_stmt = mysqli_prepare($conn, $insert_query);
                            mysqli_stmt_bind_param($insert_stmt, "ii", $student_id, $batch_id);
                            
                            if(mysqli_stmt_execute($insert_stmt)) {
                                $added_count++;
                            }
                        }
                    }
                    
                    if($added_count > 0) {
                        $success = "$added_count student(s) added to the batch successfully from the imported file.";
                        
                        // Log the action
                        $admin_id = $_SESSION['user_id'];
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                                     VALUES (?, 'admin', 'bulk_add_students_excel', ?, 'success', ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'batch_id' => $batch_id, 
                            'count' => $added_count,
                            'id_type' => $id_type
                        ]);
                        mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                        mysqli_stmt_execute($log_stmt);
                    } else {
                        $error = "No new students were found in the imported file or they are already in this batch.";
                    }
                } else {
                    $error = "No valid student IDs found in the uploaded file.";
                }
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Get department list for filtering - department admins only see their department
if (is_super_admin()) {
    $dept_query = "SELECT id, name FROM departments ORDER BY name";
    $departments_result = mysqli_query($conn, $dept_query);
} else {
    $dept_query = "SELECT id, name FROM departments WHERE id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $departments_result = mysqli_stmt_get_result($stmt);
}

$departments = [];
while ($dept = mysqli_fetch_assoc($departments_result)) {
    $departments[] = $dept;
}

// Get academic batch years for filtering
$batch_years_query = "SELECT id, batch_name FROM batch_years ORDER BY batch_name DESC";
$batch_years_result = mysqli_query($conn, $batch_years_query);
$batch_years = [];
while ($year = mysqli_fetch_assoc($batch_years_result)) {
    $batch_years[] = $year;
}

// Pagination and filtering for available students
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50; // Number of students per page
$filter_department = isset($_GET['filter_dept']) ? (int)$_GET['filter_dept'] : null;
$filter_batch = isset($_GET['filter_batch']) ? (int)$_GET['filter_batch'] : null;

// For department admins, force department filter to their department
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $filter_department = $_SESSION['department_id'];
}

// Fetch students after any operations
$batch_students = fetchBatchStudents($conn, $batch_id);
$available_students = fetchAvailableStudents($conn, $batch_id, $current_page, $per_page, $filter_department, $filter_batch);
$total_available = countAvailableStudents($conn, $batch_id, $filter_department, $filter_batch);
$total_pages = ceil($total_available / $per_page);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Batch Students - Admin Dashboard</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/select/1.3.1/css/select.bootstrap4.min.css">
    <style>
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e3e6f0;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
        .btn-success:hover {
            background-color: #17a673;
            border-color: #17a673;
        }
        .btn-info {
            background-color: #36b9cc;
            border-color: #36b9cc;
        }
        .btn-info:hover {
            background-color: #2c9faf;
            border-color: #2c9faf;
        }
        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
        }
        .btn-danger:hover {
            background-color: #be2617;
            border-color: #be2617;
        }
        .badge-success {
            background-color: #1cc88a;
        }
        .badge-danger {
            background-color: #e74a3b;
        }
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        .form-control {
            border-radius: 5px;
        }
        .batch-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .batch-name {
            font-size: 1.4rem;
            font-weight: 600;
            color: #4e73df;
        }
        .filter-section {
            background-color: rgba(78, 115, 223, 0.05);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .dataTables_length, .dataTables_filter {
            margin-bottom: 10px;
        }
        /* Add admin type indicator styling */
        .admin-type-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 15px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .admin-type-super {
            background-color: #4e73df;
            color: white;
        }
        
        .admin-type-department {
            background-color: #1cc88a;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-12">
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($warning)): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo $warning; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="d-sm-flex align-items-center justify-content-between mb-4">
                    <h1 class="h3 mb-0 text-gray-800">
                        Manage Students in Training Batch
                        <?php if (is_super_admin()): ?>
                            <span class="admin-type-badge admin-type-super">Super Admin</span>
                        <?php else: ?>
                            <span class="admin-type-badge admin-type-department">Department Admin: <?php echo get_admin_department_name($conn); ?></span>
                        <?php endif; ?>
                    </h1>
                    <a href="manage_training_batches.php" class="d-none d-sm-inline-block btn btn-sm btn-secondary shadow-sm">
                        <i class="fas fa-arrow-left fa-sm"></i> Back to Training Batches
                    </a>
                </div>
                
                <div class="batch-info">
                    <div class="row">
                        <div class="col-md-3">
                            <span class="text-muted">Batch Name:</span>
                            <span class="batch-name ml-2"><?php echo htmlspecialchars($batch['batch_name']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted">Department:</span>
                            <span class="font-weight-bold ml-2"><?php echo htmlspecialchars($batch['department_name']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted">Academic Year:</span>
                            <span class="font-weight-bold ml-2"><?php echo htmlspecialchars($batch['year_range']); ?></span>
                        </div>
                        <div class="col-md-3">
                            <span class="text-muted">Status:</span>
                            <?php if ($batch['is_active']): ?>
                                <span class="badge badge-success ml-2">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger ml-2">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($batch['description'])): ?>
                    <div class="row mt-2">
                        <div class="col-12">
                            <span class="text-muted">Description:</span>
                            <span class="ml-2"><?php echo htmlspecialchars($batch['description']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Current Batch Students -->
                <div class="card">
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-users mr-2"></i>Students in this Batch
                                    <span class="badge badge-primary ml-2"><?php echo count($batch_students); ?></span>
                                </h5>
                            </div>
                            <div class="col text-right">
                                <button onclick="handleRemoveStudents()" class="btn btn-danger btn-sm" id="removeStudentsBtn" disabled>
                                    <i class="fas fa-user-minus mr-2"></i>Remove Selected Students
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($batch_students) > 0): ?>
                            <form id="removeStudentsForm" method="post" action="">
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="currentStudentsTable" width="100%" cellspacing="0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input" id="selectAllCurrent">
                                                        <label class="custom-control-label" for="selectAllCurrent"></label>
                                                    </div>
                                                </th>
                                                <th>Roll Number</th>
                                                <th>Register Number</th>
                                                <th>Name</th>
                                                <th>Department</th>
                                                <th>Academic Batch</th>
                                                <th>Contact</th>
                                                <th>Assigned Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($batch_students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input current-student-cb" 
                                                               id="current_<?php echo $student['id']; ?>" 
                                                               name="remove_student_ids[]" 
                                                               value="<?php echo $student['id']; ?>">
                                                        <label class="custom-control-label" for="current_<?php echo $student['id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student['register_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student['name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['department_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['batch_name']); ?></td>
                                                <td>
                                                    <?php if (!empty($student['phone'])): ?>
                                                        <i class="fas fa-phone-alt mr-1"></i> <?php echo htmlspecialchars($student['phone']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($student['email'])): ?>
                                                        <i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($student['email']); ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d-m-Y', strtotime($student['assigned_date'])); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <input type="hidden" name="remove_students" value="1">
                            </form>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>No students assigned to this batch yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Available Students to Add -->
                <div class="card">
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-user-plus mr-2"></i>Add Students to Batch
                                </h5>
                            </div>
                            <div class="col text-right">
                                <button onclick="handleAddStudents()" class="btn btn-success btn-sm" id="addStudentsBtn" disabled>
                                    <i class="fas fa-plus-circle mr-2"></i>Add Selected Students
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="filter-section mb-3">
                            <form method="get" action="">
                                <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
                                <div class="row">
                                    <?php if (is_super_admin()): ?>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="filter_dept">Filter by Department</label>
                                            <select class="form-control" id="filter_dept" name="filter_dept">
                                                <option value="">All Departments</option>
                                                <?php 
                                                mysqli_data_seek($departments_result, 0);
                                                while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($filter_department == $dept['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <input type="hidden" name="filter_dept" value="<?php echo $_SESSION['department_id']; ?>">
                                    <?php endif; ?>
                                    <div class="col-md-<?php echo is_super_admin() ? '4' : '6'; ?>">
                                        <div class="form-group">
                                            <label for="filter_batch">Filter by Academic Batch</label>
                                            <select class="form-control" id="filter_batch" name="filter_batch">
                                                <option value="">All Batches</option>
                                                <?php foreach ($batch_years as $year): ?>
                                                    <option value="<?php echo $year['id']; ?>" <?php echo ($filter_batch == $year['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($year['batch_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-<?php echo is_super_admin() ? '4' : '6'; ?>">
                                        <div class="form-group">
                                            <label>&nbsp;</label>
                                            <button type="submit" class="btn btn-primary btn-block">
                                                <i class="fas fa-filter"></i> Apply Filters
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <span class="text-muted">Showing <?php echo count($available_students); ?> of <?php echo $total_available; ?> available students</span>
                            </div>
                        </div>
                        
                        <?php if (count($available_students) > 0): ?>
                            <form id="addStudentsForm" method="post" action="">
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="availableStudentsTable" width="100%" cellspacing="0">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input" id="selectAllAvailable">
                                                        <label class="custom-control-label" for="selectAllAvailable"></label>
                                                    </div>
                                                </th>
                                                <th>Roll Number</th>
                                                <th>Register Number</th>
                                                <th>Name</th>
                                                <th>Department</th>
                                                <th>Academic Batch</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($available_students as $student): ?>
                                            <tr>
                                                <td>
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input available-student-cb" 
                                                               id="available_<?php echo $student['id']; ?>" 
                                                               name="add_student_ids[]" 
                                                               value="<?php echo $student['id']; ?>">
                                                        <label class="custom-control-label" for="available_<?php echo $student['id']; ?>"></label>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student['register_number']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                    <?php if (!empty($student['other_batches'])): ?>
                                                        <span class="badge badge-warning ml-2" data-toggle="tooltip" 
                                                              title="Already in: <?php echo htmlspecialchars($student['other_batches']); ?>">
                                                            <i class="fas fa-exclamation-triangle"></i> In other batch
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['department_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student['batch_name']); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination Controls -->
                                <?php if ($total_pages > 1): ?>
                                <div class="d-flex justify-content-center mt-4">
                                    <nav aria-label="Page navigation">
                                        <ul class="pagination">
                                            <?php if ($current_page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?batch_id=<?php echo $batch_id; ?>&page=1<?php echo $filter_department ? '&filter_dept='.$filter_department : ''; ?><?php echo $filter_batch ? '&filter_batch='.$filter_batch : ''; ?>" aria-label="First">
                                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?batch_id=<?php echo $batch_id; ?>&page=<?php echo $current_page-1; ?><?php echo $filter_department ? '&filter_dept='.$filter_department : ''; ?><?php echo $filter_batch ? '&filter_batch='.$filter_batch : ''; ?>" aria-label="Previous">
                                                        <span aria-hidden="true">&laquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php
                                            // Show limited page numbers with ellipsis
                                            $start_page = max(1, $current_page - 2);
                                            $end_page = min($total_pages, $current_page + 2);
                                            
                                            if ($start_page > 1) {
                                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                            }
                                            
                                            for ($i = $start_page; $i <= $end_page; $i++) {
                                                echo '<li class="page-item '.($i == $current_page ? 'active' : '').'">
                                                    <a class="page-link" href="?batch_id='.$batch_id.'&page='.$i.
                                                    ($filter_department ? '&filter_dept='.$filter_department : '').
                                                    ($filter_batch ? '&filter_batch='.$filter_batch : '').
                                                    '">'.$i.'</a>
                                                </li>';
                                            }
                                            
                                            if ($end_page < $total_pages) {
                                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                            }
                                            ?>
                                            
                                            <?php if ($current_page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?batch_id=<?php echo $batch_id; ?>&page=<?php echo $current_page+1; ?><?php echo $filter_department ? '&filter_dept='.$filter_department : ''; ?><?php echo $filter_batch ? '&filter_batch='.$filter_batch : ''; ?>" aria-label="Next">
                                                        <span aria-hidden="true">&raquo;</span>
                                                    </a>
                                                </li>
                                                <li class="page-item">
                                                    <a class="page-link" href="?batch_id=<?php echo $batch_id; ?>&page=<?php echo $total_pages; ?><?php echo $filter_department ? '&filter_dept='.$filter_department : ''; ?><?php echo $filter_batch ? '&filter_batch='.$filter_batch : ''; ?>" aria-label="Last">
                                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                </div>
                                <?php endif; ?>
                                
                                <input type="hidden" name="add_students" value="1">
                            </form>
                        <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>No more students available to add to this batch.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Bulk Add Students -->
                <div class="card">
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-users-cog mr-2"></i>Bulk Add Students
                                </h5>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="bulkAddTabs" role="tablist">
                            <li class="nav-item">
                                <a class="nav-link active" id="section-tab" data-toggle="tab" href="#sectionTab" role="tab" aria-controls="sectionTab" aria-selected="true">Add By Section</a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" id="excel-tab" data-toggle="tab" href="#excelTab" role="tab" aria-controls="excelTab" aria-selected="false">Import from Excel</a>
                            </li>
                        </ul>
                        <div class="tab-content mt-3" id="bulkAddTabContent">
                            <!-- Add By Section Tab -->
                            <div class="tab-pane fade show active" id="sectionTab" role="tabpanel" aria-labelledby="section-tab">
                                <form method="post" action="" id="bulkSectionForm">
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label for="bulk_department_id">Department</label>
                                                <select class="form-control" id="bulk_department_id" name="bulk_department_id" required <?php echo !is_super_admin() ? 'disabled' : ''; ?>>
                                                    <option value="">Select Department</option>
                                                    <?php 
                                                    mysqli_data_seek($departments_result, 0);
                                                    while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                                        <option value="<?php echo $dept['id']; ?>" <?php echo ($dept['id'] == $batch['department_id']) ? 'selected' : ''; ?><?php echo (!is_super_admin() && $dept['id'] == $_SESSION['department_id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($dept['name']); ?>
                                                            <?php echo ($dept['id'] == $batch['department_id']) ? ' (Batch Department)' : ''; ?>
                                                        </option>
                                                    <?php endwhile; ?>
                                                </select>
                                                <?php if (!is_super_admin()): ?>
                                                <input type="hidden" name="bulk_department_id" value="<?php echo $_SESSION['department_id']; ?>">
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="bulk_batch_year_id">Academic Batch</label>
                                                <select class="form-control" id="bulk_batch_year_id" name="bulk_batch_year_id" required>
                                                    <option value="">Select Batch</option>
                                                    <?php foreach ($batch_years as $year): ?>
                                                        <option value="<?php echo $year['id']; ?>">
                                                            <?php echo htmlspecialchars($year['batch_name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="form-group">
                                                <label for="bulk_section">Section</label>
                                                <select class="form-control" id="bulk_section" name="bulk_section" required>
                                                    <option value="">Select Section</option>
                                                    <?php foreach ($sections as $section): ?>
                                                        <option value="<?php echo $section; ?>"><?php echo $section; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="form-group">
                                                <label>&nbsp;</label>
                                                <button type="button" class="btn btn-info btn-block" id="previewSectionBtn">
                                                    <i class="fas fa-search mr-1"></i> Preview
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="sectionPreviewContainer" class="mt-3" style="display: none;">
                                        <div class="alert alert-info">
                                            <span id="sectionPreviewCount">0</span> students will be added to this batch.
                                        </div>
                                        <button type="submit" name="bulk_add_section" class="btn btn-success">
                                            <i class="fas fa-user-plus mr-1"></i> Add Students
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Import from Excel Tab -->
                            <div class="tab-pane fade" id="excelTab" role="tabpanel" aria-labelledby="excel-tab">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Upload a CSV file with student IDs in the first column (excluding header row).
                                </div>
                                <form method="post" action="" enctype="multipart/form-data" id="bulkExcelForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="id_type">Student ID Type</label>
                                                <select class="form-control" id="id_type" name="id_type" required>
                                                    <option value="roll_number">Roll Number</option>
                                                    <option value="register_number">Register Number</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="student_list_file">Upload File (CSV)</label>
                                                <div class="custom-file">
                                                    <input type="file" class="custom-file-input" id="student_list_file" name="student_list_file" accept=".csv,.xlsx" required>
                                                    <label class="custom-file-label" for="student_list_file">Choose file</label>
                                                </div>
                                                <small class="form-text text-muted">
                                                    Upload file containing student IDs in the first column. 
                                                    <span id="id_type_text">Roll numbers</span> must match records in the system.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="submit" name="bulk_add_excel" class="btn btn-success">
                                        <i class="fas fa-file-upload mr-1"></i> Upload and Add Students
                                    </button>
                                </form>
                                <div class="mt-3">
                                    <h6>Supported File Formats:</h6>
                                    <ul>
                                        <li><strong>Excel (.xlsx)</strong> - Microsoft Excel spreadsheet</li>
                                        <li><strong>CSV (.csv)</strong> - Comma-separated values file</li>
                                    </ul>
                                    <h6>Sample Format:</h6>
                                    <pre class="bg-light p-2">
student_id
2022PECAI001
2022PECAI002
2022PECAI003
                                    </pre>
                                    <p class="small text-muted">
                                        <i class="fas fa-info-circle"></i> The first row is treated as a header and will be skipped.
                                        Only the first column is processed for student identification.
                                        Make sure the header is exactly <code>student_id</code> (lowercase).
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/select/1.3.1/js/dataTables.select.min.js"></script>

    <script>
        $(document).ready(function() {
            // Current Students DataTable
            var currentStudentsTable = $('#currentStudentsTable').DataTable({
                "pageLength": 10,
                "order": [[3, "asc"]],
                "language": {
                    "search": "Search students:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ students",
                    "infoEmpty": "Showing 0 to 0 of 0 students",
                    "infoFiltered": "(filtered from _MAX_ total students)"
                }
            });
            
            // Available Students DataTable - with disabled pagination (using server-side)
            var availableStudentsTable = $('#availableStudentsTable').DataTable({
                "paging": false,  // Disable DataTables pagination
                "order": [[3, "asc"]],
                "language": {
                    "search": "Search in current page:",
                    "info": "Filtered _TOTAL_ entries from current page",
                    "infoEmpty": "No matching records found",
                    "infoFiltered": "",
                    "zeroRecords": "No matching records found",
                    "emptyTable": "No data available in table"
                }
            });
            
            // Initialize tooltips
            $('[data-toggle="tooltip"]').tooltip();
            
            // Select all current students
            $('#selectAllCurrent').on('click', function() {
                var isChecked = $(this).prop('checked');
                $('.current-student-cb').prop('checked', isChecked);
                updateRemoveButtonState();
            });
            
            // Select all available students
            $('#selectAllAvailable').on('click', function() {
                var isChecked = $(this).prop('checked');
                $('.available-student-cb').prop('checked', isChecked);
                updateAddButtonState();
            });
            
            // Individual current student checkbox change
            $(document).on('change', '.current-student-cb', function() {
                updateRemoveButtonState();
                
                // Update "select all" checkbox
                var allChecked = $('.current-student-cb:checked').length === $('.current-student-cb').length;
                $('#selectAllCurrent').prop('checked', allChecked);
            });
            
            // Individual available student checkbox change
            $(document).on('change', '.available-student-cb', function() {
                updateAddButtonState();
                
                // Update "select all" checkbox
                var allChecked = $('.available-student-cb:checked').length === $('.available-student-cb').length;
                $('#selectAllAvailable').prop('checked', allChecked);
            });
            
            // Initial button state update
            updateRemoveButtonState();
            updateAddButtonState();
            
            // File input display filename
            $('input[type="file"]').on('change', function() {
                var fileName = $(this).val().split('\\').pop();
                $(this).next('.custom-file-label').html(fileName || "Choose file");
            });
            
            // Update ID type text
            $('#id_type').on('change', function() {
                var idType = $(this).val() === 'roll_number' ? 'roll numbers' : 'register numbers';
                $('#id_type_text').text(idType);
            });
            
            // Preview section students
            $('#previewSectionBtn').on('click', function() {
                var department = $('#bulk_department_id').val();
                var batchYear = $('#bulk_batch_year_id').val();
                var section = $('#bulk_section').val();
                
                if (!department || !batchYear || !section) {
                    alert('Please select department, batch year and section');
                    return;
                }
                
                // AJAX call to get count
                $.ajax({
                    url: 'ajax/get_section_students_count.php',
                    type: 'POST',
                    data: {
                        department_id: department,
                        batch_year_id: batchYear,
                        section: section,
                        training_batch_id: <?php echo $batch_id; ?>
                    },
                    dataType: 'json',
                    success: function(response) {
                        $('#sectionPreviewCount').text(response.count);
                        if (response.count > 0) {
                            $('#sectionPreviewContainer').slideDown();
                        } else {
                            alert('No students found with the selected criteria or they are already in this batch.');
                        }
                    },
                    error: function() {
                        alert('Error checking student count. Please try again.');
                    }
                });
            });
        });
        
        // Update Remove Students button state
        function updateRemoveButtonState() {
            var selectedCount = $('.current-student-cb:checked').length;
            $('#removeStudentsBtn').prop('disabled', selectedCount === 0);
            $('#removeStudentsBtn').text(selectedCount > 0 ? 
                `Remove Selected Students (${selectedCount})` : 
                'Remove Selected Students');
        }
        
        // Update Add Students button state
        function updateAddButtonState() {
            var selectedCount = $('.available-student-cb:checked').length;
            $('#addStudentsBtn').prop('disabled', selectedCount === 0);
            $('#addStudentsBtn').text(selectedCount > 0 ? 
                `Add Selected Students (${selectedCount})` : 
                'Add Selected Students');
        }
        
        // Handle Remove Students button click
        function handleRemoveStudents() {
            if ($('.current-student-cb:checked').length > 0) {
                if (confirm('Are you sure you want to remove the selected students from this batch?')) {
                    $('#removeStudentsForm').submit();
                }
            }
        }
        
        // Handle Add Students button click
        function handleAddStudents() {
            if ($('.available-student-cb:checked').length > 0) {
                $('#addStudentsForm').submit();
            }
        }
    </script>

    <?php include '../footer.php'; ?>
</body>
</html> 