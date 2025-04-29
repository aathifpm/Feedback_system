<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    try {
        // Validate all required data is present
        if (!isset($_POST['coverage_relevance']) || 
            !isset($_POST['quality_clarity']) || 
            !isset($_POST['structure_balance']) || 
            !isset($_POST['application_innovation'])) {
            throw new Exception("Missing required feedback sections");
        }

        $valid_ratings = true;
        $all_ratings = [];
        
        // Validate all sections
        $sections = [
            'coverage_relevance',
            'quality_clarity', 
            'structure_balance',
            'application_innovation'
        ];

        foreach ($sections as $section) {
            if (isset($_POST[$section])) {
                $all_ratings[$section] = $_POST[$section];
                foreach ($_POST[$section] as $statement_id => $rating) {
                    if (!is_numeric($rating) || $rating < 1 || $rating > 5) {
                        $valid_ratings = false;
                        error_log("Invalid rating found in section $section for statement ID $statement_id: $rating");
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

        // Validate text fields
        $required_text_fields = [
            'syllabus_coverage',
            'difficult_questions',
            'out_of_syllabus',
            'time_sufficiency',
            'fairness_rating',
            'improvements'
        ];

        foreach ($required_text_fields as $field) {
            if (!isset($_POST[$field]) || trim($_POST[$field]) === '') {
                error_log("Missing or empty required field: $field");
                throw new Exception("Missing required field: $field");
            }
        }

        mysqli_begin_transaction($conn);

        // Get exam timetable details
        $exam_query = "SELECT et.*, sa.subject_id, sa.faculty_id
                      FROM exam_timetable et
                      JOIN subject_assignments sa ON sa.semester = et.semester
                      WHERE sa.id = ? AND et.is_active = TRUE";
        $exam_stmt = mysqli_prepare($conn, $exam_query);
        mysqli_stmt_bind_param($exam_stmt, "i", $assignment_id);
        mysqli_stmt_execute($exam_stmt);
        $exam_result = mysqli_stmt_get_result($exam_stmt);
        $exam_details = mysqli_fetch_assoc($exam_result);

        if (!$exam_details) {
            throw new Exception("No active exam schedule found for this subject");
        }

        // Calculate section averages
        $section_averages = [];
        foreach ($all_ratings as $section => $ratings) {
            $section_averages[$section . '_avg'] = array_sum($ratings) / count($ratings);
        }
        
        // Calculate cumulative average
        $cumulative_avg = array_sum($section_averages) / count($section_averages);

        // Insert feedback record
        $insert_query = "INSERT INTO examination_feedback (
            student_id, 
            subject_assignment_id,
            exam_timetable_id,
            comments,
            coverage_relevance_avg,
            quality_clarity_avg,
            structure_balance_avg,
            application_innovation_avg,
            cumulative_avg,
            syllabus_coverage,
            difficult_questions,
            out_of_syllabus,
            time_sufficiency,
            fairness_rating,
            improvements,
            additional_comments,
            student_declaration
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = mysqli_prepare($conn, $insert_query);
        if (!$stmt) {
            throw new Exception("Failed to prepare feedback statement: " . mysqli_error($conn));
        }

        $comments = isset($_POST['comments']) ? $_POST['comments'] : '';
        $student_declaration = isset($_POST['student_declaration']) ? 1 : 0;
        
        // Sanitize and prepare text fields
        $syllabus_coverage = trim($_POST['syllabus_coverage']);
        $difficult_questions = trim($_POST['difficult_questions']);
        $out_of_syllabus = trim($_POST['out_of_syllabus']);
        $time_sufficiency = trim($_POST['time_sufficiency']);
        $fairness_rating = trim($_POST['fairness_rating']);
        $improvements = trim($_POST['improvements']);
        $additional_comments = isset($_POST['additional_comments']) ? trim($_POST['additional_comments']) : '';
        
        mysqli_stmt_bind_param($stmt, "iiisdddddsssssssi", 
            $_SESSION['user_id'],
            $assignment_id,
            $exam_details['id'],
            $comments,
            $section_averages['coverage_relevance_avg'],
            $section_averages['quality_clarity_avg'],
            $section_averages['structure_balance_avg'],
            $section_averages['application_innovation_avg'],
            $cumulative_avg,
            $syllabus_coverage,
            $difficult_questions,
            $out_of_syllabus,
            $time_sufficiency,
            $fairness_rating,
            $improvements,
            $additional_comments,
            $student_declaration
        );

        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to insert feedback: " . mysqli_stmt_error($stmt));
        }

        $feedback_id = mysqli_insert_id($conn);

        // Insert ratings
        $rating_query = "INSERT INTO examination_feedback_ratings (
            feedback_id, 
            statement_id, 
            rating
        ) VALUES (?, ?, ?)";
        
        $rating_stmt = mysqli_prepare($conn, $rating_query);

        foreach ($all_ratings as $section => $ratings) {
            foreach ($ratings as $statement_id => $rating) {
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
            }
        }

        // Log the action
        $log_query = "INSERT INTO user_logs (
            user_id,
            role,
            action,
            details,
            status
        ) VALUES (?, 'student', 'exam_feedback_submission', ?, 'success')";
        
        $log_details = json_encode([
            'feedback_id' => $feedback_id,
            'assignment_id' => $assignment_id,
            'exam_timetable_id' => $exam_details['id']
        ]);
        
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $log_details);
        mysqli_stmt_execute($log_stmt);

        mysqli_commit($conn);
        
        echo json_encode(['status' => 'success']);
        exit;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Exam feedback submission error: " . $e->getMessage());
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
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

// Fetch subject and exam details
$subject_query = "SELECT sa1.*, s.name AS subject_name, s.code AS subject_code,
                 ay.year_range as academic_year,
                 et.exam_date, et.exam_session, et.start_time, et.end_time,
                 et.id as exam_timetable_id
                 FROM subject_assignments sa1 
                 JOIN subjects s ON sa1.subject_id = s.id 
                 JOIN academic_years ay ON sa1.academic_year_id = ay.id
                 LEFT JOIN exam_timetable et ON et.subject_id = s.id
                    AND et.semester = sa1.semester 
                    AND et.academic_year_id = sa1.academic_year_id
                    AND et.exam_date <= CURDATE()
                    AND et.is_active = TRUE
                 WHERE sa1.id = ? 
                 AND sa1.is_active = TRUE
                 ORDER BY et.exam_date DESC 
                 LIMIT 1";

$subject_stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($subject_stmt, "i", $assignment_id);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject || is_null($subject['exam_timetable_id'])) {
    // Check if it's because there's no exam scheduled
    $check_subject_query = "SELECT sa.*, s.name AS subject_name, s.code AS subject_code,
                           ay.year_range as academic_year
                           FROM subject_assignments sa 
                           JOIN subjects s ON sa.subject_id = s.id 
                           JOIN academic_years ay ON sa.academic_year_id = ay.id
                           WHERE sa.id = ? AND sa.is_active = TRUE";
    
    $check_stmt = mysqli_prepare($conn, $check_subject_query);
    mysqli_stmt_bind_param($check_stmt, "i", $assignment_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $subject_exists = mysqli_fetch_assoc($check_result);

    if ($subject_exists) {
        // Check if there's a future exam scheduled
        $exam_check_query = "SELECT et.* 
                            FROM subject_assignments sa1 
                            JOIN subjects s ON sa1.subject_id = s.id
                            LEFT JOIN exam_timetable et ON et.subject_id = s.id
                                AND et.semester = sa1.semester 
                                AND et.academic_year_id = sa1.academic_year_id
                                AND et.exam_date > CURDATE()
                                AND et.is_active = TRUE
                            WHERE sa1.id = ?";
        
        $exam_check_stmt = mysqli_prepare($conn, $exam_check_query);
        mysqli_stmt_bind_param($exam_check_stmt, "i", $assignment_id);
        mysqli_stmt_execute($exam_check_stmt);
        $future_exam_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($exam_check_stmt));

        if ($future_exam_exists && !is_null($future_exam_exists['id'])) {
            $_SESSION['error'] = "The exam feedback form will be available after the exam date.";
        } else {
            $_SESSION['error'] = "No exam has been scheduled yet for this subject.";
        }
    } else {
        $_SESSION['error'] = "Invalid subject assignment.";
    }
    header('Location: dashboard.php?error=no_exam_scheduled');
    exit();
}

