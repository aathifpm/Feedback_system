<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

// Check if faculty is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'faculty' && $_SESSION['role'] !== 'admin')) {
    header('Location: faculty_login.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
// Check if name is available in session, otherwise use a default
$faculty_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Faculty User';
$department_id = $_SESSION['department_id'];
$current_date = date('Y-m-d');
$error = '';
$success = '';
$classes = [];
$students = [];
$training_batches = [];
$selected_class = null;
$selected_batch = null;
$attendance_list = [];
$schedule_type = '';

// Add sort parameters
$sort_column = isset($_POST['sort_column']) ? $_POST['sort_column'] : 'roll_number';
$sort_direction = isset($_POST['sort_direction']) ? $_POST['sort_direction'] : 'ASC';

// Validate sort parameters
$allowed_columns = ['roll_number', 'name', 'department_name', 'status'];
if (!in_array($sort_column, $allowed_columns)) {
    $sort_column = 'roll_number';
}
$sort_direction = ($sort_direction === 'DESC') ? 'DESC' : 'ASC';

// Get current academic year
$query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$result = mysqli_query($conn, $query);
$academic_year = mysqli_fetch_assoc($result);
$academic_year_id = $academic_year['id'];

// Get academic classes assigned to faculty for today
$query = "SELECT 
              'academic' AS schedule_type,
              acs.id,
              acs.class_date, 
              acs.start_time, 
              acs.end_time, 
              acs.topic, 
              v.name AS venue_name, 
              v.room_number,
              s.name AS subject_name, 
              s.code AS subject_code,
              sa.year, 
              sa.semester, 
              sa.section,
              '' AS trainer_name
          FROM 
              academic_class_schedule acs
          JOIN 
              subject_assignments sa ON acs.assignment_id = sa.id
          JOIN 
              subjects s ON sa.subject_id = s.id
          JOIN 
              venues v ON acs.venue_id = v.id
          WHERE 
              sa.faculty_id = ? 
              AND acs.class_date = ? 
              AND acs.is_cancelled = FALSE
          ORDER BY 
              acs.start_time";
          
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "is", $faculty_id, $current_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $classes[] = $row;
}

// Get training sessions for faculty's department
$query = "SELECT 
              'training' AS schedule_type,
              tss.id,
              tss.session_date AS class_date, 
              tss.start_time, 
              tss.end_time, 
              tss.topic,
              v.name AS venue_name, 
              v.room_number,
              'Training' AS subject_name, 
              '' AS subject_code,
              0 AS year, 
              0 AS semester, 
              tb.batch_name AS section,
              tss.trainer_name AS trainer_name
          FROM 
              training_session_schedule tss
          JOIN 
              training_batches tb ON tss.training_batch_id = tb.id
          JOIN 
              venues v ON tss.venue_id = v.id
          WHERE 
              tb.department_id = ? 
              AND tss.session_date = ? 
              AND tss.is_cancelled = FALSE
          ORDER BY 
              tss.start_time";
          
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "is", $department_id, $current_date);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $classes[] = $row;
}

// Sort all classes by start time
usort($classes, function($a, $b) {
    return strcmp($a['start_time'], $b['start_time']);
});

// Process form submission for class selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['select_class'])) {
    $schedule_id = $_POST['schedule_id'];
    $schedule_type = $_POST['schedule_type'];
    
    // Get sort parameters from form if available
    if (isset($_POST['sort_column'])) {
        $sort_column = $_POST['sort_column'];
        if (!in_array($sort_column, $allowed_columns)) {
            $sort_column = 'roll_number';
        }
    }
    
    if (isset($_POST['sort_direction'])) {
        $sort_direction = $_POST['sort_direction'];
        $sort_direction = ($sort_direction === 'DESC') ? 'DESC' : 'ASC';
    }
    
    // Set pagination variables
    $students_per_page = 20; // Number of students per page
    $current_page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    if ($current_page < 1) $current_page = 1;
    $offset = ($current_page - 1) * $students_per_page;
    
    if ($schedule_type == 'academic') {
        // Get academic class details
        $query = "SELECT 
                    'academic' AS schedule_type,
                    acs.*, 
                    v.name AS venue_name, 
                    v.room_number, 
                    s.name AS subject_name, 
                    s.code AS subject_code,
                    sa.year, 
                    sa.semester, 
                    sa.section, 
                    sa.faculty_id AS assigned_faculty
                  FROM 
                    academic_class_schedule acs
                  JOIN 
                    subject_assignments sa ON acs.assignment_id = sa.id
                  JOIN 
                    subjects s ON sa.subject_id = s.id
                  JOIN 
                    venues v ON acs.venue_id = v.id
                  WHERE 
                    acs.id = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $schedule_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $selected_class = mysqli_fetch_assoc($result);
        
        // Check if faculty is assigned to this class
        if ($selected_class['assigned_faculty'] == $faculty_id || $_SESSION['role'] == 'admin') {
            // First, get total count of students for pagination
            $count_query = "SELECT 
                              COUNT(*) as total_students
                            FROM 
                              students s
                            JOIN 
                              departments d ON s.department_id = d.id
                            JOIN 
                              batch_years by ON s.batch_id = by.id
                            WHERE 
                              by.current_year_of_study = ? 
                              AND s.section = ? 
                              AND s.department_id = ?";
            
            $stmt = mysqli_prepare($conn, $count_query);
            mysqli_stmt_bind_param($stmt, "isi", $selected_class['year'], $selected_class['section'], $department_id);
            mysqli_stmt_execute($stmt);
            $count_result = mysqli_stmt_get_result($stmt);
            $count_row = mysqli_fetch_assoc($count_result);
            $total_students = $count_row['total_students'];
            $total_pages = ceil($total_students / $students_per_page);
            
            // If current page is greater than total pages, reset to first page
            if ($current_page > $total_pages && $total_pages > 0) {
                $current_page = 1;
                $offset = 0;
            }
            
            // Get students for this academic class with pagination and sorting
            $query = "SELECT 
                        s.id, 
                        s.roll_number, 
                        s.register_number, 
                        s.name, 
                        d.name AS department_name,
                        COALESCE(aar.status, 'absent') AS status, 
                        aar.id AS attendance_record_id
                      FROM 
                        students s
                      JOIN 
                        departments d ON s.department_id = d.id
                      JOIN 
                        batch_years by ON s.batch_id = by.id
                      LEFT JOIN 
                        academic_attendance_records aar ON s.id = aar.student_id AND aar.schedule_id = ?
                      WHERE 
                        by.current_year_of_study = ? 
                        AND s.section = ? 
                        AND s.department_id = ?
                      ORDER BY 
                        $sort_column $sort_direction
                      LIMIT ?, ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "isiiii", $schedule_id, $selected_class['year'], $selected_class['section'], $department_id, $offset, $students_per_page);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            while ($row = mysqli_fetch_assoc($result)) {
                $students[] = $row;
            }
        } else {
            $error = "You are not authorized to mark attendance for this class.";
        }
    } else {
        // Get training session details
        $query = "SELECT 
                    'training' AS schedule_type,
                    tss.*,
                    tb.batch_name,
                    tb.id AS batch_id,
                    v.name AS venue_name,
                    v.room_number
                  FROM 
                    training_session_schedule tss
                  JOIN 
                    training_batches tb ON tss.training_batch_id = tb.id
                  JOIN 
                    venues v ON tss.venue_id = v.id
                  WHERE 
                    tss.id = ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $schedule_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $selected_class = mysqli_fetch_assoc($result);
        
        // First, get total count of students for pagination
        $count_query = "SELECT 
                          COUNT(*) as total_students
                        FROM 
                          students s
                        JOIN 
                          student_training_batch stb ON s.id = stb.student_id
                        WHERE 
                          stb.training_batch_id = ? 
                          AND stb.is_active = TRUE";
        
        $stmt = mysqli_prepare($conn, $count_query);
        mysqli_stmt_bind_param($stmt, "i", $selected_class['training_batch_id']);
        mysqli_stmt_execute($stmt);
        $count_result = mysqli_stmt_get_result($stmt);
        $count_row = mysqli_fetch_assoc($count_result);
        $total_students = $count_row['total_students'];
        $total_pages = ceil($total_students / $students_per_page);
        
        // If current page is greater than total pages, reset to first page
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = 1;
            $offset = 0;
        }
        
        // Get students for this training batch with pagination and sorting
        $query = "SELECT 
                    s.id, 
                    s.roll_number, 
                    s.register_number, 
                    s.name, 
                    d.name AS department_name,
                    COALESCE(tar.status, 'absent') AS status, 
                    tar.id AS attendance_record_id
                  FROM 
                    students s
                  JOIN 
                    departments d ON s.department_id = d.id
                  JOIN 
                    student_training_batch stb ON s.id = stb.student_id
                  LEFT JOIN 
                    training_attendance_records tar ON s.id = tar.student_id AND tar.session_id = ?
                  WHERE 
                    stb.training_batch_id = ? 
                    AND stb.is_active = TRUE
                  ORDER BY 
                    $sort_column $sort_direction
                  LIMIT ?, ?";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiii", $schedule_id, $selected_class['training_batch_id'], $offset, $students_per_page);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        while ($row = mysqli_fetch_assoc($result)) {
            $students[] = $row;
        }
    }
}

