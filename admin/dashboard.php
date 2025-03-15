<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get current academic year
$academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

// Enhanced statistics with more detailed information
$stats = [
    'students' => [
        'total' => 0,
        'active' => 0,
        'by_year' => [1 => 0, 2 => 0, 3 => 0, 4 => 0]
    ],
    'faculty' => [
        'total' => 0,
        'active' => 0,
        'by_department' => []
    ],
    'feedback' => [
        'total' => 0,
        'pending' => 0,
        'completed' => 0,
        'completion_rate' => 0
    ],
    'departments' => [
        'total' => 0,
        'active_subjects' => 0
    ],
    'exit_surveys' => [
        'total' => 0,
        'completion_rate' => 0
    ]
];

// Get student statistics - Modified query to use batch_years table
$student_query = "SELECT 
    COUNT(s.id) as total,
    SUM(CASE WHEN s.is_active = TRUE THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN batch.current_year_of_study = 1 THEN 1 ELSE 0 END) as year1,
    SUM(CASE WHEN batch.current_year_of_study = 2 THEN 1 ELSE 0 END) as year2,
    SUM(CASE WHEN batch.current_year_of_study = 3 THEN 1 ELSE 0 END) as year3,
    SUM(CASE WHEN batch.current_year_of_study = 4 THEN 1 ELSE 0 END) as year4
FROM students s
LEFT JOIN batch_years batch ON s.batch_id = batch.id";

$result = mysqli_query($conn, $student_query);
if (!$result) {
    die("Error in query: " . mysqli_error($conn));
}
$student_stats = mysqli_fetch_assoc($result);

$stats['students'] = [
    'total' => $student_stats['total'] ?? 0,
    'active' => $student_stats['active'] ?? 0,
    'by_year' => [
        1 => $student_stats['year1'] ?? 0,
        2 => $student_stats['year2'] ?? 0,
        3 => $student_stats['year3'] ?? 0,
        4 => $student_stats['year4'] ?? 0
    ]
];

// Get faculty statistics
$user_query = "SELECT f.*,
    d.name as department_name,
    d.code as department_code,
    (SELECT COUNT(DISTINCT sa.id) 
     FROM subject_assignments sa 
     WHERE sa.faculty_id = f.id 
     AND sa.academic_year_id = ?) as total_subjects,
    (SELECT COUNT(DISTINCT fb.id) 
     FROM feedback fb 
     JOIN subject_assignments sa ON fb.assignment_id = sa.id 
     WHERE sa.faculty_id = f.id 
     AND sa.academic_year_id = ?) as total_feedback,
    (SELECT AVG(fb.cumulative_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as avg_rating
FROM faculty f
JOIN departments d ON f.department_id = d.id
WHERE f.id = ? AND f.is_active = TRUE";

$stmt = mysqli_prepare($conn, $user_query);
mysqli_stmt_bind_param($stmt, "iiii", $current_academic_year['id'], $current_academic_year['id'], $current_academic_year['id'], $current_academic_year['id']);
mysqli_stmt_execute($stmt);
$faculty_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$stats['faculty'] = [
    'total' => $faculty_stats['total'] ?? 0,
    'active' => $faculty_stats['active'] ?? 0,
    'by_department' => [
        $faculty_stats['department_name'] => [
            'total' => $faculty_stats['total'] ?? 0,
            'active' => $faculty_stats['active'] ?? 0
        ]
    ]
];

// Get feedback statistics
$feedback_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT CASE WHEN f.id IS NOT NULL THEN sa.id END) as completed_subjects
FROM subject_assignments sa
LEFT JOIN feedback f ON f.assignment_id = sa.id
WHERE sa.academic_year_id = ?";

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, "i", $current_academic_year['id']);
mysqli_stmt_execute($stmt);
$feedback_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$stats['feedback'] = [
    'total' => $feedback_stats['total_feedback'],
    'pending' => $feedback_stats['total_subjects'] - $feedback_stats['completed_subjects'],
    'completed' => $feedback_stats['completed_subjects'],
    'completion_rate' => $feedback_stats['total_subjects'] > 0 ? 
        round(($feedback_stats['completed_subjects'] / $feedback_stats['total_subjects']) * 100, 2) : 0
];

