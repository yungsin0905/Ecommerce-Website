<?php
require_once("config.php");
session_start();

date_default_timezone_set('Asia/Kuala_Lumpur'); 
$conn->query("SET time_zone = '+08:00'");

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

//page title
$pageTitle = "Manage Vouchers";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';

// sorting
$sort = $_GET['sort'] ?? 'START_DATE';
$order = $_GET['order'] ?? 'DESC';

$allowedSort = ['VOUCHER_NAME','DISCOUNT_RATE','MIN_SPEND','START_DATE','EXPIRY_DATE'];
$allowedOrder = ['ASC','DESC'];

if (!in_array($sort, $allowedSort)) $sort = 'START_DATE';
if (!in_array($order, $allowedOrder)) $order = 'DESC';

// pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 5);

$allowedLimits = [5,10,30,50,100];
if (!in_array($limit, $allowedLimits)) $limit = 5;

$offset = ($page - 1) * $limit;

// search, filter conditions
$where = [];
$params = [];
$types = "";

$where[] = "v.IS_DELETED = 0";

if ($search != '') {
    $where[] = "(v.VOUCHER_NAME LIKE ? OR v.VOUCHER_CODE LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

if ($status != '') {
    $where[] = "(
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = ?";

    $params[] = $status;
    $types .= "s";
}

if ($type != '') {
    $where[] = "v.VOUCHER_TYPE = ?";
    $params[] = $type;
    $types .= "s";
}

$whereSql = " WHERE " . implode(" AND ", $where);

// Get tiers from membership_tier table
$tiersResult = $conn->query("SELECT TIER_ID, TIER_NAME FROM membership_tier WHERE STATUS = 'Active' ORDER BY MIN_SPENT ASC");
$allTiers = [];
if ($tiersResult) {
    while ($tRow = $tiersResult->fetch_assoc()) {
        $allTiers[] = $tRow; 
    }
}

// summary cards
function getCount($conn, $sql, $type = "", $param = null) {
    $stmt = $conn->prepare($sql);
    if ($type && $param !== null) {
        $stmt->bind_param($type, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// ACTIVE
$totalActive = getCount($conn, "
    SELECT COUNT(*) AS c
    FROM voucher v
    WHERE v.IS_DELETED = 0
    AND (
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = 'Active'
")['c'];

// INACTIVE
$totalInactive = getCount($conn, "
    SELECT COUNT(*) AS c
    FROM voucher v
    WHERE v.IS_DELETED = 0
    AND (
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = 'Inactive'
")['c'];

// UPCOMING
$totalUpcoming = getCount($conn, "
    SELECT COUNT(*) AS c
    FROM voucher v
    WHERE v.IS_DELETED = 0
    AND (
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = 'Upcoming'
")['c'];

// EXPIRED
$totalExpired = getCount($conn, "
    SELECT COUNT(*) AS c
    FROM voucher v
    WHERE v.IS_DELETED = 0
    AND (
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = 'Expired'
")['c'];

// FULLY REDEEMED
$totalFullyRedeemed = getCount($conn, "
    SELECT COUNT(*) AS c
    FROM voucher v
    WHERE v.IS_DELETED = 0
    AND (
        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END
    ) = 'Fully Redeemed'
")['c'];

// get total rows for pagination
$countSql = "
SELECT COUNT(*) AS total
FROM voucher v
LEFT JOIN membership_tier t ON v.TIER_ID = t.TIER_ID
$whereSql
";

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $limit));

// fetch voucher list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    v.*, 
    t.TIER_NAME,
    CASE
        WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
        WHEN NOW() < v.START_DATE THEN 'Upcoming'
        WHEN v.EXPIRY_DATE IS NOT NULL AND NOW() > v.EXPIRY_DATE THEN 'Expired'
        WHEN v.MAX_USAGE != -1 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
        ELSE 'Active'
    END AS FINAL_STATUS
    FROM voucher v
    LEFT JOIN membership_tier t ON v.TIER_ID = t.TIER_ID
$whereSql
ORDER BY $sort $order
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
function buildUrl($page,$search,$status,$type,$limit,$sort,$order){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "type"=>$type,
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
    <title>Manage Vouchers</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
.search-box {
    width: 270px;
}

.status-upcoming {
    background: #e5f0ff;
    color: #3366b3;
    border: 1px solid #bcd3ff;
}

.status-expired {
    background: #f0f0f0;
    color: #666666;
    border: 1px solid #d4d4d4;
}

.status-fullyredeemed {
    background: #fff4e5;
    color: #b36b00;
    border: 1px solid #ffd08a;
}

.type-public {
    background: #d4ffe1;
    color: #1d7a3e;
}

.type-tier {
    background: #f3e8ff;
    color: #7d38b9;
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
                    <p class="mgmt-card-title">Active</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalActive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Inactive</P>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalInactive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Upcoming</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalUpcoming) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Expired</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalExpired) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Fully Redeemed</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalFullyRedeemed) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by code(without '#'), or name" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    
                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Active" <?= $status=='Active'?'selected':'' ?>>Active</option>
                        <option value="Inactive" <?= $status=='Inactive'?'selected':'' ?>>Inactive</option>
                        <option value="Upcoming" <?= $status=='Upcoming'?'selected':'' ?>>Upcoming</option>
                        <option value="Expired" <?= $status=='Expired'?'selected':'' ?>>Expired</option>
                        <option value="Fully Redeemed" <?= $status=='Fully Redeemed'?'selected':'' ?>>Fully Redeemed</option>
                    </select>

                    <select name="type" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Type</option>
                        <option value="Public" <?= $type=='Public'?'selected':'' ?>>Public</option>
                        <option value="Tier" <?= $type=='Tier'?'selected':'' ?>>Tier</option>
                    </select>

                    <?php if (!empty($search) || !empty($status) || !empty($type)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <a href="add_voucher.php" class="add-btn">
                        <i class="bi bi-plus-lg me-1"></i>Add Voucher
                    </a>

                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="code">Code</label>
                        <label><input type="checkbox" checked data-col="name">Name</label>
                        <label><input type="checkbox" checked data-col="discount">Discount</label>
                        <label><input type="checkbox" checked data-col="tier">Tier</label>
                        <label><input type="checkbox" checked data-col="type">Type</label>
                        <label><input type="checkbox" checked data-col="minSpend">Min Spend</label>
                        <label><input type="checkbox" checked data-col="usage">Usage</label>   <!-- max count / used count -->
                        <label><input type="checkbox" checked data-col="startDate">Start Date</label>
                        <label><input type="checkbox" checked data-col="expiryDate">Expiry Date</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="code">Code</th>
                            
                            <th class="name">
                                <a class="sort-link <?= $sort=='VOUCHER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'VOUCHER_NAME',
                                    ($sort=='VOUCHER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="discount">
                                <a class="sort-link <?= $sort=='DISCOUNT_RATE' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'DISCOUNT_RATE',
                                    ($sort=='DISCOUNT_RATE' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Discount</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="tier">Tier</th>

                            <th class="type">Type</th>

                            <th class="minSpend">
                                <a class="sort-link <?= $sort=='MIN_SPEND' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'MIN_SPEND',
                                    ($sort=='MIN_SPEND' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Min Spend</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="usage">Usage</th>

                            <th class="startDate">
                                <a class="sort-link <?= $sort=='START_DATE' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'START_DATE',
                                    ($sort=='START_DATE' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Start Date</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="expiryDate">
                                <a class="sort-link <?= $sort=='EXPIRY_DATE' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'EXPIRY_DATE',
                                    ($sort=='EXPIRY_DATE' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Expiry Date</span>
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
                                <td class="code">
                                    #<?= htmlspecialchars($row['VOUCHER_CODE']) ?>
                                </td>

                                <td class="name">
                                    <?= htmlspecialchars($row['VOUCHER_NAME']) ?>
                                </td>

                                <td class="discount">
                                    <?= $row['DISCOUNT_RATE'] ?>%
                                </td>

                                <td class="tier">
                                    <?= htmlspecialchars($row['TIER_NAME'] ?? 'All') ?>
                                </td>

                                <td class="type">
                                    <span class="badge type-<?= strtolower($row['VOUCHER_TYPE']) ?>">
                                        <?= htmlspecialchars($row['VOUCHER_TYPE']) ?>
                                    </span>
                                </td>

                                <td class="minSpend">
                                    RM <?= number_format($row['MIN_SPEND'], 2) ?>
                                </td>

                                <td class="usage">
                                    <?= $row['USED_COUNT'] ?> / 
                                    <?= $row['MAX_USAGE'] == -1 ? '∞' : $row['MAX_USAGE'] ?>
                                </td>

                                <td class="startDate">
                                    <?= date("d M Y, H:i", strtotime($row['START_DATE'])) ?>
                                </td>

                                <td class="expiryDate">
                                    <?= !empty($row['EXPIRY_DATE']) ? date("d M Y, H:i", strtotime($row['EXPIRY_DATE'])) : "Claim-Based"?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower(str_replace(' ', '', $row['FINAL_STATUS'])) ?>">
                                        <?= htmlspecialchars($row['FINAL_STATUS']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_voucher.php?voucher_id=<?= $row['VOUCHER_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <a href="edit_voucher.php?voucher_id=<?= $row['VOUCHER_ID'] ?>" class="edit-btn">
                                                <i class="bi bi-pencil-square"></i>Edit
                                            </a>

                                            <button class="delete-btn" data-id="<?= $row['VOUCHER_ID'] ?>">
                                                <i class="bi bi-trash"></i>Delete
                                            </button>
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
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$type,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$type,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$type,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$type,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$type,$limit,$sort,$order) ?>">
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
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">

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
        url.searchParams.delete("type");
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

// delete voucher
document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", function () {

        const voucherId = this.dataset.id; 
        const row = this.closest("tr");

        if (!confirm("Are you sure you want to delete this voucher?")) {
            return;
        }

        fetch("delete_voucher.php?voucher_id=" + voucherId)
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                row.remove();
                showToast("success", "Voucher deleted successfully");

            } else if (data.trim() === "error_used") {
                showToast("error", "Cannot delete: voucher already used in orders");

            } else {
                showToast("error", "Delete failed");
            } 
        })
        .catch(err => {
            console.error(err);
            showToast("error", "Network error");
        });
    });
});

// Show toast
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