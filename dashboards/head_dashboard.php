<?php
session_start();
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'head') { header("Location: index.php"); exit(); }

$head_name = $_SESSION['name'];
$head_id = $_SESSION['user_id'];
$msg = "";
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Fetch the assigned year for this specific head
$head_info = $conn->query("SELECT assigned_year FROM head WHERE id = $head_id")->fetch_assoc();
$assigned_year = $head_info ? $head_info['assigned_year'] : 'SE'; // Safe fallback

// FETCH SCHEMAS FOR SUPER-EDIT & ARCHIVE
$schemas = [];
$schema_q = $conn->query("SELECT academic_year, form_schema FROM form_settings");
while($s = $schema_q->fetch_assoc()) {
    $schemas[$s['academic_year']] = $s['form_schema'] ? json_decode($s['form_schema'], true) : [];
}
$schemas_json = json_encode($schemas);

// ==============================================
//           ACCOUNT SETTINGS
// ==============================================
if (isset($_POST['change_password'])) {
    $current_pass = $conn->real_escape_string($_POST['current_password']);
    $new_pass = $conn->real_escape_string($_POST['new_password']);
    $confirm_pass = $conn->real_escape_string($_POST['confirm_password']);

    $row = $conn->query("SELECT id, password FROM head WHERE id = $head_id")->fetch_assoc();
    if ($row && verify_and_upgrade_password($conn, 'head', (int)$row['id'], $current_pass, (string)$row['password'])) {
        if ($new_pass === $confirm_pass) {
            $h = $conn->real_escape_string(password_hash($new_pass, PASSWORD_DEFAULT));
            $conn->query("UPDATE head SET password = '$h' WHERE id = $head_id");
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Password changed successfully!</div>";
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> New passwords do not match.</div>";
        }
    } else {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Incorrect current password.</div>";
    }
    $active_tab = 'settings';
}

// ==============================================
//           PASSWORD RESET REQUESTS
// ==============================================
if(isset($_POST['accept_request'])) {
    $req_id = (int)$_POST['request_id'];
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $role = $conn->real_escape_string($_POST['user_role']);
    $full_name = $_POST['user_name']; 
    
    $name_parts = explode(' ', trim($full_name));
    $first_name = ucfirst($name_parts[0]);
    $new_pass = $first_name . '@' . $m_id;
    
    $table = ($role == 'guide') ? 'guide' : 'student';
    $conn->query("UPDATE $table SET password='$new_pass' WHERE moodle_id='$m_id'");
    $conn->query("UPDATE password_reset_requests SET status='Resolved' WHERE id=$req_id");
    $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Password reset to <b>$new_pass</b></div>";
    $active_tab = 'resets';
}

if(isset($_POST['reject_request'])) {
    $req_id = (int)$_POST['request_id'];
    $conn->query("UPDATE password_reset_requests SET status='Rejected' WHERE id=$req_id");
    $msg = "<div class='alert-error'><i class='fa-solid fa-xmark'></i> Request rejected.</div>";
    $active_tab = 'resets';
}

if (isset($_POST['accept_all_requests'])) {
    // FIXED: Now selects both 'Pending' and 'Rejected' statuses
    $pending = $conn->query("SELECT pr.id, pr.moodle_id, pr.role as user_role FROM password_reset_requests pr LEFT JOIN student s ON pr.moodle_id = s.moodle_id AND pr.role = 'student' WHERE (pr.status = 'Pending' OR pr.status = 'Rejected') AND ((pr.role = 'student' AND s.academic_year = '$assigned_year') OR pr.role = 'guide')");
    $count = 0;
    if($pending) {
        while($req = $pending->fetch_assoc()) {
            $m_id = $req['moodle_id'];
            $role = $req['user_role'];
            $table = ($role == 'guide') ? 'guide' : 'student';
            
            // Safely fetch name to generate password
            $user_info = $conn->query("SELECT full_name FROM $table WHERE moodle_id = '$m_id'")->fetch_assoc();
            $full_name = $user_info ? $user_info['full_name'] : 'User';
            
            $name_parts = explode(' ', trim($full_name));
            $first_name = ucfirst($name_parts[0]);
            $new_pass = $first_name . '@' . $m_id;
            
            $conn->query("UPDATE $table SET password='$new_pass' WHERE moodle_id='$m_id'");
            $conn->query("UPDATE password_reset_requests SET status='Resolved' WHERE id=".$req['id']);
            $count++;
        }
        $msg = "<div class='alert-success'><i class='fa-solid fa-check-double'></i> Auto-resolved $count pending/rejected requests!</div>";
    } else {
        $msg = "<div class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> Error: " . $conn->error . "</div>";
    }
    $active_tab = 'resets';
}

// ==============================================
//           QUICK ACTIONS (From Projects)
// ==============================================
if (isset($_POST['assign_mentor'])) {
    $project_id = $_POST['project_id'];
    $guide_id = $_POST['guide_id'];
    $conn->query("UPDATE projects SET assigned_guide_id = $guide_id WHERE id = $project_id AND project_year = '$assigned_year'");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-user-check'></i> Mentor successfully assigned!</div>";
}

if (isset($_POST['finalize_topic'])) {
    $project_id = (int)$_POST['project_id'];
    $final_topic = $conn->real_escape_string($_POST['final_topic']);
    
    if ($final_topic === 'unfinalize') {
        $conn->query("UPDATE projects SET final_topic = NULL, is_locked = 0 WHERE id = $project_id AND project_year = '$assigned_year'");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-unlock'></i> Topic unlocked and reverted to Pending!</div>";
    } else {
        if ($final_topic === 'custom') {
            $final_topic = $conn->real_escape_string($_POST['custom_topic']);
        }
        $conn->query("UPDATE projects SET final_topic = '$final_topic', is_locked = 1 WHERE id = $project_id AND project_year = '$assigned_year'");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-lock'></i> Topic safely locked and finalized!</div>";
    }
}

// ==============================================
//           HEAD SUPER POST ACTIONS
// ==============================================
if (isset($_POST['head_edit_project'])) {
    $p_id = (int)$_POST['edit_project_id'];
    $g_id = empty($_POST['edit_guide_id']) ? "NULL" : (int)$_POST['edit_guide_id'];
    
    $member_moodles = $_POST['team_moodle'] ?? [];
    $member_names = $_POST['team_name'] ?? [];
    $leader_index = $_POST['project_leader_index'] ?? 0;
    
    $members_compiled = "";
    $new_leader_id = null;

    if (is_array($member_moodles)) {
        for($i = 0; $i < count($member_moodles); $i++) {
            $m = trim($conn->real_escape_string($member_moodles[$i]));
            $n = trim($member_names[$i]);
            if(!empty($m) && !empty($n) && !str_contains($n, 'Error') && !str_contains($n, 'Not Found')) {
                if ($i == $leader_index) {
                    $members_compiled = $n . " (Leader - " . $m . ")\n" . $members_compiled;
                    $l_query = $conn->query("SELECT id FROM student WHERE moodle_id='$m'");
                    if($l_query->num_rows > 0) $new_leader_id = $l_query->fetch_assoc()['id'];
                } else {
                    $members_compiled .= $n . " (" . $m . ")\n";
                }
            }
        }
    }
    $leader_sql_update = $new_leader_id ? "leader_id = $new_leader_id," : "";

    $dept = ''; $t1 = ''; $t2 = ''; $t3 = '';
    $extra_data_array = [];
    $schema_array = isset($schemas[$assigned_year]) ? $schemas[$assigned_year] : [];
    
    foreach ($schema_array as $field) {
        $safe_key = "custom_" . preg_replace('/[^a-zA-Z0-9]/', '_', $field['label']);
        if (isset($_POST[$safe_key])) {
            $val = $_POST[$safe_key];
            if(is_array($val)) { $val = implode(", ", $val); }
            $val = $conn->real_escape_string($val);
            
            $lbl = $field['label'];
            if (stripos($lbl, 'Department') !== false) $dept = $val;
            elseif (stripos($lbl, 'Preference 1') !== false) $t1 = $val;
            elseif (stripos($lbl, 'Preference 2') !== false) $t2 = $val;
            elseif (stripos($lbl, 'Preference 3') !== false) $t3 = $val;
            elseif ($field['type'] != 'team-members') {
                $extra_data_array[$lbl] = $val;
            }
        }
    }
    
    $extra_json = empty($extra_data_array) ? 'NULL' : "'" . json_encode($extra_data_array) . "'";

    $sql = "UPDATE projects SET $leader_sql_update assigned_guide_id=$g_id, department='$dept', topic_1='$t1', topic_2='$t2', topic_3='$t3', extra_data=$extra_json WHERE id = $p_id AND project_year = '$assigned_year'";
    if ($conn->query($sql) === TRUE) {
        $fallback_leader = $new_leader_id ? (int)$new_leader_id : (int)($conn->query("SELECT leader_id FROM projects WHERE id = $p_id")->fetch_assoc()['leader_id'] ?? 0);
        set_project_members($conn, (int)$p_id, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, $fallback_leader);
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Form Details updated dynamically!</div>";
    }
}

