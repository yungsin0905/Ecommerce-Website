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
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';

// sorting 
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';

$allowedSort = ['CUSTOMER_NAME','TOTAL_AMOUNT','CREATED_AT'];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'CREATED_AT';
}
if (!in_array($order, $allowedOrder)) {
    $order = 'DESC';
}

$sortMap = [
    'CUSTOMER_NAME' => 'o.CUSTOMER_NAME_SNAPSHOT',
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

$totalProcessing = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'PROCESSING'")['o'] ?? 0;
$totalReady = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'READY'")['o'] ?? 0;
$totalCompleted = getCount($conn, "SELECT COUNT(*) AS o FROM `orders` WHERE ORDER_STATUS = 'COMPLETED'")['o'] ?? 0;
$totalAllOrders = (int)$totalProcessing + (int)$totalReady + (int)$totalCompleted;

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

/* Date Range Filters (always based on order creation date) */
if ($fromDate != '') {
    $where[] = "o.CREATED_AT >= ?";
    $params[] = $fromDate . " 00:00:00";
    $types .= "s";
}

if ($toDate != '') {
    $where[] = "o.CREATED_AT <= ?";
    $params[] = $toDate . " 23:59:59";
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
    COUNT(DISTINCT oi.ORDER_ITEM_ID) AS ITEMS

FROM orders o

LEFT JOIN order_item oi 
    ON o.ORDER_ID = oi.ORDER_ID

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
function buildUrl($page, $search, $status, $fromDate, $toDate, $limit, $sort, $order) {
    return "?" . http_build_query([
        "page" => $page,
        "search" => $search,
        "status" => $status,
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
    <title>Manage Orders | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
    color: var(--text-dark) !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge i {
    color: var(--text-dark) !important;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime) !important;
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.dash-title-badge .badge-arrow i {
    color: var(--accent-lime) !important;
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
    grid-template-columns: repeat(4, 1fr);
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

/* Controls Toolbar */
.mgmt-ctrl {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: var(--card-bg);
    padding: 14px 20px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
}

.mgmt-ctrl form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1;
}

.search-box {
    position: relative;
    width: 310px;
}

.search-box .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
    pointer-events: none;
}

.mgmt-search {
    width: 100%;
    padding: 8px 14px 8px 38px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #f8fafc;
    font-size: 13px;
    color: var(--text-dark);
    outline: none;
    transition: all 0.2s;
}

.mgmt-search:focus {
    background: #fff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 2px rgba(24, 24, 27, 0.08);
}

.mgmt-filter {
    padding: 8px 14px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-dark);
    outline: none;
    cursor: pointer;
    transition: all 0.2s;
}

.mgmt-filter:focus {
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 2px rgba(24, 24, 27, 0.08);
}

.date-filter-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--text-muted);
}

.date-filter-group input[type="date"] {
    padding: 6px 12px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    font-size: 12.5px;
    color: var(--text-dark);
    outline: none;
    cursor: pointer;
}

#clear-ctrl-btn {
    padding: 7px 16px;
    border-radius: var(--radius-pill);
    border: 1px solid #fee2e2;
    background: #fff;
    color: #dc2626 !important;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

#clear-ctrl-btn:hover {
    background: #fef2f2;
    border-color: #f87171;
}

.toggle-col-cont {
    position: relative;
}

#toggle-col-btn {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 15px;
}

#toggle-col-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

#toggle-col-menu {
    position: absolute;
    right: 0;
    top: 44px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    box-shadow: var(--shadow-hover);
    padding: 12px 14px;
    display: none;
    flex-direction: column;
    gap: 8px;
    z-index: 50;
    min-width: 170px;
}

#toggle-col-menu.show {
    display: flex;
}

#toggle-col-menu label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    color: var(--text-dark);
}

#reset-col-btn {
    margin-top: 6px;
    padding: 4px 10px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #f8fafc;
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
}

/* Data Table Card */
.mgmt-tbl {
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}

.mgmt-tbl table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    text-align: left;
}

.mgmt-tbl th {
    background: #fcfdfe;
    padding: 14px 18px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-card);
    white-space: nowrap;
}

.mgmt-tbl td {
    padding: 14px 18px;
    font-size: 13.5px;
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
}

.mgmt-tbl tbody tr {
    transition: background-color 0.15s ease;
}

