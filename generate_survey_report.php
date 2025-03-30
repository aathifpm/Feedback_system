<?php
ob_start(); // Add output buffering to prevent "headers already sent" error
session_start();
require_once 'db_connection.php';
require_once 'functions.php';
require_once 'fpdf.php';

// Check authorization and required parameters
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hod', 'faculty'])) {
    header('Location: index.php');
    exit();
}

// Verify required parameters
if (!isset($_GET['department_id'])) {
    die("Error: Department is required.");
}

// For HODs, verify they can only access their department
if ($_SESSION['role'] === 'hod') {
    $hod_query = "SELECT department_id FROM hods WHERE id = ? AND is_active = TRUE";
    $stmt = mysqli_prepare($conn, $hod_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $hod_data = mysqli_fetch_assoc($result);
    
    if ($_GET['department_id'] != $hod_data['department_id']) {
        die("Error: Unauthorized access to department data.");
    }
}

class PDF extends FPDF {
    protected $departmentName = '';
    protected $batchInfo = '';

    function setReportInfo($dept, $batch) {
        $this->departmentName = $dept;
        $this->batchInfo = $batch;
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
        $this->Cell(0, 8, 'Exit Survey Analytics Report', 0, 1, 'C');

        // Department Name
        if ($this->departmentName) {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0);
            $this->Cell(0, 6, $this->departmentName, 0, 1, 'C');
        }
        
        // Batch Information
        if ($this->batchInfo) {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0);
            $this->Cell(0, 6, 'Batch: ' . $this->batchInfo, 0, 1, 'C');
        }
        
        // Second decorative line
        $this->SetDrawColor(0, 51, 102);
        $this->SetLineWidth(0.5);
        $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
        $this->Ln(8);
    }
    
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(0);
        $this->Cell(85, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'L');
        $this->Cell(85, 10, 'Generated on: ' . date('F j, Y'), 0, 0, 'R');
    }

    function ChapterTitle($title) {
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(0);
        // Remove box, just use bold text and underline
        $this->Cell(0, 10, $title, 0, 1, 'L');
        $this->SetLineWidth(0.4);
        $this->Line($this->GetX(), $this->GetY(), 190, $this->GetY());
        $this->Ln(4);
    }

    function SubTitle($title) {
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0);
        $this->Cell(0, 8, $title, 0, 1, 'L');
        $this->SetDrawColor(0);
        
        // Create dotted line manually
        $this->SetLineWidth(0.2);
        $lineWidth = 2; // Width of each dot
        $gap = 2;      // Gap between dots
        $startX = $this->GetX();
        $y = $this->GetY();
        
        for($x = $startX; $x < $startX + 170; $x += ($lineWidth + $gap)) {
            $this->Line($x, $y, $x + $lineWidth, $y);
        }
        
        $this->Ln(3);
    }

    function CreateInfoBox($label, $value, $width = 85) {
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0);
        // Simple bordered box with thicker border
        $this->SetLineWidth(0.2);
        $this->Cell($width, 8, $label . ': ' . $value, 1, 0, 'L');
    }

    function CreateMetricsTable($headers, $data, $widths) {
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0);
        $this->SetDrawColor(0);
        
        // Header with bold text, no fill
        $this->SetLineWidth(0.3);
        foreach($headers as $i => $header) {
            $this->Cell($widths[$i], 8, $header, 'B', 0, 'C');
        }
        $this->Ln();
        
        // Data rows with alternating patterns
        $this->SetFont('Arial', '', 9);
        $this->SetLineWidth(0.2);
        $fill = false;
        foreach($data as $row) {
            if ($fill) {
                $this->SetFillColor(245); // Very light gray for alternating rows
            } else {
                $this->SetFillColor(255); // White
            }
            foreach($row as $i => $value) {
                $align = $i == 0 ? 'L' : 'C';
                $this->Cell($widths[$i], 7, $value, 'B', 0, $align, true);
            }
            $this->Ln();
            $fill = !$fill;
        }
    }

    function CreateBarChart($data, $title, $maxWidth = 140) {
        $this->SubTitle($title);
        $this->SetFont('Arial', '', 9);
        $barHeight = 7;
        $gap = 2;
        $maxValue = max(array_values($data));
        
        foreach($data as $label => $value) {
            // Label
            $this->Cell(40, $barHeight, $label, 0, 0);
            
            // Bar outline
            $this->SetFillColor(255); // White background
            $this->Cell($maxWidth, $barHeight, '', 1, 0, 'L', true);
            
            // Bar fill
            if ($value > 0) {
                $barWidth = ($value / 100) * $maxWidth;
                $this->SetX($this->GetX() - $maxWidth);
                $this->SetFillColor(235); // Light gray for bars
                $this->Cell($barWidth, $barHeight, '', 1, 0, 'L', true);
            }
            
            // Percentage
            $this->SetX($this->GetX() + ($maxWidth - 20));
            $this->Cell(20, $barHeight, number_format($value, 1) . '%', 0, 1, 'R');
            $this->Ln($gap);
        }
        $this->Ln(3);
    }

    function AddRatingLegend() {
        $this->SetFont('Arial', 'I', 9);
        $this->SetTextColor(0);
        $this->SetLineWidth(0.2);
        $this->Cell(0, 5, 'Rating Scale: 1 = Poor, 2 = Fair, 3 = Good, 4 = Very Good, 5 = Excellent', 0, 1, 'L');
        $this->Line($this->GetX(), $this->GetY(), 190, $this->GetY());
        $this->Ln(4);
    }

    // Add pattern support for better B&W printing
    function SetFillPattern($style, $pattern = 'gray', $intensity = 0.2) {
        switch($pattern) {
            case 'gray':
                $this->SetFillColor(255 * (1 - $intensity));
                break;
            default:
                $this->SetFillColor(255);
        }
    }
}

