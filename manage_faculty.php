<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    header('Location: header.php');
    exit();
}

// Get HOD's department ID
$hod_department_id = $_SESSION['department_id'];

$success_msg = $error_msg = '';

// Handle faculty operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $faculty_id = mysqli_real_escape_string($conn, $_POST['faculty_id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = $hod_department_id; // Force department ID to be HOD's department
                    $designation = mysqli_real_escape_string($conn, $_POST['designation']);
                    $experience = intval($_POST['experience']);
                    $qualification = mysqli_real_escape_string($conn, $_POST['qualification']);
                    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);
                    
                    // Default password
                    $default_password = password_hash("Faculty@123", PASSWORD_DEFAULT);
                    
                    // Check for existing faculty_id or email
                    $check_query = "SELECT id FROM faculty WHERE faculty_id = ? OR email = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ss", $faculty_id, $email);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Faculty ID or Email already exists!");
                    }
                    
                    $query = "INSERT INTO faculty (faculty_id, name, email, password, department_id, designation, 
                             experience, qualification, specialization, is_active) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                    
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssssisiss", 
                        $faculty_id, $name, $email, $default_password, $department_id,
                        $designation, $experience, $qualification, $specialization
                    );
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', 'add_faculty', ?, ?, ?)";
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode([
                            'faculty_id' => $faculty_id,
                            'name' => $name,
                            'department_id' => $department_id
                        ]);
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        
                        mysqli_stmt_bind_param($log_stmt, "isss", 
                            $_SESSION['user_id'], 
                            $details,
                            $ip_address,
                            $user_agent
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Faculty added successfully!";
                    } else {
                        throw new Exception("Error adding faculty!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'edit':
                try {
                    $id = intval($_POST['id']);
                    $faculty_id = mysqli_real_escape_string($conn, $_POST['faculty_id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $email = mysqli_real_escape_string($conn, $_POST['email']);
                    $department_id = intval($_POST['department_id']);
                    $designation = mysqli_real_escape_string($conn, $_POST['designation']);
                    $experience = intval($_POST['experience']);
                    $qualification = mysqli_real_escape_string($conn, $_POST['qualification']);
                    $specialization = mysqli_real_escape_string($conn, $_POST['specialization']);

                    // Check if faculty_id or email already exists for other faculty
                    $check_query = "SELECT id FROM faculty WHERE (faculty_id = ? OR email = ?) AND id != ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ssi", $faculty_id, $email, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);

                    if (mysqli_num_rows($result) > 0) {
                        throw new Exception("Faculty ID or Email already exists!");
                    }

                    $query = "UPDATE faculty SET 
                             faculty_id = ?, 
                             name = ?,
                             email = ?,
                             department_id = ?,
                             designation = ?,
                             experience = ?,
                             qualification = ?,
                             specialization = ?
                             WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssissssi",
                        $faculty_id, $name, $email, $department_id,
                        $designation, $experience, $qualification, 
                        $specialization, $id
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        logAction($conn, $_SESSION['user_id'], 'admin', 'edit_faculty', [
                            'faculty_id' => $faculty_id,
                            'name' => $name,
                            'department_id' => $department_id
                        ]);
                        $success_msg = "Faculty updated successfully!";
                    } else {
                        throw new Exception("Error updating faculty!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;

            case 'toggle_status':
                try {
                    $id = intval($_POST['id']);
                    $status = $_POST['status'] === 'true';
                    
                    $query = "UPDATE faculty SET is_active = ? WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $status, $id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        // Log the action directly
                        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                                     VALUES (?, 'admin', ?, ?, ?, ?)";
                        $action = $status ? 'activate_faculty' : 'deactivate_faculty';
                        $log_stmt = mysqli_prepare($conn, $log_query);
                        $details = json_encode(['faculty_id' => $id]);
                        $ip_address = $_SERVER['REMOTE_ADDR'];
                        $user_agent = $_SERVER['HTTP_USER_AGENT'];
                        
                        mysqli_stmt_bind_param($log_stmt, "issss", 
                            $_SESSION['user_id'], 
                            $action,
                            $details,
                            $ip_address,
                            $user_agent
                        );
                        mysqli_stmt_execute($log_stmt);
                        
                        $success_msg = "Faculty status updated successfully!";
                    } else {
                        throw new Exception("Error updating faculty status!");
                    }
                } catch (Exception $e) {
                    $error_msg = $e->getMessage();
                }
                break;
        }
    }
}

// Fetch departments for dropdown - now only fetch HOD's department
$dept_query = "SELECT id, name FROM departments WHERE id = ? ORDER BY name";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, "i", $hod_department_id);
mysqli_stmt_execute($dept_stmt);
$departments = mysqli_stmt_get_result($dept_stmt);

// Fetch faculty with related information - only from HOD's department
$faculty_query = "SELECT 
    f.*,
    d.name as department_name,
    COUNT(DISTINCT sa.id) as subject_count,
    COUNT(DISTINCT fb.id) as feedback_count,
    ROUND(AVG(fb.cumulative_avg), 2) as avg_rating
