ALTER TABLE admin_users 
ADD COLUMN department_id INT NULL,
ADD FOREIGN KEY (department_id) REFERENCES departments(id);

-- First, drop the existing unique constraint on code column
ALTER TABLE subjects DROP INDEX code;

-- Then add a new composite unique constraint on code and department_id
ALTER TABLE subjects ADD CONSTRAINT unique_subject_per_department UNIQUE (code, department_id);

-- Create exam_timetable table with updated structure
CREATE TABLE exam_timetable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    semester INT NOT NULL,
    subject_id INT NOT NULL,
    exam_date DATE NOT NULL,
    exam_session ENUM('Morning', 'Afternoon') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    CONSTRAINT chk_semester CHECK (semester BETWEEN 1 AND 8),
    CONSTRAINT chk_exam_time CHECK (end_time > start_time),
    UNIQUE KEY unique_exam_schedule (academic_year_id, semester, subject_id)
); 

-- Add examination feedback tables
CREATE TABLE examination_feedback_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    statement TEXT NOT NULL,
    section VARCHAR(50) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_section (section)
);

CREATE TABLE examination_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    subject_assignment_id INT NOT NULL,
    exam_timetable_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comments TEXT,
    coverage_relevance_avg DECIMAL(3,2),
    quality_clarity_avg DECIMAL(3,2),
    structure_balance_avg DECIMAL(3,2),
    application_innovation_avg DECIMAL(3,2),
    cumulative_avg DECIMAL(3,2),
    syllabus_coverage TEXT,
    difficult_questions TEXT,
    out_of_syllabus TEXT,
    time_sufficiency TEXT,
    fairness_rating TEXT,
    improvements TEXT,
    additional_comments TEXT,
    student_declaration BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (subject_assignment_id) REFERENCES subject_assignments(id),
    FOREIGN KEY (exam_timetable_id) REFERENCES exam_timetable(id),
    UNIQUE KEY unique_exam_feedback (student_id, subject_assignment_id, exam_timetable_id)
);

CREATE TABLE examination_feedback_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NOT NULL,
    statement_id INT NOT NULL,
    rating INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (feedback_id) REFERENCES examination_feedback(id),
    FOREIGN KEY (statement_id) REFERENCES examination_feedback_statements(id),
    CONSTRAINT chk_exam_rating CHECK (rating BETWEEN 1 AND 5)
);

-- Insert examination feedback statements
INSERT INTO examination_feedback_statements (statement, section) VALUES
-- Coverage and Relevance
('The question paper covered all important topics from the syllabus', 'COVERAGE_RELEVANCE'),
('The questions were relevant to the course objectives', 'COVERAGE_RELEVANCE'),
('The distribution of marks was appropriate across topics', 'COVERAGE_RELEVANCE'),
('The questions tested both theoretical and practical knowledge', 'COVERAGE_RELEVANCE'),

-- Quality and Clarity
('The questions were clearly worded and unambiguous', 'QUALITY_CLARITY'),
('The instructions were clear and easy to understand', 'QUALITY_CLARITY'),
('The questions were free from grammatical and typographical errors', 'QUALITY_CLARITY'),
('The marking scheme was clearly specified', 'QUALITY_CLARITY'),

-- Structure and Balance
('The question paper had a good mix of easy, moderate, and difficult questions', 'STRUCTURE_BALANCE'),
('The distribution of questions across different cognitive levels was appropriate', 'STRUCTURE_BALANCE'),
('The paper maintained a good balance between theory and application', 'STRUCTURE_BALANCE'),
('The sequence of questions was logical and well-organized', 'STRUCTURE_BALANCE'),

-- Application and Innovation
('The questions encouraged critical thinking and problem-solving', 'APPLICATION_INNOVATION'),
('The paper included innovative and application-based questions', 'APPLICATION_INNOVATION'),
('The questions tested the ability to apply concepts to real-world scenarios', 'APPLICATION_INNOVATION'),
('The paper included questions that tested higher-order thinking skills', 'APPLICATION_INNOVATION');

-- Add indexes for better performance
CREATE INDEX idx_exam_feedback_student ON examination_feedback(student_id);
CREATE INDEX idx_exam_feedback_assignment ON examination_feedback(subject_assignment_id);
CREATE INDEX idx_exam_feedback_timetable ON examination_feedback(exam_timetable_id);
CREATE INDEX idx_exam_feedback_ratings ON examination_feedback_ratings(feedback_id, statement_id);

-- Add exam timetable table

