<?php
// Disable error reporting and output buffering
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

// Helper function to get rating status
function getRatingStatus($rating) {
    if ($rating >= 4.5) return 'Excellent';
    if ($rating >= 4.0) return 'Very Good';
    if ($rating >= 3.5) return 'Good';
    if ($rating >= 3.0) return 'Satisfactory';
    return 'Needs Improvement';
}

// Function to send JSON error response
function sendJsonError($message, $code = 500) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit();
}

try {
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Include required files
    require_once 'db_connection.php';
    require_once 'functions.php';
    require('fpdf.php');

    // Check if user is HOD
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hod') {
        sendJsonError('Unauthorized access', 401);
    }

    // Check for department ID
    if (!isset($_SESSION['department_id'])) {
        sendJsonError('Department ID not found in session', 400);
    }

    $user_id = $_SESSION['user_id'];
    $department_id = $_SESSION['department_id'];

    // Get parameters from URL
    $academic_year_id = isset($_GET['academic_year']) ? intval($_GET['academic_year']) : null;
    $batch_year_id = isset($_GET['batch_year']) ? $_GET['batch_year'] : 'all';
    $report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'department';

    // Validate academic year
    if (!$academic_year_id) {
        // Get current academic year if not specified
        $academic_year_query = "SELECT * FROM academic_years WHERE is_current = TRUE LIMIT 1";
        $academic_year_result = mysqli_query($conn, $academic_year_query);
        if (!$academic_year_result || !($current_academic_year = mysqli_fetch_assoc($academic_year_result))) {
            throw new Exception('No current academic year found');
        }
        $academic_year_id = $current_academic_year['id'];
    } else {
        // Get specified academic year
        $academic_year_query = "SELECT * FROM academic_years WHERE id = ?";
        $stmt = mysqli_prepare($conn, $academic_year_query);
        mysqli_stmt_bind_param($stmt, "i", $academic_year_id);
        mysqli_stmt_execute($stmt);
        $current_academic_year = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$current_academic_year) {
            throw new Exception('Invalid academic year specified');
        }
    }

    // Get department details
    $dept_query = "SELECT d.*, h.name as hod_name 
                   FROM departments d 
                   LEFT JOIN hods h ON h.department_id = d.id 
                   WHERE d.id = ?";
    $dept_stmt = mysqli_prepare($conn, $dept_query);
    mysqli_stmt_bind_param($dept_stmt, "i", $department_id);
    mysqli_stmt_execute($dept_stmt);
    $department = mysqli_fetch_assoc(mysqli_stmt_get_result($dept_stmt));

    if (!$department) {
        throw new Exception('Department not found');
    }

    // Modify the feedback query based on batch year and report type
    $batch_condition = "";
    $department_condition = "";
    $params = [];
    $types = "";

    if ($batch_year_id !== 'all') {
        $batch_condition = "AND st.batch_id = ?";
        $params[] = $batch_year_id;
        $types .= "i";
    }

    if ($report_type === 'department') {
        $department_condition = "AND s.department_id = ?";
        $params[] = $department_id;
        $types .= "i";
    }

    $params[] = $academic_year_id;
    $types .= "i";

    // Modified feedback statistics query
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
    JOIN students st ON fb.student_id = st.id
    WHERE 1=1 
    {$batch_condition}
    {$department_condition}
    AND sa.academic_year_id = ?";

    $feedback_stmt = mysqli_prepare($conn, $feedback_query);
    if (!$feedback_stmt) {
        throw new Exception('Database error: ' . mysqli_error($conn));
    }

    if (!empty($params)) {
        mysqli_stmt_bind_param($feedback_stmt, $types, ...$params);
    }
    
    mysqli_stmt_execute($feedback_stmt);
    $feedback_stats = mysqli_fetch_assoc(mysqli_stmt_get_result($feedback_stmt));

    class DepartmentPDF extends FPDF {
        protected $department;
        
        function setDepartment($dept) {
            $this->department = $dept;
        }

        function Header() {
            // Logo
            if (file_exists('college_logo.png')) {
                $this->Image('college_logo.png', 20, 10, 25);
            }
            
            // College Name and Details
            $this->SetFont('Arial', 'B', 18);
            $this->SetTextColor(0, 51, 102); // Dark blue color
            $this->Cell(24); // Space for logo
            $this->Cell(165, 13, 'PANIMALAR ENGINEERING COLLEGE', 0, 1, 'C');
            
            // College Status
            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0);
            $this->Cell(20); // Add space from left
            $this->Cell(0, 5, 'An Autonomous Institution, Affiliated to Anna University, Chennai', 0, 1, 'C');
            
            // College Address
            $this->SetFont('Arial', '', 9);
            $this->Cell(20); // Add space from left
            $this->Cell(0, 5, 'Bangalore Trunk Road, Varadharajapuram, Poonamallee, Chennai - 600 123', 0, 1, 'C');
            
            // Add some extra space before the line
            $this->Ln(7);
            
            // First decorative line
            $this->SetDrawColor(0, 51, 102);
            $this->SetLineWidth(0.5);
            $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
            $this->Ln(4);

            // Report Title
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(0, 51, 102);
            $this->Cell(0, 8, 'Department Performance Report', 0, 1, 'C');

            // Department Name
            if ($this->department) {
                $this->SetFont('Arial', 'B', 12);
                $this->SetTextColor(0);
                $this->Cell(0, 6, $this->department['name'] . ' Department', 0, 1, 'C');
            }
            
            // Second decorative line
            $this->SetDrawColor(0, 51, 102);
            $this->SetLineWidth(0.5);
            $this->Line(20, $this->GetY() + 2, 190, $this->GetY() + 2);
            $this->Ln(8);
        }

        function Footer() {
            $this->SetY(-20);
            
            // Subtle line above footer
            $this->SetDrawColor(189, 195, 199);
            $this->Line(15, $this->GetY(), 195, $this->GetY());
            
            // Footer text
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(128);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'L');
            $this->Cell(0, 10, 'Generated on: ' . date('F j, Y'), 0, 0, 'R');
        }

        function ChapterTitle($title) {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(0, 51, 102);
            $this->Cell(0, 10, $title, 0, 1, 'L');
            $this->Line($this->GetX(), $this->GetY(), 190, $this->GetY());
            $this->Ln(5);
        }

        function AddInfoSection($label, $value) {
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(60, 8, $label . ':', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(0, 8, $value, 0, 1);
        }

        function CreateTable($headers, $data) {
            // Colors, line width and bold font
            $this->SetFillColor(0, 51, 102);
            $this->SetTextColor(255);
            $this->SetDrawColor(128, 128, 128);
            $this->SetLineWidth(.3);
            $this->SetFont('', 'B');

            // Header
            $w = array_values($headers);
            $columns = array_keys($headers);
            foreach($columns as $i => $column) {
                $this->Cell($w[$i], 7, $column, 1, 0, 'C', true);
            }
            $this->Ln();

            // Color and font restoration
            $this->SetFillColor(244, 245, 247);
            $this->SetTextColor(0);
            $this->SetFont('');

            // Data
            $fill = false;
            foreach($data as $row) {
                foreach($columns as $i => $column) {
                    $this->Cell($w[$i], 6, $row[$column], 'LR', 0, 'C', $fill);
                }
                $this->Ln();
                $fill = !$fill;
            }

            // Closing line
            $this->Cell(array_sum($w), 0, '', 'T');
        }
    }

    // Create new PDF instance
    $pdf = new DepartmentPDF();
    $pdf->setDepartment($department);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    // Department Information
    $pdf->ChapterTitle('Department Information');
    $pdf->AddInfoSection('HOD Name', $department['hod_name']);
    $pdf->AddInfoSection('Academic Year', $current_academic_year['year_range']);
    $pdf->Ln(5);

    // Faculty Statistics
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

    $pdf->ChapterTitle('Faculty Statistics');
    $pdf->AddInfoSection('Total Faculty', $faculty_stats['total_faculty']);
    $pdf->AddInfoSection('Average Experience', number_format($faculty_stats['avg_experience'], 1) . ' years');
    $pdf->AddInfoSection('Professors', $faculty_stats['professors']);
    $pdf->AddInfoSection('Associate Professors', $faculty_stats['associate_professors']);
    $pdf->AddInfoSection('Assistant Professors', $faculty_stats['assistant_professors']);
    $pdf->Ln(5);

    // Feedback Statistics
    $pdf->AddPage();
    $pdf->ChapterTitle('Feedback Analysis');
    $pdf->AddInfoSection('Total Feedback Received', $feedback_stats['total_feedback']);
    $pdf->AddInfoSection('Overall Department Rating', number_format($feedback_stats['overall_rating'], 2) . ' / 5.00');
    $pdf->Ln(5);

    // Performance Categories Table
    $pdf->ChapterTitle('Performance Categories');
    $performance_data = array(
        array(
            'Category' => 'Course Effectiveness',
            'Rating' => number_format($feedback_stats['course_effectiveness'], 2),
            'Status' => getRatingStatus($feedback_stats['course_effectiveness'])
        ),
        array(
            'Category' => 'Teaching Effectiveness',
            'Rating' => number_format($feedback_stats['teaching_effectiveness'], 2),
            'Status' => getRatingStatus($feedback_stats['teaching_effectiveness'])
        ),
        array(
            'Category' => 'Resources & Administration',
            'Rating' => number_format($feedback_stats['resources_admin'], 2),
            'Status' => getRatingStatus($feedback_stats['resources_admin'])
        ),
        array(
            'Category' => 'Assessment & Learning',
            'Rating' => number_format($feedback_stats['assessment_learning'], 2),
            'Status' => getRatingStatus($feedback_stats['assessment_learning'])
        ),
        array(
            'Category' => 'Course Outcomes',
            'Rating' => number_format($feedback_stats['course_outcomes'], 2),
            'Status' => getRatingStatus($feedback_stats['course_outcomes'])
        )
    );

    $headers = array(
        'Category' => 80,
        'Rating' => 30,
        'Status' => 70
    );
    $pdf->CreateTable($headers, $performance_data);
    $pdf->Ln(10);

    // Top Performing Faculty
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
    $top_faculty_result = mysqli_stmt_get_result($top_faculty_stmt);

    $pdf->AddPage();
    $pdf->ChapterTitle('Top Performing Faculty');
    $top_faculty_data = array();
    while ($faculty = mysqli_fetch_assoc($top_faculty_result)) {
        $top_faculty_data[] = array(
            'Name' => $faculty['name'],
            'Designation' => $faculty['designation'],
            'Feedback Count' => $faculty['feedback_count'],
            'Rating' => number_format($faculty['avg_rating'], 2)
        );
    }

    $headers = array(
        'Name' => 60,
        'Designation' => 50,
        'Feedback Count' => 30,
        'Rating' => 40
    );
    $pdf->CreateTable($headers, $top_faculty_data);

    // Modify the filename to include the report type and batch info
    $filename = sprintf(
        '%s_Report_%s_%s%s.pdf',
        ucfirst($report_type),
        $department['code'],
        $batch_year_id !== 'all' ? "Batch_" . $batch_year_id . "_" : "",
        date('Y-m-d')
    );

    // Before generating PDF, clear all output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set PDF headers
    header('Content-Type: application/pdf');
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Output PDF directly to browser
    $pdf->Output('D', $filename);
    exit();

} catch (Exception $e) {
    sendJsonError($e->getMessage());
} catch (Error $e) {
    sendJsonError('An unexpected error occurred: ' . $e->getMessage());
}
?> 