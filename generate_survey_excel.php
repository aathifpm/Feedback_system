<?php
session_start();
include 'functions.php';
require 'vendor/autoload.php'; // For PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hod', 'faculty'])) {
    header('Location: index.php');
    exit();
}

// Get parameters
$department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : null;
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : null;

// Validate parameters
if (!$department_id) {
    die("Department ID is required");
}

// Get department name
$dept_query = "SELECT name FROM departments WHERE id = ?";
$stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($stmt, "i", $department_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$dept_data = mysqli_fetch_assoc($result);
$department_name = $dept_data['name'];

// Get batch name if provided
$batch_name = "";
if ($batch_id) {
    $batch_query = "SELECT batch_name FROM batch_years WHERE id = ?";
    $stmt = mysqli_prepare($conn, $batch_query);
    mysqli_stmt_bind_param($stmt, "i", $batch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $batch_data = mysqli_fetch_assoc($result);
    $batch_name = $batch_data['batch_name'];
}

// Functions to process data (copied from survey_analytics.php)
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

// Fetch survey data with filters
function fetchSurveyData($conn, $filters = []) {
    $query = "SELECT 
        es.*,
        s.name as student_name,
        s.roll_number,
        s.register_number,
        d.name as department_name,
        by2.batch_name,
        by2.id as batch_id
    FROM exit_surveys es
    JOIN students s ON es.student_id = s.id
    JOIN departments d ON es.department_id = d.id
    JOIN batch_years by2 ON s.batch_id = by2.id
    WHERE 1=1";

    // Apply filters
    if (!empty($filters['department_id'])) {
        $query .= " AND es.department_id = " . intval($filters['department_id']);
    }
    if (!empty($filters['batch_id'])) {
        $query .= " AND by2.id = " . intval($filters['batch_id']);
    }

    $query .= " ORDER BY es.created_at DESC";
    
    $result = mysqli_query($conn, $query);
    $surveys = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $surveys[] = $row;
    }
    return $surveys;
}

function processRatings($surveys, $field) {
    $ratings = array_fill(1, 5, 0);
    $total = 0;
    foreach ($surveys as $survey) {
        $data = json_decode($survey[$field], true);
        if (is_array($data)) {
            foreach ($data as $rating) {
                if (is_numeric($rating) && $rating >= 1 && $rating <= 5) {
                    $ratings[$rating]++;
                    $total++;
                }
            }
        }
    }
    // Calculate percentages
    if ($total > 0) {
        foreach ($ratings as &$count) {
            $count = round(($count / $total) * 100, 1);
        }
    }
    return ['ratings' => $ratings, 'total' => $total];
}

function processEmploymentData($surveys) {
    $stats = [
        'status_count' => [],
        'avg_salary' => 0,
        'total_employed' => 0,
        'salary_ranges' => [
            '0-3' => 0,
            '3-6' => 0,
            '6-10' => 0,
            '10+' => 0
        ],
        'top_companies' => [],
        'top_institutions' => [],
        'satisfaction_levels' => [],
        'company_variations' => [], 
        'institution_variations' => [],
        'detailed_placements' => [] // For detailed student placement data
    ];

    foreach ($surveys as $survey) {
        $emp_data = json_decode($survey['employment_status'], true);
        if (!$emp_data || !isset($emp_data['status'])) continue;

        $status = $emp_data['status'];
        $stats['status_count'][$status] = ($stats['status_count'][$status] ?? 0) + 1;

        switch ($status) {
            case 'employed':
                $stats['total_employed']++;
                
                $salary = !empty($emp_data['starting_salary']) ? floatval($emp_data['starting_salary']) : 0;
                if ($salary > 0) {
                    $stats['avg_salary'] += $salary;
                    
                    if ($salary <= 3) $stats['salary_ranges']['0-3']++;
                    elseif ($salary <= 6) $stats['salary_ranges']['3-6']++;
                    elseif ($salary <= 10) $stats['salary_ranges']['6-10']++;
                    else $stats['salary_ranges']['10+']++;
                }

                $company = !empty($emp_data['employer_details']['company']) ? trim($emp_data['employer_details']['company']) : '';
                if (!empty($company)) {
                    $standardCompany = findSimilarCompany($company, $stats['top_companies']);
                    if ($standardCompany !== $company) {
                        $stats['company_variations'][$company] = $standardCompany;
                    }
                    $stats['top_companies'][$standardCompany] = ($stats['top_companies'][$standardCompany] ?? 0) + 1;
                }

                // Add detailed placement info
                $stats['detailed_placements'][] = [
                    'name' => $survey['student_name'],
                    'roll_number' => $survey['roll_number'],
                    'register_number' => $survey['register_number'],
                    'company' => $company,
                    'role' => !empty($emp_data['employer_details']['role']) ? $emp_data['employer_details']['role'] : '',
                    'salary' => $salary
                ];

                if (!empty($emp_data['satisfaction'])) {
                    $satisfaction = $emp_data['satisfaction'];
                    $stats['satisfaction_levels'][$satisfaction] = ($stats['satisfaction_levels'][$satisfaction] ?? 0) + 1;
                }
                break;

            case 'higher_studies':
                $institution = !empty($emp_data['higher_studies']['institution']) ? trim($emp_data['higher_studies']['institution']) : '';
                if (!empty($institution)) {
                    $standardInstitution = findSimilarCompany($institution, $stats['top_institutions']);
                    if ($standardInstitution !== $institution) {
                        $stats['institution_variations'][$institution] = $standardInstitution;
                    }
                    $stats['top_institutions'][$standardInstitution] = ($stats['top_institutions'][$standardInstitution] ?? 0) + 1;
                }
                
                // Add detailed higher studies info
                $stats['detailed_higher_studies'][] = [
                    'name' => $survey['student_name'],
                    'roll_number' => $survey['roll_number'],
                    'register_number' => $survey['register_number'],
                    'institution' => $institution,
                    'course' => !empty($emp_data['higher_studies']['course']) ? $emp_data['higher_studies']['course'] : ''
                ];
                break;
        }
    }

    // Calculate averages and sort data
    if ($stats['total_employed'] > 0) {
        $stats['avg_salary'] = round($stats['avg_salary'] / $stats['total_employed'], 2);
    }

    // Sort companies and institutions by count
    arsort($stats['top_companies']);
    arsort($stats['top_institutions']);

    return $stats;
}

// Fetch data based on filters
$filters = [
    'department_id' => $department_id,
    'batch_id' => $batch_id
];

$surveys = fetchSurveyData($conn, $filters);
$po_ratings = processRatings($surveys, 'po_ratings');
$pso_ratings = processRatings($surveys, 'pso_ratings');
$program_satisfaction = processRatings($surveys, 'program_satisfaction');
$infrastructure_satisfaction = processRatings($surveys, 'infrastructure_satisfaction');
$employment_stats = processEmploymentData($surveys);

// Create a new spreadsheet
$spreadsheet = new Spreadsheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("College Exit Survey System")
    ->setLastModifiedBy("College Exit Survey System")
    ->setTitle("Exit Survey Report")
    ->setSubject("Exit Survey Analytics")
    ->setDescription("Excel report with exit survey analytics");

// Overall summary sheet
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Summary');

// Add header and title
$sheet->setCellValue('A1', 'EXIT SURVEY ANALYTICS REPORT');
$sheet->mergeCells('A1:H1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Department and batch info
$sheet->setCellValue('A3', 'Department:');
$sheet->setCellValue('B3', $department_name);
$sheet->getStyle('A3')->getFont()->setBold(true);

if ($batch_name) {
    $sheet->setCellValue('A4', 'Batch:');
    $sheet->setCellValue('B4', $batch_name);
    $sheet->getStyle('A4')->getFont()->setBold(true);
}

$sheet->setCellValue('A6', 'Report Generated On:');
$sheet->setCellValue('B6', date('d-m-Y H:i:s'));
$sheet->getStyle('A6')->getFont()->setBold(true);

// Key statistics
$sheet->setCellValue('A8', 'KEY STATISTICS');
$sheet->mergeCells('A8:H8');
$sheet->getStyle('A8')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A8')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A10', 'Total Responses:');
$sheet->setCellValue('B10', count($surveys));
$sheet->getStyle('A10')->getFont()->setBold(true);

$sheet->setCellValue('A11', 'Employment Rate:');
$employment_rate = count($surveys) > 0 
    ? round(($employment_stats['total_employed'] / count($surveys)) * 100, 1) 
    : 0;
$sheet->setCellValue('B11', $employment_rate . '%');
$sheet->getStyle('A11')->getFont()->setBold(true);

$sheet->setCellValue('A12', 'Average Package:');
$sheet->setCellValue('B12', number_format($employment_stats['avg_salary'], 2) . ' LPA');
$sheet->getStyle('A12')->getFont()->setBold(true);

// Employment Status Distribution
$sheet->setCellValue('A14', 'EMPLOYMENT STATUS DISTRIBUTION');
$sheet->mergeCells('A14:D14');
$sheet->getStyle('A14')->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A14')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A15', 'Status');
$sheet->setCellValue('B15', 'Count');
$sheet->setCellValue('C15', 'Percentage');
$sheet->getStyle('A15:C15')->getFont()->setBold(true);

