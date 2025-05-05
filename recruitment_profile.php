<?php
session_start();
include 'functions.php';

// Debug output for form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("Form submitted: " . json_encode($_POST));
}

// Check if user is logged in as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'student') {
    header('Location: index.php');
    exit();
}

// Function to compress PDF files to be under 800KB
function compress_pdf($source_file, $destination_file, $quality = 'ebook') {
    $max_size = 800 * 1024; // 800KB in bytes
    
    // If file is already under max size, just return the original file
    if (filesize($source_file) <= $max_size) {
        return $source_file;
    }

    try {
        // Use the peronh/pdf-compress library as primary method
        $pdf = new \Peronh\PdfCompress\PDFCompress();
        $pdf->AddFile($source_file);

        // Try different compression qualities until we get under max size
        $compression_qualities = ['ebook', 'screen', 'prepress'];
        
        foreach ($compression_qualities as $quality) {
            $temp_file = $destination_file . '.tmp';
            $pdf->CompressFile($temp_file, $quality);
            
            if (file_exists($temp_file)) {
                if (filesize($temp_file) <= $max_size) {
                    // Success - rename temp file to destination
                    if (file_exists($destination_file)) {
                        unlink($destination_file);
                    }
                    rename($temp_file, $destination_file);
                    return $destination_file;
                }
                // Clean up temp file if compression wasn't sufficient
                unlink($temp_file);
            }
        }

        // If peronh/pdf-compress couldn't compress enough, try Imagick
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $imagick = new Imagick();
            $imagick->setResolution(72, 72); // Lower resolution
            $imagick->readImage($source_file);
            
            // Try different compression levels
            $compression_qualities = [60, 40, 20];
            
            foreach ($compression_qualities as $quality) {
                $imagick->setImageCompressionQuality($quality);
                $imagick->setImageCompression(Imagick::COMPRESSION_JPEG);
                $imagick->setOption('pdf:use-trimbox', 'true');
                $imagick->setImageFormat('pdf');
                
                // Write to temporary file first
                $temp_file = $destination_file . '.tmp';
                $imagick->writeImage($temp_file);
                
                if (file_exists($temp_file) && filesize($temp_file) <= $max_size) {
                    if (file_exists($destination_file)) {
                        unlink($destination_file);
                    }
                    rename($temp_file, $destination_file);
                    $imagick->clear();
                    $imagick->destroy();
                    return $destination_file;
                }
                
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
            }
            
            $imagick->clear();
            $imagick->destroy();
        }

        throw new Exception("Unable to compress file to under 800KB. Please try a smaller file or manually compress it before uploading.");
    } catch (Exception $e) {
        error_log("PDF compression failed: " . $e->getMessage());
        throw new Exception("PDF compression failed: " . $e->getMessage());
    }
}

// Function to compress image files (for future use)
function compress_image($source_file, $destination_file, $quality = 60) {
    $max_size = 800 * 1024; // 800KB in bytes
    
    // If file is already under max size, just return the original file
    if (filesize($source_file) <= $max_size) {
        return $source_file;
    }
    
    $info = getimagesize($source_file);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source_file);
        imagejpeg($image, $destination_file, $quality);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source_file);
        imagesavealpha($image, true);
        imagepng($image, $destination_file, 9);
    }
    
    if (isset($image)) {
        imagedestroy($image);
    }
    
    // If still over max size, reduce quality further
    if (filesize($destination_file) > $max_size && $info['mime'] == 'image/jpeg') {
        $quality = $quality - 10;
        if ($quality >= 10) {
            return compress_image($source_file, $destination_file, $quality);
        }
    }
    
    // If still too large, throw exception
    if (filesize($destination_file) > $max_size) {
        throw new Exception("File too large even after compression. Please use a smaller file (under 800KB).");
    }
    
    return $destination_file;
}

$user_id = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Set active tab (default to basic info)
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'basic';

// Handle success and error messages from redirects
if (isset($_GET['success'])) {
    $success_message = "Your profile has been updated successfully!";
}
if (isset($_GET['error'])) {
    $error_message = "An error occurred. Please try again.";
}
if (isset($_GET['deleted'])) {
    $success_message = "Item deleted successfully.";
}

