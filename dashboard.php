<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check login status
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get current academic year
$academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

if (!$current_academic_year) {
    die("Error: No active academic year found. Please contact administrator.");
}

// Fetch user details based on role
$user = null;
$stmt = null;

switch ($role) {
    case 'student':
        $user_query = "SELECT s.*, 
                        d.name as department_name,
                        d.code as department_code,
                        `by`.batch_name,
                        `by`.current_year_of_study,
                        CASE 
                            WHEN MONTH(CURDATE()) <= 5 THEN `by`.current_year_of_study * 2
                            ELSE `by`.current_year_of_study * 2 - 1
                        END as current_semester,
                        s.section
                    FROM students s
                    JOIN departments d ON s.department_id = d.id
                    JOIN batch_years `by` ON s.batch_id = `by`.id
                    WHERE s.id = ? AND s.is_active = TRUE";
        $stmt = mysqli_prepare($conn, $user_query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        break;

    case 'faculty':
        $user_query = "SELECT f.*,
                        d.name as department_name,
                        d.code as department_code,
                        (SELECT COUNT(DISTINCT s.id) 
                         FROM subjects s 
                         WHERE s.faculty_id = f.id 
                         AND s.academic_year_id = ?) as total_subjects,
                        (SELECT COUNT(DISTINCT fb.id) 
                         FROM feedback fb 
                         JOIN subjects s ON fb.subject_id = s.id 
                         WHERE s.faculty_id = f.id 
                         AND fb.academic_year_id = ?) as total_feedback,
                        (SELECT AVG(fb.cumulative_avg)
                         FROM feedback fb
                         JOIN subjects s ON fb.subject_id = s.id
                         WHERE s.faculty_id = f.id
                         AND fb.academic_year_id = ?) as avg_rating
                    FROM faculty f
                    JOIN departments d ON f.department_id = d.id
                    WHERE f.id = ? AND f.is_active = TRUE";
        $stmt = mysqli_prepare($conn, $user_query);
        if (!$stmt) {
            die("Error preparing statement: " . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmt, "iiii", 
            $current_academic_year['id'],
            $current_academic_year['id'],
            $current_academic_year['id'],
            $user_id
        );
        break;

    case 'hod':
        $user_query = "SELECT h.*,
                        d.name as department_name,
                        d.code as department_code,
                        (SELECT COUNT(*) 
                         FROM faculty f 
                         WHERE f.department_id = h.department_id 
                         AND f.is_active = TRUE) as total_faculty,
                        (SELECT COUNT(DISTINCT s.id)
                         FROM subjects s
                         WHERE s.department_id = h.department_id
                         AND s.academic_year_id = ?) as total_subjects,
                        (SELECT AVG(fb.cumulative_avg)
                         FROM feedback fb
                         JOIN subjects s ON fb.subject_id = s.id
                         WHERE s.department_id = h.department_id
                         AND fb.academic_year_id = ?) as dept_avg_rating
                    FROM hods h
                    JOIN departments d ON h.department_id = d.id
                    WHERE h.id = ? AND h.is_active = TRUE";

        break;

    default:
        header('Location: logout.php');
        exit();
}

// Execute the prepared statement
if ($stmt) {
    if (!mysqli_stmt_execute($stmt)) {
        die("Error executing query: " . mysqli_error($conn));
    }
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);
}

if (!$user) {
    header('Location: logout.php');
    exit();
}

// Fetch role-specific data using stored procedures
$data = [];
switch ($role) {
    case 'student':
        // Get student feedback status
        $stmt = mysqli_prepare($conn, "SELECT 
            s.id, s.name, s.code,
            f.name as faculty_name,
            CASE WHEN fb.id IS NOT NULL THEN 'Submitted' ELSE 'Pending' END as feedback_status,
            fb.submitted_at
        FROM subjects s
        JOIN faculty f ON s.faculty_id = f.id
        LEFT JOIN feedback fb ON fb.subject_id = s.id 
            AND fb.student_id = ?
            AND fb.academic_year_id = ?
        WHERE s.academic_year_id = ?
        AND s.semester IN (
            SELECT 
                CASE 
                    WHEN MONTH(CURDATE()) <= 5 THEN by2.current_year_of_study * 2
                    ELSE by2.current_year_of_study * 2 - 1
                END
            FROM students st2
            JOIN batch_years by2 ON st2.batch_id = by2.id
            WHERE st2.id = ?
        )
        AND s.section = (
            SELECT section 
            FROM students 
            WHERE id = ?
        )");
        
        mysqli_stmt_bind_param($stmt, "iiiii", 
            $user_id, 
            $current_academic_year['id'],
            $current_academic_year['id'],
            $user_id,
            $user_id
        );
        mysqli_stmt_execute($stmt);
        $data['subjects'] = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        
        // Calculate feedback statistics
        $data['feedback_stats'] = [
            'total_subjects' => count($data['subjects']),
            'completed_feedback' => count(array_filter($data['subjects'], function($subject) {
                return $subject['feedback_status'] === 'Submitted';
            }))
        ];
        
        // Check for exit survey eligibility
        $data['show_exit_survey'] = ($user['current_year_of_study'] == 4 && $user['current_semester'] == 8);
        break;

    case 'faculty':
        // Get faculty feedback summary
        $stmt = mysqli_prepare($conn, "SELECT 
            s.id, s.name, s.code,
            COUNT(DISTINCT fb.id) as feedback_count,
            AVG(fb.cumulative_avg) as avg_rating,
            s.semester,
            s.section,
            CASE 
                WHEN s.semester % 2 = 0 THEN s.semester / 2
                ELSE (s.semester + 1) / 2
            END as year_of_study
        FROM subjects s
        LEFT JOIN feedback fb ON fb.subject_id = s.id 
            AND fb.academic_year_id = ?
        WHERE s.faculty_id = ?
        AND s.academic_year_id = ?
        GROUP BY s.id");
        
        if (!$stmt) {
            die("Error preparing statement: " . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmt, "iii", 
            $current_academic_year['id'],
            $user_id,
            $current_academic_year['id']
        );
        mysqli_stmt_execute($stmt);
        $data['feedback_summary'] = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

        // Calculate overall statistics
        $data['overall_stats'] = [
            'total_subjects' => count($data['feedback_summary']),
            'total_feedback' => array_sum(array_column($data['feedback_summary'], 'feedback_count')),
            'average_rating' => array_sum(array_filter(array_column($data['feedback_summary'], 'avg_rating'))) / 
                              count(array_filter(array_column($data['feedback_summary'], 'avg_rating')))
        ];
        break;

    case 'hod':
        // Get department exit survey summary
        $stmt = mysqli_prepare($conn, "CALL GetDepartmentExitSurveySummary(?, ?)");
        mysqli_stmt_bind_param($stmt, "ii", $user['department_id'], $current_academic_year['id']);
        mysqli_stmt_execute($stmt);
        $data['exit_survey_summary'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        // Fetch faculty feedback summary
        $faculty_query = "SELECT f.id, f.name, f.designation,
                         COUNT(DISTINCT s.id) as total_subjects,
                         COUNT(DISTINCT fb.id) as total_feedback,
                         AVG(fb.cumulative_avg) as avg_rating
                         FROM faculty f
                         LEFT JOIN subjects s ON s.faculty_id = f.id
                         LEFT JOIN feedback fb ON fb.subject_id = s.id 
                         WHERE f.department_id = ? 
                         AND f.is_active = TRUE
                         AND (fb.academic_year_id = ? OR fb.academic_year_id IS NULL)
                         GROUP BY f.id
                         ORDER BY f.name";
        $stmt = mysqli_prepare($conn, $faculty_query);
        mysqli_stmt_bind_param($stmt, "ii", $user['department_id'], $current_academic_year['id']);
        mysqli_stmt_execute($stmt);
        $data['faculty'] = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - College Feedback System</title>
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
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }

        .dashboard-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .dashboard-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .user-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1rem;
        }

        .info-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .info-card p {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .stat-card .label {
            color: #666;
            font-size: 1rem;
        }

        .content-section {
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
            color: var(--text-color);
        }

        .subject-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .subject-card:hover {
            transform: translateY(-3px);
        }

        .subject-info h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .subject-info p {
            font-size: 0.9rem;
            color: #666;
        }

        .subject-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        @media (max-width: 768px) {
            .dashboard-container {
                padding: 0 1rem;
            }

            .user-info {
                grid-template-columns: 1fr;
            }

            .subject-card {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .subject-actions {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem;
            border-radius: 5px;
            background: var(--card-bg);
            box-shadow: var(--shadow);
            display: none;
            z-index: 1000;
        }

        .notification.success {
            border-left: 4px solid var(--secondary-color);
        }

        .notification.warning {
            border-left: 4px solid var(--warning-color);
        }

        .feedback-history-container {
            display: grid;
            gap: 1.5rem;
        }

        .feedback-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .feedback-card:hover {
            transform: translateY(-3px);
        }

        .feedback-info {
            flex: 1;
        }

        .feedback-info h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .faculty-name, .submission-date {
            font-size: 0.9rem;
            color: #666;
            margin: 0.3rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .feedback-rating {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 1rem;
            min-width: 150px;
        }

        .rating-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 1rem;
        }

        .rating-number {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .rating-label {
            font-size: 0.7rem;
            color: #666;
            text-align: center;
        }

        .btn-view {
            background: var(--bg-color);
            color: var(--primary-color);
            padding: 0.6rem 1.2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .no-feedback {
            text-align: center;
            padding: 3rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .no-feedback i {
            font-size: 3rem;
            color: #666;
            margin-bottom: 1rem;
        }

        .no-feedback p {
            color: #666;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .feedback-card {
                flex-direction: column;
                text-align: center;
                gap: 1.5rem;
            }

            .feedback-rating {
                width: 100%;
            }

            .btn-view {
                width: 100%;
                justify-content: center;
            }
        }

        .feedback-stats {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .stat-badge {
            background: var(--bg-color);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stat-badge i {
            color: var(--primary-color);
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            color: #666;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="dashboard-container">
        <div class="dashboard-header">
            <h1>Welcome, <?php echo htmlspecialchars($user['name'] ?? $user['username']); ?></h1>
            <div class="user-info">
                <div class="info-card">
                    <h3>Department</h3>
                    <p><?php echo htmlspecialchars($user['department_name']); ?></p>
                </div>
                <?php if ($role == 'student'): ?>
                    <div class="info-card">
                        <h3>Batch</h3>
                        <p><?php echo htmlspecialchars($user['batch_name']); ?></p>
                    </div>
                    <div class="info-card">
                        <h3>Current Year</h3>
                        <p><?php echo htmlspecialchars($user['current_year_of_study']); ?> Year</p>
                    </div>
                    <div class="info-card">
                        <h3>Current Semester</h3>
                        <p>Semester <?php echo htmlspecialchars($user['current_semester']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($role == 'student'): ?>
            <!-- Student Dashboard Content -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-book icon"></i>
                    <div class="number"><?php echo $data['feedback_stats']['total_subjects']; ?></div>
                    <div class="label">Total Subjects</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle icon"></i>
                    <div class="number"><?php echo $data['feedback_stats']['completed_feedback']; ?></div>
                    <div class="label">Feedback Submitted</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-clock icon"></i>
                    <div class="number"><?php echo $data['feedback_stats']['total_subjects'] - $data['feedback_stats']['completed_feedback']; ?></div>
                    <div class="label">Pending Feedback</div>
                </div>
            </div>

            <div class="content-section">
                <h2 class="section-title">Current Semester Subjects</h2>
                <?php if (!empty($data['subjects'])): ?>
                    <?php foreach ($data['subjects'] as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-info">
                                <h3><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
                                <p>Faculty: <?php echo htmlspecialchars($subject['faculty_name']); ?></p>
                            </div>
                            <div class="subject-actions">
                                <span class="status-badge <?php echo $subject['feedback_status'] === 'Submitted' ? 'status-completed' : 'status-pending'; ?>">
                                    <?php echo htmlspecialchars($subject['feedback_status']); ?>
                                </span>
                                <?php if ($subject['feedback_status'] === 'Pending'): ?>
                                    <a href="give_feedback.php?subject_id=<?php echo $subject['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-comment"></i> Give Feedback
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No subjects found for the current semester.</p>
                <?php endif; ?>
            </div>

            <?php if ($data['show_exit_survey']): ?>
                <div class="content-section">
                    <h2 class="section-title">Exit Survey</h2>
                    <p>As you're in your final semester, please complete the exit survey.</p>
                    <a href="exit_survey.php" class="btn btn-warning">
                        <i class="fas fa-poll"></i> Complete Exit Survey
                    </a>
                </div>
            <?php endif; ?>

            <div class="content-section">
                <h2 class="section-title">Feedback History</h2>
                <?php
                // Fetch feedback history
                $feedback_history_query = "SELECT 
                    f.id,
                    s.name as subject_name,
                    s.code as subject_code,
                    fac.name as faculty_name,
                    f.submitted_at,
                    f.cumulative_avg
                FROM feedback f
                JOIN subjects s ON f.subject_id = s.id
                JOIN faculty fac ON s.faculty_id = fac.id
                WHERE f.student_id = ?
                ORDER BY f.submitted_at DESC";
                
                $history_stmt = mysqli_prepare($conn, $feedback_history_query);
                mysqli_stmt_bind_param($history_stmt, "i", $user_id);
                mysqli_stmt_execute($history_stmt);
                $feedback_history = mysqli_fetch_all(mysqli_stmt_get_result($history_stmt), MYSQLI_ASSOC);
                ?>

                <?php if (!empty($feedback_history)): ?>
                    <div class="feedback-history-container">
                        <?php foreach ($feedback_history as $feedback): ?>
                            <div class="feedback-card">
                                <div class="feedback-info">
                                    <h3><?php echo htmlspecialchars($feedback['subject_name']); ?> 
                                        (<?php echo htmlspecialchars($feedback['subject_code']); ?>)</h3>
                                    <p class="faculty-name">
                                        <i class="fas fa-user-tie"></i> 
                                        <?php echo htmlspecialchars($feedback['faculty_name']); ?>
                                    </p>
                                    <p class="submission-date">
                                        <i class="fas fa-calendar-alt"></i> 
                                        <?php echo date('F j, Y, g:i a', strtotime($feedback['submitted_at'])); ?>
                                    </p>
                                </div>
                                <div class="feedback-rating">
                                    <div class="rating-circle">
                                        <span class="rating-number">
                                            <?php echo number_format($feedback['cumulative_avg'], 2); ?>
                                        </span>
                                        <span class="rating-label">Average Rating</span>
                                    </div>
                                    <a href="view_my_feedback.php?feedback_id=<?php echo $feedback['id']; ?>" 
                                       class="btn btn-view">
                                        <i class="fas fa-eye"></i> View Details
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="no-feedback">
                        <i class="fas fa-clipboard-list"></i>
                        <p>No feedback submitted yet.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($role == 'faculty'): ?>
            <!-- Faculty Dashboard Content -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-book icon"></i>
                    <div class="number"><?php echo $data['overall_stats']['total_subjects']; ?></div>
                    <div class="label">Total Subjects</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-comments icon"></i>
                    <div class="number"><?php echo $data['overall_stats']['total_feedback']; ?></div>
                    <div class="label">Total Feedback Received</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star icon"></i>
                    <div class="number"><?php echo number_format($data['overall_stats']['average_rating'], 2); ?></div>
                    <div class="label">Average Rating</div>
                </div>
            </div>

            <div class="content-section">
                <h2 class="section-title">Subject Feedback Summary</h2>
                <?php if (!empty($data['feedback_summary'])): ?>
                    <?php foreach ($data['feedback_summary'] as $subject): ?>
                        <div class="subject-card">
                            <div class="subject-info">
                                <h3><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
                                <p>Year: <?php echo htmlspecialchars($subject['year_of_study']); ?> | 
                                   Semester: <?php echo htmlspecialchars($subject['semester']); ?> | 
                                   Section: <?php echo htmlspecialchars($subject['section']); ?></p>
                            </div>
                            <div class="subject-actions">
                                <div class="feedback-stats">
                                    <span class="stat-badge">
                                        <i class="fas fa-comments"></i> 
                                        <?php echo $subject['feedback_count']; ?> Responses
                                    </span>
                                    <?php if ($subject['avg_rating']): ?>
                                        <span class="stat-badge">
                                            <i class="fas fa-star"></i> 
                                            <?php echo number_format($subject['avg_rating'], 2); ?>/5
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <a href="faculty_detailed_feedback.php?subject_id=<?php echo $subject['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-chart-bar"></i> View Details
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="no-data">No subjects assigned for the current academic year.</p>
                <?php endif; ?>
            </div>

        <?php elseif ($role == 'hod' || $role == 'hods'): ?>
            <!-- HOD Dashboard Content -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-users icon"></i>
                    <div class="number"><?php echo $data['dept_stats']['total_faculty']; ?></div>
                    <div class="label">Total Faculty</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-book icon"></i>
                    <div class="number"><?php echo $data['dept_stats']['total_subjects']; ?></div>
                    <div class="label">Total Subjects</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star icon"></i>
                    <div class="number"><?php echo number_format($data['dept_stats']['dept_avg_rating'], 2); ?></div>
                    <div class="label">Department Average Rating</div>
                </div>
            </div>

            <div class="content-section">
                <h2 class="section-title">Faculty Feedback Summary</h2>
                <?php if (!empty($data['faculty'])): ?>
                    <?php foreach ($data['faculty'] as $faculty): ?>
                        <div class="subject-card">
                            <div class="subject-info">
                                <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
                                <p>Designation: <?php echo htmlspecialchars($faculty['designation']); ?></p>
                                <p>Subjects: <?php echo $faculty['total_subjects']; ?> | 
                                   Total Feedback: <?php echo $faculty['total_feedback']; ?></p>
                            </div>
                            <div class="subject-actions">
                                <span class="status-badge">
                                    Avg Rating: <?php echo number_format($faculty['avg_rating'], 2); ?>/5
                                </span>
                                <a href="view_faculty_feedback.php?faculty_id=<?php echo $faculty['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-chart-line"></i> View Analysis
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No faculty members found in the department.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="notification" id="notification"></div>

    <script>
        // Notification function
        function showNotification(message, type = 'success') {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification ${type}`;
            notification.style.display = 'block';
            
            setTimeout(() => {
                notification.style.display = 'none';
            }, 3000);
        }

        // Check for pending feedback
        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($role == 'student' && isset($data['feedback_stats'])): ?>
                const pendingFeedback = <?php echo $data['feedback_stats']['total_subjects'] - $data['feedback_stats']['completed_feedback']; ?>;
                if (pendingFeedback > 0) {
                    showNotification(`You have ${pendingFeedback} pending feedback(s)`, 'warning');
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>