$row = 16;
$total_students = count($surveys);
foreach ($employment_stats['status_count'] as $status => $count) {
    $sheet->setCellValue('A' . $row, ucfirst($status));
    $sheet->setCellValue('B' . $row, $count);
    $sheet->setCellValue('C' . $row, $total_students > 0 ? round(($count / $total_students) * 100, 1) . '%' : '0%');
    $row++;
}

// Salary Distribution
$sheet->setCellValue('A' . ($row + 2), 'SALARY DISTRIBUTION (LPA)');
$sheet->mergeCells('A' . ($row + 2) . ':D' . ($row + 2));
$sheet->getStyle('A' . ($row + 2))->getFont()->setBold(true)->setSize(12);
$sheet->getStyle('A' . ($row + 2))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A' . ($row + 3), 'Range');
$sheet->setCellValue('B' . ($row + 3), 'Count');
$sheet->setCellValue('C' . ($row + 3), 'Percentage');
$sheet->getStyle('A' . ($row + 3) . ':C' . ($row + 3))->getFont()->setBold(true);

$salary_row = $row + 4;
foreach ($employment_stats['salary_ranges'] as $range => $count) {
    $sheet->setCellValue('A' . $salary_row, $range);
    $sheet->setCellValue('B' . $salary_row, $count);
    $sheet->setCellValue('C' . $salary_row, $employment_stats['total_employed'] > 0 ? 
        round(($count / $employment_stats['total_employed']) * 100, 1) . '%' : '0%');
    $salary_row++;
}

