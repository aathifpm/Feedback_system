<?php
session_start();
include 'functions.php';
require('fpdf.php');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods')) {
    header('Location: login.php');
    exit();
}

$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$section = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : null;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overall';
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : null;

// Fetch current academic year
$current_year = get_current_academic_year($conn);

if ($academic_year_id != $current_year['id']) {
    die("Error: Invalid academic year.");
}

// Fetch faculty details if faculty_id is provided
$faculty_data = null;
if ($faculty_id) {
    $faculty_query = "SELECT f.name, d.name AS department_name, f.email, f.experience
                      FROM faculty f
                      JOIN departments d ON f.department_id = d.id
                      WHERE f.id = ?";
    $faculty_stmt = mysqli_prepare($conn, $faculty_query);
    mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
    mysqli_stmt_execute($faculty_stmt);
    $faculty_result = mysqli_stmt_get_result($faculty_stmt);
    $faculty_data = mysqli_fetch_assoc($faculty_result);

    if (!$faculty_data) {
        die("Error: Invalid faculty ID.");
    }
}

// Custom PDF class with header and footer
class PDF extends FPDF {
    protected $col = 0;
    protected $y0;
    protected $pageTitle;

    function setPageTitle($title) {
        $this->pageTitle = $title;
    }

    function Header() {
        // Logo
        $this->Image('college_logo.png', 10, 6, 30);
        // Arial bold 15
        $this->SetFont('Arial', 'B', 15);
        // Move to the right
        $this->Cell(80);
        // Title
        $this->Cell(30, 10, 'Panimalar Engineering College', 0, 1, 'C');
        $this->SetFont('Arial', 'I', 12);
        $this->Cell(0, 10, 'Exit Survey Report', 0, 1, 'C');
        if ($this->pageTitle) {
            $this->Cell(0, 10, $this->pageTitle, 0, 1, 'C');
        }
        // Line break
        $this->Ln(20);
        // Save ordinate
        $this->y0 = $this->GetY();
    }

    function Footer() {
        // Position at 1.5 cm from bottom
        $this->SetY(-15);
        // Arial italic 8
        $this->SetFont('Arial', 'I', 8);
        // Text color in gray
        $this->SetTextColor(128);
        // Page number
        $this->Cell(0, 10, 'Page '.$this->PageNo().'/{nb}', 0, 0, 'C');
    }

    function ChapterTitle($num, $label) {
        // Arial 12
        $this->SetFont('Arial', '', 12);
        // Background color
        $this->SetFillColor(200, 220, 255);
        // Title
        $this->Cell(0, 6, "Chapter $num : $label", 0, 1, 'L', true);
        // Line break
        $this->Ln(4);
    }

    function ChapterBody($txt) {
        // Times 12
        $this->SetFont('Times', '', 12);
        // Output justified text
        $this->MultiCell(0, 5, $txt);
        // Line break
        $this->Ln();
    }
}

// Create PDF instance
$pdf = new PDF();
$pdf->AliasNbPages();

// Prepare the query based on report type
switch ($report_type) {
    case 'subject':
        $query = "SELECT s.name AS subject_name, s.semester, s.section,
                  COUNT(DISTINCT f.id) AS feedback_count,
                  AVG(fr.rating) AS avg_rating
                  FROM subjects s
                  LEFT JOIN feedback f ON s.id = f.subject_id
                  LEFT JOIN feedback_ratings fr ON f.id = fr.feedback_id
                  WHERE s.id = ? AND s.faculty_id = ? AND f.academic_year = ?
                  GROUP BY s.id, s.semester, s.section";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iii", $subject_id, $faculty_id, $academic_year_id);
        break;

    case 'year_section_semester':
        $query = "SELECT s.name AS subject_name, 
                  f.name AS faculty_name,
                  COUNT(DISTINCT fb.id) AS feedback_count,
                  AVG(fr.rating) AS avg_rating
                  FROM subjects s
                  JOIN faculty f ON s.faculty_id = f.id
                  LEFT JOIN feedback fb ON s.id = fb.subject_id
                  LEFT JOIN feedback_ratings fr ON fb.id = fr.feedback_id
                  WHERE s.semester = ? 
                  AND s.year = ? 
                  AND s.section = ? 
                  AND fb.academic_year_id = ?
                  AND s.is_active = TRUE
                  GROUP BY s.id, f.id
                  ORDER BY s.name, avg_rating DESC";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iisi", $semester, $year, $section, $academic_year_id);
        break;

    default: // overall faculty report
        $query = "SELECT s.name AS subject_name, s.semester,
                  COUNT(DISTINCT f.id) AS feedback_count,
                  AVG(fr.rating) AS avg_rating
                  FROM subjects s
                  LEFT JOIN feedback f ON s.id = f.subject_id
                  LEFT JOIN feedback_ratings fr ON f.id = fr.feedback_id
                  WHERE s.faculty_id = ? AND f.academic_year = ?
                  GROUP BY s.id, s.semester
                  ORDER BY s.semester, avg_rating DESC";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $faculty_id, $academic_year_id);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Generate PDF
