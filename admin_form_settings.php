<?php
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$admin_name = $_SESSION['name'];
$msg = "";

$selected_year = isset($_GET['year']) ? $conn->real_escape_string($_GET['year']) : 'SE';

// Determine default semester based on year
$default_sem = 3;
if ($selected_year == 'TE') $default_sem = 5;
if ($selected_year == 'BE') $default_sem = 7;

$selected_sem = isset($_GET['sem']) ? (int)$_GET['sem'] : $default_sem;

// Ensure semester is valid for the selected year
$valid_sems = ($selected_year == 'SE') ? [3, 4] : (($selected_year == 'TE') ? [5, 6] : [7, 8]);
if (!in_array($selected_sem, $valid_sems)) {
    $selected_sem = $default_sem;
}

// --- HANDLE FORM SAVING (PUBLISH) ---
if (isset($_POST['save_form'])) {
    verify_csrf_token();
    
    $year = $conn->real_escape_string($_POST['target_year']);
    $sem = (int)$_POST['target_sem'];
    
    // Final backend validation before save
    $valid_sems = ($year == 'SE') ? [3, 4] : (($year == 'TE') ? [5, 6] : [7, 8]);
    if (!in_array($sem, $valid_sems)) {
        $sem = ($year == 'SE') ? 3 : (($year == 'TE') ? 5 : 7);
    }

    $is_open = isset($_POST['is_form_open']) ? 1 : 0;
    $min_size = (int)$_POST['min_team_size'];
    $max_size = (int)$_POST['max_team_size'];
    $schema_json = $_POST['schema_json']; // Keep raw for validation

    // BACKEND VALIDATION: Check for duplicate labels
    $decoded = json_decode($schema_json, true);
    if (is_array($decoded)) {
        $labels = array_map(function($f) { return strtolower(trim($f['label'])); }, $decoded);
        if (count($labels) !== count(array_unique($labels))) {
            $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> <strong>Validation Error:</strong> Duplicate field labels detected (case-insensitive). Each field must have a unique name.</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('error', 'Duplicate field labels detected.');
        } else {
            $schema_escaped = $conn->real_escape_string($schema_json);
            $check = $conn->query("SELECT id FROM form_settings WHERE academic_year='$year' AND semester=$sem");
            if ($check->num_rows > 0) {
                $sql = "UPDATE form_settings SET is_form_open=$is_open, min_team_size=$min_size, max_team_size=$max_size, form_schema='$schema_escaped' WHERE academic_year='$year' AND semester=$sem";
            } else {
                $sql = "INSERT INTO form_settings (academic_year, semester, is_form_open, min_team_size, max_team_size, form_schema) VALUES ('$year', $sem, $is_open, $min_size, $max_size, '$schema_escaped')";
            }

            if ($conn->query($sql) === TRUE) {
                $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-paper-plane'></i> Form configuration for $year (Sem $sem) published successfully!</div>";
                if (isset($_POST['is_ajax'])) send_ajax_response('success', "Form configuration for $year (Sem $sem) published!");
            } else {
                $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> Error publishing form: " . $conn->error . "</div>";
                if (isset($_POST['is_ajax'])) send_ajax_response('error', "Error publishing form: " . $conn->error);
            }
        }
    }
}

