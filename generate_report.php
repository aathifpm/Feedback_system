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
$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overall';
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$batch = isset($_GET['batch']) ? intval($_GET['batch']) : 0;

if (!$faculty_id) {
    die("Required parameters missing");
}

// Fetch faculty details
$faculty_query = "SELECT f.*, d.name as department_name
FROM faculty f
JOIN departments d ON f.department_id = d.id
WHERE f.id = ? AND f.is_active = TRUE";

$stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($stmt, "i", $faculty_id);
mysqli_stmt_execute($stmt);
$faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$faculty) {
    die("Invalid faculty ID");
}

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

// Build the WHERE clause based on parameters
$where_conditions = ["sa.faculty_id = ?", "sa.is_active = TRUE"];
$params = [$faculty_id];
$param_types = "i";

if ($report_type != 'overall') {
    switch ($report_type) {
        case 'academic_year':
            if ($academic_year) {
                $where_conditions[] = "sa.academic_year_id = ?";
                $params[] = $academic_year;
                $param_types .= "i";
            }
            break;
        case 'semester':
            if ($academic_year && $semester) {
                $where_conditions[] = "sa.academic_year_id = ?";
                $where_conditions[] = "sa.semester = ?";
                $params[] = $academic_year;
                $params[] = $semester;
                $param_types .= "ii";
            }
            break;
        case 'section':
            if ($academic_year && $semester && $section) {
                $where_conditions[] = "sa.academic_year_id = ?";
                $where_conditions[] = "sa.semester = ?";
                $where_conditions[] = "sa.section = ?";
                $params[] = $academic_year;
                $params[] = $semester;
                $params[] = $section;
                $param_types .= "iis";
            }
            break;
        case 'batch':
            if ($batch) {
                $where_conditions[] = "st.batch_id = ?";
                $params[] = $batch;
                $param_types .= "i";
            }
            break;
    }
}

$where_clause = implode(" AND ", $where_conditions);

// Fetch performance metrics
$metrics_query = "SELECT 
    s.code,
    s.name as subject_name,
    sa.year,
    sa.semester,
    sa.section,
    COUNT(DISTINCT f.id) as total_feedback,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_avg
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
LEFT JOIN feedback f ON sa.id = f.assignment_id
LEFT JOIN students st ON f.student_id = st.id
WHERE $where_clause
GROUP BY s.id, sa.id
ORDER BY sa.year, sa.semester, sa.section";

$stmt = mysqli_prepare($conn, $metrics_query);
mysqli_stmt_bind_param($stmt, $param_types, ...$params);
mysqli_stmt_execute($stmt);
$metrics_result = mysqli_stmt_get_result($stmt);

// Fetch comments
$comments_query = "SELECT 
    f.comments,
    f.submitted_at,
    s.name as subject_name,
    s.code as subject_code,
    sa.section,
    sa.year,
    sa.semester
FROM feedback f
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN students st ON f.student_id = st.id
WHERE $where_clause
AND f.comments IS NOT NULL
AND f.comments != ''
ORDER BY f.submitted_at DESC";

$stmt = mysqli_prepare($conn, $comments_query);
mysqli_stmt_bind_param($stmt, $param_types, ...$params);
mysqli_stmt_execute($stmt);
$comments_result = mysqli_stmt_get_result($stmt);

class PDF extends FPDF {
    protected $col = 0;
    protected $y0;
    protected $departmentName = '';

    function setDepartmentName($name) {
        $this->departmentName = $name;
    }

