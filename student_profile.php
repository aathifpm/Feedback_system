<?php
// This page shows a single student profile to recruiters
session_start();
include 'functions.php';

// Check if ID is provided
$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($student_id <= 0) {
    header('Location: recruiter_view.php');
    exit();
}

// Check if user is authenticated as admin, faculty, or HOD
$is_authenticated = isset($_SESSION['user_id']) && ($_SESSION['role'] == 'admin' || $_SESSION['role'] == 'hod' || $_SESSION['role'] == 'faculty');

// Get student details - only show public profiles
$query = "SELECT s.id, s.name, s.roll_number, s.register_number, s.email, s.phone, s.section,
          s.profile_image_path, -- Added this field
          d.name as department_name, 
          b.batch_name, b.admission_year, b.graduation_year, b.current_year_of_study,
          rp.*
          FROM students s
          JOIN departments d ON s.department_id = d.id
          JOIN batch_years b ON s.batch_id = b.id
          JOIN student_recruitment_profiles rp ON s.id = rp.student_id
          WHERE s.id = ? AND s.is_active = 1 AND rp.public_profile = 1";

$stmt = mysqli_prepare($conn, $query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

// Check if student exists and profile is public
if (mysqli_num_rows($result) == 0) {
    header('Location: recruiter_view.php?error=profile_not_found');
    exit();
}

// Increment profile views - only if viewer is not the profile owner and hasn't viewed in this session
$viewed_profiles_key = 'viewed_profiles';
if (!isset($_SESSION[$viewed_profiles_key])) {
    $_SESSION[$viewed_profiles_key] = array();
}

if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $student_id) {
    // Check if this profile has been viewed in this session
    if (!in_array($student_id, $_SESSION[$viewed_profiles_key])) {
        $update_views_query = "UPDATE student_recruitment_profiles 
                              SET profile_views = profile_views + 1, 
                                  last_updated = CURRENT_TIMESTAMP 
                              WHERE student_id = ?";
        $stmt = mysqli_prepare($conn, $update_views_query);
        mysqli_stmt_bind_param($stmt, "i", $student_id);
        mysqli_stmt_execute($stmt);
        
        // Add this profile to viewed profiles in this session
        $_SESSION[$viewed_profiles_key][] = $student_id;
    }
}

// Debug information - remove this in production
echo "<!-- Debug: Profile Image Path = " . htmlspecialchars($student['profile_image_path'] ?? 'not set') . " -->";
echo "<!-- Debug: File Exists = " . ((!empty($student['profile_image_path']) && file_exists($student['profile_image_path'])) ? 'yes' : 'no') . " -->";

$student = mysqli_fetch_assoc($result);

// Fetch education entries
$education_query = "SELECT id, institution_name, degree, field_of_study, start_year, end_year, is_current, 
                   grade, activities, description 
                   FROM student_education 
                   WHERE student_id = ? 
                   ORDER BY is_current DESC, end_year DESC, start_year DESC";
$stmt = mysqli_prepare($conn, $education_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$education_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $education_entries[] = $row;
}

// Fetch experience entries
$experience_query = "SELECT id, title, company_name, location, start_date, end_date, is_current, 
                    employment_type, description, skills_used 
                    FROM student_experience 
                    WHERE student_id = ? 
                    ORDER BY is_current DESC, end_date DESC, start_date DESC";
$stmt = mysqli_prepare($conn, $experience_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$experience_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $experience_entries[] = $row;
}

// Fetch project entries
$project_query = "SELECT id, title, start_date, end_date, is_current, project_url, github_url, 
                 description, technologies_used 
                 FROM student_projects 
                 WHERE student_id = ? 
                 ORDER BY is_current DESC, end_date DESC, start_date DESC";