// Get department name and batch info
$department_name = '';
$batch_info = '';

$dept_query = "SELECT d.name, 
               GROUP_CONCAT(DISTINCT by2.batch_name ORDER BY by2.batch_name) as batch_years
               FROM departments d
               LEFT JOIN students s ON d.id = s.department_id
               LEFT JOIN batch_years by2 ON s.batch_id = by2.id
               LEFT JOIN exit_surveys es ON s.id = es.student_id
               WHERE d.id = ?";

if (!empty($_GET['batch_id'])) {
    $dept_query .= " AND by2.id = ?";
}

$dept_query .= " GROUP BY d.id";

$stmt = mysqli_prepare($conn, $dept_query);

if (!empty($_GET['batch_id'])) {
    mysqli_stmt_bind_param($stmt, "ii", $_GET['department_id'], $_GET['batch_id']);
} else {
    mysqli_stmt_bind_param($stmt, "i", $_GET['department_id']);
}

mysqli_stmt_execute($stmt);
$dept_result = mysqli_stmt_get_result($stmt);
$dept_data = mysqli_fetch_assoc($dept_result);

if ($dept_data) {
    $department_name = "Department of " . $dept_data['name'];
    $batch_info = !empty($_GET['batch_id']) ? $dept_data['batch_years'] : "All Batches";
}

// Fetch survey data
$filters = [
    'department_id' => $_GET['department_id'] ?? null,
    'year' => $_GET['year'] ?? null
];

// Fetch survey data
$query = "SELECT 
    es.*,
    s.name as student_name,
    d.name as department_name,
    ay.year_range as academic_year
FROM exit_surveys es
JOIN students s ON es.student_id = s.id
JOIN departments d ON es.department_id = d.id
JOIN academic_years ay ON es.academic_year_id = ay.id
WHERE 1=1";

if (!empty($filters['department_id'])) {
    $query .= " AND es.department_id = " . intval($filters['department_id']);
}

