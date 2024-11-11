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

// Add role validation
$allowed_roles = ['admin', 'faculty', 'hod', 'student'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: logout.php');
    exit();
}

// Add error handling for database queries
function handleDatabaseError($stmt, $error_message) {
    if (!$stmt) {
        error_log("Database error: " . mysqli_error($GLOBALS['conn']));
        die($error_message);
    }
}

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

        $stmt = mysqli_prepare($conn, $user_query);
        mysqli_stmt_bind_param($stmt, "iii", 
            $current_academic_year['id'],
            $current_academic_year['id'],
            $user_id
        );
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
            'average_rating' => !empty(array_filter(array_column($data['feedback_summary'], 'avg_rating'))) 
                ? array_sum(array_filter(array_column($data['feedback_summary'], 'avg_rating'))) / 
                  count(array_filter(array_column($data['feedback_summary'], 'avg_rating')))
                : 0
        ];
        break;

    case 'hod':
        // Get department exit survey summary
        $exit_survey_query = "SELECT 
            COUNT(*) as total_surveys,
            AVG(
                CASE 
                    WHEN po_ratings IS NOT NULL 
                    THEN (
                        SELECT CAST(REPLACE(REPLACE(po_ratings, '[', ''), ']', '') AS DECIMAL(10,2))
                    )
                    ELSE 0 
                END
            ) as po_avg,
            AVG(
                CASE 
                    WHEN pso_ratings IS NOT NULL 
                    THEN (
                        SELECT CAST(REPLACE(REPLACE(pso_ratings, '[', ''), ']', '') AS DECIMAL(10,2))
                    )
                    ELSE 0 
                END
            ) as pso_avg,
            SUM(
                CASE 
                    WHEN employment_status LIKE '%\"status\":\"employed\"%' THEN 1 
                    ELSE 0 
                END
            ) as employed_count,
            SUM(
                CASE 
                    WHEN employment_status LIKE '%\"status\":\"higher_studies\"%' THEN 1 
                    ELSE 0 
                END
            ) as higher_studies_count
        FROM exit_surveys
        WHERE department_id = ? 
        AND academic_year_id = ?";
        
        $exit_survey_stmt = mysqli_prepare($conn, $exit_survey_query);
        if (!$exit_survey_stmt) {
            error_log("Error preparing exit survey query: " . mysqli_error($conn));
            $data['exit_survey_summary'] = [
                'total_surveys' => 0,
                'po_avg' => 0,
                'pso_avg' => 0,
                'employed_count' => 0,
                'higher_studies_count' => 0
            ];
        } else {
            mysqli_stmt_bind_param($exit_survey_stmt, "ii", 
                $user['department_id'], 
                $current_academic_year['id']
            );
            
            if (!mysqli_stmt_execute($exit_survey_stmt)) {
                error_log("Error executing exit survey query: " . mysqli_stmt_error($exit_survey_stmt));
                $data['exit_survey_summary'] = [
                    'total_surveys' => 0,
                    'po_avg' => 0,
                    'pso_avg' => 0,
                    'employed_count' => 0,
                    'higher_studies_count' => 0
                ];
            } else {
                $result = mysqli_stmt_get_result($exit_survey_stmt);
                $data['exit_survey_summary'] = mysqli_fetch_assoc($result);
                
                // Ensure we have numeric values
                $data['exit_survey_summary']['po_avg'] = 
                    number_format($data['exit_survey_summary']['po_avg'] ?? 0, 2);
                $data['exit_survey_summary']['pso_avg'] = 
                    number_format($data['exit_survey_summary']['pso_avg'] ?? 0, 2);
                $data['exit_survey_summary']['employed_count'] = 
                    intval($data['exit_survey_summary']['employed_count'] ?? 0);
                $data['exit_survey_summary']['higher_studies_count'] = 
                    intval($data['exit_survey_summary']['higher_studies_count'] ?? 0);
            }
        }

        // Fetch faculty feedback summary with detailed metrics
        $faculty_query = "SELECT 
            f.id, 
            f.name,
            f.faculty_id,  -- Added faculty_id from DB structure
            f.designation,
            f.experience,  -- Added experience from DB structure
            f.qualification,  -- Added qualification from DB structure
            f.specialization,  -- Added specialization from DB structure
            d.name as department_name,
            COUNT(DISTINCT s.id) as total_subjects,
            COUNT(DISTINCT fb.id) as total_feedback,
            AVG(fb.course_effectiveness_avg) as course_effectiveness,
            AVG(fb.teaching_effectiveness_avg) as teaching_effectiveness,
            AVG(fb.resources_admin_avg) as resources_admin,
            AVG(fb.assessment_learning_avg) as assessment_learning,
            AVG(fb.course_outcomes_avg) as course_outcomes,
            AVG(fb.cumulative_avg) as overall_avg,
            MIN(fb.cumulative_avg) as min_rating,
            MAX(fb.cumulative_avg) as max_rating
        FROM faculty f
        JOIN departments d ON f.department_id = d.id
        LEFT JOIN subjects s ON s.faculty_id = f.id 
            AND s.academic_year_id = ?
            AND s.is_active = TRUE
        LEFT JOIN feedback fb ON fb.subject_id = s.id 
            AND fb.academic_year_id = ?
        WHERE f.department_id = ? 
        AND f.is_active = TRUE
        GROUP BY f.id, f.name, f.faculty_id, f.designation, f.experience, 
                 f.qualification, f.specialization, d.name
        ORDER BY f.name";
        
        $faculty_stmt = mysqli_prepare($conn, $faculty_query);
        if (!$faculty_stmt) {
            error_log("Error preparing faculty query: " . mysqli_error($conn));
            $data['faculty'] = [];
        } else {
            mysqli_stmt_bind_param($faculty_stmt, "iii", 
                $current_academic_year['id'],
                $current_academic_year['id'], 
                $user['department_id']
            );
            
            if (!mysqli_stmt_execute($faculty_stmt)) {
                error_log("Error executing faculty query: " . mysqli_stmt_error($faculty_stmt));
                $data['faculty'] = [];
            } else {
                $data['faculty'] = mysqli_fetch_all(mysqli_stmt_get_result($faculty_stmt), MYSQLI_ASSOC);
                
                // Format the ratings and handle NULL values
                foreach ($data['faculty'] as &$faculty) {
                    $faculty['overall_avg'] = number_format($faculty['overall_avg'] ?? 0, 2);
                    $faculty['course_effectiveness'] = number_format($faculty['course_effectiveness'] ?? 0, 2);
                    $faculty['teaching_effectiveness'] = number_format($faculty['teaching_effectiveness'] ?? 0, 2);
                    $faculty['resources_admin'] = number_format($faculty['resources_admin'] ?? 0, 2);
                    $faculty['assessment_learning'] = number_format($faculty['assessment_learning'] ?? 0, 2);
                    $faculty['course_outcomes'] = number_format($faculty['course_outcomes'] ?? 0, 2);
                    $faculty['min_rating'] = number_format($faculty['min_rating'] ?? 0, 2);
                    $faculty['max_rating'] = number_format($faculty['max_rating'] ?? 0, 2);
                    $faculty['total_subjects'] = intval($faculty['total_subjects']);
                    $faculty['total_feedback'] = intval($faculty['total_feedback']);
                }
            }
        }

        // Add debug information
        error_log("Number of faculty members found: " . count($data['faculty']));
        error_log("Faculty Query: " . $faculty_query);
        error_log("Department ID: " . $user['department_id']);
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

        .analysis-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin: 1.5rem 0;
        }

        .analysis-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .faculty-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 0.8rem;
            margin-top: 0.8rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .faculty-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .faculty-card:hover {
            transform: translateY(-5px);
        }

        .faculty-header {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .faculty-id {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .faculty-details {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.5rem;
        }

        .faculty-details p {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .faculty-details i {
            color: var(--primary-color);
        }

        .feedback-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-group {
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
            display: block;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .rating-categories {
            margin-bottom: 1.5rem;
        }

        .rating-item {
            margin-bottom: 1rem;
        }

        .rating-label {
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .rating-bar {
            background: var(--bg-color);
            height: 25px;
            border-radius: 12.5px;
            box-shadow: var(--inner-shadow);
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            display: flex;
            align-items: center;
            padding: 0 1rem;
            color: white;
            font-weight: 500;
            transition: width 0.3s ease;
        }

        .rating-range {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin: 1.5rem 0;
            color: #666;
        }

        .range-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .faculty-actions {
            text-align: center;
            margin-top: 1.5rem;
        }

        @media (max-width: 768px) {
            .faculty-card {
                padding: 1.5rem;
            }

            .stat-group {
                flex-direction: column;
            }

            .rating-range {
                flex-direction: column;
                gap: 1rem;
                align-items: center;
            }
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

        <?php elseif ($role === 'hod'): ?>
            <!-- HOD Dashboard Content -->
            <div class="stats-container">
                <div class="stat-card">
                    <i class="fas fa-users icon"></i>
                    <div class="number"><?php echo $user['total_faculty']; ?></div>
                    <div class="label">Total Faculty</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-book icon"></i>
                    <div class="number"><?php echo $user['total_subjects']; ?></div>
                    <div class="label">Total Subjects</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-star icon"></i>
                    <div class="number"><?php echo number_format($user['dept_avg_rating'], 2); ?></div>
                    <div class="label">Department Average Rating</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-poll icon"></i>
                    <div class="number"><?php echo $data['exit_survey_summary']['total_surveys'] ?? 0; ?></div>
                    <div class="label">Exit Surveys Completed</div>
                </div>
            </div>

            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-chalkboard-teacher"></i>
                    Faculty Performance Analysis
                </h2>
                <?php if (!empty($data['faculty'])): ?>
                    <?php foreach ($data['faculty'] as $faculty): ?>
                        <div class="faculty-card">
                            <div class="faculty-header">
                                <h3><?php echo htmlspecialchars($faculty['name']); ?></h3>
                                <p class="faculty-id">Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id']); ?></p>
                                <p class="designation">
                                    <i class="fas fa-user-tag"></i> 
                                    <?php echo htmlspecialchars($faculty['designation']); ?>
                                </p>
                                <div class="faculty-details">
                                    <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($faculty['qualification']); ?></p>
                                    <p><i class="fas fa-briefcase"></i> <?php echo htmlspecialchars($faculty['experience']); ?> years experience</p>
                                    <p><i class="fas fa-book"></i> <?php echo htmlspecialchars($faculty['specialization']); ?></p>
                                </div>
                            </div>

                            <div class="feedback-stats">
                                <div class="stat-group">
                                    <div class="stat-item">
                                        <i class="fas fa-book"></i>
                                        <span class="stat-value"><?php echo $faculty['total_subjects']; ?></span>
                                        <span class="stat-label">Subjects</span>
                                    </div>
                                    <div class="stat-item">
                                        <i class="fas fa-comments"></i>
                                        <span class="stat-value"><?php echo $faculty['total_feedback']; ?></span>
                                        <span class="stat-label">Feedbacks</span>
                                    </div>
                                    <div class="stat-item">
                                        <i class="fas fa-star"></i>
                                        <span class="stat-value"><?php echo $faculty['overall_avg']; ?></span>
                                        <span class="stat-label">Overall Rating</span>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-categories">
                                <div class="rating-item">
                                    <div class="rating-label">Course Effectiveness</div>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($faculty['course_effectiveness'] * 20); ?>%">
                                            <?php echo $faculty['course_effectiveness']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Teaching Effectiveness</div>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($faculty['teaching_effectiveness'] * 20); ?>%">
                                            <?php echo $faculty['teaching_effectiveness']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Resources & Administration</div>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($faculty['resources_admin'] * 20); ?>%">
                                            <?php echo $faculty['resources_admin']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Assessment & Learning</div>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($faculty['assessment_learning'] * 20); ?>%">
                                            <?php echo $faculty['assessment_learning']; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="rating-item">
                                    <div class="rating-label">Course Outcomes</div>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($faculty['course_outcomes'] * 20); ?>%">
                                            <?php echo $faculty['course_outcomes']; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="rating-range">
                                <span class="range-item">
                                    <i class="fas fa-arrow-down"></i>
                                    Min: <?php echo $faculty['min_rating']; ?>
                                </span>
                                <span class="range-item">
                                    <i class="fas fa-arrow-up"></i>
                                    Max: <?php echo $faculty['max_rating']; ?>
                                </span>
                            </div>

                            <div class="faculty-actions">
                                <a href="view_faculty_feedback.php?faculty_id=<?php echo $faculty['id']; ?>" 
                                   class="btn btn-primary">
                                    <i class="fas fa-chart-line"></i> View Detailed Analysis
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>No faculty feedback data available for the current academic year.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-graduation-cap"></i>
                    Exit Survey Analysis
                </h2>
                <?php if (isset($data['exit_survey_summary']) && !empty($data['exit_survey_summary'])): ?>
                    <div class="analysis-grid">
                        <div class="analysis-card">
                            <h4>Program Outcomes</h4>
                            <div class="rating-circle">
                                <?php echo number_format($data['exit_survey_summary']['po_avg'], 2); ?>
                            </div>
                            <p>Average PO Rating</p>
                        </div>
                        <div class="analysis-card">
                            <h4>Program Specific Outcomes</h4>
                            <div class="rating-circle">
                                <?php echo number_format($data['exit_survey_summary']['pso_avg'], 2); ?>
                            </div>
                            <p>Average PSO Rating</p>
                        </div>
                        <div class="analysis-card">
                            <h4>Employment Status</h4>
                            <div class="stat-value">
                                <?php echo $data['exit_survey_summary']['employed_count']; ?>
                            </div>
                            <p>Students Employed</p>
                        </div>
                        <div class="analysis-card">
                            <h4>Higher Studies</h4>
                            <div class="stat-value">
                                <?php echo $data['exit_survey_summary']['higher_studies_count']; ?>
                            </div>
                            <p>Pursuing Higher Studies</p>
                        </div>
                    </div>
                    <div class="action-buttons">
                        <a href="survey_analytics.php" class="btn btn-primary">
                            <i class="fas fa-chart-bar"></i> View Detailed Analysis
                        </a>
                        <a href="generate_report.php?type=exit_survey" class="btn btn-secondary">
                            <i class="fas fa-file-pdf"></i> Generate Report
                        </a>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-info-circle"></i>
                        <p>No exit survey data available for the current academic year.</p>
                    </div>
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
