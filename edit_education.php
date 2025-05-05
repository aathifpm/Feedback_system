<?php
session_start();
include 'functions.php';

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';
$education_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit_mode = $education_id > 0;

// Handle education deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && $education_id > 0) {
    try {
        $query = "DELETE FROM student_education WHERE id = ? AND student_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $education_id, $user_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to delete education entry: " . mysqli_error($conn));
        }
        
        header('Location: recruitment_profile.php?tab=education&deleted=1');
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Sanitize inputs
        $institution_name = sanitize_input($_POST['institution_name']);
        $degree = sanitize_input($_POST['degree']);
        $field_of_study = sanitize_input($_POST['field_of_study'] ?? '');
        $start_year = intval($_POST['start_year']);
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $end_year = $is_current ? NULL : intval($_POST['end_year']);
        $grade = sanitize_input($_POST['grade'] ?? '');
        $activities = sanitize_input($_POST['activities'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        
        // Validate inputs
        if (empty($institution_name)) {
            throw new Exception("Institution name is required");
        }
        
        if (empty($degree)) {
            throw new Exception("Degree/Certificate is required");
        }
        
        if ($start_year < 1900 || $start_year > date('Y')) {
            throw new Exception("Invalid start year");
        }
        
        if (!$is_current && ($end_year < $start_year || $end_year > date('Y'))) {
            throw new Exception("End year must be after start year and not in the future");
        }
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        if ($is_edit_mode) {
            // Update existing education entry
            $query = "UPDATE student_education SET 
                     institution_name = ?, 
                     degree = ?, 
                     field_of_study = ?, 
                     start_year = ?, 
                     end_year = ?, 
                     is_current = ?, 
                     grade = ?, 
                     activities = ?, 
                     description = ? 
                     WHERE id = ? AND student_id = ?";
                     
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "sssisisssii", 
                $institution_name, 
                $degree, 
                $field_of_study, 
                $start_year, 
                $end_year, 
                $is_current, 
                $grade, 
                $activities, 
                $description, 
                $education_id, 
                $user_id
            );
        } else {
            // Create new education entry
            $query = "INSERT INTO student_education (
                student_id, institution_name, degree, field_of_study, 
                start_year, end_year, is_current, grade, activities, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "isssisssss", 
                $user_id,
                $institution_name, 
                $degree, 
                $field_of_study, 
                $start_year, 
                $end_year, 
                $is_current, 
                $grade, 
                $activities, 
                $description
            );
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to " . ($is_edit_mode ? "update" : "add") . " education entry: " . mysqli_error($conn));
        }
        
        // Log the action
        log_user_action($user_id, ($is_edit_mode ? "Updated" : "Added") . " education details", "student");
        
        mysqli_commit($conn);
        
        // Redirect back to recruitment profile with success message
        header('Location: recruitment_profile.php?tab=education&success=1');
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// If in edit mode, fetch education data
$education = null;
if ($is_edit_mode) {
    $query = "SELECT * FROM student_education WHERE id = ? AND student_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $education_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $education = mysqli_fetch_assoc($result);
    
    // If no matching education entry found, redirect
    if (!$education) {
        header('Location: recruitment_profile.php?tab=education&error=not_found');
        exit();
    }
}

// Page title
$page_title = ($is_edit_mode ? "Edit" : "Add") . " Education";
include 'header.php';

// Current year for dropdown limits
$current_year = date('Y');
?>

