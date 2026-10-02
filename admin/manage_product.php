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
    $where[] = "p.PRODUCT_ID IN (SELECT PRODUCT_ID FROM product_category WHERE CATEGORY_ID = ?)";
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

-- category list subquery (a product can belong to multiple categories)
LEFT JOIN (
    SELECT 
        pc.PRODUCT_ID,
        GROUP_CONCAT(c.CATEGORY_NAME SEPARATOR ', ') AS CATEGORY_NAME
    FROM product_category pc
    JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
    WHERE c.IS_DELETED = 0
    GROUP BY pc.PRODUCT_ID
) c
    ON p.PRODUCT_ID = c.PRODUCT_ID

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
            CONCAT(VARIANT_LABEL, ':', VARIANT_STOCK) 
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
    <title>Manage Products | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
:root {
    --bg-page: #f4f5f8;
    --card-bg: #ffffff;
    --text-dark: #111827;
    --text-muted: #71717a;
    --text-light: #9ca3af;
    --accent-lime: #d4f938;
    --accent-lime-hover: #c4ec29;
    --accent-lime-subtle: #f4fde6;
    --dark-pill: #18181b;
    --dark-pill-hover: #27272a;
    --border-card: #edf0f3;
    --border-subtle: #f1f3f6;
    --shadow-soft: 0 4px 20px rgba(17, 24, 39, 0.035);
    --shadow-hover: 0 10px 28px rgba(17, 24, 39, 0.07);
    --radius-card: 20px;
    --radius-pill: 9999px;
    --font-heading: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'Inter', -apple-system, sans-serif;
}

body {
    background-color: var(--bg-page);
    font-family: var(--font-body);
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}

.main {
    padding: 24px 30px 40px !important;
}

.mgmt-main-cont {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Page Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    flex-wrap: wrap;
    gap: 16px;
}

.dash-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
}

.dash-title-badge {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime);
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.dash-title {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 26px;
    display: block;
}

.dash-header-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    box-shadow: var(--shadow-soft);
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* Summary Cards */
.mgmt-sum-card-cont {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
}

@media (max-width: 1100px) {
    .mgmt-sum-card-cont {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .mgmt-sum-card-cont {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .mgmt-sum-card-cont {
        grid-template-columns: 1fr;
    }
}

.mgmt-sum-card {
    background: var(--card-bg);
    padding: 18px 20px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-width: 0;
    position: relative;
    overflow: hidden;
}

.mgmt-sum-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
    border-color: #d1d5db;
}

.mgmt-card-title {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: var(--text-muted);
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.mgmt-card-title .card-tag {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    font-weight: 700;
}

.mgmt-card-value {
    font-family: var(--font-heading);
    font-size: 26px;
    font-weight: 800;
    color: var(--text-dark);
    margin: 0;
    line-height: 1.1;
}

/* Control Bar */
.mgmt-ctrl {
    background: var(--card-bg);
    padding: 16px 20px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}

.mgmt-ctrl form {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    flex: 1;
    min-width: 0;
}

.search-box {
    position: relative;
    width: 250px;
    flex-shrink: 0;
}

.mgmt-search {
    width: 100%;
    padding: 9px 16px 9px 36px;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    font-size: 13px;
    background: var(--bg-page);
    color: var(--text-dark);
    font-family: var(--font-body);
    transition: all 0.2s ease;
}

.mgmt-search:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 13px;
    pointer-events: none;
}

.mgmt-filter {
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    padding: 9px 14px;
    font-size: 13px;
    background: var(--bg-page);
    color: var(--text-dark);
    font-family: var(--font-body);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.mgmt-filter:focus,
.mgmt-filter:hover {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
}

#clear-ctrl-btn {
    border: none;
    border-radius: var(--radius-pill);
    padding: 8px 16px;
    background: #fee2e2;
    color: #dc2626;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

#clear-ctrl-btn:hover {
    background: #fecaca;
}

.toggle-col-cont {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.add-btn {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-radius: var(--radius-pill);
    padding: 9px 18px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(24, 24, 27, 0.15);
    transition: all 0.2s ease;
}

.add-btn:hover {
    background: #000000;
    color: var(--accent-lime) !important;
    transform: translateY(-1px);
}

#toggle-col-btn {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    width: 38px;
    height: 38px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 15px;
    color: var(--text-dark);
    transition: all 0.2s ease;
}

#toggle-col-btn:hover {
    background: #ffffff;
    border-color: var(--dark-pill);
}

