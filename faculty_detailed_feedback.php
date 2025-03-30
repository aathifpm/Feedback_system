<?php
session_start();
include 'functions.php';

// Check if user is logged in and is faculty
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: index.php');
    exit();
}

if (!isset($_GET['assignment_id'])) {
    die("Error: No assignment selected.");
}

$assignment_id = intval($_GET['assignment_id']);
$faculty_id = $_SESSION['user_id'];

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

if (!$current_year) {
    die("Error: No active academic year found.");
}

// Fetch subject and faculty details
$subject_query = "SELECT s.*, f.name AS faculty_name, f.faculty_id AS faculty_code,
                 f.designation, f.qualification, f.experience, f.specialization,
                 d.name AS department_name,
                 sa.year, sa.semester, sa.section, sa.id as assignment_id
                 FROM subject_assignments sa
                 JOIN subjects s ON sa.subject_id = s.id
                 JOIN faculty f ON sa.faculty_id = f.id
                 JOIN departments d ON s.department_id = d.id
                 WHERE sa.id = ? AND f.id = ? AND sa.academic_year_id = ?";
$subject_stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($subject_stmt, "iii", $assignment_id, $faculty_id, $current_year['id']);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    die("Error: Invalid assignment ID or unauthorized access.");
}

// Fetch feedback statements and organize by section
$feedback_statements_query = "SELECT id, statement, section 
                            FROM feedback_statements 
                            WHERE is_active = TRUE 
                            ORDER BY section, id";
$stmt = mysqli_prepare($conn, $feedback_statements_query);
mysqli_stmt_execute($stmt);
$feedback_statements_result = mysqli_stmt_get_result($stmt);

$feedback_statements = [
    'COURSE_EFFECTIVENESS' => [],
    'TEACHING_EFFECTIVENESS' => [],
    'RESOURCES_ADMIN' => [],
    'ASSESSMENT_LEARNING' => [],
    'COURSE_OUTCOMES' => []
];

while ($row = mysqli_fetch_assoc($feedback_statements_result)) {
    $feedback_statements[$row['section']][] = [
        'id' => $row['id'],
        'statement' => $row['statement']
    ];
}

// Section information
$section_info = [
    'COURSE_EFFECTIVENESS' => [
        'title' => 'Course Effectiveness',
        'icon' => 'fas fa-book',
        'description' => 'Evaluation of course content and delivery effectiveness',
        'count' => count($feedback_statements['COURSE_EFFECTIVENESS'])
    ],
    'TEACHING_EFFECTIVENESS' => [
        'title' => 'Teaching Effectiveness',
        'icon' => 'fas fa-chalkboard-teacher',
        'description' => 'Assessment of teaching methods and instructor effectiveness',
        'count' => count($feedback_statements['TEACHING_EFFECTIVENESS'])
    ],
    'RESOURCES_ADMIN' => [
        'title' => 'Resources & Administration',
        'icon' => 'fas fa-tools',
        'description' => 'Evaluation of learning resources and administrative support',
        'count' => count($feedback_statements['RESOURCES_ADMIN'])
    ],
    'ASSESSMENT_LEARNING' => [
        'title' => 'Assessment & Learning',
        'icon' => 'fas fa-tasks',
        'description' => 'Analysis of assessment methods and learning outcomes',
        'count' => count($feedback_statements['ASSESSMENT_LEARNING'])
    ],
    'COURSE_OUTCOMES' => [
        'title' => 'Course Outcomes',
        'icon' => 'fas fa-graduation-cap',
        'description' => 'Achievement of intended course outcomes',
        'count' => count($feedback_statements['COURSE_OUTCOMES'])
    ]
];

// Fetch feedback data with ratings
$feedback_query = "SELECT 
    fr.rating, 
    f.comments, 
    fs.statement,
    fs.section,
    f.submitted_at,
    f.course_effectiveness_avg,
    f.teaching_effectiveness_avg,
    f.resources_admin_avg,
    f.assessment_learning_avg,
    f.course_outcomes_avg,
    f.cumulative_avg
FROM feedback f
JOIN feedback_ratings fr ON f.id = fr.feedback_id
JOIN feedback_statements fs ON fr.statement_id = fs.id
WHERE f.assignment_id = ?
ORDER BY fs.section, fs.id";

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "i", $assignment_id);
mysqli_stmt_execute($stmt);
$feedback_result = mysqli_stmt_get_result($stmt);

// Organize feedback by section
$feedback_by_section = [];
while ($row = mysqli_fetch_assoc($feedback_result)) {
    if (!isset($feedback_by_section[$row['section']])) {
        $feedback_by_section[$row['section']] = [];
    }
    $feedback_by_section[$row['section']][] = $row;
}

// Fetch overall statistics
$stats_query = "SELECT 
    COUNT(DISTINCT f.id) as feedback_count,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating
FROM feedback f
WHERE f.assignment_id = ?";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "i", $assignment_id);
mysqli_stmt_execute($stats_stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt));

