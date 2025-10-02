 -- Add feedback section controls table to manage visibility of feedback sections
CREATE TABLE IF NOT EXISTS feedback_section_controls (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_enabled BOOLEAN DEFAULT TRUE,
    academic_year_id INT NULL,
    department_id INT NULL,
    batch_id INT NULL,
    year_of_study INT NULL,
    semester INT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (batch_id) REFERENCES batch_years(id),
    FOREIGN KEY (created_by) REFERENCES admin_users(id),
    INDEX idx_section_name (section_name),
    INDEX idx_enabled (is_enabled),
    INDEX idx_academic_year (academic_year_id),
    INDEX idx_department (department_id)
);

-- Insert default feedback sections
INSERT INTO feedback_section_controls (section_name, display_name, description, is_enabled, created_by) VALUES
('regular_feedback', 'Regular Subject Feedback', 'Standard feedback for subjects assigned to students', TRUE, 1),
('class_committee_feedback', 'Class Committee Meetings', 'Academic feedback through class committee meetings', TRUE, 1),
('exit_survey', 'Exit Survey', 'Exit survey for final year students', TRUE, 1),
('feedback_history', 'Feedback History', 'View previously submitted feedback', TRUE, 1),
('non_academic_feedback', 'Non-Academic Feedback', 'Feedback for non-academic services and facilities', TRUE, 1),
('examination_feedback', 'Examination Feedback', 'Feedback for examination process and conduct', TRUE, 1);

-- Add function to check if section is enabled for a student
DELIMITER //
CREATE FUNCTION IsFeedbackSectionEnabled(
    section_name VARCHAR(50),
    student_department_id INT,
    student_batch_id INT,
    student_year INT,
    student_semester INT,
    current_academic_year_id INT
) RETURNS BOOLEAN
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE section_enabled BOOLEAN DEFAULT FALSE;
    DECLARE section_count INT DEFAULT 0;
    
    SELECT COUNT(*) INTO section_count
    FROM feedback_section_controls fsc
    WHERE fsc.section_name = section_name
    AND fsc.is_enabled = TRUE
    AND (fsc.academic_year_id IS NULL OR fsc.academic_year_id = current_academic_year_id)
    AND (fsc.department_id IS NULL OR fsc.department_id = student_department_id)
    AND (fsc.batch_id IS NULL OR fsc.batch_id = student_batch_id)
    AND (fsc.year_of_study IS NULL OR fsc.year_of_study = student_year)
    AND (fsc.semester IS NULL OR fsc.semester = student_semester)
    AND (fsc.start_date IS NULL OR fsc.start_date <= CURDATE())
    AND (fsc.end_date IS NULL OR fsc.end_date >= CURDATE());
    
    IF section_count > 0 THEN
        SET section_enabled = TRUE;
    END IF;
    
    RETURN section_enabled;
END //
DELIMITER ;