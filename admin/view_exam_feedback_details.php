<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Check if feedback ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: view_exam_feedbacks.php');
    exit();
}

$feedback_id = $_GET['id'];

// Get feedback details
$query = "SELECT 
    ef.*,
    st.name as student_name,
    st.roll_number,
    s.name as subject_name,
    s.code as subject_code,
    d.name as department_name,
    et.semester,
    et.exam_date,
    et.exam_session,
    et.start_time,
    et.end_time,
    ay.year_range as academic_year
FROM examination_feedback ef
JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN departments d ON s.department_id = d.id
JOIN students st ON ef.student_id = st.id
JOIN exam_timetable et ON ef.exam_timetable_id = et.id
JOIN academic_years ay ON et.academic_year_id = ay.id
WHERE ef.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $feedback_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$feedback = mysqli_fetch_assoc($result);

if (!$feedback) {
    header('Location: view_exam_feedbacks.php');
    exit();
}

// Get individual ratings
$ratings_query = "SELECT 
    efs.statement,
    efs.section,
    efr.rating
FROM examination_feedback_ratings efr
JOIN examination_feedback_statements efs ON efr.statement_id = efs.id
WHERE efr.feedback_id = ?
ORDER BY efs.section, efs.id";

$ratings_stmt = mysqli_prepare($conn, $ratings_query);
mysqli_stmt_bind_param($ratings_stmt, "i", $feedback_id);
mysqli_stmt_execute($ratings_stmt);
$ratings_result = mysqli_stmt_get_result($ratings_stmt);
$ratings = mysqli_fetch_all($ratings_result, MYSQLI_ASSOC);

