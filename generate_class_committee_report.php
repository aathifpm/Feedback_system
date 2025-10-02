<?php
session_start();

// Set character encoding for proper display
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once 'db_connection.php';
require_once 'functions.php';
require_once 'vendor/autoload.php'; // For PDF and Excel generation

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;


// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'faculty', 'hod'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get parameters
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$faculty_id = isset($_GET['faculty_id']) ? intval($_GET['faculty_id']) : 0;
$department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;
$section = isset($_GET['section']) ? $_GET['section'] : '';
$format = isset($_GET['format']) ? $_GET['format'] : 'pdf';

// Validate access based on role
$has_access = false;
$department_filter = '';
$faculty_filter = '';

switch ($role) {
    case 'admin':
        $has_access = true;
        break;
        
    case 'hod':
        // HODs can only access their department's data
        $hod_query = "SELECT department_id FROM hods WHERE id = ? AND is_active = TRUE";
        $stmt = mysqli_prepare($conn, $hod_query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $hod_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if ($hod_result) {
            $has_access = true;
            $department_id = $department_id ?: $hod_result['department_id'];
            $department_filter = "AND s.department_id = " . $department_id;
        }
        break;
        
    case 'faculty':
        // Faculty can only access their own data
        $faculty_query = "SELECT id, department_id FROM faculty WHERE id = ? AND is_active = TRUE";
        $stmt = mysqli_prepare($conn, $faculty_query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $faculty_result = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        
        if ($faculty_result) {
            $has_access = true;
            $faculty_id = $user_id;
            $faculty_filter = "AND sa.faculty_id = " . $faculty_id;
            $department_id = $faculty_result['department_id'];
        }
        break;
}

if (!$has_access) {
    die("Access denied.");
}

// If no academic year is specified, use the current one
if (!$academic_year_id) {
    $academic_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
    $academic_year_result = mysqli_query($conn, $academic_year_query);
    $academic_year = mysqli_fetch_assoc($academic_year_result);
    $academic_year_id = $academic_year ? $academic_year['id'] : 0;
}

// Build the query based on filters
$filters = [];
$params = [];
$types = "";

$base_query = "SELECT 
    sa.id as assignment_id,
    s.name as subject_name,
    s.code as subject_code,
    f.name as faculty_name,
    d.name as department_name,
    ay.year_range as academic_year,
    sa.semester,
    sa.section,
    COUNT(ccr.id) as total_responses,
    ccs.statement_number,
    ccs.statement,
    AVG(JSON_EXTRACT(ccr.academic_ratings, CONCAT('$.', ccs.id))) * 2 as avg_rating
FROM 
    subject_assignments sa
JOIN 
    subjects s ON sa.subject_id = s.id
JOIN 
    faculty f ON sa.faculty_id = f.id
JOIN 
    departments d ON s.department_id = d.id
JOIN 
    academic_years ay ON sa.academic_year_id = ay.id
JOIN 
    class_committee_statements ccs ON ccs.is_active = TRUE
LEFT JOIN 
    class_committee_responses ccr ON ccr.assignment_id = sa.id
WHERE 
    sa.is_active = TRUE ";

if ($academic_year_id) {
    $filters[] = "sa.academic_year_id = ?";
    $params[] = $academic_year_id;
    $types .= "i";
}

if ($subject_id) {
    $filters[] = "s.id = ?";
    $params[] = $subject_id;
    $types .= "i";
}

if ($faculty_id) {
    $filters[] = "sa.faculty_id = ?";
    $params[] = $faculty_id;
    $types .= "i";
}

if ($department_id) {
    $filters[] = "s.department_id = ?";
    $params[] = $department_id;
    $types .= "i";
}

if ($semester) {
    $filters[] = "sa.semester = ?";
    $params[] = $semester;
    $types .= "i";
}

if ($section) {
    $filters[] = "sa.section = ?";
    $params[] = $section;
    $types .= "s";
}

// Add filters to query
if (!empty($filters)) {
    $base_query .= "AND " . implode(" AND ", $filters) . " ";
}

// Add grouping and ordering
$base_query .= "GROUP BY sa.id, ccs.id
ORDER BY sa.semester, sa.section, s.code, ccs.statement_number";

// Prepare and execute the query
$stmt = mysqli_prepare($conn, $base_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Process results
$report_data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $key = $row['assignment_id'];
    if (!isset($report_data[$key])) {
        $report_data[$key] = [
            'subject_name' => $row['subject_name'],
            'subject_code' => $row['subject_code'],
            'faculty_name' => $row['faculty_name'],
            'department_name' => $row['department_name'],
            'academic_year' => $row['academic_year'],
            'semester' => $row['semester'],
            'section' => $row['section'],
            'total_responses' => $row['total_responses'],
            'statements' => []
        ];
    }
    
    $report_data[$key]['statements'][$row['statement_number']] = [
        'statement' => $row['statement'],
        'avg_rating' => $row['avg_rating'] ? number_format($row['avg_rating'], 2) : 'N/A'
    ];
}

// Get comments for each assignment
foreach ($report_data as $assignment_id => &$data) {
    $comments_query = "SELECT comments FROM class_committee_responses WHERE assignment_id = ? AND comments IS NOT NULL AND comments != ''";
    $stmt = mysqli_prepare($conn, $comments_query);
    mysqli_stmt_bind_param($stmt, "i", $assignment_id);
    mysqli_stmt_execute($stmt);
    $comments_result = mysqli_stmt_get_result($stmt);
    
    $data['comments'] = [];
    while ($comment = mysqli_fetch_assoc($comments_result)) {
        if (!empty(trim($comment['comments']))) {
            $data['comments'][] = $comment['comments'];
        }
    }
}

// Generate report based on format
if ($format === 'excel') {
    // Generate Excel report
    require_once 'vendor/autoload.php';
    
    $spreadsheet = new Spreadsheet();
    
    // Create summary worksheet first
    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('Summary');
    
    // College Title
    $summarySheet->mergeCells('A1:H1');
    $summarySheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
    $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $summarySheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // College Subtitle
    $summarySheet->mergeCells('A2:H2');
    $summarySheet->setCellValue('A2', 'An Autonomous Institution, Affiliated to Anna University');
    $summarySheet->getStyle('A2')->getFont()->setBold(true);
    $summarySheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Report Title
    $summarySheet->setCellValue('A3', 'Class Committee Feedback Report - Summary');
    $summarySheet->mergeCells('A3:H3');
    $summarySheet->getStyle('A3')->getFont()->setBold(true)->setSize(14);
    $summarySheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Add date and filter information
    $summarySheet->setCellValue('A4', 'Generated on: ' . date('d-m-Y'));
    $summarySheet->mergeCells('A4:H4');
    $summarySheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Add some spacing
    $summarySheet->getRowDimension(5)->setRowHeight(10);
    
    // Headers for summary table
    $summarySheet->setCellValue('A6', 'Subject Code');
    $summarySheet->setCellValue('B6', 'Subject Name');
    $summarySheet->setCellValue('C6', 'Faculty');
    $summarySheet->setCellValue('D6', 'Department');
    $summarySheet->setCellValue('E6', 'Semester');
    $summarySheet->setCellValue('F6', 'Section');
    $summarySheet->setCellValue('G6', 'Responses');
    $summarySheet->setCellValue('H6', 'Average Rating');
    $summarySheet->getStyle('A6:H6')->getFont()->setBold(true);
    $summarySheet->getStyle('A6:H6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
    
    // Add summary data
    $row = 7;
    foreach ($report_data as $assignment_id => $data) {
        // Calculate overall average rating for this assignment
        $total_rating = 0;
        $rating_count = 0;
        foreach ($data['statements'] as $statement) {
            if ($statement['avg_rating'] !== 'N/A') {
                $total_rating += (float)$statement['avg_rating'];
                $rating_count++;
            }
        }
        $overall_avg = $rating_count > 0 ? number_format($total_rating / $rating_count, 2) : 'N/A';
        
        $summarySheet->setCellValue('A'.$row, $data['subject_code']);
        $summarySheet->setCellValue('B'.$row, $data['subject_name']);
        $summarySheet->setCellValue('C'.$row, $data['faculty_name']);
        $summarySheet->setCellValue('D'.$row, $data['department_name']);
        $summarySheet->setCellValue('E'.$row, $data['semester']);
        $summarySheet->setCellValue('F'.$row, $data['section']);
        $summarySheet->setCellValue('G'.$row, $data['total_responses']);
        $summarySheet->setCellValue('H'.$row, $overall_avg);
        
        // Add conditional formatting for ratings
        if ($overall_avg !== 'N/A') {
            if ((float)$overall_avg >= 8) {
                $summarySheet->getStyle('H'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE'); // Green
            } elseif ((float)$overall_avg >= 6) {
                $summarySheet->getStyle('H'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB9C'); // Yellow
            } else {
                $summarySheet->getStyle('H'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFCCCC'); // Red
            }
        }
        
        $row++;
    }
    
    // Format summary table as proper table
    $summaryTable = $summarySheet->getStyle('A6:H'.($row-1));
    $summaryTable->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    
    // Auto-size columns for summary sheet
    foreach (range('A', 'H') as $col) {
        $summarySheet->getColumnDimension($col)->setAutoSize(true);
    }
    
    // Group data by section
    $section_data = [];
    foreach ($report_data as $assignment_id => $data) {
        $sem = $data['semester'];
        $sec = $data['section'];
        $key = "S{$sem}{$sec}";
        
        if (!isset($section_data[$key])) {
            $section_data[$key] = [
                'semester' => $sem,
                'section' => $sec,
                'academic_year' => $data['academic_year'],
                'subjects' => []
            ];
        }
        
        $section_data[$key]['subjects'][$assignment_id] = $data;
    }
    
    // Create individual worksheets for each section
    foreach ($section_data as $section_key => $section_info) {
        // Create a worksheet name based on semester and section
        $worksheetName = "Semester " . $section_info['semester'] . "-" . $section_info['section'];
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($worksheetName);
        
        // College Title
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'PANIMALAR ENGINEERING COLLEGE');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // College Subtitle
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'An Autonomous Institution, Affiliated to Anna University');
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Report Title
        $sheet->setCellValue('A3', "Class Committee Feedback - Semester " . $section_info['semester'] . " Section " . $section_info['section']);
        $sheet->mergeCells('A3:G3');
        $sheet->getStyle('A3')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Academic Year information
        $sheet->setCellValue('A4', 'Academic Year: ' . $section_info['academic_year']);
        $sheet->mergeCells('A4:G4');
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Add some spacing
        $sheet->getRowDimension(5)->setRowHeight(10);
        
        // Table headers for subjects
        $sheet->setCellValue('A6', 'Subject Code');
        $sheet->setCellValue('B6', 'Subject Name');
        $sheet->setCellValue('C6', 'Faculty');
        $sheet->setCellValue('D6', 'Department');
        $sheet->setCellValue('E6', 'Responses');
        $sheet->setCellValue('F6', 'Average Rating');
        $sheet->getStyle('A6:F6')->getFont()->setBold(true);
        $sheet->getStyle('A6:F6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
        
        // Add subject data
        $row = 7;
        foreach ($section_info['subjects'] as $assignment_id => $data) {
            // Calculate overall average rating for this assignment
            $total_rating = 0;
            $rating_count = 0;
            foreach ($data['statements'] as $statement) {
                if ($statement['avg_rating'] !== 'N/A') {
                    $total_rating += (float)$statement['avg_rating'];
                    $rating_count++;
                }
            }
            $overall_avg = $rating_count > 0 ? number_format($total_rating / $rating_count, 2) : 'N/A';
            
            $sheet->setCellValue('A'.$row, $data['subject_code']);
            $sheet->setCellValue('B'.$row, $data['subject_name']);
            $sheet->setCellValue('C'.$row, $data['faculty_name']);
            $sheet->setCellValue('D'.$row, $data['department_name']);
            $sheet->setCellValue('E'.$row, $data['total_responses']);
            $sheet->setCellValue('F'.$row, $overall_avg);
            
            // Add conditional formatting for ratings
            if ($overall_avg !== 'N/A') {
                if ((float)$overall_avg >= 8) {
                    $sheet->getStyle('F'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE'); // Green
                } elseif ((float)$overall_avg >= 6) {
                    $sheet->getStyle('F'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB9C'); // Yellow
                } else {
                    $sheet->getStyle('F'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFCCCC'); // Red
                }
            }
            
            $row++;
        }
        
        // Format subjects table
        $subjectsTable = $sheet->getStyle('A6:F'.($row-1));
        $subjectsTable->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // Add statement ratings for all subjects
        $row += 2;
        $sheet->setCellValue('A'.$row, 'Statement Ratings by Subject');
        $sheet->mergeCells('A'.$row.':F'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // For each statement, show all subject ratings side by side
        $row += 2;
        $sheet->setCellValue('A'.$row, 'No.');
        $sheet->setCellValue('B'.$row, 'Statement');
        
        // Add subject column headers
        $col = 'C';
        foreach ($section_info['subjects'] as $assignment_id => $data) {
            $sheet->setCellValue($col.$row, $data['subject_code']);
            $col++;
            if ($col > 'Z') break; // Avoid going beyond column Z
        }
        $sheet->getStyle('A'.$row.':'.$col.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':'.$col.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
        
        // Get all unique statement numbers
        $all_statements = [];
        foreach ($section_info['subjects'] as $assignment_id => $data) {
            foreach ($data['statements'] as $number => $statement) {
                if (!isset($all_statements[$number])) {
                    $all_statements[$number] = $statement['statement'];
                }
            }
        }
        ksort($all_statements);
        
        // Add statement ratings
        $row++;
        foreach ($all_statements as $number => $statement_text) {
            $sheet->setCellValue('A'.$row, $number);
            $sheet->setCellValue('B'.$row, $statement_text);
            
            $col = 'C';
            foreach ($section_info['subjects'] as $assignment_id => $data) {
                if (isset($data['statements'][$number])) {
                    $sheet->setCellValue($col.$row, $data['statements'][$number]['avg_rating']);
                    
                    // Add conditional formatting for individual ratings
                    if ($data['statements'][$number]['avg_rating'] !== 'N/A') {
                        $rating = (float)$data['statements'][$number]['avg_rating'];
                        if ($rating >= 8) {
                            $sheet->getStyle($col.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE');
                        } elseif ($rating >= 6) {
                            $sheet->getStyle($col.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFEB9C');
                        } else {
                            $sheet->getStyle($col.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFCCCC');
                        }
                    }
                } else {
                    $sheet->setCellValue($col.$row, 'N/A');
                }
                $col++;
                if ($col > 'Z') break; // Avoid going beyond column Z
            }
            $row++;
        }
        
        // Format ratings table
        $last_col = chr(min(ord('B') + count($section_info['subjects']) + 1, ord('Z')));
        $ratingsTable = $sheet->getStyle('A'.($row-count($all_statements)).':'.$last_col.($row-1));
        $ratingsTable->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // Create a bar chart comparing subjects
        if (count($section_info['subjects']) > 1) {
            $row += 2;
            $sheet->setCellValue('A'.$row, 'Subject Comparison');
            $sheet->mergeCells('A'.$row.':F'.$row);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $row++;
            
            // Setup chart data
            $chart_data_col = $last_col;
            $chart_data_row = $row;
            $sheet->setCellValue($chart_data_col.$chart_data_row, 'Subject');
            $chart_data_col++;
            $sheet->setCellValue($chart_data_col.$chart_data_row, 'Avg Rating');
            $chart_data_row++;
            
            foreach ($section_info['subjects'] as $assignment_id => $data) {
                // Calculate overall average rating
                $total_rating = 0;
                $rating_count = 0;
                foreach ($data['statements'] as $statement) {
                    if ($statement['avg_rating'] !== 'N/A') {
                        $total_rating += (float)$statement['avg_rating'];
                        $rating_count++;
                    }
                }
                $overall_avg = $rating_count > 0 ? number_format($total_rating / $rating_count, 2) : 0;
                
                $chart_data_col = $last_col;
                $sheet->setCellValue($chart_data_col.$chart_data_row, $data['subject_code']);
                $chart_data_col++;
                $sheet->setCellValue($chart_data_col.$chart_data_row, $overall_avg);
                $chart_data_row++;
            }
            
            // Create the chart
            $chart = new \PhpOffice\PhpSpreadsheet\Chart\Chart(
                'Subject Comparison',
                new \PhpOffice\PhpSpreadsheet\Chart\Title('Average Ratings by Subject')
            );
            
            // Define the chart data series
            $dataSeriesLabels = [
                new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues('String', $worksheetName.'!$'.$last_col.'$'.$row, NULL, 1)
            ];
            
            $xAxisTickValues = [
                new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues('String', $worksheetName.'!$'.$last_col.'$'.($row+1).':$'.$last_col.'$'.($chart_data_row-1), NULL, count($section_info['subjects']))
            ];
            
            $dataSeriesValues = [
                new \PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues('Number', $worksheetName.'!$'.chr(ord($last_col)+1).'$'.($row+1).':$'.chr(ord($last_col)+1).'$'.($chart_data_row-1), NULL, count($section_info['subjects']))
            ];
            
            // Build the dataseries
            $series = new \PhpOffice\PhpSpreadsheet\Chart\DataSeries(
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::TYPE_BARCHART,
                \PhpOffice\PhpSpreadsheet\Chart\DataSeries::GROUPING_CLUSTERED,
                range(0, count($dataSeriesValues) - 1),
                $dataSeriesLabels,
                $xAxisTickValues,
                $dataSeriesValues
            );
            $series->setPlotDirection(\PhpOffice\PhpSpreadsheet\Chart\DataSeries::DIRECTION_BAR);
            
            // Set up the chart
            $plotArea = new \PhpOffice\PhpSpreadsheet\Chart\PlotArea(NULL, [$series]);
            $legend = new \PhpOffice\PhpSpreadsheet\Chart\Legend(\PhpOffice\PhpSpreadsheet\Chart\Legend::POSITION_RIGHT, NULL, false);
            
            $chart->setPlotArea($plotArea);
            $chart->setLegend($legend);
            
            $chart->setTopLeftPosition('A'.($row+1));
            $chart->setBottomRightPosition('F'.($row+15));
            
            // Add the chart to the worksheet
            $sheet->addChart($chart);
            
            $row += 16;  // Move past the chart
        }
        
        // Comments section for each subject
        $row += 2;
        $sheet->setCellValue('A'.$row, 'Comments by Subject');
        $sheet->mergeCells('A'.$row.':F'.$row);
        $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $row++;
        
        foreach ($section_info['subjects'] as $assignment_id => $data) {
            $sheet->setCellValue('A'.$row, $data['subject_code'] . ' - ' . $data['subject_name'] . ' (' . $data['faculty_name'] . ')');
            $sheet->mergeCells('A'.$row.':F'.$row);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $sheet->getStyle('A'.$row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('EAEAEA');
            $row++;
            
            if (empty($data['comments'])) {
                $sheet->setCellValue('A'.$row, 'No comments available');
                $sheet->mergeCells('A'.$row.':F'.$row);
                $row++;
            } else {
                $sheet->setCellValue('A'.$row, 'No.');
                $sheet->setCellValue('B'.$row, 'Comment');
                $sheet->mergeCells('B'.$row.':F'.$row);
                $sheet->getStyle('A'.$row.':F'.$row)->getFont()->setBold(true);
                $row++;
                
                $comment_number = 1;
                foreach ($data['comments'] as $comment) {
                    $sheet->setCellValue('A'.$row, $comment_number);
                    $sheet->setCellValue('B'.$row, $comment);
                    $sheet->mergeCells('B'.$row.':F'.$row);
                    $sheet->getStyle('B'.$row)->getAlignment()->setWrapText(true);
                    $comment_number++;
                    $row++;
                }
            }
            $row += 1;  // Add space between subject comments
        }
        
        // Auto-size columns for the sheet
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    // Set the first sheet as active
    $spreadsheet->setActiveSheetIndex(0);
    
    // Set filename
    $filename = 'class_committee_report_' . date('Y-m-d') . '.xlsx';
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Save file to php://output
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
    
} else {
    // Generate PDF report
    require_once 'fpdf.php';
    
    // Create PDF using FPDF with custom header/footer
    class MYPDF extends FPDF {
        private $is_first_page = true;
        private $filters_info = [];
        
        public function __construct($filters_info = []) {
            parent::__construct('P', 'mm', 'A4'); // Explicitly set format
            $this->filters_info = $filters_info;
        }
        
        public function Header() {
            // Skip header on first page to create custom title page
            if ($this->is_first_page) {
            return;
            }
            
            // Logo
            if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 15, 8, 20);
            }
            
            // College header with proper alignment next to logo
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0, 0, 0); // Black
            $this->SetXY(40, 10); // Position next to logo
            $this->Cell(0, 8, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'L');
            
            // College subtitle
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0, 0, 0); // Black
            $this->SetXY(40, 18);
            $this->Cell(0, 8, 'An Autonomous Institution, Affiliated to Anna University, Chennai', 0, 1, 'L');
            
            // Address
            $this->SetFont('Arial', '', 10);
            $this->SetXY(40, 26);
            $this->Cell(0, 6, 'Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai - 600 123', 0, 1, 'L');
            
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(0, 0, 0); // Black
            $this->SetXY(40, 32); // Position below address
            $this->Cell(0, 5, 'Class Committee Feedback Report', 0, 0, 'L');
            
            // Header line (positioned after all text)
            $this->SetY(40);
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetLineWidth(0.8);
            $this->Line(15, $this->GetY(), 195, $this->GetY());
            $this->Ln(8);
        }
        
        public function Footer() {
            $this->SetY(-20);
            
            // Footer line
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetLineWidth(0.3);
            $this->Line(15, $this->GetY() - 2, 195, $this->GetY() - 2);
            
            // Footer content
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(0, 0, 0); // Black
            
            // Left side - confidentiality notice
            $this->Cell(60, 8, 'Confidential Document', 0, 0, 'L');
            
            // Center - page number
            $this->Cell(70, 8, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'C');
            
            // Right side - generation date
            $this->Cell(60, 8, 'Generated: ' . date('d-M-Y H:i'), 0, 0, 'R');
        }
        
        public function CreateTitlePage() {
            $this->is_first_page = true;
            $this->AddPage();
            
            // Background decoration
            $this->SetFillColor(255, 255, 255); // White background
            $this->Rect(0, 0, 210, 297, 'F');
            
            // Logo (larger for title page)
            if (file_exists('college_logo.png')) {
                $this->Image('college_logo.png', 85, 30, 40);
            }
            
            // College name with enhanced styling
            $this->SetFont('Arial', 'B', 24);
            $this->SetTextColor(0, 0, 0); // Black
            $this->SetY(80);
            $this->Cell(0, 15, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'C');
            
            // College subtitle
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(0, 8, 'An Autonomous Institution, Affiliated to Anna University, Chennai', 0, 1, 'C');
            
            // Address
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 6, 'Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai - 600 123', 0, 1, 'C');
            
            // Decorative line
            $this->Ln(15);
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetLineWidth(2);
            $this->Line(50, $this->GetY(), 160, $this->GetY());
            
            // Report title
            $this->Ln(20);
            $this->SetFont('Arial', 'B', 20);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(0, 12, 'CLASS COMMITTEE FEEDBACK REPORT', 0, 1, 'C');
            
            // Academic year and batch year info
            $this->Ln(10);
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0, 0, 0); // Black
            if (!empty($this->filters_info['academic_year'])) {
                $this->Cell(0, 8, 'Academic Year: ' . $this->filters_info['academic_year'], 0, 1, 'C');
            }
            
            // Add batch year information
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0, 0, 0); // Black
            $batch_year = date('Y');
            $this->Cell(0, 6, 'Batch Year: ' . $batch_year, 0, 1, 'C');
            
            // Report generation details box
            $this->Ln(30);
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetFillColor(245, 245, 245); // Light gray
            $this->SetLineWidth(0.5);
            $box_y = $this->GetY();
            $this->Rect(40, $box_y, 130, 60, 'DF');
            
            // Center the "REPORT DETAILS" title properly within the box
            $this->SetXY(40, $box_y + 8);
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(130, 8, 'REPORT DETAILS', 0, 1, 'C');
            
            $this->SetX(50);
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0); // Black
            
            // Filter information
            $filter_text = [];
            if (!empty($this->filters_info['department'])) {
                $filter_text[] = 'Department: ' . $this->filters_info['department'];
            }
            if (!empty($this->filters_info['semester'])) {
                $filter_text[] = 'Semester: ' . $this->filters_info['semester'];
            }
            if (!empty($this->filters_info['section'])) {
                $filter_text[] = 'Section: ' . $this->filters_info['section'];
            }
            if (!empty($this->filters_info['faculty'])) {
                $filter_text[] = 'Faculty: ' . $this->filters_info['faculty'];
            }
            if (!empty($this->filters_info['subject'])) {
                $filter_text[] = 'Subject: ' . $this->filters_info['subject'];
            }
            
            if (empty($filter_text)) {
                $filter_text[] = 'All Departments, Semesters, and Sections';
            }
            
            foreach ($filter_text as $text) {
                $this->SetX(50);
                $this->Cell(0, 6, $text, 0, 1, 'L');
            }
            
            $this->SetX(50);
            $this->Cell(0, 6, 'Generated on: ' . date('F j, Y') . ' at ' . date('g:i A'), 0, 1, 'L');
            $this->SetX(50);
            $this->Cell(0, 6, 'Report Format: PDF', 0, 1, 'L');
            
            // Bottom message without the decorative line
            $this->SetY(260);
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(0, 5, 'This report contains confidential feedback data. Handle with care.', 0, 1, 'C');
            
            $this->is_first_page = false;
        }
        
        public function CreateSummaryPage($report_data) {
            $this->AddPage();
            
            // Page title
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(0, 12, 'EXECUTIVE SUMMARY', 0, 1, 'C');
            
            // Summary statistics
            $total_subjects = count($report_data);
            $total_responses = 0;
            $total_ratings = 0;
            $rating_count = 0;
            
            foreach ($report_data as $data) {
                $total_responses += $data['total_responses'];
                foreach ($data['statements'] as $statement) {
                    if ($statement['avg_rating'] !== 'N/A') {
                        $total_ratings += floatval($statement['avg_rating']);
                        $rating_count++;
                    }
                }
            }
            
            $overall_avg = $rating_count > 0 ? $total_ratings / $rating_count : 0;
            
            // Summary box
            $this->Ln(5);
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetFillColor(245, 245, 245); // Light gray
            $this->SetLineWidth(0.5);
            $this->Rect(20, $this->GetY(), 170, 50, 'DF');
            
            $this->SetXY(30, $this->GetY() + 10);
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(0, 0, 0); // Black
            
            $this->Cell(70, 8, 'Total Subjects Evaluated:', 0, 0, 'L');
            $this->SetFont('Arial', '', 11);
            $this->Cell(30, 8, $total_subjects, 0, 1, 'L');
            
            $this->SetX(30);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(70, 8, 'Total Student Responses:', 0, 0, 'L');
            $this->SetFont('Arial', '', 11);
            $this->Cell(30, 8, $total_responses, 0, 1, 'L');
            
            $this->SetX(30);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(70, 8, 'Overall Average Rating:', 0, 0, 'L');
            $this->SetFont('Arial', '', 11);
            $this->Cell(30, 8, number_format($overall_avg, 2) . '/10', 0, 1, 'L');
            
            $this->SetX(30);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell(70, 8, 'Report Generation Date:', 0, 0, 'L');
            $this->SetFont('Arial', '', 11);
            $this->Cell(30, 8, date('F j, Y'), 0, 1, 'L');
            
            // Quick overview table
            $this->Ln(20);
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell(0, 10, 'SUBJECTS OVERVIEW', 0, 1, 'C');
            
            $this->Ln(5);
            $this->CreateSubjectSummaryTable($report_data);
        }
        
        public function CreateSubjectSummaryTable($report_data) {
            // Table headers
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(200, 200, 200); // Light gray
            $this->SetTextColor(0, 0, 0); // Black
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetLineWidth(0.3);
            
            $this->Cell(25, 8, 'Code', 1, 0, 'C', true);
            $this->Cell(50, 8, 'Subject Name', 1, 0, 'C', true);
            $this->Cell(40, 8, 'Faculty', 1, 0, 'C', true);
            $this->Cell(15, 8, 'Sem', 1, 0, 'C', true);
            $this->Cell(15, 8, 'Sec', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Responses', 1, 0, 'C', true);
            $this->Cell(20, 8, 'Avg Rating', 1, 1, 'C', true);
            
            // Table data
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(0, 0, 0);
            $fill = false;
            
            foreach ($report_data as $data) {
                // Calculate average rating
                $total_rating = 0;
                $rating_count = 0;
                foreach ($data['statements'] as $statement) {
                    if ($statement['avg_rating'] !== 'N/A') {
                        $total_rating += floatval($statement['avg_rating']);
                        $rating_count++;
                    }
                }
                $avg_rating = $rating_count > 0 ? number_format($total_rating / $rating_count, 2) : 'N/A';
                
                // Grayscale fill based on rating
                if ($avg_rating !== 'N/A') {
                    $rating_val = floatval($avg_rating);
                    if ($rating_val >= 8.0) {
                        $this->SetFillColor(220, 220, 220); // Light gray for high ratings
                    } elseif ($rating_val >= 6.0) {
                        $this->SetFillColor(180, 180, 180); // Medium gray for medium ratings
                    } else {
                        $this->SetFillColor(140, 140, 140); // Darker gray for low ratings
                    }
                } else {
                    $this->SetFillColor(240, 240, 240); // Very light gray for N/A
                }
                
                $this->Cell(25, 6, $data['subject_code'], 1, 0, 'C', $fill);
                $this->Cell(50, 6, substr($data['subject_name'], 0, 30), 1, 0, 'L', $fill);
                $this->Cell(40, 6, substr($data['faculty_name'], 0, 25), 1, 0, 'L', $fill);
                $this->Cell(15, 6, $data['semester'], 1, 0, 'C', $fill);
                $this->Cell(15, 6, $data['section'], 1, 0, 'C', $fill);
                $this->Cell(20, 6, $data['total_responses'], 1, 0, 'C', $fill);
                $this->Cell(20, 6, $avg_rating, 1, 1, 'C', true);
                
                $fill = !$fill;
            }
        }
        
        public function CreateInfoBox($label, $value, $x = null, $y = null, $width = 80) {
            if ($x !== null && $y !== null) {
                $this->SetXY($x, $y);
            }
            
            // Box background
            $this->SetFillColor(248, 248, 248); // Light gray
            $this->SetDrawColor(0, 0, 0); // Black
            $this->SetLineWidth(0.2);
            $this->Rect($this->GetX(), $this->GetY(), $width, 8, 'DF');
            
            // Label
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(0, 0, 0); // Black
            $this->Cell($width * 0.4, 8, $label . ':', 0, 0, 'L');
            
            // Value
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(0, 0, 0);
            $this->Cell($width * 0.6, 8, $value, 0, 1, 'L');
        }
        
        public function SectionTitle($title, $level = 1) {
            $this->Ln($level == 1 ? 12 : 8);
            
            $font_size = $level == 1 ? 14 : 12;
            $this->SetFont('Arial', 'B', $font_size);
            $this->SetTextColor(0, 0, 0); // Black
            
            // Add numbering or bullet
            if ($level == 1) {
                $this->Cell(0, 10, $title, 0, 1, 'L');
                
                // Underline
                $this->SetDrawColor(0, 0, 0); // Black
                $this->SetLineWidth(0.5);
                $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 170, $this->GetY());
            } else {
                $this->Cell(0, 8, '- ' . $title, 0, 1, 'L');
            }
            
            $this->Ln($level == 1 ? 8 : 5);
        }
        
        public function CreateRatingBar($rating, $max_rating = 10, $width = 50) {
            if ($rating === 'N/A' || $rating === null) {
                $this->SetFont('Arial', '', 8);
                $this->SetTextColor(100, 100, 100); // Gray
                $this->Cell($width, 6, 'N/A', 0, 0, 'C');
                return;
            }
            
            $rating_val = floatval($rating);
            $percentage = ($rating_val / $max_rating) * 100;
            
            // Bar background
            $this->SetFillColor(230, 230, 230); // Light gray
            $this->SetDrawColor(0, 0, 0); // Black
            $this->Rect($this->GetX(), $this->GetY() + 1, $width, 4, 'DF');
            
            // Bar fill - using grayscale
            if ($rating_val >= 8.0) {
                $this->SetFillColor(100, 100, 100); // Dark gray for high ratings
            } elseif ($rating_val >= 6.0) {
                $this->SetFillColor(150, 150, 150); // Medium gray
            } else {
                $this->SetFillColor(200, 200, 200); // Light gray for low ratings
            }
            
            $fill_width = ($width * $percentage) / 100;
            $this->Rect($this->GetX(), $this->GetY() + 1, $fill_width, 4, 'F');
            
            // Rating text
            $this->SetFont('Arial', 'B', 8);
            $this->SetTextColor(0, 0, 0);
            $this->Cell($width, 6, $rating . '/10', 0, 0, 'C');
        }
    }
    
    
    // Prepare filter information for title page
    $filters_info = [
        'academic_year' => !empty($report_data) ? $report_data[array_key_first($report_data)]['academic_year'] : 'Current',
        'department' => $department_id ? '' : 'All Departments', // Will be filled if specific department
        'semester' => $semester ?: '',
        'section' => $section ?: '',
        'faculty' => $faculty_id ? '' : '', // Will be filled if specific faculty
        'subject' => $subject_id ? '' : ''  // Will be filled if specific subject
    ];
    
    // Create new PDF document with filter info
    $pdf = new MYPDF($filters_info);
    
    // Set document properties
    $pdf->SetTitle('Class Committee Feedback Report - ' . date('Y-m-d'));
    $pdf->SetAuthor('Panimalar Engineering College - Feedback System');
    $pdf->SetSubject('Class Committee Feedback Analysis');
    $pdf->SetKeywords('feedback, class committee, report, analysis');
    
    // Set margins
    $pdf->SetMargins(15, 35, 15);
    
    // Enable page numbering
    $pdf->AliasNbPages();
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, 25);
    
    // Create title page
    $pdf->CreateTitlePage();
    
    // Create summary page if there's data
    if (!empty($report_data)) {
        $pdf->CreateSummaryPage($report_data);
        
        // Add detailed subject reports
        $subject_count = 0;
        foreach ($report_data as $assignment_id => $data) {
            $subject_count++;
            $pdf->AddPage();
            
            // Subject header with improved styling
            $pdf->SectionTitle('SUBJECT ' . $subject_count . ': ' . $data['subject_code'] . ' - ' . $data['subject_name']);
            
            // Subject information in a structured layout
            $y_start = $pdf->GetY();
            
            // Left column info
            $pdf->CreateInfoBox('Subject Code', $data['subject_code'], 20, $y_start, 80);
            $pdf->CreateInfoBox('Faculty Name', $data['faculty_name'], 20, $y_start + 10, 80);
            $pdf->CreateInfoBox('Department', $data['department_name'], 20, $y_start + 20, 80);
            
            // Right column info
            $pdf->CreateInfoBox('Semester/Section', $data['semester'] . ' / ' . $data['section'], 110, $y_start, 80);
            $pdf->CreateInfoBox('Academic Year', $data['academic_year'], 110, $y_start + 10, 80);
            $pdf->CreateInfoBox('Total Responses', $data['total_responses'], 110, $y_start + 20, 80);
            
            // Move below info boxes
            $pdf->SetY($y_start + 35);
            
            // Calculate overall statistics
            $total_rating = 0;
            $rating_count = 0;
            $high_ratings = 0; // >= 8
            $medium_ratings = 0; // 6-7.9
            $low_ratings = 0; // < 6
            
            foreach ($data['statements'] as $statement) {
                if ($statement['avg_rating'] !== 'N/A') {
                    $rating_val = floatval($statement['avg_rating']);
                    $total_rating += $rating_val;
                    $rating_count++;
                    
                    if ($rating_val >= 8.0) $high_ratings++;
                    elseif ($rating_val >= 6.0) $medium_ratings++;
                    else $low_ratings++;
                }
            }
            
            $overall_avg = $rating_count > 0 ? $total_rating / $rating_count : 0;
            
            // Overall performance box
            $pdf->Ln(5);
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->SetTextColor(0, 0, 0); // Black
            $pdf->Cell(0, 8, 'OVERALL PERFORMANCE SUMMARY', 0, 1, 'C');
            
            $pdf->SetDrawColor(0, 0, 0); // Black
            $pdf->SetFillColor(245, 245, 245); // Light gray
            $pdf->SetLineWidth(0.5);
            $pdf->Rect(20, $pdf->GetY(), 170, 25, 'DF');
            
            $pdf->SetXY(30, $pdf->GetY() + 5);
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Cell(50, 6, 'Overall Average Rating:', 0, 0, 'L');
            $pdf->SetFont('Arial', 'B', 12);
            
            // Use bold text instead of color coding for ratings
            $pdf->SetTextColor(0, 0, 0); // Black
            
            $pdf->Cell(30, 6, number_format($overall_avg, 2) . '/10', 0, 1, 'L');
            
            // Performance distribution
            $pdf->SetXY(30, $pdf->GetY());
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(0, 0, 0); // Black
            $pdf->Cell(170, 6, 'High Ratings (>=8): ' . $high_ratings . ' | Medium Ratings (6-7.9): ' . $medium_ratings . ' | Low Ratings (<6): ' . $low_ratings, 0, 1, 'L');
            
            $pdf->SetY($pdf->GetY() + 30);
            
            // Detailed ratings table with enhanced design
            $pdf->SectionTitle('DETAILED STATEMENT RATINGS', 2);
            
            // Enhanced table styling
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetFillColor(200, 200, 200); // Light gray
            $pdf->SetTextColor(0, 0, 0); // Black
            $pdf->SetDrawColor(0, 0, 0); // Black
            $pdf->SetLineWidth(0.3);
            
            // Table headers
            $pdf->Cell(15, 10, 'S.No', 1, 0, 'C', true);
            $pdf->Cell(120, 10, 'Statement', 1, 0, 'C', true);
            $pdf->Cell(25, 10, 'Avg Rating', 1, 0, 'C', true);
            $pdf->Cell(25, 10, 'Performance', 1, 1, 'C', true);
            
            // Table data with improved formatting
            $pdf->SetFont('Arial', '', 8);
            $pdf->SetTextColor(0, 0, 0);
            $row_count = 0;
            
            foreach ($data['statements'] as $number => $statement) {
                $row_count++;
                $fill_color = ($row_count % 2 == 0) ? [248, 249, 250] : [255, 255, 255];
                $pdf->SetFillColor($fill_color[0], $fill_color[1], $fill_color[2]);
                
                // Calculate row height based on statement length
                $statement_text = $statement['statement'];
                $estimated_lines = ceil(strlen($statement_text) / 70);
                $row_height = max(8, $estimated_lines * 4);
                
                // Statement number
                $pdf->Cell(15, $row_height, $number, 1, 0, 'C', true);
                
                // Statement text with proper wrapping
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->MultiCell(120, 4, $statement_text, 1, 'L', true);
                
                // Calculate actual height used
                $actual_height = $pdf->GetY() - $y;
                
                // Rating cell
                $pdf->SetXY($x + 120, $y);
                $pdf->SetFont('Arial', 'B', 9);
                
                // Use black text instead of color coding
                $pdf->SetTextColor(0, 0, 0); // Black
                
                $pdf->Cell(25, $actual_height, $statement['avg_rating'], 1, 0, 'C', true);
                
                // Performance indicator
                $pdf->SetFont('Arial', '', 8);
                $performance_text = 'N/A';
                $pdf->SetTextColor(0, 0, 0);
                
                if ($statement['avg_rating'] !== 'N/A') {
                    $rating_val = floatval($statement['avg_rating']);
                    if ($rating_val >= 8.0) {
                        $performance_text = 'Excellent';
                    } elseif ($rating_val >= 6.0) {
                        $performance_text = 'Good';
                    } else {
                        $performance_text = 'Needs Improvement';
                    }
                }
                
                $pdf->Cell(25, $actual_height, $performance_text, 1, 1, 'C', true);
                
                // Reset text color for next iteration
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Arial', '', 8);
            }
            
            // Comments section with enhanced design - start on new page
            $pdf->AddPage();
            $pdf->SectionTitle('STUDENT COMMENTS', 2);
            
            if (empty($data['comments'])) {
                $pdf->SetFont('Arial', 'I', 10);
                $pdf->SetTextColor(100, 100, 100); // Gray
                $pdf->SetFillColor(248, 248, 248); // Light gray
                $pdf->SetDrawColor(0, 0, 0); // Black
                $pdf->Rect(20, $pdf->GetY(), 170, 15, 'DF');
                $pdf->SetXY(25, $pdf->GetY() + 5);
                $pdf->Cell(0, 5, 'No comments were provided for this subject.', 0, 1, 'L');
                $pdf->Ln(10);
            } else {
                $pdf->SetFont('Arial', '', 9);
                $pdf->SetTextColor(0, 0, 0); // Black
                $pdf->Cell(0, 6, 'Total Comments: ' . count($data['comments']), 0, 1, 'L');
                $pdf->Ln(3);
                
                $comment_count = 0;
                foreach ($data['comments'] as $comment) {
                    $comment_count++;
                    
                    // Comment box
                    $pdf->SetDrawColor(0, 0, 0); // Black
                    $pdf->SetFillColor(250, 250, 250); // Very light gray
                    $pdf->SetLineWidth(0.2);
                    
                    // Calculate comment height
                    $comment_lines = ceil(strlen($comment) / 100);
                    $comment_height = max(12, $comment_lines * 4 + 4);
                    
                    $pdf->Rect(20, $pdf->GetY(), 170, $comment_height, 'DF');
                    
                    // Comment number
                    $pdf->SetXY(25, $pdf->GetY() + 2);
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->SetTextColor(0, 0, 0); // Black
                    $pdf->Cell(15, 4, 'Comment ' . $comment_count . ':', 0, 0, 'L');
                    
                    // Comment text
                    $pdf->SetX(45);
                    $pdf->SetFont('Arial', '', 9);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->MultiCell(140, 4, $comment, 0, 'L');
                    
                    // Move to next comment position
                    $pdf->SetY($pdf->GetY() + $comment_height - ($pdf->GetY() - ($pdf->GetY() - $comment_height)) + 3);
                    
                    // Check for page break - only if there are more comments
                    if ($pdf->GetY() > 250 && $comment_count < count($data['comments'])) {
                        $pdf->AddPage();
                    }
                }
            }
        }
    } else {
        // No data available page
        $pdf->AddPage();
        $pdf->SectionTitle('NO DATA AVAILABLE');
        $pdf->SetFont('Arial', '', 12);
        $pdf->SetTextColor(100, 100, 100); // Gray
        $pdf->Cell(0, 10, 'No feedback data found for the selected criteria.', 0, 1, 'C');
        $pdf->Ln(5);
        $pdf->Cell(0, 8, 'Please check your filter settings and try again.', 0, 1, 'C');
    }
    
    // Output the PDF with proper headers
    $filename = 'class_committee_report_' . date('Y-m-d_H-i-s') . '.pdf';
    
    // Set headers for proper character encoding
    header('Content-Type: application/pdf; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    
    $pdf->Output('I', $filename);
    exit;
}
?> 