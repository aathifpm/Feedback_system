<?php
include 'functions.php';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $email = $_POST['email'];
    
    // Create new admin user
    if (is_password_complex($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Invalid email format";
        } else {
            // Check if username already exists
            $check_query = "SELECT id FROM admin_users WHERE username = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "s", $username);
            mysqli_stmt_execute($check_stmt);
            if (mysqli_stmt_fetch($check_stmt)) {
                $error = "Username already exists";
                mysqli_stmt_close($check_stmt);
            } else {
                mysqli_stmt_close($check_stmt);
                
                // Check if email already exists
                $check_query = "SELECT id FROM admin_users WHERE email = ?";
                $check_stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "s", $email);
                mysqli_stmt_execute($check_stmt);
                if (mysqli_stmt_fetch($check_stmt)) {
                    $error = "Email already exists";
                    mysqli_stmt_close($check_stmt);
                } else {
                    mysqli_stmt_close($check_stmt);

                    // Insert new admin user
                    $insert_query = "INSERT INTO admin_users (
                        username, 
                        email, 
                        password, 
                        is_active,
                        password_changed_at,
                        created_at
                    ) VALUES (?, ?, ?, TRUE, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";

                    $insert_stmt = mysqli_prepare($conn, $insert_query);
                    mysqli_stmt_bind_param($insert_stmt, "sss", 
                        $username,
                        $email,
                        $hashed_password
                    );
                    
                    if (mysqli_stmt_execute($insert_stmt)) {
                        $success = "Admin user '{$username}' created successfully. Please delete this file immediately for security reasons.";
                    } else {
                        $error = "Error creating admin user: " . mysqli_error($conn);
                    }
                    mysqli_stmt_close($insert_stmt);
                }
            }
        }
    } else {
        $error = "Password does not meet complexity requirements.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin User</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background-color: #f4f4f4;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h2 {
            text-align: center;
            color: #333;
        }
        .requirements {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        form {
            display: flex;
            flex-direction: column;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        input {
            margin: 5px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            box-sizing: border-box;
        }
        input[type="submit"] {
            background-color: #007bff;
            color: #fff;
            cursor: pointer;
            font-weight: bold;
            margin-top: 10px;
        }
        input[type="submit"]:hover {
            background-color: #0056b3;
        }
        .message {
            text-align: center;
            margin-bottom: 20px;
            padding: 10px;
            border-radius: 4px;
        }
        .error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Create Admin User</h2>
        <?php 
        if ($error) echo "<div class='message error'>$error</div>";
        if ($success) echo "<div class='message success'>$success</div>";
        ?>
        <div class="requirements">
            <strong>Password Requirements:</strong>
            <ul>
                <li>Minimum 8 characters</li>
                <li>At least one uppercase letter</li>
                <li>At least one lowercase letter</li>
                <li>At least one number</li>
                <li>At least one special character</li>
            </ul>
        </div>
        <form method="post" autocomplete="off">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" placeholder="Enter username" required 
                       pattern="[a-zA-Z0-9_]{3,50}" title="Username must be 3-50 characters long and can only contain letters, numbers, and underscores">
            </div>
            
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" placeholder="Enter email address" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" placeholder="Enter password" required>
            </div>
            
            <input type="submit" value="Create Admin User">
        </form>
    </div>
</body>
</html>