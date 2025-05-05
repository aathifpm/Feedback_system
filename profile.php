<?php
session_start();
require_once 'db_connection.php';
require_once 'functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$success_message = '';
$error_message = '';

// Fetch user data based on role first
$user_data = null;
switch ($role) {
    case 'student':
        $query = "SELECT 
            s.*,
            d.name as department_name,
            d.code as department_code,
            b.batch_name,
            b.admission_year,
            b.graduation_year,
            b.current_year_of_study,
            CASE 
                WHEN MONTH(CURDATE()) <= 5 THEN b.current_year_of_study * 2
                ELSE b.current_year_of_study * 2 - 1
            END as current_semester,
            DATE_FORMAT(s.created_at, '%d-%m-%Y') as joined_date,
            DATE_FORMAT(s.last_login, '%d-%m-%Y %H:%i') as last_login_date,
            rp.id AS recruitment_profile_id, 
            rp.placement_status, 
            rp.company_placed, 
            rp.placement_date, 
            rp.placement_package, 
            rp.placement_role,
            rp.public_profile,
            rp.profile_views,
            rp.last_updated
            FROM students s
            JOIN departments d ON s.department_id = d.id
            JOIN batch_years b ON s.batch_id = b.id
            LEFT JOIN student_recruitment_profiles rp ON s.id = rp.student_id
            WHERE s.id = ?";
        break;

    case 'faculty':
        $query = "SELECT 
            f.*,
            d.name as department_name,
            d.code as department_code,
            (SELECT COUNT(DISTINCT sa.id) 
             FROM subject_assignments sa 
             WHERE sa.faculty_id = f.id 
             AND sa.is_active = TRUE) as total_subjects,
            (SELECT COUNT(DISTINCT fb.id) 
             FROM feedback fb 
             JOIN subject_assignments sa ON fb.assignment_id = sa.id 
             WHERE sa.faculty_id = f.id) as total_feedback,
            (SELECT AVG(fb.cumulative_avg)
             FROM feedback fb
             JOIN subject_assignments sa ON fb.assignment_id = sa.id
             WHERE sa.faculty_id = f.id) as average_rating,
            DATE_FORMAT(f.created_at, '%d-%m-%Y') as joined_date,
            DATE_FORMAT(f.last_login, '%d-%m-%Y %H:%i') as last_login_date
            FROM faculty f
            JOIN departments d ON f.department_id = d.id
            WHERE f.id = ?";
        break;

    case 'hod':
        $query = "SELECT 
            h.*,
            d.name as department_name,
            d.code as department_code,
            (SELECT COUNT(*) 
             FROM faculty f 
             WHERE f.department_id = h.department_id 
             AND f.is_active = TRUE) as total_faculty,
            (SELECT COUNT(DISTINCT s.id)
             FROM students s
             WHERE s.department_id = h.department_id
             AND s.is_active = TRUE) as total_students,
            (SELECT COUNT(DISTINCT sa.id)
             FROM subject_assignments sa
             JOIN subjects s ON sa.subject_id = s.id
             WHERE s.department_id = h.department_id
             AND sa.is_active = TRUE) as total_subjects,
            (SELECT COUNT(DISTINCT es.id)
             FROM exit_surveys es
             WHERE es.department_id = h.department_id) as total_exit_surveys,
            (SELECT AVG(fb.cumulative_avg)
             FROM feedback fb
             JOIN subject_assignments sa ON fb.assignment_id = sa.id
             JOIN subjects s ON sa.subject_id = s.id
             WHERE s.department_id = h.department_id) as dept_avg_rating,
            (SELECT COUNT(DISTINCT by2.id)
             FROM batch_years by2
             JOIN students s ON s.batch_id = by2.id
             WHERE s.department_id = h.department_id
             AND s.is_active = TRUE) as total_batches,
            (SELECT GROUP_CONCAT(DISTINCT by2.batch_name ORDER BY by2.batch_name)
             FROM batch_years by2
             JOIN students s ON s.batch_id = by2.id
             WHERE s.department_id = h.department_id
             AND s.is_active = TRUE) as active_batches,
            DATE_FORMAT(h.created_at, '%d-%m-%Y') as joined_date,
            DATE_FORMAT(h.last_login, '%d-%m-%Y %H:%i') as last_login_date
            FROM hods h
            JOIN departments d ON h.department_id = d.id
            WHERE h.id = ?";
        break;

    case 'admin':
        $query = "SELECT 
            a.*,
            DATE_FORMAT(a.created_at, '%d-%m-%Y') as joined_date,
            DATE_FORMAT(a.last_login, '%d-%m-%Y %H:%i') as last_login_date,
            (SELECT COUNT(*) FROM departments) as total_departments,
            (SELECT COUNT(*) FROM faculty WHERE is_active = TRUE) as total_faculty,
            (SELECT COUNT(*) FROM students WHERE is_active = TRUE) as total_students,
            (SELECT COUNT(*) FROM subjects WHERE is_active = TRUE) as total_subjects
            FROM admin_users a
            WHERE a.id = ?";
        break;
}

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user_data = mysqli_fetch_assoc($result);

// Define size limits
$max_file_size = 5 * 1024 * 1024;     // 5MB limit for input file
$target_file_size = 800 * 1024;       // Target: 800KB for final file
$min_file_size = 1 * 1024;            // 1KB minimum
$max_dimension = 2048;                 // Maximum image dimension
$min_dimension = 100;                  // Minimum image dimension
$optimal_dimension = 800;              // Reduced from 500 to 800 for better quality
$initial_quality = 90;                 // Initial JPEG quality

