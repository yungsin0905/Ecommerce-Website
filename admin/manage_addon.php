<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

//page title
$pageTitle = "Manage Addons";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$stock_level = $_GET['stock_level'] ?? '';

// sorting
$sort = $_GET['sort'] ?? 'ADD_ON_ID';
$order = $_GET['order'] ?? 'DESC';
$allowedSort = ['ADD_ON_ID', 'ADD_ON_NAME', 'ADD_ON_STOCK', 'ADD_ON_PRICE'];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'ADD_ON_ID';
}
if (!in_array($order, $allowedOrder)) {
    $order = 'DESC';
}

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
$totalActive = getCount($conn, "SELECT COUNT(*) AS c FROM add_on WHERE ADD_ON_STATUS='Active' AND IS_DELETED = 0")['c'];// c is alias of the COUNT(*)
$totalInactive = getCount($conn, "SELECT COUNT(*) AS c FROM add_on WHERE ADD_ON_STATUS='Inactive' AND IS_DELETED = 0")['c'];
// <= 5 is low stock
$totalLowStock = getCount($conn, "SELECT COUNT(*) AS c FROM add_on WHERE ADD_ON_STOCK > 0 AND ADD_ON_STOCK <= 5 AND IS_DELETED = 0")['c'];
$totalOutOfStock = getCount($conn, "SELECT COUNT(*) AS c FROM add_on WHERE ADD_ON_STOCK = 0 AND IS_DELETED = 0")['c'];

