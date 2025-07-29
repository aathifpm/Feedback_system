<?php
session_start();
require_once 'functions.php';
require_once 'db_connection.php';

// Check if faculty is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'faculty' && $_SESSION['role'] !== 'admin')) {
    header('Location: faculty_login.php');
    exit();
}

$faculty_id = $_SESSION['user_id'];
// Check if name is available in session, otherwise use a default
$faculty_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Faculty User';
$department_id = $_SESSION['department_id'];
$error = '';
$success = '';

// Get current academic year
$query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$result = mysqli_query($conn, $query);
$academic_year = mysqli_fetch_assoc($result);
$academic_year_id = $academic_year['id'];

// Initialize variables
$selected_batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;
$selected_session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Add sort parameters
$sessions_sort_column = isset($_GET['sessions_sort']) ? $_GET['sessions_sort'] : 'session_date';
$sessions_sort_direction = isset($_GET['sessions_dir']) ? $_GET['sessions_dir'] : 'DESC';
$students_sort_column = isset($_GET['students_sort']) ? $_GET['students_sort'] : 'roll_number';
$students_sort_direction = isset($_GET['students_dir']) ? $_GET['students_dir'] : 'ASC';

// Validate sort parameters
$allowed_sessions_columns = ['session_date', 'start_time', 'topic', 'venue_name', 'attendance_count'];
$allowed_students_columns = ['roll_number', 'student_name', 'status', 'marked_at', 'marked_by_name'];

if (!in_array($sessions_sort_column, $allowed_sessions_columns)) {
    $sessions_sort_column = 'session_date';
}
if (!in_array($students_sort_column, $allowed_students_columns)) {
    $students_sort_column = 'roll_number';
}

$sessions_sort_direction = ($sessions_sort_direction === 'ASC') ? 'ASC' : 'DESC';
$students_sort_direction = ($students_sort_direction === 'DESC') ? 'DESC' : 'ASC';

// Get training batches for faculty's department
$batches_query = "SELECT 
                    tb.id,
                    tb.batch_name,
                    tb.description,
                    ay.year_range AS academic_year,
                    d.name AS department_name,
                    COUNT(DISTINCT stb.student_id) AS student_count
                FROM 
                    training_batches tb
                JOIN 
                    academic_years ay ON tb.academic_year_id = ay.id
                JOIN 
                    departments d ON tb.department_id = d.id
                LEFT JOIN 
                    student_training_batch stb ON tb.id = stb.training_batch_id AND stb.is_active = TRUE
                WHERE 
                    tb.department_id = ? AND tb.is_active = TRUE
                GROUP BY 
                    tb.id
                ORDER BY 
                    tb.batch_name";
                
$stmt = mysqli_prepare($conn, $batches_query);
mysqli_stmt_bind_param($stmt, "i", $department_id);
mysqli_stmt_execute($stmt);
$batches_result = mysqli_stmt_get_result($stmt);
$training_batches = [];

while ($batch = mysqli_fetch_assoc($batches_result)) {
    $training_batches[] = $batch;
}

// Variables to hold view data
$sessions = [];
$attendance_data = [];
$students = [];
$batch_details = null;
$session_details = null;

// If a batch is selected, get its details and sessions
if ($selected_batch_id > 0) {
    // Get batch details
    $batch_query = "SELECT 
                        tb.id,
                        tb.batch_name,
                        tb.description,
                        ay.year_range AS academic_year,
                        ay.id AS academic_year_id,
                        d.name AS department_name,
                        d.id AS department_id,
                        COUNT(DISTINCT stb.student_id) AS student_count
                    FROM 
                        training_batches tb
                    JOIN 
                        academic_years ay ON tb.academic_year_id = ay.id
                    JOIN 
                        departments d ON tb.department_id = d.id
                    LEFT JOIN 
                        student_training_batch stb ON tb.id = stb.training_batch_id AND stb.is_active = TRUE
                    WHERE 
                        tb.id = ? AND tb.department_id = ?
                    GROUP BY 
                        tb.id";
    
    $stmt = mysqli_prepare($conn, $batch_query);
    mysqli_stmt_bind_param($stmt, "ii", $selected_batch_id, $department_id);
    mysqli_stmt_execute($stmt);
    $batch_result = mysqli_stmt_get_result($stmt);
    $batch_details = mysqli_fetch_assoc($batch_result);
    
    // If batch exists and belongs to faculty's department
    if ($batch_details) {
        // Get training sessions for this batch within date range with custom sorting
        $sessions_query = "SELECT 
                            tss.id,
                            tss.session_date,
                            tss.start_time,
                            tss.end_time,
                            tss.topic,
                            tss.trainer_name,
                            v.name AS venue_name,
                            v.room_number,
                            COUNT(DISTINCT tar.student_id) AS attendance_count,
                            SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                            SUM(CASE WHEN tar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                            SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                            SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
                          FROM 
                            training_session_schedule tss
                          JOIN 
                            venues v ON tss.venue_id = v.id
                          LEFT JOIN 
                            training_attendance_records tar ON tss.id = tar.session_id
                          WHERE 
                            tss.training_batch_id = ? 
                            AND tss.session_date BETWEEN ? AND ?
                            AND tss.is_cancelled = FALSE
                          GROUP BY 
                            tss.id
                          ORDER BY 
                            $sessions_sort_column $sessions_sort_direction,
                            tss.session_date DESC,
                            tss.start_time DESC";
        
        $stmt = mysqli_prepare($conn, $sessions_query);
        mysqli_stmt_bind_param($stmt, "iss", $selected_batch_id, $date_from, $date_to);
        mysqli_stmt_execute($stmt);
        $sessions_result = mysqli_stmt_get_result($stmt);
        
        while ($session = mysqli_fetch_assoc($sessions_result)) {
            $sessions[] = $session;
        }
        
        // Get overall attendance statistics for this batch
        $stats_query = "SELECT 
                            COUNT(DISTINCT tss.id) AS total_sessions,
                            COUNT(DISTINCT stb.student_id) AS total_students,
                            COUNT(DISTINCT tar.id) AS total_attendance_records,
                            SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS total_present,
                            SUM(CASE WHEN tar.status = 'absent' THEN 1 ELSE 0 END) AS total_absent,
                            SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) AS total_late,
                            SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) AS total_excused
                        FROM 
                            training_batches tb
                        JOIN 
                            training_session_schedule tss ON tb.id = tss.training_batch_id
                        JOIN 
                            student_training_batch stb ON tb.id = stb.training_batch_id
                        LEFT JOIN 
                            training_attendance_records tar ON tss.id = tar.session_id AND stb.student_id = tar.student_id
                        WHERE 
                            tb.id = ?
                            AND tss.session_date BETWEEN ? AND ?
                            AND stb.is_active = TRUE
                            AND tss.is_cancelled = FALSE";
        
        $stmt = mysqli_prepare($conn, $stats_query);
        mysqli_stmt_bind_param($stmt, "iss", $selected_batch_id, $date_from, $date_to);
        mysqli_stmt_execute($stmt);
        $stats_result = mysqli_stmt_get_result($stmt);
        $attendance_stats = mysqli_fetch_assoc($stats_result);
        
        // If a specific session is selected, get its details and student attendance
        if ($selected_session_id > 0) {
            // Get session details
            $session_query = "SELECT 
                                tss.id,
                                tss.session_date,
                                tss.start_time,
                                tss.end_time,
                                tss.topic,
                                tss.trainer_name,
                                v.name AS venue_name,
                                v.room_number
                              FROM 
                                training_session_schedule tss
                              JOIN 
                                venues v ON tss.venue_id = v.id
                              WHERE 
                                tss.id = ? AND tss.training_batch_id = ?";
            
            $stmt = mysqli_prepare($conn, $session_query);
            mysqli_stmt_bind_param($stmt, "ii", $selected_session_id, $selected_batch_id);
            mysqli_stmt_execute($stmt);
            $session_result = mysqli_stmt_get_result($stmt);
            $session_details = mysqli_fetch_assoc($session_result);
            
            if ($session_details) {
                // Set pagination variables
                $students_per_page = 20; // Number of students per page
                $current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
                if ($current_page < 1) $current_page = 1;
                $offset = ($current_page - 1) * $students_per_page;
                
                // Get total count of students for pagination
                $count_query = "SELECT 
                                  COUNT(*) as total_students
                                FROM 
                                  students s
                                JOIN 
                                  student_training_batch stb ON s.id = stb.student_id
                                WHERE 
                                  stb.training_batch_id = ? AND stb.is_active = TRUE";
                
                $stmt = mysqli_prepare($conn, $count_query);
                mysqli_stmt_bind_param($stmt, "i", $selected_batch_id);
                mysqli_stmt_execute($stmt);
                $count_result = mysqli_stmt_get_result($stmt);
                $count_row = mysqli_fetch_assoc($count_result);
                $total_students = $count_row['total_students'];
                $total_pages = ceil($total_students / $students_per_page);
                
                // If current page is greater than total pages, reset to first page
                if ($current_page > $total_pages && $total_pages > 0) {
                    $current_page = 1;
                    $offset = 0;
                }
                
                // Get student attendance for this session with pagination and custom sorting
                $students_query = "SELECT 
                                    s.id AS student_id,
                                    s.roll_number,
                                    s.register_number,
                                    s.name AS student_name,
                                    s.email,
                                    tar.status,
                                    tar.created_at AS marked_at,
                                    f.name AS marked_by_name
                                  FROM 
                                    students s
                                  JOIN 
                                    student_training_batch stb ON s.id = stb.student_id
                                  LEFT JOIN 
                                    training_attendance_records tar ON s.id = tar.student_id AND tar.session_id = ?
                                  LEFT JOIN 
                                    faculty f ON tar.marked_by = f.id
                                  WHERE 
                                    stb.training_batch_id = ? AND stb.is_active = TRUE";
                
                // Add status filter if specified
                if (!empty($status_filter)) {
                    $students_query .= " AND (tar.status = ? OR (tar.status IS NULL AND ? = 'absent'))";
                }
                
                // Add ORDER BY and LIMIT for pagination
                $students_query .= " ORDER BY $students_sort_column $students_sort_direction LIMIT ?, ?";
                
                if (!empty($status_filter)) {
                    $stmt = mysqli_prepare($conn, $students_query);
                    mysqli_stmt_bind_param($stmt, "iissii", $selected_session_id, $selected_batch_id, $status_filter, $status_filter, $offset, $students_per_page);
                } else {
                    $stmt = mysqli_prepare($conn, $students_query);
                    mysqli_stmt_bind_param($stmt, "iiii", $selected_session_id, $selected_batch_id, $offset, $students_per_page);
                }
                
                mysqli_stmt_execute($stmt);
                $students_result = mysqli_stmt_get_result($stmt);
                
                while ($student = mysqli_fetch_assoc($students_result)) {
                    // Default to absent if no attendance record
                    if ($student['status'] === null) {
                        $student['status'] = 'absent';
                        $student['marked_at'] = null;
                        $student['marked_by_name'] = null;
                    }
                    $students[] = $student;
                }
            }
        }
    } else {
        $error = "Invalid batch selected or you don't have permission to view this batch.";
        $selected_batch_id = 0;
    }
}

