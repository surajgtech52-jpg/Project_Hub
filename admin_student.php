<?php
session_start();
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$admin_name = $_SESSION['name'];
$msg = "";

verify_csrf_token();

// --- 1. HANDLE ADD STUDENT ---
if (isset($_POST['add_student'])) {
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $pass = $conn->real_escape_string($_POST['password']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $year = $conn->real_escape_string($_POST['academic_year']);
    $div = $conn->real_escape_string($_POST['division']);
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $sem = (int)$_POST['current_semester'];

    if (moodle_id_in_use($conn, $m_id)) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID '$m_id' is already registered in the system!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', "Moodle ID '$m_id' is already registered!");
    } else {
        $sql = "INSERT INTO student (moodle_id, password, full_name, academic_year, current_semester, division, phone_number, status) 
                VALUES ('$m_id', '$pass', '$name', '$year', $sem, '$div', '$phone', 'Active')";
        if ($conn->query($sql) === TRUE) {
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Student added successfully!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Student added successfully!', ['callback' => 'refreshDashboard', 'closeModal' => 'addStudentModal']);
        }
    }
}

// --- 2. HANDLE EDIT STUDENT ---
if (isset($_POST['edit_student'])) {
    $id = $_POST['student_id'];
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $year = $conn->real_escape_string($_POST['academic_year']);
    $div = $conn->real_escape_string($_POST['division']);
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $sem = (int)$_POST['current_semester'];
    $status = $conn->real_escape_string($_POST['status']);

    $id_int = (int)$id;
    $current = $conn->query("SELECT moodle_id, academic_year, status FROM student WHERE id = $id_int")->fetch_assoc();
    $current_mid = $current ? $current['moodle_id'] : null;
    $current_year = $current ? $current['academic_year'] : null;
    $current_status = $current ? $current['status'] : 'Active';
    
    if ($current_mid !== null && $m_id !== $current_mid) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID cannot be changed once created (it breaks group membership). Create a new record instead.</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', 'Moodle ID cannot be changed once created.');
    } else {
        $proceed_with_update = true;
        
        // Handle Dropper / Year Demotion / Disable Logic
        if (($current_year !== null && $current_year !== $year) || ($current_status !== 'Disabled' && $status === 'Disabled')) {
            $check_leader = $conn->query("SELECT id, group_name FROM projects WHERE leader_id = $id_int AND is_archived = 0");
            if ($check_leader && $check_leader->num_rows > 0) {
                $proj = $check_leader->fetch_assoc();
                $p_id = $proj['id'];
                $group_n = $proj['group_name'];
                
                if (isset($_POST['new_leader_id']) && !empty($_POST['new_leader_id'])) {
                    $new_leader_id = (int)$_POST['new_leader_id'];
                    $conn->query("UPDATE projects SET leader_id = $new_leader_id WHERE id = $p_id");
                    $conn->query("UPDATE project_members SET is_leader = 0 WHERE project_id = $p_id");
                    $conn->query("UPDATE project_members SET is_leader = 1 WHERE project_id = $p_id AND student_id = $new_leader_id");
                    $conn->query("DELETE FROM project_members WHERE student_id = $id_int AND project_id = $p_id");
                } else {
                    $members_q = $conn->query("SELECT s.id, s.full_name, s.moodle_id FROM project_members pm JOIN student s ON pm.student_id = s.id WHERE pm.project_id = $p_id AND s.id != $id_int");
                    if ($members_q->num_rows > 0) {
                        $member_options = "";
                        while($m = $members_q->fetch_assoc()) {
                            $member_options .= "<option value='".$m['id']."'>".$m['full_name']." (".$m['moodle_id'].")</option>";
                        }
                        $msg = "<div class='alert-error' style='padding:20px; text-align:left;'>
                        <i class='fa-solid fa-triangle-exclamation'></i> <strong>Leader Reassignment Required!</strong><br>
                        Student <b>" . htmlspecialchars($name) . "</b> (ID: " . htmlspecialchars($m_id) . ") is the Leader of active group <b>$group_n</b>.<br><br>
                        Please select a new leader from the remaining group members to proceed:
                        <form method='POST' class='ajax-form' style='margin-top:15px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;'>
                            <input type='hidden' name='csrf_token' value='".$_SESSION['csrf_token']."'>
                            <input type='hidden' name='edit_student' value='1'>
                            <input type='hidden' name='student_id' value='$id_int'>
                            <input type='hidden' name='moodle_id' value='".htmlspecialchars($m_id, ENT_QUOTES)."'>
                            <input type='hidden' name='full_name' value='".htmlspecialchars($name, ENT_QUOTES)."'>
                            <input type='hidden' name='academic_year' value='".htmlspecialchars($year, ENT_QUOTES)."'>
                            <input type='hidden' name='division' value='".htmlspecialchars($div, ENT_QUOTES)."'>
                            <input type='hidden' name='phone_number' value='".htmlspecialchars($phone, ENT_QUOTES)."'>
                            <input type='hidden' name='status' value='".htmlspecialchars($status, ENT_QUOTES)."'>
                            <select name='new_leader_id' style='flex:1; min-width:200px; padding:8px; border-radius:6px; border:1px solid #FECACA; outline:none; color:black;' required>
                                <option value=''>-- Select New Leader --</option>
                                $member_options
                            </select>
                            <button type='submit' style='background:#991B1B; color:white; border:none; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold;'>Reassign & Update</button>
                            <button type='button' onclick='window.location.href=window.location.pathname;' style='background:#F3F4F6; color:#374151; border:1px solid #D1D5DB; padding:8px 15px; border-radius:6px; cursor:pointer; font-weight:bold; transition:0.2s;'>Cancel</button>
                        </form></div>";
                        $proceed_with_update = false;
                    } else {
                        // Safe cleanup of orphan data and physical files to prevent database crashes
                        $files = $conn->query("SELECT file_path FROM student_uploads WHERE project_id = $p_id");
                        if ($files) { while($f = $files->fetch_assoc()) { if(file_exists($f['file_path'])) unlink($f['file_path']); } }
                        $conn->query("DELETE FROM project_logs WHERE project_id = $p_id");
                        $conn->query("DELETE FROM student_uploads WHERE project_id = $p_id");
                        $conn->query("DELETE FROM upload_requests WHERE project_id = $p_id");
                        $conn->query("DELETE FROM projects WHERE id = $p_id");
                        $conn->query("DELETE FROM project_members WHERE project_id = $p_id");
                    }
                }
            } else {
                $conn->query("DELETE pm FROM project_members pm JOIN projects p ON pm.project_id = p.id WHERE pm.student_id = $id_int AND p.is_archived = 0");
            }
        }

        if ($proceed_with_update) {
            $new_pass = $_POST['new_password'] ?? '';
            $update_parts = ["full_name='$name'", "academic_year='$year'", "current_semester=$sem", "division='$div'", "phone_number='$phone'", "status='$status'"];
            if (!empty($new_pass)) {
                $h = $conn->real_escape_string($new_pass);
                $update_parts[] = "password='$h'";
            }
            $sql = "UPDATE student SET " . implode(", ", $update_parts) . " WHERE id=$id_int";
            if ($conn->query($sql) === TRUE) {
                $msg = "<div class='alert-success'><i class='fa-solid fa-pen'></i> Student info updated! Active group connections cleared if year or status changed.</div>";
                if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Student info updated!', ['callback' => 'refreshDashboard', 'closeModal' => 'editStudentModal']);
            }
        }
    }
}

