<?php
session_start();
require_once '../db_connection.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get departments based on admin type
$dept_query = "SELECT id, name FROM departments";
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $dept_query .= " WHERE id = " . $_SESSION['department_id'];
}
$dept_query .= " ORDER BY name";
$dept_result = mysqli_query($conn, $dept_query);
$departments = [];
while ($row = mysqli_fetch_assoc($dept_result)) {
    $departments[$row['id']] = $row['name'];
}

// Get current academic year
$academic_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

// Get all academic years
$academic_years = [];
$academic_years_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);
while ($row = mysqli_fetch_assoc($academic_years_result)) {
    $academic_years[$row['id']] = $row['year_range'];
}

// Get faculty list based on admin type
$faculty_query = "SELECT id, name FROM faculty WHERE is_active = TRUE";
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $faculty_query .= " AND department_id = " . $_SESSION['department_id'];
}
$faculty_query .= " ORDER BY name";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_list = [];
while ($row = mysqli_fetch_assoc($faculty_result)) {
    $faculty_list[$row['id']] = $row['name'];
}

// Get subjects based on admin type
$subject_query = "SELECT s.id, CONCAT(s.code, ' - ', s.name) as subject 
                 FROM subjects s 
                 WHERE s.is_active = TRUE";
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $subject_query .= " AND s.department_id = " . $_SESSION['department_id'];
}
$subject_query .= " ORDER BY s.code";
$subject_result = mysqli_query($conn, $subject_query);
$subjects = [];
while ($row = mysqli_fetch_assoc($subject_result)) {
    $subjects[$row['id']] = $row['subject'];
}

// Get semesters
$semesters = [1, 2, 3, 4, 5, 6, 7, 8];

// Get sections
$sections_query = "SELECT DISTINCT section FROM subject_assignments WHERE is_active = TRUE ORDER BY section";
$sections_result = mysqli_query($conn, $sections_query);
$sections = [];
while ($row = mysqli_fetch_assoc($sections_result)) {
    $sections[] = $row['section'];
}

