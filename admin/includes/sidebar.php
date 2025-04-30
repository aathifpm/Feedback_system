<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Get department name for department admins
$department_name = '';
if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
    require_once '../db_connection.php';
    $dept_query = "SELECT name FROM departments WHERE id = ?";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $department_name = $row['name'];
    }
}

// Define if user is super admin
$is_super_admin = ($_SESSION['admin_type'] === 'super_admin');
?>
<style>
    .sidebar {
        width: 280px;
        background: var(--bg-color);
        padding: 2rem;
        box-shadow: var(--shadow);
        border-radius: 0 20px 20px 0;
        z-index: 1000;
        position: fixed;
        left: 0;
        top: var(--header-height);
        height: calc(100vh - var(--header-height));
        overflow-y: auto;
    }

    .sidebar h2 {
        color: var(--primary-color);
        margin-bottom: 0.5rem;
        font-size: 1.5rem;
        text-align: center;
    }
    
    .sidebar .admin-type {
        text-align: center;
        margin-bottom: 1.5rem;
        font-size: 0.9rem;
        color: #666;
        padding: 0.3rem 0.8rem;
        background: rgba(231, 76, 60, 0.1);
        border-radius: 20px;
        display: inline-block;
        margin-left: auto;
        margin-right: auto;
    }
    
    .sidebar .admin-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 1.5rem;
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
    
    .sidebar-toggle {
        display: none;
        position: fixed;
        top: calc(var(--header-height) + 10px);
        left: 20px;
        width: 40px;
        height: 40px;
        background: var(--bg-color);
        border-radius: 50%;
        box-shadow: var(--shadow);
        z-index: 1001;
        border: none;
        cursor: pointer;
        color: var(--primary-color);
        font-size: 1.2rem;
        transition: all 0.3s ease;
    }

    .sidebar-toggle:hover {
        transform: scale(1.1);
    }
    
    .main-content {
        margin-left: 280px;
        transition: margin-left 0.3s ease;
    }

    @media (max-width: 768px) {
        .sidebar {
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .main-content {
            margin-left: 0;
        }
    }
</style>

<button id="sidebarToggle" class="sidebar-toggle">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar" id="sidebar">
    <div class="admin-container">
        <h2>Admin Panel</h2>
        <div class="admin-type">
            <?php if ($is_super_admin): ?>
                <i class="fas fa-user-shield"></i> Super Admin
            <?php else: ?>
                <i class="fas fa-user-cog"></i> <?php echo htmlspecialchars($department_name); ?> Admin
            <?php endif; ?>
        </div>
    </div>
    <nav>
        <a href="dashboard.php" class="nav-link">
            <i class="fas fa-home"></i> Dashboard
        </a>
        
        <?php if ($is_super_admin): ?>
        <!-- Super Admin Options -->
        <a href="manage_departments.php" class="nav-link">
            <i class="fas fa-building"></i> Departments
        </a>
        <?php endif; ?>

        
        <a href="manage_faculty.php" class="nav-link">
            <i class="fas fa-chalkboard-teacher"></i> Faculty
        </a>
        
        <a href="manage_students.php" class="nav-link">
            <i class="fas fa-user-graduate"></i> Students
        </a>
        
        <a href="manage_subjects.php" class="nav-link">
            <i class="fas fa-book"></i> Subjects
        </a>
        
        <a href="manage_exam_timetable.php" class="nav-link">
            <i class="fas fa-calendar-alt"></i> Exam Timetable
        </a>

        <a href="view_exam_feedbacks.php" class="nav-link">
            <i class="fas fa-clipboard-check"></i> Exam Feedbacks
        </a>
        
        <a href="manage_feedback.php" class="nav-link">
            <i class="fas fa-comments"></i> Course Feedback
        </a>
        
        <a href="reports.php" class="nav-link">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        <?php if ($is_super_admin): ?>
        <a href="settings.php" class="nav-link">
            <i class="fas fa-cog"></i> Settings
        </a>
        <?php endif; ?>
        
        <a href="../logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>

<script>
    // Add active class to current nav link
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.nav-link').forEach(link => {
            if(link.href === window.location.href) {
                link.classList.add('active');
            }
        });
        
        // Sidebar toggle for mobile
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
            if (sidebar.classList.contains('active')) {
                sidebarToggle.innerHTML = '<i class="fas fa-times"></i>';
            } else {
                sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 && 
                !sidebar.contains(event.target) && 
                !sidebarToggle.contains(event.target) && 
                sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                sidebarToggle.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
    });
</script>
