<?php
require_once 'db_connection.php';

// Blog Functions

// Sanitize input data
function blog_sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Generate a unique slug
function generate_slug($text) {
    global $blog_conn;
    
    // Sanitize the text
    $slug = strtolower($text);
    $slug = preg_replace('/[^a-z0-9\s]/', '', $slug);
    $slug = preg_replace('/\s+/', '-', $slug);
    $slug = trim($slug, '-');
    
    // Check if slug exists
    $base_slug = $slug;
    $counter = 1;
    
    // Keep checking until we find a unique slug
    while (true) {
        $check_query = "SELECT COUNT(*) as count FROM blog_posts WHERE slug = '$slug'";
        $result = mysqli_query($blog_conn, $check_query);
        $row = mysqli_fetch_assoc($result);
        
        if ($row['count'] == 0) {
            break;
        }
        
        $slug = $base_slug . '-' . $counter;
        $counter++;
    }
    
    return $slug;
}

/**
 * Creates a URL-friendly slug from a string
 * 
 * @param string $text The text to convert to a slug
 * @return string The URL-friendly slug
 */
function create_slug($text) {
    // Convert the text to lowercase
    $text = strtolower($text);
    
    // Replace non-alphanumeric characters with a dash
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    
    // Replace multiple dashes with a single dash
    $text = preg_replace('/-+/', '-', $text);
    
    // Remove dashes from the beginning and end
    $text = trim($text, '-');
    
    // If the slug is empty, use 'untitled'
    if (empty($text)) {
        return 'untitled';
    }
    
    // Check if slug already exists
    global $blog_conn;
    $base_slug = $text;
    $counter = 1;
    
    while (true) {
        $query = "SELECT id FROM blog_posts WHERE slug = ?";
        $stmt = mysqli_prepare($blog_conn, $query);
        mysqli_stmt_bind_param($stmt, "s", $text);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        
        if (mysqli_stmt_num_rows($stmt) === 0) {
            break;
        }
        
        $text = $base_slug . '-' . $counter;
        $counter++;
    }
    
    return $text;
}

// Get post by ID
function get_post($post_id) {
    global $blog_conn;
    
    $post_id = mysqli_real_escape_string($blog_conn, $post_id);
    $query = "SELECT p.*, u.username, u.first_name, u.last_name, u.profile_image 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              WHERE p.id = ? 
              AND p.status = 'published'";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $post_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($post = mysqli_fetch_assoc($result)) {
        return $post;
    }
    
    return null;
}

// Get post by slug
function get_post_by_slug($slug, $increment_view = true) {
    global $blog_conn;
    
    $slug = mysqli_real_escape_string($blog_conn, $slug);
    $query = "SELECT p.*, u.username, u.first_name, u.last_name, u.profile_image 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              WHERE p.slug = ? 
              AND p.status = 'published'";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $slug);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($post = mysqli_fetch_assoc($result)) {
        // Update view count only if increment_view is true
        if ($increment_view) {
            $post_id = $post['id'];
            $update_query = "UPDATE blog_posts SET view_count = view_count + 1 WHERE id = ?";
            $update_stmt = mysqli_prepare($blog_conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $post_id);
            mysqli_stmt_execute($update_stmt);
        }
        
        return $post;
    }
    
    return null;
}

// Get recent posts
function get_recent_posts($limit = 5) {
    global $blog_conn;
    
    $limit = intval($limit);
    $query = "SELECT p.*, u.username, u.first_name, u.last_name 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              WHERE p.status = 'published' 
              ORDER BY p.published_at DESC 
              LIMIT ?";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $posts = [];
    while ($post = mysqli_fetch_assoc($result)) {
        $posts[] = $post;
    }
    
    return $posts;
}

// Get featured posts
function get_featured_posts($limit = 3) {
    global $blog_conn;
    
    $limit = intval($limit);
    $query = "SELECT p.*, u.username, u.first_name, u.last_name 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              WHERE p.status = 'published' AND p.is_featured = TRUE
              ORDER BY p.published_at DESC 
              LIMIT ?";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $posts = [];
    while ($post = mysqli_fetch_assoc($result)) {
        $posts[] = $post;
    }
    
    return $posts;
}

