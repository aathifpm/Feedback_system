<?php
// Add these lines at the very top of the file
ob_start();
error_reporting(0); // Disable error reporting for production
// ini_set('display_errors', 0); // Uncomment this line for production

session_start();
require_once 'functions.php';
require('fpdf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'faculty' && $_SESSION['role'] != 'admin')) {
    header('Location: login.php');
    exit();
}

// Get parameters
$assignment_id = isset($_GET['assignment_id']) ? intval($_GET['assignment_id']) : 0;

if (!$assignment_id) {
    die("Required parameters missing");
}

// Fetch assignment, subject and faculty details
$details_query = "SELECT 
    sa.*, s.*, f.*, d.name as department_name,
    ay.year_range as academic_year
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
JOIN departments d ON s.department_id = d.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
WHERE sa.id = ? AND sa.is_active = TRUE";

$stmt = mysqli_prepare($conn, $details_query);
mysqli_stmt_bind_param($stmt, "i", $assignment_id);
mysqli_stmt_execute($stmt);
$details = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$details) {
    die("Invalid assignment ID");
}

class PDF extends FPDF {
    protected $col = 0;
    protected $y0;

    function Header() {
        // Professional margins and spacing
        $this->SetMargins(20, 20, 20);
        
        // Logo positioning
        if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 20, 15, 30);
        }
        
        // College Name with improved typography
        $this->SetFont('Helvetica', 'B', 24);
        $this->SetTextColor(28, 40, 51);
        $this->Cell(30); // Space after logo
        $this->Cell(140, 12, 'Panimalar Engineering College', 0, 1, 'C');
        
        // Department name
        $this->SetFont('Helvetica', '', 14);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(30);
        $this->Cell(140, 8, 'Department of Computer Science and Engineering', 0, 1, 'C');
        
        // Report title with subtle separator
        $this->Ln(4);
        $this->SetDrawColor(189, 195, 199);
        $this->Line(20, $this->GetY(), 190, $this->GetY());
        $this->Ln(4);
        
        $this->SetFont('Helvetica', 'B', 16);
        $this->Cell(0, 10, 'Faculty Performance Analysis Report', 0, 1, 'C');
        
        // Academic Year with improved formatting
        $this->SetFont('Helvetica', '', 12);
        $this->Cell(0, 8, 'Academic Year ' . date('Y') . '-' . (date('Y') + 1), 0, 1, 'C');
        
        $this->Ln(10);
    }

    function Footer() {
        $this->SetY(-20);
        
        // Subtle line above footer
        $this->SetDrawColor(189, 195, 199);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        
        // Footer text with Helvetica
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'L');
        $this->Cell(0, 10, 'Generated on: ' . date('F j, Y'), 0, 0, 'R');
    }

    function SectionTitle($title) {
        $this->Ln(8);
        
        // Modern section title design
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        
        // Subtle underline
        $this->SetDrawColor(52, 152, 219);
        $this->SetLineWidth(0.4);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        
        $this->Ln(5);
    }

    function CreateInfoBox($label, $value) {
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(60, 8, $label . ':', 0, 0, 'L');
        
        $this->SetFont('Helvetica', '', 11);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, $value, 0, 1, 'L');
    }

    function CreateMetricsTable($headers, $data) {
        // Modern table design
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(245, 247, 250);
        $this->SetTextColor(44, 62, 80);
        $this->SetDrawColor(189, 195, 199);
        $this->SetLineWidth(0.2);

        // Header
        $w = array_values($headers);
        $columns = array_keys($headers);
        foreach($columns as $i => $column) {
            $this->Cell($w[$i], 10, $column, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows with alternating background
        $this->SetFont('Helvetica', '', 10);
        $fill = false;

        foreach($data as $row) {
            $this->SetFillColor($fill ? 252 : 248, $fill ? 252 : 249, $fill ? 252 : 250);
            
            // Color coding for ratings
            if (isset($row['Rating'])) {
                $rating = floatval($row['Rating']);
                if ($rating >= 4.5) {
                    $this->SetTextColor(39, 174, 96);
                } elseif ($rating >= 4.0) {
                    $this->SetTextColor(41, 128, 185);
                } elseif ($rating >= 3.5) {
                    $this->SetTextColor(243, 156, 18);
                } elseif ($rating >= 3.0) {
                    $this->SetTextColor(230, 126, 34);
                } else {
                    $this->SetTextColor(192, 57, 43);
                }
            }

            foreach($columns as $i => $column) {
                $this->Cell($w[$i], 8, $row[$column], 1, 0, 'C', $fill);
            }
            $this->Ln();
            $fill = !$fill;
            $this->SetTextColor(44, 62, 80);
        }
    }

    function AddChart($title, $data, $labels) {
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell(0, 10, $title, 0, 1, 'C');
        
        // Improved chart dimensions
        $chartHeight = 80;
        $chartWidth = 170;
        $barWidth = min(25, $chartWidth / count($data)); // Maximum bar width of 25
        $startX = 25;
        $startY = $this->GetY() + $chartHeight;
        
        // Draw Y-axis with scale markers (0 to 5)
        $this->SetFont('Helvetica', '', 8);
        $this->SetDrawColor(189, 195, 199);
        for($i = 0; $i <= 5; $i++) {
            $y = $startY - ($i * $chartHeight/5);
            $this->Line($startX-2, $y, $startX+$chartWidth, $y); // Grid line
            $this->SetXY($startX-10, $y-2);
            $this->Cell(8, 4, $i, 0, 0, 'R');
        }
        
        // Draw bars with improved styling
        $x = $startX + 10; // Initial padding
        foreach($data as $i => $value) {
            $barHeight = ($value * $chartHeight/5);
            
            // Color gradient based on rating
            if ($value >= 4.5) $this->SetFillColor(46, 204, 113);
            elseif ($value >= 4.0) $this->SetFillColor(52, 152, 219);
            elseif ($value >= 3.5) $this->SetFillColor(241, 196, 15);
            elseif ($value >= 3.0) $this->SetFillColor(230, 126, 34);
            else $this->SetFillColor(231, 76, 60);
            
            // Draw bar with rounded corners
            $this->RoundedRect($x, $startY - $barHeight, $barWidth-4, $barHeight, 2, 'F');
            
            // Value on top of bar
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(44, 62, 80);
            $this->SetXY($x, $startY - $barHeight - 5);
            $this->Cell($barWidth-4, 5, number_format($value, 1), 0, 0, 'C');
            
            // Label below bar (rotated for better fit)
            $this->SetFont('Helvetica', '', 8);
            $this->SetXY($x, $startY + 2);
            $label = $this->truncateText($labels[$i], $barWidth-4);
            $this->Cell($barWidth-4, 5, $label, 0, 0, 'C');
            
            $x += $barWidth;
        }
        
        $this->Ln($chartHeight + 25);
    }

    // Helper function to truncate long labels
    function truncateText($text, $width) {
        if($this->GetStringWidth($text) > $width) {
            while($this->GetStringWidth($text . '...') > $width) {
                $text = substr($text, 0, -1);
            }
            return $text . '...';
        }
        return $text;
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

// Prepare info_data array
$info_data = array(
    array('Label' => 'Faculty Name', 'Value' => $details['name']),
    array('Label' => 'Faculty ID', 'Value' => $details['faculty_id']),
    array('Label' => 'Department', 'Value' => $details['department_name']),
    array('Label' => 'Subject', 'Value' => $details['code'] . ' - ' . $details['name']),
    array('Label' => 'Year & Semester', 'Value' => 'Year ' . $details['year'] . ' - Semester ' . $details['semester']),
    array('Label' => 'Section', 'Value' => $details['section']),
    array('Label' => 'Academic Year', 'Value' => $details['academic_year'])
);

// Fetch performance metrics
$metrics_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    AVG(f.course_effectiveness_avg) as course_effectiveness,
    AVG(f.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(f.resources_admin_avg) as resources_admin,
    AVG(f.assessment_learning_avg) as assessment_learning,
    AVG(f.course_outcomes_avg) as course_outcomes,
    AVG(f.cumulative_avg) as overall_avg,
    MIN(f.cumulative_avg) as min_rating,
    MAX(f.cumulative_avg) as max_rating
FROM feedback f
WHERE f.assignment_id = ?";

$stmt = mysqli_prepare($conn, $metrics_query);
mysqli_stmt_bind_param($stmt, "i", $assignment_id);
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
    
    // Add status and remarks based on rating
    $status = '';
    $remarks = '';
    
    if ($rating >= 4.5) {
        $status = 'Excellent';
        $remarks = 'Outstanding performance';
    } elseif ($rating >= 4.0) {
        $status = 'Very Good';
        $remarks = 'Above expectations';
    } elseif ($rating >= 3.5) {
        $status = 'Good';
        $remarks = 'Meets expectations';
    } elseif ($rating >= 3.0) {
        $status = 'Satisfactory';
        $remarks = 'Room for improvement';
    } else {
        $status = 'Needs Improvement';
        $remarks = 'Requires attention';
    }
    
    $metrics_data[] = array(
        'Parameter' => $label,
        'Rating' => $rating,
        'Status' => $status,
        'Remarks' => $remarks
    );
}

// Fetch statement-wise analysis
$statement_query = "SELECT 
    fs.section,
    fs.statement,
    COUNT(fr.id) as response_count,
    ROUND(AVG(fr.rating), 2) as avg_rating
FROM feedback_statements fs
LEFT JOIN feedback_ratings fr ON fs.id = fr.statement_id
LEFT JOIN feedback f ON fr.feedback_id = f.id AND f.assignment_id = ?
WHERE fs.is_active = TRUE
GROUP BY fs.id, fs.section, fs.statement
ORDER BY fs.section, fs.id";

$stmt = mysqli_prepare($conn, $statement_query);
mysqli_stmt_bind_param($stmt, "i", $assignment_id);
mysqli_stmt_execute($stmt);
$statement_result = mysqli_stmt_get_result($stmt);

$statement_data = array();
while($row = mysqli_fetch_assoc($statement_result)) {
    $statement_data[] = array(
        'Statement' => $row['statement'],
        'Section' => $row['section'],
        'Responses' => $row['response_count'],
        'Rating' => number_format($row['avg_rating'] ?? 0, 2)
    );
}

// Fetch and prepare comments
$comments_query = "SELECT comments, submitted_at
                  FROM feedback
                  WHERE assignment_id = ?
                  AND comments IS NOT NULL
                  AND comments != ''
                  ORDER BY submitted_at DESC";

$stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($stmt, "i", $assignment_id);
mysqli_stmt_execute($stmt);
$comments_result = mysqli_stmt_get_result($stmt);

// Faculty Information Section
$pdf->SectionTitle('Subject and Faculty Information');
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
$metrics_headers = array(
    'Parameter' => 60,
    'Rating' => 25,
    'Status' => 35,
    'Remarks' => 60
);
$pdf->CreateMetricsTable($metrics_headers, $metrics_data);

// Statement-wise Analysis
$pdf->AddPage();
$pdf->SectionTitle('Statement-wise Analysis');
$statement_headers = array(
    'Statement' => 100,
    'Section' => 40,
    'Responses' => 20,
    'Rating' => 20
);
$pdf->CreateMetricsTable($statement_headers, $statement_data);

// Add comments if available
if (mysqli_num_rows($comments_result) > 0) {
    $pdf->AddPage();
    $pdf->SectionTitle('Student Comments');
    
    while($comment = mysqli_fetch_assoc($comments_result)) {
        $pdf->AddCommentBox(
            $details['name'] . ' (' . $details['code'] . ')',
            date('F j, Y', strtotime($comment['submitted_at'])),
            $comment['comments']
        );
    }
}

// Make sure there's no output before PDF generation
ob_clean();

// Output PDF
$pdf->Output('Subject_Feedback_Report.pdf', 'D');
?>