<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Department filter based on admin type
$department_filter = "";
$department_params = [];

// If department admin, restrict data to their department
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $department_filter = " AND s.department_id = ?";
    $department_params[] = $_SESSION['department_id'];
}

// Get current academic year
$academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

// Get all academic years for filter
$academic_years_query = "SELECT * FROM academic_years ORDER BY year_range DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);

// Get departments for filter - department admins only see their department
if (is_super_admin()) {
    $departments_query = "SELECT * FROM departments ORDER BY name";
    $departments_result = mysqli_query($conn, $departments_query);
} else {
    $departments_query = "SELECT * FROM departments WHERE id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $departments_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $departments_result = mysqli_stmt_get_result($stmt);
}

// Build the query based on filters
$where_conditions = [];
$params = [];
$types = "";

if (isset($_GET['academic_year']) && !empty($_GET['academic_year'])) {
    $where_conditions[] = "et.academic_year_id = ?";
    $params[] = $_GET['academic_year'];
    $types .= "i";
} else {
    $where_conditions[] = "et.academic_year_id = ?";
    $params[] = $current_academic_year['id'];
    $types .= "i";
}

if (isset($_GET['department']) && !empty($_GET['department'])) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $_GET['department'];
    $types .= "i";
} else if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $where_conditions[] = "s.department_id = ?";
    $params[] = $_SESSION['department_id'];
    $types .= "i";
}

if (isset($_GET['semester']) && !empty($_GET['semester'])) {
    $where_conditions[] = "et.semester = ?";
    $params[] = $_GET['semester'];
    $types .= "i";
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_conditions[] = "(s.name LIKE ? OR s.code LIKE ? OR st.name LIKE ? OR st.roll_number LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "ssss";
}

// Get subjects list for filter and modal
$subject_query = "SELECT s.id, s.name, s.code FROM subjects s 
                 JOIN departments d ON s.department_id = d.id";

if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $subject_query .= " WHERE s.department_id = " . $_SESSION['department_id'];
}

$subject_query .= " ORDER BY s.name";
$subject_result = mysqli_query($conn, $subject_query);

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get exam feedbacks with filters
$query = "SELECT 
    ef.id,
    ef.student_id,
    st.name as student_name,
    st.roll_number,
    s.name as subject_name,
    s.code as subject_code,
    d.name as department_name,
    et.semester,
    et.exam_date,
    et.exam_session,
    ef.coverage_relevance_avg,
    ef.quality_clarity_avg,
    ef.structure_balance_avg,
    ef.application_innovation_avg,
    ef.cumulative_avg,
    ef.submitted_at,
    CASE 
        WHEN COALESCE(ef.syllabus_coverage, ef.difficult_questions, ef.out_of_syllabus, 
                      ef.time_sufficiency, ef.fairness_rating, ef.improvements, 
                      ef.additional_comments) IS NOT NULL 
        THEN 1 ELSE 0 
    END as has_comments
FROM examination_feedback ef
JOIN subject_assignments sa ON ef.subject_assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN departments d ON s.department_id = d.id
JOIN students st ON ef.student_id = st.id
JOIN exam_timetable et ON ef.exam_timetable_id = et.id
$where_clause
ORDER BY ef.submitted_at DESC";

