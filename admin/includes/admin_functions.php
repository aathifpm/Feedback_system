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
?> 