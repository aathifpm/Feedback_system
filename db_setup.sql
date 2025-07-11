-- Set character set and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Create and use database with proper character set
CREATE DATABASE IF NOT EXISTS college_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE college_feedback;

-- Set database character set and collation
ALTER DATABASE college_feedback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create admin users table
CREATE TABLE admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create departments table
CREATE TABLE departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create academic_years table with May-May cycle
CREATE TABLE academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_range VARCHAR(10) NOT NULL UNIQUE,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_current BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_academic_dates CHECK (
        end_date > start_date
    )
);


-- Create batch_years table for 4-year engineering batches
CREATE TABLE batch_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(10) NOT NULL UNIQUE,  -- Example: "2022-26"
    admission_year INT NOT NULL,             -- Year of admission (2022)
    graduation_year INT NOT NULL,            -- Year of graduation (2026)
    current_year_of_study INT,              -- Calculated field (1 to 4)
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_batch_years CHECK (graduation_year = admission_year + 4)
);

-- Create feedback_periods table
CREATE TABLE feedback_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT chk_date_range CHECK (end_date >= start_date)
);

-- Create hods table
CREATE TABLE hods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Create faculty table
CREATE TABLE faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id VARCHAR(20) NOT NULL UNIQUE,  -- Added this line
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department_id INT NOT NULL,
    designation VARCHAR(50),
    experience INT,
    qualification VARCHAR(100),
    specialization VARCHAR(200),
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Add index for faculty_id
CREATE INDEX idx_faculty_id ON faculty(faculty_id);
-- Create students table
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    roll_number VARCHAR(20) NOT NULL UNIQUE,
    register_number VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    department_id INT NOT NULL,
    batch_id INT NOT NULL,
    section VARCHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
    address TEXT,
    phone VARCHAR(15),
    profile_image_path VARCHAR(255) DEFAULT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    password_changed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (batch_id) REFERENCES batch_years(id)
);
-- Create password reset tokens table
CREATE TABLE password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_type ENUM('admin', 'faculty', 'hod', 'student') NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    is_used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_expiry (expires_at)
);

-- Create subjects table (Updated)
CREATE TABLE subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    department_id INT NOT NULL,
    credits INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT chk_credits CHECK (credits > 0)
);

-- Create subject_assignments table (New)
CREATE TABLE subject_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    faculty_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    year INT NOT NULL,
    semester INT NOT NULL,
    section VARCHAR(1) COLLATE utf8mb4_unicode_ci NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    FOREIGN KEY (faculty_id) REFERENCES faculty(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    CONSTRAINT chk_subject_year CHECK (year BETWEEN 1 AND 4),
    CONSTRAINT chk_subject_semester CHECK (semester BETWEEN 1 AND 8),
    UNIQUE KEY unique_subject_assignment (subject_id, academic_year_id, year, semester, section)
);
-- Create course outcomes table
CREATE TABLE course_outcomes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subject_id INT NOT NULL,
    outcome_number INT NOT NULL,
    description TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id),
    UNIQUE KEY unique_course_outcome (subject_id, outcome_number)
);

-- Create feedback_statements table
CREATE TABLE feedback_statements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    statement TEXT NOT NULL,
    section VARCHAR(50) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_section (section)
);

-- Create feedback table
CREATE TABLE feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    assignment_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    comments TEXT,
    course_effectiveness_avg DECIMAL(3,2),
    teaching_effectiveness_avg DECIMAL(3,2),
    resources_admin_avg DECIMAL(3,2),
    assessment_learning_avg DECIMAL(3,2),
    course_outcomes_avg DECIMAL(3,2),
    cumulative_avg DECIMAL(3,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (assignment_id) REFERENCES subject_assignments(id),
    UNIQUE KEY unique_feedback (student_id, assignment_id)
);
-- Create feedback_ratings table
CREATE TABLE feedback_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feedback_id INT NOT NULL,
    statement_id INT NOT NULL,
    rating INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (feedback_id) REFERENCES feedback(id),
    FOREIGN KEY (statement_id) REFERENCES feedback_statements(id),
    CONSTRAINT chk_rating CHECK (rating BETWEEN 1 AND 5)
);
-- Create exit_surveys table
CREATE TABLE exit_surveys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    department_id INT NOT NULL,
    roll_number VARCHAR(20) NOT NULL,
    register_number VARCHAR(20) NOT NULL,
    passing_year INT NOT NULL,
    contact_address TEXT NOT NULL,
    email VARCHAR(100) NOT NULL,
    contact_number VARCHAR(20) NOT NULL,
    po_ratings JSON NOT NULL,
    pso_ratings JSON NOT NULL,
    employment_status JSON NOT NULL,
    program_satisfaction JSON NOT NULL,
    infrastructure_satisfaction JSON NOT NULL,
    date DATE NOT NULL,
    station VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id),
    UNIQUE KEY unique_exit_survey (student_id, academic_year_id)
);

