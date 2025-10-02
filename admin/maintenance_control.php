<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$success = '';
$error = '';

// Define modules array
$modules = ['student', 'faculty', 'hod', 'admin', 'global'];

// Check if maintenance tables exist, create if not
try {
    $pdo->query("SELECT 1 FROM maintenance_mode LIMIT 1");
} catch (Exception $e) {
    // Create tables if they don't exist
    $sql = "
    CREATE TABLE IF NOT EXISTS maintenance_mode (
        id INT AUTO_INCREMENT PRIMARY KEY,
        module VARCHAR(50) NOT NULL UNIQUE,
        is_active BOOLEAN DEFAULT FALSE,
        message TEXT,
        start_time DATETIME NULL,
        end_time DATETIME NULL,
        updated_by INT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_module (module),
        INDEX idx_is_active (is_active),
        INDEX idx_updated_at (updated_at)
    );
    
    INSERT IGNORE INTO maintenance_mode (module, is_active, message) VALUES
    ('student', FALSE, 'Student portal is temporarily unavailable for maintenance. Please try again later.'),
    ('faculty', FALSE, 'Faculty portal is temporarily unavailable for maintenance. Please try again later.'),
    ('hod', FALSE, 'HOD portal is temporarily unavailable for maintenance. Please try again later.'),
    ('admin', FALSE, 'Admin portal is temporarily unavailable for maintenance. Please contact system administrator.'),
    ('global', FALSE, 'System is temporarily unavailable for maintenance. Please try again later.');
    ";
    
    $pdo->exec($sql);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_all_maintenance':
                    $updated_modules = [];
                    
                    foreach ($modules as $module) {
                        $is_active = isset($_POST['is_active_' . $module]) ? 1 : 0;
                        $message = trim($_POST['message_' . $module] ?? '');
                        $start_time = $_POST['start_time_' . $module] ?: null;
                        $end_time = $_POST['end_time_' . $module] ?: null;
                        
                        $query = "INSERT INTO maintenance_mode (module, is_active, message, start_time, end_time, updated_by, updated_at) 
                                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE 
                                 is_active = VALUES(is_active),
                                 message = VALUES(message),
                                 start_time = VALUES(start_time),
                                 end_time = VALUES(end_time),
                                 updated_by = VALUES(updated_by),
                                 updated_at = NOW()";
                        
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$module, $is_active, $message, $start_time, $end_time, $_SESSION['user_id']]);
                        
                        $updated_modules[] = ucfirst($module);
                    }
                    
                    $success = "Maintenance settings updated for all modules: " . implode(', ', $updated_modules);
                    break;
                    
                case 'emergency_shutdown':
                    $emergency_modules = ['student', 'faculty', 'hod']; // Don't include admin in emergency shutdown
                    $message = "Emergency maintenance in progress. Please try again later.";
                    
                    foreach ($emergency_modules as $module) {
                        
                        $query = "INSERT INTO maintenance_mode (module, is_active, message, updated_by, updated_at) 
                                 VALUES (?, 1, ?, ?, NOW())
                                 ON DUPLICATE KEY UPDATE 
                                 is_active = 1,
                                 message = VALUES(message),
                                 updated_by = VALUES(updated_by),
                                 updated_at = NOW()";
                        
                        $stmt = $pdo->prepare($query);
                        $stmt->execute([$module, $message, $_SESSION['user_id']]);
                    }
                    
                    $success = "Emergency shutdown activated for all user modules";
                    break;
                    
                case 'restore_all':
                    $query = "UPDATE maintenance_mode SET is_active = 0, updated_by = ?, updated_at = NOW()";
                    $stmt = $pdo->prepare($query);
                    $stmt->execute([$_SESSION['user_id']]);
                    
                    $success = "All modules restored from maintenance mode";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}


