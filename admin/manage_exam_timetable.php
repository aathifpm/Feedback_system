<?php
// Start session and include required files
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../db_connection.php';
require_once '../functions.php';
require_once 'includes/admin_functions.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header('Location: index.php');
    exit();
}

// Include header after authentication
include 'includes/header.php';

// Department filter based on admin type
$department_filter = "";
$department_params = [];

// If department admin, restrict data to their department
if (!is_super_admin() && isset($_SESSION['department_id'])) {
    $department_filter = " AND s.department_id = ?";
    $department_params[] = $_SESSION['department_id'];
}

// Function to validate time format and range
function validateExamTime($start_time, $end_time) {
    $start = strtotime($start_time);
    $end = strtotime($end_time);
    
    if ($start === false || $end === false) {
        return "Invalid time format";
    }
    
    if ($end <= $start) {
        return "End time must be after start time";
    }
    
    return true;
}

$redirect = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                try {
                    $academic_year_id = intval($_POST['academic_year_id']);
                    $semester = intval($_POST['semester']);
                    $subject_id = intval($_POST['subject_id']);
                    $exam_date = $_POST['exam_date'];
                    $exam_session = $_POST['exam_session'];

                    // Set times based on session
                    if ($exam_session === 'Morning') {
                        $start_time = '10:00:00';
                        $end_time = '13:00:00';
                    } else {
                        $start_time = '14:00:00';
                        $end_time = '17:00:00';
                    }

                    // Check department access for department admin
                    if (!is_super_admin()) {
                        $check_dept_query = "SELECT s.department_id 
                                           FROM subjects s 
                                           WHERE s.id = ?";
                        $check_dept_stmt = mysqli_prepare($conn, $check_dept_query);
                        mysqli_stmt_bind_param($check_dept_stmt, "i", $subject_id);
                        mysqli_stmt_execute($check_dept_stmt);
                        $dept_result = mysqli_stmt_get_result($check_dept_stmt);
                        $dept_data = mysqli_fetch_assoc($dept_result);
                        
                        if (!$dept_data || $dept_data['department_id'] != $_SESSION['department_id']) {
                            throw new Exception("You don't have permission to add exams for this subject.");
                        }
                    }

                    // Check for existing exam on same date and session
                    $check_query = "SELECT id FROM exam_timetable 
                                  WHERE exam_date = ? AND exam_session = ? 
                                  AND academic_year_id = ? AND semester = ?";
                    $check_stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($check_stmt, "ssii", $exam_date, $exam_session, $academic_year_id, $semester);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);

                    if (mysqli_fetch_assoc($check_result)) {
                        throw new Exception("An exam is already scheduled for this date and session");
                    }

                    $query = "INSERT INTO exam_timetable (
                        academic_year_id, semester, subject_id, exam_date, exam_session, 
                        start_time, end_time, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, TRUE)";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iiissss", 
                        $academic_year_id, $semester, $subject_id, $exam_date, 
                        $exam_session, $start_time, $end_time
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['success'] = "Exam schedule added successfully";
                    } else {
                        throw new Exception("Error adding exam schedule: " . mysqli_error($conn));
                    }
                    $redirect = true;
                    break;
                } catch (Exception $e) {
                    $_SESSION['error'] = $e->getMessage();
                    $redirect = true;
                    break;
                }

            case 'edit':
                try {
                    $id = intval($_POST['id']);
                    $academic_year_id = intval($_POST['academic_year_id']);
                    $semester = intval($_POST['semester']);
                    $subject_id = intval($_POST['subject_id']);
                    $exam_date = $_POST['exam_date'];
                    $exam_session = $_POST['exam_session'];

                    // Set times based on session
                    if ($exam_session === 'Morning') {
                        $start_time = '10:00:00';
                        $end_time = '13:00:00';
                    } else {
                        $start_time = '14:00:00';
                        $end_time = '17:00:00';
                    }

                    // Check department access for department admin
                    if (!is_super_admin()) {
                        $check_dept_query = "SELECT s.department_id 
                                           FROM subjects s 
                                           JOIN subject_assignments sa ON s.id = sa.subject_id 
                                           JOIN exam_timetable et ON sa.subject_id = et.subject_id 
                                           WHERE et.id = ?";
                        $check_dept_stmt = mysqli_prepare($conn, $check_dept_query);
                        mysqli_stmt_bind_param($check_dept_stmt, "i", $id);
                        mysqli_stmt_execute($check_dept_stmt);
                        $dept_result = mysqli_stmt_get_result($check_dept_stmt);
                        $dept_data = mysqli_fetch_assoc($dept_result);
                        
                        if (!$dept_data || $dept_data['department_id'] != $_SESSION['department_id']) {
                            throw new Exception("You don't have permission to edit this exam schedule.");
                        }
                    }

                    // Validate times
                    $time_validation = validateExamTime($start_time, $end_time);
                    if ($time_validation !== true) {
                        throw new Exception($time_validation);
                    }

                    // Verify that the subject exists and is assigned to this semester
                    $verify_query = "SELECT id FROM subject_assignments 
                                   WHERE subject_id = ? AND semester = ? AND academic_year_id = ?";
                    $verify_stmt = mysqli_prepare($conn, $verify_query);
                    mysqli_stmt_bind_param($verify_stmt, "iii", $subject_id, $semester, $academic_year_id);
                    mysqli_stmt_execute($verify_stmt);
                    $verify_result = mysqli_stmt_get_result($verify_stmt);

                    if (!mysqli_fetch_assoc($verify_result)) {
                        throw new Exception("Invalid subject selection for this semester");
                    }

                    // Check for existing exam on same date and session (excluding current record)
                    $check_query = "SELECT id FROM exam_timetable 
                                  WHERE exam_date = ? AND exam_session = ? 
                                  AND academic_year_id = ? AND semester = ?
                                  AND id != ?";
                    $check_stmt = mysqli_prepare($conn, $check_query);
                    mysqli_stmt_bind_param($check_stmt, "ssiii", $exam_date, $exam_session, $academic_year_id, $semester, $id);
                    mysqli_stmt_execute($check_stmt);
                    $check_result = mysqli_stmt_get_result($check_stmt);

                    if (mysqli_fetch_assoc($check_result)) {
                        throw new Exception("An exam is already scheduled for this date and session");
                    }

                    $query = "UPDATE exam_timetable SET 
                        academic_year_id = ?, semester = ?, subject_id = ?, exam_date = ?, 
                        exam_session = ?, start_time = ?, end_time = ?
                        WHERE id = ?";

                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "iiissssi", 
                        $academic_year_id, $semester, $subject_id, $exam_date, 
                        $exam_session, $start_time, $end_time, $id
                    );

                    if (mysqli_stmt_execute($stmt)) {
                        $_SESSION['success'] = "Exam schedule updated successfully";
                    } else {
                        throw new Exception("Error updating exam schedule: " . mysqli_error($conn));
                    }
                    $redirect = true;
                    break;
                } catch (Exception $e) {
                    $_SESSION['error'] = $e->getMessage();
                    $redirect = true;
                    break;
                }

            case 'delete':
                $id = intval($_POST['id']);

                // Check department access for department admin
                if (!is_super_admin()) {
                    $check_dept_query = "SELECT s.department_id 
                                       FROM subjects s 
                                       JOIN subject_assignments sa ON s.id = sa.subject_id 
                                       JOIN exam_timetable et ON sa.subject_id = et.subject_id 
                                       WHERE et.id = ?";
                    $check_dept_stmt = mysqli_prepare($conn, $check_dept_query);
                    mysqli_stmt_bind_param($check_dept_stmt, "i", $id);
                    mysqli_stmt_execute($check_dept_stmt);
                    $dept_result = mysqli_stmt_get_result($check_dept_stmt);
                    $dept_data = mysqli_fetch_assoc($dept_result);
                    
                    if (!$dept_data || $dept_data['department_id'] != $_SESSION['department_id']) {
                        $_SESSION['error'] = "You don't have permission to delete this exam schedule.";
                        $redirect = true;
                        break;
                    }
                }

                $query = "DELETE FROM exam_timetable WHERE id = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "i", $id);

                if (mysqli_stmt_execute($stmt)) {
                    $_SESSION['success'] = "Exam schedule deleted successfully";
                } else {
                    $_SESSION['error'] = "Error deleting exam schedule: " . mysqli_error($conn);
                }
                $redirect = true;
                break;
        }
    }
}

