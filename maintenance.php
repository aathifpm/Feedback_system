<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Get maintenance message from URL parameter or session
$message = $_GET['message'] ?? $_SESSION['maintenance_message'] ?? 'System is temporarily under maintenance. Please try again later.';
$module = $_GET['module'] ?? 'system';

// Clear session maintenance message
unset($_SESSION['maintenance_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode - Panimalar Engineering College</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --warning-color: #f39c12;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
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
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }

        .maintenance-container {
            background: var(--bg-color);
            padding: 3rem;
            border-radius: 30px;
            box-shadow: var(--shadow);
            text-align: center;
            max-width: 600px;
            width: 100%;
            animation: fadeIn 0.8s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .maintenance-icon {
            font-size: 5rem;
            color: var(--warning-color);
            margin-bottom: 2rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }

        .maintenance-title {
            font-size: 2.5rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .maintenance-message {
            font-size: 1.2rem;
            color: #666;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .maintenance-details {
            background: rgba(243, 156, 18, 0.1);
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            border-left: 4px solid var(--warning-color);
        }

        .maintenance-details h3 {
            color: var(--warning-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .maintenance-details p {
            color: #666;
            line-height: 1.5;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-color);
            box-shadow: var(--shadow);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .college-info {
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(0,0,0,0.1);
        }

        .college-info h4 {
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .college-info p {
            color: #666;
            font-size: 0.9rem;
        }

        .refresh-timer {
            margin-top: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        @media (max-width: 768px) {
            .maintenance-container {
                padding: 2rem;
                margin: 1rem;
            }
            
            .maintenance-title {
                font-size: 2rem;
            }
            
            .maintenance-icon {
                font-size: 4rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="maintenance-icon">
            <i class="fas fa-tools"></i>
        </div>
        
        <h1 class="maintenance-title">Under Maintenance</h1>
        
        <p class="maintenance-message">
            <?php echo htmlspecialchars($message); ?>
        </p>
        
        <div class="maintenance-details">
            <h3><i class="fas fa-info-circle"></i> What's Happening?</h3>
            <p>We're currently performing scheduled maintenance to improve your experience. This includes system updates, security enhancements, and performance optimizations.</p>
        </div>
        
        <div class="action-buttons">
            <a href="index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Home
            </a>
            <button onclick="location.reload()" class="btn btn-secondary">
                <i class="fas fa-sync-alt"></i> Refresh Page
            </button>
        </div>
        
        <div class="college-info">
            <h4>Panimalar Engineering College</h4>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
            <p>For urgent matters, please contact the administration office.</p>
        </div>
        
        <div class="refresh-timer">
            <p><i class="fas fa-clock"></i> This page will automatically refresh in <span id="countdown">30</span> seconds</p>
        </div>
    </div>

    <script>
        // Auto-refresh countdown
        let countdown = 30;
        const countdownElement = document.getElementById('countdown');
        
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                location.reload();
            }
        }, 1000);
        
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            const icon = document.querySelector('.maintenance-icon i');
            
            setInterval(() => {
                icon.style.transform = 'rotate(360deg)';
                setTimeout(() => {
                    icon.style.transform = 'rotate(0deg)';
                }, 500);
            }, 3000);
        });
    </script>
</body>
</html>