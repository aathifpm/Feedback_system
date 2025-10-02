<?php
require_once 'db_connection.php';

try {
    // Read and execute the SQL file
    $sql = file_get_contents('maintenance_mode_setup.sql');
    
    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }
    
    echo "✅ Maintenance mode tables created successfully!\n";
    echo "🔧 You can now access the maintenance control panel at: admin/maintenance_control.php\n";
    echo "⚠️  Remember to delete this setup file after running it.\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up maintenance mode: " . $e->getMessage() . "\n";
}
?>