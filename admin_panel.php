<?php
session_start();
include 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] != 'admin') {
    header("Location: index.php");
    exit();
}

// 1. STATS (TODAY)
$today = date('Y-m-d');
$total_orders = $conn->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = '$today'")->fetch_row()[0];
$total_income = $conn->query("SELECT SUM(total_price) FROM orders WHERE status='Collected' AND DATE(order_date) = '$today'")->fetch_row()[0] ?? 0;
$pending_count = $conn->query("SELECT COUNT(*) FROM orders WHERE status='Preparing' AND DATE(order_date) = '$today'")->fetch_row()[0];
$ready_count = $conn->query("SELECT COUNT(*) FROM orders WHERE status='Ready' AND DATE(order_date) = '$today'")->fetch_row()[0];

// FETCH DATA
$cat_res = $conn->query("SELECT name FROM categories");
$categories = [];
while($c_row = $cat_res->fetch_assoc()) { $categories[] = $c_row['name']; }

$menu_res = $conn->query("SELECT * FROM menu");
$menu_items = [];
while($row = $menu_res->fetch_assoc()){ $menu_items[] = $row; }

$settings = $conn->query("SELECT * FROM settings WHERE id=1")->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>Admin Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
</head>
<body>

<nav class="navbar navbar-custom sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand text-dark fs-4" href="#"><span class="text-danger"><i class="fas fa-utensils"></i> Campus</span> Dine Admin</a>
        
        <div class="d-flex align-items-center gap-3">
            <a href="admin_history.php" class="btn btn-outline-dark btn-sm fw-bold">Order History</a>
            <div class="profile-icon" data-bs-toggle="offcanvas" data-bs-target="#adminSidebar">
                <img src="https://cdn-icons-png.flaticon.com/512/2206/2206368.png" alt="Admin">
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="stats-card"><div class="stats-num" id="stat-orders"><?php echo $total_orders; ?></div><div class="stats-label">Today's Orders</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-num" id="stat-income">₹<?php echo $total_income; ?></div><div class="stats-label">Today's Income</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-num text-warning" id="stat-pending"><?php echo $pending_count; ?></div><div class="stats-label">Pending</div></div></div>
        <div class="col-md-3"><div class="stats-card"><div class="stats-num text-success" id="stat-ready"><?php echo $ready_count; ?></div><div class="stats-label">Ready</div></div></div>
    </div>

    <div class="control-bar">
        <button class="control-btn" onclick="toggleField('scheduleTimeField')"><i class="far fa-clock"></i> Schedule Time</button>
        <button class="control-btn" onclick="toggleField('editMenuField')"><i class="fas fa-edit"></i> Edit Menu</button>
        <button class="control-btn" onclick="toggleField('scheduleMenuField')"><i class="fas fa-calendar-alt"></i> Schedule Menu</button>
    </div>

    <div id="scheduleTimeField" class="control-field">
        <h5><i class="far fa-clock"></i> Today's Hours</h5>
        <div class="d-flex gap-3 align-items-end mt-3">
            <div><label>Open</label><input type="time" id="shop_open_time" class="form-control" value="<?php echo $settings['open_time']; ?>"></div>
            <div><label>Close</label><input type="time" id="shop_close_time" class="form-control" value="<?php echo $settings['close_time']; ?>"></div>
            <button onclick="updateShopTime()" class="btn btn-danger">Update</button>
        </div>
    </div>

    <div id="editMenuField" class="control-field">
        <div class="d-flex justify-content-between mb-3">
            <h5><i class="fas fa-edit"></i> Menu Management</h5>
            <button class="btn btn-dark btn-sm" onclick="openAddCatModal()">+ New Category</button>
        </div>
        
        <div class="d-flex align-items-center">
            <div class="d-flex overflow-auto pb-2 flex-grow-1" id="menuFilters">
                 <span class="category-badge active filter-btn" data-cat="All" onclick="filterMenu('All', this)">All</span>
                 <?php foreach($categories as $cat): 
                     $safeCat = htmlspecialchars($cat, ENT_QUOTES); 
                 ?>
                    <div class="position-relative d-inline-block cat-wrapper" id="cat_pill_<?php echo md5($cat); ?>">
                        <span class="category-badge filter-btn" data-cat="<?php echo $safeCat; ?>" onclick="filterMenu('<?php echo $safeCat; ?>', this)"><?php echo htmlspecialchars($cat); ?></span>
                        <div class="delete-cat-btn" onclick="deleteCategory('<?php echo $safeCat; ?>')"><i class="fas fa-times"></i></div>
                    </div>
                 <?php endforeach; ?>
            </div>
            <button class="toggle-delete-btn" onclick="toggleDeleteMode(this)"><i class="fas fa-trash-alt"></i></button>
        </div>
        
        <div class="edit-menu-grid mt-3" id="menuGrid">
            <?php foreach($menu_items as $item): 
                $safeName = htmlspecialchars($item['name'], ENT_QUOTES);
                $safeCat = htmlspecialchars($item['category'], ENT_QUOTES);
            ?>
            <div class="edit-card item-box <?php echo $item['is_sold_out']?'sold-out':''; ?>" 
                 id="item_card_<?php echo $item['id']; ?>"
                 data-cat="<?php echo $safeCat; ?>">
                
                <div class="fw-bold item-name"><?php echo $item['name']; ?></div> 
                <div class="text-danger item-price">₹<?php echo $item['price']; ?></div>
                
                <div class="edit-actions">
                    <button class="btn btn-sm btn-outline-dark" onclick="openEditModal(<?php echo $item['id']; ?>, '<?php echo $safeName; ?>', <?php echo $item['price']; ?>, '<?php echo $safeCat; ?>')"><i class="fas fa-pen"></i></button>
                    <button class="btn btn-sm <?php echo $item['is_sold_out']?'btn-secondary':'btn-outline-warning'; ?> toggle-btn" onclick="toggleSoldOut(<?php echo $item['id']; ?>, this)">
                        <?php echo $item['is_sold_out']?'Sold':'Out'; ?>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteItem(<?php echo $item['id']; ?>)"><i class="fas fa-trash"></i></button>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="edit-card add-item-card" onclick="openAddItemModal()">
                <div class="add-item-icon"><i class="fas fa-plus-circle"></i></div><div>Add Item</div>
            </div>
        </div>
    </div>

    <div id="scheduleMenuField" class="control-field">
        <h5><i class="fas fa-calendar-alt"></i> Schedule Availability</h5>
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><button class="nav-link active text-dark fw-bold" data-bs-toggle="tab" data-bs-target="#tabCategory">Schedule by Category</button></li>
            <li class="nav-item"><button class="nav-link text-dark fw-bold" data-bs-toggle="tab" data-bs-target="#tabItem">Schedule by Food Item</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabCategory">
                <div class="row g-3">
                    <div class="col-md-4"><label>Category</label><select id="sched_cat_name" class="form-select"><?php foreach($categories as $cat) echo "<option value='".htmlspecialchars($cat, ENT_QUOTES)."'>$cat</option>"; ?></select></div>
                    <div class="col-md-3"><label>Start</label><input type="time" id="sched_cat_start" class="form-control"></div>
                    <div class="col-md-3"><label>End</label><input type="time" id="sched_cat_end" class="form-control"></div>
                    <div class="col-md-2"><label>&nbsp;</label><button onclick="submitSchedCategory()" class="btn btn-dark w-100">Set</button></div>
                </div>
            </div>
            <div class="tab-pane fade" id="tabItem">
                 <div class="edit-menu-grid">
                    <?php foreach($menu_items as $item): ?>
                    <div class="edit-card text-start p-3" style="border-left: 4px solid #D81B60;">
                        <div class="d-flex justify-content-between"><h6 class="fw-bold mb-1"><?php echo htmlspecialchars($item['name']); ?></h6></div>
                        <small class="text-muted d-block mb-2">Current: <?php echo substr($item['avail_start'], 0, 5) . ' - ' . substr($item['avail_end'], 0, 5); ?></small>
                        <button class="btn btn-sm btn-outline-danger w-100" onclick="openItemScheduleModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')"><i class="far fa-clock"></i> Set Time</button>
                    </div>
                    <?php endforeach; ?>
                 </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-center my-3">
        <div class="btn-group">
            <button class="btn btn-outline-dark active" id="viewLive" onclick="switchView('live')">Pending & Ready</button>
            <button class="btn btn-outline-dark" id="viewCollected" onclick="switchView('collected')">Ready & Collected</button>
        </div>
    </div>

    <div class="d-flex d-md-none row g-0 mb-3">
        <div class="col-6">
            <button class="btn btn-dark w-100 rounded-0 rounded-start fw-bold" id="mobLeftBtn" onclick="switchMobileTab('left')">PENDING</button>
        </div>
        <div class="col-6">
            <button class="btn btn-outline-dark w-100 rounded-0 rounded-end fw-bold" id="mobRightBtn" onclick="switchMobileTab('right')">READY</button>
        </div>
    </div>

    <div class="order-manager" id="orderContainer">
        
        <div class="order-column d-block d-md-block" id="colLeft">
            <div class="col-header pending d-flex justify-content-between align-items-center px-3 py-2">
                <span id="titleLeft" class="fs-5 fw-bold text-nowrap me-2">PENDING</span>
                <input type="text" class="form-control form-control-sm" 
                       style="max-width: 150px;" 
                       placeholder="Search..." 
                       onkeyup="filterOrders(this, '#listPending')">
            </div>
            <div id="listPending"></div>
        </div>
        
        <div class="order-column d-none d-md-block" id="colRight">
            <div class="col-header ready d-flex justify-content-between align-items-center px-3 py-2">
                <span id="titleRight" class="fs-5 fw-bold text-nowrap me-2">READY</span>
                <input type="text" class="form-control form-control-sm" 
                       style="max-width: 150px;" 
                       placeholder="Search..." 
                       onkeyup="filterOrders(this, '#listReady')">
            </div>
            <div id="listReady"></div>
        </div>
        
    </div>
