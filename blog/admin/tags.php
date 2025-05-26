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

// Check if user has permission to manage tags
if ($role !== 'admin' && $role !== 'editor') {
    header('Location: dashboard.php');
    exit;
}

// Handle tag actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = trim($_POST['name']);
                $slug = create_slug($name);
                
                // Check if tag already exists
                $check_query = "SELECT id FROM blog_tags WHERE name = ? OR slug = ?";
                $check_stmt = mysqli_prepare($blog_conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "ss", $name, $slug);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $error = "Tag already exists";
                } else {
                    $query = "INSERT INTO blog_tags (name, slug) VALUES (?, ?)";
                    $stmt = mysqli_prepare($blog_conn, $query);
                    mysqli_stmt_bind_param($stmt, "ss", $name, $slug);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "Tag added successfully";
                    } else {
                        $error = "Failed to add tag";
                    }
                }
                break;
                
            case 'edit':
                $tag_id = (int)$_POST['tag_id'];
                $name = trim($_POST['name']);
                $slug = create_slug($name);
                
                // Check if tag already exists
                $check_query = "SELECT id FROM blog_tags WHERE (name = ? OR slug = ?) AND id != ?";
                $check_stmt = mysqli_prepare($blog_conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "ssi", $name, $slug, $tag_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $error = "Tag already exists";
                } else {
                    $query = "UPDATE blog_tags SET name = ?, slug = ? WHERE id = ?";
                    $stmt = mysqli_prepare($blog_conn, $query);
                    mysqli_stmt_bind_param($stmt, "ssi", $name, $slug, $tag_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "Tag updated successfully";
                    } else {
                        $error = "Failed to update tag";
                    }
                }
                break;
                
            case 'delete':
                $tag_id = (int)$_POST['tag_id'];
                
                // Delete tag relationships first
                mysqli_query($blog_conn, "DELETE FROM blog_post_tags WHERE tag_id = $tag_id");
                
                // Delete tag
                if (mysqli_query($blog_conn, "DELETE FROM blog_tags WHERE id = $tag_id")) {
                    $message = "Tag deleted successfully";
                } else {
                    $error = "Failed to delete tag";
                }
                break;
        }
    }
}

// Get all tags with post count
$query = "SELECT t.*, COUNT(pt.post_id) as post_count 
          FROM blog_tags t 
          LEFT JOIN blog_post_tags pt ON t.id = pt.tag_id 
          GROUP BY t.id 
          ORDER BY t.name";
$result = mysqli_query($blog_conn, $query);
$tags = [];
while ($row = mysqli_fetch_assoc($result)) {
    $tags[] = $row;
}

$page_title = "Tags";
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
                            <a href="tags.php" class="nav-link text-white active">
                                <i class="fas fa-tags"></i> Tags
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="media.php" class="nav-link text-white">
                                <i class="fas fa-images"></i> Media
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="users.php" class="nav-link text-white">
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
                    <h2>Tags</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTagModal">
                        <i class="fas fa-plus me-1"></i> Add New Tag
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
                                        <th>Name</th>
                                        <th>Slug</th>
                                        <th>Posts</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tags as $tag): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tag['name']); ?></td>
                                            <td><?php echo htmlspecialchars($tag['slug']); ?></td>
                                            <td><?php echo $tag['post_count']; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($tag['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-light" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editTagModal<?php echo $tag['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Are you sure you want to delete this tag?');">
                                                        <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                                
                                                <!-- Edit Tag Modal -->
                                                <div class="modal fade" id="editTagModal<?php echo $tag['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Tag</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="action" value="edit">
                                                                    <input type="hidden" name="tag_id" value="<?php echo $tag['id']; ?>">
                                                                    <div class="mb-3">
                                                                        <label for="name<?php echo $tag['id']; ?>" class="form-label">Name</label>
                                                                        <input type="text" class="form-control" id="name<?php echo $tag['id']; ?>" 
                                                                               name="name" value="<?php echo htmlspecialchars($tag['name']); ?>" required>
                                                                    </div>
                                                                </div>
                                                                <div class="modal-footer">
                                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                    <button type="submit" class="btn btn-primary">Save Changes</button>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
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
    
    <!-- Add Tag Modal -->
    <div class="modal fade" id="addTagModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Tag</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Tag</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 