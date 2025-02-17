<?php
// Add these lines at the very top of the file
ob_start();
error_reporting(E_ALL); // Enable all error reporting for debugging
ini_set('display_errors', 1); // Show errors for debugging

session_start();
require_once 'db_connection.php';
require_once 'fpdf.php';
require_once 'vendor/autoload.php';
 // For PhpSpreadsheet

// Check if user is logged in and is HOD
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hod') {
    header("Location: login.php");
    exit();
}

// Get export format from URL and sanitize inputs
$format = $_GET['format'] ?? 'pdf';
$degree = isset($_GET['degree']) ? urldecode($_GET['degree']) : null;
$batch_id = isset($_GET['batch_id']) ? urldecode($_GET['batch_id']) : null;

// Debug log function
function debug_log($message, $data = null) {
    $log_file = 'export_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";
    if ($data !== null) {
        $log_message .= ": " . print_r($data, true);
    }
    file_put_contents($log_file, $log_message . "\n", FILE_APPEND);
}

// Enhanced function to get overall stats with more details
function getDetailedStats($conn, $degree = null, $batch_id = null) {
    debug_log("Starting getDetailedStats", ['degree' => $degree, 'batch_id' => $batch_id]);
    
    $stats = [];
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "degree = ?";
        $params[] = $degree;
        $types .= 's';
        debug_log("Added degree filter", $degree);
    }
    if ($batch_id && $batch_id !== '' && $batch_id !== 'All Batches') {
        $where_clauses[] = "passing_year = ?";
        $params[] = $batch_id;
        $types .= 's';
        debug_log("Added batch filter", $batch_id);
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    debug_log("WHERE clause", $where_sql);
    
    // Total responses
    $query = "SELECT COUNT(*) as total FROM alumni_survey" . $where_sql;
    debug_log("Total responses query", $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        debug_log("Bound parameters", $params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        debug_log("Query execution error", mysqli_error($conn));
        return $stats;
    }
    
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    $stats['total_responses'] = $row['total'];
    debug_log("Total responses", $stats['total_responses']);
    
    // Gender distribution with error handling
    $query = "SELECT COALESCE(gender, 'Not Specified') as gender, COUNT(*) as count 
              FROM alumni_survey" . $where_sql . " 
              GROUP BY gender";
    debug_log("Gender distribution query", $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        debug_log("Gender query execution error", mysqli_error($conn));
    } else {
        $result = mysqli_stmt_get_result($stmt);
        $stats['gender_distribution'] = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['gender_distribution'][$row['gender']] = $row['count'];
        }
        debug_log("Gender distribution", $stats['gender_distribution']);
    }
    
    // Employment status distribution with error handling
    $query = "SELECT 
                COALESCE(present_status, 'Not Specified') as present_status, 
                COUNT(*) as count,
                GROUP_CONCAT(DISTINCT NULLIF(company_name, '')) as companies,
                GROUP_CONCAT(DISTINCT NULLIF(designation, '')) as designations
              FROM alumni_survey" . $where_sql . " 
              GROUP BY present_status";
    debug_log("Employment status query", $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        debug_log("Employment query execution error", mysqli_error($conn));
    } else {
        $result = mysqli_stmt_get_result($stmt);
        $stats['employment_details'] = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['employment_details'][$row['present_status']] = [
                'count' => $row['count'],
                'companies' => array_filter(explode(',', $row['companies'] ?? '')),
                'designations' => array_filter(explode(',', $row['designations'] ?? ''))
            ];
        }
        debug_log("Employment details", $stats['employment_details']);
    }
    
    // Higher education details with error handling
    $base_where = "course1_name IS NOT NULL AND course1_name != ''";
    $final_where = $where_sql ? $where_sql . " AND " . $base_where : " WHERE " . $base_where;
    
    $query = "SELECT 
                COALESCE(course1_name, 'Not Specified') as course_name,
                COUNT(*) as count,
                GROUP_CONCAT(DISTINCT NULLIF(course1_institution, '')) as institutions
              FROM alumni_survey" . $final_where . "
              GROUP BY course1_name";
    debug_log("Higher education query", $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        debug_log("Higher education query execution error", mysqli_error($conn));
    } else {
        $result = mysqli_stmt_get_result($stmt);
        $stats['higher_education'] = [];
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['higher_education'][$row['course_name']] = [
                'count' => $row['count'],
                'institutions' => array_filter(explode(',', $row['institutions'] ?? ''))
            ];
        }
        debug_log("Higher education details", $stats['higher_education']);
    }
    
    // Industry/Company distribution
    $company_condition = "company_name IS NOT NULL AND company_name != ''";
    $query = "SELECT 
                COALESCE(business_nature, 'Other') as industry_sector,
                company_name,
                COUNT(*) as count
              FROM alumni_survey" . 
              ($where_sql ? $where_sql . " AND " . $company_condition : " WHERE " . $company_condition) . 
              " GROUP BY business_nature, company_name";
    debug_log("Industry distribution query", $query);
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    
    if (!mysqli_stmt_execute($stmt)) {
        debug_log("Industry query execution error", mysqli_error($conn));
        $stats['industry_distribution'] = [];
    } else {
        $result = mysqli_stmt_get_result($stmt);
        $stats['industry_distribution'] = [];
        while ($row = mysqli_fetch_assoc($result)) {
            if (!isset($stats['industry_distribution'][$row['industry_sector']])) {
                $stats['industry_distribution'][$row['industry_sector']] = [];
            }
            if ($row['company_name']) {
                $stats['industry_distribution'][$row['industry_sector']][$row['company_name']] = $row['count'];
            }
        }
        debug_log("Industry distribution", $stats['industry_distribution']);
    }
    
    return $stats;
}