$stmt = mysqli_prepare($conn, $project_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$project_entries = [];
while ($row = mysqli_fetch_assoc($result)) {
    $project_entries[] = $row;
}

// Fetch certificates
$certificates_query = "SELECT * FROM student_certificates 
                      WHERE student_id = ? 
                      ORDER BY category, issue_date DESC";
$stmt = mysqli_prepare($conn, $certificates_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$certificates = [];
while ($row = mysqli_fetch_assoc($result)) {
    $certificates[$row['category']][] = $row;
}

// Fetch skills
$skills_query = "SELECT id, skill_name, proficiency, is_top_skill, endorsement_count 
                FROM student_skills 
                WHERE student_id = ? 
                ORDER BY is_top_skill DESC, endorsement_count DESC";
$stmt = mysqli_prepare($conn, $skills_query);
mysqli_stmt_bind_param($stmt, "i", $student_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$skills = [];
while ($row = mysqli_fetch_assoc($result)) {
    $skills[] = $row;
}

// Page title
$page_title = "Student Profile: " . $student['name'];
include 'header.php';
?>

<!-- Back Button (Fixed Position) -->
<a href="javascript:history.back()" class="back-btn neu-btn neu-btn-secondary">
    <i class="fas fa-arrow-left"></i> Back
</a>

<div class="container py-5">
    <!-- Profile Header -->
    <div class="profile-header mb-4">
        <div class="row">
            <div class="col-md-12">
                <div class="profile-info-wrapper">
                    <div class="profile-image-container">
                        <?php 
                        if (!empty($student['profile_image_path'])) {
                            // Debug information
                            echo "<!-- Debug: Attempting to show image from: " . htmlspecialchars($student['profile_image_path']) . " -->";
                            if (file_exists($student['profile_image_path'])) {
                                echo '<img src="' . htmlspecialchars($student['profile_image_path']) . '?v=' . time() . '" alt="Profile Image" class="profile-image">';
                            } else {
                                echo "<!-- Debug: File does not exist at specified path -->";
                                echo '<div class="profile-avatar">' . substr($student['name'], 0, 1) . '</div>';
                            }
                        } else {
                            echo "<!-- Debug: No profile image path set -->";
                            echo '<div class="profile-avatar">' . substr($student['name'], 0, 1) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="profile-info">
                        <h1 class="profile-name"><?php echo htmlspecialchars($student['name']); ?></h1>
                        
                        <?php if (!empty($student['headline'])): ?>
                            <p class="profile-headline"><?php echo html_entity_decode($student['headline']); ?></p>
                        <?php endif; ?>
                        
                        <div class="profile-details">
                            <div class="profile-detail-item">
                                <i class="fas fa-graduation-cap"></i>
                                <span><?php echo htmlspecialchars($student['department_name']); ?></span>
                            </div>
                            
                            <div class="profile-detail-item">
                                <i class="fas fa-user-clock"></i>
                                <span><?php echo htmlspecialchars($student['batch_name'] . ' - Year ' . $student['current_year_of_study']); ?></span>
                            </div>
                            
                            <?php if (!empty($student['location'])): ?>
                            <div class="profile-detail-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <span><?php echo html_entity_decode($student['location']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="profile-status">
                            <?php if ($student['placement_status'] == 'placed'): ?>
                                <span class="status-badge placed">Placed</span>
                            <?php elseif ($student['placement_status'] == 'in_progress'): ?>
                                <span class="status-badge in-progress">In Progress</span>
                            <?php elseif ($student['placement_status'] == 'not_interested'): ?>
                                <span class="status-badge not-interested">Not Interested</span>
                            <?php else: ?>
                                <span class="status-badge available">Available</span>
                            <?php endif; ?>
                            
                            <?php if ($is_authenticated): ?>
                            <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="neu-btn neu-btn-primary contact-btn">
                                <i class="fas fa-envelope"></i> Contact
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Profile Info Column -->
        <div class="col-lg-4 mb-4">
            <!-- Profile Info Grid -->
            <div class="profile-info-grid">
                <!-- Basic Info Card -->
                <div class="neu-card mini-card">
                    <div class="mini-card-header">
                        <h5><i class="fas fa-id-card me-2"></i>Basic Info</h5>
                    </div>
                    <div class="mini-card-body">
                        <div class="info-item">
                            <span class="info-label">Roll Number</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['roll_number']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Register No.</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['register_number']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Year / Section</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['current_year_of_study'] ?? 'N/A'); ?> / <?php echo htmlspecialchars($student['section'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                </div>

                
                <?php if ($is_authenticated): ?>
                <!-- Contact Info Card -->
                <div class="neu-card mini-card">
                    <div class="mini-card-header">
                        <h5><i class="fas fa-address-book me-2"></i>Contact</h5>
                    </div>
                    <div class="mini-card-body">
                        <div class="info-item">
                            <span class="info-label">Email</span>
                            <span class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="contact-link">
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </a>
                            </span>
                        </div>
                        <?php if (!empty($student['phone'])): ?>
                        <div class="info-item">
                            <span class="info-label">Phone</span>
                            <span class="info-value">
                                <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="contact-link">
                                    <?php echo htmlspecialchars($student['phone']); ?>
                                </a>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($student['location'])): ?>
                        <div class="info-item">
                            <span class="info-label">Location</span>
                            <span class="info-value"><?php echo html_entity_decode($student['location']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($student['looking_for'])): ?>
                <!-- Career Preferences Card -->
                <div class="neu-card mini-card">
                    <div class="mini-card-header">
                        <h5><i class="fas fa-search me-2"></i>Looking For</h5>
                    </div>
                    <div class="mini-card-body">
                        <div class="looking-for-badge mb-2">
                            <?php echo htmlspecialchars($student['looking_for']); ?>
                        </div>
                        <?php if ($student['willing_to_relocate']): ?>
                            <div class="relocation-badge">
                                <i class="fas fa-map-marked-alt me-1"></i> Willing to relocate
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($student['placement_status'] == 'placed' && !empty($student['company_placed'])): ?>
                <!-- Placement Card -->
                <div class="neu-card mini-card placement-mini-card">
                    <div class="mini-card-header success-header">
                        <h5><i class="fas fa-briefcase me-2"></i>Placement</h5>
                    </div>
                    <div class="mini-card-body">
                        <div class="info-item">
                            <span class="info-label">Company</span>
                            <span class="info-value fw-bold"><?php echo htmlspecialchars($student['company_placed']); ?></span>
                        </div>
                        <?php if (!empty($student['placement_role'])): ?>
                        <div class="info-item">
                            <span class="info-label">Role</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['placement_role']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($student['placement_date'])): ?>
                        <div class="info-item">
                            <span class="info-label">Date</span>
                            <span class="info-value"><?php echo date('d M Y', strtotime($student['placement_date'])); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($student['placement_package']) && $is_authenticated): ?>
                        <div class="info-item">
                            <span class="info-label">Package</span>
                            <span class="info-value"><?php echo htmlspecialchars($student['placement_package']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Social Links Card -->
                <?php if (!empty($student['linkedin_url']) || !empty($student['github_url']) || !empty($student['portfolio_url']) || !empty($student['resume_path'])): ?>
                <div class="neu-card mini-card">
                    <div class="mini-card-header">
                        <h5><i class="fas fa-link me-2"></i>Connect</h5>
                    </div>
                    <div class="mini-card-body p-2">
                        <div class="social-links-container">
                            <?php if (!empty($student['linkedin_url'])): ?>
                            <a href="<?php echo html_entity_decode($student['linkedin_url']); ?>" class="social-link linkedin" target="_blank">
                                <i class="fab fa-linkedin"></i>
                                <span>LinkedIn</span>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['github_url'])): ?>
                            <a href="<?php echo html_entity_decode($student['github_url']); ?>" class="social-link github" target="_blank">
                                <i class="fab fa-github"></i>
                                <span>GitHub</span>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['portfolio_url'])): ?>
                            <a href="<?php echo html_entity_decode($student['portfolio_url']); ?>" class="social-link portfolio" target="_blank">
                                <i class="fas fa-globe"></i>
                                <span>Portfolio</span>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($student['resume_path'])): ?>
                            <a href="<?php echo htmlspecialchars($student['resume_path']); ?>?v=<?php echo time(); ?>" class="social-link resume" target="_blank">
                                <i class="fas fa-file-pdf"></i>
                                <span>Resume</span>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Skills Card -->
                <?php if (!empty($skills)): ?>
                <div class="neu-card mini-card">
                    <div class="mini-card-header">
                        <h5><i class="fas fa-tools me-2"></i>Skills</h5>
                    </div>
                    <div class="mini-card-body">
                        <?php 
                        // Display top skills first
                        $top_skills = array_filter($skills, function($skill) {
                            return $skill['is_top_skill'] == 1;
                        });
                        
                        if (!empty($top_skills)): ?>
                        <div class="skill-category mb-3">
                            <h6 class="skill-category-title">Top Skills</h6>
                            <div class="skills-container">
                                <?php foreach ($top_skills as $skill): ?>
                                    <div class="skill-badge top-skill">
                                        <span class="skill-name"><?php echo html_entity_decode($skill['skill_name']); ?></span>
                                        <?php if (!empty($skill['endorsement_count']) && $skill['endorsement_count'] > 0): ?>
                                            <span class="endorsement-count"><?php echo $skill['endorsement_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php 
                        // Display other skills
                        $other_skills = array_filter($skills, function($skill) {
                            return $skill['is_top_skill'] != 1;
                        });
                        
                        if (!empty($other_skills)): ?>
                        <div class="skill-category">
                            <h6 class="skill-category-title">Additional Skills</h6>
                            <div class="skills-container">
                                <?php foreach ($other_skills as $skill): ?>
                                    <div class="skill-badge">
                                        <span class="skill-name"><?php echo html_entity_decode($skill['skill_name']); ?></span>
                                        <?php if (!empty($skill['endorsement_count']) && $skill['endorsement_count'] > 0): ?>
                                            <span class="endorsement-count"><?php echo $skill['endorsement_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Skills and Experience Column -->
        <div class="col-lg-8">
            <!-- About Section -->
            <?php if (!empty($student['about'])): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-user me-2"></i> About</h5>
                </div>
                <div class="neu-card-body about-section">
                    <div class="content-text">
                        <?php echo nl2br(html_entity_decode($student['about'])); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Career Objective -->
            <?php if (!empty($student['career_objective'])): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-bullseye me-2"></i> Career Objective</h5>
                </div>
                <div class="neu-card-body about-section">
                    <div class="content-text">
                        <?php echo nl2br(html_entity_decode($student['career_objective'])); ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Experience Section -->
            <?php if (!empty($experience_entries)): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-briefcase me-2"></i> Work Experience</h5>
                </div>
                <div class="neu-card-body">
                    <div class="timeline modern-timeline">
                        <?php foreach ($experience_entries as $index => $entry): ?>
                            <div class="timeline-item modern-timeline-item">
                                <div class="timeline-marker">
                                    <div class="timeline-dot"></div>
                                    <?php if ($index < count($experience_entries) - 1): ?>
                                        <div class="timeline-line"></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="timeline-content modern-timeline-content">
                                    <div class="timeline-header">
                                        <h5 class="timeline-title"><?php echo htmlspecialchars($entry['title']); ?></h5>
                                        <h6 class="timeline-subtitle">
                                            <?php echo htmlspecialchars($entry['company_name']); ?>
                                            <?php if (!empty($entry['location'])): ?>
                                                <span class="location"><i class="fas fa-map-marker-alt ms-1 me-1"></i><?php echo html_entity_decode($entry['location']); ?></span>
                                            <?php endif; ?>
                                        </h6>
                                        <div class="timeline-period">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?php 
                                            $start_date = new DateTime($entry['start_date']);
                                            echo $start_date->format('M Y'); 
                                            ?> - 
                                            <?php 
                                            if ($entry['is_current']) {
                                                echo '<span class="current-badge">Present</span>';
                                            } else {
                                                $end_date = new DateTime($entry['end_date']);
                                                echo $end_date->format('M Y');
                                            }
                                            ?>
                                            <?php if (!empty($entry['employment_type'])): ?>
                                                <span class="employment-type"><?php echo htmlspecialchars($entry['employment_type']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($entry['description'])): ?>
                                        <div class="timeline-description">
                                            <?php echo nl2br(html_entity_decode($entry['description'])); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($entry['skills_used'])): ?>
                                        <div class="timeline-skills">
                                            <?php 
                                            $skills_array = explode(',', $entry['skills_used']);
                                            foreach ($skills_array as $skill): 
                                                $skill = trim($skill);
                                                if (!empty($skill)):
                                            ?>
                                                <span class="skill-tag"><?php echo html_entity_decode($skill); ?></span>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Education Section -->
            <?php if (!empty($education_entries)): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i> Education</h5>
                </div>
                <div class="neu-card-body">
                    <div class="timeline modern-timeline">
                        <?php foreach ($education_entries as $index => $entry): ?>
                            <div class="timeline-item modern-timeline-item">
                                <div class="timeline-marker">
                                    <div class="timeline-dot education-dot"></div>
                                    <?php if ($index < count($education_entries) - 1): ?>
                                        <div class="timeline-line"></div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="timeline-content modern-timeline-content education-content">
                                    <div class="timeline-header">
                                        <h5 class="timeline-title"><?php echo htmlspecialchars($entry['institution_name']); ?></h5>
                                        <h6 class="timeline-subtitle">
                                            <?php echo htmlspecialchars($entry['degree']); ?>
                                            <?php if (!empty($entry['field_of_study'])): ?>
                                                <span class="field-of-study"><?php echo htmlspecialchars($entry['field_of_study']); ?></span>
                                            <?php endif; ?>
                                        </h6>
                                        <div class="timeline-period">
                                            <i class="fas fa-calendar-alt"></i>
                                            <?php echo htmlspecialchars($entry['start_year']); ?> - 
                                            <?php echo $entry['is_current'] ? '<span class="current-badge">Present</span>' : htmlspecialchars($entry['end_year']); ?>
                                            <?php if (!empty($entry['grade'])): ?>
                                                <span class="grade-badge ms-3"><i class="fas fa-star"></i><?php echo htmlspecialchars($entry['grade']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($entry['activities'])): ?>
                                        <div class="timeline-activities">
                                            <strong><i class="fas fa-trophy"></i>Activities:</strong> <?php echo htmlspecialchars($entry['activities']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($entry['description'])): ?>
                                        <div class="timeline-description">
                                            <?php echo nl2br(html_entity_decode($entry['description'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Projects Section -->
            <?php if (!empty($project_entries)): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-project-diagram me-2"></i> Projects</h5>
                </div>
                <div class="neu-card-body">
                    <div class="projects-grid">
                        <?php foreach ($project_entries as $entry): ?>
                            <div class="project-card modern-project-card">
                                <div class="project-header">
                                    <h5 class="project-title"><?php echo htmlspecialchars($entry['title']); ?></h5>
                                    <?php if (!empty($entry['start_date'])): ?>
                                    <div class="project-period">
                                        <i class="fas fa-calendar-alt"></i>
                                        <?php 
                                        $start_date = new DateTime($entry['start_date']);
                                        echo $start_date->format('M Y'); 
                                        ?> - 
                                        <?php 
                                        if ($entry['is_current']) {
                                            echo '<span class="current-badge">Present</span>';
                                        } elseif (!empty($entry['end_date'])) {
                                            $end_date = new DateTime($entry['end_date']);
                                            echo $end_date->format('M Y');
                                        }
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($entry['description'])): ?>
                                    <div class="project-description">
                                        <?php echo nl2br(htmlspecialchars($entry['description'])); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($entry['technologies_used'])): ?>
                                    <div class="project-technologies">
                                        <h6><i class="fas fa-code"></i>Technologies</h6>
                                        <div class="tech-tags">
                                            <?php 
                                            $technologies_array = explode(',', $entry['technologies_used']);
                                            foreach ($technologies_array as $tech): 
                                                $tech = trim($tech);
                                                if (!empty($tech)):
                                            ?>
                                                <span class="tech-tag"><?php echo html_entity_decode($tech); ?></span>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="project-links">
                                    <?php if (!empty($entry['project_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($entry['project_url']); ?>" target="_blank" class="project-link demo">
                                            <i class="fas fa-external-link-alt"></i> Demo
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($entry['github_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($entry['github_url']); ?>" target="_blank" class="project-link github">
                                            <i class="fab fa-github"></i> Code
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Certificates Section -->
            <?php if (!empty($certificates)): ?>
            <div class="neu-card content-card mb-4">
                <div class="neu-card-header">
                    <h5 class="mb-0"><i class="fas fa-certificate me-2"></i> Certificates</h5>
                </div>
                <div class="neu-card-body">
                    <!-- Certificates Tabs -->
                    <ul class="nav nav-tabs neu-tabs mb-4" role="tablist">
                        <?php 
                        $categories = ['internship' => 'Internship', 'course' => 'Courses', 'achievement' => 'Achievements'];
                        $first = true;
                        foreach ($categories as $key => $label):
                            if (!empty($certificates[$key])):
                        ?>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?php echo $first ? 'active' : ''; ?>" 
                                   data-bs-toggle="tab" 
                                   href="#<?php echo $key; ?>-certs" 
                                   role="tab">
                                    <i class="fas <?php 
                                        echo $key === 'internship' ? 'fa-briefcase' : 
                                            ($key === 'course' ? 'fa-graduation-cap' : 'fa-trophy'); 
                                    ?>"></i>
                                    <?php echo $label; ?>
                                    <span class="badge bg-primary ms-2"><?php echo count($certificates[$key]); ?></span>
                                </a>
                            </li>
                        <?php 
                            $first = false;
                            endif;
                        endforeach; 
                        ?>
                    </ul>

                    <!-- Certificates Content -->
                    <div class="tab-content">
                        <?php 
                        $first = true;
                        foreach ($categories as $key => $label):
                            if (!empty($certificates[$key])):
                        ?>
                            <div class="tab-pane fade <?php echo $first ? 'show active' : ''; ?>" 
                                 id="<?php echo $key; ?>-certs" 
                                 role="tabpanel">
                                <div class="certificates-grid">
                                    <?php foreach ($certificates[$key] as $cert): ?>
                                        <div class="certificate-card modern-certificate-card">
                                            <div class="certificate-header">
                                                <h5 class="certificate-title">
                                                    <?php echo htmlspecialchars($cert['name']); ?>
                                                </h5>
                                                <?php if ($cert['is_verified']): ?>
                                                    <span class="verified-badge">
                                                        <i class="fas fa-check-circle"></i> Verified
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div class="certificate-body">
                                                <div class="issuer">
                                                    <i class="fas fa-building"></i>
                                                    <?php echo htmlspecialchars($cert['issuing_organization']); ?>
                                                </div>

                                                <div class="cert-dates">
                                                    <div class="issue-date">
                                                        <i class="fas fa-calendar-check"></i>
                                                        Issued: <?php echo date('M Y', strtotime($cert['issue_date'])); ?>
                                                    </div>
                                                    <?php if (!empty($cert['expiry_date'])): ?>
                                                        <div class="expiry-date">
                                                            <i class="fas fa-calendar-times"></i>
                                                            Expires: <?php echo date('M Y', strtotime($cert['expiry_date'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if (!empty($cert['credential_id'])): ?>
                                                    <div class="credential-id">
                                                        <i class="fas fa-fingerprint"></i>
                                                        Credential ID: <?php echo htmlspecialchars($cert['credential_id']); ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($cert['description'])): ?>
                                                    <div class="cert-description">
                                                        <?php echo nl2br(htmlspecialchars($cert['description'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($cert['credential_url'])): ?>
                                                <div class="certificate-footer">
                                                    <a href="<?php echo htmlspecialchars($cert['credential_url']); ?>" 
                                                       target="_blank" 
                                                       class="verify-btn">
                                                        <i class="fas fa-external-link-alt"></i> Verify Certificate
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php 
                            $first = false;
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($is_authenticated): ?>
            <!-- Contact Action Card for Recruiters/Admins -->
            <div class="neu-card contact-action-card mb-4">
                <div class="neu-card-body">
                    <div class="contact-card-content">
                        <div class="contact-card-icon">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="contact-card-text">
                            <h5>Interested in recruiting this student?</h5>
                            <p>Reach out directly or request an interview to connect with this candidate.</p>
                            <div class="contact-actions">
                                <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="neu-btn neu-btn-primary me-2">
                                    <i class="fas fa-envelope"></i> Contact Directly
                                </a>
                                <a href="recruitment_request.php?student_id=<?php echo $student_id; ?>" class="neu-btn neu-btn-success">
                                    <i class="fas fa-user-plus"></i> Request Interview
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add this after the Certificates Section -->
<!-- Certificate Files Section -->
<?php 
$has_certificate_files = !empty($student['internship_certificates_path']) || 
                        !empty($student['course_certificates_path']) || 
                        !empty($student['achievement_certificates_path']);
if ($has_certificate_files): 
?>
<div class="neu-card content-card mb-4">
    <div class="neu-card-header">
        <h5 class="mb-0"><i class="fas fa-file-pdf me-2"></i> Certificate Files</h5>
    </div>
    <div class="neu-card-body">
        <div class="certificate-files-grid">
            <?php if (!empty($student['internship_certificates_path'])): ?>
                <div class="certificate-file-card">
                    <div class="file-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <div class="file-info">
                        <h6>Internship Certificates</h6>
                        <p>Combined PDF of all internship certificates</p>
                    </div>
                    <div class="file-actions">
                        <a href="<?php echo htmlspecialchars($student['internship_certificates_path']); ?>?v=<?php echo time(); ?>" 
                           target="_blank" 
                           class="view-file-btn">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($student['course_certificates_path'])): ?>
                <div class="certificate-file-card">
                    <div class="file-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div class="file-info">
                        <h6>Course Certificates</h6>
                        <p>Combined PDF of all course completion certificates</p>
                    </div>
                    <div class="file-actions">
                        <a href="<?php echo htmlspecialchars($student['course_certificates_path']); ?>?v=<?php echo time(); ?>" 
                           target="_blank" 
                           class="view-file-btn">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($student['achievement_certificates_path'])): ?>
                <div class="certificate-file-card">
                    <div class="file-icon">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="file-info">
                        <h6>Achievement Certificates</h6>
                        <p>Combined PDF of hackathons, events, and other achievements</p>
                    </div>
                    <div class="file-actions">
                        <a href="<?php echo htmlspecialchars($student['achievement_certificates_path']); ?>?v=<?php echo time(); ?>" 
                           target="_blank" 
                           class="view-file-btn">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
:root {
    --primary-color: #4e73df;  
    --primary-light: #7591e6;
    --primary-hover: #3a5ecc;
    --success-color: #1cc88a;
    --danger-color: #e74a3b;
    --warning-color: #f6c23e;
    --info-color: #36b9cc;
    --text-color: #2c3e50;
    --text-secondary: #5a6a85;
    --bg-color: #e0e5ec;
    --card-bg: #e8ecf2;
    --shadow: 9px 9px 16px rgb(163,177,198,0.6), 
             -9px -9px 16px rgba(255,255,255, 0.5);
    --soft-shadow: 5px 5px 10px rgb(163,177,198,0.4), 
                  -5px -5px 10px rgba(255,255,255, 0.4);
    --inner-shadow: inset 6px 6px 10px 0 rgba(0, 0, 0, 0.1),
                   inset -6px -6px 10px 0 rgba(255, 255, 255, 0.8);
    --transition-speed: 0.3s;
    --border-radius: 16px;
    --small-radius: 12px;
}

body {
    background: var(--bg-color);
    color: var(--text-color);
}

.container {
    padding: 2rem;
}

/* Back Button Fixed */
.back-btn {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1000;
    padding: 0.6rem 1rem;
    box-shadow: var(--shadow);
    border-radius: 30px;
    font-size: 0.9rem;
    transition: all 0.3s;
}

.back-btn:hover {
    transform: translateX(-5px);
}

/* Profile Header Redesign */
.profile-header {
    margin-bottom: 2rem;
    margin-top: 1rem;
}

.profile-info-wrapper {
    display: flex;
    align-items: flex-start;
    padding: 1.5rem;
    background: var(--card-bg);
    border-radius: var(--border-radius);
    box-shadow: var(--soft-shadow);
}

.profile-image-container {
    width: 150px;
    height: 150px;
    margin-right: 2rem;
    flex-shrink: 0;
    position: relative;
    border: 1px solid rgba(0,0,0,0.1);
}

.profile-image {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    object-fit: cover;
    box-shadow: var(--shadow);
    background: var(--bg-color);
    border: 1px solid rgba(0,0,0,0.1);
}

.profile-avatar {
    width: 100%;
    height: 100%;
    border-radius: 50%;
    background: var(--primary-color);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    font-weight: 500;
    box-shadow: var(--shadow);
    text-transform: uppercase;
}

.profile-info {
    flex: 1;
}

.profile-name {
    font-size: 2.2rem;
    font-weight: 600;
    color: var(--text-color);
    margin-bottom: 0.5rem;
}

.profile-headline {
    font-size: 1.3rem;
    color: var(--text-secondary);
    margin-bottom: 1rem;
}

.profile-details {
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.profile-detail-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-secondary);
    font-size: 1rem;
}

.profile-detail-item i {
    color: var(--primary-color);
    font-size: 1.1rem;
    margin-right: 0.7rem;
}

.profile-status {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-top: 0.5rem;
}

.contact-btn {
    padding: 0.6rem 1.2rem;
}

/* Responsive adjustments */
@media (max-width: 767px) {
    .profile-info-wrapper {
        flex-direction: column;
        align-items: center;
        text-align: center;
    }

    .profile-image-container {
        margin-right: 0;
        margin-bottom: 1.5rem;
        width: 120px;
        height: 120px;
    }

    .profile-avatar {
        font-size: 2.5rem;
    }
}

/* Profile Info Grid */
.profile-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

/* Mini Cards */
.neu-card.mini-card {
    margin-bottom: 0.5rem;
    border-radius: var(--small-radius);
    overflow: hidden;
    box-shadow: var(--soft-shadow);
    transition: all 0.3s;
    height: 100%;
}

.neu-card.mini-card:hover {
    box-shadow: var(--shadow);
    transform: translateY(-3px);
}

.mini-card-header {
    background: var(--primary-color);
    color: white;
    padding: 0.7rem 1rem;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.mini-card-header h5 {
    margin: 0;
    font-weight: 600;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.mini-card-body {
    padding: 1rem;
}

/* Success header for placement card */
.success-header {
    background: var(--success-color);
}

/* Info Items in Mini Cards */
.info-item {
    display: flex;
    flex-direction: column;
    margin-bottom: 0.8rem;
}

.info-item:last-child {
    margin-bottom: 0;
}

.info-label {
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-bottom: 0.2rem;
    font-weight: 500;
}

.info-value {
    font-size: 0.9rem;
    color: var(--text-color);
}

/* Looking for badge */
.looking-for-badge {
    background: rgba(54, 185, 204, 0.1);
    color: var(--info-color);
    padding: 0.6rem 0.8rem;
    border-radius: 8px;
    font-weight: 500;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.relocation-badge {
    background: rgba(78, 115, 223, 0.1);
    color: var(--primary-color);
    font-size: 0.8rem;
    padding: 0.4rem 0.6rem;
    border-radius: 30px;
    display: inline-flex;
    align-items: center;
}

/* Contact link */
.contact-link {
    color: var(--primary-color);
    text-decoration: none;
    transition: color 0.2s;
}

.contact-link:hover {
    color: var(--primary-hover);
    text-decoration: underline;
}

/* Social Links */
.social-links-container {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.6rem;
}

.social-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.6rem 0.5rem;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
    transition: all var(--transition-speed) ease;
    box-shadow: var(--soft-shadow);
}

.social-link.linkedin {
    background-color: rgba(0, 119, 181, 0.1);
    color: #0077b5;
}

.social-link.github {
    background-color: rgba(36, 41, 46, 0.1);
    color: #24292e;
}

.social-link.portfolio {
    background-color: rgba(44, 62, 80, 0.1);
    color: #2c3e50;
}

.social-link.resume {
    background-color: rgba(234, 67, 53, 0.1);
    color: #ea4335;
}

.social-link:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow);
}

/* Skills Badges */
.skill-category-title {
    font-size: 0.8rem;
    color: var(--text-secondary);
    margin-bottom: 0.8rem;
    font-weight: 500;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}

.skills-container {
    display: flex;
    flex-wrap: wrap;
    gap: 0.6rem;
    margin-bottom: 1rem;
}

.skill-badge {
    display: inline-flex;
    align-items: center;
    background: var(--bg-color);
    padding: 0.4rem 0.8rem;
    border-radius: 30px;
    box-shadow: var(--soft-shadow);
    font-size: 0.8rem;
    transition: all 0.2s ease;
    position: relative;
    overflow: hidden;
}

.skill-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.skill-badge.top-skill {
    background: linear-gradient(135deg, rgba(78, 115, 223, 0.1) 0%, rgba(78, 115, 223, 0.2) 100%);
    color: var(--primary-color);
    font-weight: 500;
    border-left: 3px solid var(--primary-color);
}

.skill-name {
    position: relative;
    z-index: 2;
}

.endorsement-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.5);
    color: var(--text-secondary);
    font-size: 0.7rem;
    min-width: 1.2rem;
    height: 1.2rem;
    padding: 0 0.3rem;
    border-radius: 20px;
    margin-left: 0.4rem;
    font-weight: 600;
    position: relative;
    z-index: 2;
}

.skill-badge:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(255, 255, 255, 0);
    transition: all 0.3s ease;
    z-index: 1;
}

.skill-badge:hover:before {
    background: rgba(255, 255, 255, 0.2);
}

/* Neumorphic Card */
.neu-card {
    background: var(--card-bg);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    border: none;
    overflow: hidden;
    margin-bottom: 1.5rem;
    transition: all var(--transition-speed) ease;
}

.neu-card-header {
    background: var(--primary-color);
    color: white;
    padding: 1rem 1.2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: none;
}

/* Experience and Education Headers */
.content-card.mb-4 .neu-card-header {
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
    border-top-left-radius: var(--border-radius);
    border-top-right-radius: var(--border-radius);
}

.neu-card-header h5, .neu-card-header h4 {
    margin: 0;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem; /* Adds consistent spacing between icon and text */
}

.neu-card-body {
    padding: 1.2rem;
}

/* Timeline */
.timeline {
    position: relative;
}

.timeline-item {
    display: flex;
    margin-bottom: 1.5rem;
}

.timeline-marker {
    position: relative;
    width: 30px;
    margin-right: 1rem;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.timeline-dot {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: var(--primary-color);
    box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1);
    z-index: 1;
}

.timeline-line {
    position: absolute;
    top: 16px;
    width: 2px;
    height: calc(100% + 1.5rem);
    background: rgba(78, 115, 223, 0.2);
}

.timeline-content {
    flex: 1;
    background: var(--bg-color);
    border-radius: 12px;
    padding: 1rem;
    box-shadow: var(--soft-shadow);
}

.timeline-header {
    margin-bottom: 0.8rem;
}

.timeline-title {
    font-weight: 600;
    margin-bottom: 0.4rem;
    font-size: 1.1rem;
}

.timeline-subtitle {
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.9rem;
}

.timeline-period {
    color: var(--text-secondary);
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
}

.timeline-description {
    color: var(--text-color);
    line-height: 1.5;
    font-size: 0.9rem;
}

.timeline-activities, .timeline-skills {
    margin-top: 1rem;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.field-of-study, .location, .employment-type {
    font-size: 0.85rem;
    opacity: 0.8;
    margin-left: 0.5rem;
}

.current-badge, .grade-badge {
    display: inline-flex;
    align-items: center;
    background: rgba(78, 115, 223, 0.1);
    color: var(--primary-color);
    padding: 0.2rem 0.5rem;
    border-radius: 30px;
    font-size: 0.75rem;
    font-weight: 500;
}

.skill-tag, .tech-tag {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.5);
    padding: 0.3rem 0.6rem;
    border-radius: 30px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin-right: 0.4rem;
    margin-bottom: 0.4rem;
}

/* Projects */
.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1rem;
}

.project-card {
    background: var(--bg-color);
    border-radius: 12px;
    padding: 1rem;
    box-shadow: var(--soft-shadow);
    transition: transform 0.3s, box-shadow 0.3s;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.project-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow);
}

.project-header {
    margin-bottom: 0.8rem;
}

.project-title {
    font-weight: 600;
    margin-bottom: 0.4rem;
    font-size: 1rem;
}

.project-period {
    color: var(--text-secondary);
    font-size: 0.8rem;
}

.project-description {
    color: var(--text-color);
    line-height: 1.5;
    margin-bottom: 0.8rem;
    flex: 1;
    font-size: 0.9rem;
}

.project-technologies {
    margin-bottom: 1rem;
}

.project-technologies h6 {
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
    color: var(--text-secondary);
}

.tech-tags {
    display: flex;
    flex-wrap: wrap;
}

.project-links {
    display: flex;
    margin-top: auto;
    gap: 0.6rem;
}

.project-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.4rem 0.8rem;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: var(--soft-shadow);
}

.project-link.demo {
    background: rgba(78, 115, 223, 0.1);
    color: var(--primary-color);
}

.project-link.github {
    background: rgba(36, 41, 46, 0.1);
    color: #24292e;
}

.project-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

/* Contact Action Card */
.contact-action-card {
    background: linear-gradient(135deg, var(--card-bg) 0%, #f0f3f8 100%);
}

.contact-card-content {
    display: flex;
    align-items: center;
}

.contact-card-icon {
    width: 60px;
    height: 60px;
    background: var(--primary-color);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    color: white;
    margin-right: 1.5rem;
    box-shadow: 0 8px 20px rgba(78, 115, 223, 0.3);
}

.contact-card-text {
    flex: 1;
}

.contact-card-text h5 {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.contact-card-text p {
    color: var(--text-secondary);
    margin-bottom: 1rem;
}

.contact-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.8rem;
}

/* Status Badges */
.status-badge {
    padding: 0.4rem 0.8rem;
    border-radius: 30px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.status-badge.placed {
    background: var(--success-color);
    color: white;
}

.status-badge.in-progress {
    background: var(--info-color);
    color: white;
}

.status-badge.not-interested {
    background: #858796;
    color: white;
}

.status-badge.available {
    background: var(--primary-color);
    color: white;
}

/* Buttons */
.neu-btn {
    background: var(--bg-color);
    border: none;
    border-radius: 12px;
    padding: 0.7rem 1.2rem;
    color: var(--text-color);
    box-shadow: var(--soft-shadow);
    transition: all var(--transition-speed) cubic-bezier(0.175, 0.885, 0.32, 1.275);
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
    font-weight: 500;
    position: relative;
    overflow: hidden;
    z-index: 1;
    cursor: pointer;
    font-size: 0.9rem;
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
    color: var(--text-color);
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
    color: white;
}

.neu-btn-secondary {
    background: var(--bg-color);
    color: var(--text-color);
}

.neu-btn-success {
    background: var(--success-color);
    color: white;
}

.neu-btn-success:hover {
    background: #169b6b;
    color: white;
}

/* Content text */
.content-text {
    line-height: 1.6;
    color: var(--text-color);
    font-size: 0.95rem;
}

/* Responsive */
@media (max-width: 991px) {
    .content-card {
        max-width: 100%;
        margin: 1rem 0;
        border-radius: 0;
    }
    
    .education-content.modern-timeline-content {
        padding: 1rem;
    }
    
    .education-content .timeline-description,
    .timeline-activities {
        padding: 0.8rem;
        margin: 0.8rem 0;
    }
    
    .education-content {
        width: calc(100% - 30px); /* Smaller timeline marker on mobile */
    }

    .projects-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
    
    .profile-info-grid {
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    }

    .modern-timeline,
    .about-section,
    .career-objective-section,
    .projects-grid,
    .certificates-grid,
    .certificate-files-grid {
        width: 100%;
        padding: 1rem;
    }

    .modern-timeline .timeline-description {
        max-width: 100%;
    }

    /* Adjust grid layouts for better mobile view */
    .projects-grid,
    .certificates-grid,
    .certificate-files-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
    }

    /* Remove inner card shadows on mobile */
    .content-card .neu-card-body {
        box-shadow: none;
    }
    
    /* Smaller font sizes for about and career objective on tablet */
    .about-section .content-text,
    .career-objective-section .content-text {
        font-size: 0.85rem;
        line-height: 1.5;
    }
    
    .about-section .content-text::first-letter,
    .career-objective-section .content-text::first-letter {
        font-size: 1.2rem;
    }
}

@media (min-width: 1400px) {
    .content-card {
        max-width: 1000px;
    }

    .modern-timeline,
    .about-section,
    .career-objective-section,
    .projects-grid,
    .certificates-grid,
    .certificate-files-grid {
        width: 100%;
    }

    /* Adjust grid layouts for larger screens */
    .projects-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .certificates-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    .certificate-files-grid {
        grid-template-columns: repeat(3, 1fr);
    }

    /* Enhanced shadow for larger screens */
    .content-card {
        box-shadow: var(--shadow),
                   0 10px 30px rgba(0, 0, 0, 0.05);
    }
}

@media (max-width: 767px) {
    .profile-avatar {
        width: 70px;
        height: 70px;
    }
    
    .profile-name {
        font-size: 1.5rem;
    }
    
    .contact-card-content {
        flex-direction: column;
        text-align: center;
    }
    
    .contact-card-icon {
        margin-right: 0;
        margin-bottom: 1rem;
    }
    
    .social-links-container {
        grid-template-columns: 1fr;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-info-grid {
        grid-template-columns: 1fr;
    }
    
    .back-btn {
        top: 10px;
        left: 10px;
        padding: 0.4rem 0.8rem;
        font-size: 0.8rem;
    }
    
    /* Even smaller font sizes for about and career objective on mobile */
    .about-section .content-text,
    .career-objective-section .content-text {
        font-size: 0.8rem;
        line-height: 1.4;
        letter-spacing: 0.01em;
    }
    
    .about-section .content-text::first-letter,
    .career-objective-section .content-text::first-letter {
        font-size: 1.1rem;
    }
}

/* Add this to the existing styles */
.content-card {
    border-radius: var(--small-radius);
}

.content-card .neu-card-header {
    padding: 0.9rem 1.2rem;
}

.content-card .neu-card-header h5 {
    font-size: 1.1rem;
}

.content-card .neu-card-body {
    padding: 1.2rem;
}

/* Enhanced About Section */
.about-section {
    position: relative;
    border-left: 3px solid rgba(78, 115, 223, 0.2);
    padding-left: 1.2rem;
    margin-left: 0.5rem;
}

.about-section .content-text {
    font-size: 0.9rem;
    line-height: 1.6;
    color: var(--text-color);
    text-align: justify;
    letter-spacing: 0.02em;
}

.about-section .content-text::first-letter {
    font-size: 1.3rem;
    font-weight: 500;
    color: var(--primary-color);
}

/* Career Objective Section - adding specific styles */
.career-objective-section .content-text {
    font-size: 0.9rem;
    line-height: 1.6;
    color: var(--text-color);
    text-align: justify;
    letter-spacing: 0.02em;
}

.career-objective-section .content-text::first-letter {
    font-size: 1.3rem;
    font-weight: 500;
    color: var(--primary-color);
}

/* Enhanced Timeline Styles */
.modern-timeline {
    position: relative;
    padding-left: 0.5rem;
}

.modern-timeline-item {
    margin-bottom: 2rem;
    position: relative;
}

.modern-timeline-item:last-child {
    margin-bottom: 0;
}

.modern-timeline .timeline-marker {
    position: relative;
    width: 24px;
    margin-right: 1.2rem;
}

.modern-timeline .timeline-dot {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-light) 100%);
    box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1), 0 4px 8px rgba(0,0,0,0.1);
    z-index: 1;
    transition: all 0.3s ease;
}

.modern-timeline .timeline-line {
    position: absolute;
    top: 18px;
    left: 9px;
    width: 2px;
    height: calc(100% + 2rem);
    background: linear-gradient(to bottom, rgba(78, 115, 223, 0.3) 0%, rgba(78, 115, 223, 0.1) 100%);
}

.modern-timeline-content {
    flex: 1;
    background: var(--card-bg);
    border-radius: 12px;
    padding: 1.2rem;
    box-shadow: var(--soft-shadow);
    transition: all 0.3s ease;
    border-left: 3px solid var(--primary-color);
}

.modern-timeline-content:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.08);
}