$filter_sem = isset($_GET['sem']) ? (int)$_GET['sem'] : 0;
$filter_div = isset($_GET['division']) ? $conn->real_escape_string($_GET['division']) : '';

// Base condition: FETCH STUDENTS (Hiding 'Disabled'/Passed-out students)
$where = "s.deleted_at IS NULL AND (s.status = 'Active' OR s.status IS NULL)";

if ($filter_sem) {
    $where .= " AND s.current_semester = $filter_sem";
}

if ($filter_div) {
    $where .= " AND s.division = '$filter_div'";
}

$md_sql = project_member_details_sql('p');
$sql = "SELECT s.*, p.id as project_id, p.group_name, p.is_locked, p.final_topic
        FROM student s
        LEFT JOIN project_members pm ON pm.student_id = s.id
        LEFT JOIN projects p ON (p.id = pm.project_id AND p.is_archived = 0 AND p.project_year = s.academic_year)
        WHERE $where ORDER BY s.academic_year, s.division, s.full_name";
$students = $conn->query($sql);

$group_data = []; 
$groups = $conn->query("SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name FROM projects p LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE p.is_archived = 0");
if ($groups) { while($g = $groups->fetch_assoc()) { $group_data[$g['id']] = $g; } }
$group_json = json_encode($group_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { 
            --primary-green: #105D3F; --bg-color: #F3F4F6; --text-dark: #1F2937; --text-light: #6B7280; 
            --card-bg: #FFFFFF; --border-color: #E5E7EB; --input-bg: #F9FAFB; --sidebar-width: 260px; 
            --shadow: 0 5px 20px rgba(0,0,0,0.02); --btn-blue: #3B82F6; --btn-blue-hover: #2563EB;
        }
        
        [data-theme="dark"] {
            --primary-green: #34D399; --bg-color: #111827; --text-dark: #F9FAFB; --text-light: #9CA3AF; 
            --card-bg: #1F2937; --border-color: #374151; --input-bg: #374151; --shadow: 0 5px 20px rgba(0,0,0,0.3);
            --btn-blue: #4F46E5; --btn-blue-hover: #4338CA;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; transition: background-color 0.3s, color 0.3s, border-color 0.3s; }
        body { background-color: var(--bg-color); height: 100vh; display: flex; padding: 20px; overflow: hidden; color: var(--text-dark); }

        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto; transition: width 0.3s ease, opacity 0.3s ease, padding 0.3s ease, margin-right 0.3s ease; overflow-x: hidden; white-space: nowrap;}
        .sidebar.collapsed { width: 0; padding-left: 0; padding-right: 0; margin-right: 0; opacity: 0; border: 0; pointer-events: none; }
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        .menu-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top:20px;}
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: var(--text-light); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-weight: 500; transition: all 0.3s; white-space: normal; word-break: break-word; line-height: 1.4;}
        .sidebar.collapsed .nav-link { white-space: nowrap; }
        .nav-link.active { background-color: var(--primary-green); color: white; box-shadow: 0 8px 20px rgba(16, 93, 63, 0.2); }
        .nav-link:hover:not(.active) { background-color: var(--input-bg); color: var(--primary-green); }
        .logout-btn { margin-top: auto; color: #EF4444; }

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

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA;}

        .card { background: var(--card-bg); border-radius: 24px; padding: 25px 30px; box-shadow: var(--shadow); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; font-size: 13px; color: var(--text-light); text-transform: uppercase; font-weight: 600; border-bottom: 2px solid var(--border-color); }
        td { padding: 15px; font-size: 14px; color: var(--text-dark); border-bottom: 1px solid var(--border-color); vertical-align:middle; }
        
        .btn-add { background: var(--btn-blue); color: white; border: none; padding: 12px 20px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s;}
        .btn-add:hover { background: var(--btn-blue-hover); }
        .btn-edit { background: var(--input-bg); color: var(--text-dark); border: 1px solid var(--border-color); padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition:0.2s;}
        .btn-edit:hover { background: var(--border-color); }

        .search-bar-container { display:flex; gap:15px; margin-bottom: 20px; flex-wrap:wrap;}
        .smart-search { flex:1; position:relative; min-width:250px;}
        .smart-search input { width:100%; border:1px solid var(--border-color); border-radius:16px; padding:15px 15px 15px 45px; font-size:14px; outline:none; transition:0.3s; background:var(--card-bg); color:var(--text-dark);}
        .smart-search input:focus { border-color:var(--primary-green); box-shadow:0 0 0 4px rgba(16,93,63,0.1); }
        .smart-search i { position:absolute; left:18px; top:16px; color:var(--text-light); font-size:16px; }
        .filter-select { border: 1px solid var(--border-color); background: var(--card-bg); padding: 12px 15px; border-radius: 16px; font-size: 13px; outline: none; cursor: pointer; font-weight: 500; color:var(--text-dark); font-family:inherit;}

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--card-bg); padding: 30px; border-radius: 24px; width: 100%; max-width: 500px; display:flex; flex-direction:column; max-height:90vh; overflow-y:auto;}
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-light); margin-bottom: 5px; font-weight:600;}
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 13px; outline:none; background:var(--input-bg); color:var(--text-dark); font-family:inherit;}
        .form-group input:focus, .form-group select:focus { background: var(--card-bg); border-color: var(--primary-green); }

        .mobile-menu-btn { display: block; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; transition: 0.3s; flex-shrink: 0;}
        .close-sidebar-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-light); cursor: pointer; position: absolute; right: 20px; top: 25px; }
        .mobile-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; opacity: 0; transition: 0.3s; }
        .mobile-overlay.active { display: block; opacity: 1; }

        @media (max-width: 768px) {
            body { padding: 0; flex-direction: column; overflow-x: hidden; height: auto; overflow-y: auto;}
            .sidebar { position: fixed; top: 0; left: -300px; width: 280px; height: 100vh; margin: 0 !important; border-radius: 0 24px 24px 0; box-shadow: 5px 0 20px rgba(0,0,0,0.3); z-index: 9999; transition: left 0.3s ease-in-out; display: flex !important; flex-direction: column !important; opacity: 1 !important; }
            .sidebar.active { left: 0; }
            .close-sidebar-btn { display: block; }
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; display: block; overflow-y: visible; height: auto; }
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-left { flex:1; }
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            table { display: block; width: 100%; overflow-x: auto; white-space: nowrap; }
            .modal-card { width: 95%; max-height: 90vh; padding: 20px; border-radius: 16px; margin: 20px auto; }
        }
        .tab-btn { background:none; border:none; padding:10px 20px; font-size:14px; font-weight:600; color:var(--text-light); cursor:pointer; transition:0.3s; position:relative; }
        .tab-btn.active { color:var(--primary-green); }
        .tab-btn.active::after { content:''; position:absolute; bottom:-11px; left:0; width:100%; height:3px; background:var(--primary-green); border-radius:3px 3px 0 0; }
        .mgmt-section { display:none; animation: fadeIn 0.3s ease; }
        .mgmt-section.active { display:block; }
        @keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
        code { background:var(--input-bg); padding:2px 6px; border-radius:4px; font-family:monospace; color:var(--primary-green); }
    </style>
