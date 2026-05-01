<?php
include 'db.php';

$sql = "ALTER TABLE projects 
        ADD COLUMN sdg_goals TEXT DEFAULT NULL AFTER final_topic,
        ADD COLUMN project_type VARCHAR(50) DEFAULT NULL AFTER sdg_goals";

if ($conn->query($sql)) {
    echo "Migration successful: SDG and Project Type columns added to projects table.";
} else {
    echo "Error: " . $conn->error;
}
?>
