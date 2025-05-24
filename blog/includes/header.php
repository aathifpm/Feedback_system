<?php
require_once dirname(__FILE__) . '/../functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Panimalar Engineering College Blog' : 'Panimalar Engineering College Blog'; ?></title>
    <link rel="icon" href="../college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #f39c12;
            --text-color: #2c3e50;
            --bg-color: #f9f9f9;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-color);
            background-color: var(--bg-color);
            line-height: 1.6;
        }
        
        .navbar {
            background-color: #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .nav-link {
            color: var(--text-color);
            font-weight: 500;
            margin: 0 10px;
            transition: color 0.3s;
        }
        
        .nav-link:hover {
            color: var(--primary-color);
        }
        
        .blog-header {
            padding: 2rem 0;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .blog-title {
            font-weight: 700;
            font-size: 2.5rem;
        }
        
        .blog-description {
            font-weight: 300;
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .featured-post, .post-card {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            margin-bottom: 30px;
        }
        
        .featured-post:hover, .post-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .post-img {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        
        .post-content {
            padding: 20px;
            background: white;
        }
        
        .post-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .post-excerpt {
            color: #666;
            margin-bottom: 15px;
        }
        
        .post-meta {
            font-size: 0.85rem;
            color: #888;
        }
        
        .btn-read-more {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .btn-read-more:hover {
            background: var(--secondary-color);
            color: white;
        }
        
        .sidebar-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .sidebar-title {
            font-size: 1.2rem;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: 15px;
        }
        
        .category-list, .tag-list {
            list-style: none;
            padding: 0;
        }
        
        .category-list li, .tag-list li {
            margin-bottom: 10px;
        }
        
        .category-list a, .tag-list a {
            color: var(--text-color);
            text-decoration: none;
            transition: color 0.3s;
        }
        
        .category-list a:hover, .tag-list a:hover {
            color: var(--primary-color);
        }
        
        .badge-category, .badge-tag {
            background-color: var(--primary-color);
            color: white;
            font-size: 0.8rem;
            border-radius: 20px;
            padding: 3px 10px;
        }
        
        .search-form .form-control {
            border-radius: 25px;
            padding-left: 20px;
        }
        
        .search-form .btn {
            border-radius: 25px;
            padding: 0.375rem 1.5rem;
        }
    </style>
    <?php if (isset($extra_css)): ?>
        <style>
            <?php echo $extra_css; ?>
        </style>
    <?php endif; ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">PEC Blog</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="categoriesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Categories
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="categoriesDropdown">
                            <?php
                            $categories = get_categories();
                            foreach ($categories as $category) {
                                echo '<li><a class="dropdown-item" href="category.php?slug=' . $category['slug'] . '">' . $category['name'] . '</a></li>';
                            }
                            ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="about.php">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="contact.php">Contact</a>
                    </li>
                </ul>
                <form class="d-flex search-form" action="search.php" method="get">
                    <input class="form-control me-2" type="search" name="q" placeholder="Search blog..." aria-label="Search">
                    <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search"></i></button>
                </form>
                <ul class="navbar-nav ms-auto">
                    <?php if (isset($_SESSION['blog_user_id'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-user-circle me-1"></i> <?php echo $_SESSION['blog_username']; ?>
                            </a>
                            <ul class="dropdown-menu" aria-labelledby="userDropdown">
                                <li><a class="dropdown-item" href="admin/dashboard.php">Dashboard</a></li>
                                <li><a class="dropdown-item" href="admin/profile.php">Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="login.php">Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <?php if (isset($show_header) && $show_header): ?>
    <header class="blog-header">
        <div class="container">
            <h1 class="blog-title">Panimalar Engineering College Blog</h1>
            <p class="blog-description">Insights, News, and Knowledge from Our Campus</p>
        </div>
    </header>
         <?php endif; ?> 