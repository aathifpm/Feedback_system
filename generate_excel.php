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

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] != 'hod' && $_SESSION['role'] != 'hods' && $_SESSION['role'] != 'admin' && $_SESSION['role'] != 'faculty')) {
    header('Location: index.php');
    exit();
}

// Get parameters
$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overall';
$academic_year = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$batch = isset($_GET['batch']) ? intval($_GET['batch']) : 0;

// Fetch faculty details
$faculty_query = "SELECT f.*, d.name AS department_name 
                 FROM faculty f
                 JOIN departments d ON f.department_id = d.id
                 WHERE f.id = ?";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
$faculty = mysqli_fetch_assoc($faculty_result);

if (!$faculty) {
    die("Error: Invalid faculty ID.");
}

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator('College Feedback System')
    ->setLastModifiedBy('College Feedback System')
    ->setTitle('Faculty Feedback Report')
    ->setSubject('Feedback Report for ' . $faculty['name'])
    ->setDescription('Detailed feedback report generated from the College Feedback System')
    ->setCategory('Faculty Feedback Analysis');

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

$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];

// Build the query based on report type
$query_conditions = "sa.faculty_id = $faculty_id AND sa.is_active = TRUE";
$report_title = "Overall Feedback Report";

switch ($report_type) {
    case 'academic_year':
        if ($academic_year) {
            $query_conditions .= " AND sa.academic_year_id = $academic_year";
            $year_query = "SELECT year_range FROM academic_years WHERE id = $academic_year";
            $year_result = mysqli_query($conn, $year_query);
            $year_data = mysqli_fetch_assoc($year_result);
            $report_title = "Academic Year " . $year_data['year_range'] . " Feedback Report";
        }
        break;
    case 'semester':
        if ($academic_year && $semester) {
            $query_conditions .= " AND sa.academic_year_id = $academic_year AND sa.semester = $semester";
            $report_title = "Semester $semester Feedback Report";
        }
        break;
    case 'section':
        if ($academic_year && $semester && $section) {
            $query_conditions .= " AND sa.academic_year_id = $academic_year AND sa.semester = $semester AND sa.section = '$section'";
            $report_title = "Section $section Feedback Report";
        }
        break;
    case 'batch':
        if ($batch) {
            $query_conditions .= " AND st.batch_id = $batch";
            $batch_query = "SELECT batch_name FROM batch_years WHERE id = $batch";
            $batch_result = mysqli_query($conn, $batch_query);
            $batch_data = mysqli_fetch_assoc($batch_result);
            $report_title = "Batch " . $batch_data['batch_name'] . " Feedback Report";
        }
        break;
}

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

