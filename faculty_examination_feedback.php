<?php
session_start();
include 'db_connection.php';
include 'functions.php';

// Check if user is logged in and is a faculty
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'faculty') {
    header('Location: index.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
$faculty_name = $_SESSION['name'] ?? 'Faculty Member';
$current_academic_year = get_current_academic_year($conn);

// Get active academic years for filtering
$academic_years_query = "SELECT * FROM academic_years ORDER BY start_date DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);

// Get all semesters for filtering
$semesters = range(1, 8);

// Default filters
$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year['id'];
$selected_semester = $_GET['semester'] ?? '';
$selected_section = $_GET['section'] ?? '';

// Get subject assignments for this faculty
$query = "SELECT sa.id as assignment_id, sa.year, sa.semester, sa.section, 
          s.id as subject_id, s.code as subject_code, s.name as subject_name,
          d.name as department_name, ay.year_range as academic_year
          FROM subject_assignments sa
          JOIN subjects s ON sa.subject_id = s.id
          JOIN departments d ON s.department_id = d.id
          JOIN academic_years ay ON sa.academic_year_id = ay.id
          INNER JOIN exam_timetable et ON s.id = et.subject_id AND sa.semester = et.semester
          WHERE sa.faculty_id = ? AND sa.is_active = TRUE AND et.is_active = TRUE";

$params = [$faculty_id];
$types = "i";

if (!empty($selected_academic_year)) {
    $query .= " AND sa.academic_year_id = ?";
    $params[] = $selected_academic_year;
    $types .= "i";
}

if (!empty($selected_semester)) {
    $query .= " AND sa.semester = ?";
    $params[] = $selected_semester;
    $types .= "i";
}

if (!empty($selected_section)) {
    $query .= " AND sa.section = ?";
    $params[] = $selected_section;
    $types .= "s";
}

$query .= " ORDER BY ay.year_range DESC, sa.year ASC, sa.semester ASC, sa.section ASC";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$subjects_result = mysqli_stmt_get_result($stmt);

// Get unique sections for filtering
$sections_query = "SELECT DISTINCT section FROM subject_assignments WHERE faculty_id = ? ORDER BY section";
$sections_stmt = mysqli_prepare($conn, $sections_query);
mysqli_stmt_bind_param($sections_stmt, "i", $faculty_id);
mysqli_stmt_execute($sections_stmt);
$sections_result = mysqli_stmt_get_result($sections_stmt);

// Get faculty details
$faculty_query = "SELECT f.*, d.name AS department_name 
                 FROM faculty f
                 JOIN departments d ON f.department_id = d.id
                 WHERE f.id = ?";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $faculty_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_result = mysqli_stmt_get_result($faculty_stmt);
$faculty = mysqli_fetch_assoc($faculty_result);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Feedback - Faculty Dashboard</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
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
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        /* Faculty Header Section */
        .faculty-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .profile-section {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .profile-image {
            width: 120px;
            height: 120px;
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow);
        }

        .profile-image i {
            font-size: 4rem;
            color: white;
        }

        .profile-info h1 {
            font-size: 2.4rem;
            color: var(--text-color);
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .faculty-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 50px;
            box-shadow: var(--inner-shadow);
            font-size: 0.95rem;
            color: var(--text-color);
        }

        .meta-item i {
            color: var(--primary-color);
        }

        /* Filters Section */
        .filters-section {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filters-section h2 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .filters-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1.2rem;
            border: none;
            background: var(--bg-color);
            border-radius: 50px;
            color: var(--text-color);
            font-size: 1rem;
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            box-shadow: var(--shadow);
        }

        .btn:hover {
            transform: translateY(-3px);
        }

        .btn-primary {
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
        }

        .btn-secondary {
            background: linear-gradient(45deg, #7f8c8d, #95a5a6);
        }

        .btn-outline {
            background: var(--bg-color);
            color: var(--text-color);
            border: 1px solid var(--text-color);
        }

        /* Subjects Table */
        .subjects-section {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .subjects-section h2 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .subjects-section h2 i {
            color: var(--primary-color);
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
            background: var(--bg-color);
            padding: 1rem;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 0.8rem;
        }

        thead th {
            padding: 1rem;
            color: var(--text-color);
            font-weight: 600;
            font-size: 0.95rem;
            text-align: left;
        }

        tbody tr {
            background: rgba(255, 255, 255, 0.4);
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        tbody tr:hover {
            transform: translateY(-3px);
        }

        tbody td {
            padding: 1rem;
            color: var(--text-color);
            font-size: 0.95rem;
            border-top: 1px solid rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        tbody td:first-child {
            border-left: 1px solid rgba(0, 0, 0, 0.05);
            border-top-left-radius: 15px;
            border-bottom-left-radius: 15px;
        }

        tbody td:last-child {
            border-right: 1px solid rgba(0, 0, 0, 0.05);
            border-top-right-radius: 15px;
            border-bottom-right-radius: 15px;
        }

        .badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            color: white;
        }

        .badge-primary {
            background: var(--primary-color);
        }

        .badge-secondary {
            background: var(--secondary-color);
        }

        .badge-warning {
            background: var(--warning-color);
            color: var(--text-color);
        }

        .badge-danger {
            background: var(--danger-color);
        }

        .exam-info {
            font-size: 0.85rem;
            padding: 8px 12px;
            margin-top: 5px;
            background: rgba(255, 255, 255, 0.4);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            border-left: 3px solid var(--primary-color);
        }

        .exam-date {
            font-weight: 500;
            color: var(--text-color);
        }

        .exam-session {
            font-weight: 500;
            color: var(--primary-color);
        }

        .exam-time {
            color: #666;
        }

        .progress-container {
            width: 100%;
            height: 10px;
            background: var(--bg-color);
            border-radius: 5px;
            overflow: hidden;
            box-shadow: var(--inner-shadow);
        }

        .progress-bar {
            height: 100%;
            border-radius: 5px;
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
        }

        .rating-value {
            font-weight: 600;
            margin-right: 10px;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: white;
            background: linear-gradient(45deg, var(--primary-color), #2980b9);
            box-shadow: var(--shadow);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }

        .text-muted {
            color: #7f8c8d;
        }

        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .empty-state i {
            font-size: 3.5rem;
            color: #bdc3c7;
            margin-bottom: 1.5rem;
        }

        .empty-state h4 {
            font-size: 1.5rem;
            color: var(--text-color);
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: #7f8c8d;
            max-width: 500px;
            margin: 0 auto;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .profile-section {
                flex-direction: column;
                text-align: center;
            }

            .profile-image {
                margin: 0 auto;
            }

            .faculty-meta {
                justify-content: center;
            }

            .filters-form {
                grid-template-columns: 1fr;
            }

            .btn-group {
                flex-direction: column;
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
        <!-- Faculty Header -->
        <div class="faculty-header">
            <div class="profile-section">
                <div class="profile-image">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="profile-info">
                    <h1><?php echo htmlspecialchars($faculty['name'] ?? $faculty_name); ?></h1>
                    <div class="faculty-meta">
                        <span class="meta-item">
                            <i class="fas fa-id-badge"></i>
                            Faculty ID: <?php echo htmlspecialchars($faculty['faculty_id'] ?? 'N/A'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-user-tie"></i>
                            <?php echo htmlspecialchars($faculty['designation'] ?? 'Faculty'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-building"></i>
                            <?php echo htmlspecialchars($faculty['department_name'] ?? 'Department'); ?>
                        </span>
                        <span class="meta-item">
                            <i class="fas fa-clipboard-check"></i>
                            Examination Feedback
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters Section -->
        <div class="filters-section">
            <h2>Filter Options</h2>
            <form method="GET" action="" class="filters-form">
                <div class="form-group">
                    <label for="academic_year">Academic Year</label>
                    <select name="academic_year" id="academic_year" class="form-control">
                        <option value="">All Academic Years</option>
                        <?php mysqli_data_seek($academic_years_result, 0); ?>
                        <?php while ($year = mysqli_fetch_assoc($academic_years_result)): ?>
                            <option value="<?php echo $year['id']; ?>" <?php echo $selected_academic_year == $year['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="semester">Semester</label>
                    <select name="semester" id="semester" class="form-control">
                        <option value="">All Semesters</option>
                        <?php foreach ($semesters as $semester): ?>
                            <option value="<?php echo $semester; ?>" <?php echo $selected_semester == $semester ? 'selected' : ''; ?>>
                                Semester <?php echo $semester; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="section">Section</label>
                    <select name="section" id="section" class="form-control">
                        <option value="">All Sections</option>
                        <?php mysqli_data_seek($sections_result, 0); ?>
                        <?php while ($section = mysqli_fetch_assoc($sections_result)): ?>
                            <option value="<?php echo htmlspecialchars($section['section']); ?>" <?php echo $selected_section == $section['section'] ? 'selected' : ''; ?>>
                                Section <?php echo htmlspecialchars($section['section']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Apply Filters
                    </button>
                    <a href="faculty_examination_feedback.php" class="btn btn-secondary">
                        <i class="fas fa-sync-alt"></i> Reset Filters
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Subjects Table -->
        <div class="subjects-section">
            <h2><i class="fas fa-book"></i> Examination Feedback for Your Subjects</h2>
            
            <?php if (mysqli_num_rows($subjects_result) > 0): ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Department</th>
                                <th>Year/Semester</th>
                                <th>Section</th>
                                <th>Academic Year</th>
                                <th>Exam Details</th>
                                <th>Feedback Count</th>
                                <th>Average Rating</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($subject = mysqli_fetch_assoc($subjects_result)): 
                                // Get feedback count for this subject assignment
                                $feedback_count_query = "SELECT COUNT(*) as count FROM examination_feedback 
                                                       WHERE subject_assignment_id = ?";
                                $feedback_count_stmt = mysqli_prepare($conn, $feedback_count_query);
                                mysqli_stmt_bind_param($feedback_count_stmt, "i", $subject['assignment_id']);
                                mysqli_stmt_execute($feedback_count_stmt);
                                $feedback_count_result = mysqli_stmt_get_result($feedback_count_stmt);
                                $feedback_count = mysqli_fetch_assoc($feedback_count_result)['count'];
                                
                                // Get average rating
                                $avg_rating_query = "SELECT AVG(cumulative_avg) as avg_rating FROM examination_feedback 
                                                   WHERE subject_assignment_id = ?";
                                $avg_rating_stmt = mysqli_prepare($conn, $avg_rating_query);
                                mysqli_stmt_bind_param($avg_rating_stmt, "i", $subject['assignment_id']);
                                mysqli_stmt_execute($avg_rating_stmt);
                                $avg_rating_result = mysqli_stmt_get_result($avg_rating_stmt);
                                $avg_rating = mysqli_fetch_assoc($avg_rating_result)['avg_rating'] ?? 0;
                                $avg_rating = number_format($avg_rating, 2);
                                
                                // Get exam details from exam_timetable
                                $exam_query = "SELECT et.id, et.exam_date, et.exam_session, et.start_time, et.end_time 
                                              FROM exam_timetable et
                                              WHERE et.subject_id = ? AND et.semester = ? AND et.is_active = TRUE
                                              ORDER BY et.exam_date DESC
                                              LIMIT 2";
                                $exam_stmt = mysqli_prepare($conn, $exam_query);
                                mysqli_stmt_bind_param($exam_stmt, "ii", $subject['subject_id'], $subject['semester']);
                                mysqli_stmt_execute($exam_stmt);
                                $exam_result = mysqli_stmt_get_result($exam_stmt);
                            ?>
                            <tr>
                                <td>
                                    <span class="badge badge-primary"><?php echo htmlspecialchars($subject['subject_code']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($subject['department_name']); ?></td>
                                <td>Year <?php echo htmlspecialchars($subject['year']); ?> / Semester <?php echo htmlspecialchars($subject['semester']); ?></td>
                                <td>Section <?php echo htmlspecialchars($subject['section']); ?></td>
                                <td><?php echo htmlspecialchars($subject['academic_year']); ?></td>
                                <td>
                                    <?php while ($exam = mysqli_fetch_assoc($exam_result)): ?>
                                        <div class="exam-info">
                                            <span class="exam-date"><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></span>
                                            <span class="exam-session">(<?php echo $exam['exam_session']; ?> Session)</span><br>
                                            <span class="exam-time"><?php echo date('h:i A', strtotime($exam['start_time'])); ?> - <?php echo date('h:i A', strtotime($exam['end_time'])); ?></span>
                                        </div>
                                    <?php endwhile; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?php echo $feedback_count; ?></span>
                                </td>
                                <td>
                                    <?php if ($feedback_count > 0): ?>
                                        <div class="d-flex align-items-center">
                                            <span class="rating-value"><?php echo $avg_rating; ?></span>
                                            <div class="progress-container">
                                                <div class="progress-bar" style="width: <?php echo $avg_rating * 20; ?>%;"></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">No feedback</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($feedback_count > 0): ?>
                                        <a href="faculty_examination_feedback_details.php?assignment_id=<?php echo $subject['assignment_id']; ?>" 
                                           class="action-btn">
                                           <i class="fas fa-chart-bar"></i> View Details
                                        </a>
                                    <?php else: ?>
                                        <button class="action-btn" disabled>
                                            <i class="fas fa-chart-bar"></i> No Feedback
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h4>No Subjects with Scheduled Exams Found</h4>
                    <p>There are no subjects with scheduled exams matching your selected filters.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Add any JavaScript functionality needed here
    </script>
</body>
</html> 