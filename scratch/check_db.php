<?php
require_once 'db.php';
$res = $conn->query("DESCRIBE project_logs");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        echo $row['Field'] . " " . $row['Type'] . "\n";
    }
} else {
    echo "Error: " . $conn->error;
}
?>