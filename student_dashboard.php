<?php
ob_start(); // Prevents "Headers already sent" WSOD errors
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'student') { header("Location: index.php"); exit(); }

verify_csrf_token();

$user_id = $_SESSION['user_id'];
$student_name = $_SESSION['name'];
$msg = "";

if (isset($_GET['msg']) && $_GET['msg'] == 'log_success') {
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Log Book entry created successfully!</div>";
}

// 1. SAFE FETCH: Prevent fetch_assoc() on boolean if query fails

$stmt = $conn->prepare("SELECT moodle_id, academic_year, current_semester, division FROM student WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student_result = $stmt->get_result();
$student_info = $student_result ? $student_result->fetch_assoc() : ['moodle_id'=>'', 'academic_year'=>'', 'current_semester'=>3, 'division'=>''];
$stmt->close();

$moodle_id = $student_info['moodle_id'] ?? '';
$student_year = $student_info['academic_year'] ?? '';
$student_sem = (int)($student_info['current_semester'] ?? 3);
$student_div = $student_info['division'] ?? '';

// Fetch Schema and Rules (Filtered by Semester)
$stmt = $conn->prepare("SELECT * FROM form_settings WHERE academic_year = ? AND semester = ?");
$stmt->bind_param("si", $student_year, $student_sem);
$stmt->execute();
$settings_query = $stmt->get_result();
$settings = $settings_query ? $settings_query->fetch_assoc() : null;
$stmt->close();
$is_form_open = ($settings && isset($settings['is_form_open'])) ? $settings['is_form_open'] : 0;
$min_size = $settings ? $settings['min_team_size'] : 1;
$max_size = $settings ? $settings['max_team_size'] : 4;

// Ensure JSON decodes safely
$form_schema = ($settings && !empty($settings['form_schema'])) ? json_decode($settings['form_schema'], true) : [];
if (!is_array($form_schema)) { $form_schema = []; }

// FETCH SCHEMAS FOR ARCHIVE VAULT
$schemas = [];
$stmt = $conn->prepare("SELECT academic_year, form_schema FROM form_settings");
$stmt->execute();
$schema_q = $stmt->get_result();
while($s = $schema_q->fetch_assoc()) {
    // No need to close stmt inside loop, it's reused
    $schemas[$s['academic_year']] = $s['form_schema'] ? json_decode($s['form_schema'], true) : [];
}
$schemas_json = json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// Check CURRENT Active Project Status
$md_sql = project_member_details_sql('p');
$project_query = "SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name, g.contact_number as guide_contact, s.full_name as leader_name
                  FROM projects p
                  LEFT JOIN project_members pm ON pm.project_id = p.id
                  LEFT JOIN guide g ON p.assigned_guide_id = g.id
                  LEFT JOIN student s ON p.leader_id = s.id
                  WHERE (pm.student_id = ? OR p.leader_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE student_id = ?))
                  AND p.is_archived = 0
                  AND p.project_year = ?
                  AND p.semester = ?
                  LIMIT 1";

// 2. SAFE CHECK: Prevent num_rows on boolean if query fails
$stmt = $conn->prepare($project_query);
$stmt->bind_param("iiisi", $user_id, $user_id, $user_id, $student_year, $student_sem);
$stmt->execute();
$project_result = $stmt->get_result();
$has_project = ($project_result && $project_result->num_rows > 0);
$project_data = $has_project ? $project_result->fetch_assoc() : null;
$stmt->close();
// Fetch logs for current active project (read-only for students)
$active_project_logs = [];
if ($has_project && isset($project_data['id'])) {
    $active_project_logs = get_project_logs($conn, (int)$project_data['id']);
}

// Fetch PAST Projects for History Tab (Archived OR from previous years)
$history_query = "SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name
                  FROM projects p
                  LEFT JOIN project_members pm ON pm.project_id = p.id
                  LEFT JOIN guide g ON p.assigned_guide_id = g.id
                  WHERE (pm.student_id = ? OR p.leader_id = ? OR p.id IN (SELECT project_id FROM project_members WHERE student_id = ?))
                  AND (p.is_archived = 1 OR p.project_year != ? OR p.semester != ?)
                  ORDER BY p.academic_session DESC, p.id DESC";

