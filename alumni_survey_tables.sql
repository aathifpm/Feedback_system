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
CREATE INDEX idx_alumni_passing_year ON alumni_survey(passing_year);
CREATE INDEX idx_alumni_present_status ON alumni_survey(present_status);
CREATE INDEX idx_alumni_submission_date ON alumni_survey(submission_date);
CREATE INDEX idx_po_assessment_rating ON alumni_po_assessment(rating);
CREATE INDEX idx_peo_assessment_rating ON alumni_peo_assessment(rating);
CREATE INDEX idx_pso_assessment_rating ON alumni_pso_assessment(rating);
CREATE INDEX idx_general_assessment_rating ON alumni_general_assessment(rating);