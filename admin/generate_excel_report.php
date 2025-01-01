<?php
session_start();
require_once '../db_connection.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get parameters with validation
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : null;
$department = isset($_GET['department']) ? intval($_GET['department']) : null;

if (!$academic_year) {
    die('Academic year is required');
}

// Fetch academic year info
$year_query = "SELECT year_range FROM academic_years WHERE id = ?";
$stmt = mysqli_prepare($conn, $year_query);
mysqli_stmt_bind_param($stmt, "i", $academic_year);
mysqli_stmt_execute($stmt);
$year_result = mysqli_stmt_get_result($stmt);
$year_info = mysqli_fetch_assoc($year_result);

if (!$year_info) {
    die('Invalid academic year');
}

try {
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Feedback_Report_' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel to properly display special characters
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write report header
    fputcsv($output, array('Feedback System Report'));
    fputcsv($output, array('Generated on: ' . date('d-m-Y')));
    fputcsv($output, array('Academic Year: ' . $year_info['year_range']));
    
    if ($department) {
        $dept_query = "SELECT name FROM departments WHERE id = ?";
        $stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($stmt, "i", $department);
        mysqli_stmt_execute($stmt);
        $dept_result = mysqli_stmt_get_result($stmt);
        $dept_info = mysqli_fetch_assoc($dept_result);
        fputcsv($output, array('Department: ' . $dept_info['name']));
    }
    
    fputcsv($output, array()); // Empty line for spacing

    // Overall Statistics
    $stats_query = "SELECT 
        COUNT(DISTINCT f.id) as total_feedback,
        COUNT(DISTINCT f.student_id) as total_students,
        COUNT(DISTINCT s.id) as total_subjects,
        COUNT(DISTINCT s.faculty_id) as total_faculty,
        ROUND(AVG(f.course_effectiveness_avg), 2) as avg_course_effectiveness,
        ROUND(AVG(f.teaching_effectiveness_avg), 2) as avg_teaching_effectiveness,
        ROUND(AVG(f.resources_admin_avg), 2) as avg_resources_admin,
        ROUND(AVG(f.assessment_learning_avg), 2) as avg_assessment_learning,
        ROUND(AVG(f.course_outcomes_avg), 2) as avg_course_outcomes,
        ROUND(AVG(f.cumulative_avg), 2) as overall_avg
    FROM feedback f
    JOIN subjects s ON f.subject_id = s.id
    WHERE f.academic_year_id = ?";

    if ($department) {
        $stats_query .= " AND s.department_id = ?";
    }

    $stmt = mysqli_prepare($conn, $stats_query);
    if ($department) {
        mysqli_stmt_bind_param($stmt, "ii", $academic_year, $department);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $academic_year);
    }
    mysqli_stmt_execute($stmt);
    $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Write Overall Statistics
    fputcsv($output, array('Overall Statistics'));
    fputcsv($output, array('Metric', 'Value'));
    fputcsv($output, array('Total Feedback', $stats['total_feedback']));
    fputcsv($output, array('Students Participated', $stats['total_students']));
    fputcsv($output, array('Subjects Covered', $stats['total_subjects']));
    fputcsv($output, array('Faculty Members', $stats['total_faculty']));
    fputcsv($output, array());

    // Write Rating Analysis
    fputcsv($output, array('Rating Analysis'));
    fputcsv($output, array('Parameter', 'Rating (out of 5)'));
    fputcsv($output, array('Course Effectiveness', number_format($stats['avg_course_effectiveness'], 2)));
    fputcsv($output, array('Teaching Effectiveness', number_format($stats['avg_teaching_effectiveness'], 2)));
    fputcsv($output, array('Resources & Administration', number_format($stats['avg_resources_admin'], 2)));
    fputcsv($output, array('Assessment & Learning', number_format($stats['avg_assessment_learning'], 2)));
    fputcsv($output, array('Course Outcomes', number_format($stats['avg_course_outcomes'], 2)));
    fputcsv($output, array('Overall Rating', number_format($stats['overall_avg'], 2)));
    fputcsv($output, array());

    // Department Analysis
    $dept_query = "SELECT 
        d.name as department_name,
        COUNT(DISTINCT f.id) as feedback_count,
        COUNT(DISTINCT s.faculty_id) as faculty_count,
        ROUND(AVG(f.cumulative_avg), 2) as avg_rating
    FROM departments d
    LEFT JOIN subjects s ON d.id = s.department_id
    LEFT JOIN feedback f ON s.id = f.subject_id AND f.academic_year_id = ?
    GROUP BY d.id
    ORDER BY avg_rating DESC";

    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $academic_year);
    mysqli_stmt_execute($stmt);
    $dept_result = mysqli_stmt_get_result($stmt);

    fputcsv($output, array('Department Analysis'));
    fputcsv($output, array('Department', 'Feedback Count', 'Faculty Count', 'Average Rating'));
    
    while ($row = mysqli_fetch_assoc($dept_result)) {
        fputcsv($output, array(
            $row['department_name'],
            $row['feedback_count'],
            $row['faculty_count'],
            number_format($row['avg_rating'], 2)
        ));
    }
    fputcsv($output, array());

    // Top Faculty Analysis
    $faculty_query = "SELECT 
        f.name as faculty_name,
        d.name as department_name,
        COUNT(DISTINCT fb.id) as feedback_count,
        ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
    FROM faculty f
    JOIN departments d ON f.department_id = d.id
    JOIN subjects s ON f.id = s.faculty_id
    JOIN feedback fb ON s.id = fb.subject_id
    WHERE fb.academic_year_id = ?";

    if ($department) {
        $faculty_query .= " AND f.department_id = ?";
    }

    $faculty_query .= " GROUP BY f.id
    HAVING feedback_count >= 10
    ORDER BY avg_rating DESC
    LIMIT 10";

    $stmt = mysqli_prepare($conn, $faculty_query);
    if ($department) {
        mysqli_stmt_bind_param($stmt, "ii", $academic_year, $department);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $academic_year);
    }
    mysqli_stmt_execute($stmt);
    $faculty_result = mysqli_stmt_get_result($stmt);

    fputcsv($output, array('Top Performing Faculty'));
    fputcsv($output, array('Faculty Name', 'Department', 'Feedback Count', 'Average Rating'));
    
    while ($row = mysqli_fetch_assoc($faculty_result)) {
        fputcsv($output, array(
            $row['faculty_name'],
            $row['department_name'],
            $row['feedback_count'],
            number_format($row['avg_rating'], 2)
        ));
    }

    // Close the output stream
    fclose($output);
    
} catch (Exception $e) {
    // Log error and show user-friendly message
    error_log('CSV Generation Error: ' . $e->getMessage());
    die('Error generating report. Please try again later.');
}
?> 