// Fetch student comments
$comments_query = "SELECT comments, submitted_at
                  FROM feedback
                  WHERE assignment_id = ?
                  AND comments IS NOT NULL
                  AND comments != ''
                  ORDER BY submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($comments_stmt, "i", $assignment_id);
mysqli_stmt_execute($comments_stmt);
$comments = mysqli_fetch_all(mysqli_stmt_get_result($comments_stmt), MYSQLI_ASSOC);

// Include the same HTML/CSS from view_faculty_feedback.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subject Feedback Analysis - <?php echo htmlspecialchars($subject['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <!-- Include the same CSS from view_faculty_feedback.php -->
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
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Faculty Header Section */
        .faculty-header {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            text-align: center;
        }

        .faculty-header h1 {
            font-size: 2.2rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .faculty-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .meta-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .meta-card:hover {
            transform: translateY(-5px);
        }

        .faculty-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 20px;
            box-shadow: var(--inner-shadow);
        }

        .faculty-details p {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .faculty-details p:hover {
            transform: translateY(-3px);
        }

        .faculty-details i {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        /* Stats Section */
        .stats-section {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
        }

        .stats-section h2 {
            color: var(--text-color);
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-color);
            font-size: 1.8rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }

        .subject-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .subject-card:hover {
            transform: translateY(-5px);
        }

        .subject-card h3 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.3rem;
        }

        /* Rating Bars */
        .rating-bar {
            height: 25px;
            background: var(--bg-color);
            border-radius: 12.5px;
            box-shadow: var(--inner-shadow);
            overflow: hidden;
            margin: 0.8rem 0;
        }

        .rating-fill {
            height: 100%;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            padding: 0 1rem;
            color: white;
            font-weight: 500;
            transition: width 0.8s ease-in-out;
        }

        /* Section Cards */
        .section-card {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .section-title i {
            color: var(--primary-color);
            font-size: 1.8rem;
        }

        .section-title h2 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .rating-item {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .rating-item:hover {
            transform: translateY(-3px);
        }

        .rating-item h3 {
            color: var(--text-color);
            margin-bottom: 1.5rem;
            font-size: 1.2rem;
        }

        .rating-distribution {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .rating-bar-row {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .rating-label {
            min-width: 60px;
            color: var(--text-color);
            font-weight: 500;
        }

        /* Comments Section */
        .comments-section {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
        }

        .comments-section h2 {
            color: var(--text-color);
            margin-bottom: 2rem;
            font-size: 1.8rem;
        }

        .comment-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .comment-card:hover {
            transform: translateY(-3px);
        }

        .comment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .comment-text {
            color: var(--text-color);
            line-height: 1.8;
            padding: 1rem;
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        /* Action Buttons */
        .actions {
            display: flex;
            gap: 1.5rem;
            margin-top: 2.5rem;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            padding: 1rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(45deg, var(--secondary-color), #27ae60);
            color: white;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .faculty-header {
                padding: 1.5rem;
            }

            .faculty-meta {
                grid-template-columns: 1fr;
            }

            .faculty-details {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .rating-bar-row {
                flex-direction: column;
                align-items: stretch;
            }

            .rating-label {
                text-align: center;
                margin-bottom: 0.5rem;
            }
        }

        /* Additional Neumorphic Elements */
        .section-description {
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 2rem;
            color: #666;
        }

        .feedback-count {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background: var(--bg-color);
            border-radius: 50px;
            box-shadow: var(--shadow);
            margin-top: 1.5rem;
            color: var(--primary-color);
            font-weight: 500;
        }

        .no-data {
            text-align: center;
            padding: 3rem;
            background: var(--bg-color);
            border-radius: 20px;
            box-shadow: var(--inner-shadow);
            color: #666;
            font-style: italic;
        }

        /* Hover Effects */
        .rating-item:hover .rating-fill {
            filter: brightness(1.1);
        }

        .meta-card:hover i {
            transform: scale(1.2);
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <!-- Subject Header -->
        <div class="faculty-header">
            <h1><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h1>
            <div class="faculty-meta">
                <div class="meta-card">
                    <h3>Year & Semester</h3>
                    <p>Year <?php echo htmlspecialchars($subject['year']); ?> - Semester <?php echo htmlspecialchars($subject['semester']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Section</h3>
                    <p><?php echo htmlspecialchars($subject['section']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Department</h3>
                    <p><?php echo htmlspecialchars($subject['department_name']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Faculty</h3>
                    <p><?php echo htmlspecialchars($subject['faculty_name']); ?> (<?php echo htmlspecialchars($subject['faculty_code']); ?>)</p>
                </div>
            </div>
        </div>

        <!-- Overall Statistics -->
        <div class="stats-section">
            <h2>Overall Performance (<?php echo htmlspecialchars($current_year['year_range']); ?>)</h2>
            <div class="stats-grid">
                <div class="subject-card">
                    <h3>Feedback Summary</h3>
                    <div class="rating-summary">
                        <?php
                        $metrics = [
                            'Course Effectiveness' => $stats['course_effectiveness'] ?? 0,
                            'Teaching Effectiveness' => $stats['teaching_effectiveness'] ?? 0,
                            'Resources & Admin' => $stats['resources_admin'] ?? 0,
                            'Assessment & Learning' => $stats['assessment_learning'] ?? 0,
                            'Course Outcomes' => $stats['course_outcomes'] ?? 0
                        ];
                        
                        foreach ($metrics as $label => $value):
                            $percentage = $value * 20; // Convert to percentage
                            $rating_class = getRatingClass($value);
                        ?>
                            <div class="rating-item">
                                <span class="label"><?php echo $label; ?></span>
                                <div class="rating-bar">
                                    <div class="rating-fill <?php echo $rating_class; ?>" style="width: <?php echo $percentage; ?>%">
                                        <?php echo number_format($value, 2); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="feedback-count">
                        <i class="fas fa-users"></i>
                        <span><?php echo $stats['feedback_count'] ?? 0; ?> responses</span>
                    </div>
                    <?php if (isset($stats['overall_avg'])): ?>
                    <div class="overall-rating">
                        <h4>Overall Rating</h4>
                        <div class="rating-value <?php echo getRatingClass($stats['overall_avg']); ?>">
                            <?php echo number_format($stats['overall_avg'], 2); ?> / 5.00
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Section-wise Analysis -->
        <?php foreach ($section_info as $section_code => $info): ?>
            <div class="section-card">
                <div class="section-title">
                    <i class="<?php echo $info['icon']; ?>"></i>
                    <h2><?php echo $info['title']; ?></h2>
                </div>
                <p class="section-description"><?php echo $info['description']; ?></p>

                <?php if (isset($feedback_by_section[$section_code]) && !empty($feedback_by_section[$section_code])): ?>
                    <?php foreach ($feedback_statements[$section_code] as $statement): ?>
                        <div class="rating-item">
                            <h3><?php echo htmlspecialchars($statement['statement']); ?></h3>
                            <div class="rating-distribution">
                                <?php
                                // Find ratings for this statement
                                $statement_ratings = array_filter($feedback_by_section[$section_code], function($rating) use ($statement) {
                                    return $rating['statement'] === $statement['statement'];
                                });

                                // Calculate rating distribution
                                $rating_counts = array_fill(1, 5, 0);
                                foreach ($statement_ratings as $rating) {
                                    $rating_counts[$rating['rating']]++;
                                }
                                $total_ratings = array_sum($rating_counts);

                                // Display rating bars
                                for ($i = 5; $i >= 1; $i--):
                                    $count = $rating_counts[$i];
                                    $percentage = $total_ratings > 0 ? ($count / $total_ratings * 100) : 0;
                                ?>
                                    <div class="rating-bar-row">
                                        <div class="rating-label">
                                            <?php echo $i; ?> <i class="fas fa-star"></i>
                                        </div>
                                        <div class="rating-bar">
                                            <div class="rating-fill <?php echo getRatingClass($i); ?>" 
                                                 style="width: <?php echo $percentage; ?>%">
                                                <?php if ($percentage >= 10): ?>
                                                    <span class="rating-percentage">
                                                        <?php echo round($percentage); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="rating-count">
                                            <?php echo $count; ?> vote<?php echo $count !== 1 ? 's' : ''; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-data">No feedback data available for this section.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <!-- Student Comments -->
        <div class="comments-section">
            <h2>Student Comments</h2>
            <?php if (!empty($comments)): ?>
                <?php foreach ($comments as $comment): ?>
                    <div class="comment-card">
                        <div class="comment-header">
                            <span class="date">
                                <i class="far fa-calendar-alt"></i>
                                <?php echo date('F j, Y', strtotime($comment['submitted_at'])); ?>
                            </span>
                        </div>
                        <div class="comment-text">
                            <?php echo nl2br(htmlspecialchars($comment['comments'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-data">
                    <i class="far fa-comment-dots"></i>
                    No comments available.
                </p>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a href="generate_report.php?assignment_id=<?php echo $assignment_id; ?>" class="btn btn-primary">
                <i class="fas fa-file-pdf"></i> Generate Report
            </a>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>

    <script>
        function getRatingClass(rating) {
            if (rating >= 4.5) return 'rating-excellent';
            if (rating >= 4.0) return 'rating-good';
            if (rating >= 3.0) return 'rating-average';
            return 'rating-poor';
        }

        // Add smooth scrolling to section cards
        document.querySelectorAll('.section-card').forEach(card => {
            card.addEventListener('click', function() {
                this.scrollIntoView({ behavior: 'smooth' });
            });
        });

        // Add hover effects for rating bars
        document.querySelectorAll('.rating-bar').forEach(bar => {
            bar.addEventListener('mouseover', function() {
                this.querySelector('.rating-fill').style.filter = 'brightness(1.1)';
            });
            bar.addEventListener('mouseout', function() {
                this.querySelector('.rating-fill').style.filter = 'none';
            });
        });
    </script>
</body>
</html>

<?php
function getRatingClass($rating) {
    if ($rating >= 4.5) return 'rating-excellent';
    if ($rating >= 4.0) return 'rating-good';
    if ($rating >= 3.0) return 'rating-average';
    return 'rating-poor';
}
?>