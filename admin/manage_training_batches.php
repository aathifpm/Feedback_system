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
$training_batches = [];
$academic_years = [];
$departments = [];

// Get all academic years
$academic_years_query = "SELECT id, year_range, is_current FROM academic_years ORDER BY start_date DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);
while ($year = mysqli_fetch_assoc($academic_years_result)) {
    $academic_years[] = $year;
}

// Get departments
$departments_query = "SELECT id, name FROM departments ORDER BY name";
$departments_result = mysqli_query($conn, $departments_query);
while ($dept = mysqli_fetch_assoc($departments_result)) {
    $departments[] = $dept;
}

// Get current academic year
$current_year_query = "SELECT id FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);
$current_year_id = $current_year ? $current_year['id'] : 0;

// Fetch all training batches
function fetchTrainingBatches($conn) {
    $query = "SELECT tb.*, ay.year_range, d.name as department_name,
                     (SELECT COUNT(*) FROM student_training_batch stb WHERE stb.training_batch_id = tb.id) as student_count,
                     (SELECT COUNT(*) FROM training_session_schedule tss WHERE tss.training_batch_id = tb.id) as class_count
              FROM training_batches tb
              JOIN academic_years ay ON tb.academic_year_id = ay.id
              JOIN departments d ON tb.department_id = d.id
              ORDER BY tb.is_active DESC, tb.batch_name";
    $result = mysqli_query($conn, $query);
    
    $batches = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $batches[] = $row;
    }
    
    return $batches;
}

// Add new training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_batch'])) {
    $batch_name = trim($_POST['batch_name']);
    $description = trim($_POST['description']);
    $academic_year_id = $_POST['academic_year_id'];
    $department_id = $_POST['department_id'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($batch_name) || empty($department_id)) {
        $error = "Batch name and department are required.";
    } else {
        // Check if batch with the same name already exists for this department
        $check_query = "SELECT id FROM training_batches WHERE batch_name = ? AND department_id = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "si", $batch_name, $department_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A training batch with this name already exists in the selected department.";
        } else {
            // Insert new batch
            $insert_query = "INSERT INTO training_batches (batch_name, description, academic_year_id, department_id, is_active)
                            VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "ssiis", $batch_name, $description, $academic_year_id, $department_id, $is_active);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $success = "Training batch added successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'add_training_batch', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['batch_name' => $batch_name, 'department_id' => $department_id]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to add training batch: " . mysqli_error($conn);
            }
        }
    }
}

// Update training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_batch'])) {
    $batch_id = $_POST['batch_id'];
    $batch_name = trim($_POST['batch_name']);
    $description = trim($_POST['description']);
    $academic_year_id = $_POST['academic_year_id'];
    $department_id = $_POST['department_id'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($batch_name) || empty($department_id)) {
        $error = "Batch name and department are required.";
    } else {
        // Check if batch with the same name already exists (excluding the current batch)
        $check_query = "SELECT id FROM training_batches WHERE batch_name = ? AND department_id = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "sii", $batch_name, $department_id, $batch_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A training batch with this name already exists in the selected department.";
        } else {
            // Update batch
            $update_query = "UPDATE training_batches 
                           SET batch_name = ?, description = ?, academic_year_id = ?, department_id = ?, is_active = ? 
                           WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "ssiiii", $batch_name, $description, $academic_year_id, $department_id, $is_active, $batch_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                // No need to update training_session_schedule as it references the batch by ID, not name
                
                $success = "Training batch updated successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'update_training_batch', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['batch_id' => $batch_id, 'batch_name' => $batch_name, 'department_id' => $department_id]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to update training batch: " . mysqli_error($conn);
            }
        }
    }
}

// Delete training batch
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_batch'])) {
    $batch_id = $_POST['batch_id'];
    
    // Check if batch has students or classes
    $check_query = "SELECT 
                      (SELECT COUNT(*) FROM student_training_batch WHERE training_batch_id = ?) as student_count,
                      (SELECT COUNT(*) FROM training_session_schedule WHERE training_batch_id = ?) as class_count";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $batch_id, $batch_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $counts = mysqli_fetch_assoc($check_result);
    
    if ($counts['student_count'] > 0 || $counts['class_count'] > 0) {
        $error = "Cannot delete this training batch as it has associated students or scheduled classes.";
    } else {
        // Delete batch
        $delete_query = "DELETE FROM training_batches WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $batch_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $success = "Training batch deleted successfully.";
            
            // Log the action
            $admin_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                         VALUES (?, 'admin', 'delete_training_batch', ?, 'success', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $details = json_encode(['batch_id' => $batch_id]);
            mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to delete training batch: " . mysqli_error($conn);
        }
    }
}

