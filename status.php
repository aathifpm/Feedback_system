<?php
require_once 'db_connection.php';
require_once 'functions.php';

// Get all maintenance statuses
try {
    $query = "SELECT * FROM maintenance_mode ORDER BY module";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $statuses = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status - Panimalar Engineering College</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
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
            padding: 2rem;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
        }

        .header {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .status-grid {
            display: grid;
            gap: 1.5rem;
        }

        .status-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .status-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .status-icon {
            font-size: 1.5rem;
            width: 40px;
            text-align: center;
        }

        .status-active {
            color: var(--success-color);
        }

        .status-maintenance {
            color: var(--danger-color);
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .badge-active {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
        }

        .badge-maintenance {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
        }

        .last-updated {
            text-align: center;
            margin-top: 2rem;
            color: #666;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-heartbeat"></i> System Status</h1>
            <p>Current status of all system modules</p>
        </div>

        <div class="status-grid">
            <?php if (empty($statuses)): ?>
                <div class="status-card">
                    <div class="status-info">
                        <i class="fas fa-info-circle status-icon" style="color: var(--primary-color);"></i>
                        <div>
                            <h3>System Status</h3>
                            <p>All systems operational</p>
                        </div>
                    </div>
                    <span class="status-badge badge-active">Operational</span>
                </div>
            <?php else: ?>
                <?php foreach ($statuses as $status): ?>
                    <div class="status-card">
                        <div class="status-info">
                            <i class="fas fa-<?php echo $status['is_active'] ? 'exclamation-triangle status-maintenance' : 'check-circle status-active'; ?> status-icon"></i>
                            <div>
                                <h3><?php echo ucfirst($status['module']); ?> Module</h3>
                                <p><?php echo $status['is_active'] ? htmlspecialchars($status['message']) : 'All systems operational'; ?></p>
                            </div>
                        </div>
                        <span class="status-badge <?php echo $status['is_active'] ? 'badge-maintenance' : 'badge-active'; ?>">
                            <?php echo $status['is_active'] ? 'Maintenance' : 'Operational'; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="last-updated">
            <p><i class="fas fa-sync-alt"></i> Last updated: <?php echo date('M d, Y H:i:s'); ?></p>
            <p><a href="index.php" style="color: var(--primary-color); text-decoration: none;">← Back to Home</a></p>
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>