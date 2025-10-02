<?php
// Maintenance check middleware
// Include this file at the top of dashboard pages to check maintenance status

if (!function_exists('check_maintenance_mode')) {
    require_once 'functions.php';
}

// Determine user role from session
$user_role = $_SESSION['role'] ?? 'guest';

// Map roles to modules
$role_module_map = [
    'student' => 'student',
    'faculty' => 'faculty', 
    'hod' => 'hod',
    'admin' => 'admin'
];

$module = $role_module_map[$user_role] ?? 'global';

// Check maintenance mode
$maintenance = check_maintenance_mode($module, $pdo ?? null);

if ($maintenance['is_maintenance']) {
    // Allow admins to see a warning but continue
    if ($user_role === 'admin') {
        $maintenance_warning = "⚠️ " . $module . " module is currently in maintenance mode: " . $maintenance['message'];
    } else {
        // Redirect other users to maintenance page
        $_SESSION['maintenance_message'] = $maintenance['message'];
        header('Location: ' . (strpos($_SERVER['PHP_SELF'], 'admin/') !== false ? '../' : '') . 'maintenance.php?module=' . $module);
        exit();
    }
}
?>