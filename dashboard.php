<?php
session_start();
include 'db_connect.php';

// FUNCTION: Generate Unique Alphanumeric ID (3-7 chars)
function generateUniqueOrderId($conn) {
    $chars = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $unique = false;
    $order_code = "";

    while (!$unique) {
        $length = rand(3, 7); 
        $order_code = "";
        for ($i = 0; $i < $length; $i++) {
            $order_code .= $chars[rand(0, strlen($chars) - 1)];
        }
        $check = $conn->query("SELECT id FROM orders WHERE order_code = '$order_code'");
        if ($check->num_rows == 0) $unique = true;
    }
    return $order_code;
}

// --- 1. AJAX HANDLER: LIVE TRACKER ---
if(isset($_POST['fetch_live_orders'])) {
    if(!isset($_SESSION['user_id'])) exit();
    $moodle_id = $_SESSION['user_id'];
    
    // UPDATED QUERY
    $sql_track = "SELECT * FROM orders WHERE moodle_id = '$moodle_id' 
                  AND DATE(order_date) = CURDATE() 
                  AND (status != 'Collected' OR (status = 'Collected' AND updated_at >= NOW() - INTERVAL 10 MINUTE))
                  ORDER BY id DESC";
    $active_orders = $conn->query($sql_track);

    if($active_orders->num_rows > 0) {
        echo '<div style="max-height: 140px; overflow-y: auto;">';
        while($ord = $active_orders->fetch_assoc()) {
            $s = $ord['status'];
            $width = ($s == 'Preparing') ? "33%" : (($s == 'Ready') ? "66%" : "100%");
            
            $t_prep = date("h:i A", strtotime($ord['order_date']));
            $t_ready = $ord['ready_time'] ? date("h:i A", strtotime($ord['ready_time'])) : "--:--";
            $t_coll = $ord['collected_time'] ? date("h:i A", strtotime($ord['collected_time'])) : "--:--";
            
            // FIX APPLIED HERE: Proper string escaping for the onclick function
            echo '
            <div class="track-item" data-id="'.$ord['id'].'" data-status="'.$ord['status'].'">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <div class="d-flex align-items-center">
                        <span class="fw-bold small me-2">Order #'.$ord['order_code'].'</span>
                        <button class="btn btn-outline-success btn-sm py-0 px-1" style="font-size: 0.7rem;" onclick="showFullScreenId(\''.$ord['order_code'].'\')">
                            <i class="fas fa-expand"></i> View
                        </button>
                    </div>
                    <span class="fw-bold text-danger small">₹'.$ord['total_price'].'</span>
                </div>
                <div class="progress-track"><div class="progress-fill" style="width: '.$width.';"></div></div>
                <div class="track-labels">
                    <span class="text-center">Placed<br><small>'.$t_prep.'</small></span>
                    <span class="text-center">Ready<br><small>'.$t_ready.'</small></span>
                    <span class="text-center">Taken<br><small>'.$t_coll.'</small></span>
                </div>
            </div>';
        }
        echo '</div>';
    } else {
        echo '<div class="no-orders-msg"><span>No active orders right now.</span></div>';
    }
    exit(); 
}

// --- 2. SECURITY & SETUP ---
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['student', 'teacher'])) {
    header("Location: index.php");
    exit();
}

$moodle_id = $_SESSION['user_id'];
$user_role = ucfirst($_SESSION['user_role']); 
$msg = "";
date_default_timezone_set('Asia/Kolkata'); 

$settings = $conn->query("SELECT * FROM settings WHERE id=1")->fetch_assoc();
$shop_open = $settings['open_time'];
$shop_close = $settings['close_time'];

$cat_res = $conn->query("SELECT name FROM categories");
$categories = [];
while($c_row = $cat_res->fetch_assoc()) { $categories[] = $c_row['name']; }

$menu_items = $conn->query("SELECT * FROM menu");

