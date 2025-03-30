<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: index.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];

// Fetch subjects taught by the faculty
$subjects_query = "SELECT id, name FROM subjects WHERE faculty_id = ?";
$subjects_stmt = mysqli_prepare($conn, $subjects_query);
mysqli_stmt_bind_param($subjects_stmt, "i", $faculty_id);
mysqli_stmt_execute($subjects_stmt);
$subjects_result = mysqli_stmt_get_result($subjects_stmt);

// Fetch the statements
$statements = [
    "The teacher's knowledge of the subject matter",
    "Clarity of the teacher's explanations",
    "The teacher's ability to engage students",
    "Fairness in grading and evaluation",
    "Availability and helpfulness outside of class",
    "Use of relevant examples and applications",
    "Encouragement of critical thinking and discussion",
    "Organization and structure of the course",
    "Effective use of teaching aids and technology",
    "Overall effectiveness of the teacher"
];

// Fetch feedback statistics
$stats_query = "SELECT 
    s.code,
    s.name AS subject_name,
    COUNT(DISTINCT f.id) as feedback_count,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating
FROM subjects s
LEFT JOIN feedback f ON s.id = f.subject_id
WHERE s.faculty_id = ? 
AND s.is_active = TRUE
AND f.academic_year_id = ?
GROUP BY s.id
ORDER BY s.code";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "ii", 
    $_SESSION['user_id'], 
    $current_academic_year['id']
);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);

// Fetch detailed feedback
$feedback_query = "SELECT 
    f.id,
    s.code,
    s.name AS subject_name,
    fs.statement,
    fr.rating,
    f.comments,
    f.submitted_at
FROM feedback f
JOIN subjects s ON f.subject_id = s.id
JOIN feedback_ratings fr ON f.id = fr.feedback_id
JOIN feedback_statements fs ON fr.statement_id = fs.id
WHERE s.faculty_id = ?
AND f.academic_year_id = ?
ORDER BY f.submitted_at DESC";

$feedback_stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($feedback_stmt, "ii", 
    $_SESSION['user_id'], 
    $current_academic_year['id']
);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Feedback View - College Feedback System</title>
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
            justify-content: flex-start;
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

        h1, h2, h3, h4 {
            color: #2c3e50;
            margin-bottom: 15px;
        }

        .feedback-section {
            background-color: #ffffff;
            padding: 30px;
            margin-bottom: 30px;
            border-radius: 8px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
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
            text-decoration: none;
        }

        .btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .rating-bar {
            background-color: #e0e0e0;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .rating-fill {
            height: 100%;
            background-color: #3498db;
            transition: width 0.5s ease-in-out;
        }

        .comments {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
        }

        ul {
            list-style-type: none;
            padding-left: 0;
        }

        li {
            margin-bottom: 10px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .feedback-section {
                padding: 20px;
            }
        }
    </style>
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
        <h2>Feedback for Your Subjects</h2>
        <div class="feedback-overview">
            <?php while ($subject = mysqli_fetch_assoc($subjects_result)): ?>
                <div class="subject-card">
                    <h3><?php echo htmlspecialchars($subject['name']); ?></h3>
                    
                    <div class="statement-ratings">
                        <?php foreach ($statements as $stmt_id => $statement): ?>
                            <div class="rating-row">
                                <div class="statement"><?php echo htmlspecialchars($statement); ?></div>
                                <div class="rating-bar-container">
                                    <?php 
                                    $avg_rating = $subject_ratings[$subject['id']][$stmt_id] ?? 0;
                                    $percentage = ($avg_rating / 5) * 100;
                                    ?>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo $percentage; ?>%">
                                            <?php echo number_format($avg_rating, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="feedback-stats">
                        <div class="stat">
                            <span class="label">Total Feedback:</span>
                            <span class="value"><?php echo $subject_stats[$subject['id']]['count']; ?></span>
                        </div>
                        <div class="stat">
                            <span class="label">Overall Rating:</span>
                            <span class="value"><?php echo number_format($subject_stats[$subject['id']]['overall'], 2); ?>/5</span>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
        
        <a href="dashboard.php" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>