// Rating distributions
// Create a new worksheet for PO Ratings
$poSheet = $spreadsheet->createSheet();
$poSheet->setTitle('PO Ratings');

// Add header and title
$poSheet->setCellValue('A1', 'PROGRAM OUTCOMES RATING DISTRIBUTION');
$poSheet->mergeCells('A1:G1');
$poSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$poSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Fetch PO statements from database
$po_statements = [];
$po_query = "SELECT po_number, statement FROM alumni_po_assessment GROUP BY po_number, statement ORDER BY po_number";
$po_result = mysqli_query($conn, $po_query);
while ($row = mysqli_fetch_assoc($po_result)) {
    $po_statements[$row['po_number']] = $row['statement'];
}

// Headers with statements
$poSheet->setCellValue('A3', 'PO Number');
$poSheet->setCellValue('B3', 'Statement');
$poSheet->setCellValue('C3', 'Rating 1 (%)');
$poSheet->setCellValue('D3', 'Rating 2 (%)');
$poSheet->setCellValue('E3', 'Rating 3 (%)');
$poSheet->setCellValue('F3', 'Rating 4 (%)');
$poSheet->setCellValue('G3', 'Rating 5 (%)');
$poSheet->setCellValue('H3', 'Average Rating');
$poSheet->getStyle('A3:H3')->getFont()->setBold(true);

