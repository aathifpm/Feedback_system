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
$experience_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit_mode = $experience_id > 0;

// Handle experience deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && $experience_id > 0) {
    try {
        $query = "DELETE FROM student_experience WHERE id = ? AND student_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $experience_id, $user_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to delete experience entry: " . mysqli_error($conn));
        }
        
        header('Location: recruitment_profile.php?tab=experience&deleted=1');
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Sanitize inputs
        $title = sanitize_input($_POST['title']);
        $employment_type = sanitize_input($_POST['employment_type'] ?? null);
        $company_name = sanitize_input($_POST['company_name']);
        $location = sanitize_input($_POST['location'] ?? '');
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        
        // Parse dates
        $start_date = !empty($_POST['start_month']) && !empty($_POST['start_year']) 
                    ? date('Y-m-d', strtotime($_POST['start_year'] . '-' . $_POST['start_month'] . '-01'))
                    : null;
        
        $end_date = null;
        if (!$is_current && !empty($_POST['end_month']) && !empty($_POST['end_year'])) {
            $end_date = date('Y-m-d', strtotime($_POST['end_year'] . '-' . $_POST['end_month'] . '-01'));
        }
        
        $description = sanitize_input($_POST['description'] ?? '');
        $skills_used = sanitize_input($_POST['skills_used'] ?? '');
        
        // Validate inputs
        if (empty($title)) {
            throw new Exception("Job title is required");
        }
        
        if (empty($company_name)) {
            throw new Exception("Company name is required");
        }
        
        if (empty($start_date)) {
            throw new Exception("Start date is required");
        }
        
        if (!$is_current && empty($end_date)) {
            throw new Exception("End date is required if not current position");
        }
        
        if (!$is_current && $end_date < $start_date) {
            throw new Exception("End date must be after start date");
        }
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        if ($is_edit_mode) {
            // Update existing experience entry
            $query = "UPDATE student_experience SET 
                     title = ?, 
                     employment_type = ?, 
                     company_name = ?, 
                     location = ?, 
                     is_current = ?, 
                     start_date = ?, 
                     end_date = ?, 
                     description = ?,
                     skills_used = ?
                     WHERE id = ? AND student_id = ?";
                     
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssissssii", 
                $title, 
                $employment_type, 
                $company_name, 
                $location, 
                $is_current, 
                $start_date, 
                $end_date, 
                $description,
                $skills_used,
                $experience_id, 
                $user_id
            );
        } else {
            // Create new experience entry
            $query = "INSERT INTO student_experience (
                student_id, title, employment_type, company_name, 
                location, is_current, start_date, end_date, description, skills_used
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "issssissss", 
                $user_id,
                $title, 
                $employment_type, 
                $company_name, 
                $location, 
                $is_current, 
                $start_date, 
                $end_date, 
                $description,
                $skills_used
            );
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to " . ($is_edit_mode ? "update" : "add") . " experience entry: " . mysqli_error($conn));
        }
        
        // Log the action
        log_user_action($user_id, ($is_edit_mode ? "Updated" : "Added") . " work experience", "student");
        
        mysqli_commit($conn);
        
        // Redirect back to recruitment profile with success message
        header('Location: recruitment_profile.php?tab=experience&success=1');
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// If in edit mode, fetch experience data
$experience = null;
if ($is_edit_mode) {
    $query = "SELECT * FROM student_experience WHERE id = ? AND student_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $experience_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $experience = mysqli_fetch_assoc($result);
    
    // If no matching experience entry found, redirect
    if (!$experience) {
        header('Location: recruitment_profile.php?tab=experience&error=not_found');
        exit();
    }
}

// Page title
$page_title = ($is_edit_mode ? "Edit" : "Add") . " Work Experience";
include 'header.php';

// Extract dates for form population if in edit mode
$start_month = $start_year = $end_month = $end_year = '';
if ($is_edit_mode && !empty($experience['start_date'])) {
    $start_date = new DateTime($experience['start_date']);
    $start_month = $start_date->format('m');
    $start_year = $start_date->format('Y');
    
    if (!empty($experience['end_date'])) {
        $end_date = new DateTime($experience['end_date']);
        $end_month = $end_date->format('m');
        $end_year = $end_date->format('Y');
    }
}

