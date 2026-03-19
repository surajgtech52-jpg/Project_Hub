<?php
session_start();
require_once 'bootstrap.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] != 'head') { header("Location: index.php"); exit(); }

$head_name = $_SESSION['name'];
$head_id = $_SESSION['user_id'];
$assigned_year = $conn->query("SELECT assigned_year FROM head WHERE id = $head_id")->fetch_assoc()['assigned_year'];
$msg = "";

// --- 1. HANDLE ADD STUDENT ---
if (isset($_POST['add_student'])) {
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $pass = $conn->real_escape_string($_POST['password']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $div = $conn->real_escape_string($_POST['division']);
    $phone = $conn->real_escape_string($_POST['phone_number']);

    if (moodle_id_in_use($conn, $m_id)) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID '$m_id' is already registered in the system!</div>";
    } else {
        $sql = "INSERT INTO student (moodle_id, password, full_name, academic_year, division, phone_number, status) VALUES ('$m_id', '$pass', '$name', '$assigned_year', '$div', '$phone', 'Active')";
        if ($conn->query($sql) === TRUE) $msg = "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> Student added successfully!</div>";
    }
}

// --- 2. HANDLE EDIT STUDENT ---
if (isset($_POST['edit_student'])) {
    $id = $_POST['student_id'];
    $m_id = $conn->real_escape_string($_POST['moodle_id']);
    $name = $conn->real_escape_string($_POST['full_name']);
    $div = $conn->real_escape_string($_POST['division']);
    $phone = $conn->real_escape_string($_POST['phone_number']);
    $status = $conn->real_escape_string($_POST['status']);

    $id_int = (int)$id;
    $current = $conn->query("SELECT moodle_id FROM student WHERE id = $id_int")->fetch_assoc();
    $current_mid = $current ? $current['moodle_id'] : null;
    if ($current_mid !== null && $m_id !== $current_mid) {
        $msg = "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> Moodle ID cannot be changed once created (it breaks group membership). Create a new record instead.</div>";
    } else {
        $sql = "UPDATE student SET full_name='$name', division='$div', phone_number='$phone', status='$status' WHERE id=$id_int";
        if ($conn->query($sql) === TRUE) $msg = "<div class='alert-success'><i class='fa-solid fa-pen'></i> Student info updated!</div>";
    }
}

// FETCH STUDENTS (Hiding "Disabled"/Passed-out students, and fetching final topic data)
$md_sql = project_member_details_sql('p');
$sql = "SELECT s.*, p.id as project_id, p.group_name, p.is_locked, p.final_topic
        FROM student s
        LEFT JOIN project_members pm ON pm.student_id = s.id
        LEFT JOIN projects p ON (p.id = pm.project_id AND p.is_archived = 0 AND p.project_year = '$assigned_year')
        WHERE s.academic_year = '$assigned_year' AND (s.status = 'Active' OR s.status IS NULL)
        ORDER BY s.division, s.full_name";
$students = $conn->query($sql);

// SAFELY INITIALIZE AND FETCH GROUP DATA
$group_data = []; 
$groups = $conn->query("SELECT p.*, ($md_sql) as member_details, g.full_name as guide_name FROM projects p LEFT JOIN guide g ON p.assigned_guide_id = g.id WHERE p.project_year = '$assigned_year' AND p.is_archived = 0");

if ($groups) {
    while($g = $groups->fetch_assoc()) { 
        $group_data[$g['id']] = $g; 
    }
}
$group_json = json_encode($group_data);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - Head Dashboard</title>
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
        <a href="head_dashboard.php?tab=dashboard" class="nav-link"><i class="fa-solid fa-layer-group"></i> Dashboard</a>
        <a href="head_dashboard.php?tab=history" class="nav-link"><i class="fa-solid fa-clock-rotate-left"></i> History Vault</a>
        <a href="head_dashboard.php?tab=resets" class="nav-link"><i class="fa-solid fa-unlock-keyhole"></i> Password Resets</a>

        <div class="menu-label">Other Tools</div>
        <a href="head_students.php" class="nav-link active"><i class="fa-solid fa-users"></i> Manage Students</a>
        <a href="head_guides.php" class="nav-link"><i class="fa-solid fa-chalkboard-user"></i> View Guides</a>
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
                        <h2 style="font-size: 20px; color: var(--text-dark); margin:0;">Manage Students</h2>
                        <p style="font-size: 13px; color: var(--text-light); margin:0;"><?php echo htmlspecialchars($assigned_year); ?> Student Directory</p>
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
                    <input type="text" id="liveSearchInput" placeholder="Search by Moodle ID or Name..." onkeyup="liveSearch()">
                    <i class="fa-solid fa-search"></i>
                </div>
                <button class="btn-add" onclick="openSimpleModal('addStudentModal')"><i class="fa-solid fa-user-plus"></i> Add New Student</button>
            </div>

            <div class="card" style="padding:0; overflow:hidden;">
                <table id="studentsTable">
                    <thead>
                        <tr style="background:var(--input-bg);">
                            <th>Student Details</th>
                            <th>Div / Contact</th>
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
                                <span style="font-size:14px; font-weight: 600; color: var(--text-dark);">Div <?php echo htmlspecialchars($row['division']); ?></span><br>
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
                                <button class="btn-edit" onclick="openEditModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['moodle_id']); ?>', '<?php echo htmlspecialchars($row['full_name']); ?>', '<?php echo htmlspecialchars($row['division']); ?>', '<?php echo htmlspecialchars($row['phone_number']); ?>', '<?php echo htmlspecialchars($row['status']); ?>')">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="4" style="text-align: center; color: var(--text-light); padding: 40px;"><i class="fa-solid fa-users-slash" style="font-size:40px; margin-bottom:15px; color:var(--border-color);"></i><br>No active students found for this year.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="addStudentModal" class="modal-overlay" style="z-index:3000;">
        <div class="modal-card modal-card-small">
            <div style="display:flex; justify-content:space-between; margin-bottom:15px; font-weight:bold; font-size:16px; color:var(--text-dark);">
                Add New Student (<?php echo htmlspecialchars($assigned_year); ?>)
                <button type="button" onclick="closeSimpleModal('addStudentModal')" style="border:none; background:none; cursor:pointer; color:var(--text-light);"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form method="POST">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" required></div>
                <div class="form-group"><label>Password</label><input type="text" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group">
                    <label>Division</label>
                    <select name="division" required>
                        <option value="A">Division A</option><option value="B">Division B</option><option value="C">Division C</option>
                    </select>
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
            <form method="POST">
                <input type="hidden" name="student_id" id="edit_id">
                <div class="form-group"><label>Moodle ID</label><input type="text" name="moodle_id" id="edit_moodle" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" id="edit_name" required></div>
                <div class="form-group">
                    <label>Division</label>
                    <select name="division" id="edit_div" required>
                        <option value="A">Division A</option><option value="B">Division B</option><option value="C">Division C</option>
                    </select>
                </div>
                <div class="form-group"><label>Phone Number</label><input type="text" name="phone_number" id="edit_phone"></div>
                <div class="form-group">
                    <label>Account Status</label>
                    <select name="status" id="edit_status" required>
                        <option value="Active">Active</option><option value="Disabled">Disabled</option>
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

        function openEditModal(id, moodle, name, div, phone, status) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_moodle').value = moodle;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_div').value = div;
            document.getElementById('edit_phone').value = phone;
            document.getElementById('edit_status').value = status || 'Active';
            openSimpleModal('editStudentModal');
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