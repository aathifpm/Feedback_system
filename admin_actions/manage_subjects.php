<?php
// Prevent direct access to this file
if (!defined('ADMIN_PANEL')) {
    exit('Direct access not permitted');
}

// Handle subject management logic here
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Process form submission for adding/editing subjects
    $subject_name = filter_input(INPUT_POST, 'subject_name', FILTER_SANITIZE_STRING);
    $faculty_id = filter_input(INPUT_POST, 'faculty_id', FILTER_VALIDATE_INT);
    $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_VALIDATE_INT);
    $year = filter_input(INPUT_POST, 'year', FILTER_VALIDATE_INT);
    $semester = filter_input(INPUT_POST, 'semester', FILTER_VALIDATE_INT);
    $section = filter_input(INPUT_POST, 'section', FILTER_SANITIZE_STRING);

    if ($subject_name && $faculty_id && $academic_year_id && $year && $semester && $section) {
        $query = "INSERT INTO subjects (code, name, department_id, faculty_id, academic_year_id, year, semester, section, credits, is_active) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ssiiiiisi", $subject_code, $subject_name, $department_id, $faculty_id, $academic_year_id, $year, $semester, $section, $credits);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success_message'] = "Subject added successfully.";
        } else {
            $_SESSION['error_message'] = "Error adding subject: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['error_message'] = "All fields are required.";
    }
    header("Location: admin_panel.php?action=manage_subjects");
    exit();
}

// Handle subject deletion
if (isset($_GET['delete'])) {
    $subject_id = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    if ($subject_id) {
        $query = "DELETE FROM subjects WHERE id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "i", $subject_id);
        
        if (mysqli_stmt_execute($stmt)) {
            $_SESSION['success_message'] = "Subject deleted successfully.";
        } else {
            $_SESSION['error_message'] = "Error deleting subject: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    }
    header("Location: admin_panel.php?action=manage_subjects");
    exit();
}

// Fetch and display existing subjects
$query = "SELECT s.*, f.name AS faculty_name, ay.year_range 
          FROM subjects s 
          JOIN faculty f ON s.faculty_id = f.id 
          JOIN academic_years ay ON s.academic_year = ay.id 
          ORDER BY ay.year_range DESC, s.year, s.semester, s.name";
$result = mysqli_query($conn, $query);
$subjects = mysqli_fetch_all($result, MYSQLI_ASSOC);

// Fetch faculty members for the dropdown
$faculty_query = "SELECT id, name FROM faculty ORDER BY name";
$faculty_result = mysqli_query($conn, $faculty_query);
$faculty_members = mysqli_fetch_all($faculty_result, MYSQLI_ASSOC);

// Fetch academic years for the dropdown
$academic_years_query = "SELECT id, year_range FROM academic_years ORDER BY start_year DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);
$academic_years = mysqli_fetch_all($academic_years_result, MYSQLI_ASSOC);
?>

<h1>Manage Subjects</h1>

<form method="post" action="">
    <div>
        <label for="subject_name">Subject Name:</label>
        <input type="text" id="subject_name" name="subject_name" required>
    </div>
    <div>
        <label for="faculty_id">Faculty:</label>
        <select id="faculty_id" name="faculty_id" required>
            <option value="">Select Faculty</option>
            <?php foreach ($faculty_members as $faculty): ?>
                <option value="<?php echo $faculty['id']; ?>"><?php echo htmlspecialchars($faculty['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="academic_year_id">Academic Year:</label>
        <select id="academic_year_id" name="academic_year_id" required>
            <option value="">Select Academic Year</option>
            <?php foreach ($academic_years as $academic_year): ?>
                <option value="<?php echo $academic_year['id']; ?>"><?php echo htmlspecialchars($academic_year['year_range']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="year">Year of Study:</label>
        <select id="year" name="year" required>
            <option value="">Select Year</option>
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label for="semester">Semester:</label>
        <select id="semester" name="semester" required>
            <option value="">Select Semester</option>
            <?php for ($i = 1; $i <= 8; $i++): ?>
                <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label for="section">Section:</label>
        <input type="text" id="section" name="section" required>
    </div>
    <div>
        <input type="submit" value="Add Subject" class="btn">
    </div>
</form>

<table>
    <thead>
        <tr>
            <th>Subject Name</th>
            <th>Faculty</th>
            <th>Academic Year</th>
            <th>Year</th>
            <th>Semester</th>
            <th>Section</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($subjects as $subject): ?>
        <tr>
            <td><?php echo htmlspecialchars($subject['name']); ?></td>
            <td><?php echo htmlspecialchars($subject['faculty_name']); ?></td>
            <td><?php echo htmlspecialchars($subject['year_range']); ?></td>
            <td><?php echo $subject['year']; ?></td>
            <td><?php echo $subject['semester']; ?></td>
            <td><?php echo htmlspecialchars($subject['section']); ?></td>
            <td>
                <a href="admin_panel.php?action=manage_subjects&edit=<?php echo $subject['id']; ?>" class="btn">Edit</a>
                <a href="admin_panel.php?action=manage_subjects&delete=<?php echo $subject['id']; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this subject?')">Delete</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>