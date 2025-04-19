<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Restrict this page to super admins only
require_super_admin();

$success_msg = $error_msg = '';

// Handle department operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                if (isset($_POST['name']) && isset($_POST['code'])) {
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $code = mysqli_real_escape_string($conn, $_POST['code']);
                    
                    // Check if department code already exists
                    $check_query = "SELECT id FROM departments WHERE code = ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "s", $code);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        $error_msg = "Department code already exists!";
                    } else {
                        $query = "INSERT INTO departments (name, code) VALUES (?, ?)";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "ss", $name, $code);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success_msg = "Department added successfully!";
                            
                            // Log the action
                            $log_query = "INSERT INTO user_logs (user_id, role, action, details) 
                                        VALUES (?, 'admin', 'add_department', ?)";
                            $log_stmt = mysqli_prepare($conn, $log_query);
                            $details = json_encode(['department_name' => $name, 'department_code' => $code]);
                            mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $details);
                            mysqli_stmt_execute($log_stmt);
                        } else {
                            $error_msg = "Error adding department!";
                        }
                    }
                }
                break;

            case 'edit':
                if (isset($_POST['id']) && isset($_POST['name']) && isset($_POST['code'])) {
                    $id = mysqli_real_escape_string($conn, $_POST['id']);
                    $name = mysqli_real_escape_string($conn, $_POST['name']);
                    $code = mysqli_real_escape_string($conn, $_POST['code']);
                    
                    // Check if code exists for other departments
                    $check_query = "SELECT id FROM departments WHERE code = ? AND id != ?";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "si", $code, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    
                    if (mysqli_num_rows($result) > 0) {
                        $error_msg = "Department code already exists!";
                    } else {
                        $query = "UPDATE departments SET name = ?, code = ? WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "ssi", $name, $code, $id);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success_msg = "Department updated successfully!";
                            
                            // Log the action
                            $log_query = "INSERT INTO user_logs (user_id, role, action, details) 
                                        VALUES (?, 'admin', 'edit_department', ?)";
                            $log_stmt = mysqli_prepare($conn, $log_query);
                            $details = json_encode(['department_id' => $id, 'department_name' => $name]);
                            mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $details);
                            mysqli_stmt_execute($log_stmt);
                        } else {
                            $error_msg = "Error updating department!";
                        }
                    }
                }
                break;

            case 'delete':
                if (isset($_POST['id'])) {
                    $id = mysqli_real_escape_string($conn, $_POST['id']);
                    
                    // Check if department has associated faculty or students
                    $check_query = "SELECT 
                        (SELECT COUNT(*) FROM faculty WHERE department_id = ?) as faculty_count,
                        (SELECT COUNT(*) FROM students WHERE department_id = ?) as student_count";
                    $stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($stmt, "ii", $id, $id);
                    mysqli_stmt_execute($stmt);
                    $result = mysqli_stmt_get_result($stmt);
                    $counts = mysqli_fetch_assoc($result);
                    
                    if ($counts['faculty_count'] > 0 || $counts['student_count'] > 0) {
                        $error_msg = "Cannot delete department with associated faculty or students!";
                    } else {
                        $query = "DELETE FROM departments WHERE id = ?";
                        $stmt = mysqli_prepare($conn, $query);
                        mysqli_stmt_bind_param($stmt, "i", $id);
                        
                        if (mysqli_stmt_execute($stmt)) {
                            $success_msg = "Department deleted successfully!";
                            
                            // Log the action
                            $log_query = "INSERT INTO user_logs (user_id, role, action, details) 
                                        VALUES (?, 'admin', 'delete_department', ?)";
                            $log_stmt = mysqli_prepare($conn, $log_query);
                            $details = json_encode(['department_id' => $id]);
                            mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $details);
                            mysqli_stmt_execute($log_stmt);
                        } else {
                            $error_msg = "Error deleting department!";
                        }
                    }
                }
                break;
        }
    }
}

// Fetch all departments with additional information
$query = "SELECT 
    d.*,
    COUNT(DISTINCT f.id) as faculty_count,
    COUNT(DISTINCT s.id) as student_count,
    COUNT(DISTINCT sub.id) as subject_count
FROM departments d
LEFT JOIN faculty f ON d.id = f.department_id
LEFT JOIN students s ON d.id = s.department_id
LEFT JOIN subjects sub ON d.id = sub.department_id
GROUP BY d.id
ORDER BY d.name";