$po_row = 4;
// Extract individual PO ratings from surveys
$po_individual_ratings = [];
foreach ($surveys as $survey) {
    $po_data = json_decode($survey['po_ratings'], true);
    if (is_array($po_data)) {
        foreach ($po_data as $po_number => $rating) {
            if (!isset($po_individual_ratings[$po_number])) {
                $po_individual_ratings[$po_number] = array_fill(1, 5, 0);
                $po_individual_ratings[$po_number]['total'] = 0;
                $po_individual_ratings[$po_number]['sum'] = 0;
            }
            $po_individual_ratings[$po_number][$rating]++;
            $po_individual_ratings[$po_number]['total']++;
            $po_individual_ratings[$po_number]['sum'] += $rating;
        }
    }
}

// Display PO ratings with statements
foreach ($po_individual_ratings as $po_number => $ratings) {
    $total = $ratings['total'];
    $avg_rating = $total > 0 ? round($ratings['sum'] / $total, 2) : 0;
    
    $statement = isset($po_statements[$po_number]) ? $po_statements[$po_number] : "Program Outcome $po_number";
    
    $poSheet->setCellValue('A' . $po_row, "PO $po_number");
    $poSheet->setCellValue('B' . $po_row, $statement);
    
    for ($i = 1; $i <= 5; $i++) {
        $percentage = $total > 0 ? round(($ratings[$i] / $total) * 100, 1) : 0;
        $poSheet->setCellValue(chr(66 + $i) . $po_row, $percentage . '%');
    }
    
    $poSheet->setCellValue('H' . $po_row, $avg_rating);
    $po_row++;
}

// Create a new worksheet for PSO Ratings
$psoSheet = $spreadsheet->createSheet();
$psoSheet->setTitle('PSO Ratings');

// Add header and title
$psoSheet->setCellValue('A1', 'PROGRAM SPECIFIC OUTCOMES RATING DISTRIBUTION');
$psoSheet->mergeCells('A1:G1');
$psoSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$psoSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Fetch PSO statements from database
$pso_statements = [];
$pso_query = "SELECT pso_number, statement FROM alumni_pso_assessment GROUP BY pso_number, statement ORDER BY pso_number";
$pso_result = mysqli_query($conn, $pso_query);
while ($row = mysqli_fetch_assoc($pso_result)) {
    $pso_statements[$row['pso_number']] = $row['statement'];
}

// Headers with statements
$psoSheet->setCellValue('A3', 'PSO Number');
$psoSheet->setCellValue('B3', 'Statement');
$psoSheet->setCellValue('C3', 'Rating 1 (%)');
$psoSheet->setCellValue('D3', 'Rating 2 (%)');
$psoSheet->setCellValue('E3', 'Rating 3 (%)');
$psoSheet->setCellValue('F3', 'Rating 4 (%)');
$psoSheet->setCellValue('G3', 'Rating 5 (%)');
$psoSheet->setCellValue('H3', 'Average Rating');
$psoSheet->getStyle('A3:H3')->getFont()->setBold(true);

$pso_row = 4;
// Extract individual PSO ratings from surveys
$pso_individual_ratings = [];
foreach ($surveys as $survey) {
    $pso_data = json_decode($survey['pso_ratings'], true);
    if (is_array($pso_data)) {
        foreach ($pso_data as $pso_number => $rating) {
            if (!isset($pso_individual_ratings[$pso_number])) {
                $pso_individual_ratings[$pso_number] = array_fill(1, 5, 0);
                $pso_individual_ratings[$pso_number]['total'] = 0;
                $pso_individual_ratings[$pso_number]['sum'] = 0;
            }
            $pso_individual_ratings[$pso_number][$rating]++;
            $pso_individual_ratings[$pso_number]['total']++;
            $pso_individual_ratings[$pso_number]['sum'] += $rating;
        }
    }
}

