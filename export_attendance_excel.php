<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';
require_once 'vendor/autoload.php'; // Autoload PHPSpreadsheet

// Check if faculty is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'faculty' && $_SESSION['role'] !== 'admin')) {
    header('Location: faculty_login.php');
    exit();
}

// Check required parameters
if (!isset($_GET['batch_id'])) {
    die("Error: Missing required batch_id parameter");
}

$batch_id = intval($_GET['batch_id']);
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$faculty_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'];
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$session_topic = isset($_GET['topic']) ? $_GET['topic'] : '';

// Import PHPSpreadsheet classes
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Color;

// Get batch details
$batch_query = "SELECT 
                    tb.batch_name,
                    tb.description,
                    d.name AS department_name,
                    ay.year_range AS academic_year
                FROM 
                    training_batches tb
                JOIN 
                    academic_years ay ON tb.academic_year_id = ay.id
                JOIN 
                    departments d ON tb.department_id = d.id
                WHERE 
                    tb.id = ? AND tb.department_id = ?";

$stmt = mysqli_prepare($conn, $batch_query);
mysqli_stmt_bind_param($stmt, "ii", $batch_id, $department_id);
mysqli_stmt_execute($stmt);
$batch_result = mysqli_stmt_get_result($stmt);
$batch_details = mysqli_fetch_assoc($batch_result);

if (!$batch_details) {
    die("Error: Invalid batch selected or you don't have permission to view this batch.");
}

// Create new spreadsheet
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($_SESSION['name'])
    ->setLastModifiedBy($_SESSION['name'])
    ->setTitle('Attendance Report')
    ->setSubject('Training Sessions Attendance')
    ->setDescription('Attendance report for training sessions from ' . $date_from . ' to ' . $date_to);

// Remove default worksheet
$spreadsheet->removeSheetByIndex(0);

// Define common styles
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => '000000'],
        'size' => 12,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'E0E5EC'],
    ],
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

$titleStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => '000000'],
        'size' => 14,
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];

$infoLabelStyle = [
    'font' => [
        'bold' => true,
    ],
];

$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
];

$dateHeaderStyle = [
    'font' => [
        'bold' => true,
        'size' => 11,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F0F0F0'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
];

$statusStyles = [
    'present' => [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '92D050'], // Green
        ],
    ],
    'absent' => [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FF9999'], // Red
        ],
    ],
    'late' => [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'FFCC99'], // Orange
        ],
    ],
    'excused' => [
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '99CCFF'], // Light Blue
        ],
    ],
];

