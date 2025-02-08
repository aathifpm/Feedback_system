    -- Step 1: Create backup of existing subjects table
    CREATE TABLE subjects_backup AS SELECT * FROM subjects;

    -- Step 2: Create the new subject_assignments table
    CREATE TABLE subject_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject_id INT NOT NULL,
        faculty_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        year INT NOT NULL,
        semester INT NOT NULL,
        section VARCHAR(1) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_assignment (subject_id, academic_year_id, year, semester, section)
    );

    -- Step 3: Add indexes for better performance
    CREATE INDEX idx_subject_assignments_faculty ON subject_assignments(faculty_id);
    CREATE INDEX idx_subject_assignments_academic_year ON subject_assignments(academic_year_id);
    CREATE INDEX idx_subject_assignments_active ON subject_assignments(is_active);

    -- Step 4: Migrate existing data to subject_assignments
    INSERT INTO subject_assignments (
        subject_id,
        faculty_id,
        academic_year_id,
        year,
        semester,
        section,
        is_active,
        created_at
    )
    SELECT 
        id as subject_id,
        faculty_id,
        academic_year_id,
        year,
        semester,
        section,
        is_active,
        created_at
    FROM subjects_backup;

    -- Step 5: Drop foreign key constraints first
    SET FOREIGN_KEY_CHECKS=0;

    -- Drop existing foreign keys that reference subjects table
    ALTER TABLE feedback 
    DROP FOREIGN KEY IF EXISTS feedback_ibfk_2;

    ALTER TABLE course_outcomes 
    DROP FOREIGN KEY IF EXISTS course_outcomes_ibfk_1;

    -- Drop ALL foreign keys from subjects table
    ALTER TABLE subjects
    DROP FOREIGN KEY IF EXISTS subjects_ibfk_1,
    DROP FOREIGN KEY IF EXISTS subjects_ibfk_2,
    DROP FOREIGN KEY IF EXISTS subjects_ibfk_3,
    DROP FOREIGN KEY IF EXISTS subjects_ibfk_4;

    -- Drop indexes that might be related to foreign keys
    ALTER TABLE subjects
    DROP INDEX IF EXISTS idx_subject_faculty,
    DROP INDEX IF EXISTS idx_subject_academic_year,
    DROP INDEX IF EXISTS faculty_id,
    DROP INDEX IF EXISTS academic_year_id;

    -- Step 6: Now drop the columns
    ALTER TABLE subjects
    DROP COLUMN faculty_id,
    DROP COLUMN academic_year_id,
    DROP COLUMN year,
    DROP COLUMN semester,
    DROP COLUMN section;

    -- Step 7: Add credits column if it doesn't exist
    ALTER TABLE subjects
    ADD COLUMN IF NOT EXISTS credits INT NOT NULL DEFAULT 3;

    -- Step 8: Re-add foreign key constraints
    ALTER TABLE feedback 
    ADD CONSTRAINT feedback_ibfk_2 
    FOREIGN KEY (subject_id) REFERENCES subjects(id);

    ALTER TABLE course_outcomes 
    ADD CONSTRAINT course_outcomes_ibfk_1 
    FOREIGN KEY (subject_id) REFERENCES subjects(id);

    ALTER TABLE subject_assignments
    ADD CONSTRAINT fk_subject_assignments_subject
    FOREIGN KEY (subject_id) REFERENCES subjects(id);

    ALTER TABLE subject_assignments
    ADD CONSTRAINT fk_subject_assignments_faculty
    FOREIGN KEY (faculty_id) REFERENCES faculty(id);

    ALTER TABLE subject_assignments
    ADD CONSTRAINT fk_subject_assignments_academic_year
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id);

    -- Add check constraints
    ALTER TABLE subject_assignments
    ADD CONSTRAINT chk_subject_year 
    CHECK (year BETWEEN 1 AND 4);

    ALTER TABLE subject_assignments
    ADD CONSTRAINT chk_subject_semester 
    CHECK (semester BETWEEN 1 AND 8);

    SET FOREIGN_KEY_CHECKS=1;

-- Step 9: Verify data integrity
SELECT 
    COUNT(*) as total_assignments,
    COUNT(DISTINCT subject_id) as unique_subjects,
    COUNT(DISTINCT faculty_id) as unique_faculty
FROM subject_assignments;

-- Step 10: Drop backup table if everything is successful
-- DROP TABLE subjects_backup;  -- Uncomment this line after verifying the migration
-- Step 1: Add new assignment_id column to feedback table
ALTER TABLE feedback
ADD COLUMN assignment_id INT NULL AFTER student_id;

-- Step 2: Create temporary index for migration
CREATE INDEX tmp_feedback_migration_idx ON feedback(subject_id, academic_year_id);

-- Step 3: Populate assignment_id in feedback table
    UPDATE feedback f
    JOIN students s ON f.student_id = s.id
    JOIN subject_assignments sa ON f.subject_id = sa.subject_id 
        AND f.academic_year_id = sa.academic_year_id
        AND s.section = sa.section
    SET f.assignment_id = sa.id;

-- Step 4: Verify migration count
SELECT COUNT(*) AS migrated_count FROM feedback WHERE assignment_id IS NOT NULL;

-- Step 5: Drop old foreign key constraints
ALTER TABLE feedback
DROP FOREIGN KEY feedback_ibfk_2,
DROP FOREIGN KEY feedback_ibfk_3;

-- Step 6: Modify feedback table structure
ALTER TABLE feedback
CHANGE assignment_id assignment_id INT NOT NULL,
DROP COLUMN subject_id,
DROP COLUMN academic_year_id,
ADD CONSTRAINT fk_feedback_assignment
    FOREIGN KEY (assignment_id) REFERENCES subject_assignments(id),
ADD INDEX idx_feedback_assignment (assignment_id);

-- Step 7: Update feedback_ratings table (if needed)
ALTER TABLE feedback_ratings
DROP COLUMN section;  -- No longer needed

-- Step 8: Update views and stored procedures
ALTER VIEW feedback_summary AS
SELECT 
    sa.id as assignment_id,
    s.code,
    s.name AS subject_name,
    f.name AS faculty_name,
    d.name AS department_name,
    ay.year_range AS academic_year,
    sa.year,
    sa.semester,
    sa.section,
    COUNT(DISTINCT f.id) AS total_feedback,
    AVG(fb.cumulative_avg) AS average_rating
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
JOIN departments d ON s.department_id = d.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
GROUP BY sa.id; 