// Display PSO ratings with statements
foreach ($pso_individual_ratings as $pso_number => $ratings) {
    $total = $ratings['total'];
    $avg_rating = $total > 0 ? round($ratings['sum'] / $total, 2) : 0;
    
    $statement = isset($pso_statements[$pso_number]) ? $pso_statements[$pso_number] : "Program Specific Outcome $pso_number";
    
    $psoSheet->setCellValue('A' . $pso_row, "PSO $pso_number");
    $psoSheet->setCellValue('B' . $pso_row, $statement);
    
    for ($i = 1; $i <= 5; $i++) {
        $percentage = $total > 0 ? round(($ratings[$i] / $total) * 100, 1) : 0;
        $psoSheet->setCellValue(chr(66 + $i) . $pso_row, $percentage . '%');
    }
    
    $psoSheet->setCellValue('H' . $pso_row, $avg_rating);
    $pso_row++;
}

// Create worksheets for Program Satisfaction and Infrastructure Satisfaction
$progSatSheet = $spreadsheet->createSheet();
$progSatSheet->setTitle('Program Satisfaction');

$progSatSheet->setCellValue('A1', 'PROGRAM SATISFACTION RATING DISTRIBUTION');
$progSatSheet->mergeCells('A1:G1');
$progSatSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$progSatSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Define program satisfaction categories (these can be customized)
$program_satisfaction_categories = [
    'curriculum' => 'Curriculum and Course Content',
    'teaching' => 'Teaching Quality and Methods',
    'faculty' => 'Faculty Expertise and Support',
    'resources' => 'Learning Resources and Facilities',
    'assessment' => 'Assessment and Evaluation Methods',
    'opportunities' => 'Career Development Opportunities',
    'overall' => 'Overall Program Experience'
];

// Headers with statements
$progSatSheet->setCellValue('A3', 'Category');
$progSatSheet->setCellValue('B3', 'Description');
$progSatSheet->setCellValue('C3', 'Rating 1 (%)');
$progSatSheet->setCellValue('D3', 'Rating 2 (%)');
$progSatSheet->setCellValue('E3', 'Rating 3 (%)');
$progSatSheet->setCellValue('F3', 'Rating 4 (%)');
$progSatSheet->setCellValue('G3', 'Rating 5 (%)');
$progSatSheet->setCellValue('H3', 'Average Rating');
$progSatSheet->getStyle('A3:H3')->getFont()->setBold(true);

$prog_row = 4;
// Extract individual program satisfaction ratings from surveys
$program_individual_ratings = [];
foreach ($surveys as $survey) {
    $program_data = json_decode($survey['program_satisfaction'], true);
    if (is_array($program_data)) {
        foreach ($program_data as $category => $rating) {
            if (!isset($program_individual_ratings[$category])) {
                $program_individual_ratings[$category] = array_fill(1, 5, 0);
                $program_individual_ratings[$category]['total'] = 0;
                $program_individual_ratings[$category]['sum'] = 0;
            }
            $program_individual_ratings[$category][$rating]++;
            $program_individual_ratings[$category]['total']++;
            $program_individual_ratings[$category]['sum'] += $rating;
        }
    }
}

// Display program satisfaction ratings with descriptions
foreach ($program_individual_ratings as $category => $ratings) {
    $total = $ratings['total'];
    $avg_rating = $total > 0 ? round($ratings['sum'] / $total, 2) : 0;
    
    $description = isset($program_satisfaction_categories[$category]) ? 
        $program_satisfaction_categories[$category] : ucfirst(str_replace('_', ' ', $category));
    
    $progSatSheet->setCellValue('A' . $prog_row, ucfirst($category));
    $progSatSheet->setCellValue('B' . $prog_row, $description);
    
    for ($i = 1; $i <= 5; $i++) {
        $percentage = $total > 0 ? round(($ratings[$i] / $total) * 100, 1) : 0;
        $progSatSheet->setCellValue(chr(66 + $i) . $prog_row, $percentage . '%');
    }
    
    $progSatSheet->setCellValue('H' . $prog_row, $avg_rating);
    $prog_row++;
}

$infraSheet = $spreadsheet->createSheet();
$infraSheet->setTitle('Infrastructure Satisfaction');