$result = mysqli_query($conn, $query);
$surveys = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Initialize variables
$total_responses = count($surveys);
$employment_data = [];
$po_ratings = array_fill(1, 5, 0);
$pso_ratings = array_fill(1, 5, 0);
$salary_ranges = [
    '0-3' => 0,
    '3-6' => 0,
    '6-10' => 0,
    '10+' => 0
];
$top_companies = [];
$top_institutions = [];
$total_employed = 0;
$total_salary = 0;

// Add normalization functions
function normalizeCompanyName($name) {
    // Convert to lowercase and trim
    $name = strtolower(trim($name));
    
    // Common words to remove (expanded list)
    $common_words = [
        // Business entity types
        'pvt', 'private', 'ltd', 'limited', 'inc', 'incorporated', 'llp', 'llc',
        'corporation', 'corp', 'company', 'co',
        
        // Industry terms
        'technologies', 'technology', 'tech', 'solutions', 'services', 'systems',
        'software', 'consulting', 'consultancy', 'group', 'ventures', 'labs',
        'innovations', 'enterprises', 'global', 'international', 'worldwide',
        
        // Locations
        'india', 'usa', 'uk', 'chennai', 'bangalore', 'hyderabad', 'mumbai',
        'delhi', 'pune', 'kolkata', 'america', 'asia', 'europe',
        
        // Common words
        'and', 'the', 'of', '&'
    ];
    
    // Remove special characters and extra spaces
    $name = preg_replace('/[^a-z0-9\s]/', ' ', $name);
    
    // Split into words
    $words = explode(' ', $name);
    
    // Filter out common words and empty strings
    $words = array_filter($words, function($word) use ($common_words) {
        return !empty($word) && !in_array($word, $common_words);
    });
    
    // Join remaining words
    return trim(implode(' ', $words));
}

function findSimilarCompany($name, $companies) {
    $normalizedName = normalizeCompanyName($name);
    
    // If empty after normalization, return original
    if (empty($normalizedName)) {
        return $name;
    }
    
    // First try exact match after normalization
    foreach ($companies as $company => $data) {
        if (normalizeCompanyName($company) === $normalizedName) {
            return $company;
        }
    }
    
    // Then try partial match
    foreach ($companies as $company => $data) {
        $normalizedCompany = normalizeCompanyName($company);
        
        // Check if one is substring of another
        if (strpos($normalizedCompany, $normalizedName) !== false || 
            strpos($normalizedName, $normalizedCompany) !== false) {
            return $company;
        }
        
        // Calculate similarity
        similar_text($normalizedCompany, $normalizedName, $percent);
        if ($percent > 85) {
            return $company;
        }
        
        // Check for acronym match
        $nameAcronym = getAcronym($normalizedName);
        $companyAcronym = getAcronym($normalizedCompany);
        if (!empty($nameAcronym) && $nameAcronym === $companyAcronym) {
            return $company;
        }
    }
    
    return $name;
}

function getAcronym($string) {
    $words = explode(' ', $string);
    $acronym = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $acronym .= $word[0];
        }
    }
    return $acronym;
}

