<?php
// Start the session
session_start();

// Add cache control headers to prevent browser caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Past date to encourage expiration

// Include functions
require_once 'functions.php';

// Check if the slug is provided
if (!isset($_GET['slug'])) {
    header('Location: index.php');
    exit;
}

$slug = $_GET['slug'];

// Initialize session variable for tracking viewed posts if it doesn't exist
if (!isset($_SESSION['viewed_posts'])) {
    $_SESSION['viewed_posts'] = array();
}

// Check if this post has already been viewed in current session
$is_new_view = !in_array($slug, $_SESSION['viewed_posts']);

// Add the post to viewed posts array if it's a new view
if ($is_new_view) {
    $_SESSION['viewed_posts'][] = $slug;
}

// Get the post by slug with a parameter to control view counting
$post = get_post_by_slug($slug, $is_new_view);

// If post not found, redirect to home
if (!$post) {
    header('Location: index.php');
    exit;
}

// Set page variables
$page_title = $post['title'];
$show_header = false;

// Add extra CSS for post page
$extra_css = "
    @media (max-width: 767px) {
        .post-title {
            font-size: 1.75rem;
        }
        .post-meta {
            font-size: 0.85rem;
            flex-wrap: wrap;
        }
        .post-meta span {
            margin-bottom: 5px;
        }
        .post-content img {
            max-width: 100%;
            height: auto;
        }
        .social-links .btn {
            margin-bottom: 8px;
        }
        .comment .d-flex {
            flex-direction: column;
        }
        .comment-avatar {
            margin-bottom: 10px;
        }
        .replies {
            margin-left: 15px !important;
        }
    }
    
    /* Lazy loading image styles */
    .post-content img {
        transition: opacity 0.3s ease, filter 0.5s ease;
    }
    
    .post-content img[loading] {
        opacity: 0.5;
        filter: blur(5px);
    }
    
    .post-content img.loaded {
        opacity: 1;
        filter: blur(0);
    }
    
    /* Placeholder for images while loading */
    .post-featured-image {
        position: relative;
        background-color: #f8f9fa;
        min-height: 200px;
    }
    
    .post-featured-image::before {
        content: 'Loading...';
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: #6c757d;
        font-size: 0.9rem;
    }
";

// Handle comment submission
$comment_message = '';
$comment_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_submit'])) {
    $author_name = blog_sanitize_input($_POST['author_name']);
    $author_email = blog_sanitize_input($_POST['author_email']);
    $content = blog_sanitize_input($_POST['content']);
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    // Basic validation
    if (empty($author_name) || empty($author_email) || empty($content)) {
        $comment_error = 'All fields are required.';
    } elseif (!filter_var($author_email, FILTER_VALIDATE_EMAIL)) {
        $comment_error = 'Please enter a valid email.';
    } else {
        // Add the comment
        $comment_id = add_comment($post['id'], $author_name, $author_email, $content, $parent_id);
        
        if ($comment_id) {
            $comment_message = 'Your comment has been submitted for review.';
            // Clear the form
            $_POST = array();
        } else {
            $comment_error = 'Failed to submit your comment. Please try again.';
        }
    }
}

// Get post comments
$comments = get_post_comments($post['id']);

