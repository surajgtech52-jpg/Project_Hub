<?php
ob_start(); // Prevents "Headers already sent" WSOD errors
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'student') { header("Location: index.php"); exit(); }

$user_id = $_SESSION['user_id'];
$student_name = $_SESSION['name'];
$msg = "";

// 1. SAFE FETCH: Prevent fetch_assoc() on boolean if query fails
$student_query = $conn->query("SELECT moodle_id, academic_year, division FROM student WHERE id = $user_id");
$student_info = $student_query ? $student_query->fetch_assoc() : ['moodle_id'=>'', 'academic_year'=>'', 'division'=>''];
$moodle_id = $student_info['moodle_id'] ?? '';
$student_year = $student_info['academic_year'] ?? '';
$student_div = $student_info['division'] ?? '';

// Fetch Schema and Rules
$settings_query = $conn->query("SELECT * FROM form_settings WHERE academic_year = '$student_year'");
$settings = $settings_query ? $settings_query->fetch_assoc() : null;
$is_form_open = ($settings && isset($settings['is_form_open'])) ? $settings['is_form_open'] : 0;
$min_size = $settings ? $settings['min_team_size'] : 1;
$max_size = $settings ? $settings['max_team_size'] : 4;

// Ensure JSON decodes safely
$form_schema = ($settings && !empty($settings['form_schema'])) ? json_decode($settings['form_schema'], true) : [];
if (!is_array($form_schema)) { $form_schema = []; }

// Check CURRENT Active Project Status
$md_sql = project_member_details_sql('p');
$project_query = "SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name, g.contact_number as guide_contact, s.full_name as leader_name
                  FROM projects p
                  LEFT JOIN project_members pm ON pm.project_id = p.id
                  LEFT JOIN guide g ON p.assigned_guide_id = g.id
                  LEFT JOIN student s ON p.leader_id = s.id
                  WHERE (pm.student_id = $user_id OR p.leader_id = $user_id)
                  AND p.is_archived = 0
                  AND p.project_year = '$student_year'
                  LIMIT 1";
$project_result = $conn->query($project_query);

// 2. SAFE CHECK: Prevent num_rows on boolean if query fails
$has_project = ($project_result && $project_result->num_rows > 0);
$project_data = $has_project ? $project_result->fetch_assoc() : null;

// Fetch PAST Projects for History Tab (Archived OR from previous years)
$history_query = "SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name
                  FROM projects p
                  LEFT JOIN project_members pm ON pm.project_id = p.id
                  LEFT JOIN guide g ON p.assigned_guide_id = g.id
                  WHERE (pm.student_id = $user_id OR p.leader_id = $user_id)
                  AND (p.is_archived = 1 OR p.project_year != '$student_year')
                  ORDER BY p.academic_session DESC, p.id DESC";

$past_projects_data = [];
$past_projects = $conn->query($history_query);
if($past_projects) {
    while($tp = $past_projects->fetch_assoc()) {
        $pid = $tp['id'];
        $tp['requests'] = [];
        $reqs = $conn->query("SELECT * FROM upload_requests WHERE project_id = $pid");
        if($reqs) {
            while($req = $reqs->fetch_assoc()) {
                $rid = $req['id'];
                $req['files'] = [];
                $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $rid ORDER BY uploaded_at DESC");
                if($files) { while($f = $files->fetch_assoc()) { $req['files'][] = $f; } }
                $tp['requests'][] = $req;
            }
        }
        $past_projects_data[$pid] = $tp;
    }
}
$past_projects_json = json_encode($past_projects_data);

// ==========================================
//   HANDLE PASSWORD CHANGE
// ==========================================
if (isset($_POST['change_password'])) {
    $current_pass = $conn->real_escape_string($_POST['current_password']);
    $new_pass = $conn->real_escape_string($_POST['new_password']);
    $confirm_pass = $conn->real_escape_string($_POST['confirm_password']);

    $pass_query = $conn->query("SELECT id, password FROM student WHERE id = $user_id");
    if ($pass_query && $pass_query->num_rows > 0) {
        $row = $pass_query->fetch_assoc();
        if (verify_and_upgrade_password($conn, 'student', (int)$row['id'], $current_pass, (string)$row['password'])) {
            if ($new_pass === $confirm_pass) {
                $h = $conn->real_escape_string(password_hash($new_pass, PASSWORD_DEFAULT));
                $conn->query("UPDATE student SET password = '$h' WHERE id = $user_id");
                $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Password changed successfully!</div>";
            } else {
                $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> New passwords do not match.</div>";
            }
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Incorrect current password.</div>";
        }
    }
}

