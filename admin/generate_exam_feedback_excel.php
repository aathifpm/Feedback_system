<?php
// Prevent any output before we start
ob_start();

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Log the error instead of displaying it
    error_log("Excel generation error: $errstr in $errfile on line $errline");
    return true;
});

// Include required libraries
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;

try {
    session_start();
    require_once '../db_connection.php';
    require_once '../functions.php';
    require_once 'includes/admin_functions.php';
    require_once '../vendor/autoload.php';

    // Check if user is logged in and is an admin
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header('Location: ../index.php');
        exit();
    }

    // Department filter based on admin type
    $department_filter = "";
    $department_params = [];

    // If department admin, restrict data to their department
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $department_filter = " AND s.department_id = ?";
        $department_params[] = $_SESSION['department_id'];
    }

    // Get current academic year
    $academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
    $academic_year_result = mysqli_query($conn, $academic_year_query);
    $current_academic_year = mysqli_fetch_assoc($academic_year_result);

    // Build the query based on filters
    $where_conditions = [];
    $params = [];
    $types = "";

    if (isset($_GET['academic_year']) && !empty($_GET['academic_year'])) {
        $where_conditions[] = "et.academic_year_id = ?";
        $params[] = $_GET['academic_year'];
        $types .= "i";
    } else {
        $where_conditions[] = "et.academic_year_id = ?";
        $params[] = $current_academic_year['id'];
        $types .= "i";
    }

    if (isset($_GET['department']) && !empty($_GET['department'])) {
        $where_conditions[] = "s.department_id = ?";
        $params[] = $_GET['department'];
        $types .= "i";
    } else if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $where_conditions[] = "s.department_id = ?";
        $params[] = $_SESSION['department_id'];
        $types .= "i";
    }

    if (isset($_GET['semester']) && !empty($_GET['semester'])) {
        $where_conditions[] = "et.semester = ?";
        $params[] = $_GET['semester'];
        $types .= "i";
    }

    if (isset($_GET['subject']) && !empty($_GET['subject'])) {
        $where_conditions[] = "s.id = ?";
        $params[] = $_GET['subject'];
        $types .= "i";
    }

    if (isset($_GET['section']) && !empty($_GET['section'])) {
        $where_conditions[] = "st.section = ?";
        $params[] = $_GET['section'];
        $types .= "s";
    }

    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = "%" . $_GET['search'] . "%";
        $where_conditions[] = "(s.name LIKE ? OR s.code LIKE ? OR st.name LIKE ? OR st.roll_number LIKE ?)";
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
        $types .= "ssss";
    }

    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

    // Create new Spreadsheet
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('College Feedback System')
        ->setTitle('Exam Feedback Report')
        ->setSubject('Exam Feedback Data')
        ->setDescription('Comprehensive report of examination feedback provided by students');

    // Set active sheet
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Exam Feedback Overview');

    // Header Styles
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E74C3C'],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];

    // Get department name if filtered
    $department_name = "All Departments";
    if (isset($_GET['department']) && !empty($_GET['department'])) {
        $dept_query = "SELECT name FROM departments WHERE id = ?";
        $dept_stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($dept_stmt, "i", $_GET['department']);
        mysqli_stmt_execute($dept_stmt);
        $dept_result = mysqli_stmt_get_result($dept_stmt);
        $dept_data = mysqli_fetch_assoc($dept_result);
        $department_name = $dept_data['name'];
    } else if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $dept_query = "SELECT name FROM departments WHERE id = ?";
        $dept_stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($dept_stmt, "i", $_SESSION['department_id']);
        mysqli_stmt_execute($dept_stmt);
        $dept_result = mysqli_stmt_get_result($dept_stmt);
        $dept_data = mysqli_fetch_assoc($dept_result);
        $department_name = $dept_data['name'];
    }

    // Get academic year info
    $year_info = "Current Academic Year";
    if (isset($_GET['academic_year']) && !empty($_GET['academic_year'])) {
        $year_query = "SELECT year_range FROM academic_years WHERE id = ?";
        $year_stmt = mysqli_prepare($conn, $year_query);
        mysqli_stmt_bind_param($year_stmt, "i", $_GET['academic_year']);
        mysqli_stmt_execute($year_stmt);
        $year_result = mysqli_stmt_get_result($year_stmt);
        $year_data = mysqli_fetch_assoc($year_result);
        $year_info = $year_data['year_range'];
    }

    // Get semester info
    $semester_info = "All Semesters";
    if (isset($_GET['semester']) && !empty($_GET['semester'])) {
        $semester_info = "Semester " . $_GET['semester'];
    }

    // Get section info
    $section_info = "All Sections";
    if (isset($_GET['section']) && !empty($_GET['section'])) {
        $section_info = "Section " . $_GET['section'];
    }

    // Add title
    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', 'EXAMINATION FEEDBACK REPORT');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add subtitle with filter info
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', $department_name . ' - ' . $year_info . ' - ' . $semester_info . ' - ' . $section_info);
    $sheet->getStyle('A2')->getFont()->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add report generation date
    $sheet->mergeCells('A3:H3');
    $sheet->setCellValue('A3', 'Generated on: ' . date('Y-m-d H:i:s'));
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add column headers
    $headers = [
        'Student Name',
        'Roll Number',
        'Subject',
        'Subject Code',
        'Semester',
        'Exam Date',
        'Overall Rating',
        'Has Comments'
    ];

    $col = 'A';
    $row = 5;
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $col++;
    }

    $sheet->getStyle('A5:H5')->applyFromArray($headerStyle);

    // Get exam feedbacks with filters
    $query = "SELECT 
        ef.id,
        ef.student_id,
        st.name as student_name,
        st.roll_number,
        s.name as subject_name,
        s.code as subject_code,
        d.name as department_name,
        et.semester,
        et.exam_date,
        et.exam_session,
        ef.coverage_relevance_avg,
        ef.quality_clarity_avg,
        ef.structure_balance_avg,
        ef.application_innovation_avg,
        ef.cumulative_avg,
        ef.submitted_at,
        CASE 
            WHEN COALESCE(ef.syllabus_coverage, ef.difficult_questions, ef.out_of_syllabus, 
                        ef.time_sufficiency, ef.fairness_rating, ef.improvements, 
                        ef.additional_comments) IS NOT NULL 
            THEN 'Yes' ELSE 'No' 
        END as has_comments
    FROM examination_feedback ef
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    JOIN departments d ON s.department_id = d.id
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    $where_clause
    ORDER BY ef.submitted_at DESC";

    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Add data to the sheet
    $row = 6;
    while ($feedback = mysqli_fetch_assoc($result)) {
        $sheet->setCellValue('A' . $row, $feedback['student_name']);
        $sheet->setCellValue('B' . $row, $feedback['roll_number']);
        $sheet->setCellValue('C' . $row, $feedback['subject_name']);
        $sheet->setCellValue('D' . $row, $feedback['subject_code']);
        $sheet->setCellValue('E' . $row, $feedback['semester']);
        $sheet->setCellValue('F' . $row, $feedback['exam_date'] . ' (' . $feedback['exam_session'] . ')');
        $sheet->setCellValue('G' . $row, number_format($feedback['cumulative_avg'], 2));
        $sheet->setCellValue('H' . $row, $feedback['has_comments']);
        
        // Apply conditional formatting to ratings
        $rating = $feedback['cumulative_avg'];
        if ($rating >= 4.5) {
            $sheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $sheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $sheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
        }
        
        $row++;
    }

    // Auto size columns
    foreach (range('A', 'H') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create the Rating Details sheet
    $ratingSheet = $spreadsheet->createSheet();
    $ratingSheet->setTitle('Rating Details');

    // Add headers for the rating details
    $ratingHeaders = [
        'Student Name',
        'Roll Number',
        'Subject',
        'Coverage & Relevance',
        'Quality & Clarity',
        'Structure & Balance',
        'Application & Innovation',
        'Overall Rating'
    ];

    $col = 'A';
    $row = 1;
    foreach ($ratingHeaders as $header) {
        $ratingSheet->setCellValue($col . $row, $header);
        $col++;
    }

    $ratingSheet->getStyle('A1:H1')->applyFromArray($headerStyle);

    // Re-execute the query to get data for this sheet
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Add data to the rating details sheet
    $row = 2;
    while ($feedback = mysqli_fetch_assoc($result)) {
        $ratingSheet->setCellValue('A' . $row, $feedback['student_name']);
        $ratingSheet->setCellValue('B' . $row, $feedback['roll_number']);
        $ratingSheet->setCellValue('C' . $row, $feedback['subject_name'] . ' (' . $feedback['subject_code'] . ')');
        $ratingSheet->setCellValue('D' . $row, number_format($feedback['coverage_relevance_avg'], 2));
        $ratingSheet->setCellValue('E' . $row, number_format($feedback['quality_clarity_avg'], 2));
        $ratingSheet->setCellValue('F' . $row, number_format($feedback['structure_balance_avg'], 2));
        $ratingSheet->setCellValue('G' . $row, number_format($feedback['application_innovation_avg'], 2));
        $ratingSheet->setCellValue('H' . $row, number_format($feedback['cumulative_avg'], 2));
        
        $row++;
    }

    // Auto size columns for rating sheet
    foreach (range('A', 'H') as $column) {
        $ratingSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create the Comments sheet
    $commentsSheet = $spreadsheet->createSheet();
    $commentsSheet->setTitle('Comments');

    // Add headers for the comments
    $commentHeaders = [
        'Student Name',
        'Roll Number',
        'Subject',
        'Syllabus Coverage',
        'Difficult Questions',
        'Out of Syllabus',
        'Time Sufficiency',
        'Fairness Rating',
        'Improvements',
        'Additional Comments'
    ];

    $col = 'A';
    $row = 1;
    foreach ($commentHeaders as $header) {
        $commentsSheet->setCellValue($col . $row, $header);
        $col++;
    }

    $commentsSheet->getStyle('A1:J1')->applyFromArray($headerStyle);

    // Get comments data
    $commentsQuery = "SELECT 
        ef.id,
        st.name as student_name,
        st.roll_number,
        s.name as subject_name,
        s.code as subject_code,
        ef.syllabus_coverage,
        ef.difficult_questions,
        ef.out_of_syllabus,
        ef.time_sufficiency,
        ef.fairness_rating,
        ef.improvements,
        ef.additional_comments
    FROM examination_feedback ef
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    JOIN departments d ON s.department_id = d.id
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    $where_clause
    AND (
        ef.syllabus_coverage IS NOT NULL OR
        ef.difficult_questions IS NOT NULL OR
        ef.out_of_syllabus IS NOT NULL OR
        ef.time_sufficiency IS NOT NULL OR
        ef.fairness_rating IS NOT NULL OR
        ef.improvements IS NOT NULL OR
        ef.additional_comments IS NOT NULL
    )
    ORDER BY ef.submitted_at DESC";

    $commentsStmt = mysqli_prepare($conn, $commentsQuery);
    if (!empty($params)) {
        mysqli_stmt_bind_param($commentsStmt, $types, ...$params);
    }
    mysqli_stmt_execute($commentsStmt);
    $commentsResult = mysqli_stmt_get_result($commentsStmt);

    // Add data to the comments sheet
    $row = 2;
    while ($comment = mysqli_fetch_assoc($commentsResult)) {
        $commentsSheet->setCellValue('A' . $row, $comment['student_name']);
        $commentsSheet->setCellValue('B' . $row, $comment['roll_number']);
        $commentsSheet->setCellValue('C' . $row, $comment['subject_name'] . ' (' . $comment['subject_code'] . ')');
        $commentsSheet->setCellValue('D' . $row, $comment['syllabus_coverage']);
        $commentsSheet->setCellValue('E' . $row, $comment['difficult_questions']);
        $commentsSheet->setCellValue('F' . $row, $comment['out_of_syllabus']);
        $commentsSheet->setCellValue('G' . $row, $comment['time_sufficiency']);
        $commentsSheet->setCellValue('H' . $row, $comment['fairness_rating']);
        $commentsSheet->setCellValue('I' . $row, $comment['improvements']);
        $commentsSheet->setCellValue('J' . $row, $comment['additional_comments']);
        
        $row++;
    }

    // Set column widths for comment sheet
    $commentsSheet->getColumnDimension('A')->setWidth(20);
    $commentsSheet->getColumnDimension('B')->setWidth(15);
    $commentsSheet->getColumnDimension('C')->setWidth(30);
    $commentsSheet->getColumnDimension('D')->setWidth(30);
    $commentsSheet->getColumnDimension('E')->setWidth(30);
    $commentsSheet->getColumnDimension('F')->setWidth(30);
    $commentsSheet->getColumnDimension('G')->setWidth(30);
    $commentsSheet->getColumnDimension('H')->setWidth(30);
    $commentsSheet->getColumnDimension('I')->setWidth(30);
    $commentsSheet->getColumnDimension('J')->setWidth(30);

    // Enable text wrapping for comment cells
    for ($i = 2; $i < $row; $i++) {
        $commentsSheet->getStyle('D' . $i . ':J' . $i)->getAlignment()->setWrapText(true);
        $commentsSheet->getRowDimension($i)->setRowHeight(60);
    }

    // Create the Aggregate Stats sheet
    $statsSheet = $spreadsheet->createSheet();
    $statsSheet->setTitle('Statistics');

    // Add headers and generate statistics data
    $statsSheet->setCellValue('A1', 'Examination Feedback Statistics');
    $statsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $statsSheet->mergeCells('A1:C1');

    // Get aggregated statistics
    $statsQuery = "SELECT 
        COUNT(DISTINCT ef.id) as total_feedback,
        COUNT(DISTINCT ef.student_id) as total_students,
        COUNT(DISTINCT ef.subject_assignment_id) as total_subjects,
        ROUND(AVG(ef.coverage_relevance_avg), 2) as avg_coverage,
        ROUND(AVG(ef.quality_clarity_avg), 2) as avg_quality,
        ROUND(AVG(ef.structure_balance_avg), 2) as avg_structure,
        ROUND(AVG(ef.application_innovation_avg), 2) as avg_application,
        ROUND(AVG(ef.cumulative_avg), 2) as overall_avg,
        SUM(CASE WHEN ef.syllabus_coverage IS NOT NULL THEN 1 ELSE 0 END) as syllabus_comments,
        SUM(CASE WHEN ef.difficult_questions IS NOT NULL THEN 1 ELSE 0 END) as difficult_comments,
        SUM(CASE WHEN ef.out_of_syllabus IS NOT NULL THEN 1 ELSE 0 END) as out_of_syllabus_comments,
        SUM(CASE WHEN ef.improvements IS NOT NULL THEN 1 ELSE 0 END) as improvement_comments
    FROM examination_feedback ef
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    JOIN departments d ON s.department_id = d.id
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    $where_clause";

    $statsStmt = mysqli_prepare($conn, $statsQuery);
    if (!empty($params)) {
        mysqli_stmt_bind_param($statsStmt, $types, ...$params);
    }
    mysqli_stmt_execute($statsStmt);
    $statsResult = mysqli_stmt_get_result($statsStmt);
    $stats = mysqli_fetch_assoc($statsResult);

    // Add statistics to the sheet
    $statsSheet->setCellValue('A3', 'Total Feedback Submissions:');
    $statsSheet->setCellValue('B3', $stats['total_feedback']);

    $statsSheet->setCellValue('A4', 'Total Students:');
    $statsSheet->setCellValue('B4', $stats['total_students']);

    $statsSheet->setCellValue('A5', 'Total Subjects:');
    $statsSheet->setCellValue('B5', $stats['total_subjects']);

    $statsSheet->setCellValue('A7', 'Average Ratings:');
    $statsSheet->getStyle('A7')->getFont()->setBold(true);

    $statsSheet->setCellValue('A8', 'Coverage & Relevance:');
    $statsSheet->setCellValue('B8', $stats['avg_coverage']);

    $statsSheet->setCellValue('A9', 'Quality & Clarity:');
    $statsSheet->setCellValue('B9', $stats['avg_quality']);

    $statsSheet->setCellValue('A10', 'Structure & Balance:');
    $statsSheet->setCellValue('B10', $stats['avg_structure']);

    $statsSheet->setCellValue('A11', 'Application & Innovation:');
    $statsSheet->setCellValue('B11', $stats['avg_application']);

    $statsSheet->setCellValue('A12', 'Overall Average:');
    $statsSheet->setCellValue('B12', $stats['overall_avg']);
    $statsSheet->getStyle('A12:B12')->getFont()->setBold(true);

    $statsSheet->setCellValue('A14', 'Comment Distribution:');
    $statsSheet->getStyle('A14')->getFont()->setBold(true);

    $statsSheet->setCellValue('A15', 'Syllabus Coverage Comments:');
    $statsSheet->setCellValue('B15', $stats['syllabus_comments']);

    $statsSheet->setCellValue('A16', 'Difficult Questions Comments:');
    $statsSheet->setCellValue('B16', $stats['difficult_comments']);

    $statsSheet->setCellValue('A17', 'Out of Syllabus Comments:');
    $statsSheet->setCellValue('B17', $stats['out_of_syllabus_comments']);

    $statsSheet->setCellValue('A18', 'Improvement Suggestions:');
    $statsSheet->setCellValue('B18', $stats['improvement_comments']);

    // Auto size columns
    foreach (range('A', 'C') as $column) {
        $statsSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create Subject-wise Analysis Sheet
    $subjectAnalysisSheet = $spreadsheet->createSheet();
    $subjectAnalysisSheet->setTitle('Subject Analysis');

    // Add headers for subject analysis
    $subjectHeaders = [
        'Subject Code',
        'Subject Name',
        'Semester',
        'Number of Responses',
        'Coverage & Relevance',
        'Quality & Clarity',
        'Structure & Balance',
        'Application & Innovation',
        'Overall Rating'
    ];

    $col = 'A';
    $row = 1;
    foreach ($subjectHeaders as $header) {
        $subjectAnalysisSheet->setCellValue($col . $row, $header);
        $col++;
    }

    $subjectAnalysisSheet->getStyle('A1:I1')->applyFromArray($headerStyle);

    // Get subject-wise aggregated statistics
    $subjectQuery = "SELECT 
        s.code as subject_code,
        s.name as subject_name,
        et.semester,
        COUNT(DISTINCT ef.id) as response_count,
        ROUND(AVG(ef.coverage_relevance_avg), 2) as avg_coverage,
        ROUND(AVG(ef.quality_clarity_avg), 2) as avg_quality,
        ROUND(AVG(ef.structure_balance_avg), 2) as avg_structure,
        ROUND(AVG(ef.application_innovation_avg), 2) as avg_application,
        ROUND(AVG(ef.cumulative_avg), 2) as overall_avg
    FROM examination_feedback ef
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    JOIN departments d ON s.department_id = d.id
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    $where_clause
    GROUP BY s.id, et.semester
    ORDER BY s.code, et.semester";

    $subjectStmt = mysqli_prepare($conn, $subjectQuery);
    if (!empty($params)) {
        mysqli_stmt_bind_param($subjectStmt, $types, ...$params);
    }
    mysqli_stmt_execute($subjectStmt);
    $subjectResult = mysqli_stmt_get_result($subjectStmt);

    // Add data to the subject analysis sheet
    $row = 2;
    while ($subject = mysqli_fetch_assoc($subjectResult)) {
        $subjectAnalysisSheet->setCellValue('A' . $row, $subject['subject_code']);
        $subjectAnalysisSheet->setCellValue('B' . $row, $subject['subject_name']);
        $subjectAnalysisSheet->setCellValue('C' . $row, $subject['semester']);
        $subjectAnalysisSheet->setCellValue('D' . $row, $subject['response_count']);
        $subjectAnalysisSheet->setCellValue('E' . $row, $subject['avg_coverage']);
        $subjectAnalysisSheet->setCellValue('F' . $row, $subject['avg_quality']);
        $subjectAnalysisSheet->setCellValue('G' . $row, $subject['avg_structure']);
        $subjectAnalysisSheet->setCellValue('H' . $row, $subject['avg_application']);
        $subjectAnalysisSheet->setCellValue('I' . $row, $subject['overall_avg']);
        
        // Apply conditional formatting to ratings
        $rating = $subject['overall_avg'];
        if ($rating >= 4.5) {
            $subjectAnalysisSheet->getStyle('I' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $subjectAnalysisSheet->getStyle('I' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $subjectAnalysisSheet->getStyle('I' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
        }
        
        $row++;
    }

    // Auto size columns for subject analysis sheet
    foreach (range('A', 'I') as $column) {
        $subjectAnalysisSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create Semester-wise Analysis Sheet
    $semesterAnalysisSheet = $spreadsheet->createSheet();
    $semesterAnalysisSheet->setTitle('Semester Analysis');

    // Add headers for semester analysis
    $semesterHeaders = [
        'Semester',
        'Number of Responses',
        'Number of Subjects',
        'Coverage & Relevance',
        'Quality & Clarity',
        'Structure & Balance',
        'Application & Innovation',
        'Overall Rating'
    ];

    $col = 'A';
    $row = 1;
    foreach ($semesterHeaders as $header) {
        $semesterAnalysisSheet->setCellValue($col . $row, $header);
        $col++;
    }

    $semesterAnalysisSheet->getStyle('A1:H1')->applyFromArray($headerStyle);

    // Get semester-wise aggregated statistics
    $semesterQuery = "SELECT 
        et.semester,
        COUNT(DISTINCT ef.id) as response_count,
        COUNT(DISTINCT s.id) as subject_count,
        ROUND(AVG(ef.coverage_relevance_avg), 2) as avg_coverage,
        ROUND(AVG(ef.quality_clarity_avg), 2) as avg_quality,
        ROUND(AVG(ef.structure_balance_avg), 2) as avg_structure,
        ROUND(AVG(ef.application_innovation_avg), 2) as avg_application,
        ROUND(AVG(ef.cumulative_avg), 2) as overall_avg
    FROM examination_feedback ef
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    JOIN departments d ON s.department_id = d.id
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    $where_clause
    GROUP BY et.semester
    ORDER BY et.semester";

    $semesterStmt = mysqli_prepare($conn, $semesterQuery);
    if (!empty($params)) {
        mysqli_stmt_bind_param($semesterStmt, $types, ...$params);
    }
    mysqli_stmt_execute($semesterStmt);
    $semesterResult = mysqli_stmt_get_result($semesterStmt);

    // Add data to the semester analysis sheet
    $row = 2;
    while ($semester = mysqli_fetch_assoc($semesterResult)) {
        $semesterAnalysisSheet->setCellValue('A' . $row, 'Semester ' . $semester['semester']);
        $semesterAnalysisSheet->setCellValue('B' . $row, $semester['response_count']);
        $semesterAnalysisSheet->setCellValue('C' . $row, $semester['subject_count']);
        $semesterAnalysisSheet->setCellValue('D' . $row, $semester['avg_coverage']);
        $semesterAnalysisSheet->setCellValue('E' . $row, $semester['avg_quality']);
        $semesterAnalysisSheet->setCellValue('F' . $row, $semester['avg_structure']);
        $semesterAnalysisSheet->setCellValue('G' . $row, $semester['avg_application']);
        $semesterAnalysisSheet->setCellValue('H' . $row, $semester['overall_avg']);
        
        // Apply conditional formatting to ratings
        $rating = $semester['overall_avg'];
        if ($rating >= 4.5) {
            $semesterAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $semesterAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $semesterAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
        }
        
        $row++;
    }

    // Auto size columns for semester analysis sheet
    foreach (range('A', 'H') as $column) {
        $semesterAnalysisSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Set the first sheet as active
    $spreadsheet->setActiveSheetIndex(0);

    // Generate filename based on filters
    $filename = 'exam_feedback_report';
    if (isset($_GET['department']) && !empty($_GET['department'])) {
        $filename .= '_' . preg_replace('/[^A-Za-z0-9]/', '_', $department_name);
    }
    if (isset($_GET['academic_year']) && !empty($_GET['academic_year'])) {
        $filename .= '_' . str_replace('/', '_', $year_info);
    }
    if (isset($_GET['semester']) && !empty($_GET['semester'])) {
        $filename .= '_sem' . $_GET['semester'];
    }
    if (isset($_GET['subject']) && !empty($_GET['subject'])) {
        // Get subject code for filename
        $subject_code_query = "SELECT code FROM subjects WHERE id = ?";
        $subject_code_stmt = mysqli_prepare($conn, $subject_code_query);
        mysqli_stmt_bind_param($subject_code_stmt, "i", $_GET['subject']);
        mysqli_stmt_execute($subject_code_stmt);
        $subject_code_result = mysqli_stmt_get_result($subject_code_stmt);
        $subject_code_data = mysqli_fetch_assoc($subject_code_result);
        if ($subject_code_data) {
            $filename .= '_' . preg_replace('/[^A-Za-z0-9]/', '', $subject_code_data['code']);
        }
    }
    if (isset($_GET['section']) && !empty($_GET['section'])) {
        $filename .= '_section' . $_GET['section'];
    }
    $filename .= '_' . date('Y-m-d') . '.xlsx';

    // Clear output buffer
    ob_end_clean();

    // Set headers and output the Excel file
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Expires: 0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    // Use PHP://output
    $writer->save('php://output');
} catch (Exception $e) {
    // Log the error
    error_log('Excel generation exception: ' . $e->getMessage());
    
    // Clear the output buffer
    ob_end_clean();
    
    // Redirect with error
    header('Location: view_exam_feedbacks.php?error=export');
    exit;
}
exit; 