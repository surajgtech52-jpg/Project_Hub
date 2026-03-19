<?php
session_start();
include 'db_connect.php';
if ($_SESSION['user_role'] != 'admin') header("Location: index.php");

// INITIALIZE VARIABLES
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// LOGIC: If Searching, ignore Date. If not Searching, use Date.
if (!empty($search)) {
    // --- SEARCH MODE ---
    $where_clause = "orders.order_code LIKE '%$search%'";
    $page_title = "Search Results: '$search'";
} else {
    // --- DATE MODE (Default) ---
    $where_clause = "DATE(orders.order_date) = '$date'";
    $page_title = "History: $date";
}

// 1. FETCH STATS (Based on current view)
$total_day = $conn->query("SELECT COUNT(*) FROM orders WHERE $where_clause")->fetch_row()[0];
$income_day = $conn->query("SELECT SUM(total_price) FROM orders WHERE $where_clause AND status='Collected'")->fetch_row()[0] ?? 0;

// 2. FETCH ORDERS
$sql = "SELECT orders.*, users.full_name, users.phone 
        FROM orders 
        LEFT JOIN users ON orders.moodle_id = users.moodle_id 
        WHERE $where_clause 
        ORDER BY orders.id DESC";
$orders = $conn->query($sql);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Order History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-light p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="admin_panel.php" class="btn btn-outline-dark">Back to Dashboard</a>
            <h4 class="mb-0 text-muted"><?php echo $page_title; ?></h4>
        </div>

        <div class="card p-3 mb-4 shadow-sm border-0">
            <div class="row g-3 align-items-end">
                
                <div class="col-md-5">
                    <form class="d-flex gap-2">
                        <div class="flex-grow-1">
                            <label class="form-label small fw-bold text-muted">Filter by Date</label>
                            <input type="date" name="date" class="form-control" value="<?php echo $date; ?>">
                        </div>
                        <button type="submit" class="btn btn-dark align-self-end">Filter</button>
                    </form>
                </div>

                <div class="col-md-1 text-center d-none d-md-block text-muted fw-bold">OR</div>

                <div class="col-md-6">
                    <form class="d-flex gap-2">
                        <div class="flex-grow-1">
                            <label class="form-label small fw-bold text-muted">Search Order ID</label>
                            <input type="text" name="search" class="form-control" placeholder="e.g. A7B..." value="<?php echo $search; ?>">
                        </div>
                        <button type="submit" class="btn btn-danger align-self-end">Search</button>
                        <?php if(!empty($search)): ?>
                            <a href="admin_history.php" class="btn btn-outline-secondary align-self-end">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6"><div class="card p-3 text-center border-0 shadow-sm"><h3><?php echo $total_day; ?></h3><small class="text-muted">Orders Found</small></div></div>
            <div class="col-md-6"><div class="card p-3 text-center border-0 shadow-sm"><h3>₹<?php echo $income_day; ?></h3><small class="text-muted">Total Value</small></div></div>
        </div>

        <div class="table-responsive bg-white rounded shadow-sm">
            <table class="table table-striped mb-0 text-center align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Time</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($orders->num_rows > 0): ?>
                        <?php while($row = $orders->fetch_assoc()): 
                            // Safe Data for JS
                            $js_code  = $row['order_code'];
                            $js_name  = htmlspecialchars($row['full_name'] ?? 'Unknown', ENT_QUOTES);
                            $js_phone = htmlspecialchars($row['phone'] ?? 'N/A', ENT_QUOTES);
                            $js_items = htmlspecialchars($row['items'], ENT_QUOTES);
                            $js_total = $row['total_price'];
                            $js_time  = date("h:i A", strtotime($row['order_date']));
                            $full_date= date("d M Y", strtotime($row['order_date']));
                        ?>
                        <tr>
                            <td class="fw-bold">#<?php echo $row['order_code']; ?></td>
                            <td class="text-start ps-4"><?php echo htmlspecialchars($row['full_name']); ?></td>
                            <td>
                                <span class="badge <?php echo ($row['status']=='Collected')?'bg-success':(($row['status']=='Ready')?'bg-primary':'bg-warning'); ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td class="fw-bold">₹<?php echo $row['total_price']; ?></td>
                            <td>
                                <div><?php echo date("h:i A", strtotime($row['order_date'])); ?></div>
                                <small class="text-muted" style="font-size:0.7em;"><?php echo $full_date; ?></small>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-primary" 
                                    onclick="viewOrder('<?php echo $js_code; ?>', '<?php echo $js_name; ?>', '<?php echo $js_phone; ?>', '<?php echo $js_items; ?>', '<?php echo $js_total; ?>', '<?php echo $js_time; ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-muted py-4">No orders found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="orderDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Order Details <span id="m_code" class="text-warning"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 p-3 bg-light rounded border">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted"><i class="fas fa-user me-1"></i> Name:</span>
                            <span class="fw-bold" id="m_name">--</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted"><i class="fas fa-phone me-1"></i> Phone:</span>
                            <span class="fw-bold" id="m_phone">--</span>
                        </div>
                    </div>

                    <h6 class="border-bottom pb-2 mb-2 fw-bold">Items Ordered</h6>
                    <div id="m_items" class="ps-2 mb-3" style="white-space: pre-line; color: #555;">
                        </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <div class="p-2 border rounded text-center">
                                <small class="text-muted d-block">Time</small>
                                <strong id="m_time">--</strong>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-2 border rounded text-center bg-success bg-opacity-10 border-success">
                                <small class="text-success d-block fw-bold">Total Amount</small>
                                <strong class="text-success fs-5">₹<span id="m_total">0</span></strong>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const orderModal = new bootstrap.Modal(document.getElementById('orderDetailModal'));

        function viewOrder(code, name, phone, items, total, time) {
            document.getElementById('m_code').innerText = '#' + code;
            document.getElementById('m_name').innerText = name;
            document.getElementById('m_phone').innerText = phone;
            document.getElementById('m_items').innerText = items;
            document.getElementById('m_total').innerText = total;
            document.getElementById('m_time').innerText = time;
            orderModal.show();
        }
    </script>
</body>
</html>