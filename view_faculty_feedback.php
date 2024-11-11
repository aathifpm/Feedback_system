<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods')) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['faculty_id'])) {
    die("Error: No faculty selected.");
}

$faculty_id = intval($_GET['faculty_id']);

// Fetch faculty details
$faculty_query = "SELECT f.name, d.name AS department_name 
                  FROM faculty f
                  JOIN departments d ON f.department_id = d.id
                  WHERE f.id = ?";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
$faculty_data = mysqli_fetch_assoc($faculty_result);

if (!$faculty_data) {
    die("Error: Invalid faculty ID.");
}

// Fetch subjects taught by the faculty
$subjects_query = "SELECT id, name FROM subjects WHERE faculty_id = ?";
$subjects_stmt = mysqli_prepare($conn, $subjects_query);
mysqli_stmt_bind_param($subjects_stmt, "i", $faculty_id);
mysqli_stmt_execute($subjects_stmt);
$subjects_result = mysqli_stmt_get_result($subjects_stmt);

// Fetch the statements (assuming you have these defined somewhere)
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Feedback View - Panimalar Engineering College Feedback System</title>
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

        .feedback-section {
            background: #e0e5ec;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        ul {
            list-style-type: none;
        }

        li {
            margin-bottom: 15px;
        }

        .rating-bar {
            background-color: #d1d9e6;
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
            margin-top: 5px;
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
            margin-top: 15px;
        }

        .subject-card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .subject-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .feedback-count {
            background: var(--primary-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .analysis-card {
            text-align: center;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .rating-circle, .rating-big-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem auto;
            font-size: 1.2rem;
            font-weight: bold;
            position: relative;
        }

        .rating-big-circle {
            width: 120px;
            height: 120px;
            font-size: 1.8rem;
        }

        .overall-rating {
            text-align: center;
            padding: 2rem;
            background: #f8f9fa;
            border-radius: 8px;
            margin-top: 2rem;
        }

        .rating-range {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 1rem;
            color: #666;
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
        <h2>Feedback for <?php echo htmlspecialchars($faculty_data['name']); ?></h2>
        <p>Department: <?php echo htmlspecialchars($faculty_data['department_name']); ?></p>

        <?php while ($subject = mysqli_fetch_assoc($subjects_result)): ?>
            <div class="feedback-section">
                <h3><?php echo htmlspecialchars($subject['name']); ?></h3>
                
                <h4>Average Ratings:</h4>
                <ul>
                    <?php
                    $avg_query = "SELECT fr.statement_id, AVG(fr.rating) AS avg_rating
                                  FROM feedback_ratings fr
                                  JOIN feedback f ON fr.feedback_id = f.id
                                  WHERE f.subject_id = ?
                                  GROUP BY fr.statement_id";
                    $avg_stmt = mysqli_prepare($conn, $avg_query);
                    mysqli_stmt_bind_param($avg_stmt, "i", $subject['id']);
                    mysqli_stmt_execute($avg_stmt);
                    $avg_result = mysqli_stmt_get_result($avg_stmt);
                    
                    while ($avg = mysqli_fetch_assoc($avg_result)):
                        $avg_rating = number_format($avg['avg_rating'], 2);
                        $width_percentage = ($avg_rating / 5) * 100;
                    ?>
                        <li>
                            <?php echo htmlspecialchars($statements[$avg['statement_id']]); ?>: <?php echo $avg_rating; ?>/5
                            <div class="rating-bar">
                                <div class="rating-fill" style="width: <?php echo $width_percentage; ?>%;"></div>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
                
                <h4>Comments:</h4>
                <div class="comments">
                    <?php
                    $comments_query = "SELECT comments FROM feedback WHERE subject_id = ?";
                    $comments_stmt = mysqli_prepare($conn, $comments_query);
                    mysqli_stmt_bind_param($comments_stmt, "i", $subject['id']);
                    mysqli_stmt_execute($comments_stmt);
                    $comments_result = mysqli_stmt_get_result($comments_stmt);
                    
                    while ($comment = mysqli_fetch_assoc($comments_result)):
                    ?>
                        <p><?php echo nl2br(htmlspecialchars($comment['comments'])); ?></p>
                    <?php endwhile; ?>
                </div>
            </div>
        <?php endwhile; ?>

        <a href="dashboard.php" class="btn">Back to Dashboard</a>
    </div>

    <div class="faculty-feedback-container">
        <h2>Feedback Analysis for <?php echo htmlspecialchars($faculty_data['name']); ?></h2>
        
        <div class="feedback-container">
            <?php while ($subject = mysqli_fetch_assoc($stats_result)): ?>
                <div class="subject-card">
                    <div class="subject-header">
                        <h3><?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['subject_name']); ?></h3>
                        <span class="feedback-count">
                            <?php echo $subject['feedback_count']; ?> Responses
                        </span>
                    </div>

                    <div class="section-analysis">
                        <div class="analysis-grid">
                            <!-- Section-wise ratings -->
                            <?php
                            $sections = [
                                'Course Effectiveness' => $subject['course_effectiveness'],
                                'Teaching Effectiveness' => $subject['teaching_effectiveness'],
                                'Resources & Admin' => $subject['resources_admin'],
                                'Assessment & Learning' => $subject['assessment_learning'],
                                'Course Outcomes' => $subject['course_outcomes']
                            ];
                            
                            foreach ($sections as $title => $rating):
                            ?>
                                <div class="analysis-card">
                                    <h4><?php echo $title; ?></h4>
                                    <div class="rating-circle" data-rating="<?php echo $rating; ?>">
                                        <?php echo number_format($rating, 2); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="overall-rating">
                            <h4>Overall Performance</h4>
                            <div class="rating-summary">
                                <div class="rating-big-circle" data-rating="<?php echo $subject['overall_avg']; ?>">
                                    <?php echo number_format($subject['overall_avg'], 2); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize rating circles
        document.querySelectorAll('.rating-circle, .rating-big-circle').forEach(circle => {
            const rating = parseFloat(circle.dataset.rating);
            const percentage = (rating / 5) * 100;
            circle.style.background = `conic-gradient(var(--primary-color) ${percentage}%, #eee ${percentage}% 100%)`;
        });
    });
    </script>
</body>
</html>