// ==========================================
//   HANDLE PROJECT SUBMISSION & UPDATES
// ==========================================
if ((isset($_POST['submit_project']) && !$has_project) || (isset($_POST['update_project']) && $has_project && empty($project_data['assigned_guide_id']))) {
    
    $member_moodles = $_POST['team_moodle'] ?? [];
    $member_names = $_POST['team_name'] ?? [];
    $leader_index = $_POST['project_leader_index'] ?? 0;
    
    $members_compiled = "";
    $actual_leader_id = $user_id; 

    if (is_array($member_moodles)) {
        for($i = 0; $i < count($member_moodles); $i++) {
            $m = trim($conn->real_escape_string($member_moodles[$i]));
            $n = trim($member_names[$i]);
            // 3. SAFE STRING CHECK: Replaced str_contains with strpos for older PHP compatibility
            if(!empty($m) && !empty($n) && strpos($n, 'Error') === false && strpos($n, 'Not Found') === false) {
                if ($i == $leader_index) {
                    $members_compiled = $n . " (Leader - " . $m . ")\n" . $members_compiled;
                    $l_query = $conn->query("SELECT id FROM student WHERE moodle_id='$m'");
                    if($l_query && $l_query->num_rows > 0) $actual_leader_id = $l_query->fetch_assoc()['id'];
                } else {
                    $members_compiled .= $n . " (" . $m . ")\n";
                }
            }
        }
    }

    $dept = ''; $t1 = ''; $t2 = ''; $t3 = '';
    $extra_data_array = [];
    
    foreach ($form_schema as $field) {
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

    // --- CREATE NEW PROJECT ---
    if (isset($_POST['submit_project'])) {
        
        // 1. Get ALL existing group numbers for this year to find the true max
        $max_num = 0;
        $grp_query = $conn->query("SELECT group_name FROM projects WHERE project_year = '$student_year' AND group_name LIKE 'Group %'");
        
        if ($grp_query && $grp_query->num_rows > 0) {
            while($g_row = $grp_query->fetch_assoc()) {
                $parts = explode('-', str_replace('Group ', '', $g_row['group_name']));
                $num = (int)$parts[0];
                if ($num > $max_num) { $max_num = $num; }
            }
        }
        
        // 2. Create a truly unique group name (e.g., "Group 4-SE")
        $next_group_num = $max_num + 1;
        $group_name = "Group " . $next_group_num . "-" . $student_year;
        
        $sql = "INSERT INTO projects (group_name, leader_id, project_year, division, department, topic_1, topic_2, topic_3, extra_data, academic_session)
                VALUES ('$group_name', $actual_leader_id, '$student_year', '$student_div', '$dept', '$t1', '$t2', '$t3', $extra_json, 'Current')";
        
        if ($conn->query($sql) === TRUE) {
            $new_pid = (int)$conn->insert_id;
            set_project_members($conn, $new_pid, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, (int)$actual_leader_id);
            // 4. SAFE REDIRECT: Ensure successful exit without WSOD
            header("Location: student_dashboard.php");
            exit();
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error creating project group: " . $conn->error . "</div>";
        }
    } 
   // --- EDIT EXISTING PROJECT ---
    else {
        // Double-check that $project_data exists and has an ID
        $p_id = isset($project_data['id']) ? (int)$project_data['id'] : 0;
        
        if ($p_id > 0) {
            $sql = "UPDATE projects SET leader_id=$actual_leader_id, department='$dept', topic_1='$t1', topic_2='$t2', topic_3='$t3', extra_data=$extra_json WHERE id=$p_id";
            
            if ($conn->query($sql) === TRUE) {
                // ... rest of your success logic ...
            } else {
                 $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error updating project: " . $conn->error . "</div>";
            }
        } else {
             $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Project ID missing for update.</div>";
        }
    }
        
        if ($conn->query($sql) === TRUE) {
            set_project_members($conn, (int)$p_id, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, (int)$actual_leader_id);
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Registration details updated successfully!</div>";
            $project_result = $conn->query($project_query);
            if ($project_result) { $project_data = $project_result->fetch_assoc(); }
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error updating project: " . $conn->error . "</div>";
        }
    }


// ==========================================
//   HANDLE FILE UPLOADS/DELETE
// ==========================================
if (isset($_POST['upload_file'])) {
    $req_id = $_POST['request_id'];
    $p_id = $project_data['id'];
    $file = $_FILES['document'];
    $stored = store_uploaded_file($file, "student_req{$req_id}_p{$p_id}");
    if (!$stored['ok']) {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> ".$stored['error']."</div>";
    } else {
        $stu_tag = $student_name . " (Student)";
        $orig = $conn->real_escape_string($stored['original']);
        $path = $conn->real_escape_string($stored['path']);
        $conn->query("INSERT INTO student_uploads (project_id, request_id, file_name, file_path, uploaded_by_name) VALUES ($p_id, $req_id, '$orig', '$path', '$stu_tag')");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-cloud-arrow-up'></i> File uploaded successfully!</div>";
    }
}

if (isset($_POST['delete_file'])) {
    $f_id = (int)$_POST['file_id'];
    $p_id = $project_data['id']; 
    $file_query = $conn->query("SELECT file_path FROM student_uploads WHERE id=$f_id AND project_id=$p_id");
    if($file_query && $file_query->num_rows > 0) {
        $file_info = $file_query->fetch_assoc();
        if(file_exists($file_info['file_path'])) unlink($file_info['file_path']);
    }
    $conn->query("DELETE FROM student_uploads WHERE id=$f_id AND project_id=$p_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> File removed successfully.</div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Workspace - Project Hub</title>
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
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; transition: background-color 0.3s, color 0.3s, border-color 0.3s; }
        body { background-color: var(--bg-color); height: 100vh; display: flex; padding: 20px; overflow: hidden; color: var(--text-dark); }

        /* --- SIDEBAR DESKTOP --- */
        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto;}
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: var(--text-light); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-weight: 500; transition: all 0.3s; cursor:pointer;}
        .nav-link.active { background-color: var(--primary-green); color: white; box-shadow: 0 8px 20px rgba(16, 93, 63, 0.2); }
        .nav-link:hover:not(.active) { background-color: var(--input-bg); color: var(--primary-green); }
        .logout-btn { margin-top: auto; color: #EF4444; }

        /* --- MAIN CONTENT --- */
        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding-right: 10px; position: relative;}
        
        .top-navbar { background: var(--card-bg); border-radius: 24px; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: var(--shadow); min-height: 75px; flex-shrink: 0;}
        
        .user-profile { display: flex; align-items: center; gap: 12px; border-left: 2px solid var(--border-color); padding-left: 20px; }
        .avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: white; font-weight: bold; font-size: 18px; background: linear-gradient(135deg, var(--primary-green), #34D399); flex-shrink:0;}
        
        /* Dark Mode & Refresh Toggle Buttons */
        .theme-toggle-btn { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; display: flex; justify-content: center; align-items: center; width: 40px; height: 40px; transition: 0.3s; flex-shrink:0; }
        .theme-toggle-btn:hover { background: var(--border-color); }

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0; flex-shrink: 0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA; flex-shrink: 0;}

        /* --- DASHBOARD CARDS --- */
        .grid-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start;}
        .card { background: var(--card-bg); border-radius: 24px; padding: 30px; box-shadow: var(--shadow); margin-bottom: 20px;}
        .card-header { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; justify-content:space-between;}
        
        .detail-row { display: flex; flex-direction: column; margin-bottom: 20px; }
        .detail-row label { font-size: 12px; color: var(--text-light); text-transform: uppercase; font-weight: 600; margin-bottom: 8px; }
        .detail-row span { font-size: 15px; color: var(--text-dark); font-weight: 500; background: var(--input-bg); padding: 12px 15px; border-radius: 12px; border: 1px solid var(--border-color); }
        .topic-list { background: var(--input-bg); padding: 15px 20px; border-radius: 12px; border: 1px solid var(--border-color); font-size: 15px; color: var(--text-dark); line-height:1.6;}

        /* Guide Card */
        .guide-card { text-align: center; }
        .guide-avatar { width: 80px; height: 80px; border-radius: 50%; background: var(--input-bg); color: var(--primary-green); border: 2px solid var(--border-color); display: flex; justify-content: center; align-items: center; font-size: 32px; font-weight: bold; margin: 0 auto 15px auto; }
        
        /* --- FILE MANAGER UI --- */
        .workspace-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        .folder-block { background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; transition: 0.2s; }
        .folder-block:hover { border-color: #CBD5E1; box-shadow: 0 5px 15px rgba(0,0,0,0.03); }
        .folder-header { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 600; color: var(--text-dark); margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color);}
        .file-item { display: flex; justify-content: space-between; align-items: center; background: var(--card-bg); padding: 12px 15px; border-radius: 10px; border: 1px solid var(--border-color); margin-bottom: 10px; }
        .file-item a { font-size: 13px; font-weight: 600; color: #3B82F6; text-decoration: none; word-break: break-all; }
        .file-item a:hover { text-decoration: underline; }
        .upload-area { margin-top: auto; padding-top: 15px; }
        .file-input { width: 100%; border: 1px dashed var(--border-color); padding: 10px; border-radius: 8px; font-size: 12px; background: var(--card-bg); color: var(--text-dark); margin-bottom: 10px;}

        /* --- FORM BUILDER GOOGLE-STYLE UI --- */
        .form-section { max-width: 800px; margin: 0 auto; padding-bottom: 50px; }
        .form-header-card { border-top: 10px solid var(--primary-green); background: var(--card-bg); border-radius: 16px; padding: 30px; margin-bottom: 20px; box-shadow: var(--shadow); border-left: 1px solid var(--border-color); border-right: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); }
        .g-card { background: var(--card-bg); padding: 25px; border-radius: 16px; margin-bottom: 20px; border: 1px solid var(--border-color); box-shadow: var(--shadow); transition: 0.3s;}
        .g-card:focus-within { border-left: 6px solid var(--primary-green); transform: translateX(2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1);}
        .g-label { display: block; font-size: 16px; font-weight: 500; color: var(--text-dark); margin-bottom: 15px; }
        
        .g-input { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; outline: none; background: var(--input-bg); color: var(--text-dark); transition: 0.3s; font-family: inherit;}
        .g-input:focus { border-bottom: 2px solid var(--primary-green); background: var(--card-bg); }
        
        .g-radio-group { display: flex; flex-direction: column; gap: 12px; }
        .g-radio-label { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-dark); cursor: pointer; }
        
        .btn-submit { background: var(--primary-green); color: white; border: none; padding: 15px; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: 0.3s; margin-top: 10px;}
        .btn-submit:hover { background: #0A402A; transform: translateY(-2px); }
        .btn-submit:disabled { background: var(--border-color); color: var(--text-light); cursor: not-allowed; transform: none; }
        .btn-edit-form { background: #3B82F6; color: white; border: none; padding: 8px 15px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: 0.2s; display:inline-flex; align-items:center; gap:6px;}

        /* History Vault */
        .history-card { background: var(--card-bg); border-radius: 20px; padding: 25px; border: 1px solid var(--border-color); margin-bottom: 20px; display:flex; flex-direction:column; gap:15px; box-shadow: var(--shadow);}
        .history-btn { background: #4F46E5; color: white; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 600; font-size: 13px; cursor: pointer; display:inline-flex; align-items:center; gap:6px; height:fit-content;}

        /* Modals */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--bg-color); padding: 0; border-radius: 24px; width: 100%; max-width: 800px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;}
        .modal-card-small { background: var(--card-bg); padding: 30px; border-radius: 24px; width: 100%; max-width: 650px; max-height: 90vh; display:flex; flex-direction:column; }
        /* --- Instruction Note UI (Light & Dark Mode) --- */
        .instruction-note {
            font-size: 12px;
            color: #1D4ED8; /* Dark blue text for light mode */
            margin-bottom: 15px;
            background: #EFF6FF; /* Light blue background */
            border-left: 3px solid #3B82F6;
            padding: 8px 12px;
            border-radius: 8px;
            line-height: 1.5;
        }
        [data-theme="dark"] .instruction-note {
            background: rgba(59, 130, 246, 0.15); /* Transparent blue for dark mode */
            color: #93C5FD; /* Light blue text for dark mode */
            border-left-color: #60A5FA;
        }

        /* =========================================
           📱 MOBILE UI: HAMBURGER SLIDE-OUT DRAWER
           ========================================= */
        .mobile-menu-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; margin-right: 15px; }
        .close-sidebar-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-light); cursor: pointer; position: absolute; right: 20px; top: 25px; }
        .mobile-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; opacity: 0; transition: 0.3s; }
        .mobile-overlay.active { display: block; opacity: 1; }

        @media (max-width: 768px) {
            body { padding: 0; flex-direction: column; overflow-x: hidden; height: auto; overflow-y: auto;}
            
            /* Sidebar becomes hidden drawer */
            .sidebar {
                position: fixed;
                top: 0;
                left: -300px;
                width: 280px;
                height: 100vh;
                margin: 0;
                border-radius: 0 24px 24px 0;
                box-shadow: 5px 0 20px rgba(0,0,0,0.3);
                z-index: 9999;
                transition: left 0.3s ease-in-out;
            }
            .sidebar.active { left: 0; }
            
            .mobile-menu-btn { display: block; }
            .close-sidebar-btn { display: block; }
            
            /* Main Content flows naturally downwards */
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; height: auto; overflow-y: visible;}
            
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-inner { display: flex; align-items: center; width: 100%; justify-content: space-between; }
            .top-navbar-left { display: flex; align-items: center; flex:1; }
            
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            
            .grid-layout { grid-template-columns: 1fr; }
            .history-card-header { flex-direction: column; align-items: flex-start !important; gap: 15px; }
            .history-card-body { flex-direction: column; gap: 15px; }
            .history-btn { width: 100%; justify-content: center; }
            
            .modal-card, .modal-card-small { width: 95%; max-height: 90vh; padding: 20px; border-radius: 16px; margin: 20px auto; }
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
        <div style="font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600;">Workspace</div>
        
        <?php if($has_project): ?>
            <a class="nav-link active" onclick="switchTab('overview')" id="tab-overview"><i class="fa-solid fa-layer-group"></i> Project Overview</a>
            <a class="nav-link" onclick="switchTab('workspace')" id="tab-workspace"><i class="fa-regular fa-folder-open"></i> File Workspace</a>
        <?php else: ?>
            <a class="nav-link active" onclick="switchTab('overview')" id="tab-overview"><i class="fa-solid fa-pen-to-square"></i> Registration Form</a>
        <?php endif; ?>
        
        <a class="nav-link" onclick="switchTab('history')" id="tab-history"><i class="fa-solid fa-clock-rotate-left"></i> Past Projects</a>
        
        <div style="font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top: 20px;">Account</div>
        <a class="nav-link" onclick="switchTab('settings')" id="tab-settings"><i class="fa-solid fa-key"></i> Change Password</a>

        <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        
        <div class="top-navbar">
            <div class="top-navbar-inner">
                <div class="top-navbar-left">
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                    <div style="flex:1;">
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Student Dashboard</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;"><?php echo htmlspecialchars($student_year); ?> - Division <?php echo htmlspecialchars($student_div); ?></p>
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
                <div class="avatar"><?php echo strtoupper(substr($student_name, 0, 1)); ?></div>
                <div class="user-info">
                    <h4 style="margin:0; font-size: 14px; color: var(--text-dark);"><?php echo htmlspecialchars($student_name); ?></h4>
                    <p style="margin:0; font-size: 12px; color: var(--text-light);">ID: <?php echo htmlspecialchars($moodle_id); ?></p>
                </div>
            </div>
        </div>

        <div id="alertBox"><?php echo $msg; ?></div>

        <div id="section-overview" style="display:block;">
            <?php if ($has_project): ?>
                <div class="grid-layout">
                    <div class="card">
                        <div class="card-header">
                            <div><i class="fa-solid fa-list-check" style="color:var(--primary-green);"></i> Team Information</div>
                            <?php if(!$project_data['assigned_guide_id']): ?>
                                <button class="btn-edit-form" onclick="openStudentEditModal()"><i class="fa-solid fa-pen"></i> Edit Registration</button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if(!$project_data['assigned_guide_id']): ?>
                            <div style="background:var(--input-bg); border:1px solid #BFDBFE; color:#1D4ED8; padding:12px 15px; border-radius:12px; font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:10px;">
                                <i class="fa-solid fa-circle-info"></i> Your team is pending mentor assignment. You can safely edit details.
                            </div>
                        <?php endif; ?>

                        <div class="detail-row">
                            <label>Team Members</label>
                            <span style="white-space: pre-line; line-height: 1.6; border-left: 4px solid var(--primary-green);"><?php echo htmlspecialchars($project_data['member_details']); ?></span>
                        </div>

                        <?php if(!empty($project_data['department'])): ?>
                        <div class="detail-row">
                            <label>Department / Domain</label>
                            <span><?php echo htmlspecialchars($project_data['department']); ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="detail-row">
                            <label>Submitted Topic Preferences</label>
                            <div class="topic-list">
                                <?php if($project_data['topic_1']) echo "<div style='margin-bottom:5px;'><b>1.</b> " . htmlspecialchars($project_data['topic_1']) . "</div>"; ?>
                                <?php if($project_data['topic_2']) echo "<div style='margin-bottom:5px;'><b>2.</b> " . htmlspecialchars($project_data['topic_2']) . "</div>"; ?>
                                <?php if($project_data['topic_3']) echo "<div><b>3.</b> " . htmlspecialchars($project_data['topic_3']) . "</div>"; ?>
                                <?php if(!$project_data['topic_1'] && !$project_data['topic_2'] && !$project_data['topic_3']) echo "<div style='color:var(--text-light); font-style:italic;'>No topics submitted.</div>"; ?>
                            </div>
                        </div>

                        <div class="detail-row" style="margin-bottom:0;">
                            <label>Final Status / Approved Topic</label>
                            <?php if($project_data['is_locked']): ?>
                                <span style="background: #D1FAE5; border-color: #A7F3D0; color: #065F46; font-weight:700; font-size:16px; display:inline-block; padding:10px 15px; border-radius:10px; border:1px solid #A7F3D0;"><i class="fa-solid fa-check-circle"></i> Finalized: <?php echo htmlspecialchars($project_data['final_topic']); ?></span>
                            <?php else: ?>
                                <span style="background: #FFFBEB; border-color: #FDE68A; color: #B45309; display:inline-block; padding:10px 15px; border-radius:10px; border:1px solid #FDE68A;"><i class="fa-solid fa-clock"></i> Pending Finalization by Guide</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card guide-card">
                        <div class="card-header" style="justify-content:center;"><i class="fa-solid fa-chalkboard-user" style="color:#3B82F6;"></i> Your Mentor</div>
                        <?php if($project_data['assigned_guide_id']): ?>
                            <div class="guide-avatar"><?php echo strtoupper(substr($project_data['guide_name'], 0, 1)); ?></div>
                            <h3 style="font-size:18px; margin:0; color: var(--text-dark);"><?php echo htmlspecialchars($project_data['guide_name']); ?></h3>
                            <p style="font-size:13px; color:var(--text-light); margin-bottom:10px;">Faculty Guide</p>
                        <?php else: ?>
                            <div style="padding:40px 20px; text-align:center;">
                                <i class="fa-solid fa-user-clock" style="font-size:40px; color:var(--border-color); margin-bottom:15px;"></i>
                                <p style="font-size:14px; font-weight:600; color:var(--text-light);">Mentor not assigned yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php else: ?>
                <?php if($is_form_open): ?>
                    <div class="form-section">
                        <div class="form-header-card">
                            <h2 style="font-size: 24px; color: var(--text-dark); margin-bottom: 5px;">Project Registration Form (<?php echo htmlspecialchars($student_year); ?>)</h2>
                            <p style="color: var(--text-light); font-size: 13px; margin-top: 10px;">Please fill out this form to register your project group.</p>
                        </div>
                        <form method="POST" id="projectForm">
                            <?php foreach($form_schema as $field): 
                                $safe_name = "custom_" . preg_replace('/[^a-zA-Z0-9]/', '_', $field['label']);
                                $req_attr = (isset($field['required']) && $field['required']) ? 'required' : '';
                                $req_mark = (isset($field['required']) && $field['required']) ? '<span style="color:#EF4444;">*</span>' : '';
                            ?>
                                <?php if($field['type'] === 'team-members'): ?>
                                    <div class="g-card" style="border-left-color: var(--primary-green);">
                                        <label class="g-label"><i class="fa-solid fa-users" style="color:var(--primary-green); margin-right:8px;"></i> <?php echo htmlspecialchars($field['label']); ?> <span style="font-size:12px; color:var(--text-light); font-weight:400;">(Min: <?php echo $min_size; ?>, Max: <?php echo $max_size; ?>)</span></label>
                                        <div id="membersContainer">
                                            <div style="display:flex; gap:10px; align-items:center; margin-bottom:10px;">
                                                <input type="radio" name="project_leader_index" value="0" checked required title="Leader">
                                                <input type="text" name="team_moodle[]" value="<?php echo htmlspecialchars($moodle_id); ?>" class="g-input" style="flex:1; pointer-events:none; opacity:0.8;" readonly>
                                                <input type="text" name="team_name[]" value="<?php echo htmlspecialchars($student_name); ?>" class="g-input" style="flex:2; pointer-events:none; color:var(--primary-green); font-weight:600; opacity:0.8;" readonly>
                                                <div style="width:30px;"></div>
                                            </div>
                                        </div>
                                        <button type="button" onclick="addMemberRow()" style="background:var(--card-bg); color:var(--primary-green); border:1px dashed var(--primary-green); padding:10px; border-radius:8px; cursor:pointer; font-weight:600; width:100%; margin-top:5px;"><i class="fa-solid fa-plus"></i> Add Another Member</button>
                                    </div>
                                <?php elseif($field['type'] === 'textarea'): ?>
                                    <div class="g-card"><label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label><textarea name="<?php echo $safe_name; ?>" class="g-input" rows="4" <?php echo $req_attr; ?>></textarea></div>
                                <?php elseif($field['type'] === 'date'): ?>
                                    <div class="g-card"><label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label><input type="date" name="<?php echo $safe_name; ?>" class="g-input" style="width:auto;" <?php echo $req_attr; ?>></div>
                                <?php elseif($field['type'] === 'select'): ?>
                                    <div class="g-card">
                                        <label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label>
                                        <select name="<?php echo $safe_name; ?>" class="g-input" style="width:auto; min-width:200px;" <?php echo $req_attr; ?>>
                                            <option value="">Choose</option>
                                            <?php 
                                                $opts = explode(',', $field['options']);
                                                foreach($opts as $opt) {
                                                    $opt = trim($opt);
                                                    if($opt) echo "<option value=\"".htmlspecialchars($opt)."\">".htmlspecialchars($opt)."</option>";
                                                }
                                            ?>
                                        </select>
                                    </div>
                                <?php elseif($field['type'] === 'radio'): ?>
                                    <div class="g-card">
                                        <label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label>
                                        <div class="g-radio-group">
                                            <?php 
                                                $opts = explode(',', $field['options']);
                                                foreach($opts as $opt): $o = htmlspecialchars(trim($opt));
                                            ?>
                                                <label class="g-radio-label"><input type="radio" name="<?php echo $safe_name; ?>" value="<?php echo $o; ?>" <?php echo $req_attr; ?>> <?php echo $o; ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif($field['type'] === 'checkbox'): ?>
                                    <div class="g-card">
                                        <label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label>
                                        <div class="g-radio-group">
                                            <?php 
                                                $opts = explode(',', $field['options']);
                                                foreach($opts as $opt): $o = htmlspecialchars(trim($opt));
                                            ?>
                                                <label class="g-radio-label"><input type="checkbox" name="<?php echo $safe_name; ?>[]" value="<?php echo $o; ?>"> <?php echo $o; ?></label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="g-card"><label class="g-label"><?php echo htmlspecialchars($field['label']) . " " . $req_mark; ?></label><input type="text" name="<?php echo $safe_name; ?>" class="g-input" <?php echo $req_attr; ?>></div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <button type="submit" name="submit_project" id="submitBtn" class="btn-submit">Submit Project Registration</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; margin-top: 50px;"><i class="fa-solid fa-lock" style="font-size: 60px; color: var(--border-color); margin-bottom: 20px;"></i><h2 style="color: var(--text-dark);">Forms are currently closed</h2></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($has_project): ?>
        <div id="section-workspace" style="display:none;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Project Workspace</h3>
            </div>
            <div class="workspace-grid">
                <?php 
                $p_id = $project_data['id'];
                $reqs = $conn->query("SELECT * FROM upload_requests WHERE project_id = $p_id ORDER BY id ASC");
                if($reqs->num_rows > 0): while($req = $reqs->fetch_assoc()):
                    $r_id = $req['id']; $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id ORDER BY uploaded_at DESC");
                ?>
                    <div class="folder-block">
                        <div class="folder-header" style="margin-bottom: 10px;"><i class="fa-solid fa-folder" style="color:#F59E0B;"></i> <?php echo htmlspecialchars($req['folder_name']); ?></div>
                        
                       <?php if (!empty($req['instructions'])): ?>
                            <div class="instruction-note">
                                <strong><i class="fa-solid fa-circle-info"></i> Note:</strong> <?php echo nl2br(htmlspecialchars($req['instructions'])); ?>
                            </div>
                        <?php endif; ?>

                        <div style="flex:1;">
                            <?php if($files->num_rows > 0): while($f = $files->fetch_assoc()): ?>
                                <div class="file-item">
                                    <div style="flex:1; overflow:hidden; padding-right:10px;">
                                        <a href="<?php echo htmlspecialchars($f['file_path']); ?>" target="_blank"><i class="fa-regular fa-file-pdf"></i> <?php echo htmlspecialchars($f['file_name']); ?></a>
                                    </div>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this file?');"><input type="hidden" name="file_id" value="<?php echo $f['id']; ?>"><button type="submit" name="delete_file" style="background:none; border:none; color:#EF4444; cursor:pointer;"><i class="fa-solid fa-trash-can"></i></button></form>
                                </div>
                            <?php endwhile; else: echo "<div style='text-align:center; padding:15px 0; font-size:13px; color:var(--text-light);'>No files uploaded.</div>"; endif; ?>
                        </div>
                        <div class="upload-area">
                            <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:8px;">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>"><input type="file" name="document" class="file-input" required>
                                <button type="submit" name="upload_file" style="background:#3B82F6; color:white; border:none; padding:10px; border-radius:8px; font-weight:600; cursor:pointer;"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; else: echo "<div style='grid-column: 1 / -1; text-align:center; padding:50px; background:var(--card-bg); border-radius:24px;'>No Folders Available</div>"; endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="section-history" style="display:none;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Past Projects Vault</h3>
            </div>
            <?php if(count($past_projects_data) > 0): foreach($past_projects_data as $past): ?>
                <div class="history-card">
                    <div class="history-card-header" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <strong style="font-size:18px; color:var(--text-dark);"><?php echo htmlspecialchars($past['group_name']); ?></strong>
                            <div style="font-size:13px; color:var(--text-light); margin-top:5px;">
                                <span style="background:#E0E7FF; color:#4F46E5; padding:4px 8px; border-radius:6px; font-weight:700;"><i class="fa-solid fa-box-archive"></i> <?php echo htmlspecialchars($past['academic_session']); ?></span>
                                <span style="margin-left: 10px;">Year: <?php echo htmlspecialchars($past['project_year']); ?> | Div: <?php echo htmlspecialchars($past['division']); ?></span>
                            </div>
                        </div>
                        <button class="history-btn" onclick='openArchiveModal(<?php echo $past['id']; ?>)'><i class="fa-solid fa-eye"></i> View Vault</button>
                    </div>
                    <div class="history-card-body" style="display: flex; gap: 20px; background: var(--input-bg); padding: 15px; border-radius: 12px; border: 1px solid var(--border-color); width: 100%;">
                        <div style="flex: 1;">
                            <div style="font-size:12px; font-weight:600; color:var(--text-light); text-transform:uppercase; margin-bottom:5px;">Guide</div>
                            <div style="font-size:14px; font-weight:600; color:var(--text-dark);"><i class="fa-solid fa-chalkboard-user" style="color:#3B82F6;"></i> <?php echo htmlspecialchars($past['guide_name'] ?: 'None'); ?></div>
                        </div>
                        <div style="flex: 2;">
                            <div style="font-size:12px; font-weight:600; color:var(--text-light); text-transform:uppercase; margin-bottom:5px;">Topic</div>
                            <div style="font-size:13px; color:var(--text-dark);">
                                <?php if($past['is_locked']): ?>
                                    <span style="color:#059669; font-weight:600;"><i class="fa-solid fa-check-circle"></i> Finalized: <?php echo htmlspecialchars($past['final_topic']); ?></span>
                                <?php else: ?>
                                    <?php if($past['topic_1']) echo "1. " . htmlspecialchars($past['topic_1']) . "<br>"; ?>
                                    <?php if($past['topic_2']) echo "2. " . htmlspecialchars($past['topic_2']) . "<br>"; ?>
                                    <?php if($past['topic_3']) echo "3. " . htmlspecialchars($past['topic_3']); ?>
                                    <?php if(!$past['topic_1'] && !$past['topic_2'] && !$past['topic_3']) echo "<span style='color:var(--text-light); font-style:italic;'>No topics submitted</span>"; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; else: echo "<div style='text-align:center; padding:50px; background:var(--card-bg); border-radius:24px;'>No Past Projects Found</div>"; endif; ?>
        </div>

        <div id="section-settings" style="display:none;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Account Settings</h3>
                <p style="font-size: 13px; color: var(--text-light); margin:0;">Update your account password to keep it secure.</p>
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
                    <button type="submit" name="change_password" class="btn-submit" style="width:100%; margin-top:0;"><i class="fa-solid fa-floppy-disk"></i> Update Password</button>
                </form>
            </div>
        </div>

    </div>

    <?php if($has_project && !$project_data['assigned_guide_id']): ?>
    <div id="studentEditModal" class="modal-overlay">
        <div class="modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-pen-to-square"></i> Edit Registration</h3>
                <button type="button" onclick="document.getElementById('studentEditModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="overflow-y:auto; flex:1; padding-right:5px;">
                <form method="POST">
                    <div id="edit_dynamic_form_container"></div>
                    <button type="submit" name="update_project" id="editSubmitBtn" class="btn-submit" style="margin-top:20px;">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="archiveModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header" style="background:var(--input-bg); display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color);">
                <span style="font-size: 18px; font-weight: 700; color: #4F46E5;"><i class="fa-solid fa-box-archive"></i> Archived Project Data</span>
                <button type="button" onclick="document.getElementById('archiveModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding: 30px; overflow-y:auto; flex:1;" id="archiveBody"></div>
        </div>
    </div>

    <script>
        let memberCount = 1; 
        const minSize = <?php echo $min_size; ?>; const maxSize = <?php echo $max_size; ?>;
        const studentYear = '<?php echo $student_year; ?>'; const studentDiv = '<?php echo $student_div; ?>'; const currentMoodleId = '<?php echo $moodle_id; ?>';
        const pastDataJSON = <?php echo $past_projects_json; ?>;

        // ==========================================
        //        DYNAMIC PARTIAL PAGE REFRESH
        // ==========================================
        async function refreshDashboard() {
            const icon = document.getElementById('refreshIcon');
            icon.classList.add('fa-spin'); // Make the icon spin
            
            try {
                // Fetch the latest version of the page quietly in the background
                const response = await fetch(window.location.href);
                const html = await response.text();
                
                // Parse the fetched HTML
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Swap out the current content with the fresh content
                document.getElementById('section-overview').innerHTML = doc.getElementById('section-overview').innerHTML;
                document.getElementById('section-history').innerHTML = doc.getElementById('section-history').innerHTML;
                
                if (document.getElementById('section-workspace') && doc.getElementById('section-workspace')) {
                    document.getElementById('section-workspace').innerHTML = doc.getElementById('section-workspace').innerHTML;
                }
                
                // Re-trigger form validation in case it's the Registration Form
                if(document.getElementById('submitBtn')) validateForm();

                // Show a quick success alert
                let alertBox = document.getElementById('alertBox');
                alertBox.innerHTML = "<div class='alert-success' style='padding:12px 20px;'><i class='fa-solid fa-check-circle'></i> Dashboard data refreshed!</div>";
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';
                setTimeout(() => { alertBox.style.opacity = "0"; setTimeout(()=>alertBox.style.display="none", 500); }, 3000);
                
            } catch (error) {
                console.error('Refresh failed:', error);
            } finally {
                icon.classList.remove('fa-spin'); // Stop spinning
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
        // DARK MODE TOGGLE LOGIC
        function toggleTheme() {
            let currentTheme = document.documentElement.getAttribute('data-theme');
            let newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            document.querySelector('#themeToggle i').className = newTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
        
        // Init theme on load
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.querySelector('#themeToggle i').className = 'fa-solid fa-sun';
        }

        // Mobile Menu Toggle
        function toggleMobileMenu() {
            document.getElementById('sidebar').classList.toggle('active');
            document.getElementById('mobileOverlay').classList.toggle('active');
        }

        // Dashboard Tab Switching
        function switchTab(tab) {
            document.getElementById('section-overview').style.display = 'none';
            document.getElementById('section-history').style.display = 'none';
            document.getElementById('section-settings').style.display = 'none';
            if(document.getElementById('section-workspace')) document.getElementById('section-workspace').style.display = 'none';
            
            document.getElementById('tab-overview').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-settings').classList.remove('active');
            if(document.getElementById('tab-workspace')) document.getElementById('tab-workspace').classList.remove('active');
            
            document.getElementById('section-' + tab).style.display = 'block';
            document.getElementById('tab-' + tab).classList.add('active');
            
            // Close mobile menu if open
            if(window.innerWidth <= 768) toggleMobileMenu();
        }

        // ADD NEW MEMBER TO INITIAL FORM
        function addMemberRow() {
            if(memberCount >= maxSize) { alert(`Maximum size is ${maxSize}.`); return; }
            const container = document.getElementById('membersContainer');
            const row = document.createElement('div');
            let idx = memberCount++;
            row.innerHTML = `<div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;"><input type="radio" name="project_leader_index" value="${idx}" required><input type="text" name="team_moodle[]" class="g-input" placeholder="Moodle ID" onkeyup="fetchStudentDetails(this)" style="flex:1;" required><input type="text" name="team_name[]" class="g-input fetched-name" readonly style="flex:2; opacity:0.8; pointer-events:none;"><button type="button" onclick="removeMemberRow(this)" style="background:none; border:none; color:#EF4444; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button></div><span class="member-error" style="display:block; font-size:12px; color:#EF4444; margin-left:25px; margin-bottom:15px;"></span>`;
            container.appendChild(row); validateForm();
        }
        function removeMemberRow(btn) { btn.parentElement.parentElement.remove(); memberCount--; validateForm(); }

        // FETCH STUDENT DATA (INITIAL FORM)
        function fetchStudentDetails(inputElement) {
            let moodleId = inputElement.value.trim();
            let wrapper = inputElement.parentElement.parentElement; 
            let nameBox = wrapper.querySelector('.fetched-name');
            let errorBox = wrapper.querySelector('.member-error');

            if (moodleId === '') { nameBox.value = ''; errorBox.innerText = ''; validateForm(); return; }
            if (moodleId === currentMoodleId) { nameBox.value = 'Error'; errorBox.innerText = "You are already in the group!"; validateForm(); return; }

            fetch(`get_student.php?moodle_id=${moodleId}&year=${studentYear}&div=${studentDiv}`)
            .then(r => r.json()).then(data => {
                if (data.status === 'success') { nameBox.value = data.name; nameBox.style.color = 'var(--primary-green)'; errorBox.innerText = ''; } 
                else { nameBox.value = 'Validation Error'; nameBox.style.color = '#EF4444'; errorBox.innerText = data.message; }
                validateForm();
            });
        }

        // VALIDATE INITIAL FORM
        function validateForm() {
            let submitBtn = document.getElementById('submitBtn');
            let hasError = false; let validMembersCount = 0; let enteredValues = [];
            document.querySelectorAll('input[name="team_moodle[]"]').forEach(inp => {
                let val = inp.value.trim(); let errBox = inp.parentElement.parentElement.querySelector('.member-error');
                if(val !== '') {
                    validMembersCount++;
                    if(enteredValues.includes(val)) { hasError = true; if(errBox) errBox.innerText = "Duplicate ID!"; } 
                    else { if(errBox && errBox.innerText==="Duplicate ID!") errBox.innerText=""; enteredValues.push(val); }
                }
                let nameBox = inp.parentElement.querySelector('.fetched-name');
                if (nameBox && nameBox.value.includes('Error')) hasError = true;
            });
            let submitText = 'Submit Registration';
            if (validMembersCount < minSize || validMembersCount > maxSize) { hasError = true; submitText = `Team must be ${minSize} to ${maxSize} members (Currently ${validMembersCount})`; }
            submitBtn.disabled = hasError; submitBtn.innerText = submitText;
        }
        if(document.getElementById('submitBtn')) validateForm();

        // ARCHIVE MODAL VIEWER
        function openArchiveModal(projectId) {
            let data = pastDataJSON[projectId];
            if(!data) return;

            let uploadsHtml = `<div style="font-size:13px; font-weight:700; color:var(--text-dark); margin-bottom:15px; border-bottom:1px solid var(--border-color); padding-bottom:10px;">Archived Workspace Files</div>`;
            
            if(data.requests && data.requests.length > 0) {
                data.requests.forEach(req => {
                    
                   // Generate the blue note box if instructions exist
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div class="instruction-note"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
                    }

                    uploadsHtml += `<div style="border:1px solid var(--border-color); border-radius:12px; padding:15px; margin-bottom:15px; background: var(--card-bg);">
                        <div style="font-weight:600; color:var(--text-dark); margin-bottom:10px;"><i class="fa-solid fa-folder" style="color:#F59E0B; margin-right:8px;"></i> ${req.folder_name}</div>
                        ${noteHtml}`;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            uploadsHtml += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--input-bg); padding:10px 15px; border-radius:8px; margin-bottom:8px; border: 1px solid var(--border-color);">
                                <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:#3B82F6; text-decoration:none;"><i class="fa-regular fa-file-pdf"></i> ${f.file_name}</a>
                                <span style="font-size:11px; color:var(--text-light);">Uploaded: ${f.uploaded_by_name}</span>
                            </div>`;
                        });
                    } else {
                        uploadsHtml += `<div style="font-size:12px; color:var(--text-light); font-style:italic;">No files uploaded in this folder.</div>`;
                    }
                    uploadsHtml += `</div>`;
                });
            } else {
                uploadsHtml += `<div style="text-align:center; padding:30px; background:var(--input-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No files were uploaded to the workspace during this project.</div>`;
            }

            document.getElementById('archiveBody').innerHTML = uploadsHtml;
            document.getElementById('archiveModal').style.display = 'flex';
        }

        // ===============================================
        // EDIT MODAL LOGIC (ONLY IF PROJECT IS EDITABLE)
        // ===============================================
        <?php if($has_project && !$project_data['assigned_guide_id']): ?>
            const projectData = <?php echo json_encode($project_data); ?>;
            const formSchemaEdit = <?php echo json_encode($form_schema); ?>;
            let editMemberCount = 0;

            function openStudentEditModal() {
                let container = document.getElementById('edit_dynamic_form_container');
                container.innerHTML = '';
                editMemberCount = 0;

                let extraData = {};
                if (projectData && projectData.extra_data) {
                    try { extraData = JSON.parse(projectData.extra_data); } catch(e) {}
                }

                formSchemaEdit.forEach(field => {
                    let safeName = "custom_" + field.label.replace(/[^a-zA-Z0-9]/g, '_');
                    let val = '';
                    let req_attr = field.required ? 'required' : '';
                    let req_mark = field.required ? '<span style="color:#EF4444;">*</span>' : '';

                    if (field.label.toLowerCase().includes('department')) val = projectData.department || '';
                    else if (field.label.toLowerCase().includes('preference 1')) val = projectData.topic_1 || '';
                    else if (field.label.toLowerCase().includes('preference 2')) val = projectData.topic_2 || '';
                    else if (field.label.toLowerCase().includes('preference 3')) val = projectData.topic_3 || '';
                    else if (extraData[field.label]) val = extraData[field.label];

                    if (field.type === 'team-members') {
                        container.innerHTML += `
                            <div class="g-card" style="border-left-color: var(--primary-green); box-shadow:none; border:1px solid var(--border-color); margin-bottom:20px;">
                                <label class="g-label" style="font-size:15px;"><i class="fa-solid fa-users" style="color:var(--primary-green);"></i> Edit Team Members</label>
                                <div id="editMembersContainer"></div>
                                <button type="button" onclick="addEditMemberRow()" style="background:var(--card-bg); color:var(--primary-green); border:1px dashed var(--primary-green); padding:10px; border-radius:8px; cursor:pointer; font-weight:600; width:100%; margin-top:10px;"><i class="fa-solid fa-plus"></i> Add Another Member</button>
                            </div>
                        `;
                        
                        setTimeout(() => {
                            let mc = document.getElementById('editMembersContainer');
                            mc.innerHTML = '';
                            
                            if (projectData.member_details) {
                                let lines = projectData.member_details.split('\n');
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
                                            }
                                            addEditMemberRow(moodle, name, isLeader);
                                        }
                                    }
                                });
                            }
                            validateEditForm();
                        }, 0);

                    } else if (field.type === 'select') {
                        let opts = (field.options || "").split(',');
                        let optHtml = `<option value="">Choose...</option>`;
                        opts.forEach(opt => {
                            let o = opt.trim();
                            optHtml += `<option value="${o}" ${o==val ? 'selected' : ''}>${o}</option>`;
                        });
                        container.innerHTML += `
                            <div style="margin-bottom:15px;">
                                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">${field.label} ${req_mark}</label>
                                <select name="${safeName}" class="g-input" ${req_attr}>${optHtml}</select>
                            </div>
                        `;
                    } else if (field.type === 'textarea') {
                        container.innerHTML += `
                            <div style="margin-bottom:15px;">
                                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">${field.label} ${req_mark}</label>
                                <textarea name="${safeName}" class="g-input" rows="3" ${req_attr}>${val}</textarea>
                            </div>
                        `;
                    } else if (field.type === 'radio') {
                        let opts = (field.options || "").split(',');
                        let rHtml = `<div class="g-radio-group">`;
                        opts.forEach(opt => {
                            let o = opt.trim();
                            let chk = (val === o) ? 'checked' : '';
                            rHtml += `<label class="g-radio-label"><input type="radio" name="${safeName}" value="${o}" ${chk} ${req_attr}> ${o}</label>`;
                        });
                        rHtml += `</div>`;
                        container.innerHTML += `<div style="margin-bottom:15px;"><label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px;">${field.label} ${req_mark}</label>${rHtml}</div>`;
                    } else if (field.type === 'checkbox') {
                        let opts = (field.options || "").split(',');
                        let valArr = val ? val.split(',').map(s=>s.trim()) : [];
                        let cHtml = `<div class="g-radio-group">`;
                        opts.forEach(opt => {
                            let o = opt.trim();
                            let chk = valArr.includes(o) ? 'checked' : '';
                            cHtml += `<label class="g-radio-label"><input type="checkbox" name="${safeName}[]" value="${o}" ${chk}> ${o}</label>`;
                        });
                        cHtml += `</div>`;
                        container.innerHTML += `<div style="margin-bottom:15px;"><label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px;">${field.label} ${req_mark}</label>${cHtml}</div>`;
                    } else {
                        container.innerHTML += `
                            <div style="margin-bottom:15px;">
                                <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">${field.label} ${req_mark}</label>
                                <input type="${field.type=='date'?'date':'text'}" name="${safeName}" class="g-input" value="${val}" ${req_attr}>
                            </div>
                        `;
                    }
                });

                document.getElementById('studentEditModal').style.display = 'flex';
            }
            
            function addEditMemberRow(moodle = '', name = '', isLeader = false) {
                const container = document.getElementById('editMembersContainer');
                let idx = editMemberCount++;
                const row = document.createElement('div');
                
                let isCurrentUser = (moodle === currentMoodleId);
                let readonlyAttr = isCurrentUser ? 'readonly style="flex:1; pointer-events:none; opacity:0.8;"' : 'style="flex:1;"';
                let nameStyle = isCurrentUser ? 'pointer-events:none; color:var(--primary-green); font-weight:600; opacity:0.8;' : '';
                let removeBtn = isCurrentUser ? `<div style="width:30px;"></div>` : `<button type="button" onclick="removeEditMemberRow(this)" style="background:none; border:none; color:#EF4444; font-size:18px; cursor:pointer; width:30px;"><i class="fa-solid fa-xmark"></i></button>`;
                
                row.innerHTML = `
                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                        <input type="radio" name="project_leader_index" value="${idx}" ${isLeader ? 'checked' : ''} required title="Leader">
                        <input type="text" name="team_moodle[]" class="g-input" value="${moodle}" placeholder="Moodle ID" onkeyup="fetchEditStudentDetails(this)" ${readonlyAttr} required>
                        <input type="text" name="team_name[]" value="${name}" class="g-input fetched-name" placeholder="Auto-Fetched Name" readonly style="flex:2; ${nameStyle}">
                        ${removeBtn}
                    </div>
                    <span class="member-error" style="display:block; font-size:12px; color:#EF4444; margin-left:25px; margin-bottom:15px;"></span>
                `;
                container.appendChild(row);
                validateEditForm();
            }

            function removeEditMemberRow(btn) {
                btn.parentElement.parentElement.remove();
                validateEditForm();
            }

            function fetchEditStudentDetails(inputElement) {
                let moodleId = inputElement.value.trim();
                let wrapper = inputElement.parentElement.parentElement; 
                let nameBox = wrapper.querySelector('.fetched-name');
                let errorBox = wrapper.querySelector('.member-error');

                if (moodleId === '') { nameBox.value = ''; errorBox.innerText = ''; nameBox.style.color = 'var(--text-dark)'; validateEditForm(); return; }
                if (moodleId === currentMoodleId && !inputElement.hasAttribute('readonly')) { nameBox.value = 'Error'; errorBox.innerText = "You are already in the group!"; validateEditForm(); return; }

                fetch(`get_student.php?moodle_id=${moodleId}&year=${studentYear}&div=${studentDiv}&ignore_pid=${projectData.id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') { nameBox.value = data.name; nameBox.style.color = 'var(--primary-green)'; errorBox.innerText = ''; } 
                    else { nameBox.value = 'Validation Error'; nameBox.style.color = '#EF4444'; errorBox.innerText = data.message; }
                    validateEditForm();
                }).catch(err => console.error(err));
            }

            function validateEditForm() {
                let submitBtn = document.getElementById('editSubmitBtn');
                if(!submitBtn) return;
                let hasError = false;
                let moodleInputs = document.querySelectorAll('#editMembersContainer input[name="team_moodle[]"]');
                let enteredValues = [];
                let validMembersCount = 0; 

                moodleInputs.forEach(inp => {
                    let val = inp.value.trim();
                    let errBox = inp.parentElement.parentElement.querySelector('.member-error') || null;
                    if(val !== '') {
                        validMembersCount++;
                        if(enteredValues.includes(val)) { hasError = true; if(errBox) errBox.innerText = "Duplicate ID entered!"; } 
                        else { if (errBox && errBox.innerText === "Duplicate ID entered!") errBox.innerText = ""; enteredValues.push(val); }
                    }
                    let nameBox = inp.parentElement.querySelector('.fetched-name');
                    if (nameBox && (nameBox.value === 'Validation Error' || nameBox.value === 'Error')) { hasError = true; }
                });

                let submitText = 'Save Changes';
                if (validMembersCount < minSize || validMembersCount > maxSize) {
                    hasError = true;
                    submitText = `Team must be ${minSize} to ${maxSize} members (Currently ${validMembersCount})`;
                }

                if (hasError) { submitBtn.disabled = true; submitBtn.innerText = submitText; } 
                else { submitBtn.disabled = false; submitBtn.innerText = 'Save Changes'; }
            }
        <?php endif; ?>
    </script>
</body>
</html>