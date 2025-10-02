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
    
    // Get ratings and comments separately
    $ratings = $_POST['ratings'] ?? [];
    $comments = $_POST['comments'] ?? [];
    
    // Combine ratings and comments into feedback_data
    $feedback_data = array_merge($ratings, $comments);
    
    // Get required fields with their types
    $required_fields_query = "SELECT id, statement_type FROM non_academic_feedback_statements WHERE is_required = TRUE AND is_active = TRUE";
    $required_fields_stmt = $pdo->query($required_fields_query);
    $required_fields = [];
    while ($field = $required_fields_stmt->fetch(PDO::FETCH_ASSOC)) {
        $required_fields[] = $field;
    }
    $required_fields_stmt = null; // Close the statement
    
    // Validate required fields based on their type
    foreach ($required_fields as $field) {
        $field_id = $field['id'];
        $field_type = $field['statement_type'];
        
        // Check if the field exists in the appropriate array
        if ($field_type === 'rating') {
            if (!isset($ratings[$field_id]) || trim($ratings[$field_id]) === '') {
                $_SESSION['error'] = "Please provide ratings for all required fields.";
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            }
        } else { // comment type
            if (!isset($comments[$field_id]) || trim($comments[$field_id]) === '') {
                $_SESSION['error'] = "Please fill in all required comment fields.";
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit();
            }
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

// Separate rating and comment statements
$rating_statements = [];
$comment_statements = [];
$statements_data = $statements->fetchAll(PDO::FETCH_ASSOC);

foreach ($statements_data as $statement) {
    if ($statement['statement_type'] === 'rating') {
        $rating_statements[] = $statement;
    } else {
        $comment_statements[] = $statement;
    }
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
            max-width: 1000px;
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
            text-align: center;
        }

        .form-header h2 {
            color: var(--text-color);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            color: #666;
            font-size: 1rem;
        }

        .feedback-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--primary-color);
        }

        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 15px;
            margin-bottom: 2rem;
        }

        .rating-table th {
            padding: 1rem;
            text-align: left;
            color: var(--text-color);
            font-weight: 600;
            border-bottom: 2px solid var(--primary-color);
        }

        .rating-table tr {
            background: var(--card-bg);
            box-shadow: var(--shadow);
            border-radius: 10px;
        }

        .rating-table td {
            padding: 1rem;
            border: none;
            vertical-align: middle;
        }

        .rating-table td:first-child {
            border-top-left-radius: 10px;
            border-bottom-left-radius: 10px;
            width: 60px;
            text-align: center;
            font-weight: 600;
        }

        .rating-table td:last-child {
            border-top-right-radius: 10px;
            border-bottom-right-radius: 10px;
        }

        .statement-cell {
            width: 60%;
            color: var(--text-color);
        }

        .rating-cell {
            width: 40%;
        }

        .rating-options {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }

        .rating-option {
            text-align: center;
        }

        .rating-option input[type="radio"] {
            display: none;
        }

        .rating-option label {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: var(--text-color);
        }

        .rating-option input[type="radio"]:checked + label {
            background: var(--primary-color);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .rating-option label:hover {
            transform: translateY(-2px);
        }

        .scale-info {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #666;
            font-size: 0.9rem;
            padding: 0.8rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
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

        @media (max-width: 768px) {
            .feedback-form {
                margin: 1rem;
                padding: 1.5rem;
            }
            
            .rating-table td {
                padding: 0.8rem;
            }
            
            .rating-option label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
            
            .rating-options {
                gap: 5px;
            }
            
            .statement-cell {
                width: 50%;
                font-size: 0.9rem;
            }
            
            .section-title {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="feedback-form">
            <div class="form-header">
                <h2>Non-Academic Feedback Form</h2>
                <p>Semester: <?php echo $student['current_semester']; ?> | Section: <?php echo $student['section']; ?></p>
                <div style="margin-top: 1rem; padding: 0.8rem; background: var(--bg-color); border-radius: 10px; box-shadow: var(--inner-shadow); color: #666; font-size: 0.9rem;">
                    <strong>Instructions:</strong> Please rate various aspects of non-academic services and provide your comments and suggestions to help us improve.
                </div>
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

                    <?php if (!empty($rating_statements)): ?>
                    <!-- Rating Section -->
                    <div class="feedback-section">
                        <h3 class="section-title">Rate the Following Aspects</h3>
                        
                        <div class="scale-info">
                            5 - Excellent, 4 - Good, 3 - Average, 2 - Below Average, 1 - Poor
                        </div>
                        
                        <table class="rating-table">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Statement</th>
                                    <th>Rating</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rating_statements as $statement): ?>
                                    <tr>
                                        <td><?php echo $statement['statement_number']; ?></td>
                                        <td class="statement-cell">
                                            <?php echo htmlspecialchars($statement['statement']); ?>
                                            <?php if ($statement['is_required']): ?>
                                                <span class="required-marker">*</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rating-cell">
                                            <div class="rating-options">
                                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                                    <div class="rating-option">
                                                        <input type="radio" 
                                                            id="rating_<?php echo $statement['id']; ?>_<?php echo $i; ?>" 
                                                            name="ratings[<?php echo $statement['id']; ?>]" 
                                                            value="<?php echo $i; ?>" 
                                                            <?php echo $statement['is_required'] ? 'required' : ''; ?>>
                                                        <label for="rating_<?php echo $statement['id']; ?>_<?php echo $i; ?>"><?php echo $i; ?></label>
                                                    </div>
                                                <?php endfor; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($comment_statements)): ?>
                    <!-- Comments Section -->
                    <div class="feedback-section">
                        <h3 class="section-title">Additional Comments and Suggestions</h3>
                        
                        <?php foreach ($comment_statements as $statement): ?>
                            <div class="feedback-item">
                                <div class="feedback-question">
                                    <?php echo htmlspecialchars($statement['statement']); ?>
                                    <?php if ($statement['is_required']): ?>
                                        <span class="required-marker">*</span>
                                    <?php endif; ?>
                                </div>
                                <div class="feedback-input">
                                    <textarea 
                                        name="comments[<?php echo $statement['id']; ?>]"
                                        placeholder="Please provide your feedback..."
                                        <?php echo $statement['is_required'] ? 'required' : ''; ?>
                                    ></textarea>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

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
        // Validate required rating fields
        const requiredRatings = document.querySelectorAll('input[type="radio"][required]');
        const ratingGroups = {};
        
        // Group radios by name
        requiredRatings.forEach(radio => {
            if (!ratingGroups[radio.name]) {
                ratingGroups[radio.name] = [];
            }
            ratingGroups[radio.name].push(radio);
        });
        
        // Check if at least one radio in each required group is checked
        for (const name in ratingGroups) {
            const checked = ratingGroups[name].some(radio => radio.checked);
            if (!checked) {
                alert('Please rate all required statements before submitting.');
                return false;
            }
        }
        
        // Validate required comment fields
        const requiredComments = document.querySelectorAll('textarea[required]');
        for (let textarea of requiredComments) {
            if (!textarea.value.trim()) {
                alert('Please fill in all required comment fields.');
                return false;
            }
        }
        
        return true;
    }
    </script>

    <?php include 'footer.php'; ?>
</body>
</html> 