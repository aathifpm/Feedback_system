<?php
session_start();
require_once 'db_connection.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['hod', 'faculty'])) {
    header("Location: login.php");
    exit();
}

// Get user's department from their role
$department_code = null;
if ($_SESSION['role'] === 'hod') {
    $query = "SELECT d.code FROM hods h JOIN departments d ON h.department_id = d.id WHERE h.id = ? AND h.is_active = TRUE";
} else {
    $query = "SELECT d.code FROM faculty f JOIN departments d ON f.department_id = d.id WHERE f.id = ? AND f.is_active = TRUE";
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user_data = mysqli_fetch_assoc($result);
$department_code = $user_data['code'];

if (!$department_code) {
    die("Error: Department not found.");
}

// Get unique degrees from alumni_survey
$degrees_query = "SELECT DISTINCT degree FROM alumni_survey ORDER BY degree";
$degrees_result = mysqli_query($conn, $degrees_query);
$available_degrees = [];
while ($row = mysqli_fetch_assoc($degrees_result)) {
    $available_degrees[] = $row['degree'];
}

// Get selected degree filter (default to user's department degree)
$selected_degree = isset($_GET['degree']) ? $_GET['degree'] : null;
$selected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;

// Add "All Departments" option to available degrees
array_unshift($available_degrees, 'All Departments');

if (!$selected_degree) {
    // Map department code to likely degree name
    $degree_map = [
        'CSE' => 'B.E Computer Science and Engineering',
        'ECE' => 'B.E Electronics and Communication Engineering',
        'MECH' => 'B.E Mechanical Engineering',
        'IT' => 'B.E Information Technology',
        'EEE' => 'B.E Electrical and Electronics Engineering',
        'CIVIL' => 'B.E Civil Engineering',
        'AIDS' => 'B.Tech Artificial Intelligence and Data Science',
        'AIML' => 'B.Tech Artificial Intelligence and Machine Learning',
        'CSBS' => 'B.Tech Computer Science and Business Systems',
        'ICE' => 'B.E Instrumentation and Control Engineering',
        'AUTO' => 'B.E Automobile Engineering',
        'CHEM' => 'B.Tech Chemical Engineering',
        'PROD' => 'B.E Production Engineering',
        'AERO' => 'B.E Aeronautical Engineering',
        'MARINE' => 'B.E Marine Engineering',
        'BIOTECH' => 'B.Tech Biotechnology',
        'FOOD' => 'B.Tech Food Technology'
    ];
    
    // First try to map from department code
    if (isset($degree_map[$department_code])) {
        $selected_degree = $degree_map[$department_code];
    }
    // If no mapping found, check if department code exists in available degrees
    else if (in_array($department_code, $available_degrees)) {
        $selected_degree = $department_code;
    }
    // Finally fall back to first available degree
    else if (!empty($available_degrees)) {
        $selected_degree = $available_degrees[0];
    }
    // If no degrees available, set a default
    else {
        $selected_degree = 'B.E Computer Science and Engineering';
    }
}

// Get available batch years for the selected degree
$batch_query = "SELECT DISTINCT passing_year FROM alumni_survey";
if ($selected_degree && $selected_degree !== 'All Departments') {
    $batch_query .= " WHERE degree = ?";
    $stmt = mysqli_prepare($conn, $batch_query);
    mysqli_stmt_bind_param($stmt, "s", $selected_degree);
} else {
    $stmt = mysqli_prepare($conn, $batch_query);
}
mysqli_stmt_execute($stmt);
$batch_result = mysqli_stmt_get_result($stmt);
$available_batches = [];
while ($row = mysqli_fetch_assoc($batch_result)) {
    $available_batches[] = $row['passing_year'];
}

// Function to get overall statistics
function getOverallStats($conn, $degree = null, $batch = null) {
    $stats = [];
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "degree = ?";
        $params[] = $degree;
        $types .= 's';
    }
    if ($batch && $batch !== '' && $batch !== 'All Batches') {
        $where_clauses[] = "passing_year = ?";
        $params[] = $batch;
        $types .= 's';
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    
    // Total responses
    $query = "SELECT COUNT(*) as total FROM alumni_survey" . $where_sql;
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats['total_responses'] = mysqli_fetch_assoc($result)['total'];
    
    // Gender distribution
    $query = "SELECT gender, COUNT(*) as count FROM alumni_survey" . $where_sql . " GROUP BY gender";
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats['gender_distribution'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['gender_distribution'][$row['gender']] = $row['count'];
    }
    
    // Employment status distribution
    $query = "SELECT present_status, COUNT(*) as count FROM alumni_survey" . $where_sql . " GROUP BY present_status";
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $stats['status_distribution'] = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $stats['status_distribution'][$row['present_status']] = $row['count'];
    }
    
    return $stats;
}

