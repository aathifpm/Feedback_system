<?php
ob_start();
error_reporting(0);

session_start();
require_once 'db_connection.php';
require_once 'functions.php';
require_once 'fpdf.php';

// Check if user is logged in and is a HOD
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'hod') {
    header('Location: index.php');
    exit();
}

// Get parameters
$faculty_id = intval($_GET['faculty_id']);
$academic_year = intval($_GET['academic_year']);
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;

// Get faculty details
$faculty_query = "SELECT f.*, d.name as department_name 
                 FROM faculty f 
                 JOIN departments d ON f.department_id = d.id 
                 WHERE f.id = ? AND f.is_active = TRUE";
$stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($stmt, "i", $faculty_id);
mysqli_stmt_execute($stmt);
$faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$faculty) {
    die("Faculty not found");
}

// Get academic year details
$year_query = "SELECT * FROM academic_years WHERE id = ?";
$stmt = mysqli_prepare($conn, $year_query);
mysqli_stmt_bind_param($stmt, "i", $academic_year);
mysqli_stmt_execute($stmt);
$academic_year_data = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Build subject assignments query
$assignments_query = "SELECT 
    sa.id,
    sa.year,
    sa.semester,
    sa.section,
    s.name as subject_name,
    s.code as subject_code,
    COUNT(DISTINCT fb.id) as feedback_count,
    AVG(fb.course_effectiveness_avg) as course_effectiveness,
    AVG(fb.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(fb.resources_admin_avg) as resources_admin,
    AVG(fb.assessment_learning_avg) as assessment_learning,
    AVG(fb.course_outcomes_avg) as course_outcomes,
    AVG(fb.cumulative_avg) as overall_avg
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
LEFT JOIN feedback fb ON fb.assignment_id = sa.id
WHERE sa.faculty_id = ? 
AND sa.academic_year_id = ?
AND sa.is_active = TRUE";

if ($semester) {
    $assignments_query .= " AND sa.semester = ?";
}

$assignments_query .= " GROUP BY sa.id
ORDER BY sa.year, sa.semester, sa.section";

$stmt = mysqli_prepare($conn, $assignments_query);
if ($semester) {
    mysqli_stmt_bind_param($stmt, "iii", $faculty_id, $academic_year, $semester);
} else {
    mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $academic_year);
}
mysqli_stmt_execute($stmt);
$assignments = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

class PDF extends FPDF {
    protected $facultyId;
    protected $facultyName;

    function setFacultyInfo($id, $name) {
        $this->facultyId = $id;
        $this->facultyName = $name;
    }