-- Insert sample exam timetable data
INSERT INTO exam_timetable (academic_year_id, semester, exam_date, exam_session, start_time, end_time) VALUES
(1, 1, '2024-05-01', 'Morning', '09:00:00', '12:00:00'),
(1, 1, '2024-05-02', 'Morning', '09:00:00', '12:00:00'),
(1, 1, '2024-05-03', 'Morning', '09:00:00', '12:00:00'),
(1, 1, '2024-05-04', 'Morning', '09:00:00', '12:00:00'),
(1, 1, '2024-05-05', 'Morning', '09:00:00', '12:00:00');

-- Add indexes for better performance
CREATE INDEX idx_exam_timetable_academic_year ON exam_timetable(academic_year_id);
CREATE INDEX idx_exam_timetable_semester ON exam_timetable(semester);
CREATE INDEX idx_exam_timetable_date ON exam_timetable(exam_date);

-- Create a separate student_recruitment_profiles table instead of altering students table
CREATE TABLE student_recruitment_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    linkedin_url VARCHAR(255) NULL,
    github_url VARCHAR(255) NULL,
    portfolio_url VARCHAR(255) NULL,
    resume_path VARCHAR(255) NULL,
    skills TEXT NULL,
    achievements TEXT NULL,
    career_objective TEXT NULL,
    certifications TEXT NULL,
    internship_experience TEXT NULL,
    placement_status ENUM('not_started', 'in_progress', 'placed', 'not_interested') DEFAULT 'not_started',
    company_placed VARCHAR(100) NULL,
    placement_date DATE NULL,
    placement_package VARCHAR(50) NULL,
    placement_role VARCHAR(100) NULL,
    public_profile BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_profile (student_id),
    headline VARCHAR(255) NULL,
    about TEXT NULL,
    location VARCHAR(255) NULL,
    willing_to_relocate BOOLEAN DEFAULT FALSE,
    looking_for ENUM('Internship', 'Full-time', 'Part-time', 'Contract', 'Not actively looking') DEFAULT 'Full-time',
    profile_views INT DEFAULT 0,
    last_updated TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    internship_certificates_path VARCHAR(255) NULL COMMENT 'Path to combined PDF of internship certificates',
    course_certificates_path VARCHAR(255) NULL COMMENT 'Path to combined PDF of course completion certificates',
    achievement_certificates_path VARCHAR(255) NULL COMMENT 'Path to combined PDF of hackathons, events, and other achievement certificates'
);

-- Create index for faster recruitment-based queries
CREATE INDEX idx_recruitment_placement ON student_recruitment_profiles(placement_status);
CREATE INDEX idx_recruitment_public ON student_recruitment_profiles(public_profile);

-- Add index for faster certificate path lookups
CREATE INDEX idx_certificate_paths ON student_recruitment_profiles(internship_certificates_path, course_certificates_path, achievement_certificates_path);

-- Create education history table for students
CREATE TABLE student_education (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    institution_name VARCHAR(255) NOT NULL,
    degree VARCHAR(255) NOT NULL,
    field_of_study VARCHAR(255) NULL,
    start_year YEAR NOT NULL,
    end_year YEAR NULL,
    is_current BOOLEAN DEFAULT FALSE,
    grade VARCHAR(50) NULL,
    activities TEXT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_student_education (student_id)
);

-- Create work experience table for students
CREATE TABLE student_experience (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    employment_type ENUM('Full-time', 'Part-time', 'Internship', 'Freelance', 'Contract', 'Volunteer') NULL,
    company_name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NULL,
    is_current BOOLEAN DEFAULT FALSE,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    description TEXT NULL,
    skills_used TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_student_experience (student_id)
);

-- Create table for projects
CREATE TABLE student_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_current BOOLEAN DEFAULT FALSE,
    description TEXT NULL,
    project_url VARCHAR(255) NULL,
    github_url VARCHAR(255) NULL,
    technologies_used TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_student_projects (student_id)
);

-- Create table for skills endorsements
CREATE TABLE student_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    proficiency ENUM('Beginner', 'Intermediate', 'Advanced', 'Expert') NULL,
    is_top_skill BOOLEAN DEFAULT FALSE,
    endorsement_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_skill (student_id, skill_name),
    INDEX idx_student_skills (student_id)
);

-- Create table for certificate listings
CREATE TABLE student_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    name VARCHAR(255) NOT NULL COMMENT 'Name of the certification',
    issuing_organization VARCHAR(255) NOT NULL,
    issue_date DATE NOT NULL,
    expiry_date DATE NULL,
    credential_id VARCHAR(100) NULL,
    credential_url VARCHAR(255) NULL,
    category ENUM('internship', 'course', 'achievement') NOT NULL,
    description TEXT NULL,
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_student_certificates (student_id, category)
);