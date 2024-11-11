<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: login.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;

// Fetch subject details
$subject_query = "SELECT s.name, s.code, 
                 ay.year_range as academic_year,
                 CASE 
                    WHEN s.semester % 2 = 0 THEN s.semester / 2
                    ELSE (s.semester + 1) / 2
                 END as year_of_study,
                 s.semester,
                 s.section
                 FROM subjects s
                 JOIN academic_years ay ON s.academic_year_id = ay.id 
                 WHERE s.id = ? AND s.faculty_id = ?";

$subject_stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($subject_stmt, "ii", $subject_id, $faculty_id);
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);
$subject = mysqli_fetch_assoc($subject_result);

if (!$subject) {
    die("Error: Invalid subject ID or you don't have permission to view this subject.");
}

// Get current academic year
$current_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

if (!$current_year) {
    die("Error: No active academic year found.");
}

// Fetch feedback statistics
$stats_query = "SELECT 
    COUNT(DISTINCT f.id) as total_responses,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating
FROM feedback f
WHERE f.subject_id = ? 
AND f.academic_year_id = ?";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "ii", $subject_id, $current_year['id']);
mysqli_stmt_execute($stats_stmt);
$stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stats_stmt));

// First, let's get the correct statement IDs and sections from the database structure
$feedback_statements_query = "SELECT id, statement, section 
                            FROM feedback_statements 
                            WHERE is_active = TRUE
                            ORDER BY section, id";
$stmt = mysqli_prepare($conn, $feedback_statements_query);
mysqli_stmt_execute($stmt);
$all_statements = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Now modify the ratings query to ensure we get all statements
$ratings_query = "SELECT 
    fs.section,
    fs.statement,
    fs.id as statement_id,
    AVG(COALESCE(fr.rating, 0)) as avg_rating,
    COUNT(DISTINCT f.id) as response_count,
    SUM(CASE WHEN fr.rating = 1 THEN 1 ELSE 0 END) as rating_1,
    SUM(CASE WHEN fr.rating = 2 THEN 1 ELSE 0 END) as rating_2,
    SUM(CASE WHEN fr.rating = 3 THEN 1 ELSE 0 END) as rating_3,
    SUM(CASE WHEN fr.rating = 4 THEN 1 ELSE 0 END) as rating_4,
    SUM(CASE WHEN fr.rating = 5 THEN 1 ELSE 0 END) as rating_5
FROM feedback_statements fs
LEFT JOIN feedback_ratings fr ON fr.statement_id = fs.id
LEFT JOIN feedback f ON fr.feedback_id = f.id 
    AND f.subject_id = ? 
    AND f.academic_year_id = ?
WHERE fs.is_active = TRUE
GROUP BY fs.id, fs.section, fs.statement
ORDER BY fs.section, fs.id";

// Define the correct statements for each section based on give_feedback.php
$section_statements = [
    'COURSE_EFFECTIVENESS' => [
        "Does the course stimulate self-interest?",
        "The course was delivered as outlined in the syllabus.",
        "The syllabus was explained at the beginning of the course.",
        "Well-organized presentations.",
        "Given good examples and illustrations.",
        "Encouraged questions and class participation.",
        "Learnt new techniques and methods from this course.",
        "Understood the relevance of the course for real-world application.",
        "Course assignments and lectures complemented each other for design development/Projects.",
        "Course will help in competitive examinations.",
        "Course objectives mapped with outcomes.",
        "Course outcomes help to attain Program Educational Objectives (PEOs)."
    ],
    'TEACHING_EFFECTIVENESS' => [
        "Deliverance by course instructor stimulates interest.",
        "The instructor managed classroom time and place well.",
        "Instructor meets students' expectations.",
        "Instructor demonstrates thorough preparation for the course.",
        "Instructor encourages discussions and responds to questions.",
        "Instructor appeared enthusiastic and interested.",
        "Instructor was accessible outside the classroom."
    ],
    'RESOURCES_ADMIN' => [
        "Course supported by adequate library resources.",
        "Usefulness of teaching methods (Chalk & Talk, PPT, OHP, etc.).",
        "Instructor provided guidance on finding resources.",
        "Course material/Lecture notes were effective."
    ],
    'ASSESSMENT_LEARNING' => [
        "Exams measure the knowledge acquired in the course.",
        "Problems set help in understanding the course.",
        "Feedback on assignments was useful.",
        "Tutorial sessions help in understanding course concepts."
    ],
    'COURSE_OUTCOMES' => [
        "COURSE OUTCOME 1",
        "COURSE OUTCOME 2",
        "COURSE OUTCOME 3",
        "COURSE OUTCOME 4",
        "COURSE OUTCOME 5",
        "COURSE OUTCOME 6"
    ]
];

