<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

//page title
$pageTitle = "Manage Deliveries";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';
$dateType = $_GET['dateType'] ?? 'req';

// sorting
$sort = $_GET['sort'] ?? 'REQ_DELIVERY';
$order = $_GET['order'] ?? 'DESC';
$allowedOrder = ['ASC', 'DESC'];
$allowedSort = ['CUSTOMER_NAME','REQ_DELIVERY','ACT_DELIVERY'];

/* mapping UI → SQL */
$sortMap = [
    'CUSTOMER_NAME' => 'o.CUSTOMER_NAME_SNAPSHOT',
    'REQ_DELIVERY' => 'o.DELIVERY_DATE',
    'ACT_DELIVERY' => "TIMESTAMP(s.SHIPPING_DATE, s.SHIPPING_TIME)"
];

if (!in_array($sort, $allowedSort)) {
    $sort = 'REQ_DELIVERY';
}
if (!in_array($order, $allowedOrder)) {
    $order = 'DESC';
}
$sortSql = $sortMap[$sort];

// pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 5);

$allowedLimits = [5,10,30,50,100];
if (!in_array($limit, $allowedLimits)) $limit = 5;

$offset = ($page - 1) * $limit;

// Summary cards
function getCount($conn, $sql) {
    return $conn->query($sql)->fetch_assoc()['c'];
}

