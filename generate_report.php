<?php
session_start();
require_once 'functions.php';
require('fpdf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'faculty')) {
    header('Location: login.php');
    exit();
}

// Get parameters
$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;
$academic_year_id = getCurrentAcademicYear($conn);

if (!$faculty_id || !$academic_year_id) {
    die("Required parameters missing");
}

class PDF extends FPDF {
    protected $col = 0;
    protected $y0;

    function Header() {
        // Background color for header
        $this->SetFillColor(51, 122, 183); // Professional blue
        $this->Rect(0, 0, 210, 40, 'F');
        
        // Logo
        if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 10, 6, 30);
        }
        
        // College Name
        $this->SetTextColor(255, 255, 255); // White text
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(80);
        $this->Cell(30, 15, 'Panimalar Engineering College', 0, 1, 'C');
        
        // Subtitle
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, 'Faculty Performance Analysis Report', 0, 1, 'C');
        
        // Reset text color
        $this->SetTextColor(0, 0, 0);
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        
        // Add a line above footer
        $this->SetDrawColor(51, 122, 183);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        
        // Footer text
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page '.$this->PageNo().'/{nb}     Generated on: ' . date('d-m-Y'), 0, 0, 'C');
    }

    function SectionTitle($title) {
        // Add some space before section
        $this->Ln(5);
        
        // Gradient background
        $this->SetFillColor(51, 122, 183);
        $this->SetTextColor(255);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 10, '  ' . $title, 0, 1, 'L', true);
        
        // Reset colors
        $this->SetTextColor(0);
        $this->Ln(5);
    }

    function CreateInfoBox($label, $value) {
        $this->SetFillColor(245, 245, 245);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(50, 8, $label . ':', 0, 0, 'L');
        $this->SetFont('Arial', '', 11);
        $this->Cell(0, 8, $value, 0, 1, 'L');
    }

    function CreateMetricsTable($headers, $data) {
        // Table header colors
        $this->SetFillColor(51, 122, 183);
        $this->SetTextColor(255);
        $this->SetDrawColor(51, 122, 183);
        $this->SetLineWidth(0.3);
        $this->SetFont('Arial', 'B', 10);

        // Header
        $w = array_values($headers);
        $columns = array_keys($headers);
        foreach($columns as $i => $column) {
            $this->Cell($w[$i], 8, $column, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data
        $this->SetFillColor(245, 245, 245);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 10);
        $fill = false;

        foreach($data as $row) {
            // Add color coding based on rating
            if (isset($row['Rating'])) {
                $rating = floatval($row['Rating']);
                if ($rating >= 4.5) $this->SetTextColor(46, 139, 87); // Green
                elseif ($rating >= 4.0) $this->SetTextColor(25, 135, 84); // Dark green
                elseif ($rating >= 3.5) $this->SetTextColor(255, 193, 7); // Yellow
                elseif ($rating >= 3.0) $this->SetTextColor(255, 140, 0); // Orange
                else $this->SetTextColor(220, 53, 69); // Red
            }

            foreach($columns as $i => $column) {
                $this->Cell($w[$i], 7, $row[$column], 1, 0, 'C', $fill);
            }
            $this->Ln();
            $fill = !$fill;
            $this->SetTextColor(0); // Reset text color
        }
    }

    function AddChart($title, $data, $labels) {
        // Implement simple bar chart
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, $title, 0, 1, 'C');
        
        $chartHeight = 60;
        $chartWidth = 180;
        $barWidth = $chartWidth / count($data);
        
        // Draw chart axes
        $startX = 15;
        $startY = $this->GetY() + $chartHeight;
        
        $this->Line($startX, $startY, $startX + $chartWidth, $startY); // X axis
        $this->Line($startX, $startY, $startX, $startY - $chartHeight); // Y axis
        
        // Draw bars
        $maxValue = max($data);
        $scale = $chartHeight / $maxValue;
        
        $x = $startX;
        foreach($data as $i => $value) {
            $barHeight = $value * $scale;
            $this->SetFillColor(51, 122, 183);
            $this->Rect($x, $startY - $barHeight, $barWidth - 2, $barHeight, 'F');
            
            // Add value on top of bar
            $this->SetFont('Arial', '', 8);
            $this->SetXY($x, $startY - $barHeight - 5);
            $this->Cell($barWidth - 2, 5, number_format($value, 1), 0, 0, 'C');
            
            // Add label below bar
            $this->SetXY($x, $startY + 1);
            $this->Cell($barWidth - 2, 5, $labels[$i], 0, 0, 'C');
            
            $x += $barWidth;
        }
        
        $this->Ln($chartHeight + 20);
    }

    function AddCommentBox($subject, $date, $comment) {
        $this->SetFillColor(245, 245, 245);
        $this->RoundedRect($this->GetX(), $this->GetY(), 190, 30, 3, 'F');
        
        // Subject and date header
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(140, 6, $subject, 0, 0);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(50, 6, $date, 0, 1, 'R');
        
        // Comment text
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(190, 5, $comment, 0, 'L');
        $this->Ln(5);
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F')
            $op='f';
        elseif($style=='FD' || $style=='DF')
            $op='B';
        else
            $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));

        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r, $yc + $r*$MyArc, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', 
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k,
            $x3*$this->k, ($h-$y3)*$this->k));
    }
}

