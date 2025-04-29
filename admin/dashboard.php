<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Department filter based on admin type
$department_filter = "";
$department_join = "";
$department_params = [];
$param_types = "";

// If department admin, restrict data to their department
if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
    $department_filter = "WHERE d.id = ?";
    $department_params[] = $_SESSION['department_id'];
    $param_types = "i";
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
        'by_department' => [],
        'professors' => 0,
        'associate_professors' => 0,
        'assistant_professors' => 0,
        'avg_experience' => 0
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

// Get student statistics - Modified query to use batch_years table and apply department filter
$student_query = "SELECT 
    COUNT(s.id) as total,
    SUM(CASE WHEN s.is_active = TRUE THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN batch.current_year_of_study = 1 THEN 1 ELSE 0 END) as year1,
    SUM(CASE WHEN batch.current_year_of_study = 2 THEN 1 ELSE 0 END) as year2,
    SUM(CASE WHEN batch.current_year_of_study = 3 THEN 1 ELSE 0 END) as year3,
    SUM(CASE WHEN batch.current_year_of_study = 4 THEN 1 ELSE 0 END) as year4
FROM students s
LEFT JOIN batch_years batch ON s.batch_id = batch.id
LEFT JOIN departments d ON s.department_id = d.id
$department_filter";

if (!empty($department_params)) {
    $stmt = mysqli_prepare($conn, $student_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$department_params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
} else {
    $result = mysqli_query($conn, $student_query);
}

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

// Get faculty statistics with department filter
$faculty_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN f.is_active = TRUE THEN 1 ELSE 0 END) as active,
    COUNT(CASE WHEN f.designation = 'Professor' THEN 1 END) as professors,
    COUNT(CASE WHEN f.designation = 'Associate Professor' THEN 1 END) as associate_professors,
    COUNT(CASE WHEN f.designation = 'Assistant Professor' THEN 1 END) as assistant_professors,
    AVG(f.experience) as avg_experience
FROM faculty f
JOIN departments d ON f.department_id = d.id
$department_filter";

if (!empty($department_params)) {
    $stmt = mysqli_prepare($conn, $faculty_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$department_params);
    mysqli_stmt_execute($stmt);
    $faculty_result = mysqli_stmt_get_result($stmt);
} else {
    $faculty_result = mysqli_query($conn, $faculty_query);
}

if (!$faculty_result) {
    die("Error in faculty query: " . mysqli_error($conn));
}
$faculty_stats = mysqli_fetch_assoc($faculty_result);

// Initialize faculty stats with default values if null
$stats['faculty'] = [
    'total' => $faculty_stats['total'] ?? 0,
    'active' => $faculty_stats['active'] ?? 0,
    'by_department' => [],
    'professors' => $faculty_stats['professors'] ?? 0,
    'associate_professors' => $faculty_stats['associate_professors'] ?? 0,
    'assistant_professors' => $faculty_stats['assistant_professors'] ?? 0,
    'avg_experience' => round($faculty_stats['avg_experience'] ?? 0, 1)
];

// Get department-wise faculty count
$dept_faculty_query = "SELECT 
    d.name as department_name,
    COUNT(*) as total,
    SUM(CASE WHEN f.is_active = TRUE THEN 1 ELSE 0 END) as active
FROM faculty f
JOIN departments d ON f.department_id = d.id
$department_filter
GROUP BY d.id, d.name";

if (!empty($department_params)) {
    $stmt = mysqli_prepare($conn, $dept_faculty_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$department_params);
    mysqli_stmt_execute($stmt);
    $dept_faculty_result = mysqli_stmt_get_result($stmt);
} else {
    $dept_faculty_result = mysqli_query($conn, $dept_faculty_query);
}

if ($dept_faculty_result) {
    while ($row = mysqli_fetch_assoc($dept_faculty_result)) {
        $stats['faculty']['by_department'][$row['department_name']] = [
            'total' => $row['total'],
            'active' => $row['active']
        ];
    }
}

// Get feedback statistics
$feedback_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT CASE WHEN f.id IS NOT NULL THEN sa.id END) as completed_subjects
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN departments d ON s.department_id = d.id
LEFT JOIN feedback f ON f.assignment_id = sa.id
WHERE sa.academic_year_id = ?
" . (!empty($department_filter) ? "AND d.id = ?" : "");

$feedback_params = [$current_academic_year['id']];
$feedback_param_types = "i";

if (!empty($department_params)) {
    $feedback_params[] = $_SESSION['department_id'];
    $feedback_param_types .= "i";
}

$stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($stmt, $feedback_param_types, ...$feedback_params);
mysqli_stmt_execute($stmt);
$feedback_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$stats['feedback'] = [
    'total' => $feedback_stats['total_feedback'] ?? 0,
    'pending' => ($feedback_stats['total_subjects'] ?? 0) - ($feedback_stats['completed_subjects'] ?? 0),
    'completed' => $feedback_stats['completed_subjects'] ?? 0,
    'completion_rate' => ($feedback_stats['total_subjects'] ?? 0) > 0 ? 
        round((($feedback_stats['completed_subjects'] ?? 0) / ($feedback_stats['total_subjects'] ?? 0)) * 100, 2) : 0
];

// Get recent activities with enhanced details and department filter
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
LEFT JOIN hods h ON ul.user_id = h.id AND ul.role = 'hod'";