#toggle-col-menu {
    display: none;
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 10px 12px;
    z-index: 999;
    box-shadow: 0 12px 30px rgba(17, 24, 39, 0.1);
    min-width: 160px;
}

#toggle-col-menu.show {
    display: block;
}

#toggle-col-menu label {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-dark);
    cursor: pointer;
    border-radius: 6px;
    transition: background 0.15s;
}

#toggle-col-menu label:hover {
    background: var(--bg-page);
}

#reset-col-btn {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    margin-top: 8px;
    padding: 6px 10px;
    width: 100%;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.2s;
}

#reset-col-btn:hover {
    background: var(--dark-pill);
    color: #ffffff;
}

/* Table */
.mgmt-tbl {
    width: 100%;
    overflow-x: auto;
}

.mgmt-tbl table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}

.mgmt-tbl th {
    background: #fafbfc;
    color: var(--text-muted);
    padding: 15px 16px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border-bottom: 1px solid var(--border-card);
    vertical-align: middle;
    text-align: left;
}

.mgmt-tbl td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
    color: var(--text-dark);
    font-size: 13.5px;
}

.mgmt-tbl tbody tr {
    transition: background 0.15s ease;
}

.mgmt-tbl tbody tr:hover {
    background: #fbfcfd;
}

.mgmt-tbl tbody tr:last-child td {
    border-bottom: none;
}

.tbl-image {
    width: 48px;
    height: 48px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid var(--border-card);
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
}

.category-pill {
    background: var(--bg-page);
    color: #4b5563;
    border-radius: var(--radius-pill);
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    display: inline-block;
}

/* Stock popup */
.stock-cell {
    position: relative;
    display: inline-flex;
    align-items: center;
    cursor: pointer;
}

.stock-total {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    padding: 4px 10px;
    font-weight: 700;
    font-size: 12.5px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.stock-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 12px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    z-index: 50;
    min-width: 140px;
}

.stock-cell:hover .stock-dropdown {
    display: block;
}

.variant-row {
    font-size: 12px;
    padding: 3px 0;
    color: var(--text-dark);
}
.variant-row.out { color: #dc2626; font-weight: 600; }
.variant-row.low { color: #d97706; font-weight: 600; }

.variant-warning {
    margin-top: 5px;
    font-size: 11.5px;
    color: #b45309;
    background: #fffbeb;
    border: 1px solid #fef3c7;
    padding: 3px 8px;
    border-radius: var(--radius-pill);
    display: inline-block;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.badge.status-active {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge.status-inactive {
    background: var(--bg-page);
    color: var(--text-muted);
    border: 1px solid var(--border-card);
}

.badge.stock-in-stock {
    background: #ecfdf5;
    color: #059669;
}

.badge.stock-some-variants-low-stock {
    background: #fffbeb;
    color: #d97706;
}

.badge.stock-some-variants-out-of-stock {
    background: #fef2f2;
    color: #dc2626;
}

.badge.stock-out-of-stock {
    background: #18181b;
    color: #f87171;
}

/* Sort Links */
.sort-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    color: var(--text-muted);
    font-weight: 700;
    transition: color 0.15s;
}

.sort-link:hover,
.sort-link.active-sort {
    color: var(--text-dark);
}

.sort-icons {
    display: flex;
    flex-direction: column;
    font-size: 9px;
    color: #cbd5e1;
    line-height: 1;
}

.sort-link.asc .up i,
.sort-link.desc .down i {
    color: var(--text-dark);
}

/* Actions */
.action-wrapper {
    position: relative;
    display: inline-block;
}

.action-btn {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.2s ease;
}

.action-btn:hover {
    background: var(--dark-pill);
    color: #ffffff;
    border-color: var(--dark-pill);
    width: 34px;
    height: 34px;
}

.action-btn i:hover{
    color:white;
}

.action-menu {
    display: none;
    position: fixed;
    top: 0;
    left: auto;          /* 覆盖全局的 left: 50% */
    transform: none;     /* 覆盖全局的 translateX(-50%) */
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    box-shadow: 0 12px 30px rgba(17, 24, 39, 0.12);
    padding: 6px;
    z-index: 1000;
    min-width: 130px;
}

.action-menu.show {
    display: block;
}

.action-menu a,
.action-menu button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-dark);
    text-decoration: none;
    border: none;
    background: none;
    width: 100%;
    box-sizing: border-box;
    text-align: left;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s;
}

.action-menu a:hover,
.action-menu button:hover {
    background: var(--bg-page);
}

.action-menu .delete-btn {
    color: #dc2626;
}
.action-menu .delete-btn:hover {
    background: #fee2e2;
}

/* Pagination */
.mgmt-pagination {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 14px 20px;
    box-shadow: var(--shadow-soft);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
}

.limit-selector {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
}

.limit-selector select {
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: var(--bg-page);
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    cursor: pointer;
}

.page-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-btn,
.page-num {
    border-radius: var(--radius-pill);
    padding: 6px 14px;
    font-weight: 600;
    font-size: 13px;
    border: 1px solid var(--border-card);
    background: var(--bg-page);
    color: var(--text-dark);
    text-decoration: none;
    transition: all 0.2s ease;
}

.page-btn:hover,
.page-num:hover {
    background: #ffffff;
    border-color: var(--dark-pill);
}

.page-num.active {
    background: var(--dark-pill);
    color: var(--accent-lime);
    border-color: var(--dark-pill);
}

.page-ellipsis {
    color: var(--text-muted);
    padding: 0 4px;
}

.jump-page-form {
    display: flex;
    align-items: center;
    gap: 6px;
}

.jump-input {
    width: 60px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: var(--bg-page);
    padding: 6px 10px;
    font-size: 13px;
    text-align: center;
    color: var(--text-dark);
}

.jump-input:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
}

