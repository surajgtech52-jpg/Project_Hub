<?php
session_start();
require_once 'bootstrap.php';

// ==========================================
//   AJAX ENDPOINT: LIVE USER CHECK FOR RESET
// ==========================================
if (isset($_GET['check_user'])) {
    $role = $conn->real_escape_string($_GET['role']);
    $moodle_id = $conn->real_escape_string($_GET['moodle_id']);
    $table = in_array($role, ['student', 'guide', 'head']) ? $role : '';
    
    if($table) {
        $q = $conn->query("SELECT full_name FROM $table WHERE moodle_id='$moodle_id' AND deleted_at IS NULL");
        if($q && $q->num_rows > 0) {
            echo json_encode(['status'=>'success', 'name'=>$q->fetch_assoc()['full_name']]);
            exit();
        }
    }
    echo json_encode(['status'=>'error']);
    exit();
}

$active_session_name = "";
$active_session_dashboard = "";
if (isset($_SESSION['role'])) {
    $active_session_name = $_SESSION['name'] ?? "User";
    if ($_SESSION['role'] == 'admin') $active_session_dashboard = "admin_dashboard.php";
    elseif ($_SESSION['role'] == 'head') $active_session_dashboard = "head_dashboard.php";
    elseif ($_SESSION['role'] == 'guide') $active_session_dashboard = "guide_dashboard.php";
    elseif ($_SESSION['role'] == 'student') $active_session_dashboard = "student_dashboard.php";
}

$error = "";
$success = "";

// ==========================================
//          HANDLE LOGIN
// ==========================================
if (isset($_POST['login_submit'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = (string)($_POST['password'] ?? '');
    $role = $_POST['role'];

    if ($role == 'admin') {
        $stmt = $conn->prepare("SELECT id, moodle_id, password, full_name FROM admin WHERE moodle_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!verify_and_upgrade_password($conn, 'admin', (int)$row['id'], $password, (string)$row['password'])) {
                $error = "Invalid Admin credentials.";
            } else {
            $_SESSION['role'] = 'admin';
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['name'] = $row['full_name'] ?? "Administrator";
            header("Location: admin_dashboard.php");
            exit();
            }
        } else {
            $error = "Invalid Admin credentials.";
        }
    } 
    elseif ($role == 'head') {
        $stmt = $conn->prepare("SELECT id, moodle_id, password, full_name, status FROM head WHERE moodle_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!verify_and_upgrade_password($conn, 'head', (int)$row['id'], $password, (string)$row['password'])) {
                $error = "Invalid Head credentials.";
            } else {
            
            // STRICT CHECK: Ensure Head is Active
            if ($row['status'] == 'Active' || empty($row['status'])) {
                $_SESSION['role'] = 'head';
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['name'] = $row['full_name'];
                header("Location: head_dashboard.php");
                exit();
            } else {
                $error = "Access Denied: Your Head account has been removed from the active roster.";
            }
            }
        } else {
            $error = "Invalid Head credentials.";
        }
    } 
    elseif ($role == 'guide') {
        $stmt = $conn->prepare("SELECT id, moodle_id, password, full_name, status FROM guide WHERE moodle_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!verify_and_upgrade_password($conn, 'guide', (int)$row['id'], $password, (string)$row['password'])) {
                $error = "Invalid Guide credentials.";
            } else {
            
            // STRICT CHECK: Ensure Guide is Active
            if ($row['status'] == 'Active' || empty($row['status'])) {
                $_SESSION['role'] = 'guide';
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['name'] = $row['full_name'];
                header("Location: guide_dashboard.php");
                exit();
            } else {
                $error = "Access Denied: Your Guide account has been removed from the active roster.";
            }
            }
        } else {
            $error = "Invalid Guide credentials.";
        }
    } 
    elseif ($role == 'student') {
        $stmt = $conn->prepare("SELECT id, moodle_id, password, full_name, status FROM student WHERE moodle_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            if (!verify_and_upgrade_password($conn, 'student', (int)$row['id'], $password, (string)$row['password'])) {
                $error = "Invalid Student credentials.";
            } else {
            
            // STRICT CHECK: Ensure Student is Active
            if ($row['status'] == 'Active' || empty($row['status'])) {
                $_SESSION['role'] = 'student';
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['name'] = $row['full_name'];
                header("Location: student_dashboard.php");
                exit();
            } else {
                $error = "Access Denied: Your account is currently marked as " . strtoupper($row['status']) . ".";
            }
            }
        } else {
            $error = "Invalid Student credentials.";
        }
    }
}