// --- 3. HANDLE ORDER PLACEMENT ---
if(isset($_POST['place_order_final'])) {
    $items = $_POST['order_summary_text']; 
    $total = $_POST['order_total_price'];
    $pickup = $_POST['order_pickup_time'];
    
    // TIME VALIDATION (Supports Overnight)
    $is_valid_time = false;
    if ($shop_open < $shop_close) {
        if ($pickup >= $shop_open && $pickup <= $shop_close) $is_valid_time = true;
    } else {
        if ($pickup >= $shop_open || $pickup <= $shop_close) $is_valid_time = true;
    }

    if(!$is_valid_time) {
        $msg = "❌ Error: Please select a time between " . substr($shop_open, 0, 5) . " and " . substr($shop_close, 0, 5);
    } else {
        $now_ts = date('Y-m-d H:i:s');
        
        // 1. GENERATE UNIQUE CODE
        $new_code = generateUniqueOrderId($conn);

        // 2. INSERT WITH NEW CODE
        $stmt = $conn->prepare("INSERT INTO orders (moodle_id, order_code, items, total_price, pickup_time, status, order_date) VALUES (?, ?, ?, ?, ?, 'Preparing', ?)");
        $stmt->bind_param("ssssss", $moodle_id, $new_code, $items, $total, $pickup, $now_ts);
        
        if($stmt->execute()) {
            $_SESSION['show_receipt'] = true;
            $_SESSION['last_order_id'] = $conn->insert_id;
            header("Location: dashboard.php");
            exit();
        }
    }
}

// --- 4. HANDLE PROFILE UPDATE ---
if(isset($_POST['update_profile'])) {
    $email = $_POST['email']; $phone = $_POST['phone']; $fullname = $_POST['full_name'];
    if(!empty($_FILES['profile_pic']['name'])){
        $target_dir = "uploads/"; $file_name = time()."_".basename($_FILES["profile_pic"]["name"]);
        move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_dir.$file_name);
        $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, full_name=?, profile_pic=? WHERE moodle_id=?");
        $stmt->bind_param("sssss", $email, $phone, $fullname, $file_name, $moodle_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET email=?, phone=?, full_name=? WHERE moodle_id=?");
        $stmt->bind_param("ssss", $email, $phone, $fullname, $moodle_id);
    }
    $stmt->execute(); header("Location: dashboard.php"); exit();
}

// --- 5. HANDLE PASSWORD CHANGE ---
if(isset($_POST['change_password'])) {
    $new_pass = $_POST['new_password'];
    $stmt = $conn->prepare("UPDATE users SET password=? WHERE moodle_id=?");
    $stmt->bind_param("ss", $new_pass, $moodle_id);
    if($stmt->execute()) { $msg = "Password Updated Successfully!"; }
}

// --- 6. FETCH DATA ---
$receipt_data = null;
if(isset($_SESSION['show_receipt']) && isset($_SESSION['last_order_id'])) {
    $lid = $_SESSION['last_order_id'];
    $receipt_data = $conn->query("SELECT * FROM orders WHERE id=$lid")->fetch_assoc();
}

$sql_track = "SELECT * FROM orders WHERE moodle_id = '$moodle_id' 
              AND DATE(order_date) = CURDATE() 
              AND (status != 'Collected' OR (status = 'Collected' AND updated_at >= NOW() - INTERVAL 10 MINUTE))
              ORDER BY id DESC";
$active_orders = $conn->query($sql_track);

