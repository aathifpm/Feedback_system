<?php
// Include functions
require_once 'functions.php';

// Get search query
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';

// Set page variables
$page_title = 'Search Results';
$show_header = false;

// Handle pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get search results
$search_results = [];
if (!empty($search_term)) {
    $search_results = search_posts($search_term, $per_page, $offset);
}

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8">
            <!-- Search Header -->
            <div class="search-header mb-4">
                <h1 class="search-title">Search Results</h1>
                <p class="lead">
                    <?php if (empty($search_term)): ?>
                        Please enter a search term.
                    <?php else: ?>
                        Results for: <strong><?php echo htmlspecialchars($search_term); ?></strong>
                    <?php endif; ?>
                </p>
                <hr>
            </div>
            
            <!-- Search Form -->
            <div class="search-form mb-4">
                <form action="search.php" method="get" class="d-flex">
                    <input type="text" name="q" class="form-control me-2" placeholder="Search blog..." value="<?php echo htmlspecialchars($search_term); ?>" required>
                    <button type="submit" class="btn btn-primary">Search</button>
                </form>
            </div>
            
            <!-- Search Results -->
            <?php if (empty($search_term)): ?>
                <div class="alert alert-info">Please enter a search term to find blog posts.</div>
            <?php elseif (empty($search_results)): ?>
                <div class="alert alert-warning">No posts found matching your search criteria.</div>
            <?php else: ?>
                <div class="search-results">
                    <p class="mb-4"><?php echo count($search_results); ?> posts found.</p>
                    
                    <?php foreach ($search_results as $post): ?>
                        <div class="post-card mb-4">
                            <div class="row g-0">
                                <div class="col-md-4">
                                    <?php if ($post['featured_image']): ?>
                                        <img src="<?php echo $post['featured_image']; ?>" class="img-fluid rounded-start h-100 object-fit-cover" alt="<?php echo $post['title']; ?>">
                                    <?php else: ?>
                                        <img src="https://via.placeholder.com/300x200?text=Blog+Post" class="img-fluid rounded-start" alt="<?php echo $post['title']; ?>">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-8">
                                    <div class="post-content h-100 d-flex flex-column p-4">
                                        <h3 class="post-title"><?php echo $post['title']; ?></h3>
                                        <p class="post-excerpt flex-grow-1"><?php echo isset($post['excerpt']) ? $post['excerpt'] : get_excerpt($post['content']); ?></p>
                                        <div class="post-meta mb-3">
                                            <span><i class="far fa-user me-1"></i> <?php echo $post['first_name'] . ' ' . $post['last_name']; ?></span>
                                            <span class="mx-2">|</span>
                                            <span><i class="far fa-calendar me-1"></i> <?php echo format_blog_date($post['published_at']); ?></span>
                                        </div>
                                        <a href="post.php?slug=<?php echo $post['slug']; ?>" class="btn btn-read-more align-self-start">Read More</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php
                // Get total search results count for pagination
                global $blog_conn;
                $search_count_term = mysqli_real_escape_string($blog_conn, "%$search_term%");
                $count_query = "SELECT COUNT(*) as total FROM blog_posts p 
                               JOIN blog_users u ON p.author_id = u.id 
                               WHERE (p.title LIKE ? OR p.content LIKE ? OR p.excerpt LIKE ?) 
                               AND p.status = 'published'";
                $count_stmt = mysqli_prepare($blog_conn, $count_query);
                mysqli_stmt_bind_param($count_stmt, "sss", $search_count_term, $search_count_term, $search_count_term);
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
                                <a class="page-link" href="search.php?q=<?php echo urlencode($search_term); ?>&page=<?php echo $page - 1; ?>" aria-label="Previous">
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
                                <a class="page-link" href="search.php?q=<?php echo urlencode($search_term); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page Link -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="search.php?q=<?php echo urlencode($search_term); ?>&page=<?php echo $page + 1; ?>" aria-label="Next">
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
                    foreach ($categories as $category) {
                        echo '<li><a href="category.php?slug=' . $category['slug'] . '">' . $category['name'] . '</a></li>';
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