<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';
require_once '../vendor/autoload.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin_login.php");
    exit();
}

// Include PHPMailer library
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Initialize variables
$success = [];
$error = '';
$recipients = [];
$departments = [];
$batches = [];
$sections = [];

// Get all departments
$dept_query = "SELECT id, name, code FROM departments ORDER BY name";
$dept_result = mysqli_query($conn, $dept_query);
while ($dept = mysqli_fetch_assoc($dept_result)) {
    $departments[$dept['id']] = $dept['name'] . ' (' . $dept['code'] . ')';
}

// Get all batches
$batch_query = "SELECT id, batch_name FROM batch_years ORDER BY admission_year DESC";
$batch_result = mysqli_query($conn, $batch_query);
while ($batch = mysqli_fetch_assoc($batch_result)) {
    $batches[$batch['id']] = $batch['batch_name'];
}

// Initialize sections with common values
$sections = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O'];

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        mysqli_begin_transaction($conn);

        // Validate inputs
        $subject = trim($_POST['subject']);
        $message = trim($_POST['message']);
        $recipient_type = $_POST['recipient_type'];
        $department_id = (isset($_POST['department']) && $_POST['department'] !== 'all') ? $_POST['department'] : null;
        $batch_id = (isset($_POST['batch']) && $_POST['batch'] !== 'all') ? $_POST['batch'] : null;
        $section = (isset($_POST['section']) && $_POST['section'] !== 'all') ? $_POST['section'] : null;
        
        // Debug log
        error_log("Email campaign params - Type: $recipient_type, Dept: " . ($department_id ?? 'all') . ", Batch: " . ($batch_id ?? 'all') . ", Section: " . ($section ?? 'all'));
        
        if (empty($subject)) {
            throw new Exception("Subject cannot be empty");
        }
        
        if (empty($message)) {
            throw new Exception("Message cannot be empty");
        }
        
        // Create campaign record
        $campaign_query = "INSERT INTO email_campaigns (subject, message, recipient_type, department_id, batch_id, section, created_by) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
        $campaign_stmt = mysqli_prepare($conn, $campaign_query);
        mysqli_stmt_bind_param($campaign_stmt, "sssiiis", $subject, $message, $recipient_type, $department_id, $batch_id, $section, $_SESSION['user_id']);
        mysqli_stmt_execute($campaign_stmt);
        $campaign_id = mysqli_insert_id($conn);

        // Build recipient list based on filters
        $email_list = [];
        $total_recipients = 0;
        
        switch ($recipient_type) {
            case 'faculty':
                $query = "SELECT f.id, f.email, f.name 
                         FROM faculty f 
                         WHERE f.is_active = 1 
                         AND f.email IS NOT NULL 
                         AND f.email != ''";
                
                $params = [];
                $types = "";
                
                if ($department_id && $department_id !== 'all') {
                    $query .= " AND f.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                
                error_log("Faculty Query: " . $query);
                if (!empty($params)) {
                    error_log("Faculty Query Params: " . print_r($params, true));
                }
                
                $stmt = mysqli_prepare($conn, $query);
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }
                
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (!$result) {
                    throw new Exception("Failed to fetch faculty: " . mysqli_error($conn));
                }
                
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'email' => $row['email'],
                        'name' => $row['name'],
                        'type' => 'faculty'
                    ];
                }
                
                // Debug log the count
                error_log("Found " . count($email_list) . " faculty recipients");
                break;
                
            case 'students':
                $query = "SELECT s.id, s.email, s.name 
                         FROM students s 
                         WHERE s.is_active = 1 
                         AND s.email IS NOT NULL 
                         AND s.email != ''";
                
                $params = [];
                $types = "";
                
                if ($department_id) {
                    $query .= " AND s.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                if ($batch_id) {
                    $query .= " AND s.batch_id = ?";
                    $params[] = $batch_id;
                    $types .= "i";
                }
                if ($section && $section != 'all') {
                    $query .= " AND s.section = ?";
                    $params[] = $section;
                    $types .= "s";
                }
                
                $stmt = mysqli_prepare($conn, $query);
                
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }
                
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'type' => 'student'
                    ];
                }
                break;
                
            case 'hods':
                $query = "SELECT h.id, h.name, h.email 
                         FROM hods h 
                         WHERE h.is_active = 1 
                         AND h.email IS NOT NULL 
                         AND h.email != ''";
                
                $params = [];
                $types = "";
                
                if ($department_id) {
                    $query .= " AND h.department_id = ?";
                    $params[] = $department_id;
                    $types .= "i";
                }
                
                $stmt = mysqli_prepare($conn, $query);
                if (!empty($params)) {
                    mysqli_stmt_bind_param($stmt, $types, ...$params);
                }
                
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if (!$result) {
                    throw new Exception("Failed to fetch HODs: " . mysqli_error($conn));
                }
                
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'type' => 'hod'
                    ];
                }
                break;
                
            case 'all':
                // Faculty
                $query = "SELECT id, name, email FROM faculty WHERE is_active = 1";
                $result = mysqli_query($conn, $query);
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'type' => 'faculty'
                    ];
                }
                
                // HODs
                $query = "SELECT id, name, email FROM hods WHERE is_active = 1";
                $result = mysqli_query($conn, $query);
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'type' => 'hod'
                    ];
                }
                
                // Students
                $query = "SELECT id, name, email FROM students WHERE is_active = 1";
                $result = mysqli_query($conn, $query);
                while ($row = mysqli_fetch_assoc($result)) {
                    $email_list[] = [
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'type' => 'student'
                    ];
                }
                break;
                
            case 'custom':
                if (empty($_POST['custom_emails'])) {
                    throw new Exception("Please enter at least one email address");
                }
                
                $custom_emails = explode(',', $_POST['custom_emails']);
                foreach ($custom_emails as $email) {
                    $email = trim($email);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $email_list[] = [
                            'id' => null,
                            'name' => $email,
                            'email' => $email,
                            'type' => 'custom'
                        ];
                    }
                }
                break;
        }
        
        if (empty($email_list)) {
            // Delete the campaign if no recipients were found
            $delete_campaign = "DELETE FROM email_campaigns WHERE id = ?";
            $delete_stmt = mysqli_prepare($conn, $delete_campaign);
            mysqli_stmt_bind_param($delete_stmt, "i", $campaign_id);
            mysqli_stmt_execute($delete_stmt);
            
            // Log the error for debugging
            error_log("No recipients found for campaign. Type: $recipient_type, Department: $department_id");
            
            // Build a more descriptive error message
            $error_details = [];
            if ($department_id) {
                $dept_query = "SELECT name FROM departments WHERE id = ?";
                $dept_stmt = mysqli_prepare($conn, $dept_query);
                mysqli_stmt_bind_param($dept_stmt, "i", $department_id);
                mysqli_stmt_execute($dept_stmt);
                $dept_result = mysqli_stmt_get_result($dept_stmt);
                $dept = mysqli_fetch_assoc($dept_result);
                $error_details[] = "Department: " . ($dept ? $dept['name'] : 'Unknown');
            }
            
            $error_msg = "No recipients found matching your criteria.\n";
            $error_msg .= "Selected filters: Type: " . ucfirst($recipient_type);
            if (!empty($error_details)) {
                $error_msg .= ", " . implode(", ", $error_details);
            }
            $error_msg .= "\nPlease verify that there are active users with valid email addresses matching your filters.";
            
            throw new Exception($error_msg);
        }
        
        // Add recipients to email queue
        $queue_query = "INSERT INTO email_queue (campaign_id, recipient_id, recipient_type, email, name) 
                       VALUES (?, ?, ?, ?, ?)";
        $queue_stmt = mysqli_prepare($conn, $queue_query);

        foreach ($email_list as $recipient) {
            mysqli_stmt_bind_param($queue_stmt, "iisss", 
                $campaign_id,
                $recipient['id'],
                $recipient['type'],
                $recipient['email'],
                $recipient['name']
            );
            mysqli_stmt_execute($queue_stmt);
            $total_recipients++;
        }

        // Update campaign with total recipient count
        $update_campaign = "UPDATE email_campaigns SET total_recipients = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_campaign);
        mysqli_stmt_bind_param($update_stmt, "ii", $total_recipients, $campaign_id);
        mysqli_stmt_execute($update_stmt);

        // Commit transaction
        mysqli_commit($conn);
        $success[] = "Email campaign created successfully with $total_recipients recipients. Emails will be sent in the background.";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            throw $e;
            // Get department, batch, and section from POST if set
            $department_id = isset($_POST['department']) && $_POST['department'] != 'all' ? intval($_POST['department']) : null;
            $batch_id = isset($_POST['batch']) && $_POST['batch'] != 'all' ? intval($_POST['batch']) : null;
            $section = isset($_POST['section']) && $_POST['section'] != 'all' ? $_POST['section'] : null;

            // Create campaign record
            $campaign_query = "INSERT INTO email_campaigns (
                subject, message, recipient_type, department_id, batch_id, section,
                total_recipients, created_by, status, sent_count, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())";
            $stmt = mysqli_prepare($conn, $campaign_query);
            mysqli_stmt_bind_param($stmt, "sssiisii", 
                $subject, 
                $message,
                $recipient_type,
                $department_id,
                $batch_id,
                $section,
                count($email_list),
                $_SESSION['user_id']
            );
            mysqli_stmt_execute($stmt);
            $campaign_id = mysqli_insert_id($conn);

            // Queue recipients
            $queue_query = "INSERT INTO email_queue (campaign_id, recipient_id, recipient_type, 
                       email, name) VALUES (?, ?, ?, ?, ?)";
            $queue_stmt = mysqli_prepare($conn, $queue_query);
            
            foreach ($email_list as $recipient) {
                mysqli_stmt_bind_param($queue_stmt, "iisss",
                    $campaign_id,
                    $recipient['id'],
                    $recipient['type'],
                    $recipient['email'],
                    $recipient['name']
                );
                mysqli_stmt_execute($queue_stmt);
            }

            mysqli_commit($conn);
            $success[] = "Email campaign created successfully. " . count($email_list) . 
                         " recipients queued for sending.";

        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = "Failed to create email campaign: " . $e->getMessage();
        }
}



