<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$success_msg = $error_msg = '';

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

// Fetch departments for filter
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$departments = mysqli_query($conn, $dept_query);

// Fetch academic years for filter
$academic_year_query = "SELECT id, year_range FROM academic_years ORDER BY start_date DESC";
$academic_years = mysqli_query($conn, $academic_year_query);

// Build the base query for subject-wise feedback summary
$feedback_query = "SELECT 
    sub.id as subject_id,
    sub.code as subject_code,
    sub.name as subject_name,
    sub.year,
    sub.semester,
    sub.section,
    fac.name as faculty_name,
    d.name as department_name,
    ay.year_range as academic_year,
    COUNT(DISTINCT f.id) as feedback_count,
    ROUND(AVG(f.course_effectiveness_avg), 2) as course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_rating
FROM subjects sub
LEFT JOIN feedback f ON sub.id = f.subject_id
JOIN faculty fac ON sub.faculty_id = fac.id
JOIN departments d ON sub.department_id = d.id
JOIN academic_years ay ON sub.academic_year_id = ay.id
WHERE 1=1";

// Apply filters if set
if (isset($_GET['department_id']) && !empty($_GET['department_id'])) {
    $feedback_query .= " AND d.id = " . intval($_GET['department_id']);
}

if (isset($_GET['academic_year_id']) && !empty($_GET['academic_year_id'])) {
    $feedback_query .= " AND sub.academic_year_id = " . intval($_GET['academic_year_id']);
}

if (isset($_GET['rating_min']) && !empty($_GET['rating_min'])) {
    $feedback_query .= " AND f.cumulative_avg >= " . floatval($_GET['rating_min']);
}

if (isset($_GET['rating_max']) && !empty($_GET['rating_max'])) {
    $feedback_query .= " AND f.cumulative_avg <= " . floatval($_GET['rating_max']);
}

$feedback_query .= " GROUP BY sub.id ORDER BY feedback_count DESC";
$feedback_result = mysqli_query($conn, $feedback_query);

// Get overall statistics
$stats_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    ROUND(AVG(f.cumulative_avg), 2) as avg_rating,
    COUNT(DISTINCT f.student_id) as total_students,
    COUNT(DISTINCT f.subject_id) as total_subjects,
    COUNT(DISTINCT sub.faculty_id) as total_faculty
FROM feedback f
JOIN subjects sub ON f.subject_id = sub.id";
$stats_result = mysqli_query($conn, $stats_query);
$stats = mysqli_fetch_assoc($stats_result);

// Get department-wise statistics
$dept_stats_query = "SELECT 
    d.name as department_name,
    COUNT(DISTINCT f.id) as feedback_count,
    ROUND(AVG(f.cumulative_avg), 2) as avg_rating
