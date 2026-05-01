<?php
require 'db.php';
$res=$conn->query('SELECT id, group_name, topic_1, topic_2, topic_3, final_topic FROM projects ORDER BY id');
while($r=$res->fetch_assoc()) print_r($r);
?>