$pdf->AddPage();

// Report Information
$pdf->ChapterTitle('Report Information');
$pdf->ChapterBody("Academic Year: " . $current_year['year_range'] . "\n");

switch ($report_type) {
    case 'subject':
        $pdf->ChapterBody("Faculty Name: " . $faculty_data['name'] . "\n" .
                          "Department: " . $faculty_data['department_name'] . "\n" .
                          "Subject: " . mysqli_fetch_assoc($result)['subject_name']);
        mysqli_data_seek($result, 0);
        break;
    case 'year_section_semester':
        $department_name = mysqli_fetch_assoc($result)['department_name'];
        mysqli_data_seek($result, 0);
        $pdf->ChapterBody("Department: " . $department_name . "\n" .
                          "Year: " . numberToRoman($year) . "\n" .
                          "Section: " . $section . "\n" .
                          "Semester: " . $semester);
        break;
    default: // overall faculty report
        $info = "";
        if ($faculty_data) {
            $info .= "Faculty Name: " . $faculty_data['name'] . "\n" .
                     "Department: " . $faculty_data['department_name'] . "\n" .
                     "Email: " . $faculty_data['email'] . "\n" .
                     "Experience: " . $faculty_data['experience'] . " years\n";
        }
        $pdf->ChapterBody($info);
}

$pdf->Ln(5);

// Feedback Data
$pdf->ChapterTitle('Feedback Data');

// Add table header
$pdf->SetFillColor(0, 51, 102);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 10);

