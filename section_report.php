<?php
session_start();
include 'functions.php';

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'hod') {
    header('Location: index.php');
    exit();
}

// Get current academic year
$current_year_query = "SELECT id, year_range FROM academic_years WHERE is_current = TRUE LIMIT 1";
try {
    $stmt = $pdo->query($current_year_query);
    $current_year = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching current academic year: " . $e->getMessage());
    $_SESSION['error'] = "Database error occurred.";
    header('Location: dashboard.php');
    exit();
}

// Function to get the correct batch for a given academic year and year of study
function getBatchForAcademicYear($pdo, $academic_year_id, $year_of_study) {
    // Get the academic year details
    $year_query = "SELECT year_range FROM academic_years WHERE id = :academic_year_id";
    try {
        $stmt = $pdo->prepare($year_query);
        $stmt->bindParam(':academic_year_id', $academic_year_id, PDO::PARAM_INT);
        $stmt->execute();
        $academic_year = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$academic_year) {
            return null;
        }
        
        // Extract the start year from year_range (e.g., "2023-24" -> 2023)
        $academic_start_year = intval(substr($academic_year['year_range'], 0, 4));
        
        // Calculate the admission year for the batch we're looking for
        // If we're looking for 2nd year students in 2023-24, their admission year would be 2022
        $admission_year = $academic_start_year - $year_of_study + 1;
        
        // Get the batch details
        $batch_query = "SELECT * FROM batch_years WHERE admission_year = :admission_year";
        $stmt = $pdo->prepare($batch_query);
        $stmt->bindParam(':admission_year', $admission_year, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getBatchForAcademicYear: " . $e->getMessage());
        return null;
    }
}

