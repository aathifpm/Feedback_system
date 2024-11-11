<?php
include 'functions.php';

function promote_students() {
    global $conn;
    
    // Start transaction
    mysqli_begin_transaction($conn);
    
    try {
        // Get current academic year
        $current_year = get_current_academic_year();
        
        // Update student years and semesters
        $query = "UPDATE students 
                 SET current_year = CASE 
                     WHEN current_year < 4 THEN current_year + 1 
                     ELSE current_year 
                     END,
                 current_semester = CASE 
                     WHEN current_semester < 8 THEN current_semester + 1 
                     ELSE current_semester 
                     END
                 WHERE is_active = TRUE AND current_year < 4";
        mysqli_query($conn, $query);
        
        // Deactivate students who completed 4 years
        $query = "UPDATE students 
                 SET is_active = FALSE 
                 WHERE current_year = 4 
                 AND current_semester = 8";
        mysqli_query($conn, $query);
        
        // Update academic years
        $query = "UPDATE academic_years SET is_current = FALSE";
        mysqli_query($conn, $query);
        
        $new_start_year = $current_year['start_year'] + 1;
        $new_end_year = $current_year['end_year'] + 1;
        $new_year_range = substr($new_start_year, -2) . '-' . substr($new_end_year, -2);
        
        $query = "INSERT INTO academic_years (year_range, start_year, end_year, is_current) 
                 VALUES (?, ?, ?, TRUE) 
                 ON DUPLICATE KEY UPDATE is_current = TRUE";
        $stmt = mysqli_prepare($conn, $query);
        mysqli_stmt_bind_param($stmt, "sii", $new_year_range, $new_start_year, $new_end_year);
        mysqli_stmt_execute($stmt);
        
        mysqli_commit($conn);
        return "Batch promotion completed successfully. New academic year: $new_year_range";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return "Error during promotion: " . $e->getMessage();
    }
}

// Run the promotion process
promote_students();