.jump-btn {
    border-radius: var(--radius-pill);
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.jump-btn:hover {
    background: #000000;
    color: var(--accent-lime);
}

.jump-total {
    font-size: 13px;
    color: var(--text-muted);
    font-weight: 500;
}
</style>
</head>

<body>
  <div class="global-layout">
    <?php include "global_layout_ctrl.php" ?>
      <div class="main">

        <div class="mgmt-main-cont">

            <!-- Page Header -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-box-seam-fill"></i>
                        <div class="badge-arrow">↗</div>
                    </div>
                    <div>
                        <h1 class="dash-title">Product <strong>Catalog & Inventory</strong></h1>
                    </div>
                </div>
                <div class="dash-header-controls">
                    <span class="live-pill"><span class="pulse-dot"></span> <?= number_format($totalRows) ?> Products Total</span>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Active</span>
                        <span class="card-tag" style="background: var(--accent-lime-subtle); color: #3f6212;">Live</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalActive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Inactive</span>
                        <span class="card-tag">Hidden</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalInactive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Out of Stock</span>
                        <span class="card-tag" style="background: #fee2e2; color: #991b1b;">0 Qty</span>
                    </p>
                    <p class="mgmt-card-value" style="<?= $totalOutOfStock > 0 ? 'color: #dc2626;' : '' ?>"><?= htmlspecialchars($totalOutOfStock) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Some Variants Out</span>
                        <span class="card-tag" style="background: #fee2e2; color: #991b1b;">Alert</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalSomeOut) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Some Variants Low</span>
                        <span class="card-tag" style="background: #fef3c7; color: #92400e;">&le; 5</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalLow) ?></p>
                </div>
            </div>

            <!-- Control Bar -->
            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search ID or name..." class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Status</option>
                        <option value="Active" <?php echo ($status == 'Active') ? 'selected' : ''; ?>>Active</option>
                        <option value="Inactive" <?php echo ($status == 'Inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>

                    <select name="category" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Categories</option>
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
                        <option value="">All Ratings</option>
                        <option value="4" <?php echo ($rating == 4) ? 'selected' : '' ?>>★ 4.0 & above</option>
                        <option value="3" <?php echo ($rating == 3) ? 'selected' : '' ?>>★ 3.0 & above</option>
                        <option value="2" <?php echo ($rating == 2) ? 'selected' : '' ?>>★ 2.0 & above</option>
                        <option value="1" <?php echo ($rating == 1) ? 'selected' : '' ?>>★ 1.0 & above</option>
                    </select>

                    <?php if (!empty($search) || !empty($status) || !empty($category) || !empty($stock) || !empty($rating)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <a href="add_product.php" class="add-btn">
                        <i class="bi bi-plus-lg"></i>Add Product
                    </a>

                    <button id="toggle-col-btn" title="Customize Columns">
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

            <!-- Table -->
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
                            <th class="category">Category</th>

                            <!--product stock(variants) quantity-->
                            <th class="variant">Stock</th>

                            <!--product stock status-->
                            <th class="stock">Stock Status</th>

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
                                    <strong style="color: var(--text-dark); font-family: var(--font-heading);"><?php echo htmlspecialchars($row['PRODUCT_ID']); ?></strong>
                                </td>

                                <td class="image">
                                    <?php if (!empty($row['COVER_IMAGE'])): ?>
                                        <img src="<?= htmlspecialchars($row['COVER_IMAGE']) ?>" alt="Product Image" class="tbl-image">
                                    <?php else: ?>
                                        <div style="width: 48px; height: 48px; border-radius: 12px; background: var(--bg-page); display: flex; align-items: center; justify-content: center; color: var(--text-light); font-size: 11px; font-weight: 600;">N/A</div>
                                    <?php endif; ?>
                                </td>

                                <td class="name">
                                    <span style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($row['PRODUCT_NAME']); ?></span>

                                    <?php if ($row['variant_count'] > 0 && $row['active_variant_count'] == 0): ?>
                                        <div class="variant-warning">
                                            <i class="bi bi-exclamation-triangle-fill me-1"></i>No variants active
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td class="category">
                                    <span class="category-pill"><?php echo htmlspecialchars($row['CATEGORY_NAME'] ?? 'Uncategorized') ?></span>
                                </td>

                                <td class="variant">
                                    <div class="stock-cell">
                                        <span class="stock-total">
                                            <?= $row['total_stock'] ?> <i class="bi bi-caret-down-fill" style="font-size: 10px; color: var(--text-muted);"></i>
                                        </span>

                                        <?php if (!empty($row['variant_list'])): ?>
                                            <div class="stock-dropdown">
                                                <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); margin-bottom: 4px;">Variants</div>
                                                <?php $variants = explode('|', $row['variant_list']); foreach ($variants as $v): list($name, $qty) = explode(':', $v);?>
                                                    <div class="variant-row 
                                                        <?= ($qty == 0) ? 'out' : ($qty <= 5 ? 'low' : '') ?>">
                                                        <?= htmlspecialchars($name) ?>: <strong><?= $qty ?></strong>
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
                                    <span style="font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                        <i class="bi bi-star-fill" style="color: #f59e0b; font-size: 12px;"></i>
                                        <?= htmlspecialchars($row['AVG_RATING']); ?>
                                    </span>
                                </td>

                                <td class="sales">
                                    <span style="font-weight: 600; color: var(--text-dark);"><?php echo number_format($row['SALES_COUNT']); ?></span>
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
function closeActionMenus() {
    document.querySelectorAll(".action-menu").forEach(m => m.classList.remove("show"));
}

document.querySelectorAll(".action-btn").forEach(btn => {
    btn.addEventListener("click", function (e) {
        e.stopPropagation();

        const menu = this.nextElementSibling;
        const isShowing = menu.classList.contains("show");

        closeActionMenus();
        if (isShowing) return;

        menu.classList.add("show");

        // 根据按钮位置定位；下方放不下就往上开
        const r = this.getBoundingClientRect();
        const h = menu.offsetHeight;
        const openUp = r.bottom + h + 8 > window.innerHeight;

        menu.style.top = (openUp ? r.top - h - 4 : r.bottom + 4) + "px";
        menu.style.right = (document.documentElement.clientWidth - r.right) + "px";
    });
});

// 滚动或缩放时关闭，避免菜单和按钮错位
window.addEventListener("scroll", closeActionMenus, true);
window.addEventListener("resize", closeActionMenus);

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