<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check login status
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$student_id = $user_id;

// Get student details
$query = "SELECT s.*, 
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
        WHERE s.id = ? AND s.is_active = TRUE";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);

if (!$student) {
    header('Location: logout.php');
    exit();
}

// Get current academic year
$academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$academic_year_result = mysqli_query($conn, $academic_year_query);
$current_academic_year = mysqli_fetch_assoc($academic_year_result);

if (!$current_academic_year) {
    die("Error: No active academic year found. Please contact administrator.");
}

// Set default view type (schedule or attendance)
$view_type = isset($_GET['view']) ? $_GET['view'] : 'schedule';

// Get upcoming schedule for the student
$upcoming_schedule_query = "SELECT 
    acs.id as schedule_id,
    acs.class_date,
    acs.start_time,
    acs.end_time,
    acs.topic,
    v.name as venue_name,
    v.building,
    v.room_number,
    s.name as subject_name,
    s.code as subject_code,
    f.name as faculty_name
FROM academic_class_schedule acs
JOIN subject_assignments sa ON acs.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON sa.faculty_id = f.id
JOIN venues v ON acs.venue_id = v.id
WHERE sa.year = ?
AND sa.section = ?
AND sa.academic_year_id = ?
AND acs.class_date >= CURDATE()
AND acs.is_cancelled = FALSE
ORDER BY acs.class_date ASC, acs.start_time ASC
LIMIT 10";

$stmt = mysqli_prepare($conn, $upcoming_schedule_query);
mysqli_stmt_bind_param($stmt, "isi", 
    $student['current_year_of_study'],
    $student['section'],
    $current_academic_year['id']
);
mysqli_stmt_execute($stmt);
$upcoming_schedule = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Get training schedule if student is in a training batch
$training_schedule_query = "SELECT 
    tss.id as session_id,
    tss.session_date,
    tss.start_time,
    tss.end_time,
    tss.topic,
    tss.trainer_name,
    v.name as venue_name,
    v.building,
    v.room_number,
    tb.batch_name
FROM training_session_schedule tss
JOIN venues v ON tss.venue_id = v.id
JOIN training_batches tb ON tss.training_batch_id = tb.id
JOIN student_training_batch stb ON tb.id = stb.training_batch_id
WHERE stb.student_id = ?
AND stb.is_active = TRUE
AND tss.session_date >= CURDATE()
AND tss.is_cancelled = FALSE
ORDER BY tss.session_date ASC, tss.start_time ASC
LIMIT 10";

$stmt = mysqli_prepare($conn, $training_schedule_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$training_schedule = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Get attendance records for regular classes
$attendance_query = "SELECT 
    s.name as subject_name,
    s.code as subject_code,
    COUNT(DISTINCT acs.id) as total_classes,
    SUM(CASE WHEN aar.status = 'present' THEN 1 ELSE 0 END) as classes_attended,
    SUM(CASE WHEN aar.status = 'late' THEN 1 ELSE 0 END) as classes_late,
    SUM(CASE WHEN aar.status = 'excused' THEN 1 ELSE 0 END) as classes_excused,
    SUM(CASE WHEN aar.status = 'absent' THEN 1 ELSE 0 END) as classes_absent,
    ROUND((SUM(CASE WHEN aar.status IN ('present', 'excused') THEN 1 ELSE 0 END) / COUNT(DISTINCT acs.id)) * 100, 2) as attendance_percentage
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
JOIN academic_class_schedule acs ON sa.id = acs.assignment_id
LEFT JOIN academic_attendance_records aar ON acs.id = aar.schedule_id AND aar.student_id = ?
WHERE sa.year = ?
AND sa.section = ?
AND sa.academic_year_id = ?
AND acs.class_date <= CURDATE()
AND acs.is_cancelled = FALSE
GROUP BY s.id
ORDER BY attendance_percentage ASC";

$stmt = mysqli_prepare($conn, $attendance_query);
mysqli_stmt_bind_param($stmt, "iisi", 
    $student_id,
    $student['current_year_of_study'],
    $student['section'],
    $current_academic_year['id']
);
mysqli_stmt_execute($stmt);
$attendance_records = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Get training attendance records
$training_attendance_query = "SELECT 
    tb.batch_name,
    COUNT(DISTINCT tss.id) as total_sessions,
    SUM(CASE WHEN tar.status = 'present' THEN 1 ELSE 0 END) as sessions_attended,
    SUM(CASE WHEN tar.status = 'late' THEN 1 ELSE 0 END) as sessions_late,
    SUM(CASE WHEN tar.status = 'excused' THEN 1 ELSE 0 END) as sessions_excused,
    SUM(CASE WHEN tar.status = 'absent' THEN 1 ELSE 0 END) as sessions_absent,
    ROUND((SUM(CASE WHEN tar.status IN ('present', 'excused') THEN 1 ELSE 0 END) / COUNT(DISTINCT tss.id)) * 100, 2) as attendance_percentage
FROM training_batches tb
JOIN student_training_batch stb ON tb.id = stb.training_batch_id
JOIN training_session_schedule tss ON tb.id = tss.training_batch_id
LEFT JOIN training_attendance_records tar ON tss.id = tar.session_id AND tar.student_id = ?
WHERE stb.student_id = ?
AND stb.is_active = TRUE
AND tss.session_date <= CURDATE()
AND tss.is_cancelled = FALSE
GROUP BY tb.id
ORDER BY tb.batch_name";

$stmt = mysqli_prepare($conn, $training_attendance_query);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $student_id);
mysqli_stmt_execute($stmt);
$training_attendance = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Get recent attendance records (last 10 classes/sessions)
$recent_attendance_query = "SELECT 
    'academic' as type,
    acs.class_date as date,
    acs.start_time,
    acs.end_time,
    s.name as subject_name,
    s.code as subject_code,
    aar.status,
    aar.remarks,
    f.name as marked_by
FROM academic_attendance_records aar
JOIN academic_class_schedule acs ON aar.schedule_id = acs.id
JOIN subject_assignments sa ON acs.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
JOIN faculty f ON aar.marked_by = f.id
WHERE aar.student_id = ?
UNION ALL
SELECT 
    'training' as type,
    tss.session_date as date,
    tss.start_time,
    tss.end_time,
    tss.topic as subject_name,
    tb.batch_name as subject_code,
    tar.status,
    tar.remarks,
    f.name as marked_by
