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

// Get certificate ID if editing
$certificate_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit_mode = $certificate_id > 0;

// Handle certificate deletion
if (isset($_GET['action']) && $_GET['action'] == 'delete' && $certificate_id > 0) {
    try {
        $query = "DELETE FROM student_certificates WHERE id = ? AND student_id = ?";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "ii", $certificate_id, $user_id);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to delete certificate: " . mysqli_error($conn));
        }
        
        header('Location: recruitment_profile.php?tab=basic&deleted=1');
        exit();
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $name = sanitize_input($_POST['cert_name']);
        $organization = sanitize_input($_POST['cert_organization']);
        $category = sanitize_input($_POST['cert_category']);
        $issue_date = sanitize_input($_POST['cert_issue_date']);
        $expiry_date = !empty($_POST['cert_expiry_date']) ? sanitize_input($_POST['cert_expiry_date']) : null;
        $credential_id = !empty($_POST['cert_credential_id']) ? sanitize_input($_POST['cert_credential_id']) : null;
        $credential_url = !empty($_POST['cert_credential_url']) ? sanitize_input($_POST['cert_credential_url']) : null;
        $description = !empty($_POST['cert_description']) ? sanitize_input($_POST['cert_description']) : null;

        if ($certificate_id) {
            // Update existing certificate
            $query = "UPDATE student_certificates SET 
                    name = ?, 
                    issuing_organization = ?,
                    category = ?,
                    issue_date = ?,
                    expiry_date = ?,
                    credential_id = ?,
                    credential_url = ?,
                    description = ?
                    WHERE id = ? AND student_id = ?";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "ssssssssii", 
                $name, $organization, $category, $issue_date, $expiry_date,
                $credential_id, $credential_url, $description, $certificate_id, $user_id
            );
        } else {
            // Add new certificate
            $query = "INSERT INTO student_certificates (
                student_id, name, issuing_organization, category, 
                issue_date, expiry_date, credential_id, credential_url, description
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $query);
            mysqli_stmt_bind_param($stmt, "issssssss", 
                $user_id, $name, $organization, $category, $issue_date,
                $expiry_date, $credential_id, $credential_url, $description
            );
        }

        if (mysqli_stmt_execute($stmt)) {
            header('Location: recruitment_profile.php?tab=basic&success=1');
            exit();
        } else {
            throw new Exception("Failed to " . ($certificate_id ? "update" : "add") . " certificate.");
        }

    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Fetch certificate data if editing
