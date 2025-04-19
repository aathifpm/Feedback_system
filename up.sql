ALTER TABLE admin_users 
ADD COLUMN department_id INT NULL,
ADD FOREIGN KEY (department_id) REFERENCES departments(id);

-- First, drop the existing unique constraint on code column
ALTER TABLE subjects DROP INDEX code;

-- Then add a new composite unique constraint on code and department_id
ALTER TABLE subjects ADD CONSTRAINT unique_subject_per_department UNIQUE (code, department_id);