// Get posts by category
function get_posts_by_category($category_slug, $limit = 10, $offset = 0) {
    global $blog_conn;
    
    $category_slug = mysqli_real_escape_string($blog_conn, $category_slug);
    $limit = intval($limit);
    $offset = intval($offset);
    
    $query = "SELECT p.*, u.username, u.first_name, u.last_name 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              JOIN blog_post_categories pc ON p.id = pc.post_id 
              JOIN blog_categories c ON pc.category_id = c.id 
              WHERE c.slug = ? AND p.status = 'published' 
              ORDER BY p.published_at DESC 
              LIMIT ? OFFSET ?";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "sii", $category_slug, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $posts = [];
    while ($post = mysqli_fetch_assoc($result)) {
        $posts[] = $post;
    }
    
    return $posts;
}

// Get all categories
function get_categories() {
    global $blog_conn;
    
    $query = "SELECT * FROM blog_categories ORDER BY name";
    $result = mysqli_query($blog_conn, $query);
    
    $categories = [];
    while ($category = mysqli_fetch_assoc($result)) {
        $categories[] = $category;
    }
    
    return $categories;
}

// Get post comments
function get_post_comments($post_id) {
    global $blog_conn;
    
    $post_id = mysqli_real_escape_string($blog_conn, $post_id);
    
    $query = "SELECT * FROM blog_comments 
              WHERE post_id = ? AND status = 'approved' AND parent_id IS NULL
              ORDER BY created_at";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $post_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $comments = [];
    while ($comment = mysqli_fetch_assoc($result)) {
        // Get replies
        $comment['replies'] = get_comment_replies($comment['id']);
        $comments[] = $comment;
    }
    
    return $comments;
}

// Get comment replies
function get_comment_replies($comment_id) {
    global $blog_conn;
    
    $comment_id = mysqli_real_escape_string($blog_conn, $comment_id);
    
    $query = "SELECT * FROM blog_comments 
              WHERE parent_id = ? AND status = 'approved'
              ORDER BY created_at";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $comment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $replies = [];
    while ($reply = mysqli_fetch_assoc($result)) {
        $replies[] = $reply;
    }
    
    return $replies;
}

// Add comment
function add_comment($post_id, $author_name, $author_email, $content, $parent_id = null) {
    global $blog_conn;
    
    $post_id = mysqli_real_escape_string($blog_conn, $post_id);
    $author_name = mysqli_real_escape_string($blog_conn, $author_name);
    $author_email = mysqli_real_escape_string($blog_conn, $author_email);
    $content = mysqli_real_escape_string($blog_conn, $content);
    $author_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if ($parent_id) {
        $parent_id = mysqli_real_escape_string($blog_conn, $parent_id);
        $query = "INSERT INTO blog_comments (post_id, parent_id, author_name, author_email, content, author_ip, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($blog_conn, $query);
        mysqli_stmt_bind_param($stmt, "iisssss", $post_id, $parent_id, $author_name, $author_email, $content, $author_ip, $user_agent);
    } else {
        $query = "INSERT INTO blog_comments (post_id, author_name, author_email, content, author_ip, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?)";
        
        $stmt = mysqli_prepare($blog_conn, $query);
        mysqli_stmt_bind_param($stmt, "isssss", $post_id, $author_name, $author_email, $content, $author_ip, $user_agent);
    }
    
    if (mysqli_stmt_execute($stmt)) {
        return mysqli_insert_id($blog_conn);
    }
    
    return false;
}

// Search posts
function search_posts($search_term, $limit = 10, $offset = 0) {
    global $blog_conn;
    
    $search_term = mysqli_real_escape_string($blog_conn, $search_term);
    $limit = intval($limit);
    $offset = intval($offset);
    
    $search = "%$search_term%";
    
    $query = "SELECT p.*, u.username, u.first_name, u.last_name 
              FROM blog_posts p 
              JOIN blog_users u ON p.author_id = u.id 
              WHERE (p.title LIKE ? OR p.content LIKE ? OR p.excerpt LIKE ?) 
              AND p.status = 'published' 
              ORDER BY p.published_at DESC 
              LIMIT ? OFFSET ?";
    
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "sssii", $search, $search, $search, $limit, $offset);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $posts = [];
    while ($post = mysqli_fetch_assoc($result)) {
        $posts[] = $post;
    }
    
    return $posts;
}

