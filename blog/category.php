<?php
// Include functions
require_once 'functions.php';

// Check if category slug is provided
if (!isset($_GET['slug'])) {
    header('Location: index.php');
    exit;
}

$category_slug = $_GET['slug'];

// Get current page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get posts by category
$posts = get_posts_by_category($category_slug, $per_page, $offset);

// If no posts found and it's not page 1, redirect to page 1
if (empty($posts) && $page > 1) {
    header('Location: category.php?slug=' . $category_slug);
    exit;
}

// Set page variables
$page_title = 'Category';
$show_header = false;

// Get category details
global $blog_conn;
$category_slug = mysqli_real_escape_string($blog_conn, $category_slug);
$query = "SELECT * FROM blog_categories WHERE slug = ?";
$stmt = mysqli_prepare($blog_conn, $query);
mysqli_stmt_bind_param($stmt, "s", $category_slug);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$category = mysqli_fetch_assoc($result);

if ($category) {
    $page_title = $category['name'];
}

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8">
            <!-- Category Header -->
            <div class="category-header mb-4">
                <h1 class="category-title"><?php echo $category ? $category['name'] : 'Category Not Found'; ?></h1>
                <?php if ($category && !empty($category['description'])): ?>
                    <p class="lead"><?php echo $category['description']; ?></p>
                <?php endif; ?>
                <hr>
            </div>
            
            <!-- Category Posts -->
            <?php if (empty($posts)): ?>
                <div class="alert alert-info">No posts found in this category.</div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($posts as $post): ?>
                        <div class="col-md-6 mb-4">
                            <div class="post-card h-100">
                                <?php if ($post['featured_image']): ?>
                                    <img src="uploads/<?php echo $post['featured_image']; ?>" class="post-img" alt="<?php echo $post['title']; ?>">
                                <?php else: ?>
                                    <img src="https://via.placeholder.com/600x300?text=Blog+Post" class="post-img" alt="<?php echo $post['title']; ?>">
                                <?php endif; ?>
                                <div class="post-content">
                                    <h3 class="post-title"><?php echo $post['title']; ?></h3>
                                    <p class="post-excerpt"><?php echo isset($post['excerpt']) ? $post['excerpt'] : get_excerpt($post['content']); ?></p>
                                    <div class="post-meta mb-3">
                                        <span><i class="far fa-user me-1"></i> <?php echo $post['first_name'] . ' ' . $post['last_name']; ?></span>
                                        <span class="mx-2">|</span>
                                        <span><i class="far fa-calendar me-1"></i> <?php echo format_blog_date($post['published_at']); ?></span>
                                    </div>
                                    <a href="post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-read-more">Read More</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php
                // Get total posts count for pagination
                $count_query = "SELECT COUNT(*) as total FROM blog_posts p 
                                JOIN blog_post_categories pc ON p.id = pc.post_id 
                                JOIN blog_categories c ON pc.category_id = c.id 
                                WHERE c.slug = ? AND p.status = 'published'";
                $count_stmt = mysqli_prepare($blog_conn, $count_query);
                mysqli_stmt_bind_param($count_stmt, "s", $category_slug);
                mysqli_stmt_execute($count_stmt);
                $count_result = mysqli_stmt_get_result($count_stmt);
                $count_row = mysqli_fetch_assoc($count_result);
                $total_posts = $count_row['total'];
                
                $total_pages = ceil($total_posts / $per_page);
                
                if ($total_pages > 1):
                ?>
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <!-- Previous Page Link -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="category.php?slug=<?php echo $category_slug; ?>&page=<?php echo $page - 1; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link" aria-hidden="true">&laquo;</span>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($start_page + 4, $total_pages);
                        
                        if ($end_page - $start_page < 4 && $start_page > 1) {
                            $start_page = max(1, $end_page - 4);
                        }
                        
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="category.php?slug=<?php echo $category_slug; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page Link -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="category.php?slug=<?php echo $category_slug; ?>&page=<?php echo $page + 1; ?>" aria-label="Next">
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
            <div class="sidebar-section">
                <h4 class="sidebar-title">Categories</h4>
                <ul class="category-list">
                    <?php
                    $categories = get_categories();
                    foreach ($categories as $cat) {
                        $active_class = ($cat['slug'] === $category_slug) ? 'fw-bold text-primary' : '';
                        echo '<li><a href="category.php?slug=' . $cat['slug'] . '" class="' . $active_class . '">' . $cat['name'] . '</a></li>';
                    }
                    ?>
                </ul>
            </div>
            
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
                                    <img src="uploads/<?php echo $recent_post['featured_image']; ?>" class="me-3" alt="<?php echo $recent_post['title']; ?>" style="width: 70px; height: 50px; object-fit: cover;">
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
            
            <div class="sidebar-section">
                <h4 class="sidebar-title">About Blog</h4>
                <p>Welcome to the official blog of Panimalar Engineering College. Here we share the latest news, events, and insights from our vibrant campus community.</p>
                <a href="about.php" class="btn btn-outline-primary">Learn More</a>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include 'includes/footer.php';
?> 