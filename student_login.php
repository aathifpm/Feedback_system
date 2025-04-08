<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $identifier = trim($_POST['identifier']);
        $password = $_POST['password'];

        if (empty($identifier) || empty($password)) {
            throw new Exception("Please enter both identifier and password.");
        }

        // Modified query to check both email and roll_number
        $query = "SELECT s.id, s.name, s.email, s.password, s.department_id, 
                        s.batch_id, s.section, s.roll_number,
                        b.current_year_of_study,
                        CASE 
                            WHEN MONTH(CURDATE()) <= 5 THEN b.current_year_of_study * 2
                            ELSE b.current_year_of_study * 2 - 1
                        END as current_semester,
                        d.name as department_name,
                        b.batch_name
                 FROM students s
                 JOIN batch_years b ON s.batch_id = b.id
                 JOIN departments d ON s.department_id = d.id
                 WHERE (s.email = ? OR s.roll_number = ?) AND s.is_active = TRUE";
                 
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ss", $identifier, $identifier);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $student = mysqli_fetch_assoc($result);

        if ($student && password_verify($password, $student['password'])) {
            // Update last login timestamp
            $update_query = "UPDATE students SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $student['id']);
            mysqli_stmt_execute($update_stmt);

            // Set session variables
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['role'] = 'student';
            $_SESSION['email'] = $student['email'];
            $_SESSION['name'] = $student['name'];
            $_SESSION['department_id'] = $student['department_id'];
            $_SESSION['department_name'] = $student['department_name'];
            $_SESSION['batch_id'] = $student['batch_id'];
            $_SESSION['batch_name'] = $student['batch_name'];
            $_SESSION['year_of_study'] = $student['current_year_of_study'];
            $_SESSION['semester'] = $student['current_semester'];
            $_SESSION['section'] = $student['section'];
            $_SESSION['roll_number'] = $student['roll_number'];

            // Log the successful login
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                         VALUES (?, 'student', 'login', ?, ?, ?)";
            $log_details = json_encode([
                'email' => $student['email'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "isss", 
                $student['id'], 
                $log_details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            mysqli_stmt_execute($log_stmt);

            header('Location: dashboard.php');
            exit();
        } else {
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
    <title>Student Login - Panimalar Engineering College</title>
    <link rel="icon" href="college_logo.png" type="image/png">
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
        }

        .links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .links a:hover {
            color: #2980b9;
        }

        .divider {
            margin: 0 1rem;
            color: #666;
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

        .input-with-icon {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            color: var(--primary-color);
        }

        .input-with-icon .toggle-password {
            left: auto;
            right: 15px;
            cursor: pointer;
        }

        .input-with-icon .input-field {
            padding-left: 45px;
        }

        .btn-login {
            background: linear-gradient(145deg, var(--primary-color), #2980b9);
        }

        .links a i {
            margin-right: 5px;
        }

        @media (max-width: 480px) {
            .login-container {
                margin: 1rem auto;
            }

            .links {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            .links .divider {
                display: none;
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
        <h2 class="login-title">Student Login</h2>
        
        <?php if ($error): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-group">
                <label for="identifier">Email or Roll Number</label>
                <div class="input-with-icon">
                    <i class="fas fa-user"></i>
                    <input type="text" id="identifier" name="identifier" 
                           class="input-field" 
                           placeholder="Enter email or roll number"
                           value="<?php echo isset($_POST['identifier']) ? htmlspecialchars($_POST['identifier']) : ''; ?>"
                           required>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-with-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" 
                           class="input-field" 
                           placeholder="Enter your password" required>
                    <i class="fas fa-eye toggle-password"></i>
                </div>
            </div>

            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>

            <div class="links">
                <a href="forget_password.php">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
                <span class="divider">|</span>
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        </form>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Toggle password visibility
        const togglePassword = document.querySelector('.toggle-password');
        const passwordInput = document.querySelector('#password');

        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        });
    });
    </script>
</body>
</html>