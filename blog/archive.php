<?php
// Include functions
require_once 'functions.php';

// Set page variables
$page_title = 'Blog Archive';
$show_header = true;

// Pagination settings
$posts_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $posts_per_page;

// Get total post count for pagination
$total_posts_query = "SELECT COUNT(*) as count FROM blog_posts WHERE status = 'published'";
$total_result = mysqli_query($blog_conn, $total_posts_query);
$total_row = mysqli_fetch_assoc($total_result);
$total_posts = $total_row['count'];
$total_pages = ceil($total_posts / $posts_per_page);

// Get posts with pagination
$query = "SELECT p.*, u.first_name, u.last_name 
          FROM blog_posts p 
          JOIN blog_users u ON p.author_id = u.id 
          WHERE p.status = 'published' 
          ORDER BY p.published_at DESC 
          LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($blog_conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $posts_per_page, $offset);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$posts = [];
while ($post = mysqli_fetch_assoc($result)) {
    $posts[] = $post;
}

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8">
            <div class="page-header mb-4">
                <h1>Blog Archive</h1>
                <p class="text-muted">Browse all our blog posts</p>
            </div>
            
            <?php if (empty($posts)): ?>
                <div class="alert alert-info">No posts found.</div>
            <?php else: ?>
                <div class="archive-posts">
                    <?php foreach ($posts as $post): ?>
                        <div class="card mb-4">
                            <div class="row g-0">
                                <div class="col-md-4">
                                    <?php if ($post['featured_image']): ?>
                                        <img src="<?php echo $post['featured_image']; ?>" class="img-fluid rounded-start" alt="<?php echo $post['title']; ?>" style="height: 100%; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="bg-light h-100 d-flex align-items-center justify-content-center">
                                            <i class="fas fa-file-alt fa-3x text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-8">
                                    <div class="card-body">
                                        <h3 class="card-title"><?php echo $post['title']; ?></h3>
                                        <div class="post-meta mb-2">
                                            <span><i class="far fa-user me-1"></i> <?php echo $post['first_name'] . ' ' . $post['last_name']; ?></span>
                                            <span class="mx-2">|</span>
                                            <span><i class="far fa-calendar me-1"></i> <?php echo format_blog_date($post['published_at']); ?></span>
                                            <?php if ($post['view_count'] > 0): ?>
                                                <span class="mx-2">|</span>
                                                <span><i class="far fa-eye me-1"></i> <?php echo $post['view_count']; ?> views</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="card-text"><?php echo isset($post['excerpt']) ? $post['excerpt'] : get_excerpt($post['content']); ?></p>
                                        <a href="post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-primary">Read More</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <!-- Pagination -->
                    <nav aria-label="Page navigation">
                        <ul class="pagination justify-content-center">
                            <?php if ($current_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $current_page - 1; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&laquo;</span>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            // Show limited page numbers with ellipsis
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            if ($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>';
                                if ($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $current_page) {
                                    echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                } else {
                                    echo '<li class="page-item"><a class="page-link" href="?page=' . $i . '">' . $i . '</a></li>';
                                }
                            }
                            
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '">' . $total_pages . '</a></li>';
                            }
                            ?>
                            
                            <?php if ($current_page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?php echo $current_page + 1; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php else: ?>
                                <li class="page-item disabled">
                                    <span class="page-link" aria-hidden="true">&raquo;</span>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Search Box -->
            <div class="sidebar-section">
                <h4 class="sidebar-title">Search</h4>
                <form action="search.php" method="get" class="search-form">
                    <div class="input-group">
                        <input type="text" class="form-control" placeholder="Search posts..." name="q" required>
                        <button class="btn btn-primary" type="submit">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Categories -->
            <div class="sidebar-section">
                <h4 class="sidebar-title">Categories</h4>
                <ul class="category-list">
                    <?php
                    $categories = get_categories();
                    foreach ($categories as $category) {
                        echo '<li><a href="category.php?slug=' . $category['slug'] . '">' . $category['name'] . '</a></li>';
                    }
                    ?>
                </ul>
            </div>
            
            <!-- Recent Posts -->
            <div class="sidebar-section">
                <h4 class="sidebar-title">Recent Posts</h4>
                <ul class="list-unstyled">
                    <?php 
                    $recent_posts = get_recent_posts(5);
                    foreach ($recent_posts as $recent_post): 
                    ?>
                        <li class="mb-3 pb-3 border-bottom">
                            <a href="post.php?slug=<?php echo $recent_post['slug']; ?>" class="d-flex text-decoration-none">
                                <?php if ($recent_post['featured_image']): ?>
                                    <img src="<?php echo $recent_post['featured_image']; ?>" class="me-3" alt="<?php echo $recent_post['title']; ?>" style="width: 70px; height: 50px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="me-3 bg-light" style="width: 70px; height: 50px;"></div>
                                <?php endif; ?>
                                <div>
                                    <h6 class="mb-1"><?php echo $recent_post['title']; ?></h6>
                                    <span class="text-muted small"><?php echo format_blog_date($recent_post['published_at']); ?></span>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include 'includes/footer.php';
?> 