</div>

<div class="modal fade" id="addCategoryModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">New Category</h5></div><div class="modal-body"><input type="text" id="new_cat_name" class="form-control" required></div><div class="modal-footer"><button type="button" onclick="submitAddCategory()" class="btn btn-dark w-100">Add</button></div></div></div></div>

<div class="modal fade" id="addItemModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add Item</h5></div><div class="modal-body"><label>Name</label><input type="text" id="add_name" class="form-control mb-2"><label>Price</label><input type="number" id="add_price" class="form-control mb-2"><label>Category</label><select id="add_cat" class="form-select"><?php foreach($categories as $cat) echo "<option value='".htmlspecialchars($cat, ENT_QUOTES)."'>$cat</option>"; ?></select></div><div class="modal-footer"><button type="button" onclick="submitAddItem()" class="btn btn-danger w-100">Add Item</button></div></div></div></div>

<div class="modal fade" id="editItemModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Edit Item</h5></div><div class="modal-body"><input type="hidden" id="edit_id"><label>Name</label><input type="text" id="edit_name" class="form-control mb-2"><label>Price</label><input type="number" id="edit_price" class="form-control mb-2"><label>Category</label><select id="edit_cat_select" class="form-select"><?php foreach($categories as $cat) echo "<option value='".htmlspecialchars($cat, ENT_QUOTES)."'>$cat</option>"; ?></select></div><div class="modal-footer"><button type="button" onclick="submitEditItem()" class="btn btn-dark w-100">Save</button></div></div></div></div>

