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
    $student_id = $_POST['student_id'];
    $assignment_id = $_POST['assignment_id'];
    $ratings = $_POST['ratings'] ?? [];
    $comments = $_POST['comments'] ?? '';

    // Validate student and assignment
    $validation_query = "SELECT sa.id 
                        FROM subject_assignments sa
                        JOIN subjects s ON sa.subject_id = s.id
                        JOIN students st ON st.department_id = s.department_id
                        WHERE sa.id = ? 
                        AND st.id = ?
                        AND sa.is_active = TRUE";
    
    $stmt = $pdo->prepare($validation_query);
    $stmt->execute([$assignment_id, $student_id]);
    $valid = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = null; // Close the statement

    if (!$valid) {
        $_SESSION['error'] = "Invalid submission attempt.";
        header('Location: dashboard.php');
        exit();
    }

    // Check if feedback already exists
    $check_query = "SELECT id FROM class_committee_responses WHERE student_id = ? AND assignment_id = ?";
    $stmt = $pdo->prepare($check_query);
    $stmt->execute([$student_id, $assignment_id]);
    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmt = null; // Close the statement
        $_SESSION['error'] = "Feedback already submitted for this subject.";
        header('Location: dashboard.php');
        exit();
    }
    $stmt = null; // Close the statement

    // Start transaction
    try {
        $pdo->beginTransaction();

        // Insert feedback response
        $insert_query = "INSERT INTO class_committee_responses (student_id, assignment_id, academic_ratings, comments) 
                        VALUES (?, ?, ?, ?)";
        $stmt = $pdo->prepare($insert_query);
        $ratings_json = json_encode($ratings);
        
        if (!$stmt->execute([$student_id, $assignment_id, $ratings_json, $comments])) {
            throw new Exception("Error saving feedback: " . implode(", ", $stmt->errorInfo()));
        }
        $stmt = null; // Close the statement

        // Log the feedback submission
        $log_query = "INSERT INTO user_logs (user_id, role, action, details) 
                     VALUES (?, 'student', 'Submitted class committee feedback', ?)";
        $stmt = $pdo->prepare($log_query);
        $log_details = json_encode(['assignment_id' => $assignment_id]);
        
        if (!$stmt->execute([$student_id, $log_details])) {
            throw new Exception("Error logging feedback: " . implode(", ", $stmt->errorInfo()));
        }
        $stmt = null; // Close the statement

        $pdo->commit();
        $_SESSION['success'] = "Class committee feedback submitted successfully.";
        header('Location: class_committee_meetings.php');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error submitting feedback. Please try again.";
        error_log("Error in class committee feedback submission: " . $e->getMessage());
        header('Location: dashboard.php');
        exit();
    }
}

// Check if assignment_id is provided
if (!isset($_GET['assignment_id'])) {
    header('Location: dashboard.php');
    exit();
}

$assignment_id = $_GET['assignment_id'];

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

// Get subject assignment details
$assignment_query = "SELECT sa.id as assignment_id,
                           s.name as subject_name,
                           s.code as subject_code,
                           f.name as faculty_name,
                           sa.semester,
                           sa.section
                    FROM subject_assignments sa
                    JOIN subjects s ON sa.subject_id = s.id
                    JOIN faculty f ON sa.faculty_id = f.id
                    WHERE sa.id = ?
                    AND sa.semester = ?
                    AND sa.section = ?
                    AND sa.academic_year_id = (SELECT id FROM academic_years WHERE is_current = TRUE)
                    AND sa.is_active = TRUE
                    AND s.department_id = ?";

$stmt = $pdo->prepare($assignment_query);
$stmt->execute([
    $assignment_id,
    $student['current_semester'],
    $student['section'],
    $student['department_id']
]);
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null; // Close the statement

if (!$assignment) {
    $_SESSION['error'] = "Invalid subject assignment or unauthorized access.";
    header('Location: dashboard.php');
    exit();
}

// Get class committee statements
$statements_query = "SELECT * FROM class_committee_statements WHERE is_active = TRUE ORDER BY statement_number";
$statements_result = $pdo->query($statements_query);
if (!$statements_result) {
    die("Error fetching statements: " . implode(", ", $pdo->errorInfo()));
}