-- Create user_logs table
CREATE TABLE user_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role VARCHAR(20) NOT NULL,
    action VARCHAR(255) NOT NULL,
    details JSON,
    status ENUM('success', 'failure') NOT NULL DEFAULT 'success',
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id, role),
    INDEX idx_created (created_at)
);

-- Create notifications table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role VARCHAR(20) NOT NULL,
    message TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_role (user_id, role),
    INDEX idx_unread (is_read)
);
-- Alumni Survey Table (Main table with all details)
CREATE TABLE IF NOT EXISTS alumni_survey (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    gender ENUM('male', 'female') NOT NULL,
    passing_year VARCHAR(4) NOT NULL,
    degree VARCHAR(100) NOT NULL,
    address TEXT NOT NULL,
    mobile VARCHAR(15) NOT NULL,
    phone VARCHAR(15),
    email VARCHAR(100) NOT NULL,
    competitive_exam ENUM('yes', 'no') DEFAULT 'no',
    exams TEXT,
    present_status ENUM('employed', 'higher_studies', 'entrepreneur', 'not_employed') NOT NULL,
    
    -- Employment Details
    designation VARCHAR(100),
    company_name VARCHAR(100),
    company_address TEXT,
    office_phone VARCHAR(15),
    official_email VARCHAR(100),
    job_responsibilities TEXT,
    promotion_level VARCHAR(50),
    
    -- Higher Studies Details
    course1_name VARCHAR(100),
    course1_institution VARCHAR(100),
    course1_passing_year VARCHAR(4),
    course2_name VARCHAR(100),
    course2_institution VARCHAR(100),
    course2_passing_year VARCHAR(4),
    
    -- Business/Self-Employment Details
    business_name VARCHAR(100),
    business_nature VARCHAR(100),
    business_address TEXT,
    business_phone VARCHAR(15),
    business_contact VARCHAR(255),
    
    -- General Feedback
    useful_training TEXT,
    suggested_courses TEXT,
    industry_suggestions TEXT,
    remarks TEXT,
    
    submission_date DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- PO Assessment Table
CREATE TABLE IF NOT EXISTS alumni_po_assessment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alumni_id INT NOT NULL,
    po_number INT NOT NULL,
    statement TEXT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alumni_id) REFERENCES alumni_survey(id) ON DELETE CASCADE
);

-- PEO Assessment Table
CREATE TABLE IF NOT EXISTS alumni_peo_assessment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alumni_id INT NOT NULL,
    peo_number INT NOT NULL,
    statement TEXT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alumni_id) REFERENCES alumni_survey(id) ON DELETE CASCADE
);

-- PSO Assessment Table
CREATE TABLE IF NOT EXISTS alumni_pso_assessment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alumni_id INT NOT NULL,
    pso_number INT NOT NULL,
    statement TEXT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alumni_id) REFERENCES alumni_survey(id) ON DELETE CASCADE
);

-- General Assessment Table
CREATE TABLE IF NOT EXISTS alumni_general_assessment (
    id INT PRIMARY KEY AUTO_INCREMENT,
    alumni_id INT NOT NULL,
    question_number INT NOT NULL,
    statement TEXT NOT NULL,
    rating INT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (alumni_id) REFERENCES alumni_survey(id) ON DELETE CASCADE
);


-- Insert sample alumni record first
INSERT INTO alumni_survey (
    name, gender, passing_year, degree, address, mobile, email, 
    present_status, submission_date
) VALUES (
    'John Doe', 'male', '2023', 'B.Tech in Computer Science', 
    '123 Main St, City', '1234567890', 'john.doe@example.com',
    'employed', NOW()
);


-- Create indexes for better performance
CREATE INDEX idx_alumni_passing_year ON alumni_survey(passing_year);
CREATE INDEX idx_alumni_present_status ON alumni_survey(present_status);
CREATE INDEX idx_alumni_submission_date ON alumni_survey(submission_date);
CREATE INDEX idx_po_assessment_rating ON alumni_po_assessment(rating);
CREATE INDEX idx_peo_assessment_rating ON alumni_peo_assessment(rating);
CREATE INDEX idx_pso_assessment_rating ON alumni_pso_assessment(rating);
CREATE INDEX idx_general_assessment_rating ON alumni_general_assessment(rating);

