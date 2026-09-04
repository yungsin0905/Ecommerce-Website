<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
} 
$admin_id = $_SESSION['admin_id']; 

//page title
$pageTitle = "Manage Products";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$category   = $_GET['category'] ?? '';
$stock   = $_GET['stock'] ?? '';
$rating   = $_GET['rating'] ?? '';

// sorting
$sort = $_GET['sort'] ?? 'PRODUCT_ID';
$order = $_GET['order'] ?? 'DESC';
$allowedSort = ['PRODUCT_ID', 'PRODUCT_NAME', 'AVG_RATING', 'SALES_COUNT'];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'PRODUCT_ID';
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

// build stock subquery for later use in main query and summary cards
// <= 5 is low stock
$stockSubquery = "
SELECT 
    PRODUCT_ID,
    SUM(VARIANT_STOCK) AS total_stock,
    SUM(CASE WHEN VARIANT_STOCK = 0 THEN 1 ELSE 0 END) AS zero_count,
    SUM(CASE WHEN VARIANT_STOCK <= 5 AND VARIANT_STOCK > 0 THEN 1 ELSE 0 END) AS low_count,  
    COUNT(*) AS variant_count
FROM product_variant
WHERE IS_DELETED = 0
GROUP BY PRODUCT_ID
";

// summary cards
function getCount($conn, $sql, $type = "", $param = null) {
    $stmt = $conn->prepare($sql);
    if ($type && $param !== null) {
        $stmt->bind_param($type, $param);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}
$totalActive = getCount($conn, "SELECT COUNT(*) AS c FROM product WHERE PRODUCT_STATUS='Active' AND IS_DELETED = 0")['c'];
$totalInactive = getCount($conn, "SELECT COUNT(*) AS c FROM product WHERE PRODUCT_STATUS='Inactive' AND IS_DELETED = 0")['c'];

// stock summary for filter dropdown and summary cards
$stockQuery = "
SELECT 
    SUM(CASE WHEN v.total_stock = 0 THEN 1 ELSE 0 END) AS out_of_stock,
    SUM(CASE WHEN v.zero_count > 0 AND v.total_stock > 0 THEN 1 ELSE 0 END) AS some_out_of_stock,
    SUM(CASE WHEN v.low_count > 0 AND v.total_stock > 0 THEN 1 ELSE 0 END) AS some_low_stock
FROM (
    $stockSubquery
) v
JOIN product p ON p.PRODUCT_ID = v.PRODUCT_ID
WHERE p.IS_DELETED = 0
";
$stockResult = $conn->query($stockQuery); 
$stockSummary = $stockResult->fetch_assoc();

$totalOutOfStock = $stockSummary['out_of_stock'] ?? 0;
$totalSomeOut    = $stockSummary['some_out_of_stock'] ?? 0;
$totalLow        = $stockSummary['some_low_stock'] ?? 0;

// search, filter conditions
$where[] = "p.IS_DELETED = 0";
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(p.PRODUCT_NAME LIKE ? OR p.PRODUCT_ID LIKE ?)";
    $s = "%$search%";
    $params[] = $s;
    $params[] = $s;
    $types .= "ss";
}

if ($status != '') {
    $where[] = "p.PRODUCT_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($category != '') {
    $where[] = "p.CATEGORY_ID = ?";
    $params[] = $category;
    $types .= "i";
}

if ($rating != '') {
    $where[] = "p.AVG_RATING >= ?";
    $params[] = $rating;
    $types .= "d";
}

if ($stock != '') {
    if ($stock == 'Out of Stock') {
        $where[] = "COALESCE(v.total_stock,0) = 0";
    }
    elseif ($stock == 'Some Variants Out of Stock') {
        $where[] = "v.zero_count > 0 AND COALESCE(v.total_stock,0) > 0";
    }
    elseif ($stock == 'Some Variants Low Stock') {
        $where[] = "v.low_count > 0 AND COALESCE(v.total_stock,0) > 0";
    }
    elseif ($stock == 'In Stock') {
        $where[] = "COALESCE(v.total_stock,0) > 5 
                    AND v.zero_count = 0 
                    AND v.low_count = 0";
    }
}