// Function to compress image to target size
function compressImage($source_path, $destination_path, $target_size, $initial_quality = 90) {
    $current_quality = $initial_quality;
    $min_quality = 20; // Minimum acceptable quality
    
    do {
        // Create image based on file type
        $image_info = getimagesize($source_path);
        $mime_type = $image_info['mime'];
        
        switch ($mime_type) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source_path);
                break;
            default:
                return false;
        }
        
        // Save with current quality
        imagejpeg($image, $destination_path, $current_quality);
        imagedestroy($image);
        
        // Check file size
        $current_size = filesize($destination_path);
        
        // If size is still too large, reduce quality and try again
        if ($current_size > $target_size && $current_quality > $min_quality) {
            $current_quality -= 5;
        } else {
            break;
        }
    } while (true);
    
    return $current_size <= $target_size || $current_quality <= $min_quality;
}

// Handle profile image upload for students after we have user data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'student') {
    if (isset($_POST['cropped_image']) && !empty($_POST['cropped_image'])) {
        // Process the cropped image data
        $cropped_image_data = $_POST['cropped_image'];
        
        // Extract the base64 image data
        list($type, $cropped_image_data) = explode(';', $cropped_image_data);
        list(, $cropped_image_data) = explode(',', $cropped_image_data);
        $cropped_image_data = base64_decode($cropped_image_data);
        
        // Sanitize directory names
        $safe_batch_name = preg_replace('/[^a-zA-Z0-9-_]/', '', $user_data['batch_name']);
        $safe_dept_id = preg_replace('/[^a-zA-Z0-9-_]/', '', $user_data['department_id']);
        
        // Define upload directory structure
        $base_upload_dir = __DIR__ . '/uploads/';
        $relative_structure = $safe_batch_name . '/profile_images/' . $safe_dept_id;
        $upload_dir = $base_upload_dir . $relative_structure;
        
        // Create directories if they don't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Create relative path for database storage
        $relative_path = 'uploads/' . $relative_structure;
        
        // Create filename with register_number_name format
        $safe_name = preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '_', strtolower($user_data['name'])));
        $filename = $user_data['register_number'] . '_' . $safe_name . '.jpg';
        $upload_path = $upload_dir . '/' . $filename;
        $db_path = $relative_path . '/' . $filename;

        // Delete old file if exists
        if (!empty($user_data['profile_image_path']) && file_exists($user_data['profile_image_path'])) {
            unlink($user_data['profile_image_path']);
        }
        
        // Save the cropped image to file
        if (file_put_contents($upload_path, $cropped_image_data)) {
            // Verify final file size
            $final_size = filesize($upload_path);
            
            // Update database with new image path
            $update_image_query = "UPDATE students SET profile_image_path = ? WHERE id = ?";
            $stmt = mysqli_prepare($conn, $update_image_query);
            mysqli_stmt_bind_param($stmt, "si", $db_path, $user_id);
            if (mysqli_stmt_execute($stmt)) {
                $success_message = "Profile picture updated successfully! (Size: " . formatBytes($final_size) . ")";
                // Update user_data array with new path
                $user_data['profile_image_path'] = $db_path;
            } else {
                $error_message = "Failed to update database. Please try again.";
            }
        } else {
            $error_message = "Failed to save image. Please try again.";
        }
    } else if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        // Original file upload code - keep as fallback
        // Validate file size
        $file_size = $_FILES['profile_image']['size'];
        if ($file_size > $max_file_size) {
            $error_message = "File is too large. Maximum size allowed is " . formatBytes($max_file_size);
            goto skip_upload;
        }
        if ($file_size < $min_file_size) {
            $error_message = "File is too small. Minimum size required is " . formatBytes($min_file_size);
            goto skip_upload;
        }

        // Sanitize directory names
        $safe_batch_name = preg_replace('/[^a-zA-Z0-9-_]/', '', $user_data['batch_name']);
        $safe_dept_id = preg_replace('/[^a-zA-Z0-9-_]/', '', $user_data['department_id']);
        
        // Define upload directory structure as per requirement:
        // uploads/batch_name/profile_images/department_id/file
        $base_upload_dir = __DIR__ . '/uploads/';
        $relative_structure = $safe_batch_name . '/profile_images/' . $safe_dept_id;
        $upload_dir = $base_upload_dir . $relative_structure;
        
        // Create directories if they don't exist
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        // Create relative path for database storage
        $relative_path = 'uploads/' . $relative_structure;

        $file_extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($file_extension, $allowed_extensions)) {
            // Validate image dimensions before processing
            list($width, $height) = getimagesize($_FILES['profile_image']['tmp_name']);
            
            if ($width < $min_dimension || $height < $min_dimension) {
                $error_message = "Image dimensions are too small. Minimum dimension allowed is {$min_dimension}x{$min_dimension} pixels";
                goto skip_upload;
            }
            
            if ($width > $max_dimension || $height > $max_dimension) {
                $error_message = "Image dimensions are too large. Maximum dimension allowed is {$max_dimension}x{$max_dimension} pixels";
                goto skip_upload;
            }

            // Convert all images to jpg format for consistency
            // Create filename with register_number_name format
            $safe_name = preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '_', strtolower($user_data['name'])));
            $filename = $user_data['register_number'] . '_' . $safe_name . '.jpg';
            $upload_path = $upload_dir . '/' . $filename;
            $db_path = $relative_path . '/' . $filename;

            // Delete old file if exists
            if (!empty($user_data['profile_image_path']) && file_exists($user_data['profile_image_path'])) {
                unlink($user_data['profile_image_path']);
            }

            // Create temporary file for processing
            $temp_path = $upload_dir . '/temp_' . $filename;
            
            // First resize the image if needed
            $source_image = null;
            switch($file_extension) {
                case 'jpg':
                case 'jpeg':
                    $source_image = imagecreatefromjpeg($_FILES['profile_image']['tmp_name']);
                    break;
                case 'png':
                    $source_image = imagecreatefrompng($_FILES['profile_image']['tmp_name']);
                    break;
                case 'gif':
                    $source_image = imagecreatefromgif($_FILES['profile_image']['tmp_name']);
                    break;
            }

            if ($source_image) {
                // Resize if needed
                if ($width > $optimal_dimension || $height > $optimal_dimension) {
                    if ($width > $height) {
                        $new_width = $optimal_dimension;
                        $new_height = floor($height * ($optimal_dimension / $width));
                    } else {
                        $new_height = $optimal_dimension;
                        $new_width = floor($width * ($optimal_dimension / $height));
                    }
                    
                    $resized_image = imagecreatetruecolor($new_width, $new_height);
                    
                    // Preserve transparency for PNG images
                    if ($file_extension === 'png') {
                        imagealphablending($resized_image, false);
                        imagesavealpha($resized_image, true);
                    }
                    
                    imagecopyresampled($resized_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                    imagejpeg($resized_image, $temp_path, $initial_quality);
                    imagedestroy($resized_image);
                } else {
                    imagejpeg($source_image, $temp_path, $initial_quality);
                }
                
                imagedestroy($source_image);

                // Compress the image to target size
                if (!compressImage($temp_path, $upload_path, $target_file_size, $initial_quality)) {
                    unlink($temp_path);
                    $error_message = "Could not compress image to required size. Please try a different image.";
                    goto skip_upload;
                }

                // Clean up temporary file
                unlink($temp_path);

                // Verify final file size
                $final_size = filesize($upload_path);
                if ($final_size > $target_file_size) {
                    unlink($upload_path);
                    $error_message = "Failed to compress image to required size (" . formatBytes($target_file_size) . "). Please try a different image.";
                    goto skip_upload;
                }

                // Update database with new image path
                $update_image_query = "UPDATE students SET profile_image_path = ? WHERE id = ?";
                $stmt = mysqli_prepare($conn, $update_image_query);
                mysqli_stmt_bind_param($stmt, "si", $db_path, $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    $success_message = "Profile picture updated successfully! (Size: " . formatBytes($final_size) . ")";
                    // Update user_data array with new path
                    $user_data['profile_image_path'] = $db_path;
                } else {
                    $error_message = "Failed to update database. Please try again.";
                }
            } else {
                $error_message = "Failed to process image. Please try again.";
            }
        } else {
            $error_message = "Invalid file type. Please upload JPG, JPEG, PNG, or GIF files only.";
        }
    }
    skip_upload:
}

