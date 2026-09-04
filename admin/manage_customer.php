<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Manage Customers";

// search, filter
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$tier_id = isset($_GET['tier']) && $_GET['tier'] !== '' ? (int)$_GET['tier'] : '';

// sorting
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';

$allowedSort = ['CUSTOMER_ID', 'CREATED_AT', 'CUSTOMER_NAME', 'TOTAL_SPENT', 'WALLET_BALANCE'];
$allowedOrder = ['ASC', 'DESC'];

if (!in_array($sort, $allowedSort)) $sort = 'CREATED_AT';
if (!in_array($order, $allowedOrder)) $order = 'DESC';

// pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 5);

$allowedLimits = [5,10,30,50,100];
if (!in_array($limit, $allowedLimits)) $limit = 5;

$offset = ($page - 1) * $limit;

// helper
function getCount($conn, $sql, $type = "", $param = null) {
    $stmt = $conn->prepare($sql);
    if ($type && $param !== null) {
        $stmt->bind_param($type, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

// Summary cards
$totalActive = getCount($conn, "SELECT COUNT(*) AS c FROM customer WHERE STATUS='Active'")['c'];
$totalSuspended = getCount($conn, "SELECT COUNT(*) AS c FROM customer WHERE STATUS='Suspended'")['c'];

// search, filter conditions
$where = [];
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(c.CUSTOMER_NAME LIKE ? OR c.EMAIL LIKE ? OR c.PHONE LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "sss";
}

if ($status != '') {
    $where[] = "c.STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($tier_id !== '') {
    $where[] = "c.TIER_ID = ?";
    $params[] = $tier_id;
    $types .= "i"; 
}

$whereSql = count($where) ? " WHERE " . implode(" AND ", $where) : "";

// Get tiers from membership_tier table
$tiersResult = $conn->query("SELECT TIER_ID, TIER_NAME FROM membership_tier WHERE STATUS = 'Active' ORDER BY MIN_SPENT ASC");
$allTiers = [];
if ($tiersResult) {
    while ($tRow = $tiersResult->fetch_assoc()) {
        $allTiers[] = $tRow; 
    }
}

// get total rows for pagination
$countSql = "
SELECT COUNT(*) AS total
FROM customer c
LEFT JOIN membership_tier t ON c.TIER_ID = t.TIER_ID
$whereSql
";

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $limit));

// fetch customer list (With Filter, Sort, Pagination)
$listSql = "
SELECT c.*, t.TIER_NAME
FROM customer c
LEFT JOIN membership_tier t ON c.TIER_ID = t.TIER_ID
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
function buildUrl($page,$search,$status,$tier_id,$limit,$sort,$order) {
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "tier" => $tier_id, 
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
    <title>Manage Customers</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
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
                    <p class="mgmt-card-title">Suspended</P>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalSuspended) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by name, email, or phone" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Status</option>
                      <option value="Active" <?php echo ($status == 'Active') ? 'selected' : ''; ?>>Active</option>
                      <option value="Suspended" <?php echo ($status == 'Suspended') ? 'selected' : '' ?>>Suspended</option>
                    </select>

                    <select name="tier" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Tier</option>
                        <?php foreach ($allTiers as $t): ?>
                            <option value="<?= $t['TIER_ID'] ?>" <?php echo ($tier_id == $t['TIER_ID']) ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($t['TIER_NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (!empty($search) || !empty($status) || !empty($tier_id)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont">
                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="id">ID</label>
                        <label><input type="checkbox" checked data-col="name">Name</label>
                        <label><input type="checkbox" checked data-col="email">Email</label>
                        <label><input type="checkbox" checked data-col="phone">Phone</label>
                        <label><input type="checkbox" checked data-col="tier">Tier</label>
                        <label><input type="checkbox" checked data-col="spent">Total Spent</label>
                        <label><input type="checkbox" checked data-col="balance">Wallet Balance</label>
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
                            <th class="id">
                                <a class="sort-link <?= $sort=='CUSTOMER_ID' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$tier_id,$limit,'CUSTOMER_ID',
                                    ($sort=='CUSTOMER_ID' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>ID</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="name">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$tier_id,$limit,'CUSTOMER_NAME',
                                   ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                   <span>Name</span>

                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="email">Email</th>

                            <th class="phone">Phone</th>

                            <th class="tier">Tier</th>

                            <th class="spent">
                                <a class="sort-link <?= $sort=='TOTAL_SPENT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$tier_id,$limit,'TOTAL_SPENT',
                                    ($sort=='TOTAL_SPENT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                    <span>Total Spent</span>

                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="balance">
                                <a class="sort-link <?= $sort=='WALLET_BALANCE' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$tier_id,$limit,'WALLET_BALANCE',
                                    ($sort=='WALLET_BALANCE' && $order=='ASC') ? 'DESC' : 'ASC') ?>">

                                    <span>Wallet Balance</span>

                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$tier_id,$limit,'CREATED_AT',
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
                                <td class="id">
                                    <?php echo htmlspecialchars($row['CUSTOMER_ID']); ?>
                                </td>

                                <td class="name">
                                    <?php echo htmlspecialchars($row['CUSTOMER_NAME']); ?>
                                </td>

                                <td class="email">
                                    <?php echo htmlspecialchars($row['EMAIL']); ?>
                                </td>

                                <td class="phone">
                                    <?php if (!empty($row['PHONE'])): ?>
                                        <?php echo htmlspecialchars($row['PHONE']); ?>
                                    <?php else: ?>
                                        <?php echo 'N/A'; ?>
                                    <?php endif; ?>
                                </td>

                                <td class="tier">
                                    <?php 
                                        $tierName = $row['TIER_NAME'] ?? 'None';
        
                                        // 1. Use md5 hashing so even a small difference in text generates a very different value
                                        $hashNum = hexdec(substr(md5($tierName), 0, 7)); 
                                        // 2. Limit the hue value between 0–359 degrees (full color wheel)
                                        $hue = $hashNum % 360; 
                                        // 3. Fixed saturation and lightness for soft pastel badge colors
                                        $bgColor = "hsl({$hue}, 70%, 85%)";
                                        // 4. Darker text color using the same hue for better readability
                                        $textColor = "hsl({$hue}, 70%, 25%)";
                                    ?>
    
                                    <span class="badge" style="background-color: <?= $bgColor ?>; color: <?= $textColor ?>; border: 1px solid hsl(<?= $hue ?>, 50%, 75%); font-weight: 600;">
                                        <?= htmlspecialchars($tierName) ?>
                                    </span>
                                </td>

                                <td class="spent">
                                    <?php echo htmlspecialchars($row['TOTAL_SPENT']); ?>
                                </td>

                                <td class="balance">
                                    <?php echo htmlspecialchars($row['WALLET_BALANCE']); ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['STATUS']) ?>">
                                        <?= htmlspecialchars($row['STATUS']); ?>
                                    </span>
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
                                            <a href="view_customer.php?customer_id=<?= $row['CUSTOMER_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <button class="update-btn" onclick="openStatusModal(<?= $row['CUSTOMER_ID'] ?>, '<?= $row['STATUS'] ?>')">
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
                    <h3>Update Customer Status</h3>

                    <form id="statusForm">
                        <input type="hidden" name="customer_id" id="modalCustomerId">

                        <select name="status" id="modalStatus" required>
                            <option value="Active">Active</option>
                            <option value="Suspended">Suspended</option>
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
                        <input type="hidden" name="tier" value="<?= htmlspecialchars($tier_id) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$tier_id,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$tier_id,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$tier_id,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$tier_id,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$tier_id,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="tier" value="<?= htmlspecialchars($tier_id) ?>">
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
        url.searchParams.delete("tier");
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
function openStatusModal(customerId, currentStatus) {

    const modal = document.getElementById("statusModal");
    modal.classList.add("show");

    document.getElementById("modalCustomerId").value = customerId;
    document.getElementById("modalStatus").value = currentStatus;
}

function closeModal() {
    document.getElementById("statusModal").classList.remove("show");
}

// Status update (submit via AJAX)
document.getElementById("statusForm").addEventListener("submit", function(e){
    e.preventDefault();

    const formData = new FormData(this);

    fetch("update_customer.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            showToast("success", data.message || "Status updated successfully!");
            closeModal();
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            showToast("error", data.message || "Update failed");
        }
    })
    .catch(err => {
        console.error(err);
        showToast("error", "Server error");
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