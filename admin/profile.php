<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Common fields for all roles
        $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new Exception("Invalid email format");
        }

        // Admin update
        $query = "UPDATE admin_users SET 
            email = ?
            WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "si", 
            $email,
            $user_id
        );

        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Error updating profile: " . mysqli_stmt_error($stmt));
        }

        // Log the action
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                      VALUES (?, ?, 'profile_update', ?, ?, ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $log_details = json_encode(['email' => $email]);
        mysqli_stmt_bind_param($log_stmt, "issss", 
            $user_id,
            $role,
            $log_details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        );
        mysqli_stmt_execute($log_stmt);

        // Commit transaction
        mysqli_commit($conn);
        $success_message = "Profile updated successfully!";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// Fetch admin user data
$query = "SELECT 
    a.*,
    a.username as name,
    DATE_FORMAT(a.created_at, '%d-%m-%Y') as joined_date,
    DATE_FORMAT(a.last_login, '%d-%m-%Y %H:%i') as last_login_date,
    (SELECT COUNT(*) FROM departments) as total_departments,
    (SELECT COUNT(*) FROM faculty WHERE is_active = TRUE) as total_faculty,
    (SELECT COUNT(*) FROM students WHERE is_active = TRUE) as total_students,
    (SELECT COUNT(*) FROM subjects WHERE is_active = TRUE) as total_subjects,
    CASE WHEN a.department_id IS NULL THEN 'Super Admin' ELSE 'Department Admin' END as admin_type,
    d.name as department_name
    FROM admin_users a
    LEFT JOIN departments d ON a.department_id = d.id
    WHERE a.id = ?";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user_data = mysqli_fetch_assoc($result);

// Include header
include 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars(isset($user_data['name']) ? $user_data['name'] : $user_data['username']); ?></title>
    <style>
        .profile-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .profile-header {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
            box-shadow: var(--shadow);
        }

        .profile-name {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .profile-role {
            font-size: 1rem;
            color: #666;
            margin-bottom: 1rem;
        }

        .profile-id {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 25px;
            font-size: 0.9rem;
            color: var(--primary-color);
            box-shadow: var(--inner-shadow);
        }

        .profile-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .profile-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.2rem;
            color: var(--text-color);
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
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-control[readonly] {
            background: rgba(0, 0, 0, 0.05);
            cursor: not-allowed;
        }

        .btn-submit {
            background: var(--primary-color);
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: var(--inner-shadow);
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item {
            background: var(--bg-color);
            padding: 1rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .info-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--text-color);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .profile-container {
                margin: 1rem;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .profile-name {
                font-size: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Add styles for status badge */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-badge.active {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        
        .status-badge.inactive {
    </style>
</head>
<body>
    <div class="profile-container">
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="profile-header">
            <div class="profile-avatar">
                <i class="fas fa-user"></i>
            </div>
            <h1 class="profile-name"><?php echo htmlspecialchars(isset($user_data['name']) ? $user_data['name'] : $user_data['username']); ?></h1>
            <div class="profile-role"><?php echo htmlspecialchars($user_data['admin_type']); ?></div>
            <?php if (!empty($user_data['department_name'])): ?>
                <div class="profile-id"><?php echo htmlspecialchars($user_data['department_name']); ?> Department</div>
            <?php endif; ?>
        </div>

        <div class="profile-content">
            <form method="POST" action="">
                <div class="profile-section">
                    <h2 class="section-title">System Overview</h2>
                    <div class="info-grid">
                        <div class="info-item highlight">
                            <div class="info-label">Total Departments</div>
                            <div class="info-value">
                                <i class="fas fa-building"></i>
                                <?php echo htmlspecialchars($user_data['total_departments']); ?>
                            </div>
                        </div>
                        <div class="info-item highlight">
                            <div class="info-label">Total Faculty</div>
                            <div class="info-value">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <?php echo htmlspecialchars($user_data['total_faculty']); ?>
                            </div>
                        </div>
                        <div class="info-item highlight">
                            <div class="info-label">Total Students</div>
                            <div class="info-value">
                                <i class="fas fa-user-graduate"></i>
                                <?php echo htmlspecialchars($user_data['total_students']); ?>
                            </div>
                        </div>
                        <div class="info-item highlight">
                            <div class="info-label">Total Subjects</div>
                            <div class="info-value">
                                <i class="fas fa-book"></i>
                                <?php echo htmlspecialchars($user_data['total_subjects']); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="profile-section">
                    <h2 class="section-title">Contact Information</h2>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                    </div>
                </div>

                <div class="form-group" style="text-align: center;">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .rating-bar {
            height: 8px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .info-item.highlight {
            background: linear-gradient(145deg, var(--bg-color), #f0f5fc);
        }

        .info-item.highlight .info-value {
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: var(--primary-color);
        }

        .info-item.highlight i {
            font-size: 1.2rem;
            opacity: 0.8;
        }

        .info-item.full-width {
            grid-column: 1 / -1;
        }
    </style>
</body>
</html> 