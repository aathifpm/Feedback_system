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
        <div class="row">
            <div class="col-md-6">
                <div class="neu-card mb-4">
                    <div class="neu-card-header">
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
        background: linear-gradient(145deg, #e6e6e6, #ffffff);
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
    }
    
    /* Medium devices (tablets) */
    @media (min-width: 768px) and (max-width: 991.98px) {
        .neu-card-header {
            padding: 1.2rem 1.5rem;
        }
        
        .neu-card-body {
            padding: 1.5rem;
        }
        
        .col-md-6 {
            width: 50%;
        }
        
        .col-md-12 {
            width: 100%;
        }
    }
    
    /* Large devices (desktops) */
    @media (min-width: 992px) and (max-width: 1199.98px) {
        .col-lg-4 {
            width: 33.33333%;
        }
        
        .col-lg-6 {
            width: 50%;
        }
        
        .col-lg-8 {
            width: 66.66667%;
        }
        
        .col-lg-12 {
            width: 100%;
        }
    }
    
    /* Extra large devices (large desktops) */
    @media (min-width: 1200px) {
        .container {
            padding-left: 30px;
            padding-right: 30px;
        }
        
        .col-xl-3 {
            width: 25%;
        }
        
        .col-xl-4 {
            width: 33.33333%;
        }
        
        .col-xl-6 {
            width: 50%;
        }
        
        .col-xl-8 {
            width: 66.66667%;
        }
        
        .col-xl-9 {
            width: 75%;
        }
        
        .col-xl-12 {
            width: 100%;
        }
    }
    
    /* Print styles */
    @media print {
        body {
            background: white;
            font-size: 12pt;
        }
        
        .neu-card {
            box-shadow: none;
            border: 1px solid #ddd;
            break-inside: avoid;
        }
        
        .neu-card-header {
            background: #f5f5f5;
        }
        
        .neu-btn-group, 
        .neu-btn {
            display: none;
        }
        
        .neu-heading {
            text-align: center;
            margin-bottom: 20pt;
            font-size: 18pt;
        }
        
        .attendance-tag,
        .attendance-status {
            box-shadow: none;
            border: 1px solid #ddd;
        }
        
        .table {
            border-collapse: collapse;
        }
        
        .table th,
        .table td {
            border: 1px solid #ddd;
        }
    }
</style>

<?php include 'footer.php'; ?> 