// Function to send email using PHPMailer
function sendEmail($to_email, $to_name, $subject, $message_body) {
    try {
        // Create a new PHPMailer instance
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->SMTPDebug = 0;  // Disable debugging
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'official@ads-panimalar.in';
        $mail->Password   = 'Kf$2p!#Uvrg';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->Timeout    = 30;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        // Recipients
        $mail->setFrom('official@ads-panimalar.in', 'Panimalar Engineering College');
        $mail->addAddress($to_email, $to_name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        // Create email body with HTML template
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
                    max-width: 80px;
                    height: auto;
                    margin-bottom: 10px;
                }
                .content {
                    padding: 20px 0;
                }
                .footer {
                    margin-top: 30px;
                    font-size: 12px;
                    text-align: center;
                    color: #777;
                    border-top: 1px solid #eee;
                    padding-top: 20px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <img src="https://ads-panimalar.in/college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
                    <h2>Panimalar Engineering College</h2>
                </div>
                
                <div class="content">
                    
                    
                    ' . $message_body . '
                    
                    
                </div>
                
                <div class="footer">
                    <p>This is an automated email from the college management system.</p>
                    <p>Panimalar Engineering College, Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Plain text version for non-HTML mail clients
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message_body));
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log error but don't expose details to users
        error_log("Email sending failed for {$to_email}: " . $mail->ErrorInfo);
        return false;
    }
}

