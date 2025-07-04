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
$venues = [];

// Fetch all venues
function fetchVenues($conn) {
    $query = "SELECT v.*, 
              (COUNT(DISTINCT acs.id) + COUNT(DISTINCT tss.id)) as class_count
              FROM venues v
              LEFT JOIN academic_class_schedule acs ON v.id = acs.venue_id
              LEFT JOIN training_session_schedule tss ON v.id = tss.venue_id
              GROUP BY v.id
              ORDER BY v.name";
    $result = mysqli_query($conn, $query);
    
    $venues = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $venues[] = $row;
    }
    
    return $venues;
}

// Add new venue
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_venue'])) {
    $name = trim($_POST['name']);
    $building = trim($_POST['building']);
    $room_number = trim($_POST['room_number']);
    $capacity = !empty($_POST['capacity']) ? intval($_POST['capacity']) : NULL;
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error = "Venue name is required.";
    } else {
        // Check if venue with the same name already exists
        $check_query = "SELECT id FROM venues WHERE name = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "s", $name);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A venue with this name already exists.";
        } else {
            // Insert new venue
            $insert_query = "INSERT INTO venues (name, building, room_number, capacity, description, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "sssisi", $name, $building, $room_number, $capacity, $description, $is_active);
            
            if (mysqli_stmt_execute($insert_stmt)) {
                $success = "Venue added successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'add_venue', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['venue_name' => $name]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to add venue: " . mysqli_error($conn);
            }
        }
    }
}

// Update venue
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_venue'])) {
    $venue_id = $_POST['venue_id'];
    $name = trim($_POST['name']);
    $building = trim($_POST['building']);
    $room_number = trim($_POST['room_number']);
    $capacity = !empty($_POST['capacity']) ? intval($_POST['capacity']) : NULL;
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error = "Venue name is required.";
    } else {
        // Check if venue with the same name already exists (excluding the current venue)
        $check_query = "SELECT id FROM venues WHERE name = ? AND id != ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "si", $name, $venue_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            $error = "A venue with this name already exists.";
        } else {
            // Update venue
            $update_query = "UPDATE venues 
                           SET name = ?, building = ?, room_number = ?, capacity = ?, 
                               description = ?, is_active = ? 
                           WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_query);
            mysqli_stmt_bind_param($update_stmt, "sssisii", $name, $building, $room_number, 
                                  $capacity, $description, $is_active, $venue_id);
            
            if (mysqli_stmt_execute($update_stmt)) {
                $success = "Venue updated successfully.";
                
                // Log the action
                $admin_id = $_SESSION['user_id'];
                $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                             VALUES (?, 'admin', 'update_venue', ?, 'success', ?)";
                $log_stmt = mysqli_prepare($conn, $log_query);
                $details = json_encode(['venue_id' => $venue_id, 'venue_name' => $name]);
                mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
                mysqli_stmt_execute($log_stmt);
            } else {
                $error = "Failed to update venue: " . mysqli_error($conn);
            }
        }
    }
}

// Delete venue
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_venue'])) {
    $venue_id = $_POST['venue_id'];
    
    // Check if venue is being used in class schedules (both academic and training)
    $check_query = "SELECT 
                      (SELECT COUNT(*) FROM academic_class_schedule WHERE venue_id = ?) +
                      (SELECT COUNT(*) FROM training_session_schedule WHERE venue_id = ?) 
                    AS count";
    $check_stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($check_stmt, "ii", $venue_id, $venue_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $row = mysqli_fetch_assoc($check_result);
    
    if ($row['count'] > 0) {
        $error = "Cannot delete this venue as it is being used in class schedules. You can deactivate it instead.";
    } else {
        // Delete venue
        $delete_query = "DELETE FROM venues WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_query);
        mysqli_stmt_bind_param($delete_stmt, "i", $venue_id);
        
        if (mysqli_stmt_execute($delete_stmt)) {
            $success = "Venue deleted successfully.";
            
            // Log the action
            $admin_id = $_SESSION['user_id'];
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address)
                         VALUES (?, 'admin', 'delete_venue', ?, 'success', ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $details = json_encode(['venue_id' => $venue_id]);
            mysqli_stmt_bind_param($log_stmt, "iss", $admin_id, $details, $_SERVER['REMOTE_ADDR']);
            mysqli_stmt_execute($log_stmt);
        } else {
            $error = "Failed to delete venue: " . mysqli_error($conn);
        }
    }
}

