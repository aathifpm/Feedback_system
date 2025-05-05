<?php
// This page is publicly accessible for recruiters
session_start();
include 'functions.php';

// If not logged in as admin or faculty, restrict some features
$is_authenticated = isset($_SESSION['user_id']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'hod' || $_SESSION['role'] == 'faculty');

// Get filters
$department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;
$skill_search = isset($_GET['skills']) ? sanitize_input($_GET['skills']) : '';
$placement_status = isset($_GET['placement_status']) ? sanitize_input($_GET['placement_status']) : '';
$search_term = isset($_GET['search']) ? sanitize_input($_GET['search']) : '';

// Set up pagination
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$items_per_page = 12; // Number of profiles per page
$offset = ($page - 1) * $items_per_page;

// Build query with filters
$query = "SELECT s.id, s.name, s.roll_number, s.register_number, s.email, s.section,
          d.name as department_name, 
          b.batch_name, b.admission_year, b.graduation_year,
          rp.linkedin_url, rp.github_url, rp.portfolio_url, rp.resume_path,
          rp.skills, rp.achievements, rp.placement_status, rp.company_placed,
          rp.certifications,
          (SELECT COUNT(*) FROM student_projects WHERE student_id = s.id) as project_count,
          (SELECT COUNT(*) FROM student_certificates WHERE student_id = s.id) as certificate_count
          FROM students s
          JOIN departments d ON s.department_id = d.id
          JOIN batch_years b ON s.batch_id = b.id
          JOIN student_recruitment_profiles rp ON s.id = rp.student_id
          WHERE s.is_active = 1 AND rp.public_profile = 1";

// Add filters to query
$params = [];
$types = "";

if ($department_id > 0) {
    $query .= " AND s.department_id = ?";
    $params[] = $department_id;
    $types .= "i";
}

if ($batch_id > 0) {
    $query .= " AND s.batch_id = ?";
    $params[] = $batch_id;
    $types .= "i";
}