// Update section info with correct counts
$section_info = [
    'COURSE_EFFECTIVENESS' => [
        'title' => 'Course Effectiveness',
        'icon' => 'fas fa-book',
        'description' => 'Evaluation of course content and delivery effectiveness',
        'count' => 12
    ],
    'TEACHING_EFFECTIVENESS' => [
        'title' => 'Teaching Effectiveness',
        'icon' => 'fas fa-chalkboard-teacher',
        'description' => 'Assessment of teaching methods and instructor effectiveness',
        'count' => 7
    ],
    'RESOURCES_ADMIN' => [
        'title' => 'Resources & Administration',
        'icon' => 'fas fa-tools',
        'description' => 'Evaluation of learning resources and administrative support',
        'count' => 4
    ],
    'ASSESSMENT_LEARNING' => [
        'title' => 'Assessment & Learning',
        'icon' => 'fas fa-tasks',
        'description' => 'Analysis of assessment methods and learning outcomes',
        'count' => 4
    ],
    'COURSE_OUTCOMES' => [
        'title' => 'Course Outcomes',
        'icon' => 'fas fa-graduation-cap',
        'description' => 'Achievement of intended course outcomes',
        'count' => 6
    ]
];

// Execute the ratings query
$ratings_stmt = mysqli_prepare($conn, $ratings_query);
mysqli_stmt_bind_param($ratings_stmt, "ii", $subject_id, $current_year['id']);
mysqli_stmt_execute($ratings_stmt);
$ratings_result = mysqli_stmt_get_result($ratings_stmt);

// Organize ratings by section
$ratings_by_section = [];
while ($row = mysqli_fetch_assoc($ratings_result)) {
    $ratings_by_section[$row['section']][] = $row;
}

// Calculate section averages
$section_averages = [];
foreach ($ratings_by_section as $section => $ratings) {
    $total_rating = 0;
    $valid_ratings = 0;
    foreach ($ratings as $rating) {
        if ($rating['avg_rating'] > 0) {
            $total_rating += $rating['avg_rating'];
            $valid_ratings++;
        }
    }
    $section_averages[$section] = $valid_ratings > 0 ? $total_rating / $valid_ratings : 0;
}

// Fetch comments
$comments_query = "SELECT comments, submitted_at
                  FROM feedback
                  WHERE subject_id = ? 
                  AND academic_year_id = ?
                  AND comments IS NOT NULL
                  AND comments != ''
                  ORDER BY submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($comments_stmt, "ii", $subject_id, $current_year['id']);
