<?php
session_start();
require 'vendor/autoload.php';
include 'functions.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hod') {
    header('Location: index.php');
    exit();
}

// Get parameters
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : 0;
$year = isset($_GET['year']) ? intval($_GET['year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$department_id = $_SESSION['department_id'];

// Validate required parameters
if (!$academic_year || !$year || !isset($semester) || empty($section)) {
    die("Required parameters missing");
}

// Get academic year details
$year_query = "SELECT year_range FROM academic_years WHERE id = ?";
$year_stmt = mysqli_prepare($conn, $year_query);
mysqli_stmt_bind_param($year_stmt, "i", $academic_year);
mysqli_stmt_execute($year_stmt);
$year_result = mysqli_stmt_get_result($year_stmt);
$academic_year_data = mysqli_fetch_assoc($year_result);

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

// Create new Spreadsheet with basic settings
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('College Feedback System')
    ->setTitle('Section-wise Feedback Report');

// Define basic styles
$headerStyle = [
    'font' => ['bold' => true],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '3498DB'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
];

// Get department name
$dept_query = "SELECT name FROM departments WHERE id = ?";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, "i", $department_id);
mysqli_stmt_execute($dept_stmt);
$dept_result = mysqli_stmt_get_result($dept_stmt);
$department = mysqli_fetch_assoc($dept_result);

// Get section overview first so we have data for the overview sheet
$overview_query = "SELECT 
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT f.id) as total_feedback,
    COUNT(DISTINCT st.id) as total_students,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating
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

// =====================
// OVERVIEW SHEET
// =====================
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Overview');

// College Title
$sheet->mergeCells('A1:D1');
$sheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// College Subtitle
$sheet->mergeCells('A2:D2');
$sheet->setCellValue('A2', 'An Autonomous Institution, Affiliated to Anna University');
$sheet->getStyle('A2')->getFont()->setBold(true);
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Report Title
$yearInRoman = array(1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV')[$year];
$title = $yearInRoman . " Year - Section " . $section;
if ($semester > 0) {
    $title .= " Semester " . $semester;
} else {
    $title .= " ({$academic_year_data['year_range']})";
}
$title .= " Feedback Report";

$sheet->mergeCells('A3:D3');
$sheet->setCellValue('A3', $title);
$sheet->getStyle('A3')->getFont()->setBold(true);
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Academic Year and Batch
$sheet->setCellValue('A5', 'Academic Year:');
$sheet->setCellValue('B5', $academic_year_data['year_range']);
$sheet->getStyle('A5')->getFont()->setBold(true);

$sheet->setCellValue('A6', 'Batch:');
$sheet->setCellValue('B6', $batch_name);
$sheet->getStyle('A6')->getFont()->setBold(true);

// Department info
$sheet->setCellValue('A7', 'Department:');
$sheet->setCellValue('B7', $department['name']);
$sheet->getStyle('A7')->getFont()->setBold(true);

// Overview data
$sheet->setCellValue('A9', 'Overview');
$sheet->getStyle('A9')->getFont()->setBold(true);
$sheet->getStyle('A9')->getFont()->setSize(14);

$sheet->setCellValue('A10', 'Total Subjects:');
$sheet->setCellValue('B10', $overview['total_subjects']);
$sheet->getStyle('A10')->getFont()->setBold(true);

$sheet->setCellValue('A11', 'Total Students:');
$sheet->setCellValue('B11', $overview['total_students']);
$sheet->getStyle('A11')->getFont()->setBold(true);

$sheet->setCellValue('A12', 'Total Feedback:');
$sheet->setCellValue('B12', $overview['total_feedback']);
$sheet->getStyle('A12')->getFont()->setBold(true);

$sheet->setCellValue('A13', 'Overall Rating:');
$sheet->setCellValue('B13', $overview['overall_rating']);
$sheet->getStyle('A13')->getFont()->setBold(true);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(20);
$sheet->getColumnDimension('D')->setWidth(20);

// =====================
// SUBJECT ANALYSIS SHEET
// =====================
$subjectSheet = $spreadsheet->createSheet();
$subjectSheet->setTitle('Subject Analysis');

// Fetch subject-wise feedback
$subject_query = "SELECT 
    s.code,
    s.name as subject_name,
    f.name as faculty_name,
    sa.semester,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as overall_rating
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

// Add headers
$headers = [
    'Subject Code',
    'Subject Name',
    'Faculty Name',
    'Semester',
    'Responses',
    'Overall Rating',
    'Status'
];

// Write headers
foreach (array_keys($headers) as $i) {
    $col = chr(65 + $i); // A, B, C, etc.
    $subjectSheet->setCellValue($col . '1', $headers[$i]);
    $subjectSheet->getStyle($col . '1')->getFont()->setBold(true);
    $subjectSheet->getStyle($col . '1')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
}

// Auto-size columns
for ($i = 0; $i < count($headers); $i++) {
    $subjectSheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
}

$row = 2;
while ($subject = mysqli_fetch_assoc($subject_result)) {
    $status = getRatingStatus($subject['overall_rating']);
    
    $subjectSheet->setCellValue('A'.$row, $subject['code']);
    $subjectSheet->setCellValue('B'.$row, $subject['subject_name']);
    $subjectSheet->setCellValue('C'.$row, $subject['faculty_name']);
    $subjectSheet->setCellValue('D'.$row, $subject['semester']);
    $subjectSheet->setCellValue('E'.$row, $subject['feedback_count']);
    $subjectSheet->setCellValue('F'.$row, $subject['overall_rating']);
    $subjectSheet->setCellValue('G'.$row, $status);
    
    $row++;
}

// =====================
// STUDENT PARTICIPATION SHEET
// =====================
$studentSheet = $spreadsheet->createSheet();
$studentSheet->setTitle('Student Participation');

// Fetch student participation data
$student_query = "SELECT 
    st.roll_number,
    st.name as student_name,
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT f.id) as submitted_feedback,
    ROUND(AVG(f.cumulative_avg), 2) as avg_rating
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