// Process scanning/marking attendance for individual students
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_attendance'])) {
    $schedule_id = $_POST['schedule_id'];
    $schedule_type = $_POST['schedule_type'];
    $roll_number = $_POST['roll_number'];
    $status = $_POST['status'];
    
    // Debugging output
    error_log("Checking roll number: " . $roll_number);
    
    // Find student by roll number
    $query = "SELECT id FROM students WHERE roll_number = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $roll_number);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) === 0) {
        $error = "ERROR: Student with Roll Number $roll_number not found in database.";
        error_log($error);
    } else {
        $student = mysqli_fetch_assoc($result);
        $student_id = $student['id'];
        $student_belongs_to_class = false;
        
        error_log("Found student ID: " . $student_id);
        
        // Validate student belongs to class based on schedule type
        if ($schedule_type == 'academic') {
            // Get class details
            $query = "SELECT sa.year, sa.section, sa.department_id 
                      FROM academic_class_schedule acs 
                      JOIN subject_assignments sa ON acs.assignment_id = sa.id 
                      WHERE acs.id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) === 0) {
                $error = "ERROR: Could not find class details for validation.";
                error_log($error);
            } else {
                $class_details = mysqli_fetch_assoc($result);
                error_log("Class details - Year: " . $class_details['year'] . ", Section: " . $class_details['section']);
                
                // Check if student belongs to this class
                $query = "SELECT s.id 
                          FROM students s 
                          JOIN batch_years by ON s.batch_id = by.id 
                          WHERE s.id = ? 
                          AND by.current_year_of_study = ? 
                          AND s.section = ? 
                          AND s.department_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "iisi", $student_id, $class_details['year'], 
                                      $class_details['section'], $class_details['department_id']);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) > 0) {
                    $student_belongs_to_class = true;
                    error_log("Student belongs to academic class: YES");
                } else {
                    $error = "ERROR: Student with Roll Number $roll_number does not belong to this class (Year: " . 
                            $class_details['year'] . ", Section: " . $class_details['section'] . ").";
                    error_log($error);
                }
            }
        } else {
            // Get training batch details
            $query = "SELECT training_batch_id FROM training_session_schedule WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) === 0) {
                $error = "ERROR: Could not find training batch details for validation.";
                error_log($error);
            } else {
                $batch_details = mysqli_fetch_assoc($result);
                error_log("Training batch ID: " . $batch_details['training_batch_id']);
                
                // Check if student belongs to this batch
                $query = "SELECT id FROM student_training_batch 
                          WHERE student_id = ? 
                          AND training_batch_id = ? 
                          AND is_active = TRUE";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ii", $student_id, $batch_details['training_batch_id']);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (mysqli_num_rows($result) > 0) {
                    $student_belongs_to_class = true;
                    error_log("Student belongs to training batch: YES");
                } else {
                    $error = "ERROR: Student with Roll Number $roll_number is not enrolled in this training batch.";
                    error_log($error);
                }
            }
        }
        
        // Mark attendance only if validation passed
        if ($student_belongs_to_class) {
            error_log("Proceeding with attendance marking for student ID: " . $student_id);
            
            if ($schedule_type == 'academic') {
                // Check if attendance already recorded
            $query = "SELECT id FROM academic_attendance_records WHERE student_id = ? AND schedule_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ii", $student_id, $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($record = mysqli_fetch_assoc($result)) {
                    // Update existing record
                $query = "UPDATE academic_attendance_records 
                          SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $record['id']);
                    error_log("Updating existing academic attendance record ID: " . $record['id']);
            } else {
                    // Insert new record
                $query = "INSERT INTO academic_attendance_records (student_id, schedule_id, status, marked_by)
                          VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                    error_log("Creating new academic attendance record");
            }
        } else {
                // Check if attendance already recorded
            $query = "SELECT id FROM training_attendance_records WHERE student_id = ? AND session_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ii", $student_id, $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($record = mysqli_fetch_assoc($result)) {
                    // Update existing record
                $query = "UPDATE training_attendance_records 
                          SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                          WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $record['id']);
                    error_log("Updating existing training attendance record ID: " . $record['id']);
            } else {
                    // Insert new record
                $query = "INSERT INTO training_attendance_records (student_id, session_id, status, marked_by)
                          VALUES (?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                    error_log("Creating new training attendance record");
            }
        }
        
        if (mysqli_stmt_execute($stmt)) {
            $success = "Attendance marked for Roll Number: $roll_number";
                error_log("SUCCESS: " . $success);
                
            // Reload student list
            $_POST['select_class'] = true;
            $_POST['schedule_id'] = $schedule_id;
            $_POST['schedule_type'] = $schedule_type;
        } else {
            $error = "Failed to mark attendance: " . mysqli_error($conn);
                error_log("ERROR: " . $error);
        }
        }
    }
}

// Process bulk attendance marking
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bulk_mark'])) {
    $schedule_id = $_POST['schedule_id'];
    $schedule_type = $_POST['schedule_type'];
    $default_status = $_POST['default_status'];
    
    if ($schedule_type == 'academic') {
        // Use stored procedure for bulk academic attendance marking
        $query = "CALL MarkBulkAcademicAttendance(?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iis", $schedule_id, $faculty_id, $default_status);
    } else {
        // Use stored procedure for bulk training attendance marking
        $query = "CALL MarkBulkTrainingAttendance(?, ?, ?)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iis", $schedule_id, $faculty_id, $default_status);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $success = "Bulk attendance marking completed successfully.";
        // Reload student list
        $_POST['select_class'] = true;
        $_POST['schedule_id'] = $schedule_id;
        $_POST['schedule_type'] = $schedule_type;
    } else {
        $error = "Failed to mark bulk attendance: " . mysqli_error($conn);
    }
}

