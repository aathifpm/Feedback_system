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

// Get subject assignments for the current semester
$assignments_query = "SELECT sa.id as assignment_id,
                            s.name as subject_name,
                            s.code as subject_code,
                            f.name as faculty_name,
                            sa.semester,
                            sa.section,
                            CASE 
                                WHEN ccr.id IS NOT NULL THEN 'Submitted' 
                                ELSE 'Pending' 
                            END as feedback_status,
                            ccr.submitted_at
                    FROM subject_assignments sa
                    JOIN subjects s ON sa.subject_id = s.id
                    JOIN faculty f ON sa.faculty_id = f.id
                    LEFT JOIN class_committee_responses ccr ON ccr.assignment_id = sa.id AND ccr.student_id = ?
                    WHERE sa.semester = ?
                    AND UPPER(TRIM(sa.section)) = UPPER(TRIM(?))
                    AND sa.academic_year_id = (SELECT id FROM academic_years WHERE is_current = TRUE)
                    AND sa.is_active = TRUE
                    AND s.department_id = ?
                    ORDER BY s.code";

$stmt = $pdo->prepare($assignments_query);
$stmt->execute([
    $user_id,
    $student['current_semester'],
    $student['section'],
    $student['department_id']
]);

// Check for query errors
if (!$stmt) {
    echo "Query Error: " . print_r($pdo->errorInfo(), true);
}

$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = null; // Close the statement

// If no assignments found with exact section match, try without section filter
// Sometimes sections in the DB might be stored differently than expected
$used_backup_query = false;
if (count($assignments) == 0) {
    $used_backup_query = true;
    $backup_query = "SELECT sa.id as assignment_id,
                           s.name as subject_name,
                           s.code as subject_code,
                           f.name as faculty_name,
                           sa.semester,
                           sa.section,
                           CASE 
                               WHEN ccr.id IS NOT NULL THEN 'Submitted' 
                               ELSE 'Pending' 
                           END as feedback_status,
                           ccr.submitted_at
                   FROM subject_assignments sa
                   JOIN subjects s ON sa.subject_id = s.id
                   JOIN faculty f ON sa.faculty_id = f.id
                   LEFT JOIN class_committee_responses ccr ON ccr.assignment_id = sa.id AND ccr.student_id = ?
                   WHERE sa.semester = ?
                   AND sa.academic_year_id = (SELECT id FROM academic_years WHERE is_current = TRUE)
                   AND sa.is_active = TRUE
                   AND s.department_id = ?
                   ORDER BY s.code";
                   
    $stmt = $pdo->prepare($backup_query);
    $stmt->execute([
        $user_id,
        $student['current_semester'],
        $student['department_id']
    ]);
    $all_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = null; // Close the statement
    
    // Filter assignments with matching or null sections
    $assignments = []; // Reset assignments array
    foreach ($all_assignments as $assignment) {
        if (empty($assignment['section']) || 
            strtoupper(trim($assignment['section'])) == strtoupper(trim($student['section']))) {
            $assignments[] = $assignment;
        }
    }
}

// Display temporary debug notice that can be commented out later
// echo '<div style="background:#ffd; border:1px solid #dd0; padding:5px; margin:5px;">
//     Debug: ' . ($used_backup_query ? 'Used backup query (no exact section match)' : 'Used primary query with exact section match') . 
//     ' - Found ' . count($assignments) . ' assignments
// </div>';

// Uncomment below to debug what assignments are being found
/* 
echo "<div style='display:none'>";
echo "Debug - Student section: " . htmlspecialchars($student['section']) . "<br>";
echo "Debug - Semester: " . htmlspecialchars($student['current_semester']) . "<br>";
echo "Debug - Assignments found: " . count($assignments) . "<br>";
foreach ($assignments as $index => $assignment) {
    echo "Assignment " . ($index + 1) . ": Subject=" . htmlspecialchars($assignment['subject_name']) . 
         ", Section=" . htmlspecialchars($assignment['section']) . "<br>";
}
echo "</div>";
*/

