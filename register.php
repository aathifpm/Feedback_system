<?php
// Add at the very top of the file
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Initialize variables
$error = '';
$success = '';
$debug_messages = [];  // Array to store debug messages

// Function to add debug messages
function addDebug($message) {
    global $debug_messages;
    $debug_messages[] = $message;
}

// Start the registration process
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        mysqli_begin_transaction($conn);
        
        $role = $_POST['role'] ?? '';
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        $department_id = filter_var($_POST['department'], FILTER_VALIDATE_INT);

        // Validate basic inputs
        if (!$role || !$email || !$password || !$department_id) {
            throw new Exception("All fields are required");
        }

        // Check email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        // Check password complexity
        if (!is_password_complex($password)) {
            throw new Exception("Password must be at least 8 characters long and contain uppercase, lowercase, numbers, and special characters");
        }

        // Check for existing email
        $email_exists = check_email_exists($conn, $email);
        if ($email_exists) {
            throw new Exception("Email already exists");
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Handle different roles
        switch ($role) {
            case 'student':
                register_student($conn, $_POST, $hashed_password);
                break;
            case 'faculty':
                register_faculty($conn, $_POST, $hashed_password);
                break;
            case 'hod':
                register_hod($conn, $_POST, $hashed_password);
                break;
            default:
                throw new Exception("Invalid role selected");
        }

        mysqli_commit($conn);
        $success = ucfirst($role) . " registered successfully!";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error = "Registration failed: " . $e->getMessage();
        addDebug("Error details: " . $e->getTraceAsString());
    }
}
    
// Helper functions
function check_email_exists($conn, $email) {
    $tables = ['students', 'faculty', 'hods'];
    foreach ($tables as $table) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM $table WHERE email = ?");
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        if (mysqli_num_rows($result) > 0) {
            return true;
        }
    }
    return false;
}

// Function to register a student
function register_student($conn, $data, $hashed_password) {
    $roll_no = htmlspecialchars(trim($data['roll_no']), ENT_QUOTES, 'UTF-8');
    $register_number = htmlspecialchars(trim($data['register_number']), ENT_QUOTES, 'UTF-8');
    $name = htmlspecialchars(trim($data['name']), ENT_QUOTES, 'UTF-8');
    $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
    $department_id = filter_var($data['department'], FILTER_VALIDATE_INT);
    $batch_id = filter_var($data['batch_id'], FILTER_VALIDATE_INT);
    $section = htmlspecialchars(trim($data['section']), ENT_QUOTES, 'UTF-8');

    // Validate required fields
    if (empty($roll_no) || empty($name) || empty($email) || !$department_id || !$batch_id || empty($section)) {
        throw new Exception("All required fields must be filled");
    }

    // Validate roll number format
    if (!preg_match('/^[0-9A-Z]{10,15}$/', $roll_no)) {
        throw new Exception("Invalid roll number format");
    }

    // Validate register number format (adjust the pattern as per your requirements)
    if (!preg_match('/^[0-9A-Z]{10,15}$/', $register_number)) {
        throw new Exception("Invalid register number format");
    }

    // Check if register number already exists
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM students WHERE register_number = ?");
    mysqli_stmt_bind_param($check_stmt, "s", $register_number);
    mysqli_stmt_execute($check_stmt);
    if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
        throw new Exception("Register number already exists");
    }

    // Check if roll number or email already exists
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM students WHERE roll_number = ? OR email = ?");
    mysqli_stmt_bind_param($check_stmt, "ss", $roll_no, $email);
    mysqli_stmt_execute($check_stmt);
    if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
        throw new Exception("Roll number or email already exists");
    }

    // Insert student record
    $insert_query = "INSERT INTO students (
        roll_number,
        register_number,
        name,
        email,
        password,
        department_id,
        batch_id,
        section,
        is_active
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)";

    $stmt = mysqli_prepare($conn, $insert_query);
    if ($stmt === false) {
        throw new Exception("Failed to prepare insert statement: " . mysqli_error($conn));
    }

    // Bind parameters
    mysqli_stmt_bind_param(
        $stmt, 
        "sssssiis",
        $roll_no,
        $register_number,
        $name,
        $email,
        $hashed_password,
        $department_id,
        $batch_id,
        $section
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Failed to register student: " . mysqli_stmt_error($stmt));
    }

    return mysqli_insert_id($conn);
}

