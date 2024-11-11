<?php
// Database connection
$host = 'localhost'; // Replace with your database host
$username = 'root'; // Replace with your database username
$password = ''; // Replace with your database password
$database = 'college_feedback'; // Replace with your database name

$conn = mysqli_connect($host, $username, $password, $database);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

function authenticate_user($email, $password) {
    global $conn;
    $email = mysqli_real_escape_string($conn, $email);
    
    $tables = ['students', 'faculty', 'hods'];
    
    foreach ($tables as $table) {
        if ($table === 'students') {
            $query = "SELECT id, email, password, department_id, 'student' as role, 
                     current_year, current_semester, section 
                     FROM students 
                     WHERE email = ? AND is_active = TRUE";
        } elseif ($table === 'faculty') {
            $query = "SELECT id, email, password, department_id, 'faculty' as role,
                     designation, experience, qualification, specialization 
                     FROM faculty 
                     WHERE email = ? AND is_active = TRUE";
        } else {
            $query = "SELECT id, email, password, department_id, 'hod' as role,
                     name, username 
                     FROM hods 
                     WHERE email = ? AND is_active = TRUE";
        }
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($user = mysqli_fetch_assoc($result)) {
            if (password_verify($password, $user['password'])) {
                return $user;
            }
        }
    }
    return false;
}

function get_user_role($user_id) {
    global $conn;
    $user_id = mysqli_real_escape_string($conn, $user_id);
    
    $tables = ['students', 'faculty', 'hods'];
    
    foreach ($tables as $table) {
        $query = "SELECT '$table' as role FROM $table WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            return $row['role'];
        }
    }
    return null;
}

function is_password_complex($password) {
    // Password should be at least 8 characters long
    if (strlen($password) < 8) {
        return false;
    }
    
    // Password should contain at least one uppercase letter
    if (!preg_match('/[A-Z]/', $password)) {
        return false;
    }
    
    // Password should contain at least one lowercase letter
    if (!preg_match('/[a-z]/', $password)) {
        return false;
    }
    
    // Password should contain at least one number
    if (!preg_match('/[0-9]/', $password)) {
        return false;
    }
    
    // Password should contain at least one special character
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        return false;
    }
    
    return true;
}

function get_department_name($department_id) {
    global $conn;
    $department_id = mysqli_real_escape_string($conn, $department_id);
    
    $query = "SELECT name FROM departments WHERE id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $department_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    return $row['name'];
}

function get_subject_details($subject_id) {
    global $conn;
    $subject_id = mysqli_real_escape_string($conn, $subject_id);
    
    $query = "SELECT s.name AS subject_name, f.name AS faculty_name 
              FROM subjects s 
              JOIN faculty f ON s.faculty_id = f.id 
              WHERE s.id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $subject_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

function log_user_action($user_id, $action, $role) {
    global $conn;
    
    // Check if the user_logs table exists
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'user_logs'");
    if(mysqli_num_rows($table_check) == 0) {
        // Table doesn't exist, create it without foreign key constraint
        $create_table = "CREATE TABLE IF NOT EXISTS user_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            role VARCHAR(20) NOT NULL,
            action VARCHAR(255) NOT NULL,
            timestamp DATETIME NOT NULL
        )";
        mysqli_query($conn, $create_table);
    } else {
        // Table exists, remove the foreign key constraint if it exists
        $alter_table = "ALTER TABLE user_logs DROP FOREIGN KEY IF EXISTS user_logs_ibfk_1";
        mysqli_query($conn, $alter_table);
    }
    
    $user_id = mysqli_real_escape_string($conn, $user_id);
    $action = mysqli_real_escape_string($conn, $action);
    $role = mysqli_real_escape_string($conn, $role);
    
    $query = "INSERT INTO user_logs (user_id, role, action, timestamp) VALUES (?, ?, ?, NOW())";
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "iss", $user_id, $role, $action);
        $result = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $result;
    } else {
        error_log("Failed to prepare statement for logging user action: " . mysqli_error($conn));
        return false;
    }
}

function get_total_students() {
    global $conn;
    
    $query = "SELECT COUNT(*) as total FROM students";
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    return $row['total'];
}

function getNotifications($user_id, $role) {
    global $conn;
    $notifications = array();

    $query = "SELECT * FROM notifications WHERE user_id = ? AND role = ? ORDER BY created_at DESC LIMIT 5";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "is", $user_id, $role);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $notifications[] = array(
            'message' => $row['message'],
            'date' => date('M d, Y', strtotime($row['created_at']))
        );
    }

    return $notifications;
}