    function Header() {
        // Logo
        if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 20, 10, 25);
        }
        
        // College Name and Details
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(0, 51, 102); // Dark blue color
        $this->Cell(24); // Space for logo
        $this->Cell(165, 13, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'C');
        
        // College Status
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0);
        $this->Cell(20); // Add space from left
        $this->Cell(0, 5, 'An Autonomous Institution, Affiliated to Anna University, Chennai', 0, 1, 'C');
        
        // College Address
        $this->SetFont('Arial', '', 9);
        $this->Cell(20); // Add space from left
        $this->Cell(0, 5, 'Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai - 600 123', 0, 1, 'C');
        // Add some extra space before the line
        $this->Ln(7);
        
        // First decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(4);

        // Report Title
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 8, 'Faculty Performance Analysis Report', 0, 1, 'C');

        // Department Name
        if ($this->departmentName) {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0);
            $this->Cell(0, 6, $this->departmentName, 0, 1, 'C');
        }
        
        // Second decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(8);
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
        $this->Cell(0, 8, $title, 0, 1, 'C');
        
        // More compact chart dimensions
        $chartHeight = 60;
        $chartWidth = 170;
        $barWidth = min(20, $chartWidth / count($data));
        $startX = 25;
        $startY = $this->GetY() + $chartHeight;
        
        // Draw Y-axis with scale markers (0 to 5)
        $this->SetFont('Helvetica', '', 8);
        $this->SetDrawColor(189, 195, 199);
        for($i = 0; $i <= 5; $i++) {
            $y = $startY - ($i * $chartHeight/5);
            $this->Line($startX-2, $y, $startX+$chartWidth, $y);
            $this->SetXY($startX-10, $y-2);
            $this->Cell(8, 4, $i, 0, 0, 'R');
        }
        
        // Draw bars with improved styling
        $x = $startX + 10;
        foreach($data as $i => $value) {
            $barHeight = ($value * $chartHeight/5);
            
            // Color gradient based on rating
            if ($value >= 4.5) $this->SetFillColor(46, 204, 113);
            elseif ($value >= 4.0) $this->SetFillColor(52, 152, 219);
            elseif ($value >= 3.5) $this->SetFillColor(241, 196, 15);
            elseif ($value >= 3.0) $this->SetFillColor(230, 126, 34);
            else $this->SetFillColor(231, 76, 60);
            
            $this->RoundedRect($x, $startY - $barHeight, $barWidth-4, $barHeight, 2, 'F');
            
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(44, 62, 80);
            $this->SetXY($x, $startY - $barHeight - 5);
            $this->Cell($barWidth-4, 5, number_format($value, 1), 0, 0, 'C');
            
            $this->SetFont('Helvetica', '', 8);
            $this->SetXY($x, $startY + 2);
            $label = $this->truncateText($labels[$i], $barWidth-4);
            $this->Cell($barWidth-4, 5, $label, 0, 0, 'C');
            
            $x += $barWidth;
        }
        
        $this->Ln($chartHeight + 15);
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
$pdf->setDepartmentName($faculty['department_name']);
$pdf->AliasNbPages();
$pdf->AddPage();

// Faculty Information Section
$pdf->SectionTitle('Faculty Information');
$pdf->CreateInfoBox('Faculty Name', $faculty['name']);
$pdf->CreateInfoBox('Faculty ID', $faculty['faculty_id']);
$pdf->CreateInfoBox('Department', $faculty['department_name']);
$pdf->CreateInfoBox('Designation', $faculty['designation']);

// Add report type information
switch ($report_type) {
    case 'overall':
        $pdf->CreateInfoBox('Report Type', 'Overall Performance Report');
        break;
    case 'academic_year':
        $year_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT year_range FROM academic_years WHERE id = $academic_year"));
        $pdf->CreateInfoBox('Report Type', 'Academic Year: ' . $year_info['year_range']);
        break;
    case 'semester':
        $year_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT year_range FROM academic_years WHERE id = $academic_year"));
        $pdf->CreateInfoBox('Report Type', 'Semester ' . $semester . ' - ' . $year_info['year_range']);
        break;
    case 'section':
        $year_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT year_range FROM academic_years WHERE id = $academic_year"));
        $pdf->CreateInfoBox('Report Type', 'Section ' . $section . ' - Semester ' . $semester . ' - ' . $year_info['year_range']);
        break;
    case 'batch':
        $batch_info = mysqli_fetch_assoc(mysqli_query($conn, "SELECT batch_name FROM batch_years WHERE id = $batch"));
        $pdf->CreateInfoBox('Report Type', 'Batch: ' . $batch_info['batch_name']);
        break;
}

// Subject-wise Performance Analysis
$pdf->AddPage();
$pdf->SectionTitle('Subject-wise Performance Analysis');