$user = $conn->query("SELECT * FROM users WHERE moodle_id = '$moodle_id'")->fetch_assoc();
$photo_path = !empty($user['profile_pic']) && $user['profile_pic'] != 'default.png' ? "uploads/".$user['profile_pic'] : "https://cdn-icons-png.flaticon.com/512/3135/3135715.png";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar navbar-custom sticky-top">
    <div class="container px-4">
        <a class="navbar-brand text-dark fs-4" href="#"><span class="text-danger"><i class="fas fa-utensils"></i> Campus</span> Dine</a>
        <div class="d-flex align-items-center gap-3">
            <a href="order_history.php" class="text-secondary text-decoration-none fw-bold small">History</a>
            <div class="profile-icon" data-bs-toggle="offcanvas" data-bs-target="#profileSidebar"><img src="<?php echo $photo_path; ?>"></div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php if($msg) echo "<div class='alert alert-info'>$msg</div>"; ?>

    <div class="row dashboard-header g-3 mb-4">
        <div class="col-md-4">
            <div class="schedule-card">
                <h5 class="fw-bold mb-1">Pick a Time</h5>
                <p class="text-muted small mb-3">
                    Today: <?php echo date("h:i A", strtotime($shop_open)); ?> - <?php echo date("h:i A", strtotime($shop_close)); ?>
                </p>
                
                <div class="d-flex gap-2">
                    <select id="hourSelect" class="form-select custom-time-input text-center" onchange="combineTime()">
                        <option value="">Hr</option>
                    </select>
                    
                    <select id="minSelect" class="form-select custom-time-input text-center" onchange="combineTime()">
                        <option value="">Min</option>
                        <option value="00">00</option>
                        <option value="15">15</option>
                        <option value="30">30</option>
                        <option value="45">45</option>
                    </select>
                </div>
                
                <input type="hidden" id="scheduleTime" name="order_pickup_time">
                <input type="hidden" id="hidden_time_card">
            </div>
        </div>

        <div class="col-md-8">
            <div class="tracker-card" id="liveTrackerBox">
                <div class="no-orders-msg"><span>Loading status...</span></div>
            </div>
        </div>
    </div>

    <div class="d-flex overflow-auto mb-4 pb-2" id="filterContainer">
        <span class="category-badge active" onclick="filterMenu('All', this)">All</span>
        <?php foreach($categories as $cat): ?>
            <span class="category-badge" onclick="filterMenu('<?php echo $cat; ?>', this)"><?php echo $cat; ?></span>
        <?php endforeach; ?>
    </div>

    <div class="row g-3">
        <?php 
        $current_time = date('H:i:s');
        while($item = $menu_items->fetch_assoc()): 
            $is_available = false;
            $status_text = "Closed";

            if ($shop_open < $shop_close) {
                if ($current_time >= $shop_open && $current_time <= $shop_close) $is_available = true;
                else $status_text = "Shop Closed";
            } else {
                if ($current_time >= $shop_open || $current_time <= $shop_close) $is_available = true;
                else $status_text = "Shop Closed";
            }

            if ($is_available) {
                if ($item['avail_start'] < $item['avail_end']) {
                    if (!($current_time >= $item['avail_start'] && $current_time <= $item['avail_end'])) {
                        $is_available = false; 
                        $status_text = "Closed (" . substr($item['avail_start'],0,5) . ")";
                    }
                } else {
                    if (!($current_time >= $item['avail_start'] || $current_time <= $item['avail_end'])) {
                        $is_available = false;
                        $status_text = "Closed";
                    }
                }
            }

            if($item['is_sold_out']) {
                $is_available = false; 
                $status_text = "Sold Out";
            }
        ?>
        <div class="col-6 col-md-4 col-lg-3 menu-item-col" data-category="<?php echo $item['category']; ?>">
            <div class="card menu-card h-100 p-3 <?php echo !$is_available ? 'bg-light border' : ''; ?>">
                <div class="d-flex justify-content-between mb-2">
                    <small class="text-muted"><?php echo $item['category']; ?></small>
                    <span class="fw-bold text-danger">₹<?php echo $item['price']; ?></span>
                </div>
                <h6 class="fw-bold mb-1 <?php echo !$is_available ? 'text-muted' : ''; ?>"><?php echo $item['name']; ?></h6>
                <?php if($is_available): ?>
                    <button class="btn btn-outline-secondary btn-sm w-100 mt-2 add-btn disabled-btn" 
                            onclick="addToCart('<?php echo $item['name']; ?>', <?php echo $item['price']; ?>)">Add +</button>
                <?php else: ?>
                    <button class="btn btn-sm btn-secondary w-100 mt-2" disabled><?php echo $status_text; ?></button>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
</div>

<div class="floating-cart" id="floatBtn" onclick="openCartModal()"><i class="fas fa-shopping-basket"></i> <span id="cartCount">0</span> Items | ₹<span id="cartTotalBtn">0</span></div>

