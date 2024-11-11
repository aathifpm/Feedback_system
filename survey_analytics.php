<?php
session_start();
include 'functions.php';

// Check authorization
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'hod', 'faculty'])) {
    header('Location: login.php');
    exit();
}

// Fetch survey data
function fetchSurveyData($conn) {
    $query = "SELECT 
        es.*,
        s.name as student_name,
        s.roll_number,
        s.register_number,
        d.name as department_name,
        ay.year_range as academic_year
    FROM exit_surveys es
    JOIN students s ON es.student_id = s.id
    JOIN departments d ON es.department_id = d.id
    JOIN academic_years ay ON es.academic_year_id = ay.id
    WHERE es.is_active = TRUE
    ORDER BY es.submitted_at DESC";
    
    $result = mysqli_query($conn, $query);
    $surveys = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $surveys[] = $row;
    }
    return $surveys;
}

$surveys = fetchSurveyData($conn);

// Process data for charts
function processRatings($surveys, $field) {
    $ratings = array_fill(1, 5, 0);
    foreach ($surveys as $survey) {
        $data = json_decode($survey[$field], true);
        foreach ($data as $rating) {
            $ratings[$rating]++;
        }
    }
    return $ratings;
}

// Calculate averages for different sections
$po_ratings = processRatings($surveys, 'po_ratings');
$pso_ratings = processRatings($surveys, 'pso_ratings');
$program_satisfaction = processRatings($surveys, 'program_satisfaction');
$infrastructure_satisfaction = processRatings($surveys, 'infrastructure_satisfaction');

// Process employment data
$employment_status = [];
foreach ($surveys as $survey) {
    $emp_data = json_decode($survey['employment_status'], true);
    $status = $emp_data['status'];
    $employment_status[$status] = ($employment_status[$status] ?? 0) + 1;
}