// Include header
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<div class="main-content">
    <div class="dashboard-header">
        <h1><i class="fas fa-chart-bar me-3"></i>Class Committee Feedback Reports</h1>
    </div>
    
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic" type="button">
                        <i class="fas fa-graduation-cap me-2"></i>Class Committee Feedback
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="non-academic-tab" data-bs-toggle="tab" data-bs-target="#non-academic" type="button">
                        <i class="fas fa-university me-2"></i>Non-Academic Feedback
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content" id="reportTabsContent">
                <!-- Class Committee Feedback Tab -->
                <div class="tab-pane fade show active" id="academic" role="tabpanel">
                    <h5 class="card-title">Generate Class Committee Feedback Report</h5>
                    
                    <form action="../generate_class_committee_report.php" method="get" target="_blank" class="report-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="academic_year_id">
                                    <i class="far fa-calendar-alt me-2"></i>Academic Year
                                </label>
                                <select class="form-control" id="academic_year_id" name="academic_year_id">
                                    <?php foreach ($academic_years as $id => $year): ?>
                                        <option value="<?= $id ?>" <?= ($current_academic_year && $current_academic_year['id'] == $id) ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="department_id">
                                    <i class="fas fa-building me-2"></i>Department
                                </label>
                                <select class="form-control" id="department_id" name="department_id" <?= !is_super_admin() ? 'disabled' : '' ?>>
                                    <?php if (is_super_admin()): ?>
                                        <option value="">All Departments</option>
                                    <?php endif; ?>
                                    <?php foreach ($departments as $id => $name): ?>
                                        <option value="<?= $id ?>" <?= (!is_super_admin() && $_SESSION['department_id'] == $id) ? 'selected' : '' ?>><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="faculty_id">
                                    <i class="fas fa-chalkboard-teacher me-2"></i>Faculty
                                </label>
                                <select class="form-control" id="faculty_id" name="faculty_id">
                                    <option value="">All Faculty</option>
                                    <?php foreach ($faculty_list as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="subject_id">
                                    <i class="fas fa-book me-2"></i>Subject
                                </label>
                                <select class="form-control" id="subject_id" name="subject_id">
                                    <option value="">All Subjects</option>
                                    <?php foreach ($subjects as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="semester">
                                    <i class="fas fa-list-ol me-2"></i>Semester
                                </label>
                                <select class="form-control" id="semester" name="semester">
                                    <option value="">All Semesters</option>
                                    <?php foreach ($semesters as $sem): ?>
                                        <option value="<?= $sem ?>"><?= $sem ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="section">
                                    <i class="fas fa-layer-group me-2"></i>Section
                                </label>
                                <select class="form-control" id="section" name="section">
                                    <option value="">All Sections</option>
                                    <?php foreach ($sections as $sec): ?>
                                        <option value="<?= $sec ?>"><?= $sec ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="format">
                                    <i class="fas fa-file-alt me-2"></i>Report Format
                                </label>
                                <select class="form-control" id="format" name="format">
                                    <option value="pdf">PDF</option>
                                    <option value="excel">Excel</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn">
                                <i class="fas fa-file-export me-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Non-Academic Feedback Tab -->
                <div class="tab-pane fade" id="non-academic" role="tabpanel">
                    <h5 class="card-title">Generate Non-Academic Feedback Report</h5>
                    
                    <form action="../generate_non_academic_report.php" method="get" target="_blank" class="report-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="na_academic_year_id">
                                    <i class="far fa-calendar-alt me-2"></i>Academic Year
                                </label>
                                <select class="form-control" id="na_academic_year_id" name="academic_year_id">
                                    <?php foreach ($academic_years as $id => $year): ?>
                                        <option value="<?= $id ?>" <?= ($current_academic_year && $current_academic_year['id'] == $id) ? 'selected' : '' ?>><?= $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="na_department_id">
                                    <i class="fas fa-building me-2"></i>Department
                                </label>
                                <select class="form-control" id="na_department_id" name="department_id">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= $name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="na_semester">
                                    <i class="fas fa-list-ol me-2"></i>Semester
                                </label>
                                <select class="form-control" id="na_semester" name="semester">
                                    <option value="">All Semesters</option>
                                    <?php foreach ($semesters as $sem): ?>
                                        <option value="<?= $sem ?>"><?= $sem ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="na_section">
                                    <i class="fas fa-layer-group me-2"></i>Section
                                </label>
                                <select class="form-control" id="na_section" name="section">
                                    <option value="">All Sections</option>
                                    <?php foreach ($sections as $sec): ?>
                                        <option value="<?= $sec ?>"><?= $sec ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="na_format">
                                    <i class="fas fa-file-alt me-2"></i>Report Format
                                </label>
                                <select class="form-control" id="na_format" name="format">
                                    <option value="pdf">PDF</option>
                                    <option value="excel">Excel</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn">
                                <i class="fas fa-file-export me-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
        --primary-color: #3498db;  /* Blue theme for Reports */
        --text-color: #2c3e50;
        --bg-color: #e0e5ec;
        --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                 -9px -9px 16px rgba(255,255,255, 0.5);
        --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
    }

    .main-content {
        background: var(--bg-color);
        min-height: 100vh;
        padding: 2rem;
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
        margin: 0;
        display: flex;
        align-items: center;
    }

    .card {
        background: var(--bg-color);
        border-radius: 15px;
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        border: none;
    }

    .card-header {
        background: var(--bg-color);
        padding: 1rem 1.5rem;
        border-bottom: none;
        border-radius: 15px 15px 0 0 !important;
    }

    .card-body {
        padding: 1.5rem;
    }

    .card-title {
        color: var(--text-color);
        margin-bottom: 1.5rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }

    .nav-tabs {
        border-bottom: none;
    }

    .nav-tabs .nav-link {
        border: none;
        color: var(--text-color);
        background: var(--bg-color);
        border-radius: 10px;
        padding: 0.75rem 1.5rem;
        margin-right: 0.5rem;
        transition: all 0.3s ease;
    }

    .nav-tabs .nav-link:hover {
        transform: translateY(-2px);
    }

    .nav-tabs .nav-link.active {
        color: var(--primary-color);
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        border: none;
    }

    .form-row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -0.5rem 1rem;
    }

    .form-group {
        flex: 1;
        min-width: 200px;
        padding: 0 0.5rem;
        margin-bottom: 1.5rem;
    }

    .form-control {
        width: 100%;
        padding: 0.8rem;
        border: none;
        border-radius: 10px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        color: var(--text-color);
        transition: all 0.3s ease;
    }

    .form-control:focus {
        outline: none;
        box-shadow: var(--shadow);
    }

    .form-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 2rem;
    }

    .btn {
        padding: 0.8rem 1.5rem;
        border: none;
        border-radius: 10px;
        background: var(--bg-color);
        color: var(--text-color);
        font-weight: 500;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                   -12px -12px 20px rgba(255,255,255, 0.6);
        color: var(--primary-color);
    }

    .tab-pane {
        animation: fadeIn 0.3s;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @media (max-width: 768px) {
        .form-row {
            flex-direction: column;
        }

        .form-group {
            margin-bottom: 1rem;
        }
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Initialize Bootstrap tabs
        var triggerTabList = [].slice.call(document.querySelectorAll('#reportTabs a, #reportTabs button'));
        triggerTabList.forEach(function (triggerEl) {
            var tabTrigger = new bootstrap.Tab(triggerEl);
            triggerEl.addEventListener('click', function (event) {
                event.preventDefault();
                tabTrigger.show();
            });
        });

        // Add animation for form submissions
        document.querySelectorAll('.report-form').forEach(form => {
            form.addEventListener('submit', function() {
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Generating...';
                submitBtn.disabled = true;
                
                // Re-enable after 2 seconds for UX (report will open in new tab)
                setTimeout(() => {
                    submitBtn.innerHTML = '<i class="fas fa-file-export me-2"></i>Generate Report';
                    submitBtn.disabled = false;
                }, 2000);
            });
        });
    });
</script>

<?php include '../footer.php'; ?> 