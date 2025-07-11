<?php
// Start output buffering at the very beginning
ob_start();

session_start();
require_once 'functions.php';
require('fpdf.php');

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hod') {
    header('Location: index.php');
    exit();
}

// Get parameters with better validation
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : 0;
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$department_id = $_SESSION['department_id'];

// Enhanced parameter validation
if (!$academic_year || !$year || !isset($semester) || empty($section)) {
    die("Required parameters missing. Please provide academic year, year, semester, and section.");
}

// Validate year and semester ranges
if ($year < 1 || $year > 4) {
    die("Invalid year. Year must be between 1 and 4.");
}
if ($semester < 1 || $semester > 8) {
    die("Invalid semester. Semester must be between 1 and 8.");
}

// Get academic year details with error handling
$year_query = "SELECT year_range, start_date, end_date FROM academic_years WHERE id = ?";
$year_stmt = mysqli_prepare($conn, $year_query);
if (!$year_stmt) {
    die("Error preparing query: " . mysqli_error($conn));
}
mysqli_stmt_bind_param($year_stmt, "i", $academic_year);
if (!mysqli_stmt_execute($year_stmt)) {
    die("Error executing query: " . mysqli_stmt_error($year_stmt));
}
$year_result = mysqli_stmt_get_result($year_stmt);
$academic_year_data = mysqli_fetch_assoc($year_result);

if (!$academic_year_data) {
    die("Invalid academic year ID.");
}

// Get batch year information
$batch_query = "SELECT 
    by2.batch_name 
FROM students st
JOIN batch_years by2 ON st.batch_id = by2.id
WHERE st.section = ?
AND st.department_id = ?
AND by2.current_year_of_study = ?
LIMIT 1";

$batch_stmt = mysqli_prepare($conn, $batch_query);
mysqli_stmt_bind_param($batch_stmt, "sii", $section, $department_id, $year);
mysqli_stmt_execute($batch_stmt);
$batch_result = mysqli_stmt_get_result($batch_stmt);
$batch_data = mysqli_fetch_assoc($batch_result);
$batch_name = isset($batch_data['batch_name']) ? $batch_data['batch_name'] : 'N/A';

class PDF extends FPDF {
    protected $col = 0;
    protected $y0;

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
        
        $this->Ln(7);
        
        // First decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(4);
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
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        
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
        // Set styling
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(220, 220, 220);
        
        // Widths of columns - give more space to faculty column (60mm)
        $w = array(20, 45, 60, 15, 20, 20);
        
        // Header
        for($i=0; $i<count($headers); $i++) {
            $this->Cell($w[$i], 7, $headers[$i], 1, 0, 'C', true);
        }
        $this->Ln();
        
        // Calculate max height for each row before drawing anything
        $this->SetFont('Arial', '', 9);
        
