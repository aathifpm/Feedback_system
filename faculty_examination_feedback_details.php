<?php
session_start();
include 'db_connection.php';
include 'functions.php';

// Check if user is logged in and is a faculty
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: index.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];

// Check if assignment_id is provided
if (!isset($_GET['assignment_id'])) {
    header('Location: faculty_examination_feedback.php');
    exit();
}

$assignment_id = intval($_GET['assignment_id']);

// Verify that this assignment belongs to the logged-in faculty
$check_query = "SELECT sa.id, sa.subject_id, sa.semester, sa.section, sa.year, 
                s.code as subject_code, s.name as subject_name, 
                d.name as department_name, ay.year_range as academic_year
                FROM subject_assignments sa
                JOIN subjects s ON sa.subject_id = s.id
                JOIN departments d ON s.department_id = d.id
                JOIN academic_years ay ON sa.academic_year_id = ay.id
                WHERE sa.id = ? AND sa.faculty_id = ?";
$check_stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($check_stmt, "ii", $assignment_id, $faculty_id);
mysqli_stmt_execute($check_stmt);
$assignment_result = mysqli_stmt_get_result($check_stmt);

if (mysqli_num_rows($assignment_result) === 0) {
    // This assignment either doesn't exist or doesn't belong to this faculty
    header('Location: faculty_examination_feedback.php');
    exit();
}

$assignment = mysqli_fetch_assoc($assignment_result);

// Get faculty details
$faculty_query = "SELECT f.*, d.name AS department_name 
                 FROM faculty f
                 JOIN departments d ON f.department_id = d.id
                 WHERE f.id = ?";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
$faculty = mysqli_fetch_assoc($faculty_result);

// Get all exam timetables for this subject
$exams_query = "SELECT et.id, et.exam_date, et.exam_session, et.start_time, et.end_time,
                COUNT(ef.id) as feedback_count
                FROM exam_timetable et
                LEFT JOIN examination_feedback ef ON et.id = ef.exam_timetable_id AND ef.subject_assignment_id = ?
                WHERE et.subject_id = ? AND et.semester = ?
                GROUP BY et.id
                ORDER BY et.exam_date DESC";
$exams_stmt = mysqli_prepare($conn, $exams_query);
mysqli_stmt_bind_param($exams_stmt, "iii", $assignment_id, $assignment['subject_id'], $assignment['semester']);
mysqli_stmt_execute($exams_stmt);
$exams_result = mysqli_stmt_get_result($exams_stmt);

// Get examination feedback statistics
$selected_exam = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;

// Get examination feedback statistics for selected exam or all exams
$where_clause = $selected_exam > 0 ? "AND ef.exam_timetable_id = ?" : "";
$stats_query = "SELECT 
                COUNT(ef.id) as feedback_count,
                AVG(ef.coverage_relevance_avg) as coverage_relevance_avg,
                AVG(ef.quality_clarity_avg) as quality_clarity_avg,
                AVG(ef.structure_balance_avg) as structure_balance_avg,
                AVG(ef.application_innovation_avg) as application_innovation_avg,
                AVG(ef.cumulative_avg) as overall_avg,
                MIN(ef.cumulative_avg) as min_rating,
                MAX(ef.cumulative_avg) as max_rating
                FROM examination_feedback ef
                WHERE ef.subject_assignment_id = ? $where_clause";

$params = [$assignment_id];
$types = "i";
if ($selected_exam > 0) {
    $params[] = $selected_exam;
    $types .= "i";
}

$stats_stmt = mysqli_prepare($conn, $stats_query);
mysqli_stmt_bind_param($stats_stmt, $types, ...$params);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);

// Get average ratings by statement category
$category_ratings_query = "SELECT 
                          efs.section, 
                          AVG(efr.rating) as avg_rating,
                          COUNT(DISTINCT ef.id) as feedback_count
                          FROM examination_feedback ef
                          JOIN examination_feedback_ratings efr ON ef.id = efr.feedback_id
                          JOIN examination_feedback_statements efs ON efr.statement_id = efs.id
                          WHERE ef.subject_assignment_id = ? $where_clause
                          GROUP BY efs.section
                          ORDER BY efs.section";