while ($subject = mysqli_fetch_assoc($metrics_result)) {
    // Check if we need a new page
    if ($pdf->GetY() > 220) { // Adjust this value based on the content height
        $pdf->AddPage();
    }

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 10, $subject['subject_name'] . ' (' . $subject['code'] . ')', 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 6, 'Year: ' . $subject['year'] . ' | Semester: ' . $subject['semester'] . ' | Section: ' . $subject['section'], 0, 1, 'L');
    $pdf->Ln(2); // Reduced spacing

    // Create metrics table for this subject
    $metrics_data = array(
        array(
            'Parameter' => 'Course Effectiveness',
            'Rating' => number_format($subject['course_effectiveness'], 2),
            'Status' => getRatingStatus($subject['course_effectiveness'])
        ),
        array(
            'Parameter' => 'Teaching Effectiveness',
            'Rating' => number_format($subject['teaching_effectiveness'], 2),
            'Status' => getRatingStatus($subject['teaching_effectiveness'])
        ),
        array(
            'Parameter' => 'Resources & Admin',
            'Rating' => number_format($subject['resources_admin'], 2),
            'Status' => getRatingStatus($subject['resources_admin'])
        ),
        array(
            'Parameter' => 'Assessment & Learning',
            'Rating' => number_format($subject['assessment_learning'], 2),
            'Status' => getRatingStatus($subject['assessment_learning'])
        ),
        array(
            'Parameter' => 'Course Outcomes',
            'Rating' => number_format($subject['course_outcomes'], 2),
            'Status' => getRatingStatus($subject['course_outcomes'])
        ),
        array(
            'Parameter' => 'Overall Rating',
            'Rating' => number_format($subject['overall_avg'], 2),
            'Status' => getRatingStatus($subject['overall_avg'])
        )
    );

    $headers = array(
        'Parameter' => 80,
        'Rating' => 30,
        'Status' => 70
    );
    
    $pdf->CreateMetricsTable($headers, $metrics_data);
    $pdf->Ln(5); // Reduced spacing

    // Check if we need a new page before adding chart
    if ($pdf->GetY() > 180) { // Adjust this value based on the chart height
        $pdf->AddPage();
    }

    // Add performance chart with proper number formatting
    $chart_data = [
        floatval($subject['course_effectiveness']),
        floatval($subject['teaching_effectiveness']),
        floatval($subject['resources_admin']),
        floatval($subject['assessment_learning']),
        floatval($subject['course_outcomes']),
        floatval($subject['overall_avg'])
    ];
    
    $chart_labels = [
        'Course Eff.',
        'Teaching Eff.',
        'Resources',
        'Assessment',
        'Outcomes',
        'Overall'
    ];
    
    $pdf->AddChart('Performance Metrics', $chart_data, $chart_labels);
    
    // Add some spacing between subjects
    $pdf->Ln(10);
}