$whereSql = count($where) ? "WHERE " . implode(" AND ", $where) : "";

// category dropdown data
$catResult = $conn->query("
    SELECT CATEGORY_ID, CATEGORY_NAME 
    FROM category 
    WHERE IS_DELETED = 0
");

// get total rows for pagination
$countSql = "
SELECT COUNT(*) AS total
FROM product p
LEFT JOIN (
    $stockSubquery
) v ON p.PRODUCT_ID = v.PRODUCT_ID
$whereSql
";
$stmtCount = $conn->prepare($countSql);
if ($params) {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];
$totalPages = max(1, ceil($totalRows / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// fetch product list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    p.*, 
    c.CATEGORY_NAME,

    -- stock summary
    COALESCE(v.total_stock, 0) AS total_stock,
    COALESCE(v.zero_count, 0) AS zero_count,
    COALESCE(v.low_count, 0) AS low_count,
    COALESCE(v.variant_count, 0) AS variant_count,
    COALESCE(av.active_variant_count, 0) AS active_variant_count,

    -- variant breakdown
    vl.variant_list,

    -- image
    img.IMAGE_PATH

FROM product p

LEFT JOIN category c 
    ON p.CATEGORY_ID = c.CATEGORY_ID

-- stock summary subquery
LEFT JOIN (
    $stockSubquery
) v 
ON p.PRODUCT_ID = v.PRODUCT_ID

-- active variant count
LEFT JOIN (
    SELECT 
        PRODUCT_ID,
        SUM(CASE WHEN VARIANT_STATUS = 'Active' THEN 1 ELSE 0 END) AS active_variant_count
    FROM product_variant
    WHERE IS_DELETED = 0
    GROUP BY PRODUCT_ID
) av
ON p.PRODUCT_ID = av.PRODUCT_ID

-- variant list subquery
LEFT JOIN (
    SELECT 
        PRODUCT_ID,
        GROUP_CONCAT(
            CONCAT(VARIANT_SIZE, ':', VARIANT_STOCK) 
            SEPARATOR '|'
        ) AS variant_list
    FROM product_variant
    WHERE IS_DELETED = 0
    GROUP BY PRODUCT_ID
) vl 
    ON p.PRODUCT_ID = vl.PRODUCT_ID

-- image
LEFT JOIN (
    SELECT 
        PRODUCT_ID, 
        MIN(IMAGE_PATH) AS IMAGE_PATH
    FROM product_images
    WHERE IS_DELETED = 0
    GROUP BY PRODUCT_ID
) img 
    ON p.PRODUCT_ID = img.PRODUCT_ID

$whereSql

ORDER BY p.$sort $order
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
function buildUrl($page,$search,$status,$category,$stock,$rating,$limit,$sort,$order){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "category"=>$category,
        "stock"=>$stock,
        "rating"=>$rating,
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
    <title>Manage Products</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
.stock-some-variants-low-stock {
    background: #fff4e6;
    color: #9f7f1e;
}

.stock-some-variants-out-of-stock {
    background: #ffe6e6;
    color: #b30000;
}

.stock-cell {
    position: relative;
    cursor: pointer;
}

.stock-dropdown {
    display: none;
    position: absolute;
    background: white;
    border: 1px solid #ddd;
    padding: 6px 10px;
    border-radius: 6px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    z-index: 10;
    min-width: 120px;
}

.stock-cell:hover .stock-dropdown {
    display: block;
}

.variant-warning {
    margin-top: 4px;
    font-size: 12px;
    color: #b45309;
    background: #fff7ed;
    padding: 4px 6px;
    border-radius: 4px;
    display: block;
    width: fit-content;
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
                    <p class="mgmt-card-title">Out of Stock</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalOutOfStock) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Some Variants Out</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalSomeOut) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Some Variants Low</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalLow) ?></p>
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

                    <select name="category" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Category</option>
                        <?php while($cat = $catResult->fetch_assoc()) { ?>
                            <option value="<?= $cat['CATEGORY_ID'] ?>"
                                <?= ($category == $cat['CATEGORY_ID']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['CATEGORY_NAME']) ?>
                            </option>
                        <?php } ?>
                    </select>

                    <select name="stock" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Stock</option>
                        <option value="In Stock" <?= ($stock == 'In Stock') ? 'selected' : '' ?>>In Stock</option>
                        <option value="Some Variants Out of Stock" <?= ($stock == 'Some Variants Out of Stock') ? 'selected' : '' ?>>Some Variants Out of Stock</option>
                        <option value="Some Variants Low Stock" <?= ($stock == 'Some Variants Low Stock') ? 'selected' : '' ?>>Some Variants Low Stock</option>
                        <option value="Out of Stock" <?= ($stock == 'Out of Stock') ? 'selected' : '' ?>>Out of Stock</option>
                    </select>

                    <select name="rating" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Rating</option>
                        <option value="4" <?php echo ($rating == 4) ? 'selected' : '' ?>>4.0 & above</option>
                        <option value="3" <?php echo ($rating == 3) ? 'selected' : '' ?>>3.0 & above</option>
                        <option value="2" <?php echo ($rating == 2) ? 'selected' : '' ?>>2.0 & above</option>
                        <option value="1" <?php echo ($rating == 1) ? 'selected' : '' ?>>1.0 & above</option>
                    </select>

                    <?php if (!empty($search) || !empty($status) || !empty($category) || !empty($stock) || !empty($rating)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <a href="add_product.php" class="add-btn">
                        <i class="bi bi-plus-lg me-1"></i>Add Product
                    </a>

                    <button id="toggle-col-btn">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="id">ID</label>
                        <label><input type="checkbox" checked data-col="image">Image</label>
                        <label><input type="checkbox" checked data-col="name">Name</label>
                        <label><input type="checkbox" checked data-col="category">Category</label>
                        <label><input type="checkbox" checked data-col="variant">Stock</label>
                        <label><input type="checkbox" checked data-col="stock">Stock Status</label>
                        <label><input type="checkbox" checked data-col="rating">Rating</label>
                        <label><input type="checkbox" checked data-col="sales">Sales</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <!--product id-->
                            <th class="id">
                                <a class="sort-link <?= $sort=='PRODUCT_ID' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$category,$stock,$rating,$limit,'PRODUCT_ID',
                                    ($sort=='PRODUCT_ID' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>ID</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--product image-->
                            <th class="image">Image</th>
                            
                            <!--product name-->
                            <th class="name">
                                <a class="sort-link <?= $sort=='PRODUCT_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$category,$stock,$rating,$limit,'PRODUCT_NAME',
                                    ($sort=='PRODUCT_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--product category-->
                            <th class="category">Category</th> <!--get from category table-->


                            <!--product stock(variants) quantity-->
                            <th class="variant">Stock</th>

                            <!--product stock status-->
                            <th class="stock">Stock Status</th> <!--based on algorithm-->

                            <!--product rating-->
                            <th class="rating">
                                <a class="sort-link <?= $sort=='AVG_RATING' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$category,$stock,$rating,$limit,'AVG_RATING',
                                    ($sort=='AVG_RATING' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Avg. Rating</span>
                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--product sales-->
                            <th class="sales">
                                <a class="sort-link <?= $sort=='SALES_COUNT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$category,$stock,$rating,$limit,'SALES_COUNT',
                                    ($sort=='SALES_COUNT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Sales</span>
                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <!--product status-->
                            <th class="status">Status</th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="id">
                                    <?php echo htmlspecialchars($row['PRODUCT_ID']); ?>
                                </td>

                                <td class="image">
                                    <?php if (!empty($row['COVER_IMAGE'])): ?>
                                        <img src="<?= htmlspecialchars($row['COVER_IMAGE']) ?>" alt="Product Image" class="tbl-image"> <!--路径之后会换-->
                                    <?php else: ?>
                                        <span>N/A</span>
                                    <?php endif; ?>
                                </td>

                                <td class="name">
                                    <?php echo htmlspecialchars($row['PRODUCT_NAME']); ?>

                                    <?php if ($row['variant_count'] > 0 && $row['active_variant_count'] == 0): ?>
                                        <div class="variant-warning">
                                            ⚠ No variants active
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="category">
                                    <?php echo htmlspecialchars($row['CATEGORY_NAME']) ?>
                                </td>

                                <td class="variant">
                                    <div class="stock-cell">
                                        <span class="stock-total">
                                            <?= $row['total_stock'] ?> <i class="bi bi-caret-down-fill"></i>
                                        </span>

                                        <?php if (!empty($row['variant_list'])): ?>
                                            <div class="stock-dropdown">
                                                <?php $variants = explode('|', $row['variant_list']); foreach ($variants as $v): list($name, $qty) = explode(':', $v);?>
                                                    <div class="variant-row 
                                                        <?= ($qty == 0) ? 'out' : ($qty <= 5 ? 'low' : '') ?>">
                                                        <?= htmlspecialchars($name) ?>: <?= $qty ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <td class="stock">
                                    <?php
                                        $total = $row['total_stock'];
                                        $zero  = $row['zero_count'] ?? 0;
                                        $low   = $row['low_count'] ?? 0;

                                        if ($total == 0) {
                                            $stockStatus = "Out of Stock";
                                        }
                                        elseif ($zero > 0) {
                                            $stockStatus = "Some Variants Out of Stock";
                                        }
                                        elseif ($low > 0) {
                                            $stockStatus = "Some Variants Low Stock";
                                        }
                                        else {
                                             $stockStatus = "In Stock";
                                        }
                                    ?>
                                    <span class="badge stock-<?= strtolower(str_replace(' ','-',$stockStatus)) ?>">
                                        <?= $stockStatus ?>
                                    </span>
                                </td>

                                <td class="rating">
                                    <?php echo htmlspecialchars($row['AVG_RATING']); ?>
                                </td>

                                <td class="sales">
                                    <?php echo htmlspecialchars($row['SALES_COUNT']); ?>
                                </td>

                                <td class="status">
                                    <span class="badge status-<?= strtolower($row['PRODUCT_STATUS']) ?>">
                                        <?= htmlspecialchars($row['PRODUCT_STATUS']); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-wrapper">
                                        <button class="action-btn">
                                            <i class="bi bi-three-dots"></i>
                                        </button>

                                        <div class="action-menu">
                                            <a href="view_product.php?product_id=<?= $row['PRODUCT_ID'] ?>" class="view-btn">
                                                <i class="bi bi-eye"></i>View
                                            </a>

                                            <a href="edit_product.php?product_id=<?= $row['PRODUCT_ID'] ?>" class="edit-btn">
                                                <i class="bi bi-pencil-square"></i>Edit
                                            </a>

                                            <button class="delete-btn" data-id="<?= $row['PRODUCT_ID'] ?>">
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
                        <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                        <input type="hidden" name="stock" value="<?= htmlspecialchars($stock) ?>">
                        <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$category,$stock,$rating,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$category,$stock,$rating,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$category,$stock,$rating,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$category,$stock,$rating,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$category,$stock,$rating,$limit,$sort,$order) ?>">
                            Next ▶
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="category" value="<?= htmlspecialchars($category) ?>">
                    <input type="hidden" name="stock" value="<?= htmlspecialchars($stock) ?>">
                    <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
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
        url.searchParams.delete("category");
        url.searchParams.delete("stock");
        url.searchParams.delete("rating");
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

// delete product
document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", function () {

        const productId = this.dataset.id; // ✅ FIX: 正确变量
        const row = this.closest("tr");

        if (!confirm("Are you sure you want to delete this product?")) {
            return;
        }

        fetch("delete_product.php?product_id=" + productId)
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                row.remove();
                showToast("success", "Product deleted successfully");

            } else if (data.trim() === "error_used") {
                showToast("error", "Cannot delete: product already used in orders");
                    
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