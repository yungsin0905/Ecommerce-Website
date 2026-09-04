<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
} 
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Manage Orders";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';
$dateType = $_GET['dateType'] ?? 'created';

// sorting 
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';

$allowedSort = ['CUSTOMER_NAME','REQ_DELIVERY','TOTAL_AMOUNT','CREATED_AT'];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'CREATED_AT';
}
if (!in_array($order, $allowedOrder)) {
    $order = 'DESC';
}

$sortMap = [
    'CUSTOMER_NAME' => 'o.CUSTOMER_NAME_SNAPSHOT',
    'REQ_DELIVERY' => 'o.DELIVERY_DATE',
    'TOTAL_AMOUNT' => 'o.TOTAL_AMOUNT',
    'CREATED_AT' => 'o.CREATED_AT'
];
$sortSql = $sortMap[$sort];

// pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$allowedLimits = [5,10,30,50,100];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;
if (!in_array($limit, $allowedLimits)) {
    $limit = 5;
}
$offset = ($page - 1) * $limit;

// summary cards
function getCount($conn, $sql, $type = "", $param = null) {
    $stmt = $conn->prepare($sql);
    if ($type && $param !== null) {
        $stmt->bind_param($type, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

$totalProcessing = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'PROCESSING'")['o'];
$totalReady = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'READY'")['o'];
$totalRefunded = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'REFUNDED'")['o'];

// search, filter conditions
$where = [];
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(o.ORDER_NO LIKE ? OR o.CUSTOMER_NAME_SNAPSHOT LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= "ss";
}

if ($status != '') {
    $where[] = "o.ORDER_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($type != '') {
    $where[] = "o.ORDER_TYPE = ?";
    $params[] = $type;
    $types .= "s";
}

/* Date Range Filters */
$dateField = ($dateType === 'delivery')
    ? "o.DELIVERY_DATE"
    : "o.CREATED_AT";

if ($fromDate != '') {

    if ($dateType === 'delivery') {
        $where[] = "$dateField >= ?";
        $params[] = $fromDate;
    } else {
        $where[] = "$dateField >= ?";
        $params[] = $fromDate . " 00:00:00";
    }

    $types .= "s";
}

if ($toDate != '') {

    if ($dateType === 'delivery') {
        $where[] = "$dateField <= ?";
        $params[] = $toDate;
    } else {
        $where[] = "$dateField <= ?";
        $params[] = $toDate . " 23:59:59";
    }

    $types .= "s";
}

$whereSql = "";
if (count($where) > 0) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

// get total rows for pagination
$countSql = "
SELECT COUNT(DISTINCT o.ORDER_ID) AS total
FROM orders o
LEFT JOIN order_item oi ON o.ORDER_ID = oi.ORDER_ID
" . $whereSql;

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// fetch order list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    o.*,
    COUNT(DISTINCT oi.ORDER_ITEM_ID) AS ITEMS,

    rr.REQUEST_ID,
    rr.REASON AS REFUND_REASON,
    r.REFUND_AMOUNT

FROM orders o

LEFT JOIN order_item oi 
    ON o.ORDER_ID = oi.ORDER_ID

LEFT JOIN refund_request rr
    ON rr.REQUEST_ID = (
        SELECT rr2.REQUEST_ID
        FROM refund_request rr2
        WHERE rr2.ORDER_ID = o.ORDER_ID
        AND rr2.REQUEST_STATUS = 'APPROVED'
        ORDER BY rr2.CREATED_AT DESC
        LIMIT 1
    )

LEFT JOIN refund r
    ON rr.REQUEST_ID = r.REQUEST_ID

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
function buildUrl($page, $search, $status, $type, $fromDate, $toDate, $limit, $sort, $order) {
    return "?" . http_build_query([
        "page" => $page,
        "search" => $search,
        "status" => $status,
        "type" => $type,
        "fromDate" => $fromDate,
        "toDate" => $toDate,
        "limit" => $limit,
        "sort" => $sort,
        "order" => $order
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Orders</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
.search-box {
    width: 340px;
}

.status-processing {
    background: #e6ffed;
    color: #1f7a3f;
}

.status-ready {
    background: #e6f0ff;
    color: #1a4d99;
}

.status-completed {
    background: #f0f0f0;
    color: #666666;
}

.status-refunded {
    background: #fff0e6;
    color: #994d00;
}

/* textarea max length */
.text-cont {
    margin-top:-5px;
    text-align:right;
}

.text-hint {
    font-size:10px;
    color:grey;
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
                    <p class="mgmt-card-title">Processing</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalProcessing) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Ready</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalReady) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Refunded</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalRefunded) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by order no(without '#'), or customer name" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Status</option>
                      <option value="Processing" <?php echo ($status == 'Processing') ? 'selected' : ''; ?>>Processing</option>
                      <option value="Ready" <?php echo ($status == 'Ready') ? 'selected' : '' ?>>Ready</option>
                      <option value="Completed" <?php echo ($status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                      <option value="Refunded" <?php echo ($status == 'Refunded') ? 'selected' : '' ?>>Refunded</option>
                    </select>

                    <select name="type" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Types</option>
                      <option value="Normal" <?php echo ($type == 'Normal') ? 'selected' : ''; ?>>Normal</option>  
                      <option value="Custom" <?php echo ($type == 'Custom') ? 'selected' : ''; ?>>Custom</option>
                    </select>

                    <!-- Date Range Filters -->
                    <label>Date Range:</label>

                    <select name="dateType" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="created" <?= ($dateType == 'created') ? 'selected' : '' ?>>
                            Order Created
                        </option>

                        <option value="delivery" <?= ($dateType == 'delivery') ? 'selected' : '' ?>>
                            Req. Delivery
                        </option>
                    </select>

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
                        <label><input type="checkbox" checked data-col="items">Items</label>  <!-- total num of products purchased -->
                        <label><input type="checkbox" checked data-col="total">Total</label>
                        <label><input type="checkbox" checked data-col="reqDelivery">Req. Delivery</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <label><input type="checkbox" checked data-col="type">Type</label>
                        <label><input type="checkbox" checked data-col="since">Since</label>
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
                                    href="<?= buildUrl(1,$search,$status,$type,$fromDate,$toDate,$limit,'CUSTOMER_NAME',
                                   ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Customer</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="type">Type</th>

                            <th class="items">Items</th>
                      
                            <th class="total">
                                <a class="sort-link <?= $sort=='TOTAL_AMOUNT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$fromDate,$toDate,$limit,'TOTAL_AMOUNT',
                                   ($sort=='TOTAL_AMOUNT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Total</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>
                            
                            <th class="reqDelivery">
                                <a class="sort-link <?= $sort=='REQ_DELIVERY' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$fromDate,$toDate,$limit,'REQ_DELIVERY',
                                    ($sort=='REQ_DELIVERY' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Req. Delivery</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$fromDate,$toDate,$limit,'CREATED_AT',
                                    ($sort=='CREATED_AT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                    <span>Since</span>

                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                                $hasRequest = !empty($row['REQUEST_ID']);
                            ?>

                            <tr>
                                <td class="orderNo">
                                    #<?php echo htmlspecialchars($row['ORDER_NO']); ?>
                                </td>

                                <td class="customer">
                                    <?php echo htmlspecialchars($row['CUSTOMER_NAME_SNAPSHOT']); ?>
                                </td>

                                 <td class="type">
                                    <span class="badge type-<?= strtolower($row['ORDER_TYPE']) ?>">
                                        <?= htmlspecialchars($row['ORDER_TYPE']); ?>
                                    </span>
                                </td>

                                <td class="items">
                                    <?php if ($row['ORDER_TYPE'] == 'Custom'): ?>
                                        Custom Order
                                    <?php else: ?>
                                        <?= $row['ITEMS'] ?? 0 ?> item<?= $row['ITEMS'] == 1 ? '' : 's' ?>
                                    <?php endif; ?>
                                </td>
                       
                                <td class="total">
                                    RM <?php echo htmlspecialchars($row['TOTAL_AMOUNT']); ?>
                                </td>

                                <td class="reqDelivery">
                                    <?= date("d M Y", strtotime($row['DELIVERY_DATE'])) ?>
                                    <br>
                                    <small><?= htmlspecialchars($row['DELIVERY_SLOT_SNAPSHOT']) ?></small>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['ORDER_STATUS']) ?>">
                                        <?= htmlspecialchars($row['ORDER_STATUS']); ?>
                                    </span>
    
                                    <?php if ($row['ORDER_STATUS'] === 'REFUNDED'): ?>
                                        <div style="font-size: 10px; color: #28a745; margin-top: 4px;">
                                            <i class="bi bi-check-circle-fill"></i> Refund Successful
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="since">
                                    <?= date("d M Y, H:i", strtotime($row['CREATED_AT'])); ?>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <button class="update-btn" onclick="openStatusModal(<?= $row['ORDER_ID'] ?>, '<?= $row['ORDER_STATUS'] ?>')">
                                                <i class="bi bi-arrow-repeat"></i>Update Status
                                            </button>

                                            <button class="cancel-btn" onclick="openRefundModal(<?= $row['ORDER_ID'] ?>,'<?= $row['ORDER_NO'] ?>',<?= $row['TOTAL_AMOUNT'] ?>,<?= $row['REQUEST_ID'] ? 'true' : 'false' ?>,<?= $row['REFUND_AMOUNT'] ?? 0 ?>)">
                                                <i class="bi bi-x-circle-fill"></i>
                                                <?= !empty($row['REQUEST_ID']) ? 'Refund Order' : 'Cancel Order' ?>
                                           </button> <!--when click automatically open refund-->
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
                    <h3>Update Order Status</h3>

                    <form id="statusForm">
                        <input type="hidden" name="order_id" id="modalOrderId">

                        <select name="status" id="modalStatus" required>
                            <option value="PROCESSING">Processing</option>
                            <option value="READY">Ready</option>
                            <option value="COMPLETED">Completed</option>
                            <option value="REFUNDED">Refunded</option>
                        </select>

                        <div>
                            <button type="submit" class="save-btn">Save</button>
                            <button type="button" onclick="closeModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Cancel & Refund Pop Up -->
            <div id="refundModal" class="modal">
                <div class="modal-content">
                    
                    <h3 id="refundModalTitle">Cancel & Refund Order</h3>

                    <p id="refundInfo" style="font-size: 14px; color: #666; margin-bottom: 15px;"></p>
        
                    <form id="refundForm">
                        <div id="refundAmountSection" style="margin-bottom:15px;">

                            <label>Refund Amount (RM):</label>

                            <input type="number" name="refund_amount" id="refundAmount" step="0.01" min="0" 
                                    style="
                                       width:100%;
                                       padding:8px;
                                       margin-top:5px;
                                       border-radius:5px;
                                       border:1px solid #ccc;
                                    "
                            >

                        </div>
                        <input type="hidden" name="order_id" id="refundOrderId">
            
                        <label for="refundReason">Reason:</label>
                        <textarea name="reason" id="refundReason" maxlength="300" required style="width: 100%; height: 80px; margin-top: 5px; padding: 8px; border-radius: 5px; border: 1px solid #ccc;"></textarea>
                        <div class="text-cont">
                            <span class="text-hint">Maximum 300 characters</span>
                        </div>
                        <div>
                            <button type="submit" class="save-btn">Confirm Refund</button>
                            <button type="button" onclick="closeRefundModal()">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$type,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$type,$fromDate,$toDate,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$type,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$type,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$type,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
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
        url.searchParams.delete("type");
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

// Open Status POP UP
function openStatusModal(orderId, currentStatus) {

    const modal = document.getElementById("statusModal");
    modal.classList.add("show");

    document.getElementById("modalOrderId").value = orderId;
    document.getElementById("modalStatus").value = currentStatus;
}

// Close status pop up
function closeModal() {
    document.getElementById("statusModal").classList.remove("show");
}

// Update status (submit via AJAX)
document.getElementById("statusForm").addEventListener("submit", function(e){
    e.preventDefault();

    const formData = new FormData(this);

    fetch("update_order.php", {
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

// click outside then close
window.addEventListener("click", function (e) {
    const statusModal = document.getElementById("statusModal");
    const refundModal = document.getElementById("refundModal");

    if (e.target === statusModal) {
        closeModal();
    }

    if (e.target === refundModal) {
        closeRefundModal();
    }
});

// Open refund pop up
function openRefundModal(
    orderId,
    orderNo,
    amount,
    hasRefundRequest,
    refundAmount
) {

    const modal = document.getElementById("refundModal");
    modal.classList.add("show");

    document.getElementById("refundOrderId").value = orderId;
    document.getElementById("refundReason").value = "";

    const refundAmountInput = document.getElementById("refundAmount");
    const refundAmountSection = document.getElementById("refundAmountSection");
    const title = document.getElementById("refundModalTitle");

    // Have refund request and is APPROVED
    if (hasRefundRequest) {

        title.innerText = "Refund Order";

        refundAmountInput.value = refundAmount;
        refundAmountInput.readOnly = true;
        refundAmountSection.style.opacity = "0.7";

        document.getElementById("refundInfo").innerText =
            `Order: #${orderNo} | Approved Refund Amount: RM${refundAmount}`;

    // No refund request from customer or refund request is still PENDING / REJECTED, but admin cancel & refund himself 
    } else {

        title.innerText = "Cancel & Refund Order";

        refundAmountInput.value = amount;
        refundAmountInput.readOnly = false;
        refundAmountInput.max = amount;
        refundAmountSection.style.opacity = "1";

        document.getElementById("refundInfo").innerText =
            `Order: #${orderNo} | Max Refund Amount: RM${amount}`;
    }
}

// Close refund popup
function closeRefundModal() {
    document.getElementById("refundModal").classList.remove("show");
}

// Refund money (Submit via AJAX)
document.getElementById("refundForm").addEventListener("submit", function(e) {
    e.preventDefault();

    if (!confirm("Are you sure? This will refund the full amount to the customer's wallet.")) {
        return;
    }

    const formData = new FormData(this);

    fetch("process_refund.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        const result = data.trim();

        if (result === "ok") {
            alert("Refund Successful!");
            location.reload();
        } else {
            alert("Error: " + result);
        }
    })
    .catch(err => {
        alert("Network error.");
        console.error(err);
    });
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