.mgmt-tbl tbody tr:hover {
    background-color: #fafbfd;
}

.mgmt-tbl tbody tr:last-child td {
    border-bottom: none;
}

.sort-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted) !important;
    text-decoration: none;
}

.sort-link.active-sort {
    color: var(--text-dark) !important;
}

.sort-icons {
    display: inline-flex;
    flex-direction: column;
    font-size: 9px;
    line-height: 1;
}

.order-no-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 700;
    font-family: monospace;
    font-size: 13px;
    background: #f4f4f5;
    color: var(--text-dark) !important;
    padding: 3px 8px;
    border-radius: 6px;
}

.customer-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.customer-avatar {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-pill);
    background: #f1f5f9;
    color: #475569 !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}

.items-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #f8fafc;
    border: 1px solid var(--border-card);
    padding: 3px 10px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
}

.items-badge i {
    color: var(--text-muted) !important;
}

.total-amount {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-dark);
}

/* Status Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2px;
}

.badge::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.status-processing {
    background: #fef9c3;
    color: #854d0e !important;
}
.status-processing::before { background: #ca8a04; }

.status-ready {
    background: #e0f2fe;
    color: #0369a1 !important;
}
.status-ready::before { background: #0284c7; }

.status-completed {
    background: #dcfce7;
    color: #15803d !important;
}
.status-completed::before { background: #16a34a; }

.status-cancelled {
    background: #fee2e2;
    color: #b91c1c !important;
}
.status-cancelled::before { background: #dc2626; }

/* Action Dropdown Menu */
.action-wrapper {
    position: relative;
    display: inline-block;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.15s;
    font-size: 14px;
}

.action-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.action-menu {
    position: fixed;
    background: #ffffff;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    box-shadow: var(--shadow-hover);
    padding: 6px;
    display: none;
    flex-direction: column;
    gap: 2px;
    z-index: 1050;
    min-width: 155px;
}

.action-menu.show {
    display: flex;
}

.action-menu a, .action-menu button {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-dark) !important;
    text-decoration: none;
    background: none;
    border: none;
    width: 100%;
    text-align: left;
    cursor: pointer;
    transition: background 0.15s;
}

.action-menu a:hover, .action-menu button:hover {
    background: #f4f5f8;
}

.action-menu i {
    font-size: 14px;
    color: var(--text-muted) !important;
}

/* Modal Popup */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(17, 24, 39, 0.45);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: #ffffff;
    border-radius: 20px;
    padding: 26px 28px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    border: 1px solid var(--border-card);
    animation: modalIn 0.2s ease-out;
}

@keyframes modalIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

.modal-header-custom {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.modal-icon-badge {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark) !important;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}

.modal-icon-badge i {
    color: var(--text-dark) !important;
}

.modal-title-custom {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
}

