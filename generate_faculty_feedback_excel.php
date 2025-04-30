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
    require_once 'db_connection.php';
    require_once 'functions.php';
    require_once 'vendor/autoload.php';

    // Check if user is logged in and is a faculty
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'faculty') {
        header('Location: index.php');
        exit();
    }

    $faculty_id = $_SESSION['user_id'];

    // Check if assignment_id is provided
    if (!isset($_GET['assignment_id'])) {
        header('Location: faculty_examination_feedback.php');
        exit();
    }

    $assignment_id = intval($_GET['assignment_id']);

    // Verify that this assignment belongs to the logged-in faculty
    $check_query = "SELECT sa.id, sa.subject_id, sa.semester, sa.section, sa.year, 
                    s.code as subject_code, s.name as subject_name, 
                    d.name as department_name, ay.year_range as academic_year
                    FROM subject_assignments sa
                    JOIN subjects s ON sa.subject_id = s.id
                    JOIN departments d ON s.department_id = d.id
                    JOIN academic_years ay ON sa.academic_year_id = ay.id
                    WHERE sa.id = ? AND sa.faculty_id = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $assignment_id, $faculty_id);
    mysqli_stmt_execute($check_stmt);
    $assignment_result = mysqli_stmt_get_result($check_stmt);

    if (mysqli_num_rows($assignment_result) === 0) {
        // This assignment either doesn't exist or doesn't belong to this faculty
        header('Location: faculty_examination_feedback.php');
        exit();
    }

    $assignment = mysqli_fetch_assoc($assignment_result);

    // Get faculty details
    $faculty_query = "SELECT f.*, d.name AS department_name 
                     FROM faculty f
                     JOIN departments d ON f.department_id = d.id
                     WHERE f.id = ?";
    $faculty_stmt = mysqli_prepare($conn, $faculty_query);
    mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
    mysqli_stmt_execute($faculty_stmt);
    $faculty_result = mysqli_stmt_get_result($faculty_stmt);
    $faculty = mysqli_fetch_assoc($faculty_result);

    // Get filters
    $selected_exam = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
    $include_comments = isset($_GET['include_comments']) ? intval($_GET['include_comments']) : 1;
    $format = isset($_GET['format']) ? $_GET['format'] : 'excel';

    // Create new Spreadsheet
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('College Feedback System')
        ->setTitle('Exam Feedback Report')
        ->setSubject('Exam Feedback Data')
        ->setDescription('Comprehensive report of examination feedback for ' . $assignment['subject_code']);

    // Set active sheet
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Exam Feedback Overview');

    // Header Styles
    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '3498DB'], // Blue for faculty
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

    // Data Styles
    $dataStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];

    // Add title
    $sheet->mergeCells('A1:H1');
    $sheet->setCellValue('A1', 'EXAMINATION FEEDBACK REPORT');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add faculty and subject info
    $sheet->mergeCells('A2:H2');
    $sheet->setCellValue('A2', 'Faculty: ' . $faculty['name'] . ' (' . $faculty['faculty_id'] . ') - ' . $faculty['department_name']);
    $sheet->getStyle('A2')->getFont()->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add subject details
    $sheet->mergeCells('A3:H3');
    $sheet->setCellValue('A3', 'Subject: ' . $assignment['subject_name'] . ' (' . $assignment['subject_code'] . ') - ' . 
                       'Semester ' . $assignment['semester'] . ' - Section ' . $assignment['section']);
    $sheet->getStyle('A3')->getFont()->setBold(true);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add academic year info
    $sheet->mergeCells('A4:H4');
    $sheet->setCellValue('A4', 'Academic Year: ' . $assignment['academic_year'] . ' - Generated on: ' . date('Y-m-d H:i:s'));
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Add column headers
    $headers = [
        'Student Roll No.',
        'Exam Date',
        'Session',
        'Subject',
        'Overall Rating',
        'Coverage & Relevance',
        'Quality & Clarity',
        'Has Comments'
    ];

    $col = 'A';
    $row = 6;
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $col++;
    }

    $sheet->getStyle('A6:H6')->applyFromArray($headerStyle);

    // Build the query based on filters
    $where_clause = "WHERE ef.subject_assignment_id = ?";
    $params = [$assignment_id];
    $types = "i";

    if ($selected_exam > 0) {
        $where_clause .= " AND ef.exam_timetable_id = ?";
        $params[] = $selected_exam;
        $types .= "i";
    }

    // Get exam feedbacks with filters
    $query = "SELECT 
        ef.id,
        ef.student_id,
        st.roll_number,
        et.exam_date,
        et.exam_session,
        s.name as subject_name,
        s.code as subject_code,
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
    JOIN students st ON ef.student_id = st.id
    JOIN exam_timetable et ON ef.exam_timetable_id = et.id
    JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
    JOIN subjects s ON sa.subject_id = s.id
    $where_clause
    ORDER BY ef.submitted_at DESC";

    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    // Add data to the sheet
    $row = 7;
    while ($feedback = mysqli_fetch_assoc($result)) {
        $sheet->setCellValue('A' . $row, $feedback['roll_number']);
        $sheet->setCellValue('B' . $row, date('d M Y', strtotime($feedback['exam_date'])));
        $sheet->setCellValue('C' . $row, $feedback['exam_session']);
        $sheet->setCellValue('D' . $row, $feedback['subject_name'] . ' (' . $feedback['subject_code'] . ')');
        $sheet->setCellValue('E' . $row, number_format($feedback['cumulative_avg'], 2));
        $sheet->setCellValue('F' . $row, number_format($feedback['coverage_relevance_avg'], 2));
        $sheet->setCellValue('G' . $row, number_format($feedback['quality_clarity_avg'], 2));
        $sheet->setCellValue('H' . $row, $feedback['has_comments']);
        
        // Apply conditional formatting to ratings
        $rating = $feedback['cumulative_avg'];
        if ($rating >= 4.5) {
            $sheet->getStyle('E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $sheet->getStyle('E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $sheet->getStyle('E' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
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
        'Student Roll No.',
        'Exam Date',
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

    $ratingSheet->getStyle('A1:G1')->applyFromArray($headerStyle);

    // Re-execute the query to get data for this sheet
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    // Add data to the rating details sheet
    $row = 2;
    while ($feedback = mysqli_fetch_assoc($result)) {
        $ratingSheet->setCellValue('A' . $row, $feedback['roll_number']);
        $ratingSheet->setCellValue('B' . $row, date('d M Y', strtotime($feedback['exam_date'])) . ' (' . $feedback['exam_session'] . ')');
        $ratingSheet->setCellValue('C' . $row, number_format($feedback['coverage_relevance_avg'], 2));
        $ratingSheet->setCellValue('D' . $row, number_format($feedback['quality_clarity_avg'], 2));
        $ratingSheet->setCellValue('E' . $row, number_format($feedback['structure_balance_avg'], 2));
        $ratingSheet->setCellValue('F' . $row, number_format($feedback['application_innovation_avg'], 2));
        $ratingSheet->setCellValue('G' . $row, number_format($feedback['cumulative_avg'], 2));
        
        // Apply conditional formatting to ratings
        $rating = $feedback['cumulative_avg'];
        if ($rating >= 4.5) {
            $ratingSheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $ratingSheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $ratingSheet->getStyle('G' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
        }
        
        $row++;
    }

    // Auto size columns for rating sheet
    foreach (range('A', 'G') as $column) {
        $ratingSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create the Comments sheet
    $commentsSheet = $spreadsheet->createSheet();
    $commentsSheet->setTitle('Comments');

    // Add headers for the comments
    $commentHeaders = [
        'Student Roll No.',
        'Exam Date',
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

    $commentsSheet->getStyle('A1:I1')->applyFromArray($headerStyle);

    // Get comments data
    $commentsQuery = "SELECT 
        ef.id,
        st.roll_number,
        et.exam_date,
        et.exam_session,
        ef.syllabus_coverage,
        ef.difficult_questions,
        ef.out_of_syllabus,
        ef.time_sufficiency,
        ef.fairness_rating,
        ef.improvements,
        ef.additional_comments
    FROM examination_feedback ef
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
    mysqli_stmt_bind_param($commentsStmt, $types, ...$params);
    mysqli_stmt_execute($commentsStmt);
    $commentsResult = mysqli_stmt_get_result($commentsStmt);

    // Add data to the comments sheet
    $row = 2;
    while ($comment = mysqli_fetch_assoc($commentsResult)) {
        $commentsSheet->setCellValue('A' . $row, $comment['roll_number']);
        $commentsSheet->setCellValue('B' . $row, date('d M Y', strtotime($comment['exam_date'])) . ' (' . $comment['exam_session'] . ')');
        $commentsSheet->setCellValue('C' . $row, $comment['syllabus_coverage']);
        $commentsSheet->setCellValue('D' . $row, $comment['difficult_questions']);
        $commentsSheet->setCellValue('E' . $row, $comment['out_of_syllabus']);
        $commentsSheet->setCellValue('F' . $row, $comment['time_sufficiency']);
        $commentsSheet->setCellValue('G' . $row, $comment['fairness_rating']);
        $commentsSheet->setCellValue('H' . $row, $comment['improvements']);
        $commentsSheet->setCellValue('I' . $row, $comment['additional_comments']);
        
        $row++;
    }

    // Set column widths for comment sheet
    $commentsSheet->getColumnDimension('A')->setWidth(15);
    $commentsSheet->getColumnDimension('B')->setWidth(20);
    $commentsSheet->getColumnDimension('C')->setWidth(30);
    $commentsSheet->getColumnDimension('D')->setWidth(30);
    $commentsSheet->getColumnDimension('E')->setWidth(30);
    $commentsSheet->getColumnDimension('F')->setWidth(30);
    $commentsSheet->getColumnDimension('G')->setWidth(30);
    $commentsSheet->getColumnDimension('H')->setWidth(30);
    $commentsSheet->getColumnDimension('I')->setWidth(30);

    // Enable text wrapping for comment cells
    for ($i = 2; $i < $row; $i++) {
        $commentsSheet->getStyle('C' . $i . ':I' . $i)->getAlignment()->setWrapText(true);
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
    $where_clause";

    $statsStmt = mysqli_prepare($conn, $statsQuery);
    mysqli_stmt_bind_param($statsStmt, $types, ...$params);
    mysqli_stmt_execute($statsStmt);
    $statsResult = mysqli_stmt_get_result($statsStmt);
    $stats = mysqli_fetch_assoc($statsResult);

    // Add statistics to the sheet
    $statsSheet->setCellValue('A3', 'Total Feedback Submissions:');
    $statsSheet->setCellValue('B3', $stats['total_feedback']);

    $statsSheet->setCellValue('A4', 'Total Students:');
    $statsSheet->setCellValue('B4', $stats['total_students']);

    $statsSheet->setCellValue('A6', 'Average Ratings:');
    $statsSheet->getStyle('A6')->getFont()->setBold(true);

    $statsSheet->setCellValue('A7', 'Coverage & Relevance:');
    $statsSheet->setCellValue('B7', $stats['avg_coverage']);

    $statsSheet->setCellValue('A8', 'Quality & Clarity:');
    $statsSheet->setCellValue('B8', $stats['avg_quality']);

    $statsSheet->setCellValue('A9', 'Structure & Balance:');
    $statsSheet->setCellValue('B9', $stats['avg_structure']);

    $statsSheet->setCellValue('A10', 'Application & Innovation:');
    $statsSheet->setCellValue('B10', $stats['avg_application']);

    $statsSheet->setCellValue('A11', 'Overall Average:');
    $statsSheet->setCellValue('B11', $stats['overall_avg']);
    $statsSheet->getStyle('A11:B11')->getFont()->setBold(true);

    $statsSheet->setCellValue('A13', 'Comment Distribution:');
    $statsSheet->getStyle('A13')->getFont()->setBold(true);

    $statsSheet->setCellValue('A14', 'Syllabus Coverage Comments:');
    $statsSheet->setCellValue('B14', $stats['syllabus_comments']);

    $statsSheet->setCellValue('A15', 'Difficult Questions Comments:');
    $statsSheet->setCellValue('B15', $stats['difficult_comments']);

    $statsSheet->setCellValue('A16', 'Out of Syllabus Comments:');
    $statsSheet->setCellValue('B16', $stats['out_of_syllabus_comments']);

    $statsSheet->setCellValue('A17', 'Improvement Suggestions:');
    $statsSheet->setCellValue('B17', $stats['improvement_comments']);

    // Auto size columns
    foreach (range('A', 'C') as $column) {
        $statsSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Create Exam-wise Analysis Sheet if there are multiple exams
    if ($selected_exam == 0) {
        $examAnalysisSheet = $spreadsheet->createSheet();
        $examAnalysisSheet->setTitle('Exam Analysis');

        // Add headers for exam analysis
        $examHeaders = [
            'Exam Date',
            'Session',
            'Number of Responses',
            'Coverage & Relevance',
            'Quality & Clarity',
            'Structure & Balance',
            'Application & Innovation',
            'Overall Rating'
        ];

        $col = 'A';
        $row = 1;
        foreach ($examHeaders as $header) {
            $examAnalysisSheet->setCellValue($col . $row, $header);
            $col++;
        }

        $examAnalysisSheet->getStyle('A1:H1')->applyFromArray($headerStyle);

        // Get exam-wise aggregated statistics
        $examQuery = "SELECT 
            et.exam_date,
            et.exam_session,
            COUNT(DISTINCT ef.id) as response_count,
            ROUND(AVG(ef.coverage_relevance_avg), 2) as avg_coverage,
            ROUND(AVG(ef.quality_clarity_avg), 2) as avg_quality,
            ROUND(AVG(ef.structure_balance_avg), 2) as avg_structure,
            ROUND(AVG(ef.application_innovation_avg), 2) as avg_application,
            ROUND(AVG(ef.cumulative_avg), 2) as overall_avg
        FROM examination_feedback ef
        JOIN exam_timetable et ON ef.exam_timetable_id = et.id
        WHERE ef.subject_assignment_id = ?
        GROUP BY et.id
        ORDER BY et.exam_date";

        $examStmt = mysqli_prepare($conn, $examQuery);
        mysqli_stmt_bind_param($examStmt, "i", $assignment_id);
        mysqli_stmt_execute($examStmt);
        $examResult = mysqli_stmt_get_result($examStmt);

        // Add data to the exam analysis sheet
        $row = 2;
        while ($exam = mysqli_fetch_assoc($examResult)) {
            $examAnalysisSheet->setCellValue('A' . $row, date('d M Y', strtotime($exam['exam_date'])));
            $examAnalysisSheet->setCellValue('B' . $row, $exam['exam_session']);
            $examAnalysisSheet->setCellValue('C' . $row, $exam['response_count']);
            $examAnalysisSheet->setCellValue('D' . $row, $exam['avg_coverage']);
            $examAnalysisSheet->setCellValue('E' . $row, $exam['avg_quality']);
            $examAnalysisSheet->setCellValue('F' . $row, $exam['avg_structure']);
            $examAnalysisSheet->setCellValue('G' . $row, $exam['avg_application']);
            $examAnalysisSheet->setCellValue('H' . $row, $exam['overall_avg']);
            
            // Apply conditional formatting to ratings
            $rating = $exam['overall_avg'];
            if ($rating >= 4.5) {
                $examAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
            } else if ($rating >= 3.5) {
                $examAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
            } else {
                $examAnalysisSheet->getStyle('H' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
            }
            
            $row++;
        }

        // Auto size columns for exam analysis sheet
        foreach (range('A', 'H') as $column) {
            $examAnalysisSheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    // Create statement-wise analysis sheet
    $statementAnalysisSheet = $spreadsheet->createSheet();
    $statementAnalysisSheet->setTitle('Statement Analysis');

    // Add headers for statement analysis
    $statementHeaders = [
        'Category',
        'Statement',
        'Average Rating'
    ];

    $col = 'A';
    $row = 1;
    foreach ($statementHeaders as $header) {
        $statementAnalysisSheet->setCellValue($col . $row, $header);
        $col++;
    }

    $statementAnalysisSheet->getStyle('A1:C1')->applyFromArray($headerStyle);

    // Get statement-wise aggregated statistics
    $statementQuery = "SELECT 
        efs.section,
        efs.statement,
        ROUND(AVG(efr.rating), 2) as avg_rating
    FROM examination_feedback ef
    JOIN examination_feedback_ratings efr ON ef.id = efr.feedback_id
    JOIN examination_feedback_statements efs ON efr.statement_id = efs.id
    $where_clause
    GROUP BY efs.id
    ORDER BY efs.section, efs.id";

    $statementStmt = mysqli_prepare($conn, $statementQuery);
    mysqli_stmt_bind_param($statementStmt, $types, ...$params);
    mysqli_stmt_execute($statementStmt);
    $statementResult = mysqli_stmt_get_result($statementStmt);

    // Add data to the statement analysis sheet
    $row = 2;
    while ($statement = mysqli_fetch_assoc($statementResult)) {
        $categoryName = '';
        switch ($statement['section']) {
            case 'COVERAGE_RELEVANCE':
                $categoryName = 'Coverage & Relevance';
                break;
            case 'QUALITY_CLARITY':
                $categoryName = 'Quality & Clarity';
                break;
            case 'STRUCTURE_BALANCE':
                $categoryName = 'Structure & Balance';
                break;
            case 'APPLICATION_INNOVATION':
                $categoryName = 'Application & Innovation';
                break;
        }
        
        $statementAnalysisSheet->setCellValue('A' . $row, $categoryName);
        $statementAnalysisSheet->setCellValue('B' . $row, $statement['statement']);
        $statementAnalysisSheet->setCellValue('C' . $row, $statement['avg_rating']);
        
        // Apply conditional formatting to ratings
        $rating = $statement['avg_rating'];
        if ($rating >= 4.5) {
            $statementAnalysisSheet->getStyle('C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('ABEBC6')); // Light Green
        } else if ($rating >= 3.5) {
            $statementAnalysisSheet->getStyle('C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F9E79F')); // Light Yellow
        } else {
            $statementAnalysisSheet->getStyle('C' . $row)->getFill()->setFillType(Fill::FILL_SOLID)->setStartColor(new Color('F5B7B1')); // Light Red
        }
        
        $row++;
    }

    // Auto size columns for statement analysis sheet
    foreach (range('A', 'C') as $column) {
        $statementAnalysisSheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Set the first sheet as active
    $spreadsheet->setActiveSheetIndex(0);

    // Generate filename
    $filename = 'exam_feedback_report_' . 
                 preg_replace('/[^A-Za-z0-9]/', '', $assignment['subject_code']) . 
                '_sem' . $assignment['semester'] . 
                '_section' . $assignment['section'] . 
                '_' . date('Y-m-d') . '.xlsx';

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
    exit;

} catch (Exception $e) {
    // Log the error
    error_log("Excel generation exception: " . $e->getMessage());
    
    // Redirect back with error
    header('Location: faculty_examination_feedback_details.php?assignment_id=' . $assignment_id . '&error=report_generation_failed');
    exit();
}
?> 