.modern-timeline .timeline-header {
    margin-bottom: 1rem;
    position: relative;
}

.modern-timeline .timeline-title {
    font-weight: 600;
    margin-bottom: 0.6rem;
    font-size: 1.15rem;
    color: var(--primary-color);
}

.modern-timeline .timeline-subtitle {
    color: var(--text-secondary);
    margin-bottom: 0.8rem;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.95rem;
    font-weight: 500;
}

.modern-timeline .timeline-period {
    color: var(--text-secondary);
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    background: rgba(78, 115, 223, 0.05);
    padding: 0.3rem 0.8rem;
    border-radius: 30px;
    font-weight: 500;
}

.modern-timeline .timeline-description {
    color: var(--text-color);
    line-height: 1.6;
    font-size: 0.95rem;
    margin-top: 0.8rem;
    margin-bottom: 0.8rem;
    text-align: justify;
    max-width: 800px;
}

.modern-timeline .timeline-skills {
    margin-top: 1rem;
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.modern-timeline .skill-tag {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.7);
    padding: 0.3rem 0.7rem;
    border-radius: 30px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    box-shadow: var(--soft-shadow);
    transition: all 0.2s ease;
}

.modern-timeline .skill-tag:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.08);
    background: rgba(78, 115, 223, 0.1);
    color: var(--primary-color);
}

