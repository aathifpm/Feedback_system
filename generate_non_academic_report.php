<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in with proper role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'faculty', 'hod'])) {
    header('Location: index.php');
    exit();
}

// Get parameters and set access controls
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get filter parameters
$academic_year_id = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Validate access based on role
$has_access = false;
$department_filter = '';

switch ($role) {
    case 'admin':
        $has_access = true;
        break;
        
    case 'hod':
        // HODs can only access their department's data
        $stmt = $pdo->prepare("SELECT department_id FROM hods WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$user_id]);
        $hod_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hod_result) {
            $has_access = true;
            $department_id = $department_id ?: $hod_result['department_id'];
        }
        break;
        
    case 'faculty':
        // Faculty can only access their department's data
        $stmt = $pdo->prepare("SELECT id, department_id FROM faculty WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$user_id]);
        $faculty_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($faculty_result) {
            $has_access = true;
            $department_id = $faculty_result['department_id'];
        }
        break;
}

if (!$has_access) {
    die("Access denied.");
}

// If no academic year is specified, use the current one
if (!$academic_year_id) {
    $stmt = $pdo->query("SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1");
    $academic_year = $stmt->fetch(PDO::FETCH_ASSOC);
    $academic_year_id = $academic_year ? $academic_year['id'] : 0;
}

// Validate required parameters
if ($academic_year_id <= 0) {
    die("Academic year is required.");
}

// Initialize query parts
$params = [];
$where_clauses = ["naf.academic_year_id = :academic_year_id"];
$params[':academic_year_id'] = $academic_year_id;

// Add optional filters
if ($department_id > 0) {
    $where_clauses[] = "s.department_id = :department_id";
    $params[':department_id'] = $department_id;
}

if ($semester > 0) {
    $where_clauses[] = "naf.semester = :semester";
    $params[':semester'] = $semester;
}

if (!empty($section)) {
    $where_clauses[] = "s.section = :section";
    $params[':section'] = $section;
}

try {
    // Build and execute query
    $query = "SELECT 
                naf.id, 
                naf.feedback, 
                naf.submitted_at,
                naf.semester,
                s.name AS student_name, 
                s.roll_number,
                s.section,
                d.name AS department_name,
                d.code AS department_code,
                ay.year_range AS academic_year
              FROM non_academic_feedback naf
              JOIN students s ON naf.student_id = s.id
              JOIN departments d ON s.department_id = d.id
              JOIN academic_years ay ON naf.academic_year_id = ay.id
              WHERE " . implode(" AND ", $where_clauses) . "
              ORDER BY d.name, naf.semester, s.section, s.roll_number";

    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get non-academic feedback statements
    $stmt = $pdo->query("SELECT id, statement_number, statement FROM non_academic_feedback_statements WHERE is_active = TRUE ORDER BY statement_number");
    $statements = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statements[$row['statement_number']] = $row['statement'];
    }

    // Get department and academic year info for report header
    $department_name = "All Departments";
    if ($department_id > 0) {
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :department_id");
        $stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
        $stmt->execute();
        $dept = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($dept) {
            $department_name = $dept['name'];
        }
    }

    $stmt = $pdo->prepare("SELECT year_range FROM academic_years WHERE id = :academic_year_id");
    $stmt->bindParam(':academic_year_id', $academic_year_id, PDO::PARAM_INT);
    $stmt->execute();
    $academic_year = $stmt->fetch(PDO::FETCH_ASSOC);
    $academic_year_name = $academic_year ? $academic_year['year_range'] : "";

    // Define the keys that should be present in each feedback
    $feedback_keys = ['1', '2', '3', '4']; // Match the actual JSON structure

    // Organize data for reporting
    $report_data = [];
    foreach ($feedbacks as $feedback) {
        $feedback_json = json_decode($feedback['feedback'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Handle JSON decode error
            error_log("JSON decode error for feedback ID {$feedback['id']}: " . json_last_error_msg());
            continue;
        }
        
        // Debug log the feedback data
        error_log("Feedback data for ID {$feedback['id']}: " . print_r($feedback_json, true));
        
        // Ensure all keys exist in the feedback
        foreach ($feedback_keys as $key) {
            if (!isset($feedback_json[$key])) {
                $feedback_json[$key] = "";
            }
        }
        
        $dept = $feedback['department_name'];
        $sem = $feedback['semester'];
        $section = $feedback['section'];
        
        if (!isset($report_data[$dept])) {
            $report_data[$dept] = [];
        }
        
        if (!isset($report_data[$dept][$sem])) {
            $report_data[$dept][$sem] = [];
        }
        
        if (!isset($report_data[$dept][$sem][$section])) {
            $report_data[$dept][$sem][$section] = [
                'student_responses' => []
            ];
        }
        
        // Store student info with their responses
        $student_data = [
            'name' => $feedback['student_name'],
            'roll_number' => $feedback['roll_number'],
            'submitted_at' => $feedback['submitted_at'],
            'responses' => $feedback_json
        ];
        
        $report_data[$dept][$sem][$section]['student_responses'][] = $student_data;
    }
    
    // Generate report based on format
    if ($format === 'excel') {
        // Excel report
        require_once 'vendor/autoload.php';
        
        // Import PhpSpreadsheet classes
        require_once 'vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Spreadsheet.php';
        require_once 'vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Writer/Xlsx.php';
        
        // Create instance
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        
        // Create Summary worksheet
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Summary');
        
        // Set summary header
        $summarySheet->setCellValue('A1', 'Non-Academic Feedback Summary Report');
        $summarySheet->setCellValue('A2', 'Academic Year: ' . $academic_year_name);
        $summarySheet->setCellValue('A3', 'Department: ' . $department_name);
        
        // Summary headers
        $summarySheet->setCellValue('A5', 'Department');
        $summarySheet->setCellValue('B5', 'Semester');
        $summarySheet->setCellValue('C5', 'Section');
        $summarySheet->setCellValue('D5', 'Total Students');
        $summarySheet->setCellValue('E5', 'Responses Received');
        $summarySheet->setCellValue('F5', 'Participation Rate');
        
        $summaryRow = 6;
        $worksheetIndex = 0;
        
        $row = 7;
        
        foreach ($report_data as $dept => $semesters) {
            foreach ($semesters as $sem => $sections) {
                foreach ($sections as $sect => $data) {
                    $worksheetIndex++;
                    
                    // Get total number of students in this section from database
                    $student_params = [
                        ':dept' => $dept,
                        ':section' => $sect,
                        ':semester' => $sem
                    ];
                    
                    $student_query = "SELECT COUNT(DISTINCT s.id) as total 
                                    FROM students s 
                                    JOIN departments d ON s.department_id = d.id
                                    JOIN batch_years b ON s.batch_id = b.id
                                    WHERE d.name = :dept
                                    AND s.section = :section
                                    AND (
                                        CASE 
                                            WHEN :semester = 1 THEN b.current_year_of_study = 1
                                            WHEN :semester = 2 THEN b.current_year_of_study = 1
                                            WHEN :semester = 3 THEN b.current_year_of_study = 2
                                            WHEN :semester = 4 THEN b.current_year_of_study = 2
                                            WHEN :semester = 5 THEN b.current_year_of_study = 3
                                            WHEN :semester = 6 THEN b.current_year_of_study = 3
                                            WHEN :semester = 7 THEN b.current_year_of_study = 4
                                            WHEN :semester = 8 THEN b.current_year_of_study = 4
                                        END
                                    )";
                    
                    $stmt = $pdo->prepare($student_query);
                    $stmt->execute($student_params);
                    $totalStudents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    // Calculate participation
                    $responses_received = count($data['student_responses']);
                    $participation_rate = $totalStudents > 0 ? round(($responses_received / $totalStudents) * 100, 2) : 0;
                    
                    // Add to summary
                    $summarySheet->setCellValue('A' . $summaryRow, $dept);
                    $summarySheet->setCellValue('B' . $summaryRow, $sem);
                    $summarySheet->setCellValue('C' . $summaryRow, $sect);
                    $summarySheet->setCellValue('D' . $summaryRow, $totalStudents);
                    $summarySheet->setCellValue('E' . $summaryRow, $responses_received);
                    $summarySheet->setCellValue('F' . $summaryRow, $participation_rate . '%');
                    $summaryRow++;
                    
                    // Create new worksheet for this section
                    $sectionSheet = $spreadsheet->createSheet($worksheetIndex);
                    $sectionSheet->setTitle(substr("${dept}_S${sem}_${sect}", 0, 31)); // Max 31 chars for sheet name
                    
                    // Section sheet header
                    $sectionSheet->setCellValue('A1', "Non-Academic Feedback Report");
                    $sectionSheet->setCellValue('A2', "Department: $dept");
                    $sectionSheet->setCellValue('A3', "Semester: $sem");
                    $sectionSheet->setCellValue('A4', "Section: $sect");
                    $sectionSheet->setCellValue('A5', "Academic Year: $academic_year_name");
                    $sectionSheet->setCellValue('A6', "Total Students: $totalStudents");
                    $sectionSheet->setCellValue('A7', "Responses Received: $responses_received");
                    $sectionSheet->setCellValue('A8', "Participation Rate: $participation_rate%");
                    
                    // Style the header
                    $sectionSheet->getStyle('A1:A8')->getFont()->setBold(true);
                    $sectionSheet->mergeCells('A1:E1');
                    $sectionSheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    
                    $row = 10; // Start data from row 10
                    
                    // Table headers
                    $sectionSheet->setCellValue('A' . $row, 'Roll Number');
                    $sectionSheet->setCellValue('B' . $row, 'Student Name');
                    $sectionSheet->setCellValue('C' . $row, 'Statement');
                    $sectionSheet->setCellValue('D' . $row, 'Response');
                    $sectionSheet->setCellValue('E' . $row, 'Submitted At');
                    $sectionSheet->getStyle("A{$row}:E{$row}")->getFont()->setBold(true);
                    $row++;
                    
                    // Responses for each student
                    foreach ($data['student_responses'] as $student) {
                        $first_row = $row;
                        
                        foreach ($statements as $num => $statement_text) {
                            $key = (string)$num; // Convert statement number to string to match JSON keys
                            
                            if ($first_row == $row) {
                                // First row of this student includes student info
                                $sectionSheet->setCellValue('A' . $row, $student['roll_number']);
                                $sectionSheet->setCellValue('B' . $row, $student['name']);
                                $sectionSheet->setCellValue('C' . $row, $statement_text);
                                $sectionSheet->setCellValue('D' . $row, empty($student['responses'][$key]) ? "[No response]" : $student['responses'][$key]);
                                $sectionSheet->setCellValue('E' . $row, $student['submitted_at']);
                            } else {
                                // Subsequent rows only include the statement and response
                                $sectionSheet->setCellValue('C' . $row, $statement_text);
                                $sectionSheet->setCellValue('D' . $row, empty($student['responses'][$key]) ? "[No response]" : $student['responses'][$key]);
                            }
                            $row++;
                        }
                        
                        // Merge the student info cells across their rows
                        if ($row > $first_row + 1) {
                            $sectionSheet->mergeCells("A{$first_row}:A" . ($row-1));
                            $sectionSheet->mergeCells("B{$first_row}:B" . ($row-1));
                            $sectionSheet->mergeCells("E{$first_row}:E" . ($row-1));
                        }
                        
                        $row++; // Add a blank row between students
                    }
                    
                    $row += 2; // Add space between sections
                }
            }
        }
        
        // Format summary sheet
        $summarySheet->getStyle('A5:F5')->getFont()->setBold(true);
        $summarySheet->getStyle('A5:F' . ($summaryRow-1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $summarySheet->getStyle('F6:F' . ($summaryRow-1))->getNumberFormat()->setFormatCode('0.00%');
        
        // Auto-size columns for all sheets
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }
        
        // Set the first sheet (Summary) as active
        $spreadsheet->setActiveSheetIndex(0);
        
        // Set headers and output file
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Non_Academic_Feedback_Report.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
        
    } else {
        // PDF report
        require_once('fpdf.php');
        
        class PDF extends FPDF {
            function Header() {
                $this->SetFont('Arial', 'B', 16);
                $this->Cell(0, 10, 'Non-Academic Feedback Report', 0, 1, 'C');
                $this->SetFont('Arial', '', 12);
                $this->Ln(5);
            }
            
            function Footer() {
                $this->SetY(-15);
                $this->SetFont('Arial', 'I', 8);
                $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            }
            
            function SectionHeader($text) {
                $this->SetFont('Arial', 'B', 12);
                $this->Cell(0, 10, $text, 0, 1);
                $this->SetFont('Arial', '', 10);
            }
        }
        
        $pdf = new PDF();
        $pdf->AliasNbPages();
        $pdf->AddPage();
        
        // Report header
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, 'Academic Year: ' . $academic_year_name, 0, 1);
        $pdf->Cell(0, 8, 'Department: ' . $department_name, 0, 1);
        if ($semester > 0) {
            $pdf->Cell(0, 8, 'Semester: ' . $semester, 0, 1);
        }
        if (!empty($section)) {
            $pdf->Cell(0, 8, 'Section: ' . $section, 0, 1);
        }
        $pdf->Ln(5);

        // Summary Statistics Table
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 10, 'Summary Statistics', 0, 1, 'L');
        
        // Table headers
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor(200, 200, 200);
        $pdf->Cell(50, 8, 'Department', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Semester', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Section', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Total', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Responses', 1, 0, 'C', true);
        $pdf->Cell(30, 8, 'Rate (%)', 1, 1, 'C', true);

        // Table data
        $pdf->SetFont('Arial', '', 10);
        foreach ($report_data as $dept => $semesters) {
            foreach ($semesters as $sem => $sections) {
                foreach ($sections as $sect => $data) {
                    // Get total number of students
                    $student_params = [
                        ':dept' => $dept,
                        ':section' => $sect,
                        ':semester' => $sem
                    ];
                    
                    $student_query = "SELECT COUNT(DISTINCT s.id) as total 
                                    FROM students s 
                                    JOIN departments d ON s.department_id = d.id
                                    JOIN batch_years b ON s.batch_id = b.id
                                    WHERE d.name = :dept
                                    AND s.section = :section
                                    AND (
                                        CASE 
                                            WHEN :semester = 1 THEN b.current_year_of_study = 1
                                            WHEN :semester = 2 THEN b.current_year_of_study = 1
                                            WHEN :semester = 3 THEN b.current_year_of_study = 2
                                            WHEN :semester = 4 THEN b.current_year_of_study = 2
                                            WHEN :semester = 5 THEN b.current_year_of_study = 3
                                            WHEN :semester = 6 THEN b.current_year_of_study = 3
                                            WHEN :semester = 7 THEN b.current_year_of_study = 4
                                            WHEN :semester = 8 THEN b.current_year_of_study = 4
                                        END
                                    )";
                    
                    $stmt = $pdo->prepare($student_query);
                    $stmt->execute($student_params);
                    $totalStudents = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    $responses_received = count($data['student_responses']);
                    $participation_rate = $totalStudents > 0 ? round(($responses_received / $totalStudents) * 100, 2) : 0;

                    $pdf->Cell(50, 8, $dept, 1, 0, 'L');
                    $pdf->Cell(25, 8, $sem, 1, 0, 'C');
                    $pdf->Cell(25, 8, $sect, 1, 0, 'C');
                    $pdf->Cell(30, 8, $totalStudents, 1, 0, 'C');
                    $pdf->Cell(30, 8, $responses_received, 1, 0, 'C');
                    $pdf->Cell(30, 8, $participation_rate . '%', 1, 1, 'C');
                }
            }
        }
        
        $pdf->Ln(10);
        
        foreach ($report_data as $dept => $semesters) {
            foreach ($semesters as $sem => $sections) {
                foreach ($sections as $sect => $data) {
                    $pdf->SetFont('Arial', 'B', 12);
                    $pdf->Cell(0, 10, "Department: $dept - Semester: $sem - Section: $sect", 0, 1);
                    
                    // Student count
                    $student_count = count($data['student_responses']);
                    $pdf->Cell(0, 8, "Number of Students: $student_count", 0, 1);
                    $pdf->Ln(3);
                    
                    // Student responses
                    foreach ($data['student_responses'] as $i => $student) {
                        // Student header
                        $pdf->SetFont('Arial', 'B', 11);
                        $pdf->Cell(0, 8, "Student: " . $student['name'] . " (Roll No: " . $student['roll_number'] . ")", 0, 1);
                        $pdf->SetFont('Arial', '', 10);
                        $pdf->Cell(0, 6, "Submitted: " . $student['submitted_at'], 0, 1);
                        
                        // Feedback responses
                        foreach ($statements as $num => $statement_text) {
                            $key = (string)$num; // Convert statement number to string to match JSON keys
                            
                            $pdf->SetFont('Arial', 'B', 10);
                            $pdf->MultiCell(0, 6, "Q$num: $statement_text", 0, 'L');
                            $pdf->SetFont('Arial', '', 10);
                            
                            $response = empty($student['responses'][$key]) ? "[No response]" : $student['responses'][$key];
                            $pdf->MultiCell(0, 6, $response, 0, 'L');
                            $pdf->Ln(3);
                        }
                        
                        // Add separator between students
                        if ($i < count($data['student_responses']) - 1) {
                            $pdf->Line($pdf->GetX(), $pdf->GetY(), $pdf->GetPageWidth() - $pdf->GetX(), $pdf->GetY());
                            $pdf->Ln(5);
                        }
                        
                        // Check if we need a page break for the next student
                        if ($pdf->GetY() > 240) {
                            $pdf->AddPage();
                        }
                    }
                    
                    $pdf->AddPage();
                }
            }
        }
        
        $pdf->Output('Non_Academic_Feedback_Report.pdf', 'I');
        exit;
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?> 