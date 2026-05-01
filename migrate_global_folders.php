<?php
require_once 'bootstrap.php';

echo "Starting migration for Global Workspace...\n";

// 1. Drop the foreign key constraint on project_id in upload_requests to allow NULL
$res = $conn->query("SHOW CREATE TABLE upload_requests");
$row = $res->fetch_assoc();
$create_stmt = $row['Create Table'];
if (preg_match('/CONSTRAINT `([^`]+)` FOREIGN KEY \(`project_id`\)/', $create_stmt, $matches)) {
    $fk_name = $matches[1];
    echo "Dropping foreign key $fk_name...\n";
    $conn->query("ALTER TABLE upload_requests DROP FOREIGN KEY $fk_name");
}

// 2. Modify project_id to be NULLable
echo "Modifying project_id to be NULLable...\n";
$conn->query("ALTER TABLE upload_requests MODIFY project_id INT(11) NULL");

// 3. Add global scope columns
echo "Adding global scope columns...\n";
$conn->query("ALTER TABLE upload_requests ADD COLUMN is_global TINYINT(1) DEFAULT 0");
$conn->query("ALTER TABLE upload_requests ADD COLUMN academic_year ENUM('SE', 'TE', 'BE') NULL");
$conn->query("ALTER TABLE upload_requests ADD COLUMN semester INT(11) NULL");
$conn->query("ALTER TABLE upload_requests ADD COLUMN created_by_role VARCHAR(20) NULL");
$conn->query("ALTER TABLE upload_requests ADD COLUMN created_by_id INT(11) NULL");

// 4. Re-add foreign key with ON DELETE CASCADE
echo "Re-adding foreign key constraint...\n";
$conn->query("ALTER TABLE upload_requests ADD CONSTRAINT fk_req_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE");

echo "Migration completed successfully!\n";
?>
