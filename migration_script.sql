-- Migration Script to Update Attendance System
-- This script converts the existing combined class_schedule and attendance_records
-- to separated academic and training tables

USE college_feedback;

-- Start transaction for safe migration
START TRANSACTION;

-- Step 1: Create backup tables
CREATE TABLE class_schedule_backup LIKE class_schedule;
INSERT INTO class_schedule_backup SELECT * FROM class_schedule;

CREATE TABLE attendance_records_backup LIKE attendance_records;
INSERT INTO attendance_records_backup SELECT * FROM attendance_records;

-- Step 2: Create new tables
-- Create academic_class_schedule table
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

-- Create training_session_schedule table
CREATE TABLE training_session_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    training_batch_id INT NOT NULL,
    session_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    topic VARCHAR(255),
    venue_id INT NOT NULL,
    is_cancelled BOOLEAN DEFAULT FALSE,
    cancellation_reason VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (training_batch_id) REFERENCES training_batches(id),
    FOREIGN KEY (venue_id) REFERENCES venues(id),
    CONSTRAINT chk_training_session_time CHECK (end_time > start_time),
    UNIQUE KEY unique_training_session (training_batch_id, session_date, start_time, venue_id)
);

-- Create academic_attendance_records table
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

-- Create training_attendance_records table
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

-- Step 3: Create mapping tables to track old IDs to new IDs
CREATE TEMPORARY TABLE academic_class_id_mapping (
    old_id INT,
    new_id INT
);

CREATE TEMPORARY TABLE training_session_id_mapping (
    old_id INT,
    new_id INT
);

-- Step 4: Migrate regular class schedules
INSERT INTO academic_class_schedule (
    assignment_id,
    class_date,
    start_time,
    end_time,
    topic,
    venue_id,
    is_cancelled,
    cancellation_reason,
    created_at
)
SELECT 
    assignment_id,
    class_date,
    start_time,
    end_time,
    topic,
    venue_id,
    is_cancelled,
    cancellation_reason,
    created_at
FROM 
    class_schedule
WHERE 
    is_placement_training = FALSE AND
    assignment_id IS NOT NULL;

-- Track the ID mappings for academic classes
INSERT INTO academic_class_id_mapping (old_id, new_id)
SELECT cs.id, acs.id
FROM class_schedule cs
JOIN academic_class_schedule acs ON 
    cs.assignment_id = acs.assignment_id AND
    cs.class_date = acs.class_date AND
    cs.start_time = acs.start_time AND
    cs.venue_id = acs.venue_id
WHERE cs.is_placement_training = FALSE;

-- Step 5: Migrate training sessions
-- First, get batch IDs from training_batches based on batch_name
INSERT INTO training_session_schedule (
    training_batch_id,
    session_date,
    start_time,
    end_time,
    topic,
    venue_id,
    is_cancelled,
    cancellation_reason,
    created_at
)
SELECT 
    tb.id AS training_batch_id,
    cs.class_date AS session_date,
    cs.start_time,
    cs.end_time,
    cs.topic,
    cs.venue_id,
    cs.is_cancelled,
    cs.cancellation_reason,
    cs.created_at
FROM 
    class_schedule cs
JOIN 
    training_batches tb ON cs.training_batch_name = tb.batch_name
WHERE 
    cs.is_placement_training = TRUE;

-- Track the ID mappings for training sessions
INSERT INTO training_session_id_mapping (old_id, new_id)
SELECT cs.id, tss.id
FROM class_schedule cs
JOIN training_batches tb ON cs.training_batch_name = tb.batch_name
JOIN training_session_schedule tss ON 
    tss.training_batch_id = tb.id AND
    tss.session_date = cs.class_date AND
    tss.start_time = cs.start_time AND
    tss.venue_id = cs.venue_id
WHERE cs.is_placement_training = TRUE;

-- Step 6: Migrate academic attendance records
INSERT INTO academic_attendance_records (
    student_id,
    schedule_id,
    status,
    marked_by,
    remarks,
    created_at,
    updated_at
)
SELECT 
    ar.student_id,
    acim.new_id AS schedule_id,
    ar.status,
    ar.marked_by,
    ar.remarks,
    ar.created_at,
    ar.updated_at
FROM 
    attendance_records ar
JOIN 
    academic_class_id_mapping acim ON ar.schedule_id = acim.old_id;

-- Step 7: Migrate training attendance records
INSERT INTO training_attendance_records (
    student_id,
    session_id,
    status,
    marked_by,
    remarks,
    created_at,
    updated_at
)
SELECT 
    ar.student_id,
    tsim.new_id AS session_id,
    ar.status,
    ar.marked_by,
    ar.remarks,
    ar.created_at,
    ar.updated_at
FROM 
    attendance_records ar
JOIN 
    training_session_id_mapping tsim ON ar.schedule_id = tsim.old_id;

-- Step 8: Create indexes for better performance
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

-- Step 9: Create the new stored procedures
DELIMITER //

-- For academic classes
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

-- For training sessions
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

-- Step 10: Create new triggers
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

-- Step 11: Update views
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

-- Step 12: Verify data integrity - count records to ensure all data was migrated
SELECT COUNT(*) AS original_academic_classes FROM class_schedule WHERE is_placement_training = FALSE;
SELECT COUNT(*) AS migrated_academic_classes FROM academic_class_schedule;

SELECT COUNT(*) AS original_training_sessions FROM class_schedule WHERE is_placement_training = TRUE;
SELECT COUNT(*) AS migrated_training_sessions FROM training_session_schedule;

SELECT COUNT(*) AS original_academic_attendance FROM attendance_records ar 
JOIN class_schedule cs ON ar.schedule_id = cs.id WHERE cs.is_placement_training = FALSE;
SELECT COUNT(*) AS migrated_academic_attendance FROM academic_attendance_records;

SELECT COUNT(*) AS original_training_attendance FROM attendance_records ar 
JOIN class_schedule cs ON ar.schedule_id = cs.id WHERE cs.is_placement_training = TRUE;
SELECT COUNT(*) AS migrated_training_attendance FROM training_attendance_records;

-- If all counts match, proceed to drop old tables and procedures
-- Step 13: Drop old tables and procedures (only if verification passes)
-- Uncomment these lines after verifying counts
/*
-- Drop old trigger
DROP TRIGGER IF EXISTS after_attendance_record_update;

-- Drop old procedure
DROP PROCEDURE IF EXISTS MarkBulkAttendance;

-- Drop old tables (remove these foreign key checks if needed)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS class_schedule;
SET FOREIGN_KEY_CHECKS = 1;
*/

-- Commit the transaction if everything went well
COMMIT;

-- If there were errors, you can rollback instead
-- ROLLBACK; 