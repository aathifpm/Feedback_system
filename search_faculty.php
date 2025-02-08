<?php
session_start();
require_once 'db_connection.php';

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    die(json_encode(['error' => 'Unauthorized access']));
}

// Get search parameters
$search_term = isset($_POST['search_term']) ? mysqli_real_escape_string($conn, $_POST['search_term']) : '';
$academic_year_id = isset($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : 0;
$semester = isset($_POST['semester']) ? intval($_POST['semester']) : 0;
$section = isset($_POST['section']) ? mysqli_real_escape_string($conn, $_POST['section']) : '';
$subject_id = isset($_POST['subject_id']) ? intval($_POST['subject_id']) : 0;
$department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;

// Build the base query
$query = "SELECT 
    f.id, 
    f.name,
    f.faculty_id,
    f.designation,
    f.experience,
    f.qualification,
    f.specialization,
    d.name as department_name,
    COUNT(DISTINCT sa.id) as total_subjects,
    COUNT(DISTINCT fb.id) as total_feedback,
    AVG(fb.course_effectiveness_avg) as course_effectiveness,
    AVG(fb.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(fb.resources_admin_avg) as resources_admin,
    AVG(fb.assessment_learning_avg) as assessment_learning,
    AVG(fb.course_outcomes_avg) as course_outcomes,
    AVG(fb.cumulative_avg) as overall_avg,
    GROUP_CONCAT(DISTINCT CONCAT(sa.year, '-', sa.semester, '-', sa.section) 
        ORDER BY sa.year, sa.semester, sa.section) as sections,
    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name) as subjects
FROM faculty f
JOIN departments d ON f.department_id = d.id
LEFT JOIN subject_assignments sa ON sa.faculty_id = f.id 
    AND sa.academic_year_id = ?
    AND sa.is_active = TRUE
LEFT JOIN subjects s ON sa.subject_id = s.id
LEFT JOIN feedback fb ON fb.assignment_id = sa.id
WHERE f.department_id = ? 
AND f.is_active = TRUE";

$params = [$academic_year_id, $department_id];
$types = "ii";

// Add search conditions
if ($search_term) {
    $query .= " AND (f.name LIKE ? OR f.faculty_id LIKE ?)";
    $search_pattern = "%$search_term%";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $types .= "ss";
}

if ($semester) {
    $query .= " AND sa.semester = ?";
    $params[] = $semester;
    $types .= "i";
}

if ($section) {
    $query .= " AND sa.section = ?";
    $params[] = $section;
    $types .= "s";
}

if ($subject_id) {
    $query .= " AND sa.subject_id = ?";
    $params[] = $subject_id;
    $types .= "i";
}

// Add grouping and ordering
$query .= " GROUP BY f.id, f.name, f.faculty_id, f.designation, f.experience, 
            f.qualification, f.specialization, d.name
           ORDER BY f.name";

$stmt = mysqli_prepare($conn, $query);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $faculty = [];
    while ($row = mysqli_fetch_assoc($result)) {
        // Format numeric values
        $row['overall_avg'] = number_format($row['overall_avg'] ?? 0, 2);
        $row['course_effectiveness'] = number_format($row['course_effectiveness'] ?? 0, 2);
        $row['teaching_effectiveness'] = number_format($row['teaching_effectiveness'] ?? 0, 2);
        $row['resources_admin'] = number_format($row['resources_admin'] ?? 0, 2);
        $row['assessment_learning'] = number_format($row['assessment_learning'] ?? 0, 2);
        $row['course_outcomes'] = number_format($row['course_outcomes'] ?? 0, 2);
        $row['total_subjects'] = intval($row['total_subjects']);
        $row['total_feedback'] = intval($row['total_feedback']);
        
        $faculty[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($faculty);
} else {
    die(json_encode(['error' => 'Query preparation failed']));
}
?>