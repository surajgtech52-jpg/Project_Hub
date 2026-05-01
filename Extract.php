<?php
$c = file_get_contents('c:/xampp/htdocs/project_hub/head_dashboard.php');
$p1 = strpos($c, 'function openArchiveModal(');
$p2 = strpos($c, 'function ', $p1 + 10);
echo substr($c, $p1, $p2 - $p1);