// If specific session ID is provided, get only that session's topic
if ($session_id > 0) {
    $topic_query = "SELECT topic FROM training_session_schedule WHERE id = ?";
    $stmt = mysqli_prepare($conn, $topic_query);
    mysqli_stmt_bind_param($stmt, "i", $session_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $session_topic = $row['topic'];
}

// Query to get all topics in the date range
$topics_query = "SELECT DISTINCT topic 
                FROM training_session_schedule 
                WHERE training_batch_id = ? 
                AND session_date BETWEEN ? AND ?
                AND is_cancelled = FALSE";

if (!empty($session_topic)) {
    $topics_query .= " AND topic = ?";
    $stmt = mysqli_prepare($conn, $topics_query);
    mysqli_stmt_bind_param($stmt, "isss", $batch_id, $date_from, $date_to, $session_topic);
} else {
    $stmt = mysqli_prepare($conn, $topics_query);
    mysqli_stmt_bind_param($stmt, "iss", $batch_id, $date_from, $date_to);
}

mysqli_stmt_execute($stmt);
$topics_result = mysqli_stmt_get_result($stmt);

$topics = [];
while ($row = mysqli_fetch_assoc($topics_result)) {
    $topics[] = $row['topic'];
}

// Get all students in the batch
$students_query = "SELECT 
                    s.id AS student_id,
                    s.roll_number,
                    s.register_number,
                    s.name AS student_name
                  FROM 
                    students s
                  JOIN 
                    student_training_batch stb ON s.id = stb.student_id
                  WHERE 
                    stb.training_batch_id = ? AND stb.is_active = TRUE
                  ORDER BY 
                    s.roll_number";

$stmt = mysqli_prepare($conn, $students_query);
mysqli_stmt_bind_param($stmt, "i", $batch_id);
mysqli_stmt_execute($stmt);
$students_result = mysqli_stmt_get_result($stmt);

$all_students = [];
while ($student = mysqli_fetch_assoc($students_result)) {
    $all_students[] = $student;
}

// Create summary worksheet
$summary = new Worksheet($spreadsheet, 'Summary');
$spreadsheet->addSheet($summary, 0);
$spreadsheet->setActiveSheetIndex(0);

// Add title to summary
$summary->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
$summary->mergeCells('A1:F1');
$summary->getStyle('A1')->applyFromArray($titleStyle);

// Add batch info to summary
$summary->setCellValue('A3', 'ATTENDANCE SUMMARY REPORT');
$summary->mergeCells('A3:F3');
$summary->getStyle('A3')->applyFromArray($titleStyle);

$summary->setCellValue('A4', 'Department:');
$summary->setCellValue('B4', $batch_details['department_name']);
$summary->getStyle('A4')->applyFromArray($infoLabelStyle);

$summary->setCellValue('A5', 'Batch:');
$summary->setCellValue('B5', $batch_details['batch_name']);
$summary->getStyle('A5')->applyFromArray($infoLabelStyle);

$summary->setCellValue('A6', 'Period:');
$summary->setCellValue('B6', date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to)));
$summary->getStyle('A6')->applyFromArray($infoLabelStyle);

// Add topics list to summary
$summary->setCellValue('A8', 'Topics');
$summary->getStyle('A8')->applyFromArray($headerStyle);
$summary->setCellValue('B8', 'Sessions Count');
$summary->getStyle('B8')->applyFromArray($headerStyle);
$summary->setCellValue('C8', 'Average Attendance');
$summary->getStyle('C8')->applyFromArray($headerStyle);
$summary->setCellValue('D8', 'Go to Sheet');
$summary->getStyle('D8')->applyFromArray($headerStyle);

$summary_row = 9;
$worksheet_index = 1; // Start at 1 since summary is at 0

