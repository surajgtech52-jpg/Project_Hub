<?php
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'head') { header("Location: index.php"); exit(); }

$head_name = $_SESSION['name'];
$head_id = $_SESSION['user_id'];
$stmt_h = $conn->prepare("SELECT assigned_year FROM head WHERE id = ?");
$stmt_h->bind_param("i", $head_id);
$stmt_h->execute();
$assigned_year = $stmt_h->get_result()->fetch_assoc()['assigned_year'];
$stmt_h->close();

$msg = "";

// --- 1. HANDLE ADD GUIDE ---
if (isset($_POST['add_guide'])) {
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $pass = $_POST['password'];
    $name = $conn->real_escape_string($_POST['full_name']);
    $contact = $conn->real_escape_string($_POST['contact_number']);

    if (moodle_id_in_use($conn, $m_id)) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID '$m_id' is already registered in the system!</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', "Moodle ID '$m_id' is already registered!");
    } else {
        $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO guide (moodle_id, password, full_name, contact_number, status) VALUES (?, ?, ?, ?, 'Active')");
        $stmt->bind_param("ssss", $m_id, $hashed_pass, $name, $contact);
        if ($stmt->execute()) {
            $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Guide added successfully!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Guide added successfully!', ['callback' => 'refreshDashboard', 'closeModal' => 'addGuideModal']);
        }
        $stmt->close();
    }
}


// --- 2. HANDLE EDIT GUIDE ---
if (isset($_POST['edit_guide'])) {
    $id = $_POST['guide_id'];
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $contact = $conn->real_escape_string($_POST['contact_number']);

    $id_int = (int)$id;
    $stmt_c = $conn->prepare("SELECT moodle_id FROM guide WHERE id = ?");
    $stmt_c->bind_param("i", $id_int);
    $stmt_c->execute();
    $current = $stmt_c->get_result()->fetch_assoc();
    $stmt_c->close();
    $current_mid = $current ? $current['moodle_id'] : null;

    if ($current_mid !== null && $m_id !== $current_mid) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID cannot be changed once created. Create a new record instead.</div>";
        if (isset($_POST['is_ajax'])) send_ajax_response('error', 'Moodle ID cannot be changed once created.');
    } else {
        $stmt = $conn->prepare("UPDATE guide SET full_name=?, contact_number=? WHERE id=?");
        $stmt->bind_param("ssi", $name, $contact, $id_int);
        
        if ($stmt->execute()) {
            // If this person is also a Head (same moodle_id), keep profile in sync.
            $mid_for_sync = $current_mid ?? $m_id;
            $stmt_sync = $conn->prepare("UPDATE head SET full_name=?, contact_number=? WHERE moodle_id=?");
            $stmt_sync->bind_param("sss", $name, $contact, $mid_for_sync);
            $stmt_sync->execute();
            $stmt_sync->close();

            $msg = "<div class='alert-success'><i class='fa-solid fa-pen'></i> Guide info updated!</div>";
            if (isset($_POST['is_ajax'])) send_ajax_response('success', 'Guide info updated!', ['callback' => 'refreshDashboard', 'closeModal' => 'editGuideModal']);
        }
        $stmt->close();
    }
}