// Function to get PO/PEO/PSO attainment analysis
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
    $query = "SELECT apa.po_number, AVG(apa.rating) as avg_rating 
              FROM alumni_po_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY apa.po_number ORDER BY apa.po_number";
    
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
    }
    
    // PEO Attainment
    $query = "SELECT apa.peo_number, AVG(apa.rating) as avg_rating 
              FROM alumni_peo_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY apa.peo_number ORDER BY apa.peo_number";
    
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
    }
    
    // PSO Attainment
    $query = "SELECT apa.pso_number, AVG(apa.rating) as avg_rating 
              FROM alumni_pso_assessment apa 
              JOIN alumni_survey a ON apa.alumni_id = a.id" . 
              $where_sql . " GROUP BY apa.pso_number ORDER BY apa.pso_number";
    
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
    }
    
    return $attainment;
}

// Function to get competitive exam analysis
function getCompetitiveExamAnalysis($conn, $degree = null, $batch = null) {
    $where_clauses = ["competitive_exam = 'yes'", "exams IS NOT NULL"];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "degree = ?";
        $params[] = $degree;
        $types .= 's';
    }
    if ($batch && $batch !== '' && $batch !== 'All Batches') {
        $where_clauses[] = "passing_year = ?";
        $params[] = $batch;
        $types .= 's';
    }
    
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    
    $query = "SELECT exams, COUNT(*) as count FROM alumni_survey" . $where_sql . " GROUP BY exams";
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $exam_stats = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $exams = explode(', ', $row['exams']);
        foreach ($exams as $exam) {
            if (!isset($exam_stats[$exam])) {
                $exam_stats[$exam] = 0;
            }
            $exam_stats[$exam] += $row['count'];
        }
    }
    return $exam_stats;
}

// Function to get year-wise analysis
function getYearWiseAnalysis($conn, $degree = null, $batch = null) {
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "degree = ?";
        $params[] = $degree;
        $types .= 's';
    }
    if ($batch && $batch !== '' && $batch !== 'All Batches') {
        $where_clauses[] = "passing_year = ?";
        $params[] = $batch;
        $types .= 's';
    }
    
    $where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";
    
    $query = "SELECT passing_year, COUNT(*) as count, 
              SUM(CASE WHEN present_status = 'employed' THEN 1 ELSE 0 END) as employed,
              SUM(CASE WHEN present_status = 'higher_studies' THEN 1 ELSE 0 END) as higher_studies,
              SUM(CASE WHEN present_status = 'entrepreneur' THEN 1 ELSE 0 END) as entrepreneurs
              FROM alumni_survey" . $where_sql . "
              GROUP BY passing_year 
              ORDER BY passing_year DESC";
              
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $year_stats = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $year_stats[$row['passing_year']] = [
            'total' => $row['count'],
            'employed' => $row['employed'],
            'higher_studies' => $row['higher_studies'],
            'entrepreneurs' => $row['entrepreneurs']
        ];
    }
    return $year_stats;
}

