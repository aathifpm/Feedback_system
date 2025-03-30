<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$feedback_id = isset($_GET['feedback_id']) ? intval($_GET['feedback_id']) : 0;

// Fetch feedback details with subject and faculty information
$query = "SELECT f.*, 
          s.name AS subject_name, 
          s.code AS subject_code,
          fac.name AS faculty_name,
          d.name AS department_name,
          ay.year_range AS academic_year
          FROM feedback f
          JOIN subject_assignments sa ON f.assignment_id = sa.id
          JOIN subjects s ON sa.subject_id = s.id
          JOIN faculty fac ON sa.faculty_id = fac.id
          JOIN departments d ON s.department_id = d.id
          JOIN academic_years ay ON sa.academic_year_id = ay.id
          WHERE f.id = ? AND f.student_id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $feedback_id, $user_id);
mysqli_stmt_execute($stmt);
$feedback = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$feedback) {
    header('Location: dashboard.php');
    exit();
}

// Fetch ratings by section
$ratings_query = "SELECT fr.*, fs.statement, fs.section
                 FROM feedback_ratings fr
                 JOIN feedback_statements fs ON fr.statement_id = fs.id
                 WHERE fr.feedback_id = ?
                 ORDER BY fs.section, fs.id";

$ratings_stmt = mysqli_prepare($conn, $ratings_query);
mysqli_stmt_bind_param($ratings_stmt, "i", $feedback_id);
mysqli_stmt_execute($ratings_stmt);
$ratings_result = mysqli_stmt_get_result($ratings_stmt);

$ratings_by_section = [];
while ($rating = mysqli_fetch_assoc($ratings_result)) {
    $ratings_by_section[$rating['section']][] = $rating;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Feedback - <?php echo htmlspecialchars($feedback['subject_name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .feedback-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .feedback-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .meta-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .meta-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .meta-card p {
            font-size: 1.1rem;
            font-weight: 500;
        }

        .section-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .rating-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .rating-statement {
            flex: 1;
            padding-right: 1rem;
        }

        .rating-value {
            background: var(--primary-color);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            box-shadow: var(--shadow);
        }

        .average-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 1rem;
        }

        .average-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin: 0 auto 1rem;
        }

        .average-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .average-label {
            font-size: 0.8rem;
            color: #666;
        }

        .comments-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-top: 1rem;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 500;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .rating-item {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .rating-statement {
                padding-right: 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="feedback-header">
            <h1><?php echo htmlspecialchars($feedback['subject_name']); ?> (<?php echo htmlspecialchars($feedback['subject_code']); ?>)</h1>
            <div class="feedback-meta">
                <div class="meta-card">
                    <h3>Faculty</h3>
                    <p><?php echo htmlspecialchars($feedback['faculty_name']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Department</h3>
                    <p><?php echo htmlspecialchars($feedback['department_name']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Academic Year</h3>
                    <p><?php echo htmlspecialchars($feedback['academic_year']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Submitted On</h3>
                    <p><?php echo date('F j, Y', strtotime($feedback['submitted_at'])); ?></p>
                </div>
            </div>
        </div>

        <div class="section-card">
            <h2 class="section-title">Overall Ratings</h2>
            <div class="average-card">
                <div class="average-circle">
                    <span class="average-number"><?php echo number_format($feedback['cumulative_avg'], 2); ?></span>
                    <span class="average-label">Overall Average</span>
                </div>
            </div>
            <div class="feedback-meta">
                <div class="meta-card">
                    <h3>Course Effectiveness</h3>
                    <p><?php echo number_format($feedback['course_effectiveness_avg'], 2); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Teaching Effectiveness</h3>
                    <p><?php echo number_format($feedback['teaching_effectiveness_avg'], 2); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Resources & Admin</h3>
                    <p><?php echo number_format($feedback['resources_admin_avg'], 2); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Assessment & Learning</h3>
                    <p><?php echo number_format($feedback['assessment_learning_avg'], 2); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Course Outcomes</h3>
                    <p><?php echo number_format($feedback['course_outcomes_avg'], 2); ?></p>
                </div>
            </div>
        </div>

        <?php foreach ($ratings_by_section as $section => $ratings): ?>
            <div class="section-card">
                <h2 class="section-title"><?php echo str_replace('_', ' ', $section); ?></h2>
                <?php foreach ($ratings as $rating): ?>
                    <div class="rating-item">
                        <div class="rating-statement">
                            <?php echo htmlspecialchars($rating['statement']); ?>
                        </div>
                        <div class="rating-value">
                            <?php echo $rating['rating']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($feedback['comments'])): ?>
            <div class="section-card">
                <h2 class="section-title">Additional Comments</h2>
                <div class="comments-section">
                    <?php echo nl2br(htmlspecialchars($feedback['comments'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <a href="dashboard.php" class="btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</body>
</html>