$certificate = null;
if ($certificate_id) {
    $query = "SELECT * FROM student_certificates WHERE id = ? AND student_id = ?";
    $stmt = mysqli_prepare($conn, $query);
    mysqli_stmt_bind_param($stmt, "ii", $certificate_id, $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $certificate = mysqli_fetch_assoc($result);

    if (!$certificate) {
        header('Location: recruitment_profile.php?tab=basic&error=1');
        exit();
    }
}

$page_title = ($certificate_id ? "Edit" : "Add") . " Certificate";
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

    .col-md-6, .col-md-12 {
        padding: 0 15px;
        position: relative;
        width: 100%;
    }

    .col-md-6 {
        flex: 0 0 50%;
        max-width: 50%;
    }

    .col-md-12 {
        flex: 0 0 100%;
        max-width: 100%;
    }

    .form-section {
        background: var(--bg-color);
        border-radius: 15px;
        box-shadow: var(--shadow);
        padding: 2rem;
        margin-bottom: 2rem;
    }

    .form-section h5 {
        color: var(--primary-color);
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .action-buttons {
        display: flex;
        gap: 1rem;
        justify-content: center;
        margin-top: 2rem;
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
            <h4><i class="fas fa-certificate"></i> <?php echo $page_title; ?></h4>
            <a href="recruitment_profile.php?tab=basic" class="neu-btn">
                <i class="fas fa-arrow-left"></i> Back to Profile
            </a>
        </div>
        
        <div class="neu-card-body">
            <form method="post" class="neu-form">
                <div class="form-section">
                    <h5><i class="fas fa-info-circle"></i> Basic Information</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cert_name" class="form-label">Certificate Name*</label>
                                <input type="text" class="form-control" id="cert_name" name="cert_name" 
                                    value="<?php echo htmlspecialchars($certificate['name'] ?? ''); ?>" required>
                                <div class="form-text">Enter the full name of the certificate/certification</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cert_organization" class="form-label">Issuing Organization*</label>
                                <input type="text" class="form-control" id="cert_organization" name="cert_organization" 
                                    value="<?php echo htmlspecialchars($certificate['issuing_organization'] ?? ''); ?>" required>
                                <div class="form-text">Name of the organization that issued the certificate</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="cert_category" class="form-label">Category*</label>
                        <select class="form-select" id="cert_category" name="cert_category" required>
                            <option value="">Select Category</option>
                            <option value="internship" <?php echo (isset($certificate['category']) && $certificate['category'] == 'internship') ? 'selected' : ''; ?>>Internship Certificate</option>
                            <option value="course" <?php echo (isset($certificate['category']) && $certificate['category'] == 'course') ? 'selected' : ''; ?>>Course Completion</option>
                            <option value="achievement" <?php echo (isset($certificate['category']) && $certificate['category'] == 'achievement') ? 'selected' : ''; ?>>Achievement/Event</option>
                        </select>
                        <div class="form-text">Choose the category that best describes this certificate</div>
                    </div>
                </div>

                <div class="form-section">
                    <h5><i class="fas fa-calendar-alt"></i> Date Information</h5>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cert_issue_date" class="form-label">Issue Date*</label>
                                <input type="date" class="form-control" id="cert_issue_date" name="cert_issue_date" 
                                    value="<?php echo htmlspecialchars($certificate['issue_date'] ?? ''); ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cert_expiry_date" class="form-label">Expiry Date (Optional)</label>
                                <input type="date" class="form-control" id="cert_expiry_date" name="cert_expiry_date" 
                                    value="<?php echo htmlspecialchars($certificate['expiry_date'] ?? ''); ?>">
                                <div class="form-text">Leave blank if the certificate does not expire</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h5><i class="fas fa-link"></i> Verification Details</h5>
                    
                    <div class="form-group">
                        <label for="cert_credential_id" class="form-label">Credential ID (Optional)</label>
                        <input type="text" class="form-control" id="cert_credential_id" name="cert_credential_id" 
                            value="<?php echo htmlspecialchars($certificate['credential_id'] ?? ''); ?>">
                        <div class="form-text">Enter the unique identifier for your certificate if available</div>
                    </div>
                    
                    <div class="form-group">
                        <label for="cert_credential_url" class="form-label">Credential URL (Optional)</label>
                        <input type="url" class="form-control" id="cert_credential_url" name="cert_credential_url" 
                            value="<?php echo htmlspecialchars($certificate['credential_url'] ?? ''); ?>">
                        <div class="form-text">Link to verify your certificate online</div>
                    </div>
                </div>

                <div class="form-section">
                    <h5><i class="fas fa-align-left"></i> Additional Information</h5>
                    
                    <div class="form-group">
                        <label for="cert_description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="cert_description" name="cert_description" rows="4"
                            placeholder="Add any additional details about your certification..."><?php echo htmlspecialchars($certificate['description'] ?? ''); ?></textarea>
                        <div class="form-text">Include any relevant details about what you learned or achieved</div>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="recruitment_profile.php?tab=basic" class="neu-btn">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="neu-btn neu-btn-primary">
                        <i class="fas fa-save"></i> <?php echo $certificate_id ? 'Update' : 'Save'; ?> Certificate
                    </button>
                    
                    <?php if ($is_edit_mode): ?>
                    <a href="edit_certificates.php?id=<?php echo $certificate_id; ?>&action=delete" 
                       class="neu-btn neu-btn-danger" 
                       onclick="return confirm('Are you sure you want to delete this certificate?');">
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