$sheet->mergeCells('A3:F3');
$sheet->setCellValue('A3', $report_title);
$sheet->getStyle('A3')->applyFromArray([
    'font' => ['bold' => true, 'size' => 14],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// Faculty Information
$sheet->mergeCells('A5:C5');
$sheet->setCellValue('A5', 'Faculty Details');
$sheet->getStyle('A5:C5')->applyFromArray($subheaderStyle);

$sheet->setCellValue('A6', 'Name:');
$sheet->setCellValue('B6', $faculty['name']);
$sheet->setCellValue('A7', 'Faculty ID:');
$sheet->setCellValue('B7', $faculty['faculty_id']);
$sheet->setCellValue('A8', 'Department:');
$sheet->setCellValue('B8', $faculty['department_name']);
$sheet->setCellValue('A9', 'Designation:');
$sheet->setCellValue('B9', $faculty['designation']);

// Fetch overall statistics
$feedback_query = "SELECT 
    COUNT(DISTINCT f.id) as total_responses,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating,
    COUNT(DISTINCT s.id) as total_subjects,
    COUNT(DISTINCT CONCAT(sa.semester, sa.section)) as total_classes
FROM subject_assignments sa
LEFT JOIN feedback f ON sa.id = f.assignment_id
LEFT JOIN students st ON f.student_id = st.id
JOIN subjects s ON sa.subject_id = s.id
WHERE $query_conditions";

$stats_result = mysqli_query($conn, $feedback_query);
$stats = mysqli_fetch_assoc($stats_result);

// Overall Statistics
$sheet->mergeCells('D5:F5');
$sheet->setCellValue('D5', 'Overall Statistics');
$sheet->getStyle('D5:F5')->applyFromArray($subheaderStyle);

$sheet->setCellValue('D6', 'Total Responses:');
$sheet->setCellValue('E6', $stats['total_responses']);
$sheet->setCellValue('D7', 'Overall Rating:');
$sheet->setCellValue('E7', $stats['overall_rating']);
$sheet->setCellValue('D8', 'Total Subjects:');
$sheet->setCellValue('E8', $stats['total_subjects']);
$sheet->setCellValue('D9', 'Total Classes:');
$sheet->setCellValue('E9', $stats['total_classes']);

// Subject-wise Analysis Sheet
$subjectSheet = $spreadsheet->createSheet();
$subjectSheet->setTitle('Subject Analysis');

// Fetch subject-wise feedback
$subject_query = "SELECT 
    s.code,
    s.name as subject_name,
    sa.semester,
    sa.section,
    COUNT(DISTINCT f.id) as feedback_count,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
LEFT JOIN feedback f ON sa.id = f.assignment_id
LEFT JOIN students st ON f.student_id = st.id
WHERE $query_conditions
GROUP BY s.id, sa.id
ORDER BY s.code, sa.semester, sa.section";

$subject_result = mysqli_query($conn, $subject_query);

// Add headers
$headers = [
    'Subject Code & Name', 'Semester', 'Section', 'Responses',
    'Course Effectiveness', 'Teaching Effectiveness', 'Resources & Admin',
    'Assessment & Learning', 'Course Outcomes', 'Overall Rating', 'Status'
];

foreach (range('A', 'K') as $col) {
    $subjectSheet->getColumnDimension($col)->setAutoSize(true);
}

$subjectSheet->fromArray([$headers], NULL, 'A1');
$subjectSheet->getStyle('A1:K1')->applyFromArray($headerStyle);

$row = 2;
while ($data = mysqli_fetch_assoc($subject_result)) {
    $status = getRatingStatus($data['overall_rating']);
    $color = getRatingColor($data['overall_rating']);
    
    $subjectSheet->setCellValue('A'.$row, $data['code'] . ' - ' . $data['subject_name']);
    $subjectSheet->setCellValue('B'.$row, $data['semester']);
    $subjectSheet->setCellValue('C'.$row, $data['section']);
    $subjectSheet->setCellValue('D'.$row, $data['feedback_count']);
    $subjectSheet->setCellValue('E'.$row, $data['course_effectiveness']);
    $subjectSheet->setCellValue('F'.$row, $data['teaching_effectiveness']);
    $subjectSheet->setCellValue('G'.$row, $data['resources_admin']);
    $subjectSheet->setCellValue('H'.$row, $data['assessment_learning']);
    $subjectSheet->setCellValue('I'.$row, $data['course_outcomes']);
    $subjectSheet->setCellValue('J'.$row, $data['overall_rating']);
    $subjectSheet->setCellValue('K'.$row, $status);
    
    // Apply conditional formatting
    $subjectSheet->getStyle('J'.$row)->getFont()->setColor(new Color($color));
    $subjectSheet->getStyle('K'.$row)->getFont()->setColor(new Color($color));
    
    $row++;
}

// Detailed Analysis Sheet
$detailSheet = $spreadsheet->createSheet();
$detailSheet->setTitle('Detailed Analysis');

// Fetch feedback statements
$stmt_query = "SELECT id, statement, section FROM feedback_statements WHERE is_active = TRUE ORDER BY section, id";
$stmt_result = mysqli_query($conn, $stmt_query);

$statements = [];
while ($stmt = mysqli_fetch_assoc($stmt_result)) {
    $statements[$stmt['section']][] = $stmt;
}

$row = 1;
foreach ($statements as $section => $section_statements) {
    $detailSheet->mergeCells('A'.$row.':F'.$row);
    $detailSheet->setCellValue('A'.$row, getFeedbackSectionTitle($section));
    $detailSheet->getStyle('A'.$row.':F'.$row)->applyFromArray($subheaderStyle);

$row++;
    $detailSheet->fromArray([['Statement', 'Average Rating', 'Responses', 'Excellent', 'Good', 'Needs Improvement']], NULL, 'A'.$row);
    $detailSheet->getStyle('A'.$row.':F'.$row)->applyFromArray($headerStyle);
    
    $row++;
    foreach ($section_statements as $statement) {
        $ratings_query = "SELECT 
            COUNT(fr.rating) as total_responses,
            ROUND(AVG(fr.rating), 2) as avg_rating,
            SUM(CASE WHEN fr.rating >= 4.5 THEN 1 ELSE 0 END) as excellent,
            SUM(CASE WHEN fr.rating >= 3.5 AND fr.rating < 4.5 THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN fr.rating < 3.5 THEN 1 ELSE 0 END) as needs_improvement
        FROM feedback_ratings fr
        JOIN feedback f ON fr.feedback_id = f.id
        JOIN subject_assignments sa ON f.assignment_id = sa.id
        LEFT JOIN students st ON f.student_id = st.id
        WHERE fr.statement_id = {$statement['id']} AND $query_conditions";
        
        $ratings_result = mysqli_query($conn, $ratings_query);
        $ratings = mysqli_fetch_assoc($ratings_result);
        
        $detailSheet->setCellValue('A'.$row, $statement['statement']);
        $detailSheet->setCellValue('B'.$row, $ratings['avg_rating']);
        $detailSheet->setCellValue('C'.$row, $ratings['total_responses']);
        $detailSheet->setCellValue('D'.$row, $ratings['excellent']);
        $detailSheet->setCellValue('E'.$row, $ratings['good']);
        $detailSheet->setCellValue('F'.$row, $ratings['needs_improvement']);
        
        // Apply conditional formatting based on average rating
        $color = getRatingColor($ratings['avg_rating']);
        $detailSheet->getStyle('B'.$row)->getFont()->setColor(new Color($color));
        
    $row++;
    }
    $row++; // Add space between sections
}

// Comments Sheet
$commentSheet = $spreadsheet->createSheet();
$commentSheet->setTitle('Student Comments');

// Fetch student comments
$comments_query = "SELECT 
    f.comments,
    s.code,
    s.name as subject_name,
    sa.semester,
    sa.section,
    f.submitted_at
FROM feedback f
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
LEFT JOIN students st ON f.student_id = st.id
WHERE $query_conditions AND f.comments IS NOT NULL AND f.comments != ''
ORDER BY f.submitted_at DESC";

$comments_result = mysqli_query($conn, $comments_query);

$commentSheet->fromArray([['Subject', 'Semester', 'Section', 'Comment', 'Submitted Date']], NULL, 'A1');
$commentSheet->getStyle('A1:E1')->applyFromArray($headerStyle);

$row = 2;
while ($comment = mysqli_fetch_assoc($comments_result)) {
    $commentSheet->setCellValue('A'.$row, $comment['code'] . ' - ' . $comment['subject_name']);
    $commentSheet->setCellValue('B'.$row, $comment['semester']);
    $commentSheet->setCellValue('C'.$row, $comment['section']);
    $commentSheet->setCellValue('D'.$row, $comment['comments']);
    $commentSheet->setCellValue('E'.$row, date('Y-m-d', strtotime($comment['submitted_at'])));
        $row++;
}

// Auto-size columns for all sheets
foreach ($spreadsheet->getAllSheets() as $sheet) {
    foreach (range('A', 'K') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// Set active sheet to first sheet
$spreadsheet->setActiveSheetIndex(0);

// Set headers and output the file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="faculty_feedback_report.xlsx"');
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

function getFeedbackSectionTitle($section) {
    $titles = [
        'COURSE_EFFECTIVENESS' => 'Course Effectiveness Analysis',
        'TEACHING_EFFECTIVENESS' => 'Teaching Effectiveness Analysis',
        'RESOURCES_ADMIN' => 'Resources & Administration Analysis',
        'ASSESSMENT_LEARNING' => 'Assessment & Learning Analysis',
        'COURSE_OUTCOMES' => 'Course Outcomes Analysis'
    ];
    return $titles[$section] ?? $section;
} 