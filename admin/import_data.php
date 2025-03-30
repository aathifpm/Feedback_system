<?php
session_start();
require_once '../db_connection.php';
require_once '../functions.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit();
}

// Check for required extensions
if (!extension_loaded('zip')) {
    die('
        <div style="text-align: center; padding: 20px; font-family: Arial, sans-serif;">
            <h2 style="color: #e74c3c;">Extension Error</h2>
            <p>The PHP ZIP extension is not enabled. Please follow these steps to enable it:</p>
            <ol style="text-align: left; max-width: 600px; margin: 20px auto;">
                <li>Open your php.ini file (usually at C:\xampp\php\php.ini)</li>
                <li>Find the line <code>;extension=zip</code></li>
                <li>Remove the semicolon to make it <code>extension=zip</code></li>
                <li>Save the file</li>
                <li>Restart your Apache server in XAMPP</li>
            </ol>
            <p>After completing these steps, refresh this page.</p>
            <button onclick="window.location.reload()" style="padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">Refresh Page</button>
        </div>
    ');
}

// Require the PhpSpreadsheet library
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

$success_msg = $error_msg = '';

// Get departments for the form and mapping
$departments_query = "SELECT id, code, name FROM departments ORDER BY name";
$departments = mysqli_query($conn, $departments_query);
$department_map = [];
while ($dept = mysqli_fetch_assoc($departments)) {
    $department_map[$dept['id']] = $dept;
}

// Debug department mapping
error_log("Available Department IDs: " . implode(", ", array_keys($department_map)));

// Get batches for the form
$batches_query = "SELECT * FROM batch_years ORDER BY batch_name DESC";
$batches = mysqli_query($conn, $batches_query);

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid Excel file.");
        }

        $import_type = $_POST['import_type'] ?? '';
        if (!in_array($import_type, ['students', 'faculty'])) {
            throw new Exception("Invalid import type selected.");
        }

        $inputFileName = $_FILES['excel_file']['tmp_name'];
        $spreadsheet = IOFactory::load($inputFileName);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        // Get headers and validate
        $headers = array_map('strtolower', array_map('trim', array_shift($rows)));
        
        // Debug headers
        error_log("Excel Headers: " . implode(", ", $headers));

        // Define required and optional fields
        $required_fields = [
            'students' => ['roll_number', 'name', 'department_id', 'batch_name'],
            'faculty' => ['faculty_id', 'name', 'department_id']
        ];

        $optional_fields = [
            'students' => ['register_number', 'email', 'section', 'phone', 'address'],
            'faculty' => ['email', 'designation', 'experience', 'qualification', 'specialization']
        ];

        // Validate required headers
        $missing_fields = [];
        foreach ($required_fields[$import_type] as $field) {
            if (!in_array($field, $headers)) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            throw new Exception("Missing required columns: " . implode(", ", $missing_fields));
        }

        // Create column index map
        $column_map = array_flip($headers);

        mysqli_begin_transaction($conn);

        if ($import_type === 'students') {
            foreach ($rows as $row_index => $row) {
                if (empty($row[$column_map['roll_number']])) continue; // Skip empty rows

                // Required fields
                $roll_number = trim($row[$column_map['roll_number']]);
                $name = trim($row[$column_map['name']]);
                $department_id = trim($row[$column_map['department_id']]);
                $batch_name = trim($row[$column_map['batch_name']]);

                // Debug department ID
                error_log("Processing Row " . ($row_index + 2) . ": Department ID = " . $department_id);
                error_log("Processing Row " . ($row_index + 2) . ": Batch Name = " . $batch_name);

                // Validate department_id
                if (!isset($department_map[$department_id])) {
                    throw new Exception(sprintf(
                        "Invalid department ID: %s at row %d. Available IDs are: %s",
                        $department_id,
                        $row_index + 2,
                        implode(", ", array_keys($department_map))
                    ));
                }

                // Optional fields with defaults
                $register_number = isset($column_map['register_number']) ? trim($row[$column_map['register_number']]) : '';
                $email = isset($column_map['email']) ? trim($row[$column_map['email']]) : '';
                $section = isset($column_map['section']) ? trim($row[$column_map['section']]) : 'A';
                $phone = isset($column_map['phone']) ? trim($row[$column_map['phone']]) : '';
                $address = isset($column_map['address']) ? trim($row[$column_map['address']]) : '';

                // Get batch_id from batch_name
                $batch_query = "SELECT id FROM batch_years WHERE batch_name = ?";
                $stmt = mysqli_prepare($conn, $batch_query);
                mysqli_stmt_bind_param($stmt, "s", $batch_name);
                mysqli_stmt_execute($stmt);
                $batch_result = mysqli_stmt_get_result($stmt);
                $batch_row = mysqli_fetch_assoc($batch_result);
                
                if (!$batch_row) {
                    throw new Exception("Invalid batch name: $batch_name at row " . ($row_index + 2) . ". Please make sure the batch exists in the system.");
                }
                $batch_id = $batch_row['id'];

                // Default password
                $default_password = password_hash("Student@123", PASSWORD_DEFAULT);

                // Check for existing student
                $check_query = "SELECT id FROM students WHERE roll_number = ? OR (register_number = ? AND register_number != '') OR (email = ? AND email != '')";
                $stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($stmt, "sss", $roll_number, $register_number, $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($result) > 0) {
                    continue; // Skip existing students
                }

                // Insert student
                $query = "INSERT INTO students (roll_number, register_number, name, email, password, 
                         department_id, batch_id, section, phone, address, is_active) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "sssssiisss", 
                    $roll_number, $register_number, $name, $email, $default_password,
                    $department_id, $batch_id, $section, $phone, $address
                );
                mysqli_stmt_execute($stmt);
            }
        } else { // faculty import
            foreach ($rows as $row_index => $row) {
                if (empty($row[$column_map['faculty_id']])) continue; // Skip empty rows

                // Required fields
                $faculty_id = trim($row[$column_map['faculty_id']]);
                $name = trim($row[$column_map['name']]);
                $department_id = trim($row[$column_map['department_id']]);

                // Debug department ID
                error_log("Processing Row " . ($row_index + 2) . ": Department ID = " . $department_id);

                // Validate department_id
                if (!isset($department_map[$department_id])) {
                    throw new Exception(sprintf(
                        "Invalid department ID: %s at row %d. Available IDs are: %s",
                        $department_id,
                        $row_index + 2,
                        implode(", ", array_keys($department_map))
                    ));
                }

                // Optional fields with defaults
                $email = isset($column_map['email']) ? trim($row[$column_map['email']]) : '';
                $designation = isset($column_map['designation']) ? trim($row[$column_map['designation']]) : '';
                $experience = isset($column_map['experience']) ? intval(trim($row[$column_map['experience']])) : 0;
                $qualification = isset($column_map['qualification']) ? trim($row[$column_map['qualification']]) : '';
                $specialization = isset($column_map['specialization']) ? trim($row[$column_map['specialization']]) : '';

                // Default password
                $default_password = password_hash("Faculty@123", PASSWORD_DEFAULT);

                // Check for existing faculty
                $check_query = "SELECT id FROM faculty WHERE faculty_id = ? OR (email = ? AND email != '')";
                $stmt = mysqli_prepare($conn, $check_query);
                mysqli_stmt_bind_param($stmt, "ss", $faculty_id, $email);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);

                if (mysqli_num_rows($result) > 0) {
                    continue; // Skip existing faculty
                }

                // Insert faculty
                $query = "INSERT INTO faculty (faculty_id, name, email, password, department_id, 
                         designation, experience, qualification, specialization, is_active) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE)";
                
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, "ssssissss", 
                    $faculty_id, $name, $email, $default_password,
                    $department_id, $designation, $experience, $qualification, $specialization
                );
                mysqli_stmt_execute($stmt);
            }
        }

        mysqli_commit($conn);
        $success_msg = "Data imported successfully!";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_msg = "Error: " . $e->getMessage();
    }
}