switch ($report_type) {
    case 'subject':
        $pdf->Cell(60, 7, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'Semester', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'Section', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Feedback Count', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Avg. Rating', 1, 0, 'C', true);
        break;
    case 'year_section_semester':
        $pdf->Cell(50, 7, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(50, 7, 'Faculty', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Feedback Count', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Avg. Rating', 1, 0, 'C', true);
        break;
    default: // overall faculty report
        $pdf->Cell(60, 7, 'Subject', 1, 0, 'C', true);
        $pdf->Cell(20, 7, 'Semester', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Feedback Count', 1, 0, 'C', true);
        $pdf->Cell(30, 7, 'Avg. Rating', 1, 0, 'C', true);
        break;
}
$pdf->Ln();

// Reset text color for data
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', '', 10);

$total_feedback = 0;
$total_rating = 0;
$subject_count = 0;

$row_color = false;
while ($row = mysqli_fetch_assoc($result)) {
    $pdf->SetFillColor($row_color ? 240 : 255);
    switch ($report_type) {
        case 'subject':
            $pdf->Cell(60, 6, $row['subject_name'], 1, 0, 'L', $row_color);
            $pdf->Cell(20, 6, $row['semester'], 1, 0, 'C', $row_color);
            $pdf->Cell(20, 6, $row['section'], 1, 0, 'C', $row_color);
            $pdf->Cell(30, 6, $row['feedback_count'], 1, 0, 'C', $row_color);
            $pdf->Cell(30, 6, number_format($row['avg_rating'], 2), 1, 0, 'C', $row_color);
            break;
        case 'year_section_semester':
            $pdf->Cell(50, 6, $row['subject_name'], 1, 0, 'L', $row_color);
            $pdf->Cell(50, 6, $row['faculty_name'], 1, 0, 'L', $row_color);
            $pdf->Cell(30, 6, $row['feedback_count'], 1, 0, 'C', $row_color);
            $pdf->Cell(30, 6, number_format($row['avg_rating'], 2), 1, 0, 'C', $row_color);
            break;
        default: // overall faculty report
            $pdf->Cell(60, 6, $row['subject_name'], 1, 0, 'L', $row_color);
            $pdf->Cell(20, 6, $row['semester'], 1, 0, 'C', $row_color);
            $pdf->Cell(30, 6, $row['feedback_count'], 1, 0, 'C', $row_color);
            $pdf->Cell(30, 6, number_format($row['avg_rating'], 2), 1, 0, 'C', $row_color);
            break;
    }
    $pdf->Ln();

    $total_feedback += $row['feedback_count'];
    $total_rating += $row['avg_rating'];
    $subject_count++;
    $row_color = !$row_color;
}

$pdf->Ln(10);

// Overall Statistics
$pdf->ChapterTitle('Overall Statistics');
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(0, 6, 'Total Subjects: ' . $subject_count, 0, 1);
$pdf->Cell(0, 6, 'Total Feedback Received: ' . $total_feedback, 0, 1);
$pdf->Cell(0, 6, 'Overall Average Rating: ' . ($subject_count > 0 ? number_format($total_rating / $subject_count, 2) : 'N/A') . '/5', 0, 1);

// Add a chart
$pdf->AddPage(); // Start a new page for the chart
$pdf->ChapterTitle('Rating Distribution');
$chart_data = array(
    'Excellent (4.5-5.0)' => 0,
    'Good (3.5-4.4)' => 0,
    'Average (2.5-3.4)' => 0,
    'Below Average (1.5-2.4)' => 0,
    'Poor (0-1.4)' => 0
);

// Store the data in an array instead of relying on mysqli_data_seek
$all_data = array();
mysqli_data_seek($result, 0);
while ($row = mysqli_fetch_assoc($result)) {
    $all_data[] = $row;
}

foreach ($all_data as $row) {
    $rating = floatval($row['avg_rating']);
    if ($rating >= 4.5) $chart_data['Excellent (4.5-5.0)']++;
    elseif ($rating >= 3.5) $chart_data['Good (3.5-4.4)']++;
    elseif ($rating >= 2.5) $chart_data['Average (2.5-3.4)']++;
    elseif ($rating >= 1.5) $chart_data['Below Average (1.5-2.4)']++;
    else $chart_data['Poor (0-1.4)']++;
}

$pdf->SetFont('Arial', '', 8);
$colors = array(
    'Excellent (4.5-5.0)' => array(0, 102, 204),
    'Good (3.5-4.4)' => array(0, 204, 102),
    'Average (2.5-3.4)' => array(255, 204, 0),
    'Below Average (1.5-2.4)' => array(255, 102, 0),
    'Poor (0-1.4)' => array(204, 0, 0)
);

$chart_width = 140;
$bar_height = 20;
$x = $pdf->GetX() + 30;
$y = $pdf->GetY() + 10;

if ($subject_count > 0) {
    foreach ($chart_data as $label => $value) {
        $bar_width = ($value / $subject_count) * $chart_width;
        $pdf->SetFillColor($colors[$label][0], $colors[$label][1], $colors[$label][2]);
        $pdf->Rect($x, $y, $bar_width, $bar_height, 'F');
        $pdf->SetXY($x + $bar_width + 2, $y + 2);
        $pdf->Cell(20, 5, $value . ' (' . number_format(($value / $subject_count) * 100, 1) . '%)', 0, 0, 'L');
        $pdf->SetXY($x - 70, $y + 6);
        $pdf->Cell(65, 5, $label, 0, 0, 'R');
        $y += $bar_height + 5;
    }

    // Add a legend
    $y += 10;
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($x, $y);
    $pdf->Cell(0, 5, 'Legend:', 0, 1);
    $pdf->SetFont('Arial', '', 8);
    $legend_x = $x;
    $legend_y = $y + 7;
    foreach ($colors as $label => $color) {
        $pdf->SetFillColor($color[0], $color[1], $color[2]);
        $pdf->Rect($legend_x, $legend_y, 5, 5, 'F');
        $pdf->SetXY($legend_x + 8, $legend_y);
        $pdf->Cell(60, 5, $label, 0, 0);
        $legend_x += 70;
        if ($legend_x > 180) {
            $legend_x = $x;
            $legend_y += 7;
        }
    }
} else {
    $pdf->Cell(0, 10, 'No data available for chart', 0, 1, 'C');
}

// Generate the PDF
$pdf->Output('D', 'feedback_report_' . $report_type . '_' . $current_year['year_range'] . '.pdf');
