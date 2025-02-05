<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get subject ID and assignment ID from request
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

if (!$subject_id || !$assignment_id) {
    header('Location: manage_subjects.php');
    exit();
}

// Get subject details with specific assignment
$subject_query = "SELECT 
    s.code,
    s.name,
    sa.year,
    sa.semester,
    sa.section COLLATE utf8mb4_unicode_ci as section,
    d.name as department_name,
    f.name as faculty_name,
    ay.year_range as academic_year
FROM subjects s
JOIN departments d ON s.department_id = d.id
JOIN subject_assignments sa ON s.id = sa.subject_id
JOIN faculty f ON sa.faculty_id = f.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
WHERE s.id = ? 
AND sa.id = ?
AND sa.is_active = TRUE";

$stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($stmt, "ii", $subject_id, $assignment_id);
mysqli_stmt_execute($stmt);
$subject = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$subject) {
    header('Location: manage_subjects.php');
    exit();
}

// Get feedback statistics for specific assignment
$stats_query = "SELECT 
    COUNT(DISTINCT CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.id END) as total_feedback,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.course_effectiveness_avg END), 2) as course_effectiveness,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.teaching_effectiveness_avg END), 2) as teaching_effectiveness,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.resources_admin_avg END), 2) as resources_admin,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.assessment_learning_avg END), 2) as assessment_learning,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.course_outcomes_avg END), 2) as course_outcomes,
    ROUND(AVG(CASE WHEN s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci THEN f.cumulative_avg END), 2) as overall_rating
FROM feedback f
JOIN subjects sub ON f.subject_id = sub.id
JOIN subject_assignments sa ON sub.id = sa.subject_id AND sa.academic_year_id = f.academic_year_id
JOIN students s ON f.student_id = s.id
WHERE f.subject_id = ? 
AND sa.id = ?";

$stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stmt, "ii", $subject_id, $assignment_id);
mysqli_stmt_execute($stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Get detailed feedback for specific assignment
$feedback_query = "SELECT 
    f.*,
    s.name as student_name,
    s.roll_number,
    s.section COLLATE utf8mb4_unicode_ci as student_section
FROM feedback f
JOIN students s ON f.student_id = s.id
JOIN subject_assignments sa ON f.subject_id = sa.subject_id AND sa.academic_year_id = f.academic_year_id
WHERE f.subject_id = ?
AND sa.id = ?
AND s.section COLLATE utf8mb4_unicode_ci = sa.section COLLATE utf8mb4_unicode_ci
ORDER BY f.submitted_at DESC";

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "ii", $subject_id, $assignment_id);
mysqli_stmt_execute($stmt);
$feedback_result = mysqli_stmt_get_result($stmt);

// Get feedback statements
$statements_query = "SELECT id, statement, section FROM feedback_statements WHERE is_active = TRUE ORDER BY section, id";
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Feedback - <?php echo htmlspecialchars($subject['code']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        /* Copy the CSS from manage_subjects.php and add/modify as needed */
        :root {
            --primary-color: #2ecc71;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
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
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .btn-back {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
        }

        .btn-back:hover {
            transform: translateY(-2px);
        }

        .subject-info {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .info-item {
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .info-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        .feedback-list {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .feedback-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 1rem;
        }

        .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .student-info {
            font-size: 0.9rem;
            color: #666;
        }

        .rating-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .rating-item {
            padding: 0.8rem;
            background: var(--bg-color);
            border-radius: 8px;
            box-shadow: var(--inner-shadow);
        }

        .rating-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.3rem;
        }

        .rating-value {
            font-size: 1.1rem;
            color: var(--primary-color);
            font-weight: 500;
        }

        .comments {
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 8px;
            box-shadow: var(--inner-shadow);
            margin-top: 1rem;
        }

        .comments-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .comments-text {
            font-size: 0.95rem;
            color: var(--text-color);
            line-height: 1.5;
        }

        @media (max-width: 768px) {
            .info-grid,
            .stats-grid,
            .rating-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Subject Feedback</h1>
            <a href="manage_subjects.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back to Subjects
            </a>
        </div>

        <div class="subject-info">
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Subject Code</div>
                    <div class="info-value"><?php echo htmlspecialchars($subject['code']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Subject Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($subject['name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Department</div>
                    <div class="info-value"><?php echo htmlspecialchars($subject['department_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Faculty</div>
                    <div class="info-value"><?php echo htmlspecialchars($subject['faculty_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Academic Year</div>
                    <div class="info-value"><?php echo htmlspecialchars($subject['academic_year']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Year & Semester</div>
                    <div class="info-value">Year <?php echo $subject['year']; ?> - Semester <?php echo $subject['semester']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Section</div>
                    <div class="info-value">Section <?php echo htmlspecialchars($subject['section']); ?></div>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_feedback']; ?></div>
                <div class="stat-label">Total Feedback</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['overall_rating'] ?? 'N/A'; ?></div>
                <div class="stat-label">Overall Rating</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['course_effectiveness'] ?? 'N/A'; ?></div>
                <div class="stat-label">Course Effectiveness</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['teaching_effectiveness'] ?? 'N/A'; ?></div>
                <div class="stat-label">Teaching Effectiveness</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['resources_admin'] ?? 'N/A'; ?></div>
                <div class="stat-label">Resources & Admin</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['assessment_learning'] ?? 'N/A'; ?></div>
                <div class="stat-label">Assessment & Learning</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['course_outcomes'] ?? 'N/A'; ?></div>
                <div class="stat-label">Course Outcomes</div>
            </div>
        </div>

        <div class="feedback-list">
            <h2>Detailed Feedback</h2>
            <?php while ($feedback = mysqli_fetch_assoc($feedback_result)): ?>
                <div class="feedback-item">
                    <div class="feedback-header">
                        <div class="student-info">
                            <strong><?php echo htmlspecialchars($feedback['student_name']); ?></strong>
                            (<?php echo htmlspecialchars($feedback['roll_number']); ?>)
                        </div>
                        <div class="submission-date">
                            <?php echo date('M d, Y h:i A', strtotime($feedback['submitted_at'])); ?>
                        </div>
                    </div>

                    <div class="rating-grid">
                        <div class="rating-item">
                            <div class="rating-label">Course Effectiveness</div>
                            <div class="rating-value"><?php echo $feedback['course_effectiveness_avg']; ?></div>
                        </div>
                        <div class="rating-item">
                            <div class="rating-label">Teaching Effectiveness</div>
                            <div class="rating-value"><?php echo $feedback['teaching_effectiveness_avg']; ?></div>
                        </div>
                        <div class="rating-item">
                            <div class="rating-label">Resources & Admin</div>
                            <div class="rating-value"><?php echo $feedback['resources_admin_avg']; ?></div>
                        </div>
                        <div class="rating-item">
                            <div class="rating-label">Assessment & Learning</div>
                            <div class="rating-value"><?php echo $feedback['assessment_learning_avg']; ?></div>
                        </div>
                        <div class="rating-item">
                            <div class="rating-label">Course Outcomes</div>
                            <div class="rating-value"><?php echo $feedback['course_outcomes_avg']; ?></div>
                        </div>
                        <div class="rating-item">
                            <div class="rating-label">Overall Rating</div>
                            <div class="rating-value"><?php echo $feedback['cumulative_avg']; ?></div>
                        </div>
                    </div>

                    <?php if (!empty($feedback['comments'])): ?>
                        <div class="comments">
                            <div class="comments-label">Additional Comments:</div>
                            <div class="comments-text"><?php echo nl2br(htmlspecialchars($feedback['comments'])); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <script>
        // Add any necessary JavaScript here
    </script>
</body>
</html> 