function getAttainmentAnalysis($conn, $degree = null, $batch_id = null) {
    $attainment = [
        'po' => [],
        'peo' => [],
        'pso' => []
    ];
    
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "a.degree = ?";
        $params[] = $degree;
        $types .= 's';
    }
    if ($batch_id && $batch_id !== '' && $batch_id !== 'All Batches') {
        $where_clauses[] = "a.passing_year = ?";
        $params[] = $batch_id;
        $types .= 's';
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    
    // PO Attainment
    $query = "SELECT po_number, AVG(rating) as avg_rating 
              FROM alumni_po_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY po_number ORDER BY po_number";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $result->num_rows > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attainment['po'][$row['po_number']] = round($row['avg_rating'], 2);
        }
    } else {
        for ($i = 1; $i <= 12; $i++) {
            $attainment['po'][$i] = 0.00;
        }
    }
    
    // PEO Attainment
    $query = "SELECT peo_number, AVG(rating) as avg_rating 
              FROM alumni_peo_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY peo_number ORDER BY peo_number";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $result->num_rows > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attainment['peo'][$row['peo_number']] = round($row['avg_rating'], 2);
        }
    } else {
        for ($i = 1; $i <= 5; $i++) {
            $attainment['peo'][$i] = 0.00;
        }
    }
    
    // PSO Attainment
    $query = "SELECT pso_number, AVG(rating) as avg_rating 
              FROM alumni_pso_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY pso_number ORDER BY pso_number";
    
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && $result->num_rows > 0) {
        while ($row = mysqli_fetch_assoc($result)) {
            $attainment['pso'][$row['pso_number']] = round($row['avg_rating'], 2);
        }
    } else {
        for ($i = 1; $i <= 3; $i++) {
            $attainment['pso'][$i] = 0.00;
        }
    }
    
    return $attainment;
}

// Custom PDF class with header and footer
class PDF extends FPDF {
    protected $col = 0;
    protected $y0;
    protected $departmentName = '';

    function setDepartmentName($name) {
        $this->departmentName = $name;
    }

    function Header() {
        // Logo
        if (file_exists('college_logo.png')) {
            $this->Image('college_logo.png', 20, 10, 25);
        }
        
        // College Name and Details
        $this->SetFont('Arial', 'B', 18);
        $this->SetTextColor(0, 51, 102); // Dark blue color
        $this->Cell(24); // Space for logo
        $this->Cell(165, 13, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'C');
        
        // College Status
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0);
        $this->Cell(20); // Add space from left
        $this->Cell(0, 5, 'An Autonomous Institution, Affiliated to Anna University, Chennai', 0, 1, 'C');
        
        // College Address
        $this->SetFont('Arial', '', 9);
        $this->Cell(20); // Add space from left
        $this->Cell(0, 5, 'Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai - 600 123', 0, 1, 'C');
        
