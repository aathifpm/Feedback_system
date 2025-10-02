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
        position: relative;
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
        width: 20px;
        text-align: center;
    }

    /* Submenu Styles */
    .nav-submenu {
        margin-bottom: 0.5rem;
    }

    .nav-submenu-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem;
        color: var(--text-color);
        text-decoration: none;
        border-radius: 10px;
        transition: all 0.3s ease;
        cursor: pointer;
        background: none;
        border: none;
        width: 100%;
        text-align: left;
    }

    .nav-submenu-toggle:hover {
        background: var(--bg-color);
        box-shadow: var(--shadow);
        transform: translateY(-2px);
    }

    .nav-submenu-toggle i.main-icon {
        margin-right: 1rem;
        color: var(--primary-color);
        width: 20px;
        text-align: center;
    }

    .nav-submenu-toggle i.arrow {
        color: #666;
        transition: transform 0.3s ease;
        font-size: 0.8rem;
    }

    .nav-submenu-toggle.active i.arrow {
        transform: rotate(180deg);
    }

    .nav-submenu-items {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease;
        background: rgba(0,0,0,0.02);
        border-radius: 10px;
        margin-top: 0.5rem;
    }

    .nav-submenu-items.active {
        max-height: 200px;
        padding: 0.5rem 0;
    }

    .nav-submenu-item {
        display: flex;
        align-items: center;
        padding: 0.8rem 1rem 0.8rem 3rem;
        color: var(--text-color);
        text-decoration: none;
        transition: all 0.3s ease;
        border-radius: 8px;
        margin: 0.2rem 0.5rem;
        font-size: 0.9rem;
    }

    .nav-submenu-item:hover {
        background: var(--bg-color);
        box-shadow: var(--shadow);
        transform: translateX(5px);
    }

    .nav-submenu-item i {
        margin-right: 0.8rem;
        color: var(--primary-color);
        width: 16px;
        text-align: center;
        font-size: 0.9rem;
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

    /* Super Admin Badge */
    .super-admin-only {
        position: relative;
    }

    .super-admin-only::after {
        content: "SA";
        position: absolute;
        top: 5px;
        right: 5px;
        background: var(--primary-color);
        color: white;
        font-size: 0.6rem;
        padding: 2px 4px;
        border-radius: 3px;
        font-weight: bold;
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
        <a href="manage_departments.php" class="nav-link super-admin-only">
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
        
        <a href="manage_schedules.php" class="nav-link">
            <i class="fas fa-calendar"></i> Schedules
        </a>
        
        <a href="manage_venues.php" class="nav-link">
            <i class="fas fa-map-marker-alt"></i> Venues
        </a>
        
        <a href="manage_holidays.php" class="nav-link">
            <i class="fas fa-calendar-times"></i> Holidays
        </a>
        
        <a href="manage_attendance_records.php" class="nav-link">
            <i class="fas fa-clipboard-list"></i> Attendance
        </a>
        
        <a href="manage_training_batches.php" class="nav-link">
            <i class="fas fa-users"></i> Training Batches
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
        
        <a href="class_committee_reports.php" class="nav-link">
            <i class="fas fa-clipboard-list"></i> Class Committee Reports
        </a>
        
        <a href="reports.php" class="nav-link">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        
        <?php if ($is_super_admin): ?>
        <!-- Email Management - Super Admin Only -->
        <div class="nav-submenu">
            <button class="nav-submenu-toggle super-admin-only" onclick="toggleSubmenu('emailSubmenu')">
                <div style="display: flex; align-items: center;">
                    <i class="fas fa-envelope main-icon"></i>
                    <span>Email Management</span>
                </div>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div id="emailSubmenu" class="nav-submenu-items">
                <a href="email_sender.php" class="nav-submenu-item">
                    <i class="fas fa-paper-plane"></i> Send Emails
                </a>
                <a href="email/manage_mailboxes.php" class="nav-submenu-item">
                    <i class="fas fa-mail-bulk"></i> Manage Mailboxes
                </a>
            </div>
        </div>

        <!-- System Management - Super Admin Only -->
        <div class="nav-submenu">
            <button class="nav-submenu-toggle super-admin-only" onclick="toggleSubmenu('systemSubmenu')">
                <div style="display: flex; align-items: center;">
                    <i class="fas fa-cogs main-icon"></i>
                    <span>System Management</span>
                </div>
                <i class="fas fa-chevron-down arrow"></i>
            </button>
            <div id="systemSubmenu" class="nav-submenu-items">
                <a href="maintenance_control.php" class="nav-submenu-item">
                    <i class="fas fa-tools"></i> Maintenance Control
                </a>
                <a href="settings.php" class="nav-submenu-item">
                    <i class="fas fa-cog"></i> System Settings
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <a href="../logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div>

<script>
    // Submenu toggle function
    function toggleSubmenu(submenuId) {
        const submenu = document.getElementById(submenuId);
        const toggle = submenu.previousElementSibling;
        
        // Close all other submenus
        document.querySelectorAll('.nav-submenu-items').forEach(item => {
            if (item.id !== submenuId) {
                item.classList.remove('active');
                item.previousElementSibling.classList.remove('active');
            }
        });
        
        // Toggle current submenu
        submenu.classList.toggle('active');
        toggle.classList.toggle('active');
    }

    // Add active class to current nav link
    document.addEventListener('DOMContentLoaded', function() {
        const currentPath = window.location.pathname;
        const currentFile = currentPath.split('/').pop();
        
        // Check main nav links
        document.querySelectorAll('.nav-link').forEach(link => {
            const linkFile = link.getAttribute('href');
            if (linkFile === currentFile) {
                link.classList.add('active');
            }
        });
        
        // Check submenu items and expand parent if active
        document.querySelectorAll('.nav-submenu-item').forEach(item => {
            const itemFile = item.getAttribute('href');
            if (itemFile === currentFile || itemFile.split('/').pop() === currentFile) {
                item.classList.add('active');
                // Expand parent submenu
                const parentSubmenu = item.closest('.nav-submenu-items');
                const parentToggle = parentSubmenu.previousElementSibling;
                parentSubmenu.classList.add('active');
                parentToggle.classList.add('active');
            }
        });
        
        // Sidebar toggle for mobile
        const sidebarToggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        
        if (sidebarToggle && sidebar) {
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
        }
    });
</script>