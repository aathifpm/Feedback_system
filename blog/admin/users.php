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

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $target_user_id = (int)$_POST['user_id'];
        
        switch ($_POST['action']) {
            case 'delete':
                if ($target_user_id !== $user_id) {
                    mysqli_query($blog_conn, "DELETE FROM blog_users WHERE id = $target_user_id");
                    $message = "User deleted successfully";
                } else {
                    $error = "You cannot delete your own account";
                }
                break;
                
            case 'toggle_status':
                if ($target_user_id !== $user_id) {
                    mysqli_query($blog_conn, "UPDATE blog_users SET is_active = NOT is_active WHERE id = $target_user_id");
                    $message = "User status updated successfully";
                } else {
                    $error = "You cannot change your own status";
                }
                break;
                
            case 'update_role':
                if ($target_user_id !== $user_id) {
                    $new_role = mysqli_real_escape_string($blog_conn, $_POST['role']);
                    mysqli_query($blog_conn, "UPDATE blog_users SET role = '$new_role' WHERE id = $target_user_id");
                    $message = "User role updated successfully";
                } else {
                    $error = "You cannot change your own role";
                }
                break;
        }
    }
}

// Get all users
$query = "SELECT * FROM blog_users ORDER BY created_at DESC";
$result = mysqli_query($blog_conn, $query);
$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $users[] = $row;
}

$page_title = "User Management";
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
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
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
                    <h2>User Management</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-user-plus me-1"></i> Add New User
                    </button>
                </div>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Status</th>
                                        <th>Last Login</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php if ($user['profile_image']): ?>
                                                        <img src="../<?php echo htmlspecialchars($user['profile_image']); ?>" 
                                                             class="user-avatar me-2" alt="Profile">
                                                    <?php else: ?>
                                                        <div class="user-avatar me-2 bg-secondary d-flex align-items-center justify-content-center text-white">
                                                            <i class="fas fa-user"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="action" value="update_role">
                                                    <select name="role" class="form-select form-select-sm" 
                                                            onchange="this.form.submit()" 
                                                            <?php echo $user['id'] === $user_id ? 'disabled' : ''; ?>>
                                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                                        <option value="editor" <?php echo $user['role'] === 'editor' ? 'selected' : ''; ?>>Editor</option>
                                                        <option value="author" <?php echo $user['role'] === 'author' ? 'selected' : ''; ?>>Author</option>
                                                    </select>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <button type="submit" class="btn btn-sm <?php echo $user['is_active'] ? 'btn-success' : 'btn-danger'; ?>"
                                                            <?php echo $user['id'] === $user_id ? 'disabled' : ''; ?>>
                                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                    </button>
                                                </form>
                                            </td>
                                            <td>
                                                <?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="user-edit.php?id=<?php echo $user['id']; ?>" 
                                                       class="btn btn-sm btn-light">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($user['id'] !== $user_id): ?>
                                                        <form method="POST" class="d-inline" 
                                                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="user-edit.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="author">Author</option>
                                <option value="editor">Editor</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 