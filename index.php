<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panimalar Engineering College - Student Portal</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --accent-color: #f39c12;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
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
        }

        .header {
            width: 100%;
            padding: 2rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            max-width: 150px;
            height: auto;
            margin-bottom: 1rem;
        }

        .college-info h1 {
            font-size: 2rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .college-info p {
            color: #666;
            line-height: 1.4;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
        }

        .welcome-section {
            text-align: center;
            margin: 4rem auto;
            max-width: 1000px;
            padding: 2rem;
            background: var(--bg-color);
            border-radius: 30px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .welcome-section h2 {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-transform: uppercase;
            letter-spacing: 2px;
            position: relative;
            display: inline-block;
        }

        .welcome-section h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 50%;
            height: 3px;
            background: var(--accent-color);
            border-radius: 2px;
        }

        .welcome-section p {
            font-size: 1.2rem;
            color: #666;
            line-height: 1.8;
            max-width: 800px;
            margin: 1.5rem auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2.5rem;
            margin: 4rem auto;
            padding: 0 2rem;
            max-width: 1200px;
        }

        .feature-card {
            background: var(--bg-color);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.8), 
                       -12px -12px 20px rgba(255,255,255, 0.7);
        }

        .feature-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            background: var(--bg-color);
            width: 80px;
            height: 80px;
            line-height: 80px;
            border-radius: 50%;
            margin: 0 auto 1.5rem;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .feature-card:hover .feature-icon {
            transform: scale(1.1);
            color: var(--accent-color);
        }

        .feature-card h3 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            position: relative;
            padding-bottom: 1rem;
        }

        .feature-card h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 2px;
            background: var(--primary-color);
            border-radius: 2px;
        }

        .login-section {
            text-align: center;
            margin: 4rem auto;
            padding: 3rem 2rem;
            background: var(--bg-color);
            border-radius: 30px;
            box-shadow: var(--shadow);
            max-width: 1000px;
        }

        .login-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
            padding: 1rem;
        }

        .login-btn {
            background: var(--bg-color);
            padding: 1.2rem 2rem;
            border-radius: 50px;
            text-decoration: none;
            color: var(--text-color);
            font-weight: 500;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            position: relative;
            overflow: hidden;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: translateX(-100%);
            transition: 0.5s;
        }

        .login-btn:hover::before {
            transform: translateX(100%);
        }

        .login-btn:hover {
            transform: translateY(-5px);
            box-shadow: 15px 15px 25px rgb(163,177,198,0.8), 
                       -15px -15px 25px rgba(255,255,255, 0.7);
            color: var(--primary-color);
        }

        .login-btn i {
            font-size: 1.2rem;
            transition: all 0.3s ease;
        }

        .login-btn:hover i {
            transform: scale(1.2);
        }

        .stats-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin: 4rem auto;
            padding: 2rem;
            max-width: 1200px;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: var(--text-color);
            font-size: 1rem;
            font-weight: 500;
        }

        .footer {
            background: var(--bg-color);
            padding: 3rem 2rem;
            text-align: center;
            box-shadow: var(--shadow);
            margin-top: 4rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .footer-section h3 {
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .footer-section p {
            color: #666;
            line-height: 1.6;
        }

        .social-links {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-links a {
            color: var(--primary-color);
            font-size: 1.5rem;
            transition: all 0.3s ease;
        }

        .social-links a:hover {
            color: var(--accent-color);
            transform: translateY(-3px);
        }

        @media (max-width: 768px) {
            .welcome-section h2 {
                font-size: 2rem;
            }

            .feature-card {
                padding: 2rem;
            }

            .login-buttons {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
            <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123.</p>
        </div>
    </div>

    <div class="container">
        <div class="welcome-section">
            <h2>Welcome to Panimalar Engineering College</h2>
            <p>Fostering excellence in education, innovation, and research. We are committed to providing a transformative learning experience that prepares students for successful careers and meaningful contributions to society.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <i class="fas fa-graduation-cap feature-icon"></i>
                <h3>Academic Excellence</h3>
                <p>Benefit from our rigorous academic programs designed to foster critical thinking, innovation, and practical skills development.</p>
            </div>

            <div class="feature-card">
                <i class="fas fa-flask feature-icon"></i>
                <h3>Research Opportunities</h3>
                <p>Engage in cutting-edge research with state-of-the-art laboratories and guidance from experienced faculty members.</p>
            </div>

            <div class="feature-card">
                <i class="fas fa-laptop-code feature-icon"></i>
                <h3>Industry Connections</h3>
                <p>Access internships, placements, and collaborative projects with our extensive network of industry partners.</p>
            </div>
        </div>

        <div class="stats-section">
            <div class="stat-card">
                <div class="stat-number">5000+</div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">200+</div>
                <div class="stat-label">Faculty Members</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">95%</div>
                <div class="stat-label">Placement Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">30+</div>
                <div class="stat-label">Years of Excellence</div>
            </div>
        </div>

        <div class="login-section">
            <h2>Access Student Portal</h2>
            <div class="login-buttons">
                <a href="student_login.php" class="login-btn">
                    <i class="fas fa-user-graduate"></i>
                    Student Login
                </a>
                <a href="faculty_login.php" class="login-btn">
                    <i class="fas fa-chalkboard-teacher"></i>
                    Faculty Login
                </a>
                <a href="hod_login.php" class="login-btn">
                    <i class="fas fa-user-tie"></i>
                    HOD Login
                </a>
                <a href="admin_login.php" class="login-btn">
                    <i class="fas fa-user-shield"></i>
                    Admin Login
                </a>
                <a href="alumni_survey.php" class="login-btn">
                    <i class="fas fa-graduation-cap"></i>
                    Alumni Survey
                </a>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>