.modern-timeline .location,
.modern-timeline .employment-type {
    font-size: 0.85rem;
    opacity: 0.9;
    margin-left: 0.7rem;
}

.modern-timeline .current-badge {
    display: inline-flex;
    align-items: center;
    background: rgba(28, 200, 138, 0.1);
    color: var(--success-color);
    padding: 0.2rem 0.5rem;
    border-radius: 30px;
    font-size: 0.75rem;
    font-weight: 500;
}

@media (max-width: 767px) {
    .modern-timeline .timeline-content {
        padding: 1rem;
    }
    
    .about-section {
        padding-left: 0.8rem;
        margin-left: 0.3rem;
    }
}

/* Icon spacing improvements */
.fas:not(.user-avatar .fas), 
.fab:not(.user-avatar .fab), 
.far:not(.user-avatar .far) {
    margin-right: 0.5rem; /* Global spacing for all icons except user avatar */
}

/* Timeline period icon spacing */
.timeline-period i, 
.project-period i,
.modern-timeline .timeline-period i {
    margin-right: 0.7rem; /* Increased spacing */
}

/* Location icon specific spacing */
.location i, 
.modern-timeline .location i {
    margin-left: 0.7rem !important;
    margin-right: 0.7rem !important;
}

/* Status badge icon spacing */
.status-badge i {
    margin-right: 0.6rem;
}

