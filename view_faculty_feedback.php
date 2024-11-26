<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods' && $_SESSION['role'] != 'admin')) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['faculty_id'])) {
    die("Error: No faculty selected.");
}

$faculty_id = intval($_GET['faculty_id']);

// Fetch faculty details
$faculty_query = "SELECT f.*, d.name AS department_name 
                 FROM faculty f
                 JOIN departments d ON f.department_id = d.id
                 WHERE f.id = ?";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
$faculty = mysqli_fetch_assoc($faculty_result);

if (!$faculty) {
    die("Error: Invalid faculty ID.");
}

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

if (!$current_year) {
    die("Error: No active academic year found.");
}

// Fetch feedback statistics
$stats_query = "SELECT 
    s.code,
    s.name as subject_name,
    COUNT(DISTINCT f.id) as feedback_count,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating,
    s.semester,
    s.section
FROM subjects s
LEFT JOIN feedback f ON s.id = f.subject_id
WHERE s.faculty_id = ? 
AND s.is_active = TRUE
AND f.academic_year_id = ?
GROUP BY s.id
ORDER BY s.code";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "ii", 
    $faculty_id, 
    $current_year['id']
);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);

// Fetch feedback statements from database
$feedback_statements_query = "SELECT id, statement, section 
                            FROM feedback_statements 
                            WHERE is_active = TRUE 
                            ORDER BY section, id";
$stmt = mysqli_prepare($conn, $feedback_statements_query);
mysqli_stmt_execute($stmt);
$feedback_statements_result = mysqli_stmt_get_result($stmt);

// Organize statements by section
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

// Section information with correct counts
$section_info = [
    'COURSE_EFFECTIVENESS' => [
        'title' => 'Course Effectiveness',
        'icon' => 'fas fa-book',
        'description' => 'Evaluation of course content and delivery effectiveness',
        'count' => count($feedback_statements['COURSE_EFFECTIVENESS']) // Should be 12
    ],
    'TEACHING_EFFECTIVENESS' => [
        'title' => 'Teaching Effectiveness',
        'icon' => 'fas fa-chalkboard-teacher',
        'description' => 'Assessment of teaching methods and instructor effectiveness',
        'count' => count($feedback_statements['TEACHING_EFFECTIVENESS']) // Should be 7
    ],
    'RESOURCES_ADMIN' => [
        'title' => 'Resources & Administration',
        'icon' => 'fas fa-tools',
        'description' => 'Evaluation of learning resources and administrative support',
        'count' => count($feedback_statements['RESOURCES_ADMIN']) // Should be 4
    ],
    'ASSESSMENT_LEARNING' => [
        'title' => 'Assessment & Learning',
        'icon' => 'fas fa-tasks',
        'description' => 'Analysis of assessment methods and learning outcomes',
        'count' => count($feedback_statements['ASSESSMENT_LEARNING']) // Should be 4
    ],
    'COURSE_OUTCOMES' => [
        'title' => 'Course Outcomes',
        'icon' => 'fas fa-graduation-cap',
        'description' => 'Achievement of intended course outcomes',
        'count' => count($feedback_statements['COURSE_OUTCOMES']) // Should be 6
    ]
];

// Update the feedback query to join with feedback_statements
$feedback_query = "SELECT 
    fr.rating, 
    f.comments, 
    s.code, 
    s.name AS subject_name,
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
JOIN subjects s ON f.subject_id = s.id
JOIN feedback_ratings fr ON f.id = fr.feedback_id
JOIN feedback_statements fs ON fr.statement_id = fs.id
WHERE s.faculty_id = ? 
AND f.academic_year_id = ?
ORDER BY fs.section, fs.id";

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $current_year['id']);
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

// Fetch student comments
$comments_query = "SELECT f.comments, f.submitted_at, s.name as subject_name, s.code as subject_code
                  FROM feedback f
                  JOIN subjects s ON f.subject_id = s.id
                  WHERE s.faculty_id = ? 
                  AND f.academic_year_id = ?
                  AND f.comments IS NOT NULL
                  AND f.comments != ''
                  ORDER BY f.submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($comments_stmt, "ii", $faculty_id, $current_year['id']);
mysqli_stmt_execute($comments_stmt);
$comments = mysqli_fetch_all(mysqli_stmt_get_result($comments_stmt), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Feedback Analysis - <?php echo htmlspecialchars($faculty['name']); ?></title>
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
        <div class="faculty-header">
            <h1><?php echo htmlspecialchars($faculty['name']); ?></h1>
            <div class="faculty-meta">
                <p class="faculty-id">Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id']); ?></p>
                <p class="designation"><?php echo htmlspecialchars($faculty['designation']); ?></p>
                <p class="department"><?php echo htmlspecialchars($faculty['department_name']); ?></p>
            </div>
            <div class="faculty-details">
                <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($faculty['qualification']); ?></p>
                <p><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($faculty['experience']); ?> years experience</p>
                <p><i class="fas fa-book"></i> <?php echo htmlspecialchars($faculty['specialization']); ?></p>
            </div>
        </div>

        <!-- Subject-wise Statistics -->
        <div class="stats-section">
            <h2>Subject Performance (<?php echo htmlspecialchars($current_year['year_range']); ?>)</h2>
            <div class="stats-grid">
                <?php while ($subject = mysqli_fetch_assoc($stats_result)): ?>
                    <div class="subject-card">
                        <h3><?php echo htmlspecialchars($subject['subject_name']); ?> 
                            (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
                        <p>Semester: <?php echo $subject['semester']; ?> | 
                           Section: <?php echo $subject['section']; ?></p>
                        
                        <div class="rating-summary">
                            <?php
                            $metrics = [
                                'Course Effectiveness' => $subject['course_effectiveness'],
                                'Teaching Effectiveness' => $subject['teaching_effectiveness'],
                                'Resources & Admin' => $subject['resources_admin'],
                                'Assessment & Learning' => $subject['assessment_learning'],
                                'Course Outcomes' => $subject['course_outcomes']
                            ];
                            
                            foreach ($metrics as $label => $value):
                                $percentage = $value * 20; // Convert to percentage
                            ?>
                                <div class="rating-item">
                                    <span class="label"><?php echo $label; ?></span>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo $percentage; ?>%">
                                            <?php echo number_format($value, 2); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="feedback-count">
                            <span><?php echo $subject['feedback_count']; ?> responses</span>
                        </div>
                    </div>
                <?php endwhile; ?>
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

                <?php if (isset($feedback_by_section[$section_code])): ?>
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
                                            <div class="rating-fill" style="width: <?php echo $percentage; ?>%">
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
                            <span class="subject"><?php echo htmlspecialchars($comment['subject_name']); ?> 
                                (<?php echo htmlspecialchars($comment['subject_code']); ?>)</span>
                            <span class="date"><?php echo date('F j, Y', strtotime($comment['submitted_at'])); ?></span>
                        </div>
                        <div class="comment-text">
                            <?php echo nl2br(htmlspecialchars($comment['comments'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="no-data">No comments available.</p>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a href="generate_report.php?faculty_id=<?php echo $faculty_id; ?>" class="btn btn-primary">
                <i class="fas fa-file-pdf"></i> Generate Report
            </a>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>