function getQuickStats($user_id, $role) {
    global $conn;
    $stats = array();

    switch ($role) {
        case 'student':
            // Number of subjects
            $query = "SELECT COUNT(*) as subject_count FROM student_subjects WHERE student_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Subjects', 'value' => $row['subject_count']);

            // Feedback given
            $query = "SELECT COUNT(*) as feedback_count FROM feedback WHERE student_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Feedback Given', 'value' => $row['feedback_count']);
            break;

        case 'faculty':
            // Average rating
            $query = "SELECT AVG(rating) as avg_rating FROM feedback WHERE faculty_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Average Rating', 'value' => number_format($row['avg_rating'], 2));

            // Number of subjects taught
            $query = "SELECT COUNT(DISTINCT subject_id) as subject_count FROM faculty_subjects WHERE faculty_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Subjects Taught', 'value' => $row['subject_count']);
            break;

        case 'hod':
        case 'hods':
            // Department performance
            $query = "SELECT AVG(rating) as dept_avg_rating FROM feedback f
                      JOIN faculty fa ON f.faculty_id = fa.id
                      WHERE fa.department_id = (SELECT department_id FROM hods WHERE id = ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Dept. Avg Rating', 'value' => number_format($row['dept_avg_rating'], 2));

            // Number of faculty
            $query = "SELECT COUNT(*) as faculty_count FROM faculty WHERE department_id = (SELECT department_id FROM hods WHERE id = ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($result);
            $stats[] = array('label' => 'Faculty Count', 'value' => $row['faculty_count']);
            break;
    }

    return $stats;
}

function get_current_academic_year($conn) {
    $query = "SELECT id, year_range, start_year, end_year 
              FROM academic_years 
              WHERE is_current = TRUE 
              LIMIT 1";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    
    // If no current academic year is set, return the most recent one
    $query = "SELECT id, year_range, start_year, end_year 
              FROM academic_years 
              ORDER BY start_year DESC 
              LIMIT 1";
    
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        return mysqli_fetch_assoc($result);
    }
    
    // If no academic years exist, return null
    return null;
}
function get_active_academic_years($conn) {
    $query = "SELECT * FROM academic_years WHERE start_year <= YEAR(CURRENT_DATE()) AND end_year >= YEAR(CURRENT_DATE()) ORDER BY start_year DESC";
    $result = mysqli_query($conn, $query);
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

function sanitize_input($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function getCurrentAcademicYear($conn) {
    $query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }
    
    // If no current academic year is set, get the latest one
    $query = "SELECT id FROM academic_years ORDER BY end_year DESC LIMIT 1";
    $result = mysqli_query($conn, $query);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        return $row['id'];
    }
    
    throw new Exception("No academic year found in the system.");
}

function calculateFeedbackAverages($conn, $feedback_id) {
    $averages = [];
    
    // Get all sections
    $sections = [
        'course_effectiveness',
        'teaching_effectiveness',
        'resources_admin',
        'assessment_learning',
        'course_outcomes'
    ];
    
    // Calculate averages for each section
    foreach ($sections as $section) {
        $query = "SELECT 
                    statement_id,
                    COUNT(*) as total_responses,
                    AVG(rating) as avg_rating,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as sd_count,
                    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as d_count,
                    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as n_count,
                    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as a_count,
                    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as sa_count
                  FROM feedback_ratings 
                  WHERE feedback_id = ? AND section = ?
                  GROUP BY statement_id";
                  
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "is", $feedback_id, $section);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        $section_total = 0;
        $question_count = 0;
        
        while ($row = mysqli_fetch_assoc($result)) {
            $weighted_sum = (
                ($row['sd_count'] * 1) +
                ($row['d_count'] * 2) +
                ($row['n_count'] * 3) +
                ($row['a_count'] * 4) +
                ($row['sa_count'] * 5)
            );
            
            $avg = $weighted_sum / $row['total_responses'];
            $section_total += $avg;
            $question_count++;
        }
        
        $averages[$section] = $question_count > 0 ? 
            round($section_total / $question_count, 2) : 0;
    }
    
    // Calculate cumulative average
    $cumulative_avg = round(
        array_sum($averages) / count(array_filter($averages)),
        2
    );
    
    $averages['cumulative'] = $cumulative_avg;
    
    return $averages;
}

// Add any other necessary functions here

?>