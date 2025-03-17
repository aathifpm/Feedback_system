<?php
session_start();
require_once 'db_connection.php';

// Function to sanitize input data
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Start transaction
        $conn->begin_transaction();

        // Prepare all the fields
        $fields = [
            'name' => sanitize_input($_POST['name']),
            'gender' => sanitize_input($_POST['gender']),
            'passing_year' => sanitize_input($_POST['passing_year']),
            'degree' => sanitize_input($_POST['degree']),
            'address' => sanitize_input($_POST['address']),
            'mobile' => sanitize_input($_POST['mobile']),
            'phone' => isset($_POST['phone']) ? sanitize_input($_POST['phone']) : null,
            'email' => sanitize_input($_POST['email']),
            'competitive_exam' => isset($_POST['competitive_exam']) ? sanitize_input($_POST['competitive_exam']) : 'no',
            'exams' => isset($_POST['exams']) ? implode(', ', $_POST['exams']) : null,
            'present_status' => sanitize_input($_POST['present_status']),
            
            // Employment Details (if employed)
            'designation' => isset($_POST['designation']) ? sanitize_input($_POST['designation']) : null,
            'company_name' => isset($_POST['company_name']) ? sanitize_input($_POST['company_name']) : null,
            'company_address' => isset($_POST['company_address']) ? sanitize_input($_POST['company_address']) : null,
            'office_phone' => isset($_POST['office_phone']) ? sanitize_input($_POST['office_phone']) : null,
            'official_email' => isset($_POST['official_email']) ? sanitize_input($_POST['official_email']) : null,
            'job_responsibilities' => isset($_POST['job_responsibilities']) ? sanitize_input($_POST['job_responsibilities']) : null,
            'promotion_level' => isset($_POST['promotion_level']) ? sanitize_input($_POST['promotion_level']) : null,
            
            // Higher Studies Details
            'course1_name' => isset($_POST['course1']) ? sanitize_input($_POST['course1']) : null,
            'course1_institution' => isset($_POST['institution1']) ? sanitize_input($_POST['institution1']) : null,
            'course1_passing_year' => isset($_POST['passing_year1']) ? sanitize_input($_POST['passing_year1']) : null,
            'course2_name' => isset($_POST['course2']) ? sanitize_input($_POST['course2']) : null,
            'course2_institution' => isset($_POST['institution2']) ? sanitize_input($_POST['institution2']) : null,
            'course2_passing_year' => isset($_POST['passing_year2']) ? sanitize_input($_POST['passing_year2']) : null,
            
            // Business Details
            'business_name' => isset($_POST['own_company_name']) ? sanitize_input($_POST['own_company_name']) : null,
            'business_nature' => isset($_POST['business_nature']) ? sanitize_input($_POST['business_nature']) : null,
            'business_address' => isset($_POST['business_address']) ? sanitize_input($_POST['business_address']) : null,
            'business_phone' => isset($_POST['business_phone']) ? sanitize_input($_POST['business_phone']) : null,
            'business_contact' => isset($_POST['business_contact']) ? sanitize_input($_POST['business_contact']) : null,
            
            // General Feedback
            'useful_training' => isset($_POST['useful_training']) ? sanitize_input($_POST['useful_training']) : null,
            'suggested_courses' => isset($_POST['suggested_courses']) ? sanitize_input($_POST['suggested_courses']) : null,
            'industry_suggestions' => isset($_POST['industry_suggestions']) ? sanitize_input($_POST['industry_suggestions']) : null,
            'remarks' => isset($_POST['remarks']) ? sanitize_input($_POST['remarks']) : null
        ];

        // Remove null values
        $fields = array_filter($fields, function($value) {
            return $value !== null;
        });
        
        // Create the SQL query dynamically
        $columns = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        
        $sql = "INSERT INTO alumni_survey ($columns, submission_date) VALUES ($placeholders, NOW())";
        
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            throw new Exception("Error preparing statement: " . $conn->error);
        }
        
        // Bind all parameters
        $types = str_repeat('s', count($fields));
        $stmt->bind_param($types, ...array_values($fields));
        
        if (!$stmt->execute()) {
            throw new Exception("Error executing statement: " . $stmt->error);
        }
        
        $alumni_id = $stmt->insert_id;
        
        // Fetch statements from database
        $statements = [];
        
        // Get PO statements
        $stmt = $conn->prepare("SELECT po_number, statement FROM alumni_po_assessment WHERE alumni_id = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $statements['po_' . $row['po_number']] = $row['statement'];
        }
        
        // Get PEO statements
        $stmt = $conn->prepare("SELECT peo_number, statement FROM alumni_peo_assessment WHERE alumni_id = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $statements['peo_' . $row['peo_number']] = $row['statement'];
        }
        
        // Get PSO statements
        $stmt = $conn->prepare("SELECT pso_number, statement FROM alumni_pso_assessment WHERE alumni_id = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $statements['pso_' . $row['pso_number']] = $row['statement'];
        }
        
        // Get General Assessment statements
        $stmt = $conn->prepare("SELECT question_number, statement FROM alumni_general_assessment WHERE alumni_id = 1");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $statements['general_' . $row['question_number']] = $row['statement'];
        }

        // Handle PO Assessment
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'po_') === 0) {
                $po_number = substr($key, 3);
                $rating = sanitize_input($value);
                $statement = $statements[$key] ?? '';
                
                $sql = "INSERT INTO alumni_po_assessment (alumni_id, po_number, statement, rating) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Error preparing PO statement: " . $conn->error);
                }
                $stmt->bind_param("iisi", $alumni_id, $po_number, $statement, $rating);
                if (!$stmt->execute()) {
                    throw new Exception("Error executing PO statement: " . $stmt->error);
                }
            }
        }

        // Handle PEO Assessment
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'peo_') === 0) {
                $peo_number = substr($key, 4);
                $rating = sanitize_input($value);
                $statement = $statements[$key] ?? '';
                
                $sql = "INSERT INTO alumni_peo_assessment (alumni_id, peo_number, statement, rating) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Error preparing PEO statement: " . $conn->error);
                }
                $stmt->bind_param("iisi", $alumni_id, $peo_number, $statement, $rating);
                if (!$stmt->execute()) {
                    throw new Exception("Error executing PEO statement: " . $stmt->error);
                }
            }
        }

        // Handle PSO Assessment
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'pso_') === 0) {
                $pso_number = substr($key, 4);
                $rating = sanitize_input($value);
                $statement = $statements[$key] ?? '';
                
                $sql = "INSERT INTO alumni_pso_assessment (alumni_id, pso_number, statement, rating) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Error preparing PSO statement: " . $conn->error);
                }
                $stmt->bind_param("iisi", $alumni_id, $pso_number, $statement, $rating);
                if (!$stmt->execute()) {
                    throw new Exception("Error executing PSO statement: " . $stmt->error);
                }
            }
        }

        // Handle General Assessment
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'general_') === 0) {
                $question_number = substr($key, 8);
                $rating = sanitize_input($value);
                $statement = $statements[$key] ?? '';
                
                $sql = "INSERT INTO alumni_general_assessment (alumni_id, question_number, statement, rating) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new Exception("Error preparing General statement: " . $conn->error);
                }
                $stmt->bind_param("iisi", $alumni_id, $question_number, $statement, $rating);
                if (!$stmt->execute()) {
                    throw new Exception("Error executing General statement: " . $stmt->error);
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Success message
        $_SESSION['success_message'] = "Thank you for submitting the alumni survey!";
        header("Location: index.php");
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        $_SESSION['error_message'] = "An error occurred while submitting the survey: " . $e->getMessage();
        error_log("Alumni Survey Error: " . $e->getMessage());
        header("Location: alumni_survey.php");
        exit();
    }
} else {
    header("Location: alumni_survey.php");
    exit();
}
?> 