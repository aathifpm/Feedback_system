<?php
session_start();
include 'functions.php';

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hod', 'faculty'])) {
    header('Location: index.php');
    exit();
}

// Get available filters
$current_dept_id = null;
if ($_SESSION['role'] === 'hod') {
    // Get HOD's department
    $hod_query = "SELECT department_id FROM hods WHERE id = ? AND is_active = TRUE";
    $stmt = mysqli_prepare($conn, $hod_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $hod_data = mysqli_fetch_assoc($result);
    $current_dept_id = $hod_data['department_id'];
}

// Get departments (only if admin, for HOD it's fixed to their department)
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments = mysqli_query($conn, $departments_query);

// Get available batch years for the current department
$batch_query = "SELECT DISTINCT by2.id, by2.batch_name 
                FROM batch_years by2 
                JOIN students s ON s.batch_id = by2.id
                JOIN exit_surveys es ON es.student_id = s.id
                WHERE 1=1";
if ($current_dept_id) {
    $batch_query .= " AND s.department_id = " . intval($current_dept_id);
}
$batch_query .= " ORDER BY by2.batch_name DESC";
$batches = mysqli_query($conn, $batch_query);

// Process filters
$filters = [
    'department_id' => $_GET['department_id'] ?? $current_dept_id,
    'batch_id' => $_GET['batch_id'] ?? null
];

// Add these functions at the top of the file after the includes
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

// Modify the fetchSurveyData function to use batch_id
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

$surveys = fetchSurveyData($conn, $filters);

// Process data for charts
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
    return $ratings;
}

