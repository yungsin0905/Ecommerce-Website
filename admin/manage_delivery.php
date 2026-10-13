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

// sorting
$sort = $_GET['sort'] ?? 'ACT_DELIVERY';
$order = $_GET['order'] ?? 'DESC';
$allowedOrder = ['ASC', 'DESC'];
$allowedSort = ['CUSTOMER_NAME','ACT_DELIVERY'];

/* mapping UI → SQL */
$sortMap = [
    'CUSTOMER_NAME' => 'o.CUSTOMER_NAME_SNAPSHOT',
    'ACT_DELIVERY' => "TIMESTAMP(s.SHIPPING_DATE, s.SHIPPING_TIME)"
];

if (!in_array($sort, $allowedSort)) {
    $sort = 'ACT_DELIVERY';
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
") ?? 0;

$totalOutForDelivery = getCount($conn, "
    SELECT COUNT(*) AS c 
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'OUT FOR DELIVERY'
    AND o.ORDER_STATUS != 'REFUNDED'
") ?? 0;

$totalDelivered = getCount($conn, "
    SELECT COUNT(*) AS c 
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'DELIVERED'
    AND o.ORDER_STATUS != 'REFUNDED'
") ?? 0;

$totalAllShipments = (int)$totalPending + (int)$totalOutForDelivery + (int)$totalDelivered;

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

$dateField = 's.SHIPPING_DATE';
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

    o.CUSTOMER_NAME_SNAPSHOT,
    o.DELIVERY_ADDRESS_SNAPSHOT,

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
function buildUrl($page,$search,$status,$fromDate,$toDate,$limit,$sort,$order){
    return "?" . http_build_query([
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "fromDate"=>$fromDate,
        "toDate"=>$toDate,
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
    <title>Manage Deliveries | Admin</title>
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
    width: 330px;
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

.order-link {
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
    text-decoration: none;
    transition: background 0.15s;
}

.order-link:hover {
    background: #e4e4e7;
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

.address-block {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    max-width: 320px;
    font-size: 13px;
    line-height: 1.4;
    color: var(--text-dark);
}

.address-block i {
    color: var(--text-light);
    margin-top: 2px;
    flex-shrink: 0;
}

.delivery-time-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12.5px;
    color: var(--text-dark);
    font-weight: 500;
}

.delivery-time-badge i {
    color: var(--text-muted);
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
    text-transform: capitalize;
}

.badge::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.status-pending {
    background: #fef3c7;
    color: #92400e !important;
}
.status-pending::before { background: #d97706; }

.status-out-for-delivery {
    background: #e0f2fe;
    color: #0369a1 !important;
}
.status-out-for-delivery::before { background: #0284c7; }

.status-delivered {
    background: #dcfce7;
    color: #15803d !important;
}
.status-delivered::before { background: #16a34a; }

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
    min-width: 165px;
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
                        <i class="bi bi-truck"></i>
                        <span class="badge-arrow"><i class="bi bi-geo-alt-fill"></i></span>
                    </div>
                    <div>
                        <h1 class="dash-title">Delivery <strong>Dispatch</strong></h1>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                            Monitor logistics dispatch status, courier deliveries, and customer destinations
                        </div>
                    </div>
                </div>

                <div class="dash-header-controls">
                    <div class="live-pill">
                        <span class="pulse-dot"></span>
                        <span>Logistics Dispatch Live</span>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Total Shipments</span>
                        <span class="card-tag" style="background: var(--accent-lime-subtle); color: #3f6212;">All</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalAllShipments) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Pending</span>
                        <span class="card-tag" style="background: #fef3c7; color: #92400e;">To Dispatch</span>
                    </p>
                    <p class="mgmt-card-value" style="<?= $totalPending > 0 ? 'color: #d97706;' : '' ?>"><?= htmlspecialchars($totalPending) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Out for Delivery</span>
                        <span class="card-tag" style="background: #e0f2fe; color: #0369a1;">In Transit</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalOutForDelivery) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Delivered</span>
                        <span class="card-tag" style="background: #dcfce7; color: #166534;">Delivered</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalDelivered) ?></p>
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
                        <input type="text" name="search" placeholder="Search order number or customer..." class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Statuses</option>
                      <option value="Pending" <?php echo ($status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                      <option value="Out for Delivery" <?php echo ($status == 'Out for Delivery') ? 'selected' : '' ?>>Out for Delivery</option>
                      <option value="Delivered" <?php echo ($status == 'Delivered') ? 'selected' : '' ?>>Delivered</option>
                    </select>

                    <!-- Date Range Filters -->
                    <div class="date-filter-group">
                        <span>Shipping Date:</span>
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
                        <label><input type="checkbox" checked data-col="address">Address</label>
                        <label><input type="checkbox" checked data-col="actualDeliveryTime">Delivery Date</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
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

                            <th class="address">Destination Address</th>

                            <th class="actualDeliveryTime">
                                <a class="sort-link <?= $sort=='ACT_DELIVERY' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$fromDate,$toDate,$limit,'ACT_DELIVERY',
                                    ($sort=='ACT_DELIVERY' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Delivery Date</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="status">Status</th>

                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): 
                                $custName = $row['CUSTOMER_NAME_SNAPSHOT'] ?? 'Guest';
                                $initials = strtoupper(substr($custName, 0, 1));

                                $date = $row['SHIPPING_DATE'];
                                $time = $row['SHIPPING_TIME'];
                                $isValidDate = !empty($date) && $date !== '0000-00-00' && !empty($time) && $time !== '00:00:00';
                            ?>
                                <tr>
                                    <td class="orderNo">
                                        <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="order-link">
                                            #<?= htmlspecialchars($row['ORDER_NO']) ?>
                                        </a>
                                    </td>

                                    <td class="customer">
                                        <div class="customer-cell">
                                            <div class="customer-avatar"><?= $initials ?></div>
                                            <span style="font-weight: 600;"><?= htmlspecialchars($custName) ?></span>
                                        </div>
                                    </td>

                                    <td class="address">
                                        <div class="address-block">
                                            <i class="bi bi-geo-alt"></i>
                                            <span><?= nl2br(htmlspecialchars($row['DELIVERY_ADDRESS_SNAPSHOT'])) ?></span>
                                        </div>
                                    </td>
                                    
                                    <td class="actualDeliveryTime">
                                        <?php if ($isValidDate): ?>
                                            <span class="delivery-time-badge">
                                                <i class="bi bi-calendar-check"></i>
                                                <?= date("d M Y, H:i", strtotime("$date $time")) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="color: var(--text-light); font-size: 13px;">Pending schedule</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="status">
                                        <span class="badge status-<?= strtolower(str_replace(' ', '-', $row['DELIVERY_STATUS'])) ?>">
                                            <?= htmlspecialchars($row['DELIVERY_STATUS']) ?>
                                        </span>
                                    </td>

                                    <td style="text-align: right;">
                                        <div class="action-wrapper">
                                            <button class="action-btn" title="Actions">
                                                <i class="bi bi-three-dots"></i>
                                            </button>

                                            <div class="action-menu">
                                                <a href="view_delivery.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                                    <i class="bi bi-eye"></i> View Delivery
                                                </a>

                                                <button type="button" class="update-btn" onclick="openStatusModal(<?= $row['SHIPPING_ID'] ?>, '<?= $row['DELIVERY_STATUS'] ?>')">
                                                    <i class="bi bi-arrow-repeat"></i> Update Status
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    <i class="bi bi-truck" style="font-size: 28px; display: block; margin-bottom: 8px;"></i>
                                    No delivery shipments found matching your search or filters.
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
                        <h3 class="modal-title-custom">Update Delivery Status</h3>
                    </div>

                    <form id="statusForm">
                        <input type="hidden" name="delivery_id" id="modalDeliveryId">

                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--text-muted); margin-bottom: 6px;">
                            Delivery Stage:
                        </label>
                        <select name="status" id="modalStatus" class="modal-select-styled" required>
                            <option value="PENDING">Pending</option>
                            <option value="OUT FOR DELIVERY">Out for Delivery</option>
                            <option value="DELIVERED">Delivered</option>
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

// Status POP UP
function openStatusModal(deliveryId, currentStatus) {
    closeActionMenus();
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
        showToast("error", "Error updating status");
        console.error(err);
    });
});

// Close status Pop up on outside click
window.addEventListener("click", function (e) {
    const modal = document.getElementById("statusModal");
    if (e.target === modal) {
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