// Reset department result pointer for the form
mysqli_data_seek($departments, 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Data - College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <link rel="icon" href="../college_logo.png" type="image/png">
    <style>
        :root {
            --primary-color: #2ecc71;
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
            margin: 20px;
            background: var(--bg-color);
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-left: 280px; /* Add margin to accommodate fixed sidebar */
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow);
        }

        .dashboard-header h1 {
            font-size: 1.8rem;
            color: var(--text-color);
            margin: 0;
        }

        .import-section {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.8rem;
            font-weight: 500;
            color: var(--text-color);
        }

        .form-control {
            width: 100%;
            padding: 1rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            transition: all 0.3s ease;
            color: var(--text-color);
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        select.form-control {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            background: var(--bg-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1rem;
            color: var(--text-color);
        }

        .btn:hover {
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.8);
            transform: translateY(-2px);
        }

        .btn:active {
            box-shadow: var(--inner-shadow);
            transform: translateY(0);
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
        }

        .alert-success {
            color: #2ecc71;
            border-left: 4px solid #2ecc71;
        }

        .alert-danger {
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }

        .template-section {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: var(--shadow);
            margin-top: 2rem;
        }

        .template-section h3 {
            color: var(--text-color);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
        }

        .template-info {
            background: var(--bg-color);
            padding: 1.5rem;
            border-radius: 12px;
            margin: 1rem 0;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .template-info:hover {
            transform: translateY(-2px);
            box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                       -12px -12px 20px rgba(255,255,255, 0.8);
        }

        .template-info h4 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .template-info p {
            margin: 0.8rem 0;
            color: var(--text-color);
        }

        .template-info ol, .template-info ul {
            margin: 1rem 0 1rem 1.5rem;
            color: var(--text-color);
        }

        .template-info li {
            margin: 0.5rem 0;
            line-height: 1.5;
        }

        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 180px;
            border-radius: 15px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .file-upload-wrapper:hover {
            box-shadow: var(--shadow);
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }

        .upload-content {
            text-align: center;
            padding: 20px;
        }

        .upload-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .upload-text {
            color: var(--text-color);
            font-size: 1.1rem;
        }

        .upload-text span {
            color: var(--primary-color);
            font-weight: 500;
        }

        .upload-text small {
            display: block;
            margin-top: 0.5rem;
            opacity: 0.7;
        }

        @media (max-width: 768px) {
            .main-content {
                margin: 10px;
                padding: 1rem;
                margin-left: 0; /* Remove left margin on mobile */
            }

            .dashboard-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .template-section {
                padding: 1rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include_once 'includes/header.php'; ?>
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="dashboard-header">
            <h1><i class="fas fa-file-import"></i> Import Data</h1>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_msg; ?>
            </div>
        <?php endif; ?>

        <div class="import-section">
            <form method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="import_type">Select Import Type</label>
                    <select name="import_type" id="import_type" class="form-control" required>
                        <option value="">Choose type of data to import...</option>
                        <option value="students">Student Data</option>
                        <option value="faculty">Faculty Data</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Upload Excel File</label>
                    <div class="file-upload-wrapper">
                        <input type="file" name="excel_file" id="excel_file" accept=".xlsx,.xls" required>
                        <div class="upload-content">
                            <div class="upload-icon">
                                <i class="fas fa-file-excel"></i>
                            </div>
                            <div class="upload-text">
                                Drag and drop your Excel file here or <span>browse</span>
                                <small>Supported formats: .xlsx, .xls</small>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn">
                    <i class="fas fa-upload"></i> Import Data
                </button>
            </form>
        </div>

        <div class="template-section">
            <h3><i class="fas fa-info-circle"></i> Excel Template Format</h3>
            
            <div class="template-info" id="studentTemplate" style="display: none;">
                <h4><i class="fas fa-user-graduate"></i> Student Import Template</h4>
                <p>Required columns (headers must match exactly):</p>
                <ol>
                    <li><strong>roll_number</strong> - Unique identifier for each student</li>
                    <li><strong>name</strong> - Full name of the student</li>
                    <li><strong>department_id</strong> - Department ID number (see available IDs below)</li>
                    <li><strong>batch_name</strong> - Batch name (e.g., 2023-27)</li>
                </ol>
                <p>Optional columns:</p>
                <ol>
                    <li><strong>register_number</strong> - University register number</li>
                    <li><strong>email</strong> - Student's email address</li>
                    <li><strong>section</strong> - Class section (defaults to 'A')</li>
                    <li><strong>phone</strong> - Contact number</li>
                    <li><strong>address</strong> - Residential address</li>
                </ol>
                <p><i class="fas fa-building"></i> Available Department IDs:</p>
                <ul>
                    <?php 
                    foreach ($department_map as $id => $dept) {
                        echo "<li><strong>ID: {$id}</strong> - {$dept['name']} ({$dept['code']})</li>";
                    }
                    ?>
                </ul>
                <p><i class="fas fa-calendar-alt"></i> Available Batch Names:</p>
                <ul>
                    <?php 
                    mysqli_data_seek($batches, 0);
                    while ($batch = mysqli_fetch_assoc($batches)) {
                        echo "<li><strong>{$batch['batch_name']}</strong></li>";
                    }
                    ?>
                </ul>
            </div>

            <div class="template-info" id="facultyTemplate" style="display: none;">
                <h4><i class="fas fa-chalkboard-teacher"></i> Faculty Import Template</h4>
                <p>Required columns (headers must match exactly):</p>
                <ol>
                    <li><strong>faculty_id</strong> - Unique identifier for each faculty</li>
                    <li><strong>name</strong> - Full name of the faculty</li>
                    <li><strong>department_id</strong> - Department ID number (see available IDs below)</li>
                </ol>
                <p>Optional columns:</p>
                <ol>
                    <li><strong>email</strong> - Faculty's email address</li>
                    <li><strong>designation</strong> - Current position/role</li>
                    <li><strong>experience</strong> - Years of experience</li>
                    <li><strong>qualification</strong> - Academic qualifications</li>
                    <li><strong>specialization</strong> - Area of expertise</li>
                </ol>
                <p><i class="fas fa-building"></i> Available Department IDs:</p>
                <ul>
                    <?php 
                    foreach ($department_map as $id => $dept) {
                        echo "<li><strong>ID: {$id}</strong> - {$dept['name']} ({$dept['code']})</li>";
                    }
                    ?>
                </ul>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('import_type').addEventListener('change', function() {
            document.getElementById('studentTemplate').style.display = 'none';
            document.getElementById('facultyTemplate').style.display = 'none';
            
            if (this.value === 'students') {
                document.getElementById('studentTemplate').style.display = 'block';
            } else if (this.value === 'faculty') {
                document.getElementById('facultyTemplate').style.display = 'block';
            }
        });

        // File upload visual feedback
        const fileInput = document.getElementById('excel_file');
        const uploadWrapper = document.querySelector('.file-upload-wrapper');
        const uploadText = document.querySelector('.upload-text');

        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                uploadText.innerHTML = `Selected file: <span>${this.files[0].name}</span>`;
                uploadWrapper.style.borderColor = 'var(--primary-color)';
            }
        });

        uploadWrapper.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--primary-color)';
            this.style.background = 'rgba(76, 175, 80, 0.05)';
        });

        uploadWrapper.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '#e1e1e1';
            this.style.background = 'transparent';
        });

        uploadWrapper.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '#e1e1e1';
            this.style.background = 'transparent';
            
            if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                fileInput.files = e.dataTransfer.files;
                uploadText.innerHTML = `Selected file: <span>${e.dataTransfer.files[0].name}</span>`;
                uploadWrapper.style.borderColor = 'var(--primary-color)';
            }
        });
    </script>
</body>
</html> 