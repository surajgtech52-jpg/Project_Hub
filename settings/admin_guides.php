<?php
session_start();
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$admin_name = $_SESSION['name'];
$msg = "";

// ==============================================
//               GUIDE MANAGEMENT
// ==============================================

// 1. Add Guide
if (isset($_POST['add_guide'])) {
   $m_id = $conn->real_escape_string($_POST['moodle_id']); 
    $pass = $conn->real_escape_string($_POST['password']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $contact = $conn->real_escape_string($_POST['contact_number']);

    if (moodle_id_in_use($conn, $m_id)) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID '$m_id' is already registered in the system!</div>";
    } else {
        $conn->query("INSERT INTO guide (moodle_id, password, full_name, contact_number, status) VALUES ('$m_id', '$pass', '$name', '$contact', 'Active')");
        $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Mentor added successfully!</div>";
    }
}

// --- DYNAMIC CROSS-TABLE EDIT LOGIC ---
if (isset($_POST['edit_guide'])) {
    // We use the unique moodle_id from the form to tie everything together
    $m_id = $conn->real_escape_string($_POST['moodle_id']); 
    $new_name = $conn->real_escape_string($_POST['full_name']);
    $contact = $conn->real_escape_string($_POST['contact_number']);
    
    // 1. Update the Guide table based ONLY on moodle_id
    $sql_guide = "UPDATE guide SET full_name='$new_name', contact_number='$contact' WHERE moodle_id='$m_id'";
    $conn->query($sql_guide);

    // 2. Instantly update the Head table as well, if they exist there
    $sql_head = "UPDATE head SET full_name='$new_name', contact_number='$contact' WHERE moodle_id='$m_id'";
    $conn->query($sql_head);
    
    // 3. Optional: If they are also an Admin, update that too!
    $sql_admin = "UPDATE admin SET full_name='$new_name' WHERE moodle_id='$m_id'";
    $conn->query($sql_admin);

    $msg = "<div class='alert-success'><i class='fa-solid fa-check'></i> Profile updated everywhere dynamically!</div>";
}

// 3. Promote Guide to Head
if (isset($_POST['make_head'])) {
    $guide_id = (int)$_POST['guide_id'];
    $assigned_year = $conn->real_escape_string($_POST['assigned_year']);

    $guide = $conn->query("SELECT * FROM guide WHERE id = $guide_id")->fetch_assoc();
    $m_id = $guide['moodle_id'];
    $pass = $guide['password'];
    $name = $guide['full_name'];

    $check_year = $conn->query("SELECT id FROM head WHERE assigned_year = '$assigned_year'");
    if ($check_year->num_rows > 0) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: $assigned_year already has a Head. Remove or edit them first.</div>";
    } else {
        $check_moodle = $conn->query("SELECT id FROM head WHERE moodle_id = '$m_id'");
        if ($check_moodle->num_rows > 0) {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: This person is already a Head.</div>";
        } else {
            $conn->query("INSERT INTO head (moodle_id, password, full_name, assigned_year) VALUES ('$m_id', '$pass', '$name', '$assigned_year')");
            $msg = "<div class='alert-success'><i class='fa-solid fa-crown'></i> $name was successfully promoted to $assigned_year Year Head!</div>";
        }
    }
}

// 4. Remove Guide
if (isset($_POST['delete_guide'])) {
    $id = (int)$_POST['guide_id'];
    $conn->query("UPDATE projects SET assigned_guide_id = NULL WHERE assigned_guide_id = $id");
    $conn->query("UPDATE upload_requests SET guide_id = 0 WHERE guide_id = $id");
    $conn->query("DELETE FROM guide WHERE id = $id");
    $msg = "<div class='alert-success'><i class='fa-solid fa-trash-can'></i> Mentor removed successfully!</div>";
}

// ==============================================
//               HEAD MANAGEMENT
// ==============================================

