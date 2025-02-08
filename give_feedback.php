<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    try {
        // Validate all required data is present
        if (!isset($_POST['course_effectiveness']) || 
            !isset($_POST['teaching_effectiveness']) || 
            !isset($_POST['resources_admin']) || 
            !isset($_POST['assessment_learning']) || 
            !isset($_POST['course_outcomes'])) {
            throw new Exception("Missing required feedback sections");
        }

        $valid_ratings = true;
        $all_ratings = [];
        
        // Validate all sections
        $sections = [
            'course_effectiveness',
            'teaching_effectiveness', 
            'resources_admin',
            'assessment_learning',
            'course_outcomes'
        ];

        foreach ($sections as $section) {
            if (isset($_POST[$section])) {
                $all_ratings[$section] = $_POST[$section];
                foreach ($_POST[$section] as $rating) {
                    if (!is_numeric($rating) || $rating < 1 || $rating > 5) {
                        $valid_ratings = false;
                        error_log("Invalid rating found in section $section: $rating");
                        break 2;
                    }
                }
            } else {
                $valid_ratings = false;
                error_log("Missing section: $section");
                break;
            }
        }

        if (!$valid_ratings) {
            throw new Exception("Invalid ratings provided");
        }

        mysqli_begin_transaction($conn);

        // Get current academic year
        $academic_year = getCurrentAcademicYear($conn);
        if (!$academic_year) {
            throw new Exception("No active academic year found");
        }

        // Calculate section averages
        $section_averages = [];
        foreach ($all_ratings as $section => $ratings) {
            $section_averages[$section . '_avg'] = array_sum($ratings) / count($ratings);
        }
        
        // Calculate cumulative average
        $cumulative_avg = array_sum($section_averages) / count($section_averages);

        // First verify if the assignment exists and get necessary details
        $verify_query = "SELECT sa.id, sa.faculty_id, f.email as faculty_email
                        FROM subject_assignments sa
                        JOIN faculty f ON sa.faculty_id = f.id
                        WHERE sa.id = ?";
        $verify_stmt = mysqli_prepare($conn, $verify_query);
        mysqli_stmt_bind_param($verify_stmt, "i", $assignment_id);
        mysqli_stmt_execute($verify_stmt);
        $verify_result = mysqli_stmt_get_result($verify_stmt);
        $assignment_details = mysqli_fetch_assoc($verify_result);
        if (!$assignment_details) {
            header('Location: dashboard.php?error=invalid_assignment');
            exit();
        }

        // Insert feedback record (note: faculty_id is now removed from feedback)
        $insert_query = "INSERT INTO feedback (
            assignment_id, 
            student_id,
            comments,
            course_effectiveness_avg,
            teaching_effectiveness_avg,
            resources_admin_avg,
            assessment_learning_avg,
            course_outcomes_avg,
            cumulative_avg,
            submitted_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = mysqli_prepare($conn, $insert_query);
        if (!$stmt) {
            throw new Exception("Failed to prepare feedback statement: " . mysqli_error($conn));
        }

        $comments = isset($_POST['comments']) ? $_POST['comments'] : '';
        mysqli_stmt_bind_param($stmt, "iisdddddd", 
            $assignment_id,
            $_SESSION['user_id'],
            $comments,
            $section_averages['course_effectiveness_avg'],
            $section_averages['teaching_effectiveness_avg'],
            $section_averages['resources_admin_avg'],
            $section_averages['assessment_learning_avg'],
            $section_averages['course_outcomes_avg'],
            $cumulative_avg
        );

        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to insert feedback: " . mysqli_stmt_error($stmt));
        }

        $feedback_id = mysqli_insert_id($conn);

        // Insert ratings with correct statement IDs
        $rating_query = "INSERT INTO feedback_ratings (
            feedback_id, 
            statement_id, 
            rating
        ) VALUES (?, ?, ?)";
        
        $rating_stmt = mysqli_prepare($conn, $rating_query);

        // Define statement ID ranges for each section
        $section_ranges = [
            'course_effectiveness' => ['start' => 1, 'end' => 12],
            'teaching_effectiveness' => ['start' => 13, 'end' => 19],
            'resources_admin' => ['start' => 20, 'end' => 23],
            'assessment_learning' => ['start' => 24, 'end' => 27],
            'course_outcomes' => ['start' => 28, 'end' => 33]
        ];

        foreach ($all_ratings as $section => $ratings) {
            $statement_id = $section_ranges[$section]['start'];
            foreach ($ratings as $rating) {
                if (!$rating_stmt) {
                    throw new Exception("Failed to prepare rating statement");
                }

                mysqli_stmt_bind_param($rating_stmt, "iii", 
                    $feedback_id, 
                    $statement_id, 
                    $rating
                );

                if (!mysqli_stmt_execute($rating_stmt)) {
                    throw new Exception("Failed to insert rating: " . mysqli_stmt_error($rating_stmt));
                }
                $statement_id++;
            }
        }

        // Log the action
        $log_query = "INSERT INTO user_logs (
            user_id,
            role,
            action,
            details,
            status
        ) VALUES (?, 'student', 'feedback_submission', ?, 'success')";
        
        $log_details = json_encode([
            'feedback_id' => $feedback_id,
            'assignment_id' => $assignment_id
        ]);
        
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $log_details);
        mysqli_stmt_execute($log_stmt);

        mysqli_commit($conn);
        
        echo json_encode(['status' => 'success']);
        exit;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Feedback submission error: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

