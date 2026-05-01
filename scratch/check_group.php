<?php
require 'db.php';
$res = $conn->query('SELECT id, group_name FROM projects LIMIT 5');
while($r = $res->fetch_assoc()) print_r($r);
?>