// Add this function before the PDF generation code
function scoreComment($comment) {
    // Positive keywords for scoring
    $positive_keywords = [
        // Simple encouragements
        'nice', 'cool', 'super', 'yay', 'wow', 'yes', 'love', 'lovely',
        'liked', 'enjoy', 'enjoyed', 'fun', 'interesting', 'excited', 'happy',
        'pleased', 'glad', 'thankful', 'thanks', 'appreciate', 'appreciated',
        
        // General positive words
        'excellent', 'outstanding', 'amazing', 'fantastic', 'great', 'good', 'best',
        'wonderful', 'brilliant', 'exceptional', 'superb', 'perfect', 'impressive',
        'terrific', 'fabulous', 'marvelous', 'splendid', 'phenomenal', 'remarkable',
        'extraordinary', 'stellar', 'exemplary', 'admirable', 'commendable', 'praiseworthy',
        
        // Teaching related
        'clear', 'helpful', 'knowledgeable', 'engaging', 'interactive', 'organized',
        'patient', 'supportive', 'dedicated', 'passionate', 'inspiring', 'thorough',
        'prepared', 'experienced', 'expert', 'proficient', 'skilled', 'competent',
        'qualified', 'capable', 'efficient', 'effective', 'methodical', 'systematic',
        
        // Communication
        'explains', 'communicates', 'clarifies', 'understands', 'responds', 'approachable',
        'articulate', 'eloquent', 'expressive', 'responsive', 'receptive', 'attentive',
        'accessible', 'available', 'reachable', 'open', 'transparent', 'clear-spoken',
        
        // Method related
        'innovative', 'effective', 'practical', 'structured', 'systematic', 'comprehensive',
        'creative', 'adaptable', 'flexible', 'dynamic', 'interactive', 'hands-on',
        'experiential', 'participatory', 'collaborative', 'engaging', 'stimulating',
        'thought-provoking', 'challenging', 'well-planned', 'organized', 'coherent',
        
        // Student support
        'encourages', 'motivates', 'guides', 'supports', 'helps', 'assists',
        'mentors', 'nurtures', 'facilitates', 'empowers', 'enables', 'inspires',
        'counsels', 'advises', 'coaches', 'tutors', 'aids', 'backs', 'reinforces',
        
        // Personality traits
        'friendly', 'kind', 'punctual', 'professional', 'sincere', 'committed',
        'reliable', 'dependable', 'trustworthy', 'honest', 'ethical', 'respectful',
        'courteous', 'considerate', 'empathetic', 'understanding', 'patient', 'fair',
        'consistent', 'disciplined', 'enthusiastic', 'energetic', 'positive', 'cheerful',
        
        // Academic terms
        'concepts', 'understanding', 'learning', 'knowledge', 'skills', 'expertise',
        'competency', 'proficiency', 'mastery', 'comprehension', 'grasp', 'insight',
        'wisdom', 'intellect', 'aptitude', 'capability', 'talent', 'acumen',
        'scholarship', 'erudition', 'academic', 'scholarly', 'intellectual', 'pedagogical',
        
        // Course delivery
        'well-paced', 'timely', 'punctual', 'regular', 'consistent', 'structured',
        'organized', 'sequential', 'logical', 'coherent', 'systematic', 'methodical',
        'planned', 'prepared', 'ready', 'equipped', 'resourceful', 'well-designed',
        
        // Assessment related
        'fair', 'balanced', 'reasonable', 'appropriate', 'relevant', 'meaningful',
        'constructive', 'helpful', 'informative', 'detailed', 'thorough', 'comprehensive',
        'timely', 'prompt', 'quick', 'regular', 'consistent', 'standardized'
    ];

    // Convert comment to lowercase for case-insensitive matching
    $comment_lower = strtolower($comment);
    
    // Initialize score
    $score = 0;
    
    // Score based on keyword matches
    foreach ($positive_keywords as $keyword) {
        if (strpos($comment_lower, $keyword) !== false) {
            $score += 1;
        }
    }
    
    // Additional scoring factors
    if (strlen($comment) > 100) { // Favor detailed comments
        $score += 1;
    }
    
    // Check for negative indicators
    $negative_keywords = ['not', 'poor', 'bad', 'worse', 'worst', 'terrible', 'horrible'];
    foreach ($negative_keywords as $keyword) {
        if (strpos($comment_lower, $keyword) !== false) {
            $score -= 2;
        }
    }
    
    return $score;
}

// Modify the comments section code
if (mysqli_num_rows($comments_result) > 0) {
    $pdf->AddPage();
    $pdf->SectionTitle('Student Comments');
    
    // Collect and score all comments
    $scored_comments = [];
    while ($comment = mysqli_fetch_assoc($comments_result)) {
        $score = scoreComment($comment['comments']);
        $scored_comments[] = [
            'score' => $score,
            'data' => $comment
        ];
    }
    
    // Sort comments by score in descending order
    usort($scored_comments, function($a, $b) {
        return $b['score'] - $a['score'];
    });
    
    // Take only top 20 comments
    $top_comments = array_slice($scored_comments, 0, 20);
    
    foreach ($top_comments as $scored_comment) {
        $comment = $scored_comment['data'];
        $subject_info = sprintf('%s (%s) - Year %d, Semester %d, Section %s',
            $comment['subject_name'],
            $comment['subject_code'],
            $comment['year'],
            $comment['semester'],
            $comment['section']
        );
        
        $pdf->AddCommentBox(
            $subject_info,
            date('F j, Y', strtotime($comment['submitted_at'])),
            $comment['comments']
        );
    }
}

// Helper function to get rating status
function getRatingStatus($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
}

// Make sure there's no output before PDF generation
ob_clean();

// Output PDF
$filename = 'Faculty_Feedback_Report_' . $faculty['faculty_id'] . '.pdf';
$pdf->Output($filename, 'D');
?>