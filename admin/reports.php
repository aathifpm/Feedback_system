<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Department filter based on admin type
$department_filter = "";
$department_params = [];
$param_types = "";

// If department admin, restrict data to their department
if (!is_super_admin()) {
    $department_filter = " AND d.id = ?";
    $department_params[] = $_SESSION['department_id'];
    $param_types = "i";
}

// Fetch departments for dropdown - department admins only see their department
if (is_super_admin()) {
    $dept_query = "SELECT id, name FROM departments ORDER BY name";
    $departments = mysqli_query($conn, $dept_query);
} else {
    $dept_query = "SELECT id, name FROM departments WHERE id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $departments = mysqli_stmt_get_result($stmt);
}

// Get academic years for filters
$years_query = "SELECT * FROM academic_years ORDER BY start_date DESC";
$years_result = mysqli_query($conn, $years_query);

// Prepare filters
$selected_department = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$selected_year = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;
$selected_semester = isset($_GET['semester']) ? intval($_GET['semester']) : 0;

// Department admin can only see their department
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $selected_department = $_SESSION['department_id'];
}

// Build the where clause based on filters
$filter_where = [];
$filter_params = [];
$filter_types = "";

if ($selected_department > 0) {
    $filter_where[] = "d.id = ?";
    $filter_params[] = $selected_department;
    $filter_types .= "i";
} else if (!is_super_admin() && isset($_SESSION['department_id'])) {
    // Force department filter for department admins
    $filter_where[] = "d.id = ?";
    $filter_params[] = $_SESSION['department_id'];
    $filter_types .= "i";
}

if ($selected_year > 0) {
    $filter_where[] = "sa.academic_year_id = ?";
    $filter_params[] = $selected_year;
    $filter_types .= "i";
}

if ($selected_semester > 0) {
    $filter_where[] = "sa.semester = ?";
    $filter_params[] = $selected_semester;
    $filter_types .= "i";
}

$where_clause = count($filter_where) > 0 ? " WHERE " . implode(" AND ", $filter_where) : "";

// Get feedback data with applied filters
$feedback_query = "SELECT 
    sa.id as assignment_id,
    s.code as subject_code,
    s.name as subject_name,
    f.name as faculty_name,
    f.faculty_id,
    d.name as department_name,
    ay.year_range as academic_year,
    sa.year as year_of_study,
    sa.semester,
    sa.section,
    COUNT(fb.id) as feedback_count,
    COALESCE(ROUND(AVG(fb.cumulative_avg), 2), 0) as average_rating,
    COALESCE(ROUND(AVG(fb.course_effectiveness_avg), 2), 0) as ce_avg,
    COALESCE(ROUND(AVG(fb.teaching_effectiveness_avg), 2), 0) as te_avg,
    COALESCE(ROUND(AVG(fb.resources_admin_avg), 2), 0) as ra_avg,
    COALESCE(ROUND(AVG(fb.assessment_learning_avg), 2), 0) as al_avg,
    COALESCE(ROUND(AVG(fb.course_outcomes_avg), 2), 0) as co_avg
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
JOIN departments d ON s.department_id = d.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
$where_clause
GROUP BY sa.id
ORDER BY d.name, s.code, sa.year, sa.semester, sa.section";

if (count($filter_params) > 0) {
    $stmt = mysqli_prepare($conn, $feedback_query);
    mysqli_stmt_bind_param($stmt, $filter_types, ...$filter_params);
    mysqli_stmt_execute($stmt);
    $feedback_result = mysqli_stmt_get_result($stmt);
} else {
    $feedback_result = mysqli_query($conn, $feedback_query);
}

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

// Fetch all academic years for filter
$academic_years_query = "SELECT id, year_range FROM academic_years ORDER BY start_date DESC";
$academic_years = mysqli_query($conn, $academic_years_query);

// Overall System Statistics
$overall_stats_query = "SELECT 
    COUNT(DISTINCT f.id) as total_feedback,
    COUNT(DISTINCT f.student_id) as total_students,
    COUNT(DISTINCT s.id) as total_subjects,
    COUNT(DISTINCT sa.faculty_id) as total_faculty,
    ROUND(AVG(f.course_effectiveness_avg), 2) as avg_course_effectiveness,
    ROUND(AVG(f.teaching_effectiveness_avg), 2) as avg_teaching_effectiveness,
    ROUND(AVG(f.resources_admin_avg), 2) as avg_resources_admin,
    ROUND(AVG(f.assessment_learning_avg), 2) as avg_assessment_learning,
    ROUND(AVG(f.course_outcomes_avg), 2) as avg_course_outcomes,
    ROUND(AVG(f.cumulative_avg), 2) as overall_avg