$totalPending = getCount($conn, "
    SELECT COUNT(*) AS c 
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'PENDING'
    AND o.ORDER_STATUS != 'REFUNDED'
");

$totalOutForDelivery = getCount($conn, "
    SELECT COUNT(*) AS c 
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'OUT FOR DELIVERY'
    AND o.ORDER_STATUS != 'REFUNDED'
");

$totalDelivered = getCount($conn, "
    SELECT COUNT(*) AS c 
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'DELIVERED'
    AND o.ORDER_STATUS != 'REFUNDED'
");

// search, filter conditions
$where = [];
$params = [];
$types = "";

$where[] = "o.ORDER_STATUS != 'REFUNDED'";

if ($search != '') {
    $where[] = "(o.ORDER_NO LIKE ? OR o.CUSTOMER_NAME_SNAPSHOT LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

if ($status != '') {
    $where[] = "s.DELIVERY_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

$dateField = ($dateType === 'req') 
    ? "o.DELIVERY_DATE" 
    : "s.SHIPPING_DATE";

if ($fromDate != '') {
    $where[] = "$dateField >= ?";
    $params[] = $fromDate;
    $types .= "s";
}

if ($toDate != '') {
    $where[] = "$dateField <= ?";
    $params[] = $toDate;
    $types .= "s";
}

$whereSql = " WHERE " . implode(" AND ", $where);

// get total rows for pagination
$countSql = "
SELECT COUNT(DISTINCT o.ORDER_ID) AS total
FROM orders o
JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
$whereSql
";

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $limit));

// fetch delivery list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    o.ORDER_ID,
    o.ORDER_NO,
    o.ORDER_TYPE,

    o.CUSTOMER_NAME_SNAPSHOT,
    o.DELIVERY_ADDRESS_SNAPSHOT,

    o.DELIVERY_DATE,
    o.DELIVERY_SLOT_SNAPSHOT,

    s.SHIPPING_ID,
    s.DELIVERY_STATUS,
    s.SHIPPING_DATE,
    s.SHIPPING_TIME,

    COUNT(oi.ORDER_ITEM_ID) AS ITEMS

FROM orders o
JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
LEFT JOIN order_item oi ON o.ORDER_ID = oi.ORDER_ID

$whereSql
GROUP BY o.ORDER_ID
ORDER BY $sortSql $order
LIMIT ? OFFSET ?
";

$stmtList = $conn->prepare($listSql);

$params2 = $params;
$types2 = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;

$stmtList->bind_param($types2, ...$params2);
$stmtList->execute();
$result = $stmtList->get_result();

// Build URL (or Pagination & Sorting Links)
function buildUrl($page,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "fromDate"=>$fromDate,
        "toDate"=>$toDate,
        "limit"=>$limit,
        "sort"=>$sort,
        "order"=>$order,
        "dateType"=>$dateType
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Deliveries</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
.search-box {
    width: 368px;
}

.status-pending {
    background: #fff4e5;
    color: #663d00;
}

.status-out-for-delivery {
    background: #e5f7ff;
    color: #004d66;
}

.status-delivered {
    background: #e6ffed;
    color: #09391a;
}
</style>
</head>

<body>
  <div class="global-layout">
    <?php include "global_layout_ctrl.php" ?>
      <div class="main">

        <div class="mgmt-main-cont">
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Pending</p>
                    <p class="mgmt-card-value"><?= $totalPending ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Out for Delivery</p>
                    <p class="mgmt-card-value"><?= $totalOutForDelivery ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Delivered</p>
                    <p class="mgmt-card-value"><?= $totalDelivered ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by order number(without '#'), or customer name" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Status</option>
                      <option value="Pending" <?php echo ($status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                      <option value="Out for Delivery" <?php echo ($status == 'Out for Delivery') ? 'selected' : '' ?>>Out for Delivery</option>
                      <option value="Delivered" <?php echo ($status == 'Delivered') ? 'selected' : '' ?>>Delivered</option>
                    </select>

                    <label>Date Range:</label>
                    <select name="dateType" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="req" <?= ($dateType ?? 'req')=='req'?'selected':'' ?>>Requested Delivery</option>
                      <option value="act" <?= ($dateType ?? 'act')=='act'?'selected':'' ?>>Actual Delivery</option>
                    </select>

                    <!-- Date Range Filters -->
                    <input type="date" name="fromDate" class="mgmt-filter" 
                        value="<?= htmlspecialchars($fromDate) ?>" 
                        onchange="this.form.submit()">
                        <span>to</span>
                    <input type="date" name="toDate" class="mgmt-filter" 
                        value="<?= htmlspecialchars($toDate) ?>" 
                        onchange="this.form.submit()">

                    <?php if (!empty($search) || !empty($status) || !empty($type) || !empty($fromDate) || !empty($toDate)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont">
                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="orderNo">Order No</label>
                        <label><input type="checkbox" checked data-col="customer">Customer</label>
                        <label><input type="checkbox" checked data-col="address">Address</label>
                        <label><input type="checkbox" checked data-col="requestedDeliveryTime">Req. Delivery</label>
                        <label><input type="checkbox" checked data-col="actualDeliveryTime">Act. Delivery</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="orderNo">Order No</th>

                            <th class="customer">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'CUSTOMER_NAME',
                                    ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC', $dateType) ?>">

                                   <span>Customer</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="address">Address</th>

                            <th class="requestedDeliveryTime">
                                <a class="sort-link <?= $sort=='REQ_DELIVERY' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'REQ_DELIVERY',
                                    ($sort=='REQ_DELIVERY' && $order=='ASC') ? 'DESC' : 'ASC', $dateType) ?>">

                                   <span>Req. Delivery</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="actualDeliveryTime">
                                <a class="sort-link <?= $sort=='ACT_DELIVERY' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'ACT_DELIVERY',
                                    ($sort=='ACT_DELIVERY' && $order=='ASC') ? 'DESC' : 'ASC', $dateType) ?>">

                                   <span>Act. Delivery</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="orderNo">
                                    <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="order-link">
                                        #<?= htmlspecialchars($row['ORDER_NO']) ?>
                                    </a>
                                </td>

                                <td class="customer">
                                    <?= htmlspecialchars($row['CUSTOMER_NAME_SNAPSHOT']) ?>
                                </td>

                                <td class="address">
                                    <?= nl2br(htmlspecialchars($row['DELIVERY_ADDRESS_SNAPSHOT'])) ?>
                                </td>

                                <td class="requestedDeliveryTime">
                                    <?= date("d M Y", strtotime($row['DELIVERY_DATE'])) ?>
                                    <br>
                                    <small><?= htmlspecialchars($row['DELIVERY_SLOT_SNAPSHOT']) ?></small>
                                </td>

                                <td class="actualDeliveryTime">
                                    <?php 
                                        $date = $row['SHIPPING_DATE'];
                                        $time = $row['SHIPPING_TIME'];

                                        $isValidDate = !empty($date) && $date !== '0000-00-00' && !empty($time) && $time !== '00:00:00';?>

                                        <?php if ($isValidDate): ?>
                                            <?= date("d M Y H:i", strtotime("$date $time")) ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower(str_replace(' ', '-', $row['DELIVERY_STATUS'])) ?>">
                                        <?= htmlspecialchars($row['DELIVERY_STATUS']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_delivery.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <button class="update-btn" onclick="openStatusModal(<?= $row['SHIPPING_ID'] ?>, '<?= $row['DELIVERY_STATUS'] ?>')">
                                                <i class="bi bi-arrow-repeat"></i>Update Status
                                            </button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Status Update Pop Up -->
            <div id="statusModal" class="modal">
                <div class="modal-content">
                    <h3>Update Delivery Status</h3>

                    <form id="statusForm">
                        <input type="hidden" name="delivery_id" id="modalDeliveryId">

                        <select name="status" id="modalStatus" required>
                            <option value="PENDING">Pending</option>
                            <option value="OUT FOR DELIVERY">Out for Delivery</option>
                            <option value="DELIVERED">Delivered</option>
                        </select>

                        <div>
                            <button type="submit" class="save-btn">Save</button>
                            <button type="button" onclick="closeModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>">
                        <input type="hidden" name="toDate" value="<?= htmlspecialchars($toDate) ?>">
                        <input type="hidden" name="dateType" value="<?= htmlspecialchars($dateType) ?>">
                        <input type="hidden" name="page" value="1"> 
                        <label>Rows per page</label>
                        <select name="limit" id="page-limit-select" class="page-limit">
                            <option value="5" <?= $limit==5?'selected':'' ?>>5</option>
                            <option value="10" <?= $limit==10?'selected':'' ?>>10</option>
                            <option value="30" <?= $limit==30?'selected':'' ?>>30</option>
                            <option value="50" <?= $limit==50?'selected':'' ?>>50</option>
                            <option value="100" <?= $limit==100?'selected':'' ?>>100</option>
                        </select>
                    </form>
                </div>

                <?php
                    $range = 2; 

                    $start = max(1, $page - $range);
                    $end = min($totalPages, $page + $range);
                ?>
                <div class="pagination-left">
                    <div class="page-controls">
                    <!-- Prev -->
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$fromDate,$toDate,$limit,$sort,$order,$dateType) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>">
                    <input type="hidden" name="toDate" value="<?= htmlspecialchars($toDate) ?>">
                    <input type="hidden" name="dateType" value="<?= htmlspecialchars($dateType) ?>">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" class="jump-input" placeholder="Page">

                    <button type="submit" class="jump-btn">Go</button>

                    <span class="jump-total">
                        / <?= $totalPages ?>
                    </span>
                    </form>
                </div>

            </div>
        </div>

      </div>
  </div>

<script>
// Search auto submit
const searchInput = document.querySelector(".mgmt-search");
let timeout = null;
if (searchInput) {
    searchInput.addEventListener("input", function () {
        clearTimeout(timeout);

        timeout = setTimeout(() => {
            document.querySelector(".mgmt-ctrl form").submit();
        }, 500);
    });
}

// Clear search + filters
const clearCtrlBtn = document.getElementById("clear-ctrl-btn");
if (clearCtrlBtn) {
    clearCtrlBtn.addEventListener("click", function () {
        const url = new URL(window.location.href);
        url.searchParams.delete("search");
        url.searchParams.delete("status");
        url.searchParams.delete("fromDate");
        url.searchParams.delete("toDate");
        url.searchParams.delete("dateType");
        window.location.href = url.toString();
    });
}

// Toggle column menu
const toggleColBtn = document.getElementById("toggle-col-btn");
const toggleColMenu = document.getElementById("toggle-col-menu");

if (toggleColBtn && toggleColMenu) {
    toggleColBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        toggleColMenu.classList.toggle("show");
    });

    toggleColMenu.addEventListener("click", function (e) {
        e.stopPropagation();
    });
}

// apply column visibility
function applyColumn(colClass, show) {
    document.querySelectorAll("." + colClass).forEach(el => {
        el.style.display = show ? "" : "none";
    });
}

// initialize column visibility based on localStorage
document.querySelectorAll("#toggle-col-menu input").forEach(cb => {
    const key = "col_" + cb.dataset.col;
    const saved = localStorage.getItem(key);

    if (saved !== null) {
        cb.checked = saved === "true";
    }

    applyColumn(cb.dataset.col, cb.checked);

    cb.addEventListener("change", function () {
        localStorage.setItem(key, this.checked);
        applyColumn(this.dataset.col, this.checked);
    });
});

// reset columns
const resetColBtn = document.getElementById("reset-col-btn");

if (resetColBtn) {
    resetColBtn.addEventListener("click", function () {
        document.querySelectorAll("#toggle-col-menu input").forEach(cb => {
            cb.checked = true;
            localStorage.removeItem("col_" + cb.dataset.col);
            applyColumn(cb.dataset.col, true);
        });
    });
}

// action menu toggle
document.querySelectorAll(".action-btn").forEach(btn => {
    btn.addEventListener("click", function (e) {
        e.stopPropagation();

        const menu = this.nextElementSibling;
        const isShowing = menu.classList.contains("show");

        document.querySelectorAll(".action-menu").forEach(m => {
            m.classList.remove("show");
        });

        if (!isShowing) {
            menu.classList.add("show");
        }
    });
});

// close menus on outside click
document.addEventListener("click", function (e) {
    document.querySelectorAll(".action-menu").forEach(menu => {
        menu.classList.remove("show");
    });

    if (toggleColMenu) {
        toggleColMenu.classList.remove("show");
    }
});

// page limit elements
const limitSelect = document.getElementById("page-limit-select");

// page limit persistence
document.addEventListener('DOMContentLoaded', () => {
    const url = new URL(window.location.href);
    const urlLimit = url.searchParams.get('limit');
    const savedLimit = localStorage.getItem("preferred_limit");

    if (!urlLimit && savedLimit) {
        if (!sessionStorage.getItem("limit_applied")) {
            sessionStorage.setItem("limit_applied", "true");

            url.searchParams.set('limit', savedLimit);
            url.searchParams.delete("page");
            url.searchParams.set("page", 1);

            window.location.replace(url.toString());
        }
    } else {
        sessionStorage.removeItem("limit_applied");
    }
});

// Change page limit
if (limitSelect) {
    limitSelect.addEventListener("change", function () {
        const selectedValue = this.value;

        localStorage.setItem("preferred_limit", selectedValue);

        const url = new URL(window.location.href);
        url.searchParams.set("limit", selectedValue);
        url.searchParams.set("page", 1);

        window.location.href = url.toString();
    });
}

// Status POP UP
function openStatusModal(deliveryId, currentStatus) {

    const modal = document.getElementById("statusModal");
    modal.classList.add("show");

    document.getElementById("modalDeliveryId").value = deliveryId;
    document.getElementById("modalStatus").value = currentStatus;
}

function closeModal() {
    document.getElementById("statusModal").classList.remove("show");
}

// Status update (submit via AJAX)
document.getElementById("statusForm").addEventListener("submit", function(e){
    e.preventDefault();

    const formData = new FormData(this);

    fetch("update_delivery.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === "success") {
            showToast("success", "Status updated successfully!");
            closeModal();
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            showToast("error", "Update failed");
        }
    })
    .catch(err => {
        showToast("Error updating status");
        console.error(err);
    });
});

// Close status Pop up
window.addEventListener("click", function (e) {
    const modal = document.getElementById("statusModal");

    if (e.target === modal) {
        closeModal();
    }
});

// Show Toast
function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    const text = document.createElement("span");
    text.innerText = message;

    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);

    document.body.appendChild(toast);

    let removed = false;

    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        removeToast();
    });

    toast.addEventListener("click", removeToast);

    function removeToast() {
        if (removed) return;
        removed = true;

        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";

        setTimeout(() => toast.remove(), 300);
    }

    const duration = type === "error" ? 8000 : 3000;

    setTimeout(removeToast, duration);
}
</script>

</body>
</html>