FROM training_attendance_records tar
JOIN training_session_schedule tss ON tar.session_id = tss.id
JOIN training_batches tb ON tss.training_batch_id = tb.id
JOIN faculty f ON tar.marked_by = f.id
WHERE tar.student_id = ?
ORDER BY date DESC, start_time DESC
LIMIT 10";

$stmt = mysqli_prepare($conn, $recent_attendance_query);
mysqli_stmt_bind_param($stmt, "ii", $student_id, $student_id);
mysqli_stmt_execute($stmt);
$recent_attendance = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Get attendance settings for minimum required attendance
$settings_query = "SELECT min_attendance_percentage 
                  FROM attendance_settings 
                  WHERE department_id = ? 
                  AND academic_year_id = ? 
                  LIMIT 1";
$stmt = mysqli_prepare($conn, $settings_query);
mysqli_stmt_bind_param($stmt, "ii", $student['department_id'], $current_academic_year['id']);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$settings = mysqli_fetch_assoc($result);

$min_attendance = $settings ? $settings['min_attendance_percentage'] : 75;

$page_title = "Attendance & Schedule";
include 'header.php';
?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="neu-heading">My Attendance & Schedule</h1>
        <div class="neu-btn-group">
            <a href="?view=schedule" class="neu-btn <?php echo $view_type == 'schedule' ? 'neu-btn-primary' : 'neu-btn-secondary'; ?>">
                <i class="fas fa-calendar-alt"></i> Schedule
            </a>
            <a href="?view=attendance" class="neu-btn <?php echo $view_type == 'attendance' ? 'neu-btn-primary' : 'neu-btn-secondary'; ?>">
                <i class="fas fa-clipboard-check"></i> Attendance
            </a>
            <?php if ($view_type == 'attendance'): ?>
            <button id="view-toggle" class="neu-btn neu-btn-secondary" data-view="list">
                <i class="fas fa-calendar-alt"></i> Calendar View
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($view_type == 'schedule'): ?>
        <!-- Schedule View -->
        <div class="row">
            <div class="col-md-12">
                <div class="neu-card mb-4">
                    <div class="neu-card-header d-flex justify-content-between align-items-center">
                        <h2><i class="fas fa-calendar-alt"></i> Upcoming Classes</h2>
                        <span class="neu-badge neu-badge-primary"><?php echo count($upcoming_schedule); ?> Upcoming</span>
                    </div>
                    <div class="neu-card-body">
                        <?php if (empty($upcoming_schedule)): ?>
                            <div class="alert alert-info">No upcoming classes scheduled.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Subject</th>
                                            <th>Topic</th>
                                            <th>Faculty</th>
                                            <th>Venue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($upcoming_schedule as $class): ?>
                                            <tr>
                                                <td><?php echo date('d-M-Y (D)', strtotime($class['class_date'])); ?></td>
                                                <td><?php echo date('h:i A', strtotime($class['start_time'])) . ' - ' . date('h:i A', strtotime($class['end_time'])); ?></td>
                                                <td>
                                                    <span class="fw-bold"><?php echo htmlspecialchars($class['subject_code']); ?></span><br>
                                                    <small><?php echo htmlspecialchars($class['subject_name']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($class['topic'] ?? 'Not specified'); ?></td>
                                                <td><?php echo htmlspecialchars($class['faculty_name']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($class['venue_name']); ?><br>
                                                    <small><?php echo htmlspecialchars($class['building'] . ', ' . $class['room_number']); ?></small>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($training_schedule)): ?>
                <div class="neu-card mb-4">
                    <div class="neu-card-header d-flex justify-content-between align-items-center">
                        <h2><i class="fas fa-briefcase"></i> Upcoming Training Sessions</h2>
                        <span class="neu-badge neu-badge-success"><?php echo count($training_schedule); ?> Sessions</span>
                    </div>
                    <div class="neu-card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Topic</th>
                                        <th>Trainer</th>
                                        <th>Batch</th>
                                        <th>Venue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($training_schedule as $session): ?>
                                        <tr>
                                            <td><?php echo date('d-M-Y (D)', strtotime($session['session_date'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($session['start_time'])) . ' - ' . date('h:i A', strtotime($session['end_time'])); ?></td>
                                            <td><?php echo htmlspecialchars($session['topic'] ?? 'Not specified'); ?></td>
                                            <td><?php echo htmlspecialchars($session['trainer_name']); ?></td>
                                            <td><?php echo htmlspecialchars($session['batch_name']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($session['venue_name']); ?><br>
                                                <small><?php echo htmlspecialchars($session['building'] . ', ' . $session['room_number']); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Attendance View -->
        <div id="list-view">
            <div class="row">
                <div class="col-md-6">
                    <div class="neu-card mb-4">
                        <div class="neu-card-header d-flex justify-content-between align-items-center">
                            <h2><i class="fas fa-clipboard-check"></i> Subject Attendance</h2>
                        </div>
                        <div class="neu-card-body">
                            <?php if (empty($attendance_records)): ?>
                                <div class="alert alert-info">No attendance records found.</div>
                            <?php else: ?>
                                <div class="attendance-summary">
                                    <?php foreach ($attendance_records as $record): ?>
                                        <div class="subject-attendance">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div>
                                                    <h4><?php echo htmlspecialchars($record['subject_code']); ?></h4>
                                                    <p class="text-muted mb-0"><?php echo htmlspecialchars($record['subject_name']); ?></p>
                                                </div>
                                                <div class="text-center">
                                                    <div class="attendance-percentage 
                                                        <?php 
                                                            if ($record['attendance_percentage'] >= $min_attendance) {
                                                                echo 'text-success';
                                                            } elseif ($record['attendance_percentage'] >= $min_attendance * 0.9) {
                                                                echo 'text-warning';
                                                            } else {
                                                                echo 'text-danger';
                                                            }
                                                        ?>">
                                                        <?php echo $record['attendance_percentage']; ?>%
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo $record['classes_attended']; ?>/<?php echo $record['total_classes']; ?> classes
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="progress neu-progress mb-3" style="height: 8px;">
                                                <div class="progress-bar 
                                                    <?php 
                                                        if ($record['attendance_percentage'] >= $min_attendance) {
                                                            echo 'bg-success';
                                                        } elseif ($record['attendance_percentage'] >= $min_attendance * 0.9) {
                                                            echo 'bg-warning';
                                                        } else {
                                                            echo 'bg-danger';
                                                        }
                                                    ?>"
                                                    role="progressbar" 
                                                    style="width: <?php echo $record['attendance_percentage']; ?>%;"
                                                    aria-valuenow="<?php echo $record['attendance_percentage']; ?>" 
                                                    aria-valuemin="0" 
                                                    aria-valuemax="100">
                                                </div>
                                            </div>
                                            <div class="attendance-details">
                                                <span class="attendance-tag present">
                                                    <i class="fas fa-check-circle"></i> Present: <?php echo $record['classes_attended']; ?>
                                                </span>
                                                <span class="attendance-tag late">
                                                    <i class="fas fa-clock"></i> Late: <?php echo $record['classes_late']; ?>
                                                </span>
                                                <span class="attendance-tag excused">
                                                    <i class="fas fa-calendar-check"></i> Excused: <?php echo $record['classes_excused']; ?>
                                                </span>
                                                <span class="attendance-tag absent">
                                                    <i class="fas fa-times-circle"></i> Absent: <?php echo $record['classes_absent']; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <hr>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($training_attendance)): ?>
                    <div class="neu-card mb-4">
                        <div class="neu-card-header">
                            <h2><i class="fas fa-briefcase"></i> Training Attendance</h2>
                        </div>
                        <div class="neu-card-body">
                            <div class="attendance-summary">
                                <?php foreach ($training_attendance as $record): ?>
                                    <div class="subject-attendance">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div>
                                                <h4><?php echo htmlspecialchars($record['batch_name']); ?></h4>
                                            </div>
                                            <div class="text-center">
                                                <div class="attendance-percentage 
                                                    <?php 
                                                        if ($record['attendance_percentage'] >= $min_attendance) {
                                                            echo 'text-success';
                                                        } elseif ($record['attendance_percentage'] >= $min_attendance * 0.9) {
                                                            echo 'text-warning';
                                                        } else {
                                                            echo 'text-danger';
                                                        }
                                                    ?>">
                                                    <?php echo $record['attendance_percentage']; ?>%
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo $record['sessions_attended']; ?>/<?php echo $record['total_sessions']; ?> sessions
                                                </small>
                                            </div>
                                        </div>
                                        <div class="progress neu-progress mb-3" style="height: 8px;">
                                            <div class="progress-bar 
                                                <?php 
                                                    if ($record['attendance_percentage'] >= $min_attendance) {
                                                        echo 'bg-success';
                                                    } elseif ($record['attendance_percentage'] >= $min_attendance * 0.9) {
                                                        echo 'bg-warning';
                                                    } else {
                                                        echo 'bg-danger';
                                                    }
                                                ?>"
                                                role="progressbar" 
                                                style="width: <?php echo $record['attendance_percentage']; ?>%;"
                                                aria-valuenow="<?php echo $record['attendance_percentage']; ?>" 
                                                aria-valuemin="0" 
                                                aria-valuemax="100">
                                            </div>
                                        </div>
                                        <div class="attendance-details">
                                            <span class="attendance-tag present">
                                                <i class="fas fa-check-circle"></i> Present: <?php echo $record['sessions_attended']; ?>
                                            </span>
                                            <span class="attendance-tag late">
                                                <i class="fas fa-clock"></i> Late: <?php echo $record['sessions_late']; ?>
                                            </span>
                                            <span class="attendance-tag excused">
                                                <i class="fas fa-calendar-check"></i> Excused: <?php echo $record['sessions_excused']; ?>
                                            </span>
                                            <span class="attendance-tag absent">
                                                <i class="fas fa-times-circle"></i> Absent: <?php echo $record['sessions_absent']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <hr>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <div class="neu-card mb-4">
                        <div class="neu-card-header d-flex justify-content-between align-items-center">
                            <h2><i class="fas fa-history"></i> Recent Attendance Records</h2>
                        </div>
                        <div class="neu-card-body">
                            <?php if (empty($recent_attendance)): ?>
                                <div class="alert alert-info">No recent attendance records found.</div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Subject/Session</th>
                                                <th>Status</th>
                                                <th>Marked By</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_attendance as $record): ?>
                                                <tr>
                                                    <td>
                                                        <?php echo date('d-M-Y', strtotime($record['date'])); ?><br>
                                                        <small><?php echo date('h:i A', strtotime($record['start_time'])); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="fw-bold"><?php echo htmlspecialchars($record['subject_code']); ?></span><br>
                                                        <small><?php echo htmlspecialchars($record['subject_name']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="attendance-status <?php echo $record['status']; ?>">
                                                            <?php echo ucfirst($record['status']); ?>
                                                        </span>
                                                        <?php if (!empty($record['remarks'])): ?>
                                                            <br><small><?php echo htmlspecialchars($record['remarks']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['marked_by']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="neu-card mb-4">
                        <div class="neu-card-header">
                            <h2><i class="fas fa-info-circle"></i> Attendance Information</h2>
                        </div>
                        <div class="neu-card-body">
                            <div class="attendance-info">
                                <p><i class="fas fa-check"></i> Minimum required attendance: <strong><?php echo $min_attendance; ?>%</strong></p>
                                <p><i class="fas fa-exclamation-triangle"></i> If your attendance falls below the minimum requirement, you may face academic consequences as per college policy.</p>
                                <p><i class="fas fa-calendar-plus"></i> If you need to request a leave, please submit a leave application through the Leave Application form.</p>
                                <p><i class="fas fa-question-circle"></i> For any attendance-related queries, please contact your class advisor or department office.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="calendar-view" style="display: none;">
            <!-- Calendar view will be populated with JavaScript -->
            <div class="neu-card mb-4">
                <div class="neu-card-header d-flex justify-content-between align-items-center">
                    <h2><i class="fas fa-calendar-alt"></i> Attendance Calendar</h2>
                    <div class="d-flex align-items-center">
                        <button class="neu-btn neu-btn-secondary prev-month mr-2"><i class="fas fa-chevron-left"></i> Previous</button>
                        <h5 class="current-month-display mb-0 mx-3">Loading...</h5>
                        <button class="neu-btn neu-btn-secondary next-month ml-2">Next <i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="neu-card-body">
                    <div class="calendar-container"></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    :root {
        --primary-color: #3498db;
        --secondary-color: #2ecc71;
        --warning-color: #f39c12;
        --danger-color: #e74c3c;
        --text-color: #2c3e50;
        --bg-color: #e0e5ec;
        --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                 -9px -9px 16px rgba(255,255,255, 0.5);
        --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
    }

    body {
        background: var(--bg-color);
        color: var(--text-color);
        font-family: 'Poppins', sans-serif;
    }
    
    .container {
        width: 100%;
        max-width: 1400px;
        padding-left: 15px;
        padding-right: 15px;
        margin: 0 auto;
    }
    
    .neu-card {
        background: var(--bg-color);
        border-radius: 20px;
        box-shadow: var(--shadow);
        margin-bottom: 2rem;
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .neu-card:hover {
        transform: translateY(-5px);
    }
    
    .neu-card-header {
        padding: 1.5rem 2rem;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        background: linear-gradient(145deg,rgb(58, 189, 233),rgb(222, 187, 187));
    }
    
    .neu-card-header h2 {
        margin: 0;
        font-size: 1.4rem;
        color: var(--text-color);
        font-weight: 600;
    }
    
    .neu-card-body {
        padding: 2rem;
    }
    
    .neu-badge {
        padding: 0.5rem 1rem;
        border-radius: 50px;
        font-size: 0.8rem;
        font-weight: 600;
        box-shadow: var(--shadow);
    }
    
    .neu-badge-primary {
        background: var(--primary-color);
        color: white;
    }
    
    .neu-badge-success {
        background: var(--secondary-color);
        color: white;
    }
    
    .neu-progress {
        height: 8px;
        background-color: #e0e0e0;
        border-radius: 4px;
        box-shadow: var(--inner-shadow);
        overflow: hidden;
    }
    
    .attendance-percentage {
        font-size: 1.5rem;
        font-weight: 600;
    }
    
    .text-success {
        color: var(--secondary-color) !important;
    }
    
    .text-warning {
        color: var(--warning-color) !important;
    }
    
    .text-danger {
        color: var(--danger-color) !important;
    }
    
    .attendance-details {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }
    
    .attendance-tag {
        font-size: 0.8rem;
        padding: 0.3rem 0.6rem;
        border-radius: 50px;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        box-shadow: var(--shadow);
        transition: all 0.3s ease;
    }
    
    .attendance-tag:hover {
        transform: translateY(-2px);
    }
    
    .attendance-tag.present {
        background-color: rgba(46, 204, 113, 0.1);
        color: #27ae60;
    }
    
    .attendance-tag.late {
        background-color: rgba(241, 196, 15, 0.1);
        color: #f39c12;
    }
    
    .attendance-tag.excused {
        background-color: rgba(52, 152, 219, 0.1);
        color: #2980b9;
    }
    
    .attendance-tag.absent {
        background-color: rgba(231, 76, 60, 0.1);
        color: #c0392b;
    }
    
    .attendance-status {
        padding: 0.3rem 0.8rem;
        border-radius: 50px;
        font-size: 0.85rem;
        font-weight: 600;
        box-shadow: var(--shadow);
        display: inline-block;
    }
    
    .attendance-status.present {
        background-color: rgba(46, 204, 113, 0.1);
        color: #27ae60;
    }
    
    .attendance-status.late {
        background-color: rgba(241, 196, 15, 0.1);
        color: #f39c12;
    }
    
    .attendance-status.excused {
        background-color: rgba(52, 152, 219, 0.1);
        color: #2980b9;
    }
    
    .attendance-status.absent {
        background-color: rgba(231, 76, 60, 0.1);
        color: #c0392b;
    }
    
    .attendance-info p {
        margin-bottom: 1rem;
        display: flex;
        align-items: flex-start;
        gap: 0.8rem;
        padding: 0.8rem;
        border-radius: 10px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
    }
    
    .attendance-info p i {
        margin-top: 0.2rem;
        color: var(--primary-color);
    }
    
    .subject-attendance {
        margin-bottom: 1.5rem;
        padding: 1.2rem;
        border-radius: 15px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
    }
    
    .subject-attendance:last-child {
        margin-bottom: 0;
    }
    
    .neu-btn-group {
        display: flex;
        gap: 0.5rem;
    }
    
    .neu-btn {
        padding: 0.7rem 1.4rem;
        border-radius: 50px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
        transition: all 0.3s ease;
        border: none;
        cursor: pointer;
        box-shadow: var(--shadow);
    }
    
    .neu-btn-primary {
        background: var(--primary-color);
        color: white;
    }
    
    .neu-btn-secondary {
        background: var(--bg-color);
        color: var(--text-color);
    }
    
    .neu-btn:hover {
        transform: translateY(-2px);
        box-shadow: 12px 12px 20px rgb(163,177,198,0.7),
                   -12px -12px 20px rgba(255,255,255,0.6);
    }
    
    .neu-btn.active {
        background: var(--primary-color);
        color: white;
    }
    
    /* Calendar button spacing in top navigation */
    .neu-btn-group #view-toggle {
        margin-left: 0.5rem;
    }
    
    @media (max-width: 767.98px) {
        .neu-btn-group {
            flex-wrap: wrap;
        }
        
        .neu-btn-group #view-toggle {
            margin-top: 0.5rem;
            margin-left: 0;
            width: 100%;
            justify-content: center;
        }
    }
    
    .neu-heading {
        font-size: 2rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 1.5rem;
        text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
    }
    
    hr {
        margin: 1.5rem 0;
        border: none;
        height: 1px;
        background: rgba(0,0,0,0.05);
    }
    
    .table-responsive {
        border-radius: 15px;
        overflow: hidden;
        box-shadow: var(--inner-shadow);
    }
    
    .table {
        margin-bottom: 0;
        width: 100%;
        border-collapse: collapse;
    }
    
    .table th {
        background: rgba(0,0,0,0.03);
        border-bottom: 1px solid rgba(0,0,0,0.05);
        font-weight: 600;
        color: var(--text-color);
        padding: 1.2rem 1rem;
        text-align: left;
    }
    
    .table td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .table tr:last-child td {
        border-bottom: none;
    }
    
    .table-hover tbody tr {
        transition: all 0.2s ease;
    }
    
    .table-hover tbody tr:hover {
        background-color: rgba(52, 152, 219, 0.05);
    }
    
    .alert {
        padding: 1.2rem;
        border-radius: 15px;
        margin-bottom: 1rem;
        box-shadow: var(--inner-shadow);
    }
    
    .alert-info {
        background-color: rgba(52, 152, 219, 0.1);
        color: #2980b9;
        border-left: 4px solid #3498db;
    }

    /* Row and column layout */
    .row {
        display: flex;
        flex-wrap: wrap;
        margin-right: -15px;
        margin-left: -15px;
    }
    
    [class*="col-"] {
        padding-right: 15px;
        padding-left: 15px;
        width: 100%;
    }
    
    /* Calendar styles */
    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 8px;
        margin-bottom: 20px;
    }
    
    .calendar-day {
        text-align: center;
        font-weight: bold;
        padding: 8px 0;
        background-color: var(--bg-color);
        border-radius: 5px;
        box-shadow: var(--shadow);
        margin-bottom: 8px;
    }
    
    .calendar-date {
        min-height: 90px;
        padding: 8px;
        border-radius: 8px;
        box-shadow: var(--inner-shadow);
        position: relative;
        transition: all 0.3s ease;
        overflow: hidden;
    }
    
    .calendar-date:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow);
    }
    
    .calendar-date.current-month {
        background-color: rgba(255, 255, 255, 0.3);
    }
    
    .calendar-date.other-month {
        background-color: rgba(200, 200, 200, 0.2);
        color: #999;
        min-height: 60px;
    }
    
    .calendar-date.has-attendance {
        border-left: 3px solid var(--primary-color);
    }
    
    .calendar-date.present {
        background-color: rgba(46, 204, 113, 0.15);
    }
    
    .calendar-date.absent {
        background-color: rgba(231, 76, 60, 0.15);
    }
    
    .calendar-date.late {
        background-color: rgba(241, 196, 15, 0.15);
    }
    
    .calendar-date.excused {
        background-color: rgba(52, 152, 219, 0.15);
    }
    
    .calendar-date.holiday {
        background-color: rgba(231, 74, 59, 0.15);
        border-left: 3px solid #e74a3b;
    }
    
    .date-number {
        font-weight: bold;
        margin-bottom: 8px;
        text-align: right;
        font-size: 1.1rem;
        position: relative;
        z-index: 1;
    }
    
    .attendance-item {
        font-size: 0.8rem;
        word-break: break-word;
        overflow: hidden;
        text-overflow: ellipsis;
        background-color: rgba(255, 255, 255, 0.6);
        padding: 3px 5px;
        border-radius: 3px;
        margin-bottom: 3px;
        font-weight: 500;
    }
    
    .holiday-name {
        font-size: 0.8rem;
        color: #e74a3b;
        word-break: break-word;
        overflow: hidden;
        text-overflow: ellipsis;
        background-color: rgba(255, 255, 255, 0.6);
        padding: 3px 5px;
        border-radius: 3px;
        margin-bottom: 3px;
        font-weight: 500;
    }
    
    .holiday-description {
        font-size: 0.75rem;
        color: #666;
        font-style: italic;
        background-color: rgba(255, 255, 255, 0.4);
        padding: 2px 5px;
        border-radius: 3px;
        margin-bottom: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .attendance-item.present {
        color: #27ae60;
    }
    
    .attendance-item.absent {
        color: #e74c3c;
    }
    
    .attendance-item.late {
        color: #f39c12;
    }
    
    .attendance-item.excused {
        color: #2980b9;
    }
    
    .current-month-display {
        font-size: 1.3rem;
        font-weight: 600;
        color: var(--primary-color);
    }
    
    .prev-month, .next-month {
        padding: 8px 15px;
        background-color: var(--bg-color);
        box-shadow: var(--shadow);
        border: none;
        border-radius: 8px;
        color: var(--text-color);
        transition: all 0.3s ease;
    }
    
    .prev-month:hover, .next-month:hover {
        transform: translateY(-2px);
        box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                    -6px -6px 10px rgba(255,255,255,0.6);
    }
    
    .attendance-description {
        font-size: 0.75rem;
        color: #666;
        font-style: italic;
        background-color: rgba(255, 255, 255, 0.4);
        padding: 2px 5px;
        border-radius: 3px;
        margin-bottom: 3px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Responsive classes */
    /* Extra small devices (phones) */
    @media (max-width: 575.98px) {
        .container {
            padding-left: 10px;
            padding-right: 10px;
        }
        
        .neu-card-header {
            padding: 1rem;
        }
        
        .neu-card-body {
            padding: 1rem;
        }
        
        .neu-heading {
            font-size: 1.5rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .d-flex {
            flex-direction: column;
        }
        
        .justify-content-between {
            align-items: center;
        }
        
        .neu-btn-group {
            flex-direction: column;
            width: 100%;
            margin-top: 1rem;
        }
        
        .neu-btn {
            width: 100%;
            justify-content: center;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            padding: 0.6rem 1rem;
        }
        
        .attendance-details {
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .attendance-tag {
            width: 100%;
            justify-content: space-between;
        }
        
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table th, 
        .table td {
            white-space: nowrap;
            padding: 0.7rem 0.5rem;
            font-size: 0.85rem;
        }

        .attendance-status {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        
        .subject-attendance {
            padding: 1rem;
        }
        
        .attendance-percentage {
            font-size: 1.2rem;
        }
        
        .attendance-info p {
            font-size: 0.9rem;
            padding: 0.6rem;
        }
        
        /* Calendar mobile optimizations */
        .calendar-grid {
            gap: 2px;
        }
        
        .calendar-day {
            font-size: 0.7rem;
            padding: 4px 0;
            margin-bottom: 2px;
        }
        
        .calendar-date {
            min-height: 60px;
            padding: 4px;
        }
        
        .date-number {
            font-size: 0.8rem;
            margin-bottom: 2px;
        }
        
        .holiday-name, .attendance-item {
            font-size: 0.65rem;
            padding: 2px 3px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .holiday-description, .attendance-description {
            display: none; /* Hide descriptions on mobile */
        }
        
        .current-month-display {
            font-size: 1rem;
        }
        
        .prev-month, .next-month {
            padding: 5px 10px;
            font-size: 0.8rem;
        }
    }
    
    /* Small devices (landscape phones) */
    @media (min-width: 576px) and (max-width: 767.98px) {
        .neu-card-header {
            padding: 1.2rem;
        }
        
        .neu-card-body {
            padding: 1.2rem;
        }
        
        .neu-heading {
            font-size: 1.7rem;
        }
        
        .d-flex {
            flex-wrap: wrap;
        }
        
        .neu-btn-group {
            margin-top: 0.5rem;
        }
        
        .neu-btn {
            padding: 0.6rem 1.2rem;
            font-size: 0.9rem;
        }
        
        .table th, 
        .table td {
            padding: 0.9rem 0.7rem;
        }
        
        .col-sm-6 {
            width: 50%;
        }
    
        /* Calendar tablet optimizations */
        .calendar-grid {
            gap: 4px;
        }
        
        .calendar-date {
            min-height: 70px;
            padding: 6px;
        }
        
        .holiday-name, .attendance-item {
            font-size: 0.7rem;
        }
        
        .holiday-description, .attendance-description {
            font-size: 0.65rem;
            max-height: 2.6em;
            overflow: hidden;
        }
    }
    
    /* Portrait orientation specific adjustments */
    @media (max-width: 767.98px) and (orientation: portrait) {
        .calendar-container {
            margin: 0 -10px; /* Extend calendar to edges on portrait */
        }
        
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        
        .date-number {
            position: absolute;
            top: 2px;
            right: 4px;
            background-color: rgba(255, 255, 255, 0.7);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-size: 0.7rem;
        }
        
        .calendar-date {
            position: relative;
            padding-top: 24px;
            min-height: 55px;
        }
        
        .holiday-name, .attendance-item {
            max-width: 100%;
            margin-bottom: 1px;
            font-size: 0.6rem;
        }
    }
    
    /* Landscape orientation specific adjustments */
    @media (max-width: 767.98px) and (orientation: landscape) {
        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        
        .calendar-date {
            min-height: 50px;
        }
        
        .holiday-name, .attendance-item {
            font-size: 0.65rem;
            max-width: 100%;
        }
    }
    
    /* Modal styles */
    .modal-open {
        overflow: hidden;
        padding-right: 0 !important;
    }
    
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1050;
        width: 100%;
        height: 100%;
        overflow: hidden;
        outline: 0;
        display: flex !important;
        align-items: center;
        justify-content: center;
        }
        
    .modal-dialog {
        position: relative;
        width: auto;
        margin: 0 auto;
        max-width: 500px;
        pointer-events: none;
        transition: transform 0.3s ease-out !important;
        }
        
    .modal.fade .modal-dialog {
        transform: translateY(-20px);
        }
        
    .modal.show .modal-dialog {
        transform: translateY(0);
        }
        
    .modal-content {
        position: relative;
            width: 100%;
        pointer-events: auto;
        outline: 0;
        border: none;
        box-shadow: var(--shadow);
        border-radius: 20px;
        overflow: hidden;
        }
        
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.2rem 1.5rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        background: linear-gradient(145deg, rgba(58, 189, 233, 0.8), rgba(222, 187, 187, 0.8));
        }
        
    .modal-header .modal-title {
        margin: 0;
        font-weight: 600;
        font-size: 1.2rem;
        color: var(--text-color);
        }
        
    .modal-header .close {
        width: 28px;
        height: 28px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background: var(--bg-color);
        box-shadow: var(--shadow);
        border: none;
        padding: 0;
        margin: 0;
        opacity: 1;
        transition: all 0.3s ease;
        color: var(--text-color);
        cursor: pointer;
        }
        
    .modal-header .close:hover {
        transform: translateY(-2px);
        box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                    -6px -6px 10px rgba(255,255,255,0.6);
    }
    
    .modal-header .close span {
        font-size: 1.2rem;
        font-weight: 300;
        line-height: 1;
    }
    
    .modal-body {
        padding: 1.5rem;
        background: var(--bg-color);
    }
    
    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid rgba(0, 0, 0, 0.08);
        display: flex;
        align-items: center;
        justify-content: center;
        background: var(--bg-color);
    }
    
    .modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1040;
        width: 100vw;
        height: 100vh;
        background-color: #000;
    }
    
    .modal-backdrop.fade {
        opacity: 0;
        }
        
    .modal-backdrop.show {
        opacity: 0.5;
    }
    
    @media (max-width: 575.98px) {
        .modal-dialog {
            margin: 1rem;
            max-width: calc(100% - 2rem);
        }
        
        .modal-header {
            padding: 1rem;
        }
        
        .modal-body {
            padding: 1rem;
        }
        
        .modal-footer {
            padding: 0.8rem 1rem;
        }
    }
</style>

<?php include 'footer.php'; ?> 

<!-- Add jQuery and Bootstrap JS libraries -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
    $(document).ready(function() {
        // Initialize variables for calendar
        let currentDate = new Date();
        
        // Toggle between list and calendar view
        $('#view-toggle').click(function() {
            const currentView = $(this).data('view');
            
            if (currentView === 'list') {
                $(this).data('view', 'calendar');
                $(this).html('<i class="fas fa-list"></i> List View');
                $('#list-view').hide();
                $('#calendar-view').show();
                loadCalendarView();
            } else {
                $(this).data('view', 'list');
                $(this).html('<i class="fas fa-calendar-alt"></i> Calendar View');
                $('#calendar-view').hide();
                $('#list-view').show();
            }
        });
        
        // Calendar navigation
        $('.prev-month').click(function() {
            currentDate.setMonth(currentDate.getMonth() - 1);
            loadCalendarView();
        });
        
        $('.next-month').click(function() {
            currentDate.setMonth(currentDate.getMonth() + 1);
            loadCalendarView();
        });
        
        // Function to load calendar view
        function loadCalendarView() {
            const year = currentDate.getFullYear();
            const month = currentDate.getMonth() + 1; // JavaScript months are 0-indexed
            
            // Update header
            $('.current-month-display').text(new Date(year, month - 1, 1).toLocaleString('default', { month: 'long', year: 'numeric' }));
            
            // Show loading state
            $('.calendar-container').html('<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading attendance data...</div>');
            
            // Fetch both attendance and holidays data using Promises
            const attendancePromise = $.ajax({
                url: 'get_student_attendance_by_month.php',
                method: 'POST',
                data: { year: year, month: month },
                dataType: 'json'
            });
            
            const holidaysPromise = $.ajax({
                url: 'get_student_holidays_by_month.php',
                method: 'POST',
                data: { year: year, month: month },
                dataType: 'json'
            });
            
            // Wait for both promises to resolve
            Promise.all([attendancePromise, holidaysPromise])
                .then(([attendanceResponse, holidaysResponse]) => {
                    if (attendanceResponse.status === 'success' && holidaysResponse.status === 'success') {
                        // Generate calendar with both datasets
                        generateCalendar(year, month, attendanceResponse.data, holidaysResponse.data);
                    } else {
                        $('.calendar-container').html('<div class="alert alert-danger">Failed to load calendar data</div>');
                    }
                })
                .catch(error => {
                    console.error('Error fetching calendar data:', error);
                    $('.calendar-container').html('<div class="alert alert-danger">An error occurred while fetching calendar data</div>');
                });
        }
        
        // Function to generate calendar
        function generateCalendar(year, month, attendanceData, holidaysData) {
            const container = $('.calendar-container');
            container.empty();
            
            // Create headers
            const daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            let calendarHTML = '<div class="calendar-grid">';
            
            // Add day headers
            daysOfWeek.forEach(day => {
                calendarHTML += `<div class="calendar-day">${day}</div>`;
            });
            
            // Get first day of month and total days
            const firstDayOfMonth = new Date(year, month - 1, 1).getDay();
            const lastDayOfMonth = new Date(year, month, 0).getDate();
            
            // Get last day of previous month
            const lastDayOfPrevMonth = new Date(year, month - 1, 0).getDate();
            
            // Calculate rows needed (either 5 or 6 depending on month layout)
            const totalDays = firstDayOfMonth + lastDayOfMonth;
            const rowsNeeded = Math.ceil(totalDays / 7);
            
            // Generate rows
            let day = 1;
            for (let row = 0; row < rowsNeeded; row++) {
                for (let col = 0; col < 7; col++) {
                    // Determine if we're in previous month, current month, or next month
                    let dateNum, dateClass, dateStr;
                    
                    if ((row === 0 && col < firstDayOfMonth) || day > lastDayOfMonth) {
                        if (row === 0 && col < firstDayOfMonth) {
                            // Previous month
                            dateNum = lastDayOfPrevMonth - (firstDayOfMonth - col - 1);
                            
                            const prevMonth = month === 1 ? 12 : month - 1;
                            const prevYear = month === 1 ? year - 1 : year;
                            dateStr = `${prevYear}-${prevMonth.toString().padStart(2, '0')}-${dateNum.toString().padStart(2, '0')}`;
                        } else {
                            // Next month
                            dateNum = day - lastDayOfMonth;
                            
                            const nextMonth = month === 12 ? 1 : month + 1;
                            const nextYear = month === 12 ? year + 1 : year;
                            dateStr = `${nextYear}-${nextMonth.toString().padStart(2, '0')}-${dateNum.toString().padStart(2, '0')}`;
                            
                            day++;
                        }
                        dateClass = 'other-month';
                    } else {
                        // Current month
                        dateNum = day;
                        dateStr = `${year}-${month.toString().padStart(2, '0')}-${dateNum.toString().padStart(2, '0')}`;
                        dateClass = 'current-month';
                        
                        // Check if this day is a holiday
                        if (holidaysData && holidaysData[dateStr]) {
                            dateClass += ' holiday';
                        }
                        
                        // Check if this day has attendance records
                        if (attendanceData && attendanceData[dateStr] && attendanceData[dateStr].length > 0) {
                            dateClass += ' has-attendance';
                            
                            // Determine overall attendance status for the day
                            const statuses = attendanceData[dateStr].map(record => record.status);
                            
                            if (statuses.includes('absent')) {
                                dateClass += ' absent';
                            } else if (statuses.includes('late')) {
                                dateClass += ' late';
                            } else if (statuses.includes('excused')) {
                                dateClass += ' excused';
                            } else if (statuses.includes('present')) {
                                dateClass += ' present';
                            }
                        }
                        
                        day++;
                    }
                    
                    // Create the calendar cell
                    calendarHTML += `<div class="calendar-date ${dateClass}" data-date="${dateStr}">`;
                    calendarHTML += `<div class="date-number">${dateNum}</div>`;
                    
                    // Add holiday name if present
                    if (holidaysData && holidaysData[dateStr] && dateClass.includes('current-month')) {
                        const holiday = holidaysData[dateStr];
                        calendarHTML += `<div class="holiday-name" title="${holiday.description || ''}">
                            <i class="fas fa-star"></i> ${holiday.holiday_name}
                        </div>`;
                        
                        // Show short description if available
                        if (holiday.description) {
                            const shortDesc = holiday.description.length > 30 ? 
                                holiday.description.substring(0, 30) + '...' : 
                                holiday.description;
                            calendarHTML += `<div class="holiday-description">${shortDesc}</div>`;
                        }
                    }
                    
                    // Add attendance records if present for current month
                    if (attendanceData && attendanceData[dateStr] && dateClass.includes('current-month')) {
                        attendanceData[dateStr].forEach(record => {
                            const recordType = record.type === 'academic' ? record.subject_code : record.batch_name;
                            const recordName = record.subject_name;
                            calendarHTML += `
                                <div class="attendance-item ${record.status}" title="${recordName} (${record.start_time} - ${record.end_time})">
                                    <i class="fas fa-${getStatusIcon(record.status)}"></i> ${recordType}: ${record.status}
                                </div>
                            `;
                            
                            // Show remarks if available
                            if (record.remarks) {
                                const shortRemarks = record.remarks.length > 30 ? 
                                    record.remarks.substring(0, 30) + '...' : 
                                    record.remarks;
                                calendarHTML += `<div class="attendance-description">${shortRemarks}</div>`;
                            }
                        });
                    }
                    
                    calendarHTML += '</div>';
                }
            }
            
            calendarHTML += '</div>';
            container.html(calendarHTML);
            
            // Add click event to calendar cells with attendance
            $('.calendar-date.has-attendance').click(function() {
                const dateStr = $(this).data('date');
                if (attendanceData[dateStr]) {
                    showAttendanceDetails(dateStr, attendanceData[dateStr]);
                }
            });
            
            // Add click event to calendar cells with holidays
            $('.calendar-date.holiday').click(function() {
                const dateStr = $(this).data('date');
                if (holidaysData[dateStr]) {
                    showHolidayDetails(dateStr, holidaysData[dateStr]);
                }
            });
        }
        
        // Function to get icon for attendance status
        function getStatusIcon(status) {
            switch (status) {
                case 'present':
                    return 'check-circle';
                case 'absent':
                    return 'times-circle';
                case 'late':
                    return 'clock';
                case 'excused':
                    return 'calendar-check';
                default:
                    return 'question-circle';
            }
        }
        
        // Function to show attendance details for a specific day
        function showAttendanceDetails(date, records) {
            // Format the date
            const formattedDate = new Date(date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Create modal content
            let modalContent = `
                <div class="modal fade" id="attendanceDetailsModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Attendance for ${formattedDate}</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <div class="table-responsive" style="border-radius: 12px; overflow: hidden; box-shadow: var(--inner-shadow);">
                                    <table class="table table-hover" style="margin-bottom: 0;">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>Subject/Batch</th>
                                                <th>Status</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
            `;
            
            // Add each attendance record
            records.forEach(record => {
                const subjectName = record.type === 'academic' ? 
                    `${record.subject_code} - ${record.subject_name}` : 
                    `${record.batch_name} - ${record.subject_name}`;
                
                modalContent += `
                    <tr>
                        <td>${record.start_time} - ${record.end_time}</td>
                        <td>${subjectName}</td>
                        <td>
                            <span class="attendance-status ${record.status}">
                                ${record.status.charAt(0).toUpperCase() + record.status.slice(1)}
                            </span>
                        </td>
                        <td>${record.remarks || '-'}</td>
                    </tr>
                `;
            });
            
            modalContent += `
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="neu-btn neu-btn-secondary" data-dismiss="modal" id="closeAttendanceModal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove any existing modals
            $('#attendanceDetailsModal').remove();
            
            // Add modal to body
            $('body').append(modalContent);
            
            // Initialize and show the modal
            const $modal = $('#attendanceDetailsModal');
            $modal.modal('show');
            
            // Add explicit click handlers for closing
            $modal.find('.close, #closeAttendanceModal').on('click', function() {
                $modal.modal('hide');
                setTimeout(function() {
                    $modal.remove();
                }, 300);
            });
            
            // Also handle backdrop click
            $modal.on('click', function(e) {
                if ($(e.target).hasClass('modal')) {
                    $modal.modal('hide');
                    setTimeout(function() {
                        $modal.remove();
                    }, 300);
                }
            });
        }
        
        // Function to show holiday details
        function showHolidayDetails(date, holiday) {
            // Format the date
            const formattedDate = new Date(date).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            const holidayType = holiday.is_recurring == 1 ? 'Recurring Holiday' : 'One-time Holiday';
            
            // Create modal content
            let modalContent = `
                <div class="modal fade" id="holidayDetailsModal" tabindex="-1" role="dialog" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Holiday Details</h5>
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                            </div>
                            <div class="modal-body">
                                <h4 class="text-center" style="color: #e74a3b; margin-bottom: 0.5rem;">${holiday.holiday_name}</h4>
                                <p class="text-center" style="margin-bottom: 0.5rem;">${formattedDate}</p>
                                <p class="text-center" style="margin-bottom: 1rem;">
                                    <span class="badge badge-primary" style="background: #e74a3b; color: white; padding: 0.3rem 0.7rem; font-size: 0.75rem; border-radius: 50px;">${holidayType}</span>
                                </p>
                                
                                ${holiday.description ? `<div style="margin-top: 1rem; padding: 0.8rem; border-radius: 12px; background: rgba(255,255,255,0.5); box-shadow: var(--inner-shadow);">
                                    <h6 style="font-weight: 600; font-size: 0.9rem; margin-bottom: 0.5rem;">Description:</h6>
                                    <p style="font-size: 0.9rem; margin-bottom: 0;">${holiday.description}</p>
                                </div>` : ''}
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="neu-btn neu-btn-secondary" data-dismiss="modal" id="closeHolidayModal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Remove any existing modals
            $('#holidayDetailsModal').remove();
            
            // Add modal to body
            $('body').append(modalContent);
            
            // Initialize and show the modal
            const $modal = $('#holidayDetailsModal');
            $modal.modal('show');
            
            // Add explicit click handlers for closing
            $modal.find('.close, #closeHolidayModal').on('click', function() {
                $modal.modal('hide');
                setTimeout(function() {
                    $modal.remove();
                }, 300);
            });
            
            // Also handle backdrop click
            $modal.on('click', function(e) {
                if ($(e.target).hasClass('modal')) {
                    $modal.modal('hide');
                    setTimeout(function() {
                        $modal.remove();
                    }, 300);
                }
            });
        }
        
        // Function to ensure modal is centered
        function centerModalVertically() {
            $('.modal').on('show.bs.modal', function() {
                // Reset modal positioning
                $(this).css({
                    'display': 'block',
                    'margin-top': function() {
                        if ($(window).height() > $(this).find('.modal-dialog').height()) {
                            return Math.max(0, ($(window).height() - $(this).find('.modal-dialog').height()) / 2);
                        } else {
                            return 30; // Add some padding at the top when modal is taller than viewport
                        }
                    }
                });
                
                // Ensure modal is scrollable if taller than viewport
                if ($(this).find('.modal-content').height() > $(window).height() - 60) {
                    $(this).find('.modal-body').css('max-height', $(window).height() * 0.7);
                    $(this).find('.modal-body').css('overflow-y', 'auto');
                }
            });
        }
        
        // Apply modal positioning fix
        centerModalVertically();
        
        // Handle window resize
        $(window).resize(function() {
            centerModalVertically();
        });

        // Add keyboard event handler for escape key to close modals
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') {
                $('.modal').each(function() {
                    $(this).modal('hide');
                    setTimeout(() => $(this).remove(), 300);
                });
            }
        });

        // Fix for Bootstrap modals not closing
        $(document).on('click', '.modal .close, [data-dismiss="modal"]', function(e) {
            e.preventDefault();
            const $modal = $(this).closest('.modal');
            $modal.modal('hide');
            setTimeout(() => $modal.remove(), 300);
        });
    });
</script> 