// Include header
include 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-lg-8">
            <!-- Single Post Content -->
            <article class="single-post">
                <div class="post-header mb-4">
                    <h1 class="post-title"><?php echo $post['title']; ?></h1>
                    <div class="post-meta d-flex flex-wrap">
                        <span class="me-2 mb-2"><i class="far fa-user me-1"></i> <?php echo $post['first_name'] . ' ' . $post['last_name']; ?></span>
                        <span class="mx-2 mb-2 d-none d-sm-inline">|</span>
                        <span class="me-2 mb-2"><i class="far fa-calendar me-1"></i> <?php echo format_blog_date($post['published_at']); ?></span>
                        <span class="mx-2 mb-2 d-none d-sm-inline">|</span>
                        <span class="mb-2"><i class="far fa-eye me-1"></i> <?php echo $post['view_count']; ?> views</span>
                    </div>
                </div>
                
                <?php if ($post['featured_image']): ?>
                <div class="post-featured-image mb-4">
                    <img src="<?php echo $post['featured_image']; ?>" class="img-fluid rounded w-100" alt="<?php echo $post['title']; ?>" loading="lazy">
                </div>
                <?php endif; ?>
                
                <div class="post-content mb-5">
                    <?php echo $post['content']; ?>
                </div>
                
                <!-- Social Share -->
                <div class="post-share mb-5">
                    <h5><i class="fas fa-share-alt me-2"></i> Share this post</h5>
                    <div class="social-links d-flex flex-wrap">
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" class="btn btn-primary btn-sm me-2 mb-2" target="_blank"><i class="fab fa-facebook-f"></i> Facebook</a>
                        <a href="https://twitter.com/intent/tweet?url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>&text=<?php echo urlencode($post['title']); ?>" class="btn btn-info btn-sm me-2 mb-2" target="_blank"><i class="fab fa-twitter"></i> Twitter</a>
                        <a href="https://www.linkedin.com/shareArticle?mini=true&url=<?php echo urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" class="btn btn-secondary btn-sm me-2 mb-2" target="_blank"><i class="fab fa-linkedin-in"></i> LinkedIn</a>
                        <a href="mailto:?subject=<?php echo urlencode($post['title']); ?>&body=<?php echo urlencode('Check out this post: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']); ?>" class="btn btn-danger btn-sm mb-2" target="_blank"><i class="far fa-envelope"></i> Email</a>
                    </div>
                </div>
                
                <!-- Author Bio -->
                <div class="author-bio bg-light p-3 p-md-4 rounded mb-5">
                    <div class="row align-items-center">
                        <div class="col-sm-2 col-12 text-center mb-3 mb-sm-0">
                            <?php if ($post['profile_image']): ?>
                                <img src="<?php echo $post['profile_image']; ?>" class="img-fluid rounded-circle mx-auto" alt="<?php echo $post['first_name'] . ' ' . $post['last_name']; ?>" style="width: 80px; height: 80px; object-fit: cover;">
                            <?php else: ?>
                                <div class="author-avatar rounded-circle bg-primary d-flex align-items-center justify-content-center text-white mx-auto" style="width: 80px; height: 80px; font-size: 2.5rem;">
                                    <?php echo strtoupper(substr($post['first_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-sm-10 col-12">
                            <h5 class="mb-2"><?php echo $post['first_name'] . ' ' . $post['last_name']; ?></h5>
                            <p class="mb-2"><strong>Author</strong></p>
                            <p class="mb-0">Content author for Panimalar Engineering College Blog</p>
                        </div>
                    </div>
                </div>
                
                <!-- Comments Section -->
                <div class="comments-section" id="comments">
                    <h3 class="mb-4"><?php echo count($comments); ?> Comments</h3>
                    
                    <?php if ($comment_message): ?>
                        <div class="alert alert-success mb-4"><?php echo $comment_message; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($comment_error): ?>
                        <div class="alert alert-danger mb-4"><?php echo $comment_error; ?></div>
                    <?php endif; ?>
                    
                    <!-- Comments List -->
                    <?php if (empty($comments)): ?>
                        <p>No comments yet. Be the first to comment!</p>
                    <?php else: ?>
                        <div class="comments-list mb-5">
                            <?php foreach ($comments as $comment): ?>
                                <div class="comment bg-light p-3 rounded mb-3" id="comment-<?php echo $comment['id']; ?>">
                                    <div class="d-md-flex">
                                        <div class="comment-avatar me-md-3 mb-3 mb-md-0 text-center text-md-start">
                                            <div class="avatar-placeholder rounded-circle bg-primary d-flex align-items-center justify-content-center text-white mx-auto mx-md-0" style="width: 50px; height: 50px;">
                                                <?php echo strtoupper(substr($comment['author_name'], 0, 1)); ?>
                                            </div>
                                        </div>
                                        <div class="comment-content">
                                            <h5 class="mb-1"><?php echo $comment['author_name']; ?></h5>
                                            <p class="comment-date text-muted small mb-2">
                                                <i class="far fa-clock me-1"></i> <?php echo format_blog_date($comment['created_at']); ?>
                                            </p>
                                            <div class="comment-text mb-2">
                                                <?php echo nl2br($comment['content']); ?>
                                            </div>
                                            <button class="btn btn-sm btn-outline-primary reply-btn" data-parent-id="<?php echo $comment['id']; ?>">Reply</button>
                                        </div>
                                    </div>
                                    
                                    <!-- Replies -->
                                    <?php if (!empty($comment['replies'])): ?>
                                        <div class="replies ms-md-5 ms-3 mt-3">
                                            <?php foreach ($comment['replies'] as $reply): ?>
                                                <div class="comment bg-white p-3 rounded mb-2" id="comment-<?php echo $reply['id']; ?>">
                                                    <div class="d-md-flex">
                                                        <div class="comment-avatar me-md-3 mb-3 mb-md-0 text-center text-md-start">
                                                            <div class="avatar-placeholder rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white mx-auto mx-md-0" style="width: 40px; height: 40px;">
                                                                <?php echo strtoupper(substr($reply['author_name'], 0, 1)); ?>
                                                            </div>
                                                        </div>
                                                        <div class="comment-content">
                                                            <h6 class="mb-1"><?php echo $reply['author_name']; ?></h6>
                                                            <p class="comment-date text-muted small mb-2">
                                                                <i class="far fa-clock me-1"></i> <?php echo format_blog_date($reply['created_at']); ?>
                                                            </p>
                                                            <div class="comment-text">
                                                                <?php echo nl2br($reply['content']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Reply Form (Hidden by default) -->
                                    <div class="reply-form mt-3 ms-md-5 ms-3 d-none" id="reply-form-<?php echo $comment['id']; ?>">
                                        <form method="post" action="#comment-<?php echo $comment['id']; ?>">
                                            <input type="hidden" name="parent_id" value="<?php echo $comment['id']; ?>">
                                            <div class="mb-3">
                                                <label for="reply-name-<?php echo $comment['id']; ?>" class="form-label">Name *</label>
                                                <input type="text" class="form-control" id="reply-name-<?php echo $comment['id']; ?>" name="author_name" required>
                                            </div>
                                            <div class="mb-3">
                                                <label for="reply-email-<?php echo $comment['id']; ?>" class="form-label">Email *</label>
                                                <input type="email" class="form-control" id="reply-email-<?php echo $comment['id']; ?>" name="author_email" required>
                                            </div>
                                            <div class="mb-3">
                                                <label for="reply-content-<?php echo $comment['id']; ?>" class="form-label">Reply *</label>
                                                <textarea class="form-control" id="reply-content-<?php echo $comment['id']; ?>" name="content" rows="3" required></textarea>
                                            </div>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="submit" name="comment_submit" class="btn btn-primary">Submit Reply</button>
                                                <button type="button" class="btn btn-light cancel-reply">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Comment Form -->
                    <div class="comment-form">
                        <h4 class="mb-4">Leave a Comment</h4>
                        <form method="post" action="#comments">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="author_name" class="form-label">Name *</label>
                                    <input type="text" class="form-control" id="author_name" name="author_name" value="<?php echo isset($_POST['author_name']) ? $_POST['author_name'] : ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="author_email" class="form-label">Email *</label>
                                    <input type="email" class="form-control" id="author_email" name="author_email" value="<?php echo isset($_POST['author_email']) ? $_POST['author_email'] : ''; ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="content" class="form-label">Comment *</label>
                                <textarea class="form-control" id="content" name="content" rows="5" required><?php echo isset($_POST['content']) ? $_POST['content'] : ''; ?></textarea>
                            </div>
                            <button type="submit" name="comment_submit" class="btn btn-primary">Submit Comment</button>
                        </form>
                    </div>
                </div>
            </article>
        </div>
        
        <!-- Sidebar -->
        <div class="col-lg-4 mt-4 mt-lg-0">
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
                                    <img src="<?php echo $recent_post['featured_image']; ?>" class="me-3 rounded" alt="<?php echo $recent_post['title']; ?>" style="width: 70px; height: 50px; object-fit: cover;" loading="lazy">
                                <?php else: ?>
                                    <div class="me-3 bg-light rounded d-flex align-items-center justify-content-center" style="width: 70px; height: 50px; color: #6c757d;">
                                        <i class="fas fa-newspaper" style="font-size: 1.5rem;"></i>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h6 class="mb-1 text-truncate" style="max-width: 200px;"><?php echo $recent_post['title']; ?></h6>
                                    <span class="text-muted small"><?php echo format_blog_date($recent_post['published_at']); ?></span>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            
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
        </div>
    </div>
</div>

<?php
$extra_js = "
    // Check if jQuery is loaded, if not, use vanilla JS
    if (typeof jQuery === 'undefined') {
        // Vanilla JS version
        document.addEventListener('DOMContentLoaded', function() {
            // Reply button click handler
            document.querySelectorAll('.reply-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var parentId = this.getAttribute('data-parent-id');
                    document.getElementById('reply-form-' + parentId).classList.remove('d-none');
                });
            });
            
            // Cancel reply button click handler
            document.querySelectorAll('.cancel-reply').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    this.closest('.reply-form').classList.add('d-none');
                });
            });
            
            // Apply lazy loading to all content images
            document.querySelectorAll('.post-content img').forEach(function(img) {
                if (!img.hasAttribute('loading')) {
                    img.setAttribute('loading', 'lazy');
                }
                
                // Add blur-up loading effect
                img.style.transition = 'filter 0.5s';
                img.style.filter = 'blur(5px)';
                img.onload = function() {
                    this.style.filter = 'blur(0)';
                };
                
                // Handle broken images
                img.onerror = function() {
                    this.style.display = 'none';
                };
            });
            
            // Apply lazy loading to sidebar images
            document.querySelectorAll('.sidebar-section img').forEach(function(img) {
                img.setAttribute('loading', 'lazy');
            });
            
            // Make videos responsive
            document.querySelectorAll('.post-content iframe').forEach(function(iframe) {
                if (!iframe.parentElement.classList.contains('video-container')) {
                    var wrapper = document.createElement('div');
                    wrapper.className = 'video-container';
                    iframe.parentNode.insertBefore(wrapper, iframe);
                    wrapper.appendChild(iframe);
                }
            });
            
            // Make tables mobile-friendly
            function makeTablesResponsive() {
                document.querySelectorAll('.post-content table').forEach(function(table) {
                    // Add mobile-cards class for the card-based layout
                    if (window.innerWidth <= 767.98) {
                        table.classList.add('mobile-cards');
                        
                        // Process table structure for mobile
                        var headers = Array.from(table.querySelectorAll('th')).map(function(th) {
                            return th.textContent.trim();
                        });
                        
                        // If no headers found, try using first row as header
                        if (headers.length === 0) {
                            var firstRow = table.querySelector('tr');
                            if (firstRow) {
                                headers = Array.from(firstRow.querySelectorAll('td')).map(function(td) {
                                    return td.textContent.trim();
                                });
                                // Hide the first row since we're using it as headers
                                firstRow.style.display = 'none';
                            }
                        }
                        
                        if (headers.length > 0) {
                            // Apply data-label to all cells
                            table.querySelectorAll('tbody tr').forEach(function(row) {
                                row.querySelectorAll('td').forEach(function(cell, index) {
                                    if (headers[index]) {
                                        cell.setAttribute('data-label', headers[index]);
                                    }
                                });
                            });
                        }
                    } else {
                        table.classList.remove('mobile-cards');
                    }
                });
            }
            
            // Run initially
            makeTablesResponsive();
            
            // Rerun on resize
            window.addEventListener('resize', makeTablesResponsive);
        });
    } else {
        // jQuery version
        $(document).ready(function() {
            // Toggle reply form
            $('.reply-btn').click(function() {
                var parentId = $(this).data('parent-id');
                $('#reply-form-' + parentId).removeClass('d-none');
            });
            
            // Cancel reply
            $('.cancel-reply').click(function() {
                $(this).closest('.reply-form').addClass('d-none');
            });
            
            // Apply lazy loading to all content images
            $('.post-content img').each(function() {
                if (!$(this).attr('loading')) {
                    $(this).attr('loading', 'lazy');
                }
                
                // Add blur-up loading effect
                $(this).css({
                    'transition': 'filter 0.5s',
                    'filter': 'blur(5px)'
                });
                
                $(this).on('load', function() {
                    $(this).css('filter', 'blur(0)');
                });
                
                // Handle broken images
                $(this).on('error', function() {
                    $(this).hide();
                });
            });
            
            // Apply lazy loading to sidebar images
            $('.sidebar-section img').attr('loading', 'lazy');
            
            // Make sure videos are responsive
            $('.post-content iframe').each(function(){
                if (!$(this).parent().hasClass('video-container')) {
                    $(this).wrap('<div class=\"video-container\"></div>');
                }
            });
            
            // Make tables mobile-friendly
            function makeTablesResponsive() {
                $('.post-content table').each(function() {
                    // Add mobile-cards class for the card-based layout
                    if ($(window).width() <= 767.98) {
                        $(this).addClass('mobile-cards');
                        
                        // Process table structure for mobile
                        var headers = $(this).find('th').map(function() {
                            return $(this).text().trim();
                        }).get();
                        
                        // If no headers found, try using first row as header
                        if (headers.length === 0) {
                            var firstRow = $(this).find('tr:first');
                            if (firstRow.length) {
                                headers = firstRow.find('td').map(function() {
                                    return $(this).text().trim();
                                }).get();
                                // Hide the first row since we're using it as headers
                                firstRow.hide();
                            }
                        }
                        
                        if (headers.length > 0) {
                            // Apply data-label to all cells
                            $(this).find('tbody tr').each(function() {
                                $(this).find('td').each(function(index) {
                                    if (headers[index]) {
                                        $(this).attr('data-label', headers[index]);
                                    }
                                });
                            });
                        }
                    } else {
                        $(this).removeClass('mobile-cards');
                    }
                });
            }
            
            // Run initially
            makeTablesResponsive();
            
            // Rerun on resize
            $(window).resize(makeTablesResponsive);
        });
    }
";

// Include footer
include 'includes/footer.php';
?> 