// Handle other form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['profile_image'])) {
    try {
        // Start transaction
        mysqli_begin_transaction($conn);
        
        // Handle placement status updates
        if (isset($_POST['update_placement']) && $role === 'student') {
            $placement_status = mysqli_real_escape_string($conn, $_POST['placement_status']);
            $company_placed = isset($_POST['company_placed']) ? mysqli_real_escape_string($conn, $_POST['company_placed']) : null;
            $placement_date = !empty($_POST['placement_date']) ? mysqli_real_escape_string($conn, $_POST['placement_date']) : null;
            $placement_package = isset($_POST['placement_package']) ? mysqli_real_escape_string($conn, $_POST['placement_package']) : null;
            $placement_role = isset($_POST['placement_role']) ? mysqli_real_escape_string($conn, $_POST['placement_role']) : null;
            $public_profile = isset($_POST['public_profile']) ? 1 : 0;
            
            // Check if the recruitment profile exists
            if (!empty($user_data['recruitment_profile_id'])) {
                // Update existing profile
                $update_query = "UPDATE student_recruitment_profiles SET 
                    placement_status = ?,
                    company_placed = ?,
                    placement_date = ?,
                    placement_package = ?,
                    placement_role = ?,
                    public_profile = ?
                    WHERE student_id = ?";
                $stmt = mysqli_prepare($conn, $update_query);
                mysqli_stmt_bind_param($stmt, "sssssii", 
                    $placement_status,
                    $company_placed,
                    $placement_date,
                    $placement_package,
                    $placement_role,
                    $public_profile,
                    $user_id
                );
            } else {
                // Create new profile
                $insert_query = "INSERT INTO student_recruitment_profiles 
                    (student_id, placement_status, company_placed, placement_date, placement_package, placement_role, public_profile) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = mysqli_prepare($conn, $insert_query);
                mysqli_stmt_bind_param($stmt, "isssssi", 
                    $user_id,
                    $placement_status,
                    $company_placed,
                    $placement_date,
                    $placement_package,
                    $placement_role,
                    $public_profile
                );
            }
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error updating placement information: " . mysqli_stmt_error($stmt));
            }
            
            // Log the action
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                          VALUES (?, ?, 'update_placement', ?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_details = json_encode([
                'placement_status' => $placement_status,
                'company_placed' => $company_placed,
                'placement_date' => $placement_date
            ]);
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            
            mysqli_stmt_bind_param($log_stmt, "issss", 
                $user_id,
                $role,
                $log_details,
                $ip_address,
                $user_agent
            );
            mysqli_stmt_execute($log_stmt);
            
            // Update user_data to reflect changes
            $user_data['placement_status'] = $placement_status;
            $user_data['company_placed'] = $company_placed;
            $user_data['placement_date'] = $placement_date;
            $user_data['placement_package'] = $placement_package;
            $user_data['placement_role'] = $placement_role;
            $user_data['public_profile'] = $public_profile;
            
            // Commit transaction
            mysqli_commit($conn);
            $success_message = "Placement information updated successfully!";
        }
        // Handle regular profile updates
        else if (isset($_POST['email'])) {
            // Validate email if it's set
            if (!isset($_POST['email']) || empty($_POST['email'])) {
                throw new Exception("Email is required");
            }
            
            $email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
            if (!$email) {
                throw new Exception("Invalid email format");
            }

            // Role-specific updates
            switch ($role) {
                case 'student':
                    $query = "UPDATE students SET 
                        email = ?,
                        phone = ?,
                        address = ?
                        WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    // Create temporary variables for phone and address
                    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
                    $address = isset($_POST['address']) ? $_POST['address'] : '';
                    mysqli_stmt_bind_param($stmt, "sssi", 
                        $email,
                        $phone,
                        $address,
                        $user_id
                    );
                    break;

                case 'faculty':
                    $query = "UPDATE faculty SET 
                        email = ?,
                        qualification = ?,
                        specialization = ?
                        WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    // Create temporary variables for qualification and specialization
                    $qualification = isset($_POST['qualification']) ? $_POST['qualification'] : '';
                    $specialization = isset($_POST['specialization']) ? $_POST['specialization'] : '';
                    mysqli_stmt_bind_param($stmt, "sssi", 
                        $email,
                        $qualification,
                        $specialization,
                        $user_id
                    );
                    break;

                case 'hod':
                    $query = "UPDATE hods SET 
                        email = ?
                        WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "si", 
                        $email,
                        $user_id
                    );
                    break;

                case 'admin':
                    $query = "UPDATE admin_users SET 
                        email = ?
                        WHERE id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    mysqli_stmt_bind_param($stmt, "si", 
                        $email,
                        $user_id
                    );
                    break;
            }

            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Error updating profile: " . mysqli_stmt_error($stmt));
            }

            // Log the action
            $log_query = "INSERT INTO user_logs (user_id, role, action, details, ip_address, user_agent) 
                          VALUES (?, ?, 'profile_update', ?, ?, ?)";
            $log_stmt = mysqli_prepare($conn, $log_query);
            $log_details = json_encode(['email' => $email]);
            $log_action = 'profile_update';
            $ip_address = $_SERVER['REMOTE_ADDR'];
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            
            mysqli_stmt_bind_param($log_stmt, "issss", 
                $user_id,
                $role,
                $log_details,
                $ip_address,
                $user_agent
            );
            mysqli_stmt_execute($log_stmt);

            // Commit transaction
            mysqli_commit($conn);
            $success_message = "Profile updated successfully!";
            
            // Update the user_data array with new values
            $user_data['email'] = $email;
            if ($role === 'student') {
                $user_data['phone'] = $phone;
                $user_data['address'] = $address;
            } elseif ($role === 'faculty') {
                $user_data['qualification'] = $qualification;
                $user_data['specialization'] = $specialization;
            }
        }
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $error_message = $e->getMessage();
    }
}

