<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit();
}

// Handle different admin actions based on GET parameter
$action = isset($_GET['action']) ? filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING) : 'dashboard';

// Include the appropriate admin action file
switch ($action) {
    case 'manage_subjects':
        $content = 'admin_actions/manage_subjects.php';
        break;
    case 'manage_faculty':
        $content = 'admin_actions/manage_faculty.php';
        break;
    case 'manage_students':
        $content = 'admin_actions/manage_students.php';
        break;
    case 'manage_academic_years':
        $content = 'admin_actions/manage_academic_years.php';
        break;
    default:
        $content = 'admin_actions/dashboard.php';
        break;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f4f4;
            color: #333;
        }
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        .admin-nav {
            width: 250px;
            background-color: #333;
            color: #fff;
            padding: 20px;
        }
        .admin-nav ul {
            list-style-type: none;
        }
        .admin-nav li {
            margin-bottom: 10px;
        }
        .admin-nav a {
            color: #fff;
            text-decoration: none;
            font-size: 16px;
        }
        .admin-content {
            flex-grow: 1;
            padding: 20px;
        }
        h1 {
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background-color: #007bff;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
        }
        .btn-danger {
            background-color: #dc3545;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <nav class="admin-nav">
            <ul>
                <li><a href="admin_panel.php">Dashboard</a></li>
                <li><a href="admin_panel.php?action=manage_subjects">Manage Subjects</a></li>
                <li><a href="admin_panel.php?action=manage_faculty">Manage Faculty</a></li>
                <li><a href="admin_panel.php?action=manage_students">Manage Students</a></li>
                <li><a href="admin_panel.php?action=manage_academic_years">Manage Academic Years</a></li>
                <li><a href="logout.php">Logout</a></li>
            </ul>
        </nav>
        <main class="admin-content">
            <?php
            if (isset($_SESSION['success_message'])) {
                echo '<div class="alert alert-success">' . htmlspecialchars($_SESSION['success_message']) . '</div>';
                unset($_SESSION['success_message']);
            }
            if (isset($_SESSION['error_message'])) {
                echo '<div class="alert alert-danger">' . htmlspecialchars($_SESSION['error_message']) . '</div>';
                unset($_SESSION['error_message']);
            }
            include $content;
            ?>
        </main>
    </div>
</body>
</html>