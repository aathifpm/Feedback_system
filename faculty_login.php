<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        error_log("Before: " . $_POST['email']);
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        error_log("After: " . $email);
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
                 
        $stmt = $pdo->prepare($query);
        $stmt->execute([$email]);
        $faculty = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = null; // Close the statement

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
    global $pdo;
    
    // Check failed attempts in the last 15 minutes
    $query = "SELECT COUNT(*) as attempts 
              FROM user_logs 
              WHERE ip_address = ? 
              AND action = 'login' 
              AND status = 'failure' 
              AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null; // Close the statement
    
    return $row['attempts'];
}

function recordFailedAttempt($ip) {
    global $pdo;
    
    // Log the failed attempt
    $details = json_encode([
        'ip_address' => $ip,
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);
    
    $query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address, user_agent) 
              VALUES (0, 'faculty', 'login', ?, 'failure', ?, ?)";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $details,
        $ip,
        $_SERVER['HTTP_USER_AGENT']
    ]);
    $stmt = null; // Close the statement
}

function generateRememberMeToken() {
    return bin2hex(random_bytes(32));
}

function loginSuccess($faculty) {
    global $pdo;
    
    // Update last login timestamp
    $update_query = "UPDATE faculty SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
    $update_stmt = $pdo->prepare($update_query);
    $update_stmt->execute([$faculty['id']]);
    $update_stmt = null; // Close the statement

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
    global $pdo;
    
    $details = json_encode([
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
    ]);

    $query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
              
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $user_id,
        $role,
        $action,
        $details,
        $status,
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    $stmt = null; // Close the statement
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Login - Panimalar Engineering College</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --text-color: #2c3e50;
            --text-light: #7f8c8d;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --transition: all 0.3s ease;
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
            padding: 2rem 1rem;
        }

        .header {
            width: 100%;
            padding: 1.5rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
            border-radius: 20px;
            max-width: 800px;
            transition: var(--transition);
        }

        .header:hover {
            transform: translateY(-5px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 1rem;
            filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
        }

        .college-info h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .college-info p {
            color: var(--text-light);
            line-height: 1.4;
        }

        .login-container {
            background: var(--bg-color);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 450px;
            margin: 2rem auto;
            transition: var(--transition);
        }

        .login-container:hover {
            transform: translateY(-5px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .login-title {
            text-align: center;
            color: var(--text-color);
            margin-bottom: 2rem;
            font-size: 1.8rem;
            font-weight: 600;
            position: relative;
            padding-bottom: 0.5rem;
        }

        .login-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background: var(--primary-color);
            border-radius: 3px;
        }

        .form-group {
            margin-bottom: 1.8rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.7rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .input-field {
            width: 100%;
            padding: 1rem 1.2rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: var(--transition);
        }

        .input-field:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .input-field::placeholder {
            color: var(--text-light);
            opacity: 0.7;
        }

        .btn-login {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: 1.1rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-login:hover {
            transform: translateY(-3px);
            background: var(--primary-dark);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .btn-login:active {
            transform: scale(0.98);
        }

        .error-message {
            background: rgba(231, 76, 60, 0.1);
            color: var(--error-color);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .links {
            margin-top: 2rem;
            text-align: center;
            padding: 1.2rem 0;
            border-top: 1px solid rgba(0,0,0,0.1);
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .link-item {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.95rem;
            transition: var(--transition);
            padding: 0.6rem 1.2rem;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .link-item:hover {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-dark);
            transform: translateY(-2px);
        }

        .link-item i {
            font-size: 1rem;
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
            color: var(--text-light);
            transition: var(--transition);
        }

        .toggle-password:hover {
            color: var(--primary-color);
        }

        .remember-me {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .remember-me input {
            margin-right: 0.5rem;
            accent-color: var(--primary-color);
        }

        .loading {
            display: none;
            margin-left: 8px;
        }

        .success-message {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success-color);
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: fadeIn 0.5s ease;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }

        .input-with-icon {
            padding-left: 3rem;
        }

        .theme-toggle {
            position: absolute;
            top: 20px;
            right: 20px;
            background: var(--bg-color);
            border: none;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: var(--transition);
            color: var(--text-color);
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        .footer {
            margin-top: auto;
            text-align: center;
            padding: 1.5rem;
            color: var(--text-light);
            font-size: 0.9rem;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
            }
            
            .links {
                flex-direction: column;
                gap: 10px;
            }
            
            .link-item {
                justify-content: center;
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
        <h2 class="login-title">Faculty Login</h2>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="" id="loginForm">
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="password-wrapper">
                    <i class="fas fa-envelope input-icon"></i>
                    <input type="email" id="email" name="email" class="input-field input-with-icon" 
                           placeholder="Enter your email" required autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="password" name="password" 
                           class="input-field input-with-icon" placeholder="Enter your password" required>
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
                <a href="forget_password.php" class="link-item">
                    <i class="fas fa-key"></i> Forgot Password
                </a>
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

    <?php include 'footer.php'; ?>

</body>
</html>