// Check if feedback already submitted
$feedback_check_query = "SELECT id FROM examination_feedback 
                        WHERE student_id = ? AND subject_assignment_id = ?";
$feedback_check_stmt = mysqli_prepare($conn, $feedback_check_query);
mysqli_stmt_bind_param($feedback_check_stmt, "ii", $user_id, $assignment_id);
mysqli_stmt_execute($feedback_check_stmt);
$feedback_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($feedback_check_stmt));

if ($feedback_exists) {
    $_SESSION['error'] = "You have already submitted feedback for this exam.";
    header('Location: dashboard.php?error=feedback_exists');
    exit();
}

// Fetch feedback statements
$statements_query = "SELECT id, statement, section 
                    FROM examination_feedback_statements 
                    WHERE is_active = TRUE 
                    ORDER BY section, id";
$statements_result = mysqli_query($conn, $statements_query);
$statements = [];
while ($row = mysqli_fetch_assoc($statements_result)) {
    $statements[$row['section']][] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Exam Feedback Form - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
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
            position: relative;
        }

        .container {
            max-width: 1200px;
            width: 100%;
            margin: 1rem auto;
            padding: clamp(0.5rem, 2vw, 2rem);
            overflow-x: hidden;
        }

        .feedback-section {
            background: #e0e5ec;
            border-radius: clamp(10px, 2vw, 15px);
            padding: clamp(0.8rem, 2vw, 2rem);
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                       -9px -9px 16px rgba(255,255,255, 0.5);
            width: 100%;
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .purpose-box {
            background: #e0e5ec;
            border-radius: clamp(10px, 2vw, 15px);
            padding: clamp(1rem, 2vw, 1.5rem);
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            width: 100%;
            overflow: hidden;
        }

        .purpose-box h3 {
            font-size: clamp(1rem, 2vw, 1.2rem);
            margin-bottom: 0.5rem;
        }

        .purpose-box p {
            font-size: clamp(0.8rem, 1.5vw, 1rem);
            margin-bottom: clamp(0.5rem, 1vw, 1rem);
        }

        .form-group {
            margin-bottom: clamp(0.8rem, 2vw, 1.5rem);
            width: 100%;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: clamp(0.3rem, 1vw, 0.5rem);
            color: #2c3e50;
            font-size: clamp(0.8rem, 1.5vw, 1rem);
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: clamp(0.7rem, 1.5vw, 1rem);
            border: none;
            border-radius: clamp(8px, 1.5vw, 15px);
            background: #e0e5ec;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            font-size: clamp(0.8rem, 1.5vw, 0.95rem);
            color: #2c3e50;
        }

        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 clamp(0.8rem, 1.5vw, 1.2rem);
            display: block;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            max-width: 100%;
        }

        .rating-table th, 
        .rating-table td {
            padding: clamp(1rem, 1.5vw, 1.5rem);
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                       -9px -9px 16px rgba(255,255,255, 0.5);
        }

        .rating-table th:first-child,
        .rating-table td:first-child {
            width: 40px;
            min-width: 40px;
            text-align: center;
        }

        .rating-table th:nth-child(2),
        .rating-table td:nth-child(2) {
            min-width: 180px;
            width: 60%;
            padding-right: 2rem;
        }

        .rating-table th:last-child,
        .rating-table td:last-child {
            width: 100%;
            min-width: auto;
            max-width: none;
        }

        .rating-options {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 12px;
            padding: 0;
            width: 100%;
            background: transparent;
            border-radius: 0;
            box-shadow: none;
        }

        .rating-option {
            flex: 0 0 auto;
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
            box-shadow: 6px 6px 12px rgb(163,177,198,0.6), 
                       -6px -6px 12px rgba(255,255,255, 0.5);
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            color: #2c3e50;
            font-size: 0.95rem;
            border: none;
            position: relative;
        }

        .rating-input:checked + .rating-label {
            background: #e0e5ec;
            color: #2c3e50;
            box-shadow: inset 3px 3px 6px rgba(163,177,198,0.6),
                       inset -3px -3px 6px rgba(255,255,255, 0.5);
            transform: scale(0.95);
        }

        .rating-label:hover {
            transform: scale(1.05);
            box-shadow: 8px 8px 16px rgb(163,177,198,0.6), 
                       -8px -8px 16px rgba(255,255,255, 0.5);
        }

        .rating-input:focus + .rating-label {
            outline: none;
            box-shadow: 8px 8px 16px rgb(163,177,198,0.6), 
                       -8px -8px 16px rgba(255,255,255, 0.5);
        }

        .section-title {
            color: #2c3e50;
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            padding-bottom: clamp(0.3rem, 1vw, 0.5rem);
            border-bottom: 2px solid #3498db;
            font-size: clamp(1rem, 2.5vw, 1.5rem);
        }

        .scale-info {
            margin-bottom: clamp(1rem, 2vw, 1.5rem);
            padding: clamp(0.7rem, 1.5vw, 1rem);
            background: #e0e5ec;
            border-radius: clamp(8px, 1.5vw, 15px);
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            color: #2c3e50;
            font-size: clamp(0.7rem, 1.5vw, 0.9rem);
        }

        .btn-submit {
            background: #3498db;
            color: white;
            padding: clamp(0.8rem, 2vw, 1rem) clamp(1.5rem, 3vw, 2rem);
            border: none;
            border-radius: clamp(25px, 5vw, 50px);
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
            margin-top: clamp(1.5rem, 3vw, 2rem);
            font-size: clamp(0.9rem, 1.8vw, 1.1rem);
            width: clamp(120px, 50%, 200px);
        }

        .btn-submit:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .declaration-checkbox {
            display: flex;
            align-items: flex-start;
            gap: clamp(0.5rem, 1.5vw, 1rem);
            margin: clamp(1.5rem, 3vw, 2rem) 0;
        }

        .declaration-checkbox input[type="checkbox"] {
            width: clamp(16px, 3vw, 20px);
            height: clamp(16px, 3vw, 20px);
            border-radius: 4px;
            margin-top: 0.25rem;
        }

        .declaration-text {
            font-size: clamp(0.8rem, 1.5vw, 0.9rem);
            color: #2c3e50;
        }

        /* Media Queries */
        @media (max-width: 768px) {
            .container {
                width: 100%;
                padding: 0.8rem;
                max-width: 100vw;
                box-sizing: border-box;
            }

            .feedback-section {
                padding: 0.8rem;
                max-width: 100%;
            }

            .rating-options {
                gap: 10px;
            }

            .rating-label {
                width: 38px;
                height: 38px;
                font-size: 0.9rem;
            }

            .declaration-checkbox {
                align-items: flex-start;
                flex-direction: column;
                gap: 0.5rem;
            }

            .declaration-checkbox input[type="checkbox"] {
                margin-left: 0;
            }
            
            .rating-table td {
                padding: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            html, body {
                max-width: 100vw;
                overflow-x: hidden;
            }
            
            .container {
                padding: 0.5rem;
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }
            
            .feedback-section {
                padding: 0.7rem;
                border-radius: 10px;
                max-width: 100%;
            }
            
            .purpose-box {
                padding: 0.8rem;
                border-radius: 10px;
                max-width: 100%;
            }
            
            .rating-options {
                gap: 8px;
            }

            .rating-label {
                width: 35px;
                height: 35px;
                font-size: 0.85rem;
            }

            .rating-table td {
                padding: 0.8rem;
            }
            
            .btn-submit {
                width: 100%;
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
                box-shadow: none;
                margin-bottom: 1rem;
                border: 1px solid #ddd;
            }
            
            .purpose-box {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            .btn-submit {
                display: none;
            }
            
            .rating-table th, 
            .rating-table td {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        .char-counter {
            text-align: right;
            font-size: clamp(0.7rem, 1.2vw, 0.8rem);
            color: #666;
            margin-top: 0.5rem;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container">
        <div class="purpose-box">
            <h3>PURPOSE</h3>
            <p>To gather feedback from students about their examination experience.</p>
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
                           value="<?php echo htmlspecialchars(($subject['subject_code'] ?? '') . ' - ' . ($subject['subject_name'] ?? '')); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="exam_date">EXAMINATION DATE</label>
                    <input type="text" 
                           id="exam_date" 
                           name="exam_date" 
                           value="<?php echo date('d M Y', strtotime($subject['exam_date'])); ?>" 
                           readonly>
                </div>
                <div class="form-group">
                    <label for="exam_session">EXAMINATION SESSION</label>
                    <input type="text" 
                           id="exam_session" 
                           name="exam_session" 
                           value="<?php echo htmlspecialchars($subject['exam_session']); ?>" 
                           readonly>
                </div>
            </div>

            <!-- Coverage and Relevance Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part A: Coverage and Relevance</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Strongly Agree
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
                        $index = 0;
                        foreach ($statements['COVERAGE_RELEVANCE'] as $statement):
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . htmlspecialchars($statement['statement']) . '</td>';
                            echo '<td class="rating-options">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'cr_' . $statement['id'] . '_' . $i;
                                echo '<div class="rating-option">';
                                echo '<input type="radio" 
                                        id="' . $inputId . '" 
                                        name="coverage_relevance[' . $statement['id'] . ']" 
                                        value="' . $i . '" 
                                        class="rating-input"
                                        required>';
                                echo '<label for="' . $inputId . '" class="rating-label">' . $i . '</label>';
                                echo '</div>';
                            }
                            echo '</td>';
                            echo '</tr>';
                            $index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Quality and Clarity Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part B: Quality and Clarity</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Strongly Agree
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
                        $index = 0;
                        foreach ($statements['QUALITY_CLARITY'] as $statement):
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . htmlspecialchars($statement['statement']) . '</td>';
                            echo '<td class="rating-options">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'qc_' . $statement['id'] . '_' . $i;
                                echo '<div class="rating-option">';
                                echo '<input type="radio" 
                                        id="' . $inputId . '" 
                                        name="quality_clarity[' . $statement['id'] . ']" 
                                        value="' . $i . '" 
                                        class="rating-input"
                                        required>';
                                echo '<label for="' . $inputId . '" class="rating-label">' . $i . '</label>';
                                echo '</div>';
                            }
                            echo '</td>';
                            echo '</tr>';
                            $index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Structure and Balance Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part C: Structure and Balance</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Strongly Agree
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
                        $index = 0;
                        foreach ($statements['STRUCTURE_BALANCE'] as $statement):
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . htmlspecialchars($statement['statement']) . '</td>';
                            echo '<td class="rating-options">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'sb_' . $statement['id'] . '_' . $i;
                                echo '<div class="rating-option">';
                                echo '<input type="radio" 
                                        id="' . $inputId . '" 
                                        name="structure_balance[' . $statement['id'] . ']" 
                                        value="' . $i . '" 
                                        class="rating-input"
                                        required>';
                                echo '<label for="' . $inputId . '" class="rating-label">' . $i . '</label>';
                                echo '</div>';
                            }
                            echo '</td>';
                            echo '</tr>';
                            $index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Application and Innovation Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part D: Application and Innovation</h2>
                <div class="scale-info">
                    1 = Strongly Disagree, 2 = Disagree, 3 = Neutral, 4 = Agree, 5 = Strongly Agree
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
                        $index = 0;
                        foreach ($statements['APPLICATION_INNOVATION'] as $statement):
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . htmlspecialchars($statement['statement']) . '</td>';
                            echo '<td class="rating-options">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'ai_' . $statement['id'] . '_' . $i;
                                echo '<div class="rating-option">';
                                echo '<input type="radio" 
                                        id="' . $inputId . '" 
                                        name="application_innovation[' . $statement['id'] . ']" 
                                        value="' . $i . '" 
                                        class="rating-input"
                                        required>';
                                echo '<label for="' . $inputId . '" class="rating-label">' . $i . '</label>';
                                echo '</div>';
                            }
                            echo '</td>';
                            echo '</tr>';
                            $index++;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Descriptive Feedback Section -->
            <div class="feedback-section">
                <h2 class="section-title">Part E: Descriptive Feedback</h2>
                <div class="form-group">
                    <label for="syllabus_coverage">1. Was the syllabus coverage adequate in the question paper? Provide specific examples.</label>
                    <textarea id="syllabus_coverage" name="syllabus_coverage" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="difficult_questions">2. Mention any question(s) you found difficult or confusing. What made it challenging?</label>
                    <textarea id="difficult_questions" name="difficult_questions" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="out_of_syllabus">3. Were there any questions that you feel were out of syllabus? If yes, list them.</label>
                    <textarea id="out_of_syllabus" name="out_of_syllabus" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="time_sufficiency">4. Was the time allotted for the examination sufficient for the type and quantity of questions asked?</label>
                    <textarea id="time_sufficiency" name="time_sufficiency" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="fairness_rating">5. How do you rate the overall fairness of the question paper? Why?</label>
                    <textarea id="fairness_rating" name="fairness_rating" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="improvements">6. Suggest improvements for future question papers.</label>
                    <textarea id="improvements" name="improvements" rows="3" required></textarea>
                </div>
                <div class="form-group">
                    <label for="additional_comments">7. Additional Comments (Optional):</label>
                    <textarea id="additional_comments" name="additional_comments" rows="3"></textarea>
                    <div class="char-counter"><span id="charCount">0</span>/1000 characters</div>
                </div>
            </div>

            <!-- Student Declaration -->
            <div class="feedback-section">
                <h2 class="section-title">Student Declaration</h2>
                <div class="declaration-checkbox">
                    <input type="checkbox" id="student_declaration" name="student_declaration" required>
                    <label for="student_declaration" class="declaration-text">
                        I hereby declare that the above feedback is provided honestly and voluntarily, 
                        intending to improve the academic standards of the institution.
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-submit">Submit Feedback</button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('feedbackForm');
            
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                console.log('Form submission started');

                let isValid = true;
                const formData = new FormData(form);
                
                // Define the sections to validate
                const sections = [
                    'coverage_relevance',
                    'quality_clarity',
                    'structure_balance',
                    'application_innovation'
                ];

                // Validate all sections
                sections.forEach(section => {
                    const radioGroups = form.querySelectorAll(`input[name^="${section}["]`);
                    if (!radioGroups.length) {
                        console.log(`No questions found for section ${section}`);
                        return;
                    }
                    
                    // Get all unique group names for this section to count total questions
                    const questionGroups = new Set();
                    radioGroups.forEach(radio => {
                        questionGroups.add(radio.name);
                    });
                    
                    // Get count of answered questions
                    const answeredQuestions = new Set();
                    radioGroups.forEach(radio => {
                        if (radio.checked) {
                            answeredQuestions.add(radio.name);
                        }
                    });

                    console.log(`${section}: ${answeredQuestions.size} answered out of ${questionGroups.size} questions`);

                    if (answeredQuestions.size < questionGroups.size) {
                        isValid = false;
                        console.error(`Incomplete section: ${section}`);
                        
                        // Highlight unanswered questions
                        questionGroups.forEach(name => {
                            const isAnswered = document.querySelector(`input[name="${name}"]:checked`);
                            if (!isAnswered) {
                                // Get the row containing this question
                                const radio = document.querySelector(`input[name="${name}"]`);
                                if (radio) {
                                    const row = radio.closest('tr');
                                    if (row) row.style.backgroundColor = '#fff3cd';
                                }
                            }
                        });
                    }
                });

                // Validate required text fields
                const requiredTextFields = [
                    'syllabus_coverage',
                    'difficult_questions',
                    'out_of_syllabus',
                    'time_sufficiency',
                    'fairness_rating',
                    'improvements'
                ];

                requiredTextFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (!field || !field.value.trim()) {
                        isValid = false;
                        console.error(`Required field empty: ${fieldId}`);
                        if (field) {
                            field.classList.add('error');
                            field.style.borderColor = 'red';
                        }
                    } else {
                        if (field) {
                            field.classList.remove('error');
                            field.style.borderColor = '';
                        }
                    }
                });

                // Validate student declaration
                if (!document.getElementById('student_declaration').checked) {
                    isValid = false;
                    alert('Please agree to the student declaration');
                }

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
            
            // Add character counter for additional comments
            const commentsTextarea = document.getElementById('additional_comments');
            const charCount = document.getElementById('charCount');
            
            if (commentsTextarea && charCount) {
                commentsTextarea.addEventListener('input', function() {
                    const chars = this.value.length;
                    charCount.textContent = chars;
                    
                    // Optional: Change color when approaching limit
                    if (chars > 900) {
                        charCount.style.color = '#e74c3c';
                    } else {
                        charCount.style.color = '#666';
                    }
                });
            }
        });
    </script>
</body>
</html> 