    function Header() {
        // Professional margins and spacing
        $this->SetMargins(20, 20, 20);
        
        // Logo positioning
        if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 20, 15, 30);
        }
        
        // College Name
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(28, 40, 51);
        $this->Cell(30); // Space after logo
        $this->Cell(140, 12, 'Panimalar Engineering College', 0, 1, 'C');
        
        // Department name
        $this->SetFont('Arial', '', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(30);
        $this->Cell(140, 8, 'Department of ' . $faculty['department_name'], 0, 1, 'C');
        
        // Report title with separator
        $this->Ln(4);
        $this->SetDrawColor(189, 195, 199);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->Ln(4);
        
        $this->SetFont('Arial', 'B', 16);
        $this->Cell(0, 10, 'Faculty Performance Analysis Report', 0, 1, 'C');
        
        // Add faculty info if available
        if ($this->facultyId && $this->facultyName) {
            $this->SetFont('Arial', '', 12);
            $this->SetTextColor(52, 73, 94);
            $this->Cell(0, 6, 'Faculty ID: ' . $this->facultyId . ' | Name: ' . $this->facultyName, 0, 1, 'C');
        }
        
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-20);
        $this->SetDrawColor(189, 195, 199);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'L');
        $this->Cell(0, 10, 'Generated on: ' . date('F j, Y'), 0, 0, 'R');
    }

    function SectionTitle($title) {
        $this->Ln(8);
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        
        $this->SetDrawColor(52, 152, 219);
        $this->SetLineWidth(0.4);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 170, $this->GetY());
        $this->Ln(5);
    }

    function CreateInfoBox($label, $value) {
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(60, 8, $label . ':', 0, 0, 'L');
        
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, $value, 0, 1, 'L');
    }

    function RatingBar($label, $value, $maxWidth = 160) {
        $this->SetFont('Arial', '', 11);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(60, 8, $label, 0, 0);
        
        // Draw empty bar
        $this->SetFillColor(245, 247, 250);
        $this->Cell($maxWidth, 8, '', 1, 0, 'L', true);
        
        // Set color based on rating
        if ($value >= 4.5) $this->SetFillColor(46, 204, 113);
        elseif ($value >= 4.0) $this->SetFillColor(52, 152, 219);
        elseif ($value >= 3.5) $this->SetFillColor(241, 196, 15);
        elseif ($value >= 3.0) $this->SetFillColor(230, 126, 34);
        else $this->SetFillColor(231, 76, 60);
        
        // Draw filled portion
        $fillWidth = ($value / 5) * $maxWidth;
        $this->SetX($this->GetX() - $maxWidth);
        $this->Cell($fillWidth, 8, '', 1, 0, 'L', true);
        
        // Add value text
        $this->SetX($this->GetX() + ($maxWidth - $fillWidth));
        $this->SetTextColor(44, 62, 80);
        $this->Cell(20, 8, number_format($value, 2), 0, 1, 'R');
    }

    function CreateMetricsTable($headers, $data) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(245, 247, 250);
        $this->SetTextColor(44, 62, 80);
        $this->SetDrawColor(189, 195, 199);
        $this->SetLineWidth(0.2);

        // Column widths
        $w = array(70, 25, 35, 40);

        // Header
        foreach($headers as $i => $header) {
            $this->Cell($w[$i], 10, $header, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data
        $this->SetFont('Arial', '', 10);
        $fill = false;

        foreach($data as $row) {
            $this->SetFillColor($fill ? 252 : 248, $fill ? 252 : 249, $fill ? 252 : 250);
            
            $this->Cell($w[0], 8, $row[0], 1, 0, 'L', $fill);
            
            // Color code the rating
            $rating = floatval($row[1]);
            if ($rating >= 4.5) $this->SetTextColor(39, 174, 96);
            elseif ($rating >= 4.0) $this->SetTextColor(41, 128, 185);
            elseif ($rating >= 3.5) $this->SetTextColor(243, 156, 18);
            elseif ($rating >= 3.0) $this->SetTextColor(230, 126, 34);
            else $this->SetTextColor(192, 57, 43);
            
            $this->Cell($w[1], 8, $row[1], 1, 0, 'C', $fill);
            
            $this->SetTextColor(44, 62, 80);
            $this->Cell($w[2], 8, $row[2], 1, 0, 'C', $fill);
            $this->Cell($w[3], 8, $row[3], 1, 0, 'C', $fill);
            
            $this->Ln();
            $fill = !$fill;
        }
    }
}

// Create PDF instance
$pdf = new PDF();
$pdf->setFacultyInfo($faculty['faculty_id'], $faculty['name']);
$pdf->AliasNbPages();
$pdf->AddPage();

// Faculty Details
$pdf->SectionTitle('Faculty Details');

// Create info box with better styling
$pdf->SetFillColor(245, 247, 250);
$pdf->Rect($pdf->GetX(), $pdf->GetY(), 170, 45, 'F');

// Personal Info Column
$pdf->SetFont('Arial', 'B', 14);
$pdf->SetTextColor(44, 62, 80);
$pdf->Cell(170, 10, $faculty['name'], 0, 1, 'C');

$pdf->SetFont('Arial', '', 11);
$pdf->SetTextColor(52, 73, 94);

// Two-column layout for details
$x = $pdf->GetX();
$y = $pdf->GetY();

// Left column
$pdf->SetXY($x + 10, $y);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 8, 'Faculty ID:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(45, 8, $faculty['faculty_id'], 0, 0);

// Right column
$pdf->SetXY($x + 85, $y);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 8, 'Department:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(45, 8, $faculty['department_name'], 0, 1);

// Second row
$pdf->SetXY($x + 10, $y + 8);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 8, 'Designation:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(45, 8, $faculty['designation'], 0, 0);

$pdf->SetXY($x + 85, $y + 8);
$pdf->SetFont('Arial', 'B', 10);
$pdf->Cell(30, 8, 'Academic Year:', 0, 0);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(45, 8, $academic_year_data['year_range'], 0, 1);

if ($semester) {
    $pdf->SetXY($x + 10, $y + 16);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(30, 8, 'Semester:', 0, 0);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(45, 8, $semester, 0, 1);
}

$pdf->Ln(10);

