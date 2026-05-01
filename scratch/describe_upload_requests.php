<?php
include 'db.php';
$res = $conn->query("DESCRIBE upload_requests");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
?>