// Process survey data
foreach ($surveys as $survey) {
    $emp_data = json_decode($survey['employment_status'], true);
    if ($emp_data && isset($emp_data['status'])) {
        // Count employment status
        $status = $emp_data['status'];
        if (!isset($employment_data[$status])) {
            $employment_data[$status] = 0;
        }
        $employment_data[$status]++;
        
        if ($status === 'employed') {
            $total_employed++;
            
            // Process salary
            if (!empty($emp_data['starting_salary'])) {
                $salary = floatval($emp_data['starting_salary']);
                $total_salary += $salary;
                
                // Salary ranges
                if ($salary <= 3) $salary_ranges['0-3']++;
                elseif ($salary <= 6) $salary_ranges['3-6']++;
                elseif ($salary <= 10) $salary_ranges['6-10']++;
                else $salary_ranges['10+']++;
            }
            
            // Process companies with normalization
            if (!empty($emp_data['employer_details']['company'])) {
                $company = trim($emp_data['employer_details']['company']);
                if (!empty($company)) {
                    // Find or get similar company name
                    $standardCompany = findSimilarCompany($company, $top_companies);
                    if (!isset($top_companies[$standardCompany])) {
                        $top_companies[$standardCompany] = 0;
                    }
                    $top_companies[$standardCompany]++;
                }
            }
        } elseif ($status === 'higher_studies') {
            if (!empty($emp_data['higher_studies']['institution'])) {
                $institution = trim($emp_data['higher_studies']['institution']);
                if (!empty($institution)) {
                    // Find or get similar institution name
                    $standardInstitution = findSimilarCompany($institution, $top_institutions);
                    if (!isset($top_institutions[$standardInstitution])) {
                        $top_institutions[$standardInstitution] = 0;
                    }
                    $top_institutions[$standardInstitution]++;
                }
            }
        }
    }
    
    // Process PO ratings
    $po_data = json_decode($survey['po_ratings'], true);
    if (is_array($po_data)) {
        foreach ($po_data as $rating) {
            if (is_numeric($rating) && $rating >= 1 && $rating <= 5) {
                $po_ratings[$rating]++;
            }
        }
    }
    
    // Process PSO ratings
    $pso_data = json_decode($survey['pso_ratings'], true);
    if (is_array($pso_data)) {
        foreach ($pso_data as $rating) {
            if (is_numeric($rating) && $rating >= 1 && $rating <= 5) {
                $pso_ratings[$rating]++;
            }
        }
    }
}

// Calculate employment rate and average salary
$employment_rate = $total_responses > 0 ? ($total_employed / $total_responses) * 100 : 0;
$avg_salary = $total_employed > 0 ? $total_salary / $total_employed : 0;

// Sort and prepare company data
arsort($top_companies);
$top_companies = array_slice($top_companies, 0, 5, true);
$company_data = [];
foreach ($top_companies as $company => $count) {
    $percentage = ($count / $total_responses) * 100;
    $company_data[] = [
        $company,
        $count,
        number_format($percentage, 1) . '%',
        $count >= 3 ? 'Major Recruiter' : 'Regular Recruiter'
    ];
}

// Sort and prepare institution data
arsort($top_institutions);
$top_institutions = array_slice($top_institutions, 0, 5, true);
$institution_data = [];
foreach ($top_institutions as $institution => $count) {
    $percentage = ($count / $total_responses) * 100;
    $institution_data[] = [
        $institution,
        $count,
        number_format($percentage, 1) . '%',
        'Higher Education'
    ];
}

// Prepare employment status data
$employment_data_table = [];
foreach ($employment_data as $status => $count) {
    $percentage = ($count / $total_responses) * 100;
    $employment_data_table[] = [
        ucfirst($status),
        $count,
        number_format($percentage, 1) . '%',
        $percentage > 50 ? 'Good' : 'Needs Improvement'
    ];
}

// Create PDF
$pdf = new PDF();
$pdf->AliasNbPages();
$pdf->setReportInfo($department_name, $batch_info);

$pdf->AddPage();

// Executive Summary
$pdf->ChapterTitle('Executive Summary');
$pdf->Ln(2);

// Summary Statistics in a grid
$pdf->CreateInfoBox('Total Responses', $total_responses);
$pdf->CreateInfoBox('Employment Rate', number_format($employment_rate, 1) . '%');
$pdf->Ln();
$pdf->CreateInfoBox('Average Package', number_format($avg_salary, 2) . ' LPA');
$pdf->CreateInfoBox('Higher Studies', count($top_institutions) . ' Students');
$pdf->Ln(10);

