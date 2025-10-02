<?php
/**
 * Helper functions for feedback section visibility control
 */

/**
 * Check if a feedback section is enabled for a specific student
 */
function isFeedbackSectionEnabled($pdo, $section_name, $student_data, $current_academic_year_id) {
    try {
        $query = "SELECT COUNT(*) as count
                  FROM feedback_section_controls fsc
                  WHERE fsc.section_name = ?
                  AND fsc.is_enabled = TRUE
                  AND (fsc.academic_year_id IS NULL OR fsc.academic_year_id = ?)
                  AND (fsc.department_id IS NULL OR fsc.department_id = ?)
                  AND (fsc.batch_id IS NULL OR fsc.batch_id = ?)
                  AND (fsc.year_of_study IS NULL OR fsc.year_of_study = ?)
                  AND (fsc.semester IS NULL OR fsc.semester = ?)
                  AND (fsc.start_date IS NULL OR fsc.start_date <= CURDATE())
                  AND (fsc.end_date IS NULL OR fsc.end_date >= CURDATE())";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $section_name,
            $current_academic_year_id,
            $student_data['department_id'],
            $student_data['batch_id'],
            $student_data['current_year_of_study'],
            $student_data['current_semester']
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
        
    } catch (PDOException $e) {
        error_log("Error checking section visibility: " . $e->getMessage());
        // Default to enabled if there's an error
        return true;
    }
}

/**
 * Get all enabled sections for a student
 */
function getEnabledSectionsForStudent($pdo, $student_data, $current_academic_year_id) {
    $sections = [
        'regular_feedback',
        'class_committee_feedback', 
        'exit_survey',
        'feedback_history',
        'non_academic_feedback',
        'examination_feedback'
    ];
    
    $enabled_sections = [];
    
    foreach ($sections as $section) {
        if (isFeedbackSectionEnabled($pdo, $section, $student_data, $current_academic_year_id)) {
            $enabled_sections[] = $section;
        }
    }
    
    return $enabled_sections;
}

/**
 * Check if exit survey should be shown (with additional logic)
 */
function shouldShowExitSurvey($pdo, $student_data, $current_academic_year_id) {
    // First check if exit survey section is enabled
    if (!isFeedbackSectionEnabled($pdo, 'exit_survey', $student_data, $current_academic_year_id)) {
        return false;
    }
    
    // Then check if student is eligible (final year, final semester)
    return ($student_data['current_year_of_study'] == 4 && $student_data['current_semester'] == 8);
}

/**
 * Get section configuration details
 */
function getSectionConfig($pdo, $section_name) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM feedback_section_controls WHERE section_name = ? LIMIT 1");
        $stmt->execute([$section_name]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting section config: " . $e->getMessage());
        return null;
    }
}
?>