// Create PDF instance
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Fetch faculty details
$faculty_query = "SELECT f.*, d.name as department_name 
                 FROM faculty f 
                 JOIN departments d ON f.department_id = d.id 
                 WHERE f.id = ?";
$stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($stmt, "i", $faculty_id);
mysqli_stmt_execute($stmt);
$faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Prepare info_data array
$info_data = array(
    array('Label' => 'Name', 'Value' => $faculty['name']),
    array('Label' => 'Faculty ID', 'Value' => $faculty['faculty_id']),
    array('Label' => 'Department', 'Value' => $faculty['department_name']),
    array('Label' => 'Designation', 'Value' => $faculty['designation']),
    array('Label' => 'Experience', 'Value' => $faculty['experience'] . ' years'),
    array('Label' => 'Qualification', 'Value' => $faculty['qualification']),
    array('Label' => 'Specialization', 'Value' => $faculty['specialization'])
);

// Fetch performance metrics
$metrics_query = "SELECT 
    COUNT(DISTINCT s.id) as total_subjects,
    COUNT(DISTINCT f.id) as total_feedback,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating
FROM subjects s
LEFT JOIN feedback f ON s.id = f.subject_id
WHERE s.faculty_id = ? 
AND f.academic_year_id = ?
GROUP BY s.faculty_id";

$stmt = mysqli_prepare($conn, $metrics_query);
mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $academic_year_id);
mysqli_stmt_execute($stmt);
$metrics = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Prepare metrics_data array
$metrics_data = array();
$parameters = array(
    'course_effectiveness' => 'Course Effectiveness',
    'teaching_effectiveness' => 'Teaching Effectiveness',
    'resources_admin' => 'Resources & Admin',
    'assessment_learning' => 'Assessment & Learning',
    'course_outcomes' => 'Course Outcomes',
    'overall_avg' => 'Overall Rating'
);

foreach($parameters as $key => $label) {
    $rating = round($metrics[$key] ?? 0, 2);
    $metrics_data[] = array(
        'Parameter' => $label,
        'Rating' => $rating
    );
}

// Prepare subjects_data array
$subjects_query = "SELECT 
    s.name as subject_name,
    s.code as subject_code,
    COUNT(DISTINCT f.id) as feedback_count,
    AVG(f.cumulative_avg) as avg_rating
FROM subjects s
LEFT JOIN feedback f ON s.id = f.subject_id
WHERE s.faculty_id = ? 
AND s.academic_year_id = ?
GROUP BY s.id";

$stmt = mysqli_prepare($conn, $subjects_query);
mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $academic_year_id);
mysqli_stmt_execute($stmt);
$subjects_result = mysqli_stmt_get_result($stmt);

$subjects_data = array();
while($row = mysqli_fetch_assoc($subjects_result)) {
    $subjects_data[] = array(
        'Subject' => $row['subject_name'],
        'Code' => $row['subject_code'],
        'Responses' => $row['feedback_count'],
        'Rating' => number_format($row['avg_rating'] ?? 0, 2)
    );
}

// Prepare headers for tables
$metrics_headers = array(
    'Parameter' => 60,
    'Rating' => 30,
    'Status' => 40,
    'Remarks' => 60
);

$subjects_headers = array(
    'Subject' => 80,
    'Code' => 30,
    'Responses' => 30,
    'Rating' => 30
);

// Faculty Information Section
$pdf->SectionTitle('Faculty Information');
foreach($info_data as $info) {
    $pdf->CreateInfoBox($info['Label'], $info['Value']);
}

// Performance Metrics Section
$pdf->AddPage();
$pdf->SectionTitle('Performance Analysis');

// Add radar chart for performance metrics
$metrics_values = array_column($metrics_data, 'Rating');
$metrics_labels = array_column($metrics_data, 'Parameter');
$pdf->AddChart('Performance Metrics Overview', $metrics_values, $metrics_labels);

// Create detailed metrics table
$pdf->CreateMetricsTable($metrics_headers, $metrics_data);

// Subject-wise Analysis
$pdf->AddPage();
$pdf->SectionTitle('Subject-wise Analysis');
$pdf->CreateMetricsTable($subjects_headers, $subjects_data);

// Add bar chart for subject ratings
if (!empty($subjects_data)) {
    $subject_ratings = array_column($subjects_data, 'Rating');
    $subject_names = array_column($subjects_data, 'Subject');
    $pdf->AddChart('Subject Rating Comparison', $subject_ratings, $subject_names);
}

// Fetch and display comments
$comments_query = "SELECT f.comments, s.name as subject_name, f.submitted_at
                  FROM feedback f
                  JOIN subjects s ON f.subject_id = s.id
                  WHERE s.faculty_id = ? 
                  AND f.academic_year_id = ?
                  AND f.comments IS NOT NULL
                  AND f.comments != ''
                  ORDER BY f.submitted_at DESC";

$stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $academic_year_id);
mysqli_stmt_execute($stmt);
$comments_result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($comments_result) > 0) {
    $pdf->AddPage();
    $pdf->SectionTitle('Student Feedback Comments');
    
    while($comment = mysqli_fetch_assoc($comments_result)) {
        $pdf->AddCommentBox(
            $comment['subject_name'],
            date('F j, Y', strtotime($comment['submitted_at'])),
            $comment['comments']
        );
    }
}

// Output PDF
$pdf->Output('Faculty_Analysis_Report.pdf', 'D');
?>