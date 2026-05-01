<?php
session_start();
require_once 'bootstrap.php';

// Security: Check role
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'head'])) {
    die("Unauthorized access.");
}

// Get Filters (Trimmed for safety)
$session = isset($_GET['session']) ? trim($conn->real_escape_string($_GET['session'])) : 'all';
$sem = isset($_GET['sem']) ? trim($_GET['sem']) : 'all';
$division = isset($_GET['division']) ? trim($conn->real_escape_string($_GET['division'])) : 'all';
$report_title = isset($_GET['title']) ? htmlspecialchars(trim($_GET['title'])) : 'Topic Selection Report';
$is_preview = isset($_GET['preview']) && $_GET['preview'] == '1';

// Get Selected Columns (Default to all if empty)
$selected_cols = isset($_GET['cols']) ? $_GET['cols'] : ['sr_no', 'group_no', 'roll_no', 'student_name', 'topics', 'final_topic', 'sdg', 'guide_name', 'sign'];

// Columns configuration (Widths and Titles)
$colConfig = [
    'sr_no' => ['title' => 'Sr. No.', 'width' => '50'],
    'group_no' => ['title' => 'Group No.', 'width' => '70'],
    'roll_no' => ['title' => 'Roll No', 'width' => '80'],
    'moodle_id' => ['title' => 'Moodle ID', 'width' => '100'],
    'student_name' => ['title' => 'Name of Student', 'width' => '250'],
    'topics' => ['title' => 'Topics', 'width' => '250'],
    'final_topic' => ['title' => 'Topic Selected', 'width' => '250'],
    'project_type' => ['title' => 'Project Type', 'width' => '120'],
    'sdg' => ['title' => 'SDG Mapped (No. & Name)', 'width' => '150'],
    'guide_name' => ['title' => 'Guide Name', 'width' => '150'],
    'sign' => ['title' => 'Sign', 'width' => '80']
];

// Clean selected cols to only include valid ones to prevent errors
$valid_cols = [];
foreach ($selected_cols as $c) {
    if (isset($colConfig[$c])) $valid_cols[] = $c;
}
$colspan = count($valid_cols);
if ($colspan == 0) $colspan = 1; // Prevent colspan=0

// Build Query
$where = [];
if ($session !== 'all' && !empty($session)) {
    $where[] = "p.academic_session = '$session'";
}
if ($sem !== 'all' && !empty($sem)) {
    $where[] = "p.semester = " . (int)$sem;
}
if ($division !== 'all' && !empty($division)) {
    $where[] = "p.division = '$division'";
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Fetch Projects
$sql = "SELECT 
            p.*,
            g.full_name as guide_name
        FROM projects p
        LEFT JOIN guide g ON p.assigned_guide_id = g.id
        $where_sql
        ORDER BY p.division ASC, p.group_name ASC, p.id ASC";

$res = $conn->query($sql);

if (!$is_preview) {
    // Set Filename
    $safe_title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $report_title);
    $filename = $safe_title . "_" . ($sem != 'all' ? "Sem$sem" : "AllSem") . "_" . date('Y-m-d') . ".xls";

    // Headers for Excel
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");
}

// Header display variables
$display_year = ($session == 'all') ? 'All Sessions' : $session;
$display_div = ($division == 'all') ? 'All' : $division;
$display_sem = ($sem == 'all') ? 'All' : $sem;

?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<!--[if gte mso 9]><xml>
 <x:ExcelWorkbook>
  <x:ExcelWorksheets>
   <x:ExcelWorksheet>
    <x:Name>Topic Selection Report</x:Name>
    <x:WorksheetOptions>
     <x:DisplayGridlines/>
     <x:FitToPage/>
    </x:WorksheetOptions>
   </x:ExcelWorksheet>
  </x:ExcelWorksheets>
 </x:ExcelWorkbook>