// FOLDER ACTIONS
if (isset($_POST['create_folder'])) {
    $p_id = (int)$_POST['target_project_id'];
    // FIXED: Changed NULL to 0 to prevent "Column 'guide_id' cannot be null" database crashes
    $g_id = empty($_POST['current_guide_id']) ? 0 : (int)$_POST['current_guide_id'];
    $folder_name = $conn->real_escape_string($_POST['folder_name']);
    $instructions = isset($_POST['instructions']) ? $conn->real_escape_string($_POST['instructions']) : '';
    
    $conn->query("INSERT INTO upload_requests (guide_id, project_id, folder_name, instructions) VALUES ($g_id, $p_id, '$folder_name', '$instructions')");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-folder-plus'></i> Upload folder created!</div>";
}
if (isset($_POST['edit_folder'])) {
    $r_id = $_POST['req_id'];
    $new_name = $conn->real_escape_string($_POST['new_folder_name']);
    $edit_instructions = isset($_POST['edit_instructions']) ? $conn->real_escape_string($_POST['edit_instructions']) : '';
    $conn->query("UPDATE upload_requests SET folder_name='$new_name', instructions='$edit_instructions' WHERE id=$r_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-pen'></i> Folder updated!</div>";
}
if (isset($_POST['delete_folder'])) {
    $r_id = $_POST['req_id'];
    $files = $conn->query("SELECT file_path FROM student_uploads WHERE request_id=$r_id");
    while($f = $files->fetch_assoc()) { if(file_exists($f['file_path'])) unlink($f['file_path']); }
    $conn->query("DELETE FROM student_uploads WHERE request_id=$r_id");
    $conn->query("DELETE FROM upload_requests WHERE id=$r_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Folder deleted!</div>";
}
if (isset($_POST['delete_file'])) {
    $f_id = $_POST['file_id'];
    $file_info = $conn->query("SELECT file_path FROM student_uploads WHERE id=$f_id")->fetch_assoc();
    if($file_info && file_exists($file_info['file_path'])) unlink($file_info['file_path']);
    $conn->query("DELETE FROM student_uploads WHERE id=$f_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> File removed successfully!</div>";
}

// UPLOAD FILE (5MB LIMIT)
if (isset($_POST['upload_file_head'])) {
    $req_id = $_POST['request_id'];
    $p_id = $_POST['proj_id'];
    $file = $_FILES['document'];
    $stored = store_uploaded_file($file, "head_req{$req_id}_p{$p_id}");
    if (!$stored['ok']) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> ".$stored['error']."</div>";
    } else {
        $head_tag = $head_name . " (Head)";
        $orig = $conn->real_escape_string($stored['original']);
        $path = $conn->real_escape_string($stored['path']);
        $conn->query("INSERT INTO student_uploads (project_id, request_id, file_name, file_path, uploaded_by_name) VALUES ($p_id, $req_id, '$orig', '$path', '$head_tag')");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-cloud-arrow-up'></i> File uploaded by Head!</div>";
    }
}

// ==============================================
//        DATA FETCHING
// ==============================================

$filter_div = isset($_GET['division']) ? $conn->real_escape_string($_GET['division']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// 1. DASHBOARD STATS (Active projects only)
$stu_where = "academic_year='$assigned_year' AND (status = 'Active' OR status IS NULL)";
$proj_where = "p.project_year='$assigned_year' AND p.is_archived = 0";
if ($filter_div) { $stu_where .= " AND division='$filter_div'"; $proj_where .= " AND p.division='$filter_div'"; }

$student_count = $conn->query("SELECT COUNT(*) as c FROM student WHERE $stu_where")->fetch_assoc()['c'];
$project_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE $proj_where")->fetch_assoc()['c'];
$locked_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE p.is_locked=1 AND $proj_where")->fetch_assoc()['c'];

// Remaining Students
$md_sql = project_member_details_sql('p'); // Call the helper to get the subquery string
$all_students_q = $conn->query("SELECT moodle_id, full_name, division FROM student WHERE $stu_where ORDER BY division, full_name");
$proj_q = $conn->query("SELECT ($md_sql) as member_details FROM projects p WHERE $proj_where");

$all_members_string = "";
if ($proj_q) { // Added a safety check in case the query fails
    while($p = $proj_q->fetch_assoc()) { 
        $all_members_string .= $p['member_details'] . " "; 
    }
}

$remaining_students_list = [];
if ($all_students_q) {
    while($st = $all_students_q->fetch_assoc()) {
        if (strpos($all_members_string, $st['moodle_id']) === false) { 
            $remaining_students_list[] = $st; 
        }
    }
}
$remaining_students = count($remaining_students_list);

// Pending Groups
$pending_groups_list = [];
$pend_q = $conn->query("SELECT p.*, s.full_name as leader_name FROM projects p LEFT JOIN student s ON p.leader_id = s.id WHERE p.is_locked = 0 AND $proj_where ORDER BY p.id DESC");
while($pg = $pend_q->fetch_assoc()) { $pending_groups_list[] = $pg; }
$unlocked_count = count($pending_groups_list);

// Build Active Projects Query
$table_where = $proj_where;
if ($filter_status == 'unassigned') $table_where .= " AND p.assigned_guide_id IS NULL";
if ($filter_status == 'assigned') $table_where .= " AND p.assigned_guide_id IS NOT NULL AND p.is_locked = 0";
if ($filter_status == 'locked') $table_where .= " AND p.is_locked = 1";

$md_sql = project_member_details_sql('p');
$projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle, g.full_name as guide_name FROM projects p LEFT JOIN student s ON p.leader_id = s.id LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE $table_where ORDER BY p.id DESC");

$guides = $conn->query("SELECT id, full_name FROM guide WHERE status = 'Active' OR status IS NULL ORDER BY full_name");

// 2. HISTORY VAULT DATA
$hist_sessions = $conn->query("SELECT DISTINCT academic_session FROM projects WHERE project_year = '$assigned_year' AND is_archived = 1 ORDER BY academic_session DESC");
$filter_hist_session = isset($_GET['h_session']) ? $conn->real_escape_string($_GET['h_session']) : '';
$filter_hist_div = isset($_GET['h_division']) ? $conn->real_escape_string($_GET['h_division']) : '';

$hist_where = "p.project_year = '$assigned_year' AND p.is_archived = 1";
if ($filter_hist_session) $hist_where .= " AND p.academic_session = '$filter_hist_session'";
if ($filter_hist_div) $hist_where .= " AND p.division = '$filter_hist_div'";

$history_projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle, g.full_name as guide_name FROM projects p LEFT JOIN student s ON p.leader_id = s.id LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE $hist_where ORDER BY p.academic_session DESC, p.id DESC");

// 3. PASSWORD RESET REQUESTS
// FIXED: Using pr.role instead of pr.user_role to fix the crash, and ordering safely by ID.
$reset_query = "SELECT pr.*, pr.role as user_role, 
       CASE 
           WHEN pr.role = 'student' THEN s.full_name 
           WHEN pr.role = 'guide' THEN g.full_name 
       END as user_name,
       CASE 
           WHEN pr.role = 'student' THEN s.division 
           ELSE 'N/A' 
       END as user_division
FROM password_reset_requests pr
LEFT JOIN student s ON pr.moodle_id = s.moodle_id AND pr.role = 'student'
LEFT JOIN guide g ON pr.moodle_id = g.moodle_id AND pr.role = 'guide'
WHERE (
    (pr.role = 'student' AND s.academic_year = '$assigned_year') 
    OR (pr.role = 'guide')
)
ORDER BY FIELD(pr.status, 'Pending', 'Resolved', 'Rejected'), pr.id DESC";
$reset_requests = $conn->query($reset_query);

// UNIFIED TEAMS JSON (For Modals)
$teams_data = [];
$all_projects_query = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle FROM projects p LEFT JOIN student s ON p.leader_id = s.id WHERE p.project_year = '$assigned_year'");
while($t = $all_projects_query->fetch_assoc()) {
    $p_id = $t['id'];
    $t['requests'] = [];
    $reqs = $conn->query("SELECT * FROM upload_requests WHERE project_id = $p_id ORDER BY id ASC");
    while($r = $reqs->fetch_assoc()) {
        $r_id = $r['id'];
        $r['files'] = [];
        $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id ORDER BY uploaded_at DESC");
        while($f = $files->fetch_assoc()) { $r['files'][] = $f; }
        $t['requests'][] = $r;
    }
    $teams_data[$p_id] = $t;
}
$teams_json = json_encode($teams_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Head Dashboard - Project Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* === THEME VARIABLES === */
        :root { 
            --primary-green: #105D3F; 
            --bg-color: #F3F4F6; 
            --text-dark: #1F2937; 
            --text-light: #6B7280; 
            --card-bg: #FFFFFF;
            --border-color: #E5E7EB;
            --input-bg: #F9FAFB;
            --sidebar-width: 260px; 
            --shadow: 0 5px 20px rgba(0,0,0,0.02);
            --note-bg: #EFF6FF;
            --note-border: #3B82F6;
            --note-text: #1D4ED8;
            --btn-blue: #3B82F6;
            --btn-blue-hover: #2563EB;
        }
        
        [data-theme="dark"] {
            --primary-green: #34D399;
            --bg-color: #111827; 
            --text-dark: #F9FAFB; 
            --text-light: #9CA3AF; 
            --card-bg: #1F2937;
            --border-color: #374151;
            --input-bg: #374151;
            --shadow: 0 5px 20px rgba(0,0,0,0.3);
            --note-bg: rgba(52, 211, 153, 0.1);
            --note-border: #34D399;
            --note-text: #6EE7B7;
            --btn-blue: #4F46E5;
            --btn-blue-hover: #4338CA;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; transition: background-color 0.3s, color 0.3s, border-color 0.3s; }
        body { background-color: var(--bg-color); height: 100vh; display: flex; padding: 20px; overflow: hidden; color: var(--text-dark); }

        /* --- SIDEBAR DESKTOP --- */
        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto;}
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        .menu-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top:20px;}
        
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: var(--text-light); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-weight: 500; transition: all 0.3s; cursor:pointer;}
        .nav-link.active { background-color: var(--primary-green); color: white; box-shadow: 0 8px 20px rgba(16, 93, 63, 0.2); }
        .nav-link:hover:not(.active) { background-color: var(--input-bg); color: var(--primary-green); }
        .logout-btn { margin-top: auto; color: #EF4444; }

        /* --- MAIN CONTENT --- */
        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding-right: 10px; position: relative;}
        
        .top-navbar { background: var(--card-bg); border-radius: 24px; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: var(--shadow); min-height: 75px; flex-shrink: 0;}
        
        .user-profile { display: flex; align-items: center; gap: 12px; border-left: 2px solid var(--border-color); padding-left: 20px; }
        .avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: var(--primary-green); font-weight: bold; font-size: 18px; background: var(--input-bg); border: 2px solid var(--border-color); flex-shrink:0;}
        .user-info h4 { font-size: 14px; font-weight: 600; color: var(--text-dark); margin:0;}
        .user-info p { font-size: 12px; color: var(--text-light); margin:0;}
        
        /* Dark Mode & Refresh Toggle Buttons */
        .theme-toggle-btn { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; display: flex; justify-content: center; align-items: center; width: 40px; height: 40px; transition: 0.3s; flex-shrink:0; }
        .theme-toggle-btn:hover { background: var(--border-color); }

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0; flex-shrink: 0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA; flex-shrink: 0;}

        .dashboard-canvas { flex: 1; overflow-y: auto; padding-right: 5px; }
        
        /* STATS COMPRESSION FOR MOBILE */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: var(--card-bg); padding: 25px; border-radius: 24px; box-shadow: var(--shadow); display: flex; flex-direction: column; position: relative;}
        .stat-title { font-size: 15px; font-weight: 600; color: var(--text-light); margin-bottom: 10px; }
        .stat-value { font-size: 36px; font-weight: 700; color: var(--text-dark); }
        .stat-icon { position: absolute; top: 25px; right: 25px; width: 45px; height: 45px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 20px; background: var(--input-bg); color: var(--primary-green); }
        .stat-sub { font-size: 13px; font-weight: 600; color: #EF4444; background: rgba(239,68,68,0.1); padding: 5px 10px; border-radius: 8px; display: inline-block; margin-top:10px; width: fit-content; transition:0.2s;}
        .stat-sub.warning { color: #D97706; background: rgba(217,119,6,0.1); }

        .search-bar-container { display:flex; margin-bottom: 20px; gap: 10px; flex-wrap: wrap;}
        .smart-search { flex:1; position:relative; min-width: 250px;}
        .smart-search input { width:100%; border:1px solid var(--border-color); border-radius:16px; padding:15px 15px 15px 45px; font-size:14px; outline:none; transition:0.3s; background:var(--card-bg); font-family:inherit; color:var(--text-dark);}
        .smart-search input:focus { border-color:var(--primary-green); box-shadow:0 0 0 4px rgba(16,93,63,0.1); }
        .smart-search i { position:absolute; left:18px; top:16px; color:var(--text-light); font-size:16px; }

        .filter-select { border: 1px solid var(--border-color); background: var(--card-bg); padding: 12px 15px; border-radius: 12px; font-size: 13px; outline: none; cursor: pointer; font-weight: 500; color:var(--text-dark); font-family:inherit;}

        .card { background: var(--card-bg); border-radius: 24px; padding: 25px 30px; box-shadow: var(--shadow); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; font-size: 13px; color: var(--text-light); text-transform: uppercase; font-weight: 600; border-bottom: 2px solid var(--border-color); }
        td { padding: 15px; font-size: 14px; color: var(--text-dark); border-bottom: 1px solid var(--border-color); vertical-align:top; }
        
        .badge { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; display:inline-block;}
        .badge-warning { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
        .badge-success { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
        
        .btn-action { background: var(--btn-blue); color: white; border: none; padding: 8px 15px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:5px; width:100%; justify-content:center;}
        .btn-action:hover { background: var(--btn-blue-hover); }
        .btn-outline { background: var(--card-bg); color: var(--primary-green); border: 1px solid var(--primary-green); padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:5px; margin-top:5px; }
        .btn-outline:hover { background: var(--input-bg); }
        .btn-submit { background: var(--primary-green); color: white; border: none; padding: 15px; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: 0.3s; margin-top: 10px;}
        .btn-submit:hover { background: #0A402A; }
        .btn-copy { background: var(--primary-green); color: white; border: none; padding: 10px 20px; border-radius: 12px; font-size: 13px; font-weight: 600; cursor: pointer; transition:0.2s; display:flex; align-items:center; gap:8px;}

        /* Dynamic Form Setup */
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-light); margin-bottom: 5px; font-weight:600;}
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 13px; outline:none; font-family:inherit; background:var(--input-bg); color:var(--text-dark);}
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { background: var(--card-bg); border-color: var(--btn-blue); }

        /* Instruction Note */
        .instruction-note { font-size: 12px; color: var(--note-text); margin-bottom: 15px; background: var(--note-bg); border-left: 3px solid var(--note-border); padding: 8px 12px; border-radius: 8px; line-height: 1.5; }

        /* MODAL STYLES */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--bg-color); padding: 0; border-radius: 24px; width: 100%; max-width: 800px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;}
        .modal-card-small { background: var(--card-bg); max-width: 500px; padding: 30px; border-radius: 24px; display:flex; flex-direction:column; max-height:80vh;}
        
        .modal-header { padding: 25px 30px; background:var(--input-bg); display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); }
        .modal-tabs { display:flex; gap:15px; padding: 0 30px; background:var(--input-bg); border-bottom:1px solid var(--border-color); overflow-x:auto;}
        .modal-tab { padding: 15px 20px; font-size: 14px; font-weight: 600; color: var(--text-light); cursor:pointer; border-bottom: 3px solid transparent; transition:0.2s; white-space:nowrap;}
        .modal-tab.active { color: var(--primary-green); border-bottom-color: var(--primary-green); }
        
        .modal-body { padding: 30px; overflow-y:auto; flex:1; }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        .request-block { background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 20px; }
        .request-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 15px; }
        .btn-icon { background: var(--card-bg); border: 1px solid var(--border-color); width: 32px; height: 32px; border-radius: 8px; display: flex; justify-content: center; align-items: center; cursor: pointer; color: var(--text-light); transition: 0.2s; }
        .btn-icon:hover { color: var(--btn-blue); border-color: var(--btn-blue); }
        .file-row { display: flex; justify-content: space-between; align-items: center; background: var(--card-bg); padding: 12px 15px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 10px; }

        /* =========================================
           📱 MOBILE UI: COMPRESSED STATS & DRAWER
           ========================================= */
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; margin-right: 15px; }
        .close-sidebar-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-light); cursor: pointer; position: absolute; right: 20px; top: 25px; }
        .mobile-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; opacity: 0; transition: 0.3s; }
        .mobile-overlay.active { display: block; opacity: 1; }

        @media (max-width: 768px) {
            body { padding: 0; flex-direction: column; overflow-x: hidden; height: auto; overflow-y: auto;}
            
            .sidebar {
                position: fixed; top: 0; left: -300px; width: 280px; height: 100vh; margin: 0;
                border-radius: 0 24px 24px 0; box-shadow: 5px 0 20px rgba(0,0,0,0.3); z-index: 9999;
                transition: left 0.3s ease-in-out; display: flex !important; flex-direction: column !important;
            }
            .sidebar.active { left: 0; }
            .mobile-menu-btn, .close-sidebar-btn { display: block; }
            
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; display: block; overflow-y: visible; height: auto; }
            
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-inner { display: flex; align-items: center; width: 100%; justify-content: space-between; }
            .top-navbar-left { display: flex; align-items: center; flex:1; }
            
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            
            /* Compact Mobile Stats */
            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .stat-card { padding: 12px 10px; text-align: center; border-radius: 16px; justify-content:center; align-items:center;}
            .stat-title { font-size: 10px; white-space: normal; line-height: 1.2; margin-bottom: 5px; }
            .stat-value { font-size: 22px; }
            .stat-icon { display: none; }
            .stat-sub { display: none; } /* Hide extra text to fit in the box */
            
            table { display: block; width: 100%; overflow-x: auto; white-space: nowrap; }
            
            .modal-card, .modal-card-small { width: 95%; max-height: 90vh; padding: 0; border-radius: 16px; margin: 20px auto; }
            .modal-card-small { padding: 20px; }
            .modal-body { padding: 20px 15px; }
        }
    </style>
