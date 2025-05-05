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
$skill_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit_mode = $skill_id > 0;

// Handle skill deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && $skill_id > 0) {
    try {
        $query = "DELETE FROM student_skills WHERE id = ? AND student_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $skill_id, $user_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to delete skill: " . mysqli_error($conn));
        }
        
        header('Location: recruitment_profile.php?tab=skills&deleted=1');
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Sanitize inputs
        $skill_name = sanitize_input($_POST['skill_name']);
        $proficiency = sanitize_input($_POST['proficiency'] ?? null);
        $is_top_skill = isset($_POST['is_top_skill']) ? 1 : 0;
        
        // Validate inputs
        if (empty($skill_name)) {
            throw new Exception("Skill name is required");
        }
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        // Check if the skill already exists for this student (only for new skills)
        if (!$is_edit_mode) {
            $check_query = "SELECT id FROM student_skills WHERE student_id = ? AND skill_name = ?";
            $check_stmt = mysqli_prepare($conn, $check_query);
            mysqli_stmt_bind_param($check_stmt, "is", $user_id, $skill_name);
            mysqli_stmt_execute($check_stmt);
            mysqli_stmt_store_result($check_stmt);
            
            if (mysqli_stmt_num_rows($check_stmt) > 0) {
                throw new Exception("You have already added this skill to your profile.");
            }
        }
        
        if ($is_edit_mode) {
            // Update existing skill
            $query = "UPDATE student_skills SET 
                     skill_name = ?, 
                     proficiency = ?, 
                     is_top_skill = ?
                     WHERE id = ? AND student_id = ?";
                     
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssiii", 
                $skill_name, 
                $proficiency, 
                $is_top_skill, 
                $skill_id, 
                $user_id
            );
        } else {
            // Create new skill entry
            $query = "INSERT INTO student_skills (
                student_id, skill_name, proficiency, is_top_skill
            ) VALUES (?, ?, ?, ?)";
                
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "issi", 
                $user_id,
                $skill_name, 
                $proficiency, 
                $is_top_skill
            );
        }
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to " . ($is_edit_mode ? "update" : "add") . " skill: " . mysqli_error($conn));
        }
        
        // Log the action
        // Temporarily commenting out the log_user_action call that's causing the "Unknown column 'timestamp'" error
        log_user_action($user_id, ($is_edit_mode ? "Updated" : "Added") . " skill: " . $skill_name, "student");
        
        mysqli_commit($conn);
        
        // Redirect back to recruitment profile with success message
        header('Location: recruitment_profile.php?tab=skills&success=1');
        exit();
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// If in edit mode, fetch skill data
$skill = null;
if ($is_edit_mode) {
    $query = "SELECT * FROM student_skills WHERE id = ? AND student_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $skill_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $skill = mysqli_fetch_assoc($result);
    
    // If no matching skill entry found, redirect
    if (!$skill) {
        header('Location: recruitment_profile.php?tab=skills&error=not_found');
        exit();
    }
}