// Handle form submission for basic profile info
if ($_SERVER['REQUEST_METHOD'] == 'POST' && $active_tab == 'basic') {
    try {
        // First fetch student data including batch info
        $student_query = "SELECT s.*, b.batch_name 
                         FROM students s 
                         JOIN batch_years b ON s.batch_id = b.id 
                         WHERE s.id = ?";
        $stmt = mysqli_prepare($conn, $student_query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $student_data = mysqli_fetch_assoc($result);

        if (!$student_data) {
            throw new Exception("Student data not found!");
        }

        // Process certificate uploads if provided
        $internship_certificates_path = null;
        $course_certificates_path = null;
        $achievement_certificates_path = null;

        // Function to handle certificate upload
        function process_certificate_upload($file, $category, $student_data) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['application/pdf'];
                $file_info = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($file_info, $file['tmp_name']);
                finfo_close($file_info);
                
                if (!in_array($mime_type, $allowed_types)) {
                    throw new Exception("Invalid file type for " . $category . ". Only PDF files are allowed.");
                }
                
                // Check file size before compression
                $max_size = 800 * 1024; // 800KB in bytes
                if (filesize($file['tmp_name']) > $max_size) {
                    // File needs compression
                    $temp_compressed_file = tempnam(sys_get_temp_dir(), 'comp_');
                    try {
                        $compressed_file = compress_pdf($file['tmp_name'], $temp_compressed_file);
                        // Replace original file with compressed one for further processing
                        $file['tmp_name'] = $compressed_file;
                    } catch (Exception $e) {
                        if (file_exists($temp_compressed_file)) {
                            unlink($temp_compressed_file);
                        }
                        throw $e;
                    }
                }
                
                // Create upload directory structure
                $upload_dir = 'uploads/' . $student_data['batch_name'] . '/certificates/' . $category . '/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Create filename using register_number and category
                $filename = $student_data['register_number'] . '_' . $category . '_certificates.pdf';
                $target_file = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    return $target_file;
                } else {
                    throw new Exception("Failed to upload " . $category . " certificates.");
                }
            }
            return null;
        }

        // Process each certificate category
        if (isset($_FILES['internship_certificates']) && $_FILES['internship_certificates']['error'] !== UPLOAD_ERR_NO_FILE) {
            $internship_certificates_path = process_certificate_upload($_FILES['internship_certificates'], 'internship', $student_data);
        }
        
        if (isset($_FILES['course_certificates']) && $_FILES['course_certificates']['error'] !== UPLOAD_ERR_NO_FILE) {
            $course_certificates_path = process_certificate_upload($_FILES['course_certificates'], 'course', $student_data);
        }
        
        if (isset($_FILES['achievement_certificates']) && $_FILES['achievement_certificates']['error'] !== UPLOAD_ERR_NO_FILE) {
            $achievement_certificates_path = process_certificate_upload($_FILES['achievement_certificates'], 'achievement', $student_data);
        }

        // Process resume upload if provided
        $resume_path = null;
        if (isset($_FILES['resume']) && $_FILES['resume']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
            $file_info = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($file_info, $_FILES['resume']['tmp_name']);
            finfo_close($file_info);
            
            if (!in_array($mime_type, $allowed_types)) {
                throw new Exception("Invalid file type. Only PDF and Word documents are allowed.");
            }
            
            // Check file size before compression
            $max_size = 800 * 1024; // 800KB in bytes
            $original_tmp_name = $_FILES['resume']['tmp_name'];
            
            if (filesize($original_tmp_name) > $max_size) {
                // File needs compression
                if ($mime_type === 'application/pdf') {
                    $temp_compressed_file = tempnam(sys_get_temp_dir(), 'comp_');
                    try {
                        $compressed_file = compress_pdf($original_tmp_name, $temp_compressed_file);
                        // Replace original file with compressed one for further processing
                        $_FILES['resume']['tmp_name'] = $compressed_file;
                    } catch (Exception $e) {
                        if (file_exists($temp_compressed_file)) {
                            unlink($temp_compressed_file);
                        }
                        throw $e;
                    }
                } else {
                    // For Word documents, just warn the user
                    throw new Exception("Resume file size exceeds 800KB. Please compress your document before uploading.");
                }
            }
            
            // Get student batch name
            $batch_name = $student_data['batch_name'] ?? 'unknown_batch';
            
            // Get file extension
            $file_extension = pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION);
            
            // Create upload directory structure
            $upload_dir = 'uploads/' . $batch_name . '/resumes/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Create filename using register_number and sanitized student name
            $sanitized_name = preg_replace('/[^a-zA-Z0-9]/', '_', $student_data['name']);
            $filename = $student_data['register_number'] . '_' . $sanitized_name . '.' . $file_extension;
            $target_file = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['resume']['tmp_name'], $target_file)) {
                $resume_path = $target_file;
            } else {
                throw new Exception("Failed to upload resume.");
            }
            
            // Clean up temp files if needed
            if (isset($temp_compressed_file) && file_exists($temp_compressed_file)) {
                unlink($temp_compressed_file);
            }
        }
        
        // Sanitize inputs
        $linkedin_url = sanitize_input($_POST['linkedin_url'] ?? '');
        $github_url = sanitize_input($_POST['github_url'] ?? '');
        $portfolio_url = sanitize_input($_POST['portfolio_url'] ?? '');
        $headline = sanitize_input($_POST['headline'] ?? '');
        $about = sanitize_input($_POST['about'] ?? '');
        $location = sanitize_input($_POST['location'] ?? '');
        $willing_to_relocate = isset($_POST['willing_to_relocate']) ? 1 : 0;
        $looking_for = sanitize_input($_POST['looking_for'] ?? 'Full-time');
        $career_objective = isset($_POST['career_objective']) ? sanitize_input($_POST['career_objective']) : '';
        $public_profile = isset($_POST['public_profile']) ? 1 : 0;
        $certifications = isset($_POST['certifications']) ? sanitize_input($_POST['certifications']) : '';
        
        // Debug log
        error_log("Career Objective Value: " . print_r($career_objective, true));
        
        // Validate LinkedIn URL format
        if (!empty($linkedin_url)) {
            // Clean up the URL first
            $linkedin_url = trim($linkedin_url);
            
            // Add https:// if no protocol is specified
            if (!preg_match('~^(?:f|ht)tps?://~i', $linkedin_url)) {
                $linkedin_url = 'https://' . $linkedin_url;
            }
            
            // More flexible pattern that accepts various LinkedIn URL formats
            if (!preg_match('/^https?:\/\/(?:www\.)?linkedin\.com\/in\/[a-zA-Z0-9\-_.]+\/?$/', $linkedin_url)) {
                throw new Exception("Please enter a valid LinkedIn profile URL (e.g., https://linkedin.com/in/username)");
            }
        }
        
        // Begin transaction
        mysqli_begin_transaction($conn);
        
        // Check if a profile exists for this student
        $check_query = "SELECT id, internship_certificates_path, course_certificates_path, achievement_certificates_path, resume_path FROM student_recruitment_profiles WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $profile_exists = mysqli_fetch_assoc($result);
        
        // Only update certificate paths if new certificates are uploaded, otherwise keep existing paths
        if ($profile_exists) {
            // Preserve existing certificate paths if no new files uploaded
            if ($internship_certificates_path === null && !empty($profile_exists['internship_certificates_path'])) {
                $internship_certificates_path = $profile_exists['internship_certificates_path'];
            }
            
            if ($course_certificates_path === null && !empty($profile_exists['course_certificates_path'])) {
                $course_certificates_path = $profile_exists['course_certificates_path'];
            }
            
            if ($achievement_certificates_path === null && !empty($profile_exists['achievement_certificates_path'])) {
                $achievement_certificates_path = $profile_exists['achievement_certificates_path'];
            }
            
            // Preserve existing resume path if no new resume uploaded
            if ($resume_path === null && !empty($profile_exists['resume_path'])) {
                $resume_path = $profile_exists['resume_path'];
            }
            
            // Update existing profile
            $query = "UPDATE student_recruitment_profiles SET 
                     linkedin_url = ?, 
                     github_url = ?, 
                     portfolio_url = ?, 
                     headline = ?,
                     about = ?,
                     location = ?,
                     willing_to_relocate = ?,
                     looking_for = ?,
                     career_objective = ?, 
                     public_profile = ?,
                     certifications = ?,
                     internship_certificates_path = ?,
                     course_certificates_path = ?,
                     achievement_certificates_path = ?,
                     resume_path = ?
                     WHERE student_id = ?";
                     
            $params = [
                $linkedin_url, 
                $github_url, 
                $portfolio_url, 
                $headline,
                $about,
                $location,
                $willing_to_relocate,
                $looking_for,
                $career_objective, 
                $public_profile,
                $certifications,
                $internship_certificates_path,
                $course_certificates_path,
                $achievement_certificates_path,
                $resume_path,
                $user_id
            ];
            $types = "ssssssississsssi";
        } else {
            // Create new profile
            $query = "INSERT INTO student_recruitment_profiles (
                student_id,
                linkedin_url, 
                github_url, 
                portfolio_url, 
                headline,
                about,
                location,
                willing_to_relocate,
                looking_for,
                career_objective, 
                public_profile,
                certifications,
                internship_certificates_path,
                course_certificates_path,
                achievement_certificates_path,
                resume_path
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
            $params = [
                $user_id,
                $linkedin_url, 
                $github_url, 
                $portfolio_url, 
                $headline,
                $about,
                $location,
                $willing_to_relocate,
                $looking_for,
                $career_objective, 
                $public_profile,
                $certifications,
                $internship_certificates_path,
                $course_certificates_path,
                $achievement_certificates_path,
                $resume_path
            ];
            $types = "issssssississsss";
        }
        
        $stmt = mysqli_prepare($conn, $query);

        // Add debugging to verify parameter count matches type string length
        error_log("Params count: " . count($params) . ", Types length: " . strlen($types));

        mysqli_stmt_bind_param($stmt, $types, ...$params);
        
        if (!mysqli_stmt_execute($stmt)) {
            throw new Exception("Failed to update profile: " . mysqli_error($conn));
        }
        
        // Log the action
        // Temporarily commenting out the log_user_action call that's causing the "Unknown column 'timestamp'" error
        // log_user_action($user_id, "Updated recruitment profile", "student");
        
        mysqli_commit($conn);
        $success_message = "Your recruitment profile has been updated successfully!";
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// Add this to the form processing section at the beginning of the file
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_certificate':
            case 'edit_certificate':
                try {
                    $cert_id = isset($_POST['certificate_id']) ? intval($_POST['certificate_id']) : null;
                    $name = sanitize_input($_POST['cert_name']);
                    $organization = sanitize_input($_POST['cert_organization']);
                    $category = sanitize_input($_POST['cert_category']);
                    $issue_date = sanitize_input($_POST['cert_issue_date']);
                    $expiry_date = !empty($_POST['cert_expiry_date']) ? sanitize_input($_POST['cert_expiry_date']) : null;
                    $credential_id = !empty($_POST['cert_credential_id']) ? sanitize_input($_POST['cert_credential_id']) : null;
                    $credential_url = !empty($_POST['cert_credential_url']) ? sanitize_input($_POST['cert_credential_url']) : null;
                    $description = !empty($_POST['cert_description']) ? sanitize_input($_POST['cert_description']) : null;

                    if ($cert_id) {
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
                            $credential_id, $credential_url, $description, $cert_id, $user_id
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
                        $success_message = "Certificate " . ($cert_id ? "updated" : "added") . " successfully!";
                    } else {
                        throw new Exception("Failed to " . ($cert_id ? "update" : "add") . " certificate.");
                    }

                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;

            case 'delete_certificate':
                try {
                    $cert_id = intval($_POST['certificate_id']);
                    
                    // Delete certificate
                    $query = "DELETE FROM student_certificates WHERE id = ? AND student_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "ii", $cert_id, $user_id);
                    
                    if (mysqli_stmt_execute($stmt)) {
                        $success_message = "Certificate deleted successfully!";
                    } else {
                        throw new Exception("Failed to delete certificate.");
                    }

                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                }
                break;
        }
    }
}

