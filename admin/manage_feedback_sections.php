<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

$admin_id = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'toggle_section':
                $section_id = intval($_POST['section_id']);
                $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
                
                $stmt = $pdo->prepare("UPDATE feedback_section_controls SET is_enabled = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$is_enabled, $section_id])) {
                    $_SESSION['success'] = "Section status updated successfully.";
                } else {
                    $_SESSION['error'] = "Failed to update section status.";
                }
                break;
                
            case 'update_section':
                $section_id = intval($_POST['section_id']);
                $academic_year_id = !empty($_POST['academic_year_id']) ? intval($_POST['academic_year_id']) : null;
                $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
                $batch_id = !empty($_POST['batch_id']) ? intval($_POST['batch_id']) : null;
                $year_of_study = !empty($_POST['year_of_study']) ? intval($_POST['year_of_study']) : null;
                $semester = !empty($_POST['semester']) ? intval($_POST['semester']) : null;
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                
                $stmt = $pdo->prepare("UPDATE feedback_section_controls SET
                    academic_year_id = ?, department_id = ?, batch_id = ?,
                    year_of_study = ?, semester = ?, start_date = ?, end_date = ?,
                    updated_at = NOW()
                    WHERE id = ?");
                
                if ($stmt->execute([$academic_year_id, $department_id, $batch_id, $year_of_study, $semester, $start_date, $end_date, $section_id])) {
                    $_SESSION['success'] = "Section configuration updated successfully.";
                } else {
                    $_SESSION['error'] = "Failed to update section configuration.";
                }
                break;
        }
        
        header('Location: manage_feedback_sections.php');
        exit();
    }
}

// Fetch all feedback sections with their current settings
$sections_query = "SELECT
    fsc.*,
    ay.year_range as academic_year_name,
    d.name as department_name,
    `by`.batch_name,
    au.username as created_by_name
FROM feedback_section_controls fsc
LEFT JOIN academic_years ay ON fsc.academic_year_id = ay.id
LEFT JOIN departments d ON fsc.department_id = d.id
LEFT JOIN batch_years `by` ON fsc.batch_id = `by`.id
LEFT JOIN admin_users au ON fsc.created_by = au.id
ORDER BY fsc.section_name";

$sections = $pdo->query($sections_query)->fetchAll(PDO::FETCH_ASSOC);