?>

<?php
// Set page title before including header
$pageTitle = "View Training Attendance";
include 'header.php';
// Add Chart.js - required for this page
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <style>
        :root {
            --primary-color: #3498db;
            --primary-dark: #2980b9;
            --text-color: #2c3e50;
            --text-light: #7f8c8d;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --success-color: #2ecc71;
            --error-color: #e74c3c;
            --warning-color: #f39c12;
            --info-color: #3498db;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
            padding-bottom: 0.5rem;
            font-size: 16px;
        }

        .custom-header {
            width: 100%;
            padding: 1rem;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 1rem;
            border-radius: 15px;
            max-width: 1200px;
        }

        .custom-header h1 {
            font-size: clamp(1.2rem, 4vw, 1.8rem);
            color: var(--text-color);
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .custom-header p {
            color: var(--text-light);
            line-height: 1.4;
            font-size: clamp(0.85rem, 3vw, 1rem);
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0.5rem;
        }

        .card {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: clamp(1rem, 3vw, 2rem);
            margin-bottom: 1.5rem;
            transition: var(--transition);
            width: 100%;
        }

        .card:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                      -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .card-title {
            font-size: clamp(1.1rem, 4vw, 1.5rem);
            color: var(--text-color);
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
            font-size: clamp(0.85rem, 3vw, 0.95rem);
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1.1rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: clamp(0.85rem, 3vw, 1rem);
            color: var(--text-color);
            transition: var(--transition);
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .btn {
            padding: 0.7rem 1.25rem;
            border: none;
            border-radius: 50px;
            background: var(--primary-color);
            color: white;
            font-size: clamp(0.85rem, 3vw, 1rem);
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px; /* Minimum touch target size */
            margin: 0.25rem;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-3px);
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success-color);
        }

        .btn-success:hover {
            background: #27ae60;
        }

        .btn-warning {
            background: var(--warning-color);
        }

        .btn-warning:hover {
            background: #e67e22;
        }

        .btn-danger {
            background: var(--error-color);
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-info {
            background: var(--info-color);
        }

        .btn-info:hover {
            background: #2980b9;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }

        .btn-outline:hover {
            background: var(--primary-color);
            color: white;
        }

        .table-container {
            overflow-x: auto;
            margin-bottom: 1.25rem;
            border-radius: 10px;
            box-shadow: var(--shadow);
            width: 100%;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--bg-color);
            overflow: hidden;
            min-width: 600px; /* Ensures table doesn't get too cramped */
        }

        .table th, .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            font-size: clamp(0.75rem, 2.5vw, 0.9rem);
        }

        .table th {
            background: rgba(52, 152, 219, 0.1);
            font-weight: 600;
            color: var(--text-color);
        }

        .table tbody tr:hover {
            background: rgba(52, 152, 219, 0.05);
        }

        .table tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            font-size: clamp(0.7rem, 2.5vw, 0.8rem);
            font-weight: 500;
            display: inline-block;
        }

        .badge-success {
            background: rgba(46, 204, 113, 0.2);
            color: #27ae60;
        }

        .badge-danger {
            background: rgba(231, 76, 60, 0.2);
            color: #c0392b;
        }

        .badge-warning {
            background: rgba(243, 156, 18, 0.2);
            color: #e67e22;
        }

        .badge-info {
            background: rgba(52, 152, 219, 0.2);
            color: #2980b9;
        }

        .badge-count {
            background: var(--primary-color);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 50px;
            font-size: 0.7rem;
            margin-left: 0.5rem;
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .filter-item {
            flex: 1;
            min-width: 200px;
        }

        .batch-card {
            cursor: pointer;
            transition: var(--transition);
            border-left: 4px solid transparent;
        }

        .batch-card.active {
            border-left: 4px solid var(--primary-color);
            transform: translateX(5px);
        }

        .batch-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .batch-title {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1.1rem;
        }

        .batch-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: 1.5rem;
            text-align: center;
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .present {
            background: rgba(46, 204, 113, 0.1);
        }
        
        .absent {
            background: rgba(231, 76, 60, 0.1);
        }
        
        .late {
            background: rgba(243, 156, 18, 0.1);
        }
        
        .excused {
            background: rgba(52, 152, 219, 0.1);
        }

        .search-box {
            position: relative;
            margin-bottom: 1.25rem;
        }

        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: none;
            border-radius: 50px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 0.9rem;
            color: var(--text-color);
        }

        .search-input:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .floating-back {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary-color);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
            text-decoration: none;
            transition: all 0.3s;
            z-index: 1000;
        }

        .floating-back:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(52, 152, 219, 0.5);
        }

        .nav-user {
            background: rgba(52, 152, 219, 0.1);
            padding: 0.4rem 0.8rem;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-color);
            font-size: clamp(0.8rem, 3vw, 0.9rem);
            margin-bottom: 0.5rem;
        }

        .nav-user i {
            color: var(--primary-color);
        }

        .progress-container {
            width: 100%;
            height: 12px;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(to right, #3498db, #2ecc71);
            transition: width 0.5s ease;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
        }

        .indicator-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .indicator-present {
            background-color: #2ecc71;
        }

        .indicator-absent {
            background-color: #e74c3c;
        }

        .indicator-late {
            background-color: #f39c12;
        }

        .indicator-excused {
            background-color: #3498db;
        }

        /* Chart containers */
        .chart-container {
            width: 100%;
            margin: 1.5rem 0;
            padding: 1rem;
            background-color: white;
            border-radius: 15px;
            box-shadow: var(--shadow);
            position: relative;
            height: 300px;
            overflow: hidden;
        }
        
        .chart-loader {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1;
        }

        .chart-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 1.5rem;
        }

        .chart-col {
            flex: 1 1 300px;
            min-width: 300px;
        }

        .chart-title {
            text-align: center;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            color: var(--text-color);
            font-weight: 600;
        }

        .chart-filters {
            display: flex;
            justify-content: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .chart-filter-btn {
            background: var(--bg-color);
            border: none;
            border-radius: 20px;
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .chart-filter-btn:hover,
        .chart-filter-btn.active {
            background: var(--primary-color);
            color: white;
        }

        @media (max-width: 768px) {
            .filters {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .filter-item {
                width: 100%;
            }
            
            .stats-container {
                grid-template-columns: 1fr 1fr;
            }

            .chart-container {
                height: 250px;
            }
        }

        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .batch-meta {
                flex-direction: column;
                gap: 0.25rem;
            }

            .chart-container {
                height: 200px;
            }

            .chart-row {
                flex-direction: column;
            }
        }

        /* Hide charts when printing */
        @media print {
            .chart-container, .chart-row {
                display: none;
            }
        }

        /* Add sorting styles */
        .sortable {
            cursor: pointer;
            position: relative;
            padding-right: 18px !important;
        }
        
        .sortable:hover {
            background-color: rgba(52, 152, 219, 0.2);
        }
        
        .sortable::after {
            content: "↕";
            position: absolute;
            right: 5px;
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .sortable.asc::after {
            content: "↓";
            color: var(--primary-color);
        }
        
        .sortable.desc::after {
            content: "↑";
            color: var(--primary-color);
        }
    </style>
</head>
<body>
    <div class="custom-header">
        <h1>Training Attendance Records</h1>
        <p>Welcome, <?php echo htmlspecialchars($faculty_name); ?></p>
    </div>

    <div class="container">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Batch Selection Card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Training Batches</h2>
            </div>

            <!-- Search Box -->
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="batchSearch" class="search-input" placeholder="Search batches...">
            </div>

            <!-- Batches List -->
            <div class="batches-container">
                <?php if (count($training_batches) > 0): ?>
                    <?php foreach ($training_batches as $batch): ?>
                        <div class="card batch-card <?php echo ($selected_batch_id == $batch['id']) ? 'active' : ''; ?>" 
                             onclick="window.location.href='view_training_attendance.php?batch_id=<?php echo $batch['id']; ?>'">
                            <div class="batch-header">
                                <h3 class="batch-title">
                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                    <span class="badge-count"><?php echo $batch['student_count']; ?> students</span>
                                </h3>
                            </div>
                            <div class="batch-meta">
                                <div class="meta-item">
                                    <i class="fas fa-building"></i>
                                    <?php echo htmlspecialchars($batch['department_name']); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <?php echo htmlspecialchars($batch['academic_year']); ?>
                                </div>
                                <?php if (!empty($batch['description'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-info-circle"></i>
                                    <?php echo htmlspecialchars($batch['description']); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No training batches found for your department.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Batch Details and Statistics (shown when a batch is selected) -->
        <?php if ($selected_batch_id > 0 && $batch_details): ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title"><?php echo htmlspecialchars($batch_details['batch_name']); ?> - Attendance</h2>
                </div>
                
                <!-- Date Range Filter -->
                <form method="get" action="" class="filters">
                    <input type="hidden" name="batch_id" value="<?php echo $selected_batch_id; ?>">
                    <?php if ($selected_session_id): ?>
                        <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                    <?php endif; ?>
                    
                    <div class="filter-item">
                        <label for="date_from">From Date:</label>
                        <input type="date" id="date_from" name="date_from" class="form-control" 
                               value="<?php echo $date_from; ?>">
                    </div>
                    
                    <div class="filter-item">
                        <label for="date_to">To Date:</label>
                        <input type="date" id="date_to" name="date_to" class="form-control" 
                               value="<?php echo $date_to; ?>">
                    </div>
                    
                    <div class="filter-item" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn">
                            <i class="fas fa-filter"></i> Apply Filters
                        </button>
                    </div>
                </form>
                
                <!-- Batch Statistics -->
                <?php if (isset($attendance_stats)): ?>
                <div class="stats-container">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attendance_stats['total_sessions'] ?? 0; ?></div>
                        <div class="stat-label">Total Sessions</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attendance_stats['total_students'] ?? 0; ?></div>
                        <div class="stat-label">Total Students</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value">
                            <?php 
                                // Calculate attendance percentage
                                $total_attendance = ($attendance_stats['total_present'] ?? 0) + 
                                                 ($attendance_stats['total_excused'] ?? 0) + 
                                                 ($attendance_stats['total_late'] ?? 0);
                                $total_possible = $attendance_stats['total_sessions'] * $attendance_stats['total_students'];
                                $percentage = ($total_possible > 0) ? round(($total_attendance / $total_possible) * 100, 1) : 0;
                                echo $percentage . '%';
                            ?>
                        </div>
                        <div class="stat-label">Attendance Rate</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attendance_stats['total_present'] ?? 0; ?></div>
                        <div class="stat-label">Present</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attendance_stats['total_absent'] ?? 0; ?></div>
                        <div class="stat-label">Absent</div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $attendance_stats['total_late'] ?? 0; ?></div>
                        <div class="stat-label">Late</div>
                    </div>
                </div>

                <!-- Attendance Visualization Dashboard -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Attendance Analytics</h3>
                        <div class="chart-filters">
                            <button class="chart-filter-btn active" data-period="all">All Time</button>
                            <button class="chart-filter-btn" data-period="month">Last 30 Days</button>
                            <button class="chart-filter-btn" data-period="week">Last 7 Days</button>
                        </div>
                    </div>
                    
                    <!-- First row of charts -->
                    <div class="chart-row">
                        <!-- Attendance Trend Chart -->
                        <div class="chart-col">
                            <div class="chart-container">
                                <div class="chart-title">Attendance Trend</div>
                                <div class="chart-loader">Loading...</div>
                                <canvas id="attendanceTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Second row of charts -->
                    <div class="chart-row">
                        <!-- Top Absentees -->
                        <div class="chart-col">
                            <div class="chart-container">
                                <div class="chart-title">Top 10 Absentees</div>
                                <canvas id="topAbsenteesChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Day of Week Analysis -->
                        <div class="chart-col">
                            <div class="chart-container">
                                <div class="chart-title">Day of Week Analysis</div>
                                <canvas id="dayOfWeekChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    // Prepare data for charts
                    // Query to get date-wise attendance data
                    $date_wise_query = "SELECT 
                                        tss.session_date,
                                        COUNT(DISTINCT tar.id) AS total_records,
                                        SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                                        SUM(CASE WHEN tar.status = 'absent' OR tar.status IS NULL THEN 1 ELSE 0 END) AS absent_count,
                                        SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                                        SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
                                    FROM 
                                        training_session_schedule tss
                                    JOIN 
                                        student_training_batch stb ON tss.training_batch_id = stb.training_batch_id
                                    LEFT JOIN 
                                        training_attendance_records tar ON tss.id = tar.session_id AND stb.student_id = tar.student_id
                                    WHERE 
                                        tss.training_batch_id = ?
                                        AND tss.session_date BETWEEN ? AND ?
                                        AND tss.is_cancelled = FALSE
                                    GROUP BY 
                                        tss.session_date
                                    ORDER BY 
                                        tss.session_date";
                    
                    $stmt = mysqli_prepare($conn, $date_wise_query);
                    mysqli_stmt_bind_param($stmt, "iss", $selected_batch_id, $date_from, $date_to);
                    mysqli_stmt_execute($stmt);
                    $date_wise_result = mysqli_stmt_get_result($stmt);
                    
                    $dates = [];
                    $present_counts = [];
                    $absent_counts = [];
                    $late_counts = [];
                    $attendance_rates = [];
                    
                    while ($row = mysqli_fetch_assoc($date_wise_result)) {
                        $dates[] = date('d M', strtotime($row['session_date']));
                        $present_counts[] = $row['present_count'];
                        $absent_counts[] = $row['absent_count'];
                        $late_counts[] = $row['late_count'];
                        
                        $total = $row['present_count'] + $row['absent_count'] + $row['late_count'] + $row['excused_count'];
                        $attendance_rates[] = $total > 0 ? round(($row['present_count'] + $row['late_count'] + $row['excused_count']) * 100 / $total, 1) : 0;
                    }
                    

                    
                    // Query to get day-of-week attendance data
                    $day_of_week_query = "SELECT 
                                        DAYNAME(tss.session_date) AS day_name,
                                        COUNT(DISTINCT tar.id) AS total_records,
                                        ROUND((SUM(CASE WHEN tar.status IN ('present', 'late', 'excused') THEN 1 ELSE 0 END) / 
                                              COUNT(DISTINCT tar.id)) * 100, 1) AS attendance_rate
                                    FROM 
                                        training_session_schedule tss
                                    JOIN 
                                        student_training_batch stb ON tss.training_batch_id = stb.training_batch_id
                                    LEFT JOIN 
                                        training_attendance_records tar ON tss.id = tar.session_id AND stb.student_id = tar.student_id
                                    WHERE 
                                        tss.training_batch_id = ?
                                        AND tss.session_date BETWEEN ? AND ?
                                        AND tss.is_cancelled = FALSE
                                    GROUP BY 
                                        day_name
                                    ORDER BY 
                                        DAYOFWEEK(tss.session_date)";
                    
                    $stmt = mysqli_prepare($conn, $day_of_week_query);
                    mysqli_stmt_bind_param($stmt, "iss", $selected_batch_id, $date_from, $date_to);
                    mysqli_stmt_execute($stmt);
                    $day_of_week_result = mysqli_stmt_get_result($stmt);
                    
                    $days = [];
                    $day_attendance_rates = [];
                    
                    while ($row = mysqli_fetch_assoc($day_of_week_result)) {
                        $days[] = $row['day_name'];
                        $day_attendance_rates[] = $row['attendance_rate'];
                    }
                    
                    // Query to get top absentees
                    $absentees_query = "SELECT 
                                        s.roll_number,
                                        s.name AS student_name,
                                        COUNT(DISTINCT tss.id) AS total_sessions,
                                        SUM(CASE WHEN tar.status = 'absent' OR tar.status IS NULL THEN 1 ELSE 0 END) AS absent_count,
                                        ROUND((SUM(CASE WHEN tar.status = 'absent' OR tar.status IS NULL THEN 1 ELSE 0 END) / 
                                              COUNT(DISTINCT tss.id)) * 100, 1) AS absent_rate
                                    FROM 
                                        students s
                                    JOIN 
                                        student_training_batch stb ON s.id = stb.student_id
                                    JOIN 
                                        training_session_schedule tss ON stb.training_batch_id = tss.training_batch_id
                                    LEFT JOIN 
                                        training_attendance_records tar ON s.id = tar.student_id AND tss.id = tar.session_id
                                    WHERE 
                                        stb.training_batch_id = ?
                                        AND tss.session_date BETWEEN ? AND ?
                                        AND tss.is_cancelled = FALSE
                                    GROUP BY 
                                        s.id, s.roll_number, s.name
                                    HAVING 
                                        absent_count > 0
                                    ORDER BY 
                                        absent_rate DESC
                                    LIMIT 10";
                    
                    $stmt = mysqli_prepare($conn, $absentees_query);
                    mysqli_stmt_bind_param($stmt, "iss", $selected_batch_id, $date_from, $date_to);
                    mysqli_stmt_execute($stmt);
                    $absentees_result = mysqli_stmt_get_result($stmt);
                    
                    $absentees = [];
                    $absent_rates = [];
                    
                    while ($row = mysqli_fetch_assoc($absentees_result)) {
                        $absentees[] = $row['roll_number'] . ' - ' . substr($row['student_name'], 0, 15) . (strlen($row['student_name']) > 15 ? '...' : '');
                        $absent_rates[] = $row['absent_rate'];
                    }
                    ?>
                </div>
                <?php endif; ?>
                
                <!-- Department-wide export button -->
                <div style="margin-bottom: 1.5rem; display: flex; justify-content: flex-end;">
                    <button class="btn btn-success export-department-btn">  
                        <i class="fas fa-file-excel"></i> Export Department-Wide Attendance Report
                    </button>
                </div>
                
                <!-- Sessions List -->
                <?php if (count($sessions) > 0): ?>
                <h3 style="margin-bottom: 1rem;">Training Sessions</h3>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="sortable <?php echo ($sessions_sort_column == 'session_date') ? strtolower($sessions_sort_direction) : ''; ?>"
                                    data-column="session_date" data-table="sessions">Date</th>
                                <th class="sortable <?php echo ($sessions_sort_column == 'start_time') ? strtolower($sessions_sort_direction) : ''; ?>"
                                    data-column="start_time" data-table="sessions">Time</th>
                                <th class="sortable <?php echo ($sessions_sort_column == 'topic') ? strtolower($sessions_sort_direction) : ''; ?>"
                                    data-column="topic" data-table="sessions">Topic</th>
                                <th class="sortable <?php echo ($sessions_sort_column == 'venue_name') ? strtolower($sessions_sort_direction) : ''; ?>"
                                    data-column="venue_name" data-table="sessions">Venue</th>
                                <th class="sortable <?php echo ($sessions_sort_column == 'attendance_count') ? strtolower($sessions_sort_direction) : ''; ?>"
                                    data-column="attendance_count" data-table="sessions">Attendance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sessions as $session): ?>
                                <tr class="<?php echo ($selected_session_id == $session['id']) ? 'active' : ''; ?>">
                                    <td><?php echo date('d M, Y', strtotime($session['session_date'])); ?></td>
                                    <td>
                                        <?php 
                                            echo date('h:i A', strtotime($session['start_time'])) . ' - ' . 
                                                 date('h:i A', strtotime($session['end_time']));
                                        ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($session['topic']); ?>
                                        <?php if (!empty($session['trainer_name'])): ?>
                                            <br><small>Trainer: <?php echo htmlspecialchars($session['trainer_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            echo htmlspecialchars($session['venue_name']);
                                            if (!empty($session['room_number'])) {
                                                echo ' (' . htmlspecialchars($session['room_number']) . ')';
                                            }
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            // Calculate attendance percentage for this session
                                            $total_attendance = ($session['present_count'] ?? 0) + 
                                                             ($session['excused_count'] ?? 0) + 
                                                             ($session['late_count'] ?? 0);
                                            $total_students = $batch_details['student_count'];
                                            $percentage = ($total_students > 0) ? round(($total_attendance / $total_students) * 100) : 0;
                                        ?>
                                        <div class="status-indicator">
                                            <div class="indicator-dot indicator-present"></div> <?php echo $session['present_count'] ?? 0; ?>
                                            <div class="indicator-dot indicator-absent"></div> <?php echo $session['absent_count'] ?? 0; ?>
                                            <div class="indicator-dot indicator-late"></div> <?php echo $session['late_count'] ?? 0; ?>
                                        </div>
                                        <div class="progress-container">
                                            <div class="progress-bar" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                        <small><?php echo $percentage; ?>% attended</small>
                                    </td>
                                    <td>
                                        <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $session['id']; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-eye"></i> View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p>No training sessions found for this batch within the selected date range.</p>
                <?php endif; ?>
            </div>

            <!-- Individual Session Details (shown when a session is selected) -->
            <?php if ($selected_session_id > 0 && $session_details): ?>
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Session Details</h2>
                        <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-outline">
                            <i class="fas fa-arrow-left"></i> Back to Sessions
                        </a>
                    </div>
                    
                    <div class="class-info" style="margin-bottom: 1.5rem;">
                        <div class="info-item">
                            <span>Date:</span>
                            <span class="info-value"><?php echo date('d M, Y', strtotime($session_details['session_date'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span>Time:</span>
                            <span class="info-value">
                                <?php 
                                    echo date('h:i A', strtotime($session_details['start_time'])) . ' - ' . 
                                         date('h:i A', strtotime($session_details['end_time']));
                                ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span>Topic:</span>
                            <span class="info-value"><?php echo htmlspecialchars($session_details['topic']); ?></span>
                        </div>
                        <div class="info-item">
                            <span>Venue:</span>
                            <span class="info-value">
                                <?php 
                                    echo htmlspecialchars($session_details['venue_name']);
                                    if (!empty($session_details['room_number'])) {
                                        echo ' (' . htmlspecialchars($session_details['room_number']) . ')';
                                    }
                                ?>
                            </span>
                        </div>
                        <?php if (!empty($session_details['trainer_name'])): ?>
                        <div class="info-item">
                            <span>Trainer:</span>
                            <span class="info-value"><?php echo htmlspecialchars($session_details['trainer_name']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Status Filter -->
                    <form method="get" action="" class="filters">
                        <input type="hidden" name="batch_id" value="<?php echo $selected_batch_id; ?>">
                        <input type="hidden" name="session_id" value="<?php echo $selected_session_id; ?>">
                        <input type="hidden" name="date_from" value="<?php echo $date_from; ?>">
                        <input type="hidden" name="date_to" value="<?php echo $date_to; ?>">
                        <input type="hidden" name="page" value="1">
                        
                        <div class="filter-item">
                            <label for="status">Filter by Status:</label>
                            <select name="status" id="status" class="form-control" onchange="this.form.submit()">
                                <option value="">All Students</option>
                                <option value="present" <?php echo $status_filter == 'present' ? 'selected' : ''; ?>>Present</option>
                                <option value="absent" <?php echo $status_filter == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                <option value="late" <?php echo $status_filter == 'late' ? 'selected' : ''; ?>>Late</option>
                                <option value="excused" <?php echo $status_filter == 'excused' ? 'selected' : ''; ?>>Excused</option>
                            </select>
                        </div>
                    </form>
                    
                    <!-- Student Attendance List -->
                                            <?php if (count($students) > 0): ?>
                        <h3 style="margin: 1rem 0;">Student Attendance</h3>
                        
                        <!-- Visualization for individual session attendance -->
                        <div class="chart-row">
                            <div class="chart-col">
                                <div class="chart-container">
                                    <div class="chart-title">Session Attendance Distribution</div>
                                    <canvas id="sessionAttendanceChart"></canvas>
                                </div>
                            </div>
                            <div class="chart-col">
                                <div class="chart-container">
                                    <div class="chart-title">Attendance Timing Analysis</div>
                                    <canvas id="attendanceTimingChart"></canvas>
                                </div>
                            </div>
                        </div>

                        <?php
                        // Calculate attendance statistics for this session
                        $present_count = 0;
                        $absent_count = 0;
                        $late_count = 0;
                        $excused_count = 0;
                        
                        // Calculate hourly attendance marking distribution
                        $hourly_distribution = [];
                        
                        foreach ($students as $student) {
                            switch($student['status']) {
                                case 'present':
                                    $present_count++;
                                    break;
                                case 'absent':
                                    $absent_count++;
                                    break;
                                case 'late':
                                    $late_count++;
                                    break;
                                case 'excused':
                                    $excused_count++;
                                    break;
                            }
                            
                            // Get hour when attendance was marked (for timing analysis)
                            if ($student['marked_at']) {
                                $hour = date('H', strtotime($student['marked_at']));
                                if (!isset($hourly_distribution[$hour])) {
                                    $hourly_distribution[$hour] = 0;
                                }
                                $hourly_distribution[$hour]++;
                            }
                        }
                        
                        // Sort hours and prepare for chart
                        ksort($hourly_distribution);
                        $hours = [];
                        $attendance_counts = [];
                        
                        foreach ($hourly_distribution as $hour => $count) {
                            $formatted_hour = sprintf('%02d:00', $hour);
                            $hours[] = $formatted_hour;
                            $attendance_counts[] = $count;
                        }
                        ?>
                        
                        <!-- Search Box -->
                        <div class="search-box" style="margin-bottom: 1rem;">
                            <i class="fas fa-search"></i>
                            <input type="text" id="studentSearch" class="search-input" placeholder="Search students by name or roll number...">
                        </div>
                        
                        <div class="table-container">
                            <table class="table" id="studentTable">
                                <thead>
                                    <tr>
                                        <th class="sortable <?php echo ($students_sort_column == 'roll_number') ? strtolower($students_sort_direction) : ''; ?>"
                                            data-column="roll_number" data-table="students">Roll Number</th>
                                        <th class="sortable <?php echo ($students_sort_column == 'student_name') ? strtolower($students_sort_direction) : ''; ?>"
                                            data-column="student_name" data-table="students">Name</th>
                                        <th class="sortable <?php echo ($students_sort_column == 'status') ? strtolower($students_sort_direction) : ''; ?>"
                                            data-column="status" data-table="students">Status</th>
                                        <th class="sortable <?php echo ($students_sort_column == 'marked_by_name') ? strtolower($students_sort_direction) : ''; ?>"
                                            data-column="marked_by_name" data-table="students">Marked By</th>
                                        <th class="sortable <?php echo ($students_sort_column == 'marked_at') ? strtolower($students_sort_direction) : ''; ?>"
                                            data-column="marked_at" data-table="students">Marked At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr class="<?php echo $student['status']; ?>">
                                            <td><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($student['student_name']); ?>
                                                <br>
                                                <small><?php echo htmlspecialchars($student['register_number']); ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                    switch($student['status']) {
                                                        case 'present':
                                                            echo '<span class="badge badge-success">Present</span>';
                                                            break;
                                                        case 'absent':
                                                            echo '<span class="badge badge-danger">Absent</span>';
                                                            break;
                                                        case 'late':
                                                            echo '<span class="badge badge-warning">Late</span>';
                                                            break;
                                                        case 'excused':
                                                            echo '<span class="badge badge-info">Excused</span>';
                                                            break;
                                                        default:
                                                            echo '<span class="badge badge-danger">Absent</span>';
                                                    }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo $student['marked_by_name'] ? htmlspecialchars($student['marked_by_name']) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    echo $student['marked_at'] ? date('d/m/Y H:i', strtotime($student['marked_at'])) : 'N/A'; 
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Attendance Summary -->
                        <div class="stats-container" style="margin-top: 1.5rem;">
                            <?php
                                // Get overall attendance statistics for all students in this session, not just current page
                                $overall_stats_query = "SELECT 
                                    SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                                    SUM(CASE WHEN tar.status = 'absent' OR tar.status IS NULL THEN 1 ELSE 0 END) AS absent_count,
                                    SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                                    SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count,
                                    COUNT(DISTINCT s.id) AS total_students
                                FROM 
                                    students s
                                JOIN 
                                    student_training_batch stb ON s.id = stb.student_id
                                LEFT JOIN 
                                    training_attendance_records tar ON s.id = tar.student_id AND tar.session_id = ?
                                WHERE 
                                    stb.training_batch_id = ? AND stb.is_active = TRUE";
                                
                                $stmt = mysqli_prepare($conn, $overall_stats_query);
                                mysqli_stmt_bind_param($stmt, "ii", $selected_session_id, $selected_batch_id);
                                mysqli_stmt_execute($stmt);
                                $overall_result = mysqli_stmt_get_result($stmt);
                                $overall_stats = mysqli_fetch_assoc($overall_result);
                                
                                $present_count = $overall_stats['present_count'] ?? 0;
                                $absent_count = $overall_stats['absent_count'] ?? 0;
                                $late_count = $overall_stats['late_count'] ?? 0;
                                $excused_count = $overall_stats['excused_count'] ?? 0;
                                $total_students = $overall_stats['total_students'] ?? 0;
                                
                                $attendance_percentage = ($total_students > 0) ? 
                                    round((($present_count + $excused_count + $late_count) / $total_students) * 100, 1) : 0;
                            ?>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $attendance_percentage; ?>%</div>
                                <div class="stat-label">Attendance Rate</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $present_count; ?></div>
                                <div class="stat-label">Present</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $absent_count; ?></div>
                                <div class="stat-label">Absent</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $late_count; ?></div>
                                <div class="stat-label">Late</div>
                            </div>
                            
                            <div class="stat-card">
                                <div class="stat-value"><?php echo $excused_count; ?></div>
                                <div class="stat-label">Excused</div>
                            </div>
                        </div>
                        

                        <!-- Pagination controls with sort parameters -->
                        <?php if (isset($total_pages) && $total_pages > 1): ?>
                        <div class="pagination-container" style="margin: 1.5rem 0; text-align: center;">
                            <div class="pagination-info" style="margin-bottom: 0.5rem; color: var(--text-light); font-size: 0.9rem;">
                                <?php 
                                // Store the count of students on current page
                                $current_page_students = count($students);
                                
                                // Use accurate total students count from the database query
                                $pagination_total = $overall_stats['total_students'] ?? $total_students;
                                
                                // Ensure we have a valid range to display
                                $start_item = min($offset + 1, $pagination_total);
                                $end_item = min($offset + $students_per_page, $pagination_total);
                                ?>
                                Showing <?php echo $start_item; ?>-<?php echo $end_item; ?> 
                                of <?php echo $pagination_total; ?> students
                            </div>
                            
                            <div class="pagination" style="display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 0.25rem; margin-top: 1rem;">
                                <!-- Previous page button -->
                                <?php if ($current_page > 1): ?>
                                    <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&page=<?php echo $current_page - 1; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>&students_sort=<?php echo $students_sort_column; ?>&students_dir=<?php echo $students_sort_direction; ?>" 
                                       class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0;">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0; opacity: 0.5; cursor: not-allowed;">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                <?php endif; ?>
                                
                                <!-- Page numbers -->
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $start_page + 4);
                                if ($end_page - $start_page < 4 && $total_pages > 5) {
                                    $start_page = max(1, $end_page - 4);
                                }
                                
                                if ($start_page > 1): ?>
                                    <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&page=1<?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>&students_sort=<?php echo $students_sort_column; ?>&students_dir=<?php echo $students_sort_direction; ?>" 
                                       class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0;">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span style="display: inline-flex; align-items: center; justify-content: center; padding: 0 0.5rem; height: 40px;">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&page=<?php echo $i; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>&students_sort=<?php echo $students_sort_column; ?>&students_dir=<?php echo $students_sort_direction; ?>" 
                                       class="btn btn-sm <?php echo $i == $current_page ? 'btn-info' : ''; ?>"
                                       style="min-width: 40px; height: 40px; padding: 0;">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span style="display: inline-flex; align-items: center; justify-content: center; padding: 0 0.5rem; height: 40px;">...</span>
                                    <?php endif; ?>
                                    <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&page=<?php echo $total_pages; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>&students_sort=<?php echo $students_sort_column; ?>&students_dir=<?php echo $students_sort_direction; ?>" 
                                       class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0;">
                                        <?php echo $total_pages; ?>
                                    </a>
                                <?php endif; ?>
                                
                                <!-- Next page button -->
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="view_training_attendance.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&page=<?php echo $current_page + 1; ?><?php echo !empty($status_filter) ? '&status=' . $status_filter : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from=' . $_GET['date_from'] : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to=' . $_GET['date_to'] : ''; ?>&students_sort=<?php echo $students_sort_column; ?>&students_dir=<?php echo $students_sort_direction; ?>" 
                                       class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0;">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm" style="min-width: 40px; height: 40px; padding: 0; opacity: 0.5; cursor: not-allowed;">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Actions -->
                        <div style="margin-top: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap;">
                            <a href="attendance_marking.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit"></i> Edit Attendance
                            </a>
                            <a href="export_attendance_excel.php?batch_id=<?php echo $selected_batch_id; ?>&session_id=<?php echo $selected_session_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export This Session
                            </a>
                            <a href="export_attendance_excel.php?batch_id=<?php echo $selected_batch_id; ?>&topic=<?php echo urlencode($session_details['topic']); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export All '<?php echo htmlspecialchars($session_details['topic']); ?>' Sessions
                            </a>
                            <a href="export_attendance_excel.php?batch_id=<?php echo $selected_batch_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export All Topics
                            </a>
                            <button class="btn btn-info" onclick="printAttendance()">
                                <i class="fas fa-print"></i> Print
                            </button>
                            <button class="btn btn-success export-department-btn">
                                <i class="fas fa-file-excel"></i> Export Department Batches
                            </button>
                        </div>

                        <?php
                        // Find other sessions on the same date
                        if (!empty($session_details)) {
                            $same_date_query = "SELECT 
                                tss.id,
                                tss.session_date,
                                tss.start_time,
                                tss.end_time,
                                tss.topic,
                                tss.trainer_name,
                                v.name AS venue_name,
                                v.room_number,
                                COUNT(DISTINCT tar.student_id) AS attendance_count,
                                SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) AS present_count,
                                SUM(CASE WHEN tar.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
                                SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) AS late_count,
                                SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) AS excused_count
                              FROM 
                                training_session_schedule tss
                              JOIN 
                                venues v ON tss.venue_id = v.id
                              LEFT JOIN 
                                training_attendance_records tar ON tss.id = tar.session_id
                              WHERE 
                                tss.training_batch_id = ? 
                                AND tss.session_date = ?
                                AND tss.id != ?
                                AND tss.is_cancelled = FALSE
                              GROUP BY 
                                tss.id
                              ORDER BY 
                                tss.start_time";
                            
                            $stmt = mysqli_prepare($conn, $same_date_query);
                            mysqli_stmt_bind_param($stmt, "isi", $selected_batch_id, $session_details['session_date'], $selected_session_id);
                            mysqli_stmt_execute($stmt);
                            $same_date_result = mysqli_stmt_get_result($stmt);
                            
                            $same_date_sessions = [];
                            while ($session = mysqli_fetch_assoc($same_date_result)) {
                                $same_date_sessions[] = $session;
                            }
                            
                            // Display same day comparison section only if other sessions exist
                            if (count($same_date_sessions) > 0) {
                                echo '<div class="card" style="margin-top: 1.5rem;">';
                                echo '<div class="card-header">';
                                echo '<h3 class="card-title">Same Day Attendance Comparison</h3>';
                                echo '</div>';
                                echo '<div class="info-item" style="margin-bottom: 1rem;">';
                                echo '<p>Compare attendance with other sessions on ' . date('d M, Y', strtotime($session_details['session_date'])) . '</p>';
                                echo '</div>';
                                
                                // Create comparison table
                                echo '<div class="table-container">';
                                echo '<table class="table">';
                                echo '<thead>';
                                echo '<tr>';
                                echo '<th>Time</th>';
                                echo '<th>Topic</th>';
                                echo '<th>Venue</th>';
                                echo '<th>Attendance Rate</th>';
                                echo '<th>Present/Late/Absent</th>';
                                echo '<th>Actions</th>';
                                echo '</tr>';
                                echo '</thead>';
                                echo '<tbody>';
                                
                                // Current session data for comparison
                                echo '<tr class="present">';
                                echo '<td>' . date('h:i A', strtotime($session_details['start_time'])) . ' - ' . 
                                            date('h:i A', strtotime($session_details['end_time'])) . '</td>';
                                echo '<td><strong>' . htmlspecialchars($session_details['topic']) . '</strong> (Current)</td>';
                                echo '<td>' . htmlspecialchars($session_details['venue_name']) . 
                                        (!empty($session_details['room_number']) ? ' (' . htmlspecialchars($session_details['room_number']) . ')' : '') . '</td>';
                                echo '<td>' . $attendance_percentage . '%</td>';
                                echo '<td>';
                                echo '<div class="status-indicator">';
                                echo '<div class="indicator-dot indicator-present"></div> ' . $present_count;
                                echo '<div class="indicator-dot indicator-late"></div> ' . $late_count;
                                echo '<div class="indicator-dot indicator-absent"></div> ' . $absent_count;
                                echo '</div>';
                                echo '</td>';
                                echo '<td><span class="badge badge-info">Current Session</span></td>';
                                echo '</tr>';
                                
                                // Other sessions on same date
                                foreach ($same_date_sessions as $session) {
                                    $total = $batch_details['student_count'];
                                    $session_attendance = ($total > 0) ? 
                                        round((($session['present_count'] + $session['late_count'] + $session['excused_count']) / $total) * 100) : 0;
                                    
                                    // Calculate attendance difference with current session
                                    $diff = $session_attendance - $attendance_percentage;
                                    $diff_class = $diff > 0 ? 'badge-success' : ($diff < 0 ? 'badge-danger' : 'badge-info');
                                    $diff_text = $diff > 0 ? '+'.$diff.'%' : $diff.'%';
                                    
                                    echo '<tr>';
                                    echo '<td>' . date('h:i A', strtotime($session['start_time'])) . ' - ' . 
                                                date('h:i A', strtotime($session['end_time'])) . '</td>';
                                    echo '<td>' . htmlspecialchars($session['topic']) . '</td>';
                                    echo '<td>' . htmlspecialchars($session['venue_name']) . 
                                            (!empty($session['room_number']) ? ' (' . htmlspecialchars($session['room_number']) . ')' : '') . '</td>';
                                    echo '<td>';
                                    echo $session_attendance . '% ';
                                    echo '<span class="badge ' . $diff_class . '">' . $diff_text . '</span>';
                                    echo '</td>';
                                    echo '<td>';
                                    echo '<div class="status-indicator">';
                                    echo '<div class="indicator-dot indicator-present"></div> ' . ($session['present_count'] ?? 0);
                                    echo '<div class="indicator-dot indicator-late"></div> ' . ($session['late_count'] ?? 0);
                                    echo '<div class="indicator-dot indicator-absent"></div> ' . ($session['absent_count'] ?? 0);
                                    echo '</div>';
                                    echo '</td>';
                                    echo '<td>';
                                    echo '<a href="view_training_attendance.php?batch_id=' . $selected_batch_id . '&session_id=' . $session['id'] . '" class="btn btn-info btn-sm">';
                                    echo '<i class="fas fa-eye"></i> View';
                                    echo '</a>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                                
                                echo '</tbody>';
                                echo '</table>';
                                echo '</div>';
                                
                                // Absentees analysis
                                echo '<div style="margin-top: 1rem;">';
                                echo '<h4>Absent Students Analysis</h4>';
                                echo '<p>Identifying students absent in current session but present in other sessions on the same day:</p>';
                                
                                // Get students who are absent in current session but present in other same-day sessions
                                $absentee_query = "SELECT DISTINCT 
                                    s.id,
                                    s.roll_number, 
                                    s.name,
                                    s.register_number,
                                    COUNT(DISTINCT tar_other.id) as other_sessions_present,
                                    GROUP_CONCAT(DISTINCT 
                                       CONCAT(
                                           TIME_FORMAT(other_sessions.start_time, '%h:%i %p'),
                                           ' - ',
                                           other_sessions.topic
                                       ) 
                                       ORDER BY other_sessions.start_time
                                       SEPARATOR ', '
                                    ) as attended_sessions
                                FROM 
                                    students s
                                JOIN 
                                    student_training_batch stb ON s.id = stb.student_id AND stb.training_batch_id = ?
                                -- Current session attendance (absent or null)
                                LEFT JOIN 
                                    training_attendance_records tar ON s.id = tar.student_id AND tar.session_id = ?
                                -- Get the same date from the current session
                                JOIN
                                    training_session_schedule current_session ON current_session.id = ? 
                                -- Find other sessions on the same day 
                                JOIN
                                    training_session_schedule other_sessions ON other_sessions.session_date = current_session.session_date 
                                    AND other_sessions.id != current_session.id
                                -- Find where student was present in those other sessions
                                JOIN
                                    training_attendance_records tar_other ON s.id = tar_other.student_id 
                                    AND tar_other.session_id = other_sessions.id
                                    AND tar_other.status IN ('present', 'late')
                                WHERE 
                                    (tar.status = 'absent' OR tar.status IS NULL)
                                GROUP BY
                                    s.id, s.roll_number, s.name, s.register_number
                                HAVING 
                                    other_sessions_present > 0
                                ORDER BY 
                                    s.roll_number";
                                
                                $stmt = mysqli_prepare($conn, $absentee_query);
                                mysqli_stmt_bind_param($stmt, "iii", $selected_batch_id, $selected_session_id, $selected_session_id);
                                mysqli_stmt_execute($stmt);
                                $absentee_result = mysqli_stmt_get_result($stmt);
                                
                                $selective_absentees = [];
                                while ($student = mysqli_fetch_assoc($absentee_result)) {
                                    $selective_absentees[] = $student;
                                }
                                
                                if (count($selective_absentees) > 0) {
                                    echo '<div class="alert alert-warning" style="margin-top: 0.5rem;">';
                                    echo '<strong>' . count($selective_absentees) . ' students</strong> were absent in this session but attended other sessions on the same day.';
                                    echo '</div>';
                                    
                                    echo '<div class="table-container">';
                                    echo '<table class="table">';
                                    echo '<thead>';
                                    echo '<tr>';
                                    echo '<th>Roll Number</th>';
                                    echo '<th>Student Name</th>';
                                    echo '<th>Other Sessions Attended</th>';
                                    echo '<th>Attended Sessions</th>';
                                    echo '</tr>';
                                    echo '</thead>';
                                    echo '<tbody>';
                                    
                                    foreach ($selective_absentees as $student) {
                                        echo '<tr>';
                                        echo '<td>' . $student['roll_number'] . '</td>';
                                        echo '<td>' . $student['name'] . ' (' . $student['register_number'] . ')</td>';
                                        echo '<td>' . $student['other_sessions_present'] . '</td>';
                                        echo '<td>' . ($student['attended_sessions'] ?: 'N/A') . '</td>';
                                        echo '</tr>';
                                    }
                                    
                                    echo '</tbody>';
                                    echo '</table>';
                                    echo '</div>';
                                } else {
                                    echo '<div class="alert alert-success" style="margin-top: 0.5rem;">';
                                    echo 'No students were selectively absent for this session. All absent students were absent for all sessions on this day.';
                                    echo '</div>';
                                }
                                
                                echo '</div>'; // End absentees analysis
                                
                                echo '</div>'; // End comparison card
                            }
                        }
                        ?>
                    <?php else: ?>
                        <p>No students found for this session with the selected filters.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <a href="dashboard.php" class="floating-back">
        <i class="fas fa-arrow-left"></i>
    </a>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide all chart loaders after charts are ready
            const hideLoaders = function() {
                document.querySelectorAll('.chart-loader').forEach(loader => {
                    loader.style.display = 'none';
                });
            };
            // Set a timeout to ensure charts are properly displayed
            setTimeout(hideLoaders, 1000);
            // Batch search functionality
            const batchSearch = document.getElementById('batchSearch');
            if (batchSearch) {
                batchSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const batchCards = document.querySelectorAll('.batch-card');
                    
                    batchCards.forEach(card => {
                        const batchName = card.querySelector('.batch-title').textContent.toLowerCase();
                        const deptName = card.querySelector('.meta-item:first-child').textContent.toLowerCase();
                        const desc = card.querySelector('.meta-item:last-child') ? 
                                    card.querySelector('.meta-item:last-child').textContent.toLowerCase() : '';
                        
                        if (batchName.includes(searchTerm) || deptName.includes(searchTerm) || desc.includes(searchTerm)) {
                            card.style.display = '';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
            }
            
            // Student search functionality
            const studentSearch = document.getElementById('studentSearch');
            if (studentSearch) {
                studentSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const studentRows = document.querySelectorAll('#studentTable tbody tr');
                    
                    studentRows.forEach(row => {
                        const rollNumber = row.cells[0].textContent.toLowerCase();
                        const studentName = row.cells[1].textContent.toLowerCase();
                        
                        if (rollNumber.includes(searchTerm) || studentName.includes(searchTerm)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }

            // Sorting functionality for tables
            document.querySelectorAll('th.sortable').forEach(header => {
                header.addEventListener('click', function() {
                    const column = this.getAttribute('data-column');
                    const tableType = this.getAttribute('data-table');
                    const currentDirection = this.classList.contains('asc') ? 'ASC' : 
                                           (this.classList.contains('desc') ? 'DESC' : '');
                    
                    // Toggle sort direction or default to ASC
                    let newDirection = 'ASC';
                    if (currentDirection === 'ASC') {
                        newDirection = 'DESC';
                    } else if (currentDirection === 'DESC') {
                        newDirection = 'ASC';
                    }
                    
                    // Build the URL with appropriate sort parameters
                    const url = new URL(window.location.href);
                    
                    if (tableType === 'sessions') {
                        url.searchParams.set('sessions_sort', column);
                        url.searchParams.set('sessions_dir', newDirection);
                    } else if (tableType === 'students') {
                        url.searchParams.set('students_sort', column);
                        url.searchParams.set('students_dir', newDirection);
                    }
                    
                    // Redirect to the new URL with sort parameters
                    window.location.href = url.toString();
                });
            });
            
            // Chart rendering
            <?php if (isset($attendance_stats) && $selected_batch_id > 0): ?>
            // Chart.js configuration with better responsiveness
            Chart.defaults.font.family = "'Poppins', sans-serif";
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = true;
            
            // Attendance Trend Chart
            const trendCtx = document.getElementById('attendanceTrendChart').getContext('2d');
            const attendanceTrendChart = new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($dates); ?>,
                    datasets: [{
                        label: 'Attendance Rate (%)',
                        data: <?php echo json_encode($attendance_rates); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Attendance: ${context.raw}%`;
                                }
                            }
                        }
                    }
                }
            });
            
            // 5. Top Absentees Chart
            const absenteesCtx = document.getElementById('topAbsenteesChart').getContext('2d');
            const topAbsenteesChart = new Chart(absenteesCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($absentees); ?>,
                    datasets: [{
                        label: 'Absence Rate (%)',
                        data: <?php echo json_encode($absent_rates); ?>,
                        backgroundColor: function(context) {
                            const value = context.raw;
                            if (value > 50) return 'rgba(231, 76, 60, 0.9)';
                            if (value > 30) return 'rgba(243, 156, 18, 0.9)';
                            return 'rgba(230, 126, 34, 0.7)';
                        },
                        borderWidth: 1
                    }]
                },
                options: {
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Absence Rate: ${context.raw}%`;
                                }
                            }
                        }
                    }
                }
            });
            
            // 6. Day of Week Chart
            const dayCtx = document.getElementById('dayOfWeekChart').getContext('2d');
            const dayOfWeekChart = new Chart(dayCtx, {
                type: 'radar',
                data: {
                    labels: <?php echo json_encode($days); ?>,
                    datasets: [{
                        label: 'Attendance Rate (%)',
                        data: <?php echo json_encode($day_attendance_rates); ?>,
                        backgroundColor: 'rgba(46, 204, 113, 0.2)',
                        borderColor: 'rgba(46, 204, 113, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'rgba(46, 204, 113, 1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        r: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                stepSize: 20
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `Attendance: ${context.raw}%`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Chart filter functionality
            const filterButtons = document.querySelectorAll('.chart-filter-btn');
            filterButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const period = this.getAttribute('data-period');
                    const url = new URL(window.location.href);
                    
                    // Set appropriate date range
                    const today = new Date();
                    let fromDate = new Date();
                    
                    if (period === 'week') {
                        fromDate.setDate(today.getDate() - 7);
                    } else if (period === 'month') {
                        fromDate.setDate(today.getDate() - 30);
                    } else {
                        // For 'all', set date to 6 months ago or beginning of year
                        fromDate.setMonth(today.getMonth() - 6);
                    }
                    
                    // Format dates as YYYY-MM-DD
                    const formatDate = (date) => {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        return `${year}-${month}-${day}`;
                    };
                    
                    // Update URL and reload page
                    url.searchParams.set('date_from', formatDate(fromDate));
                    url.searchParams.set('date_to', formatDate(today));
                    window.location.href = url.toString();
                });
            });
            <?php endif; ?>

            // Session-specific charts if session is selected
            <?php if ($selected_session_id > 0 && isset($session_details)): ?>
            // Session Attendance Distribution Chart
            const sessionDistributionCtx = document.getElementById('sessionAttendanceChart').getContext('2d');
            const sessionAttendanceChart = new Chart(sessionDistributionCtx, {
                type: 'pie',
                data: {
                    labels: ['Present', 'Absent', 'Late', 'Excused'],
                    datasets: [{
                        data: [
                            <?php echo $present_count; ?>,
                            <?php echo $absent_count; ?>,
                            <?php echo $late_count; ?>,
                            <?php echo $excused_count; ?>
                        ],
                        backgroundColor: [
                            'rgba(46, 204, 113, 0.8)',
                            'rgba(231, 76, 60, 0.8)',
                            'rgba(243, 156, 18, 0.8)',
                            'rgba(52, 152, 219, 0.8)'
                        ],
                        borderColor: [
                            'rgba(46, 204, 113, 1)',
                            'rgba(231, 76, 60, 1)',
                            'rgba(243, 156, 18, 1)',
                            'rgba(52, 152, 219, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            position: 'right'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value * 100) / total);
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });

            // Attendance Timing Analysis Chart
            const timingCtx = document.getElementById('attendanceTimingChart').getContext('2d');
            const attendanceTimingChart = new Chart(timingCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($hours); ?>,
                    datasets: [{
                        label: 'Students Marked',
                        data: <?php echo json_encode($attendance_counts); ?>,
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointRadius: 5,
                        pointBackgroundColor: 'rgba(52, 152, 219, 1)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1,
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    return `Time: ${context[0].label}`;
                                },
                                label: function(context) {
                                    return `Students: ${context.raw}`;
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
        
        // Function to print attendance
        function printAttendance() {
            window.print();
        }
    </script>

    <!-- Export Department Report Modal -->
    <div class="modal" id="exportDepartmentModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">
        <div class="modal-content" style="background-color: var(--bg-color); margin: 15% auto; padding: 20px; border-radius: 15px; box-shadow: var(--shadow); max-width: 500px;">
            <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-color);">Export Department Attendance</h2>
                <span class="close" id="closeExportModal" style="cursor: pointer; font-size: 1.8rem; color: var(--text-light);">&times;</span>
            </div>
            <form id="exportForm" action="export_department_attendance.php" method="get">
                <input type="hidden" name="department_id" value="<?php echo $department_id; ?>">
                <input type="hidden" name="academic_year_id" value="<?php echo $academic_year_id; ?>">
                
                <div class="form-group">
                    <label for="selected_batch_id">Select Student Batch:</label>
                    <select name="selected_batch_id" id="selected_batch_id" class="form-control">
                        <option value="all">All Batches</option>
                        <?php
                        // Get all active batch years
                        $batch_query = "SELECT DISTINCT batch_years.id, batch_years.batch_name
                                       FROM batch_years
                                       JOIN students s ON s.batch_id = batch_years.id
                                       WHERE s.department_id = ?
                                       ORDER BY batch_years.admission_year DESC";
                        
                        $stmt = mysqli_prepare($conn, $batch_query);
                        mysqli_stmt_bind_param($stmt, "i", $department_id);
                        mysqli_stmt_execute($stmt);
                        $batch_result = mysqli_stmt_get_result($stmt);
                        
                        while ($batch = mysqli_fetch_assoc($batch_result)) {
                            echo "<option value=\"{$batch['id']}\">{$batch['batch_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date_from">From Date:</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" 
                           value="<?php echo $date_from; ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_to">To Date:</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" 
                           value="<?php echo $date_to; ?>">
                </div>
                
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                    <button type="button" id="cancelExport" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn">Generate Report</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Add to the existing DOMContentLoaded event or create a new one
    document.addEventListener('DOMContentLoaded', function() {
        // Previous script code remains...

        // Export Department Modal functionality
        const exportModal = document.getElementById('exportDepartmentModal');
        const exportButtons = document.querySelectorAll('.export-department-btn');
        const closeExportModal = document.getElementById('closeExportModal');
        const cancelExport = document.getElementById('cancelExport');
        
        exportButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                exportModal.style.display = 'block';
            });
        });
        
        closeExportModal.addEventListener('click', function() {
            exportModal.style.display = 'none';
        });
        
        cancelExport.addEventListener('click', function() {
            exportModal.style.display = 'none';
        });
        
        // Close modal if clicked outside
        window.addEventListener('click', function(e) {
            if (e.target === exportModal) {
                exportModal.style.display = 'none';
            }
        });
    });
    </script>
<?php
// Don't include closing body and html tags as they are in header.php
?>