// Add department filter to activities if department admin
if (!empty($department_params)) {
    $activities_query .= " 
    LEFT JOIN departments d ON 
        (ul.role = 'student' AND s.department_id = d.id) OR 
        (ul.role = 'faculty' AND f.department_id = d.id) OR 
        (ul.role = 'hod' AND h.department_id = d.id)
    WHERE (d.id = ? OR ul.role = 'admin')";
    
    $activities_query .= " ORDER BY ul.created_at DESC LIMIT 10";
    
    $stmt = mysqli_prepare($conn, $activities_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $activities_result = mysqli_stmt_get_result($stmt);
} else {
    $activities_query .= " ORDER BY ul.created_at DESC LIMIT 10";
    $activities_result = mysqli_query($conn, $activities_query);
}

$recent_activities = mysqli_fetch_all($activities_result, MYSQLI_ASSOC);

// Get pending tasks
$pending_tasks = [
    'feedback_completion' => $stats['feedback']['pending'],
    'inactive_faculty' => $stats['faculty']['total'] - $stats['faculty']['active'],
    'exit_surveys_pending' => $stats['exit_surveys']['total'] - 
        ($stats['exit_surveys']['total'] * $stats['exit_surveys']['completion_rate'] / 100)
];

?>
<?php
include_once "includes/header.php";
include_once "includes/sidebar.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
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
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px;
            margin-top: 0; /* No need for margin-top as header.php sets body padding-top */
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

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: var(--bg-color);
            margin: 5% auto;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 800px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(0,0,0,0.1);
        }

        .modal-header h2 {
            color: var(--text-color);
            font-size: 1.5rem;
            margin: 0;
        }

        .close {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            color: var(--text-color);
            font-size: 1.5rem;
            border: none;
        }

        .close:hover {
            box-shadow: var(--inner-shadow);
            color: #e74c3c;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }

        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 180px;
            border-radius: 15px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-wrapper:hover {
            box-shadow: var(--shadow);
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-content {
            text-align: center;
            padding: 20px;
        }

        .upload-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .upload-text {
            color: var(--text-color);
            font-size: 1.1rem;
        }

        .upload-text span {
            color: var(--primary-color);
            font-weight: 500;
        }

        .upload-text small {
            display: block;
            margin-top: 0.5rem;
            opacity: 0.7;
        }

        .template-info {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1rem 0;
            box-shadow: var(--shadow);
        }

        .template-info h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .template-info p {
            margin: 0.8rem 0;
            color: var(--text-color);
        }

        .template-info ol, .template-info ul {
            margin: 1rem 0 1rem 1.5rem;
            color: var(--text-color);
        }

        .template-info li {
            margin: 0.5rem 0;
            line-height: 1.5;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            color: var(--text-color);
        }

        .btn:hover {
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.8);
            transform: translateY(-2px);
        }

        .btn:active {
            box-shadow: var(--inner-shadow);
            transform: translateY(0);
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-color);
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 2% auto;
                padding: 1rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="dashboard-header">
            <div>
                <h1>Admin Dashboard</h1>
                <p>Current Academic Year: <?php echo $current_academic_year['year_range']; ?></p>
            </div>
            <div class="header-actions">
                <button onclick="location.href='import_data.php'" class="btn">
                    <i class="fas fa-file-import"></i> Import Data
                </button>
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

        <!-- Import Modal -->
        <div id="importModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2><i class="fas fa-file-import"></i> Import Data</h2>
                    <button class="close" onclick="closeImportModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" action="import_data.php" enctype="multipart/form-data" id="importForm">
                        <div class="form-group">
                            <label for="import_type">Select Import Type</label>
                            <select name="import_type" id="import_type" class="form-control" required onchange="showTemplate()">
                                <option value="">Choose type of data to import...</option>
                                <option value="students">Student Data</option>
                                <option value="faculty">Faculty Data</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Upload Excel File</label>
                            <div class="file-upload-wrapper">
                                <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required>
                                <div class="upload-content">
                                    <div class="upload-icon">
                                        <i class="fas fa-file-excel"></i>
                                    </div>
                                    <div class="upload-text">
                                        Drag and drop your Excel file here or <span>browse</span>
                                        <small>Supported formats: .xlsx, .xls</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="template-info" id="studentTemplate" style="display: none;">
                            <h4><i class="fas fa-user-graduate"></i> Student Import Template</h4>
                            <p>Required columns:</p>
                            <ul>
                                <li><strong>roll_number</strong> - Unique identifier</li>
                                <li><strong>name</strong> - Full name</li>
                                <li><strong>department_id</strong> - Department ID</li>
                                <li><strong>batch_name</strong> - e.g., 2023-27</li>
                            </ul>
                        </div>

                        <div class="template-info" id="facultyTemplate" style="display: none;">
                            <h4><i class="fas fa-chalkboard-teacher"></i> Faculty Import Template</h4>
                            <p>Required columns:</p>
                            <ul>
                                <li><strong>faculty_id</strong> - Unique identifier</li>
                                <li><strong>name</strong> - Full name</li>
                                <li><strong>department_id</strong> - Department ID</li>
                            </ul>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn">
                                <i class="fas fa-upload"></i> Import Data
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="closeImportModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>
            </div>
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

        function showImportModal() {
            document.getElementById('importModal').style.display = 'block';
        }

        function closeImportModal() {
            document.getElementById('importModal').style.display = 'none';
            document.getElementById('importForm').reset();
            document.getElementById('studentTemplate').style.display = 'none';
            document.getElementById('facultyTemplate').style.display = 'none';
        }

        function showTemplate() {
            const importType = document.getElementById('import_type').value;
            document.getElementById('studentTemplate').style.display = 'none';
            document.getElementById('facultyTemplate').style.display = 'none';
            
            if (importType === 'students') {
                document.getElementById('studentTemplate').style.display = 'block';
            } else if (importType === 'faculty') {
                document.getElementById('facultyTemplate').style.display = 'block';
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('importModal');
            if (event.target === modal) {
                closeImportModal();
            }
        }
    </script>
</body>
</html>