</head>
<body>

    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileMenu()"></div>

    <div class="sidebar" id="sidebar">
        <div class="brand">
            <i class="fa-solid fa-leaf"></i> Project Hub
            <button class="close-sidebar-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="menu-label" style="margin-top:0;">Global Management</div>
        <a href="admin_dashboard.php?tab=dashboard" class="nav-link"><i class="fa-solid fa-layer-group"></i> Active Projects</a>
        <a href="admin_dashboard.php?tab=history" class="nav-link"><i class="fa-solid fa-vault"></i> History Vault</a>
        <a href="admin_dashboard.php?tab=workspace" class="nav-link"><i class="fa-solid fa-briefcase"></i> Manage Workspace</a>
        <a href="admin_dashboard.php?tab=resets" class="nav-link"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>
        <a href="admin_dashboard.php?tab=logs" class="nav-link"><i class="fa-solid fa-book"></i> Project Logs</a>
        <a href="admin_dashboard.php?tab=exports" class="nav-link"><i class="fa-solid fa-file-excel"></i> Export Hub</a>

        <div class="menu-label">System Tools</div>
        <a href="admin_student.php" class="nav-link active"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="admin_guides.php" class="nav-link"><i class="fa-solid fa-chalkboard-user"></i> View Guides & Heads</a>
        <a href="admin_form_settings.php" class="nav-link"><i class="fa-brands fa-google"></i> Form Builder</a>
        <a href="admin_transfer.php" class="nav-link"><i class="fa-solid fa-arrow-right-arrow-left"></i> Sem Promotion</a>
        
        <div class="menu-label">Account</div>
        <a href="admin_dashboard.php?tab=settings" class="nav-link"><i class="fa-solid fa-key"></i> Change Password</a>
        <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <div class="top-navbar-inner">
                <div class="top-navbar-left">
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Manage Students</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Global Active Student Directory</p>
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
                <div class="avatar"><?php echo strtoupper(substr($admin_name, 0, 1)); ?></div>
                <div class="user-info">
                    <h4><?php echo htmlspecialchars($admin_name); ?></h4>
                    <p>System Administrator</p>
                </div>
            </div>
        </div>

        <div id="alertBox"><?php echo $msg; ?></div>

        <div class="dashboard-canvas" id="canvas-area">
            <!-- TAB NAVIGATION -->
            <div class="tab-nav" style="display:flex; gap:15px; margin-bottom:25px; border-bottom:1px solid var(--border-color); padding-bottom:10px;">
                <button class="tab-btn active" onclick="switchMgmtTab('active')" id="tab-active"><i class="fa-solid fa-users"></i> Active Students</button>
                <button class="tab-btn" onclick="switchMgmtTab('bulk')" id="tab-bulk"><i class="fa-solid fa-file-csv"></i> Bulk Management</button>
                <button class="tab-btn" onclick="switchMgmtTab('disabled')" id="tab-disabled"><i class="fa-solid fa-user-slash"></i> Removed / Disabled</button>
            </div>

            <!-- SECTION: ACTIVE STUDENTS -->
            <div id="section-active" class="mgmt-section active">
                <form method="GET" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                    <div style="display:flex; gap:10px; flex:1; min-width:300px;">
                        <select name="sem" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Active Semesters</option>
                            <option value="3" <?php if($filter_sem==3) echo 'selected'; ?>>SE - Sem 3</option>
                            <option value="4" <?php if($filter_sem==4) echo 'selected'; ?>>SE - Sem 4</option>
                            <option value="5" <?php if($filter_sem==5) echo 'selected'; ?>>TE - Sem 5</option>
                            <option value="6" <?php if($filter_sem==6) echo 'selected'; ?>>TE - Sem 6</option>
                            <option value="7" <?php if($filter_sem==7) echo 'selected'; ?>>BE - Sem 7</option>
                            <option value="8" <?php if($filter_sem==8) echo 'selected'; ?>>BE - Sem 8</option>
                        </select>
                       <select name="division" class="filter-select" onchange="this.form.submit()">
                        <option value="">All Divisions</option>
                        <option value="A" <?php if(isset($_GET['division']) && $_GET['division']=='A') echo 'selected'; ?>>Div A</option>
                        <option value="B" <?php if(isset($_GET['division']) && $_GET['division']=='B') echo 'selected'; ?>>Div B</option>
                        <option value="C" <?php if(isset($_GET['division']) && $_GET['division']=='C') echo 'selected'; ?>>Div C</option>
                    </select>
                        <div class="smart-search" style="flex:1;">
                            <input type="text" id="liveSearchInput" placeholder="Search by Moodle ID or Name..." onkeyup="liveSearch()">
                            <i class="fa-solid fa-search"></i>
                        </div>
                    </div>
                    <button type="button" class="btn-add" onclick="openSimpleModal('addStudentModal')"><i class="fa-solid fa-user-plus"></i> Add New Student</button>
                </form>

                <div class="card" style="padding:0; overflow:hidden;">
                    <table id="studentsTable">
                        <thead>
                            <tr style="background:var(--input-bg);">
                                <th>Student Details</th>
                                <th>Year/Div & Contact</th>
                                <th>Group Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                    <tbody style="padding:15px;">
                        <?php if($students->num_rows > 0): while($row = $students->fetch_assoc()): ?>
                        <tr class="student-row">
                            <td style="padding:15px;">
                                <strong style="color:var(--primary-green); font-size:15px;"><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                <span style="font-size:12px; color:var(--text-light); font-weight:600;">ID: <?php echo htmlspecialchars($row['moodle_id']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['academic_year']); ?> - Sem <?php echo $row['current_semester']; ?> (Div <?php echo htmlspecialchars($row['division']); ?>)</span><br>
                                <span style="font-size:12px; color: var(--text-light);"><i class="fa-solid fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($row['phone_number'] ?? 'N/A'); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <?php if($row['project_id']): ?>
                                    <span style="background:var(--input-bg); color:var(--btn-blue); border:1px solid var(--border-color); padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; display:inline-block;" onclick="openGroupInfo(<?php echo $row['project_id']; ?>)">
                                        <i class="fa-solid fa-users"></i> <?php echo htmlspecialchars($row['group_name']); ?>
                                    </span>
                                    <?php if($row['is_locked']): ?>
                                        <div style="font-size:11px; color:var(--primary-green); font-weight:600; margin-top:6px;">
                                            <i class="fa-solid fa-check-circle"></i> Topic: <?php echo htmlspecialchars($row['final_topic']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:11px; color:#D97706; font-weight:600; margin-top:6px; font-style:italic;">
                                            <i class="fa-solid fa-clock"></i> Not Finalized
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="background:rgba(220,38,38,0.1); color:#DC2626; border:1px solid #FECACA; padding:4px 8px; border-radius:6px; font-size:11px; font-weight:700;">
                                        <i class="fa-solid fa-triangle-exclamation"></i> No Group
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center; padding:15px; vertical-align:middle;">
                                <button class="btn-edit" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['moodle_id']); ?>', '<?php echo htmlspecialchars($row['full_name']); ?>', '<?php echo htmlspecialchars($row['academic_year']); ?>', '<?php echo $row['current_semester']; ?>', '<?php echo htmlspecialchars($row['division']); ?>', '<?php echo htmlspecialchars($row['phone_number']); ?>', '<?php echo htmlspecialchars($row['status']); ?>')">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-users-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No active students found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div> <!-- End section-active -->

            <!-- SECTION: BULK MANAGEMENT -->
            <div id="section-bulk" class="mgmt-section">
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap:20px;">
                    <!-- CSV IMPORT -->
                    <div class="card" style="text-align:left; margin:0;">
                        <h3 style="font-size:18px; margin-bottom:15px; color:var(--text-dark); display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-file-import" style="color:var(--primary-green);"></i> Bulk CSV Import</h3>
                        <p style="font-size:13px; color:var(--text-light); margin-bottom:20px;">Upload a CSV file to add or update multiple students at once. Required headers: <code>moodle_id</code>, <code>full_name</code>, <code>division</code>, <code>phone_number</code>.</p>
                        
                        <form id="importCsvForm" onsubmit="handleBulkAction(event, 'import_csv')">
                            <div style="display:flex; gap:10px; margin-bottom:15px;">
                                <div style="flex:1;">
                                    <label style="font-size:12px; font-weight:600; color:var(--text-light); display:block; margin-bottom:5px;">Target Year</label>
                                    <select id="import_year" class="filter-select" style="width:100%;" onchange="updateSemesterOptions(this.value, 'import_sem')">
                                        <option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                                    </select>
                                </div>
                                <div style="flex:1;">
                                    <label style="font-size:12px; font-weight:600; color:var(--text-light); display:block; margin-bottom:5px;">Target Semester</label>
                                    <select id="import_sem" class="filter-select" style="width:100%;">
                                        <option value="3">Sem 3</option><option value="4">Sem 4</option>
                                    </select>
                                </div>
                            </div>
                            <div style="margin-bottom:20px;">
                                <label style="font-size:12px; font-weight:600; color:var(--text-light); display:block; margin-bottom:5px;">Choose CSV File</label>
                                <input type="file" id="student_csv_file" accept=".csv" class="filter-select" style="width:100%; padding:8px;">
                            </div>
                            <button type="submit" class="btn-add" style="width:100%; justify-content:center;"><i class="fa-solid fa-upload"></i> Start Import Process</button>
                        </form>
                    </div>

                    <!-- BULK DELETE -->
                    <div class="card" style="text-align:left; border-top: 5px solid #EF4444; margin:0;">
                        <h3 style="font-size:18px; margin-bottom:15px; color:#EF4444; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-user-minus"></i> Bulk Student Removal</h3>
                        <p style="font-size:13px; color:var(--text-light); margin-bottom:20px;">Permanently disable all students for a specific year and semester. This will move them to the 'Removed' list.</p>
                        
                        <form id="bulkDeleteForm" onsubmit="handleBulkAction(event, 'bulk_delete')">
                            <div style="display:flex; gap:10px; margin-bottom:15px;">
                                <div style="flex:1;">
                                    <label style="font-size:12px; font-weight:600; color:var(--text-light); display:block; margin-bottom:5px;">Target Year</label>
                                    <select id="delete_year" class="filter-select" style="width:100%;" onchange="updateSemesterOptions(this.value, 'delete_sem')">
                                        <option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                                    </select>
                                </div>
                                <div style="flex:1;">
                                    <label style="font-size:12px; font-weight:600; color:var(--text-light); display:block; margin-bottom:5px;">Target Semester</label>
                                    <select id="delete_sem" class="filter-select" style="width:100%;">
                                        <option value="3">Sem 3</option><option value="4">Sem 4</option>
                                    </select>
                                </div>
                            </div>
                            <div style="background:rgba(239,68,68,0.05); padding:15px; border-radius:12px; border:1px solid rgba(239,68,68,0.2); margin-bottom:20px;">
                                <p style="font-size:12px; color:#991B1B;"><i class="fa-solid fa-triangle-exclamation"></i> This action will disable login for all students in the selected group.</p>
                            </div>
                            <button type="submit" class="btn-add" style="width:100%; justify-content:center; background:#EF4444;"><i class="fa-solid fa-trash-can"></i> Remove All Selected</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- SECTION: DISABLED STUDENTS -->
            <div id="section-disabled" class="mgmt-section">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px;">
                    <div style="display:flex; gap:10px;">
                        <select id="inactive_year" class="filter-select" onchange="fetchInactiveStudents()">
                            <option value="">All Years</option><option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                        </select>
                        <select id="inactive_sem" class="filter-select" onchange="fetchInactiveStudents()">
                            <option value="0">All Semesters</option>
                            <option value="3">Sem 3</option><option value="4">Sem 4</option>
                            <option value="5">Sem 5</option><option value="6">Sem 6</option>
                            <option value="7">Sem 7</option><option value="8">Sem 8</option>
                        </select>
                    </div>
                    <button class="btn-edit" onclick="fetchInactiveStudents()"><i class="fa-solid fa-arrows-rotate"></i> Refresh List</button>
                </div>

                <div class="card" style="padding:0; overflow:hidden; margin:0;">
                    <table>
                        <thead>
                            <tr style="background:var(--input-bg);">
                                <th>Student Info</th>
                                <th>Year / Status</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="inactiveStudentsList">
                            <tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size:30px; margin-bottom:15px;"></i><br>Loading removed students...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="addStudentModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Add New Student
                <button type="button" onclick="closeSimpleModal('addStudentModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" required></div>
                <div class="form-group"><label>Password</label><input type="text" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Year</label>
                        <select name="academic_year" onchange="updateSemesterOptions(this.value, 'add_sem_select')" required>
                            <option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Semester</label>
                        <select name="current_semester" id="add_sem_select" required>
                            <option value="3">Sem 3</option><option value="4">Sem 4</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Division</label>
                        <select name="division" required>
                            <option value="A">Div A</option><option value="B">Div B</option><option value="C">Div C</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number"></div>
                <button type="submit" name="add_student" class="btn-add" style="width:100%; justify-content:center;">Create Student</button>
            </form>
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
                <input type="hidden" name="student_id" id="edit_id">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" id="edit_moodle" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_name" required></div>
                <div style="display:flex; gap:10px;">
                    <div class="form-group" style="flex:1;">
                        <label>Year</label>
                        <select name="academic_year" id="edit_year" onchange="updateSemesterOptions(this.value, 'edit_sem_select')" required>
                            <option value="SE">SE</option><option value="TE">TE</option><option value="BE">BE</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Semester</label>
                        <select name="current_semester" id="edit_sem_select" required>
                            <!-- Options populated by JS -->
                        </select>
                    </div>
                    <div class="form-group" style="flex:1;">
                        <label>Division</label>
                        <select name="division" id="edit_div" required>
                            <option value="A">Div A</option><option value="B">Div B</option><option value="C">Div C</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number" id="edit_phone"></div>
                
                <div style="background:var(--input-bg); padding:15px; border-radius:12px; border:1px dashed var(--border-color); margin-bottom:15px;">
                    <label style="display:block; font-size:13px; font-weight:700; color:var(--primary-green); margin-bottom:10px;"><i class="fa-solid fa-key"></i> Reset Password</label>
                    <div class="form-group" style="margin-bottom:0;">
                        <input type="password" name="new_password" placeholder="Enter new password to change">
                        <p style="font-size:10px; color:var(--text-light); margin-top:5px;">Leave blank to keep existing password.</p>
                    </div>
                </div>

                <div class="form-group">
                    <label>Account Status</label>
                    <select name="status" id="edit_status" required>
                        <option value="Active">Active</option><option value="Disabled">Disabled (Passout)</option>
                    </select>
                </div>
                <button type="submit" name="edit_student" class="btn-add" style="width:100%; justify-content:center;">Update Info</button>
            </form>
        </div>
    </div>

    <div id="groupInfoModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--primary-green);">
                <span id="groupInfoTitle">Group Info</span>
                <button type="button" onclick="closeSimpleModal('groupInfoModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="groupInfoBody" style="font-size:14px; color:var(--text-dark);"></div>
        </div>
    </div>

    <script>
        const groupDataJSON = <?php echo $group_json; ?>;

        function openSimpleModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeSimpleModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(id, moodle, name, year, sem, div, phone, status) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_moodle').value = moodle;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_year').value = year;
            updateSemesterOptions(year, 'edit_sem_select', sem);
            document.getElementById('edit_div').value = div;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_status').value = status || 'Active';
            openSimpleModal('editStudentModal');
        }

        function updateSemesterOptions(year, selectId, selectedSem = null) {
            const select = document.getElementById(selectId);
            select.innerHTML = '';
            let options = [];
            if(year === 'SE') options = [3, 4];
            else if(year === 'TE') options = [5, 6];
            else if(year === 'BE') options = [7, 8];
            
            options.forEach(opt => {
                const el = document.createElement('option');
                el.value = opt;
                el.textContent = 'Sem ' + opt;
                if(selectedSem && opt == selectedSem) el.selected = true;
                select.appendChild(el);
            });
        }

        function openGroupInfo(projectId) {
            let g = groupDataJSON[projectId];
            if(!g) return;
            document.getElementById('groupInfoTitle').innerHTML = `<i class="fa-solid fa-users"></i> ` + g.group_name;
            
            let topicsList = '';
            if(g.topic_1) topicsList += `1. ${g.topic_1}<br>`;
            if(g.topic_2) topicsList += `2. ${g.topic_2}<br>`;
            if(g.topic_3) topicsList += `3. ${g.topic_3}`;
            if(!topicsList) topicsList = '<em style="color:gray;">No topics submitted</em>';

            let guideDisplay = g.guide_name ? `<i class="fa-solid fa-chalkboard-user"></i> ${g.guide_name}` : '<em style="color:#EF4444;">No Guide Assigned Yet</em>';

            let finalTopicHtml = '';
            if(g.is_locked == 1) {
                finalTopicHtml = `
                    <div style="margin-bottom:15px; background:rgba(16, 185, 129, 0.1); padding:12px; border-radius:8px; border:1px solid #A7F3D0;">
                        <div style="font-size:11px; font-weight:700; color:var(--primary-green); margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Final Allotted Topic</div>
                        <div style="font-size:14px; font-weight:700; color:var(--text-dark);"><i class="fa-solid fa-check-circle" style="color:var(--primary-green);"></i> ${g.final_topic}</div>
                    </div>
                `;
            }

            let html = `
                <div style="margin-bottom:15px;">
                    <div style="font-size:11px; font-weight:700; color:var(--text-light); margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Assigned Guide</div>
                    <div style="font-weight:600; color:var(--text-dark);">${guideDisplay}</div>
                </div>
                ${finalTopicHtml}
                <div style="margin-bottom:15px; background:var(--input-bg); padding:12px; border-radius:8px; border:1px solid var(--border-color);">
                    <div style="font-size:11px; font-weight:700; color:var(--primary-green); margin-bottom:5px; text-transform:uppercase; letter-spacing:1px;">Topics Submitted</div>
                    <div style="font-size:13px; line-height:1.5;">${topicsList}</div>
                </div>
                <div style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; padding:15px;">
                    <div style="font-size:11px; font-weight:700; color:var(--text-light); margin-bottom:8px; text-transform:uppercase; letter-spacing:1px;">Team Members</div>
                    <div style="font-size:13px; color:var(--text-dark); white-space:pre-line; line-height:1.6;">${g.member_details}</div>
                </div>
            `;
            document.getElementById('groupInfoBody').innerHTML = html;
            openSimpleModal('groupInfoModal');
        }

        function liveSearch() {
            let input = document.getElementById('liveSearchInput').value.toLowerCase().trim();
            document.querySelectorAll('.student-row').forEach(row => {
                let textData = row.textContent.toLowerCase();
                row.style.display = textData.includes(input) ? "" : "none";
            });
        }

        function switchMgmtTab(tabId) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.mgmt-section').forEach(s => s.classList.remove('active'));
            
            document.getElementById('tab-' + tabId).classList.add('active');
            document.getElementById('section-' + tabId).classList.add('active');
            
            if(tabId === 'disabled') {
                fetchInactiveStudents();
            }
        }

        async function handleBulkAction(event, action) {
            event.preventDefault();
            
            if(action === 'bulk_delete') {
                if(!confirm('CRITICAL WARNING: This will DISABLE and REMOVE all students for the selected Year and Semester. They will lose access immediately. Are you sure?')) return;
            }

            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            if(action === 'import_csv') {
                const fileInput = document.getElementById('student_csv_file');
                if(!fileInput.files[0]) { alert('Please select a CSV file.'); return; }
                formData.append('student_csv', fileInput.files[0]);
                formData.append('academic_year', document.getElementById('import_year').value);
                formData.append('semester', document.getElementById('import_sem').value);
            } else if(action === 'bulk_delete') {
                formData.append('academic_year', document.getElementById('delete_year').value);
                formData.append('semester', document.getElementById('delete_sem').value);
            }

            const btn = event.target.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
            btn.disabled = true;

            try {
                const response = await fetch('ajax_student_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if(result.status === 'success') {
                    alert(result.message);
                    if(action === 'import_csv') event.target.reset();
                    refreshDashboard(); // Refresh current view
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (error) {
                console.error('Bulk action failed:', error);
                alert('A server error occurred. Please try again.');
            } finally {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
        }

        async function fetchInactiveStudents() {
            const list = document.getElementById('inactiveStudentsList');
            list.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-spinner fa-spin" style="font-size:30px; margin-bottom:15px;"></i><br>Fetching records...</td></tr>';
            
            const formData = new FormData();
            formData.append('action', 'get_inactive_students');
            formData.append('academic_year', document.getElementById('inactive_year').value);
            formData.append('semester', document.getElementById('inactive_sem').value);

            try {
                const response = await fetch('ajax_student_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                
                if(result.status === 'success') {
                    if(result.students.length === 0) {
                        list.innerHTML = '<tr><td colspan="3" style="text-align:center; padding:40px; color:var(--text-light);"><i class="fa-solid fa-user-check" style="font-size:30px; margin-bottom:10px; opacity:0.3;"></i><br>No inactive students found for this selection.</td></tr>';
                        return;
                    }
                    
                    let html = '';
                    result.students.forEach(s => {
                        html += `
                            <tr>
                                <td style="padding:15px;">
                                    <strong style="color:var(--text-dark);">${s.full_name}</strong><br>
                                    <span style="font-size:12px; color:var(--text-light);">ID: ${s.moodle_id} | Div ${s.division}</span>
                                </td>
                                <td style="padding:15px;">
                                    <span style="background:rgba(239,68,68,0.1); color:#EF4444; padding:3px 8px; border-radius:6px; font-size:11px; font-weight:700;">${s.status}</span><br>
                                    <span style="font-size:11px; color:var(--text-light);">${s.academic_year} Sem ${s.current_semester}</span>
                                </td>
                                <td style="text-align:center; padding:15px;">
                                    <button class="btn-edit" style="color:var(--primary-green); border-color:var(--primary-green);" onclick="reactivateStudent(${s.id})">
                                        <i class="fa-solid fa-rotate-left"></i> Reactivate
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    list.innerHTML = html;
                }
            } catch (error) {
                list.innerHTML = '<tr><td colspan="3" style="text-align:center; color:#EF4444; padding:20px;">Failed to load data.</td></tr>';
            }
        }

        async function reactivateStudent(id) {
            if(!confirm('Restore this student to active list? They will be able to log in again.')) return;
            
            const formData = new FormData();
            formData.append('action', 'reactivate_student');
            formData.append('student_id', id);
            formData.append('csrf_token', '<?php echo $_SESSION['csrf_token']; ?>');

            try {
                const response = await fetch('ajax_student_mgmt.php', { method: 'POST', body: formData });
                const result = await response.json();
                if(result.status === 'success') {
                    alert(result.message);
                    fetchInactiveStudents();
                } else {
                    alert(result.message);
                }
            } catch (error) {
                alert('Error connecting to server.');
            }
        }

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

        function toggleMobileMenu() {
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.toggle('active');
                document.getElementById('mobileOverlay').classList.toggle('active');
            } else {
                document.getElementById('sidebar').classList.toggle('collapsed');
            }
        }

        async function refreshDashboard() {
            const icon = document.getElementById('refreshIcon');
            icon.classList.add('fa-spin');
            try {
                const response = await fetch(window.location.href);
                const html = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                document.getElementById('canvas-area').innerHTML = doc.getElementById('canvas-area').innerHTML;
                
                let alertBox = document.getElementById('alertBox');
                alertBox.innerHTML = "<div class='alert-success' style='padding:12px 20px;'><i class='fa-solid fa-check-circle'></i> Data refreshed successfully!</div>";
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';
                setTimeout(() => { alertBox.style.opacity = "0"; setTimeout(()=>alertBox.style.display="none", 500); }, 3000);
            } catch (error) { console.error('Refresh failed:', error); } 
            finally { icon.classList.remove('fa-spin'); }
        }

        setTimeout(() => { let a = document.getElementById('alertBox'); if(a && a.innerHTML.trim() !== "" && !a.querySelector('form')) { a.style.transition = "opacity 0.5s"; a.style.opacity = "0"; setTimeout(()=>a.style.display="none", 500); } }, 4000);
    </script>
    <script src="assets/js/ajax-forms.js?v=1.1"></script>
</body>
</html>