<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set default values for unknown users
$user_name = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Guest';
$user_role = ucfirst($_SESSION['role'] ?? 'Guest');
$is_logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panimalar Engineering College - Admin Panel</title>
    <link rel="icon" href="../college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Crimson+Pro:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --header-height: 90px;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --college-name-font: 'Playfair Display', serif;
            --college-text-font: 'Crimson Pro', serif;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            min-height: 100vh;
            padding-top: var(--header-height);
        }

        .header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: var(--header-height);
            background: var(--bg-color);
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2.5rem;
            z-index: 1000;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            max-width: 65%;
            overflow: hidden;
        }

        .logo-container {
            position: relative;
            width: 60px;
            height: 60px;
            min-width: 60px;
            border-radius: 50%;
            box-shadow: var(--shadow);
            overflow: hidden;
            transition: transform 0.3s ease;
            margin: 0.5rem 0;
        }

        .logo-container:hover {
            transform: scale(1.05);
        }

        .logo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .college-info {
            padding-left: 1.5rem;
            position: relative;
            margin: 0.5rem 0;
            overflow: hidden;
            flex-shrink: 1;
        }

        .college-info::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 2px;
            height: 70%;
            background: linear-gradient(to bottom, var(--primary-color), transparent);
        }

        .college-info h1 {
            font-family: var(--college-name-font);
            font-size: 1.6rem;
            background: linear-gradient(135deg, var(--text-color) 0%, #34495e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 0.3rem;
            letter-spacing: 0.8px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .college-info p {
            font-family: var(--college-text-font);
            font-size: 0.9rem;
            line-height: 1.3;
            margin-bottom: 0.2rem;
            color: #666;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .college-info p::before {
            display: none;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 2.5rem;
        }

        .user-profile {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.5rem 0.8rem;
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 0.5rem 0;
            min-width: 150px; /* Increased min-width */
        }

        .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(145deg, var(--primary-color), #2980b9);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: inset 2px 2px 5px rgba(0,0,0,0.2);
        }

        .user-info1 {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1; /* Take up remaining space */
            padding: 0 0.3rem;
        }

        .user-name {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-color);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%; /* Use full width */
            height: 100%;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .user-role {
            display: none;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 0.5rem;
            background: var(--bg-color);
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 0.6rem;
            min-width: 180px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(10px);
            transition: all 0.3s ease;
        }

        .user-profile:hover .dropdown-menu {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1rem;
            color: var(--text-color);
            text-decoration: none;
            font-size: 0.9rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .dropdown-item:hover {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
            transform: translateX(5px);
        }

        .dropdown-item i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
        }

        @media (max-width: 1200px) {
            .header-left {
                max-width: 60%;
                gap: 1.2rem;
            }
            .college-info h1 {
                font-size: 1.4rem;
                letter-spacing: 0.6px;
            }
            .college-info p {
                font-size: 0.85rem;
            }
            .logo-container {
                width: 55px;
                height: 55px;
                min-width: 55px;
            }
        }

        @media (max-width: 992px) {
            :root {
                --header-height: 80px;
            }
            .header-left {
                max-width: 55%;
                gap: 1rem;
            }
            .college-info h1 {
                font-size: 1.2rem;
                letter-spacing: 0.4px;
            }
            .college-info p {
                font-size: 0.8rem;
            }
            .logo-container {
                width: 50px;
                height: 50px;
                min-width: 50px;
            }
            .college-info {
                padding-left: 1.2rem;
            }
            .mobile-menu-btn {
                display: block;
            }
            .header-right {
                gap: 1rem;
            }
            .user-info1 {
                display: none;
            }
            .user-profile {
                padding: 0.4rem;
                min-width: auto;
            }
            .user-avatar {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 768px) {
            .header-left {
                max-width: 70%;
                gap: 0.8rem;
            }
            .college-info h1 {
                font-size: 1.1rem;
                letter-spacing: 0.3px;
                margin-bottom: 0.2rem;
            }
            .college-info p {
                font-size: 0.75rem;
            }
            .logo-container {
                width: 45px;
                height: 45px;
                min-width: 45px;
            }
            .college-info {
                padding-left: 1rem;
            }
        }

        @media (max-width: 480px) {
            .header-left {
                max-width: 75%;
                gap: 0.6rem;
            }
            .college-info h1 {
                font-size: 1rem;
                letter-spacing: 0.2px;
            }
            .college-info p {
                font-size: 0.7rem;
            }
            .logo-container {
                width: 40px;
                height: 40px;
                min-width: 40px;
            }
            .college-info {
                padding-left: 0.8rem;
            }
            .college-info p:last-child {
                display: none;
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-left">
            <div class="logo-container">
                <img src="../college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
            </div>
            <div class="college-info">
                <h1>Panimalar Engineering College</h1>
                <p>An Autonomous Institution, Affiliated to Anna University</p>
                <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123</p>
            </div>
        </div>
        
        <div class="header-right">
            <?php if ($is_logged_in): ?>
            <div class="user-profile">
                <div class="user-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div class="user-info1">
                    <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                </div>
                <div class="dropdown-menu">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fas fa-user-circle"></i> Profile
                    </a>
                    <a href="change_password.php" class="dropdown-item">
                        <i class="fas fa-key"></i> Change Password
                    </a>
                    <a href="../logout.php" class="dropdown-item">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>
</body>
</html>