$infraSheet->setCellValue('A1', 'INFRASTRUCTURE SATISFACTION RATING DISTRIBUTION');
$infraSheet->mergeCells('A1:G1');
$infraSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$infraSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Define infrastructure satisfaction categories (these can be customized)
$infrastructure_satisfaction_categories = [
    'classrooms' => 'Classroom Facilities',
    'labs' => 'Laboratory Equipment and Facilities',
    'library' => 'Library Resources and Services',
    'internet' => 'Internet and Wi-Fi Connectivity',
    'computing' => 'Computing Facilities',
    'recreation' => 'Recreational Facilities',
    'cafeteria' => 'Cafeteria/Canteen Facilities',
    'washrooms' => 'Washroom Cleanliness and Maintenance',
    'transportation' => 'Transportation Facilities',
    'hostel' => 'Hostel Facilities',
    'overall' => 'Overall Infrastructure Quality'
];

// Headers with statements
$infraSheet->setCellValue('A3', 'Category');
$infraSheet->setCellValue('B3', 'Description');
$infraSheet->setCellValue('C3', 'Rating 1 (%)');
$infraSheet->setCellValue('D3', 'Rating 2 (%)');
$infraSheet->setCellValue('E3', 'Rating 3 (%)');
$infraSheet->setCellValue('F3', 'Rating 4 (%)');
$infraSheet->setCellValue('G3', 'Rating 5 (%)');
$infraSheet->setCellValue('H3', 'Average Rating');
$infraSheet->getStyle('A3:H3')->getFont()->setBold(true);

$infra_row = 4;
// Extract individual infrastructure satisfaction ratings from surveys
$infrastructure_individual_ratings = [];
foreach ($surveys as $survey) {
    $infra_data = json_decode($survey['infrastructure_satisfaction'], true);
    if (is_array($infra_data)) {
        foreach ($infra_data as $category => $rating) {
            if (!isset($infrastructure_individual_ratings[$category])) {
                $infrastructure_individual_ratings[$category] = array_fill(1, 5, 0);
                $infrastructure_individual_ratings[$category]['total'] = 0;
                $infrastructure_individual_ratings[$category]['sum'] = 0;
            }
            $infrastructure_individual_ratings[$category][$rating]++;
            $infrastructure_individual_ratings[$category]['total']++;
            $infrastructure_individual_ratings[$category]['sum'] += $rating;
        }
    }
}

// Display infrastructure satisfaction ratings with descriptions
foreach ($infrastructure_individual_ratings as $category => $ratings) {
    $total = $ratings['total'];
    $avg_rating = $total > 0 ? round($ratings['sum'] / $total, 2) : 0;
    
    $description = isset($infrastructure_satisfaction_categories[$category]) ? 
        $infrastructure_satisfaction_categories[$category] : ucfirst(str_replace('_', ' ', $category));
    
    $infraSheet->setCellValue('A' . $infra_row, ucfirst($category));
    $infraSheet->setCellValue('B' . $infra_row, $description);
    
    for ($i = 1; $i <= 5; $i++) {
        $percentage = $total > 0 ? round(($ratings[$i] / $total) * 100, 1) : 0;
        $infraSheet->setCellValue(chr(66 + $i) . $infra_row, $percentage . '%');
    }
    
    $infraSheet->setCellValue('H' . $infra_row, $avg_rating);
    $infra_row++;
}

// Create a new worksheet for Top Companies
$companiesSheet = $spreadsheet->createSheet();
$companiesSheet->setTitle('Top Companies');

// Add header and title
$companiesSheet->setCellValue('A1', 'TOP RECRUITING COMPANIES');
$companiesSheet->mergeCells('A1:C1');
$companiesSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$companiesSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$companiesSheet->setCellValue('A3', 'Company');
$companiesSheet->setCellValue('B3', 'Students Placed');
$companiesSheet->setCellValue('C3', 'Percentage');
$companiesSheet->getStyle('A3:C3')->getFont()->setBold(true);

$company_row = 4;
foreach ($employment_stats['top_companies'] as $company => $count) {
    $companiesSheet->setCellValue('A' . $company_row, $company);
    $companiesSheet->setCellValue('B' . $company_row, $count);
    $companiesSheet->setCellValue('C' . $company_row, $employment_stats['total_employed'] > 0 ? 
        round(($count / $employment_stats['total_employed']) * 100, 1) . '%' : '0%');
    $company_row++;
}

