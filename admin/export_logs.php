<?php
// Enable error reporting for all environments
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../admin_login.php');
    exit();
}

// Initialize filter variables
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$action_filter = isset($_GET['action']) ? $_GET['action'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Department filter based on admin type
$department_filter = "";
$department_join = "";
$department_params = [];
$param_types = "";
$params = [];

// If department admin, restrict data to their department
if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
    $department_filter = " AND (d_s.id = ? OR d_f.id = ? OR d_h.id = ? OR ul.role = 'admin')";
    $params[] = $_SESSION['department_id'];
    $params[] = $_SESSION['department_id'];
    $params[] = $_SESSION['department_id'];
    $param_types .= "iii";
}

// Build the query
$query = "SELECT 
    ul.*,
    CASE 
        WHEN ul.role = 'student' THEN s.name
        WHEN ul.role = 'faculty' THEN f.name
        WHEN ul.role = 'hod' THEN h.name
        WHEN ul.role = 'admin' THEN a.username
        ELSE 'Unknown'
    END as user_name,
    CASE
        WHEN ul.role = 'student' THEN d_s.name
        WHEN ul.role = 'faculty' THEN d_f.name
        WHEN ul.role = 'hod' THEN d_h.name
        ELSE NULL
    END as department_name
FROM user_logs ul
LEFT JOIN students s ON ul.user_id = s.id AND ul.role = 'student'
LEFT JOIN faculty f ON ul.user_id = f.id AND ul.role = 'faculty'
LEFT JOIN hods h ON ul.user_id = h.id AND ul.role = 'hod'
LEFT JOIN admin_users a ON ul.user_id = a.id AND ul.role = 'admin'
LEFT JOIN departments d_s ON s.department_id = d_s.id
LEFT JOIN departments d_f ON f.department_id = d_f.id
LEFT JOIN departments d_h ON h.department_id = d_h.id
WHERE 1=1";

// Add filters
if (!empty($role_filter)) {
    $query .= " AND ul.role = ?";
    $params[] = $role_filter;
    $param_types .= "s";
}

if (!empty($action_filter)) {
    $query .= " AND ul.action = ?";
    $params[] = $action_filter;
    $param_types .= "s";
}