function create_academic_status($conn, $student_id, $admission_year, $section) {
    $academic_year_result = mysqli_query($conn, 
        "SELECT id FROM academic_years WHERE is_current = TRUE"
    );
    $academic_year = mysqli_fetch_assoc($academic_year_result);

    if (!$academic_year) {
        throw new Exception("No current academic year found");
    }

    $year_of_study = calculate_year_of_study($admission_year);
    $semester = calculate_current_semester($year_of_study);

    $status_stmt = mysqli_prepare($conn, 
        "INSERT INTO student_academic_status (student_id, academic_year_id, 
         year_of_study, semester, section, is_current) 
         VALUES (?, ?, ?, ?, ?, TRUE)"
    );
    mysqli_stmt_bind_param($status_stmt, "iiiis", 
        $student_id, $academic_year['id'], $year_of_study, $semester, $section
    );

    if (!mysqli_stmt_execute($status_stmt)) {
        throw new Exception("Error creating academic status: " . mysqli_error($conn));
    }
}

// Helper functions for calculations
function calculate_year_of_study($admission_year) {
    $current_month = date('n');
    $current_year = date('Y');
    $year_of_study = $current_year - $admission_year;
    if ($current_month <= 5) {
        $year_of_study--;
    }
    return max(1, min(4, $year_of_study + 1));
}

function calculate_current_semester($year_of_study) {
    $is_odd_semester = date('n') > 5;
    return ($year_of_study - 1) * 2 + ($is_odd_semester ? 1 : 2);
}

function register_faculty($conn, $data, $hashed_password) {
    $name = htmlspecialchars(trim($data['name']), ENT_QUOTES, 'UTF-8');
    $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
    $department_id = filter_var($data['department'], FILTER_VALIDATE_INT);
    $designation = isset($data['designation']) ? htmlspecialchars(trim($data['designation']), ENT_QUOTES, 'UTF-8') : '';
    $experience = isset($data['experience']) ? filter_var($data['experience'], FILTER_VALIDATE_INT) : null;
    $qualification = isset($data['qualification']) ? htmlspecialchars(trim($data['qualification']), ENT_QUOTES, 'UTF-8') : '';
    $specialization = isset($data['specialization']) ? htmlspecialchars(trim($data['specialization']), ENT_QUOTES, 'UTF-8') : '';

    // Validate required fields
    if (empty($name) || empty($email) || !$department_id) {
        throw new Exception("All required fields must be filled");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }

    // Check if email already exists
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM faculty WHERE email = ?");
    mysqli_stmt_bind_param($check_stmt, "s", $email);
    mysqli_stmt_execute($check_stmt);
    if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
        throw new Exception("Email already exists");
    }

    // Insert faculty record
    $insert_query = "INSERT INTO faculty (
        name, 
        email, 
        password, 
        department_id, 
        designation, 
        experience, 
        qualification, 
        specialization, 
        is_active
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, TRUE)";

    $stmt = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($stmt, "sssissss", 
        $name, 
        $email, 
        $hashed_password, 
        $department_id, 
        $designation, 
        $experience, 
        $qualification, 
        $specialization
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error registering faculty: " . mysqli_error($conn));
    }

    return mysqli_insert_id($conn);
}

function register_hod($conn, $data, $hashed_password) {
    $name = htmlspecialchars(trim($data['name']), ENT_QUOTES, 'UTF-8');
    $email = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
    $department_id = filter_var($data['department'], FILTER_VALIDATE_INT);
    $username = htmlspecialchars(trim($data['username']), ENT_QUOTES, 'UTF-8');

    // Validate required fields
    if (empty($name) || empty($email) || !$department_id || empty($username)) {
        throw new Exception("All required fields must be filled");
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format");
    }

    // Check if email or username already exists
    $check_stmt = mysqli_prepare($conn, "SELECT id FROM hods WHERE email = ? OR username = ?");
    mysqli_stmt_bind_param($check_stmt, "ss", $email, $username);
    mysqli_stmt_execute($check_stmt);
    if (mysqli_stmt_get_result($check_stmt)->num_rows > 0) {
        throw new Exception("Email or username already exists");
    }

    // Insert HOD record
    $insert_query = "INSERT INTO hods (
        username,
        name,
        email,
        password,
        department_id,
        is_active
    ) VALUES (?, ?, ?, ?, ?, TRUE)";

    $stmt = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($stmt, "ssssi", 
        $username,
        $name,
        $email,
        $hashed_password,
        $department_id
    );

    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error registering HOD: " . mysqli_error($conn));
    }

    return mysqli_insert_id($conn);
}

