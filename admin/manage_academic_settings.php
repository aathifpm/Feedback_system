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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mysqli_begin_transaction($conn);

        $academic_year_id = $_POST['academic_year_id'];
        $odd_start = $_POST['odd_semester_start_month'];
        $odd_end = $_POST['odd_semester_end_month'];
        $even_start = $_POST['even_semester_start_month'];
        $even_end = $_POST['even_semester_end_month'];
        $exit_year = $_POST['exit_survey_year'];
        $exit_semester = $_POST['exit_survey_semester'];

        // Update or insert settings
        $query = "INSERT INTO academic_settings 
                 (academic_year_id, odd_semester_start_month, odd_semester_end_month,
                  even_semester_start_month, even_semester_end_month,
                  exit_survey_year, exit_survey_semester)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                 odd_semester_start_month = VALUES(odd_semester_start_month),
                 odd_semester_end_month = VALUES(odd_semester_end_month),
                 even_semester_start_month = VALUES(even_semester_start_month),
                 even_semester_end_month = VALUES(even_semester_end_month),
                 exit_survey_year = VALUES(exit_survey_year),
                 exit_survey_semester = VALUES(exit_survey_semester)";

        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiiiiii", 
            $academic_year_id, $odd_start, $odd_end, 
            $even_start, $even_end, $exit_year, $exit_semester);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
        $success_message = "Academic settings updated successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current settings
$settings_query = "SELECT * FROM academic_settings WHERE is_active = TRUE";
$settings_result = mysqli_query($conn, $settings_query);
$current_settings = mysqli_fetch_assoc($settings_result);

// Fetch academic years
$years_query = "SELECT * FROM academic_years ORDER BY start_date DESC";
$years_result = mysqli_query($conn, $years_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Academic Settings - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Add your CSS styles here */
    </style>
</head>
<body>
    <div class="container">
        <h1>Manage Academic Settings</h1>
        
        <?php if (isset($success_message)): ?>
            <div class="alert success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="post" class="settings-form">
            <div class="form-group">
                <label>Academic Year</label>
                <select name="academic_year_id" required>
                    <?php while ($year = mysqli_fetch_assoc($years_result)): ?>
                        <option value="<?php echo $year['id']; ?>"
                            <?php echo ($current_settings && $current_settings['academic_year_id'] == $year['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($year['year_range']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group">
                <h3>Odd Semester Duration</h3>
                <label>Start Month</label>
                <select name="odd_semester_start_month" required>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['odd_semester_start_month'] == $i) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label>End Month</label>
                <select name="odd_semester_end_month" required>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['odd_semester_end_month'] == $i) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <h3>Even Semester Duration</h3>
                <label>Start Month</label>
                <select name="even_semester_start_month" required>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['even_semester_start_month'] == $i) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label>End Month</label>
                <select name="even_semester_end_month" required>
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['even_semester_end_month'] == $i) ? 'selected' : ''; ?>>
                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="form-group">
                <h3>Exit Survey Settings</h3>
                <label>Year</label>
                <select name="exit_survey_year" required>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['exit_survey_year'] == $i) ? 'selected' : ''; ?>>
                            Year <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <label>Semester</label>
                <select name="exit_survey_semester" required>
                    <?php for ($i = 1; $i <= 8; $i++): ?>
                        <option value="<?php echo $i; ?>"
                            <?php echo ($current_settings && $current_settings['exit_survey_semester'] == $i) ? 'selected' : ''; ?>>
                            Semester <?php echo $i; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</body>
</html>