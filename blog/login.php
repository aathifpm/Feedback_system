<?php
session_start();
require_once 'functions.php';

// Check if already logged in
if (isset($_SESSION['blog_user_id'])) {
    header('Location: admin/dashboard.php');
    exit;
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? blog_sanitize_input($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $user = authenticate_blog_user($username, $password);
        
        if ($user) {
            // Set session variables
            $_SESSION['blog_user_id'] = $user['id'];
            $_SESSION['blog_username'] = $user['username'];
            $_SESSION['blog_role'] = $user['role'];
            
            // Redirect to dashboard
            header('Location: admin/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

// Set page title
$page_title = 'Login';
$show_header = false;

// Include header
include 'includes/header.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0">Login to Blog Admin</h3>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <form method="post" action="">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">Login</button>
                        </div>
                    </form>
                    
                    <div class="mt-3 text-center">
                        <a href="forget_password.php">Forgot Password?</a>
                    </div>
                </div>
                <div class="card-footer text-center">
                    <a href="index.php">Back to Blog</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include 'includes/footer.php';
?> 