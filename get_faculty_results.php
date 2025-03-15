<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in and is a HOD
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'hod') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Get current academic year
$academic_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

if (!$current_academic_year) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No active academic year found']);
    exit();
}

// Get parameters from POST request
$search = mysqli_real_escape_string($conn, $_POST['search'] ?? '');
$experience = mysqli_real_escape_string($conn, $_POST['experience'] ?? '');
$designation = mysqli_real_escape_string($conn, $_POST['designation'] ?? '');
$sort_by = mysqli_real_escape_string($conn, $_POST['sort_by'] ?? 'name');
$department_id = intval($_POST['department_id']);

// Build the query
$query = "SELECT 
    f.*,
    d.name as department_name,
    d.code as department_code,
    (SELECT COUNT(DISTINCT sa.id) 
     FROM subject_assignments sa 
     WHERE sa.faculty_id = f.id 
     AND sa.academic_year_id = ? 
     AND sa.is_active = TRUE) as total_subjects,
    (SELECT COUNT(DISTINCT fb.id) 
     FROM feedback fb 
     JOIN subject_assignments sa ON fb.assignment_id = sa.id 
     WHERE sa.faculty_id = f.id 
     AND sa.academic_year_id = ?) as total_feedback,
    (SELECT AVG(fb.course_effectiveness_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as course_effectiveness,
    (SELECT AVG(fb.teaching_effectiveness_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as teaching_effectiveness,
    (SELECT AVG(fb.resources_admin_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as resources_admin,
    (SELECT AVG(fb.assessment_learning_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as assessment_learning,
    (SELECT AVG(fb.cumulative_avg)
     FROM feedback fb
     JOIN subject_assignments sa ON fb.assignment_id = sa.id
     WHERE sa.faculty_id = f.id
     AND sa.academic_year_id = ?) as overall_avg,
    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name) as subjects
FROM faculty f
JOIN departments d ON f.department_id = d.id
LEFT JOIN subject_assignments sa ON sa.faculty_id = f.id AND sa.academic_year_id = ?
LEFT JOIN subjects s ON sa.subject_id = s.id
WHERE f.department_id = ? AND f.is_active = TRUE";

// Add search conditions
if (!empty($search)) {
    $query .= " AND (f.name LIKE ? OR f.faculty_id LIKE ?)";
}

// Add experience filter
if (!empty($experience)) {
    list($min, $max) = explode('-', $experience);
    if ($max === '+') {
        $query .= " AND f.experience >= ?";
    } else {
        $query .= " AND f.experience BETWEEN ? AND ?";
    }
}

// Add designation filter
if (!empty($designation)) {
    $query .= " AND f.designation = ?";
}

// Group by faculty
$query .= " GROUP BY f.id";

// Add sorting
switch ($sort_by) {
    case 'rating':
        $query .= " ORDER BY overall_avg DESC";
        break;
    case 'experience':
        $query .= " ORDER BY f.experience DESC";
        break;
    case 'feedback_count':
        $query .= " ORDER BY total_feedback DESC";
        break;
    default:
        $query .= " ORDER BY f.name ASC";
}

// Prepare statement
$stmt = mysqli_prepare($conn, $query);

// Bind parameters
$params = [$current_academic_year['id'], $current_academic_year['id'], 
           $current_academic_year['id'], $current_academic_year['id'],
           $current_academic_year['id'], $current_academic_year['id'],
           $current_academic_year['id'], $current_academic_year['id'],
           $department_id];

if (!empty($search)) {
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($experience)) {
    list($min, $max) = explode('-', $experience);
    if ($max === '+') {
        $params[] = intval($min);
    } else {
        $params[] = intval($min);
        $params[] = intval($max);
    }
}

if (!empty($designation)) {
    $params[] = $designation;
}

// Create type string for bind_param
$types = str_repeat('i', 9); // First 9 parameters are integers
if (!empty($search)) $types .= 'ss'; // Add types for search parameters
if (!empty($experience)) {
    if (strpos($experience, '+') !== false) {
        $types .= 'i';
    } else {
        $types .= 'ii';
    }
}
if (!empty($designation)) $types .= 's';

// Bind parameters dynamically
mysqli_stmt_bind_param($stmt, $types, ...$params);

// Execute query
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$faculty = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Return results as HTML
if (!empty($faculty)) {
    foreach ($faculty as $member) {
        ?>
        <div class="faculty-card">
            <div class="faculty-header">
                <div class="faculty-id"><?php echo htmlspecialchars($member['faculty_id']); ?></div>
                <h3><?php echo htmlspecialchars($member['name']); ?></h3>
            </div>

            <div class="faculty-details">
                <p><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($member['designation']); ?></p>
                <p><i class="fas fa-clock"></i> <?php echo htmlspecialchars($member['experience']); ?> years</p>
                <p><i class="fas fa-graduation-cap"></i> <?php echo htmlspecialchars($member['qualification']); ?></p>
                <p><i class="fas fa-book"></i> <?php echo htmlspecialchars($member['specialization']); ?></p>
            </div>

            <div class="feedback-stats">
                <div class="stat-group">
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $member['total_subjects']; ?></span>
                        <span class="stat-label">Subjects</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo $member['total_feedback']; ?></span>
                        <span class="stat-label">Feedbacks</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-value"><?php echo number_format($member['overall_avg'], 2); ?></span>
                        <span class="stat-label">Overall Rating</span>
                    </div>
                </div>
            </div>

            <div class="rating-categories">
                <div class="rating-item">
                    <div class="rating-label">Course Effectiveness</div>
                    <div class="rating-bar">
                        <div class="rating-fill" style="width: <?php echo ($member['course_effectiveness'] * 20); ?>%">
                            <?php echo number_format($member['course_effectiveness'], 2); ?>
                        </div>
                    </div>
                </div>
                <div class="rating-item">
                    <div class="rating-label">Teaching Effectiveness</div>
                    <div class="rating-bar">
                        <div class="rating-fill" style="width: <?php echo ($member['teaching_effectiveness'] * 20); ?>%">
                            <?php echo number_format($member['teaching_effectiveness'], 2); ?>
                        </div>
                    </div>
                </div>
                <div class="rating-item">
                    <div class="rating-label">Resources & Administration</div>
                    <div class="rating-bar">
                        <div class="rating-fill" style="width: <?php echo ($member['resources_admin'] * 20); ?>%">
                            <?php echo number_format($member['resources_admin'], 2); ?>
                        </div>
                    </div>
                </div>
                <div class="rating-item">
                    <div class="rating-label">Assessment & Learning</div>
                    <div class="rating-bar">
                        <div class="rating-fill" style="width: <?php echo ($member['assessment_learning'] * 20); ?>%">
                            <?php echo number_format($member['assessment_learning'], 2); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="faculty-assignments">
                <h4>Current Assignments</h4>
                <div class="assignments-grid">
                    <?php 
                    $assignments = explode(',', $member['subjects']);
                    foreach ($assignments as $subject): 
                        if (!empty(trim($subject))):
                    ?>
                        <div class="assignment-item">
                            <span class="subject-name"><?php echo htmlspecialchars(trim($subject)); ?></span>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>

            <div class="faculty-actions">
                <a href="faculty_detailed_report.php?id=<?php echo $member['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-chart-line"></i> View Detailed Report
                </a>
                <button class="btn btn-secondary generate-report-btn" data-faculty-id="<?php echo $member['id']; ?>">
                    <i class="fas fa-file-pdf"></i> Generate PDF Report
                </button>
            </div>
        </div>
        <?php
    }
} else {
    ?>
    <div class="no-results">
        <i class="fas fa-search"></i>
        <p>No faculty members found matching your criteria.</p>
    </div>
    <?php
}
?> 