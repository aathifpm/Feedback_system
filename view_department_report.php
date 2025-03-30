<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is HOD
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'];

// Get all academic years
$academic_years_query = "SELECT * FROM academic_years ORDER BY start_date DESC";
$academic_years_result = mysqli_query($conn, $academic_years_query);

// Get all batch years
$batch_years_query = "SELECT * FROM batch_years WHERE is_active = TRUE ORDER BY admission_year DESC";
$batch_years_result = mysqli_query($conn, $batch_years_query);

// Get current academic year
$current_academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
$current_academic_year_result = mysqli_query($conn, $current_academic_year_query);
$current_academic_year = mysqli_fetch_assoc($current_academic_year_result);

// Get department details
$dept_query = "SELECT d.*, h.name as hod_name 
               FROM departments d 
               JOIN hods h ON d.id = h.department_id 
               WHERE d.id = ?";
$dept_stmt = mysqli_prepare($conn, $dept_query);
mysqli_stmt_bind_param($dept_stmt, "i", $department_id);
mysqli_stmt_execute($dept_stmt);
$department = mysqli_fetch_assoc(mysqli_stmt_get_result($dept_stmt));

// Get faculty statistics
$faculty_query = "SELECT 
    COUNT(*) as total_faculty,
    AVG(experience) as avg_experience,
    COUNT(CASE WHEN designation = 'Professor' THEN 1 END) as professors,
    COUNT(CASE WHEN designation = 'Associate Professor' THEN 1 END) as associate_professors,
    COUNT(CASE WHEN designation = 'Assistant Professor' THEN 1 END) as assistant_professors
FROM faculty 
WHERE department_id = ? AND is_active = TRUE";
$faculty_stmt = mysqli_prepare($conn, $faculty_query);
mysqli_stmt_bind_param($faculty_stmt, "i", $department_id);
mysqli_stmt_execute($faculty_stmt);
$faculty_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($faculty_stmt));

// Get feedback statistics
$feedback_query = "SELECT 
    COUNT(DISTINCT fb.id) as total_feedback,
    AVG(fb.cumulative_avg) as overall_rating,
    AVG(fb.course_effectiveness_avg) as course_effectiveness,
    AVG(fb.teaching_effectiveness_avg) as teaching_effectiveness,
    AVG(fb.resources_admin_avg) as resources_admin,
    AVG(fb.assessment_learning_avg) as assessment_learning,
    AVG(fb.course_outcomes_avg) as course_outcomes
FROM feedback fb
JOIN subject_assignments sa ON fb.assignment_id = sa.id
JOIN subjects s ON sa.subject_id = s.id
WHERE s.department_id = ? AND sa.academic_year_id = ?";
$feedback_stmt = mysqli_prepare($conn, $feedback_query);
mysqli_stmt_bind_param($feedback_stmt, "ii", $department_id, $current_academic_year['id']);
mysqli_stmt_execute($feedback_stmt);
$feedback_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($feedback_stmt));

// Get subject-wise performance
$subject_query = "SELECT 
    s.name as subject_name,
    s.code as subject_code,
    f.name as faculty_name,
    COUNT(DISTINCT fb.id) as feedback_count,
    AVG(fb.cumulative_avg) as avg_rating
FROM subjects s
JOIN subject_assignments sa ON s.id = sa.subject_id
JOIN faculty f ON sa.faculty_id = f.id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
WHERE s.department_id = ? AND sa.academic_year_id = ?
GROUP BY s.id, sa.id
ORDER BY avg_rating DESC";
$subject_stmt = mysqli_prepare($conn, $subject_query);
mysqli_stmt_bind_param($subject_stmt, "ii", $department_id, $current_academic_year['id']);
mysqli_stmt_execute($subject_stmt);
$subject_performance = mysqli_stmt_get_result($subject_stmt);

// Get top performing faculty
$top_faculty_query = "SELECT 
    f.name,
    f.designation,
    COUNT(DISTINCT fb.id) as feedback_count,
    AVG(fb.cumulative_avg) as avg_rating