// Get current admin info
$admin_id = $_SESSION['user_id'];
$admin_query = "SELECT username FROM admin_users WHERE id = ?";
$admin_stmt = mysqli_prepare($conn, $admin_query);
mysqli_stmt_bind_param($admin_stmt, "i", $admin_id);
mysqli_stmt_execute($admin_stmt);
$admin_result = mysqli_stmt_get_result($admin_stmt);
$admin_info = mysqli_fetch_assoc($admin_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Sender - Admin Portal</title>
    <link rel="icon" href="../college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <!-- Use CDN for Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
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
            color: var(--text-color);
        }
        
        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px;
            min-height: 100vh;
        }
        
        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .dashboard-header p {
            color: #666;
            font-size: 1rem;
        }
        
        .email-container {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .email-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px 0 0 4px;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-group {
            flex: 1 1 250px;
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
            font-size: 0.95rem;
        }
        
        .form-control {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }
        
        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }
        
        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            color: var(--text-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .btn:hover {
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.8);
            transform: translateY(-2px);
        }
        
        .btn:active {
            box-shadow: var(--inner-shadow);
            transform: translateY(0);
        }
        
        .recipient-options {
            margin-top: 20px;
            padding: 1.5rem;
            border-radius: 15px;
            background: #f8f9fc;
            box-shadow: var(--inner-shadow);
        }
        
        .recipient-options h4 {
            margin-bottom: 1rem;
            color: var(--primary-color);
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .hidden {
            display: none;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: none;
            border-radius: 15px;
            box-shadow: var(--shadow);
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
        }
        
        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
        }
        
        .note-editor {
            margin-bottom: 20px;
            border-radius: 15px !important;
            overflow: hidden;
            box-shadow: var(--shadow) !important;
            border: none !important;
        }
        
        .note-editor .note-toolbar {
            background-color: #f8f9fc !important;
            border-bottom: 1px solid rgba(0,0,0,.125) !important;
        }
        
        .note-editor .note-editing-area .note-editable {
            background-color: white !important;
            color: var(--text-color) !important;
            padding: 15px !important;
        }

        /* Progress styles */
        .progress {
            height: 20px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 5px 0;
        }

        .progress-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 12px;
            transition: width 0.6s ease;
        }

        .badge {
            padding: 0.4em 0.6em;
            font-size: 85%;
            font-weight: 500;
            border-radius: 6px;
        }

        .badge-success {
            background-color: #28a745;
            color: white;
        }

        .badge-warning {
            background-color: #ffc107;
            color: #000;
        }

        .badge-danger {
            background-color: #dc3545;
            color: white;
        }

        .badge-info {
            background-color: #17a2b8;
            color: white;
        }

        .table-responsive {
            margin-top: 1rem;
        }
        
        .filter-actions {
            margin: 1rem 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        #applyFilterBtn {
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        #applyFilterBtn:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 12px rgba(0,0,0,0.1);
        }
        
        .recipient-count {
            background: var(--bg-color);
            padding: 1rem;
            border-radius: 12px;
            margin-top: 15px;
            font-size: 14px;
            color: var(--text-color);
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .recipient-count i {
            font-size: 1.2rem;
            color: var(--primary-color);
        }
        
        .recipient-count strong {
            font-size: 1.1rem;
            color: var(--primary-color);
        }
        
        .email-preview {
            margin: 2rem 0;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: 0;
            overflow: hidden;
            position: relative;
        }
        
        .email-preview h4 {
            padding: 1.2rem;
            margin: 0;
            background: var(--primary-color);
            color: white;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .preview-container {
            border: 1px solid #ddd;
            margin: 1rem;
            background: #fff;
            border-radius: 5px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: transform 0.3s ease;
        }
        
        .preview-container:hover {
            transform: translateY(-5px);
        }
        
        .preview-header {
            text-align: center;
            padding: 1.2rem;
            border-bottom: 1px solid #eee;
            background: #fafafa;
        }
        
        .preview-logo {
            width: 70px;
            height: auto;
            margin-bottom: 0.8rem;
            filter: drop-shadow(0 2px 5px rgba(0,0,0,0.1));
        }
        
        .preview-header h3 {
            margin: 0;
            font-size: 1.3rem;
            color: #333;
            font-weight: 600;
        }
        
        .preview-content {
            padding: 1.8rem;
            color: #333;
            min-height: 150px;
            line-height: 1.6;
        }
        
        #preview-message {
            margin: 1.5rem 0;
            padding: 1.2rem;
            background: #f9f9f9;
            border-left: 3px solid var(--primary-color);
            border-radius: 0 5px 5px 0;
            font-style: italic;
            min-height: 80px;
        }
        
        .sender-info {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
            color: #777;
            font-size: 0.95rem;
        }
        
        .tab-container {
            display: flex;
            margin-bottom: 2rem;
        }
        
        .tab {
            padding: 1rem 2rem;
            background: var(--bg-color);
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            color: var(--text-color);
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
            margin-right: 5px;
        }
        
        .tab.active {
            border-bottom: 3px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .tab:hover:not(.active) {
            border-bottom: 3px solid rgba(231, 76, 60, 0.5);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .dashboard-header {
                padding: 1rem;
            }
            
            .recipient-options {
                padding: 1rem;
            }
            
            .tab {
                padding: 0.8rem 1rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">
        <?php include 'includes/sidebar.php'; ?>
        
        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">
                <?php include 'includes/header.php'; ?>
                
                <div class="main-content">
                    <div class="dashboard-header">
                        <div>
                            <h1>Email Communication Center</h1>
                            <p>Create and send professional emails to students, faculty, and staff</p>
                        </div>
                        </div>

                        <!-- Progress Tab Content -->
                        <div id="progress" class="tab-content">
                            <h4 class="mb-4"><i class="fas fa-tasks"></i> Email Campaign Progress</h4>
                            <?php
                            // Get recent campaigns with status
                            $campaigns_query = "SELECT 
                                ec.id,
                                ec.subject,
                                ec.recipient_type,
                                ec.total_recipients,
                                ec.sent_count,
                                ec.status,
                                ec.created_at,
                                ec.updated_at,
                                COUNT(eq.id) as total_queued,
                                SUM(CASE WHEN eq.status = 'sent' THEN 1 ELSE 0 END) as sent,
                                SUM(CASE WHEN eq.status = 'failed' THEN 1 ELSE 0 END) as failed
                            FROM email_campaigns ec
                            LEFT JOIN email_queue eq ON ec.id = eq.campaign_id
                            GROUP BY ec.id
                            ORDER BY ec.created_at DESC
                            LIMIT 10";
                            
                            $campaigns_result = mysqli_query($conn, $campaigns_query);
                            
                            if (mysqli_num_rows($campaigns_result) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Subject</th>
                                                <th>Recipients</th>
                                                <th>Progress</th>
                                                <th>Status</th>
                                                <th>Created</th>
                                                <th>Last Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($campaign = mysqli_fetch_assoc($campaigns_result)): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo htmlspecialchars($campaign['subject']); ?>
                                                        <br>
                                                        <small class="text-muted">Type: <?php echo ucwords(str_replace('_', ' ', $campaign['recipient_type'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <?php echo $campaign['total_recipients']; ?> total
                                                        <br>
                                                        <small class="text-success"><?php echo $campaign['sent']; ?> sent</small>
                                                        <?php if ($campaign['failed'] > 0): ?>
                                                            <br>
                                                            <small class="text-danger"><?php echo $campaign['failed']; ?> failed</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $progress = ($campaign['total_recipients'] > 0) 
                                                            ? ($campaign['sent_count'] / $campaign['total_recipients']) * 100 
                                                            : 0;
                                                        ?>
                                                        <div class="progress">
                                                            <div class="progress-bar <?php echo $campaign['status'] === 'failed' ? 'bg-danger' : 'bg-success'; ?>" 
                                                                 role="progressbar" 
                                                                 style="width: <?php echo $progress; ?>%"
                                                                 aria-valuenow="<?php echo $progress; ?>" 
                                                                 aria-valuemin="0" 
                                                                 aria-valuemax="100">
                                                                <?php echo round($progress); ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        switch ($campaign['status']) {
                                                            case 'completed':
                                                                $status_class = 'badge-success';
                                                                break;
                                                            case 'failed':
                                                                $status_class = 'badge-danger';
                                                                break;
                                                            case 'in_progress':
                                                                $status_class = 'badge-info';
                                                                break;
                                                            default:
                                                                $status_class = 'badge-warning';
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>">
                                                            <?php echo ucwords(str_replace('_', ' ', $campaign['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php echo date('M d, Y g:i A', strtotime($campaign['created_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $campaign['updated_at'] ? date('M d, Y g:i A', strtotime($campaign['updated_at'])) : 'N/A'; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p>No email campaigns found.</p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($success)): ?>
                            <?php foreach ($success as $msg): ?>
                                <div class="alert alert-success">
                                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="email-container">
                        <div class="tab-container">
                            <button class="tab active" onclick="openTab(event, 'compose')">
                                <i class="fas fa-pen-to-square"></i> Compose Email
                            </button>
                            <button class="tab" onclick="openTab(event, 'preview')">
                                <i class="fas fa-eye"></i> Preview
                            </button>
                            <button class="tab" onclick="openTab(event, 'recipients')">
                                <i class="fas fa-users"></i> Recipients
                            </button>
                            <button class="tab" onclick="openTab(event, 'progress')">
                                <i class="fas fa-tasks"></i> Progress
                            </button>
                        </div>
                        
                        <form method="post" action="" id="emailForm">
                            <div id="compose" class="tab-content active">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="subject">
                                            <i class="fas fa-heading"></i> Email Subject *
                                        </label>
                                        <input type="text" id="subject" name="subject" class="form-control" required placeholder="Enter subject line...">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="message">
                                        <i class="fas fa-envelope-open-text"></i> Email Message *
                                    </label>
                                    <textarea id="message" name="message" class="form-control"></textarea>
                                </div>
                            </div>
                            
                            <div id="preview" class="tab-content">
                                <div class="email-preview">
                                    <h4><i class="fas fa-eye"></i> Email Template Preview</h4>
                                    <div class="preview-container">
                                        <div class="preview-header">
                                            <img src="../college_logo.png" alt="Panimalar Engineering College Logo" class="preview-logo">
                                            <h3>Panimalar Engineering College</h3>
                                        </div>
                                        <div class="preview-content">
                                            
                                            <div id="preview-message">Your message will appear here...</div>
                                            <div class="sender-info">
                                                <p>This is an automated email from the college management system.</p>
                                                <p>Panimalar Engineering College, Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="recipients" class="tab-content">
                                <div class="form-group">
                                    <label>
                                        <i class="fas fa-user-group"></i> Select Recipients *
                                    </label>
                                    <select id="recipient_type" name="recipient_type" class="form-control" required onchange="toggleRecipientOptions()">
                                        <option value="">-- Select Recipients --</option>
                                        <option value="faculty">Faculty Members</option>
                                        <option value="students">Students</option>
                                        <option value="hods">Department Heads</option>
                                        <option value="all">All Users</option>
                                        <option value="custom">Custom Emails</option>
                                    </select>
                                </div>
                                
                                <div id="recipientFilters" class="recipient-options hidden">
                                    <h4><i class="fas fa-filter"></i> Filter Recipients</h4>
                                    <div class="form-row">
                                        <div class="form-group" id="departmentGroup">
                                            <label for="department">Department</label>
                                            <select id="department" name="department" class="form-control">
                                                <option value="all">All Departments</option>
                                                <?php foreach ($departments as $id => $name): ?>
                                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group" id="batchGroup">
                                            <label for="batch">Batch</label>
                                            <select id="batch" name="batch" class="form-control">
                                                <option value="all">All Batches</option>
                                                <?php foreach ($batches as $id => $name): ?>
                                                    <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="form-group" id="sectionGroup">
                                            <label for="section">Section</label>
                                            <select id="section" name="section" class="form-control">
                                                <option value="all">All Sections</option>
                                                <?php foreach ($sections as $section): ?>
                                                    <option value="<?php echo $section; ?>"><?php echo $section; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="filter-actions">
                                        <button type="button" class="btn btn-primary" id="applyFilterBtn" onclick="updateRecipientCount()">
                                            <i class="fas fa-filter"></i> Apply Filter
                                        </button>
                                        
                                        <?php if (isset($_SESSION['admin_type']) && $_SESSION['admin_type'] === 'super_admin'): ?>
                                        <button type="button" class="btn btn-secondary" id="debugAjaxBtn" onclick="debugAjaxConnection()">
                                            <i class="fas fa-bug"></i> Debug AJAX
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="recipient-count" id="recipientCount">
                                        <i class="fas fa-users"></i> Select filters and click Apply Filter to see recipient count
                                    </div>
                                </div>
                                
                                <div id="customEmailsGroup" class="recipient-options hidden">
                                    <h4><i class="fas fa-at"></i> Custom Email Addresses</h4>
                                    <div class="form-group">
                                        <textarea id="custom_emails" name="custom_emails" class="form-control" 
                                                placeholder="Enter email addresses separated by commas"></textarea>
                                        <small class="form-text text-muted">Enter multiple email addresses separated by commas (e.g., user1@example.com, user2@example.com)</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group mt-4 text-center">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Send Email
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            
        </div>
    </div>
    
    <a class="scroll-to-top rounded" href="#page-top">
        <i class="fas fa-angle-up"></i>
    </a>
    
    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-easing/1.4.1/jquery.easing.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-lite.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Initialize rich text editor
            $('#message').summernote({
                height: 300,
                toolbar: [
                    ['style', ['style']],
                    ['font', ['bold', 'underline', 'italic', 'clear']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['table', ['table']],
                    ['insert', ['link']],
                    ['view', ['fullscreen', 'codeview', 'help']]
                ],
                placeholder: 'Compose your email message here...',
                callbacks: {
                    onImageUpload: function(files) {
                        // Disable image uploads for security
                        alert('Image uploads are not allowed for security reasons. Please use text and links only.');
                    },
                    onChange: function(contents) {
                        // Update the preview when content changes
                        $('#preview-message').html(contents);
                    }
                }
            });
            
            // Initially hide the recipient filters
            $('#recipientFilters').addClass('hidden');
            $('#customEmailsGroup').addClass('hidden');
            
            // Handle form submission
            $('#emailForm').on('submit', function(e) {
                const recipientType = $('#recipient_type').val();
                const subject = $('#subject').val();
                const message = $('#message').val();
                
                if (!subject.trim()) {
                    e.preventDefault();
                    alert('Please enter an email subject');
                    return false;
                }
                
                if (!message.trim()) {
                    e.preventDefault();
                    alert('Please enter an email message');
                    return false;
                }
                
                if (!recipientType) {
                    e.preventDefault();
                    alert('Please select recipient type');
                    openTab(null, 'recipients');
                    return false;
                }
                
                if (recipientType === 'custom') {
                    const customEmails = $('#custom_emails').val().trim();
                    if (!customEmails) {
                        e.preventDefault();
                        alert('Please enter at least one email address');
                        return false;
                    }
                }
                
                return true;
            });
            
            // Trigger recipient option toggle on recipient type change
            $('#recipient_type').change(function() {
                toggleRecipientOptions();
            });
            
            // Apply Filter button click handler
            $('#applyFilterBtn').click(function() {
                updateRecipientCount();
            });
        });
        
        // Tab navigation function
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            
            // Hide all tab content
            tabcontent = document.getElementsByClassName("tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.remove("active");
            }
            
            // Remove active class from all tabs
            tablinks = document.getElementsByClassName("tab");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("active");
            }
            
            // Show the selected tab content
            document.getElementById(tabName).classList.add("active");
            
            // Add active class to the clicked tab
            if (evt) {
                evt.currentTarget.classList.add("active");
            } else {
                // If no event (programmatic call), find and activate the tab
                for (i = 0; i < tablinks.length; i++) {
                    if (tablinks[i].textContent.toLowerCase().includes(tabName)) {
                        tablinks[i].classList.add("active");
                        break;
                    }
                }
            }
        }
        
        // Toggle recipient options based on selection
        function toggleRecipientOptions() {
            const recipientType = document.getElementById('recipient_type').value;
            const recipientFilters = document.getElementById('recipientFilters');
            const customEmailsGroup = document.getElementById('customEmailsGroup');
            const batchGroup = document.getElementById('batchGroup');
            const sectionGroup = document.getElementById('sectionGroup');
            
            // Hide all first
            recipientFilters.classList.add('hidden');
            customEmailsGroup.classList.add('hidden');
            
            // Show relevant sections
            if (recipientType === 'custom') {
                customEmailsGroup.classList.remove('hidden');
            } else if (recipientType !== '') {
                recipientFilters.classList.remove('hidden');
                
                // Show/hide batch and section filters based on recipient type
                if (recipientType === 'students') {
                    batchGroup.style.display = 'block';
                    sectionGroup.style.display = 'block';
                } else {
                    batchGroup.style.display = 'none';
                    sectionGroup.style.display = 'none';
                }
            }
        }
        
        // Fetch and update recipient count based on selected filters
        function updateRecipientCount() {
            const recipientType = document.getElementById('recipient_type').value;
            const department = document.getElementById('department').value;
            const batch = document.getElementById('batch').value;
            const section = document.getElementById('section').value;
            const countElement = document.getElementById('recipientCount');
            
            if (!recipientType) {
                countElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Please select a recipient type first`;
                return;
            }
            
            // Show loading indicator
            countElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading recipient count...';
            
            // Make AJAX call to get count
            $.ajax({
                url: 'ajax/get_email_recipient_count.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    recipient_type: recipientType,
                    department: department,
                    batch: batch,
                    section: section
                },
                success: function(response) {
                    console.log("Response received:", response);
                    if (response.success) {
                        countElement.innerHTML = `<i class="fas fa-users"></i> Recipients: <strong>${response.count}</strong> users will receive this email`;
                    } else {
                        countElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Error: ${response.error || 'Unknown error occurred'}`;
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX error:", status, error);
                    console.error("Response text:", xhr.responseText);
                    try {
                        const response = JSON.parse(xhr.responseText);
                        countElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Error: ${response.error || 'Server error'}`;
                    } catch (e) {
                        countElement.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Server error: ${status}. Check console for details.`;
                    }
                }
            });
        }
        
        // Add active class to current sidebar item
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = window.location.pathname.split('/').pop();
            document.querySelectorAll('.sidebar .nav-link').forEach(link => {
                if (link.getAttribute('href') === currentPage) {
                    link.classList.add('active');
                }
            });
        });
        
        // Debug AJAX connection function for administrators
        function debugAjaxConnection() {
            const countElement = document.getElementById('recipientCount');
            countElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testing AJAX connection...';
            
            // Send a simple AJAX request with minimal data
            $.ajax({
                url: 'ajax/get_email_recipient_count.php',
                type: 'POST',
                data: {
                    recipient_type: 'faculty',
                    department: 'all',
                    test_mode: true
                },
                success: function(response) {
                    countElement.innerHTML = '<div style="background:#eef;padding:10px;border-radius:5px;"><h4>Debug Info:</h4>';
                    countElement.innerHTML += '<p>AJAX connection successful!</p>';
                    countElement.innerHTML += '<pre style="background:#f8f8f8;padding:10px;overflow:auto;">' + 
                                           JSON.stringify(response, null, 2) + '</pre></div>';
                    
                    $.get('ajax/recipient_count_log.txt')
                        .done(function(data) {
                            countElement.innerHTML += '<h4 style="margin-top:10px;">Log File:</h4>';
                            countElement.innerHTML += '<pre style="background:#f8f8f8;padding:10px;overflow:auto;max-height:200px;">' + 
                                                   data + '</pre>';
                        })
                        .fail(function() {
                            countElement.innerHTML += '<p>Could not read log file. Check permissions.</p>';
                        });
                },
                error: function(xhr, status, error) {
                    countElement.innerHTML = '<div style="background:#fee;padding:10px;border-radius:5px;">';
                    countElement.innerHTML += '<h4>AJAX Error:</h4>';
                    countElement.innerHTML += '<p>Status: ' + status + '</p>';
                    countElement.innerHTML += '<p>Error: ' + error + '</p>';
                    countElement.innerHTML += '<p>Response Text:</p>';
                    countElement.innerHTML += '<pre style="background:#f8f8f8;padding:10px;overflow:auto;">' + xhr.responseText + '</pre></div>';
                }
            });
        }
    </script>
</body>
</html>