FROM feedback f
JOIN subject_assignments sa ON f.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN departments d ON s.department_id = d.id
WHERE sa.academic_year_id = ?";

if (!is_super_admin()) {
    $overall_stats_query .= " AND d.id = ?";
    $stmt = mysqli_prepare($conn, $overall_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $_SESSION['department_id']);
} else if ($selected_department > 0) {
    $overall_stats_query .= " AND d.id = ?";
    $stmt = mysqli_prepare($conn, $overall_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $selected_department);
} else {
    $stmt = mysqli_prepare($conn, $overall_stats_query);
    mysqli_stmt_bind_param($stmt, "i", $selected_year);
}

mysqli_stmt_execute($stmt);
$overall_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

// Department-wise Analysis
$dept_stats_query = "SELECT 
    d.name as department_name,
    COUNT(DISTINCT f.id) as feedback_count,
    COUNT(DISTINCT sa.faculty_id) as faculty_count,
    COUNT(DISTINCT s.id) as subjects_count,
    ROUND(AVG(f.cumulative_avg), 2) as avg_rating
FROM departments d
LEFT JOIN subjects s ON d.id = s.department_id
LEFT JOIN subject_assignments sa ON s.id = sa.subject_id
LEFT JOIN feedback f ON f.assignment_id = sa.id AND sa.academic_year_id = ?
WHERE 1=1";

// Apply department filter for department admins
if (!is_super_admin()) {
    $dept_stats_query .= " AND d.id = ?";
    $stmt = mysqli_prepare($conn, $dept_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $_SESSION['department_id']);
} else {
    $stmt = mysqli_prepare($conn, $dept_stats_query);
    mysqli_stmt_bind_param($stmt, "i", $selected_year);
}

$dept_stats_query .= " GROUP BY d.id
ORDER BY avg_rating DESC";

mysqli_stmt_execute($stmt);
$dept_stats_result = mysqli_stmt_get_result($stmt);

// Top Performing Faculty
$faculty_stats_query = "SELECT 
    f.name as faculty_name,
    d.name as department_name,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
FROM faculty f
JOIN departments d ON f.department_id = d.id
JOIN subject_assignments sa ON f.id = sa.faculty_id
JOIN feedback fb ON fb.assignment_id = sa.id
WHERE sa.academic_year_id = ?";

if (!is_super_admin()) {
    $faculty_stats_query .= " AND f.department_id = ?";
    $stmt = mysqli_prepare($conn, $faculty_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $_SESSION['department_id']);
} else if ($selected_department > 0) {
    $faculty_stats_query .= " AND f.department_id = ?";
    $stmt = mysqli_prepare($conn, $faculty_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $selected_department);
} else {
    $stmt = mysqli_prepare($conn, $faculty_stats_query);
    mysqli_stmt_bind_param($stmt, "i", $selected_year);
}

$faculty_stats_query .= " GROUP BY f.id HAVING feedback_count >= 10
ORDER BY avg_rating DESC LIMIT 10";

mysqli_stmt_execute($stmt);
$faculty_stats_result = mysqli_stmt_get_result($stmt);