// Get venues list
$venues = fetchVenues($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Venues - Admin Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #4e73df;  /* Blue theme */
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

        .card-header h5 {
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

        .btn-secondary {
            color: #fff;
            background: #858796;
        }

        .btn-sm {
            padding: 0.5rem 0.7rem;
            font-size: 0.8rem;
        }

        .badge {
            padding: 0.3rem 0.6rem;
            border-radius: 20px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-success {
            color: #1cc88a;
        }
        
        .badge-danger {
            color: #e74a3b;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table thead th {
            background: var(--bg-color);
            color: var(--text-color);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid rgba(0,0,0,0.1);
        }

        .table tbody td {
            padding: 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .table tbody tr {
            transition: transform 0.3s ease;
        }

        .table tbody tr:hover {
            transform: translateY(-2px);
        }

        .alert {
            border-radius: 15px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: var(--shadow);
        }

        .alert-success {
            background: rgba(28, 200, 138, 0.1);
            color: #1cc88a;
        }

        .alert-danger {
            background: rgba(231, 74, 59, 0.1);
            color: #e74a3b;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-family: inherit;
            margin-bottom: 1rem;
        }

        .modal-content {
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
            border: none;
        }

        .modal-header {
            border-bottom: 1px solid rgba(0,0,0,0.1);
            background: transparent;
        }

        .modal-footer {
            border-top: 1px solid rgba(0,0,0,0.1);
            background: transparent;
        }

        .custom-control-input:checked ~ .custom-control-label::before {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="dashboard-header">
            <h1>Manage Venues</h1>
        </div>

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
                <h5 class="font-weight-bold">
                    <i class="fas fa-building mr-2"></i>Venue List
                </h5>
                <button class="btn btn-primary" data-toggle="modal" data-target="#addVenueModal">
                    <i class="fas fa-plus-circle mr-2"></i>Add New Venue
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table" id="venuesTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Building</th>
                                <th>Room Number</th>
                                <th>Capacity</th>
                                <th>Status</th>
                                <th>Classes Scheduled</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($venues as $venue): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($venue['name']); ?></td>
                                <td><?php echo htmlspecialchars($venue['building']); ?></td>
                                <td><?php echo htmlspecialchars($venue['room_number']); ?></td>
                                <td><?php echo $venue['capacity'] ? htmlspecialchars($venue['capacity']) : 'N/A'; ?></td>
                                <td>
                                    <?php if ($venue['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $venue['class_count']; ?></td>
                                <td>
                                    <button class="btn btn-info btn-sm edit-venue" 
                                            data-id="<?php echo $venue['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($venue['name']); ?>"
                                            data-building="<?php echo htmlspecialchars($venue['building']); ?>"
                                            data-room="<?php echo htmlspecialchars($venue['room_number']); ?>"
                                            data-capacity="<?php echo htmlspecialchars($venue['capacity']); ?>"
                                            data-description="<?php echo htmlspecialchars($venue['description']); ?>"
                                            data-active="<?php echo $venue['is_active']; ?>"
                                            data-toggle="modal" data-target="#editVenueModal">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    
                                    <?php if ($venue['class_count'] == 0): ?>
                                    <button class="btn btn-danger btn-sm delete-venue" 
                                            data-id="<?php echo $venue['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($venue['name']); ?>"
                                            data-toggle="modal" data-target="#deleteVenueModal">
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

    <!-- Add Venue Modal -->
    <div class="modal fade" id="addVenueModal" tabindex="-1" role="dialog" aria-labelledby="addVenueModalLabel" aria-hidden="true" style="display: none;">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addVenueModalLabel">Add New Venue</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="name">Venue Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="building">Building</label>
                            <input type="text" class="form-control" id="building" name="building">
                        </div>
                        <div class="form-group">
                            <label for="room_number">Room Number</label>
                            <input type="text" class="form-control" id="room_number" name="room_number">
                        </div>
                        <div class="form-group">
                            <label for="capacity">Capacity</label>
                            <input type="number" class="form-control" id="capacity" name="capacity" min="1">
                        </div>
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
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
                        <button type="submit" name="add_venue" class="btn btn-primary">Add Venue</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Venue Modal -->
    <div class="modal fade" id="editVenueModal" tabindex="-1" role="dialog" aria-labelledby="editVenueModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editVenueModalLabel">Edit Venue</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="edit_venue_id" name="venue_id">
                        <div class="form-group">
                            <label for="edit_name">Venue Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_building">Building</label>
                            <input type="text" class="form-control" id="edit_building" name="building">
                        </div>
                        <div class="form-group">
                            <label for="edit_room_number">Room Number</label>
                            <input type="text" class="form-control" id="edit_room_number" name="room_number">
                        </div>
                        <div class="form-group">
                            <label for="edit_capacity">Capacity</label>
                            <input type="number" class="form-control" id="edit_capacity" name="capacity" min="1">
                        </div>
                        <div class="form-group">
                            <label for="edit_description">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
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
                        <button type="submit" name="update_venue" class="btn btn-primary">Update Venue</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Venue Modal -->
    <div class="modal fade" id="deleteVenueModal" tabindex="-1" role="dialog" aria-labelledby="deleteVenueModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteVenueModalLabel">Delete Venue</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" id="delete_venue_id" name="venue_id">
                        <p>Are you sure you want to delete the venue <span id="delete_venue_name" class="font-weight-bold"></span>?</p>
                        <p class="text-danger">This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_venue" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // Hide all modals on page load
            $('.modal').modal('hide');
            
            $('#venuesTable').DataTable({
                "order": [[0, "asc"]],
                "language": {
                    "search": "Search venues:",
                    "lengthMenu": "Show _MENU_ entries",
                    "info": "Showing _START_ to _END_ of _TOTAL_ venues",
                    "infoEmpty": "Showing 0 to 0 of 0 venues",
                    "infoFiltered": "(filtered from _MAX_ total venues)"
                }
            });
            
            // Edit venue modal
            $('.edit-venue').click(function() {
                $('#edit_venue_id').val($(this).data('id'));
                $('#edit_name').val($(this).data('name'));
                $('#edit_building').val($(this).data('building'));
                $('#edit_room_number').val($(this).data('room'));
                $('#edit_capacity').val($(this).data('capacity'));
                $('#edit_description').val($(this).data('description'));
                $('#edit_is_active').prop('checked', $(this).data('active') == 1);
            });
            
            // Delete venue modal
            $('.delete-venue').click(function() {
                $('#delete_venue_id').val($(this).data('id'));
                $('#delete_venue_name').text($(this).data('name'));
            });
        });
    </script>

    <?php include '../footer.php'; ?>
</body>
</html> 