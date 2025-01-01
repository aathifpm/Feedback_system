<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_academic_year'])) {
        $year_id = intval($_POST['current_year']);
        
        // First, reset all years to not current
        $reset_query = "UPDATE academic_years SET is_current = FALSE";
        mysqli_query($conn, $reset_query);
        
        // Set the selected year as current
        $update_query = "UPDATE academic_years SET is_current = TRUE WHERE id = ?";
        $stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($stmt, "i", $year_id);
        mysqli_stmt_execute($stmt);
        
        $_SESSION['success_msg'] = "Academic year updated successfully!";
        header('Location: settings.php');
        exit();
    }
    
    if (isset($_POST['add_academic_year'])) {
        $year_range = mysqli_real_escape_string($conn, $_POST['year_range']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        $insert_query = "INSERT INTO academic_years (year_range, start_date, end_date) VALUES (?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($stmt, "sss", $year_range, $start_date, $end_date);
        mysqli_stmt_execute($stmt);
        
        $_SESSION['success_msg'] = "New academic year added successfully!";
        header('Location: settings.php');
        exit();
    }
    
    if (isset($_POST['update_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        $password_query = "SELECT password FROM users WHERE id = ?";
        $stmt = mysqli_prepare($conn, $password_query);
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE users SET password = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "si", $hashed_password, $_SESSION['user_id']);
                mysqli_stmt_execute($stmt);
                
                $_SESSION['success_msg'] = "Password updated successfully!";
            } else {
                $_SESSION['error_msg'] = "New passwords do not match!";
            }
        } else {
            $_SESSION['error_msg'] = "Current password is incorrect!";
        }
        
        header('Location: settings.php');
        exit();
    }
}

// Fetch academic years
$years_query = "SELECT * FROM academic_years ORDER BY start_date DESC";
$years_result = mysqli_query($conn, $years_query);

// Get current academic year
$current_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
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
            background: var(--primary-color);
            padding: 20px;
            color: #fff;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar h2 {
            color: #fff;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--secondary-color);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            color: #fff;
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 5px;
            transition: all 0.3s ease;
        }

        .nav-link i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .nav-link:hover {
            background: var(--secondary-color);
            box-shadow: var(--shadow);
        }

        .nav-link.active {
            background: var(--accent-color);
            box-shadow: var(--shadow);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            margin-left: 280px;
        }

        .page-title {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--accent-color);
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
            padding: 1.5rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            border-radius: 15px 15px 0 0;
        }

        .card-header h5 {
            color: var(--text-color);
            font-size: 1.2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-header h5 i {
            color: var(--accent-color);
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-label {
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        .btn-primary {
            background: var(--accent-color);
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            color: #fff;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover {
            background: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn-primary:active {
            transform: translateY(0);
            box-shadow: var(--inner-shadow);
        }

        .alert {
            background: var(--bg-color);
            border: none;
            border-radius: 10px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow);
        }

        .alert-success {
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }

        .alert-danger {
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        hr {
            border: none;
            border-top: 1px solid rgba(0,0,0,0.05);
            margin: 1.5rem 0;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                height: auto;
                position: relative;
            }

            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .card {
                margin-bottom: 1rem;
            }

            .card-header, .card-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h2><i class="fas fa-graduation-cap"></i> Admin Panel</h2>
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
            <a href="settings.php" class="nav-link active">
                <i class="fas fa-cog"></i> Settings
            </a>
            <a href="../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </nav>
    </div>

    <div class="main-content">
        <h1 class="page-title"><i class="fas fa-cog"></i> System Settings</h1>

        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="alert alert-success">
                <?php 
                    echo $_SESSION['success_msg'];
                    unset($_SESSION['success_msg']);
                ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="alert alert-danger">
                <?php 
                    echo $_SESSION['error_msg'];
                    unset($_SESSION['error_msg']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Academic Year Settings -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-calendar"></i> Academic Year Settings</h5>
            </div>
            <div class="card-body">
                <form method="POST" class="mb-4">
                    <div class="mb-3">
                        <label class="form-label">Current Academic Year</label>
                        <select name="current_year" class="form-control">
                            <?php while ($year = mysqli_fetch_assoc($years_result)): ?>
                                <option value="<?php echo $year['id']; ?>" 
                                    <?php echo ($year['is_current'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <button type="submit" name="update_academic_year" class="btn btn-primary">
                        Update Current Year
                    </button>
                </form>

                <hr>

                <h6 class="mb-3">Add New Academic Year</h6>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Year Range (e.g., 2023-2024)</label>
                        <input type="text" name="year_range" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                    <button type="submit" name="add_academic_year" class="btn btn-primary">
                        Add Academic Year
                    </button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-lock"></i> Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="update_password" class="btn btn-primary">
                        Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 