// Add headers
$headers = ['Roll Number', 'Student Name', 'Total Subjects', 'Feedback Submitted', 'Average Rating', 'Completion Status'];

// Write headers
foreach (array_keys($headers) as $i) {
    $col = chr(65 + $i); // A, B, C, etc.
    $studentSheet->setCellValue($col . '1', $headers[$i]);
    $studentSheet->getStyle($col . '1')->getFont()->setBold(true);
    $studentSheet->getStyle($col . '1')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
}

// Auto-size columns
for ($i = 0; $i < count($headers); $i++) {
    $studentSheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
}

$row = 2;
while ($student = mysqli_fetch_assoc($student_result)) {
    $completion = ($student['submitted_feedback'] / $student['total_subjects']) * 100;
    $status = $completion == 100 ? 'Completed' : ($completion > 0 ? 'Partial' : 'Pending');
    
    $studentSheet->setCellValue('A'.$row, $student['roll_number']);
    $studentSheet->setCellValue('B'.$row, $student['student_name']);
    $studentSheet->setCellValue('C'.$row, $student['total_subjects']);
    $studentSheet->setCellValue('D'.$row, $student['submitted_feedback']);
    $studentSheet->setCellValue('E'.$row, $student['avg_rating']);
    $studentSheet->setCellValue('F'.$row, $status);
    
    $row++;
}

// =====================
// COMMENTS SHEET
// =====================
$commentsSheet = $spreadsheet->createSheet();
$commentsSheet->setTitle('Comments');

// Fetch notable comments
$comments_query = "SELECT 
    s.code as subject_code,
    s.name as subject_name,
    f.name as faculty_name,
    fb.comments,
    DATE_FORMAT(fb.submitted_at, '%Y-%m-%d') as submitted_date,
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
    fb.submitted_at DESC";

$comments_stmt = mysqli_prepare($conn, $comments_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($comments_stmt, "iissi", $academic_year, $year, $semester, $section, $department_id);
} else {
    mysqli_stmt_bind_param($comments_stmt, "iisi", $academic_year, $year, $section, $department_id);
}
mysqli_stmt_execute($comments_stmt);
$comments_result = mysqli_stmt_get_result($comments_stmt);

// Add headers
$headers = ['Subject Code', 'Subject Name', 'Faculty', 'Comments', 'Date'];

// Write headers
foreach (array_keys($headers) as $i) {
    $col = chr(65 + $i); // A, B, C, etc.
    $commentsSheet->setCellValue($col . '1', $headers[$i]);
    $commentsSheet->getStyle($col . '1')->getFont()->setBold(true);
    $commentsSheet->getStyle($col . '1')->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
}

// Set column widths
$commentsSheet->getColumnDimension('A')->setWidth(15);  // Subject Code
$commentsSheet->getColumnDimension('B')->setWidth(30);  // Subject Name
$commentsSheet->getColumnDimension('C')->setWidth(25);  // Faculty
$commentsSheet->getColumnDimension('D')->setWidth(50);  // Comments
$commentsSheet->getColumnDimension('E')->setWidth(15);  // Date

$row = 2;
$commentCount = 0;
while ($comment = mysqli_fetch_assoc($comments_result)) {
    $commentCount++;
    
    $commentsSheet->setCellValue('A'.$row, $comment['subject_code']);
    $commentsSheet->setCellValue('B'.$row, $comment['subject_name']);
    $commentsSheet->setCellValue('C'.$row, $comment['faculty_name']);
    $commentsSheet->setCellValue('D'.$row, $comment['comments']);
    $commentsSheet->setCellValue('E'.$row, $comment['submitted_date']);
    
    // Enable text wrapping for comments
    $commentsSheet->getStyle('D'.$row)->getAlignment()->setWrapText(true);
    // Set row height to accommodate comments
    $commentsSheet->getRowDimension($row)->setRowHeight(50);
    
    $row++;
}

// If no comments found, add a message
if ($commentCount == 0) {
    $commentsSheet->mergeCells('A2:E2');
    $commentsSheet->setCellValue('A2', 'No feedback comments found for this section.');
    $commentsSheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="section_' . $section . '_semester_' . $semester . '_year_' . str_replace('/', '-', $academic_year_data['year_range']) . '_batch_' . $batch_name . '_report.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();

function getRatingStatus($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
}

function getRatingColor($rating) {
    if ($rating >= 4.5) return '27AE60'; // Green
    if ($rating >= 4.0) return '2ECC71'; // Light Green
    if ($rating >= 3.5) return 'F1C40F'; // Yellow
    if ($rating >= 3.0) return 'E67E22'; // Orange
    return 'E74C3C'; // Red
} 