// Calculate statistics
$total_subjects = count($assignments);
$completed_feedback = 0;
foreach ($assignments as $assignment) {
    if ($assignment['feedback_status'] === 'Submitted') {
        $completed_feedback++;
    }
}
$pending_feedback = $total_subjects - $completed_feedback;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Committee Meetings</title>
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
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            text-align: center;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #666;
            font-size: 1rem;
        }

        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .info-card {
            background: var(--bg-color);
            padding: 1rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            text-align: center;
        }

        .info-card h3 {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .info-card p {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .stat-card .label {
            color: #666;
            font-size: 1rem;
        }

        .subjects-container {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
            color: var(--text-color);
        }

        .subject-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s ease;
        }

        .subject-card:hover {
            transform: translateY(-3px);
        }

        .subject-info h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }

        .subject-info p {
            font-size: 0.9rem;
            color: #666;
        }

        .subject-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .no-subjects {
            text-align: center;
            padding: 3rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .no-subjects i {
            font-size: 3rem;
            color: #666;
            margin-bottom: 1rem;
        }

        .no-subjects p {
            color: #666;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .student-info {
                grid-template-columns: 1fr;
            }

            .subject-card {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .subject-actions {
                flex-direction: column;
                width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>Class Committee Meetings</h1>
            <p>Provide feedback for your current semester subjects</p>
            
            <div class="student-info">
                <div class="info-card">
                    <h3>Department</h3>
                    <p><?php echo htmlspecialchars($student['department_name']); ?></p>
                </div>
                <div class="info-card">
                    <h3>Batch</h3>
                    <p><?php echo htmlspecialchars($student['batch_name']); ?></p>
                </div>
                <div class="info-card">
                    <h3>Current Year</h3>
                    <p><?php echo htmlspecialchars($student['current_year_of_study']); ?> Year</p>
                </div>
                <div class="info-card">
                    <h3>Current Semester</h3>
                    <p>Semester <?php echo htmlspecialchars($student['current_semester']); ?></p>
                </div>
                <div class="info-card">
                    <h3>Section</h3>
                    <p><?php echo htmlspecialchars($student['section']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="stats-container">
            <div class="stat-card">
                <i class="fas fa-book icon"></i>
                <div class="number"><?php echo $total_subjects; ?></div>
                <div class="label">Total Subjects</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle icon"></i>
                <div class="number"><?php echo $completed_feedback; ?></div>
                <div class="label">Feedback Submitted</div>
            </div>
            <div class="stat-card">
                <i class="fas fa-clock icon"></i>
                <div class="number"><?php echo $pending_feedback; ?></div>
                <div class="label">Pending Feedback</div>
            </div>
        </div>
        
        <div class="subjects-container">
            <h2 class="section-title">Your Subjects</h2>
            
            <?php if (empty($assignments)): ?>
                <div class="no-subjects">
                    <i class="fas fa-info-circle"></i>
                    <p>No subjects found for the current semester.</p>
                </div>
            <?php else: ?>
                <?php foreach ($assignments as $assignment): ?>
                    <div class="subject-card">
                        <div class="subject-info">
                            <h3><?php echo htmlspecialchars($assignment['subject_name']); ?> (<?php echo htmlspecialchars($assignment['subject_code']); ?>)</h3>
                            <p>Faculty: <?php echo htmlspecialchars($assignment['faculty_name']); ?></p>
                        </div>
                        <div class="subject-actions">
                            <span class="status-badge <?php echo $assignment['feedback_status'] === 'Submitted' ? 'status-completed' : 'status-pending'; ?>">
                                <?php echo htmlspecialchars($assignment['feedback_status']); ?>
                            </span>
                            
                            <?php if ($assignment['feedback_status'] === 'Pending'): ?>
                                <a href="give_class_committee_feedback.php?assignment_id=<?php echo urlencode($assignment['assignment_id']); ?>" class="btn btn-primary">
                                    <i class="fas fa-users"></i> Give Feedback
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Add Non-Academic Feedback Section -->
        <div class="subjects-container" style="margin-top: 2rem;">
            <h2 class="section-title">Non-Academic Feedback</h2>
            
            <?php
            // Check if non-academic feedback already submitted for current semester
            $nonacademic_check_query = "SELECT id FROM non_academic_feedback 
                                      WHERE student_id = ? 
                                      AND academic_year_id = (SELECT id FROM academic_years WHERE is_current = TRUE)
                                      AND semester = ?";
            $stmt = $pdo->prepare($nonacademic_check_query);
            $stmt->execute([$user_id, $student['current_semester']]);
            $nonacademic_feedback_exists = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = null; // Close the statement
            ?>
            
            <div class="subject-card">
                <div class="subject-info">
                    <h3>Campus Facilities & Services Feedback</h3>
                    <p>Share your experience with campus facilities, hostel, transport, and other non-academic aspects</p>
                </div>
                <div class="subject-actions">
                    <span class="status-badge <?php echo $nonacademic_feedback_exists ? 'status-completed' : 'status-pending'; ?>">
                        <?php echo $nonacademic_feedback_exists ? 'Submitted' : 'Pending'; ?>
                    </span>
                    
                    <?php if (!$nonacademic_feedback_exists): ?>
                        <a href="give_non_academic_feedback.php" class="btn btn-primary">
                            <i class="fas fa-building"></i> Give Feedback
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'footer.php'; ?>
</body>
</html> 