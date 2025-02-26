<?php
// Health check script for Render deployment

// Check if database connection is working
require_once 'db_connection.php';

// If we got here, database connection is working
$status = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'database' => 'connected',
    'php_version' => PHP_VERSION
];

// Return JSON response
header('Content-Type: application/json');
echo json_encode($status);
?> 