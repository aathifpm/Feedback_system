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

// If batch_id is not provided, calculate it
if (!isset($_GET['batch_id']) || empty($_GET['batch_id'])) {
    try {
        // Get the academic year details
        $year_query = "SELECT year_range FROM academic_years WHERE id = :academic_year_id";
        $year_stmt = $pdo->prepare($year_query);
        $year_stmt->bindParam(':academic_year_id', $academic_year, PDO::PARAM_INT);
        $year_stmt->execute();
        $academic_year_data = $year_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($academic_year_data) {
            // Extract the start year from year_range (e.g., "2023-24" -> 2023)
            $academic_start_year = intval(substr($academic_year_data['year_range'], 0, 4));
            
            // Calculate the admission year for the batch we're looking for
            // If we're looking for 2nd year students in 2023-24, their admission year would be 2022
            $admission_year = $academic_start_year - $year + 1;
            
            // Get the batch details
            $batch_query = "SELECT id FROM batch_years WHERE admission_year = :admission_year";
            $batch_stmt = $pdo->prepare($batch_query);
            $batch_stmt->bindParam(':admission_year', $admission_year, PDO::PARAM_INT);
            $batch_stmt->execute();
            $batch_data = $batch_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($batch_data) {
                $batch_id = $batch_data['id'];
            } else {
                die("No matching batch found for academic year {$academic_year_data['year_range']} and year of study $year.");
            }
        } else {
            die("Invalid academic year ID.");
        }
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        die("A database error occurred. Please try again later.");
    }
} else {
    $batch_id = intval($_GET['batch_id']);
}

// Validate required parameters
if (!$academic_year || !$year || !isset($semester) || empty($section) || !$batch_id) {
    die("Required parameters missing");
}

