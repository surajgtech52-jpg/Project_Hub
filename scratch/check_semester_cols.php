z<?php
require_once 'bootstrap.php';
$tables = ['student', 'projects', 'form_settings', 'student_history'];
foreach ($tables as $table) {
    echo "--- $table ---\n";
    $res = $conn->query("DESCRIBE $table");
    while($row = $res->fetch_assoc()) {
        if (strpos($row['Field'], 'semester') !== false) {
            echo $row['Field'] . " - " . $row['Type'] . "\n";
        }
    }
}
?>
