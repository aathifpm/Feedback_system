<?php
/**
 * Password Reset Mailbox Manager
 * Manages multiple mailboxes for password reset emails with daily and monthly limits
 */

require_once '../db_connection.php';
require_once '../vendor/autoload.php';


// Include PHPMailer library
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;


class PasswordResetMailboxManager {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Get an available mailbox for sending password reset emails
     * @return array|null Mailbox details or null if none available
     */
    public function getAvailableMailbox() {
        try {
            $stmt = $this->conn->prepare("CALL GetAvailablePasswordResetMailbox()");
            $stmt->execute();
            $result = $stmt->get_result();
            $mailbox = $result->fetch_assoc();
            $stmt->close();
            
            return $mailbox;
        } catch (Exception $e) {
            error_log("Error getting available mailbox: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Atomically acquire and reserve a mailbox for sending (recommended method)
     * This method gets a mailbox and increments its usage counters in one transaction
     * @return array|null Mailbox details or null if none available
     */
    public function acquireMailbox() {
        try {
            $stmt = $this->conn->prepare("CALL AcquireAndUsePasswordResetMailbox()");
            $stmt->execute();
            $result = $stmt->get_result();
            $mailbox = $result->fetch_assoc();
            $stmt->close();
            
            // If id is NULL, no mailbox was available
            if (!$mailbox || !$mailbox['id']) {
                return null;
            }
            
            return $mailbox;
        } catch (Exception $e) {
            error_log("Error acquiring mailbox: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Send password reset email using available mailbox
     * @param string $email Recipient email
     * @param string $name Recipient name
     * @param string $reset_link Password reset link
     * @param int $user_id User ID
     * @param string $user_type User type (admin, faculty, hod, student)
     * @param string $reset_token Reset token
     * @return bool Success status
     */
    public function sendPasswordResetEmail($email, $name, $reset_link, $user_id, $user_type, $reset_token) {
        // Use the atomic acquire method to get and reserve a mailbox
        $mailbox = $this->acquireMailbox();
        
        if (!$mailbox) {
            $this->logEmail(null, $email, $name, $user_id, $user_type, $reset_token, 'failed', 'No available mailbox');
            error_log("No available mailbox for password reset email to: " . $email);
            return false;
        }
        
        try {
            // Check if PHPMailer is available
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                throw new Exception("PHPMailer library not found. Please install PHPMailer.");
            }
            
            $mail = new PHPMailer(true);
            
            // Server settings
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = $mailbox['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mailbox['email'];
            $mail->Password = $mailbox['password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $mailbox['port'];
            $mail->Timeout = 30;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
            // Recipients
            $mail->setFrom($mailbox['email'], 'Panimalar Engineering College - Password Reset');
            $mail->addAddress($email, $name);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request - Panimalar Engineering College';
            
            // Create email body
            $mail->Body = $this->createEmailBody($name, $reset_link);
            $mail->AltBody = $this->createPlainTextBody($name, $reset_link);
            
            $mail->send();
            
            // No need to update mailbox usage here since acquireMailbox() already did it
            
            // Log successful email
            $this->logEmail($mailbox['id'], $email, $name, $user_id, $user_type, $reset_token, 'sent', null);
            
            return true;
            
        } catch (Exception $e) {
            $error_message = "Email sending failed: " . $e->getMessage();
            error_log($error_message . " for email: " . $email);
            
            // Log failed email
            $this->logEmail($mailbox['id'], $email, $name, $user_id, $user_type, $reset_token, 'failed', $error_message);
            
            return false;
        }
    }
    
    /**
     * Update mailbox usage counters
     * @param int $mailbox_id Mailbox ID
     */
    private function updateMailboxUsage($mailbox_id) {
        try {
            $stmt = $this->conn->prepare("CALL UpdatePasswordResetMailboxUsage(?)");
            $stmt->bind_param("i", $mailbox_id);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error updating mailbox usage: " . $e->getMessage());
        }
    }
    
    /**
     * Log password reset email attempt
     */
    private function logEmail($mailbox_id, $recipient_email, $recipient_name, $user_id, $user_type, $reset_token, $status, $error_message) {
        try {
            // Handle NULL mailbox_id case
            if ($mailbox_id === null) {
                $stmt = $this->conn->prepare("INSERT INTO password_reset_email_logs (mailbox_id, recipient_email, recipient_name, user_id, user_type, reset_token, status, error_message, sent_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, NULL)");
                $stmt->bind_param("ssissss", $recipient_email, $recipient_name, $user_id, $user_type, $reset_token, $status, $error_message);
            } else {
                $stmt = $this->conn->prepare("CALL LogPasswordResetEmail(?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("isssssss", $mailbox_id, $recipient_email, $recipient_name, $user_id, $user_type, $reset_token, $status, $error_message);
            }
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error logging password reset email: " . $e->getMessage());
        }
    }
    
    /**
     * Create HTML email body
     */
    private function createEmailBody($name, $reset_link) {
        return '
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
        </html>';
    }
    
    /**
     * Create plain text email body
     */
    private function createPlainTextBody($name, $reset_link) {
        return "Dear " . $name . ",\n\n" .
               "We received a request to reset your password. If you didn't make this request, you can safely ignore this email.\n\n" .
               "To reset your password, please copy and paste the following link into your browser:\n" .
               $reset_link . "\n\n" .
               "This link will expire in 24 hours for security reasons.\n\n" .
               "If you need any assistance, please contact our support team.\n\n" .
               "Regards,\nPanimalar Engineering College";
    }
    
    /**
     * Get mailbox status for monitoring
     * @return array Array of mailbox statuses
     */
    public function getMailboxStatus() {
        try {
            $query = "SELECT * FROM password_reset_mailbox_status";
            $result = $this->conn->query($query);
            return $result->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting mailbox status: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add new mailbox
     * @param string $email Mailbox email
     * @param string $password Mailbox password
     * @param string $host SMTP host
     * @param int $port SMTP port
     * @param int $daily_limit Daily email limit
     * @param int $monthly_limit Monthly email limit
     * @return bool Success status
     */
    public function addMailbox($email, $password, $host = 'smtp.hostinger.com', $port = 465, $daily_limit = 100, $monthly_limit = 15000) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO password_reset_mailboxes (email, password, host, port, daily_limit, monthly_limit) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssiis", $email, $password, $host, $port, $daily_limit, $monthly_limit);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (Exception $e) {
            error_log("Error adding mailbox: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Toggle mailbox active status
     * @param int $mailbox_id Mailbox ID
     * @param bool $is_active Active status
     * @return bool Success status
     */
    public function toggleMailboxStatus($mailbox_id, $is_active) {
        try {
            $stmt = $this->conn->prepare("UPDATE password_reset_mailboxes SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $is_active, $mailbox_id);
            $result = $stmt->execute();
            $stmt->close();
            return $result;
        } catch (Exception $e) {
            error_log("Error toggling mailbox status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get email sending statistics
     * @param int $days Number of days to look back
     * @return array Statistics
     */
    public function getEmailStats($days = 7) {
        try {
            $stmt = $this->conn->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(*) as total_emails,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_emails,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_emails
                FROM password_reset_email_logs 
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                GROUP BY DATE(created_at)
                ORDER BY date DESC
            ");
            $stmt->bind_param("i", $days);
            $stmt->execute();
            $result = $stmt->get_result();
            $stats = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting email stats: " . $e->getMessage());
            return [];
        }
    }
}
?>