/* Adjust spacing in timeline items */
.modern-timeline .timeline-title {
    font-weight: 600;
    margin-bottom: 0.6rem; /* Increased spacing */
    font-size: 1.15rem;
    color: var(--primary-color);
}

.modern-timeline .timeline-subtitle {
    color: var(--text-secondary);
    margin-bottom: 0.8rem; /* Increased spacing */
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem;
    font-size: 0.95rem;
    font-weight: 500;
}

/* Skill tag and technology tag spacing */
.skill-tag i,
.tech-tag i {
    margin-right: 0.5rem;
}

/* Card headers icon spacing */
.neu-card-header h5 i,
.mini-card-header h5 i {
    margin-right: 0rem; /* Increased spacing */
}

/* Fix multiple display declaration in timeline period */
.modern-timeline .timeline-period {
    color: var(--text-secondary);
    font-size: 0.85rem;
    display: inline-flex;
    align-items: center;
    background: rgba(78, 115, 223, 0.05);
    padding: 0.3rem 0.8rem; /* Increased horizontal padding */
    border-radius: 30px;
    font-weight: 500;
}

/* Employment type and badges spacing */
.employment-type, 
.modern-timeline .employment-type {
    margin-left: 0.7rem;
}

.current-badge i,
.grade-badge i {
    margin-right: 0.4rem;
}