// Include header
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?php echo htmlspecialchars($user_data['name'] ?? 'User'); ?></title>
    <!-- Add Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <style>
        .profile-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .profile-header {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            text-align: center;
            margin-bottom: 2rem;
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
            box-shadow: var(--shadow);
        }

        .profile-name {
            font-size: 1.8rem;
            color: var(--text-color);
            margin-bottom: 0.5rem;
        }

        .profile-role {
            font-size: 1rem;
            color: #666;
            margin-bottom: 1rem;
        }

        .profile-id {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: var(--bg-color);
            border-radius: 25px;
            font-size: 0.9rem;
            color: var(--primary-color);
            box-shadow: var(--inner-shadow);
        }

        .profile-content {
            background: var(--bg-color);
            padding: 2rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .profile-section {
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 1.2rem;
            color: var(--text-color);
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-color);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.8rem 1.2rem;
            border: none;
            border-radius: 10px;
            background: var(--bg-color);
            box-shadow: var(--inner-shadow);
            color: var(--text-color);
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: var(--shadow);
        }

        .form-control[readonly] {
            background: rgba(0, 0, 0, 0.05);
            cursor: not-allowed;
        }

        .btn-submit {
            background: var(--primary-color);
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.4);
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: var(--inner-shadow);
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .info-item {
            background: var(--bg-color);
            padding: 1rem;
            border-radius: 15px;
            box-shadow: var(--inner-shadow);
        }

        .info-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .info-value {
            font-size: 1.1rem;
            color: var(--text-color);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            .profile-container {
                margin: 1rem;
            }

            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .profile-name {
                font-size: 1.5rem;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Add styles for status badge */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-badge.active {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        
        .status-badge.inactive {
            background: rgba(231, 76, 60, 0.1);
            color: #c0392b;
        }

        /* Profile Image Styles */
        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 1.5rem;
        }

        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: var(--shadow);
        }

        .profile-image-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-color);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .profile-image-upload:hover {
            transform: scale(1.1);
        }

        .profile-image-upload input[type="file"] {
            display: none;
        }

        .default-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            box-shadow: var(--shadow);
        }

        /* Image cropper styles */
        .crop-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.7);
            padding: 20px;
            backdrop-filter: blur(3px);
        }

        .crop-modal-content {
            background: var(--bg-color);
            margin: 2rem auto;
            padding: 20px;
            width: 90%;
            max-width: 700px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            position: relative;
        }

        .crop-container {
            height: 400px;
            width: 100%;
            background: #ddd;
            margin-bottom: 20px;
            overflow: hidden;
            border-radius: 10px;
            box-shadow: var(--inner-shadow);
        }

        .crop-preview {
            height: 150px;
            width: 150px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 20px auto;
            border: 3px solid var(--primary-color);
            background-color: #f0f0f0;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        
        .crop-preview > div {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: #f0f0f0;
        }
        
        /* Ensure preview shows content correctly */
        .crop-preview img {
            max-width: 100%;
            display: block;
        }
        
        .crop-controls {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .crop-btn {
            padding: 10px 16px;
            border-radius: 30px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }
        
        .crop-btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .crop-btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .crop-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        .crop-btn:active {
            transform: translateY(1px);
        }

        .crop-close {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-color);
        }

        .rating-bar {
            height: 8px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 4px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .rating-fill {
            height: 100%;
            background: var(--primary-color);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .info-item.highlight {
            background: linear-gradient(145deg, var(--bg-color), #f0f5fc);
        }

        .info-item.highlight .info-value {
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: var(--primary-color);
        }

        .info-item.highlight i {
            font-size: 1.2rem;
            opacity: 0.8;
        }

        .info-item.full-width {
            grid-column: 1 / -1;
        }

        .batch-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .batch-tag {
            background: var(--bg-color);
            padding: 0.4rem 1rem;
            border-radius: 15px;
            font-size: 0.9rem;
            box-shadow: var(--inner-shadow);
        }

        .batch-tag:hover {
            box-shadow: var(--shadow);
            transform: translateY(-2px);
        }

        /* Recruitment Profile Section Styles */
        .recruitment-section {
            margin-top: 2rem;
        }

        .recruitment-desc {
            color: #666;
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }
        
        /* Placement form styles */
        .placement-form {
            margin-bottom: 2rem;
        }
        
        .subsection-title {
            font-size: 1.1rem;
            color: var(--text-color);
            margin-bottom: 1.2rem;
            font-weight: 500;
        }
        
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .form-row .form-group {
            flex: 1;
            min-width: 250px;
        }
        
        .placement-toggle {
            display: flex;
            align-items: center;
            min-width: 250px;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }
        
        .checkbox-text {
            color: var(--text-color);
            font-size: 0.95rem;
        }
        
        .placement-details {
            background: rgba(0, 0, 0, 0.02);
            padding: 1.5rem;
            border-radius: 15px;
            margin-top: 1.5rem;
            box-shadow: var(--inner-shadow);
        }
        
        .placement-divider {
            height: 1px;
            background: rgba(0, 0, 0, 0.1);
            margin: 2rem 0;
        }

        .recruitment-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: flex-start;
        }

        .recruitment-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.8rem 1.2rem;
            border-radius: 25px;
            font-size: 0.95rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: var(--shadow);
        }

        .recruitment-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }

        .recruitment-btn.primary {
            background: var(--primary-color);
            color: white;
        }

        .recruitment-btn.secondary {
            background: var(--bg-color);
            color: var(--text-color);
            box-shadow: var(--inner-shadow);
        }

        .recruitment-btn.secondary:hover {
            background: rgba(52, 152, 219, 0.1);
            color: var(--primary-color);
            box-shadow: var(--shadow);
        }

        .recruitment-btn i {
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .recruitment-buttons {
                flex-direction: column;
                gap: 0.8rem;
            }
            
            .recruitment-btn {
                width: 100%;
                justify-content: center;
            }
        }

        /* Profile Stats Styles */
        .profile-stats {
            display: flex;
            gap: 1.5rem;
            margin: 1.5rem 0;
            padding: 1rem;
            background: rgba(78, 115, 223, 0.05);
            border-radius: 12px;
            flex-wrap: wrap;
        }
        
        .stats-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.3rem;
            padding: 0.8rem 1.2rem;
            background: var(--bg-color);
            border-radius: 10px;
            box-shadow: var(--soft-shadow);
            min-width: 120px;
            transition: all 0.3s ease;
        }
        
        .stats-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }
        
        .stats-item i {
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 0.3rem;
        }
        
        .stats-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .stats-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .profile-stats {
                flex-direction: row;
                justify-content: center;
                padding: 0.8rem;
            }
            
            .stats-item {
                min-width: 140px;
                flex: 1;
            }
        }
    </style>

    <!-- Add Cropper.js Script -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