$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$feedbacks = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Exam Feedbacks - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
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
            background: var(--bg-color);
            margin-left: 280px; /* Add margin for fixed sidebar */
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
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

        .filter-row:last-child {
            margin-bottom: 0;
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

        .form-select {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-family: inherit;
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
        }

        .feedback-card:hover {
            transform: translateY(-5px);
        }

        .feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .feedback-info {
            flex: 1;
        }

        .student-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .student-roll {
            font-size: 0.9rem;
            color: #666;
        }

        .subject-info {
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .subject-name {
            font-size: 1.1rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .subject-code {
            font-size: 0.9rem;
            color: #666;
        }

        .department-name {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
        }

        .rating-section {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .rating-item {
            text-align: center;
        }

        .rating-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .rating-label {
            font-size: 0.8rem;
            color: #666;
        }

        .rating-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 0.5rem;
        }

        .rating-excellent { background-color: #28a745; color: white; }
        .rating-good { background-color: #17a2b8; color: white; }
        .rating-average { background-color: #ffc107; color: black; }
        .rating-poor { background-color: #dc3545; color: white; }

        .feedback-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .comments-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .comments-present {
            background: #d4edda;
            color: #155724;
        }

        .comments-absent {
            background: #f8d7da;
            color: #721c24;
        }

        .submission-time {
            font-size: 0.8rem;
            color: #666;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .export-btn .btn {
            background: var(--primary-color);
            color: white;
        }

        .export-btn .btn:hover {
            background: #c0392b;
            transform: translateY(-2px);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        
        .modal-content {
            background: var(--bg-color);
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 80%;
            max-width: 600px;
            position: relative;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--text-color);
        }
        
        .close-modal {
            color: #aaa;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: var(--primary-color);
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #7f8c8d;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: #c0392b;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Exam Feedbacks</h1>
            <div class="export-btn">
                <button id="openExportModal" class="btn">
                    <i class="fas fa-file-excel"></i> Export to Excel
                </button>
            </div>
        </div>

        <div class="filter-section">
            <form method="GET" class="mb-4">
                <div class="filter-row">
                    <div class="col-md-3">
                        <label for="academic_year" class="form-label">Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-select">
                            <?php while ($year = mysqli_fetch_assoc($academic_years_result)): ?>
                                <option value="<?php echo $year['id']; ?>" 
                                    <?php echo (isset($_GET['academic_year']) && $_GET['academic_year'] == $year['id']) || 
                                             (!isset($_GET['academic_year']) && $year['is_current']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <?php if (is_super_admin()): ?>
                    <div class="col-md-3">
                        <label for="department" class="form-label">Department</label>
                        <select name="department" id="department" class="form-select">
                            <option value="">All Departments</option>
                            <?php while ($dept = mysqli_fetch_assoc($departments_result)): ?>
                                <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo isset($_GET['department']) && $_GET['department'] == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="col-md-2">
                        <label for="semester" class="form-label">Semester</label>
                        <select name="semester" id="semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo isset($_GET['semester']) && $_GET['semester'] == $i ? 'selected' : ''; ?>>
                                    Semester <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" name="search" id="search" class="form-control" 
                               placeholder="Search by subject, student..." 
                               value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="subject" class="form-label">Subject</label>
                        <select name="subject" id="subject" class="form-select">
                            <option value="">All Subjects</option>
                            <?php
                            while ($subject = mysqli_fetch_assoc($subject_result)): ?>
                                <option value="<?php echo $subject['id']; ?>" 
                                    <?php echo isset($_GET['subject']) && $_GET['subject'] == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <div class="feedback-grid">
            <?php foreach ($feedbacks as $feedback): ?>
                <div class="feedback-card">
                    <div class="feedback-header">
                        <div class="feedback-info">
                            <div class="student-name"><?php echo htmlspecialchars($feedback['student_name']); ?></div>
                            <div class="student-roll"><?php echo htmlspecialchars($feedback['roll_number']); ?></div>
                        </div>
                    </div>

                    <div class="subject-info">
                        <div class="subject-name"><?php echo htmlspecialchars($feedback['subject_name']); ?></div>
                        <div class="subject-code"><?php echo htmlspecialchars($feedback['subject_code']); ?></div>
                        <div class="department-name"><?php echo htmlspecialchars($feedback['department_name']); ?></div>
                    </div>

                    <div class="rating-section">
                        <div class="rating-item">
                            <div class="rating-value"><?php echo number_format($feedback['coverage_relevance_avg'], 2); ?></div>
                            <div class="rating-label">Coverage & Relevance</div>
                            <span class="rating-badge rating-<?php echo getRatingClass($feedback['coverage_relevance_avg']); ?>">
                                <?php echo getRatingText($feedback['coverage_relevance_avg']); ?>
                            </span>
                        </div>
                        <div class="rating-item">
                            <div class="rating-value"><?php echo number_format($feedback['quality_clarity_avg'], 2); ?></div>
                            <div class="rating-label">Quality & Clarity</div>
                            <span class="rating-badge rating-<?php echo getRatingClass($feedback['quality_clarity_avg']); ?>">
                                <?php echo getRatingText($feedback['quality_clarity_avg']); ?>
                            </span>
                        </div>
                        <div class="rating-item">
                            <div class="rating-value"><?php echo number_format($feedback['structure_balance_avg'], 2); ?></div>
                            <div class="rating-label">Structure & Balance</div>
                            <span class="rating-badge rating-<?php echo getRatingClass($feedback['structure_balance_avg']); ?>">
                                <?php echo getRatingText($feedback['structure_balance_avg']); ?>
                            </span>
                        </div>
                        <div class="rating-item">
                            <div class="rating-value"><?php echo number_format($feedback['application_innovation_avg'], 2); ?></div>
                            <div class="rating-label">Application & Innovation</div>
                            <span class="rating-badge rating-<?php echo getRatingClass($feedback['application_innovation_avg']); ?>">
                                <?php echo getRatingText($feedback['application_innovation_avg']); ?>
                            </span>
                        </div>
                    </div>

                    <div class="feedback-footer">
                        <span class="comments-badge <?php echo $feedback['has_comments'] ? 'comments-present' : 'comments-absent'; ?>">
                            <i class="fas fa-comments"></i>
                            <?php echo $feedback['has_comments'] ? 'Has Comments' : 'No Comments'; ?>
                        </span>
                        <div class="submission-time">
                            <?php echo date('d M Y H:i', strtotime($feedback['submitted_at'])); ?>
                        </div>
                        <a href="view_exam_feedback_details.php?id=<?php echo $feedback['id']; ?>" 
                           class="btn-action" title="View Details">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Export Parameters Modal -->
    <div id="exportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Export Parameters</h2>
                <span class="close-modal">&times;</span>
            </div>
            <div class="modal-body">
                <form id="exportForm" method="get" action="generate_exam_feedback_excel.php">
                    <div class="form-group">
                        <label for="export_academic_year">Academic Year</label>
                        <select name="academic_year" id="export_academic_year" class="form-select">
                            <?php 
                            // Reset pointer to beginning
                            mysqli_data_seek($academic_years_result, 0);
                            while ($year = mysqli_fetch_assoc($academic_years_result)): ?>
                                <option value="<?php echo $year['id']; ?>" 
                                    <?php echo (isset($_GET['academic_year']) && $_GET['academic_year'] == $year['id']) || 
                                             (!isset($_GET['academic_year']) && $year['is_current']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="export_semester">Semester</label>
                        <select name="semester" id="export_semester" class="form-select">
                            <option value="">All Semesters</option>
                            <?php for ($i = 1; $i <= 8; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                    <?php echo isset($_GET['semester']) && $_GET['semester'] == $i ? 'selected' : ''; ?>>
                                    Semester <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="export_subject">Subject</label>
                        <select name="subject" id="export_subject" class="form-select">
                            <option value="">All Subjects</option>
                            <?php
                            // Reset pointer to beginning (assumes subject_result was defined earlier)
                            mysqli_data_seek($subject_result, 0);
                            while ($subject = mysqli_fetch_assoc($subject_result)): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="export_section">Section</label>
                        <select name="section" id="export_section" class="form-select">
                            <option value="">All Sections</option>
                            <?php foreach (range('A', 'K') as $section): ?>
                                <option value="<?php echo $section; ?>">
                                    Section <?php echo $section; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="closeModal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Generate Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Add active class to current nav link
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });
        
        // Modal functionality
        const modal = document.getElementById("exportModal");
        const openModalBtn = document.getElementById("openExportModal");
        const closeModalBtn = document.getElementById("closeModal");
        const closeModalX = document.querySelector(".close-modal");
        
        openModalBtn.onclick = function() {
            modal.style.display = "block";
        }
        
        closeModalBtn.onclick = function() {
            modal.style.display = "none";
        }
        
        closeModalX.onclick = function() {
            modal.style.display = "none";
        }
        
        window.onclick = function(event) {
            if (event.target == modal) {
                modal.style.display = "none";
            }
        }
    </script>
</body>
</html>

<?php
function getRatingClass($rating) {
    if ($rating >= 4.5) return 'excellent';
    if ($rating >= 3.5) return 'good';
    if ($rating >= 2.5) return 'average';
    return 'poor';
}

function getRatingText($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 2.5) return 'Average';
    return 'Poor';
}
?> 