// Process ratings with statement information
function processDetailedRatings($surveys, $field, $statements) {
    $ratings = array();
    foreach ($statements as $stmt) {
        $ratings[$stmt['id']] = array(
            'statement' => $stmt['statement'],
            'ratings' => array_fill(1, 5, 0)
        );
    }
    
    foreach ($surveys as $survey) {
        $data = json_decode($survey[$field], true);
        foreach ($data as $stmt_id => $rating) {
            if (isset($ratings[$stmt_id])) {
                $ratings[$stmt_id]['ratings'][$rating]++;
            }
        }
    }
    return $ratings;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exit Survey Analytics</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .dashboard {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .chart-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        .chart-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-col {
            flex: 1;
        }
        .filters {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
        }
        .stat-label {
            color: #666;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="dashboard">
        <h1>Exit Survey Analytics Dashboard</h1>

        <!-- Filters -->
        <div class="filters">
            <label>
                Year:
                <select id="yearFilter">
                    <option value="all">All Years</option>
                    <?php
                    $years = array_unique(array_map(function($survey) {
                        return date('Y', strtotime($survey['created_at']));
                    }, $surveys));
                    foreach ($years as $year) {
                        echo "<option value='$year'>$year</option>";
                    }
                    ?>
                </select>
            </label>
            
            <label>
                Department:
                <select id="deptFilter">
                    <option value="all">All Departments</option>
                    <?php
                    $departments = array_unique(array_map(function($survey) {
                        return $survey['department_name'];
                    }, $surveys));
                    foreach ($departments as $dept) {
                        echo "<option value='$dept'>$dept</option>";
                    }
                    ?>
                </select>
            </label>
        </div>

        <!-- Summary Statistics -->
        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($surveys); ?></div>
                <div class="stat-label">Total Responses</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $employment_status['employed'] ?? 0; ?></div>
                <div class="stat-label">Employed Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $employment_status['higher_studies'] ?? 0; ?></div>
                <div class="stat-label">Pursuing Higher Studies</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="chart-row">
            <div class="chart-col">
                <div class="chart-container">
                    <canvas id="poChart"></canvas>
                </div>
            </div>
            <div class="chart-col">
                <div class="chart-container">
                    <canvas id="psoChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-row">
            <div class="chart-col">
                <div class="chart-container">
                    <canvas id="employmentChart"></canvas>
                </div>
            </div>
            <div class="chart-col">
                <div class="chart-container">
                    <canvas id="satisfactionChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-row">
            <div class="chart-container">
                <canvas id="infrastructureChart"></canvas>
            </div>
        </div>
    </div>

    <script>
    // Chart configuration and data
    const poData = <?php echo json_encode(array_values($po_ratings)); ?>;
    const psoData = <?php echo json_encode(array_values($pso_ratings)); ?>;
    const employmentData = <?php echo json_encode($employment_status); ?>;
    const programSatisfactionData = <?php echo json_encode(array_values($program_satisfaction)); ?>;
    const infrastructureData = <?php echo json_encode(array_values($infrastructure_satisfaction)); ?>;

    // Program Outcomes Chart
    new Chart(document.getElementById('poChart'), {
        type: 'bar',
        data: {
            labels: ['Poor', 'Fair', 'Good', 'Very Good', 'Excellent'],
            datasets: [{
                label: 'Program Outcomes Ratings',
                data: poData,
                backgroundColor: 'rgba(54, 162, 235, 0.5)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Program Outcomes Distribution'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // PSO Chart
    new Chart(document.getElementById('psoChart'), {
        type: 'bar',
        data: {
            labels: ['Poor', 'Fair', 'Good', 'Very Good', 'Excellent'],
            datasets: [{
                label: 'Program Specific Outcomes Ratings',
                data: psoData,
                backgroundColor: 'rgba(255, 99, 132, 0.5)',
                borderColor: 'rgba(255, 99, 132, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Program Specific Outcomes Distribution'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Employment Status Chart
    new Chart(document.getElementById('employmentChart'), {
        type: 'pie',
        data: {
            labels: Object.keys(employmentData),
            datasets: [{
                data: Object.values(employmentData),
                backgroundColor: [
                    'rgba(75, 192, 192, 0.5)',
                    'rgba(255, 206, 86, 0.5)',
                    'rgba(153, 102, 255, 0.5)'
                ],
                borderColor: [
                    'rgba(75, 192, 192, 1)',
                    'rgba(255, 206, 86, 1)',
                    'rgba(153, 102, 255, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Employment Status Distribution'
                }
            }
        }
    });

    // Program Satisfaction Chart
    new Chart(document.getElementById('satisfactionChart'), {
        type: 'radar',
        data: {
            labels: ['Quality', 'Enrichment', 'Support', 'Seminars', 'Conferences', 'Courses', 'Visits'],
            datasets: [{
                label: 'Program Satisfaction',
                data: programSatisfactionData,
                backgroundColor: 'rgba(255, 159, 64, 0.2)',
                borderColor: 'rgba(255, 159, 64, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Program Satisfaction Analysis'
                }
            },
            scales: {
                r: {
                    beginAtZero: true,
                    max: 5
                }
            }
        }
    });

    // Infrastructure Satisfaction Chart
    new Chart(document.getElementById('infrastructureChart'), {
        type: 'bar',
        data: {
            labels: ['Classrooms', 'Labs', 'Library', 'Mess', 'Hostel', 'Transport', 'Sports'],
            datasets: [{
                label: 'Infrastructure Ratings',
                data: infrastructureData,
                backgroundColor: 'rgba(153, 102, 255, 0.5)',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Infrastructure Satisfaction Analysis'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Filter functionality
    document.getElementById('yearFilter').addEventListener('change', filterData);
    document.getElementById('deptFilter').addEventListener('change', filterData);

    function filterData() {
        const year = document.getElementById('yearFilter').value;
        const dept = document.getElementById('deptFilter').value;
        
        // Add AJAX call to fetch filtered data
        fetch(`get_filtered_data.php?year=${year}&dept=${dept}`)
            .then(response => response.json())
            .then(data => {
                // Update all charts with new data
                updateCharts(data);
            });
    }
    </script>
</body>
</html> 