// Fetch Current Settings
$current_settings = $conn->query("SELECT * FROM form_settings WHERE academic_year='$selected_year' AND semester=$selected_sem")->fetch_assoc();
$is_open = $current_settings ? $current_settings['is_form_open'] : 0;
$min = $current_settings ? $current_settings['min_team_size'] : 1;
$max = $current_settings ? $current_settings['max_team_size'] : 4;
$schema = ($current_settings && !empty($current_settings['form_schema'])) ? $current_settings['form_schema'] : '[]';

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Form Builder - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <style>
        :root { 
            --primary-green: #105D3F; --primary-hover: #0A402A; --bg-color: #F3F4F6; --text-dark: #1F2937; --text-light: #6B7280; 
            --card-bg: #FFFFFF; --border-color: #E5E7EB; --input-bg: #F9FAFB; --sidebar-width: 260px; 
            --shadow: 0 5px 20px rgba(0,0,0,0.02); --btn-blue: #3B82F6; --btn-blue-hover: #2563EB;
        }
        
        [data-theme="dark"] {
            --primary-green: #34D399; --primary-hover: #105D3F; --bg-color: #111827; --text-dark: #F9FAFB; --text-light: #9CA3AF; 
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

        .main-content { flex: 1; display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding-right: 15px;}
        
        .top-navbar { background: var(--card-bg); border-radius: 24px; padding: 15px 30px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; box-shadow: var(--shadow); min-height: 75px; flex-shrink: 0; gap: 20px;}
        .top-navbar-inner { display: flex; align-items: center; width: 100%; justify-content: space-between; }
        .top-navbar-left { display: flex; align-items: center; gap: 15px; }
        .user-profile { display: flex; align-items: center; gap: 12px; border-left: 2px solid var(--border-color); padding-left: 20px; }
        .avatar { width: 45px; height: 45px; border-radius: 50%; display: flex; justify-content: center; align-items: center; color: var(--primary-green); font-weight: bold; font-size: 18px; background: var(--input-bg); border: 2px solid var(--border-color); flex-shrink:0;}
        .user-info h4 { font-size: 14px; font-weight: 600; color: var(--text-dark); margin:0;}
        .user-info p { font-size: 12px; color: var(--text-light); margin:0;}
        
        .theme-toggle-btn { background: var(--input-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; display: flex; justify-content: center; align-items: center; width: 40px; height: 40px; transition: 0.3s; flex-shrink:0; }
        .theme-toggle-btn:hover { background: var(--border-color); }

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0; text-align:center;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA; text-align:center;}

        /* Form Builder UI */
        .form-header-card { background: var(--card-bg); border-radius: 16px; padding: 30px; box-shadow: var(--shadow); margin-bottom: 20px; border: 1px solid var(--border-color); border-top: 8px solid var(--primary-green);}
        .form-title { font-size: 24px; font-weight: 600; color: var(--text-dark); margin-bottom: 5px; border:none; outline:none; width:100%; background:transparent;}
        
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-color); transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: var(--primary-green); }
        input:checked + .slider:before { transform: translateX(20px); }

        .builder-container { max-width: 800px; margin: 0 auto; width: 100%; position:relative; padding-bottom: 100px;}
        
        .q-card { background: var(--card-bg); border-radius: 16px; padding: 25px; box-shadow: var(--shadow); margin-bottom: 15px; border: 1px solid var(--border-color); position: relative; transition:0.2s; border-left: 5px solid transparent;}
        .q-card.active { border-left-color: var(--primary-green); box-shadow: 0 10px 30px rgba(0,0,0,0.05); transform:scale(1.01); z-index:10;}
        /* Modern Google Forms Drag Styling */
        .sortable-ghost { visibility: hidden !important; }
        .sortable-drag { opacity: 1 !important; box-shadow: 0 8px 30px rgba(0,0,0,0.12) !important; cursor: grabbing !important; background: var(--card-bg) !important; z-index: 10000 !important; border-radius: 16px; }
        .drag-handle:active { cursor: grabbing !important; }
        .q-header { display: flex; gap: 15px; margin-bottom: 20px; }
        .q-title-input { flex: 1; padding: 15px 15px 15px 0; border: none; border-bottom: 1px solid var(--border-color); font-size: 15px; font-weight: 500; color: var(--text-dark); outline: none; transition: 0.3s; background: transparent; font-family:inherit;}
        .q-title-input:focus { border-bottom: 2px solid var(--primary-green); margin-bottom:-1px; }
        .q-type-select { padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; color: var(--text-dark); outline: none; background: var(--input-bg); cursor: pointer; width:200px; font-family:inherit;}
        .q-type-select:focus { border-color: var(--primary-green); }

        .option-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .option-icon { color: var(--text-light); font-size: 16px; width: 20px; text-align: center;}
        .option-input { flex: 1; padding: 10px 0; border: none; border-bottom: 1px solid transparent; font-size: 14px; outline: none; transition: 0.3s; font-family:inherit; background:transparent; color:var(--text-dark);}
        .option-input:hover { border-bottom: 1px solid var(--border-color); }
        .option-input:focus { border-bottom: 2px solid var(--primary-green); margin-bottom:-1px;}
        .btn-remove-opt { background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 16px; padding:5px;}
        .btn-remove-opt:hover { color: #EF4444; }
        .add-option-btn { background: none; border: none; color: var(--btn-blue); font-size: 13px; font-weight: 600; cursor: pointer; padding: 10px 0; display:flex; align-items:center; gap:8px;}

        .q-footer { display: flex; justify-content: flex-end; align-items: center; border-top: 1px solid var(--border-color); padding-top: 15px; margin-top: 20px; gap:15px;}
        .q-action-icon { background: none; border: none; color: var(--text-light); cursor: pointer; font-size: 18px; padding: 8px; border-radius: 50%; transition: 0.2s;}
        .q-action-icon:hover { background: var(--input-bg); color: var(--text-dark);}
        
        .floating-menu { position: fixed; right: 50px; top: 50%; transform: translateY(-50%); background: var(--card-bg); padding: 10px; border-radius: 12px; box-shadow: var(--shadow); border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 10px; z-index: 100;}
        .fab-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--card-bg); border: none; color: var(--text-light); font-size: 20px; display: flex; justify-content: center; align-items: center; cursor: pointer; transition: 0.2s;}
        .fab-btn:hover { background: var(--input-bg); color: var(--primary-green); }

        .action-buttons-container { display:flex; justify-content:center; gap:15px; margin-top: 40px; }
        .btn-publish { background: var(--primary-green); color: white; border: none; padding: 14px 35px; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; display:flex; align-items:center; gap:8px;}
        .btn-publish:hover { background: var(--primary-hover); transform:translateY(-2px);}
        .btn-preview { background: var(--card-bg); color: var(--primary-green); border: 2px solid var(--primary-green); padding: 12px 35px; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; display:flex; align-items:center; gap:8px;}
        .btn-preview:hover { background: var(--input-bg); transform:translateY(-2px);}

        /* Preview Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--bg-color); width: 100%; max-width: 800px; height: 90vh; border-radius: 24px; display: flex; flex-direction: column; overflow: hidden;}
        .modal-header { padding: 20px 30px; background: var(--card-bg); display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); color:var(--text-dark);}
        .modal-body { padding: 40px; overflow-y: auto; flex: 1; }
        
        .preview-form-group { background: var(--card-bg); padding: 25px; border-radius: 16px; margin-bottom: 20px; border: 1px solid var(--border-color); box-shadow: var(--shadow);}
        .preview-label { display: block; font-size: 15px; font-weight: 600; color: var(--text-dark); margin-bottom: 15px; }
        .preview-input { width: 100%; padding: 12px 15px; border: 1px solid var(--border-color); border-radius: 10px; font-size: 14px; outline: none; background: var(--input-bg); font-family: inherit; color:var(--text-dark);}
        .preview-radio-container { display: flex; flex-direction: column; gap: 10px; }
        .preview-radio-label { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text-dark); cursor: pointer; }

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
            
            .floating-menu { right: 20px; top: auto; bottom: 100px; transform: none; flex-direction: row; border-radius: 50px; }
            .modal-card { width: 95%; max-height: 90vh; border-radius: 16px; margin: 20px auto; }
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
        <div class="menu-label" style="margin-top:0;">Global Management</div>
        <a href="admin_dashboard.php?tab=dashboard" class="nav-link"><i class="fa-solid fa-layer-group"></i> Active Projects</a>
        <a href="admin_dashboard.php?tab=history" class="nav-link"><i class="fa-solid fa-vault"></i> History Vault</a>
        <a href="admin_dashboard.php?tab=workspace" class="nav-link"><i class="fa-solid fa-briefcase"></i> Manage Workspace</a>
        <a href="admin_dashboard.php?tab=resets" class="nav-link"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>
        <a href="admin_dashboard.php?tab=logs" class="nav-link"><i class="fa-solid fa-book"></i> Project Logs</a>
        <a href="admin_dashboard.php?tab=exports" class="nav-link"><i class="fa-solid fa-file-excel"></i> Export Hub</a>

        <div class="menu-label">System Tools</div>
        <a href="admin_student.php" class="nav-link"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="admin_guides.php" class="nav-link"><i class="fa-solid fa-chalkboard-user"></i> View Guides & Heads</a>
        <a href="admin_form_settings.php" class="nav-link active"><i class="fa-brands fa-google"></i> Form Builder</a>
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
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Form Builder</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Configure registration forms globally</p>
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

        <div id="canvas-area">
            <form method="GET" style="margin-bottom: 20px; max-width:800px; margin: 0 auto 20px auto; text-align:center; display:flex; justify-content:center; align-items:center; gap:15px;">
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: var(--text-dark); margin-right: 10px;">Year:</label>
                    <select name="year" onchange="this.form.submit()" style="padding: 10px 15px; border-radius: 12px; border: 1px solid var(--border-color); font-size: 14px; outline: none; cursor:pointer; background:var(--card-bg); color:var(--text-dark); font-family:inherit;">
                        <option value="SE" <?php if($selected_year == 'SE') echo 'selected'; ?>>Second Year (SE)</option>
                        <option value="TE" <?php if($selected_year == 'TE') echo 'selected'; ?>>Third Year (TE)</option>
                        <option value="BE" <?php if($selected_year == 'BE') echo 'selected'; ?>>Final Year (BE)</option>
                    </select>
                </div>
                <div>
                    <label style="font-size: 14px; font-weight: 600; color: var(--text-dark); margin-right: 10px;">Semester:</label>
                    <select name="sem" onchange="this.form.submit()" style="padding: 10px 15px; border-radius: 12px; border: 1px solid var(--border-color); font-size: 14px; outline: none; cursor:pointer; background:var(--card-bg); color:var(--text-dark); font-family:inherit;">
                        <?php if($selected_year == 'SE'): ?>
                            <option value="3" <?php if($selected_sem == 3) echo 'selected'; ?>>Semester 3</option>
                            <option value="4" <?php if($selected_sem == 4) echo 'selected'; ?>>Semester 4</option>
                        <?php elseif($selected_year == 'TE'): ?>
                            <option value="5" <?php if($selected_sem == 5) echo 'selected'; ?>>Semester 5</option>
                            <option value="6" <?php if($selected_sem == 6) echo 'selected'; ?>>Semester 6</option>
                        <?php elseif($selected_year == 'BE'): ?>
                            <option value="7" <?php if($selected_sem == 7) echo 'selected'; ?>>Semester 7</option>
                            <option value="8" <?php if($selected_sem == 8) echo 'selected'; ?>>Semester 8</option>
                        <?php endif; ?>
                    </select>
                </div>
            </form>

            <form method="POST" id="masterForm" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="target_year" value="<?php echo htmlspecialchars($selected_year); ?>">
                <input type="hidden" name="target_sem" value="<?php echo (int)$selected_sem; ?>">
                <input type="hidden" name="schema_json" id="schema_output">
                <input type="hidden" name="save_form" value="1">
                
                <div class="builder-container">
                    
                    <div class="form-header-card">
                        <input type="text" class="form-title" value="Project Registration Form (<?php echo htmlspecialchars($selected_year); ?> - Sem <?php echo $selected_sem; ?>)" readonly>
                        <p style="color:var(--text-light); font-size:14px; margin-top:5px;">This form is generated dynamically for <?php echo htmlspecialchars($selected_year); ?> Semester <?php echo $selected_sem; ?> students.</p>
                        
                        <div style="margin-top: 30px; display:flex; align-items:center; justify-content:space-between; background:var(--input-bg); padding:15px 20px; border-radius:12px; border:1px solid var(--border-color);">
                            <div>
                                <strong style="font-size:15px; color:var(--text-dark);">Accepting Responses</strong>
                                <p style="font-size:12px; color:var(--text-light); margin-top:2px;">Turn off to lock the form for all <?php echo htmlspecialchars($selected_year); ?> Sem <?php echo $selected_sem; ?> students.</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="is_form_open" <?php echo $is_open ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <div id="fields_canvas"></div>

                    <div class="action-buttons-container">
                        <button type="button" class="btn-preview" onclick="openPreviewModal()"><i class="fa-regular fa-eye"></i> Preview</button>
                        <button type="button" class="btn-publish" onclick="saveGoogleForm()"><i class="fa-solid fa-paper-plane"></i> Publish Form</button>
                    </div>
                </div>
            </form>

            <div class="floating-menu">
                <button type="button" class="fab-btn" title="Add Question" onclick="addNewField()"><i class="fa-solid fa-circle-plus"></i></button>
            </div>
        </div>
    </div>

    <div id="previewModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3 style="margin:0; font-size: 18px; color: var(--primary-green); display:flex; align-items:center; gap:10px;"><i class="fa-regular fa-eye"></i> Student Form Preview</h3>
                <button type="button" onclick="document.getElementById('previewModal').style.display='none'" style="border:none; background:none; font-size:20px; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="previewCanvas"></div>
        </div>
    </div>

    <script>
        let formFields = <?php echo $schema; ?>;
        
        let teamFieldIdx = formFields.findIndex(f => f.type === 'team-members');
        if(teamFieldIdx !== -1) {
            let teamData = formFields.splice(teamFieldIdx, 1)[0];
            formFields.unshift(teamData); 
        } else {
            formFields.unshift({ id: 'f_team', label: 'Team Members Configuration', type: 'team-members', required: true });
        }

        let activeIndex = null;
        let sortableInstance = null;
        let currentMin = <?php echo $min; ?>;
        let currentMax = <?php echo $max; ?>;

        function renderCanvas() {
            const canvas = document.getElementById('fields_canvas');
            canvas.innerHTML = '';

            formFields.forEach((field, index) => {
                const card = document.createElement('div');
                card.className = `q-card ${field.type === 'team-members' ? 'system-locked' : ''} ${index === activeIndex ? 'active' : ''}`;
                card.dataset.index = index;
                card.onclick = () => { if(activeIndex !== index) { activeIndex = index; renderCanvas(); } };

                if(field.type === 'team-members') {
                    card.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div style="font-size:18px; font-weight:600; color:var(--text-dark); display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-users" style="color:var(--primary-green);"></i> ${field.label}</div>
                            <div style="font-size:12px; font-weight:600; color:var(--btn-blue); background:rgba(59,130,246,0.1); padding:4px 10px; border-radius:6px; border:1px solid rgba(59,130,246,0.3);">System Locked Field</div>
                        </div>
                        <p style="font-size:13px; color:var(--text-light); margin-top:10px; margin-bottom:20px;">Determines how many members students can add to their group.</p>
                        
                        <div style="display:flex; gap:20px; background:var(--input-bg); padding:20px; border-radius:12px; border:1px solid var(--border-color);">
                            <div style="flex:1;">
                                <label style="font-size:12px; font-weight:600; color:var(--text-light); margin-bottom:8px; display:block;">Minimum Size</label>
                                <input type="number" name="min_team_size" value="${currentMin}" onchange="currentMin=this.value" min="1" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); outline:none; font-family:inherit; background:var(--card-bg); color:var(--text-dark);">
                            </div>
                            <div style="flex:1;">
                                <label style="font-size:12px; font-weight:600; color:var(--text-light); margin-bottom:8px; display:block;">Maximum Size</label>
                                <input type="number" name="max_team_size" value="${currentMax}" onchange="currentMax=this.value" min="1" style="width:100%; padding:12px; border-radius:8px; border:1px solid var(--border-color); outline:none; font-family:inherit; background:var(--card-bg); color:var(--text-dark);">
                            </div>
                        </div>
                    `;
                } else {
                    let optionsHtml = '';
                    if (['select', 'radio', 'checkbox'].includes(field.type)) {
                        let opts = (field.options || "Option 1").split(',');
                        let optIcon = field.type === 'radio' ? 'fa-regular fa-circle' : (field.type === 'checkbox' ? 'fa-regular fa-square' : 'fa-solid fa-list-ol');
                        
                        optionsHtml += `<div class="options-sortable-container" data-field-index="${index}">`;
                        opts.forEach((opt, oIdx) => {
                            optionsHtml += `
                                <div class="option-row" data-opt-index="${oIdx}">
                                    <i class="fa-solid fa-grip-vertical option-drag-handle" style="color:#9CA3AF; cursor:grab; margin-right:5px; font-size:12px;" title="Drag to reorder option"></i>
                                    <i class="option-icon ${optIcon}"></i>
                                    <input type="text" class="option-input" value="${opt.trim()}" onchange="updateOption(${index}, ${oIdx}, this.value)" placeholder="Option ${oIdx+1}">
                                    ${opts.length > 1 ? `<button type="button" class="btn-remove-opt" onclick="removeOption(${index}, ${oIdx})"><i class="fa-solid fa-xmark"></i></button>` : ''}
                                </div>
                            `;
                        });
                        optionsHtml += `</div>`;
                        optionsHtml += `
                            <div class="option-row" style="margin-top:10px;">
                                <i class="option-icon ${optIcon}" style="opacity:0.5; margin-left: 20px;"></i>
                                <button type="button" class="add-option-btn" onclick="addOption(${index})"><i class="fa-solid fa-plus"></i> Add option</button>
                            </div>
                        `;
                    } else if (field.type === 'textarea') {
                        optionsHtml = `
                        <div style="color:var(--text-light); font-size:14px; border-bottom:1px dashed var(--border-color); padding:10px 0; width:80%; margin-bottom:15px;">Long answer text</div>
                        `;
                    } else if (field.type === 'date') {
                        optionsHtml = `<div style="color:var(--text-light); font-size:14px; border-bottom:1px dashed var(--border-color); padding:10px 0; width:40%;"><i class="fa-regular fa-calendar" style="margin-right:10px;"></i> Month, Day, Year</div>`;
                    } else if (field.type === 'text') {
                        optionsHtml = `
                        <div style="color:var(--text-light); font-size:14px; border-bottom:1px dashed var(--border-color); padding:10px 0; width:50%; margin-bottom:15px;">Short answer text</div>
                        `;
                    } else {
                        optionsHtml = `<div style="color:var(--text-light); font-size:14px; border-bottom:1px dashed var(--border-color); padding:10px 0; width:50%;">Short answer text</div>`;
                    }

                    card.innerHTML = `
                        <div class="drag-handle" style="text-align:center; color:var(--text-light); cursor:grab; margin-top:-15px; margin-bottom:10px; font-size:18px;" title="Drag to reorder"><i class="fa-solid fa-grip-lines"></i></div>
                        <div class="q-header">
                            <input type="text" class="q-title-input" value="${field.label}" onchange="updateLabel(${index}, this.value)" placeholder="Question">
                            <select class="q-type-select" onchange="updateType(${index}, this.value)">
                                <option value="text" ${field.type=='text'?'selected':''}>Short answer</option>
                                <option value="textarea" ${field.type=='textarea'?'selected':''}>Paragraph</option>
                                <option value="radio" ${field.type=='radio'?'selected':''}>Multiple choice</option>
                                <option value="checkbox" ${field.type=='checkbox'?'selected':''}>Checkboxes</option>
                                <option value="select" ${field.type=='select'?'selected':''}>Dropdown</option>
                                <option value="date" ${field.type=='date'?'selected':''}>Date</option>
                            </select>
                        </div>
                        
                        <div style="margin-bottom: 20px; padding-left:5px;">
                            ${optionsHtml}
                        </div>

                        <div class="q-footer">
                            <button type="button" class="q-action-icon" title="Duplicate" onclick="duplicateField(${index})"><i class="fa-regular fa-copy"></i></button>
                            <button type="button" class="q-action-icon" title="Delete" onclick="deleteField(${index})"><i class="fa-regular fa-trash-can"></i></button>
                            <div style="width:1px; height:25px; background:var(--border-color); margin:0 10px;"></div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span style="font-size:13px; color:var(--text-dark); font-weight:600;">Required</span>
                                <label class="switch" style="transform:scale(0.8); transform-origin:left;">
                                    <input type="checkbox" onchange="updateRequired(${index}, this.checked)" ${field.required?'checked':''}>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    `;
                }

                canvas.appendChild(card);
            });

            if (sortableInstance) {
                sortableInstance.destroy();
            }
            sortableInstance = new Sortable(canvas, {
                animation: 150,
                easing: "cubic-bezier(0.25, 1, 0.5, 1)",
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                dragClass: 'sortable-drag',
                forceFallback: true,
                fallbackClass: 'sortable-drag',
                fallbackOnBody: true,
                scroll: true,
                scrollSensitivity: 100,
                scrollSpeed: 20,
                filter: '.system-locked',
                onMove: function (evt) {
                    return !evt.related.classList.contains('system-locked');
                },
                onEnd: function (evt) {
                    if (evt.oldIndex === evt.newIndex) return;
                    let movedItem = formFields.splice(evt.oldIndex, 1)[0];
                    formFields.splice(evt.newIndex, 0, movedItem);
                    activeIndex = evt.newIndex;
                    renderCanvas();
                }
            });

            // Initialize Sortable for options within each field
            document.querySelectorAll('.options-sortable-container').forEach(container => {
                new Sortable(container, {
                    animation: 150,
                    easing: "cubic-bezier(0.25, 1, 0.5, 1)",
                    handle: '.option-drag-handle',
                    ghostClass: 'sortable-ghost',
                    dragClass: 'sortable-drag',
                    forceFallback: true,
                    fallbackClass: 'sortable-drag',
                    fallbackOnBody: true,
                    onEnd: function(evt) {
                        if (evt.oldIndex === evt.newIndex) return;
                        
                        const fieldIndex = parseInt(container.dataset.fieldIndex);
                        let opts = formFields[fieldIndex].options.split(',');
                        
                        // Move array element
                        const movedOpt = opts.splice(evt.oldIndex, 1)[0];
                        opts.splice(evt.newIndex, 0, movedOpt);
                        
                        formFields[fieldIndex].options = opts.join(',');
                        // No need to call renderCanvas() here because the DOM is already updated by Sortable
                        // and we just updated the underlying data model!
                    }
                });
            });
        }

        function addNewField() {
            formFields.push({ id: 'f_' + Date.now(), label: 'Untitled Question', type: 'radio', options: 'Option 1', required: false });
            activeIndex = formFields.length - 1;
            renderCanvas();
            setTimeout(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }), 100);
        }

        function updateLabel(idx, val) { formFields[idx].label = val; }
        
        function updateType(idx, val) { 
            formFields[idx].type = val; 
            if(['select', 'radio', 'checkbox'].includes(val) && !formFields[idx].options) { formFields[idx].options = "Option 1"; }
            renderCanvas();
        }
        
        function updateRequired(idx, val) { formFields[idx].required = val; }
        
        function updateOption(fIdx, oIdx, val) {
            let opts = formFields[fIdx].options.split(',');
            opts[oIdx] = val.trim();
            formFields[fIdx].options = opts.join(',');
        }

        function addOption(fIdx) {
            let opts = formFields[fIdx].options ? formFields[fIdx].options.split(',') : [];
            opts.push(`Option ${opts.length + 1}`);
            formFields[fIdx].options = opts.join(',');
            renderCanvas();
        }

        function removeOption(fIdx, oIdx) {
            let opts = formFields[fIdx].options.split(',');
            opts.splice(oIdx, 1);
            formFields[fIdx].options = opts.join(',');
            renderCanvas();
        }

        function deleteField(idx) {
            formFields.splice(idx, 1);
            activeIndex = null;
            renderCanvas();
        }

        function duplicateField(idx) {
            let copy = JSON.parse(JSON.stringify(formFields[idx]));
            copy.id = 'f_' + Date.now();
            formFields.splice(idx + 1, 0, copy);
            activeIndex = idx + 1;
            renderCanvas();
        }

        function saveGoogleForm() {
            // Check for duplicate labels
            const labels = formFields.map(f => f.label.trim().toLowerCase());
            const duplicates = labels.filter((item, index) => labels.indexOf(item) !== index);
            
            if (duplicates.length > 0) {
                alert(`Error: Duplicate labels found: "${duplicates[0]}". Each field must have a unique label as it is used for database identification.`);
                return;
            }

            document.getElementById('schema_output').value = JSON.stringify(formFields);
            document.getElementById('masterForm').submit();
        }

        function openPreviewModal() {
            const previewContainer = document.getElementById('previewCanvas');
            let minS = document.querySelector('input[name="min_team_size"]').value;
            let maxS = document.querySelector('input[name="max_team_size"]').value;
            let html = `
                <div style="border-top: 10px solid var(--primary-green); background:var(--card-bg); border-radius:16px; padding:30px; margin-bottom:20px; box-shadow:var(--shadow); border-left:1px solid var(--border-color); border-right:1px solid var(--border-color); border-bottom:1px solid var(--border-color);">
                    <h1 style="font-size:26px; color:var(--text-dark); margin-bottom:5px;">Project Registration Form (${'<?php echo htmlspecialchars($selected_year); ?>'} - Sem ${'<?php echo $selected_sem; ?>'})</h1>
                    <p style="color:var(--text-light); font-size:14px; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:15px;">Required fields are marked with <span style="color:#EF4444;">*</span></p>
                </div>
            `;

            formFields.forEach(field => {
                let reqMark = field.required ? '<span style="color:#EF4444;">*</span>' : '';
                
                if (field.type === 'team-members') {
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label"><i class="fa-solid fa-users" style="color:var(--primary-green); margin-right:8px;"></i> ${field.label} ${reqMark} <span style="font-weight:400; font-size:12px; color:var(--text-light);">(Min: ${minS}, Max: ${maxS})</span></label>
                            <div style="background:var(--input-bg); padding:15px; border-radius:8px; border:1px solid var(--border-color); display:flex; align-items:center; gap:10px; margin-bottom:10px;">
                                <input type="radio" checked> 
                                <input type="text" value="Logged-in Student Name" readonly style="flex:1; border:none; background:transparent; font-weight:600; outline:none; color:var(--primary-green);">
                            </div>
                            <button type="button" style="background:var(--card-bg); border:1px dashed var(--primary-green); color:var(--primary-green); padding:10px 15px; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer;"><i class="fa-solid fa-plus"></i> Add Another Member</button>
                        </div>
                    `;
                } else if (field.type === 'text') {
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label">${field.label} ${reqMark}</label>
                        <input type="text" class="preview-input" placeholder="Your answer">
                        </div>
                    `;
                } else if (field.type === 'textarea') {
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label">${field.label} ${reqMark}</label>
                        <textarea class="preview-input" rows="4" placeholder="Your answer"></textarea>
                        </div>
                    `;
                } else if (field.type === 'date') {
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label">${field.label} ${reqMark}</label>
                            <input type="date" class="preview-input" style="width:auto;">
                        </div>
                    `;
                } else if (field.type === 'select') {
                    let opts = (field.options || "").split(',');
                    let optHtml = '<option value="">Choose</option>';
                    opts.forEach(opt => optHtml += `<option>${opt.trim()}</option>`);
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label">${field.label} ${reqMark}</label>
                            <select class="preview-input" style="width:auto; min-width:200px;">${optHtml}</select>
                        </div>
                    `;
                } else if (field.type === 'radio' || field.type === 'checkbox') {
                    let opts = (field.options || "").split(',');
                    let inputType = field.type;
                    let rHtml = `<div class="preview-radio-container">`;
                    opts.forEach(opt => {
                        rHtml += `<label class="preview-radio-label"><input type="${inputType}" name="preview_${field.id}"> ${opt.trim()}</label>`;
                    });
                    rHtml += `</div>`;
                    
                    html += `
                        <div class="preview-form-group">
                            <label class="preview-label">${field.label} ${reqMark}</label>
                            ${rHtml}
                        </div>
                    `;
                }
            });

            html += `<button type="button" class="btn-publish" style="margin-top:10px;">Submit Form</button>`;

            previewContainer.innerHTML = html;
            document.getElementById('previewModal').style.display = 'flex';
        }

        renderCanvas();

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
                renderCanvas(); // re-render the form
                
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
    <script src="assets/js/ajax-forms.js?v=1.1"></script>
</body>
</html>