// Get academic year details
try {
    $year_query = "SELECT year_range FROM academic_years WHERE id = :academic_year_id";
    $year_stmt = $pdo->prepare($year_query);
    $year_stmt->bindParam(':academic_year_id', $academic_year, PDO::PARAM_INT);
    $year_stmt->execute();
    $academic_year_data = $year_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$academic_year_data) {
        die("Invalid academic year ID.");
    }
} catch (PDOException $e) {
    error_log("Error fetching academic year details: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// Get batch year information
try {
    $batch_query = "SELECT batch_name FROM batch_years WHERE id = :batch_id";
    $batch_stmt = $pdo->prepare($batch_query);
    $batch_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $batch_stmt->execute();
    $batch_data = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    $batch_name = isset($batch_data['batch_name']) ? $batch_data['batch_name'] : 'N/A';
} catch (PDOException $e) {
    error_log("Error fetching batch information: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

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

// Function to add common headers to worksheets
function addCommonHeaders($sheet, $academic_year_data, $year, $section, $semester, $batch_name, $department, $sheetTitle, $mergeColumns = 'G') {
    $yearInRoman = array(1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV')[$year];
    
    // College Title
    $sheet->mergeCells('A1:' . $mergeColumns . '1');
    $sheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // College Subtitle
    $sheet->mergeCells('A2:' . $mergeColumns . '2');
    $sheet->setCellValue('A2', 'An Autonomous Institution, Affiliated to Anna University');
    $sheet->getStyle('A2')->getFont()->setBold(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Report Title
    $title = $yearInRoman . " Year - Section " . $section;
    if ($semester > 0) {
        $title .= " Semester " . $semester;
    } else {
        $title .= " ({$academic_year_data['year_range']})";
    }
    $title .= " - " . $sheetTitle;
    
    $sheet->mergeCells('A3:' . $mergeColumns . '3');
    $sheet->setCellValue('A3', $title);
    $sheet->getStyle('A3')->getFont()->setBold(true);
    $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Basic info in row 4
    $sheet->setCellValue('A4', 'Academic Year: ' . $academic_year_data['year_range'] . ' | Batch: ' . $batch_name . ' | Department: ' . $department['name']);
    $sheet->mergeCells('A4:' . $mergeColumns . '4');
    $sheet->getStyle('A4')->getFont()->setBold(true);
    $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    // Add some spacing
    $sheet->getRowDimension(5)->setRowHeight(10);
    
    return 6; // Return the row number where data should start
}

// Get department name
try {
    $dept_query = "SELECT name FROM departments WHERE id = :department_id";
    $dept_stmt = $pdo->prepare($dept_query);
    $dept_stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
    $dept_stmt->execute();
    $department = $dept_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching department name: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// Get section overview first so we have data for the overview sheet
try {
    $overview_query = "SELECT 
        COUNT(DISTINCT sa.id) as total_subjects,
        COUNT(DISTINCT f.id) as total_feedback,
        (SELECT COUNT(*) FROM students st 
         WHERE st.batch_id = :batch_id_1
         AND st.section = :section_1
         AND st.department_id = :department_id_1) as total_students,
        COUNT(DISTINCT st.id) as participated_students,
        ROUND(AVG(f.cumulative_avg) * 2, 2) as overall_rating
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    LEFT JOIN feedback f ON sa.id = f.assignment_id
    LEFT JOIN students st ON f.student_id = st.id AND st.batch_id = :batch_id_2
    WHERE sa.academic_year_id = :academic_year
    AND sa.year = :year"
    . ($semester > 0 ? " AND sa.semester = :semester" : "") . "
    AND sa.section = :section_2
    AND s.department_id = :department_id_2";

    $overview_stmt = $pdo->prepare($overview_query);
    
    $overview_stmt->bindParam(':batch_id_1', $batch_id, PDO::PARAM_INT);
    $overview_stmt->bindParam(':section_1', $section, PDO::PARAM_STR);
    $overview_stmt->bindParam(':department_id_1', $department_id, PDO::PARAM_INT);
    $overview_stmt->bindParam(':batch_id_2', $batch_id, PDO::PARAM_INT);
    $overview_stmt->bindParam(':academic_year', $academic_year, PDO::PARAM_INT);
    $overview_stmt->bindParam(':year', $year, PDO::PARAM_INT);
    if ($semester > 0) {
        $overview_stmt->bindParam(':semester', $semester, PDO::PARAM_INT);
    }
    $overview_stmt->bindParam(':section_2', $section, PDO::PARAM_STR);
    $overview_stmt->bindParam(':department_id_2', $department_id, PDO::PARAM_INT);
    
    $overview_stmt->execute();
    $overview = $overview_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching section overview: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

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

$sheet->setCellValue('A11', 'Total Students in Section:');
$sheet->setCellValue('B11', $overview['total_students']);
$sheet->getStyle('A11')->getFont()->setBold(true);

$sheet->setCellValue('A12', 'Students Participated:');
$sheet->setCellValue('B12', $overview['participated_students']);
$sheet->getStyle('A12')->getFont()->setBold(true);

$participation_percentage = ($overview['total_students'] > 0) ? 
    round(($overview['participated_students'] / $overview['total_students']) * 100, 2) : 0;
$sheet->setCellValue('A13', 'Participation Rate:');
$sheet->setCellValue('B13', $participation_percentage . '%');
$sheet->getStyle('A13')->getFont()->setBold(true);

$sheet->setCellValue('A14', 'Total Feedback:');
$sheet->setCellValue('B14', $overview['total_feedback']);
$sheet->getStyle('A14')->getFont()->setBold(true);

$sheet->setCellValue('A15', 'Overall Rating:');
$sheet->setCellValue('B15', $overview['overall_rating']);
$sheet->getStyle('A15')->getFont()->setBold(true);

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

// Add common headers
$startRow = addCommonHeaders($subjectSheet, $academic_year_data, $year, $section, $semester, $batch_name, $department, 'Subject Analysis', 'G');

// Fetch subject-wise feedback
try {
    $subject_query = "SELECT 
        s.code,
        s.name as subject_name,
        f.name as faculty_name,
        sa.semester,
        COUNT(DISTINCT fb.id) as feedback_count,
        ROUND(AVG(fb.cumulative_avg) * 2, 2) as overall_rating
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    JOIN faculty f ON sa.faculty_id = f.id
    LEFT JOIN feedback fb ON sa.id = fb.assignment_id
    LEFT JOIN students st ON fb.student_id = st.id AND st.batch_id = :batch_id
    WHERE sa.academic_year_id = :academic_year
    AND sa.year = :year"
    . ($semester > 0 ? " AND sa.semester = :semester" : "") . "
    AND sa.section = :section
    AND s.department_id = :department_id
    GROUP BY s.id, f.id, sa.semester
    ORDER BY sa.semester, s.code";

    $subject_stmt = $pdo->prepare($subject_query);
    $subject_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $subject_stmt->bindParam(':academic_year', $academic_year, PDO::PARAM_INT);
    $subject_stmt->bindParam(':year', $year, PDO::PARAM_INT);
    if ($semester > 0) {
        $subject_stmt->bindParam(':semester', $semester, PDO::PARAM_INT);
    }
    $subject_stmt->bindParam(':section', $section, PDO::PARAM_STR);
    $subject_stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
    $subject_stmt->execute();
    $subject_result = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching subject analysis: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

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
    $subjectSheet->setCellValue($col . $startRow, $headers[$i]);
    $subjectSheet->getStyle($col . $startRow)->getFont()->setBold(true);
    $subjectSheet->getStyle($col . $startRow)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
}

// Auto-size columns
for ($i = 0; $i < count($headers); $i++) {
    $subjectSheet->getColumnDimension(chr(65 + $i))->setAutoSize(true);
}

$row = $startRow + 1;
foreach ($subject_result as $subject) {
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

// Add common headers (using more columns for student participation)
$startRow = addCommonHeaders($studentSheet, $academic_year_data, $year, $section, $semester, $batch_name, $department, 'Student Participation Report', 'J');

// First, get all subjects for the section to create columns
try {
    $subjects_query = "SELECT 
        DISTINCT s.id,
        s.code,
        s.name as subject_name,
        f.name as faculty_name
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    JOIN faculty f ON sa.faculty_id = f.id
    WHERE sa.academic_year_id = :academic_year
    AND sa.year = :year"
    . ($semester > 0 ? " AND sa.semester = :semester" : "") . "
    AND sa.section = :section
    AND s.department_id = :department_id
    ORDER BY s.code";

    $subjects_stmt = $pdo->prepare($subjects_query);
    $subjects_stmt->bindParam(':academic_year', $academic_year, PDO::PARAM_INT);
    $subjects_stmt->bindParam(':year', $year, PDO::PARAM_INT);
    if ($semester > 0) {
        $subjects_stmt->bindParam(':semester', $semester, PDO::PARAM_INT);
    }
    $subjects_stmt->bindParam(':section', $section, PDO::PARAM_STR);
    $subjects_stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
    $subjects_stmt->execute();
    $subjects_result = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching subjects for section: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// Store subjects and create headers
$subjects = [];
$headers = ['Roll Number', 'Student Name'];
$col_index = 2; // Starting after Roll Number and Name
$subject_columns = []; // To store subject column mapping

 foreach ($subjects_result as $subject) {
    $subjects[] = $subject;
    $col_letter = chr(65 + $col_index); // Convert to Excel column letter
    $subject_columns[$subject['id']] = $col_letter;
    $headers[] = $subject['code'] . "\n" . $subject['subject_name'] . "\n(" . $subject['faculty_name'] . ")";
    $col_index++;
}

// Add summary columns at the end
$headers[] = 'Total Subjects';
$headers[] = 'Feedback Submitted';
$headers[] = 'Completion Rate';
$headers[] = 'Average Rating';

// Write headers
foreach (array_keys($headers) as $i) {
    $col = chr(65 + $i);
    $studentSheet->setCellValue($col . $startRow, $headers[$i]);
    $studentSheet->getStyle($col . $startRow)->getFont()->setBold(true);
    $studentSheet->getStyle($col . $startRow)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
    $studentSheet->getStyle($col . $startRow)->getAlignment()
        ->setWrapText(true)
        ->setVertical(Alignment::VERTICAL_CENTER)
        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Set row height for header
$studentSheet->getRowDimension($startRow)->setRowHeight(60);

// Fetch student data with individual subject ratings
try {
    $student_query = "SELECT 
        st.id,
        st.roll_number,
        st.name as student_name,
        GROUP_CONCAT(
            CONCAT(sa.subject_id, ':', IFNULL(ROUND(f.cumulative_avg * 2, 2), 'NA'))
            ORDER BY s.code
            SEPARATOR ';'
        ) as subject_ratings,
        COUNT(DISTINCT sa.id) as total_subjects,
        COUNT(DISTINCT f.id) as submitted_feedback,
        ROUND(AVG(f.cumulative_avg) * 2, 2) as avg_rating
    FROM students st
    JOIN subject_assignments sa ON sa.year = :year AND sa.section = :section_1 AND sa.academic_year_id = :academic_year"
    . ($semester > 0 ? " AND sa.semester = :semester" : "") . "
    JOIN subjects s ON sa.subject_id = s.id AND s.department_id = :department_id_1
    LEFT JOIN feedback f ON f.assignment_id = sa.id AND f.student_id = st.id
    WHERE st.section = :section_2
    AND st.department_id = :department_id_2
    AND st.batch_id = :batch_id
    GROUP BY st.id
    ORDER BY st.roll_number";

    $student_stmt = $pdo->prepare($student_query);
    $student_stmt->bindParam(':year', $year, PDO::PARAM_INT);
    $student_stmt->bindParam(':section_1', $section, PDO::PARAM_STR);
    $student_stmt->bindParam(':academic_year', $academic_year, PDO::PARAM_INT);
    if ($semester > 0) {
        $student_stmt->bindParam(':semester', $semester, PDO::PARAM_INT);
    }
    $student_stmt->bindParam(':department_id_1', $department_id, PDO::PARAM_INT);
    $student_stmt->bindParam(':section_2', $section, PDO::PARAM_STR);
    $student_stmt->bindParam(':department_id_2', $department_id, PDO::PARAM_INT);
    $student_stmt->bindParam(':batch_id', $batch_id, PDO::PARAM_INT);
    $student_stmt->execute();
    $student_result = $student_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching student participation data: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

$row = $startRow + 1;
foreach ($student_result as $student) {
    // Basic student info
    $studentSheet->setCellValue('A'.$row, $student['roll_number']);
    $studentSheet->setCellValue('B'.$row, $student['student_name']);
    
    // Initialize all subject columns as 'NA'
    foreach ($subjects as $subject) {
        $col = $subject_columns[$subject['id']];
        $studentSheet->setCellValue($col.$row, 'NA');
    }
    
    // Fill in the actual ratings
    if (!empty($student['subject_ratings'])) {
        $ratings = explode(';', $student['subject_ratings']);
        foreach ($ratings as $rating) {
            list($subject_id, $rating_value) = explode(':', $rating);
            if (isset($subject_columns[$subject_id])) {
                $col = $subject_columns[$subject_id];
                $studentSheet->setCellValue($col.$row, $rating_value);
                
                // Add conditional formatting for ratings
                if ($rating_value !== 'NA') {
                    $color = getRatingColor($rating_value);
                    $studentSheet->getStyle($col.$row)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->setStartColor(new Color($color));
                }
            }
        }
    }
    
    // Calculate completion rate
    $completion_rate = ($student['total_subjects'] > 0) ? 
        round(($student['submitted_feedback'] / $student['total_subjects']) * 100, 1) : 0;
    
    // Add summary columns
    $last_col_index = count($headers) - 1;
    $studentSheet->setCellValue(chr(65 + $last_col_index - 3).$row, $student['total_subjects']);
    $studentSheet->setCellValue(chr(65 + $last_col_index - 2).$row, $student['submitted_feedback']);
    $studentSheet->setCellValue(chr(65 + $last_col_index - 1).$row, $completion_rate . '%');
    $studentSheet->setCellValue(chr(65 + $last_col_index).$row, $student['avg_rating']);
    
    $row++;
}

// Auto-size columns and add borders
foreach (range('A', chr(65 + count($headers) - 1)) as $col) {
    $studentSheet->getColumnDimension($col)->setAutoSize(true);
    $studentSheet->getStyle($col.$startRow.':'.$col.($row-1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
}

// Freeze panes for better navigation (adjust for headers)
$studentSheet->freezePane('C' . ($startRow + 1));

// =====================
// COMMENTS SHEET
// =====================
$commentsSheet = $spreadsheet->createSheet();
$commentsSheet->setTitle('Comments');

// Add common headers
$startRow = addCommonHeaders($commentsSheet, $academic_year_data, $year, $section, $semester, $batch_name, $department, 'Feedback Comments', 'E');

// Fetch notable comments
try {
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
    WHERE sa.academic_year_id = :academic_year
    AND sa.year = :year"
    . ($semester > 0 ? " AND sa.semester = :semester" : "") . "
    AND sa.section = :section
    AND s.department_id = :department_id
    AND fb.comments IS NOT NULL 
    AND fb.comments != ''
    ORDER BY 
        (sentiment_score + importance_score + length_score) DESC, 
        fb.submitted_at DESC";

    $comments_stmt = $pdo->prepare($comments_query);
    $comments_stmt->bindParam(':academic_year', $academic_year, PDO::PARAM_INT);
    $comments_stmt->bindParam(':year', $year, PDO::PARAM_INT);
    if ($semester > 0) {
        $comments_stmt->bindParam(':semester', $semester, PDO::PARAM_INT);
    }
    $comments_stmt->bindParam(':section', $section, PDO::PARAM_STR);
    $comments_stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
    $comments_stmt->execute();
    $comments_result = $comments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching notable comments: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// Add headers
$headers = ['Subject Code', 'Subject Name', 'Faculty', 'Comments', 'Date'];

// Write headers
foreach (array_keys($headers) as $i) {
    $col = chr(65 + $i); // A, B, C, etc.
    $commentsSheet->setCellValue($col . $startRow, $headers[$i]);
    $commentsSheet->getStyle($col . $startRow)->getFont()->setBold(true);
    $commentsSheet->getStyle($col . $startRow)->getFill()
        ->setFillType(Fill::FILL_SOLID)
        ->setStartColor(new Color('DDDDDD'));
}

// Set column widths
$commentsSheet->getColumnDimension('A')->setWidth(15);  // Subject Code
$commentsSheet->getColumnDimension('B')->setWidth(30);  // Subject Name
$commentsSheet->getColumnDimension('C')->setWidth(25);  // Faculty
$commentsSheet->getColumnDimension('D')->setWidth(50);  // Comments
$commentsSheet->getColumnDimension('E')->setWidth(15);  // Date

$row = $startRow + 1;
$commentCount = 0;
foreach ($comments_result as $comment) {
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
    $commentsSheet->mergeCells('A'.$row.':E'.$row);
    $commentsSheet->setCellValue('A'.$row, 'No feedback comments found for this section.');
    $commentsSheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
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