// Format date
function format_blog_date($date) {
    return date('F j, Y', strtotime($date));
}

// Get post excerpt
function get_excerpt($content, $length = 150) {
    $content = strip_tags($content);
    if (strlen($content) <= $length) {
        return $content;
    }
    
    $excerpt = substr($content, 0, $length);
    return $excerpt . '...';
}

// Authenticate blog user
function authenticate_blog_user($username, $password) {
    global $blog_conn;
    
    $username = mysqli_real_escape_string($blog_conn, $username);
    
    $query = "SELECT * FROM blog_users WHERE username = ? AND is_active = TRUE";
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($user = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $user['password'])) {
            // Update last login
            $update_query = "UPDATE blog_users SET last_login = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($blog_conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "i", $user['id']);
            mysqli_stmt_execute($update_stmt);
            
            return $user;
        }
    }

    return false;
}

/**
 * Format file size in bytes to human readable format
 * 
 * @param int $bytes File size in bytes
 * @param int $precision Number of decimal places
 * @return string Formatted file size
 */
function format_file_size($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Compress and resize image while maintaining quality
 * 
 * @param string $source_path Path to source image
 * @param string $destination_path Path to save compressed image
 * @param int $max_width Maximum width (default: 1920)
 * @param int $quality JPEG quality (default: 85)
 * @return bool True on success, false on failure
 */
function compress_image($source_path, $destination_path, $max_width = 1920, $quality = 85) {
    // Get image info
    $image_info = getimagesize($source_path);
    if ($image_info === false) {
        return false;
    }

    // Get image type
    $mime_type = $image_info['mime'];
    
    // Create image from file
    switch ($mime_type) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source_path);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source_path);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source_path);
            break;
        default:
            return false;
    }
    
    if (!$image) {
        return false;
    }

    // Get original dimensions
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Calculate new dimensions
    if ($width > $max_width) {
        $ratio = $max_width / $width;
        $new_width = $max_width;
        $new_height = $height * $ratio;
    } else {
        $new_width = $width;
        $new_height = $height;
    }
    
    // Create new image
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // Preserve transparency for PNG
    if ($mime_type === 'image/png') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }
    
    // Resize image
    imagecopyresampled(
        $new_image, $image,
        0, 0, 0, 0,
        $new_width, $new_height,
        $width, $height
    );
    
    // Save image
    $success = false;
    switch ($mime_type) {
        case 'image/jpeg':
            $success = imagejpeg($new_image, $destination_path, $quality);
            break;
        case 'image/png':
            // PNG compression level (0-9)
            $png_quality = 9 - round(($quality / 100) * 9);
            $success = imagepng($new_image, $destination_path, $png_quality);
            break;
        case 'image/gif':
            $success = imagegif($new_image, $destination_path);
            break;
    }
    
    // Free memory
    imagedestroy($image);
    imagedestroy($new_image);
    
    return $success;
}

/**
 * Upload and process multiple images
 * 
 * @param array $files Array of uploaded files
 * @param string $upload_dir Directory to save images to
 * @return array Array of uploaded image paths
 */
function process_post_images($files, $upload_dir = 'uploads/') {
    // Ensure upload directory exists
    $full_upload_dir = '../' . $upload_dir;
    if (!file_exists($full_upload_dir)) {
        mkdir($full_upload_dir, 0777, true);
    }
    
    $uploaded_images = [];
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    // Loop through each file
    foreach ($files['name'] as $key => $name) {
        // Skip if there was an error or no file was uploaded
        if ($files['error'][$key] !== UPLOAD_ERR_OK || $files['size'][$key] <= 0) {
            continue;
        }
        
        // Check file type
        $file_extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            continue;
        }
        
        // Generate unique filename
        $new_filename = uniqid() . '_post_' . time() . '.' . $file_extension;
        $temp_path = $files['tmp_name'][$key];
        $upload_path = $full_upload_dir . $new_filename;
        
        // Compress and save the image
        if (compress_image($temp_path, $upload_path)) {
            $uploaded_images[] = $upload_dir . $new_filename;
        }
    }
    
    return $uploaded_images;
}
?> 