<style>
    :root {
        --primary-color: #4e73df;  
        --primary-hover: #3a5ecc;
        --text-color: #2c3e50;
        --bg-color: #e0e5ec;
        --card-bg: #e8ecf2;
        --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
                 -9px -9px 16px rgba(255,255,255, 0.5);
        --soft-shadow: 5px 5px 10px rgb(163,177,198,0.4), 
                      -5px -5px 10px rgba(255,255,255, 0.4);
        --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                       inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
        --transition-speed: 0.3s;
    }

    body {
        background: var(--bg-color);
        color: var(--text-color);
    }

    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 2rem;
    }

    /* Neumorphic Card */
    .neu-card {
        background: var(--card-bg);
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: none;
        overflow: hidden;
        margin-bottom: 2rem;
        transition: all var(--transition-speed) ease;
    }

    .neu-card-header {
        background: var(--primary-color);
        color: white;
        padding: 1.8rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: none;
    }

    .neu-card-header h4 {
        margin: 0;
        font-size: 1.6rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .neu-card-body {
        padding: 2rem;
    }

    /* Form Controls */
    .form-control, .form-select {
        width: 100%;
        padding: 1rem 1.2rem;
        border: none;
        border-radius: 12px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        color: var(--text-color);
        transition: all var(--transition-speed) ease;
        margin-bottom: 0.2rem;
        font-size: 1rem;
    }

    .form-control:focus, .form-select:focus {
        box-shadow: var(--shadow);
        outline: none;
        transform: translateY(-2px);
    }

    /* Form Groups */
    .form-group {
        margin-bottom: 1.8rem;
    }

    .form-label {
        display: block;
        margin-bottom: 0.8rem;
        color: var(--text-color);
        font-weight: 600;
        font-size: 1.05rem;
    }

    .form-text {
        color: #6c757d;
        font-size: 0.9rem;
        margin-top: 0.5rem;
        font-style: italic;
    }

    /* Form Checks */
    .form-check {
        position: relative;
        display: flex;
        align-items: center;
        padding-left: 1.8rem;
        margin: 1.5rem 0;
    }

    .form-check-input {
        position: absolute;
        left: 0;
        margin-top: 0.25rem;
        cursor: pointer;
        width: 1.2rem;
        height: 1.2rem;
    }

    .form-check-label {
        margin-bottom: 0;
        cursor: pointer;
        user-select: none;
        font-weight: 500;
    }

    /* Buttons */
    .neu-btn {
        background: var(--bg-color);
        border: none;
        border-radius: 12px;
        padding: 0.9rem 1.6rem;
        color: var(--text-color);
        box-shadow: var(--soft-shadow);
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        text-decoration: none;
        font-weight: 500;
        position: relative;
        overflow: hidden;
        z-index: 1;
        cursor: pointer;
    }

    .neu-btn::after {
        content: '';
        position: absolute;
        top: 50%;
        left: 50%;
        width: 0;
        height: 0;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        z-index: -1;
        transition: width 0.6s, height 0.6s;
    }

    .neu-btn:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow);
    }

    .neu-btn:hover::after {
        width: 300px;
        height: 300px;
    }

    .neu-btn-primary {
        background: var(--primary-color);
        color: white;
    }

    .neu-btn-primary:hover {
        background: var(--primary-hover);
    }

    .neu-btn-danger {
        background: #e74a3b;
        color: white;
    }

    .neu-btn-danger:hover {
        background: #c0392b;
    }

    /* Alerts */
    .neu-alert {
        background: var(--bg-color);
        border: none;
        border-radius: 15px;
        box-shadow: var(--soft-shadow);
        padding: 1.2rem 1.8rem;
        margin-bottom: 1.8rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        position: relative;
        overflow: hidden;
    }

    .neu-alert::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 5px;
    }

    .neu-alert-danger {
        background: #fff0f0;
        color: #e74a3b;
    }

    .neu-alert-danger::before {
        background: #e74a3b;
    }

    /* Layout */
    .row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -15px;
    }

    .col-md-6 {
        flex: 0 0 50%;
        max-width: 50%;
        padding: 0 15px;
        position: relative;
        width: 100%;
    }

    .text-center {
        text-align: center;
    }

    .mt-4 {
        margin-top: 2rem;
    }

    .ms-2 {
        margin-left: 0.5rem;
    }

    .px-4 {
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }

    @media (max-width: 767.98px) {
        .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        .neu-card-header {
            flex-direction: column;
            gap: 1rem;
            text-align: center;
            padding: 1.5rem;
        }
        
        .neu-card-body {
            padding: 1.5rem 1rem;
        }
        
        .container {
            padding: 1rem;
        }
    }
</style>