if (!empty($skill_search)) {
    $query .= " AND (rp.skills LIKE ? OR rp.achievements LIKE ? OR rp.certifications LIKE ?)";
    $search_term = "%" . $skill_search . "%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

if (!empty($placement_status)) {
    $query .= " AND rp.placement_status = ?";
    $params[] = $placement_status;
    $types .= "s";
}

// Add search term filter
if (!empty($search_term)) {
    $query .= " AND (s.name LIKE ? OR rp.skills LIKE ? OR rp.achievements LIKE ? OR rp.certifications LIKE ?)";
    $search_param = "%" . $search_term . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

// Count total records for pagination before adding LIMIT/OFFSET
$count_query = "SELECT COUNT(*) as total FROM students s
                JOIN departments d ON s.department_id = d.id
                JOIN batch_years b ON s.batch_id = b.id
                JOIN student_recruitment_profiles rp ON s.id = rp.student_id
                WHERE s.is_active = 1 AND rp.public_profile = 1";

// Add filters to count query
if ($department_id > 0) {
    $count_query .= " AND s.department_id = ?";
}
if ($batch_id > 0) {
    $count_query .= " AND s.batch_id = ?";
}
if (!empty($skill_search)) {
    $count_query .= " AND (rp.skills LIKE ? OR rp.achievements LIKE ? OR rp.certifications LIKE ?)";
}
if (!empty($placement_status)) {
    $count_query .= " AND rp.placement_status = ?";
}
if (!empty($search_term)) {
    $count_query .= " AND (s.name LIKE ? OR rp.skills LIKE ? OR rp.achievements LIKE ? OR rp.certifications LIKE ?)";
}

// Execute count query
$count_stmt = mysqli_prepare($conn, $count_query);
if (!empty($params)) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
}
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_students = mysqli_fetch_assoc($count_result)['total'];
$total_pages = ceil($total_students / $items_per_page);

// Order by graduation year desc, name asc
$query .= " ORDER BY b.graduation_year DESC, s.name ASC";

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $items_per_page;
$params[] = $offset;

// Execute query
$stmt = mysqli_prepare($conn, $query);
if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Get departments and batches for filters
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments = mysqli_query($conn, $departments_query);

$batches_query = "SELECT id, batch_name, admission_year, graduation_year FROM batch_years ORDER BY graduation_year DESC";
$batches = mysqli_query($conn, $batches_query);

// Page title
$page_title = "Student Recruitment Profiles";
include 'header.php';
?>

<style>
    :root {
        --primary-color: #4e73df;  
        --primary-hover: #3a5ecc;
        --text-color: #2c3e50;
        --bg-color: #e0e5ec;
        --card-bg: #e8ecf2;
        --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                 -9px -9px 16px rgba(255,255,255, 0.5);
        --soft-shadow: 5px 5px 10px rgb(163,177,198,0.4), 
                      -5px -5px 10px rgba(255,255,255, 0.4);
        --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        --transition-speed: 0.3s;
    }

    body {
        background: var(--bg-color);
        color: var(--text-color);
    }

    .container-fluid {
        padding: 2rem;
    }

    /* Neumorphic Card */
    .neu-card {
        background: var(--card-bg);
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: none;
        overflow: hidden;
        margin-bottom: 2rem;
        transition: all var(--transition-speed) ease;
    }

    .neu-card-header {
        background: var(--primary-color);
        color: white;
        padding: 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: none;
    }

    .neu-card-header h5 {
        margin: 0;
        font-size: 1.4rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .neu-card-body {
        padding: 1.8rem;
    }

    .neu-card-footer {
        background: var(--card-bg);
        border-top: 1px solid rgba(0,0,0,0.05);
        padding: 1.2rem;
        text-align: center;
    }

    /* Profile Cards */
    .profile-card {
        height: 100%;
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        position: relative;
        z-index: 1;
    }

    .profile-card:hover {
        transform: translateY(-10px);
        box-shadow: var(--shadow), 0 10px 20px rgba(0,0,0,0.1);
    }

    .profile-card .card-header {
        background: var(--primary-color);
        color: white;
        padding: 1.2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .profile-card .card-header h5 {
        margin: 0;
        font-weight: 600;
        font-size: 1.2rem;
    }

    .profile-card .card-body {
        padding: 1.5rem;
    }

    /* Status Badges */
    .status-badge {
        padding: 0.4rem 1rem;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }

    .status-badge.placed {
        background: #1cc88a;
        color: white;
    }

    .status-badge.in-progress {
        background: #36b9cc;
        color: white;
    }

    .status-badge.not-interested {
        background: #858796;
        color: white;
    }

    .status-badge.available {
        background: var(--primary-color);
        color: white;
    }

    /* Form Controls */
    .form-control, .form-select {
        width: 100%;
        padding: 0.8rem 1.2rem;
        border: none;
        border-radius: 12px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        color: var(--text-color);
        transition: all var(--transition-speed) ease;
        margin-bottom: 0.2rem;
        font-size: 1rem;
    }

    .form-control:focus, .form-select:focus {
        box-shadow: var(--shadow);
        outline: none;
        transform: translateY(-2px);
    }

    .form-control::placeholder {
        color: #98a6ad;
    }

    .form-label {
        display: block;
        margin-bottom: 0.8rem;
        color: var(--text-color);
        font-weight: 600;
        font-size: 1rem;
    }

    /* Buttons */
    .neu-btn {
        background: var(--bg-color);
        border: none;
        border-radius: 12px;
        padding: 0.8rem 1.5rem;
        color: var(--text-color);
        box-shadow: var(--soft-shadow);
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        font-weight: 500;
        position: relative;
        overflow: hidden;
        z-index: 1;
        cursor: pointer;
    }

    .neu-btn::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        z-index: -1;
        transition: width 0.6s, height 0.6s;
    }

    .neu-btn:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow);
    }

    .neu-btn:hover::after {
        width: 300px;
        height: 300px;
    }

    .neu-btn-primary {
        background: var(--primary-color);
        color: white;
    }

    .neu-btn-primary:hover {
        background: var(--primary-hover);
    }

    .neu-btn-outline {
        background: transparent;
        color: var(--text-color);
        border: 2px solid var(--primary-color);
    }

    .neu-btn-success {
        background: #1cc88a;
        color: white;
    }

    .neu-btn-success:hover {
        background: #169b6b;
    }

    .neu-btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }

    /* Social Links */
    .profile-links {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .social-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border-radius: 10px;
        font-size: 0.9rem;
        font-weight: 500;
        text-decoration: none;
        transition: all var(--transition-speed) ease;
    }

    .social-link.linkedin {
        background-color: rgba(0, 119, 181, 0.1);
        color: #0077b5;
    }

    .social-link.github {
        background-color: rgba(36, 41, 46, 0.1);
        color: #24292e;
    }

    .social-link.resume {
        background-color: rgba(234, 67, 53, 0.1);
        color: #ea4335;
    }

    .social-link:hover {
        transform: translateY(-2px);
        box-shadow: var(--soft-shadow);
    }

    /* Layout */
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
    }
    
    .page-header h2 {
        margin: 0;
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-color);
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    /* Alerts */
    .neu-alert {
        background: var(--bg-color);
        border: none;
        border-radius: 15px;
        box-shadow: var(--soft-shadow);
        padding: 2rem;
        margin-bottom: 2rem;
        text-align: center;
    }

    .neu-alert-info {
        background: rgba(54, 185, 204, 0.1);
        color: #36b9cc;
    }

    .neu-alert-info i {
        color: #36b9cc;
        margin-bottom: 1rem;
    }

    /* Info sections */
    .profile-info-section {
        margin-bottom: 1.2rem;
    }

    .profile-info-section h6 {
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .text-truncate-3 {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        max-height: 4.5em;
    }

    .student-info {
        color: #6c757d;
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    /* Student card styling */
    .student-card {
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

    .student-card:hover {
        transform: translateY(-5px);
    }

    /* Student card header */
    .student-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .student-info {
        flex: 1;
    }

    .student-name {
        font-size: 1.2rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 0.5rem;
    }

    .student-id {
        font-size: 0.9rem;
        color: #666;
    }

    /* Student details section */
    .student-details {
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

    /* Student stats section */
    .student-stats {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
        margin: 1rem 0;
        padding: 1rem 0;
        border-top: 1px solid rgba(0,0,0,0.1);
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }

    .stat-item {
        text-align: center;
        padding: 0.5rem;
        background: var(--bg-color);
        border-radius: 10px;
        box-shadow: var(--inner-shadow);
    }

    .stat-value {
        font-size: 1.4rem;
        font-weight: 600;
        color: var(--primary-color);
        margin-bottom: 0.3rem;
    }

    .stat-label {
        font-size: 0.85rem;
        color: #666;
    }

    /* Student actions section */
    .student-actions {
        display: flex;
        gap: 0.8rem;
        margin-top: auto;
        padding-top: 1rem;
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
    }

    .btn-action:hover {
        transform: translateY(-2px);
        box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                    -6px -6px 10px rgba(255,255,255, 0.6);
    }

    /* Filter section styling */
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

    .filter-group {
        flex: 1;
        min-width: 200px;
    }

    .search-box {
        min-width: 300px;
    }

    .search-container {
        position: relative;
        display: flex;
        align-items: center;
    }
    
    .btn-search {
        position: absolute;
        right: 8px;
        background: transparent;
        border: none;
        color: var(--primary-color);
        font-size: 1.1rem;
        cursor: pointer;
        padding: 8px;
        border-radius: 50%;
        transition: all 0.3s ease;
    }
    
    .btn-search:hover {
        background: rgba(78, 115, 223, 0.1);
        transform: translateY(-2px);
    }

    .form-control {
        width: 100%;
        padding: 0.8rem 1.2rem;
        border: none;
        border-radius: 12px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        color: var(--text-color);
        transition: all var(--transition-speed) ease;
        margin-bottom: 0.2rem;
        font-size: 1rem;
    }

    .form-control:focus {
        box-shadow: var(--shadow);
        outline: none;
        transform: translateY(-2px);
    }

    .btn-reset {
        padding: 0.5rem 1rem;
        background: var(--bg-color);
        color: var(--primary-color);
        border: none;
        border-radius: 8px;
        cursor: pointer;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
    }

    .btn-reset:hover {
        transform: translateY(-2px);
    }

    /* Responsive adjustments */
    @media (max-width: 1200px) {
        .student-grid {
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        }
    }

    @media (max-width: 768px) {
        .student-grid {
            grid-template-columns: 1fr;
        }
        
        .student-card {
            max-width: 100%;
        }

        .student-actions {
            flex-direction: column;
        }

        .btn-action {
            width: 100%;
        }

        .student-details {
            grid-template-columns: 1fr;
        }
        
        .filter-row {
            flex-direction: column;
        }
        
        .search-box {
            width: 100%;
            min-width: auto;
        }
        
        .filter-group {
            width: 100%;
        }
    }

    .student-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    /* Pagination Styles */
    .pagination-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin: 2rem 0;
        gap: 0.8rem;
    }
    
    .pagination {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: center;
    }
    
    .page-link {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--bg-color);
        color: var(--text-color);
        text-decoration: none;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
    }
    
    .page-link:hover {
        transform: translateY(-3px);
        box-shadow: 6px 6px 12px rgb(163,177,198,0.7),
                   -6px -6px 12px rgba(255,255,255, 0.6);
    }
    
    .page-link.active {
        background: var(--primary-color);
        color: white;
    }
    
    .page-info {
        color: var(--text-color);
        font-size: 0.9rem;
        text-align: center;
    }
    
    @media (max-width: 768px) {
        .pagination {
            gap: 0.3rem;
        }
        
        .page-link {
            width: 35px;
            height: 35px;
            font-size: 0.9rem;
        }
    }
</style>

<div class="container-fluid">
    <div class="page-header">
        <div>
            <h2><i class="fas fa-user-graduate"></i> Student Recruitment Database</h2>
            <p class="text-muted">Browse student profiles for campus recruitment</p>
        </div>
        <?php if ($is_authenticated): ?>
        <div>
            <a href="recruitment_report.php" class="neu-btn neu-btn-success">
                <i class="fas fa-file-excel"></i> Export Data
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="search-box">
                <div class="search-container">
                    <input type="text" id="searchInput" class="form-control" placeholder="Search by name, skills, or achievements..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    <button type="button" id="searchButton" class="btn-search">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </div>
            
            <div class="filter-group">
                <select id="departmentFilter" class="form-control">
                        <option value="">All Departments</option>
                    <?php 
                    mysqli_data_seek($departments, 0);
                    while ($dept = mysqli_fetch_assoc($departments)): 
                    ?>
                            <option value="<?php echo $dept['id']; ?>" <?php echo ($department_id == $dept['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                
            <div class="filter-group">
                <select id="batchFilter" class="form-control">
                        <option value="">All Batches</option>
                    <?php 
                    mysqli_data_seek($batches, 0);
                    while ($batch = mysqli_fetch_assoc($batches)): 
                    ?>
                            <option value="<?php echo $batch['id']; ?>" <?php echo ($batch_id == $batch['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($batch['batch_name'] . ' (' . $batch['graduation_year'] . ')'); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            
            <div class="filter-row">
                <div class="filter-group">
                    <select id="placementFilter" class="form-control">
                        <option value="">All Status</option>
                        <option value="not_started" <?php echo ($placement_status == 'not_started') ? 'selected' : ''; ?>>Available</option>
                        <option value="in_progress" <?php echo ($placement_status == 'in_progress') ? 'selected' : ''; ?>>In Progress</option>
                        <option value="placed" <?php echo ($placement_status == 'placed') ? 'selected' : ''; ?>>Placed</option>
                        <option value="not_interested" <?php echo ($placement_status == 'not_interested') ? 'selected' : ''; ?>>Not Interested</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <select id="skillFilter" class="form-control">
                        <option value="">Filter by Skills</option>
                        <option value="programming">Programming</option>
                        <option value="web">Web Development</option>
                        <option value="mobile">Mobile Development</option>
                        <option value="ai">AI/ML</option>
                        <option value="data">Data Science</option>
                    </select>
                </div>

                <button class="btn-reset" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div class="student-grid">
        <?php if (mysqli_num_rows($result) == 0): ?>
            <div class="col-12">
                <div class="neu-alert neu-alert-info">
                    <i class="fas fa-info-circle fa-3x"></i>
                    <h4>No matching profiles found</h4>
                    <p>Try adjusting your filters or check back later for new profiles.</p>
                </div>
            </div>
        <?php else: ?>
            <?php while ($student = mysqli_fetch_assoc($result)): ?>
                <div class="student-card">
                    <div class="student-header">
                        <div class="student-info">
                            <div class="student-name"><?php echo htmlspecialchars($student['name']); ?></div>
                            <div class="student-id">
                                Roll No: <?php echo htmlspecialchars($student['roll_number']); ?>
                            </div>
                        </div>
                            <?php if ($student['placement_status'] == 'placed'): ?>
                                <span class="status-badge placed">
                                    <i class="fas fa-check-circle"></i> Placed
                                </span>
                            <?php elseif ($student['placement_status'] == 'in_progress'): ?>
                                <span class="status-badge in-progress">
                                    <i class="fas fa-sync-alt"></i> In Progress
                                </span>
                            <?php elseif ($student['placement_status'] == 'not_interested'): ?>
                                <span class="status-badge not-interested">
                                    <i class="fas fa-ban"></i> Not Interested
                                </span>
                            <?php else: ?>
                                <span class="status-badge available">
                                    <i class="fas fa-user-check"></i> Available
                                </span>
                            <?php endif; ?>
                        </div>

                    <div class="student-details">
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Batch</span>
                            <span class="detail-value"><?php echo htmlspecialchars($student['batch_name']); ?></span>
                            </div>
                            <?php if (!empty($student['skills'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Key Skills</span>
                            <span class="detail-value text-truncate"><?php echo htmlspecialchars($student['skills']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($student['achievements'])): ?>
                        <div class="detail-item">
                            <span class="detail-label">Achievements</span>
                            <span class="detail-value text-truncate"><?php echo htmlspecialchars($student['achievements']); ?></span>
                            </div>
                            <?php endif; ?>
                    </div>

                    <div class="student-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $student['certificate_count']; ?></div>
                            <div class="stat-label">Certifications</div>
                            </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $student['project_count']; ?></div>
                            <div class="stat-label">Projects</div>
                        </div>
                    </div>

                    <div class="student-actions">
                        <a href="student_profile.php?id=<?php echo $student['id']; ?>" class="btn-action">
                            <i class="fas fa-user"></i> View Profile
                            </a>
                            <?php if ($is_authenticated): ?>
                        <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="btn-action">
                                <i class="fas fa-envelope"></i> Contact
                            </a>
                            <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <div class="pagination">
            <?php 
            // Preserve all filter parameters in pagination links
            $params = $_GET;
            unset($params['page']); // Remove page param, we'll add it back
            
            // First and previous page links
            if ($page > 1): 
                $params['page'] = 1;
                $first_link = '?' . http_build_query($params);
                
                $params['page'] = $page - 1;
                $prev_link = '?' . http_build_query($params);
            ?>
                <a href="<?php echo $first_link; ?>" class="page-link first">
                    <i class="fas fa-angle-double-left"></i>
                </a>
                <a href="<?php echo $prev_link; ?>" class="page-link prev">
                    <i class="fas fa-angle-left"></i>
                </a>
            <?php endif; ?>
            
            <?php
            // Display limited page links with current page in the middle when possible
            $start_page = max(1, min($page - 2, $total_pages - 4));
            $end_page = min($total_pages, max($page + 2, 5));
            
            // Ensure we always show at least 5 pages when available
            if ($end_page - $start_page + 1 < 5 && $total_pages >= 5) {
                if ($start_page == 1) {
                    $end_page = min(5, $total_pages);
                }
                if ($end_page == $total_pages) {
                    $start_page = max(1, $total_pages - 4);
                }
            }
            
            for ($i = $start_page; $i <= $end_page; $i++): 
                $params['page'] = $i;
                $page_link = '?' . http_build_query($params);
            ?>
                <a href="<?php echo $page_link; ?>" class="page-link <?php echo ($i == $page) ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>
            
            <?php 
            // Next and last page links
            if ($page < $total_pages): 
                $params['page'] = $page + 1;
                $next_link = '?' . http_build_query($params);
                
                $params['page'] = $total_pages;
                $last_link = '?' . http_build_query($params);
            ?>
                <a href="<?php echo $next_link; ?>" class="page-link next">
                    <i class="fas fa-angle-right"></i>
                </a>
                <a href="<?php echo $last_link; ?>" class="page-link last">
                    <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>
        <div class="page-info">
            Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> 
            (<?php echo $total_students; ?> total profiles)
        </div>
    </div>
    <?php endif; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const searchButton = document.getElementById('searchButton');
            const departmentFilter = document.getElementById('departmentFilter');
            const batchFilter = document.getElementById('batchFilter');
            const placementFilter = document.getElementById('placementFilter');
            const skillFilter = document.getElementById('skillFilter');

            // Function to apply filters
            function applyFilters() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDept = departmentFilter.value;
                const selectedBatch = batchFilter.value;
                const selectedStatus = placementFilter.value;
                const selectedSkill = skillFilter.value;

                // Build query string
                const params = new URLSearchParams(window.location.search);
                
                if (searchTerm) params.set('search', searchTerm);
                else params.delete('search');
                
                if (selectedDept) params.set('department_id', selectedDept);
                else params.delete('department_id');
                
                if (selectedBatch) params.set('batch_id', selectedBatch);
                else params.delete('batch_id');
                
                if (selectedStatus) params.set('placement_status', selectedStatus);
                else params.delete('placement_status');
                
                if (selectedSkill) params.set('skills', selectedSkill);
                else params.delete('skills');
                
                // Reset to page 1 when filters change
                params.set('page', 1);

                // Redirect with new filters
                window.location.href = '?' + params.toString();
            }

            // Search button event listener
            searchButton.addEventListener('click', applyFilters);
            
            // Enter key event listener for search input
            searchInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    applyFilters();
                }
            });

            // Other filter changes should apply immediately
            departmentFilter.addEventListener('change', applyFilters);
            batchFilter.addEventListener('change', applyFilters);
            placementFilter.addEventListener('change', applyFilters);
            skillFilter.addEventListener('change', applyFilters);

            // Function to reset filters
            window.resetFilters = function() {
                // Clear search input value 
                document.getElementById('searchInput').value = '';
                
                // Navigate to the page without any query parameters
                window.location.href = window.location.pathname;
            };
        
        // Add smooth transition on page load
            const studentCards = document.querySelectorAll('.student-card');
            studentCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 100 + (index * 50));
        });
    });
    </script>
</div>

<?php include 'footer.php'; ?> 