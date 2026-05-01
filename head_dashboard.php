<?php
session_start();
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'head') { header("Location: index.php"); exit(); }

verify_csrf_token();

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
$schemas_json = json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// ==============================================
//           ACCOUNT SETTINGS
// ==============================================
// ==============================================
//   ACCOUNT SETTINGS (AJAX-FRIENDLY)
// ==============================================
if (isset($_POST['change_password'])) {
    $current_pass = $conn->real_escape_string($_POST['current_password']);
    $new_pass = $conn->real_escape_string($_POST['new_password']);
    $confirm_pass = $conn->real_escape_string($_POST['confirm_password']);
    $is_ajax = isset($_POST['is_ajax']);

    $row = $conn->query("SELECT id, password FROM head WHERE id = $head_id")->fetch_assoc();
    if ($row && verify_and_upgrade_password($conn, 'head', (int)$row['id'], $current_pass, (string)$row['password'])) {
        if ($new_pass === $confirm_pass) {
            $h = $conn->real_escape_string(password_hash($new_pass, PASSWORD_DEFAULT));
            $conn->query("UPDATE head SET password = '$h' WHERE id = $head_id");
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
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    $active_tab = 'resets';
}

if(isset($_POST['reject_request'])) {
    $req_id = (int)$_POST['request_id'];
    $conn->query("UPDATE password_reset_requests SET status='Rejected' WHERE id=$req_id");
    $msg = "<div class='alert-error'><i class='fa-solid fa-xmark'></i> Request rejected.</div>";
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
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
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
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
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}

if (isset($_POST['finalize_topic'])) {
    $project_id = (int)$_POST['project_id'];
    $final_topic = $conn->real_escape_string($_POST['final_topic']);
    $is_ajax = isset($_POST['is_ajax']);
    
    if ($final_topic === 'unfinalize') {
        $conn->query("UPDATE projects SET final_topic = NULL, is_locked = 0 WHERE id = $project_id AND project_year = '$assigned_year'");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-unlock'></i> Topic unlocked and reverted to Pending!</div>";
        if ($is_ajax) send_ajax_response('success', $msg, ['reload' => true]);
    } else {
        if ($final_topic === 'custom') {
            $final_topic = $conn->real_escape_string($_POST['custom_topic']);
        }
        $conn->query("UPDATE projects SET final_topic = '$final_topic', is_locked = 1 WHERE id = $project_id AND project_year = '$assigned_year'");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-lock'></i> Topic safely locked and finalized!</div>";
        if ($is_ajax) send_ajax_response('success', $msg, ['reload' => true]);
    }
}

// ==============================================
//           HEAD SUPER POST ACTIONS
// ==============================================
if (isset($_POST['update_classification'])) {
    $p_id = (int)$_POST['project_id'];
    $type = $conn->real_escape_string($_POST['project_type']);
    $goals = isset($_POST['sdg_goals']) ? json_encode($_POST['sdg_goals']) : '[]';
    
    $sql = "UPDATE projects SET project_type='$type', sdg_goals='$goals' WHERE id=$p_id";
    if ($conn->query($sql)) {
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Project classification updated successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
        $active_tab = 'dashboard';
        $reopen_modal_project_id = $p_id;
    } else {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: " . $conn->error . "</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', $msg);
    }
}

if (isset($_POST['head_edit_project'])) {
    $p_id = (int)$_POST['edit_project_id'];
    $g_id = empty($_POST['edit_guide_id']) ? "NULL" : (int)$_POST['edit_guide_id'];
    
    $member_moodles = $_POST['team_moodle'] ?? [];
    $member_names = $_POST['team_name'] ?? [];
    $leader_index = $_POST['project_leader_index'] ?? 0;
    
    $members_compiled = "";

    if (is_array($member_moodles)) {
        for($i = 0; $i < count($member_moodles); $i++) {
            $m = trim($conn->real_escape_string($member_moodles[$i]));
            $n = trim($member_names[$i]);
            if(!empty($m) && !empty($n) && !str_contains($n, 'Error') && !str_contains($n, 'Not Found')) {
                if ($i == $leader_index) {
                    $members_compiled = $n . " (Leader - " . $m . ")\n" . $members_compiled;
                } else {
                    $members_compiled .= $n . " (" . $m . ")\n";
                }
            }
        }
    }

    $dept = ''; $t1 = ''; $t2 = ''; $t3 = '';
    
    $old_p = $conn->query("SELECT extra_data FROM projects WHERE id = $p_id")->fetch_assoc();
    $extra_data_array = [];
    if ($old_p && !empty($old_p['extra_data'])) {
        $extra_data_array = json_decode($old_p['extra_data'], true) ?: [];
    }

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

    $encoded = json_encode($extra_data_array, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $extra_json = empty($extra_data_array) ? "NULL" : "'" . $conn->real_escape_string(is_string($encoded) ? $encoded : '{}') . "'";

    $sql = "UPDATE projects SET assigned_guide_id=$g_id, department='$dept', topic_1='$t1', topic_2='$t2', topic_3='$t3', extra_data=$extra_json WHERE id = $p_id AND project_year = '$assigned_year'";
    if ($conn->query($sql) === TRUE) {
        $q_ldr = $conn->query("SELECT student_id FROM project_members WHERE project_id = $p_id AND is_leader = 1");
        $fallback_leader = ($q_ldr && $q_ldr->num_rows > 0) ? (int)$q_ldr->fetch_assoc()['student_id'] : 0;
        set_project_members($conn, (int)$p_id, is_array($member_moodles) ? $member_moodles : [], (int)$leader_index, $fallback_leader);
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Form Details updated dynamically!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
}

// FOLDER ACTIONS
if (isset($_POST['create_folder'])) {
    $p_id = (int)$_POST['target_project_id'];
    // FIXED: Changed NULL to 0 to prevent "Column 'guide_id' cannot be null" database crashes
    $g_id = empty($_POST['current_guide_id']) ? 0 : (int)$_POST['current_guide_id'];
    $folder_name = $conn->real_escape_string($_POST['folder_name']);
    $instructions = isset($_POST['instructions']) ? $conn->real_escape_string($_POST['instructions']) : '';
    
    $check = $conn->query("SELECT id FROM upload_requests WHERE project_id = $p_id AND folder_name = '$folder_name'");
    if ($check && $check->num_rows > 0) {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Folder '$folder_name' already exists!</div>";
    } else {
        $conn->query("INSERT INTO upload_requests (guide_id, project_id, folder_name, instructions) VALUES ($g_id, $p_id, '$folder_name', '$instructions')");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-folder-plus'></i> Upload folder created!</div>";
    }
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}
if (isset($_POST['edit_folder'])) {
    $r_id = $_POST['req_id'];
    $new_name = $conn->real_escape_string($_POST['new_folder_name']);
    $edit_instructions = isset($_POST['edit_instructions']) ? $conn->real_escape_string($_POST['edit_instructions']) : '';
    $conn->query("UPDATE upload_requests SET folder_name='$new_name', instructions='$edit_instructions' WHERE id=$r_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-pen'></i> Folder updated!</div>";
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}
if (isset($_POST['delete_folder'])) {
    $r_id = $_POST['req_id'];
    $files = $conn->query("SELECT file_path FROM student_uploads WHERE request_id=$r_id");
    while($f = $files->fetch_assoc()) { if(file_exists($f['file_path'])) unlink($f['file_path']); }
    $conn->query("DELETE FROM student_uploads WHERE request_id=$r_id");
    $conn->query("DELETE FROM upload_requests WHERE id=$r_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Folder deleted!</div>";
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}
if (isset($_POST['delete_file'])) {
    $f_id = $_POST['file_id'];
    $file_info = $conn->query("SELECT file_path FROM student_uploads WHERE id=$f_id")->fetch_assoc();
    if($file_info && file_exists($file_info['file_path'])) unlink($file_info['file_path']);
    $conn->query("DELETE FROM student_uploads WHERE id=$f_id");
    $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> File removed successfully!</div>";
    if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
}

// UPLOAD FILE (5MB LIMIT)
if (isset($_POST['upload_file_head'])) {
    $req_id = $_POST['request_id'];
    $p_id = $_POST['proj_id'];
    $file = $_FILES['document'];
    $stored = secure_store_uploaded_file($file, "head_req{$req_id}_p{$p_id}");
    if (!$stored['ok']) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> ".$stored['error']."</div>";
    } else {
        $head_tag = $head_name . " (Head)";
        $orig = $stored['original'];
        $path = $stored['path'];
        $stmt = $conn->prepare("INSERT INTO student_uploads (project_id, request_id, file_name, file_path, uploaded_by_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $p_id, $req_id, $orig, $path, $head_tag);
        $stmt->execute();
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-cloud-arrow-up'></i> File uploaded by Head!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
}

// ==============================================
//           LOG MANAGEMENT
// ==============================================
if (isset($_POST['save_weekly_log'])) {
    $project_id = (int)$_POST['project_id'];
    $log_id = !empty($_POST['log_id']) ? (int)$_POST['log_id'] : 0;
    $log_title = trim((string)($_POST['log_title'] ?? ''));
    $date_from = trim((string)($_POST['log_date_from'] ?? ''));
    $date_to = trim((string)($_POST['log_date_to'] ?? ''));
    $status = trim((string)($_POST['log_status'] ?? 'Working'));
    
    $planned_arr = $_POST['planned_tasks'] ?? [];
    $achieved_arr = $_POST['achieved_tasks'] ?? [];
    $table_data = [];
    for($i=0; $i < count($planned_arr); $i++) {
        if(trim($planned_arr[$i]) !== '' || trim($achieved_arr[$i]) !== '') {
            $table_data[] = ['planned' => trim($planned_arr[$i]), 'achieved' => trim($achieved_arr[$i])];
        }
    }
    $role = (string)($_SESSION['role'] ?? 'head');
    $creator_str = $head_name . " (" . ucfirst($role) . ")";
    $guide_review = isset($_POST['guide_review']) ? (string)$_POST['guide_review'] : null;

    if (!can_manage_project_log($conn, 'head', (int)$head_id, $project_id, $assigned_year)) {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> You are not allowed to edit logs for this project.</div>";
    } else {
        $save = save_project_log($conn, [
            'project_id' => $project_id,
            'log_id' => $log_id,
            'created_by_role' => $role,
            'created_by_id' => (int)$head_id,
            'created_by_name' => $creator_str,
            'log_title' => $log_title,
            'log_date' => $date_from,
            'log_date_to' => $date_to,
            'log_status' => $status,
            'log_entries' => $table_data,
            'guide_review' => $guide_review,
        ]);

        if ($save['ok']) {
            $msg = $log_id > 0
                ? "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check'></i> Log updated successfully!</div>"
                : "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check'></i> New weekly log created!</div>";
        } else {
            $err = (string)($save['error'] ?? 'Failed to save log.');
            $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> " . htmlspecialchars($err) . "</div>";
        }
    }
    if (isset($_POST['is_ajax'])) send_ajax_response(strpos($msg, 'alert-success') !== false ? 'success' : 'error', $msg, ['reload' => true]);
    $active_tab = 'dashboard';
}

if (isset($_POST['delete_weekly_log'])) {
    $log_id = (int)$_POST['log_id'];
    $log_project_id = get_project_id_for_log($conn, $log_id);
    if ($log_project_id > 0 && can_manage_project_log($conn, 'head', (int)$head_id, $log_project_id, $assigned_year)) {
        if (delete_log($conn, $log_id)) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Log deleted!</div>";
        }
    } else {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> You are not allowed to delete this log.</div>";
    }
    if (isset($_POST['is_ajax'])) send_ajax_response(strpos($msg, 'alert-success') !== false ? 'success' : 'error', $msg, ['reload' => true]);
    $active_tab = 'dashboard';
}

if (isset($_POST['update_head_review'])) {
    $log_id = (int)$_POST['log_id'];
    $p_id = (int)$_POST['project_id'];
    $review = $conn->real_escape_string($_POST['guide_review']);
    
    if (can_manage_project_log($conn, 'head', $head_id, $p_id, $assigned_year)) {
        $conn->query("UPDATE project_logs SET guide_review='$review', updated_at=NOW() WHERE id=$log_id");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Review updated successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
        $active_tab = 'logs';
        $reopen_log_project_id = $p_id;
    }
}

if (isset($_POST['create_log_head'])) {
    $p_id = (int)$_POST['project_id'];
    
    if (can_manage_project_log($conn, 'head', $head_id, $p_id, $assigned_year)) {
        $title = $conn->real_escape_string($_POST['log_title']);
        $date_from = $conn->real_escape_string($_POST['log_date_from']);
        $date_to = $conn->real_escape_string($_POST['log_date_to']);
        $review = $conn->real_escape_string($_POST['guide_review']);
        
        $planned_arr = $_POST['planned_tasks'] ?? [];
        $achieved_arr = $_POST['achieved_tasks'] ?? [];
        $table_data = [];
        for($i=0; $i < count($planned_arr); $i++) {
            if(!empty($planned_arr[$i]) || !empty($achieved_arr[$i])) { $table_data[] = ['planned' => $planned_arr[$i], 'achieved' => $achieved_arr[$i]]; }
        }
        $planned_json = $conn->real_escape_string(json_encode($table_data));
        $head_tag = $head_name . " (Head)";
        
        $sql = "INSERT INTO project_logs (project_id, created_by_role, created_by_id, created_by_name, log_title, log_date, log_date_to, progress_planned, guide_review) VALUES ($p_id, 'head', $head_id, '$head_tag', '$title', '$date_from', '$date_to', '$planned_json', '$review')";
        if ($conn->query($sql)) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> New log created successfully!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
            $active_tab = 'logs';
            $reopen_log_project_id = $p_id;
        }
    }
}

if (isset($_POST['delete_log_head'])) {
    $log_id = (int)$_POST['log_id'];
    $reopen_log_project_id = (int)get_project_id_for_log($conn, $log_id);
    if (can_manage_project_log($conn, 'head', $head_id, $reopen_log_project_id, $assigned_year)) {
        delete_log($conn, $log_id);
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Log deleted!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
        $active_tab = 'logs';
    }
}

// ==============================================
//        DATA FETCHING
// ==============================================

$filter_div = isset($_GET['division']) ? $conn->real_escape_string($_GET['division']) : '';
$filter_sem = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

// Validate semester range for assigned year
$valid_sems = ($assigned_year == 'SE') ? [3, 4] : (($assigned_year == 'TE') ? [5, 6] : [7, 8]);
if ($filter_sem > 0 && !in_array($filter_sem, $valid_sems)) {
    $filter_sem = 0; // Default to 'All' if invalid
}
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// 1. DASHBOARD STATS (Active projects only)
$stu_where = "academic_year='$assigned_year' AND (status = 'Active' OR status IS NULL)";
$proj_where = "p.project_year='$assigned_year' AND p.is_archived = 0";

if ($filter_div) { $stu_where .= " AND division='$filter_div'"; $proj_where .= " AND p.division='$filter_div'"; }
if ($filter_sem) { $stu_where .= " AND current_semester=$filter_sem"; $proj_where .= " AND p.semester=$filter_sem"; }

$student_count = $conn->query("SELECT COUNT(*) as c FROM student WHERE $stu_where")->fetch_assoc()['c'];
$project_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE $proj_where")->fetch_assoc()['c'];
$locked_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE p.is_locked=1 AND $proj_where")->fetch_assoc()['c'];

// Remaining Students
$remaining_students_list = [];
$rem_q = $conn->query("
    SELECT s.moodle_id, s.full_name, s.division 
    FROM student s 
    LEFT JOIN project_members pm ON s.id = pm.student_id 
    LEFT JOIN projects p ON pm.project_id = p.id AND p.is_archived = 0 AND p.project_year = '$assigned_year'
    WHERE $stu_where AND p.id IS NULL 
    ORDER BY s.division, s.full_name
");
if ($rem_q) {
    while($st = $rem_q->fetch_assoc()) { $remaining_students_list[] = $st; }
}
$remaining_students = count($remaining_students_list);

// Pending Groups
$pending_groups_list = [];
$pend_q = $conn->query("SELECT p.*, s.full_name as leader_name FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE p.is_locked = 0 AND $proj_where ORDER BY p.id DESC");
while($pg = $pend_q->fetch_assoc()) { $pending_groups_list[] = $pg; }
$unlocked_count = count($pending_groups_list);

// Build Active Projects Query
$table_where = $proj_where;
if ($filter_status == 'unassigned') $table_where .= " AND p.assigned_guide_id IS NULL";
if ($filter_status == 'assigned') $table_where .= " AND p.assigned_guide_id IS NOT NULL AND p.is_locked = 0";
if ($filter_status == 'locked') $table_where .= " AND p.is_locked = 1";

$md_sql = project_member_details_sql('p');
$projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle, g.full_name as guide_name FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE $table_where ORDER BY p.id DESC");

$log_proj_where = "p.project_year='$assigned_year' AND p.is_archived = 0";
if ($filter_div) { $log_proj_where .= " AND p.division='$filter_div'"; }
if ($filter_sem) { $log_proj_where .= " AND p.semester=$filter_sem"; }
$log_projects = $conn->query("SELECT p.*, s.full_name as leader_name FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE $log_proj_where ORDER BY p.id DESC");


$guides = $conn->query("SELECT id, full_name FROM guide WHERE deleted_at IS NULL AND (status = 'Active' OR status IS NULL) ORDER BY full_name");

// 2. HISTORY VAULT DATA
$hist_sessions = $conn->query("SELECT DISTINCT academic_session FROM projects WHERE is_archived = 1 ORDER BY academic_session DESC");
$filter_hist_session = isset($_GET['h_session']) ? $conn->real_escape_string($_GET['h_session']) : '';
$filter_hist_sem = isset($_GET['h_semester']) ? (int)$_GET['h_semester'] : 0;
$filter_hist_div = isset($_GET['h_division']) ? $conn->real_escape_string($_GET['h_division']) : '';

$hist_where = "p.project_year = '$assigned_year' AND p.is_archived = 1";
if ($filter_hist_session) $hist_where .= " AND p.academic_session = '$filter_hist_session'";
if ($filter_hist_sem) $hist_where .= " AND p.semester = $filter_hist_sem";
if ($filter_hist_div) $hist_where .= " AND p.division = '$filter_hist_div'";

$history_projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle, g.full_name as guide_name FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE $hist_where ORDER BY p.academic_session DESC, p.id DESC");

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
$all_projects_query = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE p.project_year = '$assigned_year'");
while($t = $all_projects_query->fetch_assoc()) {
    $p_id = $t['id'];
    $t['requests'] = [];
    $reqs = $conn->query("SELECT * FROM upload_requests 
                          WHERE project_id = $p_id 
                          OR (is_global = 1 AND academic_year = '$assigned_year' AND semester = " . ($t['semester'] ?? 0) . ") 
                          ORDER BY is_global DESC, id ASC");
    while($r = $reqs->fetch_assoc()) {
        $r_id = $r['id'];
        $r['files'] = [];
        $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id AND project_id = $p_id ORDER BY uploaded_at DESC");
        while($f = $files->fetch_assoc()) { $r['files'][] = $f; }
        $t['requests'][] = $r;
    }
    // Fetch logs for this project
    $t['logs'] = get_project_logs($conn, $p_id);
    $teams_data[$p_id] = $t;
}
$teams_json = json_encode($teams_data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
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
        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto; transition: margin-left 0.3s ease, margin-right 0.3s ease, opacity 0.3s ease;}
        .sidebar.collapsed { margin-left: calc(-1 * var(--sidebar-width)); margin-right: 0; opacity: 0; pointer-events: none; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        .menu-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top:20px;}
        
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

        /* WORKSPACE & LOGS GRID */
        .workspace-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        .logs-grid { grid-template-columns: repeat(3, 1fr) !important; }
        .folder-block { background: var(--input-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; display: flex; flex-direction: column; transition: 0.2s; }
        .folder-block:hover { border-color: #CBD5E1; box-shadow: 0 5px 15px rgba(0,0,0,0.03); }
        .g-card { background: var(--card-bg); padding: 20px; border-radius: 16px; margin-bottom: 20px; border: 1px solid var(--border-color);}
        .g-label { display: block; font-size: 14px; font-weight: 600; color: var(--text-dark); margin-bottom: 10px; }
        .g-input { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; outline: none; background: var(--input-bg); color: var(--text-dark); transition: all 0.3s ease; font-family: inherit; box-sizing: border-box; }
        .g-input:focus { border-color: var(--primary-green); background: var(--card-bg); box-shadow: 0 0 0 4px rgba(16, 93, 63, 0.1); }

        /* Instruction Note */
        .instruction-note { font-size: 12px; color: var(--note-text); margin-bottom: 15px; background: var(--note-bg); border-left: 3px solid var(--note-border); padding: 8px 12px; border-radius: 8px; line-height: 1.5; }

        /* MODAL STYLES */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--bg-color); padding: 0; border-radius: 24px; width: 100%; max-width: 800px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden;}
        .modal-card-small { background: var(--card-bg); max-width: 500px; padding: 30px; border-radius: 24px; display:flex; flex-direction:column; max-height:80vh;}
        
        .modal-header { padding: 25px 30px; background:var(--input-bg); display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); flex-shrink: 0; }
        .modal-tabs { display:flex; gap:15px; padding: 0 30px; background:var(--input-bg); border-bottom:1px solid var(--border-color); overflow-x:auto; flex-shrink: 0;}
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
        .mobile-menu-btn { display: block; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; transition: 0.3s; flex-shrink: 0; }
        .close-sidebar-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-light); cursor: pointer; position: absolute; right: 20px; top: 25px; }
        .mobile-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; opacity: 0; transition: 0.3s; }
        .mobile-overlay.active { display: block; opacity: 1; }

        @media (max-width: 768px) {
            body { padding: 0; flex-direction: column; overflow-x: hidden; height: auto; overflow-y: auto;}
            
            .sidebar {
                position: fixed; top: 0; left: -300px; width: 280px; height: 100vh; margin: 0 !important;
                border-radius: 0 24px 24px 0; box-shadow: 5px 0 20px rgba(0,0,0,0.3); z-index: 9999;
                transition: left 0.3s ease-in-out; display: flex !important; flex-direction: column !important; opacity: 1 !important;
            }
            .sidebar.active { left: 0; }
            .close-sidebar-btn { display: block; }
            
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; display: block; overflow-y: visible; height: auto; }
            
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-left { flex:1; }
            
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            
            /* Compact Mobile Stats */
            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 10px; }
            .stat-card { padding: 12px 10px; text-align: center; border-radius: 16px; justify-content:center; align-items:center;}
            .stat-title { font-size: 10px; white-space: normal; line-height: 1.2; margin-bottom: 5px; }
            .stat-value { font-size: 22px; }
            .stat-icon { display: none; }
            .stat-sub { display: none; } /* Hide extra text to fit in the box */
            .logs-grid { grid-template-columns: 1fr !important; }
            
            table { display: block; width: 100%; overflow-x: auto; white-space: nowrap; }
            
            .modal-card, .modal-card-small { width: 95%; max-height: 90vh; padding: 0; border-radius: 16px; margin: 20px auto; }
            .modal-card-small { padding: 20px; }
            .modal-body { padding: 20px 15px; }
        }
        /* Modern Google Forms Drag Styling */
        .sortable-ghost { visibility: hidden !important; }
        .sortable-drag { opacity: 1 !important; box-shadow: 0 8px 30px rgba(0,0,0,0.12) !important; cursor: grabbing !important; background: var(--card-bg) !important; z-index: 10000 !important; border-radius: 16px; }
        .drag-handle:active { cursor: grabbing !important; }
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
        <a class="nav-link" onclick="switchTab('history')" id="tab-history"><i class="fa-solid fa-vault"></i> History Vault</a>
        <a class="nav-link" onclick="switchTab('workspace')" id="tab-workspace"><i class="fa-solid fa-briefcase"></i> Manage Workspace</a>
        <a href="export_hub.php" class="nav-link" style="background:transparent;"><i class="fa-solid fa-file-export"></i> Export Hub</a>
        <a class="nav-link" onclick="switchTab('resets')" id="tab-resets"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>
        <a class="nav-link" onclick="switchTab('logs')" id="tab-logs"><i class="fa-solid fa-book"></i> Project Logs</a>

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

            <form method="GET" class="search-bar-container">
                <input type="hidden" name="tab" value="dashboard">
                <div class="smart-search">
                    <input type="text" id="searchStudent" placeholder="Search by Moodle ID or Student Name..." onkeyup="liveProjectSearch('projectsTable')">
                    <i class="fa-solid fa-user-graduate"></i>
                </div>
                <div class="smart-search">
                    <input type="text" id="searchGuide" placeholder="Search by Group Name or Guide Name..." onkeyup="liveProjectSearch('projectsTable')">
                    <i class="fa-solid fa-users-viewfinder"></i>
                </div>
                <div style="display:flex; gap:10px;">
                    <select name="semester" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Semesters</option>
                        <?php if($assigned_year == 'SE'): ?>
                            <option value="3" <?php if($filter_sem==3) echo 'selected'; ?>>Sem 3</option>
                            <option value="4" <?php if($filter_sem==4) echo 'selected'; ?>>Sem 4</option>
                        <?php elseif($assigned_year == 'TE'): ?>
                            <option value="5" <?php if($filter_sem==5) echo 'selected'; ?>>Sem 5</option>
                            <option value="6" <?php if($filter_sem==6) echo 'selected'; ?>>Sem 6</option>
                        <?php elseif($assigned_year == 'BE'): ?>
                            <option value="7" <?php if($filter_sem==7) echo 'selected'; ?>>Sem 7</option>
                            <option value="8" <?php if($filter_sem==8) echo 'selected'; ?>>Sem 8</option>
                        <?php endif; ?>
                    </select>
                </div>
            </form>

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
                                <span style="font-size:12px; color: var(--text-light);"><?php echo htmlspecialchars($row['project_year'])." (Sem ".($row['semester']??'-').") - Div ".htmlspecialchars($row['division']); ?></span>
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
            
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                <h3 style="font-size:18px; color:var(--text-dark); margin:0;"><i class="fa-solid fa-box-archive" style="color:var(--primary-green);"></i> History Archive Vault</h3>
                <button class="btn-add" onclick="openHistoricalEntryModal()"><i class="fa-solid fa-plus-circle"></i> Add Historical Entry</button>
            </div>
            
            <form method="GET" class="search-bar-container" style="margin-bottom: 20px;">
                <input type="hidden" name="tab" value="history">
                <select name="h_session" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Sessions</option>
                    <?php $hist_sessions->data_seek(0); while($s = $hist_sessions->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($s['academic_session']); ?>" <?php if($filter_hist_session == $s['academic_session']) echo 'selected'; ?>><?php echo htmlspecialchars($s['academic_session']); ?></option>
                    <?php endwhile; ?>
                </select>
                <select name="h_semester" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Semesters</option>
                    <?php if($assigned_year == 'SE'): ?>
                        <option value="3" <?php if($filter_hist_sem==3) echo 'selected'; ?>>Sem 3</option>
                        <option value="4" <?php if($filter_hist_sem==4) echo 'selected'; ?>>Sem 4</option>
                    <?php elseif($assigned_year == 'TE'): ?>
                        <option value="5" <?php if($filter_hist_sem==5) echo 'selected'; ?>>Sem 5</option>
                        <option value="6" <?php if($filter_hist_sem==6) echo 'selected'; ?>>Sem 6</option>
                    <?php elseif($assigned_year == 'BE'): ?>
                        <option value="7" <?php if($filter_hist_sem==7) echo 'selected'; ?>>Sem 7</option>
                        <option value="8" <?php if($filter_hist_sem==8) echo 'selected'; ?>>Sem 8</option>
                    <?php endif; ?>
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
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['project_year']); ?> (Sem <?php echo ($row['semester']??'-'); ?>)</span><br>
                                <span style="font-size:12px; color: var(--text-light);">Div <?php echo htmlspecialchars($row['division']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <?php if($row['is_locked']): ?>
                                    <span style="color:var(--primary-green); font-weight:600; font-size:13px;"><?php echo htmlspecialchars($row['final_topic']); ?></span>
                                <?php else: ?>
                                    <span style="color:#D97706; font-size:12px; font-style:italic;">Never Finalized</span>
                                <?php endif; ?>
                                <?php if(!empty($row['project_type'])): ?>
                                    <div style="margin-top:5px; display:flex; flex-wrap:wrap; gap:4px;">
                                        <span style="font-size:10px; background:var(--input-bg); color:var(--primary-green); padding:2px 6px; border-radius:4px; font-weight:700; border:1px solid var(--border-color);"><?php echo htmlspecialchars($row['project_type']); ?></span>
                                        <?php 
                                            $goals = json_decode($row['sdg_goals'] ?? '[]', true);
                                            if(!empty($goals)) {
                                                foreach($goals as $g) {
                                                    echo '<span style="font-size:9px; background:var(--note-bg); color:var(--note-text); padding:1px 5px; border-radius:4px; font-weight:600; border:1px solid var(--note-border);"><i class="fa-solid fa-leaf" style="font-size:8px;"></i> '.htmlspecialchars($g).'</span>';
                                                }
                                            }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align:middle; width: 130px; padding:15px;">
                                <button class="btn-action" onclick='openArchiveModal(<?php echo $row['id']; ?>)'><i class="fa-solid fa-folder-open"></i> Vault & Details</button>
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
                
                <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('Are you sure you want to accept ALL Pending and Rejected requests? This will instantly reset their passwords.');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                                            <form method="POST" class="ajax-form" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <input type="hidden" name="moodle_id" value="<?php echo htmlspecialchars($req['moodle_id']); ?>">
                                                <input type="hidden" name="user_role" value="<?php echo htmlspecialchars($req['user_role']); ?>">
                                                <input type="hidden" name="user_name" value="<?php echo htmlspecialchars($req['user_name']); ?>">
                                                <button type="submit" name="accept_request" class="btn-action" style="background:#10B981; padding:8px 12px; width:auto;" title="Auto-Generate Password"><i class="fa-solid fa-check"></i> Accept</button>
                                            </form>
                                            <form method="POST" class="ajax-form" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                                <button type="submit" name="reject_request" class="btn-action" style="background:#EF4444; padding:8px 12px; width:auto;" title="Reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                                            </form>
                                        </div>
                                    <?php elseif($req['status'] == 'Rejected'): ?>
                                        <div style="display:flex; gap:10px; justify-content:center;">
                                            <form method="POST" class="ajax-form" style="margin:0;">
                                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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

        <div id="section-logs" class="dashboard-canvas" style="display:none;">
            <!-- GROUPS VIEW -->
            <div id="logs-groups-view">
                <form method="GET" class="search-bar-container">
                    <input type="hidden" name="tab" value="logs">
                    <select name="division" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Divisions</option>
                        <option value="A" <?php if($filter_div=='A') echo 'selected'; ?>>Div A</option>
                        <option value="B" <?php if($filter_div=='B') echo 'selected'; ?>>Div B</option>
                        <option value="C" <?php if($filter_div=='C') echo 'selected'; ?>>Div C</option>
                    </select>
                    <select name="semester" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Semesters</option>
                        <?php 
                        $sems = ($assigned_year == 'SE') ? [3,4] : (($assigned_year == 'TE') ? [5,6] : [7,8]);
                        foreach($sems as $s): ?>
                            <option value="<?php echo $s; ?>" <?php if($filter_sem==$s) echo 'selected'; ?>>Sem <?php echo $s; ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <div class="workspace-grid logs-grid">
                    <?php if($log_projects && $log_projects->num_rows > 0): ?>
                        <?php while($group = $log_projects->fetch_assoc()): ?>
                            <div class="folder-block" style="border-left: 5px solid #3B82F6;">
                                <h4 style="font-size:18px; color:var(--text-dark); margin:0 0 10px 0;"><i class="fa-solid fa-users-viewfinder" style="color:#3B82F6; margin-right:5px;"></i> <?php echo htmlspecialchars($group['group_name']); ?></h4>
                                <div style="font-size:13px; color:var(--text-light); margin-bottom:15px; line-height: 1.6;">
                                    <strong>Division:</strong> <?php echo htmlspecialchars($group['division']); ?><br>
                                    <strong>Leader:</strong> <?php echo htmlspecialchars($group['leader_name'] ?: 'Unknown'); ?>
                                </div>
                                <button type="button" onclick="openGroupLogs(<?php echo $group['id']; ?>)" class="btn-submit" style="text-align:center; padding:10px;"><i class="fa-solid fa-book-open"></i> Manage Logs</button>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-light); background:var(--card-bg); border-radius:16px;">
                            <i class="fa-solid fa-folder-open" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i>
                            <div>No groups found for this year.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- DETAILS VIEW -->
            <div id="logs-detail-view" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:15px;">
                    <div>
                        <button onclick="closeGroupLogs()" style="background:none; border:none; font-size:13px; color:#3B82F6; cursor:pointer; margin-bottom:5px; font-weight:600;"><i class="fa-solid fa-arrow-left"></i> Back to Groups</button>
                        <h3 style="font-size: 22px; color: var(--text-dark); margin:0;" id="logs-detail-title">Logs</h3>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;" id="logs-detail-leader"></p>
                    </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="section-workspace" class="dashboard-canvas" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h3 style="font-size:24px; color:var(--text-dark); margin:0;"><i class="fa-solid fa-briefcase" style="color:var(--primary-green);"></i> Global Workspace Manager</h3>
            </div>

            <div class="g-card" style="border-left: 5px solid var(--primary-green);">
                <h4 style="font-size:16px; color:var(--primary-green); margin-bottom:15px;"><i class="fa-solid fa-folder-plus"></i> Create New Shared Folder (<?php echo $assigned_year; ?>)</h4>
                <p style="font-size:13px; color:var(--text-light); margin-bottom:20px;">Shared folders will appear in the workspace of every group in the selected semester of your assigned year.</p>
                
                <form method="POST" class="ajax-form" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px; align-items:end;">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="form-group" style="margin:0;">
                        <label>Folder Name</label>
                        <input type="text" name="folder_name" placeholder="e.g. Project Report (Final)" required class="g-input">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label>Semester</label>
                        <select name="semester" required class="g-input">
                            <?php if($assigned_year == 'SE'): ?>
                                <option value="3">Sem 3</option>
                                <option value="4">Sem 4</option>
                            <?php elseif($assigned_year == 'TE'): ?>
                                <option value="5">Sem 5</option>
                                <option value="6">Sem 6</option>
                            <?php elseif($assigned_year == 'BE'): ?>
                                <option value="7">Sem 7</option>
                                <option value="8">Sem 8</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1; margin:0;">
                        <label>Instructions (Optional)</label>
                        <textarea name="instructions" placeholder="Enter any specific instructions for students..." class="g-input" style="height:80px;"></textarea>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <button type="submit" name="create_global_folder" class="btn-submit" style="width:auto; padding:12px 30px;"><i class="fa-solid fa-plus"></i> Create Shared Folder</button>
                    </div>
                </form>
            </div>

            <div class="card" style="padding:0; overflow:hidden;">
                <table style="width:100%;">
                    <thead>
                        <tr style="background:var(--input-bg);">
                            <th style="padding:15px;">Shared Folder Info</th>
                            <th style="padding:15px;">Target Semester</th>
                            <th style="padding:15px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $globals = $conn->query("SELECT * FROM upload_requests WHERE is_global = 1 AND academic_year = '$assigned_year' ORDER BY semester, folder_name");
                        if($globals && $globals->num_rows > 0): 
                            while($g = $globals->fetch_assoc()): 
                        ?>
                            <tr>
                                <td style="padding:15px;">
                                    <div style="font-weight:700; color:var(--text-dark);"><i class="fa-solid fa-folder-tree" style="color:#F59E0B; margin-right:8px;"></i> <?php echo htmlspecialchars($g['folder_name']); ?></div>
                                    <div style="font-size:12px; color:var(--text-light); margin-top:4px;"><?php echo htmlspecialchars($g['instructions'] ?: 'No specific instructions.'); ?></div>
                                </td>
                                <td style="padding:15px;">
                                    <span class="badge" style="background:rgba(16, 93, 63, 0.1); color:var(--primary-green); border:1px solid rgba(16, 93, 63, 0.2);">Sem <?php echo $g['semester']; ?></span>
                                </td>
                                <td style="padding:15px; text-align:center;">
                                    <form method="POST" class="ajax-form" onsubmit="return confirm('Are you sure? This will delete the folder for ALL groups in this semester and remove any uploaded files.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="request_id" value="<?php echo $g['id']; ?>">
                                        <button type="submit" name="delete_global_folder" class="btn-icon" style="margin:0 auto; color:#EF4444;"><i class="fa-solid fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="3" style="padding:40px; text-align:center; color:var(--text-light);"><i class="fa-solid fa-briefcase" style="font-size:40px; color:var(--border-color); margin-bottom:15px;"></i><br>No shared folders created for <?php echo $assigned_year; ?> yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
                </div>

                <div style="background:var(--card-bg); padding:20px; border-radius:16px;">
                    <div class="workspace-grid logs-grid" id="logs-detail-container" style="align-items: start; gap: 15px;">
                        <!-- JS injected logs go here -->
                    </div>
                </div>
            </div>
        </div>

    <!-- EDIT FOLDER MODAL -->
    <div id="editFolderModal" class="modal-overlay" style="z-index: 3500;">
        <div class="modal-card-small">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-pen"></i> Edit Folder</h3>
                <button type="button" onclick="document.getElementById('editFolderModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="req_id" id="edit_folder_rid">
                <div class="form-group" style="margin-bottom:15px;">
                    <label>Folder Name</label>
                    <input type="text" name="new_folder_name" id="edit_folder_name_input" class="g-input" required>
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label>Instructions (Optional)</label>
                    <textarea name="edit_instructions" id="edit_folder_instructions_input" class="g-input" rows="3"></textarea>
                </div>
                <button type="submit" name="edit_folder" class="btn-submit" style="margin-top:0;"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
        </div>
    </div>

        <!-- Review Modal -->
        <div id="reviewModal" class="modal-overlay" style="z-index: 3500;">
            <div class="modal-card-small" style="max-width: 750px; width: 95%;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                    <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-comment-dots"></i> Add/Edit Review</h3>
                    <button type="button" onclick="document.getElementById('reviewModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="log_id" id="review_log_id">
                    <input type="hidden" name="project_id" id="review_project_id">
                    <div style="margin-bottom:15px;">
                        <label style="display:block; font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:5px;">Your Feedback / Review Notes:</label>
                        <textarea name="guide_review" id="review_text_field" class="g-input" required placeholder="Provide your review for the weekly progress..." style="min-height: 300px !important; width: 100% !important; resize: vertical; overflow-y: auto; padding: 15px; line-height: 1.6; font-size: 14px;"></textarea>
                    </div>
                    <button type="submit" name="update_head_review" class="btn-submit" style="background:#3B82F6; width:100%;"><i class="fa-solid fa-floppy-disk"></i> Save Review</button>
                </form>
            </div>
        </div>

        <!-- Create Log Modal (For Head) -->
        <div id="createLogModal" class="modal-overlay" style="z-index: 3500;">
            <div class="modal-card" style="max-width: 800px; max-height: 90vh;">
                <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding: 20px 30px; background:var(--card-bg);">
                    <h3 style="margin:0; font-size: 18px; color: #8B5CF6;"><i class="fa-solid fa-book-medical"></i> Create Group Log</h3>
                    <button type="button" onclick="document.getElementById('createLogModal').style.display='none'" style="border:none; background:none; cursor:pointer; font-size:20px; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div style="padding:30px; overflow-y:auto; flex:1;">
                    <form method="POST" class="ajax-form" id="logForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="project_id" id="create_log_project_id">
                        <div class="g-card" style="padding:15px; margin-bottom:20px;">
                            <label class="g-label" style="font-size:13px;">Week Title / Heading</label>
                            <input type="text" name="log_title" placeholder="e.g. Week 4: UI Development" class="g-input" required>
                        </div>
                        
                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">
                            <div class="g-card" style="padding:15px; margin-bottom:0;">
                                <label class="g-label" style="font-size:13px;">From Date</label>
                                <input type="date" name="log_date_from" value="<?php echo date('Y-m-d'); ?>" class="g-input" required>
                            </div>
                            <div class="g-card" style="padding:15px; margin-bottom:0;">
                                <label class="g-label" style="font-size:13px;">To Date</label>
                                <input type="date" name="log_date_to" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="g-input" required>
                            </div>
                        </div>

                        <div class="g-card" style="padding:20px; margin-bottom:20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                                <label class="g-label" style="font-size:14px; margin:0;">Detailed Progress Table</label>
                                <button type="button" onclick="addLogRow()" class="btn-outline" style="font-size:11px; padding:5px 12px; margin:0;"><i class="fa-solid fa-plus"></i> Add Row</button>
                            </div>
                            <div style="max-height: 200px; overflow-y:auto; border:1px solid var(--border-color); border-radius:8px;">
                                <table style="width:100%; border-collapse: collapse; font-size:13px;" id="logTable">
                                    <thead style="background:var(--input-bg); position:sticky; top:0; z-index:1;"><tr><th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:50%;">Progress Planned</th><th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:45%;">Progress Achieved</th><th style="padding:12px; border-bottom:1px solid var(--border-color); width:5%;"></th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                        
                        <div class="g-card" style="padding:15px; margin-bottom:20px; border-left: 5px solid #3B82F6;">
                            <label class="g-label" style="font-size:13px; color:#3B82F6;">Add Your Review (Optional)</label>
                            <textarea name="guide_review" class="g-input" placeholder="Provide initial review or feedback..." style="min-height: 150px !important; width: 100% !important; resize: vertical; overflow-y: auto; padding: 15px; line-height: 1.6; font-size: 14px;"></textarea>
                        </div>

                        <button type="submit" name="create_log_head" class="btn-submit" style="background:#8B5CF6; margin-top:0; width:100%;"><i class="fa-solid fa-floppy-disk"></i> Create Log Entry</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- REPORT CENTER / EXPORT HUB -->
        <div id="section-exports" class="dashboard-canvas" style="display:none;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Export Hub / Report Center</h3>
                <p style="font-size: 13px; color: var(--text-light); margin:0;">Customize, preview, and download professional project reports.</p>
            </div>

            <div class="g-card">
                <form id="exportFilterForm" method="GET" action="export_handler.php">
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:25px;">
                        <div class="form-group">
                            <label class="g-label">Academic Session</label>
                            <select name="session" class="g-input" required>
                                <option value="all">All Sessions</option>
                                <?php 
                                $sessions_q = $conn->query("SELECT DISTINCT academic_session FROM projects ORDER BY academic_session DESC");
                                while($s = $sessions_q->fetch_assoc()) {
                                    echo "<option value=\"".htmlspecialchars($s['academic_session'])."\">".htmlspecialchars($s['academic_session'])."</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="g-label">Year / Semester</label>
                            <select name="sem" class="g-input" required>
                                <option value="all">All Semesters</option>
                                <?php if($assigned_year == 'SE'): ?>
                                    <option value="3" selected>Sem 3</option>
                                    <option value="4">Sem 4</option>
                                <?php elseif($assigned_year == 'TE'): ?>
                                    <option value="5" selected>Sem 5</option>
                                    <option value="6">Sem 6</option>
                                <?php elseif($assigned_year == 'BE'): ?>
                                    <option value="7" selected>Sem 7</option>
                                    <option value="8">Sem 8</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="g-label">Division</label>
                            <select name="division" class="g-input">
                                <option value="all">All Divisions</option>
                                <option value="A">Division A</option>
                                <option value="B">Division B</option>
                                <option value="C">Division C</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="g-label">Report Title</label>
                            <input type="text" name="title" class="g-input" placeholder="e.g. Topic Selection Report" value="Topic Selection Report">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="g-label" style="margin-bottom:15px; display:block;">Select Columns to Include in Report</label>
                        <div style="display:flex; gap:10px; align-items:center; margin-bottom: 15px;">
                            <select id="colSelectDropdown" class="g-input" style="flex:1; margin:0;">
                                <option value="sr_no">Serial No.</option>
                                <option value="group_no">Group No.</option>
                                <option value="roll_no">Roll No</option>
                                <option value="moodle_id">Moodle ID</option>
                                <option value="student_name">Name of Student</option>
                                <option value="topics">Topics (Vertical List)</option>
                                <option value="final_topic">Topic Selected</option>
                                <option value="sdg">SDG Mapped (No. & Name)</option>
                                <option value="project_type">Project Type</option>
                                <option value="guide_name">Guide Name</option>
                                <option value="sign">Sign Box (Empty)</option>
                            </select>
                            <button type="button" onclick="addColumnPill()" class="btn-submit" style="margin:0; width:auto; background:#3B82F6; padding:12px 20px;"><i class="fa-solid fa-plus"></i> Add Column</button>
                        </div>
                        
                        <!-- Pills Container -->
                        <div id="colPillsContainer" style="display:flex; flex-wrap:wrap; gap:10px; background:var(--input-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); min-height: 55px;">
                            <!-- Default columns pre-added via JS -->
                        </div>
                        
                        <!-- Hidden Inputs Container -->
                        <div id="hiddenColsContainer"></div>
                    </div>

                    <div style="margin-top:30px; display:flex; gap:15px; flex-wrap:wrap;">
                        <button type="button" onclick="fetchReportPreview()" class="btn-outline" style="width:auto; padding:12px 25px; border-color:#3B82F6; color:#3B82F6; font-weight:600;">
                            <i class="fa-solid fa-magnifying-glass-chart"></i> Live Preview
                        </button>
                        <button type="submit" class="btn-submit" style="width:auto; padding:12px 30px; background:#10B981; border:none; color:white; border-radius:12px; font-weight:600; cursor:pointer;">
                            <i class="fa-solid fa-file-excel"></i> Download Excel Report
                        </button>
                    </div>
                </form>
            </div>

            <!-- PREVIEW CONTAINER -->
            <div id="reportPreviewArea" class="g-card" style="margin-top:30px; display:none; padding:0; overflow:hidden;">
                <div style="background:var(--input-bg); padding:15px 25px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                    <h4 style="margin:0; font-size:15px; color:var(--text-dark);">Report Preview</h4>
                    <button type="button" onclick="document.getElementById('reportPreviewArea').style.display='none'" style="border:none; background:none; color:var(--text-light); cursor:pointer;"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div id="reportPreviewContent" style="padding:25px; overflow-x:auto; max-height:600px; background:white;">
                    <!-- Table will be loaded here -->
                </div>
            </div>
        </div>

        <div id="section-settings" style="display:none; padding-bottom:50px;">
            <div style="margin-bottom:20px;">
                <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Account Settings</h3>
                <p style="font-size: 13px; color: var(--text-light); margin:0;">Update your account password securely.</p>
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
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
       <div class="modal-card modal-card-small" style="max-width: 700px; width: 95%;">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:#D97706;">
                Finalize Topic <button type="button" onclick="closeSimpleModal('finalizeModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                <div class="modal-tab" id="tab-form" onclick="switchModalTab('form')">Form Details</div>
                <div class="modal-tab" id="tab-upload" onclick="switchModalTab('upload')">Workspace & Files</div>
                <div class="modal-tab" id="tab-logs" onclick="switchModalTab('logs')">Project Logs</div>
                <div class="modal-tab" id="tab-classification" onclick="switchModalTab('classification')">Classification</div>
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

                    <form method="POST" class="ajax-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                    <form method="POST" class="ajax-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                        <form method="POST" class="ajax-form" style="display:flex; flex-direction:column; gap:10px; margin-top:10px;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="target_project_id" id="upload_pid">
                            <input type="hidden" name="current_guide_id" id="upload_gid">
                            <input type="text" name="folder_name" placeholder="Folder Name (e.g. Final PPT)" required style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; background:var(--card-bg); color:var(--text-dark);">
                            <textarea name="instructions" placeholder="Optional Note/Instructions (e.g. Max 10 slides)" style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; resize:vertical; min-height:60px; background:var(--card-bg); color:var(--text-dark);"></textarea>
                            <button type="submit" name="create_folder" style="background:var(--btn-blue); color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:600; align-self:flex-start;"><i class="fa-solid fa-plus"></i> Create Folder</button>
                        </form>
                    </div>
                    <div id="upload_folders_container"></div>
                </div>

                <div id="modal-sec-classification" class="tab-content">
                    <form method="POST" class="ajax-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="project_id" id="class_pid">
                        
                        <div class="g-card" style="padding:20px; margin-bottom:20px; border-left: 4px solid var(--primary-green);">
                            <label class="g-label" style="font-size:14px; font-weight:700; color:var(--text-dark); display:block; margin-bottom:15px;">
                                <i class="fa-solid fa-layer-group"></i> Project Type
                            </label>
                            <select name="project_type" id="modal_project_type" class="g-input" required>
                                <option value="">-- Select Type --</option>
                                <option value="Research">Research</option>
                                <option value="Product">Product</option>
                                <option value="Application">Application</option>
                                <option value="XYZ">XYZ</option>
                            </select>
                        </div>

                        <div class="g-card" style="padding:20px; border-left: 4px solid var(--btn-blue);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                                <label class="g-label" style="font-size:14px; font-weight:700; color:var(--text-dark); margin:0;">
                                    <i class="fa-solid fa-leaf" style="color:#10B981;"></i> SDG Goals (Sustainable Development Goals)
                                </label>
                                <button type="button" onclick="addSdgRow()" class="btn-outline" style="font-size:11px; padding:5px 12px; margin:0;"><i class="fa-solid fa-plus"></i> Add Goal</button>
                            </div>
                            <div id="sdgContainer" style="background:var(--input-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                <!-- SDG rows will be added here -->
                            </div>
                            <p style="font-size:11px; color:var(--text-light); margin-top:10px;">Select one or more of the 17 UN Sustainable Development Goals that align with this project.</p>
                        </div>

                        <button type="submit" name="update_classification" class="btn-submit" style="width:100%; margin-top:20px;"><i class="fa-solid fa-floppy-disk"></i> Save Classification</button>
                    </form>
                </div>

                <div id="modal-sec-logs" class="tab-content">
                    <div style="background:var(--input-bg); border:1px solid var(--border-color); padding:15px; border-radius:12px; margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label style="font-size:12px; font-weight:600; color:var(--text-light); text-transform:uppercase;">Group Logs</label>
                            <button type="button" onclick="openCreateLogModalNew()" class="btn-action" style="width:auto; padding:6px 12px; background:var(--primary-green);"><i class="fa-solid fa-plus"></i> Create Log</button>
                        </div>
                    </div>
                    <div class="workspace-grid logs-grid" id="modal_logs_container" style="align-items: start; gap: 15px;"></div>
                </div>

            </div>
        </div>
    </div>

    <!-- CREATE/EDIT LOG MODAL -->
    <div id="createLogModal" class="modal-overlay" style="z-index: 3500;">
        <div class="modal-card" style="max-width: 800px; max-height: 90vh; display:flex; flex-direction:column; padding:0;">
            <div class="modal-header" style="padding:20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; background:var(--input-bg);">
                <h3 style="margin:0; font-size:18px; color:var(--primary-green);"><i class="fa-solid fa-book"></i> <span id="logModalTitleText">Weekly Project Log</span></h3>
                <button type="button" onclick="closeSimpleModal('createLogModal')" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" style="padding:20px; overflow-y:auto; flex:1;">
                <form method="POST" class="ajax-form" id="logForm" class="ajax-form">
                    <input type="hidden" name="log_id" id="log_id_field">
                    <input type="hidden" name="project_id" id="modal_log_pid">
                    
                    <div style="display:flex; gap:15px; margin-bottom:15px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:2; min-width:200px; margin-bottom:0;">
                            <label>Log Title (e.g., Week 1, Milestone 1)</label>
                            <input type="text" name="log_title" id="log_title_field" required>
                        </div>
                        <div class="form-group" style="flex:1; min-width:150px; margin-bottom:0;">
                            <label>Status</label>
                            <select name="log_status" id="log_status_field">
                                <option value="Working">Working</option>
                                <option value="Completed">Completed</option>
                                <option value="Pending Review">Pending Review</option>
                                <option value="Blocked">Blocked</option>
                            </select>
                        </div>
                    </div>
                    
                    <div style="display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1; margin-bottom:0;">
                            <label>From Date</label>
                            <input type="date" name="log_date_from" id="log_from_field" required>
                        </div>
                        <div class="form-group" style="flex:1; margin-bottom:0;">
                            <label>To Date</label>
                            <input type="date" name="log_date_to" id="log_to_field" required>
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <label style="font-size:13px; font-weight:600; color:var(--text-dark); margin:0;">Tasks & Progress</label>
                            <button type="button" onclick="addLogRow()" style="background:var(--card-bg); color:var(--primary-green); border:1px dashed var(--primary-green); padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer;"><i class="fa-solid fa-plus"></i> Add Row</button>
                        </div>
                        <table id="logTable" style="width:100%; border-collapse:collapse; background:var(--input-bg); border-radius:8px; overflow:hidden; border:1px solid var(--border-color);">
                            <thead>
                                <tr style="background:var(--border-color);">
                                    <th style="padding:10px; font-size:12px; text-align:left; width:45%; color:var(--text-dark);">Progress Planned</th>
                                    <th style="padding:10px; font-size:12px; text-align:left; width:45%; color:var(--text-dark);">Progress Achieved</th>
                                    <th style="padding:10px; width:10%;"></th>
                                </tr>
                            </thead>
                            <tbody>
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="guideReviewContainer" class="form-group" style="margin-bottom:20px;">
                        <label style="color:#D97706;"><i class="fa-solid fa-chalkboard-user"></i> Guide's Review / Remarks</label>
                        <textarea name="guide_review" id="log_review_field" rows="3" style="border:1px solid #FDE68A; background:#FFFBEB; border-radius:8px; padding:10px; width:100%; outline:none; resize:vertical; font-family:inherit; color:#92400E;"></textarea>
                    </div>

                    <button type="submit" name="save_weekly_log" id="logSubmitBtn" class="btn-submit" style="margin-top:0;"><i class="fa-solid fa-floppy-disk"></i> Save Log Book</button>
                </form>
            </div>
        </div>
    </div>

    <!-- LOG PREVIEW MODAL -->
    <div id="logPreviewModal" class="modal-overlay" style="z-index:4000;">
        <div class="modal-card" style="max-width: 850px; display:flex; flex-direction:column; max-height:95vh; padding:0;">
            <div class="modal-header" style="padding:15px 20px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center; background:var(--input-bg);">
                <h3 style="margin:0; font-size:16px; color:var(--text-dark);"><i class="fa-solid fa-print"></i> Log Book Preview</h3>
                <div style="display:flex; gap:10px;">
                    <button type="button" onclick="printLogPreview()" class="btn-action" style="width:auto; padding:6px 12px; font-size:12px;"><i class="fa-solid fa-print"></i> Print</button>
                    <button type="button" onclick="closeSimpleModal('logPreviewModal')" style="border:none; background:none; font-size:18px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
                </div>
            </div>
            <div class="modal-body" id="previewContent" style="padding:0; overflow-y:auto; background:#525659;">
            </div>
        </div>
    </div>

    <div id="archiveModal" class="modal-overlay" style="z-index: 3500;">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: var(--text-dark);"><i class="fa-solid fa-box-archive"></i> History Vault Details</span>
                <button type="button" onclick="document.getElementById('archiveModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" id="tab-arch-overview" onclick="switchArchiveTab('overview')">Overview & Details</div>
                <div class="modal-tab" id="tab-arch-upload" onclick="switchArchiveTab('upload')">Archived Files</div>
                <div class="modal-tab" id="tab-arch-logs" onclick="switchArchiveTab('logs')">Archived Logs</div>
            </div>

            <div class="modal-body">
                <div id="arch-sec-overview" class="tab-content active">
                    </div>
                <div id="arch-sec-upload" class="tab-content">
                    </div>
                <div id="arch-sec-logs" class="tab-content">
                    </div>
            </div>
        </div>
    </div>

    <!-- Include SortableJS for smooth sliding animations during column rearrangement -->
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <script>
        const schemasByYear = <?php echo $schemas_json; ?>;
        const teamsData = <?php echo $teams_json; ?>;
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        let currentEditYear = '<?php echo $assigned_year; ?>';
        let currentEditDiv = '';
        let currentEditPid = '';
        let headMemberCount = 0;
        let activeLogsProjectId = 0;

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
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.toggle('active');
                document.getElementById('mobileOverlay').classList.toggle('active');
            } else {
                document.getElementById('sidebar').classList.toggle('collapsed');
            }
        }

        // INIT TABS
        function switchTab(tab) {
            sessionStorage.setItem('head_active_tab', tab);
            document.getElementById('section-dashboard').style.display = 'none';
            document.getElementById('section-history').style.display = 'none';
            document.getElementById('section-settings').style.display = 'none';
            document.getElementById('section-resets').style.display = 'none';
            document.getElementById('section-logs').style.display = 'none';
            if(document.getElementById('section-workspace')) document.getElementById('section-workspace').style.display = 'none';
            if(document.getElementById('section-exports')) document.getElementById('section-exports').style.display = 'none';
            
            document.getElementById('tab-dashboard').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-settings').classList.remove('active');
            document.getElementById('tab-resets').classList.remove('active');
            document.getElementById('tab-logs').classList.remove('active');
            if(document.getElementById('tab-workspace')) document.getElementById('tab-workspace').classList.remove('active');
            if(document.getElementById('tab-exports')) document.getElementById('tab-exports').classList.remove('active');
            
            if(document.getElementById('section-' + tab)) document.getElementById('section-' + tab).style.display = 'block';
            if(document.getElementById('tab-' + tab)) document.getElementById('tab-' + tab).classList.add('active');
            
            if(window.innerWidth <= 768) toggleMobileMenu();
        }

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
            sessionStorage.setItem('head_master_tab', tab);
            document.getElementById('tab-overview').classList.remove('active');
            document.getElementById('tab-form').classList.remove('active');
            document.getElementById('tab-upload').classList.remove('active');
            document.getElementById('tab-logs').classList.remove('active');
            document.getElementById('tab-classification').classList.remove('active');
            
            document.getElementById('modal-sec-overview').classList.remove('active');
            document.getElementById('modal-sec-form').classList.remove('active');
            document.getElementById('modal-sec-upload').classList.remove('active');
            document.getElementById('modal-sec-logs').classList.remove('active');
            document.getElementById('modal-sec-classification').classList.remove('active');

            document.getElementById('tab-' + tab).classList.add('active');
            document.getElementById('modal-sec-' + tab).classList.add('active');
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

        function closeMasterModal() {
            sessionStorage.removeItem('head_open_modal_pid');
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
            sessionStorage.setItem('head_open_modal_pid', projectId);

            document.getElementById('modal_group_title').innerText = data.group_name;

            // --- 1. POPULATE OVERVIEW TAB ---
            let escHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
            document.getElementById('modal_member_list').innerHTML = escHTML(data.member_details || '').replace(/\[Disabled\]/g, '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>').replace(/\n/g, '<br>');
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

            // --- 3. POPULATE CLASSIFICATION TAB ---
            document.getElementById('class_pid').value = data.id;
            document.getElementById('modal_project_type').value = data.project_type || '';
            const sdgCont = document.getElementById('sdgContainer');
            sdgCont.innerHTML = '';
            let existingGoals = [];
            try { existingGoals = JSON.parse(data.sdg_goals || '[]'); } catch(e) {}
            if (existingGoals.length > 0) {
                existingGoals.forEach(g => addSdgRow(g));
            } else {
                addSdgRow();
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
            let processedSchemaLabels = [];
            
            schemaArray.forEach(field => {
                processedSchemaLabels.push(field.label);
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

            let escapeHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
            for (let key in extraData) {
                if (!processedSchemaLabels.includes(key)) {
                    dynamicContainer.innerHTML += `
                        <div class="form-group">
                            <label>${escapeHTML(key)} <span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px;">Legacy Field</span></label>
                            <input type="text" class="g-input" value="${escapeHTML(extraData[key])}" readonly style="opacity:0.7;">
                        </div>
                    `;
                }
            }

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
                        <div class="request-block" style="${req.is_global == 1 ? 'border-left: 5px solid #F59E0B; background: rgba(245, 158, 11, 0.05);' : ''}">
                            <div class="request-header">
                                <span style="font-weight:600; color:var(--text-dark);">
                                    <i class="fa-solid ${req.is_global == 1 ? 'fa-globe' : 'fa-folder-open'}" style="color:#F59E0B; margin-right:8px;"></i> 
                                    ${req.folder_name}
                                    ${req.is_global == 1 ? '<span class="badge" style="font-size:10px; background:#F59E0B; color:white; padding:2px 8px; border-radius:10px; margin-left:10px;">GLOBAL</span>' : ''}
                                </span>
                                <div style="display:flex; gap:8px;">
                                    <button type="button" class="btn-icon" onclick="openEditFolderModal(${req.id}, '${escapedName}', '${escapedInstructions}')"><i class="fa-solid fa-pen"></i></button>
                                    <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('Delete this folder and ALL its files?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                                    <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('Delete this file?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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
                            
                            <form method="POST" class="ajax-form" enctype="multipart/form-data" class="ajax-form" style="display:flex; flex-direction:column; gap:8px; background:var(--card-bg); padding:15px; border-radius:10px; border:1px dashed var(--border-color);">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
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

            // --- 4. POPULATE LOGS TAB ---
            activeLogsProjectId = projectId; // Ensure 'Create Log' works
            let logsContainer = document.getElementById('modal_logs_container');
            let logsHtml = '';
            
            if(data.logs && data.logs.length > 0) {
                data.logs.forEach(log => {
                    let safeTitle = log.log_title ? log.log_title.replace(/'/g, "\\'").replace(/\"/g, '&quot;') : 'Untitled';
                    let fromDateStr = log.log_date ? new Date(log.log_date).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'No date set';
                    let toDateStr = log.log_date_to ? new Date(log.log_date_to).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'No date set';
                    
                    let plannedHtml = '';
                    let achievedHtml = '';
                    
                    try {
                        let tasks = Array.isArray(log.progress_planned) ? log.progress_planned : JSON.parse(log.progress_planned || '[]');
                        if (Array.isArray(tasks)) {
                            tasks.forEach(t => {
                                if(t.planned) plannedHtml += `• ${t.planned}<br>`;
                                if(t.achieved) achievedHtml += `✓ ${t.achieved}<br>`;
                            });
                        } else {
                            plannedHtml = log.progress_planned || '';
                        }
                    } catch(e) { plannedHtml = log.progress_planned || ''; }

                    let reviewHtml = log.guide_review ? log.guide_review.replace(/\n/g, '<br>') : '<span style="color:var(--text-light); font-style:italic;">No review provided yet. Click the <i class="fa-solid fa-comment-medical"></i> icon to add.</span>';

                    logsHtml += `
                        <div class="folder-block" style="border-left: 5px solid #8B5CF6; padding: 15px;">
                            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:10px; cursor:pointer;" onclick="document.getElementById('modal_log_details_${log.id}').style.display = document.getElementById('modal_log_details_${log.id}').style.display === 'none' ? 'block' : 'none';">
                                <div>
                                    <h4 style="font-size:16px; color:var(--text-dark); margin:0;">${safeTitle}</h4>
                                    <p style="font-size:11px; color:var(--text-light); margin:4px 0; line-height: 1.4;">
                                        <i class="fa-solid fa-calendar-day"></i> ${fromDateStr} - ${toDateStr}
                                        <br>Created by ${log.created_by_name}
                                    </p>
                                </div>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <button type="button" onclick="event.stopPropagation(); openReviewModal(${log.id}, ${projectId})" style="background:none; border:none; color:#3B82F6; cursor:pointer; font-size:18px;" title="Add/Edit Review">
                                        <i class="fa-solid fa-comment-medical"></i>
                                    </button>
                                    <form method="POST" class="ajax-form" onsubmit="return confirm('Delete this log completely?');" style="margin:0;" onclick="event.stopPropagation();">
                                        <input type="hidden" name="log_id" value="${log.id}">
                                        <button type="submit" name="delete_log_head" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div id="modal_log_details_${log.id}" style="display:none;">
                                <div style="display:grid; grid-template-columns: 1fr; gap:15px; margin-bottom:15px;">
                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#8B5CF6; display:block; margin-bottom:8px;">Planned Tasks</label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${plannedHtml}</div>
                                    </div>
                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#10B981; display:block; margin-bottom:8px;">Achieved</label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${achievedHtml}</div>
                                    </div>
                                    <div style="margin-top:5px;">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:8px;">Review</label>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); font-size:13px; color:var(--text-dark); line-height:1.5;">${reviewHtml}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                logsHtml = `<div style="grid-column:1/-1; text-align:center; padding:20px; color:var(--text-light); background:var(--input-bg); border-radius:12px; border:1px dashed var(--border-color);">No logs created yet.</div>`;
            }
            logsContainer.innerHTML = logsHtml;

            document.getElementById('masterModal').style.display = 'flex';
        }

        const sdgGoalsList = [
            "1. No Poverty", "2. Zero Hunger", "3. Good Health and Well-being", 
            "4. Quality Education", "5. Gender Equality", "6. Clean Water and Sanitation",
            "7. Affordable and Clean Energy", "8. Decent Work and Economic Growth",
            "9. Industry, Innovation and Infrastructure", "10. Reduced Inequality",
            "11. Sustainable Cities and Communities", "12. Responsible Consumption and Production",
            "13. Climate Action", "14. Life Below Water", "15. Life on Land",
            "16. Peace and Justice Strong Institutions", "17. Partnerships for the Goals"
        ];

        function addSdgRow(selectedValue = '') {
            const container = document.getElementById('sdgContainer');
            const rowId = 'sdg_row_' + Date.now() + Math.floor(Math.random()*1000);
            
            let optionsHtml = sdgGoalsList.map(goal => `<option value="${goal}" ${goal === selectedValue ? 'selected' : ''}>${goal}</option>`).join('');
            
            const div = document.createElement('div');
            div.id = rowId;
            div.style = "display:flex; gap:10px; margin-bottom:10px; align-items:center;";
            div.innerHTML = `
                <select name="sdg_goals[]" class="g-input" style="flex:1;" required>
                    <option value="">-- Select SDG Goal --</option>
                    ${optionsHtml}
                </select>
                <button type="button" onclick="document.getElementById('${rowId}').remove()" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;"><i class="fa-solid fa-circle-xmark"></i></button>
            `;
            container.appendChild(div);
        }

        let headLogExtraCount = 0;
        function addLogExtraField(label = '', value = '') {
            headLogExtraCount++;
            const container = document.getElementById('log_extra_fields');
            const fieldId = 'head_log_extra_' + headLogExtraCount;

            const fieldWrapper = document.createElement('div');
            fieldWrapper.id = fieldId;
            fieldWrapper.style = 'display:flex; flex-wrap:wrap; gap:8px; align-items:flex-start; padding:10px; border:1px solid #dce6fb; border-radius:8px; background:#ffffff;';
            fieldWrapper.innerHTML = `
                <input type="text" placeholder="Field title" value="${label}" style="flex:1; min-width:150px; padding:8px; border:1px solid #c7d4ed; border-radius:6px; background:#fdfdff; color:#223456;" />
                <textarea placeholder="Field value" style="flex:2; min-width:220px; padding:8px; border:1px solid #c7d4ed; border-radius:6px; background:#fcfdff; color:#223456;">${value}</textarea>
                <button type="button" onclick="removeLogExtraField('${fieldId}')" style="background:#ef4444; color:white; border:none; border-radius:6px; padding:7px 10px; font-size:12px; cursor:pointer;">Remove</button>
            `;
            container.appendChild(fieldWrapper);
        }

        function removeLogExtraField(id) {
            const elm = document.getElementById(id);
            if (elm) elm.remove();
        }

        function prepareLogSubmission(event) {
            const extraNodes = Array.from(document.getElementById('log_extra_fields').children);
            const extras = extraNodes.map(node => {
                const inputs = node.querySelectorAll('input, textarea');
                if (!inputs || inputs.length < 2) return null;
                const label = inputs[0].value.trim();
                const value = inputs[1].value.trim();
                return label && value ? { label, value } : null;
            }).filter(Boolean);

            document.getElementById('log_extra_entries').value = JSON.stringify(extras);

            if (!document.getElementById('log_date').value) {
                const now = new Date();
                const local = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0,16);
                document.getElementById('log_date').value = local;
            }

            return true;
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
                    ${data.is_locked ? '<span class="badge badge-success" style="font-size:14px;"><i class="fa-solid fa-check-circle"></i> Topic Finalized</span>' : '<span class="badge badge-warning" style="font-size:14px;">Not Finalized</span>'}
                </div>
            `;

            // -- TAB 1: FORM DETAILS --
            let extraData = {};
            if (data.extra_data) { try { extraData = JSON.parse(data.extra_data); } catch(e) {} }
            let schemaArray = schemasByYear[data.project_year] || [];
            let escapeHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
                        let processedKeys = [];

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

                    <button type="button" class="btn-action" onclick="document.getElementById('full_form_archive_' + ${data.id}).style.display='block'; this.style.display='none';" style="width: 100%; justify-content: center; padding: 10px; font-size: 14px; background:var(--bg-color); color:var(--primary-green); border:1px solid var(--primary-green);"><i class="fa-solid fa-file-lines"></i> View Full Form Details</button>
                    
                    <div id="full_form_archive_${data.id}" style="display:none; margin-top:20px; border-top:1px dashed var(--border-color); padding-top:20px;">
                        <div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase; letter-spacing:1px;">Original Form Submission</div>
            `;
            
            
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

            let uploadsHtml = `
                <div style="font-size:12px; font-weight:700; color:var(--text-light); margin-bottom:15px; text-transform:uppercase;">Archived Files</div>
                <div style="background:var(--input-bg); border:1px solid var(--border-color); padding:15px; border-radius:12px; margin-bottom:20px;">
                    <label style="font-size:12px; font-weight:600; color:var(--text-light); text-transform:uppercase;">Create New Folder (Archive)</label>
                    <form method="POST" class="ajax-form" style="display:flex; flex-direction:column; gap:10px; margin-top:10px; box-sizing: border-box; width: 100%;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="target_project_id" value="${data.id}">
                        <input type="hidden" name="current_guide_id" value="${data.assigned_guide_id || 0}">
                        <input type="text" name="folder_name" placeholder="Folder Name (e.g. Late Submission)" required style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; background:var(--card-bg); color:var(--text-dark); box-sizing: border-box; width: 100%;">
                        <textarea name="instructions" placeholder="Optional Note/Instructions" style="padding:10px; border:1px solid var(--border-color); border-radius:8px; font-family:inherit; outline:none; resize:vertical; min-height:60px; background:var(--card-bg); color:var(--text-dark); box-sizing: border-box; width: 100%;"></textarea>
                        <button type="submit" name="create_folder" style="background:var(--btn-blue); color:white; border:none; padding:10px 20px; border-radius:8px; cursor:pointer; font-weight:600; align-self:flex-start;"><i class="fa-solid fa-plus"></i> Create Folder</button>
                    </form>
                </div>
            `;
            
            if(data.requests && data.requests.length > 0) {
                data.requests.forEach(req => {
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div class="instruction-note"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
                    }
                    let escapedName = req.folder_name ? req.folder_name.replace(/'/g, "\\'").replace(/"/g, '&quot;') : '';
                    let escapedInstructions = req.instructions ? req.instructions.replace(/'/g, "\\'").replace(/"/g, '&quot;') : '';

                    uploadsHtml += `
                    <div style="border:1px solid var(--border-color); border-radius:12px; padding:15px; margin-bottom:15px; background:var(--card-bg);">
                        <div class="request-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; margin-bottom: 15px; flex-wrap:wrap; gap:10px;">
                            <span style="font-weight:600; color:var(--text-dark);"><i class="fa-solid fa-folder-open" style="color:#F59E0B; margin-right:8px;"></i> ${req.folder_name}</span>
                            <div style="display:flex; gap:8px;">
                                <button type="button" class="btn-icon" onclick="openEditFolderModal(${req.id}, '${escapedName}', '${escapedInstructions}')"><i class="fa-solid fa-pen"></i></button>
                                <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('Delete this folder and ALL its files?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="req_id" value="${req.id}">
                                    <button type="submit" name="delete_folder" class="btn-icon" style="color:#EF4444; border-color:#FECACA;"><i class="fa-solid fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        ${noteHtml}
                    `;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            uploadsHtml += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--input-bg); padding:10px 15px; border-radius:8px; margin-bottom:8px; border:1px solid var(--border-color);">
                                <div style="flex:1;">
                                    <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:var(--btn-blue); text-decoration:none;"><i class="fa-regular fa-file-pdf" style="margin-right:5px;"></i> ${f.file_name}</a>
                                    <div style="font-size:11px; color:var(--text-light); margin-top:3px;">By: ${f.uploaded_by_name}</div>
                                </div>
                                <form method="POST" class="ajax-form" style="margin:0;" onsubmit="return confirm('WARNING: You are deleting a file from an ARCHIVED project. This is permanent. Proceed?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="file_id" value="${f.id}">
                                    <button type="submit" name="delete_file" style="background:none; border:none; color:#EF4444; cursor:pointer;" title="Delete Archived File"><i class="fa-solid fa-trash-can"></i></button>
                                </form>
                            </div>`;
                        });
                    } else {
                        uploadsHtml += `<div style="font-size:12px; color:var(--text-light); font-style:italic;">No files uploaded in this folder.</div>`;
                    }
                    
                    uploadsHtml += `
                        <form method="POST" class="ajax-form" enctype="multipart/form-data" class="ajax-form" style="display:flex; flex-direction:column; gap:8px; background:var(--input-bg); padding:15px; border-radius:10px; border:1px dashed var(--border-color); box-sizing:border-box; width:100%; margin-top: 15px;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="request_id" value="${req.id}">
                            <input type="hidden" name="proj_id" value="${data.id}">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <div style="font-size:11px; color:var(--text-light);">Max file size: 5MB</div>
                            </div>
                            <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                <input type="file" name="document" required style="font-size:12px; flex:1; color:var(--text-dark); min-width:150px;">
                                <button type="submit" name="upload_file_head" style="background:var(--primary-green); color:white; border:none; padding:8px 15px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
                            </div>
                        </form>
                    `;
                    uploadsHtml += `</div>`;
                });
            } else {
                uploadsHtml += `<div style="text-align:center; padding:20px; background:var(--card-bg); border-radius:12px; border:1px dashed var(--border-color); color:var(--text-light); font-size:13px;">No folders exist in this archived group. Create one to upload files.</div>`;
            }

            // -- TAB 3: LOGS --
            let logsHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div style="font-size:12px; font-weight:700; color:var(--text-light); text-transform:uppercase;">Archived Logs</div>
                    <button type="button" onclick="activeLogsProjectId = ${data.id}; openCreateLogModalNew();" class="btn-action" style="width:auto; padding:6px 12px; background:var(--primary-green);"><i class="fa-solid fa-plus"></i> Create Log</button>
                </div>
            `;
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

        // ADD MEMBER LOGIC (EDIT FORM)
        function addHeadMemberRow(moodle = '', name = '', isLeader = false) {
            const container = document.getElementById('head_membersContainer');
            const wrapper = document.createElement('div');
            let idx = headMemberCount++;
            
            wrapper.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                    <label style="cursor:pointer; display:flex; flex-direction:column; align-items:center;" title="Set as Team Leader">
                        <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Leader</span>
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
        //        LOG MANAGEMENT FUNCTIONS
        // ==========================================
        function openGroupLogs(projectId) {
            const data = teamsData[projectId];
            if (!data) return;
            
            sessionStorage.setItem('head_open_log_pid', projectId);
            activeLogsProjectId = projectId;
            document.getElementById('logs-detail-title').innerText = "Logs: " + data.group_name;
            document.getElementById('logs-detail-leader').innerText = "Leader: " + (data.leader_name || 'Unknown');
            
            let logsHtml = '';
            if (data.logs && data.logs.length > 0) {
                data.logs.forEach(log => {
                                        let safeTitle = log.log_title ? String(log.log_title).replace(/'/g, "\\'").replace(/\"/g, '&quot;') : 'Untitled';
                    let fromDateStr = log.log_date ? new Date(log.log_date).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'No date set';
                    let toDateStr = log.log_date_to ? new Date(log.log_date_to).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'}) : 'No date set';
                    
                    let plannedHtml = '';
                    let achievedHtml = '';
                    
                    try {
                        let tasks = Array.isArray(log.progress_planned) ? log.progress_planned : JSON.parse(log.progress_planned || '[]');
                        if (Array.isArray(tasks)) {
                            tasks.forEach(t => {
                                if(t.planned) plannedHtml += `• ${t.planned}<br>`;
                                if(t.achieved) achievedHtml += `✓ ${t.achieved}<br>`;
                            });
                        } else {
                            plannedHtml = log.progress_planned || '';
                        }
                    } catch(e) { plannedHtml = log.progress_planned || ''; }

                    let reviewHtml = log.guide_review ? String(log.guide_review).replace(/\n/g, '<br>') : '<em style="color:gray;">No review provided.</em>';

                    logsHtml += `
                        <div class="folder-block" style="border-left: 5px solid #8B5CF6; padding: 15px;">
                            <div style="display:flex; justify-content:space-between; align-items:start; margin-bottom:10px; cursor:pointer;" onclick="document.getElementById('log_details_${log.id}').style.display = document.getElementById('log_details_${log.id}').style.display === 'none' ? 'block' : 'none';">
                                <div>
                                    <h4 style="font-size:16px; color:var(--text-dark); margin:0;">${safeTitle}</h4>
                                    <p style="font-size:11px; color:var(--text-light); margin:4px 0; line-height: 1.4;">
                                        <i class="fa-solid fa-calendar-day"></i> ${fromDateStr} - ${toDateStr}
                                        <br>Created by ${log.created_by_name}
                                    </p>
                                </div>
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <button type="button" onclick="event.stopPropagation(); openReviewModal(${log.id}, ${projectId})" style="background:none; border:none; color:#3B82F6; cursor:pointer; font-size:18px;" title="Add/Edit Review">
                                        <i class="fa-solid fa-comment-medical"></i>
                                    </button>
                                    <form method="POST" class="ajax-form" onsubmit="return confirm('Delete this log completely?');" style="margin:0;" onclick="event.stopPropagation();">
                                        <input type="hidden" name="log_id" value="${log.id}">
                                        <button type="submit" name="delete_log_head" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div id="log_details_${log.id}" style="display:none;">
                                <div style="display:grid; grid-template-columns: 1fr; gap:15px; margin-bottom:15px;">
                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#8B5CF6; display:block; margin-bottom:8px;">Planned Tasks</label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${plannedHtml}</div>
                                    </div>

                                    <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color);">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#10B981; display:block; margin-bottom:8px;">Achieved</label>
                                        <div style="font-size:13px; color:var(--text-dark); line-height:1.5;">${achievedHtml}</div>
                                    </div>

                                    <div style="margin-top:5px;">
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:8px;">Guide/Head Review</label>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); font-size:13px; color:var(--text-dark); line-height:1.5;">${reviewHtml}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                logsHtml = `<div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-light);"><i class="fa-solid fa-book" style="font-size:50px; color:var(--border-color); margin-bottom:20px;"></i><h4 style="color:var(--text-dark); margin-bottom:10px;">Log Book is Empty</h4><p style="font-size:14px; max-width:400px; margin:0 auto 20px auto;">No logs have been created for this group yet.</p></div>`;
            }
            
            document.getElementById('logs-detail-container').innerHTML = logsHtml;
            document.getElementById('logs-groups-view').style.display = 'none';
            document.getElementById('logs-detail-view').style.display = 'block';
        }
        
        function closeGroupLogs() {
            sessionStorage.removeItem('head_open_log_pid');
            document.getElementById('logs-detail-view').style.display = 'none';
            document.getElementById('logs-groups-view').style.display = 'block';
        }
        
        function openReviewModal(logId, projectId) {
            const data = teamsData[projectId];
            if (!data) return;
            const log = data.logs.find(l => l.id == logId);
            if (!log) return;
            
            document.getElementById('review_log_id').value = log.id;
            document.getElementById('review_project_id').value = projectId;
            document.getElementById('review_text_field').value = log.guide_review || '';
            document.getElementById('reviewModal').style.display = 'flex';
        }

        function openCreateLogModalNew() {
            document.getElementById('create_log_project_id').value = activeLogsProjectId;
            const tbody = document.querySelector('#logTable tbody');
            tbody.innerHTML = '';
            addLogRow();
            document.getElementById('createLogModal').style.display = 'flex';
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

        // RESTORE STATE ON PAGE LOAD
        window.onload = () => { 
            let phpTab = '<?php echo ($active_tab !== "dashboard" && !empty($_POST)) ? $active_tab : ""; ?>';
            let savedTab = sessionStorage.getItem('head_active_tab') || '<?php echo $active_tab; ?>';
            let finalTab = phpTab || savedTab;
            
            switchTab(finalTab);

            <?php if (!empty($reopen_log_project_id)): ?>
                // Reopen the specific log view after a PHP log action
                setTimeout(() => { openGroupLogs(<?php echo $reopen_log_project_id; ?>); }, 50);
            <?php else: ?>
                // Restore previous state safely
                if (finalTab === 'dashboard') {
                    let openModalPid = sessionStorage.getItem('head_open_modal_pid');
                    if (openModalPid) {
                        openMasterModal(openModalPid);
                        let mTab = sessionStorage.getItem('head_master_tab');
                        if (mTab) switchModalTab(mTab);
                    }
                } else if (finalTab === 'logs') {
                    let openLogPid = sessionStorage.getItem('head_open_log_pid');
                    if (openLogPid) setTimeout(() => { openGroupLogs(openLogPid); }, 50);
                }
            <?php endif; ?>
        };

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

        async function fetchReportPreview() {
            const form = document.getElementById('exportFilterForm');
            const previewArea = document.getElementById('reportPreviewArea');
            const previewContent = document.getElementById('reportPreviewContent');
            
            // Collect form data
            const formData = new FormData(form);
            const params = new URLSearchParams(formData);
            params.append('preview', '1');

            previewArea.style.display = 'block';
            previewContent.innerHTML = '<div style="text-align:center; padding:50px;"><i class="fa-solid fa-circle-notch fa-spin" style="font-size:30px; color:var(--primary-green);"></i><p style="margin-top:15px; color:var(--text-light);">Generating live preview...</p></div>';
            
            // Scroll to preview
            previewArea.scrollIntoView({ behavior: 'smooth' });

            try {
                // Use iframe to prevent CSS bleeding and ensure exact rendering
                previewContent.innerHTML = '';
                const iframe = document.createElement('iframe');
                iframe.src = 'export_handler.php?' + params.toString();
                iframe.style.width = '100%';
                iframe.style.height = '600px';
                iframe.style.border = 'none';
                iframe.style.background = 'white';
                previewContent.appendChild(iframe);
            } catch (error) {
                console.error('Preview failed:', error);
                previewContent.innerHTML = '<div style="color:#EF4444; padding:20px;">Failed to load preview. Please try again.</div>';
            }
        }

        // ==========================================
        // HISTORICAL GROUP ENTRY LOGIC
        // ==========================================
        
        let currentHistSchema = null;
        let histValidatedMembers = [];

        function openHistoricalEntryModal() {
            switchHistStep(1);
            openSimpleModal('historicalEntryModal');
        }

        function switchHistStep(step) {
            document.getElementById('hist-step-1').style.display = (step === 1) ? 'block' : 'none';
            document.getElementById('hist-step-2').style.display = (step === 2) ? 'block' : 'none';
        }

        async function loadHistoricalForm() {
            const session = document.getElementById('hist_session_select').value;
            const year = document.getElementById('hist_year_val').value;
            const sem = document.getElementById('hist_sem_select').value;

            if(!session) { alert('Please select an academic session.'); return; }

            document.getElementById('hist_selection_label').innerText = `${session} | ${year} Sem ${sem}`;
            const container = document.getElementById('hist_form_container');
            container.innerHTML = '<div style="padding:40px; text-align:center; color:var(--text-light);"><i class="fa-solid fa-spinner fa-spin" style="font-size:30px; margin-bottom:15px;"></i><br>Fetching Form Schema...</div>';
            switchHistStep(2);

            const formData = new FormData();
            formData.append('action', 'get_historical_form');
            formData.append('academic_session', session);
            formData.append('academic_year', year);
            formData.append('semester', sem);

            try {
                const response = await fetch('ajax_history_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if(result.status === 'success') {
                    currentHistSchema = result;
                    renderHistForm(result);
                } else {
                    alert(result.message);
                    switchHistStep(1);
                }
            } catch (error) {
                alert('Connection error.');
                switchHistStep(1);
            }
        }

        function renderHistForm(config) {
            const container = document.getElementById('hist_form_container');
            let html = `
                <div style="margin-bottom:20px; padding:15px; background:rgba(16,93,63,0.05); border-radius:12px; border:1px dashed var(--primary-green);">
                    <label style="display:block; font-size:12px; font-weight:700; color:var(--primary-green); margin-bottom:10px;">TEAM MEMBERS (Moodle IDs)</label>
                    <div style="display:flex; gap:10px; flex-direction:column;" id="hist_members_input_area">
                        <p style="font-size:11px; color:var(--text-light);">Enter Moodle IDs separated by commas or new lines.</p>
                        <textarea id="hist_moodle_ids" placeholder="e.g. 21102A0001, 21102A0002" class="filter-select" style="width:100%; height:80px; padding:10px;"></textarea>
                        <button type="button" class="btn-outline" onclick="validateHistMembers(event)" style="align-self:flex-start;"><i class="fa-solid fa-user-check"></i> Validate Members</button>
                    </div>
                    <div id="hist_members_validated" style="display:none; margin-top:10px;"></div>
                </div>
                <div id="hist_dynamic_fields">
            `;

            config.schema.forEach(field => {
            if (field.type === 'team-members') return; // Skip rendering team members as it's already handled
                const req = field.required ? 'required' : '';
                html += `<div class="form-group"><label>${field.label} ${field.required ? '<span style="color:red">*</span>' : ''}</label>`;
                if(field.type === 'textarea') {
                    html += `<textarea name="hist_field_${field.label}" class="filter-select" style="width:100%; height:80px;" ${req}></textarea>`;
                } else if(field.type === 'select') {
                    html += `<select name="hist_field_${field.label}" class="filter-select" style="width:100%;" ${req}>`;
                    field.options.forEach(opt => html += `<option value="${opt}">${opt}</option>`);
                    html += `</select>`;
                } else {
                    html += `<input type="text" name="hist_field_${field.label}" class="filter-select" style="width:100%;" ${req}>`;
                }
                html += `</div>`;
            });

            html += `</div>`;
            container.innerHTML = html;
        }

        async function validateHistMembers(e) {
            const session = document.getElementById('hist_session_select').value;
            const year = document.getElementById('hist_year_val').value;
            const sem = document.getElementById('hist_sem_select').value;
            const input = document.getElementById('hist_moodle_ids').value;
            const ids = input.split(/[,\n]/).map(id => id.trim()).filter(id => id.length > 0);

            if(ids.length < currentHistSchema.min_size || ids.length > currentHistSchema.max_size) {
                alert(`Team size must be between ${currentHistSchema.min_size} and ${currentHistSchema.max_size} for this form.`);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'validate_historical_members');
            formData.append('academic_session', session);
            formData.append('academic_year', year);
            formData.append('semester', sem);
            ids.forEach(id => formData.append('moodle_ids[]', id));

            try {
                const response = await fetch('ajax_history_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if(result.status === 'success') {
                    histValidatedMembers = result.members;
                    const display = document.getElementById('hist_members_validated');
                    display.style.display = 'block';
                    let html = '<div style="font-size:12px; color:var(--primary-green); font-weight:600; margin-bottom:8px;"><i class="fa-solid fa-check-circle"></i> Members Verified:</div>';
                    result.members.forEach((m, idx) => {
                        html += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:var(--card-bg); padding:8px 12px; border-radius:8px; margin-bottom:5px; border:1px solid var(--border-color);">
                                <span style="font-size:12px;"><strong>${m.full_name}</strong> (${m.division})</span>
                                <label style="font-size:10px; cursor:pointer;"><input type="radio" name="hist_leader" value="${m.id}" ${idx===0?'checked':''}> Leader</label>
                            </div>
                        `;
                    });
                    display.innerHTML = html;
                    document.getElementById('hist_moodle_ids').style.display = 'none';
                    e.target.style.display = 'none';
                } else {
                    let msg = result.message;
                    if(result.invalid) msg += "\n\n" + result.invalid.join("\n");
                    alert(msg);
                }
            } catch (error) {
                alert('Validation request failed.');
            }
        }

        async function submitHistoricalGroup() {
            if(histValidatedMembers.length === 0) { alert('Please validate members first.'); return; }
            
            const formData = new FormData();
            formData.append('action', 'save_historical_project');
            formData.append('csrf_token', csrfToken);
            formData.append('academic_session', document.getElementById('hist_session_select').value);
            formData.append('academic_year', document.getElementById('hist_year_val').value);
            formData.append('semester', document.getElementById('hist_sem_select').value);
            
            const leaderRadio = document.querySelector('input[name="hist_leader"]:checked');
            formData.append('leader_id', leaderRadio ? leaderRadio.value : histValidatedMembers[0].id);
            
            histValidatedMembers.forEach(m => formData.append('member_ids[]', m.id));

            // Gather dynamic fields
            const dynamicContainer = document.getElementById('hist_dynamic_fields');
            const inputs = dynamicContainer.querySelectorAll('input, select, textarea');
            let isValid = true;
            inputs.forEach(input => {
                if(input.hasAttribute('required') && !input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = 'red';
                }
                const label = input.name.replace('hist_field_', '');
                formData.append(`form_data[${label}]`, input.value);
            });

            if(!isValid) { alert('Please fill in all required fields.'); return; }

            const btn = document.getElementById('hist_save_btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            try {
                const response = await fetch('ajax_history_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                if(result.status === 'success') {
                    alert(result.message);
                    location.reload();
                } else {
                    alert(result.message);
                    btn.disabled = false;
                    btn.innerHTML = 'Save to History Vault';
                }
            } catch (error) {
                alert('Save failed.');
                btn.disabled = false;
                btn.innerHTML = 'Save to History Vault';
            }
        }

        // --- Dynamic Column Selection Logic (With SortableJS sliding effect) ---
        let selectedColumns = [];
        let sortableInstance = null;
        
        function addColumnPill(value = null) {
            const dropdown = document.getElementById('colSelectDropdown');
            const val = value || dropdown.value;
            
            if (!val || selectedColumns.includes(val)) return; // Don't add duplicates
            
            selectedColumns.push(val);
            renderPills();
        }
        
        function removeColumnPill(val) {
            selectedColumns = selectedColumns.filter(c => c !== val);
            renderPills();
        }
        
        function renderPills() {
            const container = document.getElementById('colPillsContainer');
            container.innerHTML = '';
            
            selectedColumns.forEach((val, index) => {
                const dropdown = document.getElementById('colSelectDropdown');
                let label = val;
                for (let i = 0; i < dropdown.options.length; i++) {
                    if (dropdown.options[i].value === val) {
                        label = dropdown.options[i].text;
                        break;
                    }
                }
                
                const pill = document.createElement('div');
                pill.className = 'col-pill';
                pill.dataset.val = val;
                pill.style.cssText = 'display:inline-flex; align-items:center; gap:8px; background:#E5E7EB; color:#374151; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:500; user-select:none;';
                pill.innerHTML = `
                    <i class="fa-solid fa-grip-vertical sort-handle" style="color:#9CA3AF; cursor:grab;"></i>
                    ${label}
                    <button type="button" onclick="removeColumnPill('${val}')" style="background:none; border:none; color:#EF4444; cursor:pointer; padding:0; display:flex; align-items:center;"><i class="fa-solid fa-xmark"></i></button>
                `;
                
                container.appendChild(pill);
            });
            
            updateHiddenCols();
            
            // Initialize or update SortableJS instance
            if (!sortableInstance) {
                sortableInstance = new Sortable(container, {
                    animation: 150, // Snappy sliding effect
                    easing: "cubic-bezier(0.25, 1, 0.5, 1)",
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    forceFallback: true,
                    fallbackClass: 'sortable-drag',
                    fallbackOnBody: true,
                    handle: '.sort-handle',
                    onEnd: function (evt) {
                        // Rebuild selectedColumns array based on new DOM order after sliding
                        selectedColumns = Array.from(container.children).map(child => child.dataset.val);
                        updateHiddenCols();
                    }
                });
            }
        }
        
        function updateHiddenCols() {
            const container = document.getElementById('hiddenColsContainer');
            container.innerHTML = '';
            selectedColumns.forEach(val => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'cols[]';
                input.value = val;
                container.appendChild(input);
            });
        }
        
        // Initialize default columns on load
        window.addEventListener('DOMContentLoaded', () => {
            const defaults = ['sr_no', 'group_no', 'roll_no', 'student_name', 'topics', 'final_topic', 'sdg', 'guide_name', 'sign'];
            defaults.forEach(c => addColumnPill(c));
        });

        </script>
        <script src="assets/js/ajax-forms.js?v=1.2"></script>
    </body>
    </html> 