// Get recent activities with enhanced details
$activities_query = "SELECT 
    ul.*,
    CASE 
        WHEN ul.role = 'student' THEN s.name
        WHEN ul.role = 'faculty' THEN f.name
        WHEN ul.role = 'hod' THEN h.name
        ELSE 'Admin'
    END as user_name
FROM user_logs ul
LEFT JOIN students s ON ul.user_id = s.id AND ul.role = 'student'
LEFT JOIN faculty f ON ul.user_id = f.id AND ul.role = 'faculty'
LEFT JOIN hods h ON ul.user_id = h.id AND ul.role = 'hod'
ORDER BY ul.created_at DESC
LIMIT 10";
$activities_result = mysqli_query($conn, $activities_query);
$recent_activities = mysqli_fetch_all($activities_result, MYSQLI_ASSOC);

// Get pending tasks
$pending_tasks = [
    'feedback_completion' => $stats['feedback']['pending'],
    'inactive_faculty' => $stats['faculty']['total'] - $stats['faculty']['active'],
    'exit_surveys_pending' => $stats['exit_surveys']['total'] - 
        ($stats['exit_surveys']['total'] * $stats['exit_surveys']['completion_rate'] / 100)
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <!-- Include Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
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
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 280px;
            background: var(--bg-color);
            padding: 2rem;
            box-shadow: var(--shadow);
            border-radius: 0 20px 20px 0;
            z-index: 1000;
        }

        .sidebar h2 {
            color: var(--primary-color);
            margin-bottom: 2rem;
            font-size: 1.5rem;
            text-align: center;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem;
            color: var(--text-color);
            text-decoration: none;
            margin-bottom: 0.5rem;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .nav-link i {
            margin-right: 1rem;
            color: var(--primary-color);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
        }

        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card h3 {
            color: var(--text-color);
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-color);
        }

        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .pending-tasks {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .task-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .task-item:hover {
            background: rgba(231, 76, 60, 0.05);
            transform: translateX(5px);
        }

        .task-item:last-child {
            border-bottom: none;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .recent-activity {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .activity-item {
            display: grid;
            grid-template-columns: 2fr 3fr 1fr;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }

        .activity-item:hover {
            background: rgba(231, 76, 60, 0.05);
        }

        .activity-user {
            font-weight: 500;
            color: var(--primary-color);
        }

        .activity-action {
            color: var(--text-color);
        }

        .activity-time {
            color: #666;
            font-size: 0.9rem;
            text-align: right;
        }

        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -280px;
                height: 100vh;
                transition: all 0.3s ease;
            }

            .sidebar.active {
                left: 0;
            }

            .main-content {
                margin-left: 0;
            }

            .chart-grid {
                grid-template-columns: 1fr;
            }

            .activity-item {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2>Admin Panel</h2>
        <nav>
            <a href="dashboard.php" class="nav-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="manage_departments.php" class="nav-link">
                <i class="fas fa-building"></i> Departments
            </a>
            <a href="manage_faculty.php" class="nav-link">
                <i class="fas fa-chalkboard-teacher"></i> Faculty
            </a>
            <a href="manage_students.php" class="nav-link">
                <i class="fas fa-user-graduate"></i> Students
            </a>
            <a href="manage_subjects.php" class="nav-link">
                <i class="fas fa-book"></i> Subjects
            </a>
            <a href="manage_feedback.php" class="nav-link">
                <i class="fas fa-comments"></i> Feedback
            </a>
            <a href="reports.php" class="nav-link">
                <i class="fas fa-chart-bar"></i> Reports
            </a>
            <a href="settings.php" class="nav-link">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>

    <div class="main-content">
        <div class="dashboard-header">
            <div>
                <h1>Admin Dashboard</h1>
                <p>Current Academic Year: <?php echo $current_academic_year['year_range']; ?></p>
            </div>
            <div class="header-actions">
                <button onclick="location.href='settings.php'" class="btn">
                    <i class="fas fa-cog"></i> Settings
                </button>
                <button onclick="location.href='generate_report.php'" class="btn">
                    <i class="fas fa-file-alt"></i> Generate Report
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-container">
            <!-- Previous stat cards with enhanced information -->
            <div class="stat-card">
                <h3>Students</h3>
                <div class="number"><?php echo $stats['students']['active']; ?> / <?php echo $stats['students']['total']; ?></div>
                <div class="stat-details">
                    <?php foreach ($stats['students']['by_year'] as $year => $count): ?>
                        <span>Year <?php echo $year; ?>: <?php echo $count; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Add more enhanced stat cards -->
        </div>

        <!-- Charts -->
        <div class="chart-grid">
            <div class="chart-container">
                <canvas id="feedbackChart"></canvas>
            </div>
            <div class="chart-container">
                <canvas id="facultyChart"></canvas>
            </div>
        </div>

        <!-- Pending Tasks -->
        <div class="pending-tasks">
            <h3>Pending Tasks</h3>
            <?php foreach ($pending_tasks as $task => $count): ?>
                <div class="task-item">
                    <span><?php echo ucwords(str_replace('_', ' ', $task)); ?></span>
                    <span class="badge <?php echo $count > 0 ? 'badge-warning' : 'badge-success'; ?>">
                        <?php echo $count; ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Activity -->
        <div class="recent-activity">
            <h3>Recent Activity</h3>
            <?php foreach ($recent_activities as $activity): ?>
                <div class="activity-item">
                    <span class="activity-user"><?php echo htmlspecialchars($activity['user_name']); ?></span>
                    <span class="activity-action"><?php echo htmlspecialchars($activity['action']); ?></span>
                    <span class="activity-time"><?php echo date('M d, Y H:i', strtotime($activity['created_at'])); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Add active class to current nav link
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });

        // Initialize tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Enhanced chart options
        const chartOptions = {
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        padding: 20,
                        font: {
                            family: 'Poppins'
                        }
                    }
                },
                title: {
                    display: true,
                    font: {
                        family: 'Poppins',
                        size: 16,
                        weight: 500
                    }
                }
            },
            responsive: true,
            maintainAspectRatio: false
        };

        // Initialize charts
        const feedbackCtx = document.getElementById('feedbackChart').getContext('2d');
        new Chart(feedbackCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Pending'],
                datasets: [{
                    data: [
                        <?php echo $stats['feedback']['completed']; ?>,
                        <?php echo $stats['feedback']['pending']; ?>
                    ],
                    backgroundColor: ['#2ecc71', '#e74c3c'],
                    borderWidth: 0,
                    borderRadius: 5
                }]
            },
            options: {
                ...chartOptions,
                cutout: '70%',
                plugins: {
                    ...chartOptions.plugins,
                    title: {
                        ...chartOptions.plugins.title,
                        text: 'Feedback Completion Status'
                    }
                }
            }
        });

        const facultyCtx = document.getElementById('facultyChart').getContext('2d');
        new Chart(facultyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($stats['faculty']['by_department'])); ?>,
                datasets: [{
                    label: 'Active Faculty',
                    data: <?php echo json_encode(array_column($stats['faculty']['by_department'], 'active')); ?>,
                    backgroundColor: '#3498db',
                    borderRadius: 5,
                    borderSkipped: false
                }]
            },
            options: {
                ...chartOptions,
                plugins: {
                    ...chartOptions.plugins,
                    title: {
                        ...chartOptions.plugins.title,
                        text: 'Faculty Distribution by Department'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            display: false
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>