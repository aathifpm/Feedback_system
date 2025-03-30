<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'faculty' && $_SESSION['role'] != 'admin')) {
    header('Location: index.php');
    exit();
}

// Get student ID from request
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

if (!$student_id) {
    header('Location: admin/manage_students.php');
    exit();
}

// Fetch student details
$student_query = "SELECT s.*, d.name as department_name, b.batch_name 
                 FROM students s
                 JOIN departments d ON s.department_id = d.id
                 JOIN batch_years b ON s.batch_id = b.id
                 WHERE s.id = ?";
$stmt = mysqli_prepare($conn, $student_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$student) {
    header('Location: admin/manage_students.php');
    exit();
}

// Get feedback statistics for the student
$stats_query = "SELECT 
    s.code,
    s.name as subject_name,
    sa.year,
    sa.semester,
    sa.section,
    f.id as feedback_id,
    f.comments,
    f.course_effectiveness_avg,
    f.teaching_effectiveness_avg,
    f.resources_admin_avg,
    f.assessment_learning_avg,
    f.course_outcomes_avg,
    f.cumulative_avg,
    f.submitted_at,
    fac.name as faculty_name
FROM feedback f
JOIN subjects s ON f.subject_id = s.id
JOIN subject_assignments sa ON s.id = sa.subject_id AND sa.academic_year_id = f.academic_year_id
JOIN faculty fac ON sa.faculty_id = fac.id
WHERE f.student_id = ?
AND sa.is_active = TRUE
AND sa.section = ?
ORDER BY f.submitted_at DESC";

$stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stmt, "is", $student_id, $student['section']);
mysqli_stmt_execute($stmt);
$feedbacks = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Fetch all feedback statements once
$statements_query = "SELECT id, statement FROM feedback_statements WHERE is_active = TRUE ORDER BY id";
$stmt = mysqli_prepare($conn, $statements_query);
mysqli_stmt_execute($stmt);
$statements_result = mysqli_stmt_get_result($stmt);
$statements = [];
while ($row = mysqli_fetch_assoc($statements_result)) {
    $statements[$row['id']] = $row['statement'];
}

// Get detailed feedback ratings
$ratings_query = "SELECT 
    fr.rating,
    fs.statement,
    fs.section as feedback_section,
    f.subject_id,
    sa.year,
    sa.semester,
    sa.section
FROM feedback f
JOIN feedback_ratings fr ON f.id = fr.feedback_id
JOIN feedback_statements fs ON fr.statement_id = fs.id
JOIN subject_assignments sa ON f.subject_id = sa.subject_id AND sa.academic_year_id = f.academic_year_id
WHERE f.student_id = ?
AND sa.is_active = TRUE
AND sa.section = ?
ORDER BY f.submitted_at DESC, fs.section, fs.id";

$stmt = mysqli_prepare($conn, $ratings_query);
mysqli_stmt_bind_param($stmt, "is", $student_id, $student['section']);
mysqli_stmt_execute($stmt);
$ratings = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Organize ratings by subject and section
$organized_ratings = [];
foreach ($ratings as $rating) {
    $subject_id = $rating['subject_id'];
    $section = $rating['feedback_section'];
    if (!isset($organized_ratings[$subject_id])) {
        $organized_ratings[$subject_id] = [];
    }
    if (!isset($organized_ratings[$subject_id][$section])) {
        $organized_ratings[$subject_id][$section] = [];
    }
    $organized_ratings[$subject_id][$section][] = $rating;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Student Feedback - <?php echo $student['name']; ?> - Panimalar Engineering College</title>
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
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            width: 100%;
            padding: 1rem 2rem;
            background: #e0e5ec;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
        }

        .logo {
            width: 80px;
            height: auto;
            margin-right: 1rem;
        }

        .college-info {
            flex-grow: 1;
        }

        .college-info h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .college-info p {
            font-size: 0.8rem;
            color: #34495e;
        }

        .container {
            max-width: 1200px;
            width: 90%;
            margin: 2rem auto;
            padding: 2rem;
            background: #e0e5ec;
            border-radius: 20px;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
        }

        h2, h3 {
            color: #2c3e50;
            margin-bottom: 1rem;
        }

        .feedback-card {
            background: #e0e5ec;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .feedback-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .feedback-meta p {
            font-size: 0.9em;
            color: #34495e;
        }

        ul {
            list-style-type: none;
            padding-left: 0;
        }

        li {
            margin-bottom: 10px;
        }

        .rating-bar {
            background-color: #d1d9e6;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
            box-shadow: inset 3px 3px 6px #b8b9be, inset -3px -3px 6px #fff;
        }

        .rating-fill {
            height: 100%;
            background-color: #3498db;
            transition: width 0.5s ease-in-out;
        }

        .comments {
            background-color: #f0f3f6;
            padding: 15px;
            border-radius: 10px;
            margin-top: 10px;
            box-shadow: inset 3px 3px 6px #b8b9be, inset -3px -3px 6px #fff;
        }

        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: #fff;
            background: #3498db;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: #2980b9;
        }

        .no-feedback {
            text-align: center;
            padding: 40px;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
        </div>
    </div>
    <div class="container">
        <h2>View Student Feedback</h2>
        <h3>Student: <?php echo $student['name']; ?></h3>
        <h3>Department: <?php echo $student['department_name']; ?></h3>
        <h3>Batch: <?php echo $student['batch_name']; ?></h3>

        <?php if (empty($feedbacks)): ?>
            <div class="no-feedback">
                <p>No feedback submitted for this student yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $feedback): ?>
                <div class="feedback-card">
                    <div class="feedback-meta">
                        <h3>Feedback for <?php echo htmlspecialchars($feedback['subject_name']); ?></h3>
                        <p>Submitted on: <?php echo date('F j, Y, g:i a', strtotime($feedback['submitted_at'])); ?></p>
                    </div>
                    
                    <h4>Average Rating: <?php echo number_format($feedback['cumulative_avg'], 2); ?>/5</h4>
                    
                    <h4>Ratings:</h4>
                    <ul>
                        <?php
                        $ratings_query = "SELECT fr.statement_id, fr.rating, fs.statement 
                                        FROM feedback_ratings fr
                                        JOIN feedback_statements fs ON fr.statement_id = fs.id
                                        WHERE fr.feedback_id = ?
                                        ORDER BY fs.section, fs.id";
                        $ratings_stmt = mysqli_prepare($conn, $ratings_query);
                        mysqli_stmt_bind_param($ratings_stmt, "i", $feedback['feedback_id']);
                        mysqli_stmt_execute($ratings_stmt);
                        $ratings_result = mysqli_stmt_get_result($ratings_stmt);
                        
                        while ($rating = mysqli_fetch_assoc($ratings_result)):
                            $width_percentage = ($rating['rating'] / 5) * 100;
                        ?>
                            <li>
                                <?php echo htmlspecialchars($rating['statement']); ?>: <?php echo $rating['rating']; ?>/5
                                <div class="rating-bar">
                                    <div class="rating-fill" style="width: <?php echo $width_percentage; ?>%;"></div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                    
                    <h4>Comments:</h4>
                    <div class="comments">
                        <?php if (!empty($feedback['comments'])): ?>
                            <p><?php echo nl2br(htmlspecialchars($feedback['comments'])); ?></p>
                        <?php else: ?>
                            <p><em>No comments provided</em></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <a href="dashboard.php" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>