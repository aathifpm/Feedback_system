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

// Check if user has permission to edit posts
if ($role !== 'admin' && $role !== 'author' && $role !== 'editor') {
    header('Location: dashboard.php');
    exit;
}

// Define image compression settings
$max_file_size = 10 * 1024 * 1024;     // 5MB limit for input file
$target_file_size = 800 * 1024;       // Target: 800KB for final file
$min_file_size = 1 * 1024;            // 1KB minimum
$max_dimension = 2048;                 // Maximum image dimension
$min_dimension = 100;                  // Minimum image dimension
$optimal_dimension = 1200;             // Optimal dimension for blog images
$initial_quality = 90;                 // Initial JPEG quality

// Function to compress image to target size
function compressImage($source_path, $destination_path, $target_size, $initial_quality = 90) {
    $current_quality = $initial_quality;
    $min_quality = 20; // Minimum acceptable quality
    
    do {
        // Create image based on file type
        $image_info = getimagesize($source_path);
        $mime_type = $image_info['mime'];
        
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
        
        // Save with current quality
        imagejpeg($image, $destination_path, $current_quality);
        imagedestroy($image);
        
        // Check file size
        $current_size = filesize($destination_path);
        
        // If size is still too large, reduce quality and try again
        if ($current_size > $target_size && $current_quality > $min_quality) {
            $current_quality -= 5;
        } else {
            break;
        }
    } while (true);
    
    return $current_size <= $target_size || $current_quality <= $min_quality;
}

// Process multiple uploaded images with compression
function process_post_images_admin($files) {
    global $max_file_size, $target_file_size, $min_file_size, $max_dimension, $min_dimension, 
           $optimal_dimension, $initial_quality, $errors;
    
    $upload_dir = '../uploads/blog_images/';
    $uploaded_files = [];
    $error_messages = [];
    
    // Create directory if it doesn't exist
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    // Process each uploaded file
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === 0) {
            // Check file size
            if ($files['size'][$i] > $max_file_size) {
                $error_messages[] = "File '{$files['name'][$i]}' is too large. Maximum size allowed is " . formatBytes($max_file_size);
                continue;
            }
            
            if ($files['size'][$i] < $min_file_size) {
                $error_messages[] = "File '{$files['name'][$i]}' is too small. Minimum size required is " . formatBytes($min_file_size);
                continue;
            }
            
            $file_extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Validate image dimensions
                list($width, $height) = getimagesize($files['tmp_name'][$i]);
                
                if ($width < $min_dimension || $height < $min_dimension) {
                    $error_messages[] = "File '{$files['name'][$i]}' dimensions are too small. Minimum dimension allowed is {$min_dimension}x{$min_dimension} pixels";
                    continue;
                }
                
                if ($width > $max_dimension || $height > $max_dimension) {
                    $error_messages[] = "File '{$files['name'][$i]}' dimensions are too large. Maximum dimension allowed is {$max_dimension}x{$max_dimension} pixels";
                    continue;
                }
                
                // Create unique filename
                $new_filename = uniqid('blog_') . '.' . 'jpg'; // Convert all to jpg
                $upload_path = $upload_dir . $new_filename;
                $temp_path = $upload_dir . 'temp_' . $new_filename;
                $relative_path = 'uploads/blog_images/' . $new_filename;
                
                // First resize if needed
                $source_image = null;
                switch($file_extension) {
                    case 'jpg':
                    case 'jpeg':
                        $source_image = imagecreatefromjpeg($files['tmp_name'][$i]);
                        break;
                    case 'png':
                        $source_image = imagecreatefrompng($files['tmp_name'][$i]);
                        break;
                    case 'gif':
                        $source_image = imagecreatefromgif($files['tmp_name'][$i]);
                        break;
                }
                
                if ($source_image) {
                    // Resize if needed
                    if ($width > $optimal_dimension || $height > $optimal_dimension) {
                        if ($width > $height) {
                            $new_width = $optimal_dimension;
                            $new_height = floor($height * ($optimal_dimension / $width));
                        } else {
                            $new_height = $optimal_dimension;
                            $new_width = floor($width * ($optimal_dimension / $height));
                        }
                        
                        $resized_image = imagecreatetruecolor($new_width, $new_height);
                        
                        // Preserve transparency for PNG images
                        if ($file_extension === 'png') {
                            imagealphablending($resized_image, false);
                            imagesavealpha($resized_image, true);
                        }
                        
                        imagecopyresampled($resized_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                        imagejpeg($resized_image, $temp_path, $initial_quality);
                        imagedestroy($resized_image);
                    } else {
                        imagejpeg($source_image, $temp_path, $initial_quality);
                    }
                    
                    imagedestroy($source_image);
                    
                    // Compress the image to target size
                    if (compressImage($temp_path, $upload_path, $target_file_size, $initial_quality)) {
                        $uploaded_files[] = $relative_path;
                    } else {
                        $error_messages[] = "Could not compress file '{$files['name'][$i]}' to required size.";
                    }
                    
                    // Clean up temporary file
                    if (file_exists($temp_path)) {
                        unlink($temp_path);
                    }
                } else {
                    $error_messages[] = "Failed to process file '{$files['name'][$i]}'.";
                }
            } else {
                $error_messages[] = "File '{$files['name'][$i]}' has invalid type. Please upload JPG, JPEG, PNG, or GIF files only.";
            }
        }
    }
    
    // Store the error messages
    if (!empty($error_messages)) {
        $errors['post_images'] = $error_messages;
    }
    
    return $uploaded_files;
}

$post_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$post = null;
$categories = [];
$tags = [];

// Initialize error messages array
$errors = [
    'featured_image' => '',
    'post_images' => ''
];

// Get all categories
$cat_query = "SELECT * FROM blog_categories ORDER BY name";
$cat_result = mysqli_query($blog_conn, $cat_query);
while ($row = mysqli_fetch_assoc($cat_result)) {
    $categories[] = $row;
}

// Get all tags
$tag_query = "SELECT * FROM blog_tags ORDER BY name";
$tag_result = mysqli_query($blog_conn, $tag_query);
while ($row = mysqli_fetch_assoc($tag_result)) {
    $tags[] = $row;
}

