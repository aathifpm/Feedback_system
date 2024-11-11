<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hod') {
    header('HTTP/1.1 403 Forbidden');
    exit('Access denied');
}

// Get search parameters
$search_term = $_POST['searchTerm'] ?? '';
$academic_year = isset($_POST['academicYear']) ? intval($_POST['academicYear']) : 0;
$semester = isset($_POST['semester']) ? intval($_POST['semester']) : 0;
$section = $_POST['section'] ?? '';
$subject_id = isset($_POST['subject']) ? intval($_POST['subject']) : 0;
$department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;

// Build the query
$query = "SELECT DISTINCT
    f.id, 
    f.name,
    f.faculty_id,
    f.designation,
    f.experience,
    f.qualification,
    f.specialization,
    d.name as department_name,
    COUNT(DISTINCT s.id) as total_subjects,
    COUNT(DISTINCT fb.id) as total_feedback,
    AVG(fb.course_effectiveness_avg) as course_effectiveness,
    AVG(fb.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(fb.resources_admin_avg) as resources_admin,
    AVG(fb.assessment_learning_avg) as assessment_learning,
    AVG(fb.course_outcomes_avg) as course_outcomes,
    AVG(fb.cumulative_avg) as overall_avg,
    MIN(fb.cumulative_avg) as min_rating,
    MAX(fb.cumulative_avg) as max_rating
FROM faculty f
JOIN departments d ON f.department_id = d.id
LEFT JOIN subjects s ON s.faculty_id = f.id 
    AND s.academic_year_id = ?
    AND s.is_active = TRUE
LEFT JOIN feedback fb ON fb.subject_id = s.id 
    AND fb.academic_year_id = ?
WHERE f.department_id = ?
AND f.is_active = TRUE";

$params = [$academic_year, $academic_year, $department_id];
$types = "iii";

// Add search conditions
if ($search_term) {
    $query .= " AND (f.name LIKE ? OR f.faculty_id LIKE ?)";
    $search_param = "%$search_term%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($semester) {
    $query .= " AND s.semester = ?";
    $params[] = $semester;
    $types .= "i";
}

if ($section) {
    $query .= " AND s.section = ?";
    $params[] = $section;
    $types .= "s";
}

if ($subject_id) {
    $query .= " AND s.id = ?";
    $params[] = $subject_id;
    $types .= "i";
}

$query .= " GROUP BY f.id, f.name, f.faculty_id, f.designation, f.experience, 
            f.qualification, f.specialization, d.name
           ORDER BY f.name";

// Execute query
$stmt = mysqli_prepare($conn, $query);
if (!$stmt) {
    error_log("Query preparation failed: " . mysqli_error($conn));
    echo '<div class="no-results">An error occurred while searching.</div>';
    exit;
}

mysqli_stmt_bind_param($stmt, $types, ...$params);

if (!mysqli_stmt_execute($stmt)) {
    error_log("Query execution failed: " . mysqli_stmt_error($stmt));
    echo '<div class="no-results">An error occurred while searching.</div>';
    exit;
}

$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) == 0) {
    echo '<div class="no-results">
            <i class="fas fa-search"></i>
            <p>No faculty members found matching your criteria.</p>
          </div>';
    exit;
}

// Display results
while ($faculty = mysqli_fetch_assoc($result)) {
    // Format numeric values
    $faculty['overall_avg'] = number_format($faculty['overall_avg'] ?? 0, 2);
    $faculty['course_effectiveness'] = number_format($faculty['course_effectiveness'] ?? 0, 2);
    $faculty['teaching_effectiveness'] = number_format($faculty['teaching_effectiveness'] ?? 0, 2);
    $faculty['resources_admin'] = number_format($faculty['resources_admin'] ?? 0, 2);
    $faculty['assessment_learning'] = number_format($faculty['assessment_learning'] ?? 0, 2);
    $faculty['course_outcomes'] = number_format($faculty['course_outcomes'] ?? 0, 2);
    $faculty['min_rating'] = number_format($faculty['min_rating'] ?? 0, 2);
    $faculty['max_rating'] = number_format($faculty['max_rating'] ?? 0, 2);
    
    include 'faculty_card_template.php';
}