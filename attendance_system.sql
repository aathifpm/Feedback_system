-- Attendance System Extension for college_feedback database
USE college_feedback;

-- Create venues table to track different locations
CREATE TABLE venues (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    capacity INT,
    building VARCHAR(100),
    room_number VARCHAR(20),
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create academic_class_schedule table for regular academic classes
CREATE TABLE academic_class_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT NOT NULL,
    class_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    topic VARCHAR(255),
    venue_id INT NOT NULL,
    is_cancelled BOOLEAN DEFAULT FALSE,
    cancellation_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES subject_assignments(id),
    FOREIGN KEY (venue_id) REFERENCES venues(id),
    CONSTRAINT chk_academic_class_time CHECK (end_time > start_time),
    UNIQUE KEY unique_academic_class (assignment_id, class_date, start_time, venue_id)
);

-- Create training_session_schedule table for placement training
CREATE TABLE training_session_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_batch_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    topic VARCHAR(255),
    trainer_name VARCHAR(100),
    venue_id INT NOT NULL,
    is_cancelled BOOLEAN DEFAULT FALSE,
    cancellation_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (training_batch_id) REFERENCES training_batches(id),
    FOREIGN KEY (venue_id) REFERENCES venues(id),
    CONSTRAINT chk_training_session_time CHECK (end_time > start_time),
    UNIQUE KEY unique_training_session (training_batch_id, session_date, start_time, venue_id)
);

-- Create training_batches table for placement training groups
CREATE TABLE training_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(50) NOT NULL,
    description TEXT,
    academic_year_id INT NOT NULL,
    department_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Create student_training_batch table to assign students to training batches
CREATE TABLE student_training_batch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    training_batch_id INT NOT NULL,
    assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (training_batch_id) REFERENCES training_batches(id),
    UNIQUE KEY unique_student_batch (student_id, training_batch_id)
);

-- Create academic_attendance_records table for academic classes
CREATE TABLE academic_attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    schedule_id INT NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL DEFAULT 'absent',
    marked_by INT NOT NULL,
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (schedule_id) REFERENCES academic_class_schedule(id),
    FOREIGN KEY (marked_by) REFERENCES faculty(id),
    UNIQUE KEY unique_academic_attendance (student_id, schedule_id)
);

-- Create training_attendance_records table for training sessions
CREATE TABLE training_attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') NOT NULL DEFAULT 'absent',
    marked_by INT NOT NULL,
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (session_id) REFERENCES training_session_schedule(id),
    FOREIGN KEY (marked_by) REFERENCES faculty(id),
    UNIQUE KEY unique_training_attendance (student_id, session_id)
);

-- Create leave_applications table for student absence requests
CREATE TABLE leave_applications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    from_date DATE NOT NULL,
    to_date DATE NOT NULL,
    reason TEXT NOT NULL,
    document_path VARCHAR(255),
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    reviewed_by INT,
    reviewed_at TIMESTAMP NULL,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (reviewed_by) REFERENCES faculty(id),
    CONSTRAINT chk_leave_dates CHECK (to_date >= from_date)
);

-- Create attendance_settings table for configuration
CREATE TABLE attendance_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    min_attendance_percentage DECIMAL(5,2) NOT NULL DEFAULT 75.00,
    late_threshold_minutes INT NOT NULL DEFAULT 10,
    auto_mark_absent_after_minutes INT NOT NULL DEFAULT 30,
    allow_leave_applications BOOLEAN DEFAULT TRUE,
    allow_student_view BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    UNIQUE KEY unique_settings (department_id, academic_year_id)
);

-- Create regular class attendance view
CREATE OR REPLACE VIEW regular_attendance_summary AS
SELECT 
    s.id AS student_id,
    s.roll_number,
    s.name AS student_name,
    sa.id AS assignment_id,
    subj.name AS subject_name,
    subj.code AS subject_code,
    f.name AS faculty_name,
    COUNT(DISTINCT acs.id) AS total_classes,
    SUM(CASE WHEN aar.status = 'present' THEN 1 ELSE 0 END) AS classes_attended,
    SUM(CASE WHEN aar.status = 'late' THEN 1 ELSE 0 END) AS classes_late,
    SUM(CASE WHEN aar.status = 'excused' THEN 1 ELSE 0 END) AS classes_excused,
    SUM(CASE WHEN aar.status = 'absent' THEN 1 ELSE 0 END) AS classes_absent,
    ROUND((SUM(CASE WHEN aar.status IN ('present', 'excused') THEN 1 ELSE 0 END) / COUNT(DISTINCT acs.id)) * 100, 2) AS attendance_percentage
