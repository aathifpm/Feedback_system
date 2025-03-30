<?php
session_start();
require_once '../db_connection.php';
require_once '../fpdf.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
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

// Custom PDF class
class FeedbackReport extends FPDF {
    function Header() {
        // Logo - you can add your college logo here
        // $this->Image('logo.png', 10, 6, 30);
        
        // Title
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(0, 10, 'Feedback System Report', 0, 1, 'C');
        $this->SetFont('Arial', '', 12);
        $this->Cell(0, 10, 'Generated on: ' . date('d-m-Y'), 0, 1, 'C');
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function ChapterTitle($title) {
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        $this->Ln(5);
    }

    function StatisticsTable($header, $data) {
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(200, 220, 255);
        
        // Calculate column widths based on content
        $w = array(60, 40, 40, 50);
        
        // Header
        for($i = 0; $i < count($header); $i++) {
            $this->Cell($w[$i], 7, $header[$i], 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Data
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(245, 245, 245);
        $fill = false;
        foreach($data as $row) {
            $this->Cell($w[0], 6, $row[0], 1, 0, 'L', $fill);
            $this->Cell($w[1], 6, $row[1], 1, 0, 'C', $fill);
            $this->Cell($w[2], 6, $row[2], 1, 0, 'C', $fill);
            $this->Cell($w[3], 6, $row[3], 1, 0, 'C', $fill);
            $this->Ln();
            $fill = !$fill;
        }
        $this->Ln(10);
    }

    function RatingBox($label, $value) {
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(60, 8, $label . ':', 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->Cell(30, 8, number_format($value, 2) . ' / 5.00', 0, 1);
    }
}

try {
    // Create PDF instance
    $pdf = new FeedbackReport();
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // Add report filters info
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 10, 'Academic Year: ' . $year_info['year_range'], 0, 1);
    
    if($department) {
        $dept_query = "SELECT name FROM departments WHERE id = ?";
        $stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($stmt, "i", $department);
        mysqli_stmt_execute($stmt);
        $dept_result = mysqli_stmt_get_result($stmt);
        $dept_info = mysqli_fetch_assoc($dept_result);
        $pdf->Cell(0, 10, 'Department: ' . $dept_info['name'], 0, 1);
    }
    $pdf->Ln(5);

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

    if($department) {
        $stats_query .= " AND s.department_id = ?";
    }

    $stmt = mysqli_prepare($conn, $stats_query);
    if($department) {
        mysqli_stmt_bind_param($stmt, "ii", $academic_year, $department);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $academic_year);
    }
    mysqli_stmt_execute($stmt);
    $stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    // Overall Statistics Section
    $pdf->ChapterTitle('Overall Statistics');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 8, 'Total Feedback: ' . $stats['total_feedback'], 0, 1);
    $pdf->Cell(0, 8, 'Students Participated: ' . $stats['total_students'], 0, 1);
    $pdf->Cell(0, 8, 'Subjects Covered: ' . $stats['total_subjects'], 0, 1);
    $pdf->Cell(0, 8, 'Faculty Members: ' . $stats['total_faculty'], 0, 1);
    $pdf->Ln(5);

    // Rating Analysis
    $pdf->ChapterTitle('Rating Analysis');
    $pdf->RatingBox('Course Effectiveness', $stats['avg_course_effectiveness']);
    $pdf->RatingBox('Teaching Effectiveness', $stats['avg_teaching_effectiveness']);
    $pdf->RatingBox('Resources & Administration', $stats['avg_resources_admin']);
    $pdf->RatingBox('Assessment & Learning', $stats['avg_assessment_learning']);
    $pdf->RatingBox('Course Outcomes', $stats['avg_course_outcomes']);
    $pdf->RatingBox('Overall Rating', $stats['overall_avg']);
    $pdf->Ln(10);

    // Department Analysis
    $pdf->AddPage();
    $pdf->ChapterTitle('Department Analysis');

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

    $header = array('Department', 'Feedback', 'Faculty', 'Avg Rating');
    $data = array();
    while($row = mysqli_fetch_assoc($dept_result)) {
        $data[] = array(
            $row['department_name'],
            $row['feedback_count'],
            $row['faculty_count'],
            number_format($row['avg_rating'], 2)
        );
    }
    $pdf->StatisticsTable($header, $data);

    // Top Faculty
    $pdf->AddPage();
    $pdf->ChapterTitle('Top Performing Faculty');

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

    if($department) {
        $faculty_query .= " AND f.department_id = ?";
    }

    $faculty_query .= " GROUP BY f.id
    HAVING feedback_count >= 10
    ORDER BY avg_rating DESC
    LIMIT 10";

    $stmt = mysqli_prepare($conn, $faculty_query);
    if($department) {
        mysqli_stmt_bind_param($stmt, "ii", $academic_year, $department);
    } else {
        mysqli_stmt_bind_param($stmt, "i", $academic_year);
    }
    mysqli_stmt_execute($stmt);
    $faculty_result = mysqli_stmt_get_result($stmt);

    $header = array('Faculty Name', 'Department', 'Feedback', 'Rating');
    $data = array();
    while($row = mysqli_fetch_assoc($faculty_result)) {
        $data[] = array(
            $row['faculty_name'],
            $row['department_name'],
            $row['feedback_count'],
            number_format($row['avg_rating'], 2)
        );
    }
    $pdf->StatisticsTable($header, $data);

    // Output PDF
    $pdf->Output('Feedback_Report_' . date('Y-m-d') . '.pdf', 'D');

} catch (Exception $e) {
    // Log error and show user-friendly message
    error_log('PDF Generation Error: ' . $e->getMessage());
    die('Error generating PDF report. Please try again later.');
}
?> 