<?php
require 'db.php';
$res=$conn->query('SELECT topic_1, topic_2, topic_3 FROM projects WHERE id = 4');
print_r($res->fetch_assoc());
?>
