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

// Check if user has admin privileges
if ($role !== 'admin') {
    header('Location: dashboard.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'general':
                $site_title = mysqli_real_escape_string($blog_conn, $_POST['site_title']);
                $site_description = mysqli_real_escape_string($blog_conn, $_POST['site_description']);
                $posts_per_page = (int)$_POST['posts_per_page'];
                $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
                $moderate_comments = isset($_POST['moderate_comments']) ? 1 : 0;
                
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$site_title' WHERE setting_key = 'site_title'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$site_description' WHERE setting_key = 'site_description'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$posts_per_page' WHERE setting_key = 'posts_per_page'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$allow_comments' WHERE setting_key = 'allow_comments'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$moderate_comments' WHERE setting_key = 'moderate_comments'");
                
                $message = "General settings updated successfully";
                break;
                
            case 'appearance':
                $theme = mysqli_real_escape_string($blog_conn, $_POST['theme']);
                $header_image = mysqli_real_escape_string($blog_conn, $_POST['header_image']);
                $footer_text = mysqli_real_escape_string($blog_conn, $_POST['footer_text']);
                
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$theme' WHERE setting_key = 'theme'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$header_image' WHERE setting_key = 'header_image'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$footer_text' WHERE setting_key = 'footer_text'");
                
                $message = "Appearance settings updated successfully";
                break;
                
            case 'social':
                $facebook_url = mysqli_real_escape_string($blog_conn, $_POST['facebook_url']);
                $twitter_url = mysqli_real_escape_string($blog_conn, $_POST['twitter_url']);
                $instagram_url = mysqli_real_escape_string($blog_conn, $_POST['instagram_url']);
                $linkedin_url = mysqli_real_escape_string($blog_conn, $_POST['linkedin_url']);
                
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$facebook_url' WHERE setting_key = 'facebook_url'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$twitter_url' WHERE setting_key = 'twitter_url'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$instagram_url' WHERE setting_key = 'instagram_url'");
                mysqli_query($blog_conn, "UPDATE blog_settings SET 
                    value = '$linkedin_url' WHERE setting_key = 'linkedin_url'");
                
                $message = "Social media settings updated successfully";
                break;
        }
    }
}

// Get current settings
$settings_query = "SELECT * FROM blog_settings";
$settings_result = mysqli_query($blog_conn, $settings_query);
$settings = [];
while ($row = mysqli_fetch_assoc($settings_result)) {
    $settings[$row['setting_key']] = $row['value'];
}

$page_title = "Settings";
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
        .nav-pills .nav-link.active {
            background-color: #343a40;
        }
        .nav-pills .nav-link {
            color: #343a40;
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
                    <h2>Settings</h2>
                </div>
                
                <?php if (isset($message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <ul class="nav nav-pills mb-4" id="settingsTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" 
                                        data-bs-target="#general" type="button" role="tab">
                                    General
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="appearance-tab" data-bs-toggle="tab" 
                                        data-bs-target="#appearance" type="button" role="tab">
                                    Appearance
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="social-tab" data-bs-toggle="tab" 
                                        data-bs-target="#social" type="button" role="tab">
                                    Social Media
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content" id="settingsTabContent">
                            <!-- General Settings -->
                            <div class="tab-pane fade show active" id="general" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="action" value="general">
                                    
                                    <div class="mb-3">
                                        <label for="site_title" class="form-label">Site Title</label>
                                        <input type="text" class="form-control" id="site_title" name="site_title" 
                                               value="<?php echo htmlspecialchars($settings['site_title'] ?? ''); ?>" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="site_description" class="form-label">Site Description</label>
                                        <textarea class="form-control" id="site_description" name="site_description" 
                                                  rows="3"><?php echo htmlspecialchars($settings['site_description'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="posts_per_page" class="form-label">Posts Per Page</label>
                                        <input type="number" class="form-control" id="posts_per_page" name="posts_per_page" 
                                               value="<?php echo (int)($settings['posts_per_page'] ?? 10); ?>" min="1" max="50" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="allow_comments" 
                                                   name="allow_comments" <?php echo ($settings['allow_comments'] ?? '') == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="allow_comments">Allow Comments</label>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="moderate_comments" 
                                                   name="moderate_comments" <?php echo ($settings['moderate_comments'] ?? '') == '1' ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="moderate_comments">Moderate Comments</label>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Save General Settings</button>
                                </form>
                            </div>
                            
                            <!-- Appearance Settings -->
                            <div class="tab-pane fade" id="appearance" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="action" value="appearance">
                                    
                                    <div class="mb-3">
                                        <label for="theme" class="form-label">Theme</label>
                                        <select class="form-select" id="theme" name="theme">
                                            <option value="default" <?php echo ($settings['theme'] ?? '') === 'default' ? 'selected' : ''; ?>>Default</option>
                                            <option value="dark" <?php echo ($settings['theme'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                                            <option value="light" <?php echo ($settings['theme'] ?? '') === 'light' ? 'selected' : ''; ?>>Light</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="header_image" class="form-label">Header Image URL</label>
                                        <input type="url" class="form-control" id="header_image" name="header_image" 
                                               value="<?php echo htmlspecialchars($settings['header_image'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="footer_text" class="form-label">Footer Text</label>
                                        <textarea class="form-control" id="footer_text" name="footer_text" 
                                                  rows="3"><?php echo htmlspecialchars($settings['footer_text'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Save Appearance Settings</button>
                                </form>
                            </div>
                            
                            <!-- Social Media Settings -->
                            <div class="tab-pane fade" id="social" role="tabpanel">
                                <form method="POST">
                                    <input type="hidden" name="action" value="social">
                                    
                                    <div class="mb-3">
                                        <label for="facebook_url" class="form-label">Facebook URL</label>
                                        <input type="url" class="form-control" id="facebook_url" name="facebook_url" 
                                               value="<?php echo htmlspecialchars($settings['facebook_url'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="twitter_url" class="form-label">Twitter URL</label>
                                        <input type="url" class="form-control" id="twitter_url" name="twitter_url" 
                                               value="<?php echo htmlspecialchars($settings['twitter_url'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="instagram_url" class="form-label">Instagram URL</label>
                                        <input type="url" class="form-control" id="instagram_url" name="instagram_url" 
                                               value="<?php echo htmlspecialchars($settings['instagram_url'] ?? ''); ?>">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="linkedin_url" class="form-label">LinkedIn URL</label>
                                        <input type="url" class="form-control" id="linkedin_url" name="linkedin_url" 
                                               value="<?php echo htmlspecialchars($settings['linkedin_url'] ?? ''); ?>">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary">Save Social Media Settings</button>
                                </form>
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