        // Add some extra space before the line
        $this->Ln(7);
        
        // First decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(4);

        // Report Title
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0, 51, 102);
        $this->Cell(0, 8, 'Alumni Survey Analytics Report', 0, 1, 'C');

        // Department Name
        if ($this->departmentName) {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0);
            $this->Cell(0, 6, $this->departmentName, 0, 1, 'C');
        }
        
        // Second decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(8);
    }

    function Footer() {
        $this->SetY(-20);
        
        // Subtle line above footer
        $this->SetDrawColor(189, 195, 199);
        $this->Line(15, $this->GetY(), 195, $this->GetY());
        
        // Footer text with Helvetica
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(127, 140, 141);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'L');
        $this->Cell(0, 10, 'Generated on: ' . date('F j, Y'), 0, 0, 'R');
    }

    function SectionTitle($title) {
        $this->Ln(8);
        
        // Modern section title design
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 10, $title, 0, 1, 'L');
        
        // Subtle underline
        $this->SetDrawColor(52, 152, 219);
        $this->SetLineWidth(0.4);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + 190, $this->GetY());
        
        $this->Ln(5);
    }

    function CreateInfoBox($label, $value) {
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(52, 73, 94);
        $this->Cell(60, 8, $label . ':', 0, 0, 'L');
        
        $this->SetFont('Helvetica', '', 11);
        $this->SetTextColor(44, 62, 80);
        $this->Cell(0, 8, $value, 0, 1, 'L');
    }

    function CreateMetricsTable($headers, $data) {
        // Modern table design
        $this->SetFont('Helvetica', 'B', 10);
        $this->SetFillColor(245, 247, 250);
        $this->SetTextColor(44, 62, 80);
        $this->SetDrawColor(189, 195, 199);
        $this->SetLineWidth(0.2);

        // Header
        $w = array_values($headers);
        $columns = array_keys($headers);
        foreach($columns as $i => $column) {
            $this->Cell($w[$i], 10, $column, 1, 0, 'C', true);
        }
        $this->Ln();

        // Data rows with alternating background
        $this->SetFont('Helvetica', '', 10);
        $fill = false;

        foreach($data as $row) {
            $this->SetFillColor($fill ? 252 : 248, $fill ? 252 : 249, $fill ? 252 : 250);
            
            // Color coding for ratings
            if (isset($row['Rating'])) {
                $rating = floatval($row['Rating']);
                if ($rating >= 4.5) {
                    $this->SetTextColor(39, 174, 96);
                } elseif ($rating >= 4.0) {
                    $this->SetTextColor(41, 128, 185);
                } elseif ($rating >= 3.5) {
                    $this->SetTextColor(243, 156, 18);
                } elseif ($rating >= 3.0) {
                    $this->SetTextColor(230, 126, 34);
                } else {
                    $this->SetTextColor(192, 57, 43);
                }
            }

            foreach($columns as $i => $column) {
                $this->Cell($w[$i], 8, $row[$column], 1, 0, 'C', $fill);
            }
            $this->Ln();
            $fill = !$fill;
            $this->SetTextColor(44, 62, 80);
        }
    }

    function AddChart($title, $data, $labels) {
        $this->SetFont('Helvetica', 'B', 12);
        $this->Cell(0, 8, $title, 0, 1, 'C');
        
        // More compact chart dimensions
        $chartHeight = 60;
        $chartWidth = 170;
        $barWidth = min(20, $chartWidth / count($data));
        $startX = 25;
        $startY = $this->GetY() + $chartHeight;
        
        // Draw Y-axis with scale markers (0 to 5)
        $this->SetFont('Helvetica', '', 8);
        $this->SetDrawColor(189, 195, 199);
        for($i = 0; $i <= 5; $i++) {
            $y = $startY - ($i * $chartHeight/5);
            $this->Line($startX-2, $y, $startX+$chartWidth, $y);
            $this->SetXY($startX-10, $y-2);
            $this->Cell(8, 4, $i, 0, 0, 'R');
        }
        
        // Draw bars with improved styling
        $x = $startX + 10;
        foreach($data as $i => $value) {
            $barHeight = ($value * $chartHeight/5);
            
            // Color gradient based on rating
            if ($value >= 4.5) $this->SetFillColor(46, 204, 113);
            elseif ($value >= 4.0) $this->SetFillColor(52, 152, 219);
            elseif ($value >= 3.5) $this->SetFillColor(241, 196, 15);
            elseif ($value >= 3.0) $this->SetFillColor(230, 126, 34);
            else $this->SetFillColor(231, 76, 60);
            
            $this->RoundedRect($x, $startY - $barHeight, $barWidth-4, $barHeight, 2, 'F');
            
            $this->SetFont('Helvetica', 'B', 8);
            $this->SetTextColor(44, 62, 80);
            $this->SetXY($x, $startY - $barHeight - 5);
            $this->Cell($barWidth-4, 5, number_format($value, 1), 0, 0, 'C');
            
            $this->SetFont('Helvetica', '', 8);
            $this->SetXY($x, $startY + 2);
            $label = $this->truncateText($labels[$i], $barWidth-4);
            $this->Cell($barWidth-4, 5, $label, 0, 0, 'C');
            
            $x += $barWidth;
        }
        
        $this->Ln($chartHeight + 15);
    }

    // Helper function to truncate long labels
    function truncateText($text, $width) {
        if($this->GetStringWidth($text) > $width) {
            while($this->GetStringWidth($text . '...') > $width) {
                $text = substr($text, 0, -1);
            }
            return $text . '...';
        }
        return $text;
    }

    function RoundedRect($x, $y, $w, $h, $r, $style = '') {
        $k = $this->k;
        $hp = $this->h;
        if($style=='F')
            $op='f';
        elseif($style=='FD' || $style=='DF')
            $op='B';
        else
            $op='S';
        $MyArc = 4/3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m',($x+$r)*$k,($hp-$y)*$k ));
        $xc = $x+$w-$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l', $xc*$k,($hp-$y)*$k ));

        $this->_Arc($xc + $r*$MyArc, $yc - $r, $xc + $r, $yc - $r*$MyArc, $xc + $r, $yc);
        $xc = $x+$w-$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',($x+$w)*$k,($hp-$yc)*$k));
        $this->_Arc($xc + $r*$MyArc, $yc + $r, $xc + $r*$MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x+$r ;
        $yc = $y+$h-$r;
        $this->_out(sprintf('%.2F %.2F l',$xc*$k,($hp-($y+$h))*$k));
        $this->_Arc($xc - $r*$MyArc, $yc + $r, $xc - $r, $yc + $r*$MyArc, $xc - $r, $yc);
        $xc = $x+$r ;
        $yc = $y+$r;
        $this->_out(sprintf('%.2F %.2F l',($x)*$k,($hp-$yc)*$k ));
        $this->_Arc($xc - $r, $yc - $r*$MyArc, $xc - $r*$MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3) {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', 
            $x1*$this->k, ($h-$y1)*$this->k,
            $x2*$this->k, ($h-$y2)*$this->k,
            $x3*$this->k, ($h-$y3)*$this->k));
    }
}

