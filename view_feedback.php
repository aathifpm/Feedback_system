<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'faculty' && $_SESSION['role'] != 'hod')) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

// Fetch feedback for the subject or department
if ($role == 'faculty') {
    $query = "SELECT fr.rating, f.comments, s.code, s.name AS subject_name, 
              st.name AS student_name, fs.statement AS feedback_statement,
              f.submitted_at, fr.section,
              f.course_effectiveness_avg,
              f.teaching_effectiveness_avg,
              f.resources_admin_avg,
              f.assessment_learning_avg,
              f.course_outcomes_avg,
              f.cumulative_avg
              FROM feedback f
              JOIN subjects s ON f.subject_id = s.id
              JOIN students st ON f.student_id = st.id
              JOIN feedback_ratings fr ON fr.feedback_id = f.id
              JOIN feedback_statements fs ON fr.statement_id = fs.id
              WHERE f.subject_id = ? 
              AND s.faculty_id = ?
              AND s.is_active = TRUE
              AND f.academic_year_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "iii", $subject_id, $user_id, $current_academic_year['id']);
} else { // HOD
    $query = "SELECT fr.rating, f.comments, s.code, s.name AS subject_name, 
              st.name AS student_name, fc.name AS faculty_name,
              fs.statement AS feedback_statement, f.submitted_at
              FROM feedback f
              JOIN subjects s ON f.subject_id = s.id
              JOIN students st ON f.student_id = st.id
              JOIN faculty fc ON s.faculty_id = fc.id
              JOIN feedback_ratings fr ON fr.feedback_id = f.id
              JOIN feedback_statements fs ON fr.statement_id = fs.id
              WHERE s.department_id = (SELECT department_id FROM hods WHERE id = ?)
              AND s.is_active = TRUE
              AND f.academic_year_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $current_academic_year['id']);
}

// Calculate average rating
$avg_query = "SELECT AVG(rating) AS avg_rating FROM feedback WHERE subject_id = ?";
$avg_stmt = mysqli_prepare($conn, $avg_query);
mysqli_stmt_bind_param($avg_stmt, "i", $subject_id);
mysqli_stmt_execute($avg_stmt);
$avg_result = mysqli_stmt_get_result($avg_stmt);
$avg_row = mysqli_fetch_assoc($avg_result);
$avg_rating = number_format($avg_row['avg_rating'], 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Feedback - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #003366;
            --secondary-color: #FFD700;
            --text-color: #333;
            --background-color: #f4f4f4;
            --card-background: #ffffff;
            --border-color: #ddd;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Roboto', sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-color);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background-color: var(--primary-color);
            color: white;
            padding: 20px 0;
            text-align: center;
        }

        .logo {
            max-width: 150px;
            height: auto;
            margin-bottom: 10px;
        }

        h1, h2, h3 {
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: var(--card-background);
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 12px;
            border: 1px solid var(--border-color);
            text-align: left;
        }

        th {
            background-color: var(--primary-color);
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f2f2f2;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: var(--secondary-color);
            color: var(--primary-color);
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s, transform 0.3s;
            font-weight: 500;
            margin-top: 20px;
        }

        .btn:hover {
            background-color: #e6c200;
            transform: translateY(-2px);
        }

        .rating {
            display: flex;
            align-items: center;
        }

        .star {
            color: var(--secondary-color);
            font-size: 20px;
            margin-right: 2px;
        }

        .avg-rating {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .stat-card {
            background: var(--card-background);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .stat-card h4 {
            margin-bottom: 1rem;
            color: var(--primary-color);
        }

        .stat-card.overall {
            grid-column: 1 / -1;
            background: var(--primary-color);
            color: white;
        }

        .stat-card.overall h4 {
            color: white;
        }

        .rating-bar {
            background: #eee;
            height: 24px;
            border-radius: 12px;
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-color);
            font-weight: 500;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
            <h1>Panimalar Engineering College</h1>
            <h2>(An Autonomous Institution)</h2>
            <h3>Affiliated to Anna University, Chennai</h3>
            <h3 style="color: var(--secondary-color); margin-top: 10px;">College Feedback Portal</h3>
        </div>
    </div>
    <div class="container">
        <h1>View Feedback</h1>
        <div class="avg-rating">
            Average Rating: <?php echo $avg_rating; ?> / 5
            <div class="rating">
                <?php
                $full_stars = floor($avg_rating);
                $half_star = $avg_rating - $full_stars >= 0.5;
                for ($i = 1; $i <= 5; $i++) {
                    if ($i <= $full_stars) {
                        echo '<span class="star">★</span>';
                    } elseif ($i == $full_stars + 1 && $half_star) {
                        echo '<span class="star">½</span>';
                    } else {
                        echo '<span class="star">☆</span>';
                    }
                }
                ?>
            </div>
        </div>
        
        <div class="feedback-container">
            <h2>Feedback Summary</h2>
            
            <!-- Overall Statistics -->
            <div class="feedback-summary">
                <h3>Overall Performance</h3>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h4>Course Effectiveness</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['course_effectiveness_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['course_effectiveness_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h4>Teaching Effectiveness</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['teaching_effectiveness_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['teaching_effectiveness_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h4>Resources & Administration</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['resources_admin_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['resources_admin_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h4>Assessment & Learning</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['assessment_learning_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['assessment_learning_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <h4>Course Outcomes</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['course_outcomes_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['course_outcomes_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                    
                    <div class="stat-card overall">
                        <h4>Cumulative Average</h4>
                        <div class="rating-bar">
                            <div class="rating-fill" style="width: <?php echo ($feedback['cumulative_avg'] * 20); ?>%;">
                                <?php echo number_format($feedback['cumulative_avg'], 2); ?>/5
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <a href="dashboard.php" class="btn">Back to Dashboard</a>
    </div>
</body>
</html>
