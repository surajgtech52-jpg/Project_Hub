<?php
$content = file_get_contents('c:/xampp/htdocs/project_hub/head_dashboard.php');
$p1 = strpos($content, '<div id="archiveModal"');
$p2 = strpos($content, '</div>', $p1);
$p2 = strpos($content, '</div>', $p2 + 1);
$p2 = strpos($content, '</div>', $p2 + 1);
$p2 = strpos($content, '</div>', $p2 + 1);
$p2 = strpos($content, '</div>', $p2 + 1);
echo substr($content, $p1, $p2 - $p1 + 6);