// If redirect is needed, use JavaScript
if ($redirect): ?>
<script>
    window.location.href = 'manage_exam_timetable.php';
</script>
<?php
    exit();
endif;

// Fetch academic years for dropdown
$academic_years_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);

// Fetch departments for dropdown - department admins only see their department
if (is_super_admin()) {
    $dept_query = "SELECT id, name FROM departments ORDER BY name";
    $departments = mysqli_query($conn, $dept_query);
} else {
    $dept_query = "SELECT id, name FROM departments WHERE id = ? ORDER BY name";
    $stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
    mysqli_stmt_execute($stmt);
    $departments = mysqli_stmt_get_result($stmt);
}

// Fetch exam timetable with department filtering
$timetable_query = "SELECT et.*, ay.year_range, s.name as subject_name, s.code as subject_code, d.name as department_name
                   FROM exam_timetable et 
                   JOIN academic_years ay ON et.academic_year_id = ay.id 
                   JOIN subjects s ON et.subject_id = s.id
                   JOIN departments d ON s.department_id = d.id
                   WHERE 1=1" . $department_filter . "
                   ORDER BY et.exam_date DESC, et.exam_session";

if (!empty($department_params)) {
    $stmt = mysqli_prepare($conn, $timetable_query);
    mysqli_stmt_bind_param($stmt, "i", ...$department_params);
    mysqli_stmt_execute($stmt);
    $timetable_result = mysqli_stmt_get_result($stmt);
} else {
    $timetable_result = mysqli_query($conn, $timetable_query);
}

