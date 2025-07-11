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
$current_year_result = mysqli_query($conn, $current_year_query);
$current_year = mysqli_fetch_assoc($current_year_result);

// Function to get the correct batch for a given academic year and year of study
function getBatchForAcademicYear($conn, $academic_year_id, $year_of_study) {
    // Get the academic year details
    $year_query = "SELECT year_range FROM academic_years WHERE id = ?";
    $stmt = mysqli_prepare($conn, $year_query);
    mysqli_stmt_bind_param($stmt, "i", $academic_year_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $academic_year = mysqli_fetch_assoc($result);
    
    if (!$academic_year) {
        return null;
    }
    
    // Extract the start year from year_range (e.g., "2023-24" -> 2023)
    $academic_start_year = intval(substr($academic_year['year_range'], 0, 4));
    
    // Calculate the admission year for the batch we're looking for
    // If we're looking for 2nd year students in 2023-24, their admission year would be 2022
    $admission_year = $academic_start_year - $year_of_study + 1;
    
    // Get the batch details
    $batch_query = "SELECT * FROM batch_years WHERE admission_year = ?";
    $stmt = mysqli_prepare($conn, $batch_query);
    mysqli_stmt_bind_param($stmt, "i", $admission_year);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    return mysqli_fetch_assoc($result);
}

// If form is submitted
if (isset($_GET['academic_year']) && isset($_GET['year']) && isset($_GET['semester']) && isset($_GET['section'])) {
    $academic_year_id = $_GET['academic_year'];
    $year_of_study = $_GET['year'];
    $semester = $_GET['semester'];
    $section = $_GET['section'];
    $export_format = $_GET['export_format'];
    
    // Get the correct batch for this academic year and year of study
    $batch = getBatchForAcademicYear($conn, $academic_year_id, $year_of_study);
    
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
            --bg-color: #f0f2f5;
            --text-color: #2c3e50;
            --shadow: 0 2px 10px rgba(0,0,0,0.1);
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
        }

        .container {
            max-width: 1000px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: #666;
            font-size: 1rem;
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
            padding: 0.8rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
        }

        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }

        .btn-secondary:hover {
            background: #27ae60;
        }

        .actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .card {
                padding: 1.5rem;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
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
                echo '<div class="alert alert-danger">' . $_SESSION['error'] . '</div>';
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
                        <label>Academic Year</label>
                        <select name="academic_year" class="form-control" required>
                            <?php
                            $year_query = "SELECT id, year_range FROM academic_years ORDER BY year_range DESC";
                            $year_result = mysqli_query($conn, $year_query);
                            while ($year = mysqli_fetch_assoc($year_result)) {
                                $selected = ($year['id'] == $current_year['id']) ? 'selected' : '';
                                echo "<option value='" . $year['id'] . "' $selected>" . $year['year_range'] . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Year of Study</label>
                        <select name="year" class="form-control" required onchange="updateSemesters(this.value)">
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
                        <label>Semester</label>
                        <select name="semester" class="form-control" required>
                            <option value="">Select Semester</option>
                            <option value="0">All Semesters</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Section</label>
                        <select name="section" class="form-control" required>
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
                        <label>Export Format</label>
                        <select name="export_format" class="form-control" required>
                            <option value="pdf">PDF Document</option>
                            <option value="excel">Excel Spreadsheet</option>
                        </select>
                    </div>
                </div>

                <div class="actions">
                    <a href="dashboard.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button type="submit" class="btn btn-primary">
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
            }
        }

        // Handle form submission
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const format = document.querySelector('select[name="export_format"]').value;
            const form = this;
            
            if (format === 'pdf') {
                form.action = 'generate_section_report.php';
            } else if (format === 'excel') {
                form.action = 'generate_section_excel.php';
            }
            
            form.submit();
        });
    </script>
</body>
</html> 