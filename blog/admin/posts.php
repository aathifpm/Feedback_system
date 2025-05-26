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

// Check if user has permission to manage posts
if ($role !== 'admin' && $role !== 'editor' && $role !== 'author') {
    header('Location: dashboard.php');
    exit;
}

// Handle post actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $post_id = (int)$_POST['post_id'];
        
        switch ($_POST['action']) {
            case 'delete':
                // Delete post relationships first
                mysqli_query($blog_conn, "DELETE FROM blog_post_categories WHERE post_id = $post_id");
                mysqli_query($blog_conn, "DELETE FROM blog_post_tags WHERE post_id = $post_id");
                mysqli_query($blog_conn, "DELETE FROM blog_comments WHERE post_id = $post_id");
                
                // Delete post
                if (mysqli_query($blog_conn, "DELETE FROM blog_posts WHERE id = $post_id")) {
                    $message = "Post deleted successfully";
                } else {
                    $error = "Failed to delete post";
                }
                break;
                
            case 'status':
                $new_status = mysqli_real_escape_string($blog_conn, $_POST['status']);
                $published_at = $new_status === 'published' ? "published_at = CURRENT_TIMESTAMP," : "";
                
                mysqli_query($blog_conn, "UPDATE blog_posts SET status = '$new_status', $published_at updated_at = CURRENT_TIMESTAMP WHERE id = $post_id");
                $message = "Post status updated successfully";
                break;
        }
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$author_id = isset($_GET['author_id']) ? (int)$_GET['author_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "SELECT p.*, u.username as author_name, 
          GROUP_CONCAT(DISTINCT c.name) as categories,
          GROUP_CONCAT(DISTINCT t.name) as tags
          FROM blog_posts p 
          LEFT JOIN blog_users u ON p.author_id = u.id 
          LEFT JOIN blog_post_categories pc ON p.id = pc.post_id 
          LEFT JOIN blog_categories c ON pc.category_id = c.id 
          LEFT JOIN blog_post_tags pt ON p.id = pt.post_id 
          LEFT JOIN blog_tags t ON pt.tag_id = t.id 
          WHERE 1=1";

if ($status !== 'all') {
    $query .= " AND p.status = '" . mysqli_real_escape_string($blog_conn, $status) . "'";
}

if ($category_id > 0) {
    $query .= " AND pc.category_id = $category_id";
}

if ($author_id > 0) {
    $query .= " AND p.author_id = $author_id";
}

if ($search) {
    $search = mysqli_real_escape_string($blog_conn, $search);
    $query .= " AND (p.title LIKE '%$search%' OR p.content LIKE '%$search%')";
}

$query .= " GROUP BY p.id ORDER BY p.created_at DESC";

$result = mysqli_query($blog_conn, $query);
$posts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $posts[] = $row;
}

// Get all categories for filter
$categories_query = "SELECT * FROM blog_categories ORDER BY name";
$categories_result = mysqli_query($blog_conn, $categories_query);
$categories = [];
while ($row = mysqli_fetch_assoc($categories_result)) {
    $categories[] = $row;
}

// Get all authors for filter
$authors_query = "SELECT id, username FROM blog_users WHERE role IN ('admin', 'editor', 'author') ORDER BY username";
$authors_result = mysqli_query($blog_conn, $authors_query);
$authors = [];
while ($row = mysqli_fetch_assoc($authors_result)) {
    $authors[] = $row;
}

$page_title = "Posts";
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
        .post-title {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
                            <a href="posts.php" class="nav-link text-white active">
                                <i class="fas fa-file-alt"></i> Posts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="categories.php" class="nav-link text-white">
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
                    <h2>Posts</h2>
                    <a href="post-new.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Add New Post
                    </a>
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
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $status === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="archived" <?php echo $status === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id" onchange="this.form.submit()">
                                    <option value="0">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>" 
                                                <?php echo $category_id === $category['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="author_id" class="form-label">Author</label>
                                <select class="form-select" id="author_id" name="author_id" onchange="this.form.submit()">
                                    <option value="0">All Authors</option>
                                    <?php foreach ($authors as $author): ?>
                                        <option value="<?php echo $author['id']; ?>" 
                                                <?php echo $author_id === $author['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($author['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search); ?>" 
                                       placeholder="Search posts...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">Search</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Posts List -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Categories</th>
                                        <th>Tags</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posts as $post): ?>
                                        <tr>
                                            <td>
                                                <div class="post-title" title="<?php echo htmlspecialchars($post['title']); ?>">
                                                    <?php echo htmlspecialchars($post['title']); ?>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($post['author_name']); ?></td>
                                            <td>
                                                <?php 
                                                $categories = explode(',', $post['categories']);
                                                foreach ($categories as $category) {
                                                    if ($category) {
                                                        echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($category) . '</span>';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $tags = explode(',', $post['tags']);
                                                foreach ($tags as $tag) {
                                                    if ($tag) {
                                                        echo '<span class="badge bg-info me-1">' . htmlspecialchars($tag) . '</span>';
                                                    }
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($post['status']) {
                                                        'published' => 'success',
                                                        'draft' => 'warning',
                                                        'archived' => 'secondary',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($post['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($post['status'] === 'published') {
                                                    echo date('M j, Y', strtotime($post['published_at']));
                                                } else {
                                                    echo date('M j, Y', strtotime($post['created_at']));
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="post-edit.php?id=<?php echo $post['id']; ?>" 
                                                       class="btn btn-sm btn-light">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="../post.php?slug=<?php echo $post['slug']; ?>" 
                                                       class="btn btn-sm btn-info" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <div class="btn-group">
                                                        <button type="button" class="btn btn-sm btn-secondary dropdown-toggle" 
                                                                data-bs-toggle="dropdown">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <?php if ($post['status'] === 'draft'): ?>
                                                                <li>
                                                                    <form method="POST" class="dropdown-item">
                                                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                                        <input type="hidden" name="action" value="status">
                                                                        <input type="hidden" name="status" value="published">
                                                                        <button type="submit" class="btn btn-link text-success p-0">
                                                                            <i class="fas fa-check me-1"></i> Publish
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($post['status'] === 'published'): ?>
                                                                <li>
                                                                    <form method="POST" class="dropdown-item">
                                                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                                        <input type="hidden" name="action" value="status">
                                                                        <input type="hidden" name="status" value="archived">
                                                                        <button type="submit" class="btn btn-link text-warning p-0">
                                                                            <i class="fas fa-archive me-1"></i> Archive
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($post['status'] === 'archived'): ?>
                                                                <li>
                                                                    <form method="POST" class="dropdown-item">
                                                                        <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                                        <input type="hidden" name="action" value="status">
                                                                        <input type="hidden" name="status" value="draft">
                                                                        <button type="submit" class="btn btn-link text-secondary p-0">
                                                                            <i class="fas fa-undo me-1"></i> Unarchive
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                            
                                                            <li><hr class="dropdown-divider"></li>
                                                            
                                                            <li>
                                                                <form method="POST" class="dropdown-item" 
                                                                      onsubmit="return confirm('Are you sure you want to delete this post?');">
                                                                    <input type="hidden" name="post_id" value="<?php echo $post['id']; ?>">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <button type="submit" class="btn btn-link text-danger p-0">
                                                                        <i class="fas fa-trash me-1"></i> Delete
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 