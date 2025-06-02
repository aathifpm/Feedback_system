<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Get counts for dashboard
$academic_query = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN class_date = CURRENT_DATE() THEN 1 END) as today,
    COUNT(CASE WHEN class_date > CURRENT_DATE() THEN 1 END) as upcoming,
    COUNT(CASE WHEN is_cancelled = TRUE THEN 1 END) as cancelled
FROM academic_class_schedule";
$academic_result = mysqli_query($conn, $academic_query);
$academic_stats = mysqli_fetch_assoc($academic_result);

$training_query = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN session_date = CURRENT_DATE() THEN 1 END) as today,
    COUNT(CASE WHEN session_date > CURRENT_DATE() THEN 1 END) as upcoming,
    COUNT(CASE WHEN is_cancelled = TRUE THEN 1 END) as cancelled
FROM training_session_schedule";
$training_result = mysqli_query($conn, $training_query);
$training_stats = mysqli_fetch_assoc($training_result);

// Get today's schedule for quick view
$today_query = "SELECT 
    'academic' COLLATE utf8mb4_unicode_ci as schedule_type,
    acs.id,
    acs.class_date as date,
    acs.start_time,
    acs.end_time,
    acs.is_cancelled,
    s.code COLLATE utf8mb4_unicode_ci as subject_code,
    s.name COLLATE utf8mb4_unicode_ci as subject_name,
    CONCAT('Year ', sa.year, ', Sem ', sa.semester, ', Section ', sa.section) COLLATE utf8mb4_unicode_ci as class_info,
    f.name COLLATE utf8mb4_unicode_ci as faculty_name,
    v.name COLLATE utf8mb4_unicode_ci as venue_name,
    v.room_number COLLATE utf8mb4_unicode_ci
FROM 
    academic_class_schedule acs
JOIN 
    subject_assignments sa ON acs.assignment_id = sa.id
JOIN 
    subjects s ON sa.subject_id = s.id
JOIN 
    faculty f ON sa.faculty_id = f.id
JOIN 
    venues v ON acs.venue_id = v.id
WHERE 
    acs.class_date = CURRENT_DATE()

UNION ALL

SELECT 
    'training' COLLATE utf8mb4_unicode_ci as schedule_type,
    tss.id,
    tss.session_date as date,
    tss.start_time,
    tss.end_time,
    tss.is_cancelled,
    '' COLLATE utf8mb4_unicode_ci as subject_code,
    tss.topic COLLATE utf8mb4_unicode_ci as subject_name,
    tb.batch_name COLLATE utf8mb4_unicode_ci as class_info,
    '' COLLATE utf8mb4_unicode_ci as faculty_name,
    v.name COLLATE utf8mb4_unicode_ci as venue_name,
    v.room_number COLLATE utf8mb4_unicode_ci
FROM 
    training_session_schedule tss
JOIN 
    training_batches tb ON tss.training_batch_id = tb.id
JOIN 
    venues v ON tss.venue_id = v.id
WHERE 
    tss.session_date = CURRENT_DATE()
ORDER BY 
    start_time";