// Group ratings by section
$ratings_by_section = [];
foreach ($ratings as $rating) {
    $ratings_by_section[$rating['section']][] = $rating;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Feedback Details - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --header-height: 90px;
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
            padding-top: var(--header-height);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px; /* Add margin for fixed sidebar */
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
            }
        }

        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .btn {
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

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .info-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .info-card h3 {
            color: var(--text-color);
            font-size: 1.2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
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
            margin-bottom: 0.25rem;
        }

        .info-value {
            font-size: 1rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .rating-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .rating-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .rating-item {
            text-align: center;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .rating-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .rating-label {
            font-size: 0.9rem;
            color: #666;
        }

        .rating-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .rating-excellent { background-color: #28a745; color: white; }
        .rating-good { background-color: #17a2b8; color: white; }
        .rating-average { background-color: #ffc107; color: black; }
        .rating-poor { background-color: #dc3545; color: white; }

        .section-title {
            font-size: 1.1rem;
            color: var(--text-color);
            margin: 1.5rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .statement-item {
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .statement-text {
            flex: 1;
            margin-right: 1rem;
        }

        .comments-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .comment-item {
            margin-bottom: 1.5rem;
        }

        .comment-item:last-child {
            margin-bottom: 0;
        }

        .comment-title {
            font-size: 1rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .comment-text {
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            line-height: 1.5;
        }

        .declaration-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .declaration-text {
            color: var(--text-color);
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .declaration-details {
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .declaration-details p {
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .declaration-details p:last-child {
            margin-bottom: 0;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Exam Feedback Details</h1>
            <a href="view_exam_feedbacks.php" class="btn">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <!-- Basic Information -->
        <div class="info-card">
            <h3>Basic Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Student Name</div>
                    <div class="info-value"><?php echo htmlspecialchars($feedback['student_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Roll Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($feedback['roll_number']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Subject</div>
                    <div class="info-value">
                        <?php echo htmlspecialchars($feedback['subject_name']); ?>
                        (<?php echo htmlspecialchars($feedback['subject_code']); ?>)
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Department</div>
                    <div class="info-value"><?php echo htmlspecialchars($feedback['department_name']); ?></div>
                </div>
            </div>
        </div>

        <!-- Exam Details -->
        <div class="info-card">
            <h3>Exam Details</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Academic Year</div>
                    <div class="info-value"><?php echo htmlspecialchars($feedback['academic_year']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Semester</div>
                    <div class="info-value"><?php echo $feedback['semester']; ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Exam Date</div>
                    <div class="info-value"><?php echo date('d M Y', strtotime($feedback['exam_date'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Exam Time</div>
                    <div class="info-value">
                        <?php echo date('h:i A', strtotime($feedback['start_time'])); ?> - 
                        <?php echo date('h:i A', strtotime($feedback['end_time'])); ?>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-label">Session</div>
                    <div class="info-value"><?php echo htmlspecialchars($feedback['exam_session']); ?></div>
                </div>
            </div>
        </div>

        <!-- Overall Ratings -->
        <div class="rating-card">
            <h3>Overall Ratings</h3>
            <div class="rating-grid">
                <div class="rating-item">
                    <div class="rating-value"><?php echo number_format($feedback['coverage_relevance_avg'], 2); ?></div>
                    <div class="rating-label">Coverage & Relevance</div>
                    <span class="rating-badge rating-<?php echo getRatingClass($feedback['coverage_relevance_avg']); ?>">
                        <?php echo getRatingText($feedback['coverage_relevance_avg']); ?>
                    </span>
                </div>
                <div class="rating-item">
                    <div class="rating-value"><?php echo number_format($feedback['quality_clarity_avg'], 2); ?></div>
                    <div class="rating-label">Quality & Clarity</div>
                    <span class="rating-badge rating-<?php echo getRatingClass($feedback['quality_clarity_avg']); ?>">
                        <?php echo getRatingText($feedback['quality_clarity_avg']); ?>
                    </span>
                </div>
                <div class="rating-item">
                    <div class="rating-value"><?php echo number_format($feedback['structure_balance_avg'], 2); ?></div>
                    <div class="rating-label">Structure & Balance</div>
                    <span class="rating-badge rating-<?php echo getRatingClass($feedback['structure_balance_avg']); ?>">
                        <?php echo getRatingText($feedback['structure_balance_avg']); ?>
                    </span>
                </div>
                <div class="rating-item">
                    <div class="rating-value"><?php echo number_format($feedback['application_innovation_avg'], 2); ?></div>
                    <div class="rating-label">Application & Innovation</div>
                    <span class="rating-badge rating-<?php echo getRatingClass($feedback['application_innovation_avg']); ?>">
                        <?php echo getRatingText($feedback['application_innovation_avg']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Detailed Ratings -->
        <div class="rating-card">
            <h3>Detailed Ratings</h3>
            <?php foreach ($ratings_by_section as $section => $section_ratings): ?>
                <h4 class="section-title"><?php echo htmlspecialchars($section); ?></h4>
                <?php foreach ($section_ratings as $rating): ?>
                    <div class="statement-item">
                        <div class="statement-text"><?php echo htmlspecialchars($rating['statement']); ?></div>
                        <span class="rating-badge rating-<?php echo getRatingClass($rating['rating']); ?>">
                            <?php echo number_format($rating['rating'], 2); ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <!-- Additional Comments -->
        <div class="comments-section">
            <h3>Descriptive Feedback</h3>
            <div class="comment-item">
                <div class="comment-title">Syllabus Coverage</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['syllabus_coverage']) ? nl2br(htmlspecialchars($feedback['syllabus_coverage'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <div class="comment-item">
                <div class="comment-title">Difficult Questions</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['difficult_questions']) ? nl2br(htmlspecialchars($feedback['difficult_questions'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <div class="comment-item">
                <div class="comment-title">Out of Syllabus Questions</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['out_of_syllabus']) ? nl2br(htmlspecialchars($feedback['out_of_syllabus'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <div class="comment-item">
                <div class="comment-title">Time Sufficiency</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['time_sufficiency']) ? nl2br(htmlspecialchars($feedback['time_sufficiency'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <div class="comment-item">
                <div class="comment-title">Fairness Rating Comments</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['fairness_rating']) ? nl2br(htmlspecialchars($feedback['fairness_rating'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <div class="comment-item">
                <div class="comment-title">Suggested Improvements</div>
                <div class="comment-text">
                    <?php echo !empty($feedback['improvements']) ? nl2br(htmlspecialchars($feedback['improvements'])) : '<em>No feedback provided</em>'; ?>
                </div>
            </div>

            <?php if (!empty($feedback['additional_comments'])): ?>
            <div class="comment-item">
                <div class="comment-title">Additional Comments</div>
                <div class="comment-text">
                    <?php echo nl2br(htmlspecialchars($feedback['additional_comments'])); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Student Declaration -->
        <div class="declaration-section">
            <h3>Student Declaration</h3>
            <p class="declaration-text">
                I hereby declare that the feedback provided is based on my genuine experience and understanding of the examination process. I confirm that my responses are honest and reflect my true assessment of the examination.
            </p>
            <div class="declaration-details">
                <p><strong>Submitted by:</strong> <?php echo htmlspecialchars($feedback['student_name']); ?></p>
                <p><strong>Roll Number:</strong> <?php echo htmlspecialchars($feedback['roll_number']); ?></p>
                <p><strong>Date & Time:</strong> <?php echo date('d M Y H:i', strtotime($feedback['submitted_at'])); ?></p>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add active class to current nav link
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });
    </script>
</body>
</html>

<?php
function getRatingClass($rating) {
    if ($rating >= 4.5) return 'excellent';
    if ($rating >= 3.5) return 'good';
    if ($rating >= 2.5) return 'average';
    return 'poor';
}

function getRatingText($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 2.5) return 'Average';
    return 'Poor';
}
?> 