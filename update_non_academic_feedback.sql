-- Update script for existing non_academic_feedback_statements table
-- Add statement_type column if it doesn't exist
ALTER TABLE non_academic_feedback_statements 
ADD COLUMN statement_type ENUM('rating', 'comment') DEFAULT 'comment' AFTER statement;

-- Update existing statements to use the new structure
-- Clear existing statements first
DELETE FROM non_academic_feedback_statements;

-- Insert new statements with both rating and comment types
INSERT INTO non_academic_feedback_statements (statement_number, statement, statement_type, is_required) VALUES
(1, 'Rate the overall campus facilities (cleanliness, restrooms, etc.)', 'rating', TRUE),
(2, 'Rate the library facilities and resources', 'rating', TRUE),
(3, 'Rate the canteen/food services quality', 'rating', TRUE),
(4, 'Rate the sports and recreational facilities', 'rating', TRUE),
(5, 'Rate the hostel facilities (if applicable)', 'rating', FALSE),
(6, 'Rate the transport services (if applicable)', 'rating', FALSE),
(7, 'Rate the overall campus environment and atmosphere', 'rating', TRUE),
(8, 'Rate the administrative services and support', 'rating', TRUE),
(9, 'What is your feedback about campus facilities (cleanliness, restrooms etc.)?', 'comment', TRUE),
(10, 'What suggestions do you have to improve the non-academic experience for students?', 'comment', TRUE),
(11, 'Any other feedback or suggestions?', 'comment', FALSE);