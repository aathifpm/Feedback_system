<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Include PHPMailer library
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load Composer's autoloader
if (file_exists('vendor/autoload.php')) {
    require 'vendor/autoload.php';
}

$error = '';
$success = '';
$email_sent = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        
        if (empty($email)) {
            throw new Exception("Please enter your email address.");
        }

        // Check which user table contains this email
        $user_tables = [
            'admin' => 'admin_users',
            'faculty' => 'faculty',
            'hod' => 'hods',
            'student' => 'students'
        ];
        
        $user_id = null;
        $user_type = null;
        $user_name = null;
        
        foreach ($user_tables as $type => $table) {
            $query = "SELECT id, name FROM $table WHERE email = ? AND is_active = TRUE";
            if ($table === 'admin_users') {
                $query = "SELECT id, username as name FROM $table WHERE email = ? AND is_active = TRUE";
            }
            
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            
            if ($user = mysqli_fetch_assoc($result)) {
                $user_id = $user['id'];
                $user_type = $type;
                $user_name = $user['name'];
                break;
            }
        }
        
        if (!$user_id) {
            throw new Exception("No account found with this email address.");
        }
        
        // Generate a unique token
        $token = bin2hex(random_bytes(32));
        
        // Token expiry time (24 hours from now)
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Delete any existing tokens for this user
        $delete_query = "DELETE FROM password_reset_tokens WHERE user_id = ? AND user_type = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "is", $user_id, $user_type);
        mysqli_stmt_execute($delete_stmt);
        
        // Store the new token
        $insert_query = "INSERT INTO password_reset_tokens (user_id, user_type, token, expires_at) 
                        VALUES (?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_query);
        mysqli_stmt_bind_param($insert_stmt, "isss", $user_id, $user_type, $token, $expires_at);
        
        if (mysqli_stmt_execute($insert_stmt)) {
            // Log the password reset request
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                         VALUES (?, ?, 'password_reset_request', ?, ?, ?)";
            $log_details = json_encode([
                'email' => $email,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $log_stmt = mysqli_prepare($conn, $log_query);
            mysqli_stmt_bind_param($log_stmt, "issss", 
                $user_id, 
                $user_type,
                $log_details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            );
            mysqli_stmt_execute($log_stmt);
            
            // Create reset link
            $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . 
                          "://" . $_SERVER['HTTP_HOST'] . 
                          dirname($_SERVER['PHP_SELF']) . 
                          "/reset_password.php?token=" . $token;
            
            // Send email with the reset link
            if (sendResetEmail($email, $user_name, $reset_link)) {
                $success = "A password reset link has been sent to your email. Please check your inbox and spam folder.";
                $email_sent = true;
            } else {
                throw new Exception("Failed to send password reset email. Please try again or contact support.");
            }
        } else {
            throw new Exception("Failed to create password reset token. Please try again.");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Helper function to send reset email using PHPMailer
function sendResetEmail($email, $name, $reset_link) {
    try {
        // Create a new PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;  // Enable debugging
        // Start output buffering to capture debug output
        ob_start();
        $mail->isSMTP();
        $mail->Host       =   // Hostinger SMTP server
        $mail->SMTPAuth   = true;
        $mail->Username   =   // Your Hostinger email address
        $mail->Password   =     // Your email password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SSL for port 465
        $mail->Port       = 
        $mail->Timeout   = 10; // Timeout in seconds
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Recipients
        $mail->setFrom('no-reply-passwordreset@ads-panimalar.in', 'Panimalar Engineering College');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Panimalar Engineering College';
        
        // Create email body
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                }
                .container {
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                    border: 1px solid #ddd;
                    border-radius: 5px;
                }
                .header {
                    text-align: center;
                    padding-bottom: 10px;
                    border-bottom: 1px solid #eee;
                    margin-bottom: 20px;
                }
                .logo {
                    max-width: 150px;
                    height: auto;
                }
                .button {
                    display: inline-block;
                    padding: 10px 20px;
                    background-color: #2ecc71;
                    color: white;
                    text-decoration: none;
                    border-radius: 5px;
                    margin: 20px 0;
                }
                .footer {
                    margin-top: 30px;
                    font-size: 12px;
                    text-align: center;
                    color: #777;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Panimalar Engineering College</h2>
                </div>
                
                <p>Dear ' . htmlspecialchars($name) . ',</p>
                
                <p>We received a request to reset your password. If you didn\'t make this request, you can safely ignore this email.</p>
                
                <p>To reset your password, please click the button below:</p>
                
                <p style="text-align: center;">
                    <a href="' . $reset_link . '" class="button">Reset Password</a>
                </p>
                
                <p>Alternatively, you can copy and paste the following link into your browser:</p>
                
                <p style="word-break: break-all;">' . $reset_link . '</p>
                
                <p>This link will expire in 24 hours for security reasons.</p>
                
                <p>If you need any assistance, please contact our support team.</p>
                
                <p>Regards,<br>Panimalar Engineering College</p>
                
                <div class="footer">
                    <p>This is an automated email. Please do not reply to this message.</p>
                    <p>Panimalar Engineering College, Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Plain text version for non-HTML mail clients
        $mail->AltBody = "Dear " . $name . ",\n\n" .
                        "We received a request to reset your password. If you didn't make this request, you can safely ignore this email.\n\n" .
                        "To reset your password, please copy and paste the following link into your browser:\n" .
                        $reset_link . "\n\n" .
                        "This link will expire in 24 hours for security reasons.\n\n" .
                        "If you need any assistance, please contact our support team.\n\n" .
                        "Regards,\nPanimalar Engineering College";
        
        $mail->send();
        // Get and clear debug output
        $smtp_debug = ob_get_clean();
        error_log("SMTP Debug: " . $smtp_debug);
        return true;
    } catch (Exception $e) {
        // Get and clear debug output
        $smtp_debug = ob_get_clean();
        error_log("SMTP Debug: " . $smtp_debug);
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Panimalar Engineering College</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #2ecc71;  /* Green theme for password reset */
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
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            width: 100%;
            padding: 1.5rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            max-width: 120px;
            height: auto;
            margin-bottom: 1rem;
        }

        .college-info h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .college-info p {
            color: #666;
            line-height: 1.4;
        }

        .reset-container {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 450px;
            margin: 2rem auto;
            position: relative;
            overflow: hidden;
        }

        .reset-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
        }

        .reset-title {
            text-align: center;
            color: var(--text-color);
            margin-bottom: 2rem;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .input-field {
            width: 100%;
            padding: 0.8rem 1rem;
            padding-left: 2.5rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .input-field:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-group i {
            position: absolute;
            left: 1rem;
            top: 2.3rem;
            color: var(--primary-color);
        }

        .btn-reset {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
            background: #27ae60;
        }

        .error-message {
            background: #fee;
            color: #e74c3c;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .success-message {
            background: #efc;
            color: #2ecc71;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .links {
            margin-top: 1.5rem;
            text-align: center;
        }

        .links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .links a:hover {
            color: #27ae60;
        }

        .divider {
            margin: 0 1rem;
            color: #666;
        }

        .role-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            text-align: center;
        }

        .info-text {
            text-align: center;
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.6;
        }

        @media (max-width: 480px) {
            .reset-container {
                width: 95%;
                padding: 1.5rem;
            }

            .college-info h1 {
                font-size: 1.5rem;
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

    <div class="reset-container">
        <div class="role-icon">
            <i class="fas fa-key"></i>
        </div>
        <h2 class="reset-title">Reset Password</h2>
        
        <?php if (!$email_sent): ?>
            <p class="info-text">
                Enter your email address and we'll send you a link to reset your password.
            </p>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="" id="resetForm">
                <div class="form-group">
                    <label for="email">Email</label>
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" class="input-field" 
                           placeholder="Enter your email address" required>
                </div>

                <button type="submit" class="btn-reset">
                    <i class="fas fa-paper-plane"></i>
                    Send Reset Link
                </button>

                <div class="links">
                    <a href="index.php">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                    <span class="divider">|</span>
                    <a href="admin_login.php">
                        <i class="fas fa-user-shield"></i> Admin Login
                    </a>
                </div>
            </form>
        <?php else: ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
            
            <div class="links">
                <a href="index.php">
                    <i class="fas fa-home"></i> Back to Home
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Form validation
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const email = document.getElementById('email').value;
            
            if (!email) {
                e.preventDefault();
                alert('Please enter your email address');
                return;
            }
            
            // Basic email validation
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return;
            }
        });
    </script>

    <?php include 'footer.php'; ?>
</body>
</html>
