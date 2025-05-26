<?php
session_start();
require_once '../functions.php';

// Check if user is logged in
if (!isset($_SESSION['blog_user_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get user data
$user_id = $_SESSION['blog_user_id'];
$username = $_SESSION['blog_username'];
$role = $_SESSION['blog_role'];

// Check if user has admin privileges
if ($role !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

$edit_user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$edit_user = null;

// If editing existing user
if ($edit_user_id > 0) {
    $query = "SELECT * FROM blog_users WHERE id = ?";
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $edit_user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $edit_user = mysqli_fetch_assoc($result);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $role = $_POST['role'];
    $password = trim($_POST['password']);
    
    // Check if username or email already exists
    $check_query = "SELECT id FROM blog_users WHERE (username = ? OR email = ?) AND id != ?";
    $check_stmt = mysqli_prepare($blog_conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ssi", $username, $email, $edit_user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error = "Username or email already exists";
    } else {
        if ($edit_user_id > 0) {
            // Update existing user
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $query = "UPDATE blog_users SET 
                          username = ?, email = ?, password = ?, 
                          first_name = ?, last_name = ?, role = ? 
                          WHERE id = ?";
                $stmt = mysqli_prepare($blog_conn, $query);
                mysqli_stmt_bind_param($stmt, "ssssssi", 
                    $username, $email, $hashed_password, 
                    $first_name, $last_name, $role, $edit_user_id);
            } else {
                $query = "UPDATE blog_users SET 
                          username = ?, email = ?, 
                          first_name = ?, last_name = ?, role = ? 
                          WHERE id = ?";
                $stmt = mysqli_prepare($blog_conn, $query);
                mysqli_stmt_bind_param($stmt, "sssssi", 
                    $username, $email, 
                    $first_name, $last_name, $role, $edit_user_id);
            }
        } else {
            // Create new user
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO blog_users 
                      (username, email, password, first_name, last_name, role) 
                      VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($blog_conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssss", 
                $username, $email, $hashed_password, 
                $first_name, $last_name, $role);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            header('Location: users.php?message=User saved successfully');
            exit;
        } else {
            $error = "Failed to save user";
        }
    }
}

$page_title = $edit_user_id > 0 ? "Edit User" : "Add New User";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - PEC Blog Admin</title>
    <link rel="icon" href="../college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f8f9fa;
        }
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
            color: #fff;
            position: sticky;
            top: 0;
        }
        .content {
            padding: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 p-0 sidebar">
                <div class="sidebar-header p-3">
                    <h4 class="mb-0">PEC Blog Admin</h4>
                </div>
                <div class="sidebar-menu">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link text-white">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="posts.php" class="nav-link text-white">
                                <i class="fas fa-file-alt"></i> Posts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="categories.php" class="nav-link text-white">
                                <i class="fas fa-folder"></i> Categories
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="media.php" class="nav-link text-white">
                                <i class="fas fa-images"></i> Media
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="users.php" class="nav-link text-white active">
                                <i class="fas fa-users"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="../logout.php" class="nav-link text-white">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 content">
                <div class="content-header d-flex justify-content-between align-items-center mb-4">
                    <h2><?php echo $page_title; ?></h2>
                    <a href="users.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users
                    </a>
                </div>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username</label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['email']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="password" class="form-label">
                                            Password <?php echo $edit_user ? '(leave blank to keep current)' : ''; ?>
                                        </label>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               <?php echo $edit_user ? '' : 'required'; ?>>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['first_name']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['last_name']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="role" class="form-label">Role</label>
                                        <select class="form-select" id="role" name="role" required>
                                            <option value="author" <?php echo $edit_user && $edit_user['role'] === 'author' ? 'selected' : ''; ?>>Author</option>
                                            <option value="editor" <?php echo $edit_user && $edit_user['role'] === 'editor' ? 'selected' : ''; ?>>Editor</option>
                                            <option value="admin" <?php echo $edit_user && $edit_user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Save User
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 