// Employment Analysis
$pdf->ChapterTitle('Employment Analysis');
$pdf->SubTitle('Employment Status Distribution');
$employment_headers = ['Status', 'Count', 'Percentage', 'Remarks'];
$widths = [50, 30, 30, 80];
$pdf->CreateMetricsTable($employment_headers, $employment_data_table, $widths);
$pdf->Ln(8);

// Salary Distribution
$pdf->SubTitle('Salary Distribution');
$salary_data = [];
foreach ($salary_ranges as $range => $count) {
    $percentage = $total_responses > 0 ? ($count / $total_responses) * 100 : 0;
    $salary_data[$range . ' LPA'] = $percentage;
}
$pdf->CreateBarChart($salary_data, 'Salary Ranges');
$pdf->Ln(5);

// Top Recruiting Companies
if (!empty($top_companies)) {
    $pdf->AddPage();
    $pdf->ChapterTitle('Placement Analysis');
    $pdf->SubTitle('Top Recruiting Companies');
    $company_headers = ['Company Name', 'Students', 'Percentage', 'Status'];
    $widths = [80, 30, 30, 50];
    $pdf->CreateMetricsTable($company_headers, $company_data, $widths);
    $pdf->Ln(10);
}

// Higher Studies Analysis
if (!empty($top_institutions)) {
    $pdf->SubTitle('Higher Education Institutions');
    $institution_headers = ['Institution', 'Students', 'Percentage', 'Course Type'];
    $widths = [80, 30, 30, 50];
    $pdf->CreateMetricsTable($institution_headers, $institution_data, $widths);
    $pdf->Ln(10);
}

// Program Outcomes Analysis
$pdf->AddPage();
$pdf->ChapterTitle('Academic Outcomes Analysis');
$pdf->AddRatingLegend();

$pdf->SubTitle('Program Outcomes (PO) Analysis');
$po_data = [];
for ($i = 1; $i <= 5; $i++) {
    $po_data["Rating $i"] = $po_ratings[$i];
}
$pdf->CreateBarChart($po_data, 'PO Ratings Distribution');

// PSO Analysis
$pdf->SubTitle('Program Specific Outcomes (PSO) Analysis');
$pso_data = [];
for ($i = 1; $i <= 5; $i++) {
    $pso_data["Rating $i"] = $pso_ratings[$i];
}
$pdf->CreateBarChart($pso_data, 'PSO Ratings Distribution');

// Additional Analysis
if (!empty($program_satisfaction)) {
    $pdf->AddPage();
    $pdf->ChapterTitle('Satisfaction Analysis');
    
    // Program Satisfaction
    $pdf->SubTitle('Program Satisfaction Metrics');
    $satisfaction_data = [
        'Quality of Program' => $program_satisfaction[0] ?? 0,
        'Intellectual Enrichment' => $program_satisfaction[1] ?? 0,
        'Financial Support' => $program_satisfaction[2] ?? 0,
        'Guest Lectures' => $program_satisfaction[3] ?? 0,
        'Industry Exposure' => $program_satisfaction[4] ?? 0
    ];
    $pdf->CreateBarChart($satisfaction_data, 'Program Satisfaction Levels');
    
    // Infrastructure Satisfaction
    if (!empty($infrastructure_satisfaction)) {
        $pdf->SubTitle('Infrastructure Satisfaction');
        $infra_data = [
            'Classrooms' => $infrastructure_satisfaction[0] ?? 0,
            'Laboratories' => $infrastructure_satisfaction[1] ?? 0,
            'Library' => $infrastructure_satisfaction[2] ?? 0,
            'Sports Facilities' => $infrastructure_satisfaction[3] ?? 0,
            'Campus Amenities' => $infrastructure_satisfaction[4] ?? 0
        ];
        $pdf->CreateBarChart($infra_data, 'Infrastructure Ratings');
    }
}

// Clean any output buffers before generating PDF
ob_end_clean();

// Output PDF
$pdf->Output('Exit_Survey_Analytics_' . date('Y-m-d') . '.pdf', 'D');
?> 