// Subject-wise Feedback (only if there are assignments)
if (!empty($assignments)) {
    // Add summary box
    $total_subjects = count($assignments);
    $total_feedback = array_sum(array_column($assignments, 'feedback_count'));
    $avg_rating = $total_subjects > 0 ? array_sum(array_column($assignments, 'overall_avg')) / $total_subjects : 0;

    // Calculate overall averages here (moved from later in the code)
    $overall_stats = [
        'total_feedback' => 0,
        'course_effectiveness' => 0,
        'teaching_effectiveness' => 0,
        'resources_admin' => 0,
        'assessment_learning' => 0,
        'course_outcomes' => 0,
        'overall_avg' => 0
    ];

    foreach ($assignments as $assignment) {
        $overall_stats['total_feedback'] += $assignment['feedback_count'];
        $overall_stats['course_effectiveness'] += $assignment['course_effectiveness'];
        $overall_stats['teaching_effectiveness'] += $assignment['teaching_effectiveness'];
        $overall_stats['resources_admin'] += $assignment['resources_admin'];
        $overall_stats['assessment_learning'] += $assignment['assessment_learning'];
        $overall_stats['course_outcomes'] += $assignment['course_outcomes'];
        $overall_stats['overall_avg'] += $assignment['overall_avg'];
    }

    if ($total_subjects > 0) {
        foreach ($overall_stats as $key => $value) {
            if ($key !== 'total_feedback') {
                $overall_stats[$key] = $value / $total_subjects;
            }
        }
    }

    // Performance Overview Box
    $pdf->SetFillColor(245, 247, 250);
    $pdf->Rect($pdf->GetX(), $pdf->GetY(), 170, 25, 'F');
    
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Cell(170, 8, 'Performance Overview', 0, 1, 'C');
    
    $pdf->SetFont('Arial', '', 10);
    $x = $pdf->GetX();
    // First row of stats
    $pdf->SetX($x + 10);
    $pdf->Cell(70, 8, 'Total Subjects: ' . $total_subjects, 0, 0);
    $pdf->Cell(90, 8, 'Total Feedback Received: ' . $total_feedback, 0, 1);
    
    // Second row of stats with highlighted rating
    $pdf->SetX($x + 10);
    $pdf->Cell(70, 8, 'Academic Year: ' . $academic_year_data['year_range'], 0, 0);
    $pdf->SetFont('Arial', 'B', 10);
    $rating_color = getRatingColor($avg_rating);
    $pdf->SetTextColor($rating_color[0], $rating_color[1], $rating_color[2]);
    $pdf->Cell(90, 8, 'Average Rating: ' . number_format($avg_rating, 2) . ' / 5.00', 0, 1);
    
    $pdf->SetTextColor(44, 62, 80);
    $pdf->Ln(5);

    // Subject-wise Feedback
    $pdf->SectionTitle('Subject-wise Feedback Analysis');

    // Create table header
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 10);
    
    // Column widths
    $w = array(60, 15, 15, 15, 25, 40);
    
    // Header
    $pdf->Cell($w[0], 8, 'Subject', 1, 0, 'C', true);
    $pdf->Cell($w[1], 8, 'Year', 1, 0, 'C', true);
    $pdf->Cell($w[2], 8, 'Sem', 1, 0, 'C', true);
    $pdf->Cell($w[3], 8, 'Sec', 1, 0, 'C', true);
    $pdf->Cell($w[4], 8, 'Feedback', 1, 0, 'C', true);
    $pdf->Cell($w[5], 8, 'Rating', 1, 1, 'C', true);

    // Data rows
    $pdf->SetFont('Arial', '', 9);
    $fill = false;
    
    foreach ($assignments as $assignment) {
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
            // Repeat header
            $pdf->SetFillColor(52, 152, 219);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell($w[0], 8, 'Subject', 1, 0, 'C', true);
            $pdf->Cell($w[1], 8, 'Year', 1, 0, 'C', true);
            $pdf->Cell($w[2], 8, 'Sem', 1, 0, 'C', true);
            $pdf->Cell($w[3], 8, 'Sec', 1, 0, 'C', true);
            $pdf->Cell($w[4], 8, 'Feedback', 1, 0, 'C', true);
            $pdf->Cell($w[5], 8, 'Rating', 1, 1, 'C', true);
            $pdf->SetFont('Arial', '', 9);
        }

        $pdf->SetFillColor($fill ? 252 : 248, $fill ? 252 : 249, $fill ? 252 : 250);
        $pdf->SetTextColor(44, 62, 80);

        // Subject name and code
        $pdf->Cell($w[0], 6, $assignment['subject_name'] . ' (' . $assignment['subject_code'] . ')', 1, 0, 'L', $fill);
        
        // Year, Semester, Section
        $pdf->Cell($w[1], 6, $assignment['year'], 1, 0, 'C', $fill);
        $pdf->Cell($w[2], 6, $assignment['semester'], 1, 0, 'C', $fill);
        $pdf->Cell($w[3], 6, $assignment['section'], 1, 0, 'C', $fill);
        $pdf->Cell($w[4], 6, $assignment['feedback_count'], 1, 0, 'C', $fill);

        // Rating with color
        $rating = $assignment['overall_avg'];
        $color = getRatingColor($rating);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell($w[5], 6, number_format($rating, 2) . ' / 5.00', 1, 1, 'C', $fill);

        $fill = !$fill;
    }

    // Add a summary box below the table
    $pdf->Ln(5);
    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetTextColor(44, 62, 80);
    $pdf->SetFont('Arial', 'B', 10);
    
    // Calculate averages for each metric
    $avg_metrics = [
        'Course Effectiveness' => array_sum(array_column($assignments, 'course_effectiveness')) / count($assignments),
        'Teaching Effectiveness' => array_sum(array_column($assignments, 'teaching_effectiveness')) / count($assignments),
        'Resources & Admin' => array_sum(array_column($assignments, 'resources_admin')) / count($assignments),
        'Assessment & Learning' => array_sum(array_column($assignments, 'assessment_learning')) / count($assignments),
        'Course Outcomes' => array_sum(array_column($assignments, 'course_outcomes')) / count($assignments)
    ];

    // Overall Performance Summary (on new page)
    $pdf->AddPage();
    $pdf->SectionTitle('Overall Performance Summary');

    // Create metrics table with improved styling
    $metrics_data = array();
    $parameters = array(
        'Course Effectiveness' => $overall_stats['course_effectiveness'],
        'Teaching Effectiveness' => $overall_stats['teaching_effectiveness'],
        'Resources & Admin' => $overall_stats['resources_admin'],
        'Assessment & Learning' => $overall_stats['assessment_learning'],
        'Course Outcomes' => $overall_stats['course_outcomes'],
        'Overall Rating' => $overall_stats['overall_avg']
    );

    // Header row
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(52, 152, 219);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(70, 8, 'Parameter', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Rating', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(40, 8, 'Remarks', 1, 1, 'C', true);

    // Data rows
    $pdf->SetTextColor(44, 62, 80);
    foreach($parameters as $label => $rating) {
        $status = getRatingStatus($rating);
        $color = getRatingColor($rating);
        
        $pdf->Cell(70, 8, $label, 1, 0, 'L');
        
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->Cell(25, 8, number_format($rating, 2), 1, 0, 'C');
        
        $pdf->SetTextColor(44, 62, 80);
        $pdf->Cell(35, 8, $status['status'], 1, 0, 'C');
        $pdf->Cell(40, 8, $status['remarks'], 1, 1, 'C');
    }

    // Add signature space
    $pdf->Ln(20);
    $pdf->SetDrawColor(189, 195, 199);
    $pdf->Line($pdf->GetX() + 120, $pdf->GetY() + 15, $pdf->GetX() + 170, $pdf->GetY() + 15);
    $pdf->Cell(0, 10, 'HOD Signature', 0, 1, 'R');
    $pdf->Cell(0, 10, 'Date: ' . date('F j, Y'), 0, 1, 'R');
} else {
    $pdf->SetFont('Arial', '', 12);
    $pdf->SetTextColor(231, 76, 60);
    $pdf->Cell(0, 10, 'No feedback data available for the selected period.', 0, 1, 'C');
}

// Helper functions
function getRatingColor($rating) {
    if ($rating >= 4.5) return [46, 204, 113]; // Green
    if ($rating >= 4.0) return [52, 152, 219]; // Blue
    if ($rating >= 3.5) return [241, 196, 15]; // Yellow
    if ($rating >= 3.0) return [230, 126, 34]; // Orange
    return [231, 76, 60]; // Red
}

function getRatingStatus($rating) {
    if ($rating >= 4.5) return ['status' => 'Excellent', 'remarks' => 'Outstanding'];
    if ($rating >= 4.0) return ['status' => 'Very Good', 'remarks' => 'Above Avg'];
    if ($rating >= 3.5) return ['status' => 'Good', 'remarks' => 'Satisfactory'];
    if ($rating >= 3.0) return ['status' => 'Average', 'remarks' => 'Need Improv'];
    return ['status' => 'Poor', 'remarks' => 'Critical'];
}

// Output PDF
ob_clean();
$pdf->Output('Faculty_Feedback_Report_' . $faculty['faculty_id'] . '.pdf', 'D');
?> 