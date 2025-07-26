<?php
require_once '../../functions.php';
require_once '../../db_connection.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class EmailQueueProcessor {
    private $conn;
    private $log_file;
    private $current_mailbox;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->log_file = dirname(__FILE__) . "/email_queue.log";
        $this->logMessage("Starting email queue processor");
    }
    
    public function process() {
        try {
            // Reset daily counts at midnight
            $this->resetDailyCounts();
            
            // Process pending campaigns
            $campaigns = $this->getPendingCampaigns();
            foreach ($campaigns as $campaign) {
                $this->processCampaign($campaign);
            }
            
        } catch (Exception $e) {
            $this->logMessage("Error: " . $e->getMessage());
        }
    }
    
    private function resetDailyCounts() {
        $query = "UPDATE email_mailboxes 
                 SET emails_sent_today = 0 
                 WHERE DATE(last_sent_at) < CURDATE()";
        mysqli_query($this->conn, $query);
    }
    
    private function getPendingCampaigns() {
        $query = "SELECT * FROM email_campaigns 
                 WHERE status IN ('pending', 'in_progress') 
                 ORDER BY created_at ASC";
        $result = mysqli_query($this->conn, $query);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
    
    private function getAvailableMailbox() {
        mysqli_begin_transaction($this->conn);
        
        $query = "SELECT * FROM email_mailboxes 
                 WHERE is_active = 1 
                 AND emails_sent_today < daily_limit 
                 ORDER BY COALESCE(last_sent_at, '1970-01-01'), id ASC 
                 LIMIT 1
                 FOR UPDATE";
        
        $result = mysqli_query($this->conn, $query);
        $mailbox = mysqli_fetch_assoc($result);
        
        if ($mailbox) {
            // Lock this mailbox by updating its last_sent_at
            $update = "UPDATE email_mailboxes 
                      SET last_sent_at = NOW() 
                      WHERE id = ?";
            $stmt = mysqli_prepare($this->conn, $update);
            mysqli_stmt_bind_param($stmt, "i", $mailbox['id']);
            mysqli_stmt_execute($stmt);
        }
        
        mysqli_commit($this->conn);
        return $mailbox;
    }
    
    private function processCampaign($campaign) {
        // Get next batch of recipients (up to 100)
        $recipients = $this->getNextRecipientBatch($campaign['id']);
        if (empty($recipients)) {
            if ($this->isCampaignComplete($campaign['id'])) {
                $this->updateCampaignStatus($campaign['id'], 'completed');
            }
            return;
        }
        
        // Get available mailbox
        $mailbox = $this->getAvailableMailbox();
        if (!$mailbox) {
            $this->logMessage("No available mailboxes for campaign {$campaign['id']}");
            return;
        }
        
        // Update campaign status
        $this->updateCampaignStatus($campaign['id'], 'in_progress');
        
        // Setup mailer
        $mailer = $this->setupMailer($mailbox);
        
        // Group recipients into batches of mailbox's recipients_per_email limit
        $batches = array_chunk($recipients, $mailbox['recipients_per_email']);
        
        foreach ($batches as $batch) {
            try {
                $mailer->clearAddresses();
                
                // Add all recipients in this batch
                foreach ($batch as $recipient) {
                    $mailer->addBCC($recipient['email'], $recipient['name']);
                }
                
                $mailer->Subject = $campaign['subject'];
                $mailer->Body = $this->getEmailBody($campaign['message']);
                
                if ($mailer->send()) {
                    // Update status for all recipients in this batch
                    foreach ($batch as $recipient) {
                        $this->updateRecipientStatus($recipient['id'], 'sent');
                    }
                    
                    // Update mailbox stats
                    $this->updateMailboxStats($mailbox['id']);
                    
                    // Update campaign sent count
                    $this->incrementCampaignSentCount($campaign['id'], count($batch));
                    
                } else {
                    foreach ($batch as $recipient) {
                        $this->updateRecipientStatus($recipient['id'], 'failed', $mailer->ErrorInfo);
                    }
                }
                
            } catch (Exception $e) {
                $this->logMessage("Error sending batch in campaign {$campaign['id']}: " . $e->getMessage());
                foreach ($batch as $recipient) {
                    $this->updateRecipientStatus($recipient['id'], 'failed', $e->getMessage());
                }
            }
            
            // Sleep briefly between batches to avoid overwhelming the server
            usleep(100000); // 100ms
        }
    }
    
    private function setupMailer($mailbox) {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailbox['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $mailbox['email'];
        $mail->Password = $mailbox['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = $mailbox['port'];
        $mail->setFrom($mailbox['email'], 'Panimalar Engineering College');
        $mail->isHTML(true);
        return $mail;
    }
    
    private function getNextRecipientBatch($campaign_id) {
        $query = "SELECT * FROM email_queue 
                 WHERE campaign_id = ? AND status = 'pending' 
                 LIMIT 100 FOR UPDATE SKIP LOCKED";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $campaign_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }
    
    private function updateRecipientStatus($recipient_id, $status, $error_message = null) {
        $query = "UPDATE email_queue 
                 SET status = ?, error_message = ?, sent_at = NOW() 
                 WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "ssi", $status, $error_message, $recipient_id);
        mysqli_stmt_execute($stmt);
    }
    
    private function updateCampaignStatus($campaign_id, $status) {
        $query = "UPDATE email_campaigns SET status = ? WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "si", $status, $campaign_id);
        mysqli_stmt_execute($stmt);
    }
    
    private function incrementCampaignSentCount($campaign_id, $count) {
        $query = "UPDATE email_campaigns 
                 SET sent_count = sent_count + ?, 
                     updated_at = NOW()
                 WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $count, $campaign_id);
        mysqli_stmt_execute($stmt);
    }
    
    private function updateMailboxStats($mailbox_id) {
        $query = "UPDATE email_mailboxes 
                 SET emails_sent_today = emails_sent_today + 1,
                     last_sent_at = NOW() 
                 WHERE id = ?";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $mailbox_id);
        mysqli_stmt_execute($stmt);
    }
    
    private function isCampaignComplete($campaign_id) {
        $query = "SELECT COUNT(*) as pending 
                 FROM email_queue 
                 WHERE campaign_id = ? AND status = 'pending'";
        $stmt = mysqli_prepare($this->conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $campaign_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($result);
        return $row['pending'] == 0;
    }
    
    private function getEmailBody($message) {
        return '<!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { text-align: center; padding-bottom: 20px; }
                .content { padding: 20px 0; }
                .footer { margin-top: 30px; font-size: 12px; text-align: center; color: #777; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <img src="https://ads-panimalar.in/college_logo.png" alt="College Logo" style="max-width: 80px;">
                    <h2>Panimalar Engineering College</h2>
                </div>
                <div class="content">
                    <p>Dear Student/Faculty Member,</p>
                    ' . $message . '
                    <p><br>Best regards,<br>Panimalar Engineering College</p>
                </div>
                <div class="footer">
                    <p>This is an automated email from the college management system.</p>
                    <p>Panimalar Engineering College, Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123</p>
                </div>
            </div>
        </body>
        </html>';
    }
    
    private function logMessage($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->log_file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

// Run the processor
try {
    mysqli_begin_transaction($conn);
    $processor = new EmailQueueProcessor($conn);
    $processor->process();
    mysqli_commit($conn);
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error in email queue processor: " . $e->getMessage());
}