// Function to get course recommendations
function getCourseRecommendations($conn, $degree = null, $batch = null) {
    $where_clauses = ["suggested_courses IS NOT NULL", "suggested_courses != ''"];
    $params = [];
    $types = '';
    
    if ($degree && $degree !== '' && $degree !== 'All Departments') {
        $where_clauses[] = "degree = ?";
        $params[] = $degree;
        $types .= 's';
    }
    if ($batch && $batch !== '' && $batch !== 'All Batches') {
        $where_clauses[] = "passing_year = ?";
        $params[] = $batch;
        $types .= 's';
    }
    
    $where_sql = " WHERE " . implode(" AND ", $where_clauses);
    
    $query = "SELECT suggested_courses FROM alumni_survey" . $where_sql;
    $stmt = mysqli_prepare($conn, $query);
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $recommendations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $courses = explode(',', $row['suggested_courses']);
        foreach ($courses as $course) {
            $course = trim($course);
            if (!empty($course)) {
                if (!isset($recommendations[$course])) {
                    $recommendations[$course] = 0;
                }
                $recommendations[$course]++;
            }
        }
    }
    arsort($recommendations);
    return array_slice($recommendations, 0, 10);
}

// Get all analytics data with filters
$overall_stats = getOverallStats($conn, $selected_degree, $selected_batch);
$attainment_analysis = getAttainmentAnalysis($conn, $selected_degree, $selected_batch);
$competitive_exam_stats = getCompetitiveExamAnalysis($conn, $selected_degree, $selected_batch);
$year_wise_stats = getYearWiseAnalysis($conn, $selected_degree, $selected_batch);
$course_recommendations = getCourseRecommendations($conn, $selected_degree, $selected_batch);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alumni Survey Analytics - <?php echo htmlspecialchars($selected_degree); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <?php include 'header.php'; ?>
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
            --chart-colors: ['#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEEAD'];
        }

        .main-content {
            min-height: calc(100vh - var(--header-height));
            padding: 2rem;
            margin-top: var(--header-height);
            background: var(--bg-color);
            font-family: 'Poppins', sans-serif;
        }

        .dashboard-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
            background: linear-gradient(to bottom, var(--primary-color), var(--secondary-color));
            border-radius: 25px 0 0 25px;
        }

        .dashboard-header:hover {
            transform: translateY(-5px);
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 2rem;
            margin-bottom: 1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .dashboard-header h1 i {
            color: var(--primary-color);
            font-size: 1.8rem;
        }

        .dashboard-header p {
            color: #666;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .degree-selector {
            background: var(--card-bg);
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
            min-width: 300px;
        }

        .degree-selector:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-7px);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-card h3 {
            color: var(--text-color);
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .stat-card h3 i {
            color: var(--primary-color);
            font-size: 1.4rem;
        }

        .stat-card h2 {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin: 0;
            font-weight: 600;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 3rem;
        }

        .chart-container {
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 350px; /* Fixed height for consistent chart sizes */
        }

        .chart-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .chart-container:hover {
            transform: translateY(-5px);
        }

        .chart-container h2 {
            color: var(--text-color);
            font-size: 1.4rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(52, 152, 219, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .chart-container h2 i {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .recommendations {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-top: 3rem;
            transition: all 0.3s ease;
        }

        .recommendations:hover {
            transform: translateY(-5px);
        }

        .recommendations h2 {
            color: var(--text-color);
            font-size: 1.4rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .recommendations h2 i {
            color: var(--primary-color);
            font-size: 1.6rem;
        }

        .recommendations ul {
            list-style: none;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .recommendations li {
            padding: 1.2rem;
            background: var(--card-bg);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .recommendations li:hover {
            transform: translateX(5px);
            box-shadow: var(--shadow);
        }

        .recommendations li i {
            color: var(--primary-color);
            font-size: 1.2rem;
        }

        .export-container {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-top: 3rem;
        }

        .export-btn {
            display: inline-flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 2rem;
            background: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            border-radius: 50px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            font-weight: 500;
            border: none;
            cursor: pointer;
        }

        .export-btn:hover {
            transform: translateY(-3px);
            color: var(--primary-color);
            box-shadow: 0 15px 25px rgba(52, 152, 219, 0.2);
        }

        .export-btn i {
            font-size: 1.2rem;
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            }

            .stat-card h2 {
                font-size: 2rem;
            }

            .dashboard-header h1 {
                font-size: 1.6rem;
            }

            .degree-selector {
                min-width: 200px;
            }

            .recommendations ul {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 300px; /* Slightly smaller on mobile */
            }

            canvas {
                max-height: 240px !important;
            }
        }

        /* Chart.js Customization */
        canvas {
            border-radius: 15px;
            padding: 0.8rem;
            background: var(--card-bg);
            box-shadow: var(--inner-shadow);
            max-height: 280px !important; /* Control maximum height of charts */
        }

        .export-form {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 2rem;
        }

        .export-form .form-group {
            margin-bottom: 1.5rem;
        }

        .export-form label {
            display: block;
            margin-bottom: 0.8rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .export-form select {
            width: 100%;
            max-width: 400px;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="dashboard-header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <h1><i class="fas fa-chart-line"></i>Alumni Survey Analytics</h1>
                    <p>Department: <?php echo htmlspecialchars($selected_degree); ?></p>
                    <?php if ($selected_batch): ?>
                        <p>Batch: <?php echo htmlspecialchars($selected_batch); ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <form method="GET" style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <select name="degree" class="degree-selector" onchange="this.form.submit()">
                            <?php foreach ($available_degrees as $degree): ?>
                                <option value="<?php echo htmlspecialchars($degree); ?>" 
                                        <?php echo $degree === $selected_degree ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($degree); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="batch_id" class="degree-selector" onchange="this.form.submit()">
                            <option value="">All Batches</option>
                            <?php foreach ($available_batches as $batch): ?>
                                <option value="<?php echo htmlspecialchars($batch); ?>"
                                        <?php echo $batch === $selected_batch ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <!-- Overall Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <h3><i class="fas fa-users"></i>Total Responses</h3>
                <h2><?php echo $overall_stats['total_responses']; ?></h2>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-briefcase"></i>Employment Rate</h3>
                <h2><?php 
                    $employed = $overall_stats['status_distribution']['employed'] ?? 0;
                    echo $overall_stats['total_responses'] > 0 
                        ? round(($employed / $overall_stats['total_responses']) * 100, 1) . '%'
                        : '0%';
                ?></h2>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-graduation-cap"></i>Higher Studies</h3>
                <h2><?php 
                    $higher_studies = $overall_stats['status_distribution']['higher_studies'] ?? 0;
                    echo $overall_stats['total_responses'] > 0 
                        ? round(($higher_studies / $overall_stats['total_responses']) * 100, 1) . '%'
                        : '0%';
                ?></h2>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-lightbulb"></i>Entrepreneurs</h3>
                <h2><?php 
                    $entrepreneurs = $overall_stats['status_distribution']['entrepreneur'] ?? 0;
                    echo $overall_stats['total_responses'] > 0 
                        ? round(($entrepreneurs / $overall_stats['total_responses']) * 100, 1) . '%'
                        : '0%';
                ?></h2>
            </div>
        </div>

        <!-- Charts -->
        <div class="chart-grid">
            <div class="chart-container">
                <h2><i class="fas fa-venus-mars"></i>Gender Distribution</h2>
                <canvas id="genderChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-user-tie"></i>Employment Status Distribution</h2>
                <canvas id="statusChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-chart-bar"></i>Program Outcomes Attainment</h2>
                <canvas id="poChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-bullseye"></i>Program Educational Objectives</h2>
                <canvas id="peoChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-tasks"></i>Program Specific Outcomes</h2>
                <canvas id="psoChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-file-alt"></i>Competitive Exam Analysis</h2>
                <canvas id="examChart"></canvas>
            </div>

            <div class="chart-container">
                <h2><i class="fas fa-calendar-alt"></i>Year-wise Analysis</h2>
                <canvas id="yearChart"></canvas>
            </div>
        </div>

        <!-- Course Recommendations -->
        <div class="recommendations">
            <h2><i class="fas fa-star"></i>Top Course Recommendations</h2>
            <ul>
                <?php foreach ($course_recommendations as $course => $count): ?>
                    <li>
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($course) . ' (' . $count . ' recommendations)'; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <!-- Export Options -->
        <?php if ($_SESSION['role'] === 'hod'): ?>
        <div class="recommendations">
            <h2><i class="fas fa-file-export"></i>Export Options</h2>
            <form id="exportForm" class="export-form" style="margin-bottom: 2rem;">
                <!-- Department/Degree Selection -->
                <div class="form-group">
                    <label for="export_degree">Select Department:</label>
                    <select name="export_degree" id="export_degree" class="degree-selector">
                        <option value="">All Departments</option>
                        <?php foreach ($available_degrees as $degree): ?>
                            <option value="<?php echo htmlspecialchars($degree); ?>"
                                    <?php echo $degree === $selected_degree ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($degree); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Batch Selection -->
                <div class="form-group">
                    <label for="batch_year">Select Batch Year:</label>
                    <select name="batch_year" id="batch_year" class="degree-selector">
                        <option value="">All Batches</option>
                        <?php
                        // Get batches based on selected degree
                        $batch_query = "SELECT DISTINCT passing_year FROM alumni_survey";
                        if ($selected_degree) {
                            $batch_query .= " WHERE degree = ?";
                            $stmt = mysqli_prepare($conn, $batch_query);
                            mysqli_stmt_bind_param($stmt, "s", $selected_degree);
                            mysqli_stmt_execute($stmt);
                            $batch_result = mysqli_stmt_get_result($stmt);
                        } else {
                            $batch_result = mysqli_query($conn, $batch_query);
                        }
                        
                        while ($batch = mysqli_fetch_assoc($batch_result)) {
                            $selected = ($batch['passing_year'] == $selected_batch) ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($batch['passing_year']) . '" ' . $selected . '>' . 
                                 htmlspecialchars($batch['passing_year']) . '</option>';
                        }
                        ?>
                    </select>
                </div>
            </form>
            <div class="export-container">
                <a href="#" onclick="exportReport('pdf')" class="export-btn">
                    <i class="fas fa-file-pdf"></i> Export as PDF
                </a>
                <a href="#" onclick="exportReport('excel')" class="export-btn">
                    <i class="fas fa-file-excel"></i> Export as Excel
                </a>
            </div>
        </div>

        <script>
        // Add event listener for degree change to update batch options
        document.getElementById('export_degree').addEventListener('change', function() {
            const degree = this.value;
            const batchSelect = document.getElementById('batch_year');
            
            // Clear current options
            batchSelect.innerHTML = '<option value="">All Batches</option>';
            
            if (degree) {
                // Fetch new batch options based on selected degree
                fetch(`get_batches.php?degree=${encodeURIComponent(degree)}`)
                    .then(response => response.json())
                    .then(batches => {
                        batches.forEach(batch => {
                            const option = document.createElement('option');
                            option.value = batch;
                            option.textContent = batch;
                            batchSelect.appendChild(option);
                        });
                    })
                    .catch(error => console.error('Error fetching batches:', error));
            }
        });

        function exportReport(format) {
            const degree = document.getElementById('export_degree').value;
            const batchYear = document.getElementById('batch_year').value;
            
            // Construct the URL with parameters
            let url = `export_analytics.php?format=${format}`;
            if (degree && degree !== 'All Departments') {
                url += `&degree=${encodeURIComponent(degree)}`;
            }
            if (batchYear && batchYear !== 'All Batches') {
                url += `&batch_id=${encodeURIComponent(batchYear)}`;
            }
            
            // Open in a new tab/window
            window.open(url, '_blank');
        }
        </script>
        <?php endif; ?>
    </div>

    <script>
        // Update Chart.js defaults for neumorphic theme
        Chart.defaults.font.family = "'Poppins', sans-serif";
        Chart.defaults.font.size = 14;
        Chart.defaults.color = '#2c3e50';
        
        // Custom chart options
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: true,
            aspectRatio: 1.5,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        padding: 15,
                        usePointStyle: true,
                        font: {
                            size: 11,
                            weight: '500'
                        },
                        boxWidth: 8
                    }
                }
            }
        };

        // Doughnut specific options
        const doughnutOptions = {
            ...chartOptions,
            cutout: '60%',
            plugins: {
                ...chartOptions.plugins,
                legend: {
                    ...chartOptions.plugins.legend,
                    position: 'right'
                }
            }
        };

        // Bar specific options
        const barOptions = {
            ...chartOptions,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 5,
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 11
                        }
                    }
                }
            }
        };

        // Gender Distribution Chart
        new Chart(document.getElementById('genderChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($overall_stats['gender_distribution'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($overall_stats['gender_distribution'])); ?>,
                    backgroundColor: ['#FF6B6B', '#4ECDC4'],
                    borderWidth: 0
                }]
            },
            options: doughnutOptions
        });

        // Employment Status Chart
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_keys($overall_stats['status_distribution'])); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_values($overall_stats['status_distribution'])); ?>,
                    backgroundColor: ['#45B7D1', '#96CEB4', '#FFEEAD', '#D4A5A5'],
                    borderWidth: 0
                }]
            },
            options: doughnutOptions
        });

        // PO Attainment Chart
        new Chart(document.getElementById('poChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($attainment_analysis['po'])); ?>,
                datasets: [{
                    label: 'Average Rating',
                    data: <?php echo json_encode(array_values($attainment_analysis['po'])); ?>,
                    backgroundColor: '#3498db',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 35
                }]
            },
            options: barOptions
        });

        // PEO Attainment Chart
        new Chart(document.getElementById('peoChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($attainment_analysis['peo'])); ?>,
                datasets: [{
                    label: 'Average Rating',
                    data: <?php echo json_encode(array_values($attainment_analysis['peo'])); ?>,
                    backgroundColor: '#2ecc71',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 35
                }]
            },
            options: barOptions
        });

        // PSO Attainment Chart
        new Chart(document.getElementById('psoChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($attainment_analysis['pso'])); ?>,
                datasets: [{
                    label: 'Average Rating',
                    data: <?php echo json_encode(array_values($attainment_analysis['pso'])); ?>,
                    backgroundColor: '#e67e22',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 35
                }]
            },
            options: barOptions
        });

        // Competitive Exams Chart
        new Chart(document.getElementById('examChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($competitive_exam_stats)); ?>,
                datasets: [{
                    label: 'Number of Students',
                    data: <?php echo json_encode(array_values($competitive_exam_stats)); ?>,
                    backgroundColor: '#9b59b6',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 35
                }]
            },
            options: barOptions
        });

        // Year-wise Analysis Chart
        new Chart(document.getElementById('yearChart'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($year_wise_stats)); ?>,
                datasets: [{
                    label: 'Employed',
                    data: <?php echo json_encode(array_map(function($year) { return $year['employed']; }, $year_wise_stats)); ?>,
                    backgroundColor: '#3498db',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 25
                }, {
                    label: 'Higher Studies',
                    data: <?php echo json_encode(array_map(function($year) { return $year['higher_studies']; }, $year_wise_stats)); ?>,
                    backgroundColor: '#2ecc71',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 25
                }, {
                    label: 'Entrepreneurs',
                    data: <?php echo json_encode(array_map(function($year) { return $year['entrepreneurs']; }, $year_wise_stats)); ?>,
                    backgroundColor: '#e67e22',
                    borderRadius: 8,
                    borderWidth: 0,
                    maxBarThickness: 25
                }]
            },
            options: barOptions
        });
    </script>
</body>
</html>