<div class="container">
    <?php if ($error_message): ?>
        <div class="neu-alert neu-alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
        </div>
    <?php endif; ?>

    <div class="neu-card">
        <div class="neu-card-header">
            <h4><i class="fas fa-graduation-cap"></i> <?php echo $page_title; ?></h4>
            <a href="recruitment_profile.php?tab=education" class="neu-btn">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>
        
        <div class="neu-card-body">
            <form method="post" id="educationForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="institution_name" class="form-label">School/University/Institution*</label>
                            <input type="text" class="form-control" id="institution_name" name="institution_name" 
                                value="<?php echo htmlspecialchars($education['institution_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="degree" class="form-label">Degree/Certificate*</label>
                            <input type="text" class="form-control" id="degree" name="degree" 
                                value="<?php echo htmlspecialchars($education['degree'] ?? ''); ?>" required>
                            <div class="form-text">e.g., Bachelor of Technology, High School Diploma, etc.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="field_of_study" class="form-label">Field of Study</label>
                            <input type="text" class="form-control" id="field_of_study" name="field_of_study" 
                                value="<?php echo htmlspecialchars($education['field_of_study'] ?? ''); ?>">
                            <div class="form-text">e.g., Computer Science, Mechanical Engineering, etc.</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="start_year" class="form-label">Start Year*</label>
                            <select class="form-select" id="start_year" name="start_year" required>
                                <option value="">Select Year</option>
                                <?php for ($year = $current_year; $year >= 1980; $year--): ?>
                                    <option value="<?php echo $year; ?>" <?php echo (isset($education['start_year']) && $education['start_year'] == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_current" name="is_current" 
                                <?php echo (isset($education['is_current']) && $education['is_current']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_current">I am currently studying here</label>
                        </div>
                        
                        <div class="form-group" id="end_year_container">
                            <label for="end_year" class="form-label">End Year (or expected)</label>
                            <select class="form-select" id="end_year" name="end_year">
                                <option value="">Select Year</option>
                                <?php for ($year = $current_year + 5; $year >= 1980; $year--): ?>
                                    <option value="<?php echo $year; ?>" <?php echo (isset($education['end_year']) && $education['end_year'] == $year) ? 'selected' : ''; ?>>
                                        <?php echo $year; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="grade" class="form-label">Grade/CGPA</label>
                            <input type="text" class="form-control" id="grade" name="grade" 
                                value="<?php echo htmlspecialchars($education['grade'] ?? ''); ?>">
                            <div class="form-text">e.g., 3.8/4.0, 85%, First Class, etc.</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="activities" class="form-label">Activities and Societies</label>
                    <textarea class="form-control" id="activities" name="activities" rows="2"><?php echo htmlspecialchars($education['activities'] ?? ''); ?></textarea>
                    <div class="form-text">Clubs, sports, leadership roles, etc. that you participated in</div>
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($education['description'] ?? ''); ?></textarea>
                    <div class="form-text">Additional details about your education, achievements, courses, etc.</div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="neu-btn neu-btn-primary px-4">
                        <i class="fas fa-save"></i> <?php echo $is_edit_mode ? 'Update' : 'Add'; ?> Education
                    </button>
                    
                    <?php if ($is_edit_mode): ?>
                    <a href="edit_education.php?id=<?php echo $education_id; ?>&action=delete" 
                       class="neu-btn neu-btn-danger px-4 ms-2" 
                       onclick="return confirm('Are you sure you want to delete this education entry?');">
                        <i class="fas fa-trash"></i> Delete
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const isCurrentCheckbox = document.getElementById('is_current');
    const endYearContainer = document.getElementById('end_year_container');
    const endYearSelect = document.getElementById('end_year');
    
    // Function to toggle end year field visibility
    function toggleEndYear() {
        if (isCurrentCheckbox.checked) {
            endYearContainer.style.opacity = '0.5';
            endYearSelect.disabled = true;
        } else {
            endYearContainer.style.opacity = '1';
            endYearSelect.disabled = false;
        }
    }
    
    // Initialize the state
    toggleEndYear();
    
    // Listen for changes
    isCurrentCheckbox.addEventListener('change', toggleEndYear);
    
    // Form validation
    document.getElementById('educationForm').addEventListener('submit', function(event) {
        const startYear = parseInt(document.getElementById('start_year').value);
        const endYear = parseInt(document.getElementById('end_year').value);
        const isCurrentChecked = document.getElementById('is_current').checked;
        
        if (!isCurrentChecked && (isNaN(endYear) || endYear < startYear)) {
            alert('End year must be after start year.');
            event.preventDefault();
        }
    });

    // Add animation to form elements
    const formElements = document.querySelectorAll('.form-control, .form-select, .neu-btn');
    formElements.forEach(element => {
        element.addEventListener('mouseover', function() {
            this.style.transition = 'transform 0.3s ease';
        });
    });
});
</script>

<?php include 'footer.php'; ?> 