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

// Check if user has permission to manage categories
if ($role !== 'admin' && $role !== 'editor') {
    header('Location: dashboard.php');
    exit;
}

// Handle category actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $name = trim($_POST['name']);
                $slug = create_slug($name);
                $description = trim($_POST['description']);
                $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
                
                // Check if category already exists
                $check_query = "SELECT id FROM blog_categories WHERE name = ? OR slug = ?";
                $check_stmt = mysqli_prepare($blog_conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "ss", $name, $slug);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $error = "Category already exists";
                } else {
                    $query = "INSERT INTO blog_categories (name, slug, description, parent_id) VALUES (?, ?, ?, ?)";
                    $stmt = mysqli_prepare($blog_conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssi", $name, $slug, $description, $parent_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "Category added successfully";
                    } else {
                        $error = "Failed to add category";
                    }
                }
                break;
                
            case 'edit':
                $category_id = (int)$_POST['category_id'];
                $name = trim($_POST['name']);
                $slug = create_slug($name);
                $description = trim($_POST['description']);
                $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
                
                // Check if category already exists
                $check_query = "SELECT id FROM blog_categories WHERE (name = ? OR slug = ?) AND id != ?";
                $check_stmt = mysqli_prepare($blog_conn, $check_query);
                mysqli_stmt_bind_param($check_stmt, "ssi", $name, $slug, $category_id);
                mysqli_stmt_execute($check_stmt);
                $check_result = mysqli_stmt_get_result($check_stmt);
                
                if (mysqli_num_rows($check_result) > 0) {
                    $error = "Category already exists";
                } else {
                    $query = "UPDATE blog_categories SET name = ?, slug = ?, description = ?, parent_id = ? WHERE id = ?";
                    $stmt = mysqli_prepare($blog_conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssii", $name, $slug, $description, $parent_id, $category_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $message = "Category updated successfully";
                    } else {
                        $error = "Failed to update category";
                    }
                }
                break;
                
            case 'delete':
                $category_id = (int)$_POST['category_id'];
                
                // Delete category relationships first
                mysqli_query($blog_conn, "DELETE FROM blog_post_categories WHERE category_id = $category_id");
                
                // Delete category
                if (mysqli_query($blog_conn, "DELETE FROM blog_categories WHERE id = $category_id")) {
                    $message = "Category deleted successfully";
                } else {
                    $error = "Failed to delete category";
                }
                break;
        }
    }
}

// Get all categories with post count and parent info
$query = "SELECT c.*, p.name as parent_name, COUNT(pc.post_id) as post_count 
          FROM blog_categories c 
          LEFT JOIN blog_categories p ON c.parent_id = p.id 
          LEFT JOIN blog_post_categories pc ON c.id = pc.category_id 
          GROUP BY c.id 
          ORDER BY c.name";
$result = mysqli_query($blog_conn, $query);
$categories = [];
while ($row = mysqli_fetch_assoc($result)) {
    $categories[] = $row;
}

$page_title = "Categories";
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
                            <a href="categories.php" class="nav-link text-white active">
                                <i class="fas fa-folder"></i> Categories
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="tags.php" class="nav-link text-white">
                                <i class="fas fa-tags"></i> Tags
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="archives.php" class="nav-link text-white">
                                <i class="fas fa-archive"></i> Archives
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
                    <h2>Categories</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus me-1"></i> Add New Category
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
                                        <th>Parent</th>
                                        <th>Description</th>
                                        <th>Posts</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($categories as $category): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($category['name']); ?></td>
                                            <td><?php echo htmlspecialchars($category['slug']); ?></td>
                                            <td>
                                                <?php echo $category['parent_name'] ? htmlspecialchars($category['parent_name']) : '-'; ?>
                                            </td>
                                            <td>
                                                <?php echo $category['description'] ? htmlspecialchars($category['description']) : '-'; ?>
                                            </td>
                                            <td><?php echo $category['post_count']; ?></td>
                                            <td><?php echo date('M j, Y', strtotime($category['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-light" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editCategoryModal<?php echo $category['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline" 
                                                          onsubmit="return confirm('Are you sure you want to delete this category?');">
                                                        <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="btn btn-sm btn-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                                
                                                <!-- Edit Category Modal -->
                                                <div class="modal fade" id="editCategoryModal<?php echo $category['id']; ?>" tabindex="-1">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header">
                                                                <h5 class="modal-title">Edit Category</h5>
                                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                            </div>
                                                            <form method="POST">
                                                                <div class="modal-body">
                                                                    <input type="hidden" name="action" value="edit">
                                                                    <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                                                    <div class="mb-3">
                                                                        <label for="name<?php echo $category['id']; ?>" class="form-label">Name</label>
                                                                        <input type="text" class="form-control" id="name<?php echo $category['id']; ?>" 
                                                                               name="name" value="<?php echo htmlspecialchars($category['name']); ?>" required>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="description<?php echo $category['id']; ?>" class="form-label">Description</label>
                                                                        <textarea class="form-control" id="description<?php echo $category['id']; ?>" 
                                                                                  name="description" rows="3"><?php echo htmlspecialchars($category['description']); ?></textarea>
                                                                    </div>
                                                                    <div class="mb-3">
                                                                        <label for="parent_id<?php echo $category['id']; ?>" class="form-label">Parent Category</label>
                                                                        <select class="form-select" id="parent_id<?php echo $category['id']; ?>" name="parent_id">
                                                                            <option value="">None</option>
                                                                            <?php foreach ($categories as $parent): ?>
                                                                                <?php if ($parent['id'] !== $category['id']): ?>
                                                                                    <option value="<?php echo $parent['id']; ?>" 
                                                                                            <?php echo $parent['id'] === $category['parent_id'] ? 'selected' : ''; ?>>
                                                                                        <?php echo htmlspecialchars($parent['name']); ?>
                                                                                    </option>
                                                                                <?php endif; ?>
                                                                            <?php endforeach; ?>
                                                                        </select>
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
    
    <!-- Add Category Modal -->
    <div class="modal fade" id="addCategoryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="parent_id" class="form-label">Parent Category</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">None</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 