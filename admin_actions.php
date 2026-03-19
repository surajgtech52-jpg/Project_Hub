<?php
session_start(); // <--- CRITICAL FIX: Required to read user session
include 'db_connect.php';
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json');

// 1. FETCH ORDERS
if(isset($_POST['fetch_orders'])) {
    $status = $_POST['status'];
    
    $sql = "SELECT orders.*, users.full_name, users.phone 
            FROM orders 
            JOIN users ON orders.moodle_id = users.moodle_id 
            WHERE orders.status=? AND DATE(orders.order_date) = CURDATE() 
            ORDER BY orders.id ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $html = "";
    if($res->num_rows > 0) {
        while($row = $res->fetch_assoc()) {
            $safe_items = htmlspecialchars($row['items']);
            $student_name = htmlspecialchars($row['full_name']);
            $student_phone = htmlspecialchars($row['phone']);
            
            $btn = "";
            if($status == 'Preparing') {
                $btn = "<button class='btn btn-success btn-sm w-100 fw-bold' style='font-size: 0.8rem;' onclick='updateStatus({$row['id']}, \"Ready\")'>Mark Ready</button>";
            }
            if($status == 'Ready') {
                $btn = "<button class='btn btn-dark btn-sm w-100 fw-bold' style='font-size: 0.8rem;' onclick='updateStatus({$row['id']}, \"Collected\")'>Collect</button>";
            }
            
            $html .= "<div class='admin-order-card p-2 mb-2 bg-white shadow-sm rounded border' style='font-size: 0.9rem;'>
                        <div class='d-flex justify-content-between align-items-start mb-1'>
                            <div class='flex-grow-1 pe-2'>
                                <div class='d-flex align-items-center mb-1'>
                                    <span class='text-muted small me-1 fw-bold'>ID:</span>
                                    <span class='fw-bold text-dark fs-5'>#{$row['order_code']}</span>
                                    <span class='badge bg-light text-dark border ms-2' style='font-size: 0.7rem;'>{$row['pickup_time']}</span>
                                </div>
                                <div class='lh-1'>
                                    <div class='fw-bold text-primary text-truncate' style='max-width: 140px;'>{$student_name}</div>
                                    <div class='text-muted small mt-1'><i class='fas fa-phone-alt me-1'></i> {$student_phone}</div>
                                </div>
                            </div>
                            <div class='w-50 p-2 rounded border border-danger bg-warning bg-opacity-10' style='min-width: 180px;'>
                                <div class='text-uppercase fw-bolder text-danger mb-1' style='font-size: 0.7rem; letter-spacing: 0.5px;'>ITEMS:</div>
                                <div class='fw-bolder text-dark' style='font-size: 0.95rem; line-height: 1.3; white-space: pre-line; color: #000 !important;'>
                                    {$safe_items}
                                </div>
                            </div>
                        </div>
                        <div class='d-flex justify-content-between align-items-center border-top pt-2 mt-1'>
                            <div class='d-flex align-items-center'>
                                <span class='text-muted small fw-bold text-uppercase me-2'>Total:</span>
                                <span class='fw-bold text-dark fs-5'>₹{$row['total_price']}</span>
                            </div>
                            <div style='width: 110px;'>{$btn}</div>
                        </div>
                      </div>";
        }
    } else { 
        $html = "<div class='p-3 text-center text-muted opacity-50 small'><i class='fas fa-clipboard-list fa-2x mb-2'></i><br>No orders.</div>"; 
    }
    
    echo json_encode(['status' => 'success', 'html' => $html]);
    exit();
}

// 2. FETCH STATS
if(isset($_POST['fetch_stats'])) {
    $today = date('Y-m-d');
    $pending = $conn->query("SELECT COUNT(*) FROM orders WHERE status='Preparing' AND DATE(order_date) = '$today'")->fetch_row()[0];
    $ready = $conn->query("SELECT COUNT(*) FROM orders WHERE status='Ready' AND DATE(order_date) = '$today'")->fetch_row()[0];
    $collected = $conn->query("SELECT COUNT(*) FROM orders WHERE status='Collected' AND DATE(order_date) = '$today'")->fetch_row()[0];
    $income = $conn->query("SELECT SUM(total_price) FROM orders WHERE status='Collected' AND DATE(order_date) = '$today'")->fetch_row()[0] ?? 0;
    echo json_encode(['status'=>'success', 'pending' => $pending, 'ready' => $ready, 'collected' => $collected, 'income' => $income]);
    exit();
}

