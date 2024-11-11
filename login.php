<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            throw new Exception("Please enter both email and password.");
        }

        // First check admin users
        $query = "SELECT id, username, email, password, 'admin' as role 
                 FROM admin_users 
                 WHERE email = ? AND is_active = TRUE";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);

        if (!$user) {
            // Check students
            $query = "SELECT s.id, s.roll_number, s.email, s.password, 
                     'student' as role, s.department_id, s.batch_id,
                     s.section, s.name,
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
                     WHERE s.email = ? 
                     AND s.is_active = TRUE
                     AND b.is_active = TRUE";
             
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
        }

        if (!$user) {
            // Check faculty
            $query = "SELECT id, email, password, 'faculty' as role,
                     department_id, designation, experience, qualification
                     FROM faculty 
                     WHERE email = ? AND is_active = TRUE";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
        }

        if (!$user) {
            // Check HODs
            $query = "SELECT id, email, password, 'hod' as role,
                     department_id, username
                     FROM hods 
                     WHERE email = ? AND is_active = TRUE";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $user = mysqli_fetch_assoc($result);
        }

        if ($user && password_verify($password, $user['password'])) {
            // Update last login timestamp
            $table = $user['role'] . 's';
            if ($user['role'] === 'admin') {
                $table = 'admin_users';
            }
            
            $update_query = "UPDATE $table SET last_login = CURRENT_TIMESTAMP WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $user['id']);
            mysqli_stmt_execute($update_stmt);

            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            
            // Set role-specific session variables
            switch ($user['role']) {
                case 'student':
                    $_SESSION['roll_number'] = $user['roll_number'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['department_id'] = $user['department_id'];
                    $_SESSION['department_name'] = $user['department_name'];
                    $_SESSION['batch_id'] = $user['batch_id'];
                    $_SESSION['batch_name'] = $user['batch_name'];
                    $_SESSION['year_of_study'] = $user['current_year_of_study'];
                    $_SESSION['semester'] = $user['current_semester'];
                    $_SESSION['section'] = $user['section'];
                    break;
                case 'faculty':
                    $_SESSION['designation'] = $user['designation'];
                    $_SESSION['department_id'] = $user['department_id'];
                    break;
                case 'hod':
                    $_SESSION['department_id'] = $user['department_id'];
                    $_SESSION['username'] = $user['username'];
                    break;
            }

            // Log the successful login
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                         VALUES (?, ?, 'login', ?, ?, ?)";
            $log_details = json_encode([
                'email' => $user['email'],
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "issss", 
                $user['id'], 
                $user['role'], 
                $log_details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            mysqli_stmt_execute($log_stmt);

            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header('Location: admin/dashboard.php');
                    break;
                case 'student':
                    header('Location: dashboard.php');
                    break;
                case 'faculty':
                    header('Location: dashboard.php');
                    break;
                case 'hod':
                    header('Location: dashboard.php');
                    break;
            }
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
    <title>Login - Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #e0e5ec;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }

        .header {
            width: 100%;
            padding: 1rem 2rem;
            background: #e0e5ec;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
        }

        .logo {
            width: 80px;
            height: auto;
            margin-right: 1rem;
        }

        .college-info {
            flex-grow: 1;
        }

        .college-info h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .college-info p {
            font-size: 0.8rem;
            color: #34495e;
        }

        .portal-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 2rem 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .login-container {
            background: #e0e5ec;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
            margin-top: 1rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .input-field {
            width: 100%;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            color: #2c3e50;
            background: #e0e5ec;
            border: none;
            border-radius: 50px;
            outline: none;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .input-field::placeholder {
            color: #7f8c8d;
        }

        .submit-btn {
            width: 100%;
            padding: 0.8rem;
            font-size: 1rem;
            color: #fff;
            background: #3498db;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }

        .submit-btn:hover {
            background: #2980b9;
        }

        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
        }

        .links a {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .error {
            color: #e74c3c;
            background-color: #fadbd8;
            border: 1px solid #f1948a;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
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
    <h1 class="portal-title">Feedback Portal</h1>
    <div class="login-container">
        <h2 style="text-align: center; margin-bottom: 1.5rem; color: #2c3e50;">Login</h2>
        <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error) . "</p>"; ?>
        <form method="post">
            <div class="form-group">
                <input type="email" name="email" class="input-field" placeholder="Email" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" class="input-field" placeholder="Password" required>
            </div>
            <button type="submit" class="submit-btn">Log in</button>
        </form>
        <div class="links">
            <a href="#">Forgot Password?</a>
            <a href="register.php">Register</a>
        </div>
    </div>
</body>
</html>