// 1. Add Head
if (isset($_POST['add_head'])) {
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $pass = $conn->real_escape_string($_POST['password']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $year = $conn->real_escape_string($_POST['assigned_year']);

    $check = $conn->query("SELECT id FROM head WHERE assigned_year = '$year'");
    if ($check->num_rows > 0) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: A Head is already assigned to $year. Please edit the existing Head instead.</div>";
    } else {
        if (moodle_id_in_use($conn, $m_id)) {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID '$m_id' is already registered in the system!</div>";
        } else {
            $conn->query("INSERT INTO head (moodle_id, password, full_name, assigned_year) VALUES ('$m_id', '$pass', '$name', '$year')");
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Year Head added successfully!</div>";
        }
    }
}

// 2. Edit Head
if (isset($_POST['edit_head'])) {
    $id = (int)$_POST['head_id'];
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $year = $conn->real_escape_string($_POST['assigned_year']);
    $check = $conn->query("SELECT id FROM head WHERE assigned_year = '$year' AND id != $id");
    if ($check->num_rows > 0) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Error: Another Head is already assigned to $year.</div>";
    } else {
        $current = $conn->query("SELECT moodle_id FROM head WHERE id = $id")->fetch_assoc();
        $current_mid = $current ? $current['moodle_id'] : null;
        if ($current_mid !== null && $m_id !== $current_mid) {
            $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID cannot be changed once created (it breaks history/assignments). Create a new record instead.</div>";
        } else {
            $conn->query("UPDATE head SET full_name='$name', assigned_year='$year' WHERE id=$id");
            // If this person is also a Guide (same moodle_id), keep name in sync.
            if ($current_mid !== null) {
                $mid_for_sync = $conn->real_escape_string($current_mid);
                $conn->query("UPDATE guide SET full_name='$name' WHERE moodle_id='$mid_for_sync'");
            }
            $msg = "<div class='alert-success'><i class='fa-solid fa-pen'></i> Year Head info updated!</div>";
        }
    }
}

// 3. Remove Head
if (isset($_POST['delete_head'])) {
    $id = (int)$_POST['head_id'];
    $conn->query("DELETE FROM head WHERE id = $id");
    $msg = "<div class='alert-success'><i class='fa-solid fa-trash-can'></i> Year Head removed successfully!</div>";
}

// ==============================================
//               FETCH DATA
// ==============================================
$guides = $conn->query("SELECT * FROM guide ORDER BY full_name");
$heads = $conn->query("SELECT * FROM head ORDER BY assigned_year DESC");

