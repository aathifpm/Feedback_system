-- Password Reset Mailbox Management Setup
-- This is separate from the existing email campaign system

-- Create password reset mailboxes table
CREATE TABLE IF NOT EXISTS password_reset_mailboxes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    host VARCHAR(255) NOT NULL DEFAULT 'smtp.hostinger.com',
    port INT NOT NULL DEFAULT 465,
    daily_limit INT NOT NULL DEFAULT 100,
    monthly_limit INT NOT NULL DEFAULT 15000,
    emails_sent_today INT DEFAULT 0,
    emails_sent_this_month INT DEFAULT 0,
    last_sent_at TIMESTAMP NULL,
    last_reset_date DATE NULL,
    last_monthly_reset DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP
);

-- Create password reset email logs table
CREATE TABLE password_reset_email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mailbox_id INT NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    recipient_name VARCHAR(100) NOT NULL,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'faculty', 'hod', 'student') NOT NULL,
    reset_token VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
    error_message TEXT NULL,
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (mailbox_id) REFERENCES password_reset_mailboxes(id),
    INDEX idx_token (reset_token),
    INDEX idx_status (status),
    INDEX idx_sent_date (sent_at),
    INDEX idx_mailbox_status (mailbox_id, status)
);

-- Insert default password reset mailboxes
INSERT INTO password_reset_mailboxes (email, password, daily_limit, monthly_limit, is_active) VALUES
('no-reply-passwordreset@ads-panimalar.in', 'Passreset@ads-panimalar@321123', 100, 15000, TRUE),
('password-reset-1@ads-panimalar.in', 'your_password_here', 100, 15000, FALSE),
('password-reset-2@ads-panimalar.in', 'your_password_here', 100, 15000, FALSE);

-- Create stored procedure to get available mailbox for password reset
DELIMITER //

CREATE PROCEDURE GetAvailablePasswordResetMailbox()
BEGIN
    DECLARE current_date DATE DEFAULT CURDATE();
    DECLARE current_month_start DATE DEFAULT DATE_FORMAT(CURDATE(), '%Y-%m-01');
    
    -- Reset daily counters if it's a new day
    UPDATE password_reset_mailboxes 
    SET emails_sent_today = 0, last_reset_date = current_date
    WHERE last_reset_date IS NULL OR last_reset_date < current_date;
    
    -- Reset monthly counters if it's a new month
    UPDATE password_reset_mailboxes 
    SET emails_sent_this_month = 0, last_monthly_reset = current_month_start
    WHERE last_monthly_reset IS NULL OR last_monthly_reset < current_month_start;
    
    -- Get the first available mailbox
    SELECT 
        id,
        email,
        password,
        host,
        port,
        daily_limit,
        monthly_limit,
        emails_sent_today,
        emails_sent_this_month,
        (daily_limit - emails_sent_today) AS daily_remaining,
        (monthly_limit - emails_sent_this_month) AS monthly_remaining
    FROM password_reset_mailboxes
    WHERE is_active = TRUE
    AND emails_sent_today < daily_limit
    AND emails_sent_this_month < monthly_limit
    ORDER BY emails_sent_today ASC, emails_sent_this_month ASC
    LIMIT 1;
END //

CREATE PROCEDURE UpdatePasswordResetMailboxUsage(
    IN mailbox_id INT
)
BEGIN
    UPDATE password_reset_mailboxes 
    SET 
        emails_sent_today = emails_sent_today + 1,
        emails_sent_this_month = emails_sent_this_month + 1,
        last_sent_at = CURRENT_TIMESTAMP
    WHERE id = mailbox_id;
END //

CREATE PROCEDURE LogPasswordResetEmail(
    IN p_mailbox_id INT,
    IN p_recipient_email VARCHAR(255),
    IN p_recipient_name VARCHAR(100),
    IN p_user_id INT,
    IN p_user_type VARCHAR(20),
    IN p_reset_token VARCHAR(255),
    IN p_status VARCHAR(20),
    IN p_error_message TEXT
)
BEGIN
    INSERT INTO password_reset_email_logs (
        mailbox_id, recipient_email, recipient_name, user_id, user_type,
        reset_token, status, error_message, sent_at
    ) VALUES (
        p_mailbox_id, p_recipient_email, p_recipient_name, p_user_id, p_user_type,
        p_reset_token, p_status, p_error_message, 
        CASE WHEN p_status = 'sent' THEN CURRENT_TIMESTAMP ELSE NULL END
    );
END //

DELIMITER ;

-- Create indexes for better performance
CREATE INDEX idx_mailbox_active_limits ON password_reset_mailboxes(is_active, emails_sent_today, emails_sent_this_month);
CREATE INDEX idx_mailbox_last_sent ON password_reset_mailboxes(last_sent_at);
CREATE INDEX idx_logs_created_at ON password_reset_email_logs(created_at);
CREATE INDEX idx_logs_user ON password_reset_email_logs(user_id, user_type);

-- Create view for mailbox status monitoring
CREATE OR REPLACE VIEW password_reset_mailbox_status AS
SELECT 
    id,
    email,
    daily_limit,
    monthly_limit,
    emails_sent_today,
    emails_sent_this_month,
    (daily_limit - emails_sent_today) AS daily_remaining,
    (monthly_limit - emails_sent_this_month) AS monthly_remaining,
    CASE 
        WHEN emails_sent_today >= daily_limit THEN 'Daily limit reached'
        WHEN emails_sent_this_month >= monthly_limit THEN 'Monthly limit reached'
        WHEN is_active = FALSE THEN 'Inactive'
        ELSE 'Available'
    END AS status,
    last_sent_at,
    is_active
FROM password_reset_mailboxes
ORDER BY emails_sent_today ASC, emails_sent_this_month ASC;