// Current year for dropdown limits
$current_year = date('Y');
$months = [
    '01' => 'January',
    '02' => 'February',
    '03' => 'March',
    '04' => 'April',
    '05' => 'May',
    '06' => 'June',
    '07' => 'July',
    '08' => 'August',
    '09' => 'September',
    '10' => 'October',
    '11' => 'November',
    '12' => 'December'
];
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

    .form-control::placeholder {
        color: #98a6ad;
    }

    textarea.form-control {
        min-height: 120px;
        resize: vertical;
        line-height: 1.6;
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

    .mt-2 {
        margin-top: 0.5rem;
    }

    .mt-4 {
        margin-top: 2rem;
    }

    .mb-3 {
        margin-bottom: 1rem;
    }

    .ms-2 {
        margin-left: 0.5rem;
    }

    .px-4 {
        padding-left: 1.5rem;
        padding-right: 1.5rem;
    }

    .date-group {
        margin-bottom: 1.8rem;
    }

    .date-group .form-label {
        margin-bottom: 0.8rem;
    }

    .date-selects {
        display: flex;
        gap: 1rem;
    }

    .date-selects > div {
        flex: 1;
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

        .date-selects {
            flex-direction: column;
            gap: 0.5rem;
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
            <h4><i class="fas fa-briefcase"></i> <?php echo $page_title; ?></h4>
            <a href="recruitment_profile.php?tab=experience" class="neu-btn">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>
        
        <div class="neu-card-body">
            <form method="post" id="experienceForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="title" class="form-label">Job Title*</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                value="<?php echo htmlspecialchars($experience['title'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="employment_type" class="form-label">Employment Type</label>
                            <select class="form-select" id="employment_type" name="employment_type">
                                <option value="">Select Type</option>
                                <option value="Full-time" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Full-time') ? 'selected' : ''; ?>>Full-time</option>
                                <option value="Part-time" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Part-time') ? 'selected' : ''; ?>>Part-time</option>
                                <option value="Internship" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Internship') ? 'selected' : ''; ?>>Internship</option>
                                <option value="Freelance" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Freelance') ? 'selected' : ''; ?>>Freelance</option>
                                <option value="Contract" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Contract') ? 'selected' : ''; ?>>Contract</option>
                                <option value="Volunteer" <?php echo (isset($experience['employment_type']) && $experience['employment_type'] == 'Volunteer') ? 'selected' : ''; ?>>Volunteer</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="company_name" class="form-label">Company*</label>
                            <input type="text" class="form-control" id="company_name" name="company_name" 
                                value="<?php echo htmlspecialchars($experience['company_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="location" class="form-label">Location</label>
                            <input type="text" class="form-control" id="location" name="location" 
                                value="<?php echo htmlspecialchars($experience['location'] ?? ''); ?>">
                            <div class="form-text">e.g., Chennai, Tamil Nadu, India or Remote</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_current" name="is_current" 
                                <?php echo (isset($experience['is_current']) && $experience['is_current']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_current">I am currently working here</label>
                        </div>
                        
                        <div class="form-group date-group">
                            <label class="form-label">Start Date*</label>
                            <div class="date-selects">
                                <div>
                                    <select class="form-select" id="start_month" name="start_month" required>
                                        <option value="">Month</option>
                                        <?php foreach ($months as $key => $month): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($start_month == $key) ? 'selected' : ''; ?>>
                                                <?php echo $month; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <select class="form-select" id="start_year" name="start_year" required>
                                        <option value="">Year</option>
                                        <?php for ($year = $current_year; $year >= 1980; $year--): ?>
                                            <option value="<?php echo $year; ?>" <?php echo ($start_year == $year) ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group date-group" id="end_date_container">
                            <label class="form-label">End Date</label>
                            <div class="date-selects">
                                <div>
                                    <select class="form-select" id="end_month" name="end_month">
                                        <option value="">Month</option>
                                        <?php foreach ($months as $key => $month): ?>
                                            <option value="<?php echo $key; ?>" <?php echo ($end_month == $key) ? 'selected' : ''; ?>>
                                                <?php echo $month; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <select class="form-select" id="end_year" name="end_year">
                                        <option value="">Year</option>
                                        <?php for ($year = $current_year + 2; $year >= 1980; $year--): ?>
                                            <option value="<?php echo $year; ?>" <?php echo ($end_year == $year) ? 'selected' : ''; ?>>
                                                <?php echo $year; ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="description" class="form-label">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($experience['description'] ?? ''); ?></textarea>
                    <div class="form-text">Describe your role, responsibilities, achievements, etc.</div>
                </div>
                
                <div class="form-group">
                    <label for="skills_used" class="form-label">Skills</label>
                    <textarea class="form-control" id="skills_used" name="skills_used" rows="3" placeholder="e.g., Project Management, Java, Python, Leadership, etc."><?php echo htmlspecialchars($experience['skills_used'] ?? ''); ?></textarea>
                    <div class="form-text">List the skills you utilized in this role (comma or line separated)</div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="neu-btn neu-btn-primary px-4">
                        <i class="fas fa-save"></i> <?php echo $is_edit_mode ? 'Update' : 'Add'; ?> Experience
                    </button>
                    
                    <?php if ($is_edit_mode): ?>
                    <a href="edit_experience.php?id=<?php echo $experience_id; ?>&action=delete" 
                       class="neu-btn neu-btn-danger px-4 ms-2" 
                       onclick="return confirm('Are you sure you want to delete this experience?');">
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
    const endDateContainer = document.getElementById('end_date_container');
    const endMonthSelect = document.getElementById('end_month');
    const endYearSelect = document.getElementById('end_year');
    
    // Function to toggle end date fields visibility
    function toggleEndDate() {
        if (isCurrentCheckbox.checked) {
            endDateContainer.style.opacity = '0.5';
            endMonthSelect.disabled = true;
            endYearSelect.disabled = true;
        } else {
            endDateContainer.style.opacity = '1';
            endMonthSelect.disabled = false;
            endYearSelect.disabled = false;
        }
    }
    
    // Initialize the state
    toggleEndDate();
    
    // Listen for changes
    isCurrentCheckbox.addEventListener('change', toggleEndDate);
    
    // Form validation
    document.getElementById('experienceForm').addEventListener('submit', function(event) {
        const startMonth = document.getElementById('start_month').value;
        const startYear = document.getElementById('start_year').value;
        const endMonth = document.getElementById('end_month').value;
        const endYear = document.getElementById('end_year').value;
        const isCurrentChecked = document.getElementById('is_current').checked;
        
        if (!startMonth || !startYear) {
            alert('Please select a start date.');
            event.preventDefault();
            return;
        }
        
        if (!isCurrentChecked && (!endMonth || !endYear)) {
            alert('Please select an end date or check "I am currently working here".');
            event.preventDefault();
            return;
        }
        
        if (!isCurrentChecked && startYear > endYear) {
            alert('End year must be after start year.');
            event.preventDefault();
            return;
        }
        
        if (!isCurrentChecked && startYear == endYear && startMonth > endMonth) {
            alert('End date must be after start date.');
            event.preventDefault();
            return;
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