$today_result = mysqli_query($conn, $today_query);
$today_schedules = [];
while ($row = mysqli_fetch_assoc($today_result)) {
    $today_schedules[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #4e73df;  /* Blue theme for Schedules */
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
            margin-left: 280px; /* Add margin for fixed sidebar */
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; /* Remove margin on mobile */
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #666;
        }
        
        .badge {
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-info {
            color: #36b9cc;
        }
        
        .badge-success {
            color: #1cc88a;
        }
        
        .badge-danger {
            color: #e74a3b;
        }
        
        .badge-warning {
            color: #f6c23e;
        }

        .card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border: none;
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding-bottom: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h6 {
            font-weight: 600;
            color: var(--text-color);
            font-size: 1.1rem;
            margin: 0;
        }

        .btn {
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
        }

        .btn-primary {
            color: #fff;
            background: var(--primary-color);
        }
        
        .btn-success {
            color: #fff;
            background: #1cc88a;
        }
        
        .btn-info {
            color: #fff;
            background: #36b9cc;
        }
        
        .btn-danger {
            color: #fff;
            background: #e74a3b;
        }

        .btn-sm {
            padding: 0.5rem 0.7rem;
            font-size: 0.8rem;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .today-schedules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .schedule-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            height: 100%;
        }

        .schedule-card:hover {
            transform: translateY(-5px);
        }

        .schedule-type-academic {
            border-left: 4px solid #4e73df;
        }

        .schedule-type-training {
            border-left: 4px solid #1cc88a;
        }

        .cancelled {
            background: rgba(231, 74, 59, 0.1);
            text-decoration: line-through;
        }

        .quick-links-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .quick-link-btn {
            padding: 1.5rem;
            border: none;
            border-radius: 15px;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.8rem;
            text-decoration: none;
            text-align: center;
            height: 100%;
        }

        .quick-link-btn:hover {
            transform: translateY(-5px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                        -6px -6px 10px rgba(255,255,255, 0.6);
        }
        
        .quick-link-btn i {
            font-size: 2rem;
            color: #7b8a8b;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Schedule Management</h1>
        </div>
        
        <!-- Stats Section -->
        <div class="stats-grid">
            <!-- Academic Schedule Stats -->
            <div class="stat-card">
                <div class="stat-value"><?php echo $academic_stats['total']; ?></div>
                <div class="stat-label">Academic Classes</div>
                <div class="mt-3">
                    <span class="badge badge-info"><?php echo $academic_stats['today']; ?> Today</span>
                    <span class="badge badge-success"><?php echo $academic_stats['upcoming']; ?> Upcoming</span>
                    <span class="badge badge-danger"><?php echo $academic_stats['cancelled']; ?> Cancelled</span>
                </div>
                <a href="manage_academic_schedules.php" class="btn btn-primary btn-sm mt-3">
                    <i class="fas fa-cogs"></i> Manage
                </a>
            </div>

            <!-- Training Schedule Stats -->
            <div class="stat-card">
                <div class="stat-value"><?php echo $training_stats['total']; ?></div>
                <div class="stat-label">Training Sessions</div>
                <div class="mt-3">
                    <span class="badge badge-info"><?php echo $training_stats['today']; ?> Today</span>
                    <span class="badge badge-success"><?php echo $training_stats['upcoming']; ?> Upcoming</span>
                    <span class="badge badge-danger"><?php echo $training_stats['cancelled']; ?> Cancelled</span>
                </div>
                <a href="manage_training_schedules.php" class="btn btn-success btn-sm mt-3">
                    <i class="fas fa-cogs"></i> Manage
                </a>
            </div>
        </div>
        
        <!-- Today's Schedule -->
        <div class="card">
            <div class="card-header">
                <h6 class="font-weight-bold">Today's Schedule (<?php echo date('d M Y'); ?>)</h6>
                <div>
                    <a href="manage_academic_schedules.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-graduation-cap"></i> Academic
                    </a>
                    <a href="manage_training_schedules.php?date=<?php echo date('Y-m-d'); ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-briefcase"></i> Training
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($today_schedules)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No classes or training sessions scheduled for today.
                    </div>
                <?php else: ?>
                    <div class="today-schedules-grid">
                        <?php foreach ($today_schedules as $schedule): ?>
                            <div class="schedule-card <?php echo $schedule['is_cancelled'] ? 'cancelled' : ''; ?> schedule-type-<?php echo $schedule['schedule_type']; ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h5 class="card-title mb-0">
                                        <?php if ($schedule['schedule_type'] == 'academic'): ?>
                                            <?php echo !empty($schedule['subject_code']) ? htmlspecialchars($schedule['subject_code']) . ' - ' : ''; ?>
                                            <?php echo htmlspecialchars($schedule['subject_name']); ?>
                                        <?php else: ?>
                                            <i class="fas fa-briefcase text-success mr-2"></i> 
                                            <?php echo htmlspecialchars($schedule['subject_name']); ?>
                                        <?php endif; ?>
                                    </h5>
                                    <?php if ($schedule['is_cancelled']): ?>
                                        <span class="badge badge-danger">Cancelled</span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <div>
                                        <i class="far fa-clock text-info"></i> 
                                        <?php echo date('h:i A', strtotime($schedule['start_time'])) . ' - ' . date('h:i A', strtotime($schedule['end_time'])); ?>
                                    </div>
                                    <div>
                                        <i class="fas fa-map-marker-alt text-danger"></i> 
                                        <?php echo htmlspecialchars($schedule['venue_name']); ?>
                                        <?php echo !empty($schedule['room_number']) ? ' (' . htmlspecialchars($schedule['room_number']) . ')' : ''; ?>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($schedule['schedule_type'] == 'academic'): ?>
                                        <i class="fas fa-users text-primary"></i> <?php echo htmlspecialchars($schedule['class_info']); ?>
                                        <br>
                                        <i class="fas fa-chalkboard-teacher text-success"></i> <?php echo htmlspecialchars($schedule['faculty_name']); ?>
                                    <?php else: ?>
                                        <i class="fas fa-layer-group text-primary"></i> <?php echo htmlspecialchars($schedule['class_info']); ?> (Training Batch)
                                    <?php endif; ?>
                                </div>
                                <div class="text-right mt-3">
                                    <?php if ($schedule['schedule_type'] == 'academic'): ?>
                                        <a href="manage_academic_schedules.php?edit=<?php echo $schedule['id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    <?php else: ?>
                                        <a href="manage_training_schedules.php?edit=<?php echo $schedule['id']; ?>" class="btn btn-info btn-sm">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="card">
            <div class="card-header">
                <h6 class="font-weight-bold">Quick Links</h6>
            </div>
            <div class="card-body">
                <div class="quick-links-grid">
                    <a href="manage_venues.php" class="quick-link-btn">
                        <i class="fas fa-building"></i>
                        <span>Manage Venues</span>
                    </a>
                    <a href="manage_training_batches.php" class="quick-link-btn">
                        <i class="fas fa-users"></i>
                        <span>Training Batches</span>
                    </a>
                    <a href="manage_attendance_records.php" class="quick-link-btn">
                        <i class="fas fa-clipboard-list"></i>
                        <span>Attendance Records</span>
                    </a>
                    <a href="reports.php?type=schedule" class="quick-link-btn">
                        <i class="fas fa-chart-bar"></i>
                        <span>Schedule Reports</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <?php include '../footer.php'; ?>
</body>
</html> 