$category_stmt = mysqli_prepare($conn, $category_ratings_query);
mysqli_stmt_bind_param($category_stmt, $types, ...$params);
mysqli_stmt_execute($category_stmt);
$category_result = mysqli_stmt_get_result($category_stmt);

// Get detailed ratings by statement
$statement_ratings_query = "SELECT 
                           efs.id, efs.statement, efs.section,
                           AVG(efr.rating) as avg_rating,
                           COUNT(efr.id) as count
                           FROM examination_feedback ef
                           JOIN examination_feedback_ratings efr ON ef.id = efr.feedback_id
                           JOIN examination_feedback_statements efs ON efr.statement_id = efs.id
                           WHERE ef.subject_assignment_id = ? $where_clause
                           GROUP BY efs.id
                           ORDER BY efs.section, efs.id";

$statement_stmt = mysqli_prepare($conn, $statement_ratings_query);
mysqli_stmt_bind_param($statement_stmt, $types, ...$params);
mysqli_stmt_execute($statement_stmt);
$statement_result = mysqli_stmt_get_result($statement_stmt);

// Get comments from students
$comments_query = "SELECT ef.syllabus_coverage, ef.difficult_questions, ef.out_of_syllabus, 
                  ef.time_sufficiency, ef.fairness_rating, ef.improvements, ef.additional_comments, 
                  ef.submitted_at
                  FROM examination_feedback ef
                  WHERE ef.subject_assignment_id = ? $where_clause
                  ORDER BY ef.submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($comments_stmt, $types, ...$params);
mysqli_stmt_execute($comments_stmt);
$comments_result = mysqli_stmt_get_result($comments_stmt);

// Define section titles for display
$section_titles = [
    'COVERAGE_RELEVANCE' => 'Coverage and Relevance',
    'QUALITY_CLARITY' => 'Quality and Clarity',
    'STRUCTURE_BALANCE' => 'Structure and Balance',
    'APPLICATION_INNOVATION' => 'Application and Innovation'
];

// Helper function to determine rating class
function getRatingClass($rating) {
    if ($rating >= 4.5) return 'excellent';
    if ($rating >= 4.0) return 'good';
    if ($rating >= 3.5) return 'average';
    if ($rating >= 3.0) return 'fair';
    return 'poor';
}

