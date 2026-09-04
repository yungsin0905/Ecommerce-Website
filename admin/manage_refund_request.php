<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Refund Requests";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// sorting
$sortMap = [
    'CUSTOMER_NAME' => 'cu.CUSTOMER_NAME',
    'PAYMENT_AMOUNT' => 'p.PAYMENT_AMOUNT',
    'CREATED_AT' => 'c.CREATED_AT'
];

$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';

if (!isset($sortMap[$sort])) $sort = 'CREATED_AT';
if (!in_array($order, ['ASC', 'DESC'])) $order = 'DESC';

$orderBy = $sortMap[$sort];

// pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 5);

$allowedLimits = [5,10,30,50,100];
if (!in_array($limit, $allowedLimits)) $limit = 5;

$offset = ($page - 1) * $limit;

// Summary cards
function getCount($conn, $sql, $types = "", $params = []) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("SQL Error (COUNT): " . $conn->error);
    }

    if ($types && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    return $res['total'] ?? 0;
}

$totalPending = getCount($conn, "
    SELECT COUNT(*) total 
    FROM refund_request 
    WHERE REQUEST_STATUS='PENDING'
");

$totalApproved = getCount($conn, "
    SELECT COUNT(*) total 
    FROM refund_request 
    WHERE REQUEST_STATUS='APPROVED'
");

$totalRejected = getCount($conn, "
    SELECT COUNT(*) total 
    FROM refund_request 
    WHERE REQUEST_STATUS='REJECTED'
");

// search, filter conditions
$where = [];
$whereParams = [];
$whereTypes = "";

/* SEARCH */
if ($search != '') {
    $where[] = "(
        c.REQUEST_ID LIKE ?
        OR o.ORDER_NO LIKE ?
        OR cu.CUSTOMER_NAME LIKE ?
        OR cu.EMAIL LIKE ?
    )";

    $s = "%$search%";
    $whereParams = [$s,$s,$s,$s];
    $whereTypes = "ssss";
}

if ($status != '') {
    $where[] = "c.REQUEST_STATUS = ?";
    $whereParams[] = $status;
    $whereTypes .= "s";
}

$whereSql = count($where) > 0
    ? "WHERE " . implode(" AND ", $where)
    : "";

// get total rows for pagination
$countSql = "
SELECT COUNT(*) total
FROM refund_request c
LEFT JOIN customer cu ON c.CUSTOMER_ID = cu.CUSTOMER_ID
LEFT JOIN orders o ON c.ORDER_ID = o.ORDER_ID
LEFT JOIN payment p ON c.ORDER_ID = p.ORDER_ID
$whereSql
";

$totalRows = getCount($conn, $countSql, $whereTypes, $whereParams);

$totalPages = max(1, ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// fetch refund request list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    c.REQUEST_ID,
    c.REQUEST_STATUS,
    c.REASON,
    c.CREATED_AT,

    o.ORDER_ID,

    cu.CUSTOMER_NAME,
    cu.EMAIL AS CUSTOMER_EMAIL,

    o.ORDER_NO,
    p.PAYMENT_AMOUNT,
    r.REFUND_STATUS

FROM refund_request c
LEFT JOIN customer cu ON c.CUSTOMER_ID = cu.CUSTOMER_ID
LEFT JOIN orders o ON c.ORDER_ID = o.ORDER_ID
LEFT JOIN payment p ON c.ORDER_ID = p.ORDER_ID
LEFT JOIN refund r ON r.REQUEST_ID = c.REQUEST_ID

$whereSql
ORDER BY $orderBy $order
LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($listSql);

/* safety check */
if (!$stmt) {
    die("SQL Error (LIST): " . $conn->error);
}

$listParams = array_merge($whereParams, [$limit, $offset]);
$listTypes = $whereTypes . "ii";

$stmt->bind_param($listTypes, ...$listParams);
$stmt->execute();
$result = $stmt->get_result();

// Build URL (or Pagination & Sorting Links)
function buildUrl($page,$search,$status,$limit,$sort,$order){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "limit"=>$limit,
        "sort"=>$sort,
        "order"=>$order
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Requests</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
.search-box {
    width: 380px;
}

.status-pending {
    background: #fff8e6;
    color: #b7791f;
}

.status-approved {
    background: #e6ffed;
    color: #1f7a3f;
}

.status-rejected {
    background: #ffe6e6;
    color: #b30000;
}

.refund-status{
    font-size: 10px;
    margin-top: 4px;
}

.refund-status-pending{
    color: #b7791f;
}

.refund-status-success{
    color: #28a745;
}

.refund-status-failed{
    color: #dc3545;
}

.reason {
    max-width: 200px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.reason:hover {
    cursor: pointer;
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
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalPending) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Approved</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalApproved) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Rejected</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalRejected) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by order no(without '#'), customer name, or email" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="PENDING"   <?= ($status == 'PENDING')   ? 'selected' : '' ?>>Pending</option>
                        <option value="APPROVED"  <?= ($status == 'APPROVED')  ? 'selected' : '' ?>>Approved</option>
                        <option value="REJECTED"  <?= ($status == 'REJECTED')  ? 'selected' : '' ?>>Rejected</option>
                    </select>

                    <?php if (!empty($search) || !empty($status)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="orderNo">Order No</label>
                        <label><input type="checkbox" checked data-col="custName">Cust Name</label>
                        <label><input type="checkbox" checked data-col="custEmail">Cust Email</label>
                        <label><input type="checkbox" checked data-col="reason">Reason</label>
                        <label><input type="checkbox" checked data-col="amount">Amount</label>  <!--from payment table-->
                        <label><input type="checkbox" checked data-col="status">Status</label>
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

                            <th class="custName">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$limit,'CUSTOMER_NAME',
                                    ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Cust Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="custEmail">
                                Cust Email
                            </th>

                            <th class="reason">Reason</th>
                            
                            <th class="amount">
                                <a class="sort-link <?= $sort=='PAYMENT_AMOUNT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$limit,'PAYMENT_AMOUNT',
                                    ($sort=='PAYMENT_AMOUNT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Pay. Amount</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$limit,'CREATED_AT',
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
                            <tr>
                                <td class="orderNo">
                                    <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="order-link">
                                        #<?= htmlspecialchars($row['ORDER_NO']) ?>
                                    </a>
                                </td>

                                <td class="custName">
                                    <?= $row['CUSTOMER_NAME'] ?>
                                </td>

                                <td class="custEmail">
                                    <?= htmlspecialchars($row['CUSTOMER_EMAIL']) ?>
                                </td>

                                <td class="reason" title="<?= htmlspecialchars($row['REASON'], ENT_QUOTES) ?>">
                                    <?= htmlspecialchars($row['REASON']) ?>
                                </td>

                                <td class="amount">
                                    RM <?= number_format($row['PAYMENT_AMOUNT'],2) ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['REQUEST_STATUS']) ?>">
                                        <?= htmlspecialchars($row['REQUEST_STATUS']); ?>
                                    </span>
    
                                    <?php if ($row['REQUEST_STATUS'] === 'APPROVED'): ?>

                                        <?php
                                            $refundStatus = $row['REFUND_STATUS'];

                                            $class = '';
                                            if ($refundStatus === 'PENDING') $class = 'refund-status-pending';
                                            elseif ($refundStatus === 'SUCCESSFUL') $class = 'refund-status-success';
                                            elseif ($refundStatus === 'FAILED') $class = 'refund-status-failed';
                                        ?>

                                        <div class="refund-status <?= $class ?>">
                                            <?php if ($refundStatus === 'PENDING'): ?>
                                                <i class="bi bi-clock"></i>
                                            <?php elseif ($refundStatus === 'SUCCESSFUL'): ?>
                                                <i class="bi bi-check-circle-fill"></i>
                                            <?php elseif ($refundStatus === 'FAILED'): ?>
                                                <i class="bi bi-x-circle-fill"></i>
                                            <?php endif; ?>

                                            <?= htmlspecialchars($refundStatus); ?>
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
                                            <a href="process_refund_request.php?request_id=<?= $row['REQUEST_ID'] ?>" class="update-btn">

                                                <?php if ($row['REQUEST_STATUS'] == 'PENDING'): ?>
                                                    <i class="bi bi-arrow-repeat"></i> Process

                                                <?php elseif ($row['REFUND_STATUS'] == 'PENDING' || $row['REFUND_STATUS'] == 'FAILED'): ?>
                                                    <i class="bi bi-cash-coin"></i> Refund

                                                <?php else: ?>
                                                    <i class="bi bi-eye"></i> View

                                                <?php endif; ?>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
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

// clear search, filter 
const clearCtrlBtn = document.getElementById("clear-ctrl-btn");
if (clearCtrlBtn) { 
    clearCtrlBtn.addEventListener("click", function () {
        const url = new URL(window.location.href);
        url.searchParams.delete("search");
        url.searchParams.delete("status");
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

// Page limit persistence (safe version)
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

// change page limit
if (limitSelect) {
    limitSelect.addEventListener("change", function() {
        const selectedValue = this.value;
        
        localStorage.setItem("preferred_limit", selectedValue);
        
        const url = new URL(window.location.href);
        url.searchParams.set("limit", selectedValue);
        url.searchParams.set("page", 1); 

        window.location.href = url.toString();
    });
}
</script>

</body>
</html>