// Process mark unmarked as absent
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mark_unmarked'])) {
    $schedule_id = $_POST['schedule_id'];
    $schedule_type = $_POST['schedule_type'];
    
    if ($schedule_type == 'academic') {
        // Mark all students without attendance records as absent for this class
        $query = "INSERT INTO academic_attendance_records (student_id, schedule_id, status, marked_by)
                 SELECT 
                     s.id,
                     ?,
                     'absent',
                     ?
                 FROM 
                     academic_class_schedule acs
                 JOIN 
                     subject_assignments sa ON acs.assignment_id = sa.id
                 JOIN 
                     students s ON s.department_id = sa.department_id AND s.section = sa.section
                 JOIN
                     batch_years by ON s.batch_id = by.id
                 WHERE 
                     acs.id = ?
                     AND by.current_year_of_study = sa.year
                     AND NOT EXISTS (
                         SELECT 1 
                         FROM academic_attendance_records aar 
                         WHERE aar.student_id = s.id AND aar.schedule_id = ?
                     )";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiii", $schedule_id, $faculty_id, $schedule_id, $schedule_id);
    } else {
        // Mark all students without attendance records as absent for this training session
        $query = "INSERT INTO training_attendance_records (student_id, session_id, status, marked_by)
                 SELECT 
                     s.id,
                     ?,
                     'absent',
                     ?
                 FROM 
                     training_session_schedule tss
                 JOIN 
                     training_batches tb ON tss.training_batch_id = tb.id
                 JOIN 
                     student_training_batch stb ON tb.id = stb.training_batch_id
                 JOIN 
                     students s ON stb.student_id = s.id
                 WHERE 
                     tss.id = ?
                     AND stb.is_active = TRUE
                     AND NOT EXISTS (
                         SELECT 1 
                         FROM training_attendance_records tar 
                         WHERE tar.student_id = s.id AND tar.session_id = ?
                     )";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiii", $schedule_id, $faculty_id, $schedule_id, $schedule_id);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        $affected_rows = mysqli_stmt_affected_rows($stmt);
        if ($affected_rows > 0) {
            $success = "Marked " . $affected_rows . " unmarked students as absent.";
        } else {
            $success = "All students already have attendance records.";
        }
        
        // Reload student list
        $_POST['select_class'] = true;
        $_POST['schedule_id'] = $schedule_id;
        $_POST['schedule_type'] = $schedule_type;
    } else {
        $error = "Failed to mark unmarked students: " . mysqli_error($conn);
    }
}

// Process individual attendance updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_attendance'])) {
    $schedule_id = $_POST['schedule_id'];
    $schedule_type = $_POST['schedule_type'];
    $student_statuses = $_POST['student_status'];
    $update_all_pages = isset($_POST['update_all_pages']) && $_POST['update_all_pages'] == 1;
    
    if ($update_all_pages) {
        // Get all students for this class/batch (without pagination)
        if ($schedule_type == 'academic') {
            // Get class details first to get year, section, etc.
            $query = "SELECT 
                        year, 
                        section, 
                        sa.faculty_id
                      FROM 
                        academic_class_schedule acs
                      JOIN 
                        subject_assignments sa ON acs.assignment_id = sa.id
                      WHERE 
                        acs.id = ?";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $class_details = mysqli_fetch_assoc($result);
            
            // Check if faculty is assigned to this class
            if ($class_details['faculty_id'] == $faculty_id || $_SESSION['role'] == 'admin') {
                // Get all students for this academic class
                $query = "SELECT 
                            s.id,
                            COALESCE(aar.status, 'absent') AS current_status,
                            aar.id AS attendance_record_id
                          FROM 
                            students s
                          JOIN 
                            batch_years by ON s.batch_id = by.id
                          LEFT JOIN
                            academic_attendance_records aar ON s.id = aar.student_id AND aar.schedule_id = ?
                          WHERE 
                            by.current_year_of_study = ? 
                            AND s.section = ? 
                            AND s.department_id = ?";
                
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "iisi", $schedule_id, $class_details['year'], $class_details['section'], $department_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                while ($student = mysqli_fetch_assoc($result)) {
                    $student_id = $student['id'];
                    
                    // Only update status if specifically provided in the form
                    // Otherwise keep the existing status
                    if (isset($student_statuses[$student_id])) {
                        $status = $student_statuses[$student_id];
                        
                        if ($student['attendance_record_id']) {
                            // Update existing record
                            $query = "UPDATE academic_attendance_records 
                                    SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                                    WHERE id = ?";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $student['attendance_record_id']);
                        } else {
                            // Insert new record
                            $query = "INSERT INTO academic_attendance_records (student_id, schedule_id, status, marked_by)
                                    VALUES (?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                        }
                        
                        mysqli_stmt_execute($stmt);
                    }
                }
            }
        } else {
            // Get training batch ID
            $query = "SELECT training_batch_id FROM training_session_schedule WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $schedule_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $batch_details = mysqli_fetch_assoc($result);
            $batch_id = $batch_details['training_batch_id'];
            
            // Get all students in this training batch
            $query = "SELECT 
                        s.id,
                        COALESCE(tar.status, 'absent') AS current_status,
                        tar.id AS attendance_record_id
                      FROM 
                        students s
                      JOIN 
                        student_training_batch stb ON s.id = stb.student_id
                      LEFT JOIN
                        training_attendance_records tar ON s.id = tar.student_id AND tar.session_id = ?
                      WHERE 
                        stb.training_batch_id = ? 
                        AND stb.is_active = TRUE";
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ii", $schedule_id, $batch_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            while ($student = mysqli_fetch_assoc($result)) {
                $student_id = $student['id'];
                
                // Only update status if specifically provided in the form
                // Otherwise keep the existing status
                if (isset($student_statuses[$student_id])) {
                    $status = $student_statuses[$student_id];
                    
                    if ($student['attendance_record_id']) {
                        // Update existing record
                        $query = "UPDATE training_attendance_records 
                                SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                                WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $student['attendance_record_id']);
                    } else {
                        // Insert new record
                        $query = "INSERT INTO training_attendance_records (student_id, session_id, status, marked_by)
                                VALUES (?, ?, ?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                    }
                    
                    mysqli_stmt_execute($stmt);
                }
            }
        }
        
        $success = "Attendance updated successfully for students with changed status.";
    } else {
        // Update only students on current page (original behavior)
        foreach ($student_statuses as $student_id => $status) {
            if ($schedule_type == 'academic') {
                // Check if academic attendance already recorded
                $query = "SELECT id FROM academic_attendance_records WHERE student_id = ? AND schedule_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ii", $student_id, $schedule_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($record = mysqli_fetch_assoc($result)) {
                    // Update existing academic record
                    $query = "UPDATE academic_attendance_records 
                              SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $record['id']);
                } else {
                    // Insert new academic record
                    $query = "INSERT INTO academic_attendance_records (student_id, schedule_id, status, marked_by)
                              VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                }
            } else {
                // Check if training attendance already recorded
                $query = "SELECT id FROM training_attendance_records WHERE student_id = ? AND session_id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ii", $student_id, $schedule_id);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($record = mysqli_fetch_assoc($result)) {
                    // Update existing training record
                    $query = "UPDATE training_attendance_records 
                              SET status = ?, marked_by = ?, updated_at = CURRENT_TIMESTAMP
                              WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sii", $status, $faculty_id, $record['id']);
                } else {
                    // Insert new training record
                    $query = "INSERT INTO training_attendance_records (student_id, session_id, status, marked_by)
                              VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iisi", $student_id, $schedule_id, $status, $faculty_id);
                }
            }
            
            mysqli_stmt_execute($stmt);
        }
        
        $success = "Attendance updated successfully for students on this page.";
    }
    
    // Reload student list
    $_POST['select_class'] = true;
    $_POST['schedule_id'] = $schedule_id;
    $_POST['schedule_type'] = $schedule_type;
}
?>

