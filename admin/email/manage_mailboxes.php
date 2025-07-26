<?php
require_once '../../functions.php';
require_once '../../db_connection.php';

// Check if user is logged in as admin
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../admin_login.php");
    exit();
}

$success = [];
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add':
                    // Add new mailbox
                    $email = trim($_POST['email']);
                    $password = trim($_POST['password']);
                    $host = trim($_POST['host']) ?: 'smtp.hostinger.com';
                    $port = intval($_POST['port']) ?: 465;
                    $daily_limit = intval($_POST['daily_limit']) ?: 100;
                    $recipients_per_email = intval($_POST['recipients_per_email']) ?: 100;
                    
                    if (empty($email) || empty($password)) {
                        throw new Exception("Email and password are required");
                    }
                    
                    $query = "INSERT INTO email_mailboxes (email, password, host, port, daily_limit, recipients_per_email) 
                             VALUES (?, ?, ?, ?, ?, ?)";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssiii", $email, $password, $host, $port, $daily_limit, $recipients_per_email);
                    mysqli_stmt_execute($stmt);
                    
                    $success[] = "Mailbox added successfully";
                    break;
                    
                case 'update':
                    // Update existing mailbox
                    $id = intval($_POST['id']);
                    $email = trim($_POST['email']);
                    $password = trim($_POST['password']);
                    $host = trim($_POST['host']);
                    $port = intval($_POST['port']);
                    $daily_limit = intval($_POST['daily_limit']);
                    $recipients_per_email = intval($_POST['recipients_per_email']);
                    $is_active = isset($_POST['is_active']) ? 1 : 0;
                    
                    $query = "UPDATE email_mailboxes 
                             SET email = ?, 
                                 password = ?, 
                                 host = ?, 
                                 port = ?, 
                                 daily_limit = ?, 
                                 recipients_per_email = ?,
                                 is_active = ?
                             WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "sssiiiis", $email, $password, $host, $port, $daily_limit, $recipients_per_email, $is_active, $id);
                    mysqli_stmt_execute($stmt);
                    
                    $success[] = "Mailbox updated successfully";
                    break;
                    
                case 'delete':
                    // Delete mailbox
                    $id = intval($_POST['id']);
                    
                    $query = "DELETE FROM email_mailboxes WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "i", $id);
                    mysqli_stmt_execute($stmt);
                    
                    $success[] = "Mailbox deleted successfully";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all mailboxes
$query = "SELECT * FROM email_mailboxes ORDER BY email";
$result = mysqli_query($conn, $query);
$mailboxes = mysqli_fetch_all($result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Email Mailboxes - Admin Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css" rel="stylesheet">
    <style>
        .card { margin-bottom: 1rem; }
        .btn-icon { width: 40px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h2>Manage Email Mailboxes</h2>
        
        <?php if (!empty($success)): ?>
            <?php foreach ($success as $msg): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Add New Mailbox Form -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Add New Mailbox</h5>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Password</label>
                                <input type="password" name="password" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>SMTP Host</label>
                                <input type="text" name="host" class="form-control" value="smtp.hostinger.com">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>SMTP Port</label>
                                <input type="number" name="port" class="form-control" value="465">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Daily Email Limit</label>
                                <input type="number" name="daily_limit" class="form-control" value="100">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Recipients per Email</label>
                                <input type="number" name="recipients_per_email" class="form-control" value="100">
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Mailbox
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Existing Mailboxes -->
        <h3 class="mt-4">Existing Mailboxes</h3>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Host</th>
                        <th>Daily Limit</th>
                        <th>Recipients/Email</th>
                        <th>Sent Today</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mailboxes as $mailbox): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($mailbox['email']); ?></td>
                        <td><?php echo htmlspecialchars($mailbox['host']); ?></td>
                        <td><?php echo htmlspecialchars($mailbox['daily_limit']); ?></td>
                        <td><?php echo htmlspecialchars($mailbox['recipients_per_email']); ?></td>
                        <td><?php echo htmlspecialchars($mailbox['emails_sent_today']); ?></td>
                        <td>
                            <?php if ($mailbox['is_active']): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-primary btn-icon" onclick="editMailbox(<?php echo htmlspecialchars(json_encode($mailbox)); ?>)">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn btn-sm btn-danger btn-icon" onclick="deleteMailbox(<?php echo $mailbox['id']; ?>)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Edit Mailbox Modal -->
    <div class="modal fade" id="editMailboxModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Mailbox</h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="edit_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Password (leave blank to keep unchanged)</label>
                            <input type="password" name="password" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="host" id="edit_host" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="port" id="edit_port" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Daily Email Limit</label>
                            <input type="number" name="daily_limit" id="edit_daily_limit" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Recipients per Email</label>
                            <input type="number" name="recipients_per_email" id="edit_recipients_per_email" class="form-control">
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active">
                                <label class="custom-control-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editMailbox(mailbox) {
            $('#edit_id').val(mailbox.id);
            $('#edit_email').val(mailbox.email);
            $('#edit_host').val(mailbox.host);
            $('#edit_port').val(mailbox.port);
            $('#edit_daily_limit').val(mailbox.daily_limit);
            $('#edit_recipients_per_email').val(mailbox.recipients_per_email);
            $('#edit_is_active').prop('checked', mailbox.is_active == 1);
            $('#editMailboxModal').modal('show');
        }
        
        function deleteMailbox(id) {
            if (confirm('Are you sure you want to delete this mailbox?')) {
                var form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>