// Add error display in the HTML
if (isset($error_message)) {
    echo '<div class="error-message" style="color: red; margin: 10px 0;">' . htmlspecialchars($error_message) . '</div>';
}

// Fetch student details
$student_id = $_SESSION['user_id'];
$query = "SELECT s.*, 
          d.name as department_name,
          by2.batch_name,
          by2.current_year_of_study,
          CASE 
              WHEN MONTH(CURDATE()) <= 5 THEN by2.current_year_of_study * 2
              ELSE by2.current_year_of_study * 2 - 1
          END as current_semester
          FROM students s 
          JOIN departments d ON s.department_id = d.id 
          JOIN batch_years by2 ON s.batch_id = by2.id
          WHERE s.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);

if (!$student) {
    die("Error: Student data not found.");
}

// Calculate current semester and year based on batch
$current_semester = $student['current_semester'];
$current_year = $student['current_year_of_study'];

// Fetch subject and faculty details with proper error handling
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;
$subject_query = "SELECT sa.*, s.name AS subject_name,
                 ay.year_range as academic_year,
                 sa.semester,
                 CASE 
                    WHEN sa.semester % 2 = 0 THEN sa.semester / 2
                    ELSE (sa.semester + 1) / 2
                 END as year
                 FROM subject_assignments sa 
                 JOIN subjects s ON sa.subject_id = s.id 
                 JOIN academic_years ay ON sa.academic_year_id = ay.id
                 WHERE sa.id = ?";

