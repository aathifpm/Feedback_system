<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
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
    
    // Handle adding new batch
    if (isset($_POST['add_batch'])) {
        try {
            $batch_name = mysqli_real_escape_string($conn, $_POST['batch_name']);
            $admission_year = intval($_POST['admission_year']);
            $graduation_year = intval($_POST['graduation_year']);
            $current_year = intval($_POST['current_year_of_study']);
            
            if ($current_year < 1 || $current_year > 4) {
                throw new Exception("Current year must be between 1 and 4");
            }
            
            mysqli_begin_transaction($conn);
            
            // Check if batch name already exists
            $check_query = "SELECT id FROM batch_years WHERE batch_name = ?";
            $stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($stmt, "s", $batch_name);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($result) > 0) {
                throw new Exception("Batch with this name already exists!");
            }
            
            // Insert the new batch
            $insert_query = "INSERT INTO batch_years (batch_name, admission_year, graduation_year, current_year_of_study, is_active) 
                            VALUES (?, ?, ?, ?, TRUE)";
            $stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($stmt, "siii", $batch_name, $admission_year, $graduation_year, $current_year);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error adding batch: " . mysqli_error($conn));
            }
            
            mysqli_commit($conn);
            $_SESSION['success_msg'] = "New batch added successfully!";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error_msg'] = $e->getMessage();
        }
        
        header('Location: settings.php');
        exit();
    }
    
    // Handle batch status toggle
    if (isset($_POST['toggle_batch'])) {
        try {
            $batch_id = intval($_POST['batch_id']);
            $new_status = intval($_POST['is_active']);
            
            $update_query = "UPDATE batch_years SET is_active = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($stmt, "ii", $new_status, $batch_id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error updating batch status: " . mysqli_error($conn));
            }
            
            $_SESSION['success_msg'] = "Batch status updated successfully!";
            
        } catch (Exception $e) {
            $_SESSION['error_msg'] = $e->getMessage();
        }
        
        header('Location: settings.php');
        exit();
    }
    
    if (isset($_POST['complete_academic_year'])) {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        try {
            // Get current academic year
            $curr_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
            $curr_year_result = mysqli_query($conn, $curr_year_query);
            $curr_year = mysqli_fetch_assoc($curr_year_result);
            
            if (!$curr_year) {
                throw new Exception("No current academic year found!");
            }
            
            // Get the next academic year (should already be added)
            $next_year_id = intval($_POST['next_academic_year']);
            $next_year_query = "SELECT * FROM academic_years WHERE id = ?";
            $stmt = mysqli_prepare($conn, $next_year_query);
            mysqli_stmt_bind_param($stmt, "i", $next_year_id);
            mysqli_stmt_execute($stmt);
            $next_year_result = mysqli_stmt_get_result($stmt);
            $next_year = mysqli_fetch_assoc($next_year_result);
            
            if (!$next_year) {
                throw new Exception("Next academic year not found!");
            }
            // Get batches that were already in 4th year before incrementing (these are the graduating batches)
            $graduate_batches_query = "UPDATE batch_years SET is_active = FALSE WHERE current_year_of_study >= 4";
            mysqli_query($conn, $graduate_batches_query);
            // Update all batches to increment their current year of study
            $update_batches_query = "UPDATE batch_years SET current_year_of_study = current_year_of_study + 1 WHERE is_active = TRUE AND current_year_of_study < 4";
            mysqli_query($conn, $update_batches_query);
            
            
            // Set next year as current
            $reset_query = "UPDATE academic_years SET is_current = FALSE";
            mysqli_query($conn, $reset_query);
            
            $set_current_query = "UPDATE academic_years SET is_current = TRUE WHERE id = ?";
            $stmt = mysqli_prepare($conn, $set_current_query);
            mysqli_stmt_bind_param($stmt, "i", $next_year_id);
            mysqli_stmt_execute($stmt);
            
            // Create a new feedback period for the new academic year if needed
            $check_period_query = "SELECT * FROM feedback_periods WHERE academic_year_id = ?";
            $stmt = mysqli_prepare($conn, $check_period_query);
            mysqli_stmt_bind_param($stmt, "i", $next_year_id);
            mysqli_stmt_execute($stmt);
            $period_result = mysqli_stmt_get_result($stmt);
            
            if (mysqli_num_rows($period_result) == 0) {
                // Calculate default period dates (first month of semester)
                $start_date = date('Y-m-d', strtotime($next_year['start_date']));
                $end_date = date('Y-m-d', strtotime($start_date . ' + 30 days'));
                
                $insert_period_query = "INSERT INTO feedback_periods (academic_year_id, start_date, end_date, is_active) 
                                        VALUES (?, ?, ?, TRUE)";
                $stmt = mysqli_prepare($conn, $insert_period_query);
                mysqli_stmt_bind_param($stmt, "iss", $next_year_id, $start_date, $end_date);
                mysqli_stmt_execute($stmt);
            }
            
            // Commit transaction
            mysqli_commit($conn);
            $_SESSION['success_msg'] = "Academic year completion process successful! Batches have been updated.";
        } 
        catch (Exception $e) {
            // Rollback on error
            mysqli_rollback($conn);
            $_SESSION['error_msg'] = "Error: " . $e->getMessage();
        }
        
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

// Get non-current academic years for transition selector
$next_years_query = "SELECT * FROM academic_years WHERE is_current = FALSE ORDER BY start_date DESC";
$next_years_result = mysqli_query($conn, $next_years_query);

// Fetch batches
$batches_query = "SELECT * FROM batch_years ORDER BY admission_year DESC, batch_name";
$batches_result = mysqli_query($conn, $batches_query);
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
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #9b59b6;  /* Purple theme */
            --secondary-color: #8e44ad;
            --accent-color: #9b59b6;
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
            margin-left: 280px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
            }
        }

        .page-title {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .card {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border: none;
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
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
            color: var(--primary-color);
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
            display: block;
        }

        .btn-primary {
            background: var(--primary-color);
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
            background: var(--secondary-color);
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
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

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
                
                <hr>
                
                <h6 class="mb-3">Complete Academic Year(promote the students to the next year)</h6>
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This process will:
                    <ul>
                        <li>Increment the year of study for all active batches</li>
                        <li>Mark graduating batches (4th year) as inactive</li>
                        <li>Change current academic year to the selected one</li>
                        <li>Create a new feedback period for the next academic year</li>
                    </ul>
                    Make sure you have added the next academic year before proceeding.
                </div>
                
                <form method="POST" onsubmit="return confirm('Are you sure you want to complete the current academic year? This action will update all student batches.');">
                    <div class="mb-3">
                        <label class="form-label">Current Academic Year</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_year['year_range']); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Next Academic Year</label>
                        <select name="next_academic_year" class="form-control" required>
                            <option value="">-- Select Next Academic Year --</option>
                            <?php while ($year = mysqli_fetch_assoc($next_years_result)): ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <button type="submit" name="complete_academic_year" class="btn btn-primary">
                        Complete Academic Year
                    </button>
                </form>
            </div>
        </div>

        <!-- Batch Management -->
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-users"></i> Batch Management</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive mb-4">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Batch Name</th>
                                <th>Admission Year</th>
                                <th>Graduation Year</th>
                                <th>Current Year of Study</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            mysqli_data_seek($batches_result, 0);
                            while ($batch = mysqli_fetch_assoc($batches_result)): 
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                    <td><?php echo $batch['admission_year']; ?></td>
                                    <td><?php echo $batch['graduation_year']; ?></td>
                                    <td><?php echo $batch['current_year_of_study']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $batch['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $batch['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $batch['is_active'] ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_batch" class="btn btn-sm btn-<?php echo $batch['is_active'] ? 'warning' : 'success'; ?>">
                                                <?php echo $batch['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <hr>

                <h6 class="mb-3">Add New Batch</h6>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Batch Name (e.g., 2024-28)</label>
                        <input type="text" name="batch_name" class="form-control" required pattern="\d{4}-\d{2}">
                        <small class="text-muted">Format: YYYY-YY (admission year - graduation year last two digits)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Admission Year</label>
                        <input type="number" name="admission_year" class="form-control" required min="2000" max="2100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Graduation Year</label>
                        <input type="number" name="graduation_year" class="form-control" required min="2000" max="2100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Current Year of Study</label>
                        <select name="current_year_of_study" class="form-control" required>
                            <option value="">Select Current Year</option>
                            <option value="1">1st Year</option>
                            <option value="2">2nd Year</option>
                            <option value="3">3rd Year</option>
                            <option value="4">4th Year</option>
                        </select>
                    </div>
                    <button type="submit" name="add_batch" class="btn btn-primary">
                        Add Batch
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