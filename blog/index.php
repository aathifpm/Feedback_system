<?php
// Include functions
require_once 'functions.php';

// Add cache control headers to prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// Set page variables
$page_title = 'Home';
$show_header = true;

// Get featured posts
$featured_posts = get_featured_posts(3);

// Get recent posts
$recent_posts = get_recent_posts(6);

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8">
            <!-- Featured Posts -->
            <?php if (!empty($featured_posts)): ?>
            <section class="featured-posts mb-5">
                <h2 class="section-title mb-4">Featured Posts</h2>
                <div id="featuredCarousel" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-indicators">
                        <?php foreach ($featured_posts as $index => $post): ?>
                            <button type="button" data-bs-target="#featuredCarousel" data-bs-slide-to="<?php echo $index; ?>" <?php echo $index === 0 ? 'class="active" aria-current="true"' : ''; ?> aria-label="Slide <?php echo $index + 1; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <div class="carousel-inner">
                        <?php foreach ($featured_posts as $index => $post): ?>
                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                <div class="featured-post">
                                    <?php if ($post['featured_image']): ?>
                                        <img src="<?php echo $post['featured_image']; ?>" class="post-img" alt="<?php echo $post['title']; ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="post-img d-flex align-items-center justify-content-center position-relative" style="background: linear-gradient(135deg, #f8f9fa 25%, #e9ecef 25%, #e9ecef 50%, #f8f9fa 50%, #f8f9fa 75%, #e9ecef 75%, #e9ecef 100%); background-size: 20px 20px;">
                                            <div class="text-center bg-white py-3 px-4 rounded shadow-sm" style="position: relative; z-index: 1;">
                                                <i class="fas fa-camera text-primary mb-2" style="font-size: 2.5rem;"></i>
                                                <h5 class="mb-0 text-truncate px-2" style="max-width: 200px;"><?php echo htmlspecialchars($post['title']); ?></h5>
                                            </div>
                                            <div class="position-absolute" style="top: 10px; right: 10px; background: rgba(255,255,255,0.8); padding: 5px 10px; border-radius: 20px; font-size: 0.8rem;">
                                                <i class="fas fa-calendar-alt text-primary"></i> <?php echo format_blog_date($post['published_at']); ?>
                                            </div>
                                        </div>
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
                    <button class="carousel-control-prev" type="button" data-bs-target="#featuredCarousel" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#featuredCarousel" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
            </section>
            <?php endif; ?>

            <!-- Recent Posts -->
            <section class="recent-posts">
                <h2 class="section-title mb-4">Recent Posts</h2>
                <div class="row">
                    <?php if (empty($recent_posts)): ?>
                        <div class="col-12">
                            <div class="alert alert-info">No posts found. Check back soon for updates!</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_posts as $post): ?>
                            <div class="col-md-6 mb-4">
                                <div class="post-card h-100">
                                    <?php if ($post['featured_image']): ?>
                                        <img src="<?php echo $post['featured_image']; ?>" class="post-img" alt="<?php echo $post['title']; ?>" loading="lazy">
                                    <?php else: ?>
                                        <div class="post-img d-flex align-items-center justify-content-center position-relative" style="background: linear-gradient(135deg, #f8f9fa 25%, #e9ecef 25%, #e9ecef 50%, #f8f9fa 50%, #f8f9fa 75%, #e9ecef 75%, #e9ecef 100%); background-size: 20px 20px;">
                                            <div class="text-center bg-white py-3 px-4 rounded shadow-sm" style="position: relative; z-index: 1;">
                                                <i class="fas fa-camera text-primary mb-2" style="font-size: 2.5rem;"></i>
                                                <h5 class="mb-0 text-truncate px-2" style="max-width: 200px;"><?php echo htmlspecialchars($post['title']); ?></h5>
                                            </div>
                                            <div class="position-absolute" style="top: 10px; right: 10px; background: rgba(255,255,255,0.8); padding: 5px 10px; border-radius: 20px; font-size: 0.8rem;">
                                                <i class="fas fa-calendar-alt text-primary"></i> <?php echo format_blog_date($post['published_at']); ?>
                                            </div>
                                        </div>
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
                    <?php endif; ?>
                </div>
                
                <div class="text-center mt-4">
                    <a href="archive.php" class="btn btn-primary btn-lg">View All Posts</a>
                </div>
            </section>
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
                <h4 class="sidebar-title">About Blog</h4>
                <p>Welcome to the official blog of Panimalar Engineering College. Here we share the latest news, events, and insights from our vibrant campus community.</p>
                <a href="about.php" class="btn btn-outline-primary">Learn More</a>
            </div>
            
            <div class="sidebar-section">
                <h4 class="sidebar-title">Subscribe</h4>
                <p>Get the latest posts delivered straight to your inbox.</p>
                <form class="mt-3">
                    <div class="mb-3">
                        <input type="email" class="form-control" placeholder="Your Email" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Subscribe</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Include footer
include 'includes/footer.php';
?> 