$subject_stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($subject_stmt, "i", $assignment_id);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    header('Location: dashboard.php?error=invalid_assignment');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Student Feedback Form - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #e0e5ec;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            width: min(90%, 1200px);
            margin: 2rem auto;
            padding: clamp(1rem, 3vw, 2rem);
        }

        .feedback-section {
            background: #e0e5ec;
            border-radius: 15px;
            padding: clamp(1rem, 3vw, 2rem);
            margin-bottom: 1.5rem;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                       -9px -9px 16px rgba(255,255,255, 0.5);
        }

        .purpose-box {
            background: #e0e5ec;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .form-group {
            margin-bottom: clamp(1rem, 3vw, 1.5rem);
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-size: clamp(0.9rem, 2vw, 1rem);
        }

        .form-group input {
            width: 100%;
            padding: clamp(0.8rem, 2vw, 1rem);
            border: none;
            border-radius: 50px;
            background: #e0e5ec;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            font-size: clamp(0.9rem, 2vw, 0.95rem);
        }

        .rating-table {
            display: block;
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .rating-table th, 
        .rating-table td {
            padding: clamp(0.8rem, 2vw, 1.2rem);
            min-width: 120px;
        }

        .rating-table th:first-child,
        .rating-table td:first-child {
            width: 60px;
            min-width: 60px;
        }

        .rating-table th:nth-child(2),
        .rating-table td:nth-child(2) {
            min-width: 200px;
        }

        .radio-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background: #e0e5ec;
            border-radius: 12px;
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            gap: 0.5rem;
        }

        .radio-group input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .radio-group label {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: #e0e5ec;
            box-shadow: 3px 3px 6px #b8b9be, 
                       -3px -3px 6px #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #2c3e50;
            position: relative;
        }

        .radio-group label:hover {
            background: #edf2f7;
        }

        .radio-group input[type="radio"]:checked + label {
            background: #3498db;
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
        }

        .radio-group input[type="radio"]:focus + label {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }

        .section-title {
            color: #2c3e50;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #3498db;
            font-size: clamp(1.2rem, 3vw, 1.5rem);
        }

        .scale-info {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .btn-submit {
            background: #3498db;
            color: white;
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            margin-top: 2rem;
            font-size: clamp(1rem, 2vw, 1.1rem);
        }

        .btn-submit:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        @media (max-width: 768px) {
            .container {
                width: 95%;
                padding: 1rem;
            }

            .feedback-section {
                padding: 1rem;
            }

            .rating-table th, 
            .rating-table td {
                padding: 0.8rem;
            }

            .radio-group label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
        }

        /* Updated header styling */
        .header {
            width: 100%;
            background: #e0e5ec;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            margin-bottom: 2rem;
        }

        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 1rem;
        }

        .college-info h1 {
            font-size: clamp(1.2rem, 3vw, 1.8rem);
            margin-bottom: 0.5rem;
        }

        .college-info p {
            font-size: clamp(0.8rem, 2vw, 1rem);
            line-height: 1.4;
        }

        /* Updated form group styling */
        .form-group {
            margin-bottom: clamp(1rem, 3vw, 1.5rem);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: clamp(0.8rem, 2vw, 1rem);
            border: none;
            border-radius: 15px;
            background: #e0e5ec;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            font-size: clamp(0.9rem, 2vw, 0.95rem);
            color: #2c3e50;
        }

        /* Updated table styling */
        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 1rem;
        }

        .rating-table th, 
        .rating-table td {
            padding: 1.2rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .rating-table th:first-child {
            width: 60px;
        }

        .rating-table th:nth-child(2) {
            width: 60%;
        }

        .rating-table th:last-child,
        .rating-table td:last-child {
            width: 280px;
            min-width: 280px;
        }

        /* Radio group container */
        .radio-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem;
            background: #e0e5ec;
            border-radius: 12px;
            width: 100%;
            max-width: 280px;
            margin: 0 auto;
            gap: 0.5rem;
        }

        .radio-group input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .radio-group label {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: #e0e5ec;
            box-shadow: 3px 3px 6px #b8b9be, 
                       -3px -3px 6px #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #2c3e50;
            position: relative;
        }

        .radio-group label:hover {
            background: #edf2f7;
        }

        .radio-group input[type="radio"]:checked + label {
            background: #3498db;
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
        }

        .radio-group input[type="radio"]:focus + label {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }

        /* Media Queries */
        @media (max-width: 768px) {
            .rating-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
                -webkit-overflow-scrolling: touch;
            }

            .rating-table th:last-child,
            .rating-table td:last-child {
                width: 240px;
                min-width: 240px;
            }

            .radio-group {
                max-width: 240px;
                padding: 0.4rem;
            }

            .radio-group label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .rating-table th:last-child,
            .rating-table td:last-child {
                width: 200px;
                min-width: 200px;
            }

            .radio-group {
                max-width: 200px;
                padding: 0.3rem;
            }

            .radio-group label {
                width: 32px;
                height: 32px;
                font-size: 0.8rem;
            }

            .rating-table th, 
            .rating-table td {
                padding: 0.8rem;
            }
        }

        /* Print styles */
        @media print {
            .container {
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .feedback-section {
                break-inside: avoid;
            }

            .btn-submit {
                display: none;
            }
        }

        /* Updated radio group styles */
        .radio-group {
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
            padding: 0.5rem;
        }

        .rating-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #e0e5ec;
            border-radius: 12px;
            padding: 0.5rem;
            gap: 0.5rem;
        }

        .rating-option {
            position: relative;
            flex: 1;
        }

        .radio-group input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .radio-group label {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: #e0e5ec;
            box-shadow: 3px 3px 6px #b8b9be, 
                       -3px -3px 6px #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #2c3e50;
            margin: 0 auto;
        }

        .radio-group label:hover {
            background: #edf2f7;
        }

        .radio-group input[type="radio"]:checked + label {
            background: #3498db;
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
        }

        .radio-group input[type="radio"]:focus + label {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }

        /* Updated table styles */
        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 1rem;
        }

        .rating-table th, 
        .rating-table td {
            padding: 1rem;
            background: #e0e5ec;
            border-radius: 15px;
        }

        .rating-table th:first-child {
            width: 60px;
        }

        .rating-table th:nth-child(2) {
            width: 50%;
        }

        .rating-table th:last-child,
        .rating-table td:last-child {
            width: 320px;
        }

        /* Rating scale legend */
        .rating-legend {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .rating-legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .rating-options {
                padding: 0.3rem;
                gap: 0.3rem;
            }

            .radio-group label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 480px) {
            .radio-group label {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }

        .rating-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 5px;
            background: #e0e5ec;
            border-radius: 10px;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }

        .rating-option {
            flex: 1;
            text-align: center;
        }

        .rating-input {
            clip: rect(0 0 0 0);
            clip-path: inset(50%);
            height: 1px;
            overflow: hidden;
            position: absolute;
            white-space: nowrap;
            width: 1px;
        }

        .rating-label {
            display: flex;
            justify-content: center;
            align-items: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e0e5ec;
            box-shadow: 3px 3px 6px #b8b9be, -3px -3px 6px #fff;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #2c3e50;
            margin: 0 auto;
        }

        .rating-input:checked + .rating-label {
            background: #3498db;
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
        }

        .rating-input:focus + .rating-label {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }

        .rating-label:hover {
            background: #edf2f7;
        }

        /* Make sure table cells don't collapse */
        .rating-table td {
            min-width: 320px;
        }

        .rating-table td:first-child {
            min-width: 50px;
        }

        .rating-table td:nth-child(2) {
            min-width: 200px;
        }

        .comments-textarea {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 15px;
            background: #e0e5ec;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            font-size: clamp(0.9rem, 2vw, 0.95rem);
            color: #2c3e50;
            resize: vertical;
            min-height: 120px;
        }

        .char-counter {
            text-align: right;
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
        }

        .comments-textarea:focus {
            outline: 2px solid #3498db;
            outline-offset: 2px;
        }
    </style>

    <!-- Updated form validation script -->
    <script>
        function validateForm() {
            const sections = [
                'course_effectiveness',
                'teaching_effectiveness',
                'resources_admin',
                'assessment_learning',
                'course_outcomes'
            ];
            
            for (const section of sections) {
                const ratings = document.querySelectorAll(`input[name^="${section}"]:checked`);
                const questions = document.querySelectorAll(`input[name^="${section}"]`);
                const totalQuestions = questions.length / 5; // 5 radio buttons per question
                
                if (ratings.length < totalQuestions) {
                    alert(`Please rate all questions in the ${section.replace('_', ' ')} section.`);
                    return false;
                }
            }

            const required = document.querySelectorAll('[required]');
            let valid = true;
            const errorMessages = document.querySelectorAll('.error-message');
            
            // Remove existing error messages
            errorMessages.forEach(msg => msg.remove());

            required.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    const errorMsg = document.createElement('div');
                    errorMsg.className = 'error-message';
                    errorMsg.textContent = 'This field is required';
                    field.parentNode.appendChild(errorMsg);
                    valid = false;
                } else {
                    field.classList.remove('error');
                }
            });

            // Validate email format
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (email && !emailRegex.test(email.value)) {
                email.classList.add('error');
                const errorMsg = document.createElement('div');
                errorMsg.className = 'error-message';
                errorMsg.textContent = 'Please enter a valid email address';
                email.parentNode.appendChild(errorMsg);
                valid = false;
            }

            // Validate phone number
            const phone = document.getElementById('contact_number');
            if (phone && !/^\d{10}$/.test(phone.value)) {
                phone.classList.add('error');
                const errorMsg = document.createElement('div');
                errorMsg.className = 'error-message';
                errorMsg.textContent = 'Please enter a valid 10-digit phone number';
                phone.parentNode.appendChild(errorMsg);
                valid = false;
            }

            return confirm("Are you sure you want to submit this feedback? This action cannot be undone.");
        }
    </script>

    <!-- Add this rating legend before the table -->
    <div class="rating-legend">
        <div class="rating-legend-item">1 = Strongly Disagree</div>
        <div class="rating-legend-item">2 = Disagree</div>
        <div class="rating-legend-item">3 = Neutral</div>
        <div class="rating-legend-item">4 = Agree</div>
        <div class="rating-legend-item">5 = Excellent</div>
    </div>

    <!-- Add this JavaScript for better user experience -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('feedbackForm');
        
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            console.log('Form submission started');

            let isValid = true;
            const formData = new FormData(form);
            
            // Define the expected number of questions and ID ranges for each section
            const sectionQuestions = {
                'course_effectiveness': {
                    count: 12,    // 12 questions
                    startId: 1,   // IDs 1-12
                    endId: 12
                },
                'teaching_effectiveness': {
                    count: 7,     // 7 questions  
                    startId: 13,  // IDs 13-19
                    endId: 19
                },
                'resources_admin': {
                    count: 4,     // 4 questions
                    startId: 20,  // IDs 20-23
                    endId: 23
                },
                'assessment_learning': {
                    count: 4,     // 4 questions
                    startId: 24,  // IDs 24-27
                    endId: 27
                },
                'course_outcomes': {
                    count: 6,     // 6 questions
                    startId: 28,  // IDs 28-33
                    endId: 33
                }
            };

            // Validate all sections
            Object.keys(sectionQuestions).forEach(section => {
                const questions = document.querySelectorAll(`input[name^="${section}["]`);
                const expectedQuestions = sectionQuestions[section];
                const answeredQuestions = new Set();

                questions.forEach(radio => {
                    if (radio.checked) {
                        answeredQuestions.add(radio.name);
                    }
                });

                console.log(`${section}: ${answeredQuestions.size} answered out of ${expectedQuestions} questions`);

                if (answeredQuestions.size < expectedQuestions) {
                    isValid = false;
                    console.error(`Incomplete section: ${section}`);
                    // Highlight unanswered questions
                    const questionGroups = new Set();
                    questions.forEach(radio => {
                        questionGroups.add(radio.name);
                    });

                    questionGroups.forEach(name => {
                        const isAnswered = document.querySelector(`input[name="${name}"]:checked`);
                        if (!isAnswered) {
                            const row = document.querySelector(`input[name="${name}"]`).closest('tr');
                            if (row) row.style.backgroundColor = '#fff3cd';
                        }
                    });
                }
            });

            // Validate required fields
            const requiredFields = ['contact_address', 'email', 'contact_number'];
            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field || !field.value.trim()) {
                    isValid = false;
                    console.error(`Required field empty: ${fieldId}`);
                    if (field) field.classList.add('error');
                }
            });

            if (!isValid) {
                console.error('Validation failed');
                alert('Please complete all required fields and rate all questions.');
                return false;
            }

            console.log('Validation passed, submitting form...');

            // Submit form using fetch
            fetch(form.action, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Server response status:', response.status);
                return response.text();
            })
            .then(data => {
                console.log('Server response:', data);
                try {
                    const result = JSON.parse(data);
                    if (result.status === 'success') {
                        window.location.href = 'dashboard.php';
                    } else {
                        throw new Error(result.message || 'Submission failed');
                    }
                } catch (e) {
                    console.error('Error parsing response:', e);
                    alert('Error submitting feedback. Please try again.');
                }
            })
            .catch(error => {
                console.error('Submission error:', error);
                alert('Error submitting feedback. Please try again.');
            });
        });

        // Add event listeners to clear highlighting when a radio button is selected
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const row = this.closest('tr');
                if (row) {
                    row.style.backgroundColor = '';
                }
            });
        });

        const commentsTextarea = document.getElementById('comments');
        const charCount = document.getElementById('charCount');

        if (commentsTextarea && charCount) {
            commentsTextarea.addEventListener('input', function() {
                const remaining = this.value.length;
                charCount.textContent = remaining;
                
                // Optional: Change color when approaching limit
                if (remaining > 900) {
                    charCount.style.color = '#e74c3c';
                } else {
                    charCount.style.color = '#666';
                }
            });
        }
    });
    </script>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
            <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123.</p>
        </div>
    </div>

    <div class="container">
        <div class="purpose-box">
            <h3>PURPOSE</h3>
            <p>To gather feedback from students about their learning experience.</p>
            <h3>INSTRUCTIONS</h3>
            <p>Check the appropriate responses among the list of choices provided. Comments and suggestions can be added at the end of the survey.</p>
        </div>

        <form method="POST" action="" id="feedbackForm" onsubmit="return false;">
            <!-- Background Information Section -->
            <div class="feedback-section">
                <h2 class="section-title">BACKGROUND INFORMATION</h2>
                <div class="form-group">
                    <label for="name">NAME OF THE STUDENT</label>
                    <input type="text" 
                           id="name" 
                           name="name" 
                           value="<?php echo htmlspecialchars($student['name'] ?? ''); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="year">YEAR</label>
                    <input type="text" 
                           id="year" 
                           name="year" 
                           value="<?php echo htmlspecialchars($student['current_year_of_study']); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="semester">SEMESTER</label>
                    <input type="text" 
                           id="semester" 
                           name="semester" 
                           value="<?php echo htmlspecialchars($student['current_semester']); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="register_number">REGISTER NUMBER</label>
                    <input type="text" 
                           id="register_number" 
                           name="register_number" 
                           value="<?php echo htmlspecialchars($student['register_number']); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="subject">SUBJECT CODE & NAME</label>
                    <input type="text" 
                           id="subject" 
                           name="subject" 
                           value="<?php echo htmlspecialchars(($subject['subject_name'] ?? '') . ' - ' . ($subject['code'] ?? '')); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="section">SECTION</label>
                    <input type="text" 
                           id="section" 
                           name="section" 
                           value="<?php echo htmlspecialchars($subject['section'] ?? ''); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="contact_address">CONTACT ADDRESS</label>
                    <textarea id="contact_address" 
                              name="contact_address" 
                              rows="3" 
                              required><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                </div>
                <div class="form-group">
                    <label for="email">EMAIL ID</label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           value="<?php echo htmlspecialchars($student['email'] ?? ''); ?>" 
                           required>
                </div>
                <div class="form-group">
                    <label for="contact_number">CONTACT NUMBER</label>
                    <input type="tel" 
                           id="contact_number" 
                           name="contact_number" 
                           pattern="[0-9]{10}" 
                           value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>" 
                           required>
                </div>
            </div>

            <!-- Course Effectiveness Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part I - COURSE EFFECTIVENESS</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Excellent
                </div>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $courseEffectiveness = [
                            "Does the course stimulate self-interest?",
                            "The course was delivered as outlined in the syllabus.",
                            "The syllabus was explained at the beginning of the course.",
                            "Well-organized presentations.",
                            "Given good examples and illustrations.",
                            "Encouraged questions and class participation.",
                            "Learnt new techniques and methods from this course.",
                            "Understood the relevance of the course for real-world application.",
                            "Course assignments and lectures complemented each other for design development/Projects.",
                            "Course will help in competitive examinations.",
                            "Course objectives mapped with outcomes.",
                            "Course outcomes help to attain Program Educational Objectives (PEOs)."
                        ];

                        function generateRatingOptions($index, $prefix, $name) {
                            $html = '<td class="radio-group">';
                            $html .= '<div class="rating-options">';
                            
                            // Add a hidden dummy input to prevent validation error when no option is selected
                            $html .= '<input type="hidden" name="' . $name . '[' . $index . ']" value="">';
                            
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = $prefix . '_' . ($index + 1) . '_' . $i;
                                $html .= '<div class="rating-option">';
                                $html .= '<input type="radio" 
                                        id="' . $inputId . '" 
                                        name="' . $name . '[' . $index . ']" 
                                        value="' . $i . '" 
                                        class="rating-input"
                                        tabindex="0">';
                                $html .= '<label for="' . $inputId . '" class="rating-label">' . $i . '</label>';
                                $html .= '</div>';
                            }
                            $html .= '</div></td>';
                            return $html;
                        }

                        foreach ($courseEffectiveness as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo generateRatingOptions($index, 'ce', 'course_effectiveness');
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Teaching Effectiveness Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part II - TEACHING EFFECTIVENESS</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Excellent
                </div>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $teachingEffectiveness = [
                            "Deliverance by course instructor stimulates interest.",
                            "The instructor managed classroom time and place well.",
                            "Instructor meets students' expectations.",
                            "Instructor demonstrates thorough preparation for the course.",
                            "Instructor encourages discussions and responds to questions.",
                            "Instructor appeared enthusiastic and interested.",
                            "Instructor was accessible outside the classroom."
                        ];

                        foreach ($teachingEffectiveness as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo generateRatingOptions($index, 'te', 'teaching_effectiveness');
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Resources and Administration Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part III - RESOURCES AND ADMINISTRATION</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Excellent
                </div>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $resourcesAdmin = [
                            "Course supported by adequate library resources.",
                            "Usefulness of teaching methods (Chalk & Talk, PPT, OHP, etc.).",
                            "Instructor provided guidance on finding resources.",
                            "Course material/Lecture notes were effective."
                        ];

                        foreach ($resourcesAdmin as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo generateRatingOptions($index, 'ra', 'resources_admin');
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Assessment of Learning Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part IV - ASSESSMENT OF LEARNING</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Excellent
                </div>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $assessmentLearning = [
                            "Exams measure the knowledge acquired in the course.",
                            "Problems set help in understanding the course.",
                            "Feedback on assignments was useful.",
                            "Tutorial sessions help in understanding course concepts."
                        ];

                        foreach ($assessmentLearning as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo generateRatingOptions($index, 'al', 'assessment_learning');
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Course Outcome Section -->
            <div class="feedback-section">
                <h2 class="section-title">COURSE OUTCOME (CO) ATTAINMENT</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Excellent
                </div>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Course Outcomes</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        for ($i = 1; $i <= 6; $i++) {
                            echo '<tr>';
                            echo '<td>' . $i . '</td>';
                            echo '<td>COURSE OUTCOME ' . $i . '</td>';
                            echo generateRatingOptions($i-1, 'co', 'course_outcomes');
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="form-group">
                <label for="academic_year">Academic Year:</label>
                <input type="text" id="academic_year" name="academic_year" 
                       value="<?php echo htmlspecialchars($subject['academic_year']); ?>" readonly>
            </div>
            <div class="form-group">
                <label for="year">Year:</label>
                <input type="text" id="year" name="year" 
                       value="<?php echo htmlspecialchars($subject['year']); ?>" readonly>
            </div>
            <div class="form-group">
                <label for="semester">Semester:</label>
                <input type="text" id="semester" name="semester" 
                       value="<?php echo htmlspecialchars($subject['semester']); ?>" readonly>
            </div>

            <!-- Comments Section -->
            <div class="feedback-section">
                <h2 class="section-title">Additional Comments</h2>
                <div class="form-group">
                    <label for="comments">Please provide any additional comments or suggestions about the course and teaching (optional):</label>
                    <textarea 
                        id="comments" 
                        name="comments" 
                        rows="5" 
                        maxlength="1000" 
                        class="comments-textarea"
                        placeholder="Enter your comments here..."
                    ></textarea>
                    <div class="char-counter"><span id="charCount">0</span>/1000 characters</div>
                </div>
            </div>

            <button type="submit" class="btn-submit">Submit Feedback</button>
        </form>
    </div>
</body>
</html>