// Function to get subjects for a specific semester
function getSubjectsForSemester($conn, $semester) {
    $department_filter = "";
    $params = [$semester];
    
    if (!is_super_admin() && isset($_SESSION['department_id'])) {
        $department_filter = " AND s.department_id = ?";
        $params[] = $_SESSION['department_id'];
    }
    
    $query = "SELECT DISTINCT s.id, s.name, s.code 
              FROM subjects s
              JOIN subject_assignments sa ON s.id = sa.subject_id
              WHERE sa.semester = ?" . $department_filter . "
              ORDER BY s.code";
              
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, str_repeat('i', count($params)), ...$params);
    mysqli_stmt_execute($stmt);
    return mysqli_stmt_get_result($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Exam Timetable - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --header-height: 90px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            min-height: 100vh;
            padding-top: var(--header-height);
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }

        .dashboard-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .dashboard-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .table-responsive {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.5rem;
        }

        .table th {
            padding: 1rem;
            text-align: left;
            color: var(--text-color);
            font-weight: 600;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            border-radius: 10px;
        }

        .table td {
            padding: 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            border-radius: 10px;
            color: var(--text-color);
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-action {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .modal-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-family: inherit;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
        }

        .badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: var(--inner-shadow);
        }

        .badge-success {
            background: #d4edda;
            color: #155724;
        }

        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Exam Timetable</h1>
            <button class="btn" onclick="showAddModal()">
                <i class="fas fa-plus"></i> Add Exam Schedule
            </button>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Academic Year</th>
                        <th>Semester</th>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Department</th>
                        <th>Exam Date</th>
                        <th>Session</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($timetable_result)): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['year_range']); ?></td>
                            <td><?php echo $row['semester']; ?></td>
                            <td><?php echo htmlspecialchars($row['subject_code']); ?></td>
                            <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['department_name']); ?></td>
                            <td><?php echo date('d M Y', strtotime($row['exam_date'])); ?></td>
                            <td><?php echo htmlspecialchars($row['exam_session']); ?></td>
                            <td>
                                <span class="badge <?php echo $row['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $row['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="action-buttons">
                                <button class="btn-action" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-action" onclick="confirmDelete(<?php echo $row['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add Exam Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add Exam Schedule</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-group">
                    <label>Academic Year</label>
                    <select class="form-control" name="academic_year_id" required>
                        <option value="">Select Academic Year</option>
                        <?php 
                        mysqli_data_seek($academic_years_result, 0);
                        while ($year = mysqli_fetch_assoc($academic_years_result)): 
                        ?>
                            <option value="<?php echo $year['id']; ?>">
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Semester</label>
                    <select class="form-control" name="semester" id="add_semester" required>
                        <option value="">Select Semester</option>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Subject</label>
                    <select class="form-control" name="subject_id" id="add_subject" required>
                        <option value="">Select Subject</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Exam Date</label>
                    <input type="date" class="form-control" name="exam_date" required>
                </div>

                <div class="form-group">
                    <label>Session</label>
                    <select class="form-control" name="exam_session" id="add_session" required>
                        <option value="Morning">Fore Noon (10:00 AM - 1:00 PM)</option>
                        <option value="Afternoon">After Noon (2:00 PM - 5:00 PM)</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Add Schedule</button>
                    <button type="button" class="btn" onclick="hideModal('addModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Exam Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Exam Schedule</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                
                <div class="form-group">
                    <label>Academic Year</label>
                    <select class="form-control" name="academic_year_id" id="edit_academic_year" required>
                        <?php 
                        mysqli_data_seek($academic_years_result, 0);
                        while ($year = mysqli_fetch_assoc($academic_years_result)): 
                        ?>
                            <option value="<?php echo $year['id']; ?>">
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Semester</label>
                    <select class="form-control" name="semester" id="edit_semester" required>
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Subject</label>
                    <select class="form-control" name="subject_id" id="edit_subject" required>
                        <option value="">Select Subject</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Exam Date</label>
                    <input type="date" class="form-control" name="exam_date" id="edit_date" required>
                </div>

                <div class="form-group">
                    <label>Session</label>
                    <select class="form-control" name="exam_session" id="edit_session" required>
                        <option value="Morning">Fore Noon (10:00 AM - 1:00 PM)</option>
                        <option value="Afternoon">After Noon (2:00 PM - 5:00 PM)</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn">Update Schedule</button>
                    <button type="button" class="btn" onclick="hideModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete this exam schedule?</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="delete_id">
                <div class="form-group">
                    <button type="submit" class="btn">Delete</button>
                    <button type="button" class="btn" onclick="hideModal('deleteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Function to show modal
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }

        // Function to hide modal
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Function to show add modal
        function showAddModal() {
            showModal('addModal');
        }

        // Function to show edit modal
        function showEditModal(data) {
            document.getElementById('edit_id').value = data.id;
            document.getElementById('edit_academic_year').value = data.academic_year_id;
            document.getElementById('edit_semester').value = data.semester;
            document.getElementById('edit_date').value = data.exam_date;
            document.getElementById('edit_session').value = data.exam_session;
            
            // Load subjects for the selected semester
            loadSubjects(data.semester, $('#edit_subject')).then(() => {
                document.getElementById('edit_subject').value = data.subject_id;
            });
            
            showModal('editModal');
        }

        // Function to show delete confirmation
        function confirmDelete(id) {
            document.getElementById('delete_id').value = id;
            showModal('deleteModal');
        }

        // Function to load subjects based on semester
        function loadSubjects(semester, targetSelect) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: 'get_subjects.php',
                    method: 'POST',
                    data: { 
                        semester: semester,
                        department_id: <?php echo isset($_SESSION['department_id']) ? $_SESSION['department_id'] : 'null'; ?>,
                        is_super_admin: <?php echo is_super_admin() ? 'true' : 'false'; ?>
                    },
                    success: function(response) {
                        targetSelect.html(response);
                        resolve();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading subjects:', error);
                        reject(error);
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Handle semester change for Add modal
            $('#add_semester').change(function() {
                loadSubjects($(this).val(), $('#add_subject'));
            });

            // Handle semester change for Edit modal
            $('#edit_semester').change(function() {
                loadSubjects($(this).val(), $('#edit_subject'));
            });

            // Close modals when clicking outside
            window.onclick = function(event) {
                if (event.target.classList.contains('modal')) {
                    event.target.style.display = 'none';
                }
            }
        });
    </script>
</body>
</html>