FROM departments d
LEFT JOIN subjects sub ON d.id = sub.department_id
LEFT JOIN feedback f ON sub.id = f.subject_id
GROUP BY d.id
ORDER BY feedback_count DESC";
$dept_stats_result = mysqli_query($conn, $dept_stats_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Feedback - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <style>
        :root {
            --primary-color: #e67e22;  /* Orange theme for Feedback */
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }

        .filter-section {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-family: inherit;
        }

        .feedback-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .feedback-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 320px;
            max-width: 450px;
            margin: 0 auto;
            width: 100%;
        }

        .feedback-card:hover {
            transform: translateY(-5px);
        }

        .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .feedback-info {
            flex: 1;
        }

        .feedback-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .feedback-meta {
            font-size: 0.9rem;
            color: #666;
        }

        .feedback-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            flex: 1;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #666;
            font-weight: 500;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .feedback-ratings {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .rating-item {
            text-align: center;
            padding: 0.5rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .rating-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.3rem;
        }

        .rating-label {
            font-size: 0.85rem;
            color: #666;
        }

        .feedback-actions {
            display: flex;
            gap: 0.8rem;
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .btn-action {
            flex: 1;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
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

            .feedback-grid {
                grid-template-columns: 1fr;
            }

            .feedback-card {
                max-width: 100%;
            }

            .feedback-details,
            .feedback-ratings {
                grid-template-columns: 1fr;
            }

            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }
        }

        /* Add new styles for the table */
        .table-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .custom-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .custom-table th {
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid rgba(0,0,0,0.1);
        }

        .custom-table td {
            padding: 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .custom-table tr {
            transition: transform 0.3s ease;
        }

        .custom-table tr:hover {
            transform: translateY(-2px);
        }

        .rating-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-weight: 500;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .rating-excellent {
            color: #27ae60;
        }

        .rating-good {
            color: #2980b9;
        }

        .rating-average {
            color: #f39c12;
        }

        .rating-poor {
            color: #e74c3c;
        }

        .department-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .dept-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .dept-card:hover {
            transform: translateY(-5px);
        }

        .dept-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .dept-stats {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dept-stat {
            text-align: center;
        }

        .dept-stat-value {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .dept-stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .chart-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            height: 400px;
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
            <a href="manage_feedback.php" class="nav-link active">
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
            <h1>Feedback Analysis</h1>
        </div>

        <!-- Statistics Section -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_feedback']; ?></div>
                <div class="stat-label">Total Feedback</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['avg_rating']; ?></div>
                <div class="stat-label">Average Rating</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_students']; ?></div>
                <div class="stat-label">Students Participated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_subjects']; ?></div>
                <div class="stat-label">Subjects Covered</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_faculty']; ?></div>
                <div class="stat-label">Faculty Members</div>
            </div>
        </div>

        <!-- Department-wise Statistics -->
        <h2 class="section-title">Department Analysis</h2>
        <div class="department-stats">
            <?php while ($dept_stat = mysqli_fetch_assoc($dept_stats_result)): ?>
                <div class="dept-card">
                    <div class="dept-name"><?php echo htmlspecialchars($dept_stat['department_name']); ?></div>
                    <div class="dept-stats">
                        <div class="dept-stat">
                            <div class="dept-stat-value"><?php echo $dept_stat['feedback_count']; ?></div>
                            <div class="dept-stat-label">Feedbacks</div>
                        </div>
                        <div class="dept-stat">
                            <div class="dept-stat-value"><?php echo $dept_stat['avg_rating'] ?? 'N/A'; ?></div>
                            <div class="dept-stat-label">Avg Rating</div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" id="filterForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <select name="department_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Departments</option>
                            <?php
                            mysqli_data_seek($departments, 0);
                            while ($dept = mysqli_fetch_assoc($departments)): ?>
                                <option value="<?php echo $dept['id']; ?>" <?php echo (isset($_GET['department_id']) && $_GET['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <select name="academic_year_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Academic Years</option>
                            <?php
                            mysqli_data_seek($academic_years, 0);
                            while ($year = mysqli_fetch_assoc($academic_years)): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo (isset($_GET['academic_year_id']) && $_GET['academic_year_id'] == $year['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <button type="button" class="btn-action" onclick="resetFilters()">
                        <i class="fas fa-undo"></i> Reset Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Subject-wise Feedback Table -->
        <div class="table-container">
            <table id="feedbackTable" class="custom-table">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Faculty</th>
                        <th>Department</th>
                        <th>Year & Semester</th>
                        <th>Feedbacks</th>
                        <th>Course Effectiveness</th>
                        <th>Teaching Effectiveness</th>
                        <th>Overall Rating</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($feedback = mysqli_fetch_assoc($feedback_result)): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($feedback['subject_code']); ?></strong><br>
                                <?php echo htmlspecialchars($feedback['subject_name']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($feedback['faculty_name']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['department_name']); ?></td>
                            <td>
                                Year <?php echo $feedback['year']; ?>, Sem <?php echo $feedback['semester']; ?><br>
                                Section <?php echo $feedback['section']; ?>
                            </td>
                            <td><?php echo $feedback['feedback_count']; ?></td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($feedback['course_effectiveness']); ?>">
                                    <?php echo number_format($feedback['course_effectiveness'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($feedback['teaching_effectiveness']); ?>">
                                    <?php echo number_format($feedback['teaching_effectiveness'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($feedback['overall_rating']); ?>">
                                    <?php echo number_format($feedback['overall_rating'], 2); ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_subject_feedback.php?subject_id=<?php echo $feedback['subject_id']; ?>" class="btn-action">
                                    <i class="fas fa-chart-bar"></i> Details
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#feedbackTable').DataTable({
                pageLength: 10,
                order: [[4, 'desc']],
                responsive: true
            });
        });

        function resetFilters() {
            document.getElementById('filterForm').reset();
            document.getElementById('filterForm').submit();
        }

        function getRatingClass(rating) {
            if (rating >= 4.5) return 'rating-excellent';
            if (rating >= 4.0) return 'rating-good';
            if (rating >= 3.0) return 'rating-average';
            return 'rating-poor';
        }
    </script>
</body>
</html>

<?php
function getRatingClass($rating) {
    if ($rating >= 4.5) return 'rating-excellent';
    if ($rating >= 4.0) return 'rating-good';
    if ($rating >= 3.0) return 'rating-average';
    return 'rating-poor';
}
?> 