FROM 
    students s
JOIN 
    batch_years batch ON s.batch_id = batch.id
JOIN 
    subject_assignments sa ON batch.current_year_of_study = sa.year
JOIN 
    subjects subj ON sa.subject_id = subj.id
JOIN 
    faculty f ON sa.faculty_id = f.id
JOIN 
    academic_class_schedule acs ON sa.id = acs.assignment_id
LEFT JOIN 
    academic_attendance_records aar ON acs.id = aar.schedule_id AND s.id = aar.student_id
WHERE 
    sa.is_active = TRUE AND
    acs.is_cancelled = FALSE
GROUP BY 
    s.id, sa.id
ORDER BY 
    s.roll_number, subj.code;

-- Create placement training attendance view
CREATE OR REPLACE VIEW placement_training_attendance AS
SELECT 
    s.id AS student_id,
    s.roll_number,
    s.name AS student_name,
    d.name AS department_name,
    tb.batch_name AS training_batch,
    tb.id AS training_batch_id,
    v.name AS venue,
    tss.topic AS training_topic,
    COUNT(DISTINCT tss.id) AS total_sessions,
    SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS sessions_attended,
    SUM(CASE WHEN tar.status = 'absent' THEN 1 ELSE 0 END) AS sessions_missed,
    ROUND((SUM(CASE WHEN tar.status IN ('present', 'excused') THEN 1 ELSE 0 END) / COUNT(DISTINCT tss.id)) * 100, 2) AS attendance_percentage
FROM 
    students s
JOIN 
    departments d ON s.department_id = d.id
JOIN 
    student_training_batch stb ON s.id = stb.student_id
JOIN 
    training_batches tb ON stb.training_batch_id = tb.id
JOIN 
    training_session_schedule tss ON tss.training_batch_id = tb.id
JOIN 
    venues v ON tss.venue_id = v.id
LEFT JOIN 
    training_attendance_records tar ON tss.id = tar.session_id AND s.id = tar.student_id
WHERE 
    stb.is_active = TRUE AND
    tss.is_cancelled = FALSE
GROUP BY 
    s.id, tb.id
ORDER BY 
    d.name, s.roll_number;

-- Create indexes for better performance
CREATE INDEX idx_academic_schedule_date ON academic_class_schedule(class_date);
CREATE INDEX idx_academic_schedule_assignment ON academic_class_schedule(assignment_id);
CREATE INDEX idx_academic_schedule_venue ON academic_class_schedule(venue_id);
CREATE INDEX idx_training_schedule_date ON training_session_schedule(session_date);
CREATE INDEX idx_training_schedule_batch ON training_session_schedule(training_batch_id);
CREATE INDEX idx_training_schedule_venue ON training_session_schedule(venue_id);
CREATE INDEX idx_academic_attendance_status ON academic_attendance_records(status);
CREATE INDEX idx_academic_attendance_date ON academic_attendance_records(created_at);
CREATE INDEX idx_training_attendance_status ON training_attendance_records(status);
CREATE INDEX idx_training_attendance_date ON training_attendance_records(created_at);
CREATE INDEX idx_leave_applications_dates ON leave_applications(from_date, to_date);
CREATE INDEX idx_student_training_batch ON student_training_batch(student_id, training_batch_id);

-- Create stored procedure for bulk academic attendance marking
DELIMITER //

