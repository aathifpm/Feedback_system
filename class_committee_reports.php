<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in and has appropriate role
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'faculty', 'hod'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get departments based on role
$departments = [];
$department_filter = '';
$faculty_filter = '';

try {
    switch ($role) {
        case 'admin':
            $dept_query = "SELECT id, name FROM departments ORDER BY name";
            $stmt = $pdo->query($dept_query);
            break;
            
        case 'hod':
            $dept_query = "SELECT d.id, d.name FROM departments d 
                          JOIN hods h ON d.id = h.department_id 
                          WHERE h.id = :user_id AND h.is_active = TRUE";
            $stmt = $pdo->prepare($dept_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            break;
            
        case 'faculty':
            $dept_query = "SELECT d.id, d.name FROM departments d 
                          JOIN faculty f ON d.id = f.department_id 
                          WHERE f.id = :user_id AND f.is_active = TRUE";
            $stmt = $pdo->prepare($dept_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            break;
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $departments[$row['id']] = $row['name'];
    }

    // Get current academic year
    $academic_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
    $stmt = $pdo->query($academic_year_query);
    $current_academic_year = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get all academic years
    $academic_years = [];
    $academic_years_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
    $stmt = $pdo->query($academic_years_query);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $academic_years[$row['id']] = $row['year_range'];
    }

    // Get faculty list based on role
    $faculty_list = [];
    switch ($role) {
        case 'admin':
            $faculty_query = "SELECT id, name FROM faculty WHERE is_active = TRUE ORDER BY name";
            $stmt = $pdo->query($faculty_query);
            break;
            
        case 'hod':
            $hod_dept_query = "SELECT department_id FROM hods WHERE id = :user_id AND is_active = TRUE";
            $stmt = $pdo->prepare($hod_dept_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $hod_dept = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($hod_dept) {
                $faculty_query = "SELECT id, name FROM faculty WHERE department_id = :department_id AND is_active = TRUE ORDER BY name";
                $stmt = $pdo->prepare($faculty_query);
                $stmt->bindParam(':department_id', $hod_dept['department_id'], PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $faculty_query = "SELECT id, name FROM faculty WHERE id = :user_id AND is_active = TRUE";
                $stmt = $pdo->prepare($faculty_query);
                $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
            }
            break;
            
        case 'faculty':
            $faculty_query = "SELECT id, name FROM faculty WHERE id = :user_id AND is_active = TRUE";
            $stmt = $pdo->prepare($faculty_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            break;
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $faculty_list[$row['id']] = $row['name'];
    }

    // Get subjects based on role
    $subjects = [];
    switch ($role) {
        case 'admin':
            $subject_query = "SELECT s.id, CONCAT(s.code, ' - ', s.name) as subject 
                             FROM subjects s 
                             WHERE s.is_active = TRUE 
                             ORDER BY s.code";
            $stmt = $pdo->query($subject_query);
            break;
            
        case 'hod':
            $hod_dept_query = "SELECT department_id FROM hods WHERE id = :user_id AND is_active = TRUE";
            $stmt = $pdo->prepare($hod_dept_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $hod_dept = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($hod_dept) {
                $subject_query = "SELECT s.id, CONCAT(s.code, ' - ', s.name) as subject 
                                 FROM subjects s 
                                 WHERE s.department_id = :department_id AND s.is_active = TRUE 
                                 ORDER BY s.code";
                $stmt = $pdo->prepare($subject_query);
                $stmt->bindParam(':department_id', $hod_dept['department_id'], PDO::PARAM_INT);
                $stmt->execute();
            } else {
                $subject_query = "SELECT s.id, CONCAT(s.code, ' - ', s.name) as subject 
                                 FROM subjects s 
                                 JOIN subject_assignments sa ON s.id = sa.subject_id 
                                 WHERE sa.faculty_id = :user_id AND s.is_active = TRUE 
                                 ORDER BY s.code";
                $stmt = $pdo->prepare($subject_query);
                $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $stmt->execute();
            }
            break;
            
        case 'faculty':
            $subject_query = "SELECT s.id, CONCAT(s.code, ' - ', s.name) as subject 
                             FROM subjects s 
                             JOIN subject_assignments sa ON s.id = sa.subject_id 
                             WHERE sa.faculty_id = :user_id AND s.is_active = TRUE 
                             ORDER BY s.code";
            $stmt = $pdo->prepare($subject_query);
            $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            break;
    }

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subjects[$row['id']] = $row['subject'];
    }

    // Get semesters
    $semesters = [1, 2, 3, 4, 5, 6, 7, 8];

    // Get sections
    $sections_query = "SELECT DISTINCT section FROM subject_assignments WHERE is_active = TRUE ORDER BY section";
    $stmt = $pdo->query($sections_query);
    $sections = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sections[] = $row['section'];
    }
} catch (PDOException $e) {
    error_log("Database error in class_committee_reports.php: " . $e->getMessage());
    $error_message = "A database error occurred. Please try again later.";
}

// Include header
include 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Committee Feedback Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
<div class="container mt-4">
    <div class="dashboard-header">
        <h1><i class="fas fa-chart-bar me-3"></i>Class Committee Feedback Reports</h1>
    </div>
    
    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error_message; ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic" type="button" role="tab" aria-controls="academic" aria-selected="true">
                        <i class="fas fa-graduation-cap me-2"></i>Class Committee Feedback
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="non-academic-tab" data-bs-toggle="tab" data-bs-target="#non-academic" type="button" role="tab" aria-controls="non-academic" aria-selected="false">
                        <i class="fas fa-university me-2"></i>Non-Academic Feedback
                    </button>
                </li>
            </ul>
        </div>
        
        <div class="card-body">
            <div class="tab-content" id="reportTabsContent">
                <!-- Class Committee Feedback Tab -->
                <div class="tab-pane fade show active" id="academic" role="tabpanel" aria-labelledby="academic-tab">
                    <h5 class="card-title">Generate Class Committee Feedback Report</h5>
                    
                    <form action="generate_class_committee_report.php" method="get" target="_blank" class="report-form">
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
                                <select class="form-control" id="department_id" name="department_id">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= $name ?></option>
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
                            <button type="submit" class="btn" id="generate-btn-academic">
                                <i class="fas fa-file-export me-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Non-Academic Feedback Tab -->
                <div class="tab-pane fade" id="non-academic" role="tabpanel" aria-labelledby="non-academic-tab">
                    <h5 class="card-title">Generate Non-Academic Feedback Report</h5>
                    
                    <form action="generate_non_academic_report.php" method="get" target="_blank" class="report-form">
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
                            <button type="submit" class="btn" id="generate-btn-nonacademic">
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

    body {
        background: var(--bg-color);
        min-height: 100vh;
    }

    .container {
        max-width: 1200px;
        margin-top: 2rem;
        margin-bottom: 2rem;
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

    .form-group label {
        display: block;
        margin-bottom: 0.8rem;
        color: var(--text-color);
        font-weight: 500;
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
    
    .btn:active {
        transform: translateY(0);
        box-shadow: var(--inner-shadow);
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
        var triggerTabList = [].slice.call(document.querySelectorAll('#reportTabs .nav-link'));
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
                const originalBtnText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Generating...';
                submitBtn.disabled = true;
                
                // Re-enable after 2 seconds for UX (report will open in new tab)
                setTimeout(() => {
                    submitBtn.innerHTML = originalBtnText;
                    submitBtn.disabled = false;
                }, 2000);
            });
        });

        // Add active effect on form controls
        document.querySelectorAll('.form-control').forEach(control => {
            control.addEventListener('focus', function() {
                this.classList.add('active');
            });
            control.addEventListener('blur', function() {
                this.classList.remove('active');
            });
        });
    });
</script>

<style>
    /* Additional styles */
    .spinner-border {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        vertical-align: text-bottom;
        border: 0.2em solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        animation: spinner-border .75s linear infinite;
    }
    
    @keyframes spinner-border {
        to { transform: rotate(360deg); }
    }
    
    .form-control.active {
        transform: translateY(-2px);
        box-shadow: var(--shadow);
    }
</style>

<?php include 'footer.php'; ?>
</body>
</html> 