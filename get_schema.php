<?php
require_once 'bootstrap.php';
$res = $conn->query("DESCRIBE student");
while($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
unlink(__FILE__);
?>