<?php
// Set page title before including header
$pageTitle = "Attendance Marking";
include 'header.php';
// Add html5-qrcode library which is specific to this page
?>
<script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --text-color: #2c3e50;
            --text-light: #7f8c8d;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0.5rem;
            padding-top: 70px; /* Add space for fixed header */
            font-size: 16px;
        }

        .custom-header {
            width: 100%;
            padding: 1rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 1rem;
            border-radius: 15px;
            max-width: 1200px;
        }

        .custom-header h1 {
            font-size: clamp(1.2rem, 4vw, 1.8rem);
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .custom-header p {
            color: var(--text-light);
            line-height: 1.4;
            font-size: clamp(0.85rem, 3vw, 1rem);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0.5rem;
        }

        .card {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: clamp(1rem, 3vw, 2rem);
            margin-bottom: 1.5rem;
            transition: var(--transition);
            width: 100%;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                      -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-title {
            font-size: clamp(1.1rem, 4vw, 1.5rem);
            color: var(--text-color);
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: clamp(0.85rem, 3vw, 0.95rem);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1.1rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: clamp(0.85rem, 3vw, 1rem);
            color: var(--text-color);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .btn {
            padding: 0.7rem 1.25rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: clamp(0.85rem, 3vw, 1rem);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px; /* Minimum touch target size */
            margin: 0.25rem;
        }

        .btn:hover {
            transform: translateY(-3px);
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-success:hover {
            background: #27ae60;
        }

        .btn-warning {
            background: var(--warning-color);
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-danger {
            background: var(--error-color);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 1.25rem;
            border-radius: 10px;
            box-shadow: var(--shadow);
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--bg-color);
            overflow: hidden;
            min-width: 600px; /* Ensures table doesn't get too cramped */
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            font-size: clamp(0.75rem, 2.5vw, 0.9rem);
        }

        .table th {
            background: rgba(52, 152, 219, 0.1);
            font-weight: 600;
            color: var(--text-color);
        }

        .table tbody tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            font-weight: 500;
            display: inline-block;
        }

        .badge-success {
            background: rgba(46, 204, 113, 0.2);
            color: #27ae60;
        }

        .badge-danger {
            background: rgba(231, 76, 60, 0.2);
            color: #c0392b;
        }

        .badge-warning {
            background: rgba(243, 156, 18, 0.2);
            color: #e67e22;
        }

        .badge-info {
            background: rgba(52, 152, 219, 0.2);
            color: #2980b9;
        }

        .error-message {
            background: rgba(231, 76, 60, 0.1);
            color: var(--error-color);
            padding: 0.75rem;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(0.8rem, 3vw, 0.9rem);
        }

        .success-message {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-color);
            padding: 0.75rem;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: clamp(0.8rem, 3vw, 0.9rem);
        }

        .scanner-container {
            text-align: center;
            padding: clamp(1rem, 4vw, 2rem);
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: var(--inner-shadow);
            width: 100%;
        }

        .scanner-container h3 {
            font-size: clamp(1rem, 3.5vw, 1.3rem);
            margin-bottom: 0.5rem;
        }

        .scanner-container p {
            font-size: clamp(0.8rem, 3vw, 0.9rem);
            margin-bottom: 1rem;
        }

        #qr-reader {
            width: 100% !important;
            max-width: 300px;
            margin: 0 auto 20px;
            aspect-ratio: 1/1;
            display: none; /* Hide by default */
        }

        #qr-reader.active {
            display: block; /* Show when active */
        }

        #qr-reader img {
            width: 100%;
            height: auto;
        }

        .scanner-input {
            background: var(--bg-color);
            width: 100%;
            max-width: 500px;
            margin: 1rem auto;
            padding: 0.8rem;
            border: none;
            border-radius: 50px;
            box-shadow: var(--inner-shadow);
            font-size: clamp(1rem, 3.5vw, 1.2rem);
            text-align: center;
            color: var(--text-color);
        }

        .scanner-input:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .status-select {
            padding: 0.4rem;
            border: none;
            border-radius: 20px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: clamp(0.8rem, 3vw, 0.9rem);
            width: clamp(100px, 25vw, 120px);
            height: 38px; /* Consistent height for better touch */
        }

        .status-select:focus {
            outline: none;
            box-shadow: var(--shadow);
        }
        
        .present {
            background: rgba(46, 204, 113, 0.2);
        }
        
        .absent {
            background: rgba(231, 76, 60, 0.2);
        }
        
        .late {
            background: rgba(243, 156, 18, 0.2);
        }
        
        .excused {
            background: rgba(52, 152, 219, 0.2);
        }

        .status-updated {
            animation: pulse-highlight 2s;
        }
        
        @keyframes pulse-highlight {
            0% { background-color: transparent; }
            30% { background-color: rgba(46, 204, 113, 0.4); }
            100% { background-color: transparent; }
        }

        .class-time {
            font-weight: 600;
            color: var(--text-color);
        }

        .class-info {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .info-item {
            background: rgba(52, 152, 219, 0.1);
            padding: 0.6rem 1rem;
            border-radius: 15px;
            font-size: clamp(0.75rem, 2.5vw, 0.9rem);
            color: var(--text-color);
            flex: 1 1 calc(50% - 0.75rem);
            min-width: 150px;
        }

        .info-value {
            font-weight: 600;
            color: var(--primary-dark);
        }

        .floating-back {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary-color);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
            text-decoration: none;
            transition: all 0.3s;
            z-index: 1000;
        }

        .floating-back:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(52, 152, 219, 0.5);
        }

        .nav-user {
            background: rgba(52, 152, 219, 0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-color);
            font-size: clamp(0.8rem, 3vw, 0.9rem);
            margin-bottom: 0.5rem;
        }

        .nav-user i {
            color: var(--primary-color);
        }

        /* Improved Media Queries */
        @media (max-width: 1024px) {
            .container, .header {
                padding: 0.75rem;
            }
            
            .card {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 1.25rem;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .scanner-container {
                padding: 1.25rem;
            }
            
            .btn {
                width: auto;
                padding: 0.6rem 1rem;
            }
            
            .info-item {
                flex: 1 1 100%;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 0.25rem;
            }
            
            .header {
                padding: 0.75rem;
                margin-bottom: 0.75rem;
            }
            
            .card {
                padding: 1rem;
                margin-bottom: 1rem;
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .btn {
                margin: 0.15rem 0;
                width: 100%;
            }
            
            .scanner-container {
                padding: 1rem;
            }
            
            .form-group {
                margin-bottom: 1rem;
            }
            
            .scanner-input {
                padding: 0.6rem;
            }
            
            div[style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;"] {
                gap: 0.5rem;
            }
            
            div[style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;"] form {
                width: 100%;
            }
            
            div[style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;"] button {
                width: 100%;
            }
            
            .table th, .table td {
                padding: 0.6rem;
            }
        }
        
        /* Handle extra small devices */
        @media (max-width: 320px) {
            .card-title {
                font-size: 1rem;
            }
            
            .info-item {
                padding: 0.5rem 0.75rem;
            }
        }
        
        /* Pagination styles */
        .pagination-container {
            margin: 1.5rem 0;
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.25rem;
            margin-top: 1rem;
            width: 100%;
        }
        
        .pagination-form {
            display: flex;
            flex-direction: row;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            width: 100%;
        }
        
        .pagination-btn {
            min-width: 40px;
            height: 40px;
            border-radius: 8px;
            border: none;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin: 0.25rem;
        }
        
        .pagination-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }
        
        .pagination-btn.active {
            background: var(--primary-color);
            color: white;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .pagination-btn.disabled:hover {
            transform: none;
            box-shadow: var(--shadow);
        }
        
        .pagination-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.5rem;
            color: var(--text-color);
            height: 40px;
        }
        
        .pagination-info {
            color: var(--text-light);
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 0.5rem;
        }
        
        @media (max-width: 480px) {
            .pagination-btn {
                min-width: 36px;
                height: 36px;
                font-size: 0.85rem;
                margin: 0.15rem;
            }
            
            .pagination-ellipsis {
                padding: 0 0.25rem;
                height: 36px;
            }
        }
        
        /* Add sorting styles */
        .sortable {
            cursor: pointer;
            position: relative;
            padding-right: 18px !important;
        }
        
        .sortable:hover {
            background-color: rgba(52, 152, 219, 0.2);
        }
        
        .sortable::after {
            content: "↕";
            position: absolute;
            right: 5px;
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .sortable.asc::after {
            content: "↓";
            color: var(--primary-color);
        }
        
        .sortable.desc::after {
            content: "↑";
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="custom-header">
        <h1>Attendance Marking System</h1>
        <p>Welcome, <?php echo htmlspecialchars($faculty_name); ?> - <?php echo date('d M, Y'); ?></p>
    </div>

    <div class="container">
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Select Class / Training Session</h2>
            </div>
            <form method="post" action="">
                <div class="form-group">
                    <label for="schedule_id">Available Classes / Sessions Today:</label>
                    <select name="schedule_id" id="schedule_id" class="form-control" required>
                        <option value="">-- Select Class / Session --</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" data-type="<?php echo $class['schedule_type']; ?>">
                                <?php 
                                    $time_slot = date('h:i A', strtotime($class['start_time'])) . ' - ' . 
                                                date('h:i A', strtotime($class['end_time']));
                                    
                                    if ($class['schedule_type'] == 'training') {
                                        echo "Training: " . htmlspecialchars($class['topic']) . 
                                             " (Batch: " . htmlspecialchars($class['section']) . ")";
                                        
                                        if (!empty($class['trainer_name'])) {
                                            echo " - Trainer: " . htmlspecialchars($class['trainer_name']);
                                        }
                                        
                                        echo " - " . $time_slot . " - " . htmlspecialchars($class['venue_name']);
                                    } else {
                                        echo htmlspecialchars($class['subject_name']) . " (" . htmlspecialchars($class['subject_code']) . ") - " .
                                             "Year: " . htmlspecialchars($class['year']) . ", Sem: " . htmlspecialchars($class['semester']) . 
                                             ", Section: " . htmlspecialchars($class['section']) . " - " . 
                                             $time_slot . " - " . htmlspecialchars($class['venue_name']);
                                    }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="schedule_type" id="schedule_type" value="">
                </div>
                <button type="submit" name="select_class" class="btn">
                    <i class="fas fa-search"></i> Load Class
                </button>
            </form>
        </div>

        <?php if ($selected_class): ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <?php if ($selected_class['schedule_type'] == 'training'): ?>
                            Training: <?php echo htmlspecialchars($selected_class['topic']); ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($selected_class['subject_name']); ?>
                        <?php endif; ?>
                    </h2>
                </div>
                
                <div class="class-info">
                    <?php if ($selected_class['schedule_type'] == 'academic'): ?>
                        <div class="info-item">
                            <span>Subject Code:</span>
                            <span class="info-value"><?php echo htmlspecialchars($selected_class['subject_code']); ?></span>
                        </div>
                        <div class="info-item">
                            <span>Year/Semester:</span>
                            <span class="info-value">
                                <?php echo htmlspecialchars($selected_class['year']); ?> Year, 
                                Semester <?php echo htmlspecialchars($selected_class['semester']); ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span>Section:</span>
                            <span class="info-value"><?php echo htmlspecialchars($selected_class['section']); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span>Training Batch:</span>
                            <span class="info-value"><?php echo htmlspecialchars($selected_class['batch_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span>Topic:</span>
                            <span class="info-value"><?php echo htmlspecialchars($selected_class['topic']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span>Time:</span>
                        <span class="info-value">
                            <?php 
                                echo date('h:i A', strtotime($selected_class['start_time'])) . ' - ' . 
                                     date('h:i A', strtotime($selected_class['end_time']));
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span>Venue:</span>
                        <span class="info-value">
                            <?php 
                                echo htmlspecialchars($selected_class['venue_name']) . 
                                    (!empty($selected_class['room_number']) ? ' (' . htmlspecialchars($selected_class['room_number']) . ')' : ''); 
                            ?>
                        </span>
                    </div>
                </div>
                
                <!-- Barcode Scanner Section -->
                <div class="scanner-container">
                    <h3>Scan Student ID Barcode</h3>
                    <p>Scan or enter student roll number to mark attendance</p>
                    
                    <!-- Camera scanner -->
                    <div id="qr-reader"></div>
                    <button type="button" id="startScanner" class="btn btn-info">
                        <i class="fas fa-camera"></i> Start Camera Scanner
                    </button>
                    <button type="button" id="stopScanner" class="btn btn-secondary" style="display: none;">
                        <i class="fas fa-stop"></i> Stop Scanner
                    </button>
                    
                    
                    <form method="post" action="" id="scannerForm">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                        <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                        <input type="hidden" name="mark_attendance" value="1">
                        <input type="text" name="roll_number" id="scanner" class="scanner-input" 
                               placeholder="Scan barcode or enter roll number" autocomplete="off">
                        <div style="margin: 1rem 0;">
                            <label for="status">Status:</label>
                            <select name="status" id="status" class="status-select">
                                <option value="present">Present</option>
                                <option value="late">Late</option>
                                <option value="absent">Absent</option>
                                <option value="excused">Excused</option>
                            </select>
                        </div>
,                         <button type="submit" name="mark_attendance" class="btn">
                            <i class="fas fa-check"></i> Mark Attendance
                        </button>
                    </form>
                </div>
                
                <!-- Bulk Attendance Options -->
                <div style="margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                    <form method="post" action="" style="display: inline-block;">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                        <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                        <input type="hidden" name="default_status" value="present">
                        <button type="submit" name="bulk_mark" class="btn btn-success">
                            <i class="fas fa-users"></i> Mark All Present
                        </button>
                    </form>
                    <form method="post" action="" style="display: inline-block;">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                        <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                        <input type="hidden" name="default_status" value="absent">
                        <button type="submit" name="bulk_mark" class="btn btn-danger">
                            <i class="fas fa-user-slash"></i> Mark All Absent
                        </button>
                    </form>
                    <form method="post" action="" style="display: inline-block;">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                        <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                        <button type="submit" name="mark_unmarked" class="btn btn-warning">
                            <i class="fas fa-user-check"></i> Mark Unmarked as Absent
                        </button>
                    </form>
                </div>
                
                <!-- Student List -->
                <?php if (!empty($students)): ?>
                    <h3 style="margin-bottom: 1rem;">Student List</h3>
                    
                    <!-- Main attendance form -->
                    <form method="post" action="" id="attendanceForm">
                        <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                        <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                        <input type="hidden" name="update_all_pages" value="1">
                        <input type="hidden" name="sort_column" id="sort_column" value="<?php echo htmlspecialchars($sort_column); ?>">
                        <input type="hidden" name="sort_direction" id="sort_direction" value="<?php echo htmlspecialchars($sort_direction); ?>">
                        <input type="hidden" name="select_class" value="1">
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="sortable <?php echo ($sort_column == 'roll_number') ? strtolower($sort_direction) : ''; ?>" 
                                            data-column="roll_number">Roll Number</th>
                                        <th class="sortable <?php echo ($sort_column == 'name') ? strtolower($sort_direction) : ''; ?>" 
                                            data-column="name">Name</th>
                                        <?php if (isset($students[0]['department_name'])): ?>
                                            <th class="sortable <?php echo ($sort_column == 'department_name') ? strtolower($sort_direction) : ''; ?>" 
                                                data-column="department_name">Department</th>
                                        <?php endif; ?>
                                        <th class="sortable <?php echo ($sort_column == 'status') ? strtolower($sort_direction) : ''; ?>" 
                                           data-column="status">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr class="<?php echo $student['status']; ?>">
                                            <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                                            <?php if (isset($student['department_name'])): ?>
                                                <td><?php echo htmlspecialchars($student['department_name']); ?></td>
                                            <?php endif; ?>
                                            <td>
                                                <select name="student_status[<?php echo $student['id']; ?>]" class="status-select">
                                                    <option value="present" <?php echo $student['status'] == 'present' ? 'selected' : ''; ?>>Present</option>
                                                    <option value="absent" <?php echo $student['status'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                    <option value="late" <?php echo $student['status'] == 'late' ? 'selected' : ''; ?>>Late</option>
                                                    <option value="excused" <?php echo $student['status'] == 'excused' ? 'selected' : ''; ?>>Excused</option>
                                                </select>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <button type="submit" name="update_attendance" class="btn">
                            <i class="fas fa-save"></i> Save Attendance Changes
                        </button>
                    </form>
                    
                    <!-- Pagination controls with sort parameters -->
                    <?php if (isset($total_pages) && $total_pages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            Showing <?php echo ($offset + 1); ?>-<?php echo min($offset + $students_per_page, $total_students); ?> 
                            of <?php echo $total_students; ?> students
                        </div>
                        
                        <form method="post" action="" class="pagination-form">
                            <input type="hidden" name="schedule_id" value="<?php echo $selected_class['id']; ?>">
                            <input type="hidden" name="schedule_type" value="<?php echo $selected_class['schedule_type']; ?>">
                            <input type="hidden" name="select_class" value="1">
                            <input type="hidden" name="sort_column" value="<?php echo htmlspecialchars($sort_column); ?>">
                            <input type="hidden" name="sort_direction" value="<?php echo htmlspecialchars($sort_direction); ?>">
                            
                            <div class="pagination">
                                <!-- Previous page button -->
                                <?php if ($current_page > 1): ?>
                                    <button type="submit" name="page" value="<?php echo $current_page - 1; ?>" class="pagination-btn">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="pagination-btn disabled">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Page numbers -->
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                if ($end_page - $start_page < 4 && $total_pages > 5) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                if ($start_page > 1): ?>
                                    <button type="submit" name="page" value="1" class="pagination-btn">1</button>
                                    <?php if ($start_page > 2): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <button type="submit" name="page" value="<?php echo $i; ?>" 
                                            class="pagination-btn <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </button>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                    <button type="submit" name="page" value="<?php echo $total_pages; ?>" class="pagination-btn">
                                        <?php echo $total_pages; ?>
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Next page button -->
                                <?php if ($current_page < $total_pages): ?>
                                    <button type="submit" name="page" value="<?php echo $current_page + 1; ?>" class="pagination-btn">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="pagination-btn disabled">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p>No students found for this class/session.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <a href="dashboard.php" class="floating-back">
        <i class="fas fa-arrow-left"></i>
    </a>

    <!-- Add this modal HTML structure at the end of the body, before the footer include -->
    <!-- Error Modal -->
    <div id="errorModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="modal-close">&times;</span>
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Error</h3>
            </div>
            <div class="modal-body" id="errorModalContent">
                Error message will appear here
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger modal-close-btn">Close</button>
            </div>
        </div>
    </div>

    <style>
        /* Modal Styles */
        .modal {
            position: fixed;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            transform: scale(1.1);
            transition: visibility 0s linear 0.25s, opacity 0.25s 0s, transform 0.25s;
            z-index: 1200;
            display: flex !important;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--bg-color);
            max-width: 90%;
            width: 500px;
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--error-color);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            padding: 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            text-align: right;
        }

        .modal-close {
            position: absolute;
            right: 15px;
            top: 10px;
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--text-light);
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--error-color);
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
            transform: scale(1.0);
            transition: visibility 0s linear 0s, opacity 0.25s 0s, transform 0.25s;
        }
        
        @media (max-width: 576px) {
            .modal-content {
                width: 95%;
            }
        }
    </style>

    <!-- The footer is included in header.php -->

    <script>
        // Check for vibration support with better detection
        const hasVibrationSupport = () => {
            return 'vibrate' in navigator && 
                   typeof navigator.vibrate === 'function' && 
                   navigator.vibrate(0) !== false; // Test that it doesn't return false
        };
        
        // Modal handling functions
        function showErrorModal(message) {
            const modal = document.getElementById('errorModal');
            const content = document.getElementById('errorModalContent');
            
            // Set the error message
            content.innerHTML = message;
            
            // Show the modal
            modal.classList.add('show');
            modal.style.display = 'flex';
            
            // Error haptic feedback 
            try {
                // Try to vibrate with a strong pattern
                if (hasVibrationSupport()) {
                    console.log('Triggering error vibration');
                    window.navigator.vibrate([300, 100, 300]);
                } else {
                    console.log('Vibration not supported on this device');
                }
            } catch (e) {
                console.error('Error triggering vibration:', e);
            }
            
            // Visual flash fallback for devices without vibration
            document.body.style.backgroundColor = '#ffcccc'; // Light red
            setTimeout(() => {
                document.body.style.backgroundColor = ''; // Reset
            }, 300);
        }
        
        // Success haptic feedback function
        function triggerSuccessFeedback() {
            try {
                // Try to vibrate with a gentle pattern
                if (hasVibrationSupport()) {
                    console.log('Triggering success vibration');
                    window.navigator.vibrate([100, 50, 100]);
                } else {
                    console.log('Vibration not supported on this device');
                }
            } catch (e) {
                console.error('Error triggering vibration:', e);
            }
            
            // Visual flash fallback for devices without vibration
            document.body.style.backgroundColor = '#ccffcc'; // Light green
            setTimeout(() => {
                document.body.style.backgroundColor = ''; // Reset
            }, 300);
        }
        
        // Close modal when clicking the X, close button or outside the modal
        document.querySelectorAll('.modal-close, .modal-close-btn').forEach(el => {
            el.addEventListener('click', function() {
                const modal = document.getElementById('errorModal');
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 250);
            });
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('errorModal');
            if (event.target === modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 250);
            }
        });
        
        // Focus on scanner input when available
        document.addEventListener('DOMContentLoaded', function() {
            const scannerInput = document.getElementById('scanner');
            if (scannerInput) {
                scannerInput.focus();
            }
            
            // Set schedule type based on selected option
            const scheduleSelect = document.getElementById('schedule_id');
            const scheduleTypeInput = document.getElementById('schedule_type');
            
            if (scheduleSelect) {
                scheduleSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    const scheduleType = selectedOption.getAttribute('data-type');
                    scheduleTypeInput.value = scheduleType;
                });
            }
            
            // Auto-update when status changes in the student list
            const statusSelects = document.querySelectorAll('.status-select');
            statusSelects.forEach(select => {
                select.addEventListener('change', function() {
                    // Find the closest form and get required data
                    const form = this.closest('form');
                    const studentId = this.name.match(/\[(\d+)\]/)[1];
                    const newStatus = this.value;
                    
                    // Show visual feedback
                    const row = this.closest('tr');
                    row.className = newStatus; // Update row class based on new status
                    row.classList.add('status-updated'); // Add highlight animation
                    
                    // Create form data for submission
                    const formData = new FormData(form);
                    
                    // Important: Add the update_attendance parameter to trigger server-side processing
                    formData.append('update_attendance', '1');
                    
                    // Set update_all_pages to 0 for AJAX updates (only update this one student)
                    formData.set('update_all_pages', '0');
                    
                    // Show updating indicator
                    const originalText = this.options[this.selectedIndex].text;
                    const selectWidth = this.offsetWidth;
                    this.style.minWidth = selectWidth + 'px'; // Prevent width change
                    this.disabled = true;
                    select.style.backgroundImage = 'url("data:image/svg+xml;charset=utf8,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\'%3E%3Cpath fill=\'%23888\' d=\'M12,4V2A10,10 0 0,0 2,12H4A8,8 0 0,1 12,4Z\'%3E%3CanimateTransform attributeName=\'transform\' type=\'rotate\' from=\'0 12 12\' to=\'360 12 12\' dur=\'1s\' repeatCount=\'indefinite\'/%3E%3C/path%3E%3C/svg%3E")';
                    select.style.backgroundPosition = 'center right 5px';
                    select.style.backgroundSize = '16px';
                    select.style.backgroundRepeat = 'no-repeat';
                    
                    // Use fetch API to submit the form without page reload
                    fetch(window.location.href, {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(html => {
                        console.log('Status updated successfully');
                        // Reset the select to normal state
                        setTimeout(() => {
                            this.disabled = false;
                            select.style.backgroundImage = '';
                            row.classList.remove('status-updated');
                        }, 1000);
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Failed to update status. Please try again or use the Update All button.');
                        // Reset the select to normal state
                        this.disabled = false;
                        select.style.backgroundImage = '';
                    });
                });
            });
            
            // QR Code / Barcode Scanner Implementation
            const startScannerButton = document.getElementById('startScanner');
            const stopScannerButton = document.getElementById('stopScanner');
            const scannerForm = document.getElementById('scannerForm');
            
            let html5QrcodeScanner = null;
            let scannerCooldown = false; // Cooldown flag to prevent rapid scans
            
            if (startScannerButton) {
                startScannerButton.addEventListener('click', function() {
                    // First check if the camera is available
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        showErrorModal("Your browser doesn't support camera access. Please try using Chrome or Firefox.");
                        return;
                    }
                    
                    // Update UI
                    startScannerButton.style.display = 'none';
                    stopScannerButton.style.display = 'inline-block';
                    
                    // Add status message
                    const qrReader = document.getElementById('qr-reader');
                    qrReader.innerHTML = '<div style="text-align: center; padding: 20px;">Accessing camera...</div>';
                    qrReader.classList.add('active'); // Show the reader
                    
                    // Request camera permission first
                    navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
                    .then(function(stream) {
                        // Camera permission granted, now start the scanner
                        stream.getTracks().forEach(track => track.stop()); // Stop the stream as html5-qrcode will create its own
                        
                        // Calculate the optimal QR box size based on screen width
                        const screenWidth = window.innerWidth;
                        let qrboxSize = 250; // Default size
                        
                        if (screenWidth < 480) {
                            qrboxSize = Math.min(screenWidth - 60, 200); // For small screens
                        } else if (screenWidth < 768) {
                            qrboxSize = Math.min(screenWidth - 80, 240); // For medium screens
                        }
                        
                        const config = {
                            fps: 10,
                            qrbox: { width: qrboxSize, height: qrboxSize },
                            rememberLastUsedCamera: true,
                            aspectRatio: 1.0,
                            // Support multiple barcode formats
                            formatsToSupport: [
                                Html5QrcodeSupportedFormats.QR_CODE,
                                Html5QrcodeSupportedFormats.CODE_39,
                                Html5QrcodeSupportedFormats.CODE_93,
                                Html5QrcodeSupportedFormats.CODE_128,
                                Html5QrcodeSupportedFormats.EAN_8,
                                Html5QrcodeSupportedFormats.EAN_13
                            ]
                        };
                        
                        html5QrcodeScanner = new Html5Qrcode("qr-reader");
                        html5QrcodeScanner.start(
                            { facingMode: "environment" }, // Use back camera
                            config,
                            (decodedText, decodedResult) => {
                                // Handle the scanned code
                                console.log("Scanned successfully:", decodedText);
                                
                                // If cooldown is active, ignore this scan
                                if (scannerCooldown) {
                                    console.log("Scan ignored - cooldown active");
                                    return;
                                }
                                
                                // Store the last scanned roll number to prevent duplicate scans
                                const lastScannedRoll = document.getElementById('scanner').value;
                                
                                // Only process if this is a new roll number
                                if (lastScannedRoll !== decodedText) {
                                    // Set cooldown to prevent rapid scans
                                    scannerCooldown = true;
                                    
                                    document.getElementById('scanner').value = decodedText;
                                    
                                    // Use our new submit function
                                    submitAttendanceForm();
                                    
                                    // Reset cooldown after 0.7 second
                                    setTimeout(() => {
                                        scannerCooldown = false;
                                        console.log("Scanner cooldown ended");
                                    }, 700);
                                }
                            },
                            (errorMessage) => {
                                // This is just a scanning error, not a critical error
                                console.log(`QR Code scanning in progress:`, errorMessage);
                            }
                        ).catch(err => {
                            console.error("Error starting scanner:", err);
                            showErrorModal("Error starting scanner: " + err.message + "<br><br>Please ensure you've granted camera permissions and try again.");
                            stopScannerButton.style.display = 'none';
                            startScannerButton.style.display = 'inline-block';
                            qrReader.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Camera error. Please try again.</div>';
                            qrReader.classList.remove('active');
                        });
                    })
                    .catch(function(err) {
                        // Camera permission denied or error
                        console.error("Camera access error:", err);
                        showErrorModal("Could not access camera: " + err.message + "<br><br>Please ensure you've granted camera permissions and try again.");
                        stopScannerButton.style.display = 'none';
                        startScannerButton.style.display = 'inline-block';
                        qrReader.innerHTML = '<div style="text-align: center; padding: 20px; color: red;">Camera permission denied. Please allow camera access.</div>';
                        qrReader.classList.remove('active');
                    });
                });
            }
            
            if (stopScannerButton) {
                stopScannerButton.addEventListener('click', function() {
                    if (html5QrcodeScanner) {
                        html5QrcodeScanner.stop().then(() => {
                            stopScannerButton.style.display = 'none';
                            startScannerButton.style.display = 'inline-block';
                            const qrReader = document.getElementById('qr-reader');
                            qrReader.innerHTML = '';
                            qrReader.classList.remove('active'); // Hide the reader
                        }).catch(err => {
                            console.error("Error stopping scanner:", err);
                            showErrorModal("Error stopping scanner: " + err.message);
                        });
                    }
                });
            }
            
            // Add a simple test function that can be called from browser console
            window.testCamera = function() {
                navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(stream) {
                    alert("Camera test successful!");
                    stream.getTracks().forEach(track => track.stop());
                })
                .catch(function(err) {
                    showErrorModal("Camera test failed: " + err.message);
                });
            };
        });

        // Auto-submit scanner form when barcode is scanned
        document.getElementById('scannerForm')?.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                submitAttendanceForm();
            }
        });

        // Handle form submission without page reload
        document.getElementById('scannerForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            submitAttendanceForm();
        });

        function submitAttendanceForm() {
            const scannerForm = document.getElementById('scannerForm');
            const rollNumber = document.getElementById('scanner').value;
            const status = document.getElementById('status').value;
            
            if (!rollNumber.trim()) {
                showErrorModal("Please enter a roll number");
                return;
            }
            
            // Disable the form during submission to prevent multiple submissions
            const submitButton = scannerForm.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
            
            // Show scanning feedback
            const feedbackDiv = document.createElement('div');
            feedbackDiv.className = 'alert alert-info mt-2';
            feedbackDiv.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Processing: ${rollNumber}`;
            document.querySelector('.scanner-container').appendChild(feedbackDiv);
            
            // Use fetch API to submit the form without page reload
            const formData = new FormData(scannerForm);
            
            // Set update_all_pages to 0 for individual attendance marking
            if (formData.has('update_all_pages')) {
                formData.set('update_all_pages', '0');
            }
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Parse the HTML response to check for error or success messages
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Check if there's an error message in the response
                const errorElement = doc.querySelector('.error-message');
                if (errorElement) {
                    // Remove the processing feedback
                    feedbackDiv.remove();
                    
                    // Show error in modal instead of inline
                    showErrorModal(errorElement.textContent.trim());
                    
                    console.error("Attendance marking error:", errorElement.textContent.trim());
                    
                    // Re-enable the submit button
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fas fa-check"></i> Mark Attendance';
                    }
                    
                    return; // Stop processing
                }
                
                // Check for success message
                const successElement = doc.querySelector('.success-message');
                if (successElement) {
                    feedbackDiv.className = 'alert alert-success mt-2';
                    feedbackDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${successElement.textContent.trim()}`;
                } else {
                    // Default success message if none found in response
                feedbackDiv.className = 'alert alert-success mt-2';
                feedbackDiv.innerHTML = `<i class="fas fa-check-circle"></i> Marked ${rollNumber} as ${status}`;
                }
                
                // Trigger success haptic feedback
                triggerSuccessFeedback();
                
                // Find and update just the specific student row
                const rows = doc.querySelectorAll('.table tbody tr');
                let updatedRow = null;
                
                for (const row of rows) {
                    const cellText = row.cells[0].textContent.trim();
                    if (cellText === rollNumber) {
                        updatedRow = row;
                        break;
                    }
                }
                
                // Update the current table with the new row data
                if (updatedRow) {
                    const currentTable = document.querySelector('.table tbody');
                    if (currentTable) {
                        // Find the matching row in the current table
                        const currentRows = document.querySelectorAll('.table tbody tr');
                        for (const row of currentRows) {
                            const cellText = row.cells[0].textContent.trim();
                            if (cellText === rollNumber) {
                                // Update row classes for status styling
                                row.className = status;
                                
                                // Update the status cell with new status
                                const statusCell = row.cells[row.cells.length - 1];
                                const newStatusCell = updatedRow.cells[updatedRow.cells.length - 1];
                                statusCell.innerHTML = newStatusCell.innerHTML;
                                
                                // Add highlight animation
                                row.classList.add('status-updated');
                                setTimeout(() => {
                                    row.classList.remove('status-updated');
                                }, 2000);
                                
                                break;
                            }
                        }
                    }
                }
                
                // Clear the input for next entry
                document.getElementById('scanner').value = '';
                
                // Re-enable the submit button after 1 second
                setTimeout(() => {
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = '<i class="fas fa-check"></i> Mark Attendance';
                    }
                    document.getElementById('scanner').focus();
                    
                    // Remove feedback message
                    feedbackDiv.remove();
                }, 2000);
            })
            .catch(error => {
                console.error('Error:', error);
                
                // Remove the processing feedback
                feedbackDiv.remove();
                
                // Show error in modal
                showErrorModal(`Network error while processing ${rollNumber}. Please try again.`);
                
                // Re-enable the submit button
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fas fa-check"></i> Mark Attendance';
                }
            });
        }

        // Replace automatic refocus with toggle functionality
        let scannerModeFocused = false;
        let scannerModeInterval = null;
        
        function toggleScannerMode() {
            const scanToggleBtn = document.getElementById('toggleScannerMode');
            const scannerInput = document.getElementById('scanner');
            
            if (scannerModeFocused) {
                // Turn off scanner mode
                scannerModeFocused = false;
                clearInterval(scannerModeInterval);
                scanToggleBtn.innerHTML = '<i class="fas fa-barcode"></i> Enable Continuous Scan Mode';
                scanToggleBtn.classList.replace('btn-warning', 'btn-info');
            } else {
                // Turn on scanner mode
                scannerModeFocused = true;
                scannerInput.focus();
                scannerModeInterval = setInterval(function() {
                    if (scannerInput && document.activeElement !== scannerInput) {
                        scannerInput.focus();
                    }
                }, 3000);
                scanToggleBtn.innerHTML = '<i class="fas fa-times"></i> Disable Continuous Scan Mode';
                scanToggleBtn.classList.replace('btn-info', 'btn-warning');
            }
        }

        // Add sorting functionality
        document.addEventListener('DOMContentLoaded', function() {
            const sortableHeaders = document.querySelectorAll('th.sortable');
            const sortColumnInput = document.getElementById('sort_column');
            const sortDirectionInput = document.getElementById('sort_direction');
            const attendanceForm = document.getElementById('attendanceForm');
            
            if (sortableHeaders) {
                sortableHeaders.forEach(header => {
                    header.addEventListener('click', function() {
                        const column = this.getAttribute('data-column');
                        let direction = 'ASC';
                        
                        // Toggle sort direction if clicking on already sorted column
                        if (column === sortColumnInput.value) {
                            direction = sortDirectionInput.value === 'ASC' ? 'DESC' : 'ASC';
                        }
                        
                        // Update hidden inputs
                        sortColumnInput.value = column;
                        sortDirectionInput.value = direction;
                        
                        // Submit the form to refresh with new sorting
                        if (attendanceForm) {
                            attendanceForm.submit();
                        }
                    });
                });
            }
            
            // ... existing JavaScript code ...
        });
    </script>

<?php
// Don't include closing body and html tags as they are in header.php
?>