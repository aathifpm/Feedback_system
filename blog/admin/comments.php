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

// Check if user has permission to manage comments
if ($role !== 'admin' && $role !== 'editor') {
    header('Location: dashboard.php');
    exit;
}

// Handle comment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $comment_id = (int)$_POST['comment_id'];
        
        switch ($_POST['action']) {
            case 'approve':
                mysqli_query($blog_conn, "UPDATE blog_comments SET status = 'approved' WHERE id = $comment_id");
                $message = "Comment approved successfully";
                break;
                
            case 'spam':
                mysqli_query($blog_conn, "UPDATE blog_comments SET status = 'spam' WHERE id = $comment_id");
                $message = "Comment marked as spam";
                break;
                
            case 'trash':
                mysqli_query($blog_conn, "UPDATE blog_comments SET status = 'trash' WHERE id = $comment_id");
                $message = "Comment moved to trash";
                break;
                
            case 'delete':
                mysqli_query($blog_conn, "DELETE FROM blog_comments WHERE id = $comment_id");
                $message = "Comment deleted permanently";
                break;
        }
    }
}

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : 'pending';
$post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;

// Build query
$query = "SELECT c.*, p.title as post_title 
          FROM blog_comments c 
          JOIN blog_posts p ON c.post_id = p.id 
          WHERE 1=1";

if ($status !== 'all') {
    $query .= " AND c.status = '" . mysqli_real_escape_string($blog_conn, $status) . "'";
}

if ($post_id > 0) {
    $query .= " AND c.post_id = $post_id";
}

$query .= " ORDER BY c.created_at DESC";

$result = mysqli_query($blog_conn, $query);
$comments = [];
while ($row = mysqli_fetch_assoc($result)) {
    $comments[] = $row;
}

// Get all posts for filter
$posts_query = "SELECT id, title FROM blog_posts ORDER BY title";
$posts_result = mysqli_query($blog_conn, $posts_query);
$posts = [];
while ($row = mysqli_fetch_assoc($posts_result)) {
    $posts[] = $row;
}

$page_title = "Comments";
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
        .comment-content {
            max-width: 400px;
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
                    <h2>Comments</h2>
                </div>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" onchange="this.form.submit()">
                                    <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                    <option value="spam" <?php echo $status === 'spam' ? 'selected' : ''; ?>>Spam</option>
                                    <option value="trash" <?php echo $status === 'trash' ? 'selected' : ''; ?>>Trash</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="post_id" class="form-label">Post</label>
                                <select class="form-select" id="post_id" name="post_id" onchange="this.form.submit()">
                                    <option value="0">All Posts</option>
                                    <?php foreach ($posts as $post): ?>
                                        <option value="<?php echo $post['id']; ?>" 
                                                <?php echo $post_id === $post['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($post['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Comments List -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Author</th>
                                        <th>Comment</th>
                                        <th>Post</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($comments as $comment): ?>
                                        <tr>
                                            <td>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($comment['author_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($comment['author_email']); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="comment-content" title="<?php echo htmlspecialchars($comment['content']); ?>">
                                                    <?php echo htmlspecialchars($comment['content']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="../post.php?slug=<?php echo $comment['post_id']; ?>" target="_blank">
                                                    <?php echo htmlspecialchars($comment['post_title']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($comment['status']) {
                                                        'approved' => 'success',
                                                        'pending' => 'warning',
                                                        'spam' => 'danger',
                                                        'trash' => 'secondary',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($comment['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($comment['created_at'])); ?></td>
                                            <td>
                                                <div class="btn-group">
                                                    <?php if ($comment['status'] === 'pending'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                            <input type="hidden" name="action" value="approve">
                                                            <button type="submit" class="btn btn-sm btn-success">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($comment['status'] !== 'spam'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                            <input type="hidden" name="action" value="spam">
                                                            <button type="submit" class="btn btn-sm btn-warning">
                                                                <i class="fas fa-ban"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($comment['status'] !== 'trash'): ?>
                                                        <form method="POST" class="d-inline">
                                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                            <input type="hidden" name="action" value="trash">
                                                            <button type="submit" class="btn btn-sm btn-secondary">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($comment['status'] === 'trash'): ?>
                                                        <form method="POST" class="d-inline" 
                                                              onsubmit="return confirm('Are you sure you want to delete this comment permanently?');">
                                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                            <input type="hidden" name="action" value="delete">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                <i class="fas fa-times"></i>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 