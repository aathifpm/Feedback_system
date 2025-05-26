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

// Get page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get latest posts
global $blog_conn;

$query = "SELECT p.*, u.username, u.first_name, u.last_name 
          FROM blog_posts p 
          JOIN blog_users u ON p.author_id = u.id 
          ORDER BY p.created_at DESC 
          LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($blog_conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $per_page, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$posts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $posts[] = $row;
}

// Get total post count
$count_query = "SELECT COUNT(*) as total FROM blog_posts";
$count_result = mysqli_query($blog_conn, $count_query);
$count_row = mysqli_fetch_assoc($count_result);
$total_posts = $count_row['total'];

// Get total categories count
$cat_query = "SELECT COUNT(*) as total FROM blog_categories";
$cat_result = mysqli_query($blog_conn, $cat_query);
$cat_row = mysqli_fetch_assoc($cat_result);
$total_categories = $cat_row['total'];

// Get total comments count
$comm_query = "SELECT COUNT(*) as total FROM blog_comments";
$comm_result = mysqli_query($blog_conn, $comm_query);
$comm_row = mysqli_fetch_assoc($comm_result);
$total_comments = $comm_row['total'];

// Get total pending comments
$pend_query = "SELECT COUNT(*) as total FROM blog_comments WHERE status = 'pending'";
$pend_result = mysqli_query($blog_conn, $pend_query);
$pend_row = mysqli_fetch_assoc($pend_result);
$pending_comments = $pend_row['total'];

// Page title
$page_title = "Admin Dashboard";
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
    <!-- Bootstrap CSS -->
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
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-menu {
            padding: 1rem 0;
        }
        .sidebar-menu .nav-link {
            color: rgba(255,255,255,0.75);
            padding: 0.75rem 1rem;
            margin: 0.2rem 0;
            border-radius: 0.25rem;
        }
        .sidebar-menu .nav-link:hover {
            color: #fff;
            background-color: rgba(255,255,255,0.1);
        }
        .sidebar-menu .nav-link.active {
            color: #fff;
            background-color: #007bff;
        }
        .sidebar-menu .nav-link i {
            margin-right: 0.75rem;
            width: 1.25rem;
            text-align: center;
        }
        .content {
            padding: 1.5rem;
        }
        .content-header {
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #dee2e6;
            margin-bottom: 1.5rem;
        }
        .dashboard-card {
            border-radius: 0.5rem;
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.05);
            border: none;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }
        .card-icon {
            font-size: 2rem;
            position: absolute;
            right: 1rem;
            top: 1rem;
            opacity: 0.25;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-lg-2 col-md-3 p-0 sidebar">
                <div class="sidebar-header">
                    <h4 class="mb-0">PEC Blog Admin</h4>
                </div>
                <div class="sidebar-menu">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a href="dashboard.php" class="nav-link active">
                                <i class="fas fa-tachometer-alt"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="posts.php" class="nav-link">
                                <i class="fas fa-file-alt"></i> Posts
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="categories.php" class="nav-link">
                                <i class="fas fa-folder"></i> Categories
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="comments.php" class="nav-link">
                                <i class="fas fa-comments"></i> Comments
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="users.php" class="nav-link">
                                <i class="fas fa-users"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="profile.php" class="nav-link">
                                <i class="fas fa-user"></i> Profile
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="../logout.php" class="nav-link">
                                <i class="fas fa-sign-out-alt"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="col-lg-10 col-md-9 content">
                <div class="content-header d-flex justify-content-between align-items-center">
                    <h2 class="mb-0">Dashboard</h2>
                    <div>
                        <span class="me-2">Welcome, <?php echo htmlspecialchars($username); ?></span>
                        <a href="post-new.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i> New Post
                        </a>
                    </div>
                </div>
                
                <!-- Dashboard Overview -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="dashboard-card card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Posts</h5>
                                <h2 class="mb-0"><?php echo $total_posts; ?></h2>
                                <i class="fas fa-file-alt card-icon"></i>
                            </div>
                            <div class="card-footer bg-transparent border-0 text-end">
                                <a href="posts.php" class="text-white">View All <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-card card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Categories</h5>
                                <h2 class="mb-0"><?php echo $total_categories; ?></h2>
                                <i class="fas fa-folder card-icon"></i>
                            </div>
                            <div class="card-footer bg-transparent border-0 text-end">
                                <a href="categories.php" class="text-white">Manage <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-card card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Comments</h5>
                                <h2 class="mb-0"><?php echo $total_comments; ?></h2>
                                <i class="fas fa-comments card-icon"></i>
                            </div>
                            <div class="card-footer bg-transparent border-0 text-end">
                                <a href="comments.php" class="text-white">View All <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="dashboard-card card bg-warning text-white">
                            <div class="card-body">
                                <h5 class="card-title">Pending Comments</h5>
                                <h2 class="mb-0"><?php echo $pending_comments; ?></h2>
                                <i class="fas fa-comment-dots card-icon"></i>
                            </div>
                            <div class="card-footer bg-transparent border-0 text-end">
                                <a href="comments.php?status=pending" class="text-white">Moderate <i class="fas fa-arrow-right ms-1"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Posts -->
                <div class="card dashboard-card">
                    <div class="card-header bg-white">
                        <h5 class="card-title mb-0">Recent Posts</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Title</th>
                                        <th>Author</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($posts)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No posts found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($posts as $post): ?>
                                            <tr>
                                                <td>
                                                    <a href="post-edit.php?id=<?php echo $post['id']; ?>"><?php echo htmlspecialchars($post['title']); ?></a>
                                                </td>
                                                <td><?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?></td>
                                                <td>
                                                    <?php if ($post['status'] === 'published'): ?>
                                                        <span class="badge bg-success">Published</span>
                                                    <?php elseif ($post['status'] === 'draft'): ?>
                                                        <span class="badge bg-secondary">Draft</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Archived</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($post['created_at'])); ?></td>
                                                <td>
                                                    <a href="post-edit.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="../post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-sm btn-outline-info" target="_blank">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="post-delete.php?id=<?php echo $post['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to delete this post?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card-footer bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <a href="posts.php" class="btn btn-outline-primary btn-sm">View All Posts</a>
                            
                            <!-- Pagination -->
                            <?php if ($total_posts > $per_page): ?>
                                <nav aria-label="Page navigation">
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php
                                        $total_pages = ceil($total_posts / $per_page);
                                        $start_page = max(1, $page - 2);
                                        $end_page = min($start_page + 4, $total_pages);
                                        
                                        if ($page > 1): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                                    <span aria-hidden="true">&laquo;</span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        
                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                            </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <li class="page-item">
                                                <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                                    <span aria-hidden="true">&raquo;</span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 