CREATE INDEX idx_subject_department ON subjects(department_id);
CREATE INDEX idx_student_department ON students(department_id);
CREATE INDEX idx_faculty_department ON faculty(department_id);
CREATE INDEX idx_exit_survey_academic_year ON exit_surveys(academic_year_id);
CREATE INDEX idx_exit_survey_department ON exit_surveys(department_id);
CREATE INDEX idx_feedback_created ON feedback(created_at);


CREATE INDEX idx_students_batch ON students(batch_id);
CREATE INDEX idx_faculty_active ON faculty(is_active);
CREATE INDEX idx_hods_active ON hods(is_active);


-- Add indexes for better performance
CREATE INDEX idx_subject_assignments_faculty ON subject_assignments(faculty_id);
CREATE INDEX idx_subject_assignments_academic_year ON subject_assignments(academic_year_id);
CREATE INDEX idx_subject_assignments_active ON subject_assignments(is_active);

-- Create views for data analysis

CREATE OR REPLACE VIEW feedback_summary AS
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
    COUNT(DISTINCT fb.id) AS total_feedback,
    AVG(fb.cumulative_avg) AS average_rating
FROM subject_assignments sa
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
JOIN departments d ON s.department_id = d.id
JOIN academic_years ay ON sa.academic_year_id = ay.id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
GROUP BY sa.id, s.code, s.name, f.name, d.name, ay.year_range, sa.year, sa.semester, sa.section;

-- Stored Procedures (modified for Cloud SQL compatibility)
DELIMITER //

CREATE PROCEDURE GetFacultyFeedbackSummary(
    IN faculty_id INT,
    IN academic_year_id INT
)
BEGIN
    SELECT 
        s.name AS subject_name,
        sa.semester,
        sa.section,
        COUNT(DISTINCT f.id) AS feedback_count,
        AVG(fb.cumulative_avg) AS average_rating
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    LEFT JOIN feedback f ON sa.id = f.assignment_id
    WHERE sa.faculty_id = faculty_id
    AND sa.academic_year_id = academic_year_id
    GROUP BY s.name, sa.semester, sa.section
    ORDER BY sa.semester, sa.section;
END //

CREATE PROCEDURE CalculateFeedbackAverages(
    IN feedback_id INT
)
BEGIN
    DECLARE ce_avg, te_avg, ra_avg, al_avg, co_avg, cum_avg DECIMAL(3,2);
    
    SELECT AVG(rating) INTO ce_avg
    FROM feedback_ratings 
    WHERE feedback_id = feedback_id 
    AND section = 'COURSE_EFFECTIVENESS';
    
    SELECT AVG(rating) INTO te_avg
    FROM feedback_ratings 
    WHERE feedback_id = feedback_id 
    AND section = 'TEACHING_EFFECTIVENESS';
    
    SELECT AVG(rating) INTO ra_avg
    FROM feedback_ratings 
    WHERE feedback_id = feedback_id 
    AND section = 'RESOURCES_ADMIN';
    
    SELECT AVG(rating) INTO al_avg
    FROM feedback_ratings 
    WHERE feedback_id = feedback_id 
    AND section = 'ASSESSMENT_LEARNING';
    
    SELECT AVG(rating) INTO co_avg
    FROM feedback_ratings 
    WHERE feedback_id = feedback_id 
    AND section = 'COURSE_OUTCOMES';
    
    SET cum_avg = (COALESCE(ce_avg, 0) + COALESCE(te_avg, 0) + COALESCE(ra_avg, 0) + 
                  COALESCE(al_avg, 0) + COALESCE(co_avg, 0)) / 5;
    
    UPDATE feedback 
    SET course_effectiveness_avg = ce_avg,
        teaching_effectiveness_avg = te_avg,
        resources_admin_avg = ra_avg,
        assessment_learning_avg = al_avg,
        course_outcomes_avg = co_avg,
        cumulative_avg = cum_avg
    WHERE id = feedback_id;
END //

DELIMITER ;

-- Create trigger
DELIMITER //

CREATE TRIGGER after_feedback_insert
AFTER INSERT ON feedback
FOR EACH ROW
BEGIN
    INSERT INTO user_logs (user_id, role, action, details)
    VALUES (NEW.student_id, 'student', 
            'Submitted feedback', 
            JSON_OBJECT(
                'assignment_id', NEW.assignment_id
            ));
            
    INSERT INTO notifications (user_id, role, message)
    SELECT 
        sa.faculty_id,
        'faculty',
        CONCAT('New feedback received for ', s.name)
    FROM subject_assignments sa
    JOIN subjects s ON sa.subject_id = s.id
    WHERE sa.id = NEW.assignment_id;
