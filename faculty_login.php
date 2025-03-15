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
        $remember_me = isset($_POST['remember_me']) ? true : false;

        if (empty($email) || empty($password)) {
            throw new Exception("Please enter both email and password.");
        }

        // Rate limiting check
        if (checkLoginAttempts($_SERVER['REMOTE_ADDR']) > 5) {
            throw new Exception("Too many login attempts. Please try again after 15 minutes.");
        }

        // Check faculty credentials with prepared statement
        $query = "SELECT id, name, email, password, department_id, is_active, 
                        designation, experience, qualification, specialization
                 FROM faculty 
                 WHERE email = ? AND is_active = TRUE";
                 
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $faculty = mysqli_fetch_assoc($result);

        if ($faculty && password_verify($password, $faculty['password'])) {
            loginSuccess($faculty);
        } else {
            recordFailedAttempt($_SERVER['REMOTE_ADDR']);
            throw new Exception("Invalid email or password.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Helper functions
function checkLoginAttempts($ip) {
    // Implementation for rate limiting
    return 0; // Placeholder
}

function generateRememberMeToken() {
    return bin2hex(random_bytes(32));
}

function loginSuccess($faculty) {
    global $conn;
    
    // Update last login timestamp
    $update_query = "UPDATE faculty SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_query);
    mysqli_stmt_bind_param($update_stmt, "i", $faculty['id']);
    mysqli_stmt_execute($update_stmt);

    // Set session variables
    $_SESSION['user_id'] = $faculty['id'];
    $_SESSION['role'] = 'faculty';
    $_SESSION['email'] = $faculty['email'];
    $_SESSION['name'] = $faculty['name'];
    $_SESSION['department_id'] = $faculty['department_id'];
    $_SESSION['designation'] = $faculty['designation'];

    // Log the successful login
    logUserActivity($faculty['id'], 'faculty', 'login', 'success');

    header('Location: dashboard.php');
    exit();
}

function logUserActivity($user_id, $role, $action, $status) {
    global $conn;
    
    $details = json_encode([
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);

    $query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "issssss", 
        $user_id,
        $role,
        $action,
        $details,
        $status,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    );
    
    mysqli_stmt_execute($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Login - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
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
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .error-message {
            background: #fee;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--inner-shadow);
        }

        .links {
            margin-top: 1.5rem;
            text-align: center;
            padding: 1rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .link-item {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 20px;
        }

        .link-item:hover {
            background: rgba(52, 152, 219, 0.1);
            color: #2980b9;
        }

        .link-item i {
            margin-right: 5px;
        }

        .divider {
            margin: 0 0.5rem;
            color: #999;
        }

        @media (max-width: 480px) {
            .links {
                display: flex;
                justify-content: center;
                gap: 10px;
            }
            
            .link-item {
                font-size: 0.9rem;
            }
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-color);
        }

        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .remember-me input {
            margin-right: 0.5rem;
        }

        .loading {
            display: none;
            margin-left: 8px;
        }

        /* Add animation for login button */
        .btn-login:active {
            transform: scale(0.98);
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
        <h2 class="login-title">Faculty Login</h2>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" id="loginForm">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" class="input-field" 
                       required autocomplete="email">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" 
                           class="input-field" required>
                    <i class="fas fa-eye toggle-password" 
                       onclick="togglePassword()"></i>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Login
                <span class="loading">
                    <i class="fas fa-spinner fa-spin"></i>
                </span>
            </button>

            <div class="links">
                <a href="forgot_password.php" class="link-item">
                    <i class="fas fa-key"></i> Forgot Password
                </a>
                <span class="divider">|</span>
                <a href="index.php" class="link-item">
                    <i class="fas fa-home"></i> Home
                </a>
            </div>
        </form>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            const password = document.getElementById('password').value;

            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
                return;
            }

            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return;
            }

            // Show loading spinner
            document.querySelector('.loading').style.display = 'inline-block';
            document.querySelector('.btn-login').disabled = true;
        });
    </script>
</body>
</html>