// Helper function to get color for rating
function getRatingColor($rating) {
    if ($rating >= 4.5) return 'linear-gradient(45deg, #27ae60, #2ecc71)';
    if ($rating >= 4.0) return 'linear-gradient(45deg, #2ecc71, #3498db)';
    if ($rating >= 3.5) return 'linear-gradient(45deg, #f1c40f, #f39c12)';
    if ($rating >= 3.0) return 'linear-gradient(45deg, #e67e22, #d35400)';
    return 'linear-gradient(45deg, #e74c3c, #c0392b)';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Feedback Details - Faculty Dashboard</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Subject Header Info */
        .subject-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .subject-info {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .subject-title {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .subject-title i {
            color: var(--primary-color);
        }

        .subject-meta {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .subject-code {
            display: inline-block;
            padding: 0.4rem 1rem;
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            color: white;
            border-radius: 50px;
            font-weight: 500;
            font-size: 0.9rem;
            box-shadow: var(--shadow);
            margin-bottom: 0.5rem;
        }

        .subject-detail {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .subject-detail i {
            color: var(--primary-color);
            width: 20px;
            text-align: center;
        }

        /* Filters Section */
        .filters-bar {
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1.2rem;
            border: none;
            background: var(--bg-color);
            border-radius: 50px;
            color: var(--text-color);
            font-size: 1rem;
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        /* Content Cards */
        .content-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .card-title {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .card-title i {
            color: var(--primary-color);
        }
        
        /* Feedback Summary */
        .feedback-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-item {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        
        .summary-item:hover {
            transform: translateY(-5px);
        }
        
        .summary-item h3 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .summary-item p {
            color: #666;
            font-size: 0.9rem;
        }
        
        /* Chart Containers */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .chart-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            height: 300px;
        }
        
        .chart-title {
            font-size: 1.1rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            text-align: center;
            font-weight: 500;
        }
        
        /* Ratings Styles */
        .ratings-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }
        
        .section-title {
            font-weight: 600;
            color: var(--text-color);
            margin-top: 1.5rem;
            margin-bottom: 1.2rem;
            border-left: 4px solid var(--primary-color);
            padding-left: 0.8rem;
            font-size: 1.2rem;
        }
        
        .statement-row {
            background: rgba(255, 255, 255, 0.5);
            padding: 1.2rem;
            border-radius: 12px;
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        
        .statement-row:hover {
            transform: translateY(-3px);
        }
        
        .statement-text {
            margin-bottom: 1rem;
            color: var(--text-color);
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .rating-container {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .rating-value {
            font-weight: 600;
            color: var(--primary-color);
            min-width: 50px;
            text-align: center;
            font-size: 1.2rem;
        }
        
        .progress-container {
            flex-grow: 1;
            height: 10px;
            background: var(--bg-color);
            border-radius: 5px;
            overflow: hidden;
            box-shadow: var(--inner-shadow);
        }
        
        .progress-bar {
            height: 100%;
            border-radius: 5px;
        }
        
        .progress-bar.excellent { background: linear-gradient(45deg, #27ae60, #2ecc71); }
        .progress-bar.good { background: linear-gradient(45deg, #2ecc71, #3498db); }
        .progress-bar.average { background: linear-gradient(45deg, #f1c40f, #f39c12); }
        .progress-bar.fair { background: linear-gradient(45deg, #e67e22, #d35400); }
        .progress-bar.poor { background: linear-gradient(45deg, #e74c3c, #c0392b); }
        
        .response-count {
            min-width: 120px;
            text-align: right;
            color: #666;
            font-size: 0.85rem;
        }
        
        /* Comments Section */
        .comment {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }
        
        .comment:hover {
            transform: translateY(-3px);
        }
        
        .comment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.2rem;
        }
        
        .comment-item {
            background: rgba(255, 255, 255, 0.5);
            padding: 1rem;
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }
        
        .comment-header {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.8rem;
            font-size: 1rem;
        }
        
        .comment-text {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.5;
        }
        
        .comment-timestamp {
            text-align: right;
            color: #7f8c8d;
            font-size: 0.8rem;
            margin-top: 1rem;
            font-style: italic;
        }
        
        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }
        
        .empty-state i {
            font-size: 3.5rem;
            color: #bdc3c7;
            margin-bottom: 1.5rem;
        }
        
        .empty-state h4 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1rem;
        }
        
        .empty-state p {
            color: #7f8c8d;
            max-width: 500px;
            margin: 0 auto;
        }
        
        /* Navigation Button */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: var(--shadow);
        }
        
        .btn:hover {
            transform: translateY(-3px);
        }
        
        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            color: white;
        }
        
        .btn-outline {
            background: var(--bg-color);
            color: var(--text-color);
            border: 1px solid var(--text-color);
        }
        
        .btn-primary:hover {
            background: #c0392b;
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
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background: var(--bg-color);
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 80%;
            max-width: 600px;
            position: relative;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-color);
        }
        
        .close-modal {
            color: #aaa;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: var(--primary-color);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .profile-section, .subject-info {
                flex-direction: column;
                text-align: center;
            }
            
            .profile-image {
                margin: 0 auto;
            }
            
            .faculty-meta, .subject-meta {
                justify-content: center;
            }
            
            .charts-row {
                grid-template-columns: 1fr;
            }
            
            .comment-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <!-- Faculty Header -->
        <div class="faculty-header">
            <div class="profile-section">
                <div class="profile-image">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($faculty['name'] ?? 'Faculty Member'); ?></h1>
                    <div class="faculty-meta">
                        <span class="meta-item">
                            <i class="fas fa-id-badge"></i>
                            Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id'] ?? 'N/A'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($faculty['designation'] ?? 'Faculty'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($faculty['department_name'] ?? 'Department'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-chart-bar"></i>
                            Examination Feedback Details
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject Info Header -->
        <div class="subject-header">
            <div class="subject-info">
                <div>
                    <h2 class="subject-title">
                        <i class="fas fa-book"></i>
                        Subject Information
                    </h2>
                    <span class="subject-code"><?php echo htmlspecialchars($assignment['subject_code']); ?></span>
                    <h3><?php echo htmlspecialchars($assignment['subject_name']); ?></h3>
                    <div class="subject-detail">
                        <i class="fas fa-university"></i>
                        <span>Department: <?php echo htmlspecialchars($assignment['department_name']); ?></span>
                    </div>
                </div>
                <div class="subject-meta">
                    <div class="subject-detail">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Year <?php echo htmlspecialchars($assignment['year']); ?> / Semester <?php echo htmlspecialchars($assignment['semester']); ?></span>
                    </div>
                    <div class="subject-detail">
                        <i class="fas fa-users"></i>
                        <span>Section: <?php echo htmlspecialchars($assignment['section']); ?></span>
                    </div>
                    <div class="subject-detail">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Year: <?php echo htmlspecialchars($assignment['academic_year']); ?></span>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 1rem;">
                        <a href="faculty_examination_feedback.php" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Feedback List
                        </a>
                        <button id="openExportModal" class="btn btn-primary">
                            <i class="fas fa-file-excel"></i> Generate Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Exam Filters -->
        <?php if (mysqli_num_rows($exams_result) > 0): ?>
            <div class="filters-bar">
                <form method="GET" action="" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                    <div style="flex: 1; min-width: 120px;">
                        <label for="exam_id" class="form-label">Select Exam:</label>
                    </div>
                    <div style="flex: 3; min-width: 300px;">
                        <select name="exam_id" id="exam_id" class="form-control" onchange="this.form.submit()">
                            <option value="0" <?php echo $selected_exam == 0 ? 'selected' : ''; ?>>All Exams</option>
                            <?php mysqli_data_seek($exams_result, 0); ?>
                            <?php while ($exam = mysqli_fetch_assoc($exams_result)): ?>
                                <option value="<?php echo $exam['id']; ?>" <?php echo $selected_exam == $exam['id'] ? 'selected' : ''; ?>>
                                    <?php echo date('d M Y', strtotime($exam['exam_date'])); ?> 
                                    (<?php echo $exam['exam_session']; ?> Session) - 
                                    <?php echo $exam['feedback_count']; ?> feedback(s)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Feedback Summary -->
        <div class="content-card">
            <h2 class="card-title">
                <i class="fas fa-chart-pie"></i>
                Feedback Summary
            </h2>
            
            <?php if ($stats['feedback_count'] > 0): ?>
                <div class="feedback-summary">
                    <div class="summary-item">
                        <h3><?php echo $stats['feedback_count']; ?></h3>
                        <p>Total Feedback</p>
                    </div>
                    <div class="summary-item">
                        <h3><?php echo number_format($stats['overall_avg'], 2); ?></h3>
                        <p>Overall Rating</p>
                    </div>
                    <div class="summary-item">
                        <h3><?php echo number_format($stats['min_rating'], 2); ?></h3>
                        <p>Lowest Rating</p>
                    </div>
                    <div class="summary-item">
                        <h3><?php echo number_format($stats['max_rating'], 2); ?></h3>
                        <p>Highest Rating</p>
                    </div>
                </div>
                
                <div class="charts-row">
                    <div class="chart-container">
                        <h3 class="chart-title">Category Ratings</h3>
                        <canvas id="categoryRatingsChart"></canvas>
                    </div>
                    <div class="chart-container">
                        <h3 class="chart-title">Rating Distribution</h3>
                        <canvas id="ratingDistributionChart"></canvas>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h4>No Feedback Available</h4>
                    <p>There is no examination feedback data available for this subject.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($stats['feedback_count'] > 0): ?>
            <!-- Detailed Ratings -->
            <div class="content-card">
                <h2 class="card-title">
                    <i class="fas fa-star"></i>
                    Detailed Ratings
                </h2>
                <div class="ratings-section">
                    <?php 
                    $current_section = '';
                    mysqli_data_seek($statement_result, 0);
                    while ($statement = mysqli_fetch_assoc($statement_result)):
                        if ($current_section != $statement['section']):
                            $current_section = $statement['section'];
                    ?>
                        <h5 class="section-title"><?php echo $section_titles[$current_section]; ?></h5>
                    <?php endif; ?>
                    
                    <div class="statement-row">
                        <p class="statement-text"><?php echo htmlspecialchars($statement['statement']); ?></p>
                        <div class="rating-container">
                            <span class="rating-value"><?php echo number_format($statement['avg_rating'], 2); ?></span>
                            <div class="progress-container">
                                <div class="progress-bar <?php echo getRatingClass($statement['avg_rating']); ?>" 
                                     style="width: <?php echo $statement['avg_rating'] * 20; ?>%;"></div>
                            </div>
                            <span class="response-count"><?php echo $statement['count']; ?> response<?php echo $statement['count'] != 1 ? 's' : ''; ?></span>
                        </div>
                    </div>
                    
                    <?php endwhile; ?>
                </div>
            </div>
            
            <!-- Student Comments -->
            <div class="content-card">
                <h2 class="card-title">
                    <i class="fas fa-comments"></i>
                    Student Comments
                </h2>
                
                <?php if (mysqli_num_rows($comments_result) > 0): ?>
                    <div class="comments-section">
                        <?php while ($comment = mysqli_fetch_assoc($comments_result)): ?>
                            <div class="comment">
                                <div class="comment-grid">
                                    <div class="comment-item">
                                        <h6 class="comment-header">Syllabus Coverage</h6>
                                        <p class="comment-text"><?php echo empty($comment['syllabus_coverage']) ? 'No comment' : htmlspecialchars($comment['syllabus_coverage']); ?></p>
                                    </div>
                                    <div class="comment-item">
                                        <h6 class="comment-header">Difficult Questions</h6>
                                        <p class="comment-text"><?php echo empty($comment['difficult_questions']) ? 'No comment' : htmlspecialchars($comment['difficult_questions']); ?></p>
                                    </div>
                                    <div class="comment-item">
                                        <h6 class="comment-header">Out of Syllabus Questions</h6>
                                        <p class="comment-text"><?php echo empty($comment['out_of_syllabus']) ? 'No comment' : htmlspecialchars($comment['out_of_syllabus']); ?></p>
                                    </div>
                                    <div class="comment-item">
                                        <h6 class="comment-header">Time Sufficiency</h6>
                                        <p class="comment-text"><?php echo empty($comment['time_sufficiency']) ? 'No comment' : htmlspecialchars($comment['time_sufficiency']); ?></p>
                                    </div>
                                    <div class="comment-item">
                                        <h6 class="comment-header">Fairness Rating</h6>
                                        <p class="comment-text"><?php echo empty($comment['fairness_rating']) ? 'No comment' : htmlspecialchars($comment['fairness_rating']); ?></p>
                                    </div>
                                    <div class="comment-item">
                                        <h6 class="comment-header">Suggested Improvements</h6>
                                        <p class="comment-text"><?php echo empty($comment['improvements']) ? 'No comment' : htmlspecialchars($comment['improvements']); ?></p>
                                    </div>
                                </div>
                                
                                <?php if (!empty($comment['additional_comments'])): ?>
                                <div class="comment-item" style="margin-top: 1rem;">
                                    <h6 class="comment-header">Additional Comments</h6>
                                    <p class="comment-text"><?php echo htmlspecialchars($comment['additional_comments']); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <p class="comment-timestamp">
                                    Submitted: <?php echo date('d M Y, h:i A', strtotime($comment['submitted_at'])); ?>
                                </p>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-comments"></i>
                        <h4>No Comments Available</h4>
                        <p>There are no student comments available for this feedback.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Export Parameters Modal -->
    <div id="exportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Export Parameters</h2>
                <span class="close-modal">&times;</span>
            </div>
            <div class="modal-body">
                <form id="exportForm" method="get" action="generate_faculty_feedback_excel.php">
                    <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                    
                    <div style="margin-bottom: 20px;">
                        <label for="export_exam" class="form-label">Exam</label>
                        <select name="exam_id" id="export_exam" class="form-control">
                            <option value="0">All Exams</option>
                            <?php 
                            mysqli_data_seek($exams_result, 0);
                            while ($exam = mysqli_fetch_assoc($exams_result)): 
                            ?>
                                <option value="<?php echo $exam['id']; ?>">
                                    <?php echo date('d M Y', strtotime($exam['exam_date'])); ?> 
                                    (<?php echo $exam['exam_session']; ?> Session)
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label for="export_include_comments" class="form-label">Include Student Comments</label>
                        <select name="include_comments" id="export_include_comments" class="form-control">
                            <option value="1" selected>Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                    
                    <div style="margin-bottom: 20px;">
                        <label for="export_format" class="form-label">Report Format</label>
                        <select name="format" id="export_format" class="form-control">
                            <option value="excel" selected>Excel</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn" id="closeModal" style="background: #95a5a6; color: white;">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if ($stats['feedback_count'] > 0): ?>
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Category Ratings Chart
            const categoryCtx = document.getElementById('categoryRatingsChart').getContext('2d');
            const categoryLabels = [];
            const categoryData = [];
            
            <?php 
            mysqli_data_seek($category_result, 0);
            while ($category = mysqli_fetch_assoc($category_result)): 
            ?>
                categoryLabels.push('<?php echo $section_titles[$category['section']]; ?>');
                categoryData.push(<?php echo $category['avg_rating']; ?>);
            <?php endwhile; ?>
            
            new Chart(categoryCtx, {
                type: 'bar',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        label: 'Average Rating',
                        data: categoryData,
                        backgroundColor: [
                            'rgba(78, 115, 223, 0.8)',
                            'rgba(28, 200, 138, 0.8)',
                            'rgba(54, 185, 204, 0.8)',
                            'rgba(246, 194, 62, 0.8)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 5,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Average Ratings by Category',
                            font: {
                                size: 16
                            }
                        },
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Rating Distribution Chart
            const ratingDistCtx = document.getElementById('ratingDistributionChart').getContext('2d');
            
            new Chart(ratingDistCtx, {
                type: 'radar',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        label: 'Average Rating',
                        data: categoryData,
                        backgroundColor: 'rgba(78, 115, 223, 0.4)',
                        borderColor: 'rgba(78, 115, 223, 1)',
                        pointBackgroundColor: 'rgba(78, 115, 223, 1)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgba(78, 115, 223, 1)',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 5,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        title: {
                            display: true,
                            text: 'Rating Distribution by Category',
                            font: {
                                size: 16
                            }
                        }
                    }
                }
            });
            
            // Modal functionality
            const modal = document.getElementById("exportModal");
            const openModalBtn = document.getElementById("openExportModal");
            const closeModalBtn = document.getElementById("closeModal");
            const closeModalX = document.querySelector(".close-modal");
            
            openModalBtn.onclick = function() {
                modal.style.display = "block";
            }
            
            closeModalBtn.onclick = function() {
                modal.style.display = "none";
            }
            
            closeModalX.onclick = function() {
                modal.style.display = "none";
            }
            
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = "none";
                }
            }
        });
    </script>
    <?php endif; ?>
</body>
</html> 