$past_projects_data = [];
$stmt = $conn->prepare($history_query);
$stmt->bind_param("iiisi", $user_id, $user_id, $user_id, $student_year, $student_sem);
$stmt->execute();
$past_projects = $stmt->get_result();
if($past_projects) {
    while($tp = $past_projects->fetch_assoc()) {
        $pid = $tp['id'];
        $tp['requests'] = [];
        $tp['logs'] = get_project_logs($conn, $pid);
        
        $p_year = $conn->real_escape_string($tp['project_year']);
        $p_sem = (int)$tp['semester'];
        $p_session = $conn->real_escape_string($tp['academic_session'] ?? 'Current');
        $req_stmt = $conn->prepare("(SELECT * FROM upload_requests WHERE project_id = ?) UNION (SELECT * FROM upload_requests WHERE is_global = 1 AND academic_year = ? AND semester = ? AND academic_session = ?) ORDER BY is_global ASC, id ASC");
        $req_stmt->bind_param("isis", $pid, $p_year, $p_sem, $p_session);
        $req_stmt->execute();
        $reqs = $req_stmt->get_result();
        if($reqs) {
            while($req = $reqs->fetch_assoc()) {
                $rid = $req['id'];
                $req['files'] = [];
                $file_stmt = $conn->prepare("SELECT * FROM student_uploads WHERE request_id = ? ORDER BY uploaded_at DESC");
                $file_stmt->bind_param("i", $rid);
                $file_stmt->execute();
                $files = $file_stmt->get_result();
                if($files) { while($f = $files->fetch_assoc()) { $req['files'][] = $f; } }
                $tp['requests'][] = $req;
            }
        }
        $past_projects_data[$pid] = $tp;
    }
}
$past_projects_json = json_encode($past_projects_data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// ==========================================
// ==========================================
//   HANDLE PASSWORD CHANGE (AJAX-FRIENDLY)
// ==========================================
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $is_ajax = isset($_POST['is_ajax']);

    $stmt = $conn->prepare("SELECT id, password FROM student WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $pass_query = $stmt->get_result();
    
    if ($pass_query && $pass_query->num_rows > 0) {
        $row = $pass_query->fetch_assoc();
        if (verify_and_upgrade_password($conn, 'student', (int)$row['id'], $current_pass, (string)$row['password'])) {
            if ($new_pass === $confirm_pass) {
                $h = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt_u = $conn->prepare("UPDATE student SET password = ? WHERE id = ?");
                $stmt_u->bind_param("si", $h, $user_id);
                $stmt_u->execute();
                $stmt_u->close();
                $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Password changed successfully!</div>";
                if ($is_ajax) send_ajax_response('success', $msg, ['reset' => true]);
            } else {
                $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> New passwords do not match.</div>";
                if ($is_ajax) send_ajax_response('error', $msg);
            }
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Incorrect current password.</div>";
            if ($is_ajax) send_ajax_response('error', $msg);
        }
    }
    $stmt->close();
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
            if(!empty($m) && !empty($n)) {
                // Backend already verifies IDs in set_project_members, 
                // but we check here to correctly identify the leader_id from the form.
                if ($i == $leader_index) {
                    $members_compiled = $n . " (Leader - " . $m . ")\n" . $members_compiled;
                    $stmt = $conn->prepare("SELECT id FROM student WHERE moodle_id = ?");
                    $stmt->bind_param("s", $m);
                    $stmt->execute();
                    $l_result = $stmt->get_result();
                    if($l_result && $l_result->num_rows > 0) $actual_leader_id = $l_result->fetch_assoc()['id'];
                } else {
                    $members_compiled .= $n . " (" . $m . ")\n";
                }
            }
        }
    }

    $dept = ''; $t1 = ''; $t2 = ''; $t3 = '';
    
    $p_id_for_old = isset($project_data['id']) ? (int)$project_data['id'] : 0;
    $extra_data_array = [];
    if ($p_id_for_old > 0) {
        $stmt_old = $conn->prepare("SELECT extra_data FROM projects WHERE id = ?");
        $stmt_old->bind_param("i", $p_id_for_old);
        $stmt_old->execute();
        $old_p = $stmt_old->get_result()->fetch_assoc();
        $stmt_old->close();
        if ($old_p && !empty($old_p['extra_data'])) {
            $extra_data_array = json_decode($old_p['extra_data'], true) ?: [];
        }

    }
    
    foreach ($form_schema as $field) {
        $safe_key = "custom_" . preg_replace('/[^a-zA-Z0-9]/', '_', $field['label']);
        if (isset($_POST[$safe_key])) {
            $val = $_POST[$safe_key];
            if(is_array($val)) { $val = implode(", ", array_map('htmlspecialchars', $val)); } else { $val = htmlspecialchars($val); }
            
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
    
       $extra_json_val = empty($extra_data_array) ? null : json_encode($extra_data_array);

    // --- CREATE NEW PROJECT ---
    if (isset($_POST['submit_project'])) {
        $conn->begin_transaction();
        try {
            $max_num = 0;
            $grp_query = $conn->query("SELECT group_name FROM projects WHERE project_year = '$student_year' AND semester = $student_sem AND division = '$student_div' AND group_name LIKE 'Group %' FOR UPDATE");
            
            if ($grp_query && $grp_query->num_rows > 0) {
                while($g_row = $grp_query->fetch_assoc()) {
                    $parts = explode('-', str_replace('Group ', '', $g_row['group_name']));
                    $num = (int)$parts[0];
                    if ($num > $max_num) { $max_num = $num; }
                }
            }
            
            $next_group_num = $max_num + 1;
            $group_name = "Group " . $next_group_num . "-" . $student_year . "-" . $student_div . "-Sem" . $student_sem;
            
            $stmt = $conn->prepare("INSERT INTO projects (group_name, leader_id, project_year, semester, division, department, topic_1, topic_2, topic_3, extra_data, academic_session) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Current')");
            $stmt->bind_param("sissssssss", $group_name, $actual_leader_id, $student_year, $student_sem, $student_div, $dept, $t1, $t2, $t3, $extra_json_val);

            if ($stmt->execute()) {
                $new_pid = (int)$stmt->insert_id;
                set_project_members($conn, $new_pid, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, (int)$actual_leader_id);
                $conn->commit();
                if (isset($_POST['is_ajax'])) {
                    send_ajax_response('success', 'Project created successfully!', ['reload' => true]);
                }
                header("Location: student_dashboard.php");
                exit();
            } else {
                throw new Exception($stmt->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error creating project group: " . $e->getMessage() . "</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('error', $msg);
        }
    } 
    // --- EDIT EXISTING PROJECT ---
    else {
        $p_id = isset($project_data['id']) ? (int)$project_data['id'] : 0;
        if ($p_id > 0) {
            $stmt = $conn->prepare("UPDATE projects SET leader_id=?, department=?, topic_1=?, topic_2=?, topic_3=?, extra_data=? WHERE id=?");
            $stmt->bind_param("isssssi", $actual_leader_id, $dept, $t1, $t2, $t3, $extra_json_val, $p_id);
            if ($stmt->execute()) {
                set_project_members($conn, (int)$p_id, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, (int)$actual_leader_id);
                $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Registration details updated successfully!</div>";
                if (isset($_POST['is_ajax'])) {
                    send_ajax_response('success', $msg, ['reload' => true]);
                }
                header("Location: student_dashboard.php"); 
                exit();
            } else {
                $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error updating project: " . $stmt->error . "</div>";
            }
        } else {
             $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Project ID missing for update.</div>";
        }
    }
} 



// ==========================================
//   HANDLE FILE UPLOADS/DELETE
// ==========================================
if (isset($_POST['upload_file'])) {
    $req_id = $_POST['request_id'];
    $p_id = $project_data['id'];
    $file = $_FILES['document'];
    $stored = secure_store_uploaded_file($file, "student_req{$req_id}_p{$p_id}");
    if (!$stored['ok']) {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> ".$stored['error']."</div>";
    } else {
        $stu_tag = $student_name . " (Student)";
        $orig = $stored['original'];
        $path = $stored['path'];
        $stmt = $conn->prepare("INSERT INTO student_uploads (project_id, request_id, file_name, file_path, uploaded_by_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $p_id, $req_id, $orig, $path, $stu_tag);
        $stmt->execute();
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-cloud-arrow-up'></i> File uploaded successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
}

if (isset($_POST['delete_file'])) {
    $f_id = (int)$_POST['file_id'];
    $p_id = $project_data['id']; 
    $stmt = $conn->prepare("SELECT file_path FROM student_uploads WHERE id=? AND project_id=?");
    $stmt->bind_param("ii", $f_id, $p_id);
    $stmt->execute();
    $file_result = $stmt->get_result();
    if($file_result && $file_result->num_rows > 0) {
        $file_info = $file_result->fetch_assoc();
        if(file_exists($file_info['file_path'])) unlink($file_info['file_path']);
    }
    $stmt->close();
    $del_stmt = $conn->prepare("DELETE FROM student_uploads WHERE id=? AND project_id=?");
    $del_stmt->bind_param("ii", $f_id, $p_id);
    $del_stmt->execute();
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> File removed successfully.</div>";
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}

// ==========================================
//   HANDLE PROJECT LOGS (CRUD for Students)
// ==========================================
if (isset($_POST['create_log_student']) || isset($_POST['update_log_student'])) {
    if (!$has_project) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> You must be in a project to manage logs.</div>";
    } elseif (empty($project_data['assigned_guide_id'])) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> You cannot manage logs until a mentor is assigned to your project.</div>";
    } else {
        $p_id = (int)$project_data['id'];
        $title = $_POST['log_title'];
        $date_from = $_POST['log_date_from'];
        $date_to = $_POST['log_date_to'];
        $status = 'Working'; // Removed quick status from UI
        
        $planned_arr = $_POST['planned_tasks'] ?? [];
        $achieved_arr = $_POST['achieved_tasks'] ?? [];
        $table_data = [];
        for($i=0; $i < count($planned_arr); $i++) {
            if(!empty($planned_arr[$i]) || !empty($achieved_arr[$i])) {
                $table_data[] = ['planned' => $planned_arr[$i], 'achieved' => $achieved_arr[$i]];
            }
        }
        
        $planned_json = json_encode($table_data);

        if (isset($_POST['update_log_student'])) {
            $log_id = (int)$_POST['log_id'];
            // Verify ownership
            $stmt = $conn->prepare("SELECT id FROM project_logs WHERE id=? AND project_id=?");
            $stmt->bind_param("ii", $log_id, $p_id);
            $stmt->execute();
            $check = $stmt->get_result();
            if ($check && $check->num_rows > 0) {
                $update_stmt = $conn->prepare("UPDATE project_logs SET log_title=?, log_date=?, log_date_to=?, progress_planned=?, progress_achieved=?, updated_at=NOW() WHERE id=?");
                $update_stmt->bind_param("sssssi", $title, $date_from, $date_to, $planned_json, $status, $log_id);
                if ($update_stmt->execute()) {
                    if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Log Book entry updated successfully!', ['reload' => true]);
                    header("Location: student_dashboard.php?msg=log_success");
                    exit();
                }
            }
        } else {
            $stu_tag = $student_name . " (Student)";
            $stmt = $conn->prepare("INSERT INTO project_logs (project_id, created_by_role, created_by_id, created_by_name, log_title, log_date, log_date_to, progress_planned, progress_achieved) VALUES (?, 'student', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissssss", $p_id, $user_id, $stu_tag, $title, $date_from, $date_to, $planned_json, $status);

            if ($stmt->execute()) {
                if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Log Book entry created successfully!', ['reload' => true]);
                header("Location: student_dashboard.php?msg=log_success");
                exit();
            }
        }
        
        if ($conn->error) {
            if (str_contains($conn->error, "Unknown column 'log_date_to'")) {
                $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> <strong>Database Update Required!</strong> Please run the migration command in XAMPP Shell.</div>";
            } else {
                $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> <strong>SQL Error:</strong> " . htmlspecialchars($conn->error) . "</div>";
            }
        }
    }
}

if (isset($_POST['add_log_entry_student'])) {
    $log_id = (int)$_POST['log_id'];
    $desc = $_POST['description'];
    
    // Security: Check if log belongs to student's project
    $p_id = (int)$project_data['id'];
    $stmt = $conn->prepare("SELECT id FROM project_logs WHERE id = ? AND project_id = ?");
    $stmt->bind_param("ii", $log_id, $p_id);
    $stmt->execute();
    $verify = $stmt->get_result();
    if ($verify && $verify->num_rows > 0) {
        if (add_log_entry($conn, $log_id, $desc)) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Entry added to log book!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
            $active_project_logs = get_project_logs($conn, $p_id); // Refresh
        }
    }
}

if (isset($_POST['delete_log_student'])) {
    $log_id = (int)$_POST['log_id'];
    $p_id = (int)$project_data['id'];
    
    // Security: Check if log belongs to student's project
    $stmt = $conn->prepare("SELECT id FROM project_logs WHERE id = ? AND project_id = ?");
    $stmt->bind_param("ii", $log_id, $p_id);
    $stmt->execute();
    $verify = $stmt->get_result();
    if ($verify && $verify->num_rows > 0) {
        if (delete_log($conn, $log_id)) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> Log entry deleted.</div>";
            $active_project_logs = get_project_logs($conn, $p_id); // Refresh
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Project Hub</title>
    
    <style>
    /* Professional Print Styles for Log Book */
    @media print {
        /* Hide everything except the modal content */
        body > *:not(#logPreviewModal) {
            display: none !important;
        }
        
        #logPreviewModal {
            position: static !important;
            display: block !important;
            padding: 0 !important;
            margin: 0 !important;
            background: white !important;
            width: 100% !important;
            height: auto !important;
            overflow: visible !important;
        }

        #logPreviewModal .modal-card {
            box-shadow: none !important;
            border: none !important;
            width: 100% !important;
            max-width: none !important;
            margin: 0 !important;
            padding: 0 !important;
            height: auto !important;
            position: static !important;
            overflow: visible !important;
            display: block !important;
        }

        /* Hide the UI header (Title, Print button, X button) */
        #logPreviewModal .modal-header {
            display: none !important;
        }

        #previewContent {
            padding: 0 !important;
            margin: 0 !important;
            overflow: visible !important;
            height: auto !important;
            display: block !important;
        }

        /* Remove browser default headers/footers */
        @page {
            margin: 1.5cm;
        }
        
        /* Table and Row break logic */
        table { 
            width: 100% !important;
            border-collapse: collapse !important;
            page-break-inside: auto !important; 
        }
        tr { 
            page-break-inside: avoid !important; 
            page-break-after: auto !important; 
        }
        
        .modal-overlay {
            background: none !important;
            position: static !important;
        }
    }
    </style>
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
        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto; transition: width 0.3s ease, opacity 0.3s ease, padding 0.3s ease, margin-right 0.3s ease; overflow-x: hidden; white-space: nowrap;}
        .sidebar.collapsed { width: 0; padding-left: 0; padding-right: 0; margin-right: 0; opacity: 0; border: 0; pointer-events: none; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: var(--text-light); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-weight: 500; transition: all 0.3s; cursor:pointer; white-space: normal; word-break: break-word; line-height: 1.4;}
        .sidebar.collapsed .nav-link { white-space: nowrap; }
        .nav-link.active { background-color: var(--primary-green); color: white; box-shadow: 0 8px 20px rgba(16, 93, 63, 0.2); }
        .nav-link:hover:not(.active) { background-color: var(--input-bg); color: var(--primary-green); }
        .logout-btn { margin-top: auto; color: #EF4444; }

        /* --- MAIN CONTENT --- */
        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding-right: 10px; position: relative;}
        
        .top-navbar { background: var(--card-bg); border-radius: 24px; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: var(--shadow); min-height: 75px; flex-shrink: 0; gap: 20px;}
        .top-navbar-inner { display: flex; align-items: center; width: 100%; justify-content: space-between; }
        .top-navbar-left { display: flex; align-items: center; gap: 15px; }
        
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
                .logs-grid { grid-template-columns: repeat(3, 1fr) !important; }
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
        
        .g-input { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; outline: none; background: var(--input-bg); color: var(--text-dark); transition: all 0.3s ease; font-family: inherit; box-sizing: border-box;}
        .g-input:focus { border-color: var(--primary-green); background: var(--card-bg); box-shadow: 0 0 0 4px rgba(16, 93, 63, 0.1); }
        
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
        
        .modal-header { padding: 25px 30px; background:var(--input-bg); display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); flex-shrink: 0; }
        .modal-tabs { display:flex; gap:15px; padding: 0 30px; background:var(--input-bg); border-bottom:1px solid var(--border-color); overflow-x:auto; flex-shrink: 0;}
        .modal-tab { padding: 15px 20px; font-size: 14px; font-weight: 600; color: var(--text-light); cursor:pointer; border-bottom: 3px solid transparent; transition:0.2s; white-space:nowrap;}
        .modal-tab.active { color: var(--primary-green); border-bottom-color: var(--primary-green); }
        
        .modal-body { padding: 30px; overflow-y:auto; flex:1; box-sizing: border-box;}
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* --- AUTOCOMPLETE UI --- */
        .autocomplete-container { position: relative; width: 100%; }
        .autocomplete-results { 
            position: absolute; 
            top: 100%; 
            left: 0; 
            width: 100%; 
            background: var(--card-bg); 
            border: 1px solid var(--border-color); 
            border-radius: 12px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
            z-index: 100; 
            display: none; 
            max-height: 250px; 
            overflow-y: auto; 
            margin-top: 5px;
            animation: fadeIn 0.2s ease;
        }
        .autocomplete-item { 
            padding: 12px 15px; 
            cursor: pointer; 
            border-bottom: 1px solid var(--border-color); 
            display: flex; 
            flex-direction: column; 
            gap: 2px;
            transition: 0.2s;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:hover { background: var(--input-bg); border-left: 4px solid var(--primary-green); }
        .autocomplete-item .stu-name { font-weight: 600; font-size: 14px; color: var(--text-dark); }
        .autocomplete-item .stu-moodle { font-size: 12px; color: var(--text-light); }
        
        .loading-spinner { 
            position: absolute; 
            right: 15px; 
            top: 50%; 
            transform: translateY(-50%); 
            font-size: 14px; 
            color: var(--primary-green); 
            display: none; 
        }

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
        .mobile-menu-btn { display: block; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; transition: 0.3s; flex-shrink: 0; }
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
            
            .close-sidebar-btn { display: block; }
            
            /* Main Content flows naturally downwards */
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; height: auto; overflow-y: visible;}
            
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-left { flex:1; }
            
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            
            .grid-layout { grid-template-columns: 1fr; }
            .history-card-header { flex-direction: column; align-items: flex-start !important; gap: 15px; }
            .history-card-body { flex-direction: column; gap: 15px; }
            .history-btn { width: 100%; justify-content: center; }
            .logs-grid { grid-template-columns: 1fr !important; }
            
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
            <?php if($project_data['is_locked']): ?>
                <a class="nav-link" onclick="switchTab('logs')" id="tab-logs"><i class="fa-solid fa-book-bookmark"></i> Weekly Log Book</a>
            <?php endif; ?>
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
                        <p style="font-size: 13px; color: var(--text-light); margin:0;"><?php echo htmlspecialchars($student_year); ?> - Sem <?php echo htmlspecialchars($student_sem); ?> - Division <?php echo htmlspecialchars($student_div); ?></p>
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
                            <label>Group Number / Semester</label>
                            <span style="font-weight: 700; color: var(--primary-green); font-size: 16px;"><i class="fa-solid fa-users-viewfinder" style="margin-right:5px;"></i> <?php echo htmlspecialchars($project_data['group_name']); ?></span>
                        </div>

                        <div class="detail-row">
                            <label>Team Members</label>
                            <span style="white-space: pre-line; line-height: 1.6; border-left: 4px solid var(--primary-green); display:block; padding-left:10px;"><?php echo str_replace('[Disabled]', '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>', htmlspecialchars(trim($project_data['member_details']))); ?></span>
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
                        
                        <div style="margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <button type="button" class="btn-outline" onclick="document.getElementById('fullFormModal').style.display='flex'" style="width: 100%; justify-content: center; padding: 10px; font-size: 14px;"><i class="fa-solid fa-file-lines"></i> View Full Form Details</button>
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
                        <form method="POST" class="ajax-form" id="projectForm" class="ajax-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <?php foreach($form_schema as $field): 
                                $safe_name = "custom_" . preg_replace('/[^a-zA-Z0-9]/', '_', $field['label']);
                                $req_attr = (isset($field['required']) && $field['required']) ? 'required' : '';
                                $req_mark = (isset($field['required']) && $field['required']) ? '<span style="color:#EF4444;">*</span>' : '';
                            ?>
                                <?php if($field['type'] === 'team-members'): ?>
                                    <div class="g-card" style="border-left-color: var(--primary-green);">
                                        <label class="g-label"><i class="fa-solid fa-users" style="color:var(--primary-green); margin-right:8px;"></i> <?php echo htmlspecialchars($field['label']); ?> <span style="font-size:12px; color:var(--text-light); font-weight:400;">(Min: <?php echo $min_size; ?>, Max: <?php echo $max_size; ?>)</span></label>
                                        <div id="membersContainer" class="membersContainer">
                                            <div>
                                                <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                                                    <label style="cursor:pointer; display:flex; flex-direction:column; align-items:center;" title="Set as Team Leader">
                                                        <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Leader</span>
                                                        <input type="radio" name="project_leader_index" value="0" checked required>
                                                    </label>
                                                    <input type="text" name="team_moodle[]" value="<?php echo htmlspecialchars($moodle_id); ?>" class="g-input" style="flex:1; pointer-events:none; opacity:0.8;" readonly>
                                                    <input type="text" name="team_name[]" value="<?php echo htmlspecialchars($student_name); ?>" class="g-input fetched-name" style="flex:2; pointer-events:none; color:var(--primary-green); font-weight:600; opacity:0.8;" readonly data-valid="true">
                                                    <div style="width:30px;"></div>
                                                </div>
                                                <span class="member-error" style="display:block; font-size:12px; color:#EF4444; margin-left:45px; margin-bottom:10px;"></span>
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
                $p_year = $conn->real_escape_string($project_data['project_year']);
                $p_sem = (int)$project_data['semester'];
                $reqs = $conn->query("(SELECT * FROM upload_requests WHERE project_id = $p_id) UNION (SELECT * FROM upload_requests WHERE is_global = 1 AND academic_year = '$p_year' AND semester = $p_sem AND academic_session = 'Current') ORDER BY is_global ASC, id ASC");
                if($reqs && $reqs->num_rows > 0): while($req = $reqs->fetch_assoc()):
                    $r_id = $req['id']; $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id ORDER BY uploaded_at DESC");
                ?>
                    <div class="folder-block" <?php if(!empty($req['is_global'])): ?>style="border-left: 3px solid var(--primary-green);"<?php endif; ?>>
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
                                <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('Delete this file?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="file_id" value="<?php echo $f['id']; ?>"><button type="submit" name="delete_file" style="background:none; border:none; color:#EF4444; cursor:pointer;"><i class="fa-solid fa-trash-can"></i></button></form>
                                </div>
                            <?php endwhile; else: echo "<div style='text-align:center; padding:15px 0; font-size:13px; color:var(--text-light);'>No files uploaded.</div>"; endif; ?>
                        </div>
                        <div class="upload-area">
                            <form method="POST" class="ajax-form" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:8px;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                <div style="font-size:11px; color:var(--text-light);">Max file size: 5MB</div>
                                <input type="file" name="document" class="file-input" required>
                                <button type="submit" name="upload_file" style="background:#3B82F6; color:white; border:none; padding:10px; border-radius:8px; font-weight:600; cursor:pointer;"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
                            </form>
                        </div>
                    </div>
                <?php endwhile; else: echo "<div style='grid-column: 1 / -1; text-align:center; padding:50px; background:var(--card-bg); border-radius:24px;'>No Folders Available</div>"; endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($has_project && $project_data['is_locked']): ?>
        <div id="section-logs" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
                <div>
                    <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Project Log Book</h3>
                    <p style="font-size: 13px; color: var(--text-light); margin:0;">Track your weekly project progress and updates.</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <?php if (!empty($project_data['assigned_guide_id'])): ?>
                        <button class="btn-edit-form" onclick="openCreateLogModal()" style="background:var(--primary-green);">
                            <i class="fa-solid fa-plus"></i> Add Weekly Log
                        </button>
                    <?php else: ?>
                        <span style="font-size: 13px; color: #B45309; background: #FFFBEB; padding: 8px 12px; border-radius: 8px; border: 1px solid #FDE68A;"><i class="fa-solid fa-clock"></i> Pending Mentor Assignment</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <?php if (!empty($active_project_logs)): ?>
                    <div class="workspace-grid logs-grid" style="align-items: start; gap: 15px;">
                        <?php foreach($active_project_logs as $log): ?>
                            <div class="folder-block" style="border-left: 5px solid #8B5CF6; background: var(--input-bg); padding: 15px;">
                                <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:10px; cursor:pointer;" onclick="document.getElementById('log_details_<?php echo $log['id']; ?>').style.display = document.getElementById('log_details_<?php echo $log['id']; ?>').style.display === 'none' ? 'block' : 'none';">
                                    <div>
                                        <h4 style="font-size:16px; color:var(--text-dark); margin:0;"><?php echo htmlspecialchars($log['log_title']); ?></h4>
                                        <p style="font-size:11px; color:var(--text-light); margin:4px 0; line-height: 1.4;">
                                            <i class="fa-solid fa-calendar-day"></i> <?php echo $log['log_date'] ? date('M d, Y', strtotime($log['log_date'])) : 'No date set'; ?> - <?php echo !empty($log['log_date_to']) ? date('M d, Y', strtotime($log['log_date_to'])) : 'No date set'; ?>
                                            <br>Created by <?php echo htmlspecialchars($log['created_by_name']); ?>
                                        </p>
                                    </div>
                                    <div style="display:flex; gap:10px; align-items:center;">
                                        <button type="button" onclick="event.stopPropagation(); openEditLogModal(<?php echo $log['id']; ?>)" style="background:none; border:none; color:var(--primary-green); cursor:pointer; font-size:16px;">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" class="ajax-form" onsubmit="return confirm('Delete this entire log entry?');" style="margin:0;" onclick="event.stopPropagation();">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="log_id" value="<?php echo $log['id']; ?>">
                                            <button type="submit" name="delete_log_student" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>

                                <div id="log_details_<?php echo $log['id']; ?>" style="display:none;">
                                    <div style="display:grid; grid-template-columns: 1fr; gap:15px; margin-bottom:15px;">
                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#8B5CF6; display:block; margin-bottom:8px;">Summary of Planned Tasks</label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">
                                            <?php 
                                            try {
                                                $tasks = json_decode($log['progress_planned'], true);
                                                if (is_array($tasks)) {
                                                    foreach($tasks as $t) {
                                                        if(!empty($t['planned'])) echo "• " . htmlspecialchars($t['planned']) . "<br>";
                                                    }
                                                } else {
                                                    echo nl2br(htmlspecialchars($log['progress_planned']));
                                                }
                                            } catch(Exception $e) {
                                                echo nl2br(htmlspecialchars($log['progress_planned']));
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#10B981; display:block; margin-bottom:8px;">Work Status: <?php echo htmlspecialchars($log['progress_achieved'] ?: 'Working'); ?></label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">
                                            <?php 
                                            try {
                                                if (is_array($tasks)) {
                                                    foreach($tasks as $t) {
                                                        if(!empty($t['achieved'])) echo "✓ " . htmlspecialchars($t['achieved']) . "<br>";
                                                    }
                                                } else {
                                                    echo "Current Status: " . htmlspecialchars($log['progress_achieved']);
                                                }
                                            } catch(Exception $e) {}
                                            ?>
                                        </div>
                                    </div>

                                    <div style="margin-top:10px;">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:8px;">Guide Review</label>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); font-size:13px; color:var(--text-dark); line-height:1.5;">
                                            <?php echo !empty($log['guide_review']) ? nl2br(htmlspecialchars($log['guide_review'])) : '<span style="color:var(--text-light); font-style:italic;">No review provided yet.</span>'; ?>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding:60px 20px; color:var(--text-light);">
                        <i class="fa-solid fa-book" style="font-size:50px; color:var(--border-color); margin-bottom:20px;"></i>
                        <h4 style="color:var(--text-dark); margin-bottom:10px;">Your Log Book is Empty</h4>
                        <p style="font-size:14px; max-width:400px; margin:0 auto 20px auto;">Start tracking your weekly project progress by adding your first log entry using the button above.</p>
                        <?php if (!empty($project_data['assigned_guide_id'])): ?>
                            <button class="btn-edit-form" onclick="openCreateLogModal()" style="background:var(--primary-green);">
                                <i class="fa-solid fa-plus"></i> Create First Log
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                            <strong style="font-size:18px; color:var(--text-dark);"><span style="color:var(--text-light); font-size:13px; font-weight:600; text-transform:uppercase; vertical-align:middle; margin-right:5px;">Group No:</span> <?php echo htmlspecialchars($past['group_name']); ?></strong>
                            <div style="font-size:13px; color:var(--text-light); margin-top:5px;">
                                <span style="background:#E0E7FF; color:#4F46E5; padding:4px 8px; border-radius:6px; font-weight:700;"><i class="fa-solid fa-box-archive"></i> <?php echo htmlspecialchars($past['academic_session']); ?></span>
                                <span style="margin-left: 10px;">Year: <?php echo htmlspecialchars($past['project_year']); ?> | Div: <?php echo htmlspecialchars($past['division']); ?></span>
                            </div>
                        </div>
                        <button class="history-btn" onclick='openArchiveModal(<?php echo $past['id']; ?>)'><i class="fa-solid fa-folder-open"></i> View Vault & Details</button>
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
                                <?php if(!empty($past['project_type'])): ?>
                                    <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap:4px;">
                                        <span style="font-size:10px; background:var(--card-bg); color:var(--primary-green); padding:2px 6px; border-radius:4px; font-weight:700; border:1px solid var(--border-color);"><?php echo htmlspecialchars($past['project_type']); ?></span>
                                        <?php 
                                            $goals = json_decode($past['sdg_goals'] ?? '[]', true);
                                            if(!empty($goals)) {
                                                foreach($goals as $g) {
                                                    echo '<span style="font-size:9px; background:var(--note-bg); color:var(--note-text); padding:1px 5px; border-radius:4px; font-weight:600; border:1px solid var(--note-border);"><i class="fa-solid fa-leaf" style="font-size:8px;"></i> '.htmlspecialchars($g).'</span>';
                                                }
                                            }
                                        ?>
                                    </div>
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
                <form method="POST" class="ajax-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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

    <?php if($has_project): ?>
    <div id="fullFormModal" class="modal-overlay">
        <div class="modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-file-lines"></i> Full Form Details</h3>
                <button type="button" onclick="document.getElementById('fullFormModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="overflow-y:auto; flex:1; padding-right:5px;">
                <?php
                $extra_data = [];
                if (!empty($project_data['extra_data'])) {
                    $extra_data = json_decode($project_data['extra_data'], true) ?: [];
                }
                $processed_labels = [];
                foreach ($form_schema as $field) {
                    $val = '';
                    $lbl = $field['label'];
                    $processed_labels[] = $lbl;
                    if (stripos($lbl, 'Department') !== false) $val = $project_data['department'] ?: '<em style="color:gray;">N/A</em>';
                    elseif (stripos($lbl, 'Preference 1') !== false) $val = $project_data['topic_1'] ?: '<em style="color:gray;">N/A</em>';
                    elseif (stripos($lbl, 'Preference 2') !== false) $val = $project_data['topic_2'] ?: '<em style="color:gray;">N/A</em>';
                    elseif (stripos($lbl, 'Preference 3') !== false) $val = $project_data['topic_3'] ?: '<em style="color:gray;">N/A</em>';
                    elseif ($field['type'] === 'team-members') $val = $project_data['member_details'];
                    elseif (isset($extra_data[$lbl])) $val = $extra_data[$lbl];
                    else $val = '<em style="color:gray;">Not answered</em>';

                    echo '<div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed var(--border-color);">';
                    echo '<div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">' . htmlspecialchars($lbl) . '</div>';
                    $displayVal = ($val === '<em style="color:gray;">N/A</em>' || $val === '<em style="color:gray;">Not answered</em>') ? $val : htmlspecialchars($val);
                    $displayVal = str_replace('[Disabled]', '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>', $displayVal);
                    echo '<div style="font-size:14px; color:var(--text-dark); white-space:pre-wrap;">' . $displayVal . '</div>';
                    echo '</div>';
                }
                
                foreach ($extra_data as $key => $val) {
                    if (!in_array($key, $processed_labels)) {
                        echo '<div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed var(--border-color);">';
                        echo '<div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">' . htmlspecialchars($key) . ' <span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px;">Legacy Field</span></div>';
                        echo '<div style="font-size:14px; color:var(--text-dark); white-space:pre-wrap;">' . htmlspecialchars($val) . '</div>';
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($has_project && !$project_data['assigned_guide_id']): ?>
    <div id="studentEditModal" class="modal-overlay">
        <div class="modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-pen-to-square"></i> Edit Registration</h3>
                <button type="button" onclick="document.getElementById('studentEditModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="overflow-y:auto; flex:1; padding-right:5px;">
                <form method="POST" class="ajax-form" id="editProjectForm" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div id="edit_dynamic_form_container"></div>
                    <button type="submit" name="update_project" id="editSubmitBtn" class="btn-submit" style="margin-top:20px;">Save Changes</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="archiveModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: #4F46E5;"><i class="fa-solid fa-box-archive"></i> Archived Project Data</span>
                <button type="button" onclick="document.getElementById('archiveModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" id="tab-arch-overview" onclick="switchArchiveTab('overview')">Form Details</div>
                <div class="modal-tab" id="tab-arch-upload" onclick="switchArchiveTab('upload')">Archived Files</div>
                <div class="modal-tab" id="tab-arch-logs" onclick="switchArchiveTab('logs')">Project Logs</div>
            </div>

            <div class="modal-body">
                <div id="arch-sec-overview" class="tab-content active"></div>
                <div id="arch-sec-upload" class="tab-content"></div>
                <div id="arch-sec-logs" class="tab-content"></div>
            </div>
        </div>
    </div>

    <!-- NEW: Create Log Modal -->
    <div id="createLogModal" class="modal-overlay">
        <div class="modal-card" style="max-width: 800px; max-height: 90vh;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding: 20px 30px;">
                <h3 style="margin:0; font-size: 18px; color: #8B5CF6;"><i class="fa-solid fa-book-medical"></i> Add Weekly Log Entry</h3>
                <button type="button" onclick="document.getElementById('createLogModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="padding:30px; overflow-y:auto; flex:1;">
                <form method="POST" class="ajax-form" id="logForm" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="log_id" id="log_id_field">
                    <div class="g-card" style="padding:15px; margin-bottom:20px;">
                        <label class="g-label" style="font-size:13px;">Week Title / Heading</label>
                        <input type="text" name="log_title" id="log_title_field" placeholder="e.g. Week 4: UI Development" class="g-input" required>
                    </div>
                    
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">
                        <div class="g-card" style="padding:15px; margin-bottom:0;">
                            <label class="g-label" style="font-size:13px;">From Date</label>
                            <input type="date" name="log_date_from" id="log_from_field" value="<?php echo date('Y-m-d'); ?>" class="g-input" required>
                        </div>
                        <div class="g-card" style="padding:15px; margin-bottom:0;">
                            <label class="g-label" style="font-size:13px;">To Date</label>
                            <input type="date" name="log_date_to" id="log_to_field" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="g-input" required>
                        </div>
                    </div>

                    <div class="g-card" style="padding:20px; margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                            <label class="g-label" style="font-size:14px; margin:0;">Detailed Progress Table</label>
                            <button type="button" onclick="addLogRow()" class="btn-edit-form" style="font-size:11px; padding:5px 12px; background:var(--primary-green);">
                                <i class="fa-solid fa-plus"></i> Add Row
                            </button>
                        </div>
                        <div style="max-height: 300px; overflow-y:auto; border:1px solid var(--border-color); border-radius:8px;">
                            <table style="width:100%; border-collapse: collapse; font-size:13px;" id="logTable">
                                <thead style="background:var(--input-bg); position:sticky; top:0; z-index:1;">
                                    <tr>
                                        <th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:50%;">Progress Planned</th>
                                        <th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:45%;">Progress Achieved</th>
                                        <th style="padding:12px; border-bottom:1px solid var(--border-color); width:5%;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Rows will be injected here -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <button type="submit" name="create_log_student" id="logSubmitBtn" class="btn-submit" style="background:#8B5CF6; margin-top:0; width:100%;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Weekly Log
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- NEW: Log Book Preview Modal -->
    <div id="logPreviewModal" class="modal-overlay">
        <div class="modal-card" style="max-width: 900px;">
            <div class="modal-header" style="background:#F9FAFB; display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding: 20px 30px;">
                <div>
                    <h3 style="margin:0; font-size: 18px; color: var(--text-dark);"><i class="fa-solid fa-file-invoice"></i> Project Log Book Preview</h3>
                    <p style="font-size: 12px; color: var(--text-light); margin:0;">Full record of all team activities</p>
                </div>
                <div style="display:flex; gap:10px;">
                    <button onclick="window.print()" class="history-btn" style="background:#374151;">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                    <button type="button" onclick="document.getElementById('logPreviewModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div style="padding: 40px; overflow-y:auto; flex:1; background:white;" id="previewContent">
                <!-- Content will be injected by JS -->
            </div>
        </div>
    </div>

    <script>
        let memberCount = 1; 
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        const minSize = <?php echo $min_size; ?>; const maxSize = <?php echo $max_size; ?>;
        const studentYear = '<?php echo $student_year; ?>'; const studentDiv = '<?php echo $student_div; ?>'; const studentSem = <?php echo (int)$current_sem; ?>; const currentMoodleId = '<?php echo $moodle_id; ?>';
        const activeProjectId = <?php echo isset($project_data['id']) ? $project_data['id'] : 0; ?>;
        const schemasByYear = <?php echo $schemas_json; ?>;
        const pastDataJSON = <?php echo $past_projects_json; ?>;
        const activeLogsData = <?php echo json_encode($active_project_logs); ?>;

        // ==========================================
        //        LOG BOOK MODALS & PREVIEW
        // ==========================================
        function openCreateLogModal() {
            document.getElementById('log_id_field').value = '';
            document.getElementById('log_title_field').value = '';
            document.getElementById('logSubmitBtn').name = 'create_log_student';
            document.getElementById('logSubmitBtn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Weekly Log';
            
            // Clear and add one empty row
            const tbody = document.querySelector('#logTable tbody');
            tbody.innerHTML = '';
            addLogRow();
            
            document.getElementById('createLogModal').style.display = 'flex';
        }

        function openEditLogModal(logId) {
            const log = activeLogsData.find(l => l.id == logId);
            if (!log) return;

            document.getElementById('log_id_field').value = log.id;
            document.getElementById('log_title_field').value = log.log_title;
            document.getElementById('log_from_field').value = log.log_date;
            document.getElementById('log_to_field').value = log.log_date_to;
            
            document.getElementById('logSubmitBtn').name = 'update_log_student';
            document.getElementById('logSubmitBtn').innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Update Weekly Log';

            // Fill table rows
            const tbody = document.querySelector('#logTable tbody');
            tbody.innerHTML = '';
            
            try {
                const tasks = JSON.parse(log.progress_planned);
                if (Array.isArray(tasks) && tasks.length > 0) {
                    tasks.forEach(t => addLogRow(t.planned, t.achieved));
                } else {
                    addLogRow();
                }
            } catch(e) {
                // Fallback for old simple logs
                addLogRow(log.progress_planned, log.progress_achieved);
            }

            document.getElementById('createLogModal').style.display = 'flex';
        }

        function openLogPreview() {
            const container = document.getElementById('previewContent');
            if (!activeLogsData || activeLogsData.length === 0) {
                container.innerHTML = '<div style="text-align:center; padding:50px; color:#6B7280;">No log entries to display.</div>';
            } else {
                // Sort logs strictly by Date (Ascending)
                const sortedLogs = [...activeLogsData].sort((a, b) => {
                    const dateA = new Date(a.log_date || '1970-01-01');
                    const dateB = new Date(b.log_date || '1970-01-01');
                    if (dateA - dateB !== 0) return dateA - dateB;
                    
                    // Fallback: If dates are same, sort by ID
                    return a.id - b.id;
                });
                
                let html = '';
                
                sortedLogs.forEach((log, index) => {
                    const fromDate = log.log_date ? new Date(log.log_date).toLocaleDateString('en-GB') : '___';
                    const toDate = log.log_date_to ? new Date(log.log_date_to).toLocaleDateString('en-GB') : '___';
                    
                    let tableRows = '';
                    try {
                        const tasks = JSON.parse(log.progress_planned);
                        if (Array.isArray(tasks)) {
                            tasks.forEach(task => {
                                tableRows += `
                                    <tr>
                                        <td style="border: 1px solid #000; padding: 8px; vertical-align: top; white-space: pre-wrap;">${task.planned || ''}</td>
                                        <td style="border: 1px solid #000; padding: 8px; vertical-align: top; white-space: pre-wrap;">${task.achieved || ''}</td>
                                    </tr>
                                `;
                            });
                        }
                    } catch(e) {
                        // Fallback for old simple text logs
                        tableRows = `
                            <tr>
                                <td style="border: 1px solid #000; padding: 8px; vertical-align: top; white-space: pre-wrap;">${log.progress_planned}</td>
                                <td style="border: 1px solid #000; padding: 8px; vertical-align: top; white-space: pre-wrap;">${log.progress_achieved}</td>
                            </tr>
                        `;
                    }
                    
                    // Fill empty rows to match template feel
                    for(let i = tableRows.split('<tr>').length - 1; i < 6; i++) {
                        tableRows += '<tr><td style="border: 1px solid #000; padding: 8px; height: 30px;"></td><td style="border: 1px solid #000; padding: 8px; height: 30px;"></td></tr>';
                    }

                    html += `
                        <div style="page-break-after: always; color: #000; font-family: 'Times New Roman', serif; padding: 20px;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 20px; font-weight: bold;">
                                <div>Class: ____________________</div>
                                <div>Sem: ____________________</div>
                            </div>
                            <div style="font-weight: bold; margin-bottom: 30px;">
                                Date: From ${fromDate} to ${toDate}
                            </div>

                            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px;">
                                <thead>
                                    <tr style="background: #f2f2f2;">
                                        <th style="border: 1px solid #000; padding: 10px; text-align: left; width: 50%;">Progress Planned</th>
                                        <th style="border: 1px solid #000; padding: 10px; text-align: left; width: 50%;">Progress Achieved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${tableRows}
                                </tbody>
                            </table>

                            <div style="margin-bottom: 40px;">
                                <div style="font-weight: bold; margin-bottom: 10px;">Guides Review:</div>
                                <div style="border-bottom: 1px solid #000; height: 25px;">${log.guides_review || ''}</div>
                                <div style="border-bottom: 1px solid #000; height: 25px;"></div>
                            </div>

                            <div style="margin-top: 60px;">
                                <div style="font-weight: bold; margin-bottom: 20px;">Signature</div>
                                <div style="margin-bottom: 10px;">Name and Signature of Team Member 1: _________________________________</div>
                                <div style="margin-bottom: 10px;">Name and Signature of Team Member 2: _________________________________</div>
                                <div style="margin-bottom: 10px;">Name and Signature of Team Member 3: _________________________________</div>
                                <div style="margin-bottom: 10px;">Name and Signature of Team Member 4: _________________________________</div>
                                
                                <div style="margin-top: 50px;">
                                    <div style="margin-bottom: 10px;">Name of Project guide: _________________________________</div>
                                    <div style="margin-bottom: 10px;">Signature of guide with Date: _________________________________</div>
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px; color: #666; font-size: 12px;">
                                — ${log.log_title} —
                            </div>
                        </div>
                    `;
                });

                container.innerHTML = html;
            }
            document.getElementById('logPreviewModal').style.display = 'flex';
        }

        function addLogRow(planned = '', achieved = '') {
            const tbody = document.querySelector('#logTable tbody');
            const row = document.createElement('tr');
            row.innerHTML = `
                <td style="padding:10px; border-bottom:1px solid var(--border-color); vertical-align: top;"><textarea name="planned_tasks[]" class="g-input" style="height:80px; font-size:13px; resize:vertical; line-height:1.5;" placeholder="Describe planned tasks here...">${planned}</textarea></td>
                <td style="padding:10px; border-bottom:1px solid var(--border-color); vertical-align: top;"><textarea name="achieved_tasks[]" class="g-input" style="height:80px; font-size:13px; resize:vertical; line-height:1.5;" placeholder="Describe achieved tasks here...">${achieved}</textarea></td>
                <td style="padding:10px; text-align:center; border-bottom:1px solid var(--border-color); vertical-align: middle;"><button type="button" style="background:rgba(239,68,68,0.1); color:#EF4444; border:none; width:36px; height:36px; border-radius:8px; cursor:pointer; transition:0.2s;" onmouseover="this.style.background='#EF4444'; this.style.color='white';" onmouseout="this.style.background='rgba(239,68,68,0.1)'; this.style.color='#EF4444';" onclick="this.closest('tr').remove()"><i class="fa-solid fa-trash-can"></i></button></td>
            `;
            tbody.appendChild(row);
        }

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
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.toggle('active');
                document.getElementById('mobileOverlay').classList.toggle('active');
            } else {
                document.getElementById('sidebar').classList.toggle('collapsed');
            }
        }

        // Dashboard Tab Switching
        function switchTab(tab) {
            sessionStorage.setItem('student_active_tab', tab);

            document.getElementById('section-overview').style.display = 'none';
            document.getElementById('section-history').style.display = 'none';
            document.getElementById('section-settings').style.display = 'none';
            if(document.getElementById('section-workspace')) document.getElementById('section-workspace').style.display = 'none';
            if(document.getElementById('section-logs')) document.getElementById('section-logs').style.display = 'none';
            
            document.getElementById('tab-overview').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-settings').classList.remove('active');
            if(document.getElementById('tab-workspace')) document.getElementById('tab-workspace').classList.remove('active');
            if(document.getElementById('tab-logs')) document.getElementById('tab-logs').classList.remove('active');
            
            document.getElementById('section-' + tab).style.display = 'block';
            document.getElementById('tab-' + tab).classList.add('active');
            
            // Close mobile menu if open
            if(window.innerWidth <= 768) toggleMobileMenu();
        }

        function switchArchiveTab(tab) {
            document.getElementById('tab-arch-overview').classList.remove('active');
            document.getElementById('tab-arch-upload').classList.remove('active');
            document.getElementById('tab-arch-logs').classList.remove('active');

            document.getElementById('arch-sec-overview').classList.remove('active');
            document.getElementById('arch-sec-upload').classList.remove('active');
            document.getElementById('arch-sec-logs').classList.remove('active');

            document.getElementById('tab-arch-' + tab).classList.add('active');
            document.getElementById('arch-sec-' + tab).classList.add('active');
        }

        // ADD NEW MEMBER TO INITIAL FORM
        function addMemberRow() {
            if(memberCount >= maxSize) { alert(`Maximum size is ${maxSize}.`); return; }
            const container = document.getElementById('membersContainer');
            const row = document.createElement('div');
            let idx = memberCount++;
            row.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                    <label style="cursor:pointer; display:flex; flex-direction:column; align-items:center;" title="Set as Team Leader">
                        <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Leader</span>
                        <input type="radio" name="project_leader_index" value="${idx}" required>
                    </label>
                    <div class="autocomplete-container" style="flex:1;">
                        <input type="text" name="team_moodle[]" class="g-input" placeholder="Moodle ID or Name" oninput="handleInput(this)" onblur="handleBlur(this)" style="width:100%;" required autocomplete="off">
                        <i class="fa-solid fa-circle-notch fa-spin loading-spinner"></i>
                        <div class="autocomplete-results"></div>
                    </div>
                    <input type="text" name="team_name[]" class="g-input fetched-name" readonly style="flex:2; opacity:0.8; pointer-events:none; background:var(--input-bg);" placeholder="Member Name">
                    <button type="button" onclick="removeMemberRow(this)" style="background:none; border:none; color:#EF4444; font-size:18px; cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <span class="member-error" style="display:block; font-size:12px; color:#EF4444; margin-left:45px; margin-bottom:10px;"></span>
            `;
            container.appendChild(row); 
            validateForm();
        }
        function removeMemberRow(btn) { btn.parentElement.parentElement.remove(); memberCount--; validateForm(); }

        let searchTimeout;
        const studentCache = {}; // Local cache for faster performance
        const abortControllers = new Map(); // To cancel pending requests
        function handleInput(input) {
            const query = input.value.trim();
            const wrapper = input.closest('.membersContainer > div, #editMembersContainer > div');
            const resultsBox = wrapper.querySelector('.autocomplete-results');
            const spinner = wrapper.querySelector('.loading-spinner');
            const nameBox = wrapper.querySelector('.fetched-name');
            const errorBox = wrapper.querySelector('.member-error');

            // Reset name and clear error instantly as user types
            if (nameBox) {
                nameBox.value = '';
                nameBox.setAttribute('data-valid', 'false');
            }
            if (errorBox) errorBox.innerText = '';
            
            if (document.getElementById('submitBtn')) validateForm();
            if (document.getElementById('editSubmitBtn')) validateEditForm();

            if (query === '') return;

            clearTimeout(searchTimeout);
            resultsBox.style.display = 'none';

            // 1. If it doesn't contain spaces, treat as Moodle ID and trigger direct fetch
            if (query !== '' && !query.includes(' ')) {
                searchTimeout = setTimeout(() => fetchStudentDetails(input), 150); 
                return;
            }

            // 2. Otherwise, treat as Name search for autocomplete
            if (query.length < 2) return;

            searchTimeout = setTimeout(() => {
                spinner.style.display = 'block';
                
                let fetchUrlSearch = `get_student.php?query=${encodeURIComponent(query)}&year=${studentYear}&div=${studentDiv}&sem=${studentSem}`;
                if (activeProjectId > 0) {
                    fetchUrlSearch += `&ignore_pid=${activeProjectId}`;
                }
                fetch(fetchUrlSearch)
                .then(r => r.json())
                .then(data => {
                    spinner.style.display = 'none';
                    resultsBox.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(student => {
                            if (student.moodle_id === currentMoodleId) return;
                            const item = document.createElement('div');
                            item.className = 'autocomplete-item';
                            item.innerHTML = `<span class="stu-name">${student.full_name}</span><span class="stu-moodle">ID: ${student.moodle_id}</span>`;
                            item.onmousedown = (e) => e.preventDefault(); 
                            item.onclick = () => {
                                input.value = student.moodle_id;
                                resultsBox.style.display = 'none';
                                fetchStudentDetails(input);
                            };
                            resultsBox.appendChild(item);
                        });
                        resultsBox.style.display = 'block';
                    }
                })
                .catch(() => spinner.style.display = 'none');
            }, 250);
        }

        function handleBlur(input) {
            setTimeout(() => {
                const resultsBox = input.closest('.autocomplete-container').querySelector('.autocomplete-results');
                resultsBox.style.display = 'none';
                let val = input.value.trim();
                if (val !== '' && !val.includes(' ')) {
                    fetchStudentDetails(input);
                }
            }, 200);
        }

        // FETCH STUDENT DATA (OPTIMIZED)
        function fetchStudentDetails(inputElement) {
            const moodleId = inputElement.value.trim();
            const wrapper = inputElement.closest('.membersContainer > div, #editMembersContainer > div'); 
            if (!wrapper) return; 
            const nameBox = wrapper.querySelector('.fetched-name');
            const errorBox = wrapper.querySelector('.member-error');
            const spinner = wrapper.querySelector('.loading-spinner');

            if (moodleId === '') return;
            if (errorBox) errorBox.innerText = ''; 
            
            // Show fetching state
            if (nameBox) {
                nameBox.value = 'Fetching...';
                nameBox.style.color = 'var(--text-light)';
            }
            if (moodleId === currentMoodleId) { 
                nameBox.value = 'Validation Error'; 
                nameBox.style.color = '#EF4444'; 
                errorBox.innerText = "You are already in the group!"; 
                validateForm(); return; 
            }

            // Check Cache
            if (studentCache[moodleId]) {
                const data = studentCache[moodleId];
                applyStudentData(data, nameBox, errorBox);
                return;
            }

            // Cancel previous request for this input if exists
            if (abortControllers.has(inputElement)) {
                abortControllers.get(inputElement).abort();
            }
            const controller = new AbortController();
            abortControllers.set(inputElement, controller);

            nameBox.value = 'Fetching...';
            if(spinner) spinner.style.display = 'block';

            let fetchUrl = `get_student.php?moodle_id=${moodleId}&year=${studentYear}&div=${studentDiv}&sem=${studentSem}`;
            if (activeProjectId > 0) {
                fetchUrl += `&ignore_pid=${activeProjectId}`;
            }

            fetch(fetchUrl, { signal: controller.signal })
            .then(r => r.json())
            .then(data => {
                if(spinner) spinner.style.display = 'none';
                studentCache[moodleId] = data; // Cache it
                applyStudentData(data, nameBox, errorBox);
                abortControllers.delete(inputElement);
            })
            .catch(err => {
                if (err.name === 'AbortError') return;
                if(spinner) spinner.style.display = 'none';
                console.error(err);
            });
        }

        function applyStudentData(data, nameBox, errorBox) {
            if (data.status === 'success') { 
                nameBox.value = data.name; 
                nameBox.style.color = 'var(--primary-green)'; 
                errorBox.innerText = ''; 
                nameBox.style.fontWeight = '700';
                nameBox.setAttribute('data-valid', 'true');
            } else { 
                nameBox.value = 'Validation Error'; 
                nameBox.style.color = '#EF4444'; 
                errorBox.innerText = data.message; 
                nameBox.style.fontWeight = '400';
                nameBox.setAttribute('data-valid', 'false');
            }
            
            if (document.getElementById('submitBtn')) validateForm();
            if (document.getElementById('editSubmitBtn')) validateEditForm();
        }

        // VALIDATE INITIAL FORM
        function validateForm() {
            let submitBtn = document.getElementById('submitBtn');
            if (!submitBtn) return;
            let hasError = false; let validMembersCount = 0; let enteredValues = [];
            document.querySelectorAll('input[name="team_moodle[]"]').forEach(inp => {
                let val = inp.value.trim(); 
                let wrapper = inp.closest('.membersContainer > div, #editMembersContainer > div');
                if (!wrapper) return;
                let errBox = wrapper.querySelector('.member-error');
                let nameBox = wrapper.querySelector('.fetched-name');

                if(val !== '') {
                    validMembersCount++;
                    
                    // 1. Check if name is fetched
                    if (!nameBox || nameBox.value === '' || nameBox.value === 'Member Name') {
                        hasError = true;
                    }
                    
                    // 2. Check for Validation Errors
                    if (nameBox && nameBox.getAttribute('data-valid') === 'false') {
                        hasError = true;
                    }

                    // 3. Check for duplicates
                    if(enteredValues.includes(val)) { 
                        hasError = true; 
                        if(errBox) errBox.innerText = "Duplicate ID!"; 
                    } 
                    else { 
                        if(errBox && errBox.innerText==="Duplicate ID!") errBox.innerText=""; 
                        enteredValues.push(val); 
                    }
                }
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

             let headerHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                    <div>
                        <h3 style="margin:0; font-size:22px; color:var(--text-dark);"><span style="color:var(--text-light); font-size:14px; font-weight:600; text-transform:uppercase; vertical-align:middle; margin-right:5px;">Group No:</span>${data.group_name}</h3>
                        <p style="margin:0; color:var(--text-light); font-size:14px;">${data.project_year} - Div ${data.division} | Session: ${data.academic_session}</p>
                    </div>
                    ${data.is_locked ? '<span class="badge badge-success" style="font-size:14px;"><i class="fa-solid fa-check-circle"></i> Topic Finalized</span>' : '<span class="badge badge-warning" style="font-size:14px;">Not Finalized</span>'}
                </div>
            `;

            // -- TAB 1: FORM DETAILS --
            let extraData = {};
            if (data.extra_data) { try { extraData = JSON.parse(data.extra_data); } catch(e) {} }
            let schemaArray = schemasByYear[data.project_year] || [];
            let escapeHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
            
            let formDetailsHtml = `
                ${headerHtml}
                <div style="background:var(--card-bg); border:1px solid var(--border-color); border-radius:12px; padding:20px;">
                    <div style="font-size:12px; font-weight:700; color:var(--primary-green); margin-bottom:15px; text-transform:uppercase; letter-spacing:1px;"><i class="fa-solid fa-users"></i> Group Overview</div>
                    
                    <div style="margin-bottom:15px;">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">Team Members</div>
                        <div style="font-size:14px; color:var(--text-dark); white-space:pre-line; line-height:1.6; border-left:3px solid var(--primary-green); padding-left:10px;">${escapeHTML(data.member_details || '').replace(/\[Disabled\]/g, '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>').replace(/\n/g, '<br>')}</div>
                    </div>

                    <div style="margin-bottom:15px;">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">Topic Preferences</div>
                        <div style="font-size:14px; color:var(--text-dark); line-height:1.6;">
                            ${data.topic_1 ? `<div><b style="color:var(--primary-green);">1.</b> ${escapeHTML(data.topic_1)}</div>` : ''}
                            ${data.topic_2 ? `<div><b style="color:var(--primary-green);">2.</b> ${escapeHTML(data.topic_2)}</div>` : ''}
                            ${data.topic_3 ? `<div><b style="color:var(--primary-green);">3.</b> ${escapeHTML(data.topic_3)}</div>` : ''}
                            ${!data.topic_1 && !data.topic_2 && !data.topic_3 ? `<em style="color:gray;">No topics submitted</em>` : ''}
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">Final Approved Topic</div>
                        <div style="font-size:16px; color:var(--primary-green); font-weight:700;">${data.final_topic ? escapeHTML(data.final_topic) : '<em style="color:gray;">N/A</em>'}</div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">Project Classification</div>
                        <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:5px;">
                            <span style="background:var(--input-bg); color:var(--primary-green); border:1px solid var(--border-color); padding:4px 10px; border-radius:8px; font-size:12px; font-weight:700;"><i class="fa-solid fa-layer-group"></i> Type: ${data.project_type || 'N/A'}</span>
                            ${(function(){
                                try {
                                    let goals = JSON.parse(data.sdg_goals || '[]');
                                    return goals.map(g => `<span style="background:var(--note-bg); color:var(--note-text); border:1px solid var(--note-border); padding:4px 10px; border-radius:8px; font-size:11px; font-weight:600;"><i class="fa-solid fa-leaf"></i> ${g}</span>`).join('');
                                } catch(e) { return ''; }
                            })()}
                        </div>
                    </div>

                    <button type="button" class="btn-action" onclick="document.getElementById('full_form_archive_stu_' + ${data.id}).style.display='block'; this.style.display='none';" style="width: 100%; justify-content: center; padding: 10px; font-size: 14px; background:var(--bg-color); color:var(--primary-green); border:1px solid var(--primary-green);"><i class="fa-solid fa-file-lines"></i> View Full Form Details</button>
                    
                    <div id="full_form_archive_stu_${data.id}" style="display:none; margin-top:20px; border-top:1px dashed var(--border-color); padding-top:20px;">
                        <div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase; letter-spacing:1px;">Original Form Submission</div>
            `;
            
            let processedKeys = [];
            
            schemaArray.forEach(field => {
                let val = '';
                processedKeys.push(field.label);
                if (field.label.toLowerCase().includes('department')) val = data.department || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 1')) val = data.topic_1 || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 2')) val = data.topic_2 || '<em style="color:gray;">N/A</em>';
                else if (field.label.toLowerCase().includes('preference 3')) val = data.topic_3 || '<em style="color:gray;">N/A</em>';
                else if (field.type === 'team-members') val = data.member_details;
                else if (extraData[field.label]) val = extraData[field.label];
                else val = '<em style="color:gray;">Not answered</em>';

                let displayVal = val;
                if (val !== '<em style="color:gray;">N/A</em>' && val !== '<em style="color:gray;">Not answered</em>') {
                    displayVal = escapeHTML(val).replace(/\[Disabled\]/g, '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>');
                }

                formDetailsHtml += `
                    <div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed var(--border-color);">
                        <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">${escapeHTML(field.label)}</div>
                        <div style="font-size:15px; color:var(--text-dark); white-space:pre-wrap;">${displayVal}</div>
                    </div>
                `;
            });

            for (const key in extraData) {
                if (!processedKeys.includes(key)) {
                    formDetailsHtml += `
                        <div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px dashed var(--border-color);">
                            <div style="font-size:12px; color:var(--text-light); font-weight:600; margin-bottom:5px;">${escapeHTML(key)} <span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px;">Legacy Field</span></div>
                            <div style="font-size:15px; color:var(--text-dark); white-space:pre-wrap;">${escapeHTML(extraData[key])}</div>
                        </div>
                    `;
                }
            }

            formDetailsHtml += `
                    </div>
                </div>
            `;

            // -- TAB 2: UPLOADS --
            let uploadsHtml = `<div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase;">Archived Files</div>`;
            
            if(data.requests && data.requests.length > 0) {
                data.requests.forEach(req => {
                    
                   // Generate the blue note box if instructions exist
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div style="font-size: 12px; color: #1D4ED8; margin-bottom: 15px; background: #EFF6FF; border-left: 3px solid #3B82F6; padding: 8px 12px; border-radius: 8px; line-height: 1.5;"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
                    }

                    uploadsHtml += `<div style="border:1px solid var(--border-color); border-radius:12px; padding:15px; margin-bottom:15px; background:var(--card-bg);">
                        <div style="font-weight:600; color:var(--text-dark); margin-bottom:10px;"><i class="fa-solid fa-folder" style="color:#F59E0B; margin-right:8px;"></i> ${req.folder_name}</div>
                        ${noteHtml}`;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            uploadsHtml += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--input-bg); padding:10px 15px; border-radius:8px; margin-bottom:8px; border:1px solid var(--border-color);">
                                <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:var(--btn-blue); text-decoration:none;"><i class="fa-regular fa-file-pdf"></i> ${f.file_name}</a>
                                <span style="font-size:11px; color:var(--text-light);">By: ${f.uploaded_by_name}</span>

                            </div>`;
                        });
                    } else {
                uploadsHtml += `<div style="text-align:center; padding:20px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No files were uploaded by this group.</div>`;
                    }
                    uploadsHtml += `</div>`;
                });
            } else {
                uploadsHtml += `<div style="text-align:center; padding:20px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No files were uploaded by this group.</div>`;
            }

            // -- TAB 3: LOGS --
            let logsHtml = `<div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase;">Archived Logs</div>`;
            if (data.logs && data.logs.length > 0) {
                data.logs.forEach(log => {
                    let safeTitle = log.log_title ? String(log.log_title).replace(/'/g, "\\'").replace(/\"/g, '&quot;') : 'Untitled';
                    let fromDateStr = log.log_date ? new Date(log.log_date).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'N/A';
                    let toDateStr = log.log_date_to ? new Date(log.log_date_to).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'N/A';
                    
                    let plannedHtml = ''; let achievedHtml = '';
                    try {
                        let tasks = Array.isArray(log.progress_planned) ? log.progress_planned : JSON.parse(log.progress_planned || '[]');
                        if (Array.isArray(tasks)) {
                            tasks.forEach(t => {
                                if(t.planned) plannedHtml += `• ${t.planned}<br>`;
                                if(t.achieved) achievedHtml += `✓ ${t.achieved}<br>`;
                            });
                        } else { plannedHtml = log.progress_planned || ''; }
                    } catch(e) { plannedHtml = log.progress_planned || ''; }

                    let reviewHtml = log.guide_review ? String(log.guide_review).replace(/\n/g, '<br>') : '<em style="color:gray;">No review provided.</em>';

                    logsHtml += `
                        <div style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; padding:15px; margin-bottom:15px;">
                            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:15px;">
                                <div>
                                    <h4 style="font-size:16px; color:var(--text-dark); margin:0;">${safeTitle}</h4>
                                    <p style="font-size:11px; color:var(--text-light); margin:4px 0; line-height:1.4;">
                                        <i class="fa-solid fa-calendar-day"></i> ${fromDateStr} - ${toDateStr}<br>Created by ${log.created_by_name}
                                    </p>
                                </div>
                                <span style="background:#E0E7FF; color:#4F46E5; border:1px solid #C7D2FE; font-size:11px; padding:4px 8px; border-radius:6px; font-weight:700; height:fit-content;">Read-Only</span>
                            </div>
                            <div style="display:grid; grid-template-columns: 1fr; gap:10px;">
                                <div style="background:var(--card-bg); padding:10px; border-radius:8px; border:1px solid var(--border-color);">
                                    <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#8B5CF6; display:block; margin-bottom:4px;">Planned Tasks</label>
                                    <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${plannedHtml}</div>
                                </div>
                                <div style="background:var(--card-bg); padding:10px; border-radius:8px; border:1px solid var(--border-color);">
                                    <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#10B981; display:block; margin-bottom:4px;">Achieved Tasks</label>
                                    <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${achievedHtml}</div>
                                </div>
                                <div style="background:var(--card-bg); padding:10px; border-radius:8px; border:1px solid var(--border-color);">
                                    <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:4px;">Guide Review</label>
                                    <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${reviewHtml}</div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                logsHtml += `<div style="text-align:center; padding:30px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No logs recorded for this archived project.</div>`;
            }

            document.getElementById('arch-sec-overview').innerHTML = formDetailsHtml;
            document.getElementById('arch-sec-upload').innerHTML = uploadsHtml;
            document.getElementById('arch-sec-logs').innerHTML = logsHtml;
            
            switchArchiveTab('overview');
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

            let processedSchemaLabels = [];

                formSchemaEdit.forEach(field => {
                processedSchemaLabels.push(field.label);
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

            let escapeHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
            for (let key in extraData) {
                if (!processedSchemaLabels.includes(key)) {
                    container.innerHTML += `
                        <div style="margin-bottom:15px;">
                            <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">${escapeHTML(key)} <span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px;">Legacy Field</span></label>
                            <input type="text" class="g-input" value="${escapeHTML(extraData[key])}" readonly style="opacity:0.7;">
                        </div>
                    `;
                }
            }

                document.getElementById('studentEditModal').style.display = 'flex';
            }
            
            function addEditMemberRow(moodle = '', name = '', isLeader = false) {
                const container = document.getElementById('editMembersContainer');
                let idx = editMemberCount++;
                const row = document.createElement('div');
                
                let isCurrentUser = (moodle === currentMoodleId);
                let readonlyAttr = isCurrentUser ? 'readonly style="width:100%; pointer-events:none; opacity:0.8;"' : 'style="width:100%;"';
                let nameStyle = isCurrentUser ? 'pointer-events:none; color:var(--primary-green); font-weight:600; opacity:0.8;' : '';
                let removeBtn = isCurrentUser ? `<div style="width:30px;"></div>` : `<button type="button" onclick="removeEditMemberRow(this)" style="background:none; border:none; color:#EF4444; font-size:18px; cursor:pointer; width:30px;"><i class="fa-solid fa-xmark"></i></button>`;
                
                row.innerHTML = `
                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                        <label style="cursor:pointer; display:flex; flex-direction:column; align-items:center;" title="Set as Team Leader">
                            <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Leader</span>
                            <input type="radio" name="project_leader_index" value="${idx}" ${isLeader ? 'checked' : ''} required>
                        </label>
                        <div class="autocomplete-container" style="flex:1;">
                            <input type="text" name="team_moodle[]" class="g-input" value="${moodle}" placeholder="Moodle ID or Name" oninput="handleInput(this)" onblur="handleBlur(this)" ${readonlyAttr} required autocomplete="off">
                            <i class="fa-solid fa-circle-notch fa-spin loading-spinner"></i>
                            <div class="autocomplete-results"></div>
                        </div>
                        <input type="text" name="team_name[]" value="${name}" class="g-input fetched-name" placeholder="Member Name" readonly style="flex:2; ${nameStyle} background:var(--input-bg);" data-valid="true">
                        ${removeBtn}
                    </div>
                    <span class="member-error" style="display:block; font-size:12px; color:#EF4444; margin-left:45px; margin-bottom:10px;"></span>
                `;
                container.appendChild(row);
                validateEditForm();
            }

            function removeEditMemberRow(btn) {
                btn.parentElement.parentElement.remove();
                validateEditForm();
            }

            function fetchEditStudentDetails(inputElement) {
                fetchStudentDetails(inputElement);
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
                    let wrapper = inp.closest('.membersContainer > div, #editMembersContainer > div');
                    if (!wrapper) return;
                    let errBox = wrapper.querySelector('.member-error');
                    let nameBox = wrapper.querySelector('.fetched-name');

                    if(val !== '') {
                        validMembersCount++;
                        
                        // 1. Check if name is fetched
                        if (!nameBox || nameBox.value === '' || nameBox.value === 'Member Name' || nameBox.value === 'Auto-Fetched Name') {
                            hasError = true;
                        }

                        // 2. Check for Validation Errors
                        if (nameBox && nameBox.getAttribute('data-valid') === 'false') {
                            hasError = true;
                        }

                        // 3. Check for duplicates
                        if(enteredValues.includes(val)) { 
                            hasError = true; 
                            if(errBox) errBox.innerText = "Duplicate ID entered!"; 
                        } 
                        else { 
                            if (errBox && errBox.innerText === "Duplicate ID entered!") errBox.innerText = ""; 
                            enteredValues.push(val); 
                        }
                    }
                });

                let submitText = 'Save Changes';
                if (validMembersCount < minSize || validMembersCount > maxSize) {
                    hasError = true;
                    submitText = `Team must be ${minSize} to ${maxSize} members (Currently ${validMembersCount})`;
                }
                
                submitBtn.disabled = hasError;
                submitBtn.innerText = submitText;
            }

            window.onload = function() {
                if(document.getElementById('submitBtn')) validateForm();
                
                // Auto-switch to logs tab if we just saved a log
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('msg') === 'log_success') {
                    switchTab('logs');
                }
            };
        <?php endif; ?>
    </script>
    <script src="assets/js/ajax-forms.js?v=1.2"></script>
</body>
</html>