$guide_projects = [];
$gp_query = $conn->query("SELECT p.id, p.group_name, p.project_year, p.division, p.assigned_guide_id FROM projects p WHERE p.assigned_guide_id IS NOT NULL AND p.is_archived = 0");
if ($gp_query) {
    while ($gp = $gp_query->fetch_assoc()) {
        $gid = $gp['assigned_guide_id'];
        if (!isset($guide_projects[$gid])) $guide_projects[$gid] = [];
        $guide_projects[$gid][] = $gp;
    }
}
$guide_projects_json = json_encode($guide_projects);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Guides & Heads - Admin</title>
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

        .sidebar { width: var(--sidebar-width); background: var(--card-bg); border-radius: 24px; padding: 30px; display: flex; flex-direction: column; height: 100%; margin-right: 20px; box-shadow: var(--shadow); z-index: 1000; overflow-y: auto;}
        .brand { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 700; color: var(--text-dark); margin-bottom: 50px; }
        .brand i { color: var(--primary-green); font-size: 26px; }
        .menu-label { font-size: 12px; color: var(--text-light); text-transform: uppercase; margin-bottom: 15px; font-weight: 600; margin-top:20px;}
        .nav-link { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: var(--text-light); text-decoration: none; border-radius: 14px; margin-bottom: 8px; font-weight: 500; transition: all 0.3s;}
        .nav-link.active { background-color: var(--primary-green); color: white; box-shadow: 0 8px 20px rgba(16, 93, 63, 0.2); }
        .nav-link:hover:not(.active) { background-color: var(--input-bg); color: var(--primary-green); }
        .logout-btn { margin-top: auto; color: #EF4444; }

        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding-right: 10px; position: relative;}
        
        .top-navbar { background: var(--card-bg); border-radius: 24px; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: var(--shadow); min-height: 75px; flex-shrink: 0;}
        .user-profile { display: flex; align-items: center; gap: 12px; border-left: 2px solid var(--border-color); padding-left: 20px; }
        .avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: var(--primary-green); font-weight: bold; font-size: 18px; background: var(--input-bg); border: 2px solid var(--border-color); flex-shrink:0;}
        .user-info h4 { font-size: 14px; font-weight: 600; color: var(--text-dark); margin:0;}
        .user-info p { font-size: 12px; color: var(--text-light); margin:0;}
        
        .theme-toggle-btn { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; display: flex; justify-content: center; align-items: center; width: 40px; height: 40px; transition: 0.3s; flex-shrink:0; }
        .theme-toggle-btn:hover { background: var(--border-color); }

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA;}

        /* Tab Switcher UI matches screenshot exactly */
        .tab-switcher { display: inline-flex; background: var(--input-bg); padding: 6px; border-radius: 16px; margin-bottom: 20px; border: 1px solid var(--border-color); width: fit-content; }
        .ts-btn { padding: 12px 25px; border-radius: 12px; font-size: 14px; font-weight: 600; color: var(--text-light); cursor: pointer; transition: 0.3s; border: none; background: transparent; display:flex; align-items:center; gap:8px;}
        .ts-btn.active { background: var(--card-bg); color: var(--primary-green); box-shadow: 0 4px 15px rgba(0,0,0,0.05); }

        .card { background: var(--card-bg); border-radius: 24px; padding: 25px 30px; box-shadow: var(--shadow); margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; font-size: 13px; color: var(--text-light); text-transform: uppercase; font-weight: 600; border-bottom: 2px solid var(--border-color); }
        td { padding: 15px; font-size: 14px; color: var(--text-dark); border-bottom: 1px solid var(--border-color); vertical-align:middle; }
        
        .btn-add { background: var(--btn-blue); color: white; border: none; padding: 12px 20px; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; display:inline-flex; align-items:center; gap:8px; transition:0.2s;}
        .btn-add:hover { background: var(--btn-blue-hover); }
        
        .action-flex { display:flex; gap:8px; justify-content:center; align-items:center; flex-wrap:wrap; }
        .btn-action-icon { width: 32px; height: 32px; border-radius: 8px; border: none; cursor: pointer; display: flex; justify-content: center; align-items: center; transition: 0.2s; font-size: 14px; outline:none;}
        .btn-action-icon:hover { opacity: 0.8; transform: scale(1.05); }

        .search-bar-container { display:flex; gap:15px; margin-bottom: 20px; flex-wrap:wrap;}
        .smart-search { flex:1; position:relative; min-width:250px;}
        .smart-search input { width:100%; border:1px solid var(--border-color); border-radius:16px; padding:15px 15px 15px 45px; font-size:14px; outline:none; transition:0.3s; background:var(--card-bg); color:var(--text-dark);}
        .smart-search input:focus { border-color:var(--primary-green); box-shadow:0 0 0 4px rgba(16,93,63,0.1); }
        .smart-search i { position:absolute; left:18px; top:16px; color:var(--text-light); font-size:16px; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--card-bg); padding: 30px; border-radius: 24px; width: 100%; max-width: 500px; display:flex; flex-direction:column; max-height:90vh; overflow-y:auto;}
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-light); margin-bottom: 5px; font-weight:600;}
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 13px; outline:none; background:var(--input-bg); color:var(--text-dark); font-family:inherit;}
        .form-group input:focus, .form-group select:focus { background: var(--card-bg); border-color: var(--primary-green); }

        .mobile-menu-btn, .close-sidebar-btn { display: none; background: none; border: none; font-size: 24px; color: var(--text-dark); cursor: pointer; margin-right: 15px; }
        .mobile-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 998; opacity: 0; transition: 0.3s; }
        .mobile-overlay.active { display: block; opacity: 1; }

        @media (max-width: 768px) {
            body { padding: 0; flex-direction: column; overflow-x: hidden; height: auto; overflow-y: auto;}
            .sidebar { position: fixed; top: 0; left: -300px; width: 280px; height: 100vh; margin: 0; border-radius: 0 24px 24px 0; box-shadow: 5px 0 20px rgba(0,0,0,0.3); z-index: 9999; transition: left 0.3s ease-in-out; display: flex !important; flex-direction: column !important; }
            .sidebar.active { left: 0; }
            .mobile-menu-btn, .close-sidebar-btn { display: block; }
            .main-content { padding: 15px; width: 100%; box-sizing: border-box; display: block; overflow-y: visible; height: auto; }
            .top-navbar { padding: 15px; border-radius: 16px; flex-direction: column; align-items: flex-start; gap: 15px; margin-bottom: 20px; height: auto; }
            .top-navbar-inner { display: flex; align-items: center; width: 100%; justify-content: space-between; }
            .top-navbar-left { display: flex; align-items: center; flex:1; }
            .user-profile { border-left: none; padding-left: 0; border-top: 1px solid var(--border-color); padding-top: 15px; width: 100%; justify-content: flex-start;}
            table { display: block; width: 100%; overflow-x: auto; white-space: nowrap; }
            .modal-card { width: 95%; max-height: 90vh; padding: 20px; border-radius: 16px; margin: 20px auto; }
            .tab-switcher { width: 100%; overflow-x: auto; white-space: nowrap; }
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
        <div class="menu-label" style="margin-top:0;">Global Management</div>
        <a href="admin_dashboard.php?tab=dashboard" class="nav-link"><i class="fa-solid fa-layer-group"></i> Active Projects</a>
        <a href="admin_dashboard.php?tab=history" class="nav-link"><i class="fa-solid fa-clock-rotate-left"></i> History Vault</a>
        <a href="admin_dashboard.php?tab=resets" class="nav-link"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>

        <div class="menu-label">System Tools</div>
        <a href="admin_student.php" class="nav-link"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="admin_guides.php" class="nav-link active"><i class="fa-solid fa-chalkboard-user"></i> View Guides & Heads</a>
        <a href="admin_form_settings.php" class="nav-link"><i class="fa-brands fa-google"></i> Form Builder</a>
        <a href="admin_transfer.php" class="nav-link"><i class="fa-solid fa-arrow-right-arrow-left"></i> Year Promotion</a>
        
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
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Staff Directory</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Global Mentors and Year Heads</p>
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
            
            <div class="tab-switcher">
                <button class="ts-btn active" id="tab-btn-guides" onclick="switchStaffTab('guides')"><i class="fa-solid fa-chalkboard-user"></i> Project Mentors (Guides)</button>
                <button class="ts-btn" id="tab-btn-heads" onclick="switchStaffTab('heads')"><i class="fa-solid fa-user-tie"></i> Year Heads</button>
            </div>
            
            <div id="sec-guides" style="display:block;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                    <div class="smart-search" style="flex:1; min-width:300px;">
                        <input type="text" id="liveSearchGuides" placeholder="Search Mentors by ID or Name..." onkeyup="liveSearch('guidesTable', 'liveSearchGuides')">
                        <i class="fa-solid fa-search"></i>
                    </div>
                    <button class="btn-add" onclick="openSimpleModal('addGuideModal')"><i class="fa-solid fa-user-plus"></i> Add Mentor</button>
                </div>

                <div class="card" style="padding:0; overflow:hidden;">
                    <table id="guidesTable">
                        <thead>
                            <tr style="background:var(--input-bg);">
                                <th>Guide Info</th>
                                <th>Status / Contact</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody style="padding:15px;">
                            <?php if($guides->num_rows > 0): while($row = $guides->fetch_assoc()): ?>
                            <tr class="searchable-row">
                                <td style="padding:15px;">
                                    <strong style="color:var(--btn-blue); font-size:15px;"><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                    <span style="font-size:12px; color:var(--text-light); font-weight:600;">ID: <?php echo htmlspecialchars($row['moodle_id']); ?></span>
                                </td>
                                <td style="padding:15px;">
                                    <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['status'] ?? 'Active'); ?></span><br>
                                    <span style="font-size:12px; color: var(--text-light);"><i class="fa-solid fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></span>
                                </td>
                                <td style="text-align:center; padding:15px; vertical-align:middle;">
                                    <div class="action-flex">
                                        <button class="btn-action-icon" style="color:#3B82F6; background:rgba(59,130,246,0.1);" 
                                            data-id="<?php echo $row['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>" 
                                            onclick="openViewGroups(this)" title="View Assigned Groups"><i class="fa-solid fa-users"></i></button>
                                        
                                        <button class="btn-action-icon" style="color:#D97706; background:rgba(217,119,6,0.1);" 
                                            data-id="<?php echo $row['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>" 
                                            onclick="openMakeHead(this)" title="Promote to Year Head"><i class="fa-solid fa-crown"></i></button>
                                        
                                        <button class="btn-action-icon" style="color:#10B981; background:rgba(16,185,129,0.1);" 
                                            data-id="<?php echo $row['id']; ?>" 
                                            data-moodle="<?php echo htmlspecialchars($row['moodle_id'], ENT_QUOTES); ?>" 
                                            data-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>" 
                                            data-contact="<?php echo htmlspecialchars($row['contact_number'] ?? '', ENT_QUOTES); ?>" 
                                            onclick="openEditGuideModal(this)" title="Edit Info"><i class="fa-solid fa-pen"></i></button>
                                        
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this guide? This will unassign them from ALL their current groups.');">
                                            <input type="hidden" name="guide_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_guide" class="btn-action-icon" style="color:#EF4444; background:rgba(239,68,68,0.1);" title="Remove Guide"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-user-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No guides registered in the system.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="sec-heads" style="display:none;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                    <div class="smart-search" style="flex:1; min-width:300px;">
                        <input type="text" id="liveSearchHeads" placeholder="Search Heads by ID or Name..." onkeyup="liveSearch('headsTable', 'liveSearchHeads')">
                        <i class="fa-solid fa-search"></i>
                    </div>
                    <button class="btn-add" style="background:var(--primary-green);" onclick="openSimpleModal('addHeadModal')"><i class="fa-solid fa-plus"></i> Assign Year Head</button>
                </div>
                
                <div class="card" style="padding:0; overflow:hidden;">
                    <table id="headsTable">
                        <thead>
                            <tr style="background:var(--input-bg);">
                                <th>Head Info</th>
                                <th>Assigned Year</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody style="padding:15px;">
                            <?php if($heads->num_rows > 0): while($row = $heads->fetch_assoc()): ?>
                            <tr class="searchable-row">
                                <td style="padding:15px;">
                                    <strong style="color:var(--text-dark); font-size:15px;"><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                    <span style="font-size:12px; color:var(--text-light); font-weight:600;">ID: <?php echo htmlspecialchars($row['moodle_id']); ?></span>
                                </td>
                                <td style="padding:15px;">
                                    <span style="background:var(--input-bg); color:var(--primary-green); border:1px solid var(--border-color); padding:4px 8px; border-radius:6px; font-size:13px; font-weight:700;"><i class="fa-solid fa-calendar-check"></i> <?php echo htmlspecialchars($row['assigned_year']); ?> Year Head</span>
                                </td>
                                <td style="text-align:center; padding:15px; vertical-align:middle;">
                                    <div class="action-flex">
                                        <button class="btn-action-icon" style="color:#10B981; background:rgba(16,185,129,0.1);" 
                                            data-id="<?php echo $row['id']; ?>" 
                                            data-moodle="<?php echo htmlspecialchars($row['moodle_id'], ENT_QUOTES); ?>" 
                                            data-name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>" 
                                            data-year="<?php echo htmlspecialchars($row['assigned_year'], ENT_QUOTES); ?>" 
                                            onclick="openEditHeadModal(this)" title="Edit Info">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('Remove this Year Head permanently?');">
                                            <input type="hidden" name="head_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_head" class="btn-action-icon" style="color:#EF4444; background:rgba(239,68,68,0.1);" title="Remove Head"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                                <tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 40px;">No Year Heads Assigned yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div id="viewGroupsModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--btn-blue);">
                <span id="vg_title">View Groups</span>
                <button type="button" onclick="closeSimpleModal('viewGroupsModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="viewGroupsBody" style="background:var(--input-bg); border:1px solid var(--border-color); border-radius:12px; max-height: 400px; overflow-y:auto;">
                </div>
        </div>
    </div>

    <div id="makeHeadModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:#D97706;">
                Promote to Year Head
                <button type="button" onclick="closeSimpleModal('makeHeadModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="guide_id" id="mh_guide_id">
                <div class="form-group">
                    <label>Promote <span id="mh_guide_name" style="color:var(--primary-green);"></span> to Head for Year:</label>
                    <select name="assigned_year" required>
                        <option value="SE">SE Year Head</option>
                        <option value="TE">TE Year Head</option>
                        <option value="BE">BE Year Head</option>
                    </select>
                </div>
                <button type="submit" name="make_head" class="btn-add" style="background:#D97706; width:100%; justify-content:center;"><i class="fa-solid fa-crown"></i> Promote to Head</button>
            </form>
        </div>
    </div>

    <div id="addHeadModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--primary-green);">
                Assign Year Head
                <button type="button" onclick="closeSimpleModal('addHeadModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" required></div>
                <div class="form-group"><label>Temporary Password</label><input type="text" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group">
                    <label>Assign to Year</label>
                    <select name="assigned_year" required>
                        <option value="SE">SE Year Head</option>
                        <option value="TE">TE Year Head</option>
                        <option value="BE">BE Year Head</option>
                    </select>
                </div>
                <button type="submit" name="add_head" class="btn-add" style="background:var(--primary-green); width:100%; justify-content:center;">Register Head</button>
            </form>
        </div>
    </div>

    <div id="editHeadModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Edit Head Info 
                <button type="button" onclick="closeSimpleModal('editHeadModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="head_id" id="eh_id">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" id="eh_moodle" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="eh_name" required></div>
                <div class="form-group">
                    <label>Assign to Year</label>
                    <select name="assigned_year" id="eh_year" required>
                        <option value="SE">SE Year Head</option>
                        <option value="TE">TE Year Head</option>
                        <option value="BE">BE Year Head</option>
                    </select>
                </div>
                <button type="submit" name="edit_head" class="btn-add" style="background:var(--primary-green); width:100%; justify-content:center;">Update Info</button>
            </form>
        </div>
    </div>

    <div id="addGuideModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--btn-blue);">
                Add New Mentor
                <button type="button" onclick="closeSimpleModal('addGuideModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" required></div>
                <div class="form-group"><label>Temporary Password</label><input type="text" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Contact Number (Optional)</label><input type="text" name="contact_number"></div>
                <button type="submit" name="add_guide" class="btn-add" style="width:100%; justify-content:center;">Register Mentor</button>
            </form>
        </div>
    </div>

    <div id="editGuideModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Edit Mentor Info 
                <button type="button" onclick="closeSimpleModal('editGuideModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <input type="hidden" name="guide_id" id="eg_id">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" id="eg_moodle" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="eg_name" required></div>
                <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" id="eg_contact"></div>
                <button type="submit" name="edit_guide" class="btn-add" style="width:100%; justify-content:center;">Update Info</button>
            </form>
        </div>
    </div>

    <script>
        const guideProjects = <?php echo $guide_projects_json; ?>;

        // STAFF TAB SWITCHER
        function switchStaffTab(tab) {
            document.getElementById('sec-guides').style.display = 'none';
            document.getElementById('sec-heads').style.display = 'none';
            document.getElementById('tab-btn-guides').classList.remove('active');
            document.getElementById('tab-btn-heads').classList.remove('active');
            
            document.getElementById('sec-' + tab).style.display = 'block';
            document.getElementById('tab-btn-' + tab).classList.add('active');
        }

        function openSimpleModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeSimpleModal(id) { document.getElementById(id).style.display = 'none'; }

        // SECURE BUTTON HANDLERS (No inline JS crash risks)
        function openViewGroups(btn) {
            let gid = btn.getAttribute('data-id');
            let gname = btn.getAttribute('data-name');
            document.getElementById('vg_title').innerHTML = `<i class="fa-solid fa-users"></i> ` + gname + "'s Groups";
            let groups = guideProjects[gid] || [];
            let html = '';
            
            if(groups.length > 0) {
                groups.forEach(g => {
                    html += `
                    <div style="padding:15px; border-bottom:1px solid var(--border-color); display:flex; justify-content:space-between; align-items:center;">
                        <strong style="color:var(--text-dark); font-size:14px;">${g.group_name}</strong>
                        <span style="background:var(--card-bg); color:var(--text-light); font-size:11px; padding:4px 8px; border-radius:6px; border:1px solid var(--border-color); font-weight:600;">
                            ${g.project_year} - Div ${g.division}
                        </span>
                    </div>`;
                });
            } else {
                html = `<div style="text-align:center; color:var(--text-light); padding:30px; font-size:13px;"><i class="fa-solid fa-users-slash" style="font-size:30px; color:var(--border-color); margin-bottom:10px; display:block;"></i>No groups currently assigned to this mentor.</div>`;
            }
            document.getElementById('viewGroupsBody').innerHTML = html;
            openSimpleModal('viewGroupsModal');
        }

        function openMakeHead(btn) {
            document.getElementById('mh_guide_id').value = btn.getAttribute('data-id');
            document.getElementById('mh_guide_name').innerText = btn.getAttribute('data-name');
            openSimpleModal('makeHeadModal');
        }

        function openEditHeadModal(btn) {
            document.getElementById('eh_id').value = btn.getAttribute('data-id');
            document.getElementById('eh_moodle').value = btn.getAttribute('data-moodle');
            document.getElementById('eh_name').value = btn.getAttribute('data-name');
            document.getElementById('eh_year').value = btn.getAttribute('data-year');
            openSimpleModal('editHeadModal');
        }

        function openEditGuideModal(btn) {
            document.getElementById('eg_id').value = btn.getAttribute('data-id');
            document.getElementById('eg_moodle').value = btn.getAttribute('data-moodle');
            document.getElementById('eg_name').value = btn.getAttribute('data-name');
            document.getElementById('eg_contact').value = btn.getAttribute('data-contact');
            openSimpleModal('editGuideModal');
        }

        // SMART SEARCH
        function liveSearch(tableId, inputId) {
            let input = document.getElementById(inputId).value.toLowerCase().trim();
            document.querySelectorAll('#' + tableId + ' .searchable-row').forEach(row => {
                let textData = row.textContent.toLowerCase();
                row.style.display = textData.includes(input) ? "" : "none";
            });
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

        // DYNAMIC PARTIAL REFRESH
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

        setTimeout(() => { let a = document.getElementById('alertBox'); if(a && a.innerHTML.trim() !== "") { a.style.transition = "opacity 0.5s"; a.style.opacity = "0"; setTimeout(()=>a.style.display="none", 500); } }, 4000);
    </script>
</body>
</html>