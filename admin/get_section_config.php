<?php
session_start();
require_once '../db_connection.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Section ID required']);
    exit();
}

$section_id = intval($_GET['id']);

try {
    $stmt = $pdo->prepare("SELECT * FROM feedback_section_controls WHERE id = ?");
    $stmt->execute([$section_id]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($config) {
        echo json_encode([
            'success' => true,
            'config' => $config
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Section not found'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error fetching section config: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error'
    ]);
}
?>