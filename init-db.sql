-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS college_feedback1;
USE college_feedback1;

-- Create basic tables if they don't exist
-- This is a minimal setup to ensure the application can start
-- The full schema will be loaded from migration_script.sql and alumni_survey_tables.sql

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'faculty', 'student', 'hod') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin user if not exists
INSERT INTO users (username, password, role)
SELECT 'admin', '$2y$10$8WxmVVD1DvV9Rkzh1QDRYuuEgCLHVF5UVDZYpgQb.jfF9xfmJRhYa', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- Academic years table
CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year VARCHAR(9) NOT NULL UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert current academic year if table is empty
INSERT INTO academic_years (year, is_active)
SELECT '2023-2024', TRUE
WHERE NOT EXISTS (SELECT 1 FROM academic_years LIMIT 1); 