        foreach($data as $row) {
            // Save the starting position
            $x = $this->GetX();
            $y = $this->GetY();
            $lineHeight = 5; // Line height for text
            
            // First pass - measure heights without drawing
            
            // Subject column - measure height
            $this->SetXY($x + $w[0], $y);
            $startY = $this->GetY();
            $this->MultiCell($w[1], $lineHeight, $row[1], 0);
            $subjectHeight = $this->GetY() - $startY;
            
            // Faculty column - measure height - this needs to fit correctly
            $this->SetXY($x + $w[0] + $w[1], $y);
            $startY = $this->GetY();
            $this->MultiCell($w[2], $lineHeight, $row[2], 0);
            $facultyHeight = $this->GetY() - $startY;
            
            // Get maximum height from all columns + add some padding
            $maxHeight = max($subjectHeight, $facultyHeight, 7) + 2;
            
            // Second pass - draw all cells
            $this->SetXY($x, $y);
            
            // Draw background cells with correct height
            $this->Cell($w[0], $maxHeight, '', 'LTR'); // Code background
            $this->Cell($w[1], $maxHeight, '', 'LTR'); // Subject background
            $this->Cell($w[2], $maxHeight, '', 'LTR'); // Faculty background
            $this->Cell($w[3], $maxHeight, '', 'LTR'); // Semester background
            $this->Cell($w[4], $maxHeight, '', 'LTR'); // Responses background
            $this->Cell($w[5], $maxHeight, '', 'LTR'); // Rating background
            $this->Ln();
            
            // Now draw content on top of the cells
            
            // Code (centered, single line)
            $this->SetXY($x, $y);
            $this->Cell($w[0], $maxHeight, $row[0], 'LBR', 0, 'C');
            
            // Subject name - multiline text
            $this->SetXY($x + $w[0], $y);
            $this->MultiCell($w[1], $lineHeight, $row[1], 'LBR');
            
            // Faculty name - multiline text
            $this->SetXY($x + $w[0] + $w[1], $y);
            $this->MultiCell($w[2], $lineHeight, $row[2], 'LBR');
            
            // Remaining columns - centered, single line
            $this->SetXY($x + $w[0] + $w[1] + $w[2], $y);
            $this->Cell($w[3], $maxHeight, $row[3], 'LBR', 0, 'C');
            $this->Cell($w[4], $maxHeight, $row[4], 'LBR', 0, 'C');
            $this->Cell($w[5], $maxHeight, $row[5], 'LBR', 0, 'C');
            
            // Move to next row
            $this->SetY($y + $maxHeight);
        }
    }

    function CreateFeedbackTable($headers, $data) {
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(245, 247, 250);
        $this->SetTextColor(44, 62, 80);
        $this->SetDrawColor(189, 195, 199);
        $this->SetLineWidth(0.2);

        // Calculate column widths based on content and number of columns
        $num_columns = count($headers);
        $page_width = $this->GetPageWidth() - 20; // Total width minus margins
        
        // If feedback table, use custom widths
        if ($num_columns == 5) {
            // Define width percentages for feedback categories table
            $width_percentages = [40, 15, 15, 15, 15]; // Adjust as needed
        } else {
            // For tables with different column counts, distribute evenly
            $width_percentages = array_fill(0, $num_columns, 100/$num_columns);
        }
        
        // Calculate actual widths
        $w = [];
        foreach($width_percentages as $percentage) {
            $w[] = $page_width * ($percentage / 100);
        }

        // Header
        foreach($headers as $i => $header) {
            $this->Cell($w[$i], 10, $header, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows
        $this->SetFont('Helvetica', '', 10);
        $fill = false;
        foreach($data as $row) {
            // Set a reasonable row height based on content
            $row_height = 8; // Default height
            
            // For category names, check length and increase height if needed
            if ($num_columns == 5 && strlen($row[0]) > 40) {
                $row_height = 10;
            }
            if ($num_columns == 5 && strlen($row[0]) > 80) {
                $row_height = 12;
            }
            
            // First column (typically category) - left aligned
            $this->Cell($w[0], $row_height, $this->TruncateText($row[0], $w[0], 'Helvetica', '', 10), 1, 0, 'L', $fill);
            
            // Other columns - centered
            for($i = 1; $i < $num_columns; $i++) {
                $this->Cell($w[$i], $row_height, $row[$i], 1, 0, 'C', $fill);
            }
            
            $this->Ln();
            $fill = !$fill;
        }
    }

    function CreateDetailedTable($headers, $data) {
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(245, 247, 250);
        $this->SetTextColor(44, 62, 80);
        $this->SetDrawColor(189, 195, 199);
        $this->SetLineWidth(0.2);

        // Calculate column widths based on number of columns and content
        $num_columns = count($headers);
        $page_width = $this->GetPageWidth() - 20; // Total width minus margins
        
        // Define column width percentages based on table type
        if ($num_columns == 7) { // Student participation table
            $width_percentages = [15, 25, 10, 10, 15, 10, 15]; // Adjust based on content needs
        } else {
            // For other tables, distribute fairly
            $width_percentages = array_fill(0, $num_columns, 100/$num_columns);
        }
        
        // Calculate actual column widths
        $w = [];
        foreach($width_percentages as $percentage) {
            $w[] = $page_width * ($percentage / 100);
        }

        // Header
        foreach($headers as $i => $header) {
            $this->Cell($w[$i], 10, $header, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows
        $this->SetFont('Helvetica', '', 10);
        $fill = false;
        foreach($data as $row) {
            // Set a reasonable row height based on content
            $row_height = 8; // Default height
            
            // For student names or other potentially long text
            if ($num_columns == 7 && strlen($row[1]) > 25) {
                $row_height = 10;
            }
            if ($num_columns == 7 && strlen($row[1]) > 40) {
                $row_height = 12;
            }
            
            // Process each column
            for($i = 0; $i < $num_columns; $i++) {
                // Left align text columns (typically column 1 for names)
                $alignment = ($i == 1) ? 'L' : 'C';
                
                // Truncate text if needed for text-heavy columns
                if ($i == 1) {
                    $text = $this->TruncateText($row[$i], $w[$i], 'Helvetica', '', 10);
                } else {
                    $text = $row[$i];
                }
                
                $this->Cell($w[$i], $row_height, $text, 1, 0, $alignment, $fill);
            }
            
            $this->Ln();
            $fill = !$fill;
        }
    }
    
    // Helper method to truncate text that's too long for a cell
    function TruncateText($text, $width, $fontname, $fontstyle, $fontsize) {
        $this->SetFont($fontname, $fontstyle, $fontsize);
        
        // If text width fits, return as is
        if ($this->GetStringWidth($text) <= $width - 4) { // 4 = cell padding
            return $text;
        }
        
        // If too long, truncate with ellipsis
        $char_width = $this->GetStringWidth('a'); // Use 'a' as a standard character width
        $chars_that_fit = floor(($width - 8) / $char_width); // 8 = padding + ellipsis width
        
        if ($chars_that_fit < 10) {
            $chars_that_fit = 10; // Minimum characters to show
        }
        
        return substr($text, 0, $chars_that_fit) . '...';
    }
}

// Create PDF instance
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->AddPage();

// Add college name and report title
$pdf->SetFont('Arial', 'B', 16);
$pdf->Cell(0, 10, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'C');
$pdf->SetFont('Arial', '', 12);
$pdf->Cell(0, 10, 'An Autonomous Institution, Affiliated to Anna University', 0, 1, 'C');

$yearInRoman = array(1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV')[$year];
$title = $yearInRoman . " Year - Section " . $section;
if ($semester > 0) {
    $title .= " Semester " . $semester;
} else {
    $title .= " ({$academic_year_data['year_range']})";
}
$title .= " Feedback Report";

$pdf->SetFont('Arial', 'B', 14);
$pdf->Cell(0, 10, $title, 0, 1, 'C');
$pdf->Cell(0, 6, "Academic Year: {$academic_year_data['year_range']}", 0, 1, 'C');
$pdf->Ln(5);

// Get department name
$dept_query = "SELECT name FROM departments WHERE id = ?";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, "i", $department_id);
mysqli_stmt_execute($dept_stmt);
$dept_result = mysqli_stmt_get_result($dept_stmt);
$department = mysqli_fetch_assoc($dept_result);

$pdf->CreateInfoBox('Department', $department['name']);
$pdf->Ln(5);

// Get section overview with enhanced metrics
$overview_query = "SELECT 
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT f.id) as total_feedback,
    COUNT(DISTINCT st.id) as total_students,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating,
    COUNT(DISTINCT CASE WHEN f.comments IS NOT NULL AND f.comments != '' THEN f.id END) as total_comments
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
LEFT JOIN feedback f ON sa.id = f.assignment_id
LEFT JOIN students st ON f.student_id = st.id
WHERE sa.academic_year_id = ? 
AND sa.year = ?
" . ($semester > 0 ? "AND sa.semester = ?" : "") . "
AND sa.section = ?
AND s.department_id = ?";

$overview_stmt = mysqli_prepare($conn, $overview_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($overview_stmt, "iissi", $academic_year, $year, $semester, $section, $department_id);
} else {
    mysqli_stmt_bind_param($overview_stmt, "iisi", $academic_year, $year, $section, $department_id);
}
mysqli_stmt_execute($overview_stmt);
$overview = mysqli_fetch_assoc(mysqli_stmt_get_result($overview_stmt));

// Section Overview
$pdf->SectionTitle('Section Overview');
$pdf->CreateInfoBox('Total Subjects', $overview['total_subjects']);
$pdf->CreateInfoBox('Total Students', $overview['total_students']);
$pdf->CreateInfoBox('Total Feedback', $overview['total_feedback']);
$pdf->CreateInfoBox('Total Comments', $overview['total_comments']);
$pdf->CreateInfoBox('Overall Rating', $overview['overall_rating']);
$pdf->Ln(5);

// Detailed Ratings
$pdf->SectionTitle('Detailed Ratings');
$pdf->CreateInfoBox('Course Effectiveness', $overview['course_effectiveness']);
$pdf->CreateInfoBox('Teaching Effectiveness', $overview['teaching_effectiveness']);
$pdf->CreateInfoBox('Resources & Administration', $overview['resources_admin']);
$pdf->CreateInfoBox('Assessment & Learning', $overview['assessment_learning']);
$pdf->CreateInfoBox('Course Outcomes', $overview['course_outcomes']);

// Subject-wise Analysis
$pdf->AddPage();
$pdf->SectionTitle('Subject-wise Analysis');

// Fetch subject-wise feedback with enhanced metrics
$subject_query = "SELECT 
    s.code,
    s.name as subject_name,
    f.name as faculty_name,
    sa.semester,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(fb.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(fb.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(fb.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(fb.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(fb.cumulative_avg), 2) as overall_rating,
    COUNT(DISTINCT CASE WHEN fb.comments IS NOT NULL AND fb.comments != '' THEN fb.id END) as total_comments
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
WHERE sa.academic_year_id = ?
AND sa.year = ?
" . ($semester > 0 ? "AND sa.semester = ?" : "") . "
AND sa.section = ?
AND s.department_id = ?
GROUP BY s.id, f.id, sa.semester
ORDER BY sa.semester, s.code";

$subject_stmt = mysqli_prepare($conn, $subject_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($subject_stmt, "iissi", $academic_year, $year, $semester, $section, $department_id);
} else {
    mysqli_stmt_bind_param($subject_stmt, "iisi", $academic_year, $year, $section, $department_id);
}
mysqli_stmt_execute($subject_stmt);
$subject_result = mysqli_stmt_get_result($subject_stmt);

// Create detailed subject table
$headers = array('Code', 'Subject', 'Faculty', 'Sem', 'Resp', 'Rating', 'Comments');
$data = array();

while ($subject = mysqli_fetch_assoc($subject_result)) {
    $data[] = array(
        $subject['code'],
        $subject['subject_name'],
        $subject['faculty_name'],
        $subject['semester'],
        $subject['feedback_count'],
        $subject['overall_rating'],
        $subject['total_comments']
    );
}

$pdf->CreateDetailedTable($headers, $data);

// Student Participation Analysis
$pdf->AddPage();
$pdf->SectionTitle('Student Participation Analysis');

// Fetch student participation data with enhanced metrics
$student_query = "SELECT 
    st.roll_number,
    st.name as student_name,
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT f.id) as submitted_feedback,
    ROUND(AVG(f.cumulative_avg), 2) as average_rating,
    COUNT(DISTINCT CASE WHEN f.comments IS NOT NULL AND f.comments != '' THEN f.id END) as total_comments
FROM students st
JOIN batch_years by2 ON st.batch_id = by2.id
JOIN subject_assignments sa ON sa.year = ? AND sa.section = ? AND sa.academic_year_id = ?
" . ($semester > 0 ? "AND sa.semester = ?" : "") . "
JOIN subjects s ON sa.subject_id = s.id AND s.department_id = ?
LEFT JOIN feedback f ON f.assignment_id = sa.id AND f.student_id = st.id
WHERE st.section = ?
AND st.department_id = ?
AND by2.current_year_of_study = ?
GROUP BY st.id
ORDER BY st.roll_number";

$student_stmt = mysqli_prepare($conn, $student_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($student_stmt, "isiiisii", $year, $section, $academic_year, $semester, $department_id, $section, $department_id, $year);
} else {
    mysqli_stmt_bind_param($student_stmt, "isiisii", $year, $section, $academic_year, $department_id, $section, $department_id, $year);
}
mysqli_stmt_execute($student_stmt);
$student_result = mysqli_stmt_get_result($student_stmt);

// Create student participation table with enhanced metrics
$headers = array('Roll No', 'Name', 'Total', 'Submitted', 'Status', '%', 'Comments');
$data = array();

while ($student = mysqli_fetch_assoc($student_result)) {
    $completion = ($student['submitted_feedback'] / $student['total_subjects']) * 100;
    $status = $completion == 100 ? 'Complete' : ($completion > 0 ? 'Partial' : 'Pending');
    
    $data[] = array(
        $student['roll_number'],
        $student['student_name'],
        $student['total_subjects'],
        $student['submitted_feedback'],
        $status,
        round($completion, 1) . '%',
        $student['total_comments']
    );
}

$pdf->CreateDetailedTable($headers, $data);

// Feedback Analysis by Category
$pdf->AddPage();
$pdf->SectionTitle('Feedback Analysis by Category');

// Fetch feedback analysis by category
$category_query = "SELECT 
    fs.section as category,
    COUNT(fr.id) as total_ratings,
    ROUND(AVG(fr.rating), 2) as average_rating,
    COUNT(DISTINCT CASE WHEN f.comments IS NOT NULL AND f.comments != '' THEN f.id END) as total_comments
FROM feedback_ratings fr
JOIN feedback_statements fs ON fr.statement_id = fs.id
JOIN feedback f ON fr.feedback_id = f.id
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
WHERE sa.academic_year_id = ?
AND sa.year = ?
" . ($semester > 0 ? "AND sa.semester = ?" : "") . "
AND sa.section = ?
AND s.department_id = ?
GROUP BY fs.section
ORDER BY fs.section";

$category_stmt = mysqli_prepare($conn, $category_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($category_stmt, "iissi", $academic_year, $year, $semester, $section, $department_id);
} else {
    mysqli_stmt_bind_param($category_stmt, "iisi", $academic_year, $year, $section, $department_id);
}
mysqli_stmt_execute($category_stmt);
$category_result = mysqli_stmt_get_result($category_stmt);

// Create feedback category table
$headers = array('Category', 'Total Ratings', 'Average Rating', 'Status', 'Comments');
$data = array();

while ($category = mysqli_fetch_assoc($category_result)) {
    $status = getRatingStatus($category['average_rating']);
    $data[] = array(
        str_replace('_', ' ', $category['category']),
        $category['total_ratings'],
        $category['average_rating'],
        $status,
        $category['total_comments']
    );
}

$pdf->CreateFeedbackTable($headers, $data);

// Notable Comments Section
$pdf->AddPage();
$pdf->SectionTitle('Notable Comments');

// Fetch notable comments
$comments_query = "SELECT 
    s.code as subject_code,
    s.name as subject_name,
    f.name as faculty_name,
    fb.comments,
    fb.submitted_at,
    CASE 
        WHEN fb.comments LIKE '%excellent%' OR fb.comments LIKE '%outstanding%' OR fb.comments LIKE '%fantastic%' OR fb.comments LIKE '%amazing%' OR fb.comments LIKE '%exceptional%' THEN 5
        WHEN fb.comments LIKE '%good%' OR fb.comments LIKE '%great%' OR fb.comments LIKE '%well%' OR fb.comments LIKE '%positive%' OR fb.comments LIKE '%helpful%' THEN 4
        WHEN fb.comments LIKE '%average%' OR fb.comments LIKE '%okay%' OR fb.comments LIKE '%ok%' OR fb.comments LIKE '%satisfactory%' THEN 3
        WHEN fb.comments LIKE '%poor%' OR fb.comments LIKE '%lacking%' OR fb.comments LIKE '%needs improvement%' OR fb.comments LIKE '%inadequate%' THEN 2
        WHEN fb.comments LIKE '%terrible%' OR fb.comments LIKE '%awful%' OR fb.comments LIKE '%worst%' OR fb.comments LIKE '%horrible%' OR fb.comments LIKE '%bad%' THEN 1
        ELSE 3
    END as sentiment_score,
    CASE
        WHEN fb.comments LIKE '%important%' OR fb.comments LIKE '%critical%' OR fb.comments LIKE '%urgent%' OR fb.comments LIKE '%must%' OR fb.comments LIKE '%need to%' THEN 3
        WHEN fb.comments LIKE '%suggest%' OR fb.comments LIKE '%recommend%' OR fb.comments LIKE '%consider%' OR fb.comments LIKE '%should%' THEN 2
        ELSE 1
    END as importance_score,
    CASE
        WHEN LENGTH(fb.comments) > 200 THEN 3
        WHEN LENGTH(fb.comments) > 100 THEN 2
        ELSE 1
    END as length_score
FROM feedback fb
JOIN subject_assignments sa ON fb.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
WHERE sa.academic_year_id = ?
AND sa.year = ?
" . ($semester > 0 ? "AND sa.semester = ?" : "") . "
AND sa.section = ?
AND s.department_id = ?
AND fb.comments IS NOT NULL 
AND fb.comments != ''
ORDER BY 
    (sentiment_score + importance_score + length_score) DESC, 
    fb.submitted_at DESC
LIMIT 20";

$comments_stmt = mysqli_prepare($conn, $comments_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($comments_stmt, "iissi", $academic_year, $year, $semester, $section, $department_id);
} else {
    mysqli_stmt_bind_param($comments_stmt, "iisi", $academic_year, $year, $section, $department_id);
}
mysqli_stmt_execute($comments_stmt);
$comments_result = mysqli_stmt_get_result($comments_stmt);

// Add comments to PDF
$pdf->SetFont('Helvetica', '', 10);
$pdf->SetTextColor(44, 62, 80);

while ($comment = mysqli_fetch_assoc($comments_result)) {
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 8, "{$comment['subject_code']} - {$comment['subject_name']}", 0, 1);
    $pdf->SetFont('Helvetica', 'I', 10);
    $pdf->Cell(0, 6, "Faculty: {$comment['faculty_name']}", 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->MultiCell(0, 6, $comment['comments'], 0, 'L');
    $pdf->SetFont('Helvetica', 'I', 9);
    $pdf->Cell(0, 6, "Submitted on: " . date('F j, Y', strtotime($comment['submitted_at'])), 0, 1);
    $pdf->Ln(5);
}

// Clear any output before sending PDF
ob_clean();

// Output PDF
$filename = "section_{$section}_" . ($semester > 0 ? "semester_{$semester}" : "all_semesters") . "_year_" . str_replace('/', '-', $academic_year_data['year_range']) . "_batch_" . $batch_name . "_report.pdf";
$pdf->Output($filename, 'D');

// End output buffering
ob_end_flush();

function getRatingStatus($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
} 