// Fetch dropdown data
$academic_years = $pdo->query("SELECT * FROM academic_years ORDER BY year_range DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$batches = $pdo->query("SELECT * FROM batch_years ORDER BY batch_name DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Feedback Sections - Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <?php include '../header.php'; ?>
    
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                     -9px -9px 16px rgba(255,255,255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            text-align: center;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .page-description {
            color: #666;
            font-size: 1.1rem;
        }

        .sections-container {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .section-card {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease;
        }

        .section-card:hover {
            transform: translateY(-3px);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .section-info h3 {
            font-size: 1.3rem;
            color: var(--text-color);
            margin-bottom: 0.3rem;
        }

        .section-info p {
            color: #666;
            font-size: 0.9rem;
        }

        .section-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
            box-shadow: var(--inner-shadow);
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: var(--shadow);
        }

        input:checked + .slider {
            background-color: var(--secondary-color);
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: var(--shadow);
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-secondary {
            background: var(--bg-color);
            color: var(--text-color);
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .section-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .detail-item {
            background: var(--card-bg);
            padding: 1rem;
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .detail-label {
            font-size: 0.8rem;
            color: #666;
            margin-bottom: 0.3rem;
        }

        .detail-value {
            font-weight: 500;
            color: var(--text-color);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background: var(--bg-color);
            margin: 5% auto;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }

        .close {
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            font-size: 1rem;
            color: var(--text-color);
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid var(--secondary-color);
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid var(--danger-color);
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-enabled {
            background: #d4edda;
            color: #155724;
        }

        .status-disabled {
            background: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .section-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .section-controls {
                justify-content: center;
            }

            .section-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cogs"></i> Manage Feedback Sections
            </h1>
            <p class="page-description">
                Control the visibility and availability of feedback sections for students
            </p>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div class="sections-container">
            <?php foreach ($sections as $section): ?>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-info">
                            <h3><?php echo htmlspecialchars($section['display_name']); ?></h3>
                            <p><?php echo htmlspecialchars($section['description']); ?></p>
                        </div>
                        <div class="section-controls">
                            <span class="status-badge <?php echo $section['is_enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo $section['is_enabled'] ? 'Enabled' : 'Disabled'; ?>
                            </span>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_section">
                                <input type="hidden" name="section_id" value="<?php echo $section['id']; ?>">
                                <label class="toggle-switch">
                                    <input type="checkbox" name="is_enabled" 
                                           <?php echo $section['is_enabled'] ? 'checked' : ''; ?>
                                           onchange="this.form.submit()">
                                    <span class="slider"></span>
                                </label>
                            </form>
                            <button class="btn btn-primary" onclick="openConfigModal(<?php echo $section['id']; ?>)">
                                <i class="fas fa-cog"></i> Configure
                            </button>
                        </div>
                    </div>

                    <div class="section-details">
                        <div class="detail-item">
                            <div class="detail-label">Academic Year</div>
                            <div class="detail-value">
                                <?php echo $section['academic_year_name'] ?: 'All Years'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Department</div>
                            <div class="detail-value">
                                <?php echo $section['department_name'] ?: 'All Departments'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Batch</div>
                            <div class="detail-value">
                                <?php echo $section['batch_name'] ?: 'All Batches'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Year of Study</div>
                            <div class="detail-value">
                                <?php echo $section['year_of_study'] ? 'Year ' . $section['year_of_study'] : 'All Years'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Semester</div>
                            <div class="detail-value">
                                <?php echo $section['semester'] ? 'Semester ' . $section['semester'] : 'All Semesters'; ?>
                            </div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Date Range</div>
                            <div class="detail-value">
                                <?php 
                                if ($section['start_date'] || $section['end_date']) {
                                    echo ($section['start_date'] ?: 'No start') . ' to ' . ($section['end_date'] ?: 'No end');
                                } else {
                                    echo 'Always Available';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Configuration Modal -->
    <div id="configModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Configure Section</h3>
                <span class="close" onclick="closeConfigModal()">&times;</span>
            </div>
            <form id="configForm" method="POST">
                <input type="hidden" name="action" value="update_section">
                <input type="hidden" name="section_id" id="modalSectionId">

                <div class="form-group">
                    <label>Academic Year (Optional)</label>
                    <select name="academic_year_id" class="form-control">
                        <option value="">All Academic Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>">
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Department (Optional)</label>
                    <select name="department_id" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>">
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Batch (Optional)</label>
                    <select name="batch_id" class="form-control">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Year of Study (Optional)</label>
                    <select name="year_of_study" class="form-control">
                        <option value="">All Years</option>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Semester (Optional)</label>
                    <select name="semester" class="form-control">
                        <option value="">All Semesters</option>
                        <?php for($i = 1; $i <= 8; $i++): ?>
                            <option value="<?php echo $i; ?>">Semester <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Start Date (Optional)</label>
                    <input type="date" name="start_date" class="form-control">
                </div>

                <div class="form-group">
                    <label>End Date (Optional)</label>
                    <input type="date" name="end_date" class="form-control">
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeConfigModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openConfigModal(sectionId) {
            document.getElementById('modalSectionId').value = sectionId;
            
            // Load current configuration
            fetch(`get_section_config.php?id=${sectionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const form = document.getElementById('configForm');
                        form.querySelector('[name="academic_year_id"]').value = data.config.academic_year_id || '';
                        form.querySelector('[name="department_id"]').value = data.config.department_id || '';
                        form.querySelector('[name="batch_id"]').value = data.config.batch_id || '';
                        form.querySelector('[name="year_of_study"]').value = data.config.year_of_study || '';
                        form.querySelector('[name="semester"]').value = data.config.semester || '';
                        form.querySelector('[name="start_date"]').value = data.config.start_date || '';
                        form.querySelector('[name="end_date"]').value = data.config.end_date || '';
                    }
                });
            
            document.getElementById('configModal').style.display = 'block';
        }

        function closeConfigModal() {
            document.getElementById('configModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('configModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>