// 3. UPDATE STATUS
if(isset($_POST['update_status'])) {
    $id = $_POST['order_id'];
    $stat = $_POST['status'];
    $now = date('Y-m-d H:i:s');
    
    if($stat == 'Ready') $conn->query("UPDATE orders SET status='$stat', ready_time='$now' WHERE id=$id");
    elseif($stat == 'Collected') $conn->query("UPDATE orders SET status='$stat', collected_time='$now', updated_at='$now' WHERE id=$id");
    else $conn->query("UPDATE orders SET status='$stat' WHERE id=$id");
    
    echo json_encode(['status' => 'success']);
    exit();
}

// 4. ADD CATEGORY
if(isset($_POST['add_category'])) {
    $cat = trim($_POST['new_cat_name']);
    if(!empty($cat)) {
        $stmt = $conn->prepare("INSERT IGNORE INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $cat);
        if($stmt->execute()) {
            echo json_encode(['status' => 'success', 'name' => htmlspecialchars($cat), 'raw_name' => $cat]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Empty name']);
    }
    exit();
}

// 5. ADD ITEM
if(isset($_POST['add_item'])) {
    $name = trim($_POST['name']);
    $price = $_POST['price'];
    $cat = trim($_POST['category']); 
    
    $stmt = $conn->prepare("INSERT INTO menu (name, category, price, description, avail_start, avail_end) VALUES (?, ?, ?, 'Item', '00:00:00', '23:59:59')");
    $stmt->bind_param("ssd", $name, $cat, $price);
    
    if($stmt->execute()) {
        $new_id = $conn->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'name' => htmlspecialchars($name), 'price' => $price, 'category' => htmlspecialchars($cat)]);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit();
}

// 6. EDIT ITEM
if(isset($_POST['update_item'])) {
    $id = $_POST['id'];
    $name = trim($_POST['name']);
    $price = $_POST['price'];
    $cat = trim($_POST['category']);
    
    $stmt = $conn->prepare("UPDATE menu SET name=?, price=?, category=? WHERE id=?");
    $stmt->bind_param("ssdi", $name, $price, $cat, $id);
    
    if($stmt->execute()) echo json_encode(['status' => 'success', 'id'=>$id, 'name'=>htmlspecialchars($name), 'price'=>$price, 'category'=>htmlspecialchars($cat)]);
    else echo json_encode(['status' => 'error']);
    exit();
}

// 7. DELETE CATEGORY
if(isset($_POST['delete_category'])) {
    $cat = $_POST['cat_name'];
    $conn->query("DELETE FROM menu WHERE category = '$cat'");
    $conn->query("DELETE FROM categories WHERE name = '$cat'");
    echo json_encode(['status' => 'success']);
    exit();
}

// 8. TOGGLE SOLD OUT
if(isset($_POST['toggle_sold_out'])) {
    $id = $_POST['item_id'];
    $conn->query("UPDATE menu SET is_sold_out = NOT is_sold_out WHERE id=$id");
    $res = $conn->query("SELECT is_sold_out FROM menu WHERE id=$id")->fetch_assoc();
    echo json_encode(['status' => 'success', 'is_sold_out' => $res['is_sold_out']]);
    exit();
}

// 9. DELETE ITEM
if(isset($_POST['delete_item'])) {
    $id = $_POST['item_id'];
    $conn->query("DELETE FROM menu WHERE id=$id");
    echo json_encode(['status' => 'success']);
    exit();
}

// 10. UPDATE TIME
if(isset($_POST['update_shop_time'])) {
    $o = $_POST['open_time'];
    $c = $_POST['close_time'];
    $conn->query("UPDATE settings SET open_time='$o', close_time='$c' WHERE id=1");
    echo json_encode(['status' => 'success']);
    exit();
}

// 11. SCHEDULE CATEGORY
if(isset($_POST['sched_cat'])) {
    $cat = $_POST['cat_name'];
    $s = $_POST['start_time'];
    $e = $_POST['end_time'];
    $stmt = $conn->prepare("UPDATE menu SET avail_start=?, avail_end=? WHERE category=?");
    $stmt->bind_param("sss", $s, $e, $cat);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
    exit();
}

// 12. SCHEDULE ITEM
if(isset($_POST['sched_item_single'])) {
    $id = $_POST['id'];
    $s = $_POST['start'];
    $e = $_POST['end'];
    $stmt = $conn->prepare("UPDATE menu SET avail_start=?, avail_end=? WHERE id=?");
    $stmt->bind_param("ssi", $s, $e, $id);
    $stmt->execute();
    echo json_encode(['status' => 'success']);
    exit();
}

// 13. CHANGE PASSWORD (AJAX) - FIX APPLIED HERE
if(isset($_POST['change_password_admin'])) {
    $pass = $_POST['new_password'];
    
    if(isset($_SESSION['user_id'])) {
        $id = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE moodle_id=?");
        $stmt->bind_param("ss", $pass, $id);
        
        if($stmt->execute()) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Database error']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Session expired']);
    }
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
?>