.modal-select-styled {
    width: 100%;
    padding: 10px 14px;
    border-radius: 12px;
    border: 1px solid var(--border-card);
    background: #f8fafc;
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
    outline: none;
    margin-bottom: 20px;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-select-styled:focus {
    border-color: var(--dark-pill);
    background: #fff;
    box-shadow: 0 0 0 2px rgba(24, 24, 27, 0.08);
}

.modal-actions-custom {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.modal-btn-cancel {
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    color: var(--text-dark);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.modal-btn-cancel:hover {
    background: #f8fafc;
}

.modal-btn-save {
    padding: 8px 22px;
    border-radius: var(--radius-pill);
    border: none;
    background: var(--dark-pill);
    color: #ffffff !important;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s;
}

.modal-btn-save:hover {
    background: var(--dark-pill-hover);
}

/* Pagination */
.mgmt-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    padding: 16px 20px;
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
}

.limit-selector label {
    font-size: 13px;
    color: var(--text-muted);
    margin-right: 6px;
}

.page-limit {
    padding: 4px 10px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #fff;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}

.page-controls {
    display: flex;
    align-items: center;
    gap: 4px;
}

.page-btn, .page-num {
    padding: 6px 12px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark) !important;
    text-decoration: none;
    background: #fff;
    border: 1px solid var(--border-card);
    transition: all 0.15s;
}

.page-btn:hover, .page-num:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.page-num.active {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
}

.page-ellipsis {
    padding: 0 4px;
    color: var(--text-light);
}

.jump-page-form {
    display: flex;
    align-items: center;
    gap: 6px;
}

.jump-input {
    width: 60px;
    padding: 5px 8px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    text-align: center;
    font-size: 12.5px;
}

.jump-btn {
    padding: 5px 12px;
    border-radius: var(--radius-pill);
    border: none;
    background: var(--dark-pill);
    color: #ffffff !important;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
}

.jump-total {
    font-size: 12.5px;
    color: var(--text-muted);
}
</style>
</head>

<body>
  <div class="global-layout">
    <?php include "global_layout_ctrl.php" ?>
      <div class="main">

        <div class="mgmt-main-cont">

            <!-- PAGE HEADER -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-bag-check-fill"></i>
                        <span class="badge-arrow"><i class="bi bi-boxes"></i></span>
                    </div>
                    <div>
                        <h1 class="dash-title">Order <strong>Management</strong></h1>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                            Review incoming customer orders, update workflow statuses, and track fulfillment
                        </div>
                    </div>
                </div>

                <div class="dash-header-controls">
                    <div class="live-pill">
                        <span class="pulse-dot"></span>
                        <span>Fulfillment Engine Active</span>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Total Orders</span>
                        <span class="card-tag" style="background: var(--accent-lime-subtle); color: #3f6212;">All</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalAllOrders) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Processing</span>
                        <span class="card-tag" style="background: #fef3c7; color: #92400e;">Active</span>
                    </p>
                    <p class="mgmt-card-value" style="<?= $totalProcessing > 0 ? 'color: #d97706;' : '' ?>"><?= htmlspecialchars($totalProcessing) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Ready</span>
                        <span class="card-tag" style="background: #e0f2fe; color: #0369a1;">Packed</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalReady) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Completed</span>
                        <span class="card-tag" style="background: #dcfce7; color: #166534;">Fulfilled</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalCompleted) ?></p>
                </div>
            </div>

            <!-- CONTROLS TOOLBAR -->
            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search order no. or customer name..." class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Statuses</option>
                      <option value="Processing" <?php echo ($status == 'Processing') ? 'selected' : ''; ?>>Processing</option>
                      <option value="Ready" <?php echo ($status == 'Ready') ? 'selected' : '' ?>>Ready</option>
                      <option value="Completed" <?php echo ($status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                    </select>

                    <!-- Date Range Filters -->
                    <div class="date-filter-group">
                        <span>Dates:</span>
                        <input type="date" name="fromDate" 
                            value="<?= htmlspecialchars($fromDate) ?>" 
                            onchange="this.form.submit()" title="From Date">
                        <span>to</span>
                        <input type="date" name="toDate" 
                            value="<?= htmlspecialchars($toDate) ?>" 
                            onchange="this.form.submit()" title="To Date">
                    </div>

                    <?php if (!empty($search) || !empty($status) || !empty($fromDate) || !empty($toDate)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear Filters</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont">
                    <button id="toggle-col-btn" title="Toggle Columns">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="orderNo">Order No</label>
                        <label><input type="checkbox" checked data-col="customer">Customer</label>
                        <label><input type="checkbox" checked data-col="items">Items</label>
                        <label><input type="checkbox" checked data-col="total">Total</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <label><input type="checkbox" checked data-col="since">Since</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <!-- DATA TABLE -->
            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="orderNo">Order No</th>

                            <th class="customer">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'CUSTOMER_NAME',
                                   ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Customer</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="items">Items</th>
                      
                            <th class="total">
                                <a class="sort-link <?= $sort=='TOTAL_AMOUNT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'TOTAL_AMOUNT',
                                   ($sort=='TOTAL_AMOUNT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Total</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'CREATED_AT',
                                    ($sort=='CREATED_AT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Since</span>
                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): 
                                $custName = $row['CUSTOMER_NAME_SNAPSHOT'] ?? 'Guest';
                                $initials = strtoupper(substr($custName, 0, 1));
                            ?>
                                <tr>
                                    <td class="orderNo">
                                        <span class="order-no-pill">#<?= htmlspecialchars($row['ORDER_NO']); ?></span>
                                    </td>

                                    <td class="customer">
                                        <div class="customer-cell">
                                            <div class="customer-avatar"><?= $initials ?></div>
                                            <span style="font-weight: 600;"><?= htmlspecialchars($custName); ?></span>
                                        </div>
                                    </td>

                                    <td class="items">
                                        <span class="items-badge">
                                            <i class="bi bi-box-seam"></i>
                                            <?= $row['ITEMS'] ?? 0 ?> item<?= ($row['ITEMS'] ?? 0) == 1 ? '' : 's' ?>
                                        </span>
                                    </td>
                           
                                    <td class="total">
                                        <span class="total-amount">
                                            RM <?= number_format((float)$row['TOTAL_AMOUNT'], 2); ?>
                                        </span>
                                    </td>

                                    <td class="status">
                                        <span class="badge status-<?= strtolower($row['ORDER_STATUS']) ?>">
                                            <?= htmlspecialchars($row['ORDER_STATUS']); ?>
                                        </span>
                                    </td>

                                    <td class="since">
                                        <?= date("d M Y, H:i", strtotime($row['CREATED_AT'])); ?>
                                    </td>

                                    <td style="text-align: right;">
                                        <div class="action-wrapper">
                                            <button class="action-btn" title="Actions">
                                                <i class="bi bi-three-dots"></i>
                                            </button>

                                            <div class="action-menu">
                                                <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                                    <i class="bi bi-eye"></i> View Order
                                                </a>

                                                <button type="button" class="update-btn" onclick="openStatusModal(<?= $row['ORDER_ID'] ?>, '<?= $row['ORDER_STATUS'] ?>')">
                                                    <i class="bi bi-arrow-repeat"></i> Update Status
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    <i class="bi bi-inbox" style="font-size: 28px; display: block; margin-bottom: 8px;"></i>
                                    No orders found matching your search or filters.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Status Update Modal -->
            <div id="statusModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header-custom">
                        <div class="modal-icon-badge">
                            <i class="bi bi-arrow-repeat"></i>
                        </div>
                        <h3 class="modal-title-custom">Update Order Status</h3>
                    </div>

                    <form id="statusForm">
                        <input type="hidden" name="order_id" id="modalOrderId">

                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">
                            Workflow Status:
                        </label>
                        <select name="status" id="modalStatus" class="modal-select-styled" required>
                            <option value="PROCESSING">Processing</option>
                            <option value="READY">Ready</option>
                            <option value="COMPLETED">Completed</option>
                        </select>

                        <div class="modal-actions-custom">
                            <button type="button" class="modal-btn-cancel" onclick="closeModal()">Cancel</button>
                            <button type="submit" class="modal-btn-save">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- PAGINATION -->
            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>">
                        <input type="hidden" name="toDate" value="<?= htmlspecialchars($toDate) ?>">
                        <input type="hidden" name="page" value="1"> 
                        <label>Rows per page:</label>
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            ◀ Prev
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$fromDate,$toDate,$limit,$sort,$order) ?>">
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

// action menu toggle with boundary detection
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

        const r = this.getBoundingClientRect();
        const h = menu.offsetHeight;
        const openUp = r.bottom + h + 8 > window.innerHeight;

        menu.style.top = (openUp ? r.top - h - 4 : r.bottom + 4) + "px";
        menu.style.right = (document.documentElement.clientWidth - r.right) + "px";
    });
});

// close menus on outside click
document.addEventListener("click", function (e) {
    closeActionMenus();

    if (toggleColMenu) {
        toggleColMenu.classList.remove("show");
    }
});

window.addEventListener("scroll", closeActionMenus, true);
window.addEventListener("resize", closeActionMenus);

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
    closeActionMenus();
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
        showToast("error", "Error updating status");
        console.error(err);
    });
});

// click outside then close
window.addEventListener("click", function (e) {
    const statusModal = document.getElementById("statusModal");
    if (e.target === statusModal) {
        closeModal();
    }
});

// Show Toast
function showToast(type, message) {
    document.querySelectorAll(".toast").forEach(t => t.remove());

    const toast = document.createElement("div");
    toast.className = "toast " + type;

    const icon = document.createElement("i");
    icon.className = type === "success" ? "bi bi-check-circle-fill" : "bi bi-exclamation-triangle-fill";

    const text = document.createElement("span");
    text.innerText = message;

    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "&times;";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(icon);
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
        toast.style.transform = "translateY(-10px)";

        setTimeout(() => toast.remove(), 300);
    }

    const duration = type === "error" ? 8000 : 3500;
    setTimeout(removeToast, duration);
}
</script>

</body>
</html>