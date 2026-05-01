<?php
require 'db.php';
$res=$conn->query('SELECT pm.project_id, s.moodle_id, s.full_name FROM project_members pm JOIN student s ON pm.student_id = s.id WHERE pm.project_id = 1 ORDER BY s.moodle_id ASC');
echo "Count: " . $res->num_rows . "\n";
while($r=$res->fetch_assoc()) print_r($r);
?>