// Fetch departments for dropdown
$dept_query = "SELECT id, name FROM departments ORDER BY name";
$dept_result = mysqli_query($conn, $dept_query);

// Fetch active batches for dropdown
$batches_query = "SELECT id, batch_name, admission_year, graduation_year 
                 FROM batch_years 
                 WHERE is_active = TRUE 
                 ORDER BY admission_year DESC";
$batches_result = mysqli_query($conn, $batches_query);
$batches = mysqli_fetch_all($batches_result, MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Panimalar Engineering College Feedback System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #e0e5ec;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
        }

        .header {
            width: 100%;
            padding: 1rem 2rem;
            background: #e0e5ec;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
        }

        .logo {
            width: 80px;
            height: auto;
            margin-right: 1rem;
        }

        .college-info {
            flex-grow: 1;
        }

        .college-info h1 {
            font-size: 1.5rem;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }

        .college-info p {
            font-size: 0.8rem;
            color: #34495e;
        }

        .portal-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2c3e50;
            margin: 2rem 0;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }

        .register-container {
            background: #e0e5ec;
            padding: 2rem;
            border-radius: 20px;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), -9px -9px 16px rgba(255,255,255, 0.5);
            margin-top: 1rem;
            width: 100%;
            max-width: 500px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 500;
        }

        .required::after {
            content: " *";
            color: #e74c3c;
        }

        .error-message {
            color: #e74c3c;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .success-message {
            color: #2ecc71;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
        }

        .validation-hint {
            font-size: 0.8rem;
            color: #666;
            margin-top: 0.25rem;
        }

        .input-field {
            width: 100%;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            color: #2c3e50;
            background: #e0e5ec;
            border: none;
            border-radius: 50px;
            outline: none;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .input-field::placeholder {
            color: #7f8c8d;
        }

        select.input-field {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg fill="%237f8c8d" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>');
            background-repeat: no-repeat;
            background-position: right 1rem center;
        }

        .submit-btn {
            width: 100%;
            padding: 0.8rem;
            font-size: 1rem;
            color: #fff;
            background: #3498db;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                        -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }

        .submit-btn:hover {
            background: #2980b9;
        }

        .error, .success {
            color: #e74c3c;
            background-color: #fadbd8;
            border: 1px solid #f1948a;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
        }

        .success {
            color: #27ae60;
            background-color: #d4efdf;
            border-color: #a9dfbf;
        }

        #studentFields, #staffFields {
            display: none;
        }
    </style>
    <script>
        function toggleFields() {
            const role = document.getElementById('role').value;
            const studentFields = document.getElementById('studentFields');
            const facultyFields = document.getElementById('facultyFields');
            const hodFields = document.getElementById('hodFields');
            
            studentFields.style.display = role === 'student' ? 'block' : 'none';
            facultyFields.style.display = role === 'faculty' ? 'block' : 'none';
            hodFields.style.display = role === 'hod' ? 'block' : 'none';

            // Reset required attributes based on role
            const studentInputs = studentFields.querySelectorAll('input, select');
            const facultyInputs = facultyFields.querySelectorAll('input, select');
            const hodInputs = hodFields.querySelectorAll('input, select');

            studentInputs.forEach(input => input.required = (role === 'student'));
            facultyInputs.forEach(input => input.required = false); // Faculty fields are optional
            hodInputs.forEach(input => input.required = (role === 'hod'));
        }

        function validateForm() {
            const role = document.getElementById('role').value;
            if (!role) {
                alert('Please select a role');
                return false;
            }

            const password = document.getElementById('password').value;
            const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/;
            if (!passwordRegex.test(password)) {
                alert('Password must contain at least 8 characters, including uppercase, lowercase, numbers, and special characters');
                return false;
            }

            if (role === 'student') {
                const rollNo = document.getElementById('roll_no').value;
                const rollNoRegex = /^[0-9A-Z]{10,15}$/;
                if (!rollNoRegex.test(rollNo)) {
                    alert('Invalid roll number format');
                    return false;
                }

                const batchId = document.getElementById('batch_id').value;
                const section = document.getElementById('section').value;
                if (!batchId || !section) {
                    alert('Please fill all required fields for student registration');
                    return false;
                }
            }

            return true;
        }
    </script>
</head>
<body>
    <div class="header">
        <img src="college_logo.png" alt="Panimalar Engineering College Logo" class="logo">
        <div class="college-info">
            <h1>Panimalar Engineering College</h1>
            <p>An Autonomous Institution, Affiliated to Anna University, Chennai</p>
            <p>Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai – 600 123.</p>
        </div>
    </div>
    <h1 class="portal-title">Feedback Portal</h1>
    <div class="register-container">
        <h2>User Registration</h2>
        
        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="post" id="registrationForm" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="role" class="required">Select Role</label>
                <select id="role" name="role" class="input-field" onchange="toggleFields()" required>
                    <option value="">Select Role</option>
                    <option value="student">Student</option>
                    <option value="faculty">Faculty</option>
                    <option value="hod">HOD</option>
                </select>
            </div>

            <!-- Common Fields -->
            <div class="form-group">
                <label for="name" class="required">Full Name</label>
                <input type="text" id="name" name="name" class="input-field" required>
            </div>

            <div class="form-group">
                <label for="email" class="required">Email</label>
                <input type="email" id="email" name="email" class="input-field" required>
                <div class="validation-hint">Must be a valid email address</div>
            </div>

            <div class="form-group">
                <label for="password" class="required">Password</label>
                <input type="password" id="password" name="password" class="input-field" required>
                <div class="validation-hint">
                    Password must contain:
                    <ul>
                        <li>At least 8 characters</li>
                        <li>One uppercase letter</li>
                        <li>One lowercase letter</li>
                        <li>One number</li>
                        <li>One special character</li>
                    </ul>
                </div>
            </div>

            <div class="form-group">
                <label for="department" class="required">Department</label>
                <select id="department" name="department" class="input-field" required>
                    <option value="">Select Department</option>
                    <?php while ($dept = mysqli_fetch_assoc($dept_result)): ?>
                        <option value="<?php echo htmlspecialchars($dept['id']); ?>">
                            <?php echo htmlspecialchars($dept['name']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Student Specific Fields -->
            <div id="studentFields" style="display:none;">
                <div class="form-group">
                    <label for="roll_no" class="required">Roll Number</label>
                    <input type="text" id="roll_no" name="roll_no" class="input-field">
                    <div class="validation-hint">10-15 characters, numbers and uppercase letters only</div>
                </div>

                <div class="form-group">
                    <label for="batch_id" class="required">Batch</label>
                    <select id="batch_id" name="batch_id" class="input-field">
                        <option value="">Select Batch</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?> 
                                (<?php echo $batch['admission_year']; ?> - <?php echo $batch['graduation_year']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="section" class="required">Section</label>
                    <select id="section" name="section" class="input-field">
                        <option value="">Select Section</option>
                        <?php for ($i = 65; $i <= 70; $i++): ?>
                            <option value="<?php echo chr($i); ?>">Section <?php echo chr($i); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Faculty Specific Fields -->
            <div id="facultyFields" style="display:none;">
                <div class="form-group">
                    <label for="designation">Designation</label>
                    <input type="text" id="designation" name="designation" class="input-field">
                </div>

                <div class="form-group">
                    <label for="experience">Experience (Years)</label>
                    <input type="number" id="experience" name="experience" class="input-field" min="0">
                </div>

                <div class="form-group">
                    <label for="qualification">Qualification</label>
                    <input type="text" id="qualification" name="qualification" class="input-field">
                </div>

                <div class="form-group">
                    <label for="specialization">Specialization</label>
                    <input type="text" id="specialization" name="specialization" class="input-field">
                </div>
            </div>

            <!-- HOD Specific Fields -->
            <div id="hodFields" style="display:none;">
                <div class="form-group">
                    <label for="username" class="required">Username</label>
                    <input type="text" id="username" name="username" class="input-field">
                    <div class="validation-hint">Must be unique</div>
                </div>
            </div>

            <div class="form-group">
                <label for="register_number" class="required">Register Number</label>
                <input type="text" 
                       id="register_number" 
                       name="register_number" 
                       class="input-field" 
                       required>
                <div class="validation-hint">Enter your university register number</div>
            </div>

            <button type="submit" class="submit-btn">Register</button>
        </form>
    </div>
</body>
</html>
