<?php
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'guide') { header("Location: index.php"); exit(); }

verify_csrf_token();

$guide_name = $_SESSION['name'];
$guide_id = $_SESSION['user_id'];
$msg = "";
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// FETCH SCHEMAS FOR SUPER-EDIT & ARCHIVE VIEW
$schemas = [];
$schema_q = $conn->query("SELECT academic_year, form_schema FROM form_settings");
while($s = $schema_q->fetch_assoc()) {
    $schemas[$s['academic_year']] = $s['form_schema'] ? json_decode($s['form_schema'], true) : [];
}
$schemas_json = json_encode($schemas, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
// ==============================================
//   ACCOUNT SETTINGS (AJAX-FRIENDLY)
// ==============================================
if (isset($_POST['change_password'])) {
    $current_pass = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm_pass = $_POST['confirm_password'] ?? '';
    $is_ajax = isset($_POST['is_ajax']);

    $stmt = $conn->prepare("SELECT id, password FROM guide WHERE id = ?");
    $stmt->bind_param("i", $guide_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && verify_and_upgrade_password($conn, 'guide', (int)$row['id'], $current_pass, (string)$row['password'])) {
        if ($new_pass === $confirm_pass) {
            $h = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE guide SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $h, $guide_id);
            $stmt->execute();
            $stmt->close();
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
//        GUIDE UPLOAD & TOPIC ACTIONS
// ==============================================
// ==============================================
//        GUIDE STUDENT MANAGEMENT
// ==============================================
if (isset($_POST['edit_student'])) {
    $id = (int)$_POST['student_id'];
    $name = $_POST['full_name'] ?? '';
    $year = $_POST['academic_year'] ?? '';
    $div = $_POST['division'] ?? '';
    $phone = $_POST['phone_number'] ?? '';
    $sem = (int)($_POST['current_semester'] ?? 0);
    $new_pass = $_POST['new_password'] ?? '';
    
    // Security check: ensure student is in a group assigned to this guide
    $stmt = $conn->prepare("SELECT s.id FROM student s JOIN project_members pm ON pm.student_id = s.id JOIN projects p ON p.id = pm.project_id WHERE s.id = ? AND p.assigned_guide_id = ? AND p.is_archived = 0");
    $stmt->bind_param("ii", $id, $guide_id);
    $stmt->execute();
    $check_access = $stmt->get_result();
    $stmt->close();
    
    if ($check_access && $check_access->num_rows > 0) {
        if (!empty($new_pass)) {
            $h = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE student SET full_name=?, academic_year=?, current_semester=?, division=?, phone_number=?, password=? WHERE id=?");
            $stmt->bind_param("ssisssi", $name, $year, $sem, $div, $phone, $h, $id);
        } else {
            $stmt = $conn->prepare("UPDATE student SET full_name=?, academic_year=?, current_semester=?, division=?, phone_number=? WHERE id=?");
            $stmt->bind_param("ssissi", $name, $year, $sem, $div, $phone, $id);
        }
        
        if ($stmt->execute()) {
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Student information updated!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Student info updated!', ['reload' => true]);
        }
        $stmt->close();
    } else {
        if (isset($_POST['is_ajax'])) send_ajax_response('error', 'Unauthorized access.');
    }
}


// FETCH STUDENTS ASSIGNED TO THIS GUIDE
$guide_students = $conn->query("
    SELECT DISTINCT s.*, p.group_name 
    FROM student s 
    JOIN project_members pm ON pm.student_id = s.id 
    JOIN projects p ON p.id = pm.project_id 
    WHERE p.assigned_guide_id = $guide_id AND p.is_archived = 0
    ORDER BY s.full_name ASC
");

if (isset($_POST['edit_folder'])) {
    $r_id = (int)$_POST['request_id'];
    $new_name = $_POST['folder_name'] ?? '';
    $edit_instructions = $_POST['instructions'] ?? ''; 
    
    $stmt = $conn->prepare("UPDATE upload_requests SET folder_name=?, instructions=? WHERE id=?");
    $stmt->bind_param("ssi", $new_name, $edit_instructions, $r_id);
    if ($stmt->execute()) {
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-pen'></i> Folder updated successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
    $stmt->close();
}

if (isset($_POST['delete_folder'])) {
    $r_id = (int)$_POST['request_id'];
    // No need to manual unlink here, we can rely on file system if needed but let's keep it consistent
    $stmt_f = $conn->prepare("SELECT file_path FROM student_uploads WHERE request_id=?");
    $stmt_f->bind_param("i", $r_id);
    $stmt_f->execute();
    $files = $stmt_f->get_result();
    while($f = $files->fetch_assoc()) { if(file_exists($f['file_path'])) unlink($f['file_path']); }
    $stmt_f->close();

    $stmt = $conn->prepare("DELETE FROM student_uploads WHERE request_id=?");
    $stmt->bind_param("i", $r_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM upload_requests WHERE id=?");
    $stmt->bind_param("i", $r_id);
    if ($stmt->execute()) {
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Folder and files deleted!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
    $stmt->close();
}

if (isset($_POST['delete_file'])) {
    $f_id = (int)$_POST['file_id'];
    $stmt_f = $conn->prepare("SELECT file_path FROM student_uploads WHERE id=?");
    $stmt_f->bind_param("i", $f_id);
    $stmt_f->execute();
    $file_info = $stmt_f->get_result()->fetch_assoc();
    $stmt_f->close();

    if($file_info && file_exists($file_info['file_path'])) unlink($file_info['file_path']);
    
    $stmt = $conn->prepare("DELETE FROM student_uploads WHERE id=?");
    $stmt->bind_param("i", $f_id);
    if ($stmt->execute()) {
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash-can'></i> File removed successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
    $stmt->close();
}


if (isset($_POST['upload_file_guide'])) {
    $req_id = $_POST['request_id'];
    $p_id = $_POST['proj_id'];
    $file = $_FILES['document'];
    $stored = secure_store_uploaded_file($file, "guide_req{$req_id}_p{$p_id}");
    if (!$stored['ok']) {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> ".$stored['error']."</div>";
    } else {
        $guide_tag = $guide_name . " (Guide)";
        $orig = $stored['original'];
        $path = $stored['path'];
        $stmt = $conn->prepare("INSERT INTO student_uploads (project_id, request_id, file_name, file_path, uploaded_by_name) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $p_id, $req_id, $orig, $path, $guide_tag);
        $stmt->execute();
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-cloud-arrow-up'></i> File uploaded to folder!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
    }
}

// ==============================================
//           LOG MANAGEMENT (MERGED)
// ==============================================
$reopen_log_project_id = 0;

if (isset($_POST['update_classification'])) {
    $p_id = (int)$_POST['project_id'];
    $type = $_POST['project_type'] ?? '';
    $goals = isset($_POST['sdg_goals']) ? json_encode($_POST['sdg_goals'], JSON_UNESCAPED_UNICODE) : '[]';
    
    $stmt = $conn->prepare("UPDATE projects SET project_type=?, sdg_goals=? WHERE id=? AND assigned_guide_id=?");
    $stmt->bind_param("ssii", $type, $goals, $p_id, $guide_id);
    if ($stmt->execute()) {
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Project classification updated successfully!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
        $active_tab = 'dashboard';
        $reopen_modal_project_id = $p_id;
    } else {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error updating classification.</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', $msg);
    }
    $stmt->close();
}

if (isset($_POST['update_guide_review'])) {
    $log_id = (int)$_POST['log_id'];
    $p_id = (int)$_POST['project_id'];
    $review = $_POST['guide_review'] ?? '';
    
    // Verify security to ensure guide owns the project
    $stmt_v = $conn->prepare("SELECT pl.id FROM project_logs pl JOIN projects p ON pl.project_id = p.id WHERE pl.id = ? AND p.assigned_guide_id = ?");
    $stmt_v->bind_param("ii", $log_id, $guide_id);
    $stmt_v->execute();
    $verify = $stmt_v->get_result();
    $stmt_v->close();

    if ($verify && $verify->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE project_logs SET guide_review=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param("si", $review, $log_id);
        if ($stmt->execute()) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> Review updated successfully!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
            $active_tab = 'logs';
            $reopen_log_project_id = $p_id;
        }
        $stmt->close();
    }
}


if (isset($_POST['create_log_guide'])) {
    $p_id = (int)$_POST['project_id'];
    
    // Verify security
    $verify = $conn->query("SELECT id FROM projects WHERE id = $p_id AND assigned_guide_id = $guide_id");
    if ($verify && $verify->num_rows > 0) {
        $title = $conn->real_escape_string($_POST['log_title']);
        $date_from = $conn->real_escape_string($_POST['log_date_from']);
        $date_to = $conn->real_escape_string($_POST['log_date_to']);
        $review = $conn->real_escape_string($_POST['guide_review']);
        $status = 'Working'; 
        
        $planned_arr = $_POST['planned_tasks'] ?? [];
        $achieved_arr = $_POST['achieved_tasks'] ?? [];
        $table_data = [];
        for($i=0; $i < count($planned_arr); $i++) {
            if(!empty($planned_arr[$i]) || !empty($achieved_arr[$i])) {
                $table_data[] = ['planned' => $planned_arr[$i], 'achieved' => $achieved_arr[$i]];
            }
        }
        
        $planned_json = $conn->real_escape_string(json_encode($table_data));
        $guide_tag = $guide_name . " (Guide)";
        
        $sql = "INSERT INTO project_logs (project_id, created_by_role, created_by_id, created_by_name, log_title, log_date, log_date_to, progress_planned, progress_achieved, guide_review) 
                VALUES ($p_id, 'guide', $guide_id, '$guide_tag', '$title', '$date_from', '$date_to', '$planned_json', '$status', '$review')";
        
        if ($conn->query($sql)) {
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-check-circle'></i> New log created successfully!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
            $active_tab = 'logs';
            $reopen_log_project_id = $p_id;
        } else {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: " . $conn->error . "</div>";
            $active_tab = 'logs';
            $reopen_log_project_id = $p_id;
        }
    }
}

if (isset($_POST['delete_log_guide'])) {
    $log_id = (int)$_POST['log_id'];
    // Verify ownership
    $verify = $conn->query("SELECT p.id as project_id FROM project_logs pl JOIN projects p ON pl.project_id = p.id WHERE pl.id = $log_id AND p.assigned_guide_id = $guide_id");
    if ($verify && $verify->num_rows > 0) {
        $p_id = $verify->fetch_assoc()['project_id'];
        $conn->query("DELETE FROM project_logs WHERE id = $log_id");
        $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-trash'></i> Log deleted!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('success', $msg, ['reload' => true]);
        $active_tab = 'logs';
        $reopen_log_project_id = $p_id;
    }
}

// ==============================================
//        DATA FETCHING (ACTIVE & HISTORY)
// ==============================================

$filter_year = isset($_GET['year']) ? $conn->real_escape_string($_GET['year']) : '';
$filter_semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$filter_div = isset($_GET['division']) ? $conn->real_escape_string($_GET['division']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// 1. DASHBOARD STATS (Filtered by Guide ID)
$proj_where = "p.assigned_guide_id = $guide_id AND p.is_archived = 0";
if ($filter_year) $proj_where .= " AND p.project_year = '$filter_year'";
if ($filter_semester) $proj_where .= " AND p.semester = $filter_semester";
if ($filter_div) $proj_where .= " AND p.division = '$filter_div'";

// ACTIVE STATS (Filtered)
$project_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE $proj_where")->fetch_assoc()['c'];
$locked_count = $conn->query("SELECT COUNT(*) as c FROM projects p WHERE p.is_locked=1 AND $proj_where")->fetch_assoc()['c'];
$unlocked_count = $project_count - $locked_count;

$md_sql = project_member_details_sql('p');
// ACTIVE PROJECTS FETCH
$active_projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE $proj_where ORDER BY p.id DESC");

// HISTORY FILTERS & FETCH
$sessions = $conn->query("SELECT DISTINCT academic_session FROM projects WHERE assigned_guide_id = $guide_id AND is_archived = 1 ORDER BY academic_session DESC");
$filter_hist_session = isset($_GET['h_session']) ? $conn->real_escape_string($_GET['h_session']) : '';
$filter_hist_year = isset($_GET['h_year']) ? $conn->real_escape_string($_GET['h_year']) : '';
$filter_hist_sem = isset($_GET['h_sem']) ? (int)$_GET['h_sem'] : 0;
$filter_hist_div = isset($_GET['h_division']) ? $conn->real_escape_string($_GET['h_division']) : '';

$hist_where = "p.assigned_guide_id = $guide_id AND p.is_archived = 1";
if ($filter_hist_session) $hist_where .= " AND p.academic_session = '$filter_hist_session'";
if ($filter_hist_year) $hist_where .= " AND p.project_year = '$filter_hist_year'";
if ($filter_hist_sem) $hist_where .= " AND p.semester = $filter_hist_sem";
if ($filter_hist_div) $hist_where .= " AND p.division = '$filter_hist_div'";

$history_projects = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE $hist_where ORDER BY p.academic_session DESC, p.id DESC");

// UNIFIED TEAMS DATA JSON (FOR MODALS)
$teams_data = [];
$all_projects_query = $conn->query("SELECT p.*, ($md_sql) as member_details, s.full_name as leader_name, s.moodle_id as leader_moodle FROM projects p LEFT JOIN project_members pm_ldr ON pm_ldr.project_id = p.id AND pm_ldr.is_leader = 1 LEFT JOIN student s ON pm_ldr.student_id = s.id WHERE p.assigned_guide_id = $guide_id");
while($t = $all_projects_query->fetch_assoc()) {
    $p_id = $t['id'];
    $t['requests'] = [];
    $p_year = $conn->real_escape_string($t['project_year']);
    $p_sem = (int)$t['semester'];
    $p_session = $conn->real_escape_string($t['academic_session'] ?? 'Current');
    $reqs = $conn->query("(SELECT * FROM upload_requests WHERE project_id = $p_id) UNION (SELECT * FROM upload_requests WHERE is_global = 1 AND academic_year = '$p_year' AND semester = $p_sem AND academic_session = '$p_session') ORDER BY is_global ASC, id ASC");
    while($r = $reqs->fetch_assoc()) {
        $r_id = $r['id'];
        $r['files'] = [];
        $files = $conn->query("SELECT * FROM student_uploads WHERE request_id = $r_id ORDER BY uploaded_at DESC");
        while($f = $files->fetch_assoc()) { $r['files'][] = $f; }
        $t['requests'][] = $r;
    }

    // Load project logs for the guide view
    $t['logs'] = [];
    $logRecords = $conn->query("SELECT * FROM project_logs WHERE project_id = $p_id ORDER BY updated_at DESC");
    if ($logRecords) {
        while ($lr = $logRecords->fetch_assoc()) {
            $lr['log_entries'] = json_decode($lr['log_entries'], true) ?: [];
            $lr['progress_planned'] = json_decode($lr['progress_planned'] ?? '[]', true) ?: (is_string($lr['progress_planned']) ? $lr['progress_planned'] : '');
            $t['logs'][] = $lr;
        }
    }

    $teams_data[$p_id] = $t;
}
$teams_json = json_encode($teams_data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guide Dashboard - Project Hub</title>
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
        .menu-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; }
        
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
        
        .theme-toggle-btn { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; display: flex; justify-content: center; align-items: center; width: 40px; height: 40px; transition: 0.3s; flex-shrink:0; }
        .theme-toggle-btn:hover { background: var(--border-color); }

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0; flex-shrink: 0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA; flex-shrink: 0;}

        /* --- DASHBOARD CARDS & GRIDS --- */
        .dashboard-canvas { flex: 1; overflow-y: auto; padding-right: 5px; }
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: var(--card-bg); padding: 25px; border-radius: 24px; box-shadow: var(--shadow); display: flex; flex-direction: column; position: relative;}
        .stat-title { font-size: 15px; font-weight: 600; color: var(--text-light); margin-bottom: 10px; }
        .stat-value { font-size: 36px; font-weight: 700; color: var(--text-dark); }
        .stat-icon { position: absolute; top: 25px; right: 25px; width: 45px; height: 45px; border-radius: 12px; display: flex; justify-content: center; align-items: center; font-size: 20px; background: var(--input-bg); color: var(--primary-green); }

        .search-bar-container { display:flex; margin-bottom: 20px; gap: 10px; flex-wrap: wrap;}
        .smart-search { flex:1; position:relative; min-width: 250px;}
        .smart-search input { width:100%; border:1px solid var(--border-color); border-radius:16px; padding:15px 15px 15px 45px; font-size:14px; outline:none; transition:0.3s; background:var(--card-bg); font-family:inherit; color:var(--text-dark);}
        .smart-search input:focus { border-color:var(--primary-green); box-shadow:0 0 0 4px rgba(16,93,63,0.1); }
        .smart-search i { position:absolute; left:18px; top:16px; color:var(--text-light); font-size:16px; }

        .filter-select { border: 1px solid var(--border-color); background: var(--card-bg); padding: 12px 15px; border-radius: 12px; font-size: 13px; outline: none; cursor: pointer; font-weight: 500; color:var(--text-dark); font-family:inherit;}

        .card { background: var(--card-bg); border-radius: 24px; padding: 25px 30px; box-shadow: var(--shadow); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; font-size: 13px; color: var(--text-light); text-transform: uppercase; font-weight: 600; border-bottom: 2px solid var(--border-color); }
        td { padding: 15px; font-size: 14px; color: var(--text-dark); border-bottom: 1px solid var(--border-color); vertical-align:middle; }
        
        .badge { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; display:inline-block;}
        .badge-warning { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
        .badge-success { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
        
        .btn-action { background: var(--btn-blue); color: white; border: none; padding: 8px 15px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:5px; width:100%; justify-content:center;}
        .btn-action:hover { background: var(--btn-blue-hover); }
        .btn-outline { background: var(--card-bg); color: var(--primary-green); border: 1px solid var(--primary-green); padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:5px; margin-top:5px; }
        .btn-outline:hover { background: var(--input-bg); }
        .btn-submit { background: var(--primary-green); color: white; border: none; padding: 15px; border-radius: 12px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; transition: 0.3s; margin-top: 10px;}
        .btn-submit:hover { background: #0A402A; }

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
            .logs-grid { grid-template-columns: 1fr !important; }
            
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
        <div style="font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600;">Workspace</div>
        
        <a class="nav-link" onclick="switchTab('dashboard')" id="tab-dashboard"><i class="fa-solid fa-chalkboard-user"></i> My Groups</a>
        <a class="nav-link" onclick="switchTab('history')" id="tab-history"><i class="fa-solid fa-clock-rotate-left"></i> Past Projects</a>
        <a class="nav-link" onclick="switchTab('logs')" id="tab-logs"><i class="fa-solid fa-book"></i> Project Logs</a>
        <a class="nav-link" onclick="switchTab('students')" id="tab-students"><i class="fa-solid fa-user-graduate"></i> Manage Students</a>
        
        <div style="font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top: 20px;">Account</div>
        <a class="nav-link" onclick="switchTab('settings')" id="tab-settings"><i class="fa-solid fa-key"></i> Change Password</a>

        <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <div class="top-navbar-inner">
                <div class="top-navbar-left">
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Guide Workspace</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Manage and view your projects</p>
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
                <div class="avatar"><?php echo strtoupper(substr($guide_name, 0, 1)); ?></div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($guide_name); ?></h4>
                    <p>Project Guide</p>
                </div>
            </div>
        </div>

        <div id="alertBox"><?php echo $msg; ?></div>

        <div id="section-dashboard" class="dashboard-canvas" style="display:none;">
            
            <form method="GET" class="search-bar-container" style="margin-bottom: 20px;">
                <input type="hidden" name="tab" value="dashboard">
                <select name="year" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <option value="SE" <?php if($filter_year=='SE') echo 'selected'; ?>>SE Projects</option>
                    <option value="TE" <?php if($filter_year=='TE') echo 'selected'; ?>>TE Projects</option>
                    <option value="BE" <?php if($filter_year=='BE') echo 'selected'; ?>>BE Projects</option>
                </select>
                <select name="semester" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Semesters</option>
                    <option value="3" <?php if($filter_semester==3) echo 'selected'; ?>>Sem 3</option>
                    <option value="4" <?php if($filter_semester==4) echo 'selected'; ?>>Sem 4</option>
                    <option value="5" <?php if($filter_semester==5) echo 'selected'; ?>>Sem 5</option>
                    <option value="6" <?php if($filter_semester==6) echo 'selected'; ?>>Sem 6</option>
                    <option value="7" <?php if($filter_semester==7) echo 'selected'; ?>>Sem 7</option>
                    <option value="8" <?php if($filter_semester==8) echo 'selected'; ?>>Sem 8</option>
                </select>
                <div class="smart-search">
                    <input type="text" id="searchInput" placeholder="Search groups by Name, Student ID, or Member Name..." onkeyup="liveSearch('projectsTable', 'searchInput')">
                    <i class="fa-solid fa-users-viewfinder"></i>
                </div>
            </form>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-title">Active Mentored Groups</div>
                    <div class="stat-value"><?php echo $project_count; ?></div>
                    <div class="stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Topics Finalized</div>
                    <div class="stat-value"><?php echo $locked_count; ?></div>
                    <div class="stat-icon" style="color:#059669; background:#D1FAE5;"><i class="fa-solid fa-check-double"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Pending Finalization</div>
                    <div class="stat-value"><?php echo $unlocked_count; ?></div>
                    <div class="stat-icon" style="color:#D97706; background:#FEF3C7;"><i class="fa-solid fa-clock"></i></div>
                </div>
            </div>

            <div class="card" style="padding: 0; overflow:hidden;">
                <table id="projectsTable">
                    <thead>
                        <tr style="background:var(--input-bg);"><th>Group Name</th><th>Details / Year</th><th>Status</th><th style="text-align:center;">Action</th></tr>
                    </thead>
                    <tbody style="padding: 15px;">
                        <?php if($active_projects->num_rows > 0): while($row = $active_projects->fetch_assoc()): ?>
                        <tr class="searchable-row">
                            <td style="padding:15px;">
                                <strong style="color:var(--primary-green); font-size:15px;"><?php echo htmlspecialchars($row['group_name']); ?></strong><br>
                                <span style="font-size:12px; color:var(--text-light); font-weight:600;">Ldr: <?php echo htmlspecialchars($row['leader_name']); ?> (<?php echo htmlspecialchars($row['leader_moodle']); ?>)</span>
                                <div class="hidden-members" style="display:none;"><?php echo htmlspecialchars($row['member_details']); ?></div>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['department'] ?? 'Dept Not Set'); ?></span><br>
                                <span style="font-size:12px; color: var(--text-light);"><?php echo htmlspecialchars($row['project_year'])." (Sem ".($row['semester']??'-').") - Div ".htmlspecialchars($row['division']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <?php if($row['is_locked']): ?>
                                    <span class="badge badge-success"><i class="fa-solid fa-check-circle"></i> Finalized: <?php echo htmlspecialchars($row['final_topic']); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="margin-bottom:8px;"><i class="fa-solid fa-clock"></i> Pending</span><br>
                                <?php endif; ?>
                            </td>
                            <td style="vertical-align:middle; width: 150px; padding:15px;">
                                <button class="btn-action" onclick='openMasterModal(<?php echo $row['id']; ?>)'><i class="fa-solid fa-folder-gear"></i> Manage Group</button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-users-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No active groups are assigned to you.</td></tr>
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
                    <?php $sessions->data_seek(0); while($s = $sessions->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($s['academic_session']); ?>" <?php if($filter_hist_session == $s['academic_session']) echo 'selected'; ?>><?php echo htmlspecialchars($s['academic_session']); ?></option>
                    <?php endwhile; ?>
                </select>
                <select name="h_year" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Years</option>
                    <option value="SE" <?php if($filter_hist_year=='SE') echo 'selected'; ?>>SE</option>
                    <option value="TE" <?php if($filter_hist_year=='TE') echo 'selected'; ?>>TE</option>
                    <option value="BE" <?php if($filter_hist_year=='BE') echo 'selected'; ?>>BE</option>
                </select>
                <select name="h_sem" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Semesters</option>
                    <option value="3" <?php if($filter_hist_sem==3) echo 'selected'; ?>>Sem 3</option>
                    <option value="4" <?php if($filter_hist_sem==4) echo 'selected'; ?>>Sem 4</option>
                    <option value="5" <?php if($filter_hist_sem==5) echo 'selected'; ?>>Sem 5</option>
                    <option value="6" <?php if($filter_hist_sem==6) echo 'selected'; ?>>Sem 6</option>
                    <option value="7" <?php if($filter_hist_sem==7) echo 'selected'; ?>>Sem 7</option>
                    <option value="8" <?php if($filter_hist_sem==8) echo 'selected'; ?>>Sem 8</option>
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

        <div id="section-logs" class="dashboard-canvas" style="display:none;">
            <!-- GROUPS VIEW -->
            <div id="logs-groups-view">
                <div style="margin-bottom:20px;">
                    <h3 style="font-size: 22px; color: var(--text-dark); margin:0;">Assigned Project Groups</h3>
                    <p style="font-size: 13px; color: var(--text-light); margin:0;">Select a group to view or add weekly logs and reviews.</p>
                </div>

                <div class="workspace-grid logs-grid">
                    <?php if($active_projects->num_rows > 0): ?>
                        <?php $active_projects->data_seek(0); while($group = $active_projects->fetch_assoc()): ?>
                            <div class="folder-block" style="border-left: 5px solid #3B82F6;">
                                <h4 style="font-size:18px; color:var(--text-dark); margin:0 0 10px 0;"><i class="fa-solid fa-users-viewfinder" style="color:#3B82F6; margin-right:5px;"></i> <?php echo htmlspecialchars($group['group_name']); ?></h4>
                                <div style="font-size:13px; color:var(--text-light); margin-bottom:15px; line-height: 1.6;">
                                    <strong>Year:</strong> <?php echo htmlspecialchars($group['project_year']); ?> (Sem <?php echo $group['semester']; ?>)<br>
                                    <strong>Division:</strong> <?php echo htmlspecialchars($group['division']); ?><br>
                                    <strong>Leader:</strong> <?php echo htmlspecialchars($group['leader_name'] ?: 'Unknown'); ?>
                                </div>
                                <button type="button" onclick="openGroupLogs(<?php echo $group['id']; ?>)" class="btn-submit" style="text-align:center; padding:10px;"><i class="fa-solid fa-book-open"></i> Manage Logs</button>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="grid-column: 1 / -1; padding: 40px; text-align: center; color: var(--text-light); background:var(--card-bg); border-radius:16px;">
                            <i class="fa-solid fa-folder-open" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i>
                            <div>No groups assigned to you yet.</div>
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
                    <div style="display:flex; gap:10px;">
                        <button class="btn-action" onclick="openCreateLogModalNew()" style="background:var(--primary-green); padding:10px 20px; font-size:14px;">
                            <i class="fa-solid fa-plus"></i> Create Log for Group
                        </button>
                    </div>
                </div>

                <div style="background:var(--card-bg); padding:20px; border-radius:16px;">
                    <div class="workspace-grid logs-grid" id="logs-detail-container" style="align-items: start; gap: 15px;">
                        <!-- JS injected logs go here -->
                    </div>
                </div>
            </div>
        </div>

        <div id="section-students" class="dashboard-canvas" style="display:none;">
            <div style="margin-bottom: 20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
                <div>
                    <h2 style="font-size: 22px; color: var(--text-dark); margin:0;">Manage Group Students</h2>
                    <p style="font-size: 13px; color: var(--text-light); margin:0;">View and update information for students in your assigned groups.</p>
                </div>
                <div class="smart-search" style="max-width:300px;">
                    <input type="text" id="searchGuideStudents" placeholder="Search student by name or ID..." onkeyup="liveSearch('guideStudentsTable', 'searchGuideStudents')">
                    <i class="fa-solid fa-search"></i>
                </div>
            </div>

            <div class="card" style="padding: 0; overflow:hidden;">
                <table id="guideStudentsTable">
                    <thead>
                        <tr style="background:var(--input-bg);">
                            <th style="padding:15px;">Student Details</th>
                            <th style="padding:15px;">Group</th>
                            <th style="padding:15px;">Academic Info</th>
                            <th style="text-align:center; padding:15px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($guide_students->num_rows > 0): while($s = $guide_students->fetch_assoc()): ?>
                        <tr class="searchable-row">
                            <td style="padding:15px;">
                                <strong style="color:var(--primary-green); font-size:15px;"><?php echo htmlspecialchars($s['full_name']); ?></strong><br>
                                <span style="font-size:12px; color:var(--text-light); font-weight:600;">Moodle ID: <?php echo htmlspecialchars($s['moodle_id']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <span style="background:var(--input-bg); color:var(--btn-blue); border:1px solid var(--border-color); padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                                    <i class="fa-solid fa-users"></i> <?php echo htmlspecialchars($s['group_name']); ?>
                                </span>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($s['academic_year']); ?> - Sem <?php echo $s['current_semester']; ?></span><br>
                                <span style="font-size:12px; color: var(--text-light);">Div <?php echo htmlspecialchars($s['division']); ?> | <i class="fa-solid fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($s['phone_number'] ?: 'N/A'); ?></span>
                            </td>
                            <td style="text-align:center; padding:15px; vertical-align:middle;">
                                <button class="btn-action" style="width:auto; padding:6px 12px; font-size:13px;" onclick="openEditStudentModal(<?php echo $s['id']; ?>, '<?php echo htmlspecialchars($s['moodle_id']); ?>', '<?php echo htmlspecialchars($s['full_name']); ?>', '<?php echo htmlspecialchars($s['academic_year']); ?>', '<?php echo $s['current_semester']; ?>', '<?php echo htmlspecialchars($s['division']); ?>', '<?php echo htmlspecialchars($s['phone_number']); ?>')">
                                    <i class="fa-solid fa-pen"></i> Edit Info
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-user-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No students found in your groups.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="editStudentModal" class="modal-overlay" style="z-index:3000;">
            <div class="modal-card modal-card-small">
                <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                    Edit Student Info
                    <button type="button" onclick="closeSimpleModal('editStudentModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <form method="POST" class="ajax-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="student_id" id="edit_s_id">
                    
                    <div class="form-group"><label>Moodle ID (Read-only)</label><input type="text" id="edit_s_moodle" readonly style="opacity:0.7;"></div>
                    <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_s_name" required></div>
                    
                    <div style="display:flex; gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Year</label>
                            <select name="academic_year" id="edit_s_year" onchange="updateSemesterOptions(this.value, 'edit_s_sem_select')" required>
                                <option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Semester</label>
                            <select name="current_semester" id="edit_s_sem_select" required></select>
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Division</label>
                            <select name="division" id="edit_s_div" required>
                                <option value="A">Div A</option><option value="B">Div B</option><option value="C">Div C</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number" id="edit_s_phone"></div>
                    
                    <div style="background:var(--input-bg); padding:15px; border-radius:12px; border:1px dashed var(--border-color); margin-top:10px; margin-bottom:15px;">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--primary-green); margin-bottom:10px;"><i class="fa-solid fa-key"></i> Reset Password</label>
                        <div class="form-group" style="margin-bottom:0;">
                            <input type="password" name="new_password" placeholder="Enter new password to change">
                            <p style="font-size:10px; color:var(--text-light); margin-top:5px;">Leave blank to keep existing password.</p>
                        </div>
                    </div>

                    <button type="submit" name="edit_student" class="btn-submit" style="margin-top:0;"><i class="fa-solid fa-floppy-disk"></i> Update Student</button>
                </form>
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


    <div id="masterModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: var(--primary-green);" id="modal_group_title"><i class="fa-solid fa-users"></i> Group Manager</span>
                <button type="button" onclick="closeMasterModal()" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            
            <div class="modal-tabs">
                <div class="modal-tab active" id="tab-overview" onclick="switchModalTab('overview')">Project Overview</div>
                <div class="modal-tab" id="tab-form" onclick="switchModalTab('form')">View Form Details</div>
                <div class="modal-tab" id="tab-upload" onclick="switchModalTab('upload')">Workspace & Uploads</div>
                <div class="modal-tab" id="tab-logs" onclick="switchModalTab('logs')">Manage Logs</div>
                <div class="modal-tab" id="tab-classification" onclick="switchModalTab('classification')">Classification</div>
            </div>

            <div class="modal-body">
                
                <div id="modal-sec-overview" class="tab-content active">
                    <div id="modal_finalized_topic_section" style="display:none; background:var(--card-bg); padding:20px; border-radius:12px; border:2px solid var(--primary-green); margin-bottom:20px;">
                        <h4 style="font-size:11px; color:var(--primary-green); text-transform:uppercase; margin-bottom:8px; font-weight:800;"><i class="fa-solid fa-check-circle"></i> Finalized Topic</h4>
                        <div id="modal_finalized_topic_text" style="font-size:16px; font-weight:700; color:var(--text-dark);"></div>
                    </div>

                    <div style="background:var(--input-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:20px;">
                        <h4 style="font-size:13px; color:var(--text-light); text-transform:uppercase; margin-bottom:10px;">Team Members</h4>
                        <div id="modal_member_list" style="font-size:14px; line-height:1.6; color:var(--text-dark); white-space:pre-line;"></div>
                    </div>
                    
                    <div style="background:var(--input-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:20px;">
                        <h4 style="font-size:13px; color:var(--text-light); text-transform:uppercase; margin-bottom:10px;">Submitted Topic Preferences</h4>
                        <div id="modal_topics_list" style="font-size:14px; line-height:1.8; color:var(--text-dark);"></div>
                    </div>
                </div>

                <div id="modal-sec-form" class="tab-content">
                    <div style="background:#DBEAFE; color:#1E40AF; padding:12px 15px; border-radius:10px; font-size:13px; margin-bottom:15px; display:flex; align-items:center; gap:10px; border:1px solid #BFDBFE;">
                        <i class="fa-solid fa-circle-info"></i> <b>View Only:</b> You can see the details submitted by the group but cannot modify them.
                    </div>
                    <form method="POST" class="ajax-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="edit_project_id" id="e_pid">
                        <input type="hidden" name="edit_project_year" id="e_pyear">
                        
                        <div id="dynamic_form_container"></div>
                    </form>
                </div>

                <div id="modal-sec-upload" class="tab-content">
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

    <div id="archiveModal" class="modal-overlay" style="z-index: 4000;">
        <div class="modal-card">
            <div class="modal-header">
                <span style="font-size: 18px; font-weight: 700; color: #4F46E5;"><i class="fa-solid fa-box-archive"></i> Archived Project Vault</span>
                <button type="button" onclick="closeSimpleModal('archiveModal')" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
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

    <!-- CREATE/EDIT LOG MODAL -->
    <!-- Review Modal -->
    <div id="reviewModal" class="modal-overlay" style="z-index: 3500;">
        <div class="modal-card-small" style="max-width: 750px; width: 95%;">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green);"><i class="fa-solid fa-comment-dots"></i> Add/Edit Guide Review</h3>
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
                <button type="submit" name="update_guide_review" class="btn-submit" style="background:#3B82F6; width:100%;"><i class="fa-solid fa-floppy-disk"></i> Save Review</button>
            </form>
        </div>
    </div>

    <!-- Create Log Modal (For Guide) -->
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
                                <thead style="background:var(--input-bg); position:sticky; top:0; z-index:1;">
                                    <tr>
                                        <th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:50%;">Progress Planned</th>
                                        <th style="padding:12px; text-align:left; border-bottom:1px solid var(--border-color); color:var(--text-light); width:45%;">Progress Achieved</th>
                                        <th style="padding:12px; border-bottom:1px solid var(--border-color); width:5%;"></th>
                                    </tr>
                                </thead>
                                <tbody><!-- Rows will be injected here --></tbody>
                            </table>
                        </div>
                    </div>
                    
                    <div class="g-card" style="padding:15px; margin-bottom:20px; border-left: 5px solid #3B82F6;">
                        <label class="g-label" style="font-size:13px; color:#3B82F6;">Add Your Review (Optional)</label>
                        <textarea name="guide_review" class="g-input" placeholder="Provide initial review or feedback..." style="min-height: 150px !important; width: 100% !important; resize: vertical; overflow-y: auto; padding: 15px; line-height: 1.6; font-size: 14px;"></textarea>
                    </div>

                    <button type="submit" name="create_log_guide" class="btn-submit" style="background:#8B5CF6; margin-top:0; width:100%;"><i class="fa-solid fa-floppy-disk"></i> Create Log Entry</button>
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


    <script>
        const schemasByYear = <?php echo $schemas_json; ?>;
        const teamsData = <?php echo $teams_json; ?>;
        const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
        
        let currentEditYear = '';
        let currentEditDiv = '';
        let currentEditPid = '';
        let guideMemberCount = 0;
        let activeLogsProjectId = 0;

        setTimeout(() => { let a = document.getElementById('alertMsg'); if(a) { a.style.opacity="0"; setTimeout(()=>a.style.display="none", 500); } }, 5000);

        function openSimpleModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeSimpleModal(id) { document.getElementById(id).style.display = 'none'; }

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

        function switchTab(tab) {
            sessionStorage.setItem('guide_active_tab', tab);
            document.getElementById('section-dashboard').style.display = 'none';
            document.getElementById('section-history').style.display = 'none';
            document.getElementById('section-settings').style.display = 'none';
            document.getElementById('section-logs').style.display = 'none';
            document.getElementById('section-students').style.display = 'none';
            
            document.getElementById('tab-dashboard').classList.remove('active');
            document.getElementById('tab-history').classList.remove('active');
            document.getElementById('tab-settings').classList.remove('active');
            document.getElementById('tab-logs').classList.remove('active');
            document.getElementById('tab-students').classList.remove('active');
            
            document.getElementById('section-' + tab).style.display = 'block';
            document.getElementById('tab-' + tab).classList.add('active');
            
            if(window.innerWidth <= 768) toggleMobileMenu();
        }

        function liveSearch(tableId, inputId) {
            let input = document.getElementById(inputId).value.toLowerCase().trim();
            document.querySelectorAll('#' + tableId + ' .searchable-row').forEach(row => {
                let textData = row.textContent.toLowerCase();
                row.style.display = textData.includes(input) ? "" : "none";
            });
        }

        function openEditStudentModal(id, moodle, name, year, sem, div, phone) {
            document.getElementById('edit_s_id').value = id;
            document.getElementById('edit_s_moodle').value = moodle;
            document.getElementById('edit_s_name').value = name;
            document.getElementById('edit_s_year').value = year;
            document.getElementById('edit_s_div').value = div;
            document.getElementById('edit_s_phone').value = phone;
            
            updateSemesterOptions(year, 'edit_s_sem_select', sem);
            openSimpleModal('editStudentModal');
        }

        function updateSemesterOptions(year, selectId, selectedSem = null) {
            const select = document.getElementById(selectId);
            select.innerHTML = '';
            let options = [];
            if (year === 'SE') options = [{v:3, t:'Sem 3'}, {v:4, t:'Sem 4'}];
            else if (year === 'TE') options = [{v:5, t:'Sem 5'}, {v:6, t:'Sem 6'}];
            else if (year === 'BE') options = [{v:7, t:'Sem 7'}, {v:8, t:'Sem 8'}];
            
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.v;
                o.textContent = opt.t;
                if (selectedSem && opt.v == selectedSem) o.selected = true;
                select.appendChild(o);
            });
        }

        function switchModalTab(tab) {
            sessionStorage.setItem('guide_master_tab', tab);
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
            sessionStorage.removeItem('guide_open_modal_pid');
            document.getElementById('masterModal').style.display = 'none';
            switchModalTab('overview'); 
        }

        function openMasterModal(projectId) {
            const data = teamsData[projectId];
            if(!data) return;
            sessionStorage.setItem('guide_open_modal_pid', projectId);

            document.getElementById('modal_group_title').innerText = data.group_name;

            // --- 1. POPULATE OVERVIEW TAB ---
            let escHTML = (str) => { let p = document.createElement("p"); p.appendChild(document.createTextNode(str)); return p.innerHTML; };
            document.getElementById('modal_member_list').innerHTML = escHTML(data.member_details || '').replace(/\[Disabled\]/g, '<span style="font-size:10px; color:#EF4444; background:#FEE2E2; padding:2px 6px; border-radius:4px; margin-left:5px; vertical-align:middle;"><i class="fa-solid fa-user-slash"></i> Disabled</span>').replace(/\n/g, '<br>');

            // Finalized Topic Display
            let finalSec = document.getElementById('modal_finalized_topic_section');
            if (data.final_topic) {
                document.getElementById('modal_finalized_topic_text').innerText = data.final_topic;
                finalSec.style.display = 'block';
            } else {
                finalSec.style.display = 'none';
            }

            // Display Submitted Topics prominently
            let topicsHtml = '';
            if(data.topic_1) topicsHtml += `<div style="padding:10px; border-bottom:1px solid var(--border-color);"><b style="color:var(--primary-green);">1.</b> ${data.topic_1}</div>`;
            if(data.topic_2) topicsHtml += `<div style="padding:10px; border-bottom:1px solid var(--border-color);"><b style="color:var(--primary-green);">2.</b> ${data.topic_2}</div>`;
            if(data.topic_3) topicsHtml += `<div style="padding:10px;"><b style="color:var(--primary-green);">3.</b> ${data.topic_3}</div>`;
            if(!topicsHtml) topicsHtml = `<div style="padding:10px; font-style:italic; color:var(--text-light);">No topics submitted.</div>`;
            document.getElementById('modal_topics_list').innerHTML = topicsHtml;


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

            // --- 4. POPULATE UPLOAD TAB ---
            document.getElementById('e_pid').value = data.id;
            document.getElementById('e_pyear').value = data.project_year;
            
            currentEditYear = data.project_year;
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
                            <div id="guide_membersContainer" style="background:var(--input-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); margin-bottom:10px;"></div>
                        </div>
                    `;
                    setTimeout(() => {
                        let mc = document.getElementById('guide_membersContainer');
                        mc.innerHTML = '';
                        guideMemberCount = 0;
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
                                        addGuideMemberRow(moodle, name, isLeader);
                                    }
                                }
                            });
                            if (!foundLeader && mc.children.length > 0) { mc.querySelector('input[type="radio"]').checked = true; }
                        } else { addGuideMemberRow(); }
                    }, 0);
                } else if (field.type === 'select') {
                    let opts = (field.options||"").split(',');
                    let optHtml = `<option value="">Choose...</option>`;
                    opts.forEach(opt => { let o = opt.trim(); if(o) optHtml += `<option value="${o}" ${o==val ? 'selected' : ''}>${o}</option>`; });
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><select name="${safeName}" class="g-input" disabled>${optHtml}</select></div>`;
                } else if (field.type === 'textarea') {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><textarea name="${safeName}" class="g-input" rows="3" disabled>${val}</textarea></div>`;
                } else if (field.type === 'radio' || field.type === 'checkbox') {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><input type="text" name="${safeName}" class="g-input" value="${val}" disabled></div>`;
                } else {
                    dynamicContainer.innerHTML += `<div class="form-group"><label>${field.label}</label><input type="${field.type=='date'?'date':'text'}" name="${safeName}" class="g-input" value="${val}" disabled></div>`;
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
                                <div style="display:flex; gap:8px;"></div>
                            </div>
                            
                            ${noteHtml}
                            
                            <div style="margin-bottom: 15px;">
                    `;
                    
                    if(req.files && req.files.length > 0) {
                        req.files.forEach(f => {
                            html += `
                                <div class="file-row">
                                    <div style="flex:1;">
                                        <a href="${f.file_path}" target="_blank" style="font-size:13px; font-weight:600; color:var(--btn-blue); text-decoration:none;"><i class="fa-regular fa-file-pdf" style="margin-right:5px;"></i> ${f.file_name}</a>
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
                            
                            <form method="POST" class="ajax-form" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:8px; background:var(--card-bg); padding:15px; border-radius:10px; border:1px dashed var(--border-color);">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="request_id" value="${req.id}">
                                <input type="hidden" name="proj_id" value="${data.id}">
                                
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div style="font-size:11px; color:var(--text-light);">Max file size: 5MB</div>
                                </div>
                                
                                <div style="display:flex; gap:10px; align-items:center;">
                                    <input type="file" name="document" required style="font-size:12px; flex:1; color:var(--text-dark);">
                                    <button type="submit" name="upload_file_guide" style="background:var(--primary-green); color:white; border:none; padding:8px 15px; border-radius:6px; font-size:12px; cursor:pointer; font-weight:600;"><i class="fa-solid fa-cloud-arrow-up"></i> Upload</button>
                                </div>
                            </form>
                        </div>
                    `;
                });
            } else {
                html = `<div style="text-align:center; padding:20px; color:var(--text-light); font-size:14px; background:var(--input-bg); border-radius:12px; border:1px dashed var(--border-color);">No upload folders created for this group yet. Create one above!</div>`;
            }
            
            uploadContainer.innerHTML = html;

            // --- 5. POPULATE LOGS TAB ---
            activeLogsProjectId = projectId; 
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
                                        <button type="submit" name="delete_log_guide" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
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

        // ==========================================
        //        LOG MANAGEMENT FUNCTIONS
        // ==========================================
        function openGroupLogs(projectId) {
            const data = teamsData[projectId];
            if (!data) return;
            
            sessionStorage.setItem('guide_open_log_pid', projectId);
            activeLogsProjectId = projectId;
            document.getElementById('logs-detail-title').innerText = "Logs: " + data.group_name;
            document.getElementById('logs-detail-leader').innerText = "Leader: " + (data.leader_name || 'Unknown');
            
            let logsHtml = '';
            if (data.logs && data.logs.length > 0) {
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
                                        <button type="submit" name="delete_log_guide" style="background:none; border:none; color:#EF4444; cursor:pointer; font-size:16px;">
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
                                        <label style="font-size:11px; text-transform:uppercase; font-weight:700; color:#3B82F6; display:block; margin-bottom:8px;">Your Review</label>
                                        <div style="background:var(--card-bg); padding:15px; border-radius:12px; border:1px solid var(--border-color); font-size:13px; color:var(--text-dark); line-height:1.5;">${reviewHtml}</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                logsHtml = `
                    <div style="grid-column:1/-1; text-align:center; padding:60px 20px; color:var(--text-light);">
                        <i class="fa-solid fa-book" style="font-size:50px; color:var(--border-color); margin-bottom:20px;"></i>
                        <h4 style="color:var(--text-dark); margin-bottom:10px;">Log Book is Empty</h4>
                        <p style="font-size:14px; max-width:400px; margin:0 auto 20px auto;">No logs have been created for this group yet.</p>
                    </div>
                `;
            }
            
            document.getElementById('logs-detail-container').innerHTML = logsHtml;
            document.getElementById('logs-groups-view').style.display = 'none';
            document.getElementById('logs-detail-view').style.display = 'block';
        }
        
        function closeGroupLogs() {
            sessionStorage.removeItem('guide_open_log_pid');
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
                    ${data.is_locked ? '<span style="background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; font-size:14px; padding:6px 12px; border-radius:8px; font-weight:600;"><i class="fa-solid fa-check-circle"></i> Topic Finalized</span>' : '<span style="background:#FEF3C7; color:#D97706; border:1px solid #FDE68A; font-size:14px; padding:6px 12px; border-radius:8px; font-weight:600;">Not Finalized</span>'}
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

                    <button type="button" class="btn-action" onclick="document.getElementById('full_form_archive_' + ${data.id}).style.display='block'; this.style.display='none';" style="width: 100%; justify-content: center; padding: 10px; font-size: 14px; background:var(--bg-color); color:var(--primary-green); border:1px solid var(--primary-green);"><i class="fa-solid fa-file-lines"></i> View Full Form Details</button>
                    
                    <div id="full_form_archive_${data.id}" style="display:none; margin-top:20px; border-top:1px dashed var(--border-color); padding-top:20px;">
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
                    let noteHtml = '';
                    if (req.instructions && req.instructions.trim() !== '') {
                        noteHtml = `<div style="font-size: 12px; color: #1D4ED8; margin-bottom: 15px; background: #EFF6FF; border-left: 3px solid #3B82F6; padding: 8px 12px; border-radius: 8px; line-height: 1.5;"><strong><i class="fa-solid fa-circle-info"></i> Note:</strong> ${req.instructions}</div>`;
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

        // ADD MEMBER LOGIC (EDIT FORM)
        function addGuideMemberRow(moodle = '', name = '', isLeader = false) {
            const container = document.getElementById('guide_membersContainer');
            const wrapper = document.createElement('div');
            let idx = guideMemberCount++;
            
            wrapper.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:5px;">
                    <label style="display:flex; flex-direction:column; align-items:center;" title="Team Leader Status">
                        <span style="font-size:10px; color:var(--text-light); text-transform:uppercase; font-weight:bold;">Leader</span>
                        <input type="radio" name="project_leader_index" value="${idx}" ${isLeader ? 'checked' : ''} disabled>
                    </label>
                    <input type="text" name="team_moodle[]" value="${moodle}" placeholder="Moodle ID" disabled style="flex:1; padding:10px; border:1px solid var(--border-color); border-radius:8px; outline:none; background:var(--bg-color); color:var(--text-dark); opacity:0.8;">
                    <input type="text" name="team_name[]" value="${name}" class="fetched-name" placeholder="Auto-Fetched Name" disabled style="flex:2; padding:10px; border:1px solid var(--border-color); border-radius:8px; outline:none; background:var(--bg-color); color:var(--text-dark); opacity:0.8;">
                </div>
            `;
            container.appendChild(wrapper);
        }

        function guideFetchStudent(inputElement) {
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

        // RESTORE STATE ON PAGE LOAD
        window.onload = () => { 
            let phpTab = '<?php echo ($active_tab !== "dashboard" && !empty($_POST)) ? $active_tab : ""; ?>';
            let savedTab = sessionStorage.getItem('guide_active_tab') || '<?php echo $active_tab; ?>';
            let finalTab = phpTab || savedTab;
            
            switchTab(finalTab);

            <?php if (!empty($reopen_log_project_id)): ?>
                // Reopen the specific log view after a PHP log action
                setTimeout(() => { openGroupLogs(<?php echo $reopen_log_project_id; ?>); }, 50);
            <?php else: ?>
                // Restore previous state safely
                if (finalTab === 'dashboard') {
                    let openModalPid = sessionStorage.getItem('guide_open_modal_pid');
                    if (openModalPid) {
                        openMasterModal(openModalPid);
                        let mTab = sessionStorage.getItem('guide_master_tab');
                        if (mTab) switchModalTab(mTab);
                    }
                } else if (finalTab === 'logs') {
                    let openLogPid = sessionStorage.getItem('guide_open_log_pid');
                    if (openLogPid) setTimeout(() => { openGroupLogs(openLogPid); }, 50);
                }
            <?php endif; ?>
        };
    </script>
    <script src="assets/js/ajax-forms.js?v=1.2"></script>
</body>
</html>