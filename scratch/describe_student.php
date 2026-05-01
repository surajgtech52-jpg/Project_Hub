<?php
require_once 'bootstrap.php';
$res = $conn->query("DESCRIBE student");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