<div class="modal fade" id="cartModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title fw-bold">Your Cart</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <ul class="list-group mb-3" id="cartList"></ul>
                <div class="d-flex justify-content-between fw-bold fs-5"><span>Total:</span><span>₹<span id="cartTotalModal">0</span></span></div>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="order_summary_text" id="hidden_items">
                    <input type="hidden" name="order_total_price" id="hidden_total">
                    <input type="hidden" name="order_pickup_time" id="hidden_time">
                    <button type="submit" name="place_order_final" class="btn btn-success w-100">Confirm & Pay</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="profileSidebar">
    <div class="offcanvas-header"><h5 class="offcanvas-title fw-bold">My Profile</h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button></div>
    <div class="offcanvas-body">
        <div class="text-center mb-4">
            <div class="profile-icon mx-auto mb-2" style="width: 100px; height: 100px;"><img src="<?php echo $photo_path; ?>" style="width:100%; height:100%; object-fit:cover; border-radius:50%;"></div>
            <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($user['full_name']); ?></h4>
            <p class="text-muted"><?php echo $user['moodle_id']; ?></p>
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateProfile()">
            <div class="mb-3"><label class="small text-muted">Change Photo</label><input type="file" name="profile_pic" class="form-control"></div>
            <div class="mb-3"><label class="small text-muted">Full Name</label><input type="text" id="p_name" name="full_name" class="form-control" value="<?php echo $user['full_name']; ?>"></div>
            <div class="mb-3"><label class="small text-muted">Email</label><input type="email" id="p_email" name="email" class="form-control" value="<?php echo $user['email']; ?>"></div>
            <div class="mb-3"><label class="small text-muted">Phone</label><input type="text" id="p_phone" name="phone" class="form-control" value="<?php echo $user['phone']; ?>"></div>
            <button type="submit" name="update_profile" class="btn btn-dark w-100 mb-4">Save Changes</button>
        </form>
        <hr>
        <form method="POST">
             <label class="small text-muted">New Password</label>
             <div class="input-group mb-3"><input type="password" name="new_password" class="form-control" placeholder="Enter new password" required><button class="btn btn-outline-danger" type="submit" name="change_password">Update</button></div>
        </form>
        <a href="index.php" class="btn btn-light w-100 text-danger fw-bold">Logout</a>
    </div>
</div>

<?php if (isset($receipt_data)): ?>
<div class="modal fade show" id="receiptModal" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <h2 class="text-success"><i class="fas fa-check-circle"></i></h2>
            <h4 class="fw-bold">Payment Successful!</h4>
            <div class="card bg-light p-3 my-3 text-start border-dashed">
                <div class="d-flex justify-content-between mb-2"><strong>Order ID:</strong> <span>#<?php echo $receipt_data['order_code']; ?></span></div>
                <div class="d-flex justify-content-between"><strong>Date:</strong> <span><?php echo date("d M, h:i A", strtotime($receipt_data['order_date'])); ?></span></div>
                <hr>
                <p class="mb-2 fw-bold">Items Ordered:</p>
                <div class="mb-3 ps-2" style="border-left: 3px solid #D81B60;">
                    <?php 
                        $items_array = explode(", ", $receipt_data['items']);
                        foreach($items_array as $item) { echo "<div>• " . htmlspecialchars($item) . "</div>"; }
                    ?>
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-4 fw-bold text-success"><span>Total Paid:</span><span>₹<?php echo $receipt_data['total_price']; ?></span></div>
            </div>
            <button class="btn btn-dark w-100 no-print mb-2" onclick="window.print()">Download Receipt</button>
            <a href="dashboard.php" class="btn btn-link no-print">Close</a>
        </div>
    </div>
</div>
<?php unset($_SESSION['show_receipt']); ?>
<?php endif; ?>

