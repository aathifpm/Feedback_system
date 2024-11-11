<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: login.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;
$section = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : null;

if (!$academic_year_id || !$year || !$semester || !$section) {
    die("Please provide academic year, year of study, semester, and section.");
}

// Fetch selected academic year
$query = "SELECT * FROM academic_years WHERE id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $academic_year_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$selected_academic_year = mysqli_fetch_assoc($result);

if (!$selected_academic_year) {
    die("Error: Invalid academic year.");
}

// Fetch feedback for the selected academic year, year of study, section, and semester
$query = "SELECT f.id, f.comments, f.created_at, s.name as subject_name,
          f.course_effectiveness_avg,
          f.teaching_effectiveness_avg,
          f.resources_admin_avg,
          f.assessment_learning_avg,
          f.course_outcomes_avg,
          f.cumulative_avg
          FROM feedback f
          JOIN subjects s ON f.subject_id = s.id
          WHERE s.faculty_id = ? 
          AND s.academic_year = ? 
          AND s.year = ? 
          AND s.semester = ? 
          AND s.section = ?
          ORDER BY f.created_at DESC";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "iiisi", $faculty_id, $academic_year_id, $year, $semester, $section);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$feedbacks = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Fetch feedback statements
$statements = [
    "Understanding of the subject matter",
    "Participation in class discussions",
    "Timely submission of assignments",
    "Quality of work",
    "Ability to apply concepts",
    "Attendance and punctuality",
    "Collaboration with peers",
    "Improvement throughout the semester",
    "Communication skills",
    "Overall performance"
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Student Feedback - <?php echo $selected_academic_year['year_range']; ?> - Panimalar Engineering College</title>
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
        <h3>Academic Year: <?php echo $selected_academic_year['year_range']; ?></h3>
        <h3>Year of Study: <?php echo $year; ?>, Semester: <?php echo $semester; ?>, Section: <?php echo $section; ?></h3>

        <?php if (empty($feedbacks)): ?>
            <div class="no-feedback">
                <p>No feedback submitted for this academic year, year of study, semester, and section yet.</p>
            </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $feedback): ?>
                <div class="feedback-card">
                    <div class="feedback-meta">
                        <h3>Feedback for <?php echo htmlspecialchars($feedback['subject_name']); ?></h3>
                        <p>Submitted on: <?php echo date('F j, Y, g:i a', strtotime($feedback['created_at'])); ?></p>
                    </div>
                    
                    <h4>Average Rating: <?php echo number_format($feedback['avg_rating'], 2); ?>/5</h4>
                    
                    <h4>Ratings:</h4>
                    <ul>
                        <?php
                        $ratings_query = "SELECT statement_id, rating FROM feedback_ratings WHERE feedback_id = ?";
                        $ratings_stmt = mysqli_prepare($conn, $ratings_query);
                        mysqli_stmt_bind_param($ratings_stmt, "i", $feedback['id']);
                        mysqli_stmt_execute($ratings_stmt);
                        $ratings_result = mysqli_stmt_get_result($ratings_stmt);
                        $ratings = mysqli_fetch_all($ratings_result, MYSQLI_ASSOC);
                        
                        foreach ($ratings as $rating):
                            $width_percentage = ($rating['rating'] / 5) * 100;
                        ?>
                            <li>
                                <?php echo htmlspecialchars($statements[$rating['statement_id']]); ?>: <?php echo $rating['rating']; ?>/5
                                <div class="rating-bar">
                                    <div class="rating-fill" style="width: <?php echo $width_percentage; ?>%;"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    
                    <h4>Comments:</h4>
                    <div class="comments">
                        <p><?php echo nl2br(htmlspecialchars($feedback['comments'])); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <a href="dashboard.php" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>