END //

DELIMITER ;

-- Insert default data
INSERT INTO admin_users (username, email, password, is_active) VALUES
('admin', 'admin@college.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', TRUE);

INSERT INTO departments (name, code) VALUES
('Computer Science and Engineering', 'CSE'),
('Electronics and Communication Engineering', 'ECE'),
('Mechanical Engineering', 'MECH'),
('Information Technology', 'IT');

INSERT INTO academic_years (year_range, start_date, end_date, is_current) VALUES
('2023-24', '2023-05-01', '2024-05-31', FALSE),
('2024-25', '2024-05-01', '2025-05-31', TRUE);

INSERT INTO batch_years (batch_name, admission_year, graduation_year, current_year_of_study) VALUES
('2021-25', 2021, 2025, 4),
('2022-26', 2022, 2026, 3),
('2023-27', 2023, 2027, 2),
('2024-28', 2024, 2028, 1);

-- Insert PO Statements
INSERT INTO alumni_po_assessment (alumni_id, po_number, statement, rating) VALUES
(1, 1, 'Apply the knowledge of mathematics, science, engineering fundamentals, and an engineering specialization to the solution of complex engineering problems.', 5),
(1, 2, 'Identify, formulate, research literature, and analyze complex engineering problems reaching substantiated conclusions using first principles of mathematics, natural sciences, and engineering sciences', 5),
(1, 3, 'Design solutions for complex engineering problems and design system components or processes that meet the specified needs with appropriate consideration for the public health and safety and the cultural, societal, and environmental considerations.', 5),
(1, 4, 'Use research-based knowledge and research methods including design of experiments, analysis and interpretation of data, and synthesis of the information.', 5),
(1, 5, 'Create, select, and apply appropriate techniques, resources, and modern engineering and IT tools including prediction and modeling to complex engineering activities with an understanding of the limitations.', 5),
(1, 6, 'Apply reasoning informed by the contextual knowledge to assess societal, health, safety, legal and cultural issues and the consequent responsibilities relevant to the professional engineering practice.', 5),
(1, 7, 'Understand the impact of the professional engineering solutions in societal and environmental contexts, and demonstrate the knowledge of need for sustainable development.', 5),
(1, 8, 'Apply ethical principles and commit to professional ethics and responsibilities and norms of the engineering practice.', 5),
(1, 9, 'Function effectively as an individual, and as a member or leader in diverse teams, and in multidisciplinary settings.', 5),
(1, 10, 'Communicate effectively on complex engineering activities with the engineering community and with society at large. Some of them are, being able to comprehend and write effective reports and design documentation, make effective presentations and give and receive clear instructions.', 5),
(1, 11, "Demonstrate knowledge and understanding of the engineering and management principles and apply these to one's own work, as a member and leader in a team, to manage projects and in multidisciplinary environments.", 5),
(1, 12, 'Recognize the need for, and have the preparation and ability to engage in independent and lifelong learning in the broadest context of technological change.', 5);

-- Insert PEO Statements
INSERT INTO alumni_peo_assessment (alumni_id, peo_number, statement, rating) VALUES
(1, 1, 'To provide graduates with the proficiency to utilize the fundamental knowledge of Basic Sciences, mathematics and statistics to build systems that require management and analysis of large volume of data.', 5),
(1, 2, 'To inculcate the students to focus on augmenting the knowledge to improve the performance for the AI era and also to serve the analytical and data-centric needs of a modern workforce', 5),
(1, 3, 'To enable graduates to illustrate the core AI and Data Science technologies, applying them in ways that optimize human-machine partnerships and providing the tools and skills to understand their societal impact for product development.', 5),
(1, 4, 'To enrich the students with necessary technical skills to foster interdisciplinary research and development to move the community in an interesting direction in the field of AI and Data Science.', 5),
(1, 5, 'To enable graduates to think logically, pursue lifelong learning and collaborate with an ethical attitude to become an entrepreneur', 5);

-- Insert PSO Statements
INSERT INTO alumni_pso_assessment (alumni_id, pso_number, statement, rating) VALUES
(1, 1, 'Graduates should be able to evolve AI based efficient domain specific processes for effective decision making in several domains such as business and governance domains.', 5),
(1, 2, 'Graduates should be able to arrive at actionable Fore sight, Insight, hind sight from data for solving business and engineering problems', 5),
(1, 3, 'Graduates should be able to create, select and apply the theoretical knowledge of AI and Data Analytics along with practical industrial tools and techniques to manage and solve wicked societal problems', 5);