// Modify the processEmploymentData function to use these normalizations
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
        'company_variations' => [], // To store variations of company names
        'institution_variations' => [] // To store variations of institution names
    ];

    foreach ($surveys as $survey) {
        $emp_data = json_decode($survey['employment_status'], true);
        if (!$emp_data || !isset($emp_data['status'])) continue;

        $status = $emp_data['status'];
        $stats['status_count'][$status] = ($stats['status_count'][$status] ?? 0) + 1;

        switch ($status) {
            case 'employed':
                $stats['total_employed']++;
                
                if (!empty($emp_data['starting_salary'])) {
                    $salary = floatval($emp_data['starting_salary']);
                    $stats['avg_salary'] += $salary;
                    
                    if ($salary <= 3) $stats['salary_ranges']['0-3']++;
                    elseif ($salary <= 6) $stats['salary_ranges']['3-6']++;
                    elseif ($salary <= 10) $stats['salary_ranges']['6-10']++;
                    else $stats['salary_ranges']['10+']++;
                }

                if (!empty($emp_data['employer_details']['company'])) {
                    $company = trim($emp_data['employer_details']['company']);
                    if (!empty($company)) {
                        // Find or get similar company name
                        $standardCompany = findSimilarCompany($company, $stats['top_companies']);
                        
                        // Store the variation mapping
                        if ($standardCompany !== $company) {
                            $stats['company_variations'][$company] = $standardCompany;
                        }
                        
                        // Update count
                        $stats['top_companies'][$standardCompany] = ($stats['top_companies'][$standardCompany] ?? 0) + 1;
                    }
                }

                if (!empty($emp_data['satisfaction'])) {
                    $satisfaction = $emp_data['satisfaction'];
                    $stats['satisfaction_levels'][$satisfaction] = ($stats['satisfaction_levels'][$satisfaction] ?? 0) + 1;
                }
                break;

            case 'higher_studies':
                if (!empty($emp_data['higher_studies']['institution'])) {
                    $institution = trim($emp_data['higher_studies']['institution']);
                    if (!empty($institution)) {
                        // Find or get similar institution name
                        $standardInstitution = findSimilarCompany($institution, $stats['top_institutions']);
                        
                        // Store the variation mapping
                        if ($standardInstitution !== $institution) {
                            $stats['institution_variations'][$institution] = $standardInstitution;
                        }
                        
                        // Update count
                        $stats['top_institutions'][$standardInstitution] = ($stats['top_institutions'][$standardInstitution] ?? 0) + 1;
                    }
                }
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
    
    // Keep only top 5
    $stats['top_companies'] = array_slice($stats['top_companies'], 0, 5, true);
    $stats['top_institutions'] = array_slice($stats['top_institutions'], 0, 5, true);

    return $stats;
}

$po_ratings = processRatings($surveys, 'po_ratings');
$pso_ratings = processRatings($surveys, 'pso_ratings');
$program_satisfaction = processRatings($surveys, 'program_satisfaction');
$infrastructure_satisfaction = processRatings($surveys, 'infrastructure_satisfaction');
$employment_stats = processEmploymentData($surveys);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exit Survey Analytics</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }

        .dashboard {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .filters-section {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
        }

        .filter-group select {
            padding: 0.8rem 1.2rem;
            border: none;
            border-radius: 12px;
            background: var(--card-bg);
            color: var(--text-color);
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .filter-group select:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1.2rem;
            margin-bottom: 1.5rem;
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--card-bg);
            box-shadow: var(--shadow);
            color: var(--primary-color);
            font-size: 1.5rem;
        }

        .stat-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 0.5rem 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin-bottom: 2.5rem;
        }

        .chart-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .chart-header {
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .chart-container {
            position: relative;
            height: 300px;
            padding: 1rem;
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.8rem;
            margin-top: 1rem;
        }

        .data-table th {
            padding: 1rem;
            background: var(--card-bg);
            color: var(--text-color);
            font-weight: 600;
            text-align: left;
            box-shadow: var(--shadow);
            border-radius: 10px;
        }

        .data-table td {
            padding: 1rem;
            background: var(--card-bg);
            box-shadow: var(--shadow);
            border-radius: 10px;
            transition: transform 0.3s ease;
        }

        .data-table tr:hover td {
            transform: translateY(-2px);
        }

        .button-group {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            background: var(--card-bg);
            box-shadow: var(--inner-shadow);
            margin-top: 1rem;
        }

        .alert-warning {
            border-left: 4px solid var(--warning-color);
            color: var(--warning-color);
        }

        @media (max-width: 768px) {
            .dashboard {
                padding: 1rem;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="dashboard">
        <div class="filters-section">
            <form method="GET" class="filters-grid">
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <div class="filter-group">
                    <label for="department">Department</label>
                    <select name="department_id" id="department">
                        <option value="">All Departments</option>
                        <?php while ($dept = mysqli_fetch_assoc($departments)): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                <?php echo ($filters['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                </select>
                </div>
                <?php endif; ?>

                <div class="filter-group">
                    <label for="batch">Batch Year</label>
                    <select name="batch_id" id="batch">
                        <option value="">All Batches</option>
                        <?php while ($batch = mysqli_fetch_assoc($batches)): ?>
                            <option value="<?php echo $batch['id']; ?>"
                                <?php echo ($filters['batch_id'] == $batch['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endwhile; ?>
                </select>
                </div>

                <div class="filter-group">
                    <label>&nbsp;</label>
                    <div class="button-group">
                        <button type="submit" class="btn btn-primary">Apply Filters</button>
                        <?php if ($filters['department_id']): ?>
                            <a href="generate_survey_report.php?department_id=<?php echo $filters['department_id']; ?>&batch_id=<?php echo $filters['batch_id'] ?? ''; ?>" 
                               class="btn btn-secondary" target="_blank">
                                <i class="fas fa-file-pdf"></i> Generate PDF Report
                            </a>
                            <a href="generate_survey_excel.php?department_id=<?php echo $filters['department_id']; ?>&batch_id=<?php echo $filters['batch_id'] ?? ''; ?>" 
                               class="btn btn-secondary" style="background-color: #27ae60;">
                                <i class="fas fa-file-excel"></i> Generate Excel Report
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                Please select a Department to generate report.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Overview Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-users stat-icon"></i>
                    <span class="stat-title">Total Responses</span>
                </div>
                <div class="stat-value"><?php echo count($surveys); ?></div>
                <div class="stat-label">Exit Survey Submissions</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-briefcase stat-icon"></i>
                    <span class="stat-title">Employment Rate</span>
                </div>
                <div class="stat-value">
                    <?php 
                    $employment_rate = count($surveys) > 0 
                        ? round(($employment_stats['total_employed'] / count($surveys)) * 100, 1) 
                        : 0;
                    echo $employment_rate . '%';
                    ?>
                </div>
                <div class="stat-label">Students Employed</div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-money-bill-wave stat-icon"></i>
                    <span class="stat-title">Average Package</span>
                </div>
                <div class="stat-value"><?php echo number_format($employment_stats['avg_salary'], 2); ?> LPA</div>
                <div class="stat-label">Annual Package</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="chart-grid">
            <!-- Employment Status Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Employment Status Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="employmentChart"></canvas>
                </div>
            </div>

            <!-- Salary Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Salary Distribution (LPA)</h3>
                </div>
                <div class="chart-container">
                    <canvas id="salaryChart"></canvas>
                </div>
            </div>

            <!-- Program Outcomes -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Program Outcomes Rating Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="poChart"></canvas>
                </div>
            </div>

            <!-- Program Specific Outcomes -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Program Specific Outcomes Rating</h3>
                </div>
                <div class="chart-container">
                    <canvas id="psoChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Companies & Institutions -->
        <div class="chart-grid">
            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Top Recruiting Companies</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Company</th>
                            <th>Students Placed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employment_stats['top_companies'] as $company => $count): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h3 class="chart-title">Top Higher Education Institutions</h3>
                </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Institution</th>
                            <th>Students Enrolled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employment_stats['top_institutions'] as $institution => $count): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($institution); ?></td>
                                <td><?php echo $count; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Chart configurations
        const employmentChart = new Chart(document.getElementById('employmentChart'), {
            type: 'pie',
        data: {
                labels: Object.keys(<?php echo json_encode($employment_stats['status_count']); ?>),
            datasets: [{
                    data: Object.values(<?php echo json_encode($employment_stats['status_count']); ?>),
                    backgroundColor: [
                        '#2ecc71',
                        '#3498db',
                        '#f1c40f',
                        '#e74c3c'
                    ]
            }]
        },
        options: {
            responsive: true,
                maintainAspectRatio: false,
            plugins: {
                    legend: {
                        position: 'bottom'
                }
            }
        }
    });

        const salaryChart = new Chart(document.getElementById('salaryChart'), {
        type: 'bar',
        data: {
                labels: Object.keys(<?php echo json_encode($employment_stats['salary_ranges']); ?>),
            datasets: [{
                    label: 'Number of Students',
                    data: Object.values(<?php echo json_encode($employment_stats['salary_ranges']); ?>),
                    backgroundColor: '#3498db'
            }]
        },
        options: {
            responsive: true,
                maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

        const poChart = new Chart(document.getElementById('poChart'), {
            type: 'bar',
        data: {
                labels: ['1', '2', '3', '4', '5'],
            datasets: [{
                    label: 'Rating Distribution (%)',
                    data: Object.values(<?php echo json_encode($po_ratings); ?>),
                    backgroundColor: '#2ecc71'
            }]
        },
        options: {
            responsive: true,
                maintainAspectRatio: false,
            scales: {
                    y: {
                    beginAtZero: true,
                        max: 100
                }
            }
        }
    });

        const psoChart = new Chart(document.getElementById('psoChart'), {
        type: 'bar',
        data: {
                labels: ['1', '2', '3', '4', '5'],
            datasets: [{
                    label: 'Rating Distribution (%)',
                    data: Object.values(<?php echo json_encode($pso_ratings); ?>),
                    backgroundColor: '#f1c40f'
            }]
        },
        options: {
            responsive: true,
                maintainAspectRatio: false,
            scales: {
                y: {
                        beginAtZero: true,
                        max: 100
                }
            }
        }
    });

        // Add filter form submission handling
        document.querySelector('form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const params = new URLSearchParams(formData);
            window.location.href = '?' + params.toString();
        });
    </script>
</body>
</html> 