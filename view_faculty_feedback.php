<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods' && $_SESSION['role'] != 'admin' && $_SESSION['role'] != 'faculty')) {
    header('Location: index.php');
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

// Get faculty details with feedback statistics
$stats_query = "SELECT 
    s.code,
    s.name as subject_name,
    sa.year,
    sa.semester,
    sa.section COLLATE utf8mb4_unicode_ci as section,
    COUNT(DISTINCT f.id) as feedback_count,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
LEFT JOIN feedback f ON sa.id = f.assignment_id
WHERE sa.faculty_id = ?
AND sa.is_active = TRUE
GROUP BY s.id, sa.id
ORDER BY s.code, sa.year, sa.semester, sa.section";

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, "i", 
    $faculty_id
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

// Get detailed feedback
$feedback_query = "SELECT 
    fr.rating, 
    f.comments, 
    s.code, 
    s.name AS subject_name,
    sa.year,
    sa.semester,
    sa.section COLLATE utf8mb4_unicode_ci as section,
    fs.statement,
    fs.section as feedback_section,
    f.submitted_at,
    f.course_effectiveness_avg,
    f.teaching_effectiveness_avg,
    f.resources_admin_avg,
    f.assessment_learning_avg,
    f.course_outcomes_avg,
    f.cumulative_avg,
    st.name as student_name,
    st.roll_number
FROM feedback f
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN feedback_ratings fr ON f.id = fr.feedback_id
JOIN feedback_statements fs ON fr.statement_id = fs.id
JOIN students st ON f.student_id = st.id
WHERE sa.faculty_id = ? 
AND sa.is_active = TRUE
ORDER BY s.code, sa.year, sa.semester, sa.section, fs.section, fs.id";

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "i", $faculty_id);
mysqli_stmt_execute($stmt);
$feedback_result = mysqli_stmt_get_result($stmt);

// Organize feedback by section and statement
$feedback_by_section = [
    'COURSE_EFFECTIVENESS' => [],
    'TEACHING_EFFECTIVENESS' => [],
    'RESOURCES_ADMIN' => [],
    'ASSESSMENT_LEARNING' => [],
    'COURSE_OUTCOMES' => []
];

while ($row = mysqli_fetch_assoc($feedback_result)) {
    $feedback_section = $row['feedback_section'];
    if (isset($feedback_by_section[$feedback_section])) {
        $feedback_by_section[$feedback_section][] = $row;
    }
}

// Get student comments
$comments_query = "SELECT 
    f.comments, 
    f.submitted_at, 
    s.name as subject_name, 
    s.code as subject_code,
    sa.year,
    sa.semester,
    sa.section COLLATE utf8mb4_unicode_ci as section,
    st.name as student_name,
    st.roll_number