// Fetch current profile data
$query = "SELECT * FROM student_recruitment_profiles WHERE student_id = ?";
$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$profile = mysqli_fetch_assoc($result);

// Fetch student basic info
$student_query = "SELECT name, roll_number, register_number, email FROM students WHERE id = ?";
$stmt = mysqli_prepare($conn, $student_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$student = mysqli_fetch_assoc($result);

// Fetch education entries
$education_query = "SELECT id, institution_name, degree, field_of_study, start_year, end_year, is_current, grade 
                   FROM student_education 
                   WHERE student_id = ? 
                   ORDER BY is_current DESC, end_year DESC, start_year DESC";
$stmt = mysqli_prepare($conn, $education_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$education_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $education_entries[] = $row;
}

// Fetch experience entries
$experience_query = "SELECT id, title, company_name, location, start_date, end_date, is_current, employment_type 
                    FROM student_experience 
                    WHERE student_id = ? 
                    ORDER BY is_current DESC, end_date DESC, start_date DESC";
$stmt = mysqli_prepare($conn, $experience_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$experience_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $experience_entries[] = $row;
}

// Fetch project entries
$project_query = "SELECT id, title, start_date, end_date, is_current, project_url, github_url 
                 FROM student_projects 
                 WHERE student_id = ? 
                 ORDER BY is_current DESC, end_date DESC, start_date DESC";
$stmt = mysqli_prepare($conn, $project_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$project_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $project_entries[] = $row;
}

// Fetch skills
$skills_query = "SELECT id, skill_name, proficiency, is_top_skill, endorsement_count 
                FROM student_skills 
                WHERE student_id = ? 
                ORDER BY is_top_skill DESC, endorsement_count DESC";
$stmt = mysqli_prepare($conn, $skills_query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$skills = [];
while ($row = mysqli_fetch_assoc($result)) {
    $skills[] = $row;
}

// Page title
$page_title = "Recruitment Profile";
include 'header.php';
?>

    <style>
    :root {
        --primary-color: #4e73df;  /* Blue theme for Recruitment */
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
        --header-height: 90px;
        --transition-speed: 0.3s;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
        font-family: 'Poppins', sans-serif;
    }

    body {
        background: var(--bg-color);
        min-height: 100vh;
        padding-top: var(--header-height);
        color: var(--text-color);
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 2rem;
    }

    /* Neumorphic Card */
    .card.neu-card {
        background: var(--card-bg);
        border-radius: 20px;
        box-shadow: var(--shadow);
        border: none;
        overflow: hidden;
        margin-bottom: 2rem;
        transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
    }

    .card.neu-card:hover {
        box-shadow: 12px 12px 20px rgb(163,177,198,0.7), 
                   -12px -12px 20px rgba(255,255,255, 0.7);
    }

    .card.neu-card .card-header {
        background: var(--card-bg);
        border-bottom: 1px solid rgba(0,0,0,0.1);
        padding: 1.8rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .card-header h4 {
        color: var(--primary-color);
        font-size: 1.6rem;
        margin: 0;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .card-header h4 i {
        font-size: 1.4rem;
    }

    .card-body {
        padding: 2rem;
    }

    /* Neumorphic Form Controls */
    .form-control, .form-select {
        width: 100%;
        padding: 1rem 1.2rem;
        border: none;
        border-radius: 12px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
        color: var(--text-color);
        transition: all var(--transition-speed) ease;
        margin-bottom: 1.2rem;
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

    /* Improve Form Layout */
    .row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -15px;
    }

    .col-md-6 {
        flex: 0 0 50%;
        max-width: 50%;
        padding: 0 15px;
    }

    .col-md-6, .col-lg-4 {
        position: relative;
        width: 100%;
    }

    .col-lg-4 {
        flex: 0 0 33.333333%;
        max-width: 33.333333%;
        padding: 0 15px;
    }

    /* Neumorphic Tabs */
    .nav-tabs.neu-tabs {
        border: none;
        margin-bottom: 2.5rem;
        display: flex;
        gap: 1rem;
        padding: 0.5rem;
        background: var(--bg-color);
        border-radius: 15px;
        box-shadow: var(--inner-shadow);
    }

    .nav-tabs.neu-tabs .nav-link {
        background: var(--bg-color);
        border: none;
        border-radius: 12px;
        padding: 1rem 1.5rem;
        color: var(--text-color);
        box-shadow: var(--soft-shadow);
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-weight: 500;
    }

    .nav-tabs.neu-tabs .nav-link.active {
        background: var(--primary-color);
        color: white;
        box-shadow: inset 4px 4px 6px rgba(0, 0, 0, 0.1),
                    inset -4px -4px 6px rgba(255, 255, 255, 0.15);
        transform: translateY(0);
    }

    .nav-tabs.neu-tabs .nav-link:hover:not(.active) {
        transform: translateY(-3px);
        background: rgba(78, 115, 223, 0.1);
        color: var(--primary-color);
    }

    .nav-tabs.neu-tabs .nav-link i {
        font-size: 1.2rem;
    }

    /* Neumorphic Cards for Education, Experience, Projects */
    .experience-card {
        background: var(--card-bg);
        border-radius: 15px;
        box-shadow: var(--soft-shadow);
        padding: 1.8rem;
        margin-bottom: 1.5rem;
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        position: relative;
        overflow: hidden;
    }

    .experience-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 5px;
        height: 100%;
        background: var(--primary-color);
        opacity: 0;
        transition: opacity var(--transition-speed) ease;
    }

    .experience-card:hover {
        transform: translateY(-5px) scale(1.01);
        box-shadow: var(--shadow);
    }

    .experience-card:hover::before {
        opacity: 1;
    }

    /* Skill Cards */
    .skill-card {
        background: var(--card-bg);
        border-radius: 15px;
        box-shadow: var(--soft-shadow);
        padding: 1.5rem;
        margin-bottom: 1rem;
        transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
        height: 100%;
        position: relative;
        overflow: hidden;
    }

    .skill-card:hover {
        transform: translateY(-3px) scale(1.02);
        box-shadow: var(--shadow);
    }

    .skill-card h5 {
        font-size: 1.1rem;
        font-weight: 600;
        color: var(--text-color);
        margin-bottom: 0.5rem;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    /* Buttons */
    .btn.neu-btn {
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
    }

    .btn.neu-btn::after {
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

    .btn.neu-btn:hover {
        transform: translateY(-3px);
        box-shadow: var(--shadow);
    }

    .btn.neu-btn:hover::after {
        width: 300px;
        height: 300px;
    }

    .btn.neu-btn:active {
        transform: translateY(1px);
        box-shadow: var(--soft-shadow);
    }

    .btn.neu-btn-primary {
        background: var(--primary-color);
        color: white;
    }

    .btn.neu-btn-primary:hover {
        background: var(--primary-hover);
    }

    .btn.neu-btn.btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.9rem;
    }

    /* Alerts */
    .alert.neu-alert {
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

    .alert.neu-alert::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 5px;
    }

    .alert.neu-alert i {
        font-size: 1.4rem;
    }

    .alert.neu-alert-success {
        background: #e6fff0;
        color: #1cc88a;
    }

    .alert.neu-alert-success::before {
        background: #1cc88a;
    }

    .alert.neu-alert-danger {
        background: #fff0f0;
        color: #e74a3b;
    }

    .alert.neu-alert-danger::before {
        background: #e74a3b;
    }

    .alert.neu-alert-info {
        background: #e6f7ff;
        color: var(--primary-color);
    }

    .alert.neu-alert-info::before {
        background: var(--primary-color);
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
        margin-bottom: 0.8rem;
    }

    .form-check-input {
        position: absolute;
        left: 0;
        margin-top: 0.25rem;
        margin-left: 0;
        cursor: pointer;
    }

    .form-check-label {
        margin-bottom: 0;
        cursor: pointer;
        user-select: none;
    }

    /* Input Groups */
    .input-group {
        position: relative;
        display: flex;
        align-items: stretch;
        width: 100%;
    }

    .input-group .form-control {
        position: relative;
        flex: 1 1 auto;
        width: 1%;
        margin-bottom: 0;
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }

    .input-group-text {
        display: flex;
        align-items: center;
        padding: 0.5rem 1rem;
        font-size: 1rem;
        font-weight: 400;
        line-height: 1.5;
        color: white;
        text-align: center;
        white-space: nowrap;
        background-color: var(--primary-color);
        border-radius: 12px 0 0 12px;
        width: 48px;
        justify-content: center;
    }

    /* File Upload */
    .file-upload {
        position: relative;
        display: inline-block;
        width: 100%;
    }

    .file-upload .form-control {
        padding-left: 1rem;
        padding-right: 1rem;
        cursor: pointer;
    }

    .file-upload .mt-2 {
        margin-top: 0.8rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        background: rgba(78, 115, 223, 0.1);
        border-radius: 8px;
    }

    /* Badges */
    .badge {
        display: inline-block;
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-weight: 500;
        font-size: 0.75rem;
        letter-spacing: 0.3px;
        background: var(--bg-color);
        box-shadow: var(--inner-shadow);
    }

    .badge-primary {
        background: var(--primary-color);
        color: white;
    }

    /* Section Titles */
    .section-title {
        color: var(--text-color);
        font-size: 1.3rem;
        margin-bottom: 1.8rem;
        padding-bottom: 0.8rem;
        border-bottom: 2px solid rgba(0,0,0,0.1);
        position: relative;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }

    .section-title::after {
        content: '';
        position: absolute;
        bottom: -2px;
        left: 0;
        width: 80px;
        height: 2px;
        background: var(--primary-color);
    }

    .section-title i {
        color: var(--primary-color);
        font-size: 1.2rem;
    }

    /* Utility Classes */
    .mb-0 { margin-bottom: 0 !important; }
    .mb-2 { margin-bottom: 0.5rem !important; }
    .mb-3 { margin-bottom: 1rem !important; }
    .mb-4 { margin-bottom: 1.5rem !important; }
    .mt-2 { margin-top: 0.5rem !important; }
    .mt-3 { margin-top: 1rem !important; }
    .mt-4 { margin-top: 1.5rem !important; }
    .me-2 { margin-right: 0.5rem !important; }
    .py-4 { padding-top: 1.5rem !important; padding-bottom: 1.5rem !important; }
    .px-5 { padding-left: 3rem !important; padding-right: 3rem !important; }
    
    .d-flex { display: flex !important; }
    .justify-content-between { justify-content: space-between !important; }
    .justify-content-center { justify-content: center !important; }
    .align-items-center { align-items: center !important; }
    .align-items-start { align-items: flex-start !important; }
    .text-center { text-align: center !important; }
    .text-muted { color: #6c757d !important; }

    /* Responsive Design */
    @media (max-width: 991.98px) {
        .col-lg-4 {
            flex: 0 0 50%;
            max-width: 50%;
        }
    }

    @media (max-width: 767.98px) {
        .container {
            padding: 1rem;
        }
        
        .nav-tabs.neu-tabs {
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .nav-tabs.neu-tabs .nav-link {
            flex: 1 0 auto;
            text-align: center;
            justify-content: center;
            padding: 0.8rem 1.2rem;
        }

        .card.neu-card .card-header {
            flex-direction: column;
            gap: 1rem;
            text-align: center;
        }

        .card-body {
            padding: 1.5rem 1rem;
        }

        .col-md-6 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        .col-lg-4 {
            flex: 0 0 100%;
            max-width: 100%;
        }

        .d-flex {
            flex-direction: column;
            gap: 1rem;
        }

        .d-flex.justify-content-between .btn {
            align-self: flex-start;
        }

        .experience-card .d-flex {
            text-align: left;
        }

        .experience-card .d-flex .btn {
            align-self: flex-start;
        }
    }

    @media (max-width: 575.98px) {
        .container {
            padding: 0.8rem;
        }

        .card.neu-card {
            border-radius: 15px;
        }

        .nav-tabs.neu-tabs {
            padding: 0.3rem;
        }

        .nav-tabs.neu-tabs .nav-link {
            padding: 0.7rem 1rem;
            font-size: 0.9rem;
        }

        .btn.neu-btn {
            padding: 0.8rem 1.2rem;
        }
    }

    .certificate-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 1.5rem;
        margin-top: 1rem;
    }

    .certificate-card {
        background: var(--bg-color);
        border-radius: 15px;
        box-shadow: var(--shadow);
        padding: 1.5rem;
        transition: transform 0.3s ease;
    }

    .certificate-card:hover {
        transform: translateY(-5px);
    }

    .certificate-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
    }

    .certificate-header h6 {
        margin: 0;
        font-weight: 600;
        color: var(--primary-color);
    }

    .certificate-body {
        margin-bottom: 1rem;
    }

    .certificate-body p {
        margin-bottom: 0.5rem;
        font-size: 0.9rem;
    }

    .certificate-body i {
        width: 20px;
        color: var(--primary-color);
    }

    .certificate-footer {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
    }

    .badge {
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.8rem;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
    }

    .badge-success {
        background: #1cc88a;
        color: white;
    }
    </style>
</head>
<body>
    <div class="container py-4">
        <?php if ($success_message): ?>
            <div class="alert neu-alert neu-alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert neu-alert neu-alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
            </div>
        <?php endif; ?>

        <div class="card neu-card">
            <div class="card-header">
                <h4><i class="fas fa-user-tie"></i> Recruitment Profile</h4>
                <a href="profile.php" class="btn neu-btn">
                    <i class="fas fa-arrow-left"></i> Back to Profile
                </a>
            </div>
            
            <div class="card-body">
                <div class="alert neu-alert neu-alert-info mb-4">
                    <i class="fas fa-info-circle"></i> Complete your recruitment profile to showcase your skills and experiences to potential employers. Your profile will be visible to recruiters visiting our campus.
                </div>
                
                <!-- Profile Navigation Tabs -->
                <ul class="nav nav-tabs neu-tabs mb-4">
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'basic') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=basic">
                            <i class="fas fa-user-circle"></i> Basic Info
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'education') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=education">
                            <i class="fas fa-graduation-cap"></i> Education
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'experience') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=experience">
                            <i class="fas fa-briefcase"></i> Experience
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'projects') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=projects">
                            <i class="fas fa-project-diagram"></i> Projects
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'skills') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=skills">
                            <i class="fas fa-tools"></i> Skills
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_tab == 'certificates') ? 'active' : ''; ?>" href="recruitment_profile.php?tab=certificates">
                            <i class="fas fa-certificate"></i> Certificates
                        </a>
                    </li>
                </ul>
                
                <!-- Basic Info Tab -->
                <?php if ($active_tab == 'basic'): ?>
                    <form id="recruitmentForm" method="post" action="recruitment_profile.php?tab=basic" enctype="multipart/form-data" class="neu-form">
                        <div class="card neu-card mb-4">
                            <div class="card-body">
                                <h5 class="section-title">
                                    <i class="fas fa-user-edit"></i> Professional Headline & Summary
                                </h5>
                                <div class="form-group">
                                    <label for="headline" class="form-label">Professional Headline</label>
                                    <input type="text" class="form-control" id="headline" name="headline" 
                                        placeholder="e.g., Computer Science Student | Web Developer | AI Enthusiast" 
                                        value="<?php echo isset($profile['headline']) ? html_entity_decode($profile['headline']) : ''; ?>">
                                    <div class="form-text">A brief one-line description of who you are professionally</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="about" class="form-label">About</label>
                                    <textarea class="form-control" id="about" name="about" rows="4" 
                                        placeholder="Summarize your background, skills, and career aspirations"><?php echo isset($profile['about']) ? html_entity_decode($profile['about']) : ''; ?></textarea>
                                </div>
                                
                                <div class="form-group">
                                    <label for="career_objective" class="form-label">Career Objective</label>
                                    <textarea class="form-control" id="career_objective" name="career_objective" rows="2" 
                                        placeholder="Briefly describe your career goals and objectives"><?php echo isset($profile['career_objective']) ? html_entity_decode($profile['career_objective']) : ''; ?></textarea>
                                    <div class="form-text">Write a brief statement about your career goals and aspirations.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card neu-card mb-4">
                            <div class="card-body">
                                <h5 class="section-title">
                                    <i class="fas fa-certificate"></i> Certifications
                                </h5>
                                <div class="form-group">
                                    <label for="certifications" class="form-label">Professional Certifications</label>
                                    <textarea class="form-control" id="certifications" name="certifications" rows="4" 
                                        placeholder="List your professional certifications (e.g., AWS Certified Solutions Architect, Microsoft Azure Developer, Google Cloud Professional, etc.). Separate each certification with a comma."><?php echo isset($profile['certifications']) ? html_entity_decode($profile['certifications']) : ''; ?></textarea>
                                    <div class="form-text">Add your professional certifications, separating each with a comma. Include certification name, issuing organization, and year if applicable.</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card neu-card mb-4">
                            <div class="card-body">
                                <h5 class="section-title">
                                    <i class="fas fa-map-marker-alt"></i> Location & Preferences
                                </h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="location" class="form-label">Current Location</label>
                                            <input type="text" class="form-control" id="location" name="location" 
                                                placeholder="e.g., Chennai, Tamil Nadu, India" 
                                                value="<?php echo isset($profile['location']) ? html_entity_decode($profile['location']) : ''; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="looking_for" class="form-label">Looking For</label>
                                            <select class="form-select" id="looking_for" name="looking_for">
                                                <option value="Internship" <?php echo (isset($profile['looking_for']) && $profile['looking_for'] == 'Internship') ? 'selected' : ''; ?>>Internship</option>
                                                <option value="Full-time" <?php echo (isset($profile['looking_for']) && $profile['looking_for'] == 'Full-time') ? 'selected' : ''; ?>>Full-time</option>
                                                <option value="Part-time" <?php echo (isset($profile['looking_for']) && $profile['looking_for'] == 'Part-time') ? 'selected' : ''; ?>>Part-time</option>
                                                <option value="Contract" <?php echo (isset($profile['looking_for']) && $profile['looking_for'] == 'Contract') ? 'selected' : ''; ?>>Contract</option>
                                                <option value="Not actively looking" <?php echo (isset($profile['looking_for']) && $profile['looking_for'] == 'Not actively looking') ? 'selected' : ''; ?>>Not actively looking</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-check mt-3">
                                    <input type="checkbox" class="form-check-input" id="willing_to_relocate" name="willing_to_relocate" 
                                        <?php echo (!empty($profile['willing_to_relocate']) && $profile['willing_to_relocate']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="willing_to_relocate">I am willing to relocate</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card neu-card mb-4">
                            <div class="card-body">
                                <h5 class="section-title">
                                    <i class="fas fa-globe"></i> Online Presence & Documents
                                </h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="linkedin_url" class="form-label">LinkedIn Profile URL</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fab fa-linkedin"></i></span>
                                                <input type="url" class="form-control" id="linkedin_url" name="linkedin_url" 
                                                    placeholder="https://linkedin.com/in/username" 
                                                    value="<?php echo isset($profile['linkedin_url']) ? html_entity_decode($profile['linkedin_url']) : ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="github_url" class="form-label">GitHub Profile URL</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fab fa-github"></i></span>
                                                <input type="url" class="form-control" id="github_url" name="github_url" 
                                                    placeholder="https://github.com/username" 
                                                    value="<?php echo isset($profile['github_url']) ? html_entity_decode($profile['github_url']) : ''; ?>">
                                            </div>
                                        </div>
                                        
                                        <div class="form-group">
                                            <label for="portfolio_url" class="form-label">Portfolio Website</label>
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fas fa-globe"></i></span>
                                                <input type="url" class="form-control" id="portfolio_url" name="portfolio_url" 
                                                    placeholder="https://myportfolio.com" 
                                                    value="<?php echo isset($profile['portfolio_url']) ? html_entity_decode($profile['portfolio_url']) : ''; ?>">
                                            </div>
                                        </div>
                                    </div>
                                        
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label for="resume" class="form-label">Resume/CV</label>
                                            <div class="file-upload">
                                                <input type="file" class="form-control" id="resume" name="resume">
                                                <?php if (!empty($profile['resume_path'])): ?>
                                                    <div class="mt-2">
                                                        <i class="fas fa-file-pdf"></i> Current resume: 
                                                        <a href="<?php echo htmlspecialchars($profile['resume_path']); ?>?v=<?php echo time(); ?>" target="_blank" class="btn neu-btn btn-sm">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-text">Upload your resume/CV (PDF or Word format)</div>
                                            </div>
                                        </div>
                                        
                                        <div class="form-check mt-4">
                                            <input type="checkbox" class="form-check-input" id="public_profile" name="public_profile" 
                                                <?php echo (!empty($profile['public_profile']) && $profile['public_profile']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="public_profile">Make my profile visible to recruiters</label>
                                            <div class="form-text">By checking this box, your profile information will be accessible to campus recruiters.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card neu-card mb-4">
                            <div class="card-body">
                                <h5 class="section-title">
                                    <i class="fas fa-certificate"></i> Certificates Upload
                                </h5>
                                <div class="alert neu-alert neu-alert-info mb-4">
                                    <i class="fas fa-info-circle"></i> Please combine all certificates of each category into a single PDF file before uploading.
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="internship_certificates" class="form-label">Internship Certificates</label>
                                            <div class="file-upload">
                                                <input type="file" class="form-control" id="internship_certificates" name="internship_certificates" accept=".pdf">
                                                <?php if (!empty($profile['internship_certificates_path'])): ?>
                                                    <div class="mt-2">
                                                        <i class="fas fa-file-pdf"></i> Current file: 
                                                        <a href="<?php echo htmlspecialchars($profile['internship_certificates_path']); ?>?v=<?php echo time(); ?>" target="_blank" class="btn neu-btn btn-sm">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-text">Upload a single PDF containing all your internship certificates.</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="course_certificates" class="form-label">Course Certificates</label>
                                            <div class="file-upload">
                                                <input type="file" class="form-control" id="course_certificates" name="course_certificates" accept=".pdf">
                                                <?php if (!empty($profile['course_certificates_path'])): ?>
                                                    <div class="mt-2">
                                                        <i class="fas fa-file-pdf"></i> Current file: 
                                                        <a href="<?php echo htmlspecialchars($profile['course_certificates_path']); ?>?v=<?php echo time(); ?>" target="_blank" class="btn neu-btn btn-sm">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-text">Upload a single PDF containing all your course completion certificates.</div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            <label for="achievement_certificates" class="form-label">Achievement Certificates</label>
                                            <div class="file-upload">
                                                <input type="file" class="form-control" id="achievement_certificates" name="achievement_certificates" accept=".pdf">
                                                <?php if (!empty($profile['achievement_certificates_path'])): ?>
                                                    <div class="mt-2">
                                                        <i class="fas fa-file-pdf"></i> Current file: 
                                                        <a href="<?php echo htmlspecialchars($profile['achievement_certificates_path']); ?>?v=<?php echo time(); ?>" target="_blank" class="btn neu-btn btn-sm">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="form-text">Upload a single PDF containing all your hackathon, event, and other achievement certificates.</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center">
                            <button type="submit" id="saveProfileBtn" class="btn neu-btn neu-btn-primary px-5">
                                <i class="fas fa-save"></i> Save Profile
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <!-- Certificates Tab -->
                <?php if ($active_tab == 'certificates'): ?>
                    <div class="card neu-card mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <h5 class="section-title mb-0">
                                    <i class="fas fa-award"></i> Certificates
                                </h5>
                                <a href="edit_certificates.php" class="btn neu-btn neu-btn-primary">
                                    <i class="fas fa-plus"></i> Add Certificate
                                </a>
                            </div>

                            <!-- Tabs for certificate categories -->
                            <ul class="nav nav-tabs neu-tabs mb-4" id="certificateTabs">
                                <li class="nav-item">
                                    <button class="nav-link active" id="internship-tab" onclick="showCertCategory('internship')">
                                        <i class="fas fa-briefcase"></i> Internships
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="course-tab" onclick="showCertCategory('course')">
                                        <i class="fas fa-graduation-cap"></i> Courses
                                    </button>
                                </li>
                                <li class="nav-item">
                                    <button class="nav-link" id="achievement-tab" onclick="showCertCategory('achievement')">
                                        <i class="fas fa-trophy"></i> Achievements
                                    </button>
                                </li>
                            </ul>

                            <!-- Certificate category sections -->
                            <div class="cert-category-content">
                                <?php
                                // Define categories
                                $categories = ['internship', 'course', 'achievement'];
                                foreach ($categories as $index => $category):
                                    // Fetch certificates for this category
                                    $cert_query = "SELECT * FROM student_certificates WHERE student_id = ? AND category = ? ORDER BY issue_date DESC";
                                    $cert_stmt = mysqli_prepare($conn, $cert_query);
                                    mysqli_stmt_bind_param($cert_stmt, "is", $user_id, $category);
                                    mysqli_stmt_execute($cert_stmt);
                                    $certificates = mysqli_stmt_get_result($cert_stmt);
                                    
                                    // Check if category has certificates
                                    $has_certs = (mysqli_num_rows($certificates) > 0);
                                ?>
                                <div id="<?php echo $category; ?>-certificates" class="cert-category-section" style="display: <?php echo ($category === 'internship') ? 'block' : 'none'; ?>">
                                    <?php if (!$has_certs): ?>
                                        <div class="alert neu-alert neu-alert-info">
                                            <i class="fas fa-info-circle"></i> No <?php echo ucfirst($category); ?> certificates added yet.
                                            <a href="edit_certificates.php" class="alert-link">Add your first certificate</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="certificate-grid">
                                            <?php while ($cert = mysqli_fetch_assoc($certificates)): ?>
                                                <div class="certificate-card">
                                                    <div class="certificate-header">
                                                        <h6><?php echo htmlspecialchars($cert['name']); ?></h6>
                                                        <?php if (isset($cert['is_verified']) && $cert['is_verified']): ?>
                                                            <span class="badge badge-success">
                                                                <i class="fas fa-check-circle"></i> Verified
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="certificate-body">
                                                        <p class="organization">
                                                            <i class="fas fa-building"></i> <?php echo htmlspecialchars($cert['issuing_organization']); ?>
                                                        </p>
                                                        <p class="issue-date">
                                                            <i class="fas fa-calendar"></i> Issued: <?php echo date('M Y', strtotime($cert['issue_date'])); ?>
                                                            <?php if (!empty($cert['expiry_date'])): ?>
                                                                <br>
                                                                <i class="fas fa-hourglass-end"></i> Expires: <?php echo date('M Y', strtotime($cert['expiry_date'])); ?>
                                                            <?php endif; ?>
                                                        </p>
                                                        <?php if (!empty($cert['credential_id'])): ?>
                                                            <p class="credential-id">
                                                                <i class="fas fa-fingerprint"></i> Credential ID: <?php echo htmlspecialchars($cert['credential_id']); ?>
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="certificate-footer">
                                                        <?php if (!empty($cert['credential_url'])): ?>
                                                            <a href="<?php echo htmlspecialchars($cert['credential_url']); ?>" target="_blank" class="btn neu-btn btn-sm">
                                                                <i class="fas fa-external-link-alt"></i> Verify
                                                            </a>
                                                        <?php endif; ?>
                                                        <a href="edit_certificates.php?id=<?php echo $cert['id']; ?>" class="btn neu-btn btn-sm">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                    </div>
                                                </div>
                                            <?php endwhile; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Education Tab -->
                <?php if ($active_tab == 'education'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">
                            <i class="fas fa-graduation-cap"></i> Education
                        </h5>
                        <a href="edit_education.php" class="btn neu-btn neu-btn-primary">
                            <i class="fas fa-plus"></i> Add Education
                        </a>
                    </div>
                    
                    <?php if (empty($education_entries)): ?>
                        <div class="alert neu-alert neu-alert-info">
                            <i class="fas fa-info-circle"></i> You haven't added any education details yet. 
                            Click the "Add Education" button to start building your profile.
                        </div>
                    <?php else: ?>
                        <?php foreach ($education_entries as $entry): ?>
                            <div class="experience-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-2"><?php echo htmlspecialchars($entry['institution_name']); ?></h5>
                                        <h6 class="text-muted">
                                            <?php echo htmlspecialchars($entry['degree']); ?>
                                            <?php if (!empty($entry['field_of_study'])): ?>
                                                - <?php echo htmlspecialchars($entry['field_of_study']); ?>
                                            <?php endif; ?>
                                        </h6>
                                        <p class="text-muted mb-0">
                                            <small>
                                                <?php echo htmlspecialchars($entry['start_year']); ?> - 
                                                <?php echo $entry['is_current'] ? 'Present' : htmlspecialchars($entry['end_year']); ?>
                                                <?php if (!empty($entry['grade'])): ?>
                                                    • Grade: <?php echo htmlspecialchars($entry['grade']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </p>
                                    </div>
                                    <div>
                                        <a href="edit_education.php?id=<?php echo $entry['id']; ?>" class="btn neu-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Experience Tab -->
                <?php if ($active_tab == 'experience'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">
                            <i class="fas fa-briefcase"></i> Work Experience
                        </h5>
                        <a href="edit_experience.php" class="btn neu-btn neu-btn-primary">
                            <i class="fas fa-plus"></i> Add Experience
                        </a>
                    </div>
                    
                    <?php if (empty($experience_entries)): ?>
                        <div class="alert neu-alert neu-alert-info">
                            <i class="fas fa-info-circle"></i> You haven't added any work experience yet. 
                            Click the "Add Experience" button to start building your profile.
                        </div>
                    <?php else: ?>
                        <?php foreach ($experience_entries as $entry): ?>
                            <div class="experience-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-2"><?php echo htmlspecialchars($entry['title']); ?></h5>
                                        <h6 class="text-muted">
                                            <?php echo htmlspecialchars($entry['company_name']); ?>
                                            <?php if (!empty($entry['location'])): ?>
                                                - <?php echo htmlspecialchars($entry['location']); ?>
                                            <?php endif; ?>
                                        </h6>
                                        <p class="text-muted mb-0">
                                            <small>
                                                <?php 
                                                $start_date = new DateTime($entry['start_date']);
                                                echo $start_date->format('M Y'); 
                                                ?> - 
                                                <?php 
                                                if ($entry['is_current']) {
                                                    echo 'Present';
                                                } else {
                                                    $end_date = new DateTime($entry['end_date']);
                                                    echo $end_date->format('M Y');
                                                }
                                                ?>
                                                <?php if (!empty($entry['employment_type'])): ?>
                                                    • <?php echo htmlspecialchars($entry['employment_type']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </p>
                                    </div>
                                    <div>
                                        <a href="edit_experience.php?id=<?php echo $entry['id']; ?>" class="btn neu-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Projects Tab -->
                <?php if ($active_tab == 'projects'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">
                            <i class="fas fa-project-diagram"></i> Projects
                        </h5>
                        <a href="edit_projects.php" class="btn neu-btn neu-btn-primary">
                            <i class="fas fa-plus"></i> Add Project
                        </a>
                    </div>
                    
                    <?php if (empty($project_entries)): ?>
                        <div class="alert neu-alert neu-alert-info">
                            <i class="fas fa-info-circle"></i> You haven't added any projects yet. 
                            Click the "Add Project" button to showcase your technical skills and achievements.
                        </div>
                    <?php else: ?>
                        <?php foreach ($project_entries as $entry): ?>
                            <div class="experience-card">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h5 class="mb-2"><?php echo htmlspecialchars($entry['title']); ?></h5>
                                        <?php if (!empty($entry['start_date'])): ?>
                                        <p class="text-muted">
                                            <small>
                                                <?php 
                                                $start_date = new DateTime($entry['start_date']);
                                                echo $start_date->format('M Y'); 
                                                ?> - 
                                                <?php 
                                                if ($entry['is_current']) {
                                                    echo 'Present';
                                                } elseif (!empty($entry['end_date'])) {
                                                    $end_date = new DateTime($entry['end_date']);
                                                    echo $end_date->format('M Y');
                                                }
                                                ?>
                                            </small>
                                        </p>
                                        <?php endif; ?>
                                        <div class="mt-3">
                                            <?php if (!empty($entry['project_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($entry['project_url']); ?>" target="_blank" class="btn neu-btn me-2">
                                                    <i class="fas fa-external-link-alt"></i> View Project
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['github_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($entry['github_url']); ?>" target="_blank" class="btn neu-btn">
                                                    <i class="fab fa-github"></i> View Code
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="edit_projects.php?id=<?php echo $entry['id']; ?>" class="btn neu-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Skills Tab -->
                <?php if ($active_tab == 'skills'): ?>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="section-title mb-0">
                            <i class="fas fa-tools"></i> Skills
                        </h5>
                        <a href="edit_skills.php" class="btn neu-btn neu-btn-primary">
                            <i class="fas fa-plus"></i> Add Skill
                        </a>
                    </div>
                    
                    <?php if (empty($skills)): ?>
                        <div class="alert neu-alert neu-alert-info">
                            <i class="fas fa-info-circle"></i> You haven't added any skills yet. 
                            Click the "Add Skill" button to highlight your technical and soft skills.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($skills as $skill): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="skill-card">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h5 class="mb-2">
                                                    <?php echo htmlspecialchars($skill['skill_name']); ?>
                                                    <?php if ($skill['is_top_skill']): ?>
                                                        <span class="badge badge-primary">Top Skill</span>
                                                    <?php endif; ?>
                                                </h5>
                                                <?php if (!empty($skill['proficiency'])): ?>
                                                    <div class="mb-2">
                                                        <span class="badge">
                                                            <?php echo htmlspecialchars($skill['proficiency']); ?>
                                                        </span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($skill['endorsement_count'] > 0): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-thumbs-up"></i> 
                                                        <?php echo $skill['endorsement_count']; ?> endorsement<?php echo $skill['endorsement_count'] > 1 ? 's' : ''; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <a href="edit_skills.php?id=<?php echo $skill['id']; ?>" class="btn neu-btn btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Simple direct approach for form submission
        const saveButton = document.getElementById('saveProfileBtn');
        if (saveButton) {
            saveButton.addEventListener('click', function(e) {
                e.preventDefault(); // Prevent default button behavior
                
                // Get the form
                const form = document.getElementById('recruitmentForm');
                if (form) {
                    // Show loading state
                    saveButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                    saveButton.disabled = true;
                    
                    // Submit the form after a brief delay
                    setTimeout(function() {
                        form.submit();
                    }, 100);
                }
            });
        }

        // Initialize tabs if they exist
        const certTabs = document.querySelectorAll('#certificateTabs .nav-link');
        if (certTabs.length > 0) {
            certTabs.forEach(tab => {
                tab.addEventListener('click', function(e) {
                    e.preventDefault();
                });
            });
        }
        
        // Add cache-busting to all file links
        document.querySelectorAll('a[href$=".pdf"], a[href$=".doc"], a[href$=".docx"]').forEach(link => {
            const currentHref = link.getAttribute('href');
            if (currentHref && !currentHref.includes('?v=')) {
                link.setAttribute('href', currentHref + '?v=' + new Date().getTime());
            }
        });
    });

    // Function to switch between certificate categories
    function showCertCategory(category) {
        // Hide all category sections
        const allSections = document.querySelectorAll('.cert-category-section');
        allSections.forEach(section => {
            section.style.display = 'none';
        });
        
        // Show the selected category
        const selectedSection = document.getElementById(category + '-certificates');
        if (selectedSection) {
            selectedSection.style.display = 'block';
        }
        
        // Update active tab
        const allTabs = document.querySelectorAll('#certificateTabs .nav-link');
        allTabs.forEach(tab => {
            tab.classList.remove('active');
        });
        
        const activeTab = document.getElementById(category + '-tab');
        if (activeTab) {
            activeTab.classList.add('active');
        }
    }
    </script>
</body>
</html> 