</head>
<body>
    <div class="profile-container">
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="profile-header">
            <?php if ($role === 'student'): ?>
                <form method="POST" action="" enctype="multipart/form-data" id="profile-image-form">
                    <div class="profile-image-container">
                        <?php if (!empty($user_data['profile_image_path']) && file_exists($user_data['profile_image_path'])): ?>
                            <img src="<?php echo htmlspecialchars($user_data['profile_image_path']); ?>?v=<?php echo time(); ?>" alt="Profile Image" class="profile-image">
                        <?php else: ?>
                            <div class="default-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                        <?php endif; ?>
                        <label class="profile-image-upload" title="Upload Profile Picture">
                            <i class="fas fa-camera"></i>
                            <input type="file" id="profile-image-input" name="profile_image" accept="image/*">
                            <input type="hidden" name="cropped_image" id="cropped-image-data">
                        </label>
                    </div>
                </form>
                
                <!-- Image Cropping Modal -->
                <div id="cropModal" class="crop-modal">
                    <div class="crop-modal-content">
                        <span class="crop-close">&times;</span>
                        <h3 style="text-align: center; margin-bottom: 20px; color: var(--primary-color);">Crop Your Profile Picture</h3>
                        
                        <div class="crop-container">
                            <img id="image-to-crop" src="" alt="Image to crop">
                        </div>
                        
                        <div class="crop-preview"></div>
                        
                        <div class="crop-controls">
                            <button id="rotate-left" class="crop-btn crop-btn-secondary">
                                <i class="fas fa-undo"></i> Rotate Left
                            </button>
                            <button id="rotate-right" class="crop-btn crop-btn-secondary">
                                <i class="fas fa-redo"></i> Rotate Right
                            </button>
                            <button id="crop-save" class="crop-btn crop-btn-primary">
                                <i class="fas fa-check"></i> Save
                            </button>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
            <?php endif; ?>
            <h1 class="profile-name"><?php echo htmlspecialchars($user_data['name']); ?></h1>
            <div class="profile-role"><?php echo ucfirst($role); ?></div>
            <?php if (isset($user_data['faculty_id'])): ?>
                <div class="profile-id">Faculty ID: <?php echo htmlspecialchars($user_data['faculty_id']); ?></div>
            <?php elseif (isset($user_data['roll_number'])): ?>
                <div class="profile-id">Roll No: <?php echo htmlspecialchars($user_data['roll_number']); ?></div>
            <?php endif; ?>
        </div>

        <div class="profile-content">
            <form method="POST" action="">
                <?php if ($role === 'student'): ?>
                    <div class="profile-section">
                        <h2 class="section-title">Academic Information</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['department_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Batch</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['batch_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Current Year</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['current_year_of_study']); ?> Year</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Current Semester</div>
                                <div class="info-value">Semester <?php echo htmlspecialchars($user_data['current_semester']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (in_array($role, ['faculty', 'hod'])): ?>
                    <div class="profile-section">
                        <h2 class="section-title">Department Information</h2>
                        <div class="info-grid">
                            <div class="info-item"> 
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['department_name']); ?></div>
                            </div>
                            <?php if ($role === 'faculty'): ?>
                                <div class="info-item">
                                    <div class="info-label">Designation</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user_data['designation']); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Experience</div>
                                    <div class="info-value"><?php echo htmlspecialchars($user_data['experience']); ?> Years</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($role === 'hod'): ?>
                    <div class="profile-section">
                        <h2 class="section-title">Department Overview</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Department</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['department_name']); ?> (<?php echo htmlspecialchars($user_data['department_code']); ?>)</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">HOD ID</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['username']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Position</div>
                                <div class="info-value">Head of Department</div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">Department Rating</div>
                                <div class="info-value">
                                    <?php 
                                    $rating = number_format($user_data['dept_avg_rating'], 2);
                                    echo $rating . ' / 5.0';
                                    ?>
                                    <div class="rating-bar">
                                        <div class="rating-fill" style="width: <?php echo ($rating * 20); ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-section">
                        <h2 class="section-title">Department Statistics</h2>
                        <div class="info-grid">
                            <div class="info-item highlight">
                                <div class="info-label">Total Faculty</div>
                                <div class="info-value">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <?php echo htmlspecialchars($user_data['total_faculty']); ?>
                                </div>
                            </div>
                            <div class="info-item highlight">
                                <div class="info-label">Total Students</div>
                                <div class="info-value">
                                    <i class="fas fa-user-graduate"></i>
                                    <?php echo htmlspecialchars($user_data['total_students']); ?>
                                </div>
                            </div>
                            <div class="info-item highlight">
                                <div class="info-label">Total Subjects</div>
                                <div class="info-value">
                                    <i class="fas fa-book"></i>
                                    <?php echo htmlspecialchars($user_data['total_subjects']); ?>
                                </div>
                            </div>
                            <div class="info-item highlight">
                                <div class="info-label">Exit Surveys</div>
                                <div class="info-value">
                                    <i class="fas fa-poll"></i>
                                    <?php echo htmlspecialchars($user_data['total_exit_surveys']); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-section">
                        <h2 class="section-title">Batch Information</h2>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="info-label">Total Active Batches</div>
                                <div class="info-value"><?php echo htmlspecialchars($user_data['total_batches']); ?></div>
                            </div>
                            <div class="info-item full-width">
                                <div class="info-label">Active Batches</div>
                                <div class="info-value batch-tags">
                                    <?php 
                                    $batches = explode(',', $user_data['active_batches']);
                                    foreach ($batches as $batch): ?>
                                        <span class="batch-tag"><?php echo htmlspecialchars($batch); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="profile-section">
                    <h2 class="section-title">Contact Information</h2>
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($user_data['email']); ?>" required>
                    </div>

                    <?php if ($role === 'student'): ?>
                        <div class="form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_data['phone']); ?>" 
                                   pattern="[0-9]{10}" title="Please enter a valid 10-digit phone number">
                        </div>
                        <div class="form-group">
                            <label for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" 
                                    rows="3"><?php echo htmlspecialchars($user_data['address']); ?></textarea>
                        </div>
                    <?php endif; ?>

                    <?php if ($role === 'faculty'): ?>
                        <div class="form-group">
                            <label for="qualification">Qualification</label>
                            <input type="text" id="qualification" name="qualification" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_data['qualification']); ?>">
                        </div>
                        <div class="form-group">
                            <label for="specialization">Specialization</label>
                            <input type="text" id="specialization" name="specialization" class="form-control" 
                                   value="<?php echo htmlspecialchars($user_data['specialization']); ?>">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="text-align: center;">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Update Profile
                    </button>
                </div>
            </form>
        </div>

        <?php if ($_SESSION['role'] == 'student'): ?>
        <div class="profile-content recruitment-section">
            <div class="profile-section">
                <h2 class="section-title"><i class="fas fa-briefcase"></i> Recruitment Profile</h2>
                
                <!-- Add Placement Status Form -->
                <form method="POST" action="" class="placement-form">
                    <input type="hidden" name="update_placement" value="1">
                    
                    <div class="placement-status-section">
                        <h3 class="subsection-title">Placement Status</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="placement_status">Current Status</label>
                                <select id="placement_status" name="placement_status" class="form-control" required>
                                    <option value="not_started" <?php echo ($user_data['placement_status'] == 'not_started' || empty($user_data['placement_status'])) ? 'selected' : ''; ?>>
                                        Available for Placement
                                    </option>
                                    <option value="in_progress" <?php echo ($user_data['placement_status'] == 'in_progress') ? 'selected' : ''; ?>>
                                        Placement In Progress
                                    </option>
                                    <option value="placed" <?php echo ($user_data['placement_status'] == 'placed') ? 'selected' : ''; ?>>
                                        Placed
                                    </option>
                                    <option value="not_interested" <?php echo ($user_data['placement_status'] == 'not_interested') ? 'selected' : ''; ?>>
                                        Not Interested
                                    </option>
                                </select>
                            </div>
                            
                            <div class="form-group placement-toggle">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="public_profile" value="1" 
                                        <?php echo (!empty($user_data['public_profile']) && $user_data['public_profile'] == 1) ? 'checked' : ''; ?>>
                                    <span class="checkbox-text">Make my profile visible to recruiters</span>
                                </label>
                            </div>
                        </div>

                        <!-- Add Profile Stats -->
                        <div class="profile-stats">
                            <div class="stats-item">
                                <i class="fas fa-eye"></i>
                                <span class="stats-value"><?php echo number_format($user_data['profile_views'] ?? 0); ?></span>
                                <span class="stats-label">Profile Views</span>
                            </div>
                            <?php if (!empty($user_data['last_updated'])): ?>
                            <div class="stats-item">
                                <i class="fas fa-clock"></i>
                                <span class="stats-value">
                                    <?php echo date('M d, Y', strtotime($user_data['last_updated'])); ?>
                                </span>
                                <span class="stats-label">Last Updated</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div id="placement-details" class="placement-details" 
                            <?php echo ($user_data['placement_status'] == 'placed') ? '' : 'style="display:none;"'; ?>>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="company_placed">Company Name</label>
                                    <input type="text" id="company_placed" name="company_placed" class="form-control"
                                        value="<?php echo htmlspecialchars($user_data['company_placed'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="placement_date">Placement Date</label>
                                    <input type="date" id="placement_date" name="placement_date" class="form-control"
                                        value="<?php echo htmlspecialchars($user_data['placement_date'] ?? ''); ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="placement_role">Job Role</label>
                                    <input type="text" id="placement_role" name="placement_role" class="form-control"
                                        value="<?php echo htmlspecialchars($user_data['placement_role'] ?? ''); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label for="placement_package">Package (₹ LPA)</label>
                                    <input type="text" id="placement_package" name="placement_package" class="form-control"
                                        placeholder="E.g., 8.5 LPA" value="<?php echo htmlspecialchars($user_data['placement_package'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group" style="text-align: center; margin-top: 1.5rem;">
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Update Placement Information
                        </button>
                    </div>
                </form>
                
                <div class="placement-divider"></div>
                
                <p class="recruitment-desc">
                    Complete your full recruitment profile to showcase your skills and professional information to campus recruiters.
                </p>
                <div class="recruitment-buttons">
                    <a href="recruitment_profile.php" class="recruitment-btn primary">
                        <i class="fas fa-user-tie"></i> Manage Basic Info
                    </a>
                    <a href="recruitment_profile.php?tab=education" class="recruitment-btn secondary">
                        <i class="fas fa-graduation-cap"></i> Education
                    </a>
                    <a href="recruitment_profile.php?tab=experience" class="recruitment-btn secondary">
                        <i class="fas fa-briefcase"></i> Experience
                    </a>
                    <a href="recruitment_profile.php?tab=projects" class="recruitment-btn secondary">
                        <i class="fas fa-project-diagram"></i> Projects
                    </a>
                    <a href="recruitment_profile.php?tab=skills" class="recruitment-btn secondary">
                        <i class="fas fa-tools"></i> Skills
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        // Image cropper setup
        let cropper;
        const cropModal = document.getElementById('cropModal');
        const imageToCrop = document.getElementById('image-to-crop');
        const cropInput = document.getElementById('profile-image-input');
        const croppedImageData = document.getElementById('cropped-image-data');
        const cropSave = document.getElementById('crop-save');
        const cropClose = document.querySelector('.crop-close');
        const rotateLeft = document.getElementById('rotate-left');
        const rotateRight = document.getElementById('rotate-right');
        const profileForm = document.getElementById('profile-image-form');

        // Event listeners
        if (cropInput) {
            cropInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                    const file = this.files[0];
                    
                    // Check file type
                    if (!file.type.match('image.*')) {
                        alert('Please select an image file');
                        return;
                    }
                    
                    // Check file size (max 5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File is too large. Maximum size allowed is 5MB');
                        return;
                    }
                    
                const reader = new FileReader();
                reader.onload = function(e) {
                        // Set the image source and show the modal
                        imageToCrop.src = e.target.result;
                        cropModal.style.display = 'block';
                        
                        // Initialize cropper after the image is loaded
                        setTimeout(() => {
                            if (cropper) {
                                cropper.destroy();
                            }
                            
                            cropper = new Cropper(imageToCrop, {
                                aspectRatio: 1, // Square aspect ratio for profile picture
                                viewMode: 1,    // Restrict the crop box to not exceed the size of the canvas
                                guides: true,   // Show grid lines in the crop box
                                center: true,   // Show the center indicator in the crop box
                                highlight: true, // Show the white modal to highlight the crop box
                                background: false, // Don't show the grid background
                                autoCropArea: 0.8, // 80% of the image will be in crop area by default
                                responsive: true,
                                preview: '.crop-preview', // Updated selector for preview container
                                zoomable: true,
                                scalable: true,
                                ready: function() {
                                    // Ensure preview is updated on initial load
                                    const previewContainer = document.querySelector('.crop-preview');
                                    if (previewContainer) {
                                        // Force refresh the preview
                                        setTimeout(() => {
                                            window.dispatchEvent(new Event('resize'));
                                        }, 200);
                                    }
                                }
                            });
                        }, 100);
                    };
                    reader.readAsDataURL(file);
                }
            });
        }

        // Rotate left button
        if (rotateLeft) {
            rotateLeft.addEventListener('click', function() {
                if (cropper) {
                    cropper.rotate(-90);
                }
            });
        }

        // Rotate right button
        if (rotateRight) {
            rotateRight.addEventListener('click', function() {
                if (cropper) {
                    cropper.rotate(90);
                }
            });
        }

        // Save cropped image
        if (cropSave) {
            cropSave.addEventListener('click', function() {
                if (cropper) {
                    // Get the cropped canvas
                    const canvas = cropper.getCroppedCanvas({
                        width: 300,    // Output image width
                        height: 300,   // Output image height
                        minWidth: 150, // Minimum width
                        minHeight: 150, // Minimum height
                        maxWidth: 1000, // Maximum width
                        maxHeight: 1000, // Maximum height
                        fillColor: '#fff', // Background color if the cropped area is padded
                        imageSmoothingEnabled: true,
                        imageSmoothingQuality: 'high',
                    });
                    
                    // Convert canvas to base64 string (JPEG format, 90% quality)
                    const croppedImageDataUrl = canvas.toDataURL('image/jpeg', 0.9);
                    
                    // Set the hidden input value
                    croppedImageData.value = croppedImageDataUrl;
                    
                    // Close the modal
                    cropModal.style.display = 'none';
                    
                    // Submit the form
                    profileForm.submit();
                }
            });
        }

        // Close modal
        if (cropClose) {
            cropClose.addEventListener('click', function() {
                cropModal.style.display = 'none';
                
                // Reset the file input
                if (cropInput) {
                    cropInput.value = '';
                }
                
                // Destroy cropper to free memory
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            });
        }

        // Close modal when clicking outside
        window.addEventListener('click', function(e) {
            if (e.target === cropModal) {
                cropModal.style.display = 'none';
                
                // Reset the file input
                if (cropInput) {
                    cropInput.value = '';
                }
                
                // Destroy cropper to free memory
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
            }
        });

        // Force reload of image when page loads to prevent caching
        window.addEventListener('load', function() {
            const profileImage = document.querySelector('.profile-image');
            if (profileImage) {
                const currentSrc = profileImage.src;
                if (currentSrc.indexOf('?') > -1) {
                    profileImage.src = currentSrc;
                } else {
                    profileImage.src = currentSrc + '?v=' + new Date().getTime();
                }
            }
        });
        
        // Placement status handling
        document.addEventListener('DOMContentLoaded', function() {
            // Get the placement status select element
            const placementStatusSelect = document.getElementById('placement_status');
            const placementDetails = document.getElementById('placement-details');
            
            if (placementStatusSelect && placementDetails) {
                // Function to toggle visibility of placement details
                function togglePlacementDetails() {
                    if (placementStatusSelect.value === 'placed') {
                        placementDetails.style.display = 'block';
                    } else {
                        placementDetails.style.display = 'none';
                    }
                }
                
                // Add event listener for status changes
                placementStatusSelect.addEventListener('change', togglePlacementDetails);
                
                // Initialize on page load
                togglePlacementDetails();
            }
        });
    </script>
</body>
</html>

<?php
// Helper function to format bytes into human readable format
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
} 