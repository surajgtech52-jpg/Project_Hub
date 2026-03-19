<?php
session_start();
include 'db_connect.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit(); 
}
$moodle_id = $_SESSION['user_id'];

// Fetch Orders (Newest First)
$sql = "SELECT * FROM orders WHERE moodle_id = '$moodle_id' ORDER BY id DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Order History</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <style>
        @media print {
            /* 1. RESET PAGE */
            @page { size: auto; margin: 0mm; }
            body, html { background-color: white; height: 100%; overflow: hidden; }
            
            /* 2. HIDE EVERYTHING ELSE */
            body > * { display: none !important; }

            /* 3. SHOW MODAL CONTAINER */
            #historyReceiptModal {
                display: block !important;
                position: absolute; top: 0; left: 0;
                width: 100%; height: 100%;
                margin: 0; padding: 0;
                background: white !important;
                visibility: visible !important;
                z-index: 9999;
            }

            /* 4. VISIBILITY RULES (Without breaking layout) */
            #historyReceiptModal .modal-dialog,
            #historyReceiptModal .modal-content,
            #historyReceiptModal .modal-body,
            #historyReceiptModal .card {
                visibility: visible !important;
                display: block !important; 
                border: none !important;
                box-shadow: none !important;
            }

            /* 5. CRITICAL: FORCE FLEXBOX TO STAY FLEX (Keeps items in one line) */
            #historyReceiptModal .d-flex {
                display: flex !important;
                justify-content: space-between !important;
                visibility: visible !important;
            }

            /* 6. Ensure text is visible but don't force display:block */
            #historyReceiptModal span,
            #historyReceiptModal strong,
            #historyReceiptModal p,
            #historyReceiptModal div,
            #historyReceiptModal h2,
            #historyReceiptModal h4,
            #historyReceiptModal hr {
                visibility: visible !important;
            }

            /* 7. CENTER CONTENT */
            .modal-dialog {
                margin: 20px auto !important;
                width: 100% !important;
                max-width: 500px !important;
            }

            /* 8. HIDE BUTTONS */
            .no-print, .btn, .btn-close {
                display: none !important;
            }
            .modal-backdrop { display: none !important; }
        }
    </style>
</head>
<body class="bg-light p-4">

<div class="container">
    <div class="d-flex align-items-center mb-4">
        <a href="dashboard.php" class="btn btn-outline-dark me-3 rounded-pill">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <h2 class="fw-bold mb-0"><i class="fas fa-history text-danger"></i> Order History</h2>
    </div>

    <?php if($result->num_rows > 0): ?>
        <div class="row g-3">
        <?php while($row = $result->fetch_assoc()): 
            $js_items = htmlspecialchars($row['items'], ENT_QUOTES);
            $js_date = date("d M Y, h:i A", strtotime($row['order_date']));
            $js_status = $row['status'];
            $js_id = $row['order_code'];
            $js_total = $row['total_price'];
        ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm border-0 h-100 p-3">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="fw-bold mb-1">Order #<?php echo $row['order_code']; ?></h5>
                            <p class="text-muted small mb-0"><?php echo $js_date; ?></p>
                        </div>
                        <span class="badge bg-success"><?php echo $row['status']; ?></span>
                    </div>
                    <hr class="my-2">
                    <p class="mb-3 text-secondary small" style="min-height: 40px;">
                        <?php echo $row['items']; ?>
                    </p>
                    <div class="d-flex justify-content-between align-items-center mt-auto">
                        <h4 class="text-danger fw-bold mb-0">₹<?php echo $row['total_price']; ?></h4>
                        <button class="btn btn-dark btn-sm rounded-pill px-3" 
                                onclick="showReceipt('<?php echo $js_id; ?>', '<?php echo $js_date; ?>', '<?php echo $js_items; ?>', '<?php echo $js_total; ?>', '<?php echo $js_status; ?>')">
                            <i class="fas fa-download"></i> Receipt
                        </button>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-info text-center py-5 rounded-4">
            <i class="fas fa-folder-open fa-3x mb-3 opacity-50"></i>
            <h4>No past orders found.</h4>
            <p>Go back to the dashboard to grab a bite!</p>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="historyReceiptModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <h2 class="text-success"><i class="fas fa-check-circle"></i></h2>
            <h4 class="fw-bold">Payment Receipt</h4>
            
            <div class="card bg-light p-3 my-3 text-start border-dashed">
                <div class="d-flex justify-content-between mb-2">
                    <strong>Order ID:</strong> <span id="r_id">#000</span>
                </div>
                <div class="d-flex justify-content-between">
                    <strong>Date:</strong> <span id="r_date">--</span>
                </div>
                <hr>
                <p class="mb-2 fw-bold">Items Ordered:</p>
                <div class="mb-3 ps-2" style="border-left: 3px solid #D81B60;" id="r_items">
                    --
                </div>
                <hr>
                <div class="d-flex justify-content-between fs-4 fw-bold text-success">
                    <span>Total Paid:</span>
                    <span>₹<span id="r_total">0</span></span>
                </div>
            </div>
            
            <button class="btn btn-dark w-100 no-print mb-2" onclick="window.print()">
                <i class="fas fa-print"></i> Download / Print
            </button>
            <button class="btn btn-light w-100 no-print" data-bs-dismiss="modal">Close</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const receiptModal = new bootstrap.Modal(document.getElementById('historyReceiptModal'));

    function showReceipt(id, date, items, total, status) {
        document.getElementById('r_id').innerText = "#" + id;
        document.getElementById('r_date').innerText = date;
        
        let formattedItems = "<div>• " + items.replace(/, /g, "</div><div>• ") + "</div>";
        document.getElementById('r_items').innerHTML = formattedItems;

        document.getElementById('r_total').innerText = total;
        receiptModal.show();
    }
</script>

</body>
</html>