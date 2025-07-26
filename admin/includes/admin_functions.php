<?php
/**
 * Helper functions for the admin two-tier system
 * Used to filter data based on admin type (super admin vs department admin)
 */

/**
 * Checks if the current admin is a super admin
 * 
 * @return bool True if super admin, false otherwise
 */
function is_super_admin() {
    return (isset($_SESSION['admin_type']) && $_SESSION['admin_type'] === 'super_admin');
}

/**
 * Gets the WHERE clause for department filtering based on admin type
 * 
 * @param string $table_alias The alias of the table to filter on (e.g., 's' for students)
 * @return string The WHERE clause for filtering
 */
function get_department_filter($table_alias = 'd') {
    if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
        return " AND {$table_alias}.id = " . $_SESSION['department_id'];
    }
    return "";
}

/**
 * Prepares a parameterized department filter
 * 
 * @return array [filter_string, params_array, param_types]
 */
function get_department_filter_params() {
    $filter = "";
    $params = [];
    $types = "";
    
    if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
        $filter = "WHERE d.id = ?";
        $params[] = $_SESSION['department_id'];
        $types = "i";
    }
    
    return [$filter, $params, $types];
}

/**
 * Gets the department name for the current admin
 * 
 * @param mysqli $conn The database connection
 * @return string The department name
 */
function get_admin_department_name($conn) {
    if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
        $dept_query = "SELECT name FROM departments WHERE id = ?";
        $stmt = mysqli_prepare($conn, $dept_query);
        mysqli_stmt_bind_param($stmt, "i", $_SESSION['department_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($result)) {
            return $row['name'];
        }
    }
    return "";
}

/**
 * Check if admin has access to a specific department
 * 
 * @param int $department_id The department ID to check
 * @return bool True if admin has access, false otherwise
 */
function admin_has_department_access($department_id) {
    // Super admin has access to all departments
    if (is_super_admin()) {
        return true;
    }
    
    // Department admin only has access to their department
    return (isset($_SESSION['department_id']) && $_SESSION['department_id'] == $department_id);
}

/**
 * Restricts page access to super admin only
 * Redirects to dashboard if not a super admin
 */
function require_super_admin() {
    if (!is_super_admin()) {
        // Set error message in session if needed
        $_SESSION['error_message'] = "You don't have permission to access this page. Super admin access required.";
        
        // Redirect to dashboard
        header('Location: dashboard.php');
        exit();
    }
}

/**
 * Modifies a query to include department filtering
 * 
 * @param string $query The original query
 * @param string $table_alias The table alias
 * @return string The modified query
 */
function apply_department_filter_to_query($query, $table_alias = 'd') {
    if (isset($_SESSION['department_id']) && $_SESSION['department_id'] !== NULL) {
        // Check if query already has WHERE clause
        if (stripos($query, 'WHERE') !== false) {
            return $query . " AND {$table_alias}.id = " . $_SESSION['department_id'];
        } else {
            return $query . " WHERE {$table_alias}.id = " . $_SESSION['department_id'];
        }
    }
    return $query;
}

/**
 * Checks if a date is a holiday for a specific department or batch
 * 
 * @param string $date Date to check in Y-m-d format
 * @param int|null $department_id Department ID to check, null for any department
 * @param int|null $batch_id Batch ID to check, null for any batch
 * @return array|bool Holiday information array if holiday, false if not
 */
function is_holiday($conn, $date, $department_id = null, $batch_id = null) {
    // First check for exact date matches including recurring holidays
    $query = "SELECT * FROM holidays 
              WHERE holiday_date = ? 
              AND (
                  -- No department restrictions or department is in the list
                  (applicable_departments IS NULL OR 
                   ? IS NULL OR
                   FIND_IN_SET(?, applicable_departments) > 0)
                  AND
                  -- No batch restrictions or batch is in the list
                  (applicable_batches IS NULL OR 
                   ? IS NULL OR
                   FIND_IN_SET(?, applicable_batches) > 0)
              )
              LIMIT 1";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "siiii", $date, $department_id, $department_id, $batch_id, $batch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        return $row;
    }
    
    return false;
}

/**
 * Log system events with detailed information
 * 
 * @param mysqli $conn The database connection
 * @param int $user_id ID of the user performing the action
 * @param string $role Role of the user (admin, faculty, hod, student)
 * @param string $action Short description of the action performed
 * @param array|null $details Additional details in an associative array (will be stored as JSON)
 * @param string $status Status of the action (success, failure)
 * @return int|bool The ID of the inserted log or false on failure
 */
function log_system_event($conn, $user_id, $role, $action, $details = null, $status = 'success') {
    // Get IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Get user agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Convert details to JSON if provided
    $details_json = null;
    if ($details !== null) {
        $details_json = json_encode($details);
    }
    
    // Prepare the query
    $query = "INSERT INTO user_logs (user_id, role, action, details, status, ip_address, user_agent) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
              
    $stmt = mysqli_prepare($conn, $query);
    
    if ($stmt === false) {
        return false;
    }
    
    mysqli_stmt_bind_param($stmt, "issssss", $user_id, $role, $action, $details_json, $status, $ip_address, $user_agent);
    $result = mysqli_stmt_execute($stmt);
    
    if ($result) {
        return mysqli_insert_id($conn);
    }
    
    return false;
}

/**
 * Log admin actions with standard format and details
 * 
 * @param mysqli $conn The database connection
 * @param string $action Short description of the action performed
 * @param array|null $details Additional details in an associative array
 * @param string $status Status of the action (success, failure)
 * @return int|bool The ID of the inserted log or false on failure
 */
function log_admin_action($conn, $action, $details = null, $status = 'success') {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    return log_system_event(
        $conn, 
        $_SESSION['user_id'], 
        'admin', 
        $action, 
        $details, 
        $status
    );
}

/**
 * Get holidays between a date range for a specific department or batch
 * 
 * @param string $start_date Start date in Y-m-d format
 * @param string $end_date End date in Y-m-d format
 * @param int|null $department_id Department ID to check, null for any department
 * @param int|null $batch_id Batch ID to check, null for any batch
 * @return array Array of holiday information
 */
function get_holidays_in_range($conn, $start_date, $end_date, $department_id = null, $batch_id = null) {
    $query = "SELECT * FROM holidays 
              WHERE holiday_date BETWEEN ? AND ?
              AND (
                  -- No department restrictions or department is in the list
                  (applicable_departments IS NULL OR 
                   ? IS NULL OR
                   FIND_IN_SET(?, applicable_departments) > 0)
                  AND
                  -- No batch restrictions or batch is in the list
                  (applicable_batches IS NULL OR 
                   ? IS NULL OR
                   FIND_IN_SET(?, applicable_batches) > 0)
              )
              ORDER BY holiday_date";
    
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ssiiii", $start_date, $end_date, $department_id, $department_id, $batch_id, $batch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $holidays = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $holidays[] = $row;
    }
    
    return $holidays;
}
?> 