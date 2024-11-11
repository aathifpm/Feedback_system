<?php
include 'functions.php';

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    
    // Check if user already exists
    $check_query = "SELECT id, role FROM users WHERE username = ?";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "s", $username);
    mysqli_stmt_execute($check_stmt);
    $result = mysqli_stmt_get_result($check_stmt);
    $existing_user = mysqli_fetch_assoc($result);

    if ($existing_user) {
        if ($existing_user['role'] == 'admin') {
            $error = "User '{$username}' is already an admin.";
        } else {
            // Update existing user to admin role
            $update_query = "UPDATE users SET role = 'admin' WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $existing_user['id']);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $success = "User '{$username}' has been updated to admin role.";
            } else {
                $error = "Error updating user role: " . mysqli_error($conn);
            }
        }
    } else {
        // Create new admin user
        if (is_password_complex($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            $insert_query = "INSERT INTO users (username, password, role, password_changed) VALUES (?, ?, 'admin', 1)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "ss", $username, $hashed_password);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $success = "Admin user '{$username}' created successfully. Please delete this file immediately for security reasons.";
                
                // Debug: Check if the role was actually inserted
                $check_role_query = "SELECT role FROM users WHERE username = ?";
                $check_role_stmt = mysqli_prepare($conn, $check_role_query);
                mysqli_stmt_bind_param($check_role_stmt, "s", $username);
                mysqli_stmt_execute($check_role_stmt);
                $role_result = mysqli_stmt_get_result($check_role_stmt);
                $role_data = mysqli_fetch_assoc($role_result);
                $success .= " Inserted role: " . ($role_data['role'] ?? 'Not set');
            } else {
                $error = "Error creating admin user: " . mysqli_error($conn);
            }
        } else {
            $error = "Password does not meet complexity requirements.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create/Update Admin User</title>
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
        form {
            display: flex;
            flex-direction: column;
        }
        input {
            margin: 10px 0;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        input[type="submit"] {
            background-color: #007bff;
            color: #fff;
            cursor: pointer;
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
        <h2>Create/Update Admin User</h2>
        <?php 
        if ($error) echo "<div class='message error'>$error</div>";
        if ($success) echo "<div class='message success'>$success</div>";
        ?>
        <form method="post">
            <input type="text" name="username" placeholder="Admin Username" required>
            <input type="password" name="password" placeholder="Admin Password (for new users)" required>
            <input type="submit" value="Create/Update Admin User">
        </form>
    </div>
</body>
</html>