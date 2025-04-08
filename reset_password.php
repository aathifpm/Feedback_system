<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';
$token = '';
$user_id = null;
$user_type = null;
$token_valid = false;

// Check if token is provided in the URL
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];
    
    // Verify token exists and is valid
    $query = "SELECT prt.user_id, prt.user_type, prt.expires_at 
              FROM password_reset_tokens prt 
              WHERE prt.token = ? 
              AND prt.is_used = FALSE
              AND prt.expires_at > NOW()";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($token_data = mysqli_fetch_assoc($result)) {
        $user_id = $token_data['user_id'];
        $user_type = $token_data['user_type'];
        $token_valid = true;
    } else {
        $error = "Invalid or expired reset link. Please request a new password reset.";
    }
} else {
    $error = "No reset token provided. Please request a password reset from the forgot password page.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $token_valid) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    try {
        // Validate passwords
        if (empty($password) || empty($confirm_password)) {
            throw new Exception("Please enter both password fields.");
        }
        
        if ($password !== $confirm_password) {
            throw new Exception("Passwords do not match.");
        }
        
        if (!is_password_complex($password)) {
            throw new Exception("Password does not meet complexity requirements.");
        }
        
        // Get the correct user table
        $table = '';
        switch ($user_type) {
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
                throw new Exception("Invalid user type.");
        }
        
        // Hash the new password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Update the user's password
        $update_query = "UPDATE $table 
                        SET password = ?, password_changed_at = CURRENT_TIMESTAMP 
                        WHERE id = ?";
        
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user_id);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception("Failed to update password. Please try again.");
        }
        
        // Mark the token as used
        $token_query = "UPDATE password_reset_tokens 
                       SET is_used = TRUE 
                       WHERE token = ?";
        
        $token_stmt = mysqli_prepare($conn, $token_query);
        mysqli_stmt_bind_param($token_stmt, "s", $token);
        mysqli_stmt_execute($token_stmt);
        
        // Log the password reset
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                     VALUES (?, ?, 'password_reset', ?, ?, ?)";
        
        $log_details = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => 'token_reset'
        ]);
        
        $log_stmt = mysqli_prepare($conn, $log_query);
        mysqli_stmt_bind_param($log_stmt, "issss", 
            $user_id, 
            $user_type,
            $log_details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        );
        mysqli_stmt_execute($log_stmt);
        
        $success = "Password has been reset successfully. You can now login with your new password.";
        $token_valid = false; // Prevent form resubmission
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Panimalar Engineering College</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #2ecc71;  /* Green theme for password reset */
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
            flex-direction: column;
            align-items: center;
        }

        .header {
            width: 100%;
            padding: 1.5rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 1rem;
        }

        .college-info h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .college-info p {
            color: #666;
            line-height: 1.4;
        }

        .reset-container {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 450px;
            margin: 2rem auto;
            position: relative;
            overflow: hidden;
        }

        .reset-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

        .reset-title {
            text-align: center;
            color: var(--text-color);
            margin-bottom: 2rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .input-field {
            width: 100%;
            padding: 0.8rem 1rem;
            padding-left: 2.5rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .input-field:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-group i {
            position: absolute;
            left: 1rem;
            top: 2.3rem;
            color: var(--primary-color);
        }

        .btn-reset {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
            background: #27ae60;
        }

        .error-message {
            background: #fee;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .success-message {
            background: #efc;
            color: #2ecc71;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .links {
            margin-top: 1.5rem;
            text-align: center;
        }

        .links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .links a:hover {
            color: #27ae60;
        }

        .divider {
            margin: 0 1rem;
            color: #666;
        }

        .role-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            text-align: center;
        }

        .info-text {
            text-align: center;
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        .password-requirements {
            background: rgba(46, 204, 113, 0.1);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }

        .password-requirements h3 {
            font-size: 1rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .password-requirements ul {
            margin-left: 1.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .password-requirements li {
            margin-bottom: 0.2rem;
        }

        .password-strength {
            height: 5px;
            width: 100%;
            background: #ddd;
            margin-top: 0.5rem;
            border-radius: 5px;
        }

        .password-strength-meter {
            height: 100%;
            width: 0;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .show-password {
            position: absolute;
            right: 1rem;
            top: 2.3rem;
            color: var(--text-color);
            cursor: pointer;
        }

        @media (max-width: 480px) {
            .reset-container {
                width: 95%;
                padding: 1.5rem;
            }

            .college-info h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
            <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123.</p>
        </div>
    </div>

    <div class="reset-container">
        <div class="role-icon">
            <i class="fas fa-key"></i>
        </div>
        <h2 class="reset-title">Reset Your Password</h2>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            
            <div class="links">
                <a href="forget_password.php">
                    <i class="fas fa-redo"></i> Request a new reset link
                </a>
                <span class="divider">|</span>
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        <?php elseif ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
            
            <div class="links">
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Home
                </a>
                <span class="divider">|</span>
                <a href="student_login.php">
                    <i class="fas fa-sign-in-alt"></i> Login as Student
                </a>
                <span class="divider">|</span>
                <a href="faculty_login.php">
                    <i class="fas fa-sign-in-alt"></i> Login as Faculty
                </a>
            </div>
        <?php elseif ($token_valid): ?>
            <p class="info-text">Enter your new password below</p>
            
            <div class="password-requirements">
                <h3>Password Requirements:</h3>
                <ul>
                    <li>At least 8 characters long</li>
                    <li>At least one uppercase letter (A-Z)</li>
                    <li>At least one lowercase letter (a-z)</li>
                    <li>At least one number (0-9)</li>
                    <li>At least one special character (!@#$%^&*()-_=+)</li>
                </ul>
            </div>

            <form method="post" action="" id="resetPasswordForm">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="form-group">
                    <label for="password">New Password</label>
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" class="input-field" 
                           placeholder="Enter new password" required>
                    <i class="fas fa-eye show-password" id="togglePassword"></i>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           class="input-field" placeholder="Confirm new password" required>
                    <i class="fas fa-eye show-password" id="toggleConfirmPassword"></i>
                </div>
                
                <div class="form-group">
                    <label for="password_strength">Password Strength</label>
                    <div class="password-strength">
                        <div class="password-strength-meter" id="strengthMeter"></div>
                    </div>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-check"></i>
                    Reset Password
                </button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
        
        document.getElementById('toggleConfirmPassword')?.addEventListener('click', function() {
            const confirmPasswordInput = document.getElementById('confirm_password');
            const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            confirmPasswordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
        
        // Password strength meter
        const passwordInput = document.getElementById('password');
        const strengthMeter = document.getElementById('strengthMeter');
        
        passwordInput?.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            let color = '#ddd';
            
            if (password.length >= 8) strength += 20;
            if (password.match(/[A-Z]/)) strength += 20;
            if (password.match(/[a-z]/)) strength += 20;
            if (password.match(/[0-9]/)) strength += 20;
            if (password.match(/[^A-Za-z0-9]/)) strength += 20;
            
            if (strength <= 20) {
                color = '#ff4d4d'; // Very weak (red)
            } else if (strength <= 40) {
                color = '#ffa64d'; // Weak (orange)
            } else if (strength <= 60) {
                color = '#ffff4d'; // Medium (yellow)
            } else if (strength <= 80) {
                color = '#4dff4d'; // Strong (light green)
            } else {
                color = '#2ecc71'; // Very strong (green)
            }
            
            strengthMeter.style.width = strength + '%';
            strengthMeter.style.backgroundColor = color;
        });
        
        // Form validation
        document.getElementById('resetPasswordForm')?.addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            // Check password complexity
            if (password.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
            
            if (!password.match(/[A-Z]/)) {
                e.preventDefault();
                alert('Password must contain at least one uppercase letter!');
                return false;
            }
            
            if (!password.match(/[a-z]/)) {
                e.preventDefault();
                alert('Password must contain at least one lowercase letter!');
                return false;
            }
            
            if (!password.match(/[0-9]/)) {
                e.preventDefault();
                alert('Password must contain at least one number!');
                return false;
            }
            
            if (!password.match(/[^A-Za-z0-9]/)) {
                e.preventDefault();
                alert('Password must contain at least one special character!');
                return false;
            }
        });
    </script>

    <?php include 'footer.php'; ?>
</body>
</html> 