// search, filter conditions
$where[] = "a.IS_DELETED = 0";
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(ADD_ON_NAME LIKE ? OR ADD_ON_ID LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

if ($status != '') {
    $where[] = "ADD_ON_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($stock_level != '') {
    if ($stock_level == 'in_stock') {
        $where[] = "ADD_ON_STOCK > 10";
    } elseif ($stock_level == 'low_stock') {
        $where[] = "ADD_ON_STOCK > 0 AND ADD_ON_STOCK <= 10";
    } elseif ($stock_level == 'out_of_stock') {
        $where[] = "ADD_ON_STOCK = 0";
    }
}

$whereSql = "";
if (count($where) > 0) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

// get total rows for pagination
$countSql = "SELECT COUNT(*) AS total FROM add_on a" . $whereSql;
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

// fetch addon list (With Filter, Sort, Pagination)
$listSql = "SELECT * FROM add_on a $whereSql ORDER BY $sort $order LIMIT ? OFFSET ?";
$stmtList = $conn->prepare($listSql);
$params2 = $params;
$types2 = $types . "ii";
$params2[] = $limit;
$params2[] = $offset;
$stmtList->bind_param($types2, ...$params2);
$stmtList->execute();
$result = $stmtList->get_result();

// Build URL (or Pagination & Sorting Links)
function buildUrl($page,$search,$status,$stock_level,$limit,$sort,$order){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "stock_level"=>$stock_level,
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
    <title>Manage Addons</title>
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
                    <p class="mgmt-card-title">Inactive</P>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalInactive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Low Stock</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalLowStock) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Out of Stock</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalOutOfStock) ?></p>
                </div>
            </div>

            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by ID, or name" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo ($status == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($status == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>

                    <select name="stock_level" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Stock</option>
                        <option value="in_stock" <?php echo ($stock_level == 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
                        <option value="low_stock" <?php echo ($stock_level == 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
                        <option value="out_of_stock" <?php echo ($stock_level == 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>

                    <?php if (!empty($search) || !empty($status) || !empty($stock_level)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <a href="add_addon.php" class="add-btn">
                        <i class="bi bi-plus-lg me-1"></i>Add Addon
                    </a>

                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="id">ID</label>
                        <label><input type="checkbox" checked data-col="image">Image</label>
                        <label><input type="checkbox" checked data-col="name">Name</label>
                        <label><input type="checkbox" checked data-col="stock">Stock</label>
                        <label><input type="checkbox" checked data-col="stock_level">Stock Status</label>
                        <label><input type="checkbox" checked data-col="price">Price</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <!--addon id-->
                            <th class="id">
                                <a class="sort-link <?= $sort=='ADD_ON_ID' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$stock_level,$limit,'ADD_ON_ID',
                                    ($sort=='ADD_ON_ID' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>ID</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--addon image-->
                            <th class="image">Image</th>
                            
                            <!--addon name-->
                            <th class="name">
                                <a class="sort-link <?= $sort=='ADD_ON_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$stock_level,$limit,'ADD_ON_NAME',
                                    ($sort=='ADD_ON_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--addon stock-->
                            <th class="stock">
                                <a class="sort-link <?= $sort=='ADD_ON_STOCK' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$stock_level,$limit,'ADD_ON_STOCK',
                                    ($sort=='ADD_ON_STOCK' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Stock</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--addon stock level-->
                            <th class="stock_level">Stock Status</th>

                            <!--addon price-->
                            <th class="price">
                                <a class="sort-link <?= $sort=='ADD_ON_PRICE' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$stock_level,$limit,'ADD_ON_PRICE',
                                    ($sort=='ADD_ON_PRICE' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Price</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--addon status-->
                            <th class="status">Status</th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="id">
                                    <?php echo htmlspecialchars($row['ADD_ON_ID']); ?>
                                </td>

                                <td class="image">
                                    <?php if (!empty($row['ADD_ON_IMAGE'])): ?>
                                        <img src="<?= htmlspecialchars($row['ADD_ON_IMAGE']) ?>" alt="Addon Image" class="tbl-image">
                                    <?php else: ?>
                                        <span>N/A</span>
                                    <?php endif; ?>
                                </td>

                                <td class="name">
                                    <?php echo htmlspecialchars($row['ADD_ON_NAME']); ?>
                                </td>

                                <td class="stock">
                                    <?php echo htmlspecialchars($row['ADD_ON_STOCK']); ?>
                                </td>

                                <td class="stock_level">
                                    <?php 
                                        $stock = $row['ADD_ON_STOCK'];
                                        if ($stock > 5) {
                                            echo '<span class="badge stock-in-stock">In Stock</span>';
                                        } elseif ($stock > 0) {
                                            echo '<span class="badge stock-low-stock">Low Stock</span>';
                                        } else {
                                            echo '<span class="badge stock-out-of-stock">Out of Stock</span>';
                                        }
                                    ?>
                                </td>

                                <td class="price">
                                    RM <?php echo number_format($row['ADD_ON_PRICE'], 2); ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['ADD_ON_STATUS']) ?>">
                                        <?= htmlspecialchars($row['ADD_ON_STATUS']); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_addon.php?addon_id=<?= $row['ADD_ON_ID'] ?>" class="view-btn">
                                               <i class="bi bi-eye"></i>View
                                            </a>

                                            <a href="edit_addon.php?addon_id=<?= $row['ADD_ON_ID'] ?>" class="edit-btn">
                                                <i class="bi bi-pencil-square"></i>Edit
                                            </a>

                                            <button class="delete-btn" data-id="<?= $row['ADD_ON_ID'] ?>">
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
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="stock_level" value="<?= htmlspecialchars($stock_level) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$stock_level,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$stock_level,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$stock_level,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$stock_level,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$stock_level,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="stock_level" value="<?= htmlspecialchars($stock_level) ?>">
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

// Clear search, filter 
const clearCtrlBtn = document.getElementById("clear-ctrl-btn");
if (clearCtrlBtn) { 
    clearCtrlBtn.addEventListener("click", function () {
        const url = new URL(window.location.href);
        url.searchParams.delete("search");
        url.searchParams.delete("status");
        url.searchParams.delete("stock_level");
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

// Apply column visibility
function applyColumn(colClass, show) {
    document.querySelectorAll("." + colClass).forEach(el => {
        el.style.display = show ? "" : "none";
    });
}

// Initialize column visibility based on localStorage
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

// Reset columns
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

// Action menu toggle
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

// Close menus on outside click
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

// Page limit persistence
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
    limitSelect.addEventListener("change", function() {
        const selectedValue = this.value;
        
        localStorage.setItem("preferred_limit", selectedValue);
        
        const url = new URL(window.location.href);
        url.searchParams.set("limit", selectedValue);
        url.searchParams.set("page", 1); 

        window.location.href = url.toString();
    });
}

// Delete addon
document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", function () {

        const addonId = this.dataset.id;
        const row = this.closest("tr");

        if (!confirm("Are you sure you want to delete this add-on?")) {
            return;
        }

        fetch("delete_addon.php?addon_id=" + addonId)
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                row.remove();
                showToast("success", "Add-on deleted successfully");
                    
            } else if (data.trim() === "error_used") {
                showToast("error", "Cannot delete: addon already used in orders");

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

// show toast
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