<?php
session_start();
include 'db_connect.php';

$error_msg = "";

// --- STEP 1: Handle Role Selection ---
if (isset($_POST['confirm_role'])) {
    $_SESSION['selected_role'] = $_POST['role_selection'];
}

// --- STEP 2: Handle Final Login (PLAIN TEXT VERSION) ---
if (isset($_POST['do_login'])) {
    $moodle_id = trim($_POST['moodle_id']);
    $password = trim($_POST['password']); 
    $role_trying_to_access = $_SESSION['selected_role'];

    // 1. Check if user exists with that Role
    $stmt = $conn->prepare("SELECT * FROM users WHERE moodle_id = ? AND role = ?");
    $stmt->bind_param("ss", $moodle_id, $role_trying_to_access);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        
        // 2. DIRECT COMPARISON
        if ($password == $row['password']) {
            $_SESSION['user_id'] = $moodle_id;
            $_SESSION['user_role'] = $role_trying_to_access;
            
            // --- REDIRECT LOGIC ---
            if($role_trying_to_access == 'student' || $role_trying_to_access == 'teacher') {
                // BOTH go to the same dashboard now
                header("Location: dashboard.php");
            } else {
                // Admin goes to separate panel
                header("Location: admin_panel.php");
            }
            exit();
        } else {
            $error_msg = "Incorrect Password.";
        }
    } else {
        $error_msg = "User not found! (Check Moodle ID or Role)";
    }
    $stmt->close();
}

// --- Helper: Reset Role Selection ---
if(isset($_GET['reset_role'])) {
    unset($_SESSION['selected_role']);
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campus Dine Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="d-flex align-items-center min-vh-100 py-5">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">

            <?php if (!isset($_SESSION['selected_role'])): ?>
                <div class="login-card text-center">
                     <h2 class="login-title">Welcome</h2>
                     <p class="login-subtitle mb-4">Please select your role to continue</p>
                     
                     <form method="POST">
                        <div class="d-grid gap-3">
                            <button type="submit" name="role_selection" value="student" class="btn role-btn">
                                <i class="fas fa-user-graduate me-2"></i> Student
                            </button>
                             <button type="submit" name="role_selection" value="teacher" class="btn role-btn">
                                <i class="fas fa-chalkboard-teacher me-2"></i> Teacher
                            </button>
                            <button type="submit" name="role_selection" value="admin" class="btn role-btn">
                                <i class="fas fa-user-shield me-2"></i> Admin
                            </button>
                        </div>
                        <input type="hidden" name="confirm_role" value="1">
                     </form>
                </div>

            <?php else: ?>
                <div class="login-card text-center fade-in">
                    <h1 class="login-title">Login</h1>
                    <p class="login-subtitle">Campus Dine</p>

                    <div class="mb-4 text-muted small">
                        Logging in as: <strong><?php echo ucfirst($_SESSION['selected_role']); ?></strong> 
                        (<a href="index.php?reset_role=1" class="text-decoration-none" style="color: #D81B60;">Change</a>)
                    </div>

                    <?php if($error_msg): ?>
                        <div class="alert alert-danger p-2 small mx-3 mb-4 rounded-pill"><?php echo $error_msg; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="input-group custom-input-group">
                            <span class="input-group-text"><i class="far fa-id-badge"></i></span>
                            <input type="text" name="moodle_id" class="form-control" placeholder="Moodle ID" required>
                        </div>

                        <div class="input-group custom-input-group">
                            <span class="input-group-text"><i class="fas fa-lock"></i></span>
                            <input type="password" name="password" class="form-control" placeholder="Password" required>
                        </div>

                        <div class="mt-5">
                            <button type="submit" name="do_login" class="btn btn-login-pink">LOGIN</button>
                        </div>
                    </form>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>