/* Project technology section */
.project-technologies h6 i {
    margin-right: 0.6rem;
}

/* Education Section Enhancements */
.education-dot {
    background: linear-gradient(135deg, #36b9cc 0%, #4e73df 100%);
}

.education-content {
    border-left: 3px solid #36b9cc;
    width: calc(100% - 40px); /* Account for timeline marker width */
}

/* Education Timeline Specific Styles */
.timeline-activities {
    background: rgba(54, 185, 204, 0.05);
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
    width: 100%;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

.timeline-activities strong {
    color: #36b9cc;
    display: block;
    margin-bottom: 0.5rem;
}

.education-content .timeline-description {
    background: rgba(78, 115, 223, 0.05);
    border-radius: 8px;
    padding: 1rem;
    margin: 1rem 0;
}

/* Handle long institution names */
.education-content .timeline-title {
    word-wrap: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
    line-height: 1.4;
}

/* Project Section Enhancements */
.modern-project-card {
    position: relative;
    border-radius: 12px;
    transition: all 0.3s ease;
    border-top: 3px solid var(--primary-color);
    overflow: hidden;
}

.modern-project-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 20px rgba(0,0,0,0.08);
}

.modern-project-card .project-header {
    background: rgba(78, 115, 223, 0.05);
    margin: -1rem -1rem 1rem -1rem;
    padding: 1rem;
    border-bottom: 1px solid rgba(78, 115, 223, 0.1);
}

.modern-project-card .project-title {
    color: var(--primary-color);
    font-weight: 600;
    font-size: 1.1rem;
}

.modern-project-card .project-period {
    display: inline-flex;
    align-items: center;
    background: rgba(255, 255, 255, 0.5);
    padding: 0.3rem 0.8rem;
    border-radius: 30px;
    margin-top: 0.5rem;
}

.modern-project-card .project-description {
    font-size: 0.9rem;
    line-height: 1.6;
    color: var(--text-color);
    padding-bottom: 0.8rem;
    border-bottom: 1px dashed rgba(78, 115, 223, 0.1);
    margin-bottom: 1rem;
    text-align: justify;
}

.modern-project-card .project-technologies h6 {
    color: var(--primary-color);
    font-weight: 600;
    margin-bottom: 0.8rem;
    display: flex;
    align-items: center;
}

.modern-project-card .tech-tags {
    margin-bottom: 1rem;
}

.modern-project-card .tech-tag {
    display: inline-flex;
    background: rgba(255, 255, 255, 0.6);
    padding: 0.3rem 0.7rem;
    border-radius: 30px;
    font-size: 0.75rem;
    color: var(--text-secondary);
    margin: 0 0.3rem 0.5rem 0;
    box-shadow: var(--soft-shadow);
    transition: all 0.2s ease;
}

.modern-project-card .tech-tag:hover {
    transform: translateY(-2px);
    background: rgba(78, 115, 223, 0.1);
    color: var(--primary-color);
}

.modern-project-card .project-links {
    margin-top: auto;
}

.modern-project-card .project-link {
    position: relative;
    overflow: hidden;
    z-index: 1;
}

.modern-project-card .project-link:before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 0;
    height: 100%;
    background: rgba(255, 255, 255, 0.2);
    transition: all 0.3s ease;
    z-index: -1;
}