// FETCH GUIDES
$sql = "SELECT * FROM guide WHERE deleted_at IS NULL ORDER BY full_name";
$guides = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Guides - Head Dashboard</title>
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

        .search-bar-container { display:flex; gap:15px; margin-bottom: 20px; }
        .smart-search { flex:1; position:relative; }
        .smart-search input { width:100%; border:1px solid var(--border-color); border-radius:16px; padding:15px 15px 15px 45px; font-size:14px; outline:none; transition:0.3s; background:var(--card-bg); color:var(--text-dark);}
        .smart-search input:focus { border-color:var(--primary-green); box-shadow:0 0 0 4px rgba(16,93,63,0.1); }
        .smart-search i { position:absolute; left:18px; top:16px; color:var(--text-light); font-size:16px; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(4px);}
        .modal-card { background: var(--card-bg); padding: 30px; border-radius: 24px; width: 100%; max-width: 500px; display:flex; flex-direction:column; max-height:90vh; overflow-y:auto;}
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; color: var(--text-light); margin-bottom: 5px; font-weight:600;}
        .form-group input { width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 13px; outline:none; background:var(--input-bg); color:var(--text-dark); font-family:inherit;}
        .form-group input:focus { background: var(--card-bg); border-color: var(--primary-green); }

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
        <a href="head_dashboard.php?tab=dashboard" class="nav-link"><i class="fa-solid fa-layer-group"></i> Dashboard</a>
        <a href="head_dashboard.php?tab=history" class="nav-link"><i class="fa-solid fa-vault"></i> History Vault</a>
        <a href="head_dashboard.php?tab=workspace" class="nav-link"><i class="fa-solid fa-briefcase"></i> Manage Workspace</a>
        <a href="head_dashboard.php?tab=resets" class="nav-link"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>
        <a href="head_dashboard.php?tab=logs" class="nav-link"><i class="fa-solid fa-book"></i> Project Logs</a>
        <a href="head_dashboard.php?tab=exports" class="nav-link"><i class="fa-solid fa-file-excel"></i> Export Hub</a>
        <div class="menu-label">System Tools</div>
        <a href="head_students.php" class="nav-link"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="head_guides.php" class="nav-link active"><i class="fa-solid fa-chalkboard-user"></i> View Guides</a>
        <a href="head_form_settings.php" class="nav-link"><i class="fa-brands fa-google"></i> Form Builder</a>
        
        <div class="menu-label">Account</div>
        <a href="head_dashboard.php?tab=settings" class="nav-link"><i class="fa-solid fa-key"></i> Change Password</a>
        <a href="logout.php" class="nav-link logout-btn"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </div>

    <div class="main-content">
        <div class="top-navbar">
            <div class="top-navbar-inner">
                <div class="top-navbar-left">
                    <button class="mobile-menu-btn" onclick="toggleMobileMenu()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Manage Guides</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;">View all guides in the system</p>
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

        <div class="dashboard-canvas" id="canvas-area">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:15px;">
                <div class="smart-search" style="flex:1; min-width:300px;">
                    <input type="text" id="liveSearchInput" placeholder="Search Guides by ID or Name..." onkeyup="liveSearch()">
                    <i class="fa-solid fa-search"></i>
                </div>
                <button class="btn-add" onclick="openSimpleModal('addGuideModal')"><i class="fa-solid fa-user-plus"></i> Add New Guide</button>
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
                        <tr class="guide-row">
                            <td style="padding:15px;">
                                <strong style="color:var(--primary-green); font-size:15px;"><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                <span style="font-size:12px; color:var(--text-light); font-weight:600;">ID: <?php echo htmlspecialchars($row['moodle_id']); ?></span>
                            </td>
                            <td style="padding:15px;">
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['status'] ?? 'Active'); ?></span><br>
                                <span style="font-size:12px; color: var(--text-light);"><i class="fa-solid fa-phone" style="font-size:10px;"></i> <?php echo htmlspecialchars($row['contact_number'] ?? 'N/A'); ?></span>
                            </td>
                            <td style="text-align:center; padding:15px; vertical-align:middle;">
                                <button class="btn-edit" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['moodle_id']); ?>', '<?php echo htmlspecialchars($row['full_name']); ?>', '<?php echo htmlspecialchars($row['contact_number']); ?>')">
                                    <i class="fa-solid fa-pen"></i> Edit Info
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="3" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-user-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No guides registered in the system.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="addGuideModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Add New Guide
                <button type="button" onclick="closeSimpleModal('addGuideModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" required></div>
                <div class="form-group"><label>Temporary Password</label><input type="text" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Contact Number (Optional)</label><input type="text" name="contact_number"></div>
                <button type="submit" name="add_guide" class="btn-add" style="width:100%; justify-content:center;">Register Guide</button>
            </form>
        </div>
    </div>

    <div id="editGuideModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Edit Guide Info 
                <button type="button" onclick="closeSimpleModal('editGuideModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST" class="ajax-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="guide_id" id="edit_id">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" id="edit_moodle" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_name" required></div>
                <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" id="edit_contact"></div>
                <button type="submit" name="edit_guide" class="btn-add" style="width:100%; justify-content:center;">Update Info</button>
            </form>
        </div>
    </div>

    <script>
        function openSimpleModal(id) { document.getElementById(id).style.display = 'flex'; }
        function closeSimpleModal(id) { document.getElementById(id).style.display = 'none'; }

        function openEditModal(id, moodle, name, contact) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_moodle').value = moodle;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_contact').value = contact;
            openSimpleModal('editGuideModal');
        }

        function liveSearch() {
            let input = document.getElementById('liveSearchInput').value.toLowerCase().trim();
            document.querySelectorAll('.guide-row').forEach(row => {
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
