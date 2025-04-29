<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: index.php');
    exit();
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate password match
    if ($new_password !== $confirm_password) {
        $error = "New passwords do not match!";
    } else {
        // Get the correct table name based on user role
        $table = '';
        switch ($_SESSION['role']) {
            case 'admin':
                $table = 'admin_users';
                break;
            case 'faculty':
                $table = 'faculty';
                break;
            case 'hod':
                $table = 'hods';
                break;
            case 'student':
                $table = 'students';
                break;
            default:
                $error = "Invalid user role!";
                break;
        }

        if (!empty($table)) {
            // Verify current password
            $query = "SELECT password FROM $table WHERE id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "i", $_SESSION['user_id']);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);

            if ($user && password_verify($current_password, $user['password'])) {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_query = "UPDATE $table SET password = ?, password_changed_at = CURRENT_TIMESTAMP WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "si", $hashed_password, $_SESSION['user_id']);
                
                if (mysqli_stmt_execute($stmt)) {
                    $success = "Password changed successfully!";
                    
                    // Log the password change
                    $log_query = "INSERT INTO user_logs (user_id, role, action, status) VALUES (?, ?, 'Changed password', 'success')";
                    $log_stmt = mysqli_prepare($conn, $log_query);
                    mysqli_stmt_bind_param($log_stmt, "is", $_SESSION['user_id'], $_SESSION['role']);
                    mysqli_stmt_execute($log_stmt);
                } else {
                    $error = "Error updating password. Please try again.";
                }
            } else {
                $error = "Current password is incorrect!";
            }
        }
    }
}

// Include the header
include 'includes/header.php';
?>

<style>
    :root {
        --primary-color: #3498db;
        --text-color: #2c3e50;
        --bg-color: #e0e5ec;
        --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                 -9px -9px 16px rgba(255,255,255, 0.5);
        --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
    }

    .main-content {
        min-height: calc(100vh - var(--header-height));
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 2rem;
    }

    .change-password-container {
        background: var(--bg-color);
        padding: 2rem;
        border-radius: 15px;
        box-shadow: var(--shadow);
        width: 100%;
        max-width: 450px;
    }

    .header {
        text-align: center;
        margin-bottom: 2rem;
    }

    .header h1 {
        color: var(--text-color);
        font-size: 1.8rem;
        margin-bottom: 0.5rem;
    }

    .header p {
        color: #666;
        font-size: 0.9rem;
    }

    .form-group {
        margin-bottom: 1.5rem;
    }

    label {
        display: block;
        margin-bottom: 0.5rem;
        color: var(--text-color);
        font-size: 0.9rem;
    }

    .input-group {
        position: relative;
    }

    .form-control {
        width: 100%;
        padding: 0.8rem 1rem;
        border: none;
        border-radius: 10px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        font-size: 0.9rem;
        color: var(--text-color);
        transition: all 0.3s ease;
    }

    .form-control:focus {
        outline: none;
        box-shadow: var(--shadow);
    }

    .toggle-password {
        position: absolute;
        right: 1rem;
        top: 50%;
        transform: translateY(-50%);
        cursor: pointer;
        color: #666;
        opacity: 0.7;
        transition: all 0.3s ease;
    }

    .toggle-password:hover {
        opacity: 1;
        color: var(--primary-color);
    }

    .btn {
        width: 100%;
        padding: 0.8rem;
        border: none;
        border-radius: 10px;
        background: var(--bg-color);
        box-shadow: var(--shadow);
        color: var(--text-color);
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn:hover {
        transform: translateY(-2px);
        color: var(--primary-color);
    }

    .btn:active {
        transform: translateY(0);
        box-shadow: var(--inner-shadow);
    }

    .alert {
        padding: 0.8rem 1rem;
        border-radius: 10px;
        margin-bottom: 1.5rem;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.9rem;
    }

    .alert-success {
        color: #2ecc71;
        border-left: 3px solid #2ecc71;
    }

    .alert-danger {
        color: #e74c3c;
        border-left: 3px solid #e74c3c;
    }

    .password-requirements {
        margin-top: 0.5rem;
        font-size: 0.8rem;
        color: #666;
    }

    .password-requirements ul {
        list-style: none;
        margin: 0.3rem 0;
        padding-left: 0.5rem;
    }

    .password-requirements li {
        margin: 0.2rem 0;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    .password-requirements li i {
        font-size: 0.7rem;
        color: #666;
    }

    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }
    }
</style>

<div class="main-content">
    <?php include 'includes/header.php'; ?>
    <div class="change-password-container">
       

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <div class="input-group">
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('current_password')"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                <div class="input-group">
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('new_password')"></i>
                </div>
                <div class="password-requirements">
                    <ul>
                        <li><i class="fas fa-circle"></i> At least 8 characters</li>
                        <li><i class="fas fa-circle"></i> Uppercase & lowercase letters</li>
                        <li><i class="fas fa-circle"></i> Numbers and special characters</li>
                    </ul>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>
                <div class="input-group">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password')"></i>
                </div>
            </div>

            <button type="submit" class="btn">
                <i class="fas fa-key"></i> Update Password
            </button>
        </form>
    </div>
</div>
<?php include 'includes/footer.php'; ?>

<script>
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const icon = input.nextElementSibling;
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
</script>