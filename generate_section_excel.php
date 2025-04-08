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

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator('College Feedback System')
    ->setLastModifiedBy('College Feedback System')
    ->setTitle('Section-wise Feedback Report')
    ->setSubject("Section $section - Semester $semester Report")
    ->setDescription('Detailed section-wise feedback report')
    ->setCategory('Section Feedback Analysis');

// Style configurations
$titleStyle = [
    'font' => [
        'bold' => true,
        'size' => 16,
        'color' => ['rgb' => '000000'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];

$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '3498DB'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
];

$subheaderStyle = [
    'font' => [
        'bold' => true,
        'size' => 12,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E8F4F9'],
    ],
];

// Overview Sheet
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Overview');

// College Name and Report Title
$sheet->mergeCells('A1:F1');
$sheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
$sheet->getStyle('A1')->applyFromArray($titleStyle);

$sheet->mergeCells('A2:F2');
$sheet->setCellValue('A2', 'An Autonomous Institution, Affiliated to Anna University');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$yearInRoman = array(1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV')[$year];
$title = $yearInRoman . " Year - Section " . $section;
if ($semester > 0) {
    $title .= " Semester " . $semester;
} else {
    $title .= " ({$academic_year_data['year_range']})";
}
$title .= " Feedback Report";

$sheet->mergeCells('A3:F3');
$sheet->setCellValue('A3', $title);
$sheet->getStyle('A3')->applyFromArray([
    'font' => ['bold' => true, 'size' => 14],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// Get section overview
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

// Add overview statistics
$sheet->mergeCells('A5:C5');
$sheet->setCellValue('A5', 'Section Overview');
$sheet->getStyle('A5:C5')->applyFromArray($subheaderStyle);

$sheet->setCellValue('A6', 'Total Subjects:');
$sheet->setCellValue('B6', $overview['total_subjects']);
$sheet->setCellValue('A7', 'Total Students:');
$sheet->setCellValue('B7', $overview['total_students']);
$sheet->setCellValue('A8', 'Total Feedback:');
$sheet->setCellValue('B8', $overview['total_feedback']);
$sheet->setCellValue('A9', 'Overall Rating:');
$sheet->setCellValue('B9', $overview['overall_rating']);

// Subject-wise Analysis Sheet
$subjectSheet = $spreadsheet->createSheet();
$subjectSheet->setTitle('Subject Analysis');

// Fetch subject-wise feedback
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
    'Course Effectiveness',
    'Teaching Effectiveness',
    'Resources & Admin',
    'Assessment & Learning',
    'Course Outcomes',
    'Overall Rating',
    'Status'
];

$subjectSheet->fromArray([$headers], NULL, 'A1');
$subjectSheet->getStyle('A1:L1')->applyFromArray($headerStyle);

// Auto-size columns
foreach (range('A', 'L') as $col) {
    $subjectSheet->getColumnDimension($col)->setAutoSize(true);
}

$row = 2;
while ($subject = mysqli_fetch_assoc($subject_result)) {
    $status = getRatingStatus($subject['overall_rating']);
    $color = getRatingColor($subject['overall_rating']);
    
    $subjectSheet->setCellValue('A'.$row, $subject['code']);
    $subjectSheet->setCellValue('B'.$row, $subject['subject_name']);
    $subjectSheet->setCellValue('C'.$row, $subject['faculty_name']);
    $subjectSheet->setCellValue('D'.$row, $subject['semester']);
    $subjectSheet->setCellValue('E'.$row, $subject['feedback_count']);
    $subjectSheet->setCellValue('F'.$row, $subject['course_effectiveness']);
    $subjectSheet->setCellValue('G'.$row, $subject['teaching_effectiveness']);
    $subjectSheet->setCellValue('H'.$row, $subject['resources_admin']);
    $subjectSheet->setCellValue('I'.$row, $subject['assessment_learning']);
    $subjectSheet->setCellValue('J'.$row, $subject['course_outcomes']);
    $subjectSheet->setCellValue('K'.$row, $subject['overall_rating']);
    $subjectSheet->setCellValue('L'.$row, $status);
    
    // Apply conditional formatting
    $subjectSheet->getStyle('K'.$row)->getFont()->setColor(new Color($color));
    $subjectSheet->getStyle('L'.$row)->getFont()->setColor(new Color($color));
    
    $row++;
}

// Student Performance Sheet
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
LEFT JOIN feedback f ON f.assignment_id = sa.id AND f.student_id = st.id
WHERE st.section = ?
AND st.department_id = ?
AND by2.current_year_of_study = ?
GROUP BY st.id
ORDER BY st.roll_number";

$student_stmt = mysqli_prepare($conn, $student_query);
if ($semester > 0) {
    mysqli_stmt_bind_param($student_stmt, "isiisii", $year, $section, $academic_year, $semester, $section, $department_id, $year);
} else {
    mysqli_stmt_bind_param($student_stmt, "isiiisi", $year, $section, $academic_year, $section, $department_id, $year);
}
mysqli_stmt_execute($student_stmt);
$student_result = mysqli_stmt_get_result($student_stmt);

// Add headers
$headers = ['Roll Number', 'Student Name', 'Total Subjects', 'Feedback Submitted', 'Average Rating', 'Completion Status'];
$studentSheet->fromArray([$headers], NULL, 'A1');
$studentSheet->getStyle('A1:F1')->applyFromArray($headerStyle);

// Auto-size columns
foreach (range('A', 'F') as $col) {
    $studentSheet->getColumnDimension($col)->setAutoSize(true);
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

// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Set headers and output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="section_' . $section . '_semester_' . $semester . '_report.xlsx"');
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