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

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['media'])) {
    $upload_dir = '../uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $files = $_FILES['media'];
    $success_count = 0;
    $error_count = 0;
    
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === 0) {
            $file_extension = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            $new_filename = uniqid() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($files['tmp_name'][$i], $upload_path)) {
                $success_count++;
            } else {
                $error_count++;
            }
        }
    }
    
    if ($success_count > 0) {
        $message = "Successfully uploaded $success_count file(s)";
        if ($error_count > 0) {
            $message .= " with $error_count error(s)";
        }
    } else {
        $message = "Failed to upload files";
    }
}

// Handle file deletion
if (isset($_POST['delete']) && isset($_POST['file'])) {
    $file = '../' . $_POST['file'];
    if (file_exists($file) && unlink($file)) {
        $message = "File deleted successfully";
    } else {
        $message = "Failed to delete file";
    }
}

// Get all media files
$media_dir = '../uploads/';
$media_files = [];
if (file_exists($media_dir)) {
    $files = scandir($media_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $file_path = $media_dir . $file;
            if (is_file($file_path)) {
                $media_files[] = [
                    'name' => $file,
                    'path' => 'uploads/' . $file,
                    'size' => filesize($file_path),
                    'type' => mime_content_type($file_path),
                    'modified' => filemtime($file_path)
                ];
            }
        }
    }
}

// Sort files by modification time (newest first)
usort($media_files, function($a, $b) {
    return $b['modified'] - $a['modified'];
});

$page_title = "Media Library";
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
        .media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        .media-item {
            position: relative;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            overflow: hidden;
        }
        .media-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        .media-item .media-info {
            padding: 0.5rem;
            background-color: #fff;
        }
        .media-item .media-actions {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            display: none;
        }
        .media-item:hover .media-actions {
            display: block;
        }
        .media-item .media-actions .btn {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
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
                            <a href="media.php" class="nav-link text-white active">
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
                    <h2>Media Library</h2>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                        <i class="fas fa-upload me-1"></i> Upload Media
                    </button>
                </div>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="media-grid">
                    <?php foreach ($media_files as $file): ?>
                        <div class="media-item">
                            <?php if (strpos($file['type'], 'image/') === 0): ?>
                                <img src="../<?php echo htmlspecialchars($file['path']); ?>" alt="<?php echo htmlspecialchars($file['name']); ?>">
                            <?php else: ?>
                                <div class="text-center p-4">
                                    <i class="fas fa-file fa-3x mb-2"></i>
                                    <p class="mb-0"><?php echo htmlspecialchars($file['name']); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="media-info">
                                <small class="text-muted"><?php echo format_file_size($file['size']); ?></small>
                            </div>
                            
                            <div class="media-actions">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-light btn-sm" 
                                            onclick="copyToClipboard('<?php echo htmlspecialchars($file['path']); ?>')">
                                        <i class="fas fa-copy"></i>
                                    </button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this file?');">
                                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['path']); ?>">
                                        <button type="submit" name="delete" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Upload Media</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="media" class="form-label">Select Files</label>
                            <input type="file" class="form-control" id="media" name="media[]" multiple required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Upload</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('File path copied to clipboard!');
            }).catch(err => {
                console.error('Failed to copy text: ', err);
            });
        }
    </script>
</body>
</html> 