FROM feedback f
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN students st ON f.student_id = st.id
WHERE sa.faculty_id = ? 
AND sa.is_active = TRUE
AND f.comments IS NOT NULL
AND f.comments != ''
ORDER BY f.submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($comments_stmt, "i", $faculty_id);
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
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .profile-section {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-image {
            width: 120px;
            height: 120px;
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
        }

        .profile-image i {
            font-size: 4rem;
            color: white;
        }

        .profile-info h1 {
            font-size: 2.4rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .faculty-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 50px;
            box-shadow: var(--inner-shadow);
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .meta-item i {
            color: var(--primary-color);
        }

        .faculty-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid rgba(0,0,0,0.1);
        }

        .detail-card {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .detail-card:hover {
            transform: translateY(-5px);
        }

        .detail-card i {
            font-size: 2rem;
            color: var(--primary-color);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(52, 152, 219, 0.1);
            border-radius: 12px;
        }

        .detail-info {
            display: flex;
            flex-direction: column;
        }

        .detail-info label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.3rem;
        }

        .detail-info span {
            font-size: 1.1rem;
            color: var(--text-color);
            font-weight: 500;
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
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .subject-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .subject-card:hover {
            transform: translateY(-5px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .subject-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
        }

        .subject-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .subject-card h3 {
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .subject-meta {
            display: flex;
            gap: 1rem;
            color: #666;
            font-size: 0.9rem;
        }

        .subject-meta span {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .subject-meta i {
            color: var(--primary-color);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .metric-box {
            background: rgba(255,255,255,0.5);
            padding: 1rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            text-align: center;
        }

        .metric-value {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.3rem;
        }

        .metric-label {
            font-size: 0.85rem;
            color: #666;
        }

        .rating-bars {
            margin-top: 1.5rem;
        }

        .rating-row {
            margin-bottom: 1rem;
        }

        .rating-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }

        .rating-label span {
            color: #666;
        }

        .rating-label .value {
            font-weight: 500;
            color: var(--text-color);
        }

        .rating-progress {
            height: 8px;
            background: var(--bg-color);
            border-radius: 4px;
            overflow: hidden;
            box-shadow: var(--inner-shadow);
        }

        .rating-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.8s ease-in-out;
        }

        .excellent { background: linear-gradient(45deg, #27ae60, #2ecc71); }
        .good { background: linear-gradient(45deg, #2ecc71, #3498db); }
        .average { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .fair { background: linear-gradient(45deg, #e67e22, #d35400); }
        .poor { background: linear-gradient(45deg, #e74c3c, #c0392b); }

        .feedback-count {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.2rem;
            background: var(--bg-color);
            border-radius: 50px;
            box-shadow: var(--shadow);
            margin-top: 1.5rem;
            color: var(--primary-color);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .feedback-count i {
            font-size: 1.1rem;
        }

        /* Section-wise Analysis */
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

        .section-description {
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 2rem;
            color: #666;
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
            padding: 1.5rem;
            border-radius: 15px;
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
            line-height: 1.6;
            color: var(--text-color);
            padding: 1rem;
            background: rgba(255,255,255,0.5);
            border-radius: 10px;
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

            .profile-section {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .profile-image {
                margin: 0 auto;
            }

            .faculty-meta {
                justify-content: center;
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

        .meta-item:hover i {
            transform: scale(1.2);
            color: var(--primary-color);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--bg-color);
            margin: 5% auto;
            border-radius: 25px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 2rem;
            border-bottom: 2px solid var(--primary-color);
            background: var(--bg-color);
            border-radius: 25px 25px 0 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .modal-header h2 {
            color: var(--text-color);
            margin: 0;
            font-size: 1.8rem;
        }

        .close {
            color: var(--text-color);
            font-size: 2rem;
            cursor: pointer;
            transition: color 0.3s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
        }

        .close:hover {
            color: var(--primary-color);
            box-shadow: var(--inner-shadow);
        }

        .modal-body {
            padding: 2rem;
            overflow-y: auto;
            max-height: calc(85vh - 140px); /* Adjust based on header and footer height */
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) var(--bg-color);
        }

        .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .modal-body::-webkit-scrollbar-track {
            background: var(--bg-color);
            border-radius: 4px;
        }

        .modal-body::-webkit-scrollbar-thumb {
            background: var(--primary-color);
            border-radius: 4px;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            background: var(--bg-color);
            border-radius: 0 0 25px 25px;
            position: sticky;
            bottom: 0;
            z-index: 1;
        }

        /* Form Group Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-group select {
            width: 100%;
            padding: 0.8rem 1rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .form-group select:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .statement-header {
            margin-bottom: 1.5rem;
        }

        .rating-summary {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin: 1rem 0;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .rating-average {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            color: white;
            text-align: center;
            min-width: 120px;
            box-shadow: var(--shadow);
        }

        .avg-number {
            font-size: 2rem;
            font-weight: 600;
            display: block;
        }

        .avg-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .rating-stats {
            display: flex;
            gap: 1.5rem;
            flex-grow: 1;
        }

        .stat-box {
            text-align: center;
            padding: 0.8rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--shadow);
            flex: 1;
        }

        .stat-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        .rating-bar-container {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-grow: 1;
        }

        .star-rating {
            display: flex;
            gap: 2px;
            min-width: 100px;
        }

        .star-rating .fas {
            color: #ddd;
            font-size: 0.9rem;
        }

        .star-rating .filled {
            color: #f1c40f;
        }

        .rating-fill {
            height: 100%;
            border-radius: 12.5px;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            color: white;
            font-weight: 500;
            transition: width 0.8s ease-in-out;
        }

        .rating-fill.excellent { background: linear-gradient(45deg, #27ae60, #2ecc71); }
        .rating-fill.good { background: linear-gradient(45deg, #2ecc71, #3498db); }
        .rating-fill.average { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .rating-fill.fair { background: linear-gradient(45deg, #e67e22, #d35400); }
        .rating-fill.poor { background: linear-gradient(45deg, #e74c3c, #c0392b); }

        .rating-count {
            min-width: 100px;
            text-align: right;
            color: #666;
            font-size: 0.9rem;
        }

        .rating-insights {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .insight-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .trend-excellent { color: #27ae60; }
        .trend-good { color: #2ecc71; }
        .trend-average { color: #f1c40f; }
        .trend-fair { color: #e67e22; }
        .trend-poor { color: #e74c3c; }

        @media (max-width: 768px) {
            .rating-summary {
                flex-direction: column;
                gap: 1rem;
            }

            .rating-stats {
                flex-direction: column;
                gap: 0.8rem;
            }

            .rating-bar-container {
                flex-direction: column;
                align-items: stretch;
            }

            .rating-count {
                text-align: center;
            }

            .rating-insights {
                flex-direction: column;
            }

            .modal-content {
                margin: 2% auto;
                width: 95%;
                max-height: 96vh;
            }

            .modal-body {
                max-height: calc(96vh - 140px);
                padding: 1.5rem;
            }

            .modal-header,
            .modal-footer {
                padding: 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="faculty-header">
            <div class="profile-section">
                <div class="profile-image">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($faculty['name']); ?></h1>
                    <div class="faculty-meta">
                        <span class="meta-item">
                            <i class="fas fa-id-badge"></i>
                            Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($faculty['designation']); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($faculty['department_name']); ?>
                        </span>
                        <button class="btn btn-primary" onclick="openReportModal()" style="margin-left: auto;">
                            <i class="fas fa-file-pdf"></i> Generate Report
                        </button>
                    </div>
                </div>
            </div>
            <div class="faculty-details">
                <div class="detail-card">
                    <i class="fas fa-graduation-cap"></i>
                    <div class="detail-info">
                        <label>Qualification</label>
                        <span><?php echo htmlspecialchars($faculty['qualification']); ?></span>
                    </div>
                </div>
                <div class="detail-card">
                    <i class="fas fa-briefcase"></i>
                    <div class="detail-info">
                        <label>Experience</label>
                        <span><?php echo htmlspecialchars($faculty['experience']); ?> years</span>
                    </div>
                </div>
                <div class="detail-card">
                    <i class="fas fa-book"></i>
                    <div class="detail-info">
                        <label>Specialization</label>
                        <span><?php echo htmlspecialchars($faculty['specialization']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject-wise Statistics -->
        <div class="stats-section">
            <h2>Subject Performance (<?php echo htmlspecialchars($current_year['year_range']); ?>)</h2>
            <div class="stats-grid">
                <?php while ($subject = mysqli_fetch_assoc($stats_result)): ?>
                    <div class="subject-card">
                        <div class="subject-header">
                        <h3><?php echo htmlspecialchars($subject['subject_name']); ?> 
                            (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
                            <div class="subject-meta">
                                <span><i class="fas fa-graduation-cap"></i> Sem <?php echo $subject['semester']; ?></span>
                                <span><i class="fas fa-users"></i> Section <?php echo $subject['section']; ?></span>
                            </div>
                        </div>
                        
                        <div class="metrics-grid">
                            <div class="metric-box">
                                <div class="metric-value"><?php echo number_format($subject['overall_rating'], 2); ?></div>
                                <div class="metric-label">Overall Rating</div>
                            </div>
                            <div class="metric-box">
                                <div class="metric-value"><?php echo $subject['feedback_count']; ?></div>
                                <div class="metric-label">Responses</div>
                            </div>
                        </div>
                        
                        <div class="rating-bars">
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
                                $rating_class = getRatingClass($value);
                            ?>
                                <div class="rating-row">
                                    <div class="rating-label">
                                        <span><?php echo $label; ?></span>
                                        <span class="value"><?php echo number_format($value, 2); ?></span>
                                        </div>
                                    <div class="rating-progress">
                                        <div class="rating-fill <?php echo $rating_class; ?>" 
                                             style="width: <?php echo $percentage; ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="feedback-count">
                            <i class="fas fa-chart-line"></i>
                            <span><?php echo getResponseRateText($subject['feedback_count']); ?></span>
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
                            <div class="statement-header">
                            <h3><?php echo htmlspecialchars($statement['statement']); ?></h3>
                                <?php
                                // Find ratings for this statement
                                $statement_ratings = array_filter($feedback_by_section[$section_code], function($rating) use ($statement) {
                                    return $rating['statement'] === $statement['statement'];
                                });

                                // Calculate rating distribution and statistics
                                $rating_counts = array_fill(1, 5, 0);
                                $total_ratings = 0;
                                $sum_ratings = 0;

                                foreach ($statement_ratings as $rating) {
                                    $rating_counts[$rating['rating']]++;
                                    $total_ratings++;
                                    $sum_ratings += $rating['rating'];
                                }

                                $avg_rating = $total_ratings > 0 ? $sum_ratings / $total_ratings : 0;
                                
                                // Calculate mode (most common rating)
                                $mode = array_search(max($rating_counts), $rating_counts);
                                
                                // Calculate standard deviation
                                $variance = 0;
                                if ($total_ratings > 0) {
                                    foreach ($statement_ratings as $rating) {
                                        $variance += pow($rating['rating'] - $avg_rating, 2);
                                    }
                                    $std_dev = sqrt($variance / $total_ratings);
                                }
                                ?>
                                
                                <div class="rating-summary">
                                    <div class="rating-average" style="background: <?php echo getRatingColor($avg_rating); ?>">
                                        <span class="avg-number"><?php echo number_format($avg_rating, 2); ?></span>
                                        <span class="avg-label">Average Rating</span>
                                    </div>
                                    <div class="rating-stats">
                                        <div class="stat-box">
                                            <span class="stat-value"><?php echo $total_ratings; ?></span>
                                            <span class="stat-label">Responses</span>
                                        </div>
                                        <div class="stat-box">
                                            <span class="stat-value"><?php echo $mode; ?></span>
                                            <span class="stat-label">Most Common</span>
                                        </div>
                                        <div class="stat-box">
                                            <span class="stat-value"><?php echo isset($std_dev) ? number_format($std_dev, 2) : 'N/A'; ?></span>
                                            <span class="stat-label">Std. Deviation</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-distribution">
                                <?php for ($i = 5; $i >= 1; $i--):
                                    $count = $rating_counts[$i];
                                    $percentage = $total_ratings > 0 ? ($count / $total_ratings * 100) : 0;
                                    $rating_class = getRatingClass($i);
                                ?>
                                    <div class="rating-bar-row">
                                        <div class="rating-label">
                                            <div class="star-rating">
                                                <?php for($j = 1; $j <= 5; $j++): ?>
                                                    <i class="fas fa-star <?php echo $j <= $i ? 'filled' : ''; ?>"></i>
                                                <?php endfor; ?>
                                        </div>
                                        </div>
                                        <div class="rating-bar-container">
                                        <div class="rating-bar">
                                                <div class="rating-fill <?php echo $rating_class; ?>" 
                                                     style="width: <?php echo $percentage; ?>%">
                                                    <?php if ($percentage >= 5): ?>
                                                    <span class="rating-percentage">
                                                        <?php echo round($percentage); ?>%
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="rating-count">
                                                <?php echo $count; ?> response<?php echo $count !== 1 ? 's' : ''; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <?php if ($total_ratings > 0): ?>
                                <div class="rating-insights">
                                    <div class="insight-item <?php echo getRatingTrendClass($avg_rating); ?>">
                                        <i class="fas <?php echo getRatingTrendIcon($avg_rating); ?>"></i>
                                        <span><?php echo getRatingTrendText($avg_rating); ?></span>
                                    </div>
                                    <div class="insight-item">
                                        <i class="fas fa-chart-line"></i>
                                        <span>Response Rate: <?php echo getResponseRateText($total_ratings); ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
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

        <!-- Report Parameters Modal -->
        <div id="reportModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Select Report Parameters</h2>
                    <span class="close" onclick="closeReportModal()">&times;</span>
                </div>
                <form id="reportForm" method="get">
                    <div class="modal-body">
                        <input type="hidden" name="faculty_id" value="<?php echo $faculty_id; ?>">
                        
                        <div class="form-group">
                            <label>Export Format</label>
                            <select name="export_format" class="input-field">
                                <option value="pdf">PDF Document</option>
                                <option value="excel">Excel Spreadsheet</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Report Type</label>
                            <select name="report_type" class="input-field" onchange="toggleParameters(this.value)">
                                <option value="overall">Overall Report</option>
                                <option value="academic_year">Academic Year Wise</option>
                                <option value="semester">Semester Wise</option>
                                <option value="section">Section Wise</option>
                                <option value="batch">Batch Wise</option>
                            </select>
                        </div>

                        <div class="form-group academic-year-group" style="display: none;">
                            <label>Academic Year</label>
                            <select name="academic_year" class="input-field">
                                <?php
                                $year_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
                                $year_result = mysqli_query($conn, $year_query);
                                while ($year = mysqli_fetch_assoc($year_result)) {
                                    echo "<option value='" . $year['id'] . "'>" . $year['year_range'] . "</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <div class="form-group semester-group" style="display: none;">
                            <label>Semester</label>
                            <select name="semester" class="input-field">
                                <?php for ($i = 1; $i <= 8; $i++) { ?>
                                    <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group section-group" style="display: none;">
                            <label>Section</label>
                            <select name="section" class="input-field">
                                <option value="A">Section A</option>
                                <option value="B">Section B</option>
                                <option value="C">Section C</option>
                                <option value="D">Section D</option>
                                <option value="E">Section E</option>
                                <option value="F">Section F</option>
                                <option value="G">Section G</option>
                                <option value="H">Section H</option>
                                <option value="I">Section I</option>
                                <option value="J">Section J</option>
                                <option value="K">Section K</option>
                            </select>
                        </div>

                        <div class="form-group batch-group" style="display: none;">
                            <label>Batch</label>
                            <select name="batch" class="input-field">
                                <?php
                                $batch_query = "SELECT id, batch_name FROM batch_years ORDER BY admission_year DESC";
                                $batch_result = mysqli_query($conn, $batch_query);
                                while ($batch = mysqli_fetch_assoc($batch_result)) {
                                    echo "<option value='" . $batch['id'] . "'>" . $batch['batch_name'] . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-export"></i> Export Report
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="closeReportModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openReportModal() {
            document.getElementById('reportModal').style.display = 'block';
        }

        function closeReportModal() {
            document.getElementById('reportModal').style.display = 'none';
        }

        function toggleParameters(reportType) {
            // Hide all parameter groups
            document.querySelector('.academic-year-group').style.display = 'none';
            document.querySelector('.semester-group').style.display = 'none';
            document.querySelector('.section-group').style.display = 'none';
            document.querySelector('.batch-group').style.display = 'none';

            // Show relevant parameter groups based on report type
            switch(reportType) {
                case 'academic_year':
                    document.querySelector('.academic-year-group').style.display = 'block';
                    break;
                case 'semester':
                    document.querySelector('.academic-year-group').style.display = 'block';
                    document.querySelector('.semester-group').style.display = 'block';
                    break;
                case 'section':
                    document.querySelector('.academic-year-group').style.display = 'block';
                    document.querySelector('.semester-group').style.display = 'block';
                    document.querySelector('.section-group').style.display = 'block';
                    break;
                case 'batch':
                    document.querySelector('.batch-group').style.display = 'block';
                    break;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('reportModal')) {
                closeReportModal();
            }
        }

        // Handle form submission based on export format
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const format = document.querySelector('select[name="export_format"]').value;
            const form = this;
            
            if (format === 'pdf') {
                form.action = 'generate_report.php';
            } else if (format === 'excel') {
                form.action = 'generate_excel.php';
            }
            
            form.submit();
        });
    </script>
</body>
</html>

<?php
// Add these helper functions at the end of the file
function getRatingColor($rating) {
    if ($rating >= 4.5) return '#27ae60';
    if ($rating >= 4.0) return '#2ecc71';
    if ($rating >= 3.5) return '#f1c40f';
    if ($rating >= 3.0) return '#e67e22';
    return '#e74c3c';
}

function getRatingClass($rating) {
    if ($rating == 5) return 'excellent';
    if ($rating == 4) return 'good';
    if ($rating == 3) return 'average';
    if ($rating == 2) return 'fair';
    return 'poor';
}

function getRatingTrendClass($rating) {
    if ($rating >= 4.5) return 'trend-excellent';
    if ($rating >= 4.0) return 'trend-good';
    if ($rating >= 3.5) return 'trend-average';
    if ($rating >= 3.0) return 'trend-fair';
    return 'trend-poor';
}

function getRatingTrendIcon($rating) {
    if ($rating >= 4.5) return 'fa-arrow-trend-up';
    if ($rating >= 4.0) return 'fa-arrow-trend-up';
    if ($rating >= 3.5) return 'fa-arrow-right';
    if ($rating >= 3.0) return 'fa-arrow-trend-down';
    return 'fa-arrow-trend-down';
}

function getRatingTrendText($rating) {
    if ($rating >= 4.5) return 'Exceptional Performance';
    if ($rating >= 4.0) return 'Strong Performance';
    if ($rating >= 3.5) return 'Satisfactory Performance';
    if ($rating >= 3.0) return 'Needs Improvement';
    return 'Requires Immediate Attention';
}

function getResponseRateText($total_responses) {
    if ($total_responses >= 50) return 'High Response Rate';
    if ($total_responses >= 30) return 'Moderate Response Rate';
    if ($total_responses >= 10) return 'Low Response Rate';
    return 'Very Low Response Rate';
}
?>