// Create a new worksheet for Top Institutions
$institutionsSheet = $spreadsheet->createSheet();
$institutionsSheet->setTitle('Top Institutions');

// Add header and title
$institutionsSheet->setCellValue('A1', 'TOP HIGHER EDUCATION INSTITUTIONS');
$institutionsSheet->mergeCells('A1:C1');
$institutionsSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$institutionsSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$institutionsSheet->setCellValue('A3', 'Institution');
$institutionsSheet->setCellValue('B3', 'Students Enrolled');
$institutionsSheet->setCellValue('C3', 'Percentage');
$institutionsSheet->getStyle('A3:C3')->getFont()->setBold(true);

$institution_row = 4;
$higher_studies_count = isset($employment_stats['status_count']['higher_studies']) ? 
    $employment_stats['status_count']['higher_studies'] : 0;

foreach ($employment_stats['top_institutions'] as $institution => $count) {
    $institutionsSheet->setCellValue('A' . $institution_row, $institution);
    $institutionsSheet->setCellValue('B' . $institution_row, $count);
    $institutionsSheet->setCellValue('C' . $institution_row, $higher_studies_count > 0 ? 
        round(($count / $higher_studies_count) * 100, 1) . '%' : '0%');
    $institution_row++;
}

// Add detailed placement data sheet
if (!empty($employment_stats['detailed_placements'])) {
    $placementSheet = $spreadsheet->createSheet();
    $placementSheet->setTitle('Placement Details');
    
    $placementSheet->setCellValue('A1', 'DETAILED PLACEMENT DATA');
    $placementSheet->mergeCells('A1:F1');
    $placementSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $placementSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $placementSheet->setCellValue('A3', 'Name');
    $placementSheet->setCellValue('B3', 'Roll Number');
    $placementSheet->setCellValue('C3', 'Register Number');
    $placementSheet->setCellValue('D3', 'Company');
    $placementSheet->setCellValue('E3', 'Role');
    $placementSheet->setCellValue('F3', 'Salary (LPA)');
    $placementSheet->getStyle('A3:F3')->getFont()->setBold(true);
    
    $placement_row = 4;
    foreach ($employment_stats['detailed_placements'] as $placement) {
        $placementSheet->setCellValue('A' . $placement_row, $placement['name']);
        $placementSheet->setCellValue('B' . $placement_row, $placement['roll_number']);
        $placementSheet->setCellValue('C' . $placement_row, $placement['register_number']);
        $placementSheet->setCellValue('D' . $placement_row, $placement['company']);
        $placementSheet->setCellValue('E' . $placement_row, $placement['role']);
        $placementSheet->setCellValue('F' . $placement_row, $placement['salary']);
        $placement_row++;
    }
    
    // Auto-size columns
    foreach (range('A', 'F') as $col) {
        $placementSheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// Add higher studies details if available
if (!empty($employment_stats['detailed_higher_studies'])) {
    $higherStudiesSheet = $spreadsheet->createSheet();
    $higherStudiesSheet->setTitle('Higher Studies Details');
    
    $higherStudiesSheet->setCellValue('A1', 'HIGHER STUDIES DETAILS');
    $higherStudiesSheet->mergeCells('A1:E1');
    $higherStudiesSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $higherStudiesSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $higherStudiesSheet->setCellValue('A3', 'Name');
    $higherStudiesSheet->setCellValue('B3', 'Roll Number');
    $higherStudiesSheet->setCellValue('C3', 'Register Number');
    $higherStudiesSheet->setCellValue('D3', 'Institution');
    $higherStudiesSheet->setCellValue('E3', 'Course');
    $higherStudiesSheet->getStyle('A3:E3')->getFont()->setBold(true);
    
    $hs_row = 4;
    foreach ($employment_stats['detailed_higher_studies'] as $hs) {
        $higherStudiesSheet->setCellValue('A' . $hs_row, $hs['name']);
        $higherStudiesSheet->setCellValue('B' . $hs_row, $hs['roll_number']);
        $higherStudiesSheet->setCellValue('C' . $hs_row, $hs['register_number']);
        $higherStudiesSheet->setCellValue('D' . $hs_row, $hs['institution']);
        $higherStudiesSheet->setCellValue('E' . $hs_row, $hs['course']);
        $hs_row++;
    }
    
    // Auto-size columns
    foreach (range('A', 'E') as $col) {
        $higherStudiesSheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// Raw Survey Data Sheet
$rawDataSheet = $spreadsheet->createSheet();
$rawDataSheet->setTitle('Raw Survey Data');

$rawDataSheet->setCellValue('A1', 'RAW SURVEY DATA');
$rawDataSheet->mergeCells('A1:J1');
$rawDataSheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$rawDataSheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Headers for raw data
$rawDataSheet->setCellValue('A3', 'Name');
$rawDataSheet->setCellValue('B3', 'Roll Number');
$rawDataSheet->setCellValue('C3', 'Register Number');
$rawDataSheet->setCellValue('D3', 'Batch');
$rawDataSheet->setCellValue('E3', 'Employment Status');
$rawDataSheet->setCellValue('F3', 'Company/Institution');
$rawDataSheet->setCellValue('G3', 'Role/Course');
$rawDataSheet->setCellValue('H3', 'Salary (LPA)');
$rawDataSheet->setCellValue('I3', 'Contact Number');
$rawDataSheet->setCellValue('J3', 'Email');
$rawDataSheet->getStyle('A3:J3')->getFont()->setBold(true);

$raw_row = 4;
foreach ($surveys as $survey) {
    $emp_data = json_decode($survey['employment_status'], true);
    $status = isset($emp_data['status']) ? $emp_data['status'] : '';
    
    $company_institution = '';
    $role_course = '';
    $salary = '';
    
    if ($status === 'employed' && isset($emp_data['employer_details'])) {
        $company_institution = isset($emp_data['employer_details']['company']) ? $emp_data['employer_details']['company'] : '';
        $role_course = isset($emp_data['employer_details']['role']) ? $emp_data['employer_details']['role'] : '';
        $salary = isset($emp_data['starting_salary']) ? $emp_data['starting_salary'] : '';
    } elseif ($status === 'higher_studies' && isset($emp_data['higher_studies'])) {
        $company_institution = isset($emp_data['higher_studies']['institution']) ? $emp_data['higher_studies']['institution'] : '';
        $role_course = isset($emp_data['higher_studies']['course']) ? $emp_data['higher_studies']['course'] : '';
    }
    
    $rawDataSheet->setCellValue('A' . $raw_row, $survey['student_name']);
    $rawDataSheet->setCellValue('B' . $raw_row, $survey['roll_number']);
    $rawDataSheet->setCellValue('C' . $raw_row, $survey['register_number']);
    $rawDataSheet->setCellValue('D' . $raw_row, $survey['batch_name']);
    $rawDataSheet->setCellValue('E' . $raw_row, ucfirst($status));
    $rawDataSheet->setCellValue('F' . $raw_row, $company_institution);
    $rawDataSheet->setCellValue('G' . $raw_row, $role_course);
    $rawDataSheet->setCellValue('H' . $raw_row, $salary);
    $rawDataSheet->setCellValue('I' . $raw_row, $survey['contact_number']);
    $rawDataSheet->setCellValue('J' . $raw_row, $survey['email']);
    
    $raw_row++;
}

// Auto-size columns for all sheets
$sheetCount = $spreadsheet->getSheetCount();
for ($i = 0; $i < $sheetCount; $i++) {
    $sheet = $spreadsheet->getSheet($i);
    foreach (range('A', 'J') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
}

// Set active sheet to Summary
$spreadsheet->setActiveSheetIndex(0);

// Generate Excel file
$filename = "ExitSurvey_" . str_replace(" ", "_", $department_name) . 
    ($batch_name ? "_" . $batch_name : "") . "_" . date('Ymd_His') . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit; 