.modern-project-card .project-link:hover:before {
    width: 100%;
}

/* Matching the spacing in education timeline to match work experience */
.timeline-activities {
    margin: 0.8rem 0;
    padding: 0.7rem;
    background: rgba(255, 255, 255, 0.5);
    border-radius: 8px;
    font-size: 0.9rem;
}

.timeline-activities strong {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--primary-color);
    margin-bottom: 0.4rem;
}

/* Dynamic Content Card Sizing */
.content-card {
    display: flex;
    flex-direction: column;
    height: auto;
    min-height: 100px;
    transition: all 0.3s ease;
    margin-bottom: 1.5rem;
    max-width: 900px;
    margin-left: auto;
    margin-right: auto;
    width: 100%;
    background: var(--card-bg);
    border-radius: var(--border-radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.content-card .neu-card-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: visible;
}

/* About Section Dynamic Sizing */
.about-section, 
.career-objective-section {
    height: auto;
    min-height: 50px;
    max-height: none;
    overflow: visible;
    max-width: 900px;
    margin: 0 auto;
    padding: 1.2rem;
}

/* Content width control for all major sections */
.content-card .neu-card-body {
    width: 100%;
    position: relative;
    background: var(--card-bg);
}

/* Projects Grid Container */
.projects-grid {
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
}

/* Certificates Grid Container */
.certificates-grid {
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
}

/* Certificate Files Grid Container */
.certificate-files-grid {
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
}

/* Timeline Flexible Layout */
.modern-timeline {
    display: flex;
    flex-direction: column;
    flex-wrap: nowrap;
    height: auto;
    max-width: 900px;
    margin: 0 auto;
    width: 100%;
    overflow: hidden;
}

.modern-timeline-item {
    display: flex;
    width: 100%;
    position: relative;
    overflow: visible;
}

.modern-timeline-item {
    width: 100%;
    height: auto;
}

.modern-timeline-content {
    height: auto;
    min-height: 50px;
    max-height: fit-content;
    display: flex;
    flex-direction: column;
    padding: 1.2rem;
    width: 100%;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

/* Education specific timeline content */
.education-content.modern-timeline-content {
    max-width: 100%;
    overflow: hidden;
}

.education-content .timeline-description {
    width: 100%;
    max-width: 100%;
    word-wrap: break-word;
    overflow-wrap: break-word;
    white-space: normal;
    text-align: left;
    padding-right: 1rem;
}

/* Projects Grid Flexible Layout */
.projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.2rem;
    grid-auto-rows: minmax(100px, auto);
}

.modern-project-card {
    height: auto;
    min-height: 200px;
    display: flex;
    flex-direction: column;
}

.modern-project-card .project-description {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: initial;
    -webkit-box-orient: vertical;
}

/* Dynamic height adjustments based on content */
@media (min-width: 992px) {
    .equal-height-cards {
        display: flex;
        flex-wrap: wrap;
    }
    
    .equal-height-cards > div {
        display: flex;
        flex-direction: column;
    }
    
    .equal-height-cards .content-card {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
}

/* Adjust card sizing for various content lengths */
.content-text.short {
    min-height: 50px;
}

.content-text.medium {
    min-height: 100px;
}

.content-text.long {
    min-height: 150px;
}

/* Ensure project cards adjust properly */
@media (min-width: 768px) {
    .projects-grid {
        grid-auto-flow: dense;
    }
    
    .modern-project-card.featured {
        grid-column: span 2;
        grid-row: span 2;
    }
}

/* Apply min/max height constraints when needed */
@media (max-width: 767px) {
    .modern-project-card {
        min-height: 100px;
        max-height: none;
    }
    
    .projects-grid {
        grid-template-columns: 1fr;
    }
}

/* Timeline responsiveness improvements */
@media (max-width: 576px) {
    .modern-timeline .timeline-marker {
        width: 20px;
        margin-right: 0.8rem;
    }
    
    .modern-timeline-content {
        padding: 1rem;
    }
}

/* Certificates Section Styles */
.certificates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.2rem;
    padding: 0.5rem;
}

.modern-certificate-card {
    background: var(--bg-color);
    border-radius: 12px;
    box-shadow: var(--soft-shadow);
    transition: all 0.3s ease;
    border-top: 3px solid var(--primary-color);
    overflow: hidden;
}

.modern-certificate-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow);
}