// Get the data with filters
$detailed_stats = getDetailedStats($conn, $degree, $batch_id);
debug_log("Final detailed stats", $detailed_stats);

$attainment_analysis = getAttainmentAnalysis($conn, $degree, $batch_id);
debug_log("Final attainment analysis", $attainment_analysis);

// Clear any existing output and turn off warnings for PDF generation
if (ob_get_length()) ob_end_clean();
error_reporting(0);

if ($format === 'pdf') {
    try {
        // Create new PDF document
        $pdf = new PDF();
        $pdf->AliasNbPages();
        
        // Set department name based on degree
        $department = $degree ?? 'All Departments';
        if ($batch_id) {
            $department .= " - Batch " . $batch_id;
        }
        
        $pdf->setDepartmentName($department);
        $pdf->AddPage();

        // Overall Statistics Section
        $pdf->SectionTitle('Overall Statistics');
        $pdf->CreateInfoBox('Total Responses', $detailed_stats['total_responses'] ?? 0);
        
        // Gender Distribution
        $pdf->Ln(5);
        $pdf->SectionTitle('Gender Distribution');
        $gender_data = [];
        if (!empty($detailed_stats['gender_distribution'])) {
            foreach ($detailed_stats['gender_distribution'] as $gender => $count) {
                $gender_data[] = array(
                    'Gender' => ucfirst($gender),
                    'Count' => $count,
                    'Percentage' => round(($count / ($detailed_stats['total_responses'] ?? 1)) * 100, 1) . '%'
                );
            }
        }
        $headers = array(
            'Gender' => 60,
            'Count' => 40,
            'Percentage' => 80
        );
        $pdf->CreateMetricsTable($headers, $gender_data);

        // Employment Status Distribution
        $pdf->AddPage();
        $pdf->SectionTitle('Employment Status Distribution');
        $employment_data = [];
        if (!empty($detailed_stats['employment_details'])) {
            foreach ($detailed_stats['employment_details'] as $status => $details) {
                $employment_data[] = array(
                    'Status' => ucfirst($status),
                    'Count' => $details['count'],
                    'Percentage' => round(($details['count'] / ($detailed_stats['total_responses'] ?? 1)) * 100, 1) . '%',
                    'Top Companies' => implode(', ', array_slice($details['companies'] ?? [], 0, 3))
                );
            }
        }
        $headers = array(
            'Status' => 40,
            'Count' => 30,
            'Percentage' => 30,
            'Top Companies' => 90
        );
        $pdf->CreateMetricsTable($headers, $employment_data);

        // Industry Distribution
        if (!empty($detailed_stats['industry_distribution'])) {
            $pdf->AddPage();
            $pdf->SectionTitle('Industry Distribution');
            $industry_data = [];
            foreach ($detailed_stats['industry_distribution'] as $sector => $companies) {
                $total_in_sector = array_sum($companies);
                $industry_data[] = array(
                    'Sector' => $sector,
                    'Companies' => count($companies),
                    'Employees' => $total_in_sector,
                    'Percentage' => round(($total_in_sector / ($detailed_stats['total_responses'] ?? 1)) * 100, 1) . '%'
                );
            }
            $headers = array(
                'Sector' => 60,
                'Companies' => 40,
                'Employees' => 40,
                'Percentage' => 50
            );
            $pdf->CreateMetricsTable($headers, $industry_data);
        }

        // Output the PDF
        $pdf->Output('D', 'alumni_survey_analytics.pdf');
    } catch (Exception $e) {
        debug_log("PDF Generation Error", $e->getMessage());
        echo "Error generating PDF: " . $e->getMessage();
    }
} else if ($format === 'excel') {
    // Set headers for Excel download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="alumni_survey_analytics.xlsx"');
    header('Cache-Control: max-age=0');
    
    // Create new Spreadsheet object
    $spreadsheet = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator('Panimalar Engineering College')
        ->setLastModifiedBy('AI&DS Department')
        ->setTitle('Detailed Alumni Survey Analytics Report')
        ->setSubject('Alumni Survey Analytics')
        ->setDescription('Comprehensive export of alumni survey analytics data');

    // Overview Sheet
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Overview');
    
    // Title
    $sheet->setCellValue('A1', 'Alumni Survey Analytics - Comprehensive Report');
    $sheet->mergeCells('A1:E1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    
    // Basic Statistics
    $sheet->setCellValue('A3', 'Basic Statistics');
    $sheet->getStyle('A3')->getFont()->setBold(true);
    
    $sheet->setCellValue('A4', 'Total Responses:');
    $sheet->setCellValue('B4', $detailed_stats['total_responses']);
    
    // Gender Distribution
    $sheet->setCellValue('A6', 'Gender Distribution');
    $sheet->getStyle('A6')->getFont()->setBold(true);
    $row = 7;
    foreach ($detailed_stats['gender_distribution'] as $gender => $count) {
        $sheet->setCellValue('A' . $row, ucfirst($gender));
        $sheet->setCellValue('B' . $row, $count);
        $sheet->setCellValue('C' . $row, round(($count / $detailed_stats['total_responses']) * 100, 1) . '%');
        $row++;
    }

    // Employment Details Sheet
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Employment Details');
    
    $sheet->setCellValue('A1', 'Employment Status Analysis');
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    
    // Headers
    $sheet->setCellValue('A3', 'Status');
    $sheet->setCellValue('B3', 'Count');
    $sheet->setCellValue('C3', 'Percentage');
    $sheet->setCellValue('D3', 'Top Companies/Designations');
    $sheet->getStyle('A3:D3')->getFont()->setBold(true);
    
    $row = 4;
    foreach ($detailed_stats['employment_details'] as $status => $details) {
        $sheet->setCellValue('A' . $row, ucfirst($status));
        $sheet->setCellValue('B' . $row, $details['count']);
        $sheet->setCellValue('C' . $row, round(($details['count'] / $detailed_stats['total_responses']) * 100, 1) . '%');
        $top_companies = array_slice($details['companies'], 0, 3);
        $top_designations = array_slice($details['designations'], 0, 3);
        $sheet->setCellValue('D' . $row, 'Companies: ' . implode(', ', $top_companies) . 
                                       "\nDesignations: " . implode(', ', $top_designations));
        $row++;
    }

    // Industry Distribution Sheet
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Industry Analysis');
    
    $sheet->setCellValue('A1', 'Industry Sector Distribution');
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    
    $sheet->setCellValue('A3', 'Industry Sector');
    $sheet->setCellValue('B3', 'Company');
    $sheet->setCellValue('C3', 'Count');
    $sheet->setCellValue('D3', 'Percentage');
    $sheet->getStyle('A3:D3')->getFont()->setBold(true);
    
    $row = 4;
    foreach ($detailed_stats['industry_distribution'] as $sector => $companies) {
        $first_company = true;
        foreach ($companies as $company => $count) {
            if ($first_company) {
                $sheet->setCellValue('A' . $row, $sector);
                $first_company = false;
            }
            $sheet->setCellValue('B' . $row, $company);
            $sheet->setCellValue('C' . $row, $count);
            $sheet->setCellValue('D' . $row, round(($count / $detailed_stats['total_responses']) * 100, 1) . '%');
            $row++;
        }
    }

    // Higher Education Sheet
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Higher Education');
    
    $sheet->setCellValue('A1', 'Higher Education Analysis');
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    
    $sheet->setCellValue('A3', 'Field of Study');
    $sheet->setCellValue('B3', 'Number of Students');
    $sheet->setCellValue('C3', 'Percentage');
    $sheet->setCellValue('D3', 'Top Institutions');
    $sheet->getStyle('A3:D3')->getFont()->setBold(true);
    
    $row = 4;
    foreach ($detailed_stats['higher_education'] as $field => $details) {
        $sheet->setCellValue('A' . $row, $field);
        $sheet->setCellValue('B' . $row, $details['count']);
        $sheet->setCellValue('C' . $row, round(($details['count'] / $detailed_stats['total_responses']) * 100, 1) . '%');
        $sheet->setCellValue('D' . $row, implode(", ", array_slice($details['institutions'], 0, 3)));
        $row++;
    }

    // Attainment Analysis Sheet
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Attainment Analysis');
    
    // Title and PO Section
    $sheet->setCellValue('A1', 'Program Outcomes (PO) Attainment');
    $sheet->mergeCells('A1:D1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
    
    // PO Headers
    $sheet->setCellValue('A3', 'PO Number');
    $sheet->setCellValue('B3', 'Description');
    $sheet->setCellValue('C3', 'Average Rating');
    $sheet->setCellValue('D3', 'Attainment Level');
    $sheet->getStyle('A3:D3')->getFont()->setBold(true);
    
    // PO Descriptions
    $po_descriptions = [
        1 => 'Apply the knowledge of mathematics, science, engineering fundamentals, and an engineering specialization to the solution of complex engineering problems.',
        2 => 'Identify, formulate, research literature, and analyze complex engineering problems reaching substantiated conclusions using first principles of mathematics, natural sciences, and engineering sciences.',
        3 => 'Design solutions for complex engineering problems and design system components or processes that meet the specified needs with appropriate consideration for the public health and safety and the cultural, societal, and environmental considerations.',
        4 => 'Use research-based knowledge and research methods including design of experiments, analysis and interpretation of data, and synthesis of the information.',
        5 => 'Create, select, and apply appropriate techniques, resources, and modern engineering and IT tools including prediction and modeling to complex engineering activities with an understanding of the limitations.',
        6 => 'Apply reasoning informed by the contextual knowledge to assess societal, health, safety, legal and cultural issues and the consequent responsibilities relevant to the professional engineering practice.',
        7 => 'Understand the impact of the professional engineering solutions in societal and environmental contexts, and demonstrate the knowledge of need for sustainable development.',
        8 => 'Apply ethical principles and commit to professional ethics and responsibilities and norms of the engineering practice.',
        9 => 'Function effectively as an individual, and as a member or leader in diverse teams, and in multidisciplinary settings.',
        10 => 'Communicate effectively on complex engineering activities with the engineering community and with society at large.',
        11 => "Demonstrate knowledge and understanding of the engineering and management principles and apply these to one's own work, as a member and leader in a team.",
        12 => 'Recognize the need for, and have the preparation and ability to engage in independent and lifelong learning in the broadest context of technological change.'
    ];
    
    $row = 4;
    $po_total = 0;
    foreach ($attainment_analysis['po'] as $po_number => $rating) {
        $sheet->setCellValue('A' . $row, 'PO' . $po_number);
        $sheet->setCellValue('B' . $row, $po_descriptions[$po_number] ?? '');
        $sheet->setCellValue('C' . $row, $rating);
        $sheet->setCellValue('D' . $row, getAttainmentLevel($rating));
        $po_total += $rating;
        $row++;
    }
    
    // PO Average
    $po_avg = count($attainment_analysis['po']) > 0 ? round($po_total / count($attainment_analysis['po']), 2) : 0;
    $sheet->setCellValue('A' . $row, 'Overall PO Average');
    $sheet->setCellValue('C' . $row, $po_avg);
    $sheet->setCellValue('D' . $row, getAttainmentLevel($po_avg));
    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
    
    // PEO Section
    $row += 3;
    $sheet->setCellValue('A' . $row, 'Program Educational Objectives (PEO) Attainment');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
    
    // PEO Headers
    $row += 2;
    $sheet->setCellValue('A' . $row, 'PEO Number');
    $sheet->setCellValue('B' . $row, 'Description');
    $sheet->setCellValue('C' . $row, 'Average Rating');
    $sheet->setCellValue('D' . $row, 'Attainment Level');
    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
    
    // PEO Descriptions
    $peo_descriptions = [
        1 => 'To provide graduates with the proficiency to utilize the fundamental knowledge of Basic Sciences, mathematics and statistics to build systems that require management and analysis of large volume of data.',
        2 => 'To inculcate the students to focus on augmenting the knowledge to improve the performance for the AI era and also to serve the analytical and data-centric needs of a modern workforce.',
        3 => 'To enable graduates to illustrate the core AI and Data Science technologies, applying them in ways that optimize human-machine partnerships and providing the tools and skills to understand their societal impact for product development.',
        4 => 'To enrich the students with necessary technical skills to foster interdisciplinary research and development to move the community in an interesting direction in the field of AI and Data Science.',
        5 => 'To enable graduates to think logically, pursue lifelong learning and collaborate with an ethical attitude to become an entrepreneur.'
    ];
    
    $row++;
    $peo_total = 0;
    foreach ($attainment_analysis['peo'] as $peo_number => $rating) {
        $sheet->setCellValue('A' . $row, 'PEO' . $peo_number);
        $sheet->setCellValue('B' . $row, $peo_descriptions[$peo_number] ?? '');
        $sheet->setCellValue('C' . $row, $rating);
        $sheet->setCellValue('D' . $row, getAttainmentLevel($rating));
        $peo_total += $rating;
        $row++;
    }
    
    // PEO Average
    $peo_avg = count($attainment_analysis['peo']) > 0 ? round($peo_total / count($attainment_analysis['peo']), 2) : 0;
    $sheet->setCellValue('A' . $row, 'Overall PEO Average');
    $sheet->setCellValue('C' . $row, $peo_avg);
    $sheet->setCellValue('D' . $row, getAttainmentLevel($peo_avg));
    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
    
    // PSO Section
    $row += 3;
    $sheet->setCellValue('A' . $row, 'Program Specific Outcomes (PSO) Attainment');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
    
    // PSO Headers
    $row += 2;
    $sheet->setCellValue('A' . $row, 'PSO Number');
    $sheet->setCellValue('B' . $row, 'Description');
    $sheet->setCellValue('C' . $row, 'Average Rating');
    $sheet->setCellValue('D' . $row, 'Attainment Level');
    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
    
    // PSO Descriptions
    $pso_descriptions = [
        1 => 'Graduates should be able to evolve AI based efficient domain specific processes for effective decision making in several domains such as business and governance domains.',
        2 => 'Graduates should be able to arrive at actionable Fore sight, Insight, hind sight from data for solving business and engineering problems.',
        3 => 'Graduates should be able to create, select and apply the theoretical knowledge of AI and Data Analytics along with practical industrial tools and techniques to manage and solve wicked societal problems.'
    ];
    
    $row++;
    $pso_total = 0;
    foreach ($attainment_analysis['pso'] as $pso_number => $rating) {
        $sheet->setCellValue('A' . $row, 'PSO' . $pso_number);
        $sheet->setCellValue('B' . $row, $pso_descriptions[$pso_number] ?? '');
        $sheet->setCellValue('C' . $row, $rating);
        $sheet->setCellValue('D' . $row, getAttainmentLevel($rating));
        $pso_total += $rating;
        $row++;
    }
    
    // PSO Average
    $pso_avg = count($attainment_analysis['pso']) > 0 ? round($pso_total / count($attainment_analysis['pso']), 2) : 0;
    $sheet->setCellValue('A' . $row, 'Overall PSO Average');
    $sheet->setCellValue('C' . $row, $pso_avg);
    $sheet->setCellValue('D' . $row, getAttainmentLevel($pso_avg));
    $sheet->getStyle('A'.$row.':D'.$row)->getFont()->setBold(true);
    
    // Overall Summary Section
    $row += 3;
    $sheet->setCellValue('A' . $row, 'Overall Attainment Summary');
    $sheet->mergeCells('A'.$row.':D'.$row);
    $sheet->getStyle('A'.$row)->getFont()->setBold(true)->setSize(12);
    
    $row += 2;
    $sheet->setCellValue('A' . $row, 'Category');
    $sheet->setCellValue('B' . $row, 'Average Rating');
    $sheet->setCellValue('C' . $row, 'Attainment Level');
    $sheet->getStyle('A'.$row.':C'.$row)->getFont()->setBold(true);
    
    $row++;
    $sheet->setCellValue('A' . $row, 'Program Outcomes (PO)');
    $sheet->setCellValue('B' . $row, $po_avg);
    $sheet->setCellValue('C' . $row, getAttainmentLevel($po_avg));
    
    $row++;
    $sheet->setCellValue('A' . $row, 'Program Educational Objectives (PEO)');
    $sheet->setCellValue('B' . $row, $peo_avg);
    $sheet->setCellValue('C' . $row, getAttainmentLevel($peo_avg));
    
    $row++;
    $sheet->setCellValue('A' . $row, 'Program Specific Outcomes (PSO)');
    $sheet->setCellValue('B' . $row, $pso_avg);
    $sheet->setCellValue('C' . $row, getAttainmentLevel($pso_avg));
    
    // Calculate overall average
    $overall_avg = round(($po_avg + $peo_avg + $pso_avg) / 3, 2);
    $row++;
    $sheet->setCellValue('A' . $row, 'Overall Average');
    $sheet->setCellValue('B' . $row, $overall_avg);
    $sheet->setCellValue('C' . $row, getAttainmentLevel($overall_avg));
    $sheet->getStyle('A'.$row.':C'.$row)->getFont()->setBold(true);
    
    // Adjust column widths and formatting
    $sheet->getColumnDimension('B')->setWidth(100);
    foreach (range('A', 'D') as $col) {
        if ($col !== 'B') {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
    
    // Add cell wrapping for description column
    $lastRow = $sheet->getHighestRow();
    $sheet->getStyle('B4:B'.$lastRow)->getAlignment()->setWrapText(true);
    
    // Format all sheets
    foreach ($spreadsheet->getAllSheets() as $sheet) {
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Add borders to used range
        $lastRow = $sheet->getHighestRow();
        $lastCol = $sheet->getHighestColumn();
        $sheet->getStyle('A1:' . $lastCol . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        
        // Add zebra striping
        for ($row = 1; $row <= $lastRow; $row++) {
            if ($row % 2 == 0) {
                $sheet->getStyle('A'.$row.':'.$lastCol.$row)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F3F3F3');
            }
        }
    }
    
    // Set active sheet to first sheet
    $spreadsheet->setActiveSheetIndex(0);
    
    // Create Excel writer
    $writer = new PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    
    // Write file to output
    $writer->save('php://output');
    exit;
}

// Helper function to determine attainment level
function getAttainmentLevel($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
}
?> 