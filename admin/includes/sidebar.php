<?php
if (!isset($current_page)) {
    $current_page = basename($_SERVER['PHP_SELF']);
}
?>
<div class="sidebar">
    <h2>Admin Panel</h2>
    <nav>
        <a href="dashboard.php" class="nav-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="manage_departments.php" class="nav-link <?php echo $current_page == 'manage_departments.php' ? 'active' : ''; ?>">
            <i class="fas fa-building"></i> Departments
        </a>
        <a href="manage_faculty.php" class="nav-link <?php echo $current_page == 'manage_faculty.php' ? 'active' : ''; ?>">
            <i class="fas fa-chalkboard-teacher"></i> Faculty
        </a>
        <a href="manage_students.php" class="nav-link <?php echo $current_page == 'manage_students.php' ? 'active' : ''; ?>">
            <i class="fas fa-user-graduate"></i> Students
        </a>
        <a href="manage_subjects.php" class="nav-link <?php echo $current_page == 'manage_subjects.php' ? 'active' : ''; ?>">
            <i class="fas fa-book"></i> Subjects
        </a>
        <a href="manage_feedback.php" class="nav-link <?php echo $current_page == 'manage_feedback.php' ? 'active' : ''; ?>">
            <i class="fas fa-comments"></i> Feedback
        </a>
        <a href="reports.php" class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
        <a href="settings.php" class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> Settings
        </a>
        <a href="../logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt"></i> Logout
        </a>
    </nav>
</div> 