FROM faculty f
JOIN subject_assignments sa ON f.id = sa.faculty_id
LEFT JOIN feedback fb ON sa.id = fb.assignment_id
WHERE f.department_id = ? AND sa.academic_year_id = ?
GROUP BY f.id
HAVING feedback_count > 0
ORDER BY avg_rating DESC
LIMIT 5";
$top_faculty_stmt = mysqli_prepare($conn, $top_faculty_query);
mysqli_stmt_bind_param($top_faculty_stmt, "ii", $department_id, $current_academic_year['id']);
mysqli_stmt_execute($top_faculty_stmt);
$top_faculty = mysqli_stmt_get_result($top_faculty_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Department Report - <?php echo htmlspecialchars($department['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.1.1/css/all.min.css">
    <?php include 'header.php'; ?>
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2ecc71;
            --warning-color: #f1c40f;
            --danger-color: #e74c3c;
            --text-color: #2c3e50;
            --bg-color: #e0e5ec;
            --card-bg: #e0e5ec;
            --shadow-light: 12px 12px 16px 0 rgba(0, 0, 0, 0.25), -8px -8px 12px 0 rgba(255, 255, 255, 0.3);
            --shadow-inset: inset 8px 8px 12px 0 rgba(0, 0, 0, 0.25), inset -8px -8px 12px 0 rgba(255, 255, 255, 0.3);
            --shadow-dark: 8px 8px 12px 0 rgba(0, 0, 0, 0.3);
        }

        body {
            background: var(--bg-color);
            font-family: 'Poppins', sans-serif;
            color: var(--text-color);
            line-height: 1.6;
        }

        .report-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .report-header {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 2rem;
            text-align: center;
        }

        .department-info {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .info-card {
            background: var(--card-bg);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-icon {
            font-size: 1.8rem;
            color: var(--primary-color);
            padding: 1rem;
            border-radius: 50%;
            background: var(--card-bg);
            box-shadow: var(--shadow-light);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 600;
            color: var(--primary-color);
            margin: 1rem 0;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        .performance-section {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 2rem;
            padding-bottom: 0.5rem;
            border-bottom: 3px solid var(--primary-color);
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
        }

        .rating-bar {
            height: 30px;
            background: var(--bg-color);
            border-radius: 15px;
            box-shadow: var(--shadow-inset);
            margin: 1rem 0;
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: linear-gradient(45deg, var(--primary-color), var(--secondary-color));
            box-shadow: var(--shadow-dark);
            display: flex;
            align-items: center;
            padding: 0 1.5rem;
            color: white;
            font-weight: 500;
            transition: width 0.5s ease;
        }

        .faculty-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: var(--card-bg);
            border-radius: 15px;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease;
        }

        .faculty-card:hover {
            transform: translateY(-3px);
        }

        .faculty-info {
            flex: 1;
        }

        .faculty-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .faculty-designation {
            color: #666;
            font-size: 0.95rem;
        }

        .faculty-rating {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
        }

        .action-buttons {
            display: flex;
            gap: 1.5rem;
            margin-top: 2rem;
            justify-content: center;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--card-bg);
            color: var(--primary-color);
            box-shadow: var(--shadow-light);
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
        }

        .btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-dark);
        }

        .btn:active {
            transform: translateY(0);
            box-shadow: var(--shadow-inset);
        }

        .btn i {
            font-size: 1.2rem;
        }

        @media print {
            body {
                background: white;
            }

            .report-container {
                margin: 0;
                padding: 0;
            }

            .action-buttons {
                display: none;
            }

            .stat-card, .performance-section, .faculty-card, .info-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .faculty-card {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(224, 229, 236, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid var(--card-bg);
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .report-filters {
            background: var(--card-bg);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow-light);
            margin-bottom: 2rem;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-item label {
            font-weight: 500;
            color: var(--text-color);
        }

        .filter-item select {
            padding: 0.8rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--shadow-inset);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .filter-item select:focus {
            outline: none;
            box-shadow: var(--shadow-light);
        }

        .filter-actions {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }
    </style>
</head>
<body>
    <!-- Add loading overlay -->
    <div class="loading-overlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="report-container">
        <!-- Add Report Filters Section -->
        <div class="report-filters">
            <h2 class="section-title">Report Filters</h2>
            <div class="filter-grid">
                <div class="filter-item">
                    <label for="academic_year">Academic Year</label>
                    <select id="academic_year" name="academic_year">
                        <?php while ($year = mysqli_fetch_assoc($academic_years_result)): ?>
                            <option value="<?php echo $year['id']; ?>" 
                                    <?php echo ($year['is_current'] ? 'selected' : ''); ?>>
                                <?php echo htmlspecialchars($year['year_range']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="batch_year">Batch Year</label>
                    <select id="batch_year" name="batch_year">
                        <option value="all">All Batches</option>
                        <?php while ($batch = mysqli_fetch_assoc($batch_years_result)): ?>
                            <option value="<?php echo $batch['id']; ?>">
                                <?php echo htmlspecialchars($batch['batch_name']); ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="filter-item">
                    <label for="report_type">Report Type</label>
                    <select id="report_type" name="report_type">
                        <option value="department">Department Report</option>
                        <option value="overall">Overall Report</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn btn-primary" onclick="generateReport()">
                    <i class="fas fa-sync-alt"></i> Generate Report
                </button>
            </div>
        </div>

        <div class="report-header">
            <h1>Department Report</h1>
            <div class="department-info">
                <div class="info-card">
                    <h3>Department</h3>
                    <p><?php echo htmlspecialchars($department['name']); ?></p>
                </div>
                <div class="info-card">
                    <h3>HOD</h3>
                    <p><?php echo htmlspecialchars($department['hod_name']); ?></p>
                </div>
                <div class="info-card">
                    <h3>Academic Year</h3>
                    <p><?php echo htmlspecialchars($current_academic_year['year_range']); ?></p>
                </div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-users stat-icon"></i>
                    <h3 class="stat-title">Faculty Statistics</h3>
                </div>
                <div class="stat-value"><?php echo $faculty_stats['total_faculty']; ?></div>
                <div class="stat-label">Total Faculty Members</div>
                <div class="stat-details">
                    <p>Professors: <?php echo $faculty_stats['professors']; ?></p>
                    <p>Associate Professors: <?php echo $faculty_stats['associate_professors']; ?></p>
                    <p>Assistant Professors: <?php echo $faculty_stats['assistant_professors']; ?></p>
                    <p>Average Experience: <?php echo number_format($faculty_stats['avg_experience'], 1); ?> years</p>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <i class="fas fa-star stat-icon"></i>
                    <h3 class="stat-title">Overall Performance</h3>
                </div>
                <div class="stat-value"><?php echo number_format($feedback_stats['overall_rating'], 2); ?></div>
                <div class="stat-label">Average Rating</div>
                <div class="stat-details">
                    <p>Total Feedback: <?php echo $feedback_stats['total_feedback']; ?></p>
                </div>
            </div>
        </div>

        <div class="performance-section">
            <h2 class="section-title">Performance Categories</h2>
            <?php
            $categories = [
                'course_effectiveness' => 'Course Effectiveness',
                'teaching_effectiveness' => 'Teaching Effectiveness',
                'resources_admin' => 'Resources & Administration',
                'assessment_learning' => 'Assessment & Learning',
                'course_outcomes' => 'Course Outcomes'
            ];

            foreach ($categories as $key => $label): 
                $percentage = ($feedback_stats[$key] / 5) * 100;
            ?>
                <div class="rating-item">
                    <div class="rating-label"><?php echo $label; ?></div>
                    <div class="rating-bar">
                        <div class="rating-fill" style="width: <?php echo $percentage; ?>%">
                            <?php echo number_format($feedback_stats[$key], 2); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="performance-section">
            <h2 class="section-title">Top Performing Faculty</h2>
            <?php while ($faculty = mysqli_fetch_assoc($top_faculty)): ?>
                <div class="faculty-card">
                    <div class="faculty-info">
                        <div class="faculty-name"><?php echo htmlspecialchars($faculty['name']); ?></div>
                        <div class="faculty-designation"><?php echo htmlspecialchars($faculty['designation']); ?></div>
                    </div>
                    <div class="faculty-stats">
                        <div class="faculty-rating">
                            <?php echo number_format($faculty['avg_rating'], 2); ?> / 5.00
                        </div>
                        <div class="feedback-count">
                            <?php echo $faculty['feedback_count']; ?> feedbacks
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="performance-section">
            <h2 class="section-title">Subject-wise Performance</h2>
            <?php while ($subject = mysqli_fetch_assoc($subject_performance)): ?>
                <div class="faculty-card">
                    <div class="faculty-info">
                        <div class="faculty-name">
                            <?php echo htmlspecialchars($subject['subject_name']); ?> 
                            (<?php echo htmlspecialchars($subject['subject_code']); ?>)
                        </div>
                        <div class="faculty-designation">
                            Faculty: <?php echo htmlspecialchars($subject['faculty_name']); ?>
                        </div>
                    </div>
                    <div class="faculty-stats">
                        <div class="faculty-rating">
                            <?php echo number_format($subject['avg_rating'], 2); ?> / 5.00
                        </div>
                        <div class="feedback-count">
                            <?php echo $subject['feedback_count']; ?> feedbacks
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>

        <div class="action-buttons">
            <button class="btn btn-primary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
            <button class="btn btn-primary" onclick="generateReport('pdf')">
                <i class="fas fa-file-pdf"></i> Download PDF
            </button>
        </div>
    </div>

    <script>
        // Function to show loading overlay
        function showLoading() {
            document.querySelector('.loading-overlay').style.display = 'flex';
        }

        // Function to hide loading overlay
        function hideLoading() {
            document.querySelector('.loading-overlay').style.display = 'none';
        }

        // Function to handle errors
        function handleError(error) {
            hideLoading();
            let errorMessage = 'Failed to generate PDF';
            
            if (error.message) {
                errorMessage = error.message;
            }
            
            alert('Error: ' + errorMessage);
            console.error('Error:', error);
        }

        // Function to generate report
        function generateReport(format = 'pdf') {
            const academicYear = document.getElementById('academic_year').value;
            const batchYear = document.getElementById('batch_year').value;
            const reportType = document.getElementById('report_type').value;
            
            showLoading();
            
            const params = new URLSearchParams({
                academic_year: academicYear,
                batch_year: batchYear,
                report_type: reportType,
                format: format
            });

            fetch(`generate_department_report.php?${params.toString()}`)
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    
                    if (!response.ok) {
                        if (contentType && contentType.includes('application/json')) {
                            return response.json().then(err => {
                                throw new Error(err.error || 'Failed to generate PDF');
                            });
                        }
                        throw new Error('HTTP error! status: ' + response.status);
                    }
                    
                    if (contentType && contentType.includes('application/json')) {
                        return response.json().then(err => {
                            throw new Error(err.error || 'Failed to generate PDF');
                        });
                    }
                    
                    if (!contentType || !contentType.includes('application/pdf')) {
                        throw new Error('Invalid response type: ' + contentType);
                    }
                    
                    return response.blob();
                })
                .then(blob => {
                    if (!(blob instanceof Blob)) {
                        throw new Error('Invalid response format');
                    }
                    
                    hideLoading();
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `${reportType}_Report_${new Date().toISOString().split('T')[0]}.pdf`;
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                })
                .catch(handleError);
        }

        // Handle print functionality
        document.querySelector('.btn[onclick="window.print()"]').addEventListener('click', function(e) {
            e.preventDefault();
            window.print();
        });
    </script>
</body>
</html> 