<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $student_id = $_POST['student_id'] ?? $user_id;
    $semester = $_POST['semester'] ?? 0;
    $feedback_data = $_POST['feedback'] ?? [];
    
    // Validate student ID
    if ($student_id != $user_id) {
        $_SESSION['error'] = "Invalid student ID.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Get current academic year
    $academic_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
    $academic_year_stmt = $pdo->query($academic_year_query);
    $academic_year = $academic_year_stmt->fetch(PDO::FETCH_ASSOC);
    $academic_year_stmt = null; // Close the statement
    
    if (!$academic_year) {
        $_SESSION['error'] = "No active academic year found.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    
    // Check if feedback already exists
    $check_query = "SELECT id FROM non_academic_feedback 
                    WHERE student_id = ? 
                    AND academic_year_id = ? 
                    AND semester = ?";
    $stmt = $pdo->prepare($check_query);
    $stmt->execute([$student_id, $academic_year['id'], $semester]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmt = null; // Close the statement
        $_SESSION['error'] = "Feedback already submitted for this semester.";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
    $stmt = null; // Close the statement
    
    // Get required fields
    $required_fields_query = "SELECT id FROM non_academic_feedback_statements WHERE is_required = TRUE AND is_active = TRUE";
    $required_fields_stmt = $pdo->query($required_fields_query);
    $required_field_ids = [];
    while ($field = $required_fields_stmt->fetch(PDO::FETCH_ASSOC)) {
        $required_field_ids[] = $field['id'];
    }
    $required_fields_stmt = null; // Close the statement
    
    // Validate required fields
    foreach ($required_field_ids as $field_id) {
        if (!isset($feedback_data[$field_id]) || trim($feedback_data[$field_id]) === '') {
            $_SESSION['error'] = "Please fill in all required fields.";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
    
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Insert feedback
        $insert_query = "INSERT INTO non_academic_feedback (student_id, academic_year_id, semester, feedback) 
                        VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($insert_query);
        $feedback_json = json_encode($feedback_data);
        
        if (!$stmt->execute([$student_id, $academic_year['id'], $semester, $feedback_json])) {
            throw new Exception("Error saving feedback: " . implode(", ", $stmt->errorInfo()));
        }
        
        $feedback_id = $pdo->lastInsertId();
        $stmt = null; // Close the statement
        
        // Log the submission
        $log_query = "INSERT INTO user_logs (user_id, role, action, details) 
                     VALUES (?, 'student', 'Submitted non-academic feedback', ?)";
        $stmt = $pdo->prepare($log_query);
        $log_details = json_encode(['feedback_id' => $feedback_id, 'semester' => $semester]);
        
        if (!$stmt->execute([$student_id, $log_details])) {
            throw new Exception("Error logging feedback: " . implode(", ", $stmt->errorInfo()));
        }
        $stmt = null; // Close the statement
        
        $pdo->commit();
        $_SESSION['success'] = "Non-academic feedback submitted successfully.";
        header('Location: class_committee_meetings.php');
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error submitting feedback. Please try again.";
        error_log("Error in non-academic feedback submission: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Get student details including current semester
$student_query = "SELECT s.*, 
                    d.name as department_name,
                    d.code as department_code,
                    `by`.batch_name,
                    `by`.current_year_of_study,
                    CASE 
                        WHEN MONTH(CURDATE()) <= 5 THEN `by`.current_year_of_study * 2
                        ELSE `by`.current_year_of_study * 2 - 1
                    END as current_semester,
                    s.section
                FROM students s
                JOIN departments d ON s.department_id = d.id
                JOIN batch_years `by` ON s.batch_id = `by`.id
                WHERE s.id = ?";

$stmt = $pdo->prepare($student_query);
$stmt->execute([$user_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null; // Close the statement

if (!$student) {
    die("Error: Student not found");
}

// Get non-academic feedback statements
$statements_query = "SELECT * FROM non_academic_feedback_statements WHERE is_active = TRUE ORDER BY statement_number";
$statements = $pdo->query($statements_query);
if (!$statements) {
    die("Error fetching feedback statements: " . implode(", ", $pdo->errorInfo()));
}

// Check if feedback already submitted for current semester
$feedback_check_query = "SELECT id FROM non_academic_feedback 
                        WHERE student_id = ? 
                        AND academic_year_id = (SELECT id FROM academic_years WHERE is_current = TRUE)
                        AND semester = ?";
$stmt = $pdo->prepare($feedback_check_query);
$stmt->execute([$user_id, $student['current_semester']]);
$feedback_exists = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null; // Close the statement

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Non-Academic Feedback</title>
    <?php include 'header.php'; ?>
    <style>
        .feedback-form {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .form-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .feedback-section {
            margin-bottom: 2rem;
        }

        .feedback-item {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .feedback-question {
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .feedback-input textarea {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            resize: vertical;
            min-height: 100px;
            font-family: inherit;
        }

        .feedback-input textarea:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-actions {
            text-align: center;
            margin-top: 2rem;
        }

        .btn-submit {
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .feedback-status {
            text-align: center;
            padding: 2rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin: 2rem auto;
            max-width: 600px;
        }

        .status-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .required-marker {
            color: #dc3545;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="feedback-form">
            <div class="form-header">
                <h2>Non-Academic Feedback Form</h2>
                <p>Semester: <?php echo $student['current_semester']; ?> | Section: <?php echo $student['section']; ?></p>
            </div>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <?php 
                        echo $_SESSION['success']; 
                        unset($_SESSION['success']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error']; 
                        unset($_SESSION['error']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if ($feedback_exists): ?>
                <div class="feedback-status">
                    <i class="fas fa-check-circle status-icon"></i>
                    <p>You have already submitted non-academic feedback for this semester.</p>
                    <a href="class_committee_meetings.php" class="btn-submit" style="display: inline-block; text-decoration: none; margin-top: 1rem;">
                        <i class="fas fa-arrow-left"></i> Back to Class Committee
                    </a>
                </div>
            <?php else: ?>
                <form action="give_non_academic_feedback.php" method="POST" onsubmit="return validateForm()">
                    <input type="hidden" name="student_id" value="<?php echo $user_id; ?>">
                    <input type="hidden" name="semester" value="<?php echo $student['current_semester']; ?>">

                    <div class="feedback-section">
                        <?php 
                        // Get all statements for display
                        $statements_data = $statements->fetchAll(PDO::FETCH_ASSOC);
                        $required_fields = [];
                        
                        foreach ($statements_data as $statement): 
                            $field_name = "feedback[{$statement['id']}]";
                            if ($statement['is_required']) {
                                $required_fields[] = $field_name;
                            }
                        ?>
                            <div class="feedback-item">
                                <div class="feedback-question">
                                    <?php echo htmlspecialchars($statement['statement']); ?>
                                    <?php if ($statement['is_required']): ?>
                                        <span class="required-marker">*</span>
                                    <?php endif; ?>
                                </div>
                                <div class="feedback-input">
                                    <textarea 
                                        name="<?php echo $field_name; ?>"
                                        <?php echo $statement['is_required'] ? 'required' : ''; ?>
                                    ></textarea>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-paper-plane"></i> Submit Feedback
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function validateForm() {
        const requiredFields = <?php echo json_encode($required_fields); ?>;

        let isValid = true;
        requiredFields.forEach(field => {
            const element = document.querySelector(`[name="${field}"]`);
            if (!element.value.trim()) {
                isValid = false;
                alert('Please fill in all required fields.');
                return false;
            }
        });

        return isValid;
    }
    </script>

    <?php include 'footer.php'; ?>
</body>
</html> 