// If form is submitted
if (isset($_GET['academic_year']) && isset($_GET['year']) && isset($_GET['semester']) && isset($_GET['section'])) {
    $academic_year_id = $_GET['academic_year'];
    $year_of_study = $_GET['year'];
    $semester = $_GET['semester'];
    $section = $_GET['section'];
    $export_format = $_GET['export_format'];
    
    // Get the correct batch for this academic year and year of study
    $batch = getBatchForAcademicYear($pdo, $academic_year_id, $year_of_study);
    
    if (!$batch) {
        $_SESSION['error'] = "No matching batch found for the selected criteria.";
        header('Location: section_report.php');
        exit();
    }
    
    // Modify the report generation query to include batch_id
    if ($export_format === 'pdf') {
        header('Location: generate_section_report.php?' . http_build_query([
            'academic_year' => $academic_year_id,
            'year' => $year_of_study,
            'semester' => $semester,
            'section' => $section,
            'batch_id' => $batch['id']
        ]));
    } else {
        header('Location: generate_section_excel.php?' . http_build_query([
            'academic_year' => $academic_year_id,
            'year' => $year_of_study,
            'semester' => $semester,
            'section' => $section,
            'batch_id' => $batch['id']
        ]));
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Section-wise Report Generation</title>
    <link rel="icon" href="college_logo.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --bg-color: #e0e5ec;
            --text-color: #2c3e50;
            --shadow-light: 9px 9px 16px rgba(163, 177, 198, 0.6);
            --shadow-dark: -9px -9px 16px rgba(255, 255, 255, 0.5);
            --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1), 
                           inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 1000px;
            margin: 3rem auto;
            padding: 0 1.5rem;
        }

        .card {
            background: var(--bg-color);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: var(--shadow-light), var(--shadow-dark);
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
        }

        .page-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .page-title {
            font-size: 2rem;
            color: var(--text-color);
            margin-bottom: 0.8rem;
            font-weight: 600;
        }

        .page-subtitle {
            color: #666;
            font-size: 1.1rem;
            opacity: 0.8;
        }

        .form-group {
            margin-bottom: 2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.8rem;
            font-weight: 500;
            color: var(--text-color);
            font-size: 1.05rem;
        }

        .form-control {
            width: 100%;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow-light), var(--shadow-dark);
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%232c3e50' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            background-size: 1em;
            padding-right: 2.5rem;
            cursor: pointer;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            gap: 0.8rem;
            background: var(--bg-color);
            color: var(--text-color);
            box-shadow: var(--shadow-light), var(--shadow-dark);
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 12px 12px 20px rgba(163, 177, 198, 0.7),
                      -12px -12px 20px rgba(255, 255, 255, 0.7);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: var(--inner-shadow);
        }

        .btn-primary {
            color: white;
            background: var(--primary-color);
        }

        .btn-primary:hover {
            background: linear-gradient(145deg, #3498db, #2980b9);
        }

        .btn-secondary {
            color: white;
            background: var(--secondary-color);
        }

        .btn-secondary:hover {
            background: linear-gradient(145deg, #2ecc71, #27ae60);
        }

        .actions {
            display: flex;
            gap: 1.5rem;
            justify-content: flex-end;
            margin-top: 2.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: #e74c3c;
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
                margin: 1.5rem auto;
            }

            .card {
                padding: 1.8rem;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <div class="card">
            <?php
            // Display error message if any
            if (isset($_SESSION['error'])) {
                echo '<div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> ' . $_SESSION['error'] . '
                </div>';
                unset($_SESSION['error']);
            }
            ?>
            <div class="page-header">
                <h1 class="page-title">Section-wise Report Generation</h1>
                <p class="page-subtitle">Generate comprehensive feedback reports for specific sections</p>
            </div>

            <form id="reportForm" method="get">
                <div class="form-row">
                    <div class="form-group">
                        <label for="academic_year"><i class="fas fa-calendar-alt"></i> Academic Year</label>
                        <select name="academic_year" id="academic_year" class="form-control" required>
                            <?php
                            try {
                                $year_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
                                $stmt = $pdo->query($year_query);
                                while ($year = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = ($year['id'] == $current_year['id']) ? 'selected' : '';
                                    echo "<option value='" . $year['id'] . "' $selected>" . $year['year_range'] . "</option>";
                                }
                            } catch (PDOException $e) {
                                error_log("Error fetching academic years: " . $e->getMessage());
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="year"><i class="fas fa-graduation-cap"></i> Year of Study</label>
                        <select name="year" id="year" class="form-control" required onchange="updateSemesters(this.value)">
                            <option value="">Select Year</option>
                            <option value="1">I Year</option>
                            <option value="2">II Year</option>
                            <option value="3">III Year</option>
                            <option value="4">IV Year</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="semester"><i class="fas fa-book-open"></i> Semester</label>
                        <select name="semester" id="semester" class="form-control" required>
                            <option value="">Select Semester</option>
                            <option value="0">All Semesters</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="section"><i class="fas fa-users"></i> Section</label>
                        <select name="section" id="section" class="form-control" required>
                            <option value="">Select Section</option>
                            <?php
                            $sections = range('A', 'K');
                            foreach ($sections as $sec) {
                                echo "<option value='$sec'>Section $sec</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="export_format"><i class="fas fa-file-export"></i> Export Format</label>
                        <select name="export_format" id="export_format" class="form-control" required>
                            <option value="pdf">PDF Document</option>
                            <option value="excel">Excel Spreadsheet</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="class_committee_reports.php" class="btn btn-secondary">
                        <i class="fas fa-chart-bar"></i> Class Committee Reports
                    </a>
                    <button type="submit" class="btn btn-primary" id="generate-btn">
                        <i class="fas fa-file-export"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateSemesters(year) {
            const semesterSelect = document.querySelector('select[name="semester"]');
            semesterSelect.innerHTML = '<option value="">Select Semester</option><option value="0">All Semesters</option>';
            
            if (year) {
                const startSem = (year - 1) * 2 + 1;
                const endSem = startSem + 1;
                
                for (let i = startSem; i <= endSem; i++) {
                    const option = document.createElement('option');
                    option.value = i;
                    option.textContent = `Semester ${i}`;
                    semesterSelect.appendChild(option);
                }

                // Add animation effect
                semesterSelect.classList.add('animate-update');
                setTimeout(() => {
                    semesterSelect.classList.remove('animate-update');
                }, 500);
            }
        }

        // Add active effect on form controls
        document.querySelectorAll('.form-control').forEach(control => {
            control.addEventListener('focus', function() {
                this.classList.add('active');
            });
            control.addEventListener('blur', function() {
                this.classList.remove('active');
            });
        });

        // Handle form submission
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading effect on button
            const generateBtn = document.getElementById('generate-btn');
            const originalBtnText = generateBtn.innerHTML;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
            generateBtn.disabled = true;
            
            const format = document.querySelector('select[name="export_format"]').value;
            const form = this;
            
            if (format === 'pdf') {
                form.action = 'generate_section_report.php';
            } else if (format === 'excel') {
                form.action = 'generate_section_excel.php';
            }
            
            // Add slight delay to show the loading effect
            setTimeout(() => {
                form.submit();
            }, 300);
        });
    </script>

    <style>
        /* Additional styles */
        .form-control.active {
            transform: translateY(-2px);
            box-shadow: var(--shadow-light), var(--shadow-dark);
        }
        
        .animate-update {
            animation: pulse 0.5s ease-in-out;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        
        /* Loading spinner */
        .fa-spin {
            animation: fa-spin 1s infinite linear;
        }
        
        @keyframes fa-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</body>
</html> 