<div class="modal fade" id="schedItemModal" tabindex="-1"><div class="modal-dialog modal-sm"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="sched_item_title">Set Time</h5></div><div class="modal-body"><input type="hidden" id="sched_item_id"><label>Start</label><input type="time" id="sched_start" class="form-control mb-2"><label>End</label><input type="time" id="sched_end" class="form-control mb-2"></div><div class="modal-footer"><button type="button" onclick="submitSchedItem()" class="btn btn-danger w-100">Set</button></div></div></div></div>

<div class="modal fade" id="changePassModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Change Password</h5></div>
            <div class="modal-body">
                <label>New Password</label>
                <input type="password" id="new_admin_pass" class="form-control" placeholder="Enter new password">
            </div>
            <div class="modal-footer">
                <button type="button" onclick="submitPasswordChange()" class="btn btn-dark w-100">Update Password</button>
            </div>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="adminSidebar">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">Admin Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column justify-content-between">
        <div>
            <button class="btn btn-outline-dark w-100 mb-3" data-bs-toggle="modal" data-bs-target="#changePassModal">
                <i class="fas fa-key me-2"></i> Change Password
            </button>
        </div>
        <a href="index.php" class="btn btn-light w-100 text-danger fw-bold">Logout</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> 

<script>
    let currentFilter = 'All';

    // 1. SEARCH FUNCTION
    function filterOrders(input, listId) {
        let term = input.value.toLowerCase();
        $(listId).find('.admin-order-card').each(function() {
            let text = $(this).text().toLowerCase();
            if(text.includes(term)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    // 2. ROBUST FILTERING (Menu)
    function filterMenu(catName, btn) {
        currentFilter = catName;
        $('.filter-btn').removeClass('active');
        $(btn).addClass('active');

        if(catName === 'All') {
            $('.item-box').show();
        } else {
            $('.item-box').hide();
            $('.item-box').filter(function() {
                return $(this).attr('data-cat') === catName;
            }).show();
        }
    }

    // 3. ADD CATEGORY
    function openAddCatModal() { new bootstrap.Modal(document.getElementById('addCategoryModal')).show(); }
    
    function submitAddCategory() {
        let name = $('#new_cat_name').val().trim();
        if(!name) return;
        
        $.post('admin_actions.php', { add_category: 1, new_cat_name: name }, function(resp) {
            if(resp.status === 'success') {
                let safeName = resp.name;
                let newBadge = `
                    <div class="position-relative d-inline-block cat-wrapper">
                        <span class="category-badge filter-btn" data-cat="${safeName}" onclick="filterMenu('${safeName}', this)">${safeName}</span>
                        <div class="delete-cat-btn" onclick="deleteCategory('${safeName}')"><i class="fas fa-times"></i></div>
                    </div>`;
                $('#menuFilters').append(newBadge);
                
                let opt = `<option value="${safeName}">${safeName}</option>`;
                $('#add_cat').append(opt);
                $('#edit_cat_select').append(opt);
                $('#sched_cat_name').append(opt);
                
                bootstrap.Modal.getInstance(document.getElementById('addCategoryModal')).hide();
                $('#new_cat_name').val('');
            }
        });
    }

    // 4. ADD ITEM
    function openAddItemModal() { new bootstrap.Modal(document.getElementById('addItemModal')).show(); }

    function submitAddItem() {
        $.post('admin_actions.php', { 
            add_item: 1, 
            name: $('#add_name').val(), 
            price: $('#add_price').val(), 
            category: $('#add_cat').val() 
        }, function(resp) {
            if(resp.status === 'success') {
                let newCard = `
                <div class="edit-card item-box" id="item_card_${resp.id}" data-cat="${resp.category}">
                    <div class="fw-bold item-name">${resp.name}</div> 
                    <div class="text-danger item-price">₹${resp.price}</div>
                    <div class="edit-actions">
                        <button class="btn btn-sm btn-outline-dark" onclick="openEditModal(${resp.id}, '${resp.name}', ${resp.price}, '${resp.category}')"><i class="fas fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-warning toggle-btn" onclick="toggleSoldOut(${resp.id}, this)">Out</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteItem(${resp.id})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>`;
                
                $('.add-item-card').before(newCard);
                
                if(currentFilter !== 'All' && currentFilter !== resp.category) $(`#item_card_${resp.id}`).hide();

                bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
                $('#add_name').val(''); $('#add_price').val('');
            }
        });
    }

    // 5. EDIT ITEM
    function openEditModal(id, name, price, cat) {
        $('#edit_id').val(id); $('#edit_name').val(name); $('#edit_price').val(price); $('#edit_cat_select').val(cat);
        new bootstrap.Modal(document.getElementById('editItemModal')).show();
    }

    function submitEditItem() {
        let id = $('#edit_id').val();
        let name = $('#edit_name').val();
        let price = $('#edit_price').val();
        let cat = $('#edit_cat_select').val();

        $.post('admin_actions.php', { update_item: 1, id: id, name: name, price: price, category: cat }, function(resp) {
            if(resp.status === 'success') {
                let card = $(`#item_card_${id}`);
                card.attr('data-cat', resp.category);
                card.find('.item-name').text(resp.name);
                card.find('.item-price').text('₹' + resp.price);
                
                let safeName = resp.name.replace(/'/g, "\\'");
                let safeCat = resp.category.replace(/'/g, "\\'");
                card.find('.btn-outline-dark').attr('onclick', `openEditModal(${id}, '${safeName}', ${resp.price}, '${safeCat}')`);

                bootstrap.Modal.getInstance(document.getElementById('editItemModal')).hide();
                
                if(currentFilter !== 'All' && currentFilter !== resp.category) card.hide();
                else card.show();
            }
        });
    }

    // 6. UPDATE SHOP TIME
    function updateShopTime() {
        $.post('admin_actions.php', { 
            update_shop_time: 1, 
            open_time: $('#shop_open_time').val(), 
            close_time: $('#shop_close_time').val() 
        }, function(resp) {
            if(resp.status === 'success') alert("Shop time updated!");
        });
    }

    // 7. SCHEDULE CATEGORY
    function submitSchedCategory() {
        $.post('admin_actions.php', { 
            sched_cat: 1, 
            cat_name: $('#sched_cat_name').val(), 
            start_time: $('#sched_cat_start').val(), 
            end_time: $('#sched_cat_end').val() 
        }, function(resp) {
            if(resp.status === 'success') alert("Schedule updated for category!");
        });
    }

    // 8. SCHEDULE ITEM
    function openItemScheduleModal(id, name) {
        $('#sched_item_id').val(id); $('#sched_item_title').text("Schedule: " + name);
        new bootstrap.Modal(document.getElementById('schedItemModal')).show();
    }
    
    function submitSchedItem() {
        $.post('admin_actions.php', { 
            sched_item_single: 1, 
            id: $('#sched_item_id').val(), 
            start: $('#sched_start').val(), 
            end: $('#sched_end').val() 
        }, function(resp) {
            if(resp.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('schedItemModal')).hide();
                alert("Item schedule updated!");
            }
        });
    }

    // 9. HELPERS
    function toggleSoldOut(id, btn) {
        $.post('admin_actions.php', { toggle_sold_out: 1, item_id: id }, function(resp) {
            if(resp.status === 'success') {
                let isSold = resp.is_sold_out == 1;
                $(btn).text(isSold ? 'Sold' : 'Out');
                $(btn).toggleClass('btn-secondary btn-outline-warning');
                $(`#item_card_${id}`).toggleClass('sold-out');
            }
        });
    }

    function deleteItem(id) {
        if(confirm('Delete this item?')) {
            $.post('admin_actions.php', { delete_item: 1, item_id: id }, function(resp) {
                if(resp.status === 'success') $(`#item_card_${id}`).remove();
            });
        }
    }

    function deleteCategory(catName) {
        if(confirm(`Delete category '${catName}' and all its items?`)) {
            $.post('admin_actions.php', { delete_category: 1, cat_name: catName }, function(resp) {
                if(resp.status === 'success') {
                    $('.item-box').filter(function() { return $(this).attr('data-cat') === catName; }).remove();
                    location.reload(); 
                }
            });
        }
    }

    function toggleDeleteMode(btn) { $(btn).toggleClass('active'); $('#menuFilters').toggleClass('delete-mode'); }
    function toggleField(id) { $('.control-field').not('#' + id).slideUp(); $('#' + id).slideToggle(); }

    // 10. LIVE ORDERS VIEW
    function switchView(mode) {
        if(mode === 'live') {
            $('#viewLive').addClass('active'); $('#viewCollected').removeClass('active');
            
            // Update Headers
            $('#colLeft .col-header').attr('class', 'col-header pending d-flex justify-content-between align-items-center px-3 py-2');
            $('#titleLeft').text('PENDING');
            
            $('#colRight .col-header').attr('class', 'col-header ready d-flex justify-content-between align-items-center px-3 py-2');
            $('#titleRight').text('READY');
            
            // Update Mobile Buttons
            $('#mobLeftBtn').text('PENDING');
            $('#mobRightBtn').text('READY');
            
            loadOrders('Preparing', '#listPending'); 
            loadOrders('Ready', '#listReady');
        } else {
            $('#viewCollected').addClass('active'); $('#viewLive').removeClass('active');
            
            // Update Headers
            $('#colLeft .col-header').attr('class', 'col-header ready d-flex justify-content-between align-items-center px-3 py-2');
            $('#titleLeft').text('READY');
            
            $('#colRight .col-header').attr('class', 'col-header collected d-flex justify-content-between align-items-center px-3 py-2');
            $('#titleRight').text('COLLECTED');
            
            // Update Mobile Buttons
            $('#mobLeftBtn').text('READY');
            $('#mobRightBtn').text('COLLECTED');
            
            loadOrders('Ready', '#listPending'); 
            loadOrders('Collected', '#listReady');
        }
    }

    // 11. MOBILE TAB SWITCHER
    function switchMobileTab(side) {
        if(side === 'left') {
            $('#colLeft').removeClass('d-none').addClass('d-block');
            $('#colRight').removeClass('d-block').addClass('d-none');
            $('#mobLeftBtn').addClass('btn-dark').removeClass('btn-outline-dark');
            $('#mobRightBtn').removeClass('btn-dark').addClass('btn-outline-dark');
        } else {
            $('#colLeft').removeClass('d-block').addClass('d-none');
            $('#colRight').removeClass('d-none').addClass('d-block');
            $('#mobRightBtn').addClass('btn-dark').removeClass('btn-outline-dark');
            $('#mobLeftBtn').removeClass('btn-dark').addClass('btn-outline-dark');
        }
    }
    
    function loadOrders(status, containerId) {
        $.post('admin_actions.php', { fetch_orders: 1, status: status }, function(resp) {
            $(containerId).html(resp.html);
            
            // FIX: Re-apply filter after data loads if user has typed something
            let parentCol = $(containerId).closest('.order-column');
            let input = parentCol.find('input');
            if(input.val()) {
                filterOrders(input[0], containerId);
            }
        });
    }

    function updateStatus(id, newStatus) {
        $.post('admin_actions.php', { update_status: 1, order_id: id, status: newStatus }, function() {
            // FIX: Don't call switchView(), just reload the lists to keep search text
            if($('#viewLive').hasClass('active')) {
                loadOrders('Preparing', '#listPending');
                loadOrders('Ready', '#listReady');
            } else {
                loadOrders('Ready', '#listPending');
                loadOrders('Collected', '#listReady');
            }
            refreshStats();
        });
    }

    function refreshStats() {
        $.post('admin_actions.php', { fetch_stats: 1 }, function(resp) {
            let stats = (typeof resp === 'string') ? JSON.parse(resp) : resp;
            let p = parseInt(stats.pending) || 0;
            let r = parseInt(stats.ready) || 0;
            let c = parseInt(stats.collected) || 0;
            let i = stats.income || 0;

            $('#stat-orders').text(p + r + c); 
            $('#stat-income').text('₹' + i);
            $('#stat-pending').text(p);
            $('#stat-ready').text(r);
        });
    }

    // FIX: Main Loop (Updates without clearing text)
    setInterval(() => { 
        refreshStats();
        if($('#viewLive').hasClass('active')) {
            loadOrders('Preparing', '#listPending');
            loadOrders('Ready', '#listReady');
        } else {
            loadOrders('Ready', '#listPending');
            loadOrders('Collected', '#listReady');
        }
    }, 5000);
    
    // 12. CHANGE PASSWORD (AJAX)
    function submitPasswordChange() {
        let pass = $('#new_admin_pass').val();
        if(!pass) {
            alert("Please enter a password");
            return;
        }

        $.post('admin_actions.php', { change_password_admin: 1, new_password: pass }, function(resp) {
            if(resp.status === 'success') {
                alert("Password Updated Successfully!");
                bootstrap.Modal.getInstance(document.getElementById('changePassModal')).hide();
                $('#new_admin_pass').val('');
            } else {
                alert("Error: " + (resp.message || "Could not update password"));
            }
        });
    }
    
    // Initial Call
    switchView('live');
    refreshStats();
</script>
</body>
</html>