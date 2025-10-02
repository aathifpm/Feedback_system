-- Create maintenance_mode table
CREATE TABLE IF NOT EXISTS maintenance_mode (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT FALSE,
    message TEXT,
    start_time DATETIME NULL,
    end_time DATETIME NULL,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_is_active (is_active),
    INDEX idx_updated_at (updated_at)
);

-- Insert default maintenance settings for all modules
INSERT IGNORE INTO maintenance_mode (module, is_active, message) VALUES
('student', FALSE, 'Student portal is temporarily unavailable for maintenance. Please try again later.'),
('faculty', FALSE, 'Faculty portal is temporarily unavailable for maintenance. Please try again later.'),
('hod', FALSE, 'HOD portal is temporarily unavailable for maintenance. Please try again later.'),
('admin', FALSE, 'Admin portal is temporarily unavailable for maintenance. Please contact system administrator.'),
('global', FALSE, 'System is temporarily unavailable for maintenance. Please try again later.');

-- Create maintenance_logs table for tracking maintenance activities
CREATE TABLE IF NOT EXISTS maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module VARCHAR(50) NOT NULL,
    action ENUM('enabled', 'disabled', 'updated') NOT NULL,
    previous_status BOOLEAN,
    new_status BOOLEAN,
    message TEXT,
    admin_id INT,
    admin_name VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_module (module),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);