// Get training batches list
$training_batches = fetchTrainingBatches($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Training Batches - Admin Dashboard</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.10.22/css/dataTables.bootstrap4.min.css">
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
        .badge-danger {
            background-color: #e74a3b;
        }
        .badge-warning {
            background-color: #f6c23e;
        }
        .badge-primary {
            background-color: #4e73df;
        }
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
        }
        .form-control {
            border-radius: 5px;
        }
        .current-year {
            color: #1cc88a;
            font-weight: bold;
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
                        <div class="row align-items-center">
                            <div class="col">
                                <h5 class="m-0 font-weight-bold text-primary">
                                    <i class="fas fa-users mr-2"></i>Manage Placement Training Batches
                                </h5>
                            </div>
                            <div class="col text-right">
                                <button class="btn btn-primary" data-toggle="modal" data-target="#addBatchModal">
                                    <i class="fas fa-plus-circle mr-2"></i>Add New Training Batch
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered" id="batchesTable" width="100%" cellspacing="0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Batch Name</th>
                                        <th>Department</th>
                                        <th>Academic Year</th>
                                        <th>Description</th>
                                        <th>Students</th>
                                        <th>Classes</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($training_batches as $batch): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($batch['batch_name']); ?></td>
                                        <td><?php echo htmlspecialchars($batch['department_name']); ?></td>
                                        <td>
                                            <?php if ($batch['academic_year_id'] == $current_year_id): ?>
                                                <span class="current-year"><?php echo htmlspecialchars($batch['year_range']); ?> (Current)</span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($batch['year_range']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($batch['description']); ?></td>
                                        <td>
                                            <?php echo $batch['student_count']; ?>
                                            <?php if ($batch['student_count'] > 0): ?>
                                                <a href="view_batch_students.php?batch_id=<?php echo $batch['id']; ?>" class="ml-2 btn btn-sm btn-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo $batch['class_count']; ?>
                                            <?php if ($batch['class_count'] > 0): ?>
                                                <a href="view_batch_classes.php?batch_id=<?php echo $batch['id']; ?>" class="ml-2 btn btn-sm btn-info">
                                                    <i class="fas fa-calendar"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($batch['is_active']): ?>
                                            <span class="badge badge-success">Active</span>
                                            <?php else: ?>
                                            <span class="badge badge-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="manage_batch_students.php?batch_id=<?php echo $batch['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-user-plus"></i> Manage Students
                                            </a>
                                            <button class="btn btn-info btn-sm edit-batch" 
                                                    data-id="<?php echo $batch['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($batch['batch_name']); ?>"
                                                    data-description="<?php echo htmlspecialchars($batch['description']); ?>"
                                                    data-academic-year="<?php echo $batch['academic_year_id']; ?>"
                                                    data-department="<?php echo $batch['department_id']; ?>"
                                                    data-active="<?php echo $batch['is_active']; ?>"
                                                    data-toggle="modal" data-target="#editBatchModal">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <?php if ($batch['student_count'] == 0 && $batch['class_count'] == 0): ?>
                                            <button class="btn btn-danger btn-sm delete-batch" 
                                                    data-id="<?php echo $batch['id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($batch['batch_name']); ?>"
                                                    data-toggle="modal" data-target="#deleteBatchModal">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Batch Modal -->
    <div class="modal fade" id="addBatchModal" tabindex="-1" role="dialog" aria-labelledby="addBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addBatchModalLabel">Add New Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="department_id">Department <span class="text-danger">*</span></label>
                            <select class="form-control" id="department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="batch_name">Batch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="batch_name" name="batch_name" required>
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="academic_year_id">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="academic_year_id" name="academic_year_id" required>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['is_current']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                    <?php if ($year['is_current']): ?> (Current) <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" checked>
                                <label class="custom-control-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" name="add_batch" class="btn btn-primary">Add Batch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Batch Modal -->
    <div class="modal fade" id="editBatchModal" tabindex="-1" role="dialog" aria-labelledby="editBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editBatchModalLabel">Edit Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_batch_id" name="batch_id">
                        <div class="form-group">
                            <label for="edit_department_id">Department <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_department_id" name="department_id" required>
                                <option value="">Select Department</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_batch_name">Batch Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_batch_name" name="batch_name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_description">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="edit_academic_year_id">Academic Year <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_academic_year_id" name="academic_year_id" required>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year['id']; ?>">
                                    <?php echo htmlspecialchars($year['year_range']); ?>
                                    <?php if ($year['is_current']): ?> (Current) <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="checkbox" class="custom-control-input" id="edit_is_active" name="is_active">
                                <label class="custom-control-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                        <button type="submit" name="update_batch" class="btn btn-primary">Update Batch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Batch Modal -->
    <div class="modal fade" id="deleteBatchModal" tabindex="-1" role="dialog" aria-labelledby="deleteBatchModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteBatchModalLabel">Delete Training Batch</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_batch_id" name="batch_id">
                        <p>Are you sure you want to delete the training batch <span id="delete_batch_name" class="font-weight-bold"></span>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_batch" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.22/js/dataTables.bootstrap4.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#batchesTable').DataTable({
                "order": [[1, "asc"], [0, "asc"]],
                "language": {
                    "search": "Search batches:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ training batches",
                    "infoEmpty": "Showing 0 to 0 of 0 training batches",
                    "infoFiltered": "(filtered from _MAX_ total training batches)"
                }
            });
            
            // Edit batch modal
            $('.edit-batch').click(function() {
                $('#edit_batch_id').val($(this).data('id'));
                $('#edit_batch_name').val($(this).data('name'));
                $('#edit_description').val($(this).data('description'));
                $('#edit_academic_year_id').val($(this).data('academic-year'));
                $('#edit_department_id').val($(this).data('department'));
                $('#edit_is_active').prop('checked', $(this).data('active') == 1);
            });
            
            // Delete batch modal
            $('.delete-batch').click(function() {
                $('#delete_batch_id').val($(this).data('id'));
                $('#delete_batch_name').text($(this).data('name'));
            });
        });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html> 