-- Insert General Assessment Questions
INSERT INTO alumni_general_assessment (alumni_id, question_number, statement, rating) VALUES
(1, 1, 'Quality of instruction in your major field', 5),
(1, 2, 'Quality of academic experiences outside the classroom', 5),
(1, 3, 'Interaction with faculty outside the classroom', 5),
(1, 4, 'Assistance by faculty in pursuing your career', 5),
(1, 5, 'Assistance in finding employment', 5),
(1, 6, 'Quality of extracurricular experiences', 5);

-- Indexes for better query performance

-- Insert feedback statements for all sections
INSERT INTO feedback_statements (statement, section, is_active) VALUES
-- Course Effectiveness statements
('Syllabus structure was clearly outlined at the beginning of the course.', 'COURSE_EFFECTIVENESS', TRUE),
('Planned course structure was aligned with the syllabus.', 'COURSE_EFFECTIVENESS', TRUE),
('Presentations were well-structured.', 'COURSE_EFFECTIVENESS', TRUE),
('Concepts were illustrated using relevant examples and illustrations.', 'COURSE_EFFECTIVENESS', TRUE),
('Core concepts and essential ideas related to the course were explained effectively.', 'COURSE_EFFECTIVENESS', TRUE),
('Active participation and inquiry were encouraged by the instructor.', 'COURSE_EFFECTIVENESS', TRUE),
('New methods and techniques were demonstrated throughout the course.', 'COURSE_EFFECTIVENESS', TRUE),
('Knowledge from assignments and lectures was applied to the design and development of projects.', 'COURSE_EFFECTIVENESS', TRUE),
('Relevance of the course content to solving real-world problems was recognized.', 'COURSE_EFFECTIVENESS', TRUE),
('Course helps to prepare for competitive exams.', 'COURSE_EFFECTIVENESS', TRUE),
('The Objectives of the course align well with its intended outcomes.', 'COURSE_EFFECTIVENESS', TRUE),
('Course outcomes contribute to achieving the Program Outcomes (PO) and Program Specific Outcomes (PSO).', 'COURSE_EFFECTIVENESS', TRUE),
('Interest in the subject was developed through the course content and activities.', 'COURSE_EFFECTIVENESS', TRUE),

-- Teaching Effectiveness statements
('Interest was stimulated by the course instructor''s deliverance.', 'TEACHING_EFFECTIVENESS', TRUE),
('Class time and pace were managed well by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),
('Expectations of students were satisfied by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),
('Effective Preparation for the course was demonstrated by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),
('Discussions were encouraged, and questions were responded to by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),
('In-depth knowledge of the course, along with enthusiasm and interest, was demonstrated by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),
('Accessibility outside the classroom was ensured by the course instructor.', 'TEACHING_EFFECTIVENESS', TRUE),

-- Resources and Administration statements
('Adequate library resources were provided to support the course.', 'RESOURCES_ADMIN', TRUE),
('Guidance on where to find resources was given by the course instructor.', 'RESOURCES_ADMIN', TRUE),
('Course materials and lecture notes were found to be effective.', 'RESOURCES_ADMIN', TRUE),
('Usefulness of Smart Classroom, Google Classroom, Interactive Simulations, and Multimedia Presentations.', 'RESOURCES_ADMIN', TRUE),
('Availability of online and offline learning materials was ensured.', 'RESOURCES_ADMIN', TRUE),

-- Assessment and Learning statements
('Communication of rubrics and grading criteria before assessments was ensured.', 'ASSESSMENT_LEARNING', TRUE),
('Bloom''s Taxonomy levels were incorporated in the assessments.', 'ASSESSMENT_LEARNING', TRUE),
('Knowledge acquired in the course was effectively measured through exams (unit test, mid-term, end semester).', 'ASSESSMENT_LEARNING', TRUE),
('Real-world applications or case-based questions were included in assessments.', 'ASSESSMENT_LEARNING', TRUE),

-- Course Outcomes statements
('To what extent has this course improved your ability to apply engineering concepts to real-world problems?', 'COURSE_OUTCOMES', TRUE),
('How well did this course enhance your problem-solving and critical-thinking skills?', 'COURSE_OUTCOMES', TRUE),
('Did this course strengthen your ability to use modern engineering tools and technologies?', 'COURSE_OUTCOMES', TRUE),
('How effectively did this course develop your analytical and data interpretation skills?', 'COURSE_OUTCOMES', TRUE),
('To what extent has this course prepared you for teamwork and professional collaboration?', 'COURSE_OUTCOMES', TRUE),
('How well did this course help in understanding the ethical and social responsibilities of an engineer?', 'COURSE_OUTCOMES', TRUE);