// Check if feedback already submitted
$feedback_check_query = "SELECT id FROM class_committee_responses WHERE student_id = ? AND assignment_id = ?";
$stmt = $pdo->prepare($feedback_check_query);
$stmt->execute([$user_id, $assignment_id]);
$feedback_exists = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt = null; // Close the statement

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Committee Feedback</title>
    <?php include 'header.php'; ?>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }

        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .feedback-container {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .feedback-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .feedback-header h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .feedback-header p {
            color: #666;
            font-size: 1rem;
        }

        .divider {
            height: 2px;
            background: var(--primary-color);
            margin: 1.5rem 0;
            border-radius: 1px;
        }

        .subject-details {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            margin-bottom: 2rem;
        }

        .subject-details h3 {
            color: var(--text-color);
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .subject-details p {
            color: #666;
            font-size: 0.9rem;
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

        .rating-legend {
            text-align: center;
            margin: 1.5rem 0;
            padding: 1rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            color: #666;
        }

        .btn-submit {
            display: block;
            width: 250px;
            margin: 2rem auto;
            padding: 1rem;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .btn-submit i {
            margin-right: 8px;
        }

        .btn-submit:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(52, 152, 219, 0.3);
        }

        .feedback-status {
            text-align: center;
            padding: 3rem 2rem;
        }

        .feedback-status i {
            font-size: 4rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }

        .feedback-status p {
            font-size: 1.2rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
        }

        .back-btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .back-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(52, 152, 219, 0.3);
        }

        .scale-info {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #666;
            font-size: 0.9rem;
        }

        .comments-section {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .comments-section h3 {
            color: var(--text-color);
            font-size: 1.1rem;
            margin-bottom: 0.8rem;
        }

        .form-group {
            position: relative;
        }

        .form-group textarea {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            line-height: 1.5;
            resize: vertical;
            min-height: 120px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .form-group textarea:focus {
            outline: none;
            box-shadow: inset 4px 4px 8px rgba(0, 0, 0, 0.1),
                       inset -4px -4px 8px rgba(255, 255, 255, 0.8),
                       0 0 0 2px rgba(52, 152, 219, 0.3);
        }

        .form-group textarea::placeholder {
            color: #999;
        }

        .char-counter {
            position: absolute;
            bottom: 10px;
            right: 15px;
            font-size: 0.8rem;
            color: #666;
            background: var(--bg-color);
            padding: 2px 8px;
            border-radius: 10px;
            box-shadow: var(--shadow);
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
                margin: 1rem;
            }
            
            .feedback-container {
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
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="feedback-container">
            <div class="feedback-header">
                <h2>Class Committee Feedback Form</h2>
                <p>Semester: <?php echo $student['current_semester']; ?> | Section: <?php echo $student['section']; ?></p>
            </div>
            
            <div class="divider"></div>
            
            <div class="subject-details">
                <h3><?php echo htmlspecialchars($assignment['subject_name']); ?> (<?php echo htmlspecialchars($assignment['subject_code']); ?>)</h3>
                <p>Faculty: <?php echo htmlspecialchars($assignment['faculty_name']); ?></p>
            </div>
            
            <?php if ($feedback_exists): ?>
                <div class="feedback-status">
                    <i class="fas fa-check-circle"></i>
                    <p>You have already submitted feedback for this subject.</p>
                    <a href="dashboard.php" class="back-btn">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?assignment_id=<?php echo $assignment_id; ?>" method="POST" onsubmit="return validateForm()">
                    <input type="hidden" name="student_id" value="<?php echo $user_id; ?>">
                    <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                    
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
                            <?php 
                            // Execute the query and fetch all statements
                            $statements_result = $pdo->query($statements_query);
                            $statements = $statements_result->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Check if there are statements
                            if (count($statements) > 0) {
                                foreach ($statements as $statement): 
                            ?>
                                <tr>
                                    <td><?php echo $statement['statement_number']; ?></td>
                                    <td class="statement-cell">
                                        <?php echo htmlspecialchars($statement['statement']); ?>
                                    </td>
                                    <td class="rating-cell">
                                        <div class="rating-options">
                                            <?php for ($i = 5; $i >= 1; $i--): ?>
                                                <div class="rating-option">
                                                    <input type="radio" 
                                                        id="rating_<?php echo $statement['id']; ?>_<?php echo $i; ?>" 
                                                        name="ratings[<?php echo $statement['id']; ?>]" 
                                                        value="<?php echo $i; ?>" 
                                                        required>
                                                    <label for="rating_<?php echo $statement['id']; ?>_<?php echo $i; ?>"><?php echo $i; ?></label>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php 
                                endforeach; 
                            } else {
                                echo '<tr><td colspan="3" style="text-align:center;">No statements found. Please contact administrator.</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                    
                    <!-- Add Comments Section -->
                    <div class="comments-section">
                        <h3>Comments and Suggestions</h3>
                        <div class="form-group">
                            <textarea name="comments" id="comments" rows="4" placeholder="Please provide any additional comments or suggestions..."></textarea>
                            <div class="char-counter">
                                <span id="charCount">0</span> characters
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane"></i> Submit Feedback
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    function validateForm() {
        const requiredRadios = document.querySelectorAll('input[type="radio"][required]');
        const groupNames = {};
        
        // Group radios by name
        requiredRadios.forEach(radio => {
            if (!groupNames[radio.name]) {
                groupNames[radio.name] = [];
            }
            groupNames[radio.name].push(radio);
        });
        
        // Check if at least one radio in each group is checked
        for (const name in groupNames) {
            const checked = groupNames[name].some(radio => radio.checked);
            if (!checked) {
                alert('Please rate all statements before submitting.');
                return false;
            }
        }
        
        return true;
    }
    
    // Add character counter for comments
    document.addEventListener('DOMContentLoaded', function() {
        const commentsTextarea = document.getElementById('comments');
        const charCount = document.getElementById('charCount');
        
        if (commentsTextarea && charCount) {
            commentsTextarea.addEventListener('input', function() {
                const count = this.value.length;
                charCount.textContent = count;
                
                // Optional: Change color when approaching limit
                if (count > 900) {
                    charCount.style.color = 'var(--danger-color)';
                } else {
                    charCount.style.color = '#666';
                }
            });
        }
    });
    </script>
    
    <?php include 'footer.php'; ?>
</body>
</html> 