// ==========================================
//      HANDLE PASSWORD RESET REQUEST
// ==========================================
if (isset($_POST['request_reset'])) {
    $r_role = $conn->real_escape_string($_POST['reset_role']);
    $r_moodle = $conn->real_escape_string($_POST['reset_moodle_id']);
    
    // Double-check backend to ensure user exists
    $table = in_array($r_role, ['student', 'guide', 'head']) ? $r_role : '';
    if ($table) {
        $user_check = $conn->query("SELECT id FROM $table WHERE moodle_id='$r_moodle' AND deleted_at IS NULL");
        if ($user_check && $user_check->num_rows > 0) {
            
            // Check if there is already a pending request
            $check = $conn->query("SELECT id FROM password_reset_requests WHERE moodle_id='$r_moodle' AND status='Pending'");
            if ($check && $check->num_rows > 0) {
                $error = "A password reset request is already pending for this ID.";
            } else {
                $conn->query("INSERT INTO password_reset_requests (role, moodle_id, status) VALUES ('$r_role', '$r_moodle', 'Pending')");
                $success = "Password reset request submitted successfully. Please contact your Admin for the new password.";
            }
            
        } else {
            $error = "Reset Failed: No account found with that Moodle ID in the selected role.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Hub - Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-green: #105D3F; --bg-color: #F3F4F6; --text-dark: #1F2937; --text-light: #9CA3AF; --card-bg: #FFFFFF; --input-bg: #F9FAFB; --border-color: #E5E7EB; --active-bg: #E8F5E9; --modal-bg: rgba(0,0,0,0.5); }
        [data-theme="dark"] { --primary-green: #34D399; --bg-color: #111827; --text-dark: #F9FAFB; --text-light: #9CA3AF; --card-bg: #1F2937; --input-bg: #374151; --border-color: #4B5563; --active-bg: rgba(52, 211, 153, 0.1); --modal-bg: rgba(0,0,0,0.8); }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; transition: background-color 0.3s, color 0.3s, border-color 0.3s; }
        body { background-color: var(--bg-color); height: 100vh; display: flex; justify-content: center; align-items: center; }
        
        .login-container { background: var(--card-bg); padding: 40px; border-radius: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); width: 100%; max-width: 450px; text-align: center; }
        .brand { font-size: 28px; font-weight: 700; color: var(--primary-green); margin-bottom: 10px; display: flex; justify-content: center; align-items: center; gap: 10px; }
        .subtitle { font-size: 14px; color: var(--text-light); margin-bottom: 30px; }
        
        .role-selector { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 25px; }
        .role-card { background: var(--input-bg); border: 2px solid transparent; padding: 15px 10px; border-radius: 16px; cursor: pointer; transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 8px; color: var(--text-light); font-size: 13px; font-weight: 600; }
        .role-card i { font-size: 20px; }
        .role-card:hover { background: var(--border-color); }
        .role-card.active { border-color: var(--primary-green); color: var(--primary-green); background: var(--active-bg); }

        .input-group { position: relative; margin-bottom: 20px; text-align: left;}
        .input-group input, .input-group select { width: 100%; padding: 15px 15px 15px 45px; border: 1px solid var(--border-color); border-radius: 12px; font-size: 14px; outline: none; transition: 0.3s; background: var(--input-bg); color: var(--text-dark); font-family: inherit;}
        .input-group input:focus, .input-group select:focus { border-color: var(--primary-green); background: var(--card-bg); }
        .input-group i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-light); }

        .btn-login { background: var(--primary-green); color: white; border: none; padding: 15px; width: 100%; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-login:hover { background: #0A402A; }
        .btn-login:disabled { background: #9CA3AF; cursor: not-allowed; }

        .forgot-pass-link { display: inline-block; margin-top: 15px; font-size: 13px; color: var(--primary-green); font-weight: 600; text-decoration: none; cursor: pointer; }
        .forgot-pass-link:hover { text-decoration: underline; }

        .alert-error { background: #FDF2F2; color: #E02424; padding: 12px; border-radius: 12px; font-size: 13px; margin-bottom: 20px; font-weight: 500; text-align:left;}
        .alert-success { background: #E8F5E9; color: #105D3F; padding: 12px; border-radius: 12px; font-size: 13px; margin-bottom: 20px; font-weight: 500; text-align:left;}

        /* MODAL STYLES */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--modal-bg); z-index: 1000; justify-content: center; align-items: center; backdrop-filter: blur(4px); }
        .modal-card { background: var(--card-bg); padding: 30px; border-radius: 24px; width: 100%; max-width: 400px; text-align: left; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .modal-header h3 { font-size: 18px; color: var(--text-dark); margin: 0; }
        .close-btn { background: none; border: none; font-size: 20px; color: var(--text-light); cursor: pointer; }
        
        .btn-submit-reset { background: var(--primary-green); color: white; border: none; padding: 12px; width: 100%; border-radius: 12px; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 10px; transition:0.3s;}
        .btn-submit-reset:hover { background: #0A402A; }
        .btn-submit-reset:disabled { background: #9CA3AF; cursor: not-allowed; }
        
        .theme-toggle { position: fixed; top: 20px; right: 20px; background: var(--card-bg); border: 1px solid var(--border-color); color: var(--text-dark); padding: 10px; border-radius: 50%; cursor: pointer; width: 45px; height: 45px; display: flex; justify-content: center; align-items: center; font-size: 18px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: 0.3s; z-index: 1100; }
        .theme-toggle:hover { transform: scale(1.1); }

        /* CONFIRMATION MODAL (ACTIVE SESSION) */
        .confirm-modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--modal-bg); z-index: 2000; justify-content: center; align-items: center; backdrop-filter: blur(8px); }
        .confirm-card { background: var(--card-bg); padding: 40px; border-radius: 28px; width: 100%; max-width: 420px; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.2); animation: slideUp 0.4s ease-out; }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        
        .confirm-icon { width: 70px; height: 70px; background: var(--active-bg); color: var(--primary-green); border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 30px; margin: 0 auto 20px; }
        .confirm-title { font-size: 20px; font-weight: 700; color: var(--text-dark); margin-bottom: 12px; }
        .confirm-msg { font-size: 14px; color: var(--text-light); line-height: 1.6; margin-bottom: 30px; }
        .confirm-msg b { color: var(--primary-green); }
        
        .confirm-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-cancel { background: var(--input-bg); color: var(--text-dark); border: 1px solid var(--border-color); padding: 12px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-logout { background: #EF4444; color: white; border: none; padding: 12px; border-radius: 12px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-cancel:hover { background: var(--border-color); }
        .btn-logout:hover { background: #DC2626; }
    </style>
</head>
<body>

    <?php if($active_session_name !== ""): ?>
    <!-- Active Session Confirmation Modal -->
    <div id="activeSessionModal" class="confirm-modal" style="display: flex;">
        <div class="confirm-card">
            <div class="confirm-icon"><i class="fa-solid fa-user-check"></i></div>
            <div class="confirm-title">Already Logged In</div>
            <p class="confirm-msg">You are already logged in as <b><?php echo htmlspecialchars($active_session_name); ?></b>. You need to log out before logging in as a different user.</p>
            <div class="confirm-actions">
                <button class="btn-cancel" onclick="window.location.href='<?php echo $active_session_dashboard; ?>'">Go to Dashboard</button>
                <button class="btn-logout" onclick="window.location.href='logout.php'">Log out</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <button id="themeToggleBtn" class="theme-toggle" onclick="toggleTheme()" title="Toggle Dark Mode">
        <i class="fa-solid fa-moon"></i>
    </button>

    <div class="login-container">
        <div class="brand"><i class="fa-solid fa-leaf"></i> Project Hub</div>
        <div class="subtitle">Select your role to login to the portal</div>

        <?php if($error != "") echo "<div class='alert-error'><i class='fa-solid fa-circle-exclamation'></i> $error</div>"; ?>
        <?php if($success != "") echo "<div class='alert-success'><i class='fa-solid fa-check-circle'></i> $success</div>"; ?>

        <form method="POST" action="">
            <input type="hidden" name="role" id="selectedRole" value="">
            
            <div class="role-selector">
                <div class="role-card" onclick="selectRole('admin', this)"><i class="fa-solid fa-user-shield"></i><span>Admin</span></div>
                <div class="role-card" onclick="selectRole('head', this)"><i class="fa-solid fa-building-user"></i><span>Head</span></div>
                <div class="role-card" onclick="selectRole('guide', this)"><i class="fa-solid fa-chalkboard-user"></i><span>Guide</span></div>
                <div class="role-card" onclick="selectRole('student', this)"><i class="fa-solid fa-graduation-cap"></i><span>Student</span></div>
            </div>

            <div class="input-group">
                <input type="text" name="username" placeholder="Moodle ID / Username" required autocomplete="off">
                <i class="fa-regular fa-id-card"></i>
            </div>

            <div class="input-group">
                <input type="password" name="password" placeholder="Password" required>
                <i class="fa-solid fa-lock"></i>
            </div>

            <button type="submit" name="login_submit" class="btn-login" id="submitBtn" disabled>Select Role to Login</button>
        </form>

        <span class="forgot-pass-link" onclick="openResetModal()">Forgot Password? Request Reset</span>
    </div>

    <div id="resetModal" class="modal-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fa-solid fa-key" style="color:var(--primary-green); margin-right:8px;"></i> Request Password Reset</h3>
                <button type="button" class="close-btn" onclick="closeResetModal()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p style="font-size:13px; color:var(--text-light); margin-bottom:20px;">Submit a request to the Admin to assign you a new password.</p>
            
            <form method="POST" action="">
                <div class="input-group">
                    <select name="reset_role" id="reset_role_select" required style="padding-left: 15px;" onchange="checkResetUser()">
                        <option value="" disabled selected>Select Your Role...</option>
                        <option value="student">Student</option>
                        <option value="guide">Guide</option>
                        <option value="head">Head</option>
                    </select>
                </div>
                
                <div class="input-group" style="margin-bottom:10px;">
                    <input type="text" name="reset_moodle_id" id="reset_moodle_input" placeholder="Enter your Moodle ID" required style="padding-left:45px;" onkeyup="checkResetUser()">
                    <i class="fa-regular fa-id-card"></i>
                </div>

                <div id="reset_user_name" style="font-size:13px; font-weight:600; margin-bottom:15px; display:none;"></div>

                <button type="submit" name="request_reset" id="resetSubmitBtn" class="btn-submit-reset" disabled>Submit Reset Request</button>
            </form>
        </div>
    </div>

    <script>
        function selectRole(role, element) {
            document.getElementById('selectedRole').value = role;
            var cards = document.querySelectorAll('.role-card');
            for(var i=0; i<cards.length; i++) {
                cards[i].classList.remove('active');
            }
            element.classList.add('active');

            var btn = document.getElementById('submitBtn');
            btn.disabled = false;
            btn.innerText = "Login as " + role.charAt(0).toUpperCase() + role.slice(1);
        }

        function openResetModal() {
            document.getElementById('resetModal').style.display = 'flex';
            // Reset fields on open
            document.getElementById('reset_role_select').value = '';
            document.getElementById('reset_moodle_input').value = '';
            document.getElementById('reset_user_name').style.display = 'none';
            document.getElementById('resetSubmitBtn').disabled = true;
        }

        function closeResetModal() {
            document.getElementById('resetModal').style.display = 'none';
        }

        // AJAX FUNCTION TO LIVE-CHECK USER FOR RESET
        function checkResetUser() {
            var role = document.getElementById('reset_role_select').value;
            var moodle = document.getElementById('reset_moodle_input').value.trim();
            var nameDiv = document.getElementById('reset_user_name');
            var btn = document.getElementById('resetSubmitBtn');

            if (role === '' || moodle === '') {
                nameDiv.style.display = 'none';
                btn.disabled = true;
                return;
            }

            fetch('index.php?check_user=1&role=' + role + '&moodle_id=' + moodle)
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if(data.status === 'success') {
                    nameDiv.innerHTML = '<i class="fa-solid fa-user-check"></i> Found: ' + data.name;
                    nameDiv.style.color = '#105D3F';
                    nameDiv.style.display = 'block';
                    btn.disabled = false;
                } else {
                    nameDiv.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> User not found in this role';
                    nameDiv.style.color = '#EF4444';
                    nameDiv.style.display = 'block';
                    btn.disabled = true;
                }
            })
            .catch(function(err) {
                console.error(err);
                btn.disabled = true;
            });
        }
        
        // DARK MODE LOGIC
        function toggleTheme() {
            var currentTheme = document.documentElement.getAttribute('data-theme');
            var newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            document.querySelector('#themeToggleBtn i').className = newTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
        }
        if(localStorage.getItem('theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
            document.addEventListener("DOMContentLoaded", function() {
                var icon = document.querySelector('#themeToggleBtn i');
                if(icon) icon.className = 'fa-solid fa-sun';
            });
        }
    </script>
</body>
</html>