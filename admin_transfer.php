<?php
session_start();
require_once 'bootstrap.php';

// Strict security check
if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') { header("Location: index.php"); exit(); }

$admin_name = $_SESSION['name'];
$msg = "";
$active_tab = "transfer";

// ==============================================
//        EXECUTE YEAR PROMOTION (TRANSFER)
// ==============================================
if (isset($_POST['execute_transfer'])) {
    verify_csrf_token();
    $session_name = $conn->real_escape_string($_POST['session_name']);
    $admin_pass = $_POST['admin_password'] ?? '';
    
    if ($admin_pass !== 'qwerty') {
        $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> <strong>Error:</strong> Incorrect password! Promotion aborted.</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', 'Incorrect password! Promotion aborted.');
    } else {
        // Safety check: Ensure we aren't double-clicking by checking if there are actually active students/projects to promote
        $check_active = $conn->query("SELECT id FROM student WHERE status = 'Active' OR status IS NULL LIMIT 1");
        
        if ($check_active->num_rows > 0) {
            // 1. ARCHIVE ALL CURRENT PROJECTS
            // This instantly removes them from the 'Active Dashboard' and sends them to the 'History Vault'
            $conn->query("UPDATE projects SET academic_session = '$session_name', is_archived = 1 WHERE is_archived = 0");
            
            // 1.1 ARCHIVE FORM SETTINGS & UPLOAD REQUESTS
            // Tag current 'Current' configurations with the session name being archived
            $conn->query("UPDATE form_settings SET academic_session = '$session_name', is_form_open = 0 WHERE academic_session = 'Current'");
            $conn->query("UPDATE upload_requests SET academic_session = '$session_name' WHERE academic_session = 'Current'");
            
            // 1.2 INITIALIZE NEW 'CURRENT' SETTINGS FOR NEXT SESSION
            // We clone the archived settings back to 'Current' so work can continue seamlessly in the new session
            $conn->query("INSERT INTO form_settings (academic_year, semester, form_schema, is_form_open, min_team_size, max_team_size, deadline_date, academic_session)
                          SELECT academic_year, semester, form_schema, 1, min_team_size, max_team_size, deadline_date, 'Current' 
                          FROM form_settings WHERE academic_session = '$session_name'");
            
            // Note: Global upload requests stay global, project-specific ones are already linked to archived projects.
            // We clone global templates if any exist for 'Current'
            $conn->query("INSERT INTO upload_requests (guide_id, project_id, folder_name, instructions, is_global, target_year, academic_year, semester, created_by_role, created_by_id, academic_session)
                          SELECT guide_id, project_id, folder_name, instructions, is_global, target_year, academic_year, semester, created_by_role, created_by_id, 'Current'
                          FROM upload_requests WHERE academic_session = '$session_name' AND is_global = 1");
            
            // 2. SAVE STUDENT SNAPSHOT
            // Records exactly which year/div/semester the student was in during this past session
            $conn->query("INSERT INTO student_history (student_id, moodle_id, academic_year, division, semester, academic_session)
                          SELECT id, moodle_id, academic_year, division, current_semester, '$session_name' FROM student WHERE status = 'Active' OR status IS NULL");
            
            // 3. PROMOTE STUDENTS (Top-Down execution to prevent cascading errors)
            
            // Step A: Current Sem 8 students graduate (BE Sem 8 -> Graduate)
            $conn->query("UPDATE student SET status = 'Disabled' WHERE current_semester = 8 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            // Step B: Sem 7 -> Sem 8 (Stay in BE)
            $conn->query("UPDATE student SET current_semester = 8 WHERE current_semester = 7 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            // Step C: Sem 6 -> Sem 7 (TE -> BE)
            $conn->query("UPDATE student SET academic_year = 'BE', current_semester = 7 WHERE current_semester = 6 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            // Step D: Sem 5 -> Sem 6 (Stay in TE)
            $conn->query("UPDATE student SET current_semester = 6 WHERE current_semester = 5 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            // Step E: Sem 4 -> Sem 5 (SE -> TE)
            $conn->query("UPDATE student SET academic_year = 'TE', current_semester = 5 WHERE current_semester = 4 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            // Step F: Sem 3 -> Sem 4 (Stay in SE)
            $conn->query("UPDATE student SET current_semester = 4 WHERE current_semester = 3 AND (status = 'Active' OR status IS NULL) AND deleted_at IS NULL");
            
            $msg = "<div id='alertMsg' class='alert-success'><i class='fa-solid fa-circle-check'></i> <strong>Success:</strong> Semester Promotion executed for session <strong>$session_name</strong>! All groups archived and students promoted to the next semester.</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', "Semester Promotion executed for session $session_name!", ['reload' => true]);
        } else {
            $msg = "<div id='alertMsg' class='alert-error'><i class='fa-solid fa-triangle-exclamation'></i> <strong>Error:</strong> No active students found to promote!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('error', 'No active students found to promote!');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Promotion - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* === THEME VARIABLES === */
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

        .alert-success { background: #D1FAE5; color: #065F46; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #A7F3D0;}
        .alert-error { background: #FEE2E2; color: #991B1B; padding: 15px 20px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; font-weight: 500; border: 1px solid #FECACA;}

        .card { background: var(--card-bg); border-radius: 24px; padding: 40px; box-shadow: var(--shadow); margin-bottom: 20px; text-align:center; max-width:600px; margin:40px auto; border:1px solid var(--border-color);}
        
        .input-box { width: 100%; padding: 15px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 15px; outline:none; background:var(--input-bg); color:var(--text-dark); font-family:inherit; transition:0.3s;}
        .input-box:focus { background: var(--card-bg); border-color: #EF4444; box-shadow: 0 0 0 4px rgba(239,68,68,0.1); }

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
            
            .card { padding: 25px 20px; border-radius: 16px; margin: 20px auto; width:100%;}
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
        <a href="admin_form_settings.php" class="nav-link"><i class="fa-brands fa-google"></i> Form Builder</a>
        <a href="admin_transfer.php" class="nav-link active"><i class="fa-solid fa-arrow-right-arrow-left"></i> Sem Promotion</a>
        
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
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Semester Promotion</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">Execute Semester Transitions</p>
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
            <div class="card">
                <div style="background:rgba(239,68,68,0.1); width:90px; height:90px; border-radius:50%; display:flex; justify-content:center; align-items:center; margin: 0 auto 20px auto;">
                    <i class="fa-solid fa-graduation-cap" style="font-size: 40px; color: #EF4444;"></i>
                </div>
                <h3 style="font-size: 24px; margin-bottom: 10px; color:var(--text-dark);">Execute Semester Promotion</h3>
                <p style="color: var(--text-light); font-size: 14px; margin-bottom: 30px; line-height: 1.6;">
                    Executing this will <b>permanently archive</b> all current projects, files, and forms to the History Vault. 
                    <br>Students will be promoted to the next semester (e.g., Sem 3 to Sem 4). 
                    Year changes (SE to TE, TE to BE) happen automatically at appropriate semester transitions. 
                    Sem 8 students will graduate and lose login access.
                </p>

                <form method="POST" class="ajax-form" onsubmit="return confirm('WARNING: This action is permanent! Are you absolutely sure you want to promote all students and archive all projects?');">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div style="text-align:left; margin-bottom:15px;">
                        <label style="font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px; display:block;">Label for New Archive (e.g., 2025-2026)</label>
                        <input type="text" name="session_name" placeholder="Enter session label..." required class="input-box">
                    </div>
                    <div style="text-align:left; margin-bottom:20px;">
                        <label style="font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:8px; display:block;">Admin Password Verification</label>
                        <input type="password" name="admin_password" placeholder="Enter password to confirm..." required class="input-box">
                    </div>
                    <button type="submit" name="execute_transfer" style="background:#EF4444; color:white; border:none; padding:15px 30px; border-radius:12px; font-size:16px; font-weight:600; cursor:pointer; width:100%; transition:0.3s; display:flex; justify-content:center; align-items:center; gap:10px;"><i class="fa-solid fa-bolt"></i> Execute Global Transfer</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Auto-fade alerts
        setTimeout(() => { 
            let a = document.getElementById('alertBox'); 
            if(a && a.innerHTML.trim() !== "") { 
                a.style.transition = "opacity 0.5s"; 
                a.style.opacity = "0"; 
                setTimeout(()=>a.style.display="none", 500); 
            } 
        }, 4000);

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
                alertBox.innerHTML = "<div class='alert-success' style='padding:12px 20px;'><i class='fa-solid fa-check-circle'></i> UI refreshed successfully!</div>";
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';
                setTimeout(() => { alertBox.style.opacity = "0"; setTimeout(()=>alertBox.style.display="none", 500); }, 3000);
            } catch (error) { console.error('Refresh failed:', error); } 
            finally { icon.classList.remove('fa-spin'); }
        }
    </script>
    <script src="assets/js/ajax-forms.js?v=1.1"></script>
</body>
</html>