<style>
    /* Default Portrait Layout */
    #fullScreenOverlay {
        display: none; 
        position: fixed; 
        top: 0; left: 0; 
        width: 100%; height: 100%; 
        background-color: #198754; 
        z-index: 10000; 
        flex-direction: column; 
        justify-content: center; 
        align-items: center; 
        text-align: center;
        transition: all 0.3s ease;
    }

    /* 1. PORTRAIT FONT SIZE (Fits Width) */
    #overlayOrderId {
        font-size: 13vw; 
        line-height: 1;
        word-break: keep-all;
    }

    /* Landscape Class (Applied via JS) */
    #fullScreenOverlay.rotate-screen {
        width: 100vh; /* Width becomes Height */
        height: 100vw; /* Height becomes Width */
        transform: rotate(90deg);
        transform-origin: center;
        /* Center the rotated box */
        position: fixed;
        left: 50%;
        top: 50%;
        margin-left: -50vh;
        margin-top: -50vw;
    }

    /* 2. LANDSCAPE FONT SIZE (Fits Height because rotated) */
    #fullScreenOverlay.rotate-screen #overlayOrderId {
        font-size: 13vh; 
    }
</style>

<div id="fullScreenOverlay">
    
    <button onclick="closeFullScreen()" class="btn btn-outline-light border-0" style="position:absolute; top:20px; right:20px; font-size:40px; line-height:1; z-index:10001;">&times;</button>
    
    <div>
        <h2 class="text-white text-uppercase mb-2" style="letter-spacing: 2px; font-size: 3vmin;">Your Order Number</h2>
        <h1 class="text-white fw-bold" id="overlayOrderId">#00</h1>
    </div>

    <button onclick="toggleLandscape()" class="btn btn-light fw-bold rounded-pill shadow" style="position:absolute; bottom:40px; padding: 10px 30px; font-size: 1rem;">
        <i class="fas fa-sync-alt me-2"></i> Rotate View
    </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let cart = {}; 
    let isTimeSelected = false;

    // --- NOTIFICATION SYSTEM ---
    let notifiedOrders = []; 
    const notificationSound = new Audio('https://actions.google.com/sounds/v1/alarms/beep_short.ogg'); 

    if ("Notification" in window) {
        if (Notification.permission !== "granted") {
            Notification.requestPermission();
        }
    }

    function checkNotifications() {
        const orders = document.querySelectorAll('.track-item');
        orders.forEach(order => {
            const id = order.getAttribute('data-id');
            const status = order.getAttribute('data-status');
            if (status === 'Ready' && !notifiedOrders.includes(id)) {
                notificationSound.play().catch(error => console.log("Audio play blocked: interaction required"));
                if (Notification.permission === "granted") {
                    new Notification("Order Ready! 🍔", {
                        body: `Order #${id} is ready for pickup!`,
                        icon: "https://cdn-icons-png.flaticon.com/512/3135/3135715.png" 
                    });
                }
                notifiedOrders.push(id);
            }
        });
    }

    // --- CONFIGURATION ---
    const shopOpenStr = "<?php echo $shop_open; ?>"; 
    const shopCloseStr = "<?php echo $shop_close; ?>"; 
    
    const shopOpenHour = parseInt(shopOpenStr.split(':')[0]);
    const shopCloseHour = parseInt(shopCloseStr.split(':')[0]);
    
    const currentHour = <?php echo (int)date('H'); ?>;
    const currentMin = <?php echo (int)date('i'); ?>;

    function initTimeDropdowns() {
        const hSelect = document.getElementById('hourSelect');
        let html = '<option value="">Hr</option>';
        let hoursList = [];

        if (shopOpenHour < shopCloseHour) {
            for (let h = shopOpenHour; h <= shopCloseHour; h++) hoursList.push(h);
        } else {
            for (let h = shopOpenHour; h <= 23; h++) hoursList.push(h);
            for (let h = 0; h <= shopCloseHour; h++) hoursList.push(h);
        }
        hoursList.forEach(h => {
            let isValid = false;
            
            if (shopOpenHour < shopCloseHour) {
                if (h >= currentHour) isValid = true;
            } else {
                if (currentHour >= shopOpenHour) {
                    if (h >= currentHour || h <= shopCloseHour) isValid = true;
                } else {
                    if (h >= currentHour && h <= shopCloseHour) isValid = true;
                }
            }

            if (isValid) {
                let val = h < 10 ? '0' + h : h;
                let disp = h;
                let ampm = 'AM';
                if(h >= 12) { ampm = 'PM'; if(h > 12) disp = h - 12; }
                if(h === 0) { disp = 12; ampm = 'AM'; }
                html += `<option value="${val}">${disp} ${ampm}</option>`;
            }
        });

        if (html === '<option value="">Hr</option>') html = '<option value="">Closed</option>';
        hSelect.innerHTML = html;
    }

    function combineTime() {
        const hVal = document.getElementById('hourSelect').value;
        const mVal = document.getElementById('minSelect').value;
        const hiddenInput = document.getElementById('scheduleTime');
        const modalInput = document.getElementById('hidden_time');

        if (hVal !== "" && mVal !== "") {
            const selectedTimeStr = `${hVal}:${mVal}:00`;
            
            if (!validateRange(selectedTimeStr)) {
                alert(`❌ Shop is closed at ${hVal}:${mVal}. \nOperating Hours: ${shopOpenStr} to ${shopCloseStr}`);
                resetSelection();
                return;
            }

            if (!validateBuffer(parseInt(hVal), parseInt(mVal))) {
                return; 
            }

            const combined = `${hVal}:${mVal}`;
            hiddenInput.value = combined;
            if(modalInput) modalInput.value = combined;
            enableOrdering(); 

        } else {
            hiddenInput.value = "";
            isTimeSelected = false;
        }
    }

    function validateRange(selTime) {
        if (shopOpenStr < shopCloseStr) {
            return (selTime >= shopOpenStr && selTime <= shopCloseStr);
        } else {
            return (selTime >= shopOpenStr || selTime <= shopCloseStr);
        }
    }

    function validateBuffer(selH, selM) {
        const now = new Date();
        const selected = new Date();
        selected.setHours(selH);
        selected.setMinutes(selM);
        selected.setSeconds(0);

        if (selH < now.getHours() && selH <= shopCloseHour) {
            selected.setDate(selected.getDate() + 1);
        }

        const minTime = new Date(now.getTime() + 30 * 60000);

        if (selected < minTime) {
            let suggestH = minTime.getHours();
            let suggestM = minTime.getMinutes();
            const remainder = suggestM % 15;
            if (remainder !== 0) suggestM += (15 - remainder);
            if (suggestM === 60) { suggestM = 0; suggestH += 1; }
            if (suggestH === 24) suggestH = 0;

            let suggAmpm = suggestH >= 12 ? 'PM' : 'AM';
            let suggDispH = suggestH > 12 ? suggestH - 12 : (suggestH === 0 ? 12 : suggestH);
            let suggDispM = suggestM < 10 ? '0' + suggestM : suggestM;

            alert(`⚠️ Too Soon! Please order at least 30 mins in advance.\n\nEarliest valid time: ${suggDispH}:${suggDispM} ${suggAmpm}`);
            resetSelection();
            return false;
        }
        return true;
    }

    function resetSelection() {
        document.getElementById('minSelect').value = "";
        document.getElementById('scheduleTime').value = "";
        isTimeSelected = false;
        document.querySelectorAll('.add-btn').forEach(b => { 
            b.classList.remove('btn-outline-danger'); 
            b.classList.add('btn-outline-secondary', 'disabled-btn'); 
        });
    }

    function enableOrdering() {
        isTimeSelected = true; 
        document.querySelectorAll('.add-btn').forEach(b => { 
            b.classList.remove('btn-outline-secondary','disabled-btn'); 
            b.classList.add('btn-outline-danger'); 
        });
    }

    setInterval(function() {
        let xhr = new XMLHttpRequest();
        xhr.open("POST", "dashboard.php", true);
        xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (this.readyState == 4 && this.status == 200) {
                document.getElementById("liveTrackerBox").innerHTML = this.responseText;
                checkNotifications();
            }
        };
        xhr.send("fetch_live_orders=1");
    }, 3000);

    function filterMenu(category, btn) {
        document.querySelectorAll('.category-badge').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll('.menu-item-col').forEach(card => {
            if(category === 'All' || card.dataset.category === category) card.style.display = 'block';
            else card.style.display = 'none';
        });
    }

    function addToCart(name, price) {
        if(!isTimeSelected) { alert("Please select a pickup time first!"); document.getElementById('hourSelect').focus(); return; }
        if (cart[name]) { cart[name].qty += 1; } else { cart[name] = { price: price, qty: 1 }; }
        updateCartUI();
    }

    function updateCartUI() {
        let count = 0; let total = 0;
        for (let item in cart) { count += cart[item].qty; total += cart[item].price * cart[item].qty; }
        document.getElementById('cartCount').innerText = count; document.getElementById('cartTotalBtn').innerText = total;
        document.getElementById('floatBtn').style.display = count > 0 ? 'block' : 'none';
    }

    function renderCart() {
        const list = document.getElementById('cartList'); 
        list.innerHTML = ''; 
        let total = 0; 
        let dbString = "";
        
        if (Object.keys(cart).length === 0) {
            list.innerHTML = '<div class="text-center text-muted py-3">Your cart is empty</div>';
            document.querySelector('button[name="place_order_final"]').disabled = true;
        } else {
            document.querySelector('button[name="place_order_final"]').disabled = false;
            for (let name in cart) {
                let item = cart[name]; 
                let itemTotal = item.price * item.qty; 
                total += itemTotal; 
                dbString += `${name} (x${item.qty}), `;
                
                let safeName = name.replace(/'/g, "\\'");
                
                list.innerHTML += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-bold">${name}</div>
                        <small class="text-muted">₹${item.price} x ${item.qty}</small>
                    </div>
                    <div class="d-flex align-items-center">
                        <button type="button" class="btn btn-sm btn-outline-danger px-2" onclick="updateQty('${safeName}', -1)">-</button>
                        <span class="mx-2 fw-bold" style="width:20px; text-align:center;">${item.qty}</span>
                        <button type="button" class="btn btn-sm btn-outline-success px-2" onclick="updateQty('${safeName}', 1)">+</button>
                    </div>
                    <span class="fw-bold text-dark">₹${itemTotal}</span>
                </li>`;
            }
        }
        
        dbString = dbString.replace(/,\s*$/, "");
        document.getElementById('cartTotalModal').innerText = total; 
        document.getElementById('hidden_total').value = total; 
        document.getElementById('hidden_items').value = dbString;
        if(document.getElementById('scheduleTime').value) {
             document.getElementById('hidden_time').value = document.getElementById('scheduleTime').value;
        }
    }

    function openCartModal() {
        renderCart();
        new bootstrap.Modal(document.getElementById('cartModal')).show();
    }

    function updateQty(name, change) {
        if (cart[name]) {
            cart[name].qty += change;
            if (cart[name].qty <= 0) {
                delete cart[name];
            }
            updateCartUI(); 
            renderCart();   
        }
    }

    function validateProfile() { return true; }

    // --- FULL SCREEN FUNCTIONS ---
    
  // --- FULL SCREEN FUNCTIONS ---
    
    function showFullScreenId(code) {
        document.getElementById('overlayOrderId').innerText = '#' + code;
        document.getElementById('fullScreenOverlay').style.display = 'flex';
        // Always start in normal (portrait) mode
        document.getElementById('fullScreenOverlay').classList.remove('rotate-screen');
    }
    
    function closeFullScreen() {
        document.getElementById('fullScreenOverlay').style.display = 'none';
    }

    function toggleLandscape() {
        // Toggle the CSS class that handles rotation and resizing
        document.getElementById('fullScreenOverlay').classList.toggle('rotate-screen');
    }

    function resetOrientation() {
        let content = document.getElementById('overlayContent');
        let idText = document.getElementById('overlayOrderId');
        content.style.transform = "rotate(0deg)";
        idText.style.fontSize = "13vw"; // Normal size
    }

    initTimeDropdowns();

    <?php if(isset($_SESSION['show_receipt'])): ?>
        new bootstrap.Modal(document.getElementById('receiptModal')).show(); 
        <?php unset($_SESSION['show_receipt']); ?>
    <?php endif; ?>
</script>
</body>
</html>