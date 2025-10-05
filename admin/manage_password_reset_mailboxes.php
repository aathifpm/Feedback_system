<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';
require_once 'PasswordResetMailboxManager.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$mailboxManager = new PasswordResetMailboxManager($conn);
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_mailbox':
                $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
                $password = $_POST['password'];
                $host = $_POST['host'] ?: 'smtp.hostinger.com';
                $port = intval($_POST['port']) ?: 465;
                $daily_limit = intval($_POST['daily_limit']) ?: 100;
                $monthly_limit = intval($_POST['monthly_limit']) ?: 15000;
                
                if ($mailboxManager->addMailbox($email, $password, $host, $port, $daily_limit, $monthly_limit)) {
                    $message = "Mailbox added successfully!";
                } else {
                    $error = "Failed to add mailbox. Email might already exist.";
                }
                break;
                
            case 'toggle_status':
                $mailbox_id = intval($_POST['mailbox_id']);
                $is_active = intval($_POST['is_active']);
                
                if ($mailboxManager->toggleMailboxStatus($mailbox_id, $is_active)) {
                    $message = "Mailbox status updated successfully!";
                } else {
                    $error = "Failed to update mailbox status.";
                }
                break;
        }
    }
}

// Get mailbox status and stats
$mailboxes = $mailboxManager->getMailboxStatus();
$stats = $mailboxManager->getEmailStats(7);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Mailbox Management - Admin Panel</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #ecf0f1;
            --card-bg: #ffffff;
            --border-color: #bdc3c7;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            text-align: center;
        }

        .header h1 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .nav-links {
            margin-top: 15px;
        }

        .nav-links a {
            color: var(--primary-color);
            text-decoration: none;
            margin: 0 15px;
            padding: 8px 16px;
            border-radius: 5px;
            transition: background-color 0.3s;
        }

        .nav-links a:hover {
            background-color: var(--bg-color);
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .card {
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            overflow: hidden;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            padding: 15px 20px;
            font-weight: 600;
        }

        .card-body {
            padding: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: white;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            background: var(--bg-color);
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-available {
            background: #d4edda;
            color: #155724;
        }

        .status-limited {
            background: #fff3cd;
            color: #856404;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: var(--primary-color);
        }

        .stat-label {
            color: #666;
            margin-top: 5px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .table {
                font-size: 12px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-envelope"></i> Password Reset Mailbox Management</h1>
            <p>Manage mailboxes for password reset emails with automatic load balancing</p>
            <div class="nav-links">
                <a href="dashboard.php"><i class="fas fa-dashboard"></i> Dashboard</a>
                <a href="manage_faculty.php"><i class="fas fa-users"></i> Manage Faculty</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <?php
            $total_sent = array_sum(array_column($stats, 'sent_emails'));
            $total_failed = array_sum(array_column($stats, 'failed_emails'));
            $active_mailboxes = count(array_filter($mailboxes, function($m) { return $m['is_active']; }));
            $available_mailboxes = count(array_filter($mailboxes, function($m) { return $m['status'] === 'Available'; }));
            ?>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_sent; ?></div>
                <div class="stat-label">Emails Sent (7 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_failed; ?></div>
                <div class="stat-label">Failed Emails (7 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $active_mailboxes; ?></div>
                <div class="stat-label">Active Mailboxes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $available_mailboxes; ?></div>
                <div class="stat-label">Available Now</div>
            </div>
        </div>

        <!-- Add New Mailbox -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-plus"></i> Add New Mailbox
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="add_mailbox">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="host">SMTP Host</label>
                            <input type="text" id="host" name="host" class="form-control" value="smtp.hostinger.com">
                        </div>
                        <div class="form-group">
                            <label for="port">SMTP Port</label>
                            <input type="number" id="port" name="port" class="form-control" value="465">
                        </div>
                        <div class="form-group">
                            <label for="daily_limit">Daily Limit</label>
                            <input type="number" id="daily_limit" name="daily_limit" class="form-control" value="100">
                        </div>
                        <div class="form-group">
                            <label for="monthly_limit">Monthly Limit</label>
                            <input type="number" id="monthly_limit" name="monthly_limit" class="form-control" value="15000">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Mailbox
                    </button>
                </form>
            </div>
        </div>

        <!-- Mailbox Status -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Mailbox Status
            </div>
            <div class="card-body">
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Email</th>
                                <th>Daily Usage</th>
                                <th>Monthly Usage</th>
                                <th>Status</th>
                                <th>Last Sent</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mailboxes as $mailbox): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($mailbox['email']); ?></td>
                                    <td>
                                        <?php echo $mailbox['emails_sent_today']; ?> / <?php echo $mailbox['daily_limit']; ?>
                                        <small>(<?php echo $mailbox['daily_remaining']; ?> left)</small>
                                    </td>
                                    <td>
                                        <?php echo $mailbox['emails_sent_this_month']; ?> / <?php echo $mailbox['monthly_limit']; ?>
                                        <small>(<?php echo $mailbox['monthly_remaining']; ?> left)</small>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php 
                                            echo $mailbox['status'] === 'Available' ? 'status-available' : 
                                                ($mailbox['status'] === 'Inactive' ? 'status-inactive' : 'status-limited'); 
                                        ?>">
                                            <?php echo $mailbox['status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $mailbox['last_sent_at'] ? date('M j, Y H:i', strtotime($mailbox['last_sent_at'])) : 'Never'; ?>
                                    </td>
                                    <td>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="mailbox_id" value="<?php echo $mailbox['id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo $mailbox['is_active'] ? 0 : 1; ?>">
                                            <button type="submit" class="btn <?php echo $mailbox['is_active'] ? 'btn-warning' : 'btn-success'; ?>">
                                                <i class="fas fa-<?php echo $mailbox['is_active'] ? 'pause' : 'play'; ?>"></i>
                                                <?php echo $mailbox['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Email Statistics -->
        <?php if (!empty($stats)): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-chart-line"></i> Email Statistics (Last 7 Days)
            </div>
            <div class="card-body">
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Emails</th>
                                <th>Sent Successfully</th>
                                <th>Failed</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats as $stat): ?>
                                <tr>
                                    <td><?php echo date('M j, Y', strtotime($stat['date'])); ?></td>
                                    <td><?php echo $stat['total_emails']; ?></td>
                                    <td><?php echo $stat['sent_emails']; ?></td>
                                    <td><?php echo $stat['failed_emails']; ?></td>
                                    <td>
                                        <?php 
                                        $success_rate = $stat['total_emails'] > 0 ? 
                                            round(($stat['sent_emails'] / $stat['total_emails']) * 100, 1) : 0;
                                        echo $success_rate . '%';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh page every 5 minutes to update stats
        setTimeout(function() {
            location.reload();
        }, 300000);
    </script>
</body>
</html>