<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            throw new Exception("Please enter both email and password.");
        }

        // Check admin credentials
        $query = "SELECT * FROM admin_users 
                 WHERE email = ? AND is_active = TRUE";
                 
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $admin = mysqli_fetch_assoc($result);

        if ($admin && password_verify($password, $admin['password'])) {
            // Update last login timestamp
            $update_query = "UPDATE admin_users SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $admin['id']);
            mysqli_stmt_execute($update_stmt);

            // Set session variables
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['role'] = 'admin';
            $_SESSION['email'] = $admin['email'];
            $_SESSION['username'] = $admin['username'];

            // Log the successful login
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                         VALUES (?, 'admin', 'login', ?, ?, ?)";
            $log_details = json_encode([
                'email' => $admin['email'],
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'success',
                'login_type' => 'admin_portal'
            ]);
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "isss", 
                $admin['id'], 
                $log_details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            mysqli_stmt_execute($log_stmt);

            header('Location: admin/dashboard.php');
            exit();
        } else {
            // Log failed login attempt in user_logs instead of login_attempts
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                         VALUES (NULL, 'admin', 'login_failed', ?, ?, ?)";
            $log_details = json_encode([
                'email' => $email,
                'timestamp' => date('Y-m-d H:i:s'),
                'status' => 'failed',
                'reason' => 'Invalid credentials'
            ]);
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "sss", 
                $log_details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            mysqli_stmt_execute($log_stmt);
            
            throw new Exception("Invalid email or password.");
        }
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
    <title>Admin Login - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
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

        .login-container {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 400px;
            margin: 2rem auto;
            position: relative;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

        .login-title {
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

        .btn-login {
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

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
            background: #c0392b;
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
            color: #c0392b;
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

        .security-notice {
            font-size: 0.8rem;
            color: #666;
            text-align: center;
            margin-top: 1rem;
            padding: 0.5rem;
            background: rgba(231, 76, 60, 0.1);
            border-radius: 10px;
        }

        @media (max-width: 480px) {
            .login-container {
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

    <div class="login-container">
        <div class="role-icon">
            <i class="fas fa-user-shield"></i>
        </div>
        <h2 class="login-title">Administrator Login</h2>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" id="adminLoginForm">
            <div class="form-group">
                <label for="email">Email</label>
                <i class="fas fa-envelope"></i>
                <input type="email" id="email" name="email" class="input-field" 
                       placeholder="Enter admin email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <i class="fas fa-lock"></i>
                <input type="password" id="password" name="password" class="input-field" 
                       placeholder="Enter admin password" required>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i>
                Login as Administrator
            </button>

            <div class="links">
                <a href="forgot_password.php">
                    <i class="fas fa-key"></i> Reset Password
                </a>
                <span class="divider">|</span>
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </form>

        <div class="security-notice">
            <i class="fas fa-shield-alt"></i>
            This is a secure administrative portal. Unauthorized access attempts will be logged.
        </div>
    </div>

    <script>
        // Enhanced form validation
        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return;
            }

            // Email validation
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return;
            }

            // Password minimum length
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long');
                return;
            }
        });

        // Password visibility toggle
        let passwordVisible = false;
        document.querySelector('.fa-lock').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            passwordVisible = !passwordVisible;
            passwordInput.type = passwordVisible ? 'text' : 'password';
            this.className = passwordVisible ? 'fas fa-eye' : 'fas fa-lock';
        });
    </script>
</body>
</html>