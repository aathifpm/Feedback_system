<?php
session_start();
include 'functions.php';
include 'includes/validation.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: login.php');
    exit();
}

// Fetch student details
$student_id = $_SESSION['user_id'];
$query = "SELECT s.*, d.name as department_name 
          FROM students s 
          JOIN departments d ON s.department_id = d.id 
          WHERE s.id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);

if (!$student) {
    die("Error: Student data not found.");
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Create validator instance
    $validator = new ExitSurveyValidator($_POST);
    
    // Perform validation
    if ($validator->validate()) {
        // Process the form data
        try {
            // Your existing database insertion code here
            $success = true;
            $_SESSION['success_message'] = "Exit survey submitted successfully!";
            header('Location: dashboard.php');
            exit();
        } catch (Exception $e) {
            $errors['database'] = "An error occurred while saving the survey. Please try again.";
        }
    } else {
        $errors = $validator->getErrors();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Exit Survey - Panimalar Engineering College</title>
    <!-- Include existing CSS -->
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
        }

        .container {
            max-width: 1200px;
            width: 90%;
            margin: 2rem auto;
            padding: 2rem;
        }

        .survey-section {
            background: #e0e5ec;
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                       -9px -9px 16px rgba(255,255,255, 0.5);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: #2c3e50;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: none;
            border-radius: 50px;
            background: #e0e5ec;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .rating-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 1rem;
        }

        .rating-table th, 
        .rating-table td {
            padding: 1.2rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .rating-table th {
            color: #2c3e50;
            font-weight: 600;
            text-align: left;
        }

        .radio-group {
            display: flex;
            justify-content: space-around;
            align-items: center;
            gap: 0.5rem;
        }

        .radio-group label {
            width: 40px;
            height: 40px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            background: #e0e5ec;
            box-shadow: 3px 3px 6px #b8b9be, 
                       -3px -3px 6px #fff;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .radio-group input[type="radio"] {
            display: none;
        }

        .radio-group label:hover {
            background: #3498db;
            color: white;
        }

        .radio-group input[type="radio"]:checked + label {
            background: #3498db;
            color: white;
            box-shadow: inset 3px 3px 6px rgba(0, 0, 0, 0.2),
                       inset -3px -3px 6px rgba(255, 255, 255, 0.1);
        }

        .scale-info {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #3498db;
            color: white;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }

        .error-field {
            box-shadow: inset 6px 6px 10px 0 rgba(220, 53, 69, 0.2),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        .error-message {
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .success-message {
            color: #28a745;
            padding: 1rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            margin-bottom: 1rem;
        }

        .error-summary {
            color: #dc3545;
            padding: 1rem;
            background: #e0e5ec;
            border-radius: 15px;
            box-shadow: 6px 6px 10px 0 rgba(220, 53, 69, 0.2),
                       -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .container {
                width: 95%;
                padding: 1rem;
            }

            .survey-section {
                padding: 1rem;
            }

            .radio-group {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .radio-group label {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }

            .rating-table th, 
            .rating-table td {
                padding: 0.8rem;
            }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        function validateForm() {
            const required = document.querySelectorAll('[required]');
            let valid = true;
            
            required.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('error');
                    valid = false;
                } else {
                    field.classList.remove('error');
                }
            });

            // Validate email format
            const email = document.getElementById('email');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                email.classList.add('error');
                valid = false;
            }

            // Validate phone number
            const phone = document.getElementById('contact_number');
            const phoneRegex = /^\d{10}$/;
            if (!phoneRegex.test(phone.value)) {
                phone.classList.add('error');
                valid = false;
            }

            return valid;
        }

        document.getElementById('employment_status').addEventListener('change', function() {
            const employmentDetails = document.getElementById('employment_details');
            if (this.value === 'employed') {
                employmentDetails.style.display = 'block';
            } else {
                employmentDetails.style.display = 'none';
            }
        });
    </script>
</head>
<body>
    <!-- Include your header -->
    <?php include 'header.php'; ?>

    <div class="container">
        <h1>Student Exit Survey</h1>
        
        <?php if (!empty($errors)): ?>
            <div class="error-summary">
                <h3>Please correct the following errors:</h3>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-message">
                Survey submitted successfully!
            </div>
        <?php endif; ?>

        <form method="post" onsubmit="return validateForm()" class="survey-form">
            <!-- Background Information Section -->
            <div class="survey-section">
                <h2>Background Information</h2>
                <div class="form-group">
                    <label for="name">Name:</label>
                    <input type="text" id="name" name="name" value="<?php echo $student['name']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="department">Department:</label>
                    <input type="text" id="department" value="<?php echo $student['department_name']; ?>" readonly>
                </div>
                
                <div class="form-group">
                    <label for="roll_number">Roll Number:</label>
                    <input type="text" id="roll_number" name="roll_number" value="<?php echo $student['roll_number']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="register_number">Register Number:</label>
                    <input type="text" id="register_number" name="register_number" required>
                </div>
                
                <div class="form-group">
                    <label for="passing_year">Year of Passing:</label>
                    <input type="number" id="passing_year" name="passing_year" min="2000" max="2099" required>
                </div>
                
                <div class="form-group">
                    <label for="contact_address">Contact Address:</label>
                    <textarea id="contact_address" name="contact_address" rows="3" required></textarea>
                </div>
                
                <div class="form-group">
                    <label for="email">Email ID:</label>
                    <input type="email" id="email" name="email" value="<?php echo $student['email']; ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="contact_number">Contact Number:</label>
                    <input type="tel" id="contact_number" name="contact_number" pattern="[0-9]{10}" required>
                </div>
            </div>

            <!-- Program Outcomes Section -->
            <div class="survey-section">
                <h2>Attainment of Program Outcomes (POs)</h2>
                <p class="scale-info">Scale: 1 = Poor, 2 = Fair, 3 = Good, 4 = Very Good, 5 = Excellent</p>
                
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $po_questions = [
                            "Ability to apply knowledge of mathematics, science, and engineering in your field.",
                            "Ability to analyze and interpret data to design and conduct experiments in Engineering applications.",
                            "Ability to design systems meeting realistic constraints and sustainability.",
                            "Ability to use research-based knowledge and methods to conduct investigations of complex problems.",
                            "Ability to apply engineering techniques and software for complex engineering activities.",
                            "Understanding societal, health, safety, legal, and cultural responsibilities in engineering practice.",
                            "Understanding the impact of engineering solutions in societal and environmental contexts.",
                            "Understanding professional and ethical responsibility.",
                            "Ability to work as a team in diverse environments.",
                            "Effective communication with peers, superiors, clients, and stakeholders.",
                            "Multi-domain knowledge for successful participation in multidisciplinary teams.",
                            "Recognition of the need for lifelong learning and pursuit of higher education."
                        ];
                        
                        foreach ($po_questions as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo '<td class="radio-group">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'po_ratings_' . $index . '_' . $i;
                                echo '<input type="radio" id="' . $inputId . '" name="po_ratings[' . $index . ']" value="' . $i . '" required>';
                                echo '<label for="' . $inputId . '">' . $i . '</label>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Program Specific Outcomes Section -->
            <div class="survey-section">
                <h2>Attainment of Program Specific Outcomes (PSOs)</h2>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Questionnaires</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pso_questions = [
                            "Ability to evolve AI-based processes for effective decision-making in various domains.",
                            "Ability to derive insights from data to solve business and engineering problems.",
                            "Ability to apply AI and Data Analytics knowledge with industrial tools for solving societal issues."
                        ];
                        
                        foreach ($pso_questions as $index => $question) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $question . '</td>';
                            echo '<td class="radio-group">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'pso_ratings_' . $index . '_' . $i;
                                echo '<input type="radio" id="' . $inputId . '" name="pso_ratings[' . $index . ']" value="' . $i . '" required>';
                                echo '<label for="' . $inputId . '">' . $i . '</label>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Employment Status Section -->
            <div class="survey-section">
                <h2>Employment Status</h2>
                
                <div class="form-group">
                    <label for="employment_status">Status of employment after completion of degree:</label>
                    <select id="employment_status" name="employment[status]" required>
                        <option value="">Select Status</option>
                        <option value="employed">Employed</option>
                        <option value="unemployed">Unemployed</option>
                        <option value="higher_studies">Pursuing Higher Studies</option>
                    </select>
                </div>
                
                <div id="employment_details" class="conditional-section">
                    <div class="form-group">
                        <label for="employer_details">Employer Details:</label>
                        <textarea id="employer_details" name="employment[employer_details]" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="starting_salary">Starting Salary (per annum):</label>
                        <input type="number" id="starting_salary" name="employment[starting_salary]" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="job_offers">Number of job offers received:</label>
                        <input type="number" id="job_offers" name="employment[job_offers]" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label for="job_satisfaction">Satisfaction with job offers received:</label>
                        <select id="job_satisfaction" name="employment[satisfaction]">
                            <option value="">Select Satisfaction Level</option>
                            <option value="very_satisfied">Very Satisfied</option>
                            <option value="satisfied">Satisfied</option>
                            <option value="neutral">Neutral</option>
                            <option value="dissatisfied">Dissatisfied</option>
                            <option value="very_dissatisfied">Very Dissatisfied</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="interviews">Number of interview opportunities:</label>
                        <input type="number" id="interviews" name="employment[interviews]" min="0">
                    </div>
                </div>
            </div>

            <!-- Program Satisfaction Section -->
            <div class="survey-section">
                <h2>Program Satisfaction</h2>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Objectives</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $program_objectives = [
                            "Quality of Program",
                            "Intellectual Enrichment",
                            "Financial Support",
                            "Seminars/Guest Lectures",
                            "Conferences & Workshops",
                            "Short Term Courses",
                            "Industrial Visits"
                        ];
                        
                        foreach ($program_objectives as $index => $objective) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $objective . '</td>';
                            echo '<td class="radio-group">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'program_satisfaction_' . $index . '_' . $i;
                                echo '<input type="radio" id="' . $inputId . '" name="program_satisfaction[' . $index . ']" value="' . $i . '" required>';
                                echo '<label for="' . $inputId . '">' . $i . '</label>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Infrastructure Satisfaction Section -->
            <div class="survey-section">
                <h2>Infrastructure Satisfaction</h2>
                <table class="rating-table">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Facilities</th>
                            <th>Rating</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $infrastructure_facilities = [
                            "Classrooms",
                            "Laboratories",
                            "Library",
                            "Mess",
                            "Hostel",
                            "Transportation",
                            "Sports/Extracurricular"
                        ];
                        
                        foreach ($infrastructure_facilities as $index => $facility) {
                            echo '<tr>';
                            echo '<td>' . ($index + 1) . '</td>';
                            echo '<td>' . $facility . '</td>';
                            echo '<td class="radio-group">';
                            for ($i = 1; $i <= 5; $i++) {
                                $inputId = 'infrastructure_satisfaction_' . $index . '_' . $i;
                                echo '<input type="radio" id="' . $inputId . '" name="infrastructure_satisfaction[' . $index . ']" value="' . $i . '" required>';
                                echo '<label for="' . $inputId . '">' . $i . '</label>';
                            }
                            echo '</td>';
                            echo '</tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Signature Section -->
            <div class="survey-section">
                <div class="form-group">
                    <label for="date">Date:</label>
                    <input type="date" id="date" name="date" required>
                </div>
                
                <div class="form-group">
                    <label for="station">Station:</label>
                    <input type="text" id="station" name="station" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Submit Survey</button>
        </form>
    </div>
</body>
</html>