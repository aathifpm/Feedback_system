<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}
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
        <a href="manage_feedback.php" class="nav-link">
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