// Page title
$page_title = ($is_edit_mode ? "Edit" : "Add") . " Skill";
include 'header.php';
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

    /* Info Box */
    .neu-info-box {
        background: var(--bg-color);
        border-radius: 15px;
        box-shadow: var(--inner-shadow);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        transition: all var(--transition-speed) ease;
        position: relative;
        overflow: hidden;
    }

    .neu-info-box h5 {
        color: var(--primary-color);
        margin-bottom: 1rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .neu-info-box h5 i {
        color: var(--primary-color);
    }

    .neu-info-box.highlight {
        background: rgba(78, 115, 223, 0.05);
    }

    .neu-info-box .endorsement-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: var(--primary-color);
        color: white;
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-weight: 500;
        margin-bottom: 0.5rem;
    }

    /* Skill Tips */
    .skill-tips-list {
        list-style-type: none;
        padding-left: 0;
        margin-bottom: 0;
    }

    .skill-tips-list li {
        position: relative;
        padding-left: 1.5rem;
        margin-bottom: 0.8rem;
        color: #6c757d;
        font-size: 0.9rem;
    }

    .skill-tips-list li:last-child {
        margin-bottom: 0;
    }

    .skill-tips-list li::before {
        content: '•';
        position: absolute;
        left: 0.5rem;
        color: var(--primary-color);
        font-weight: bold;
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

    .text-muted {
        color: #6c757d;
    }

    .small {
        font-size: 0.875rem;
    }

    .mb-0 {
        margin-bottom: 0;
    }

    .mb-2 {
        margin-bottom: 0.5rem;
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
            <h4><i class="fas fa-tools"></i> <?php echo $page_title; ?></h4>
            <a href="recruitment_profile.php?tab=skills" class="neu-btn">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>
        
        <div class="neu-card-body">
            <form method="post" id="skillForm">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="skill_name" class="form-label">Skill Name*</label>
                            <input type="text" class="form-control" id="skill_name" name="skill_name" 
                                value="<?php echo htmlspecialchars($skill['skill_name'] ?? ''); ?>" required>
                            <div class="form-text">e.g., Java Programming, Data Analysis, Project Management, etc.</div>
                        </div>
                        
                        <div class="form-group">
                            <label for="proficiency" class="form-label">Proficiency Level</label>
                            <select class="form-select" id="proficiency" name="proficiency">
                                <option value="">Select Level</option>
                                <option value="Beginner" <?php echo (isset($skill['proficiency']) && $skill['proficiency'] == 'Beginner') ? 'selected' : ''; ?>>Beginner</option>
                                <option value="Intermediate" <?php echo (isset($skill['proficiency']) && $skill['proficiency'] == 'Intermediate') ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="Advanced" <?php echo (isset($skill['proficiency']) && $skill['proficiency'] == 'Advanced') ? 'selected' : ''; ?>>Advanced</option>
                                <option value="Expert" <?php echo (isset($skill['proficiency']) && $skill['proficiency'] == 'Expert') ? 'selected' : ''; ?>>Expert</option>
                            </select>
                        </div>
                        
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_top_skill" name="is_top_skill" 
                                <?php echo (isset($skill['is_top_skill']) && $skill['is_top_skill']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_top_skill">Show as top skill on profile</label>
                            <div class="form-text">Top skills will be highlighted and shown first on your profile</div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="neu-info-box <?php echo ($is_edit_mode && isset($skill['endorsement_count']) && $skill['endorsement_count'] > 0) ? 'highlight' : ''; ?>">
                            <h5><i class="fas fa-medal"></i> Skill Endorsements</h5>
                            <?php if ($is_edit_mode && isset($skill['endorsement_count']) && $skill['endorsement_count'] > 0): ?>
                                <div class="endorsement-badge">
                                    <i class="fas fa-thumbs-up"></i>
                                    <span><?php echo $skill['endorsement_count']; ?> endorsement<?php echo $skill['endorsement_count'] > 1 ? 's' : ''; ?></span>
                                </div>
                                <div class="text-muted small">
                                    Endorsements are received when other users verify your skill proficiency.
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">
                                    You don't have any endorsements for this skill yet.
                                    <br>Endorsements will appear here when other users verify your skill proficiency.
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="neu-info-box">
                            <h5><i class="fas fa-lightbulb"></i> Tips for presenting skills</h5>
                            <ul class="skill-tips-list">
                                <li>Add both technical and soft skills relevant to your career goals</li>
                                <li>Be specific with technical skills (e.g., "Python" instead of just "Programming")</li>
                                <li>Only mark skills as top skills if you're confident in your ability</li>
                                <li>Be honest about your proficiency level</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="neu-btn neu-btn-primary px-4">
                        <i class="fas fa-save"></i> <?php echo $is_edit_mode ? 'Update' : 'Add'; ?> Skill
                    </button>
                    
                    <?php if ($is_edit_mode): ?>
                    <a href="edit_skills.php?id=<?php echo $skill_id; ?>&action=delete" 
                       class="neu-btn neu-btn-danger px-4 ms-2" 
                       onclick="return confirm('Are you sure you want to delete this skill?');">
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
    // Form validation
    document.getElementById('skillForm').addEventListener('submit', function(event) {
        const skillName = document.getElementById('skill_name').value.trim();
        
        if (!skillName) {
            alert('Please enter a skill name.');
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