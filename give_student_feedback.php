<?php
session_start();
include 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: index.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
$academic_year_batch = isset($_GET['academic_year_batch']) ? $_GET['academic_year_batch'] : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;
$semester = isset($_GET['semester']) ? intval($_GET['semester']) : null;
$section = isset($_GET['section']) ? mysqli_real_escape_string($conn, $_GET['section']) : null;
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : null;

if (!$academic_year_batch || !$year || !$semester || !$section || !$subject_id) {
    die("Error: Missing required parameters.");
}

// Get the academic year id from the batch
$query = "SELECT id FROM academic_years WHERE year_range = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "s", $academic_year_batch);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$academic_year = mysqli_fetch_assoc($result);

if (!$academic_year) {
    die("Error: Invalid academic year batch.");
}

$academic_year_id = $academic_year['id'];

// Fetch the subject details
$query = "SELECT name FROM subjects WHERE id = ? AND faculty_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "ii", $subject_id, $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$subject = mysqli_fetch_assoc($result);

if (!$subject) {
    die("Error: Invalid subject or you don't have permission to give feedback for this subject.");
}

// Fetch feedback statements from database instead of hardcoding
$stmt = mysqli_prepare($conn, "SELECT id, statement FROM feedback_statements WHERE is_active = TRUE");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$statements = [];
while ($row = mysqli_fetch_assoc($result)) {
    $statements[$row['id']] = $row['statement'];
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    mysqli_begin_transaction($conn);
    try {
        // Insert feedback record
        $query = "INSERT INTO feedback (
            student_id, subject_id, academic_year_id, 
            comments, submitted_at
        ) VALUES (?, ?, ?, ?, NOW())";
        
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "iiis", 
            $_SESSION['user_id'], 
            $subject_id, 
            $current_academic_year['id'],
            $_POST['comments']
        );
        mysqli_stmt_execute($stmt);
        $feedback_id = mysqli_insert_id($conn);
        
        // Insert ratings for each statement
        $rating_query = "INSERT INTO feedback_ratings (
            feedback_id, statement_id, rating
        ) VALUES (?, ?, ?)";
        
        $rating_stmt = mysqli_prepare($conn, $rating_query);
        foreach ($_POST['ratings'] as $statement_id => $rating) {
            mysqli_stmt_bind_param($rating_stmt, "iii", 
                $feedback_id, 
                $statement_id, 
                $rating
            );
            mysqli_stmt_execute($rating_stmt);
        }
        
        mysqli_commit($conn);
        $success_message = "Feedback submitted successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = "Error submitting feedback: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Give Student Feedback - <?php echo $academic_year_batch; ?> - Panimalar Engineering College</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #e0e5ec;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .header {
            width: 100%;
            padding: 1rem 2rem;
            background: #e0e5ec;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
        }

        .logo {
            width: 80px;
            height: auto;
            margin-right: 1rem;
        }

        .college-info {
            flex-grow: 1;
        }

        .college-info h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .college-info p {
            font-size: 0.8rem;
            color: #34495e;
        }

        .container {
            max-width: 1200px;
            width: 90%;
            margin: 2rem auto;
            padding: 2rem;
            background: #e0e5ec;
            border-radius: 20px;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
        }

        h2 {
            color: #2c3e50;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .rating {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }

        .rating input {
            display: none;
        }

        .rating label {
            cursor: pointer;
            width: 40px;
            height: 40px;
            margin-right: 5px;
            background: #e0e5ec;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            box-shadow: 3px 3px 6px #b8b9be, -3px -3px 6px #fff;
            transition: all 0.3s ease;
        }

        .rating label:hover,
        .rating label:hover ~ label,
        .rating input:checked ~ label {
            background: #3498db;
            color: white;
        }

        textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            color: #2c3e50;
            background: #e0e5ec;
            border: none;
            border-radius: 15px;
            outline: none;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .btn {
            display: inline-block;
            padding: 0.8rem 1.5rem;
            font-size: 1rem;
            font-weight: 500;
            color: #fff;
            background: #3498db;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }

        .btn:hover {
            background: #2980b9;
        }

        .error, .success {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }

        .success {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
        </div>
    </div>
    <div class="container">
        <h2>Give Student Feedback</h2>
        <p>Academic Year Batch: <?php echo $academic_year_batch; ?></p>
        <p>Year of Study: <?php echo $year; ?>, Semester: <?php echo $semester; ?>, Section: <?php echo $section; ?></p>
        <p>Subject: <?php echo htmlspecialchars($subject['name']); ?></p>
        
        <?php if (isset($error_message)): ?>
            <div class="error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($success_message)): ?>
            <div class="success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <form method="post" onsubmit="return validateForm()">
            <?php foreach ($statements as $statement_id => $statement): ?>
                <div class="form-group">
                    <label><?php echo htmlspecialchars($statement); ?></label>
                    <div class="rating">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="ratings[<?php echo $statement_id; ?>]" id="rating-<?php echo $statement_id; ?>-<?php echo $i; ?>" value="<?php echo $i; ?>" required>
                            <label for="rating-<?php echo $statement_id; ?>-<?php echo $i; ?>"><?php echo $i; ?></label>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="form-group">
                <label for="comments">Comments</label>
                <textarea name="comments" id="comments" rows="4"></textarea>
            </div>
            
            <button type="submit" class="btn">Submit Feedback</button>
        </form>
    </div>
    <script>
    function validateForm() {
        var statements = <?php echo count($statements); ?>;
        var ratings = document.querySelectorAll('input[type="radio"]:checked');
        
        if (ratings.length < statements) {
            alert("Please rate all statements.");
            return false;
        }
        
        return confirm("Are you sure you want to submit this feedback? This action cannot be undone.");
    }
    </script>
</body>
</html>