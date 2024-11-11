// admin/dashboard.php
<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get dashboard statistics
$stats = [
    'total_students' => 0,
    'total_faculty' => 0,
    'total_departments' => 0,
    'total_feedback' => 0,
    'active_academic_year' => null,
    'pending_feedback' => 0,
    'completed_feedback' => 0,
    'exit_surveys' => 0
];

// Get current academic year
$academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

// Get statistics
$queries = [
    "SELECT COUNT(*) as count FROM students WHERE is_active = TRUE",
    "SELECT COUNT(*) as count FROM faculty WHERE is_active = TRUE",
    "SELECT COUNT(*) as count FROM departments",
    "SELECT COUNT(*) as count FROM feedback WHERE academic_year_id = " . $current_academic_year['id'],
    "SELECT COUNT(*) as count FROM exit_surveys WHERE academic_year_id = " . $current_academic_year['id']
];

foreach ($queries as $index => $query) {
    $result = mysqli_query($conn, $query);
    $row = mysqli_fetch_assoc($result);
    $stats[array_keys($stats)[$index]] = $row['count'];
}

// Get feedback completion rate
$feedback_stats_query = "SELECT 
    COUNT(DISTINCT s.id) as total_expected,
    COUNT(DISTINCT f.id) as total_submitted
FROM subjects s
LEFT JOIN feedback f ON s.id = f.subject_id
WHERE s.academic_year_id = " . $current_academic_year['id'];

$feedback_stats_result = mysqli_query($conn, $feedback_stats_query);
$feedback_stats = mysqli_fetch_assoc($feedback_stats_result);

$stats['pending_feedback'] = $feedback_stats['total_expected'] - $feedback_stats['total_submitted'];
$stats['completed_feedback'] = $feedback_stats['total_submitted'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #e0e5ec;
            min-height: 100vh;
            display: flex;
        }

        .sidebar {
            width: 250px;
            background: #fff;
            padding: 20px;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            color: #333;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 24px;
            font-weight: 600;
            color: #3498db;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .action-btn {
            background: #3498db;
            color: #fff;
            padding: 15px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .action-btn:hover {
            transform: translateY(-3px);
        }

        .recent-activity {
            background: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 10px;
            color: #333;
            text-decoration: none;
            margin-bottom: 10px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .nav-link:hover {
            background-color: #f0f0f0;
        }

        .nav-link i {
            margin-right: 10px;
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
        <h1>Admin Dashboard</h1>
        <p>Welcome, Admin! Current Academic Year: <?php echo $current_academic_year['year_range']; ?></p>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Total Students</h3>
                <div class="number"><?php echo $stats['total_students']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Faculty</h3>
                <div class="number"><?php echo $stats['total_faculty']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Departments</h3>
                <div class="number"><?php echo $stats['total_departments']; ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Feedback</h3>
                <div class="number"><?php echo $stats['total_feedback']; ?></div>
            </div>
        </div>

        <div class="quick-actions">
            <a href="add_department.php" class="action-btn">
                <i class="fas fa-plus"></i> Add Department
            </a>
            <a href="add_faculty.php" class="action-btn">
                <i class="fas fa-plus"></i> Add Faculty
            </a>
            <a href="add_student.php" class="action-btn">
                <i class="fas fa-plus"></i> Add Student
            </a>
            <a href="generate_report.php" class="action-btn">
                <i class="fas fa-file-alt"></i> Generate Report
            </a>
        </div>

        <div class="recent-activity">
            <h2>Recent Activity</h2>
            <?php
            // Get recent activity logs
            $logs_query = "SELECT * FROM user_logs 
                          ORDER BY created_at DESC 
                          LIMIT 5";
            $logs_result = mysqli_query($conn, $logs_query);
            
            if (mysqli_num_rows($logs_result) > 0) {
                echo "<ul>";
                while ($log = mysqli_fetch_assoc($logs_result)) {
                    echo "<li>{$log['action']} by {$log['role']} at {$log['created_at']}</li>";
                }
                echo "</ul>";
            } else {
                echo "<p>No recent activity</p>";
            }
            ?>
        </div>
    </div>

    <script>
        // Add any JavaScript functionality here
    </script>
</body>
</html>