// If editing existing post
if ($post_id > 0) {
    $query = "SELECT p.*, GROUP_CONCAT(pc.category_id) as category_ids, GROUP_CONCAT(pt.tag_id) as tag_ids 
              FROM blog_posts p 
              LEFT JOIN blog_post_categories pc ON p.id = pc.post_id 
              LEFT JOIN blog_post_tags pt ON p.id = pt.post_id 
              WHERE p.id = ? 
              GROUP BY p.id";
    $stmt = mysqli_prepare($blog_conn, $query);
    mysqli_stmt_bind_param($stmt, "i", $post_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $post = mysqli_fetch_assoc($result);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $excerpt = trim($_POST['excerpt']);
    $status = $_POST['status'];
    $selected_categories = isset($_POST['categories']) ? $_POST['categories'] : [];
    $selected_tags = isset($_POST['tags']) ? $_POST['tags'] : [];
    $meta_title = trim($_POST['meta_title']);
    $meta_description = trim($_POST['meta_description']);
    
    // Generate slug
    $slug = create_slug($title);
    
    // Handle featured image upload
    $featured_image = $post ? $post['featured_image'] : '';
    if (isset($_FILES['featured_image']) && $_FILES['featured_image']['error'] === 0) {
        $upload_dir = '../uploads/blog_images/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_size = $_FILES['featured_image']['size'];
        // Validate file size
        if ($file_size > $max_file_size) {
            $errors['featured_image'] = "File is too large. Maximum size allowed is " . formatBytes($max_file_size);
        } 
        else if ($file_size < $min_file_size) {
            $errors['featured_image'] = "File is too small. Minimum size required is " . formatBytes($min_file_size);
        }
        else {
            $file_extension = strtolower(pathinfo($_FILES['featured_image']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_extension, $allowed_extensions)) {
                // Validate image dimensions
                list($width, $height) = getimagesize($_FILES['featured_image']['tmp_name']);
                
                if ($width < $min_dimension || $height < $min_dimension) {
                    $errors['featured_image'] = "Image dimensions are too small. Minimum dimension allowed is {$min_dimension}x{$min_dimension} pixels";
                }
                else if ($width > $max_dimension || $height > $max_dimension) {
                    $errors['featured_image'] = "Image dimensions are too large. Maximum dimension allowed is {$max_dimension}x{$max_dimension} pixels";
                }
                else {
                    // Create unique filename
                    $new_filename = uniqid('featured_') . '.jpg';
                    $upload_path = $upload_dir . $new_filename;
                    $temp_path = $upload_dir . 'temp_' . $new_filename;
                    $featured_image = 'uploads/blog_images/' . $new_filename;
                    
                    // First resize if needed
                    $source_image = null;
                    switch($file_extension) {
                        case 'jpg':
                        case 'jpeg':
                            $source_image = imagecreatefromjpeg($_FILES['featured_image']['tmp_name']);
                            break;
                        case 'png':
                            $source_image = imagecreatefrompng($_FILES['featured_image']['tmp_name']);
                            break;
                        case 'gif':
                            $source_image = imagecreatefromgif($_FILES['featured_image']['tmp_name']);
                            break;
                    }
                    
                    if ($source_image) {
                        // Resize if needed
                        if ($width > $optimal_dimension || $height > $optimal_dimension) {
                            if ($width > $height) {
                                $new_width = $optimal_dimension;
                                $new_height = floor($height * ($optimal_dimension / $width));
                            } else {
                                $new_height = $optimal_dimension;
                                $new_width = floor($width * ($optimal_dimension / $height));
                            }
                            
                            $resized_image = imagecreatetruecolor($new_width, $new_height);
                            
                            // Preserve transparency for PNG images
                            if ($file_extension === 'png') {
                                imagealphablending($resized_image, false);
                                imagesavealpha($resized_image, true);
                            }
                            
                            imagecopyresampled($resized_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                            imagejpeg($resized_image, $temp_path, $initial_quality);
                            imagedestroy($resized_image);
                        } else {
                            imagejpeg($source_image, $temp_path, $initial_quality);
                        }
                        
                        imagedestroy($source_image);
                        
                        // Compress the image to target size
                        if (!compressImage($temp_path, $upload_path, $target_file_size, $initial_quality)) {
                            $errors['featured_image'] = "Could not compress image to required size. Please try a different image.";
                        }
                        
                        // Clean up temporary file
                        if (file_exists($temp_path)) {
                            unlink($temp_path);
                        }
                    } else {
                        $errors['featured_image'] = "Failed to process image. Please try again.";
                    }
                }
            } else {
                $errors['featured_image'] = "Invalid file type. Please upload JPG, JPEG, PNG, or GIF files only.";
            }
        }
    }
    
    // Process additional post images
    $uploaded_images = [];
    if (isset($_FILES['post_images']) && !empty($_FILES['post_images']['name'][0])) {
        $uploaded_images = process_post_images_admin($_FILES['post_images']);
    }
    
    // Only proceed with database operations if there are no errors
    if (empty($errors['featured_image']) && empty($errors['post_images'])) {
        if ($post_id > 0) {
            // Update existing post
            $query = "UPDATE blog_posts SET 
                      title = ?, slug = ?, content = ?, excerpt = ?, 
                      featured_image = ?, status = ?, meta_title = ?, 
                      meta_description = ?, updated_at = CURRENT_TIMESTAMP 
                      WHERE id = ?";
            $stmt = mysqli_prepare($blog_conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssssssi", 
                $title, $slug, $content, $excerpt, 
                $featured_image, $status, $meta_title, 
                $meta_description, $post_id);
        } else {
            // Create new post
            $query = "INSERT INTO blog_posts 
                      (title, slug, content, excerpt, featured_image, 
                       status, meta_title, meta_description, author_id) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($blog_conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssssssi", 
                $title, $slug, $content, $excerpt, 
                $featured_image, $status, $meta_title, 
                $meta_description, $user_id);
        }
        
        if (mysqli_stmt_execute($stmt)) {
            if ($post_id === 0) {
                $post_id = mysqli_insert_id($blog_conn);
            }
            
            // Update categories
            mysqli_query($blog_conn, "DELETE FROM blog_post_categories WHERE post_id = $post_id");
            foreach ($selected_categories as $category_id) {
                mysqli_query($blog_conn, "INSERT INTO blog_post_categories (post_id, category_id) VALUES ($post_id, $category_id)");
            }
            
            // Update tags
            mysqli_query($blog_conn, "DELETE FROM blog_post_tags WHERE post_id = $post_id");
            foreach ($selected_tags as $tag_id) {
                mysqli_query($blog_conn, "INSERT INTO blog_post_tags (post_id, tag_id) VALUES ($post_id, $tag_id)");
            }
            
            header('Location: posts.php?message=Post saved successfully');
            exit;
        }
    }
}

$page_title = $post_id > 0 ? "Edit Post" : "New Post";
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
        .editor-toolbar {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            padding: 0.5rem;
        }
        .editor-toolbar button {
            background: none;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            margin-right: 0.25rem;
            font-size: 0.875rem;
        }
        .editor-toolbar button:hover {
            background: #e9ecef;
        }
        .editor-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-radius: 4px 4px 0 0;
            padding: 5px;
        }
        .editor-toolbar .btn {
            border: 1px solid #dee2e6;
            background-color: #ffffff;
            color: #495057;
            padding: 3px 8px;
            font-size: 0.875rem;
        }
        .editor-toolbar .btn:hover {
            background-color: #e9ecef;
        }
        .editor-toolbar .btn.active {
            background-color: #0d6efd;
            color: white;
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        .editor-toolbar .btn:focus {
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
        }
        .editor-content {
            border: 1px solid #dee2e6;
            border-radius: 0 0 4px 4px;
            min-height: 400px;
            padding: 1rem;
        }
        .editor-content:focus {
            outline: none;
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        /* Multiple Image Upload Styles */
        .post-image-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        .post-image-preview-wrapper {
            display: flex;
            flex-direction: column;
            margin-bottom: 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            overflow: hidden;
            width: 120px;
        }
        .post-image-preview {
            position: relative;
            width: 120px;
            height: 120px;
            overflow: hidden;
        }
        .post-image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .post-image-preview .insert-btn {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0,0,0,0.7);
            color: white;
            text-align: center;
            padding: 2px;
            cursor: pointer;
            font-size: 12px;
        }
        .image-upload-container {
            border: 1px dashed #dee2e6;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 15px;
            background-color: #f8f9fa;
        }
        /* Editor content image styles */
        .editor-content img {
            max-width: 100%;
            height: auto;
            margin: 10px 0;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .editor-content img:hover {
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        /* Image size options */
        .image-size-controls {
            display: flex;
            justify-content: space-between;
            padding: 5px;
            background-color: #f8f9fa;
        }
        .image-size-btn {
            font-size: 10px;
            padding: 2px 4px;
            background-color: #fff;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            cursor: pointer;
            flex: 1;
            margin: 0 2px;
        }
        .image-size-btn:hover {
            background-color: #e9ecef;
        }
        /* Image caption styles */
        .image-with-caption {
            display: table;
            margin: 15px auto;
        }
        .image-caption {
            display: table-caption;
            caption-side: bottom;
            text-align: center;
            font-size: 0.9rem;
            color: #6c757d;
            padding: 5px 0;
            font-style: italic;
        }
        /* Emoji picker */
        .emoji-picker {
            max-width: 320px;
            height: 200px;
            overflow-y: auto;
            display: flex;
            flex-wrap: wrap;
            padding: 5px;
        }
        .emoji-btn {
            font-size: 1.5rem;
            line-height: 1;
            padding: 5px;
            cursor: pointer;
            border-radius: 4px;
        }
        .emoji-btn:hover {
            background-color: #e9ecef;
        }
        /* Fullscreen mode */
        .editor-fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            background: #fff;
            padding: 20px;
            overflow-y: auto;
        }
        .editor-fullscreen .editor-content {
            height: calc(100vh - 150px);
        }
        /* Keyboard shortcuts */
        kbd {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            box-shadow: 0 1px 0 rgba(0,0,0,.2);
            color: #495057;
            display: inline-block;
            font-size: .75rem;
            font-weight: 600;
            line-height: 1;
            padding: 2px 4px;
            white-space: nowrap;
        }
        /* Color picker */
        .color-picker {
            padding: 8px;
            min-width: 150px;
        }
        .color-btn {
            width: 20px;
            height: 20px;
            border: none;
            border-radius: 4px;
            margin: 2px;
            cursor: pointer;
        }
        .color-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 0 2px rgba(0,0,0,0.3);
        }
        /* Code block styles */
        .editor-content pre {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            font-family: monospace;
            overflow: auto;
            margin: 10px 0;
        }
        .editor-content code {
            font-family: monospace;
            background-color: #f5f5f5;
            padding: 2px 4px;
            border-radius: 3px;
        }
        /* Blockquote style */
        .editor-content blockquote {
            border-left: 4px solid #6c757d;
            margin: 15px 0;
            padding: 10px 20px;
            background-color: #f8f9fa;
            font-style: italic;
        }
        /* Error message styles */
        .error-message {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background-color: rgba(220, 53, 69, 0.1);
            border-radius: 4px;
            border-left: 3px solid #dc3545;
        }
        .error-message ul {
            margin: 0.5rem 0 0.5rem 1.5rem;
            padding: 0;
        }
        .error-message li {
            margin-bottom: 0.25rem;
        }
        .has-error .form-control {
            border-color: #dc3545;
        }
        .has-error .image-upload-container {
            border-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.05);
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
                    <h2><?php echo $page_title; ?></h2>
                    <a href="posts.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Posts
                    </a>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card mb-4">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="title" class="form-label">Title</label>
                                        <input type="text" class="form-control" id="title" name="title" 
                                               value="<?php echo $post ? htmlspecialchars($post['title']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="content" class="form-label">Content</label>
                                        <div class="editor-toolbar">
                                            <!-- Text Formatting -->
                                            <button type="button" onclick="formatText('bold')" title="Bold (Ctrl+B)" class="btn btn-sm"><i class="fas fa-bold"></i></button>
                                            <button type="button" onclick="formatText('italic')" title="Italic (Ctrl+I)" class="btn btn-sm"><i class="fas fa-italic"></i></button>
                                            <button type="button" onclick="formatText('underline')" title="Underline (Ctrl+U)" class="btn btn-sm"><i class="fas fa-underline"></i></button>
                                            <button type="button" onclick="formatText('strikethrough')" title="Strikethrough" class="btn btn-sm"><i class="fas fa-strikethrough"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Font Family and Size -->
                                            <div class="btn-group me-2">
                                                <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Font">
                                                    <i class="fas fa-font"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Arial'); return false;">Arial</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Helvetica'); return false;">Helvetica</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Times New Roman'); return false;">Times New Roman</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Courier New'); return false;">Courier New</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Georgia'); return false;">Georgia</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontName', 'Verdana'); return false;">Verdana</a></li>
                                                </ul>
                                            </div>
                                            
                                            <div class="btn-group me-2">
                                                <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Font Size">
                                                    <i class="fas fa-text-height"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontSize', '1'); return false;">Small</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontSize', '3'); return false;">Normal</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontSize', '5'); return false;">Large</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('fontSize', '7'); return false;">X-Large</a></li>
                                                </ul>
                                            </div>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Headings -->
                                            <div class="btn-group me-2">
                                                <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                    Headings
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h1'); return false;">H1</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h2'); return false;">H2</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h3'); return false;">H3</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h4'); return false;">H4</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h5'); return false;">H5</a></li>
                                                    <li><a class="dropdown-item" href="#" onclick="formatText('h6'); return false;">H6</a></li>
                                                </ul>
                                            </div>
                                            
                                            <!-- Text Alignment -->
                                            <button type="button" onclick="formatText('justifyLeft')" title="Align Left" class="btn btn-sm"><i class="fas fa-align-left"></i></button>
                                            <button type="button" onclick="formatText('justifyCenter')" title="Align Center" class="btn btn-sm"><i class="fas fa-align-center"></i></button>
                                            <button type="button" onclick="formatText('justifyRight')" title="Align Right" class="btn btn-sm"><i class="fas fa-align-right"></i></button>
                                            <button type="button" onclick="formatText('justifyFull')" title="Justify" class="btn btn-sm"><i class="fas fa-align-justify"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Lists -->
                                            <button type="button" onclick="formatText('insertUnorderedList')" title="Bullet List" class="btn btn-sm"><i class="fas fa-list-ul"></i></button>
                                            <button type="button" onclick="formatText('insertOrderedList')" title="Numbered List" class="btn btn-sm"><i class="fas fa-list-ol"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Indent/Outdent -->
                                            <button type="button" onclick="formatText('outdent')" title="Decrease Indent" class="btn btn-sm"><i class="fas fa-outdent"></i></button>
                                            <button type="button" onclick="formatText('indent')" title="Increase Indent" class="btn btn-sm"><i class="fas fa-indent"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Links and Media -->
                                            <button type="button" onclick="formatText('link')" title="Insert Link (Ctrl+K)" class="btn btn-sm"><i class="fas fa-link"></i></button>
                                            <button type="button" onclick="formatText('unlink')" title="Remove Link" class="btn btn-sm"><i class="fas fa-unlink"></i></button>
                                            <button type="button" onclick="formatText('image')" title="Insert Image" class="btn btn-sm"><i class="fas fa-image"></i></button>
                                            <button type="button" onclick="insertVideo()" title="Insert Video" class="btn btn-sm"><i class="fas fa-video"></i></button>
                                            <button type="button" onclick="insertEmoji()" title="Insert Emoji" class="btn btn-sm"><i class="far fa-smile"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Special Formatting -->
                                            <button type="button" onclick="insertTable()" title="Insert Table" class="btn btn-sm"><i class="fas fa-table"></i></button>
                                            <button type="button" onclick="insertCode()" title="Insert Code" class="btn btn-sm"><i class="fas fa-code"></i></button>
                                            <button type="button" onclick="formatText('formatBlock', '<blockquote>')" title="Blockquote" class="btn btn-sm"><i class="fas fa-quote-right"></i></button>
                                            <button type="button" onclick="insertHorizontalRule()" title="Insert Horizontal Line" class="btn btn-sm"><i class="fas fa-minus"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Text Color and Background -->
                                            <div class="btn-group me-2">
                                                <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Text Color">
                                                    <i class="fas fa-palette"></i>
                                                </button>
                                                <div class="dropdown-menu p-2 color-picker">
                                                    <div class="d-flex flex-wrap" style="width: 120px;">
                                                        <button type="button" class="color-btn" style="background-color: #000000;" onclick="formatText('foreColor', '#000000')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #333333;" onclick="formatText('foreColor', '#333333')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #666666;" onclick="formatText('foreColor', '#666666')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #999999;" onclick="formatText('foreColor', '#999999')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #cccccc;" onclick="formatText('foreColor', '#cccccc')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ffffff; border: 1px solid #ddd;" onclick="formatText('foreColor', '#ffffff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ff0000;" onclick="formatText('foreColor', '#ff0000')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #00ff00;" onclick="formatText('foreColor', '#00ff00')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #0000ff;" onclick="formatText('foreColor', '#0000ff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ffff00;" onclick="formatText('foreColor', '#ffff00')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #00ffff;" onclick="formatText('foreColor', '#00ffff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ff00ff;" onclick="formatText('foreColor', '#ff00ff')"></button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="btn-group me-2">
                                                <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Background Color">
                                                    <i class="fas fa-fill-drip"></i>
                                                </button>
                                                <div class="dropdown-menu p-2 color-picker">
                                                    <div class="d-flex flex-wrap" style="width: 120px;">
                                                        <button type="button" class="color-btn" style="background-color: #ffffff; border: 1px solid #ddd;" onclick="formatText('hiliteColor', '#ffffff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #f8f9fa;" onclick="formatText('hiliteColor', '#f8f9fa')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #e9ecef;" onclick="formatText('hiliteColor', '#e9ecef')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #dee2e6;" onclick="formatText('hiliteColor', '#dee2e6')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ffdddd;" onclick="formatText('hiliteColor', '#ffdddd')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ddffdd;" onclick="formatText('hiliteColor', '#ddffdd')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ddddff;" onclick="formatText('hiliteColor', '#ddddff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ffffdd;" onclick="formatText('hiliteColor', '#ffffdd')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ddffff;" onclick="formatText('hiliteColor', '#ddffff')"></button>
                                                        <button type="button" class="color-btn" style="background-color: #ffddff;" onclick="formatText('hiliteColor', '#ffddff')"></button>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Undo/Redo -->
                                            <button type="button" onclick="formatText('undo')" title="Undo (Ctrl+Z)" class="btn btn-sm"><i class="fas fa-undo"></i></button>
                                            <button type="button" onclick="formatText('redo')" title="Redo (Ctrl+Y)" class="btn btn-sm"><i class="fas fa-redo"></i></button>
                                            <span class="border-end mx-2"></span>
                                            
                                            <!-- Additional Tools -->
                                            <button type="button" onclick="toggleSpellCheck()" id="spellCheckBtn" title="Spell Check" class="btn btn-sm"><i class="fas fa-spell-check"></i></button>
                                            <button type="button" onclick="toggleFullscreen()" title="Fullscreen" class="btn btn-sm"><i class="fas fa-expand"></i></button>
                                            
                                            <!-- Clear Formatting -->
                                            <button type="button" onclick="formatText('removeFormat')" title="Clear Formatting" class="btn btn-sm"><i class="fas fa-eraser"></i></button>
                                        </div>
                                        <div id="content" class="editor-content" contenteditable="true" role="textbox" aria-multiline="true" spellcheck="true">
                                            <?php echo $post ? $post['content'] : ''; ?>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-2 text-muted small">
                                            <div id="wordCount">0 words | 0 characters</div>
                                            <div>Press <kbd>Ctrl+S</kbd> to save draft</div>
                                        </div>
                                        <input type="hidden" name="content" id="content_input">
                                    </div>
                                    
                                    <!-- Multiple Image Upload Section -->
                                    <div class="mb-3">
                                        <label class="form-label">Post Images</label>
                                        <div class="image-upload-container">
                                            <input type="file" class="form-control mb-2" id="post_images" name="post_images[]" multiple accept="image/jpeg,image/png,image/gif">
                                            <small class="text-muted">Upload multiple images to insert into your post content. Select an image and click "Insert Into Post" to add it at cursor position.</small>
                                            <div class="post-image-gallery" id="image-previews"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-4">
                            <div class="card mb-4">
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="draft" <?php echo $post && $post['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                            <option value="published" <?php echo $post && $post['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="featured_image" class="form-label">Featured Image</label>
                                        <input type="file" class="form-control" id="featured_image" name="featured_image">
                                        <?php if ($post && $post['featured_image']): ?>
                                            <img src="../<?php echo htmlspecialchars($post['featured_image']); ?>" 
                                                 class="img-thumbnail mt-2" style="max-height: 150px;">
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Categories</label>
                                        <?php foreach ($categories as $category): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="categories[]" value="<?php echo $category['id']; ?>" 
                                                       id="category_<?php echo $category['id']; ?>"
                                                       <?php echo $post && in_array($category['id'], explode(',', $post['category_ids'])) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="category_<?php echo $category['id']; ?>">
                                                    <?php echo htmlspecialchars($category['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Tags</label>
                                        <?php foreach ($tags as $tag): ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="tags[]" value="<?php echo $tag['id']; ?>" 
                                                       id="tag_<?php echo $tag['id']; ?>"
                                                       <?php echo $post && in_array($tag['id'], explode(',', $post['tag_ids'])) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="tag_<?php echo $tag['id']; ?>">
                                                    <?php echo htmlspecialchars($tag['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="excerpt" class="form-label">Excerpt</label>
                                        <textarea class="form-control" id="excerpt" name="excerpt" rows="3"><?php echo $post ? htmlspecialchars($post['excerpt']) : ''; ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="meta_title" class="form-label">Meta Title</label>
                                        <input type="text" class="form-control" id="meta_title" name="meta_title" 
                                               value="<?php echo $post ? htmlspecialchars($post['meta_title']) : ''; ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="meta_description" class="form-label">Meta Description</label>
                                        <textarea class="form-control" id="meta_description" name="meta_description" rows="2"><?php echo $post ? htmlspecialchars($post['meta_description']) : ''; ?></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-save me-1"></i> Save Post
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel">Image Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImage" src="" class="img-fluid" alt="Preview">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Make uploaded images available in editor
        <?php if (!empty($uploaded_images)): ?>
        const uploadedImages = <?php echo json_encode($uploaded_images); ?>;
        <?php else: ?>
        const uploadedImages = [];
        <?php endif; ?>

        // Simple text editor functions
        function formatText(command, value = null) {
            const editor = document.getElementById('content');
            
            switch(command) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                    document.execCommand('formatBlock', false, '<' + command + '>');
                    break;
                case 'removeFormat':
                    document.execCommand(command, false, null);
                    // Also clear block style
                    document.execCommand('formatBlock', false, 'div');
                    break;
                case 'link':
                    const url = prompt('Enter URL:', 'https://');
                    if (url) {
                        document.execCommand(command, false, url);
                    }
                    break;
                default:
                    document.execCommand(command, false, value);
                    break;
            }
            editor.focus();
            updateWordCount();
            checkFormatState(); // Update button states after format change
        }

        // Function to check which formatting is active for current selection
        function checkFormatState() {
            const commands = [
                { cmd: 'bold', selector: '[onclick="formatText(\'bold\')"]' },
                { cmd: 'italic', selector: '[onclick="formatText(\'italic\')"]' },
                { cmd: 'underline', selector: '[onclick="formatText(\'underline\')"]' },
                { cmd: 'strikeThrough', selector: '[onclick="formatText(\'strikethrough\')"]' },
                { cmd: 'justifyLeft', selector: '[onclick="formatText(\'justifyLeft\')"]' },
                { cmd: 'justifyCenter', selector: '[onclick="formatText(\'justifyCenter\')"]' },
                { cmd: 'justifyRight', selector: '[onclick="formatText(\'justifyRight\')"]' },
                { cmd: 'justifyFull', selector: '[onclick="formatText(\'justifyFull\')"]' },
                { cmd: 'insertUnorderedList', selector: '[onclick="formatText(\'insertUnorderedList\')"]' },
                { cmd: 'insertOrderedList', selector: '[onclick="formatText(\'insertOrderedList\')"]' }
            ];
            
            commands.forEach(item => {
                const isActive = document.queryCommandState(item.cmd);
                const button = document.querySelector(item.selector);
                
                if (button) {
                    if (isActive) {
                        button.classList.add('active');
                    } else {
                        button.classList.remove('active');
                    }
                }
            });
            
            // Check for heading formats
            const headingSelectors = [
                { block: 'h1', selector: '[onclick="formatText(\'h1\')"]' },
                { block: 'h2', selector: '[onclick="formatText(\'h2\')"]' },
                { block: 'h3', selector: '[onclick="formatText(\'h3\')"]' },
                { block: 'h4', selector: '[onclick="formatText(\'h4\')"]' },
                { block: 'h5', selector: '[onclick="formatText(\'h5\')"]' },
                { block: 'h6', selector: '[onclick="formatText(\'h6\')"]' },
            ];
            
            // Reset all heading buttons
            headingSelectors.forEach(item => {
                const button = document.querySelector(item.selector);
                if (button) button.classList.remove('active');
            });
            
            // Check which block format is active
            try {
                const formatBlock = document.queryCommandValue('formatBlock').toLowerCase();
                headingSelectors.forEach(item => {
                    if (formatBlock === item.block) {
                        const button = document.querySelector(item.selector);
                        if (button) button.classList.add('active');
                    }
                });
                
                // Check for blockquote
                if (formatBlock === 'blockquote') {
                    const blockquoteBtn = document.querySelector('[onclick="formatText(\'formatBlock\', \'<blockquote>\')"]');
                    if (blockquoteBtn) blockquoteBtn.classList.add('active');
                }
            } catch (e) {
                console.log('Error checking formatBlock state:', e);
            }
        }

        // Toggle spell checker
        function toggleSpellCheck() {
            const editor = document.getElementById('content');
            const btn = document.getElementById('spellCheckBtn');
            
            if (editor.getAttribute('spellcheck') === 'true') {
                editor.setAttribute('spellcheck', 'false');
                btn.classList.remove('active');
            } else {
                editor.setAttribute('spellcheck', 'true');
                btn.classList.add('active');
            }
            
            // Need to refresh the editor to see the effect
            const content = editor.innerHTML;
            editor.innerHTML = '';
            editor.innerHTML = content;
            editor.focus();
        }

        // Toggle fullscreen mode
        function toggleFullscreen() {
            const editorContainer = document.querySelector('.card-body');
            const editor = document.getElementById('content');
            
            if (editorContainer.classList.contains('editor-fullscreen')) {
                editorContainer.classList.remove('editor-fullscreen');
            } else {
                editorContainer.classList.add('editor-fullscreen');
                editor.focus();
            }
        }

        // Insert emoji
        function insertEmoji() {
            const selection = window.getSelection();
            const range = selection.getRangeAt(0);
            
            // Create emoji picker
            const emojiPicker = document.createElement('div');
            emojiPicker.className = 'dropdown-menu p-2 emoji-picker show';
            emojiPicker.style.position = 'absolute';
            
            // Common emojis
            const emojis = ['😀', '😃', '😄', '😊', '😍', '🥰', '😂', '🤣', '😎', '🙂', '😐', '😏', 
                           '😮', '😢', '😭', '😡', '🥺', '😴', '🤔', '🙄', '😳', '❤️', '💔', '👍', 
                           '👎', '👏', '🙏', '🎉', '🔥', '💯', '✅', '❌', '⭐', '🌟'];
            
            emojis.forEach(emoji => {
                const emojiBtn = document.createElement('span');
                emojiBtn.className = 'emoji-btn';
                emojiBtn.textContent = emoji;
                emojiBtn.onclick = function() {
                    document.execCommand('insertText', false, emoji);
                    emojiPicker.remove();
                };
                emojiPicker.appendChild(emojiBtn);
            });
            
            // Position the picker near the cursor
            const rect = range.getBoundingClientRect();
            document.body.appendChild(emojiPicker);
            
            emojiPicker.style.top = (rect.bottom + window.scrollY) + 'px';
            emojiPicker.style.left = rect.left + 'px';
            
            // Close the picker when clicking outside
            document.addEventListener('click', function closeEmojiPicker(e) {
                if (!emojiPicker.contains(e.target)) {
                    emojiPicker.remove();
                    document.removeEventListener('click', closeEmojiPicker);
                }
            });
        }

        // Insert table at cursor position
        function insertTable() {
            const rows = prompt('Enter number of rows:', '3');
            const cols = prompt('Enter number of columns:', '3');
            
            if (rows && cols) {
                const rowsNum = parseInt(rows);
                const colsNum = parseInt(cols);
                
                if (rowsNum > 0 && colsNum > 0) {
                    let tableHTML = '<table><thead><tr>';
                    
                    // Add headers
                    for (let i = 0; i < colsNum; i++) {
                        tableHTML += `<th>Header ${i+1}</th>`;
                    }
                    tableHTML += '</tr></thead><tbody>';
                    
                    // Add rows
                    for (let i = 0; i < rowsNum; i++) {
                        tableHTML += '<tr>';
                        for (let j = 0; j < colsNum; j++) {
                            tableHTML += '<td>Cell</td>';
                        }
                        tableHTML += '</tr>';
                    }
                    tableHTML += '</tbody></table><p></p>';
                    
                    document.execCommand('insertHTML', false, tableHTML);
                }
            }
            document.getElementById('content').focus();
            updateWordCount();
        }

        // Insert code block
        function insertCode() {
            const codeType = prompt('Enter code language (optional):', '');
            let codeBlock;
            
            if (codeType) {
                codeBlock = `<pre><code class="language-${codeType}">// Your code here</code></pre><p></p>`;
            } else {
                codeBlock = '<pre><code>// Your code here</code></pre><p></p>';
            }
            
            document.execCommand('insertHTML', false, codeBlock);
            document.getElementById('content').focus();
            updateWordCount();
        }

        // Insert horizontal rule
        function insertHorizontalRule() {
            document.execCommand('insertHorizontalRule', false, null);
            // Add a paragraph after the rule for easier editing
            document.execCommand('insertHTML', false, '<p></p>');
            document.getElementById('content').focus();
            updateWordCount();
        }

        // Insert video (YouTube or other embed)
        function insertVideo() {
            const videoUrl = prompt('Enter video URL (YouTube, Vimeo, etc.):', '');
            if (videoUrl) {
                let embedCode;
                
                // Check if it's YouTube
                if (videoUrl.includes('youtube.com') || videoUrl.includes('youtu.be')) {
                    const videoId = getYouTubeVideoId(videoUrl);
                    if (videoId) {
                        embedCode = `<div class="video-container"><iframe width="560" height="315" src="https://www.youtube.com/embed/${videoId}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div><p></p>`;
                    } else {
                        alert('Invalid YouTube URL. Please try again.');
                        return;
                    }
                } else if (videoUrl.includes('vimeo.com')) {
                    // Try to extract Vimeo ID
                    const vimeoId = getVimeoId(videoUrl);
                    if (vimeoId) {
                        embedCode = `<div class="video-container"><iframe src="https://player.vimeo.com/video/${vimeoId}" width="560" height="315" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe></div><p></p>`;
                    } else {
                        alert('Invalid Vimeo URL. Please try again.');
                        return;
                    }
                } else {
                    // Generic embed code
                    embedCode = `<div class="video-container"><iframe src="${videoUrl}" width="560" height="315" frameborder="0" allowfullscreen></iframe></div><p></p>`;
                }
                
                document.execCommand('insertHTML', false, embedCode);
                document.getElementById('content').focus();
                updateWordCount();
            }
        }

        // Helper function to get YouTube video ID
        function getYouTubeVideoId(url) {
            const regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/;
            const match = url.match(regExp);
            return (match && match[2].length === 11) ? match[2] : null;
        }

        // Helper function to get Vimeo video ID
        function getVimeoId(url) {
            const regExp = /vimeo\.com\/(?:channels\/(?:\w+\/)?|groups\/([^\/]*)\/videos\/|album\/(\d+)\/video\/|)(\d+)(?:$|\/|\?)/;
            const match = url.match(regExp);
            return match ? match[3] : null;
        }

        // Word and character count updater
        function updateWordCount() {
            const text = document.getElementById('content').textContent || '';
            
            // Count words (split by whitespace and filter out empty strings)
            const words = text.trim().split(/\s+/).filter(word => word.length > 0).length;
            
            // Count characters (excluding whitespace)
            const chars = text.replace(/\s+/g, '').length;
            
            document.getElementById('wordCount').textContent = `${words} words | ${chars} characters`;
        }

        // Update hidden input before form submission
        document.querySelector('form').addEventListener('submit', function(e) {
            document.getElementById('content_input').value = document.getElementById('content').innerHTML;
        });
        
        // Handle multiple image uploads and previews
        document.getElementById('post_images').addEventListener('change', function(e) {
            const previewContainer = document.getElementById('image-previews');
            previewContainer.innerHTML = ''; // Clear previous previews
            
            const files = e.target.files;
            if (!files || files.length === 0) return;
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (!file.type.startsWith('image/')) continue;
                
                const reader = new FileReader();
                reader.onload = function(event) {
                    const previewWrapper = document.createElement('div');
                    previewWrapper.className = 'post-image-preview-wrapper';
                    
                    const preview = document.createElement('div');
                    preview.className = 'post-image-preview';
                    
                    const img = document.createElement('img');
                    img.src = event.target.result;
                    
                    const insertBtn = document.createElement('div');
                    insertBtn.className = 'insert-btn';
                    insertBtn.textContent = 'Insert';
                    insertBtn.addEventListener('click', function() {
                        insertImageAtCursor(event.target.result);
                    });
                    
                    preview.appendChild(img);
                    preview.appendChild(insertBtn);
                    previewWrapper.appendChild(preview);
                    
                    // Add size controls
                    const sizeControls = document.createElement('div');
                    sizeControls.className = 'image-size-controls';
                    
                    const smallBtn = document.createElement('button');
                    smallBtn.className = 'image-size-btn';
                    smallBtn.textContent = 'Small';
                    smallBtn.type = 'button';
                    smallBtn.addEventListener('click', function() {
                        insertImageAtCursor(event.target.result, '30%');
                    });
                    
                    const mediumBtn = document.createElement('button');
                    mediumBtn.className = 'image-size-btn';
                    mediumBtn.textContent = 'Medium';
                    mediumBtn.type = 'button';
                    mediumBtn.addEventListener('click', function() {
                        insertImageAtCursor(event.target.result, '50%');
                    });
                    
                    const fullBtn = document.createElement('button');
                    fullBtn.className = 'image-size-btn';
                    fullBtn.textContent = 'Full';
                    fullBtn.type = 'button';
                    fullBtn.addEventListener('click', function() {
                        insertImageAtCursor(event.target.result, '100%');
                    });

                    const captionBtn = document.createElement('button');
                    captionBtn.className = 'image-size-btn';
                    captionBtn.textContent = 'Caption';
                    captionBtn.type = 'button';
                    captionBtn.addEventListener('click', function() {
                        insertImageWithCaption(event.target.result);
                    });
                    
                    sizeControls.appendChild(smallBtn);
                    sizeControls.appendChild(mediumBtn);
                    sizeControls.appendChild(fullBtn);
                    sizeControls.appendChild(captionBtn);
                    
                    previewWrapper.appendChild(sizeControls);
                    previewContainer.appendChild(previewWrapper);
                };
                
                reader.readAsDataURL(file);
            }
        });
        
        // Insert image at cursor position in editor
        function insertImageAtCursor(imgSrc, size = '100%') {
            const editor = document.getElementById('content');
            editor.focus();
            
            // Get current selection position
            const selection = window.getSelection();
            const range = selection.getRangeAt(0);
            
            // Create and insert the image element
            const imgElement = document.createElement('img');
            imgElement.src = imgSrc;
            imgElement.style.maxWidth = size;
            imgElement.style.height = 'auto';
            imgElement.className = 'img-fluid rounded';
            imgElement.setAttribute('data-bs-toggle', 'modal');
            imgElement.setAttribute('data-bs-target', '#imageModal');
            imgElement.onclick = function() {
                document.getElementById('modalImage').src = this.src;
            };
            
            // For alignment, add a wrapper div
            const wrapper = document.createElement('div');
            wrapper.className = 'text-center';
            wrapper.style.margin = '15px 0';
            wrapper.appendChild(imgElement);
            
            range.deleteContents();
            range.insertNode(wrapper);
            
            // Move cursor after the inserted image
            range.setStartAfter(wrapper);
            range.setEndAfter(wrapper);
            selection.removeAllRanges();
            selection.addRange(range);
            
            // Trigger input event to ensure content is updated
            editor.dispatchEvent(new Event('input'));
            updateWordCount();
        }

        // Insert image with caption
        function insertImageWithCaption(imgSrc, size = '80%') {
            const caption = prompt('Enter image caption:', '');
            if (caption === null) return; // User cancelled
            
            const editor = document.getElementById('content');
            editor.focus();
            
            // Get current selection position
            const selection = window.getSelection();
            const range = selection.getRangeAt(0);
            
            // Create the figure with caption
            const figure = document.createElement('figure');
            figure.className = 'image-with-caption';
            
            const img = document.createElement('img');
            img.src = imgSrc;
            img.style.maxWidth = size;
            img.className = 'img-fluid rounded';
            img.setAttribute('data-bs-toggle', 'modal');
            img.setAttribute('data-bs-target', '#imageModal');
            img.onclick = function() {
                document.getElementById('modalImage').src = this.src;
            };
            
            const figcaption = document.createElement('figcaption');
            figcaption.className = 'image-caption';
            figcaption.textContent = caption;
            
            figure.appendChild(img);
            figure.appendChild(figcaption);
            
            range.deleteContents();
            range.insertNode(figure);
            
            // Move cursor after the inserted figure
            range.setStartAfter(figure);
            range.setEndAfter(figure);
            selection.removeAllRanges();
            selection.addRange(range);
            
            // Trigger input event
            editor.dispatchEvent(new Event('input'));
            updateWordCount();
        }
        
        // Initialize word counter and format state checker
        document.getElementById('content').addEventListener('input', updateWordCount);
        
        // Add event listener for selection changes to update format state
        document.getElementById('content').addEventListener('mouseup', checkFormatState);
        document.getElementById('content').addEventListener('keyup', checkFormatState);
        document.getElementById('content').addEventListener('click', checkFormatState);
        
        // Setup keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && !e.shiftKey && !e.altKey) {
                const editor = document.getElementById('content');
                if (document.activeElement === editor) {
                    switch (e.key.toLowerCase()) {
                        case 'b': // Bold
                            e.preventDefault();
                            formatText('bold');
                            break;
                        case 'i': // Italic
                            e.preventDefault();
                            formatText('italic');
                            break;
                        case 'u': // Underline
                            e.preventDefault();
                            formatText('underline');
                            break;
                        case 'k': // Insert link
                            e.preventDefault();
                            formatText('link');
                            break;
                        case 's': // Save draft
                            e.preventDefault();
                            // Toggle status to draft and save
                            document.getElementById('status').value = 'draft';
                            document.getElementById('content_input').value = editor.innerHTML;
                            // Alert user that draft is being saved
                            alert('Saving as draft...');
                            document.querySelector('form').submit();
                            break;
                    }
                }
            }
        });

        // Add uploaded images to preview container
        window.addEventListener('DOMContentLoaded', function() {
            const previewContainer = document.getElementById('image-previews');
            updateWordCount();
            checkFormatState();
            
            if (uploadedImages.length > 0) {
                uploadedImages.forEach(function(imagePath) {
                    const previewWrapper = document.createElement('div');
                    previewWrapper.className = 'post-image-preview-wrapper';
                    
                    const preview = document.createElement('div');
                    preview.className = 'post-image-preview';
                    
                    const img = document.createElement('img');
                    img.src = '../' + imagePath;
                    
                    const insertBtn = document.createElement('div');
                    insertBtn.className = 'insert-btn';
                    insertBtn.textContent = 'Insert';
                    insertBtn.addEventListener('click', function() {
                        insertImageAtCursor('../' + imagePath);
                    });
                    
                    preview.appendChild(img);
                    preview.appendChild(insertBtn);
                    previewWrapper.appendChild(preview);
                    
                    // Add size controls
                    const sizeControls = document.createElement('div');
                    sizeControls.className = 'image-size-controls';
                    
                    const smallBtn = document.createElement('button');
                    smallBtn.className = 'image-size-btn';
                    smallBtn.textContent = 'Small';
                    smallBtn.type = 'button';
                    smallBtn.addEventListener('click', function() {
                        insertImageAtCursor('../' + imagePath, '30%');
                    });
                    
                    const mediumBtn = document.createElement('button');
                    mediumBtn.className = 'image-size-btn';
                    mediumBtn.textContent = 'Medium';
                    mediumBtn.type = 'button';
                    mediumBtn.addEventListener('click', function() {
                        insertImageAtCursor('../' + imagePath, '50%');
                    });
                    
                    const fullBtn = document.createElement('button');
                    fullBtn.className = 'image-size-btn';
                    fullBtn.textContent = 'Full';
                    fullBtn.type = 'button';
                    fullBtn.addEventListener('click', function() {
                        insertImageAtCursor('../' + imagePath, '100%');
                    });

                    const captionBtn = document.createElement('button');
                    captionBtn.className = 'image-size-btn';
                    captionBtn.textContent = 'Caption';
                    captionBtn.type = 'button';
                    captionBtn.addEventListener('click', function() {
                        insertImageWithCaption('../' + imagePath);
                    });
                    
                    sizeControls.appendChild(smallBtn);
                    sizeControls.appendChild(mediumBtn);
                    sizeControls.appendChild(fullBtn);
                    sizeControls.appendChild(captionBtn);
                    
                    previewWrapper.appendChild(sizeControls);
                    previewContainer.appendChild(previewWrapper);
                });
            }
        });
    </script>
</body>
</html>

<?php
// Helper function to format bytes into human readable format
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
?> 