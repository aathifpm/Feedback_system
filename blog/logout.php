<?php
session_start();

// Unset all blog-related session variables
unset($_SESSION['blog_user_id']);
unset($_SESSION['blog_username']);
unset($_SESSION['blog_role']);

// Redirect to login page
header('Location: login.php');
exit;
?> 