// Get current maintenance status
$query = "SELECT * FROM maintenance_mode ORDER BY module";
$stmt = $pdo->prepare($query);
$stmt->execute();
$maintenance_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create associative array for easier access
$status = [];
foreach ($maintenance_status as $row) {
    $status[$row['module']] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Control - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --danger-color: #e74c3c;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1), inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            --header-height: 80px;
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

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            text-align: center;
        }

        .header h1 {
            color: var(--text-color);
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .header p {
            color: #666;
            font-size: 1.1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }

        .alert.error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }

        .emergency-controls {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border-left: 5px solid var(--danger-color);
        }

        .emergency-controls h2 {
            color: var(--danger-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 50px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), -12px -12px 20px rgba(255,255,255, 0.6);
        }

        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 2rem;
        }

        .module-card {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .module-card:hover {
            transform: translateY(-5px);
        }

        .module-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .module-title {
            font-size: 1.5rem;
            color: var(--text-color);
            text-transform: capitalize;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
        }

        .status-inactive {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1), inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            font-size: 1rem;
            color: var(--text-color);
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1rem;
        }

        .checkbox {
            width: 20px;
            height: 20px;
        }

        .datetime-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .update-all-section {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-top: 2rem;
            text-align: center;
            border-left: 5px solid var(--success-color);
        }

        .update-all-section h3 {
            color: var(--success-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-update-all {
            background: linear-gradient(145deg, var(--success-color), #229954);
            color: white;
            font-size: 1.2rem;
            padding: 1.2rem 3rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .btn-update-all:hover {
            transform: translateY(-5px);
            box-shadow: 15px 15px 25px rgb(163,177,198,0.8), -15px -15px 25px rgba(255,255,255, 0.7);
            background: linear-gradient(145deg, #229954, var(--success-color));
        }

        .btn-update-all:active {
            transform: scale(0.98);
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }

        .btn-quick {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 25px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }

        .btn-enable-all {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
            border: 2px solid var(--danger-color);
        }

        .btn-disable-all {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
            border: 2px solid var(--success-color);
        }

        .btn-quick:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        @media (max-width: 768px) {
            .modules-grid {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .datetime-group {
                grid-template-columns: 1fr;
            }
            
            .btn-update-all {
                font-size: 1rem;
                padding: 1rem 2rem;
            }
            
            .quick-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
        

        <?php if ($success): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="emergency-controls">
            <h2><i class="fas fa-exclamation-triangle"></i> Emergency Controls</h2>
            <p style="margin-bottom: 1.5rem; color: #666;">Use these controls for immediate system-wide actions</p>
            
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="emergency_shutdown">
                <div class="btn-group">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to shut down all user modules? This will prevent students, faculty, and HODs from accessing the system.')">
                        <i class="fas fa-power-off"></i> Emergency Shutdown
                    </button>
                </div>
            </form>
            
            <form method="post" style="display: inline;">
                <input type="hidden" name="action" value="restore_all">
                <div class="btn-group">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Are you sure you want to restore all modules from maintenance mode?')">
                        <i class="fas fa-play"></i> Restore All Modules
                    </button>
                </div>
            </form>
        </div>

        <form method="post" id="maintenanceForm">
            <input type="hidden" name="action" value="update_all_maintenance">
            
            <div class="modules-grid">
                <?php foreach ($modules as $module): ?>
                    <div class="module-card">
                        <div class="module-header">
                            <h3 class="module-title">
                                <i class="fas fa-<?php echo $module === 'student' ? 'user-graduate' : ($module === 'faculty' ? 'chalkboard-teacher' : ($module === 'hod' ? 'user-tie' : ($module === 'admin' ? 'user-shield' : 'globe'))); ?>"></i>
                                <?php echo ucfirst($module); ?> Module
                            </h3>
                            <span class="status-badge <?php echo (isset($status[$module]) && $status[$module]['is_active']) ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo (isset($status[$module]) && $status[$module]['is_active']) ? 'Maintenance' : 'Active'; ?>
                            </span>
                        </div>

                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active_<?php echo $module; ?>" id="maintenance_<?php echo $module; ?>" class="checkbox" 
                                   <?php echo (isset($status[$module]) && $status[$module]['is_active']) ? 'checked' : ''; ?>>
                            <label for="maintenance_<?php echo $module; ?>">Enable Maintenance Mode</label>
                        </div>

                        <div class="form-group">
                            <label for="message_<?php echo $module; ?>">Maintenance Message</label>
                            <textarea name="message_<?php echo $module; ?>" id="message_<?php echo $module; ?>" class="form-control" rows="3" 
                                      placeholder="Enter maintenance message for users..."><?php echo isset($status[$module]) ? htmlspecialchars($status[$module]['message']) : ''; ?></textarea>
                        </div>

                        <div class="datetime-group">
                            <div class="form-group">
                                <label for="start_time_<?php echo $module; ?>">Start Time (Optional)</label>
                                <input type="datetime-local" name="start_time_<?php echo $module; ?>" id="start_time_<?php echo $module; ?>" class="form-control"
                                       value="<?php echo isset($status[$module]) && $status[$module]['start_time'] ? date('Y-m-d\TH:i', strtotime($status[$module]['start_time'])) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="end_time_<?php echo $module; ?>">End Time (Optional)</label>
                                <input type="datetime-local" name="end_time_<?php echo $module; ?>" id="end_time_<?php echo $module; ?>" class="form-control"
                                       value="<?php echo isset($status[$module]) && $status[$module]['end_time'] ? date('Y-m-d\TH:i', strtotime($status[$module]['end_time'])) : ''; ?>">
                            </div>
                        </div>

                        <?php if (isset($status[$module]) && $status[$module]['updated_at']): ?>
                            <p style="margin-top: 1rem; font-size: 0.9rem; color: #666; text-align: center;">
                                Last updated: <?php echo date('M d, Y H:i', strtotime($status[$module]['updated_at'])); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="update-all-section">
                <h3><i class="fas fa-cogs"></i> Bulk Actions</h3>
                <p style="margin-bottom: 1.5rem; color: #666;">Quick actions to manage all modules at once</p>
                
                <div class="quick-actions">
                    <button type="button" class="btn-quick btn-enable-all" onclick="toggleAllMaintenance(true)">
                        <i class="fas fa-toggle-on"></i> Enable All
                    </button>
                    <button type="button" class="btn-quick btn-disable-all" onclick="toggleAllMaintenance(false)">
                        <i class="fas fa-toggle-off"></i> Disable All
                    </button>
                </div>
                
                <button type="submit" class="btn-update-all">
                    <i class="fas fa-save"></i> Update All Module Settings
                </button>
            </div>
        </form>
        </div>
    </div>

    <script>
        // Toggle all maintenance modes
        function toggleAllMaintenance(enable) {
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="is_active_"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = enable;
                updateStatusBadge(checkbox);
            });
        }

        // Update status badge when checkbox changes
        function updateStatusBadge(checkbox) {
            const moduleCard = checkbox.closest('.module-card');
            const statusBadge = moduleCard.querySelector('.status-badge');
            
            if (checkbox.checked) {
                statusBadge.textContent = 'Maintenance';
                statusBadge.className = 'status-badge status-active';
            } else {
                statusBadge.textContent = 'Active';
                statusBadge.className = 'status-badge status-inactive';
            }
        }

        // Add event listeners to all checkboxes
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="is_active_"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateStatusBadge(this);
                });
            });

            // Add confirmation to form submission
            document.getElementById('maintenanceForm').addEventListener('submit', function(e) {
                const enabledModules = [];
                const checkboxes = document.querySelectorAll('input[type="checkbox"][name^="is_active_"]:checked');
                
                checkboxes.forEach(checkbox => {
                    const moduleName = checkbox.name.replace('is_active_', '');
                    enabledModules.push(moduleName.charAt(0).toUpperCase() + moduleName.slice(1));
                });

                if (enabledModules.length > 0) {
                    const message = `Are you sure you want to enable maintenance mode for: ${enabledModules.join(', ')}?\n\nThis will prevent users from accessing these modules.`;
                    if (!confirm(message)) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
        });

        // Auto-save functionality (optional)
        let autoSaveTimeout;
        function scheduleAutoSave() {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                // You can implement auto-save here if needed
                console.log('Auto-save triggered');
            }, 5000);
        }

        // Add visual feedback for form changes
        document.addEventListener('DOMContentLoaded', function() {
            const formElements = document.querySelectorAll('#maintenanceForm input, #maintenanceForm textarea');
            formElements.forEach(element => {
                element.addEventListener('change', function() {
                    this.style.borderLeft = '3px solid var(--warning-color)';
                    scheduleAutoSave();
                });
            });
        });
    </script>
</body>
</html>