// Subject-wise Analysis
$subject_stats_query = "SELECT 
    s.code as subject_code,
    s.name as subject_name,
    f.name as faculty_name,
    d.name as department_name,
    COUNT(fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
JOIN faculty f ON sa.faculty_id = f.id
JOIN departments d ON s.department_id = d.id
JOIN feedback fb ON fb.assignment_id = sa.id
WHERE sa.academic_year_id = ?";

if (!is_super_admin()) {
    $subject_stats_query .= " AND s.department_id = ?";
    $stmt = mysqli_prepare($conn, $subject_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $_SESSION['department_id']);
} else if ($selected_department > 0) {
    $subject_stats_query .= " AND s.department_id = ?";
    $stmt = mysqli_prepare($conn, $subject_stats_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_year, $selected_department);
} else {
    $stmt = mysqli_prepare($conn, $subject_stats_query);
    mysqli_stmt_bind_param($stmt, "i", $selected_year);
}

$subject_stats_query .= " GROUP BY s.id
ORDER BY avg_rating DESC LIMIT 20";

mysqli_stmt_execute($stmt);
$subject_stats_result = mysqli_stmt_get_result($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.dataTables.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #9b59b6;
            --secondary-color: #8e44ad;
            --accent-color: #9b59b6;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --header-height: 90px;
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
            padding-top: var(--header-height);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
            }
        }

        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .page-title {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        select.form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        select.form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-color);
            opacity: 0.8;
        }

        .section-title {
            color: var(--text-color);
            font-size: 1.5rem;
            margin: 2rem 0 1rem;
            padding-left: 1rem;
            border-left: 4px solid var(--accent-color);
        }

        .table-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            background: var(--bg-color);
            border-radius: 10px;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table th {
            background: rgba(155, 89, 182, 0.1);
            color: var(--text-color);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
        }

        .table td {
            padding: 1rem;
            border-top: 1px solid rgba(0,0,0,0.05);
        }

        .table tr:hover {
            background: rgba(155, 89, 182, 0.02);
        }

        .rating-badge {
            padding: 0.4rem 1rem;
            border-radius: 20px;
            font-weight: 500;
            display: inline-block;
            min-width: 80px;
            text-align: center;
            box-shadow: var(--inner-shadow);
        }

        .rating-excellent { 
            background: #27ae6010;
            color: #27ae60;
        }
        .rating-good { 
            background: #9b59b610;
            color: #9b59b6;
        }
        .rating-average { 
            background: #f39c1210;
            color: #f39c12;
        }
        .rating-poor { 
            background: #e74c3c10;
            color: #e74c3c;
        }

        .btn-export {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            min-width: 150px;
            justify-content: center;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn-export:active {
            box-shadow: var(--inner-shadow);
            transform: translateY(0);
        }

        .btn-export i {
            font-size: 1.2rem;
        }

        .btn-pdf {
            color: #e74c3c;
        }

        .btn-excel {
            color: #27ae60;
        }

        .export-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        /* DataTables Custom Styling */
        .dataTables_wrapper .dataTables_filter input {
            border: none;
            border-radius: 10px;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .dataTables_wrapper .dataTables_length select {
            border: none;
            border-radius: 10px;
            padding: 0.5rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button {
            border: none !important;
            border-radius: 10px;
            padding: 0.5rem 1rem !important;
            margin: 0 0.2rem;
            background: var(--bg-color) !important;
            box-shadow: var(--shadow);
            color: var(--text-color) !important;
        }

        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-color) !important;
            color: white !important;
            box-shadow: var(--inner-shadow);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .filter-row {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .export-buttons {
                flex-direction: column;
            }

            .btn-export {
                width: 100%;
            }

            .table-container {
                padding: 1rem;
            }
        }

        .filter-label {
            display: block;
            color: var(--text-color);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .filter-row {
            display: flex;
            gap: 1.5rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
        }

        .export-buttons {
            display: flex;
            gap: 1rem;
            margin-left: auto;
        }

        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
                gap: 1rem;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .export-buttons {
                width: 100%;
                margin-left: 0;
                margin-top: 1rem;
            }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1><i class="fas fa-chart-line"></i> Feedback Reports & Analytics</h1>
            
            <form id="filterForm" method="GET" class="mt-4">
            <div class="filter-row">
                <div class="filter-group">
                        <label class="filter-label">Academic Year</label>
                    <select name="academic_year_id" class="form-control" onchange="this.form.submit()">
                        <?php
                        mysqli_data_seek($academic_years, 0);
                        while ($year = mysqli_fetch_assoc($academic_years)): ?>
                            <option value="<?php echo $year['id']; ?>" 
                                <?php echo $selected_year == $year['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <?php if (is_super_admin()): ?>
                <div class="filter-group">
                        <label class="filter-label">Department</label>
                    <select name="department_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Departments</option>
                        <?php
                        mysqli_data_seek($departments, 0);
                        while ($dept = mysqli_fetch_assoc($departments)): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                <?php echo $selected_department == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="export-buttons">
                    <button type="button" class="btn-export btn-pdf" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                    <button type="button" class="btn-export btn-excel" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                </div>
            </div>
            </form>
        </div>

        <!-- Overall Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $overall_stats['total_feedback']; ?></div>
                <div class="stat-label">Total Feedback</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $overall_stats['total_students']; ?></div>
                <div class="stat-label">Students Participated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $overall_stats['total_subjects']; ?></div>
                <div class="stat-label">Subjects Covered</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $overall_stats['total_faculty']; ?></div>
                <div class="stat-label">Faculty Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $overall_stats['overall_avg']; ?></div>
                <div class="stat-label">Overall Rating</div>
            </div>
        </div>

        <!-- Rating Analysis -->
        <h2 class="section-title">Rating Analysis</h2>
        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Parameter</th>
                        <th>Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Course Effectiveness</td>
                        <td><span class="rating-badge <?php echo getRatingClass($overall_stats['avg_course_effectiveness']); ?>"><?php echo number_format($overall_stats['avg_course_effectiveness'], 2); ?></span></td>
                    </tr>
                    <tr>
                        <td>Teaching Effectiveness</td>
                        <td><span class="rating-badge <?php echo getRatingClass($overall_stats['avg_teaching_effectiveness']); ?>"><?php echo number_format($overall_stats['avg_teaching_effectiveness'], 2); ?></span></td>
                    </tr>
                    <tr>
                        <td>Resources & Administration</td>
                        <td><span class="rating-badge <?php echo getRatingClass($overall_stats['avg_resources_admin']); ?>"><?php echo number_format($overall_stats['avg_resources_admin'], 2); ?></span></td>
                    </tr>
                    <tr>
                        <td>Assessment & Learning</td>
                        <td><span class="rating-badge <?php echo getRatingClass($overall_stats['avg_assessment_learning']); ?>"><?php echo number_format($overall_stats['avg_assessment_learning'], 2); ?></span></td>
                    </tr>
                    <tr>
                        <td>Course Outcomes</td>
                        <td><span class="rating-badge <?php echo getRatingClass($overall_stats['avg_course_outcomes']); ?>"><?php echo number_format($overall_stats['avg_course_outcomes'], 2); ?></span></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Department-wise Analysis -->
        <h2 class="section-title">Department Analysis</h2>
        <div class="table-container">
            <table id="deptTable" class="table">
                <thead>
                    <tr>
                        <th>Department</th>
                        <th>Feedback Count</th>
                        <th>Faculty Count</th>
                        <th>Subjects Count</th>
                        <th>Average Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($dept = mysqli_fetch_assoc($dept_stats_result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($dept['department_name']); ?></td>
                            <td><?php echo $dept['feedback_count']; ?></td>
                            <td><?php echo $dept['faculty_count']; ?></td>
                            <td><?php echo $dept['subjects_count']; ?></td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($dept['avg_rating']); ?>">
                                    <?php echo $dept['avg_rating'] ?? 'N/A'; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Performing Faculty -->
        <h2 class="section-title">Top Performing Faculty</h2>
        <div class="table-container">
            <table id="facultyTable" class="table">
                <thead>
                    <tr>
                        <th>Faculty Name</th>
                        <th>Department</th>
                        <th>Feedback Count</th>
                        <th>Average Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($faculty = mysqli_fetch_assoc($faculty_stats_result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($faculty['faculty_name']); ?></td>
                            <td><?php echo htmlspecialchars($faculty['department_name']); ?></td>
                            <td><?php echo $faculty['feedback_count']; ?></td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($faculty['avg_rating']); ?>">
                                    <?php echo $faculty['avg_rating']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <!-- Subject Performance -->
        <h2 class="section-title">Subject Performance</h2>
        <div class="table-container">
            <table id="subjectTable" class="table">
                <thead>
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Faculty</th>
                        <th>Department</th>
                        <th>Feedback Count</th>
                        <th>Average Rating</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($subject = mysqli_fetch_assoc($subject_stats_result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <td><?php echo htmlspecialchars($subject['faculty_name']); ?></td>
                            <td><?php echo htmlspecialchars($subject['department_name']); ?></td>
                            <td><?php echo $subject['feedback_count']; ?></td>
                            <td>
                                <span class="rating-badge <?php echo getRatingClass($subject['avg_rating']); ?>">
                                    <?php echo $subject['avg_rating']; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTables with custom options
            $('#deptTable').DataTable({
                pageLength: 10,
                order: [[4, 'desc']],
                language: {
                    search: "<i class='fas fa-search'></i>",
                    searchPlaceholder: "Search departments..."
                },
                dom: '<"top"lf>rt<"bottom"ip><"clear">'
            });

            $('#facultyTable').DataTable({
                pageLength: 10,
                order: [[3, 'desc']],
                language: {
                    search: "<i class='fas fa-search'></i>",
                    searchPlaceholder: "Search faculty..."
                },
                dom: '<"top"lf>rt<"bottom"ip><"clear">'
            });

            $('#subjectTable').DataTable({
                pageLength: 10,
                order: [[5, 'desc']],
                language: {
                    search: "<i class='fas fa-search'></i>",
                    searchPlaceholder: "Search subjects..."
                },
                dom: '<"top"lf>rt<"bottom"ip><"clear">'
            });
        });

        function getRatingClass(rating) {
            if (rating >= 4.5) return 'rating-excellent';
            if (rating >= 4.0) return 'rating-good';
            if (rating >= 3.0) return 'rating-average';
            return 'rating-poor';
        }

        function exportToPDF() {
            var academic_year = $('select[name="academic_year_id"]').val();
            var department = $('select[name="department_id"]').val() || '';
            var url = 'generate_pdf_report.php?academic_year_id=' + academic_year;
            if (department) {
                url += '&department_id=' + department;
            }
            window.location.href = url;
        }

        function exportToExcel() {
            var academic_year = $('select[name="academic_year_id"]').val();
            var department = $('select[name="department_id"]').val() || '';
            var url = 'generate_excel_report.php?academic_year_id=' + academic_year;
            if (department) {
                url += '&department_id=' + department;
            }
            window.location.href = url;
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