// Process each topic
foreach ($topics as $topic) {
    // Get all sessions for this topic
    $sessions_query = "SELECT 
                        tss.id,
                        tss.session_date,
                        tss.start_time,
                        tss.end_time,
                        tss.topic,
                        tss.trainer_name,
                        v.name AS venue_name,
                        v.room_number
                      FROM 
                        training_session_schedule tss
                      JOIN 
                        venues v ON tss.venue_id = v.id
                      WHERE 
                        tss.training_batch_id = ? 
                        AND tss.topic = ?
                        AND tss.session_date BETWEEN ? AND ?
                        AND tss.is_cancelled = FALSE
                      ORDER BY 
                        tss.session_date";
    
    $stmt = mysqli_prepare($conn, $sessions_query);
    mysqli_stmt_bind_param($stmt, "isss", $batch_id, $topic, $date_from, $date_to);
    mysqli_stmt_execute($stmt);
    $sessions_result = mysqli_stmt_get_result($stmt);
    
    $sessions = [];
    while ($session = mysqli_fetch_assoc($sessions_result)) {
        $sessions[] = $session;
    }
    
    if (count($sessions) == 0) {
        continue;
    }
    
    // Update summary row
    $topic_avg_attendance = 0;
    
    // Create worksheet for this topic
    $worksheet = new Worksheet($spreadsheet, substr($topic, 0, 30)); // Max 31 chars for sheet name
    $spreadsheet->addSheet($worksheet, $worksheet_index);
    
    // Add title and batch info
    $worksheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
    $worksheet->mergeCells('A1:' . chr(67 + count($sessions)) . '1'); // Merge cells across all sessions + 3 starting columns
    $worksheet->getStyle('A1')->applyFromArray($titleStyle);
    
    $worksheet->setCellValue('A3', 'Topic:');
    $worksheet->setCellValue('B3', $topic);
    $worksheet->getStyle('A3')->applyFromArray($infoLabelStyle);
    
    $worksheet->setCellValue('A4', 'Department:');
    $worksheet->setCellValue('B4', $batch_details['department_name']);
    $worksheet->getStyle('A4')->applyFromArray($infoLabelStyle);
    
    $worksheet->setCellValue('A5', 'Batch:');
    $worksheet->setCellValue('B5', $batch_details['batch_name']);
    $worksheet->getStyle('A5')->applyFromArray($infoLabelStyle);
    
    $worksheet->setCellValue('A6', 'Period:');
    $worksheet->setCellValue('B6', date('d-m-Y', strtotime($date_from)) . ' to ' . date('d-m-Y', strtotime($date_to)));
    $worksheet->getStyle('A6')->applyFromArray($infoLabelStyle);
    
    // Set up column widths - student info columns
    $worksheet->getColumnDimension('A')->setWidth(15); // Roll number
    $worksheet->getColumnDimension('B')->setWidth(30); // Name
    $worksheet->getColumnDimension('C')->setWidth(15); // Register number
    
    // Create table header - student info columns
    $worksheet->setCellValue('A8', 'Roll Number');
    $worksheet->setCellValue('B8', 'Student Name');
    $worksheet->setCellValue('C8', 'Register Number');
    
    // Add session date columns
    $col_index = 3; // Starting column D (index 3)
    $session_totals = []; // To track attendance totals for each session
    
    foreach ($sessions as $index => $session) {
        $col_letter = chr(65 + $col_index); // Convert to letter (D, E, F, etc.)
        
        // Format the date for display
        $display_date = date('d-m-Y', strtotime($session['session_date']));
        
        // Set column header with date
        $worksheet->setCellValue($col_letter . '8', $display_date);
        
        // Set column width
        $worksheet->getColumnDimension($col_letter)->setWidth(15);
        
        // Track which column is for which session ID
        $session['column'] = $col_letter;
        $sessions[$index] = $session;
        
        // Initialize session totals
        $session_totals[$col_letter] = [
            'total' => 0,
            'present' => 0,
            'absent' => 0,
            'late' => 0,
            'excused' => 0,
            'attendance_rate' => 0
        ];
        
        $col_index++;
    }
    
    // Apply header styles
    $max_col = chr(65 + $col_index - 1);
    $worksheet->getStyle('A8:' . $max_col . '8')->applyFromArray($headerStyle);
    
    // Fill in student attendance data
    $row = 9;
    foreach ($all_students as $student) {
        $worksheet->setCellValue('A' . $row, $student['roll_number']);
        $worksheet->setCellValue('B' . $row, $student['student_name']);
        $worksheet->setCellValue('C' . $row, $student['register_number']);
        
        // Get student's attendance for each session
        foreach ($sessions as $session) {
            $session_id = $session['id'];
            $col_letter = $session['column'];
            
            // Query this student's attendance for this session
            $attendance_query = "SELECT status FROM training_attendance_records 
                                WHERE student_id = ? AND session_id = ?";
            $stmt = mysqli_prepare($conn, $attendance_query);
            mysqli_stmt_bind_param($stmt, "ii", $student['student_id'], $session_id);
            mysqli_stmt_execute($stmt);
            $attendance_result = mysqli_stmt_get_result($stmt);
            $attendance = mysqli_fetch_assoc($attendance_result);
            
            // Default to absent if no record found
            $status = ($attendance && $attendance['status']) ? $attendance['status'] : 'absent';
            
            // Update session totals
            $session_totals[$col_letter]['total']++;
            $session_totals[$col_letter][$status]++;
            
            // Set status cell value
            $status_display = ucfirst($status);
            $worksheet->setCellValue($col_letter . $row, $status_display);
            
            // Apply status-specific styling
            if (isset($statusStyles[$status])) {
                $worksheet->getStyle($col_letter . $row)->applyFromArray($statusStyles[$status]);
            }
        }
        
        $row++;
    }
    
    // Add a summary row showing attendance percentages for each session
    $worksheet->setCellValue('A' . ($row + 1), 'Attendance Rate:');
    $worksheet->getStyle('A' . ($row + 1))->applyFromArray($infoLabelStyle);
    $worksheet->mergeCells('A' . ($row + 1) . ':C' . ($row + 1));
    
    $total_attendance_sum = 0;
    $session_count = 0;
    
    foreach ($sessions as $session) {
        $col_letter = $session['column'];
        $total = $session_totals[$col_letter]['total'];
        $present = $session_totals[$col_letter]['present'];
        $excused = $session_totals[$col_letter]['excused'];
        $late = $session_totals[$col_letter]['late'];
        
        // Calculate attendance rate (present + excused + late) / total
        $attendance_rate = ($total > 0) ? 
            round((($present + $excused + $late) / $total) * 100, 1) : 0;
        
        $session_totals[$col_letter]['attendance_rate'] = $attendance_rate;
        
        // Add to the sum for average calculation
        $total_attendance_sum += $attendance_rate;
        $session_count++;
        
        // Display attendance rate percentage for this session
        $worksheet->setCellValue($col_letter . ($row + 1), $attendance_rate . '%');
    }
    
    // Calculate average attendance across all sessions
    $topic_avg_attendance = ($session_count > 0) ? 
        round(($total_attendance_sum / $session_count), 1) : 0;
    
    // Add legend
    $legend_row = $row + 3;
    $worksheet->setCellValue('A' . $legend_row, 'Legend:');
    $worksheet->getStyle('A' . $legend_row)->applyFromArray($infoLabelStyle);
    
    $worksheet->setCellValue('B' . $legend_row, 'Present');
    $worksheet->getStyle('B' . $legend_row)->applyFromArray($statusStyles['present']);
    
    $worksheet->setCellValue('C' . $legend_row, 'Absent');
    $worksheet->getStyle('C' . $legend_row)->applyFromArray($statusStyles['absent']);
    
    $worksheet->setCellValue('D' . $legend_row, 'Late');
    $worksheet->getStyle('D' . $legend_row)->applyFromArray($statusStyles['late']);
    
    $worksheet->setCellValue('E' . $legend_row, 'Excused');
    $worksheet->getStyle('E' . $legend_row)->applyFromArray($statusStyles['excused']);
    
    // Apply border styling to all data
    $worksheet->getStyle('A8:' . $max_col . ($row - 1))->applyFromArray($dataStyle);
    
    // Update summary worksheet
    $summary->setCellValue('A' . $summary_row, $topic);
    $summary->setCellValue('B' . $summary_row, count($sessions));
    $summary->setCellValue('C' . $summary_row, $topic_avg_attendance . '%');
    $summary->setCellValue('D' . $summary_row, 'View Details');
    
    // Add hyperlink to topic sheet
    $summary->getCell('D' . $summary_row)->getHyperlink()
        ->setUrl("sheet://'" . substr($topic, 0, 30) . "'");
    $summary->getStyle('D' . $summary_row)->getFont()->setColor(new Color(Color::COLOR_BLUE));
    $summary->getStyle('D' . $summary_row)->getFont()->setUnderline(true);
    
    // Apply border to summary row
    $summary->getStyle('A' . $summary_row . ':D' . $summary_row)->applyFromArray($dataStyle);
    
    $summary_row++;
    $worksheet_index++;
}

// Apply final styling to summary
$summary->getColumnDimension('A')->setWidth(30);
$summary->getColumnDimension('B')->setWidth(15);
$summary->getColumnDimension('C')->setWidth(20);
$summary->getColumnDimension('D')->setWidth(15);

// Set active sheet to the summary
$spreadsheet->setActiveSheetIndex(0);

// Generate filename
$filename = str_replace(' ', '_', $batch_details['batch_name']);
if (!empty($session_topic)) {
    $filename .= '_' . str_replace(' ', '_', $session_topic);
}
$filename .= '_' . date('Y-m-d', strtotime($date_from)) . '_to_' . date('Y-m-d', strtotime($date_to)) . '.xlsx';

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Output to browser
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit(); 