</head>
<body>

    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>

    <div class="sidebar" id="sidebar">
        <div class="brand">
            <i class="fa-solid fa-leaf"></i> Project Hub
            <button class="close-sidebar-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="menu-label" style="margin-top:0;">Year Management</div>
        <a class="nav-link active" onclick="switchTab('dashboard')" id="tab-dashboard"><i class="fa-solid fa-layer-group"></i> Dashboard</a>
        <a class="nav-link" onclick="switchTab('history')" id="tab-history"><i class="fa-solid fa-clock-rotate-left"></i> History Vault</a>
        <a class="nav-link" onclick="switchTab('resets')" id="tab-resets"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>

        <div class="menu-label">Other Tools</div>
        <a href="head_students.php" class="nav-link" style="background:transparent;"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="head_guides.php" class="nav-link" style="background:transparent;"><i class="fa-solid fa-chalkboard-user"></i> View Guides</a>
        <a href="head_form_settings.php" class="nav-link" style="background:transparent;"><i class="fa-brands fa-google"></i> Form Builder</a>
        
        <div class="menu-label">Account</div>
        <a class="nav-link" onclick="switchTab('settings')" id="tab-settings"><i class="fa-solid fa-key"></i> Change Password</a>

        <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <div class="top-navbar-inner">
                <div class="top-navbar-left">
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Head Dashboard</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Head of <?php echo htmlspecialchars($assigned_year); ?></p>
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button class="theme-toggle-btn" onclick="refreshDashboard()" title="Refresh Data">
                        <i class="fa-solid fa-arrows-rotate" id="refreshIcon"></i>
                    </button>
                    <button id="themeToggle" class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Dark Mode">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                </div>
            </div>

            <div class="user-profile">
                <div class="avatar"><?php echo strtoupper(substr($head_name, 0, 1)); ?></div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($head_name); ?></h4>
                    <p>Department Head</p>
                </div>
            </div>
        </div>

        <div id="alertBox"><?php echo $msg; ?></div>

        <div id="section-dashboard" class="dashboard-canvas" style="display:none;">
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-title">Active Students (<?php echo htmlspecialchars($assigned_year); ?>)</div>
                    <div class="stat-value"><?php echo $student_count; ?></div>
                    <div class="stat-icon"><i class="fa-solid fa-user-graduate"></i></div>
                </div>
                <div class="stat-card" style="cursor:pointer;" onclick="openSimpleModal('remainingModal')">
                    <div class="stat-title">Teams Formed</div>
                    <div class="stat-value"><?php echo $project_count; ?></div>
                    <div class="stat-sub" title="Click to view list"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $remaining_students; ?> Students Remaining (View)</div>
                    <div class="stat-icon"><i class="fa-solid fa-users-rectangle"></i></div>
                </div>
                <div class="stat-card" style="cursor:pointer;" onclick="openSimpleModal('pendingModal')">
                    <div class="stat-title">Topics Finalized</div>
                    <div class="stat-value"><?php echo $locked_count; ?></div>
                    <div class="stat-sub warning" title="Click to view list"><i class="fa-solid fa-clock"></i> <?php echo $unlocked_count; ?> Pending Approval (View)</div>
                    <div class="stat-icon"><i class="fa-solid fa-check-double"></i></div>
                </div>
            </div>

            <div class="search-bar-container">
                <div class="smart-search">
                    <input type="text" id="searchStudent" placeholder="Search by Moodle ID or Student Name..." onkeyup="liveProjectSearch('projectsTable')">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="smart-search">
                    <input type="text" id="searchGuide" placeholder="Search by Group Name or Guide Name..." onkeyup="liveProjectSearch('projectsTable')">
                    <i class="fa-solid fa-users-viewfinder"></i>
                </div>
            </div>

            <div class="card" style="padding: 0; overflow:hidden;">
                <table id="projectsTable">
                    <thead>
                        <tr style="background:var(--input-bg);"><th>Group Info</th><th>Details</th><th>Status / Mentor</th><th style="text-align:center;">Action</th></tr>
                    </thead>
                    <tbody style="padding: 15px;">
                        <?php if($projects->num_rows > 0): while($row = $projects->fetch_assoc()): ?>
                        <tr class="searchable-row">
                            <td style="padding:15px;">
                                <strong class="group-name" style="color:var(--primary-green); font-size:15px;"><?php echo htmlspecialchars($row['group_name']); ?></strong><br>
                                <span class="leader-info" style="font-size:12px; color:var(--text-light); font-weight:600;">Ldr: <?php echo htmlspecialchars($row['leader_name']); ?> (<?php echo htmlspecialchars($row['leader_moodle']); ?>)</span>
                                <div class="member-data" style="display:none;"><?php echo htmlspecialchars($row['member_details']); ?></div>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['department'] ?? 'Dept Not Set'); ?></span><br>
                                <span style="font-size:12px; color: var(--text-light);"><?php echo htmlspecialchars($row['project_year'])." - Div ".htmlspecialchars($row['division']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <?php if($row['is_locked']): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> Finalized: <?php echo htmlspecialchars($row['final_topic']); ?></span><br>
                                    <span class="guide-name" style="font-size:12px; color:var(--text-light); font-weight:600; margin-top:4px; display:inline-block;"><i class="fa-solid fa-chalkboard-user"></i> <?php echo htmlspecialchars($row['guide_name']); ?></span>
                                
                                <?php elseif($row['assigned_guide_id']): ?>
                                    <span class="badge badge-warning" style="margin-bottom:8px;"><i class="fa-solid fa-clock"></i> Pending Finalization</span><br>
                                    <span class="guide-name" style="font-size:12px; color:var(--text-light); font-weight:600; margin-right:5px;"><i class="fa-solid fa-chalkboard-user"></i> <?php echo htmlspecialchars($row['guide_name']); ?></span>
                                    <br><button type="button" class="btn-outline" style="color:#D97706; border-color:#D97706;" onclick="openFinalizeModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['topic_1']); ?>', '<?php echo addslashes($row['topic_2']); ?>', '<?php echo addslashes($row['topic_3']); ?>')"><i class="fa-solid fa-check"></i> Finalize Topic</button>
                                
                                <?php else: ?>
                                    <span class="badge" style="background:rgba(220,38,38,0.1); color:#DC2626; margin-bottom:8px;"><i class="fa-solid fa-triangle-exclamation"></i> No Mentor Assigned</span><br>
                                    <button type="button" class="btn-outline" onclick="openAssignModal(<?php echo $row['id']; ?>)"><i class="fa-solid fa-user-plus"></i> Assign Guide</button>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align:middle; width: 150px; padding:15px;">
                                <button class="btn-action" onclick='openMasterModal(<?php echo $row['id']; ?>)'><i class="fa-solid fa-folder-gear"></i> Manage Project</button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-users-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No active projects found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="section-history" class="dashboard-canvas" style="display:none;">
            
            <form method="GET" class="search-bar-container" style="margin-bottom: 20px;">
                <input type="hidden" name="tab" value="history">
                <select name="h_session" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Sessions</option>
                    <?php $hist_sessions->data_seek(0); while($s = $hist_sessions->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($s['academic_session']); ?>" <?php if($filter_hist_session == $s['academic_session']) echo 'selected'; ?>><?php echo htmlspecialchars($s['academic_session']); ?></option>
                    <?php endwhile; ?>
                </select>
                <select name="h_division" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Divs</option>
                    <option value="A" <?php if($filter_hist_div=='A') echo 'selected'; ?>>A</option>
                    <option value="B" <?php if($filter_hist_div=='B') echo 'selected'; ?>>B</option>
                    <option value="C" <?php if($filter_hist_div=='C') echo 'selected'; ?>>C</option>
                </select>
                <div class="smart-search">
                    <input type="text" id="searchHistory" placeholder="Search past groups by name..." onkeyup="liveSearch('historyTable', 'searchHistory')">
                    <i class="fa-solid fa-search"></i>
                </div>
            </form>

            <div class="card" style="padding: 0; overflow:hidden;">
                <table id="historyTable">
                    <thead>
                        <tr style="background:var(--input-bg);"><th>Group & Session</th><th>Year/Div</th><th>Finalized Topic</th><th style="text-align:center;">Action</th></tr>
                    </thead>
                    <tbody style="padding:15px;">
                        <?php if($history_projects->num_rows > 0): while($row = $history_projects->fetch_assoc()): ?>
                        <tr class="searchable-row">
                            <td style="padding:15px;">
                                <strong style="color:var(--text-dark); font-size:15px;"><?php echo htmlspecialchars($row['group_name']); ?></strong><br>
                                <span style="background:var(--input-bg); color:var(--btn-blue); padding:4px 8px; border-radius:6px; font-weight:700; font-size:11px; display:inline-block; margin-top:5px; border:1px solid var(--border-color);"><i class="fa-solid fa-box-archive"></i> <?php echo htmlspecialchars($row['academic_session']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['project_year']); ?></span><br>
                                <span style="font-size:12px; color: var(--text-light);">Div <?php echo htmlspecialchars($row['division']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <?php if($row['is_locked']): ?>
                                    <span style="color:var(--primary-green); font-weight:600; font-size:13px;"><?php echo htmlspecialchars($row['final_topic']); ?></span>
                                <?php else: ?>
                                    <span style="color:#D97706; font-size:12px; font-style:italic;">Never Finalized</span>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align:middle; width: 130px; padding:15px;">
                                <button class="btn-action" onclick='openArchiveModal(<?php echo $row['id']; ?>)'><i class="fa-solid fa-eye"></i> View Vault</button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-ghost" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No past projects found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

       <div id="section-resets" class="dashboard-canvas" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px; flex-wrap:wrap; gap:15px;">
                <div>
                    <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Password Reset Requests</h3>
                    <p style="font-size: 13px; color: var(--text-light); margin:0;">Manage requests from Guides and <?php echo htmlspecialchars($assigned_year); ?> Students.</p>
                </div>
                
                <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to accept ALL Pending and Rejected requests? This will instantly reset their passwords.');">
                    <button type="submit" name="accept_all_requests" class="btn-submit" style="background:#059669; padding: 10px 20px; margin-top:0; width:auto; font-size:14px;"><i class="fa-solid fa-check-double"></i> Accept All (Pending & Rejected)</button>
                </form>
            </div>

            <div style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--btn-blue); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-circle-info" style="color: var(--btn-blue); font-size: 20px;"></i>
                <div style="font-size: 13px; color: var(--text-dark); line-height: 1.5;">
                    <strong>Auto-Reset Format:</strong> Passwords will be reset to <code style="background: var(--card-bg); padding: 3px 6px; border-radius: 4px; border: 1px solid var(--border-color); color: var(--primary-green); font-weight: bold; margin: 0 4px;">Firstname@MoodleID</code> 
                    <span style="color: var(--text-light); white-space: nowrap;">(e.g., Rahul@123456)</span>
                </div>
            </div>

            <div class="search-bar-container">
                <div class="smart-search">
                    <input type="text" id="liveSearchResets" placeholder="Search by Moodle ID or Name..." onkeyup="liveSearch('requestsTable', 'liveSearchResets')">
                    <i class="fa-solid fa-search"></i>
                </div>
            </div>

            <div class="card" style="padding:0; overflow:hidden;">
                <table id="requestsTable">
                    <thead>
                        <tr style="background:var(--input-bg);">
                            <th>User Details</th>
                            <th>Role / Div</th>
                            <th>Status / Date</th>
                            <th style="text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody style="padding:15px;">
                        <?php if($reset_requests->num_rows > 0): while($req = $reset_requests->fetch_assoc()): ?>
                            <tr class="searchable-row">
                                <td style="padding:15px;">
                                    <div style="font-weight:700; color:var(--text-dark);"><?php echo htmlspecialchars($req['user_name'] ?? 'Unknown User'); ?></div>
                                    <div style="font-size:12px; color:var(--text-light);">ID: <?php echo htmlspecialchars($req['moodle_id']); ?></div>
                                </td>
                                <td style="padding:15px;">
                                    <span style="font-weight:600; text-transform:uppercase; font-size:12px; color:var(--btn-blue);"><?php echo htmlspecialchars($req['user_role']); ?></span><br>
                                    <span style="font-size:12px; color:var(--text-light);">Div <?php echo htmlspecialchars($req['user_division']); ?></span>
                                </td>
                                <td style="padding:15px;">
                                    <?php if($req['status'] == 'Pending'): ?>
                                        <span class="badge badge-warning"><i class="fa-solid fa-clock"></i> Pending</span>
                                    <?php elseif($req['status'] == 'Resolved'): ?>
                                        <span class="badge badge-success"><i class="fa-solid fa-check"></i> Resolved</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:rgba(220,38,38,0.1); color:#DC2626;"><i class="fa-solid fa-xmark"></i> Rejected</span>
                                    <?php endif; ?><br>
                                    <span style="font-size:11px; color:var(--text-light); margin-top:4px; display:inline-block;">
                                        <?php echo isset($req['created_at']) ? date("d M Y, h:i A", strtotime($req['created_at'])) : 'Recent Request'; ?>
                                    </span>
                                </td>
                                <td style="text-align:center; padding:15px; vertical-align:middle;">
                                    <?php if($req['status'] == 'Pending'): ?>
                                        <div style="display:flex; gap:10px; justify-content:center;">
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <input type="hidden" name="moodle_id" value="<?php echo htmlspecialchars($req['moodle_id']); ?>">
                                                <input type="hidden" name="user_role" value="<?php echo htmlspecialchars($req['user_role']); ?>">
                                                <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($req['user_name']); ?>">
                                                <button type="submit" name="accept_request" class="btn-action" style="background:#10B981; padding:8px 12px; width:auto;" title="Auto-Generate Password"><i class="fa-solid fa-check"></i> Accept</button>
                                            </form>
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" name="reject_request" class="btn-action" style="background:#EF4444; padding:8px 12px; width:auto;" title="Reject"><i class="fa-solid fa-xmark"></i></button>
                                            </form>
                                        </div>
                                    <?php elseif($req['status'] == 'Rejected'): ?>
                                        <div style="display:flex; gap:10px; justify-content:center;">
                                            <form method="POST" style="margin:0;">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <input type="hidden" name="moodle_id" value="<?php echo htmlspecialchars($req['moodle_id']); ?>">
                                                <input type="hidden" name="user_role" value="<?php echo htmlspecialchars($req['user_role']); ?>">
                                                <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($req['user_name']); ?>">
                                                <button type="submit" name="accept_request" class="btn-action" style="background:#10B981; padding:8px 12px; width:auto;" title="Recover and Accept"><i class="fa-solid fa-check-double"></i> Re-Accept</button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--text-light); font-style:italic;">Action Completed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align:center; padding:40px; color:var(--text-light);"><i class="fa-solid fa-check-double" style="font-size:40px; color:var(--border-color); margin-bottom:15px;"></i><br>No pending password reset requests.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="section-settings" style="display:none; padding-bottom:50px;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Account Settings</h3>
                <p style="font-size: 13px; color: var(--text-light); margin:0;">Update your account password securely.</p>
            </div>
            
            <div class="card" style="max-width: 500px;">
                <form method="POST">
                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">Current Password</label>
                        <div style="position:relative;">
                            <input type="password" name="current_password" id="current_pass" class="g-input" required style="padding-right:40px;">
                            <i class="fa-regular fa-eye" id="eye_current" onclick="togglePassword('current_pass', 'eye_current')" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-light); font-size:16px;"></i>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:15px;">
                        <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">New Password</label>
                        <div style="position:relative;">
                            <input type="password" name="new_password" id="new_pass" class="g-input" required style="padding-right:40px;">
                            <i class="fa-regular fa-eye" id="eye_new" onclick="togglePassword('new_pass', 'eye_new')" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-light); font-size:16px;"></i>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom:25px;">
                        <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">Confirm New Password</label>
                        <div style="position:relative;">
                            <input type="password" name="confirm_password" id="confirm_pass" class="g-input" required style="padding-right:40px;">
                            <i class="fa-regular fa-eye" id="eye_confirm" onclick="togglePassword('confirm_pass', 'eye_confirm')" style="position:absolute; right:15px; top:50%; transform:translateY(-50%); cursor:pointer; color:var(--text-light); font-size:16px;"></i>
                        </div>
                    </div>
                    <button type="submit" name="change_password" class="btn-submit" style="margin-top:0;"><i class="fa-solid fa-floppy-disk"></i> Update Password</button>
                </form>
            </div>
        </div>

    </div>

    <div id="assignModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Assign Mentor <button type="button" onclick="closeSimpleModal('assignModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="project_id" id="assign_pid">
                <div class="form-group">
                    <label>Select Guide for this Group</label>
                    <select name="guide_id" required style="border:1px solid var(--border-color); border-radius:8px;">
                        <option value="">-- Choose Guide --</option>
                        <?php $guides->data_seek(0); while($g = $guides->fetch_assoc()): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['full_name']); ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <button type="submit" name="assign_mentor" style="background:var(--primary-green); color:white; border:none; padding:12px; border-radius:8px; width:100%; font-weight:600; cursor:pointer;">Assign Mentor</button>
            </form>
        </div>
    </div>

    <div id="finalizeModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:#D97706;">
                Finalize Topic <button type="button" onclick="closeSimpleModal('finalizeModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="project_id" id="finalize_quick_pid">
                <div class="form-group">
                    <label>Select Final Topic to Lock</label>
                    <select name="final_topic" id="quick_topic_select" required onchange="if(this.value=='custom') { document.getElementById('quick_custom_topic_mini').style.display='block'; document.getElementById('quick_custom_topic_mini').required=true; } else { document.getElementById('quick_custom_topic_mini').style.display='none'; document.getElementById('quick_custom_topic_mini').required=false; }" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); color:var(--text-dark); font-family:inherit; outline:none;">
                    </select>
                    <input type="text" name="custom_topic" id="quick_custom_topic_mini" placeholder="Type custom topic here..." style="display:none; margin-top:10px; width:100%; padding:10px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-dark);">
                </div>
                <button type="submit" name="finalize_topic" style="background:#D97706; color:white; border:none; padding:12px; border-radius:8px; width:100%; font-weight:600; cursor:pointer;">Lock & Finalize Topic</button>
            </form>
        </div>
    </div>

    <div id="remainingModal" class="modal-overlay" style="z-index: 2000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="margin:0; font-size: 18px; color: #DC2626;"><i class="fa-solid fa-triangle-exclamation"></i> Remaining Students</h3>
                <button type="button" onclick="closeSimpleModal('remainingModal')" style="border:none; background:none; cursor:pointer; font-size:18px; color:gray;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="flex:1; overflow-y:auto; margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 12px; padding: 15px; background:var(--input-bg);">
                <?php if(count($remaining_students_list) > 0): ?>
                    <ul style="list-style:none; padding:0; margin:0;">
                    <?php foreach($remaining_students_list as $st): ?>
                        <li style="padding: 10px 0; border-bottom: 1px solid var(--border-color); font-size: 13px;">
                            <strong style="color:var(--text-dark);"><?php echo htmlspecialchars($st['full_name']); ?></strong><br>
                            <span style="color:var(--text-light);">ID: <?php echo htmlspecialchars($st['moodle_id']); ?> | Div: <?php echo htmlspecialchars($st['division']); ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align:center; color:var(--primary-green); font-weight:600; margin-top: 20px;">All students are in a group!</p>
                <?php endif; ?>
            </div>
            <textarea id="copyRemainingText" style="display:none;">*Students Remaining (No Group)*&#10;<?php foreach($remaining_students_list as $i => $st): ?><?php echo ($i+1) . ". " . $st['full_name'] . " - " . $st['moodle_id'] . " (Div: " . $st['division'] . ")&#10;"; ?><?php endforeach; ?></textarea>
            <button class="btn-copy" onclick="copyToClipboard('copyRemainingText', this)" style="justify-content:center;"><i class="fa-solid fa-copy"></i> Copy List</button>
        </div>
    </div>

    <div id="pendingModal" class="modal-overlay" style="z-index: 2000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <h3 style="margin:0; font-size: 18px; color: #D97706;"><i class="fa-solid fa-clock"></i> Pending Finalization</h3>
                <button type="button" onclick="closeSimpleModal('pendingModal')" style="border:none; background:none; cursor:pointer; font-size:18px; color:gray;"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="flex:1; overflow-y:auto; margin-bottom: 20px; border: 1px solid var(--border-color); border-radius: 12px; padding: 15px; background:var(--input-bg);">
                <?php if(count($pending_groups_list) > 0): ?>
                    <ul style="list-style:none; padding:0; margin:0;">
                    <?php foreach($pending_groups_list as $pg): ?>
                        <li style="padding: 10px 0; border-bottom: 1px solid var(--border-color); font-size: 13px;">
                            <strong style="color:var(--text-dark);"><?php echo htmlspecialchars($pg['group_name']); ?></strong><br>
                            <span style="color:var(--text-light);">Leader: <?php echo htmlspecialchars($pg['leader_name']); ?> | Div: <?php echo htmlspecialchars($pg['division']); ?></span>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p style="text-align:center; color:var(--primary-green); font-weight:600; margin-top: 20px;">All topics have been finalized!</p>
                <?php endif; ?>
            </div>
            <textarea id="copyPendingText" style="display:none;">*Groups Pending Finalization*&#10;<?php foreach($pending_groups_list as $i => $pg): ?><?php echo ($i+1) . ". " . $pg['group_name'] . " (Leader: " . $pg['leader_name'] . ") - Div: " . $pg['division'] . "&#10;"; ?><?php endforeach; ?></textarea>
            <button class="btn-copy" onclick="copyToClipboard('copyPendingText', this)" style="justify-content:center;"><i class="fa-solid fa-copy"></i> Copy List</button>
        </div>
    </div>


    <div id="masterModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: var(--primary-green);" id="modal_group_title"><i class="fa-solid fa-folder-gear"></i> Project Manager</span>
                <button type="button" onclick="closeMasterModal()" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" id="tab-overview" onclick="switchModalTab('overview')">Overview & Finalize</div>
                <div class="modal-tab" id="tab-form" onclick="switchModalTab('form')">Edit Form Details</div>
                <div class="modal-tab" id="tab-upload" onclick="switchModalTab('upload')">Manage Uploads</div>
            </div>

            <div class="modal-body">
                
                <div id="modal-sec-overview" class="tab-content active">
                    <div style="background:var(--input-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:20px;">
                        <h4 style="font-size:13px; color:var(--text-light); text-transform:uppercase; margin-bottom:10px;">Team Members</h4>
                        <div id="modal_member_list" style="font-size:14px; line-height:1.6; color:var(--text-dark); white-space:pre-line;"></div>
                    </div>
                    
                    <div style="background:var(--input-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:20px;">
                        <h4 style="font-size:13px; color:var(--text-light); text-transform:uppercase; margin-bottom:10px;">Submitted Topic Preferences</h4>
                        <div id="modal_topics_list" style="font-size:14px; line-height:1.8; color:var(--text-dark);"></div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="project_id" id="finalize_pid">
                        
                        <div style="background:var(--card-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color); box-shadow:var(--shadow);">
                            <h4 style="font-size:14px; color:var(--primary-green); margin-bottom:15px;"><i class="fa-solid fa-lock"></i> Finalize Project Topic</h4>
                            
                            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px;">Select Topic to Approve & Lock</label>
                            <select name="final_topic" id="topic_select" required onchange="if(this.value=='custom') document.getElementById('quick_custom_topic').style.display='block'; else document.getElementById('quick_custom_topic').style.display='none';" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); outline:none; background:var(--input-bg); font-family:inherit; margin-bottom:10px; color:var(--text-dark);">
                            </select>
                            <input type="text" name="custom_topic" id="quick_custom_topic" placeholder="Type custom approved topic here..." style="display:none; width:100%; padding:12px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; margin-bottom:15px; background:var(--input-bg); color:var(--text-dark);">
                            
                            <button type="submit" name="finalize_topic" style="background:#D97706; color:white; border:none; padding:12px; border-radius:8px; width:100%; font-weight:600; cursor:pointer; font-size:14px; margin-top:10px; transition:0.2s;"><i class="fa-solid fa-check-double"></i> Lock & Finalize Topic</button>
                        </div>
                    </form>
                </div>

                <div id="modal-sec-form" class="tab-content">
                    <form method="POST">
                        <input type="hidden" name="edit_project_id" id="e_pid">
                        <input type="hidden" name="edit_project_year" id="e_pyear">
                        
                        <div class="form-group">
                            <label>Assigned Guide</label>
                            <select name="edit_guide_id" id="e_guide">
                                <option value="">-- No Guide Assigned --</option>
                                <?php 
                                $guides->data_seek(0);
                                while($g = $guides->fetch_assoc()): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo htmlspecialchars($g['full_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <hr style="border:0; border-top: 1px solid var(--border-color); margin: 20px 0;">
                        <div id="dynamic_form_container"></div>

                        <button type="submit" name="head_edit_project" style="background:var(--primary-green); color:white; border:none; padding:15px; border-radius:12px; width:100%; font-weight:600; cursor:pointer; font-size:14px; margin-top:20px;"><i class="fa-solid fa-floppy-disk"></i> Save Form Details</button>
                    </form>
                </div>

                <div id="modal-sec-upload" class="tab-content">
                    <div style="background:var(--input-bg); border:1px solid var(--border-color); padding:15px; border-radius:12px; margin-bottom:20px;">
                        <label style="font-size:12px; font-weight:600; color:var(--text-light); text-transform:uppercase;">Create New Request</label>
                        <form method="POST" style="display:flex; flex-direction:column; gap:10px; margin-top:10px;">
                            <input type="hidden" name="target_project_id" id="upload_pid">
                            <input type="hidden" name="current_guide_id" id="upload_gid">
                            <input type="text" name="folder_name" placeholder="Folder Name (e.g. Final PPT)" required style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; background:var(--card-bg); color:var(--text-dark);">
                            <textarea name="instructions" placeholder="Optional Note/Instructions (e.g. Max 10 slides)" style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; resize:vertical; min-height:60px; background:var(--card-bg); color:var(--text-dark);"></textarea>
                            <button type="submit" name="create_folder" style="background:var(--btn-blue); color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:600; align-self:flex-start;"><i class="fa-solid fa-plus"></i> Create Folder</button>
                        </form>
                    </div>
                    <div id="upload_folders_container"></div>
                </div>

            </div>
        </div>
    </div>

    <div id="archiveModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: var(--btn-blue);"><i class="fa-solid fa-box-archive"></i> History Vault Viewer</span>
                <button type="button" onclick="document.getElementById('archiveModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" id="tab-arch-overview" onclick="switchArchiveTab('overview')">Submitted Form & Details</div>
                <div class="modal-tab" id="tab-arch-upload" onclick="switchArchiveTab('upload')">Archived Files</div>
            </div>

            <div class="modal-body" style="padding:0;">
                <div id="arch-sec-overview" class="tab-content active" style="padding:30px;"></div>
                <div id="arch-sec-upload" class="tab-content" style="padding:30px; background:var(--bg-color);"></div>
            </div>
        </div>
    </div>

    <div id="editFolderModal" class="modal-overlay" style="z-index:2000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom: 15px; font-weight: bold; font-size: 16px; align-items:center; color:var(--text-dark);">
                Edit Workspace Folder 
                <button type="button" onclick="document.getElementById('editFolderModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:18px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" style="display:flex; flex-direction:column; gap:10px;">
                <input type="hidden" name="req_id" id="edit_folder_rid">
                <label style="font-size:12px; font-weight:600; color:var(--text-light);">Folder Name</label>
                <input type="text" name="new_folder_name" id="edit_folder_name_input" required style="padding:12px; border:1px solid var(--border-color); border-radius:8px; outline:none; font-family:inherit; background:var(--input-bg); color:var(--text-dark);">
                <label style="font-size:12px; font-weight:600; color:var(--text-light); margin-top:5px;">Note/Instructions (Optional)</label>
                <textarea name="edit_instructions" id="edit_folder_instructions_input" style="padding:12px; border:1px solid var(--border-color); border-radius:8px; outline:none; font-family:inherit; min-height:80px; resize:vertical; background:var(--input-bg); color:var(--text-dark);"></textarea>
                <button type="submit" name="edit_folder" style="background:var(--primary-green); color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; margin-top:10px;">Save Changes</button>
            </form>
        </div>
    </div>

    <script>
        const schemasByYear = <?php echo $schemas_json; ?>;
        const teamsData = <?php echo $teams_json; ?>;
        
        let currentEditYear = '<?php echo $assigned_year; ?>';
        let currentEditDiv = '';
        let currentEditPid = '';
        let headMemberCount = 0;

        setTimeout(() => { let a = document.getElementById('alertMsg'); if(a) { a.style.opacity="0"; setTimeout(()=>a.style.display="none", 500); } }, 5000);

        function openSimpleModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeSimpleModal(id) { document.getElementById(id).style.display = 'none'; }

        function copyToClipboard(textareaId, btn) {
            let copyText = document.getElementById(textareaId).value;
            navigator.clipboard.writeText(copyText).then(() => {
                let originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied Successfully!';
                setTimeout(() => { btn.innerHTML = originalHTML; }, 2000);
            }).catch(err => { alert("Failed to copy text."); });
        }

        // DARK MODE TOGGLE LOGIC
        function toggleTheme() {
            let currentTheme = document.documentElement.getAttribute('data-theme');
            let newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            document.querySelector('#themeToggle i').className = newTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.querySelector('#themeToggle i').className = 'fa-solid fa-sun';
        }

        // MOBILE LOGIC
        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('mobileOverlay').classList.toggle('active');
        }

        // INIT TABS
        function switchTab(tab) {
            document.getElementById('section-dashboard').style.display = 'none';
            document.getElementById('section-history').style.display = 'none';
            document.getElementById('section-settings').style.display = 'none';
            document.getElementById('section-resets').style.display = 'none';
            
            document.getElementById('tab-dashboard').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-settings').classList.remove('active');
            document.getElementById('tab-resets').classList.remove('active');
            
            document.getElementById('section-' + tab).style.display = 'block';
            document.getElementById('tab-' + tab).classList.add('active');
            
            if(window.innerWidth <= 768) toggleMobileMenu();
        }

        // Load initial tab from PHP variable
        window.onload = () => { switchTab('<?php echo $active_tab; ?>'); };

        function liveSearch(tableId, inputId) {
            let input = document.getElementById(inputId).value.toLowerCase().trim();
            document.querySelectorAll('#' + tableId + ' .searchable-row').forEach(row => {
                let textData = row.textContent.toLowerCase();
                row.style.display = textData.includes(input) ? "" : "none";
            });
        }

        function liveProjectSearch(tableId) {
            let studentInput = document.getElementById('searchStudent').value.toLowerCase().trim();
            let guideInput = document.getElementById('searchGuide').value.toLowerCase().trim();
            let rows = document.querySelectorAll('#' + tableId + ' .searchable-row');

            rows.forEach(row => {
                let leaderInfo = row.querySelector('.leader-info').textContent.toLowerCase();
                let memberInfo = row.querySelector('.member-data').textContent.toLowerCase();
                let groupName = row.querySelector('.group-name').textContent.toLowerCase();
                let guideEl = row.querySelector('.guide-name');
                let guideName = guideEl ? guideEl.textContent.toLowerCase() : "";

                let studentMatch = studentInput === "" || leaderInfo.includes(studentInput) || memberInfo.includes(studentInput);
                let guideMatch = guideInput === "" || groupName.includes(guideInput) || guideName.includes(guideInput);

                row.style.display = (studentMatch && guideMatch) ? "" : "none";
            });
        }

        function switchModalTab(tab) {
            document.getElementById('tab-overview').classList.remove('active');
            document.getElementById('tab-form').classList.remove('active');
            document.getElementById('tab-upload').classList.remove('active');
            
            document.getElementById('modal-sec-overview').classList.remove('active');
            document.getElementById('modal-sec-form').classList.remove('active');
            document.getElementById('modal-sec-upload').classList.remove('active');

            document.getElementById('tab-' + tab).classList.add('active');
            document.getElementById('modal-sec-' + tab).classList.add('active');
        }

        function switchArchiveTab(tab) {
            document.getElementById('tab-arch-overview').classList.remove('active');
            document.getElementById('tab-arch-upload').classList.remove('active');
            document.getElementById('arch-sec-overview').classList.remove('active');
            document.getElementById('arch-sec-upload').classList.remove('active');

            document.getElementById('tab-arch-' + tab).classList.add('active');
            document.getElementById('arch-sec-' + tab).classList.add('active');
        }

        function closeMasterModal() {
            document.getElementById('masterModal').style.display = 'none';
            switchModalTab('overview'); 
        }

        function openAssignModal(pid) {
            document.getElementById('assign_pid').value = pid;
            openSimpleModal('assignModal');
        }

       function openFinalizeModal(pid, t1, t2, t3) {
            document.getElementById('finalize_quick_pid').value = pid;
            let select = document.getElementById('quick_topic_select');
            
            select.innerHTML = '<option value="">-- Choose a Topic --</option>';
            if(t1) select.innerHTML += `<option value="${t1}">${t1}</option>`;
            if(t2) select.innerHTML += `<option value="${t2}">${t2}</option>`;
            if(t3) select.innerHTML += `<option value="${t3}">${t3}</option>`;
            select.innerHTML += `<option value="custom">Write Custom Topic...</option>`;
            
            // NEW: Add the Un-finalize option
            select.innerHTML += `<option value="unfinalize" style="color:#EF4444; font-weight:bold;">-- Not Finalize (Unlock) --</option>`;
            
            let customBox = document.getElementById('quick_custom_topic_mini');
            customBox.style.display = 'none';
            customBox.value = '';
            customBox.required = false;

            openSimpleModal('finalizeModal');
        }

        function openMasterModal(projectId) {
            const data = teamsData[projectId];
            if(!data) return;

            document.getElementById('modal_group_title').innerText = data.group_name;

            // --- 1. POPULATE OVERVIEW TAB ---
            document.getElementById('modal_member_list').innerText = data.member_details;
            document.getElementById('finalize_pid').value = data.id;

            // Display Submitted Topics prominently
            let topicsHtml = '';
            if(data.topic_1) topicsHtml += `<div style="padding:10px; border-bottom:1px solid var(--border-color);"><b style="color:var(--primary-green);">1.</b> ${data.topic_1}</div>`;
            if(data.topic_2) topicsHtml += `<div style="padding:10px; border-bottom:1px solid var(--border-color);"><b style="color:var(--primary-green);">2.</b> ${data.topic_2}</div>`;
            if(data.topic_3) topicsHtml += `<div style="padding:10px;"><b style="color:var(--primary-green);">3.</b> ${data.topic_3}</div>`;
            if(!topicsHtml) topicsHtml = `<div style="padding:10px; font-style:italic; color:var(--text-light);">No topics submitted.</div>`;
            document.getElementById('modal_topics_list').innerHTML = topicsHtml;

            let select = document.getElementById('topic_select');
            select.innerHTML = '<option value="">-- Choose a Topic --</option>';
            if(data.topic_1) select.innerHTML += `<option value="${data.topic_1}">1. ${data.topic_1}</option>`;
            if(data.topic_2) select.innerHTML += `<option value="${data.topic_2}">2. ${data.topic_2}</option>`;
            if(data.topic_3) select.innerHTML += `<option value="${data.topic_3}">3. ${data.topic_3}</option>`;
            select.innerHTML += `<option value="custom">Write Custom Approved Topic...</option>`;
            
            // NEW: Add the Un-finalize option
            select.innerHTML += `<option value="unfinalize" style="color:#EF4444; font-weight:bold;">-- Not Finalize (Unlock) --</option>`;

            let customInp = document.getElementById('quick_custom_topic');
            if (data.final_topic) {
                if (data.final_topic == data.topic_1 || data.final_topic == data.topic_2 || data.final_topic == data.topic_3) {
                    select.value = data.final_topic;
                    customInp.style.display = 'none';
                } else {
                    select.value = 'custom';
                    customInp.style.display = 'block';
                    customInp.value = data.final_topic;
                }
            } else {
                select.value = "";
                customInp.style.display = 'none';
            }

            // --- 2. POPULATE EDIT FORM TAB ---
            document.getElementById('e_pid').value = data.id;
            document.getElementById('e_pyear').value = data.project_year;
            document.getElementById('e_guide').value = data.assigned_guide_id || "";
            
            currentEditDiv = data.division;
            currentEditPid = data.id;

            let dynamicContainer = document.getElementById('dynamic_form_container');
            dynamicContainer.innerHTML = '';

            let extraData = {};
            if (data.extra_data) {
                try { extraData = JSON.parse(data.extra_data); } catch(e) {}
            }

            let schemaArray = schemasByYear[data.project_year] || [];
            
            schemaArray.forEach(field => {
                let safeName = "custom_" + field.label.replace(/[^a-zA-Z0-9]/g, '_');
                let val = '';

                if (field.label.toLowerCase().includes('department')) val = data.department || '';
                else if (field.label.toLowerCase().includes('preference 1')) val = data.topic_1 || '';
                else if (field.label.toLowerCase().includes('preference 2')) val = data.topic_2 || '';
                else if (field.label.toLowerCase().includes('preference 3')) val = data.topic_3 || '';
                else if (extraData[field.label]) val = extraData[field.label];

                if (field.type === 'team-members') {
                    dynamicContainer.innerHTML += `
                        <div class="g-card" style="padding:15px; border-left:4px solid var(--primary-green); margin-bottom:15px; background:var(--card-bg); border:1px solid var(--border-color);">
                            <label class="g-label" style="font-size:14px; font-weight:600; color:var(--text-dark); display:block; margin-bottom:10px;"><i class="fa-solid fa-users" style="color:var(--primary-green);"></i> Edit Team Members</label>
                            <div id="head_membersContainer" style="background:var(--input-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:10px;"></div>
                            <button type="button" onclick="addHeadMemberRow()" style="background: var(--card-bg); color: var(--primary-green); border: 1px dashed var(--primary-green); padding: 8px; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; width: 100%;">+ Add Another Member</button>
                        </div>
                    `;
                    setTimeout(() => {
                        let mc = document.getElementById('head_membersContainer');
                        mc.innerHTML = '';
                        headMemberCount = 0;
                        if (data.member_details) {
                            let lines = data.member_details.split('\n');
                            let foundLeader = false;
                            lines.forEach(line => {
                                let txt = line.trim();
                                if(txt) {
                                    let match = txt.match(/^(.*?)\s*\((.*?)\)$/);
                                    if(match) {
                                        let name = match[1].trim();
                                        let moodle = match[2].trim();
                                        let isLeader = false;
                                        if (moodle.startsWith('Leader - ')) {
                                            moodle = moodle.replace('Leader - ', '').trim();
                                            isLeader = true;
                                            foundLeader = true;
                                        }
                                        addHeadMemberRow(moodle, name, isLeader);
                                    }
                                }
                            });
                            if (!foundLeader && mc.children.length > 0) { mc.querySelector('input[type="radio"]').checked = true; }
                        } else { addHeadMemberRow(); }
                    }, 0);
                } else if (field.type === 'select') {
                    let opts = (field.options||"").split(',');
                    let optHtml = `<option value="">Choose...</option>`;
                    opts.forEach(opt => { let o = opt.trim(); if(o) optHtml += `<option value="${o}" ${o==val ? 'selected' : ''}>${o}</option>`; });
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><select name="${safeName}" class="g-input">${optHtml}</select></div>`;
                } else if (field.type === 'textarea') {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><textarea name="${safeName}" class="g-input" rows="3">${val}</textarea></div>`;
                } else if (field.type === 'radio' || field.type === 'checkbox') {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><input type="text" name="${safeName}" class="g-input" value="${val}" placeholder="Type value to override"></div>`;
                } else {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><input type="${field.type=='date'?'date':'text'}" name="${safeName}" class="g-input" value="${val}"></div>`;
                }
            });

            // --- 3. POPULATE UPLOAD TAB ---
            document.getElementById('upload_pid').value = data.id;
            document.getElementById('upload_gid').value = data.assigned_guide_id || "";
            
            let uploadContainer = document.getElementById('upload_folders_container');
            let html = '';
            
            if(data.requests && data.requests.length > 0) {
                data.requests.forEach(req => {
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div class="instruction-note"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
                    }
                    
                    let escapedName = req.folder_name ? req.folder_name.replace(/'/g, "\\'").replace(/"/g, '&quot;') : '';
                    let escapedInstructions = req.instructions ? req.instructions.replace(/'/g, "\\'").replace(/"/g, '&quot;') : '';

                    html += `
                        <div class="request-block">
                            <div class="request-header">
                                <span style="font-weight:600; color:var(--text-dark);"><i class="fa-solid fa-folder-open" style="color:#F59E0B; margin-right:8px;"></i> ${req.folder_name}</span>
                                <div style="display:flex; gap:8px;">
                                    <button type="button" class="btn-icon" onclick="openEditFolderModal(${req.id}, '${escapedName}', '${escapedInstructions}')"><i class="fa-solid fa-pen"></i></button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this folder and ALL its files?');">
                                        <input type="hidden" name="req_id" value="${req.id}">
                                        <button type="submit" name="delete_folder" class="btn-icon" style="color:#EF4444; border-color:#FECACA;"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </div>
                            </div>
                            
                            ${noteHtml}
                            
                            <div style="margin-bottom: 15px;">
                    `;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            html += `
                                <div class="file-row">
                                    <div style="flex:1;">
                                        <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:var(--btn-blue); text-decoration:none;"><i class="fa-regular fa-file-pdf"></i> ${f.file_name}</a>
                                        <div style="font-size:11px; color:var(--text-light); margin-top:3px;">Uploaded by: ${f.uploaded_by_name}</div>
                                    </div>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this file?');">
                                        <input type="hidden" name="file_id" value="${f.id}">
                                        <button type="submit" name="delete_file" style="background:none; border:none; color:#EF4444; cursor:pointer;"><i class="fa-solid fa-trash-can"></i></button>
                                    </form>
                                </div>
                            `;
                        });
                    } else {
                        html += `<div style="font-size:13px; color:var(--text-light); font-style:italic;">No files uploaded yet.</div>`;
                    }
                    
                    html += `
                            </div>
                            
                            <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:8px; background:var(--card-bg); padding:15px; border-radius:10px; border:1px dashed var(--border-color);">
                                <input type="hidden" name="request_id" value="${req.id}">
                                <input type="hidden" name="proj_id" value="${data.id}">
                                
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div style="font-size:11px; color:var(--text-light);">Max file size: 5MB</div>
                                </div>
                                
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="file" name="document" required style="font-size:12px; flex:1; color:var(--text-dark);">
                                    <button type="submit" name="upload_file_head" style="background:var(--primary-green); color:white; border:none; padding:8px 15px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
                                </div>
                            </form>
                        </div>
                    `;
                });
            } else {
                html = `<div style="text-align:center; padding:20px; color:var(--text-light); font-size:14px; background:var(--input-bg); border-radius:12px; border:1px dashed var(--border-color);">No upload folders created for this group yet. Create one above!</div>`;
            }
            
            uploadContainer.innerHTML = html;

            document.getElementById('masterModal').style.display = 'flex';
        }

        // ==========================================
        //        ARCHIVE VAULT VIEWER (HISTORY)
        // ==========================================
        function openArchiveModal(projectId) {
            const data = teamsData[projectId];
            if(!data) return;

            let headerHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                    <div>
                        <h3 style="margin:0; font-size:22px; color:var(--text-dark);">${data.group_name}</h3>
                        <p style="margin:0; color:var(--text-light); font-size:14px;">${data.project_year} - Div ${data.division} | Session: ${data.academic_session}</p>
                    </div>
                    ${data.is_locked ? `<span class="badge badge-success" style="font-size:14px;"><i class="fa-solid fa-check-circle"></i> Topic Finalized</span>` : `<span class="badge badge-warning" style="font-size:14px;">Not Finalized</span>`}
                </div>
            `;

            // -- TAB 1: FORM DETAILS --
            let extraData = {};
            if (data.extra_data) { try { extraData = JSON.parse(data.extra_data); } catch(e) {} }
            let schemaArray = schemasByYear[data.project_year] || [];
            
            let formDetailsHtml = `
                ${headerHtml}
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:20px;">
                    <div style="font-size:12px; font-weight:700; color:var(--primary-green); margin-bottom:20px; text-transform:uppercase; letter-spacing:1px;"><i class="fa-solid fa-file-lines"></i> Submitted Form Details</div>
            `;
            
            schemaArray.forEach(field => {
                let val = '';
                if (field.label.toLowerCase().includes('department')) val = data.department || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 1')) val = data.topic_1 || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 2')) val = data.topic_2 || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 3')) val = data.topic_3 || '<em style="color:gray;">N/A</em>';
                else if (field.type === 'team-members') val = data.member_details;
                else if (extraData[field.label]) val = extraData[field.label];
                else val = '<em style="color:gray;">Not answered</em>';

                formDetailsHtml += `
                    <div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed var(--border-color);">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">${field.label}</div>
                        <div style="font-size:15px; color:var(--text-dark); white-space:pre-wrap;">${val}</div>
                    </div>
                `;
            });
            formDetailsHtml += `
                    <div style="margin-bottom:0;">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">Final Approved Topic</div>
                        <div style="font-size:16px; color:var(--primary-green); font-weight:700;">${data.final_topic || '<em style="color:gray;">N/A</em>'}</div>
                    </div>
                </div>
            `;

            // -- TAB 2: UPLOADS --
            let uploadsHtml = `<div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase;">Archived Files</div>`;
            
            if(data.requests && data.requests.length > 0) {
                data.requests.forEach(req => {
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div class="instruction-note"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
                    }

                    uploadsHtml += `
                    <div style="border:1px solid var(--border-color); border-radius:12px; padding:15px; margin-bottom:15px; background:var(--card-bg);">
                        <div style="font-weight:600; color:var(--text-dark); margin-bottom:10px;"><i class="fa-solid fa-folder" style="color:#F59E0B; margin-right:8px;"></i> ${req.folder_name}</div>
                        ${noteHtml}
                    `;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            uploadsHtml += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--input-bg); padding:10px 15px; border-radius:8px; margin-bottom:8px; border:1px solid var(--border-color);">
                                <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:var(--btn-blue); text-decoration:none;"><i class="fa-regular fa-file-pdf"></i> ${f.file_name}</a>
                                <span style="font-size:11px; color:var(--text-light);">By: ${f.uploaded_by_name}</span>
                            </div>`;
                        });
                    } else {
                        uploadsHtml += `<div style="font-size:12px; color:var(--text-light); font-style:italic;">No files uploaded in this folder.</div>`;
                    }
                    uploadsHtml += `</div>`;
                });
            } else {
                uploadsHtml += `<div style="text-align:center; padding:20px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No files were uploaded by this group.</div>`;
            }

            document.getElementById('arch-sec-overview').innerHTML = formDetailsHtml;
            document.getElementById('arch-sec-upload').innerHTML = uploadsHtml;
            
            switchArchiveTab('overview');
            document.getElementById('archiveModal').style.display = 'flex';
        }

        // ADD MEMBER LOGIC (EDIT FORM)
        function addHeadMemberRow(moodle = '', name = '', isLeader = false) {
            const container = document.getElementById('head_membersContainer');
            const wrapper = document.createElement('div');
            let idx = headMemberCount++;
            
            wrapper.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                    <label style="cursor:pointer; display:flex; flex-direction:column; align-items:center;" title="Set as Leader">
                        <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Ldr</span>
                        <input type="radio" name="project_leader_index" value="${idx}" ${isLeader ? 'checked' : ''} required>
                    </label>
                    <input type="text" name="team_moodle[]" value="${moodle}" placeholder="Moodle ID" onkeyup="headFetchStudent(this)" style="flex:1; padding:10px; border:1px solid var(--border-color); border-radius:8px; outline:none; background:var(--card-bg); color:var(--text-dark);" required>
                    <input type="text" name="team_name[]" value="${name}" class="fetched-name" placeholder="Auto-Fetched Name" readonly style="flex:2; padding:10px; border:1px solid var(--border-color); border-radius:8px; outline:none; background:var(--bg-color); color:var(--text-dark); opacity:0.8;">
                    <button type="button" onclick="this.parentElement.remove();" style="background:none; border:none; color:#EF4444; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <span class="member-error" style="font-size: 12px; color: #EF4444; margin-left: 45px; display:block; margin-bottom:10px;"></span>
            `;
            container.appendChild(wrapper);
        }

        function headFetchStudent(inputElement) {
            let moodleId = inputElement.value.trim();
            let wrapper = inputElement.parentElement; 
            let nameBox = wrapper.querySelector('.fetched-name');
            let errorBox = wrapper.parentElement.querySelector('.member-error');

            if (moodleId === '') { nameBox.value = ''; errorBox.innerText = ''; return; }

            fetch(`get_student.php?moodle_id=${moodleId}&year=${currentEditYear}&div=${currentEditDiv}&ignore_pid=${currentEditPid}`)
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') { nameBox.value = data.name; nameBox.style.color = 'var(--primary-green)'; errorBox.innerText = ''; } 
                else { nameBox.value = 'Validation Error'; nameBox.style.color = '#EF4444'; errorBox.innerText = data.message; }
            }).catch(err => console.error(err));
        }

        function openEditFolderModal(reqId, currentName, currentInstructions) {
            document.getElementById('edit_folder_rid').value = reqId;
            document.getElementById('edit_folder_name_input').value = currentName;
            document.getElementById('edit_folder_instructions_input').value = currentInstructions || '';
            document.getElementById('editFolderModal').style.display = 'flex';
        }

        // ==========================================
        //        DYNAMIC PARTIAL PAGE REFRESH
        // ==========================================
        async function refreshDashboard() {
            const icon = document.getElementById('refreshIcon');
            icon.classList.add('fa-spin');
            
            try {
                const response = await fetch(window.location.href);
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                document.getElementById('section-dashboard').innerHTML = doc.getElementById('section-dashboard').innerHTML;
                document.getElementById('section-history').innerHTML = doc.getElementById('section-history').innerHTML;
                document.getElementById('section-resets').innerHTML = doc.getElementById('section-resets').innerHTML;
                
                let alertBox = document.getElementById('alertBox');
                alertBox.innerHTML = "<div class='alert-success' style='padding:12px 20px;'><i class='fa-solid fa-check-circle'></i> Data refreshed successfully!</div>";
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';
                setTimeout(() => { alertBox.style.opacity = "0"; setTimeout(()=>alertBox.style.display="none", 500); }, 3000);
                
            } catch (error) {
                console.error('Refresh failed:', error);
            } finally {
                icon.classList.remove('fa-spin');
            }
        }

        // ==========================================
        //        SHOW/HIDE PASSWORD TOGGLE
        // ==========================================
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                icon.style.color = "var(--primary-green)";
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                icon.style.color = "var(--text-light)";
            }
        }
    </script>
</body>
</html>