FROM faculty f
LEFT JOIN departments d ON f.department_id = d.id
LEFT JOIN subject_assignments sa ON f.id = sa.faculty_id
LEFT JOIN feedback fb ON fb.assignment_id = sa.id
WHERE f.department_id = ?
GROUP BY f.id
ORDER BY f.name";

$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $hod_department_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Faculty - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    
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

        .faculty-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .faculty-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .faculty-card:hover {
            transform: translateY(-5px);
        }

        .faculty-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .faculty-info {
            flex: 1;
        }

        .faculty-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.25rem;
        }

        .faculty-id {
            font-size: 0.9rem;
            color: #666;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .faculty-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            font-size: 0.8rem;
            color: #666;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .faculty-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .faculty-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
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

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
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

            .faculty-grid {
                grid-template-columns: 1fr;
            }
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

        .department-filters {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            flex: 1;
        }

        .filter-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }

        .search-box {
            min-width: 300px;
        }

        .filter-group {
            flex: 1;
            min-width: 200px;
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

        .hidden {
            display: none !important;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Faculty</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Faculty
            </button>
        </div>

        <div class="filter-section">
            <div class="filter-row">
                <div class="search-box">
                    <input type="text" id="searchInput" placeholder="Search faculty..." class="form-control">
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <select id="designationFilter" class="form-control">
                        <option value="">All Designations</option>
                        <?php
                        $designation_query = "SELECT DISTINCT designation FROM faculty WHERE designation IS NOT NULL AND department_id = ? ORDER BY designation";
                        $designation_stmt = mysqli_prepare($conn, $designation_query);
                        mysqli_stmt_bind_param($designation_stmt, "i", $hod_department_id);
                        mysqli_stmt_execute($designation_stmt);
                        $designations = mysqli_stmt_get_result($designation_stmt);
                        while ($designation = mysqli_fetch_assoc($designations)): ?>
                            <option value="<?php echo htmlspecialchars($designation['designation']); ?>">
                                <?php echo htmlspecialchars($designation['designation']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="experienceFilter" class="form-control">
                        <option value="">All Experience</option>
                        <option value="0-5">0-5 years</option>
                        <option value="6-10">6-10 years</option>
                        <option value="11-15">11-15 years</option>
                        <option value="15+">15+ years</option>
                    </select>
                </div>

                <div class="filter-group">
                    <select id="statusFilter" class="form-control">
                        <option value="">All Status</option>
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                </div>

                <button class="btn btn-reset" onclick="resetFilters()">
                    <i class="fas fa-undo"></i> Reset Filters
                </button>
            </div>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <div class="faculty-grid">
            <?php while ($faculty = mysqli_fetch_assoc($faculty_result)): ?>
                <div class="faculty-card" 
                     data-department="<?php echo $faculty['department_id']; ?>"
                     data-designation="<?php echo htmlspecialchars($faculty['designation']); ?>"
                     data-experience="<?php echo $faculty['experience']; ?>"
                     data-status="<?php echo $faculty['is_active'] ? '1' : '0'; ?>">
                    <div class="faculty-header">
                        <div class="faculty-info">
                            <div class="faculty-name"><?php echo htmlspecialchars($faculty['name']); ?></div>
                            <div class="faculty-id"><?php echo htmlspecialchars($faculty['faculty_id']); ?></div>
                        </div>
                        <span class="status-badge <?php echo $faculty['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                            <?php echo $faculty['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <div class="faculty-details">
                        <div class="detail-item">
                            <span class="detail-label">Department</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['department_name']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Designation</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['designation']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Experience</span>
                            <span class="detail-value"><?php echo $faculty['experience']; ?> years</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Qualification</span>
                            <span class="detail-value"><?php echo htmlspecialchars($faculty['qualification']); ?></span>
                        </div>
                    </div>

                    <div class="faculty-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $faculty['subject_count']; ?></div>
                            <div class="stat-label">Subjects</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $faculty['feedback_count']; ?></div>
                            <div class="stat-label">Feedbacks</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $faculty['avg_rating'] ?? 'N/A'; ?></div>
                            <div class="stat-label">Avg Rating</div>
                        </div>
                    </div>

                    <div class="faculty-actions">
                        <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($faculty)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action" onclick="toggleStatus(<?php echo $faculty['id']; ?>, <?php echo $faculty['is_active'] ? 'false' : 'true'; ?>)">
                            <i class="fas fa-power-off"></i> <?php echo $faculty['is_active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                        <button class="btn-action" onclick="viewFeedback(<?php echo $faculty['id']; ?>)">
                            <i class="fas fa-comments"></i> View Feedback
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Add Faculty Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add Faculty</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label for="faculty_id">Faculty ID</label>
                    <input type="text" id="faculty_id" name="faculty_id" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-control" required>
                    <small class="form-text text-muted">Default password will be: Faculty@123</small>
                </div>

                        <?php 
                // Get department name for display
                $dept = mysqli_fetch_assoc($departments);
                        mysqli_data_seek($departments, 0);
                ?>
                <div class="form-group">
                    <label>Department</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($dept['name']); ?>" readonly>
                    <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                </div>

                <div class="form-group">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="experience">Experience (years)</label>
                    <input type="number" id="experience" name="experience" class="form-control" required min="0">
                </div>

                <div class="form-group">
                    <label for="qualification">Qualification</label>
                    <input type="text" id="qualification" name="qualification" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="specialization">Specialization</label>
                    <input type="text" id="specialization" name="specialization" class="form-control" required>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Add Faculty</button>
                    <button type="button" class="btn" onclick="hideModal('addModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Faculty Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Faculty</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group">
                    <label for="edit_faculty_id">Faculty ID</label>
                    <input type="text" id="edit_faculty_id" name="faculty_id" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_name">Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_email">Email</label>
                    <input type="email" id="edit_email" name="email" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Department</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($dept['name']); ?>" readonly>
                    <input type="hidden" name="department_id" value="<?php echo $dept['id']; ?>">
                </div>

                <div class="form-group">
                    <label for="edit_designation">Designation</label>
                    <input type="text" id="edit_designation" name="designation" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_experience">Experience (years)</label>
                    <input type="number" id="edit_experience" name="experience" class="form-control" required min="0">
                </div>

                <div class="form-group">
                    <label for="edit_qualification">Qualification</label>
                    <input type="text" id="edit_qualification" name="qualification" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_specialization">Specialization</label>
                    <input type="text" id="edit_specialization" name="specialization" class="form-control" required>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Update Faculty</button>
                    <button type="button" class="btn" onclick="hideModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function showEditModal(faculty) {
            document.getElementById('edit_id').value = faculty.id;
            document.getElementById('edit_faculty_id').value = faculty.faculty_id;
            document.getElementById('edit_name').value = faculty.name;
            document.getElementById('edit_email').value = faculty.email;
            document.getElementById('edit_designation').value = faculty.designation;
            document.getElementById('edit_experience').value = faculty.experience;
            document.getElementById('edit_qualification').value = faculty.qualification;
            document.getElementById('edit_specialization').value = faculty.specialization;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function toggleStatus(id, status) {
            if (confirm('Are you sure you want to ' + (status ? 'activate' : 'deactivate') + ' this faculty?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="id" value="${id}">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function viewFeedback(id) {
            window.location.href = `view_faculty_feedback.php?faculty_id=${id}`;
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.className === 'modal') {
                event.target.style.display = 'none';
            }
        }

        // Add active class to current nav link
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            const facultyCards = document.querySelectorAll('.faculty-card');
            const searchInput = document.getElementById('searchInput');
            const designationFilter = document.getElementById('designationFilter');
            const experienceFilter = document.getElementById('experienceFilter');
            const statusFilter = document.getElementById('statusFilter');

            // Filter function
            function filterFaculty() {
                const searchTerm = searchInput.value.toLowerCase();
                const selectedDesignation = designationFilter.value.toLowerCase();
                const selectedExperience = experienceFilter.value;
                const selectedStatus = statusFilter.value;

                facultyCards.forEach(card => {
                    const name = card.querySelector('.faculty-name').textContent.toLowerCase();
                    const facultyId = card.querySelector('.faculty-id').textContent.toLowerCase();
                    const designation = card.dataset.designation.toLowerCase();
                    const experience = parseInt(card.dataset.experience);
                    const status = card.dataset.status;

                    let showCard = true;

                    // Search term filter
                    if (searchTerm && !name.includes(searchTerm) && !facultyId.includes(searchTerm)) {
                        showCard = false;
                    }

                    // Designation filter
                    if (selectedDesignation && designation !== selectedDesignation) {
                        showCard = false;
                    }

                    // Experience filter
                    if (selectedExperience) {
                        const [min, max] = selectedExperience.split('-').map(num => num.replace('+', ''));
                        if (max) {
                            if (experience < parseInt(min) || experience > parseInt(max)) {
                                showCard = false;
                            }
                        } else {
                            if (experience < parseInt(min)) {
                                showCard = false;
                            }
                        }
                    }

                    // Status filter
                    if (selectedStatus !== '' && status !== selectedStatus) {
                        showCard = false;
                    }

                    card.classList.toggle('hidden', !showCard);
                });
            }

            // Event listeners
            searchInput.addEventListener('input', filterFaculty);
            designationFilter.addEventListener('change', filterFaculty);
            experienceFilter.addEventListener('change', filterFaculty);
            statusFilter.addEventListener('change', filterFaculty);
        });

        function resetFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('designationFilter').value = '';
            document.getElementById('experienceFilter').value = '';
            document.getElementById('statusFilter').value = '';

            document.querySelectorAll('.faculty-card').forEach(card => {
                card.classList.remove('hidden');
            });
        }
    </script>
</body>
</html>
