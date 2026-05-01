<?php
/**
 * Project Hub - Database Migration Script
 * This script handles schema updates safely for production environments.
 * It should be run manually after deployment or schema changes.
 */

session_start();
require_once 'bootstrap.php';

// Security Check: Only allow Admin to run migrations
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized Access: Only administrators can run database migrations.");
}

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <title>Database Migration - Project Hub</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f7f6; padding: 40px; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #105D3F; border-bottom: 2px solid #eef; padding-bottom: 10px; }
        .log-entry { padding: 10px; border-bottom: 1px solid #eee; font-size: 14px; }
        .success { color: #105D3F; font-weight: bold; }
        .info { color: #1D4ED8; }
        .error { color: #EF4444; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 12px; color: #999; text-align: center; }
        .btn { display: inline-block; padding: 10px 20px; background: #105D3F; color: white; text-decoration: none; border-radius: 6px; margin-top: 20px; }
    </style>
</head>
<body>
<div class='container'>
    <h1><i class='fa-solid fa-database'></i> Database Migration</h1>";

function log_msg($msg, $type = 'info') {
    echo "<div class='log-entry $type'>[" . date('H:i:s') . "] $msg</div>";
}

log_msg("Starting migration process...");

// 1. Check for 'log_date_to' in 'project_logs'
$check_logs = $conn->query("SHOW COLUMNS FROM project_logs LIKE 'log_date_to'");
if ($check_logs && $check_logs->num_rows === 0) {
    log_msg("Adding 'log_date_to' column to 'project_logs' table...", "info");
    if ($conn->query("ALTER TABLE project_logs ADD COLUMN log_date_to DATE AFTER log_date")) {
        log_msg("SUCCESS: 'log_date_to' added.", "success");
    } else {
        log_msg("ERROR: Failed to add 'log_date_to': " . $conn->error, "error");
    }
} else {
    log_msg("Column 'log_date_to' already exists in 'project_logs'. Skipping.", "info");
}

// 2. Check for 'guides_review' vs 'guide_review' (Normalization)
$check_review = $conn->query("SHOW COLUMNS FROM project_logs LIKE 'guides_review'");
if ($check_review && $check_review->num_rows > 0) {
    log_msg("Found legacy column 'guides_review'. Renaming to 'guide_review' for consistency...", "info");
    // Only rename if 'guide_review' doesn't already exist
    $check_new = $conn->query("SHOW COLUMNS FROM project_logs LIKE 'guide_review'");
    if ($check_new && $check_new->num_rows === 0) {
        if ($conn->query("ALTER TABLE project_logs CHANGE guides_review guide_review TEXT DEFAULT NULL")) {
            log_msg("SUCCESS: 'guides_review' renamed to 'guide_review'.", "success");
        } else {
            log_msg("ERROR: Failed to rename column: " . $conn->error, "error");
        }
    } else {
        log_msg("INFO: Both columns found. Please manually merge data if needed.", "error");
    }
}

// 3. Ensure 'password_reset_requests' table exists
$check_table = $conn->query("SHOW TABLES LIKE 'password_reset_requests'");
if ($check_table && $check_table->num_rows === 0) {
    log_msg("Creating 'password_reset_requests' table...", "info");
    $sql = "CREATE TABLE `password_reset_requests` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `role` varchar(50) NOT NULL,
      `moodle_id` varchar(100) NOT NULL,
      `status` varchar(50) DEFAULT 'Pending',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    if ($conn->query($sql)) {
        log_msg("SUCCESS: 'password_reset_requests' table created.", "success");
    } else {
        log_msg("ERROR: Failed to create table: " . $conn->error, "error");
    }
}

// 4. Ensure uploads directory exists
$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) {
    if (@mkdir($upload_dir, 0777, true)) {
        log_msg("SUCCESS: Uploads directory created.", "success");
    } else {
        log_msg("ERROR: Failed to create uploads directory.", "error");
    }
}

// 5. Semester-wise tracking updates
$tables_to_update = [
    'student' => 'current_semester',
    'projects' => 'semester',
    'form_settings' => 'semester',
    'student_history' => 'semester'
];

foreach ($tables_to_update as $table => $column) {
    $check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($check && $check->num_rows === 0) {
        log_msg("Adding '$column' column to '$table' table...", "info");
        $after = ($table === 'student') ? "AFTER academic_year" : "AFTER academic_year"; // Simplified
        if ($table === 'projects') $after = "AFTER project_year";
        
        if ($conn->query("ALTER TABLE `$table` ADD COLUMN `$column` INT DEFAULT 0 $after")) {
            log_msg("SUCCESS: '$column' added to '$table'.", "success");
            
            // Initialize data if newly added
            if ($table === 'student') {
                $conn->query("UPDATE student SET current_semester = 3 WHERE academic_year = 'SE'");
                $conn->query("UPDATE student SET current_semester = 5 WHERE academic_year = 'TE'");
                $conn->query("UPDATE student SET current_semester = 7 WHERE academic_year = 'BE'");
            } elseif ($table === 'projects') {
                $conn->query("UPDATE projects SET semester = 3 WHERE project_year = 'SE'");
                $conn->query("UPDATE projects SET semester = 5 WHERE project_year = 'TE'");
                $conn->query("UPDATE projects SET semester = 7 WHERE project_year = 'BE'");
            } elseif ($table === 'form_settings') {
                $conn->query("UPDATE form_settings SET semester = 3 WHERE academic_year = 'SE'");
                $conn->query("UPDATE form_settings SET semester = 5 WHERE academic_year = 'TE'");
                $conn->query("UPDATE form_settings SET semester = 7 WHERE academic_year = 'BE'");
            } elseif ($table === 'student_history') {
                $conn->query("UPDATE student_history SET semester = 3 WHERE academic_year = 'SE'");
                $conn->query("UPDATE student_history SET semester = 5 WHERE academic_year = 'TE'");
                $conn->query("UPDATE student_history SET semester = 7 WHERE academic_year = 'BE'");
            }
        } else {
            log_msg("ERROR: Failed to add '$column' to '$table': " . $conn->error, "error");
        }
    } else {
        log_msg("Column '$column' already exists in '$table'. Skipping.", "info");
    }
}

log_msg("Migration process completed.");

echo "<a href='admin_dashboard.php' class='btn'>Back to Dashboard</a>
    <div class='footer'>Project Hub &copy; " . date('Y') . "</div>
</div>
</body>
</html>";
?>
