<?php
session_start();
require_once '../functions.php';
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

$success = '';
$error = '';

// Get all departments
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments_result = mysqli_query($conn, $departments_query);
$departments = [];
while ($dept = mysqli_fetch_assoc($departments_result)) {
    $departments[] = $dept;
}

// Get all subjects
$subjects_query = "SELECT id, name, code FROM subjects ORDER BY name";
$subjects_result = mysqli_query($conn, $subjects_query);
$subjects = [];
while ($subject = mysqli_fetch_assoc($subjects_result)) {
    $subjects[] = $subject;
}

// Get all training batches
$batches_query = "SELECT id, batch_name FROM training_batches WHERE is_active = TRUE ORDER BY batch_name";
$batches_result = mysqli_query($conn, $batches_query);
$training_batches = [];
while ($batch = mysqli_fetch_assoc($batches_result)) {
    $training_batches[] = $batch;
}

// Default filter values
$filter_department = isset($_GET['department_id']) ? $_GET['department_id'] : '';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-7 days'));
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$filter_subject = isset($_GET['subject_id']) ? $_GET['subject_id'] : '';
$filter_training_batch = isset($_GET['training_batch_id']) ? $_GET['training_batch_id'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';

// Prepare attendance records query
$query = "";
$params = [];
$types = "";

// Create UNION query to combine academic and training attendance records
if ($filter_type == 'all' || $filter_type == 'regular') {
    $query .= "
        SELECT 
            aar.id, 
            'academic' as record_type,
            aar.status, 
            aar.created_at, 
            aar.updated_at, 
            aar.remarks,
            s.roll_number, 
            s.name as student_name, 
            s.register_number,
            f.name as marked_by_name,
            acs.class_date, 
            acs.start_time, 
            acs.end_time, 
            acs.topic,
            '' as training_batch_name,
            v.name as venue_name, 
            v.room_number,
            d.name as department_name,
            subj.name as subject_name, 
            subj.code as subject_code
        FROM 
            academic_attendance_records aar
        JOIN 
            students s ON aar.student_id = s.id
        JOIN 
            faculty f ON aar.marked_by = f.id
        JOIN 
            academic_class_schedule acs ON aar.schedule_id = acs.id
        JOIN 
            venues v ON acs.venue_id = v.id
        JOIN 
            departments d ON s.department_id = d.id
        JOIN 
            subject_assignments sa ON acs.assignment_id = sa.id
        JOIN 
            subjects subj ON sa.subject_id = subj.id
        WHERE 1=1";

    // Apply filters specific to academic records
    if (!empty($filter_department)) {
        $query .= " AND s.department_id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }

    if (!empty($filter_date_from)) {
        $query .= " AND acs.class_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }

    if (!empty($filter_date_to)) {
        $query .= " AND acs.class_date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }

    if (!empty($filter_subject)) {
        $query .= " AND sa.subject_id = ?";
        $params[] = $filter_subject;
        $types .= "i";
    }

    if (!empty($filter_status)) {
        $query .= " AND aar.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
}

// Add training attendance records if applicable
if (($filter_type == 'all' || $filter_type == 'placement') && empty($filter_subject)) {
    // If we already have academic query, add UNION
    if (!empty($query)) {
        $query .= " UNION ";
    }
    
    $query .= "
        SELECT 
            tar.id, 
            'training' as record_type,
            tar.status, 
            tar.created_at, 
            tar.updated_at, 
            tar.remarks,
            s.roll_number, 
            s.name as student_name, 
            s.register_number,
            f.name as marked_by_name,
            tss.session_date as class_date, 
            tss.start_time, 
            tss.end_time, 
            tss.topic,
            tb.batch_name as training_batch_name,
            v.name as venue_name, 
            v.room_number,
            d.name as department_name,
            'Training' as subject_name, 
            '' as subject_code
        FROM 
            training_attendance_records tar
        JOIN 
            students s ON tar.student_id = s.id
        JOIN 
            faculty f ON tar.marked_by = f.id
        JOIN 
            training_session_schedule tss ON tar.session_id = tss.id
        JOIN 
            training_batches tb ON tss.training_batch_id = tb.id
        JOIN 
            venues v ON tss.venue_id = v.id
        JOIN 
            departments d ON s.department_id = d.id
        WHERE 1=1";

    // Apply filters specific to training records
    if (!empty($filter_department)) {
        $query .= " AND s.department_id = ?";
        $params[] = $filter_department;
        $types .= "i";
    }

    if (!empty($filter_date_from)) {
        $query .= " AND tss.session_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }

    if (!empty($filter_date_to)) {
        $query .= " AND tss.session_date <= ?";
        $params[] = $filter_date_to;
        $types .= "s";
    }

    if (!empty($filter_training_batch)) {
        $query .= " AND tss.training_batch_id = ?";
        $params[] = $filter_training_batch;
        $types .= "i";
    }

    if (!empty($filter_status)) {
        $query .= " AND tar.status = ?";
        $params[] = $filter_status;
        $types .= "s";
    }
}

$query .= " ORDER BY class_date DESC, start_time DESC, roll_number ASC LIMIT 1000";

// Execute query with prepared statement
$attendance_records = [];
$stmt = mysqli_prepare($conn, $query);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $attendance_records[] = $row;
}

// Process bulk update if submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_records'])) {
    $updated_count = 0;
    $record_ids = isset($_POST['record_ids']) ? $_POST['record_ids'] : [];
    $record_types = isset($_POST['record_types']) ? $_POST['record_types'] : [];
    $record_statuses = isset($_POST['record_status']) ? $_POST['record_status'] : [];
    $record_remarks = isset($_POST['record_remarks']) ? $_POST['record_remarks'] : [];
    
    foreach ($record_ids as $record_id) {
        if (isset($record_statuses[$record_id]) && isset($record_types[$record_id])) {
            $status = $record_statuses[$record_id];
            $remark = isset($record_remarks[$record_id]) ? $record_remarks[$record_id] : '';
            $record_type = $record_types[$record_id];
            
            if ($record_type == 'academic') {
                $update_query = "UPDATE academic_attendance_records 
                                SET status = ?, remarks = ?
                                WHERE id = ?";
            } else {
                $update_query = "UPDATE training_attendance_records 
                                SET status = ?, remarks = ?
                                WHERE id = ?";
            }
            
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ssi", $status, $remark, $record_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $updated_count++;
            }
        }
    }
    
    if ($updated_count > 0) {
        $success = "$updated_count attendance records updated successfully.";
        
        // Log the action
        $admin_id = $_SESSION['user_id'];
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                    VALUES (?, 'admin', 'update_attendance_records', ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $details = json_encode(['count' => $updated_count]);
        mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
        mysqli_stmt_execute($log_stmt);
    } else {
        $error = "No records were updated.";
    }
}

// Delete attendance record
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_record'])) {
    $record_id = $_POST['record_id'];
    $record_type = $_POST['record_type'];
    
    if ($record_type == 'academic') {
        $delete_query = "DELETE FROM academic_attendance_records WHERE id = ?";
    } else {
        $delete_query = "DELETE FROM training_attendance_records WHERE id = ?";
    }
    
    $delete_stmt = mysqli_prepare($conn, $delete_query);
    mysqli_stmt_bind_param($delete_stmt, "i", $record_id);
    
    if (mysqli_stmt_execute($delete_stmt)) {
        $success = "Attendance record deleted successfully.";
        
        // Log the action
        $admin_id = $_SESSION['user_id'];
        $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                     VALUES (?, 'admin', 'delete_attendance_record', ?, 'success', ?)";
        $log_stmt = mysqli_prepare($conn, $log_query);
        $details = json_encode(['record_id' => $record_id, 'record_type' => $record_type]);
        mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
        mysqli_stmt_execute($log_stmt);
        
        // Refresh the page to update the records list
        header("Location: manage_attendance_records.php?department_id=$filter_department&date_from=$filter_date_from&date_to=$filter_date_to&subject_id=$filter_subject&training_batch_id=$filter_training_batch&status=$filter_status&type=$filter_type");
        exit();
    } else {
        $error = "Failed to delete attendance record: " . mysqli_error($conn);
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance Records - Admin Dashboard</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e3e6f0;
        }
        .btn-primary {
            background-color: #4e73df;
            border-color: #4e73df;
        }
        .btn-primary:hover {
            background-color: #2e59d9;
            border-color: #2e59d9;
        }
        .btn-success {
            background-color: #1cc88a;
            border-color: #1cc88a;
        }
        .btn-success:hover {
            background-color: #17a673;
            border-color: #17a673;
        }
        .btn-info {
            background-color: #36b9cc;
            border-color: #36b9cc;
        }
        .btn-info:hover {
            background-color: #2c9faf;
            border-color: #2c9faf;
        }
        .btn-danger {
            background-color: #e74a3b;
            border-color: #e74a3b;
        }
        .btn-danger:hover {
            background-color: #be2617;
            border-color: #be2617;
        }
        .badge-success {
            background-color: #1cc88a;
        }
        .badge-warning {
            background-color: #f6c23e;
        }
        .badge-danger {
            background-color: #e74a3b;
        }
        .badge-info {
            background-color: #36b9cc;
        }
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        .form-control {
            border-radius: 5px;
        }
        .status-select {
            padding: 3px;
            border-radius: 4px;
            border: 1px solid #ced4da;
        }
        .remarks-input {
            width: 150px;
            border-radius: 4px;
            border: 1px solid #ced4da;
            padding: 3px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-lg-12">
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Filter Attendance Records</h3>
                    </div>
                    <div class="card-body">
                        <form method="get" action="">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="department_id">Department</label>
                                        <select class="form-control" id="department_id" name="department_id">
                                            <option value="">All Departments</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>" <?php echo ($filter_department == $dept['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="date_from">From Date</label>
                                        <input type="date" class="form-control date-picker" id="date_from" name="date_from" value="<?php echo $filter_date_from; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="date_to">To Date</label>
                                        <input type="date" class="form-control date-picker" id="date_to" name="date_to" value="<?php echo $filter_date_to; ?>">
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="subject_id">Subject</label>
                                        <select class="form-control" id="subject_id" name="subject_id">
                                            <option value="">All Subjects</option>
                                            <?php foreach ($subjects as $subject): ?>
                                                <option value="<?php echo $subject['id']; ?>" <?php echo ($filter_subject == $subject['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($subject['code'] . ' - ' . $subject['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="training_batch_id">Training Batch</label>
                                        <select class="form-control" id="training_batch_id" name="training_batch_id">
                                            <option value="">All Training Batches</option>
                                            <?php foreach ($training_batches as $batch): ?>
                                                <option value="<?php echo $batch['id']; ?>" <?php echo ($filter_training_batch == $batch['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($batch['batch_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select class="form-control" id="status" name="status">
                                            <option value="">All Statuses</option>
                                            <option value="present" <?php echo ($filter_status == 'present') ? 'selected' : ''; ?>>Present</option>
                                            <option value="absent" <?php echo ($filter_status == 'absent') ? 'selected' : ''; ?>>Absent</option>
                                            <option value="late" <?php echo ($filter_status == 'late') ? 'selected' : ''; ?>>Late</option>
                                            <option value="excused" <?php echo ($filter_status == 'excused') ? 'selected' : ''; ?>>Excused</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label for="type">Class Type</label>
                                        <select class="form-control" id="type" name="type">
                                            <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>All Types</option>
                                            <option value="regular" <?php echo ($filter_type == 'regular') ? 'selected' : ''; ?>>Regular Classes</option>
                                            <option value="placement" <?php echo ($filter_type == 'placement') ? 'selected' : ''; ?>>Placement Training</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>&nbsp;</label>
                                        <button type="submit" class="btn btn-primary btn-block">
                                            <i class="fas fa-filter"></i> Apply Filters
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <h3 class="card-title">Attendance Records</h3>
                        <div>
                            <a href="#" class="btn btn-sm btn-success" onclick="document.getElementById('bulkUpdateForm').submit();">
                                <i class="fas fa-save"></i> Save Changes
                            </a>
                            <a href="#" class="btn btn-sm btn-info" id="exportBtn">
                                <i class="fas fa-file-export"></i> Export to Excel
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (count($attendance_records) > 0): ?>
                            <form id="bulkUpdateForm" method="post" action="">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped" id="attendanceTable">
                                        <thead>
                                            <tr>
                                                <th>Date</th>
                                                <th>Time</th>
                                                <th>Roll Number</th>
                                                <th>Student Name</th>
                                                <th>Department</th>
                                                <th>Subject / Training</th>
                                                <th>Venue</th>
                                                <th>Status</th>
                                                <th>Remarks</th>
                                                <th>Marked By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attendance_records as $record): ?>
                                                <tr>
                                                    <td><?php echo date('d-m-Y', strtotime($record['class_date'])); ?></td>
                                                    <td>
                                                        <?php echo date('h:i A', strtotime($record['start_time'])) . ' - ' . 
                                                                  date('h:i A', strtotime($record['end_time'])); ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['roll_number']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['department_name']); ?></td>
                                                    <td>
                                                        <?php if ($record['record_type'] == 'training'): ?>
                                                            <span class="badge badge-info">Training</span>
                                                            <?php echo htmlspecialchars($record['topic'] ?? $record['training_batch_name']); ?>
                                                        <?php else: ?>
                                                            <span class="badge badge-primary">Academic</span>
                                                            <?php echo htmlspecialchars($record['subject_name'] . ' (' . $record['subject_code'] . ')'); ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($record['venue_name']); ?>
                                                        <?php if (!empty($record['room_number'])): ?>
                                                            (<?php echo htmlspecialchars($record['room_number']); ?>)
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="record_ids[]" value="<?php echo $record['id']; ?>">
                                                        <input type="hidden" name="record_types[<?php echo $record['id']; ?>]" value="<?php echo $record['record_type']; ?>">
                                                        <select name="record_status[<?php echo $record['id']; ?>]" class="status-select">
                                                            <option value="present" <?php echo $record['status'] == 'present' ? 'selected' : ''; ?>>Present</option>
                                                            <option value="absent" <?php echo $record['status'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                            <option value="late" <?php echo $record['status'] == 'late' ? 'selected' : ''; ?>>Late</option>
                                                            <option value="excused" <?php echo $record['status'] == 'excused' ? 'selected' : ''; ?>>Excused</option>
                                                        </select>
                                                    </td>
                                                    <td>
                                                        <input type="text" name="record_remarks[<?php echo $record['id']; ?>]" 
                                                              value="<?php echo htmlspecialchars($record['remarks'] ?? ''); ?>" 
                                                              class="remarks-input" placeholder="Remarks">
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['marked_by_name']); ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-danger delete-record" 
                                                               data-id="<?php echo $record['id']; ?>" 
                                                               data-type="<?php echo $record['record_type']; ?>"
                                                               data-roll="<?php echo htmlspecialchars($record['roll_number']); ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <input type="hidden" name="update_records" value="1">
                            </form>
                        <?php else: ?>
                            <div class="alert alert-info">
                                No attendance records found matching the selected criteria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm Delete</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this attendance record? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <form method="post" action="" id="deleteForm">
                        <input type="hidden" name="record_id" id="recordToDelete" value="">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_record" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/dataTables.bootstrap4.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.6.5/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        $(document).ready(function() {
            $('#recordsTable').DataTable({
                "lengthMenu": [[50, 100, 200, -1], [50, 100, 200, "All"]],
                "order": [[3, "desc"], [4, "desc"]],
                "pageLength": 50
            });
            
            $('.date-picker').flatpickr({
                dateFormat: "Y-m-d"
            });
            
            // Handle delete record button click
            $('.delete-record').click(function() {
                var recordId = $(this).data('id');
                var recordType = $(this).data('type');
                var rollNumber = $(this).data('roll');
                
                if (confirm('Are you sure you want to delete the attendance record for ' + rollNumber + '?')) {
                    var form = $('<form></form>').attr({
                        method: 'post',
                        action: ''
                    }).append(
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'delete_record',
                            value: 1
                        }),
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'record_id',
                            value: recordId
                        }),
                        $('<input>').attr({
                            type: 'hidden',
                            name: 'record_type',
                            value: recordType
                        })
                    );
                    $('body').append(form);
                    form.submit();
                }
            });
        });
    </script>
</body>
</html> 