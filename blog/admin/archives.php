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

// Get selected year and month
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

// Get posts for selected month
$query = "SELECT p.*, u.username, u.first_name, u.last_name, 
          GROUP_CONCAT(DISTINCT c.name) as categories,
          GROUP_CONCAT(DISTINCT t.name) as tags
          FROM blog_posts p 
          JOIN blog_users u ON p.author_id = u.id 
          LEFT JOIN blog_post_categories pc ON p.id = pc.post_id 
          LEFT JOIN blog_categories c ON pc.category_id = c.id 
          LEFT JOIN blog_post_tags pt ON p.id = pt.post_id 
          LEFT JOIN blog_tags t ON pt.tag_id = t.id 
          WHERE YEAR(p.created_at) = ? AND MONTH(p.created_at) = ? 
          GROUP BY p.id 
          ORDER BY p.created_at DESC";

$stmt = mysqli_prepare($blog_conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $year, $month);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$posts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $posts[] = $row;
}

// Get archive years and months
$archive_query = "SELECT DISTINCT YEAR(created_at) as year, MONTH(created_at) as month 
                 FROM blog_posts 
                 ORDER BY year DESC, month DESC";
$archive_result = mysqli_query($blog_conn, $archive_query);

$archives = [];
while ($row = mysqli_fetch_assoc($archive_result)) {
    if (!isset($archives[$row['year']])) {
        $archives[$row['year']] = [];
    }
    $archives[$row['year']][] = $row['month'];
}

$page_title = "Archives";
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
        .archive-link {
            text-decoration: none;
            color: inherit;
        }
        .archive-link:hover {
            color: #0d6efd;
        }
        .archive-link.active {
            color: #0d6efd;
            font-weight: 500;
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
                            <a href="tags.php" class="nav-link text-white">
                                <i class="fas fa-tags"></i> Tags
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="archives.php" class="nav-link text-white active">
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
                    <h2>Archives</h2>
                </div>
                
                <div class="row">
                    <!-- Archive Navigation -->
                    <div class="col-lg-3">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Archive Navigation</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($archives as $archive_year => $months): ?>
                                    <div class="mb-3">
                                        <h6 class="mb-2"><?php echo $archive_year; ?></h6>
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php foreach ($months as $archive_month): ?>
                                                <a href="?year=<?php echo $archive_year; ?>&month=<?php echo $archive_month; ?>" 
                                                   class="archive-link <?php echo $archive_year === $year && $archive_month === $month ? 'active' : ''; ?>">
                                                    <?php echo date('M', mktime(0, 0, 0, $archive_month, 1)); ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Posts List -->
                    <div class="col-lg-9">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    Posts from <?php echo date('F Y', mktime(0, 0, 0, $month, 1, $year)); ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($posts)): ?>
                                    <p class="text-muted">No posts found for this month.</p>
                                <?php else: ?>
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
                                                            <a href="post-edit.php?id=<?php echo $post['id']; ?>" class="text-decoration-none">
                                                                <?php echo htmlspecialchars($post['title']); ?>
                                                            </a>
                                                        </td>
                                                        <td>
                                                            <?php echo htmlspecialchars($post['first_name'] . ' ' . $post['last_name']); ?>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $categories = explode(',', $post['categories']);
                                                            foreach ($categories as $category) {
                                                                echo '<span class="badge bg-secondary me-1">' . htmlspecialchars($category) . '</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <?php 
                                                            $tags = explode(',', $post['tags']);
                                                            foreach ($tags as $tag) {
                                                                echo '<span class="badge bg-info me-1">' . htmlspecialchars($tag) . '</span>';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $post['status'] === 'published' ? 'success' : 'warning'; ?>">
                                                                <?php echo ucfirst($post['status']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php echo date('M j, Y', strtotime($post['created_at'])); ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group">
                                                                <a href="post-edit.php?id=<?php echo $post['id']; ?>" 
                                                                   class="btn btn-sm btn-light">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <a href="../post.php?slug=<?php echo $post['slug']; ?>" 
                                                                   class="btn btn-sm btn-light" target="_blank">
                                                                    <i class="fas fa-eye"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 