if (!empty($status_filter)) {
    $query .= " AND ul.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}

if (!empty($date_from)) {
    $query .= " AND DATE(ul.created_at) >= ?";
    $params[] = $date_from;
    $param_types .= "s";
}

if (!empty($date_to)) {
    $query .= " AND DATE(ul.created_at) <= ?";
    $params[] = $date_to;
    $param_types .= "s";
}

if (!empty($search)) {
    $query .= " AND (ul.action LIKE ? OR JSON_EXTRACT(ul.details, '$.*') LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= "ss";
}

if (!empty($department_filter)) {
    $query .= $department_filter;
}

// Add ordering
$query .= " ORDER BY ul.created_at DESC";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 50;
$offset = ($page - 1) * $records_per_page;

// Get total number of records for pagination
$count_query = "SELECT COUNT(*) as total FROM ($query) as filtered_logs";

if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $count_query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $total_result = mysqli_stmt_get_result($stmt);
    $total_row = mysqli_fetch_assoc($total_result);
    $total_records = $total_row['total'];
} else {
    $total_result = mysqli_query($conn, $count_query);
    $total_row = mysqli_fetch_assoc($total_result);
    $total_records = $total_row['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Add pagination to the main query
$query .= " LIMIT $records_per_page OFFSET $offset";

// Execute the main query
if (!empty($params)) {
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, $param_types, ...$params);
    mysqli_stmt_execute($stmt);
    $logs_result = mysqli_stmt_get_result($stmt);
} else {
    $logs_result = mysqli_query($conn, $query);
}

$logs = mysqli_fetch_all($logs_result, MYSQLI_ASSOC);

// Get unique actions for filter dropdown
$actions_query = "SELECT DISTINCT action FROM user_logs ORDER BY action";
$actions_result = mysqli_query($conn, $actions_query);
$actions = [];
while ($row = mysqli_fetch_assoc($actions_result)) {
    $actions[] = $row['action'];
}

// Check if export requested
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Remove LIMIT clause for export
    $export_query = str_replace(" LIMIT $records_per_page OFFSET $offset", "", $query);
    
    if (!empty($params)) {
        $stmt = mysqli_prepare($conn, $export_query);
        mysqli_stmt_bind_param($stmt, $param_types, ...$params);
        mysqli_stmt_execute($stmt);
        $export_result = mysqli_stmt_get_result($stmt);
    } else {
        $export_result = mysqli_query($conn, $export_query);
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="user_logs_' . date('Y-m-d') . '.csv"');
    
    // Create CSV file
    $output = fopen('php://output', 'w');
    
    // Add headers
    fputcsv($output, ['ID', 'User', 'Role', 'Department', 'Action', 'Details', 'Status', 'IP Address', 'User Agent', 'Date/Time']);
    
    // Add data rows
    while ($row = mysqli_fetch_assoc($export_result)) {
        $user_name = '';
        if ($row['role'] === 'student') {
            $user_name = $row['user_name'];
        } elseif ($row['role'] === 'faculty') {
            $user_name = $row['user_name'];
        } elseif ($row['role'] === 'hod') {
            $user_name = $row['user_name'];
        } elseif ($row['role'] === 'admin') {
            $user_name = $row['user_name'];
        }
        
        fputcsv($output, [
            $row['id'],
            $user_name,
            $row['role'],
            $row['department_name'] ?? 'N/A',
            $row['action'],
            $row['details'],
            $row['status'],
            $row['ip_address'],
            $row['user_agent'],
            $row['created_at']
        ]);
    }
    
    fclose($output);
    exit();
}

include_once "includes/header.php";
include_once "includes/sidebar.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Management - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #e74c3c;  /* Red theme for Admin */
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
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
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            padding: 2rem;
            background: var(--bg-color);
            margin-left: 280px;
        }

        .page-header {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header h1 {
            color: var(--text-color);
            font-size: 1.8rem;
        }

        .filter-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 0.9rem;
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
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

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-export {
            background: #27ae60;
            color: white;
        }

        .btn-reset {
            background: #7f8c8d;
            color: white;
        }

        .logs-container {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow-x: auto;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logs-table th {
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid rgba(0,0,0,0.1);
            font-weight: 600;
            color: var(--text-color);
        }

        .logs-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            color: var(--text-color);
        }

        .logs-table tr:hover {
            background: rgba(231, 76, 60, 0.05);
        }

        .logs-table .truncate {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .role-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .role-student {
            background: rgba(52, 152, 219, 0.2);
            color: #2980b9;
        }

        .role-faculty {
            background: rgba(155, 89, 182, 0.2);
            color: #8e44ad;
        }

        .role-admin {
            background: rgba(231, 76, 60, 0.2);
            color: #c0392b;
        }

        .role-hod {
            background: rgba(46, 204, 113, 0.2);
            color: #27ae60;
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-success {
            background: rgba(46, 204, 113, 0.2);
            color: #27ae60;
        }

        .status-failure {
            background: rgba(231, 76, 60, 0.2);
            color: #c0392b;
        }

        .details-toggle {
            background: none;
            border: none;
            color: var(--primary-color);
            cursor: pointer;
            font-size: 1.1rem;
            padding: 0.3rem;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .details-toggle:hover {
            background: rgba(231, 76, 60, 0.1);
        }

        .details-content {
            display: none;
            padding: 1rem;
            background: rgba(236, 240, 241, 0.6);
            border-radius: 10px;
            margin-top: 0.5rem;
            font-family: monospace;
            font-size: 0.9rem;
            white-space: pre-wrap;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
            gap: 0.5rem;
        }

        .page-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
        }

        .page-link:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 10px rgb(163,177,198,0.7), 
                       -6px -6px 10px rgba(255,255,255, 0.8);
        }

        .page-link.active {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--inner-shadow);
        }

        .department-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 500;
            background: rgba(52, 152, 219, 0.1);
            color: #3498db;
            margin-top: 0.3rem;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 1rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .form-actions {
                display: flex;
                flex-direction: column;
                gap: 1rem;
            }

            .logs-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="page-header">
            <div>
                <h1>User Activity Logs</h1>
                <p>View and export detailed user activity records</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="filter-container">
            <form method="GET" action="" class="filter-form">
                <div class="form-group">
                    <label for="role">Role</label>
                    <select name="role" id="role" class="form-control">
                        <option value="">All Roles</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                        <option value="faculty" <?php echo $role_filter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="hod" <?php echo $role_filter === 'hod' ? 'selected' : ''; ?>>HOD</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="action">Action</label>
                    <select name="action" id="action" class="form-control">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $action_filter === $action ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="success" <?php echo $status_filter === 'success' ? 'selected' : ''; ?>>Success</option>
                        <option value="failure" <?php echo $status_filter === 'failure' ? 'selected' : ''; ?>>Failure</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>

                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>

                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" name="search" id="search" class="form-control" placeholder="Search actions or details..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    
                    <a href="export_logs.php" class="btn btn-reset">
                        <i class="fas fa-undo"></i> Reset
                    </a>
                    
                    <button type="submit" name="export" value="csv" class="btn btn-export">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </button>
                </div>
            </form>
        </div>

        <!-- Logs Table -->
        <div class="logs-container">
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>Status</th>
                        <th>IP Address</th>
                        <th>Date/Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($log['user_name'] ?? 'Unknown'); ?>
                                    <span class="role-badge role-<?php echo $log['role']; ?>">
                                        <?php echo ucfirst(htmlspecialchars($log['role'])); ?>
                                    </span>
                                    <?php if (!empty($log['department_name'])): ?>
                                        <div class="department-badge"><?php echo htmlspecialchars($log['department_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td>
                                    <?php if (!empty($log['details'])): ?>
                                        <div class="details-container">
                                            <button class="details-toggle" onclick="toggleDetails(<?php echo $log['id']; ?>)">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                            <div id="details-<?php echo $log['id']; ?>" class="details-content">
                                                <?php 
                                                $details = json_decode($log['details'], true);
                                                if (is_array($details)) {
                                                    foreach ($details as $key => $value) {
                                                        echo htmlspecialchars($key) . ': ' . htmlspecialchars(is_string($value) || is_numeric($value) ? $value : json_encode($value)) . "\n";
                                                    }
                                                } else {
                                                    echo htmlspecialchars($log['details']);
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">No details</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $log['status']; ?>">
                                        <?php echo ucfirst(htmlspecialchars($log['status'])); ?>
                                    </span>
                                </td>
                                <td class="truncate" title="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>">
                                    <?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?>
                                </td>
                                <td><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 2rem;">No log records found matching your criteria.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&role=<?php echo urlencode($role_filter); ?>&action=<?php echo urlencode($action_filter); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>" class="page-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php endif; ?>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $start_page + 4);
                    
                    if ($end_page - $start_page < 4) {
                        $start_page = max(1, $end_page - 4);
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>&role=<?php echo urlencode($role_filter); ?>&action=<?php echo urlencode($action_filter); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>" class="page-link <?php echo $i === $page ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&role=<?php echo urlencode($role_filter); ?>&action=<?php echo urlencode($action_filter); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>" class="page-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function toggleDetails(id) {
            const detailsElement = document.getElementById('details-' + id);
            if (detailsElement.style.display === 'block') {
                detailsElement.style.display = 'none';
            } else {
                detailsElement.style.display = 'block';
            }
        }
        
        // Initialize date range if not set
        document.addEventListener('DOMContentLoaded', function() {
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            
            // If no date range is set, initialize with last 30 days
            if (dateFrom.value === '' && dateTo.value === '') {
                const today = new Date();
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(today.getDate() - 30);
                
                dateTo.value = today.toISOString().split('T')[0];
                dateFrom.value = thirtyDaysAgo.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html> 