CREATE PROCEDURE MarkBulkAcademicAttendance(
    IN p_schedule_id INT,
    IN p_marked_by INT,
    IN p_default_status VARCHAR(10)
)
BEGIN
    -- Insert academic attendance records
    INSERT INTO academic_attendance_records (student_id, schedule_id, status, marked_by)
    SELECT 
        s.id,
        p_schedule_id,
        p_default_status,
        p_marked_by
    FROM 
        academic_class_schedule acs
    JOIN 
        subject_assignments sa ON acs.assignment_id = sa.id
    JOIN 
        students s ON s.department_id = sa.department_id AND s.section = sa.section
    WHERE 
        acs.id = p_schedule_id
        AND s.batch_id IN (
            SELECT id FROM batch_years 
            WHERE current_year_of_study = sa.year
        )
    ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        marked_by = VALUES(marked_by),
        updated_at = CURRENT_TIMESTAMP;
END //

-- Create stored procedure for bulk training attendance marking
CREATE PROCEDURE MarkBulkTrainingAttendance(
    IN p_session_id INT,
    IN p_marked_by INT,
    IN p_default_status VARCHAR(10)
)
BEGIN
    -- Insert training attendance records
    INSERT INTO training_attendance_records (student_id, session_id, status, marked_by)
    SELECT 
        s.id,
        p_session_id,
        p_default_status,
        p_marked_by
    FROM 
        training_session_schedule tss
    JOIN 
        training_batches tb ON tss.training_batch_id = tb.id
    JOIN 
        student_training_batch stb ON tb.id = stb.training_batch_id
    JOIN 
        students s ON stb.student_id = s.id
    WHERE 
        tss.id = p_session_id
        AND stb.is_active = TRUE
    ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        marked_by = VALUES(marked_by),
        updated_at = CURRENT_TIMESTAMP;
END //

-- Create trigger to notify students who are marked absent in academic classes
CREATE TRIGGER after_academic_attendance_update
AFTER UPDATE ON academic_attendance_records
FOR EACH ROW
BEGIN
    DECLARE v_topic VARCHAR(255);
    
    -- Get class topic
    SELECT acs.topic INTO v_topic
    FROM academic_class_schedule acs
    WHERE acs.id = NEW.schedule_id;
    
    -- If student was marked absent, create a notification
    IF NEW.status = 'absent' THEN
        INSERT INTO notifications (user_id, role, message)
        VALUES (
            NEW.student_id,
            'student',
            CONCAT(
                'You were marked absent for class',
                IF(v_topic IS NOT NULL, CONCAT(' on ', v_topic), ''),
                '. Please check your attendance record.'
            )
        );
    END IF;
END //

-- Create trigger to notify students who are marked absent in training sessions
CREATE TRIGGER after_training_attendance_update
AFTER UPDATE ON training_attendance_records
FOR EACH ROW
BEGIN
    DECLARE v_topic VARCHAR(255);
    DECLARE v_batch_name VARCHAR(50);
    
    -- Get training session topic and batch name
    SELECT tss.topic, tb.batch_name INTO v_topic, v_batch_name
    FROM training_session_schedule tss
    JOIN training_batches tb ON tss.training_batch_id = tb.id
    WHERE tss.id = NEW.session_id;
    
    -- If student was marked absent, create a notification
    IF NEW.status = 'absent' THEN
        INSERT INTO notifications (user_id, role, message)
        VALUES (
            NEW.student_id,
            'student',
            CONCAT(
                'You were marked absent for placement training session',
                IF(v_batch_name IS NOT NULL, CONCAT(' in batch ', v_batch_name), ''),
                IF(v_topic IS NOT NULL, CONCAT(' on ', v_topic), ''),
                '. Please check your attendance record.'
            )
        );
    END IF;
END //

DELIMITER ;

-- Insert default data
INSERT INTO venues (name, building, room_number) VALUES
('Main Auditorium', 'Main Block', 'AUD101'),
('Seminar Hall 1', 'Engineering Block', 'SH101'),
('Computer Lab 1', 'CS Block', 'CSL01'),
('Computer Lab 2', 'CS Block', 'CSL02'),
('Training Room A', 'Placement Block', 'TR-A'),
('Training Room B', 'Placement Block', 'TR-B');

-- Insert default attendance settings
INSERT INTO attendance_settings (department_id, academic_year_id, min_attendance_percentage)
SELECT d.id, ay.id, 75.00
FROM departments d, academic_years ay
WHERE ay.is_current = TRUE;                                                      