$departments = mysqli_query($conn, $query);
?>
<?php include_once 'includes/sidebar.php'; ?>
<?php include_once 'includes/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Departments - College Feedback System</title>
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

        .department-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .department-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .department-card:hover {
            transform: translateY(-5px);
        }

        .department-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .department-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .department-code {
            padding: 0.3rem 0.8rem;
            background: var(--bg-color);
            border-radius: 20px;
            font-size: 0.9rem;
            box-shadow: var(--inner-shadow);
        }

        .department-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-color);
        }

        .department-actions {
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
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .btn-edit {
            color: #2980b9;
        }

        .btn-delete {
            color: var(--primary-color);
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
        }

        .modal-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 500px;
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

        /* Modal Styles */
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
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 500px;
            position: relative;
        }

        .modal-title {
            color: var(--primary-color);
            font-size: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1.2rem;
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

        .validation-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.5rem;
            padding-left: 0.5rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            justify-content: flex-end;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-color);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn:active {
            transform: translateY(0);
        }

        /* Department Card Styles */
        .department-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            margin-bottom: 1.5rem;
        }

        .department-card:hover {
            transform: translateY(-5px);
        }

        .department-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .department-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .department-code {
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            font-size: 0.9rem;
            box-shadow: var(--inner-shadow);
        }

        .department-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }

        .stat-item {
            text-align: center;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary-color);
        }

        .stat-label {
            font-size: 0.8rem;
            color: var(--text-color);
            margin-top: 0.25rem;
        }

        .department-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn-action {
            flex: 1;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .btn-edit {
            color: #2980b9;
        }

        .btn-delete {
            color: var(--primary-color);
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 1rem;
                padding: 1.5rem;
            }

            .modal-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .department-stats {
                grid-template-columns: 1fr;
            }

            .department-actions {
                flex-direction: column;
            }

            .btn-action {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Departments</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Department
            </button>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?php echo $success_msg; ?></div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?php echo $error_msg; ?></div>
        <?php endif; ?>

        <div class="department-grid">
            <?php while ($dept = mysqli_fetch_assoc($departments)): ?>
                <div class="department-card">
                    <div class="department-header">
                        <span class="department-name"><?php echo htmlspecialchars($dept['name']); ?></span>
                        <span class="department-code"><?php echo htmlspecialchars($dept['code']); ?></span>
                    </div>
                    <div class="department-stats">
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $dept['faculty_count']; ?></div>
                            <div class="stat-label">Faculty</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $dept['student_count']; ?></div>
                            <div class="stat-label">Students</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number"><?php echo $dept['subject_count']; ?></div>
                            <div class="stat-label">Subjects</div>
                        </div>
                    </div>
                    <div class="department-actions">
                        <button class="btn-action btn-edit" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($dept)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-action btn-delete" onclick="confirmDelete(<?php echo $dept['id']; ?>)">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>

    <!-- Add Department Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Add Department</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-group">
                    <label for="name">Department Name</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                    <div class="validation-hint">Enter full department name (e.g., Computer Science and Engineering)</div>
                </div>
                <div class="form-group">
                    <label for="code">Department Code</label>
                    <input type="text" id="code" name="code" class="form-control" required>
                    <div class="validation-hint">Enter short code (e.g., CSE)</div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Department
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('addModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Department Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2 class="modal-title">Edit Department</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="form-group">
                    <label for="edit_name">Department Name</label>
                    <input type="text" id="edit_name" name="name" class="form-control" required>
                    <div class="validation-hint">Enter full department name (e.g., Computer Science and Engineering)</div>
                </div>
                <div class="form-group">
                    <label for="edit_code">Department Code</label>
                    <input type="text" id="edit_code" name="code" class="form-control" required>
                    <div class="validation-hint">Enter short code (e.g., CSE)</div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Department
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showAddModal() {
            document.getElementById('addModal').style.display = 'flex';
        }

        function showEditModal(dept) {
            document.getElementById('edit_id').value = dept.id;
            document.getElementById('edit_name').value = dept.name;
            document.getElementById('edit_code').value = dept.code;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function confirmDelete(id) {
            if (confirm('Are you sure you want to delete this department?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
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
    </script>
</body>
</html>