</xml><![endif]-->
<style>
    body { font-family: 'Calibri', 'Arial', sans-serif; background: #ffffff; margin: 0; padding: 20px; }
    .header-table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
    .header-table td { text-align: center; font-weight: bold; font-family: 'Calibri', 'Arial', sans-serif; border: none; }
    .inst-name { font-size: 18pt; letter-spacing: 0.5px; }
    .dept-name { font-size: 14pt; padding-top: 5px; }
    .dept-sub-name { font-size: 12pt; padding-bottom: 5px; }
    .sub-header { font-size: 11pt; padding: 10px 0; border-top: 2pt solid windowtext; border-bottom: 2pt solid windowtext; }
    .report-title { font-size: 16pt; padding: 15px 0 5px 0; font-weight: bold; }
    
    .data-table { width: 100%; border-collapse: collapse; border: 2pt solid windowtext; table-layout: fixed; }
    .data-table th, .data-table td { border: .5pt solid windowtext; padding: 8px; font-family: 'Calibri', 'Arial', sans-serif; font-size: 11pt; vertical-align: top; word-wrap: break-word; }
    .data-table th { background-color: #f8f9fa; font-weight: bold; text-align: center; border-bottom: 2pt solid windowtext; vertical-align: middle; }
    .text-center { text-align: center; }
</style>
</head>
<body>

    <!-- Header Section (Exact Match to Image) -->
    <table class="header-table" cellspacing="0" cellpadding="0">
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="inst-name">A. P. SHAH INSTITUTE OF TECHNOLOGY</td>
        </tr>
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="dept-name">DEPARTMENT OF COMPUTER SCIENCE & ENGINEERING</td>
        </tr>
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="dept-sub-name">(ARTIFICIAL INTELLIGENCE & MACHINE LEARNING)</td>
        </tr>
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="sub-header">
                Class: TE CSE (AI & ML) &nbsp;&nbsp;&nbsp;&nbsp; DIV: <?php echo $display_div; ?> &nbsp;&nbsp;&nbsp;&nbsp; SEM: <?php echo $display_sem; ?> &nbsp;&nbsp;&nbsp;&nbsp; Sub: Mini Project &nbsp;&nbsp;&nbsp;&nbsp; Academic Year: <?php echo $display_year; ?>
            </td>
        </tr>
        <tr>
            <td colspan="<?php echo $colspan; ?>" class="report-title"><?php echo $report_title; ?></td>
        </tr>
        <tr>
            <td colspan="<?php echo max(1, $colspan - 1); ?>"></td>
            <td style="text-align: right; font-weight: bold; font-size: 11pt; padding-bottom: 5px;">Date: __________</td>
        </tr>
    </table>

    <!-- Data Table -->
    <table class="data-table" border="1" cellspacing="0" cellpadding="5">
        <thead>
            <tr>
                <?php foreach($valid_cols as $col): ?>
                    <th width="<?php echo $colConfig[$col]['width']; ?>"><?php echo $colConfig[$col]['title']; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            if ($res && $res->num_rows > 0) {
                $sr_no = 1;
                while ($project = $res->fetch_assoc()) {
                    $pid = $project['id'];
                    
                    // Fetch members
                    $members_q = "SELECT s.moodle_id, s.full_name 
                                  FROM project_members pm
                                  JOIN student s ON pm.student_id = s.id
                                  WHERE pm.project_id = $pid
                                  ORDER BY s.moodle_id ASC";
                    $members_res = $conn->query($members_q);
                    $members = [];
                    while($m = $members_res->fetch_assoc()) { $members[] = $m; }
                    
                    $rowCount = count($members);
                    $displayRows = max($rowCount, 3); // Show at least 3 rows for topics
                    
                    $topics = [
                        $project['topic_1'],
                        $project['topic_2'],
                        $project['topic_3']
                    ];

                    for ($i = 0; $i < $displayRows; $i++) {
                        echo "<tr>";
                        
                        foreach($valid_cols as $col) {
                            switch($col) {
                                case 'sr_no':
                                    echo "<td class='text-center'>" . ($sr_no++) . "</td>";
                                    break;
                                    
                                case 'group_no':
                                    if ($i === 0) {
                                        $display_group = htmlspecialchars($project['group_name']);
                                        // Extract just the number if it matches "Group X-..."
                                        if (preg_match('/Group\s*(\d+)/i', $project['group_name'], $matches)) {
                                            $display_group = $matches[1];
                                        }
                                        echo "<td rowspan='$displayRows' class='text-center'>" . $display_group . "</td>";
                                    }
                                    break;
                                    
                                case 'moodle_id':
                                    echo "<td class='text-center'>" . ($i < $rowCount ? htmlspecialchars($members[$i]['moodle_id']) : '') . "</td>";
                                    break;
                                    
                                case 'roll_no':
                                    // Assuming Moodle ID serves as Roll No in your system
                                    echo "<td class='text-center'>" . ($i < $rowCount ? htmlspecialchars($members[$i]['moodle_id']) : '') . "</td>";
                                    break;
                                    
                                case 'student_name':
                                    echo "<td>" . ($i < $rowCount ? htmlspecialchars($members[$i]['full_name']) : '') . "</td>";
                                    break;
                                    
                                case 'topics':
                                    $topicText = isset($topics[$i]) ? $topics[$i] : '';
                                    echo "<td>" . htmlspecialchars($topicText) . "</td>";
                                    break;
                                    
                                case 'final_topic':
                                    if ($i === 0) echo "<td rowspan='$displayRows'>" . htmlspecialchars($project['final_topic']) . "</td>";
                                    break;
                                    
                                case 'project_type':
                                    if ($i === 0) echo "<td rowspan='$displayRows' class='text-center'>" . htmlspecialchars($project['project_type']) . "</td>";
                                    break;
                                    
                                case 'sdg':
                                    if ($i === 0) {
                                        $sdg_json = json_decode($project['sdg_goals'], true);
                                        $sdg_text = is_array($sdg_json) ? implode(", ", $sdg_json) : (string)$project['sdg_goals'];
                                        echo "<td rowspan='$displayRows' class='text-center'>" . htmlspecialchars($sdg_text) . "</td>";
                                    }
                                    break;
                                    
                                case 'guide_name':
                                    if ($i === 0) echo "<td rowspan='$displayRows' class='text-center'>" . htmlspecialchars($project['guide_name'] ?? 'Not Assigned') . "</td>";
                                    break;
                                    
                                case 'sign':
                                    if ($i === 0) echo "<td rowspan='$displayRows'></td>";
                                    break;
                            }
                        }
                        
                        echo "</tr>";
                    }
                }
            } else {
                echo "<tr><td colspan='$colspan' class='text-center' style='padding: 20px;'>No project records found for selected filters.</td></tr>";
            }
            ?>
        </tbody>
    </table>

</body>
</html>