.certificate-header {
    background: rgba(78, 115, 223, 0.05);
    padding: 1rem;
    border-bottom: 1px solid rgba(78, 115, 223, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.certificate-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--primary-color);
    margin: 0;
}

.verified-badge {
    display: inline-flex;
    align-items: center;
    background: rgba(28, 200, 138, 0.1);
    color: var(--success-color);
    padding: 0.3rem 0.6rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.certificate-body {
    padding: 1rem;
}

.issuer {
    color: var(--text-color);
    font-weight: 500;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.cert-dates {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-bottom: 1rem;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.cert-dates i {
    width: 20px;
    color: var(--primary-color);
}

.credential-id {
    background: rgba(78, 115, 223, 0.05);
    padding: 0.5rem;
    border-radius: 8px;
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.cert-description {
    font-size: 0.9rem;
    line-height: 1.6;
    color: var(--text-color);
    border-top: 1px dashed rgba(78, 115, 223, 0.1);
    margin-top: 1rem;
    padding-top: 1rem;
}

.certificate-footer {
    padding: 1rem;
    background: rgba(78, 115, 223, 0.02);
    border-top: 1px solid rgba(78, 115, 223, 0.1);
    text-align: center;
}

.verify-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--primary-color);
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.verify-btn:hover {
    background: var(--primary-hover);
    transform: translateY(-2px);
    color: white;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .certificates-grid {
        grid-template-columns: 1fr;
    }
    
    .cert-dates {
        flex-direction: column;
    }
}

/* Certificate Files Section Styles */
.certificate-files-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.2rem;
    padding: 0.5rem;
}

.certificate-file-card {
    background: var(--bg-color);
    border-radius: 12px;
    padding: 1.2rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
    box-shadow: var(--soft-shadow);
}

.certificate-file-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow);
}

.file-icon {
    width: 50px;
    height: 50px;
    background: rgba(78, 115, 223, 0.1);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: var(--primary-color);
    flex-shrink: 0;
}

.file-info {
    flex: 1;
}

.file-info h6 {
    margin: 0 0 0.3rem 0;
    font-weight: 600;
    color: var(--text-color);
}

.file-info p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.file-actions {
    flex-shrink: 0;
}

.view-file-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--primary-color);
    color: white;
    border-radius: 8px;
    text-decoration: none;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.view-file-btn:hover {
    background: var(--primary-hover);
    transform: translateY(-2px);
    color: white;
}

@media (max-width: 768px) {
    .certificate-files-grid {
        grid-template-columns: 1fr;
    }
    
    .certificate-file-card {
        flex-direction: row;
        align-items: center;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add animation on page load
    const fadeInElements = [
        document.querySelector('.profile-header'),
        ...document.querySelectorAll('.neu-card'),
        ...document.querySelectorAll('.mini-card'),
        document.querySelector('.back-btn')
    ];
    
    fadeInElements.forEach((element, index) => {
        if (element) {
            element.style.opacity = '0';
            element.style.transform = 'translateY(20px)';
            setTimeout(() => {
                element.style.transition = 'all 0.4s ease';
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, 100 + (index * 40));
        }
    });
    
    // Add cache-busting to all file links
    document.querySelectorAll('a[href$=".pdf"], a[href$=".doc"], a[href$=".docx"], a[href$=".jpg"], a[href$=".jpeg"], a[href$=".png"]').forEach(link => {
        const currentHref = link.getAttribute('href');
        if (currentHref && !currentHref.includes('?v=')) {
            link.setAttribute('href', currentHref + '?v=' + new Date().getTime());
        }
    });
    
    // Hover effects for cards
    const miniCards = document.querySelectorAll('.mini-card');
    miniCards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-3px)';
            this.style.boxShadow = 'var(--shadow)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'var(--soft-shadow)';
        });
    });
    
    // Function to adjust card heights dynamically
    function adjustCardHeights() {
        // Add content-length classes based on text length
        document.querySelectorAll('.content-text').forEach(element => {
            const textLength = element.textContent.trim().length;
            if (textLength < 100) {
                element.classList.add('short');
            } else if (textLength < 500) {
                element.classList.add('medium');
            } else {
                element.classList.add('long');
            }
        });
        
        // Mark featured (longer) project cards
        document.querySelectorAll('.modern-project-card').forEach(card => {
            const descEl = card.querySelector('.project-description');
            if (descEl && descEl.textContent.trim().length > 300) {
                card.classList.add('featured');
            }
        });
    }
    
    // Call the function after page loads
    adjustCardHeights();
    
    // Optional: Re-adjust on window resize
    window.addEventListener('resize', adjustCardHeights);
});
</script>

<?php include 'footer.php'; ?> 