mysqli_stmt_execute($comments_stmt);
$comments = mysqli_fetch_all(mysqli_stmt_get_result($comments_stmt), MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detailed Feedback - <?php echo htmlspecialchars($subject['name']); ?></title>
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

        .subject-header {
            background: var(--card-bg);
            padding: 2.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            text-align: center;
        }

        .subject-header h1 {
            font-size: 2.2rem;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .subject-meta {
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

        .meta-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .meta-card p {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 1rem 0;
        }

        .stat-label {
            font-size: 1rem;
            color: #666;
        }

        .rating-distribution {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin: 2rem 0;
        }

        .rating-bar-container {
            display: flex;
            align-items: center;
            margin: 1rem 0;
            padding: 0.5rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .rating-label {
            width: 50px;
            text-align: center;
            font-weight: 600;
        }

        .progress-bar {
            flex-grow: 1;
            height: 25px;
            margin: 0 1rem;
            background: var(--bg-color);
            border-radius: 12.5px;
            box-shadow: var(--inner-shadow);
            overflow: hidden;
            position: relative;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border-radius: 12.5px;
            transition: width 0.8s ease-in-out;
        }

        .rating-count {
            width: 80px;
            text-align: right;
            font-weight: 500;
            color: #666;
        }

        .section-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin: 2rem 0;
        }

        .section-title {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .comment-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin: 1rem 0;
            transition: transform 0.3s ease;
        }

        .comment-card:hover {
            transform: translateY(-3px);
        }

        .comment-text {
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .comment-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            font-size: 0.9rem;
            color: #666;
        }

        .chart-container {
            position: relative;
            width: 100%;
            height: 300px;
            margin: 2rem 0;
        }

        .average-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            margin: 0 auto;
            transition: transform 0.3s ease;
        }

        .average-circle:hover {
            transform: scale(1.05);
        }

        .average-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }

        .average-label {
            font-size: 0.9rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }

            .rating-bar-container {
                flex-direction: column;
                gap: 0.5rem;
            }

            .progress-bar {
                width: 100%;
                margin: 0.5rem 0;
            }

            .rating-count {
                width: 100%;
                text-align: center;
            }
        }

        .rating-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin: 1rem 0;
        }

        .rating-item h3 {
            font-size: 1.1rem;
            margin-bottom: 1rem;
            color: var(--text-color);
        }

        .detailed-ratings {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .mini-rating-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 0.5rem 0;
        }

        .rating-number {
            width: 20px;
            text-align: center;
        }

        .mini-progress {
            flex-grow: 1;
            height: 15px;
            background: var(--bg-color);
            border-radius: 7.5px;
            box-shadow: var(--inner-shadow);
            overflow: hidden;
        }

        .mini-fill {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            transition: width 0.8s ease-in-out;
        }

        .mini-count {
            width: 40px;
            text-align: right;
            font-size: 0.9rem;
            color: #666;
        }

        .section-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin: 2rem 0;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .section-title i {
            color: var(--primary-color);
        }

        .section-description {
            color: #666;
            margin-bottom: 2rem;
            font-size: 0.9rem;
        }

        .section-summary {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 2rem;
        }

        .summary-stats {
            display: flex;
            justify-content: space-around;
            gap: 2rem;
        }

        .stat {
            text-align: center;
        }

        .stat-value {
            display: block;
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        .rating-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin: 1.5rem 0;
        }

        .rating-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .rating-header h3 {
            font-size: 1.1rem;
            color: var(--text-color);
            flex: 1;
            margin-right: 1rem;
        }

        .rating-summary {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .avg-rating {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .response-count {
            font-size: 0.9rem;
            color: #666;
        }

        .rating-bar-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 0.5rem 0;
        }

        .rating-bar {
            flex: 1;
            height: 20px;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            padding: 0 0.5rem;
            transition: width 0.3s ease;
        }

        .rating-percentage {
            color: white;
            font-size: 0.8rem;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .rating-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .summary-stats {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="subject-header">
            <h1><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h1>
            <div class="meta-card">
                <h3>Academic Year</h3>
                <p><?php echo htmlspecialchars($subject['academic_year']); ?></p>
            </div>
            <div class="subject-meta">
                <div class="meta-card">
                    <h3>Year</h3>
                    <p><?php echo htmlspecialchars($subject['year_of_study']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Semester</h3>
                    <p><?php echo htmlspecialchars($subject['semester']); ?></p>
                </div>
                <div class="meta-card">
                    <h3>Section</h3>
                    <p><?php echo htmlspecialchars($subject['section']); ?></p>
                </div>
            </div>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-users fa-2x" style="color: var(--primary-color)"></i>
                <div class="stat-value"><?php echo $stats['total_responses']; ?></div>
                <div class="stat-label">Total Responses</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-star fa-2x" style="color: var(--primary-color)"></i>
                <div class="stat-value"><?php echo number_format($stats['overall_avg'], 2); ?></div>
                <div class="stat-label">Average Rating</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-line fa-2x" style="color: var(--primary-color)"></i>
                <div class="stat-value"><?php echo number_format($stats['min_rating'], 1); ?> - <?php echo number_format($stats['max_rating'], 1); ?></div>
                <div class="stat-label">Rating Range</div>
            </div>
        </div>

        <div class="rating-distribution">
            <?php
            // Calculate overall rating distribution across all sections
            $rating_counts = array_fill(1, 5, 0);
            $total_responses = 0;

            foreach ($ratings_by_section as $section_ratings) {
                foreach ($section_ratings as $rating) {
                    $rating_counts[1] += $rating['rating_1'];
                    $rating_counts[2] += $rating['rating_2'];
                    $rating_counts[3] += $rating['rating_3'];
                    $rating_counts[4] += $rating['rating_4'];
                    $rating_counts[5] += $rating['rating_5'];
                    $total_responses += $rating['response_count'];
                }
            }

            // Display rating distribution
            for ($i = 5; $i >= 1; $i--): 
                $count = $rating_counts[$i];
                $percentage = ($total_responses > 0) ? ($count / $total_responses) * 100 : 0;
            ?>
                <div class="rating-bar-container">
                    <div class="rating-label">
                        <?php echo $i; ?> <i class="fas fa-star" style="color: var(--primary-color)"></i>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                    <div class="rating-count">
                        <?php echo $count; ?> votes
                    </div>
                </div>
            <?php endfor; ?>
        </div>

        <!-- Section-wise Ratings -->
        <?php foreach ($ratings_by_section as $section => $ratings): ?>
            <div class="section-card">
                <h2 class="section-title">
                    <i class="<?php echo $section_info[$section]['icon']; ?>"></i>
                    <?php echo $section_info[$section]['title']; ?>
                </h2>
                <p class="section-description"><?php echo $section_info[$section]['description']; ?></p>
                
                <div class="section-summary">
                    <div class="summary-stats">
                        <div class="stat">
                            <span class="stat-value">
                                <?php echo number_format($section_averages[$section], 2); ?>
                            </span>
                            <span class="stat-label">Section Average</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">
                                <?php 
                                $total_responses = max(array_column($ratings, 'response_count'));
                                echo $total_responses;
                                ?>
                            </span>
                            <span class="stat-label">Total Responses</span>
                        </div>
                    </div>
                </div>

                <?php foreach ($ratings as $rating): ?>
                    <div class="rating-item">
                        <div class="rating-header">
                            <h3><?php echo htmlspecialchars($rating['statement']); ?></h3>
                            <div class="rating-summary">
                                <span class="avg-rating">
                                    <i class="fas fa-star"></i>
                                    <?php echo number_format($rating['avg_rating'], 2); ?>
                                </span>
                                <span class="response-count">
                                    <?php echo $rating['response_count']; ?> responses
                                </span>
                            </div>
                        </div>

                        <div class="rating-distribution">
                            <?php 
                            $total_ratings = array_sum([
                                $rating['rating_1'],
                                $rating['rating_2'],
                                $rating['rating_3'],
                                $rating['rating_4'],
                                $rating['rating_5']
                            ]);
                            
                            for ($i = 5; $i >= 1; $i--): 
                                $count = $rating["rating_$i"];
                                $percentage = ($total_ratings > 0) ? 
                                    ($count / $total_ratings) * 100 : 0;
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
            </div>
        <?php endforeach; ?>

        <div class="section-card">
            <h2 class="section-title">
                <i class="fas fa-comments"></i>
                Student Comments
            </h2>
            <?php foreach ($comments as $comment): ?>
                <div class="comment-card">
                    <div class="comment-text">
                        <?php echo nl2br(htmlspecialchars($comment['comments'])); ?>
                    </div>
                    <div class="comment-meta">
                        <span>
                            <i class="far fa-clock"></i>
                            <?php echo date('F j, Y, g:i a', strtotime($comment['submitted_at'])); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <a href="dashboard.php" class="btn-back">Back to Dashboard</a>
    </div>
</body>
</html>