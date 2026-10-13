<?php
require_once("config.php");
session_start();

// page title
$pageTitle = "Manage Admins";

// Authentication check (Super Admin) - only SA can access
if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
}

$admin_id = $_SESSION['admin_id']; 

$stmt = $conn->prepare("
    SELECT ADMIN_TYPE 
    FROM admin 
    WHERE ADMIN_ID = ? AND IS_DELETED = 0
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();

if (!$currentAdmin || $currentAdmin['ADMIN_TYPE'] !== 'Super Admin') {
    die("Access denied (Super Admin only)");
} 

// search, filter
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// sorting
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';

$allowedSort = ['CREATED_AT','ADMIN_ID', 'ADMIN_NAME'];
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
$totalActive = getCount($conn, "
    SELECT COUNT(*) AS u 
    FROM admin 
    WHERE ADMIN_STATUS='Active' 
    AND IS_DELETED=0
    AND ADMIN_TYPE != 'Super Admin'
")['u'] ?? 0;

$totalSuspended = getCount($conn, "
    SELECT COUNT(*) AS u 
    FROM admin 
    WHERE ADMIN_STATUS='Suspended' 
    AND IS_DELETED=0
    AND ADMIN_TYPE != 'Super Admin'
")['u'] ?? 0;

$totalStaff = (int)$totalActive + (int)$totalSuspended;

// search, filter conditions
$where = ["u.IS_DELETED = 0", "u.ADMIN_TYPE != 'Super Admin'"];
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(u.ADMIN_NAME LIKE ? OR u.ADMIN_EMAIL LIKE ? OR u.ADMIN_PHONE LIKE ?)";
    $s = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types .= "sss";
}

if ($status != '') {
    $where[] = "u.ADMIN_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

$whereSql = count($where) ? " WHERE " . implode(" AND ", $where) : "";

// get total rows for pagination
$countSql = "
SELECT COUNT(*) AS total
FROM admin u
$whereSql
";

$stmtCount = $conn->prepare($countSql);
if (!empty($params)) $stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$totalRows = $stmtCount->get_result()->fetch_assoc()['total'];

$totalPages = max(1, ceil($totalRows / $limit));

// fetch admin list (With Filter, Sort, Pagination)
$listSql = "
SELECT u.*
FROM admin u
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

/* URL builder */
function buildUrl($page,$search,$status,$limit,$sort,$order) {
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
    <title>Manage Admins | Admin</title>
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
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

@media (max-width: 992px) {
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
    width: 270px;
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
    font-family: var(--font-heading);
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
    font-family: var(--font-heading);
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

.admin-name-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.admin-avatar {
    width: 34px;
    height: 34px;
    border-radius: var(--radius-pill);
    background: var(--dark-pill);
    color: var(--accent-lime);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 700;
    font-family: var(--font-heading);
    flex-shrink: 0;
}

.admin-name-text {
    font-weight: 700;
    color: var(--text-dark);
    font-family: var(--font-heading);
}

.admin-id-pill {
    font-family: monospace;
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 3px 8px;
    border-radius: var(--radius-pill);
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

.badge.status-suspended {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.badge.role-super-admin {
    background: var(--dark-pill);
    color: #ffffff;
}

.badge.role-admin {
    background: var(--bg-page);
    color: var(--text-dark);
    border: 1px solid var(--border-card);
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
}

.action-menu {
    display: none;
    position: fixed;
    top: 0;
    left: auto;
    transform: none;
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
    font-weight: 600;
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
    font-family: var(--font-heading);
}

.action-menu a:hover,
.action-menu button:hover {
    background: var(--bg-page);
    color: var(--text-dark);
}

.action-menu .delete-btn {
    color: #dc2626;
}

.action-menu .delete-btn:hover {
    background: #fee2e2;
    color: #dc2626;
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
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 14px 20px;
    border-radius: var(--radius-pill);
    color: #ffffff !important;
    font-family: var(--font-heading);
    font-size: 13.5px;
    font-weight: 700;
    z-index: 9999;
    box-shadow: 0 10px 28px rgba(17, 24, 39, 0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.toast.success { background: #15803d; }
.toast.error   { background: #b91c1c; }

.toast-close-btn {
    cursor: pointer;
    opacity: 0.8;
    font-size: 16px;
    line-height: 1;
}

.toast-close-btn:hover { opacity: 1; }
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
                        <i class="bi bi-shield-lock-fill"></i>
                        <div class="badge-arrow">↗</div>
                    </div>
                    <div>
                        <h1 class="dash-title">Administrator <strong>Management & Access</strong></h1>
                    </div>
                </div>
                <div class="dash-header-controls">
                    <span class="live-pill"><span class="pulse-dot"></span> <?= number_format($totalRows) ?> Staff Members</span>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Total Staff</span>
                        <span class="card-tag">All</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalStaff) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Active Admins</span>
                        <span class="card-tag" style="background: var(--accent-lime-subtle); color: #3f6212;">Active</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalActive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Suspended</span>
                        <span class="card-tag" style="background: #fee2e2; color: #b91c1c;">Blocked</span>
                    </p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalSuspended) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">
                        <span>Role Scope</span>
                        <span class="card-tag">Super Admin</span>
                    </p>
                    <p class="mgmt-card-value" style="font-size: 20px;">Standard Admin</p>
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
                        <input type="text" name="search" placeholder="Search by name, email, or phone" class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Status</option>
                      <option value="Active" <?php echo ($status == 'Active') ? 'selected' : ''; ?>>Active</option>
                      <option value="Suspended" <?php echo ($status == 'Suspended') ? 'selected' : '' ?>>Suspended</option>
                    </select>

                    <?php if (!empty($search) || !empty($status)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">Clear</button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont mgmt-ctrl-right">
                    <a href="add_admin.php" class="add-btn">
                        <i class="bi bi-plus-lg"></i> Add Admin
                    </a>

                    <button id="toggle-col-btn" title="Toggle Columns">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="id"> ID</label>
                        <label><input type="checkbox" checked data-col="name"> Name</label>
                        <label><input type="checkbox" checked data-col="email"> Email</label>
                        <label><input type="checkbox" checked data-col="phone"> Phone</label>
                        <label><input type="checkbox" checked data-col="role"> Role</label>
                        <label><input type="checkbox" checked data-col="status"> Status</label>
                        <label><input type="checkbox" checked data-col="since"> Since</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <!-- Data Table -->
            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="id">
                                <a class="sort-link <?= $sort=='ADMIN_ID' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$limit,'ADMIN_ID',
                                   ($sort=='ADMIN_ID' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>ID</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="name">
                                <a class="sort-link <?= $sort=='ADMIN_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$limit,'ADMIN_NAME',
                                   ($sort=='ADMIN_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="email">Email</th>

                            <th class="phone">Phone</th>

                            <th class="role">Role</th>

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

                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows == 0): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 40px 20px; color: var(--text-muted);">
                                    <i class="bi bi-shield-x" style="font-size: 28px; display: block; margin-bottom: 8px; color: var(--text-light);"></i>
                                    No administrators found matching your filter criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="id">
                                        <span class="admin-id-pill">#<?= htmlspecialchars($row['ADMIN_ID']); ?></span>
                                    </td>

                                    <td class="name">
                                        <div class="admin-name-cell">
                                            <div class="admin-avatar">
                                                <?= strtoupper(mb_substr($row['ADMIN_NAME'], 0, 1)) ?>
                                            </div>
                                            <span class="admin-name-text"><?= htmlspecialchars($row['ADMIN_NAME']); ?></span>
                                        </div>
                                    </td>

                                    <td class="email">
                                        <?= htmlspecialchars($row['ADMIN_EMAIL']); ?>
                                    </td>

                                    <td class="phone">
                                        <?php if (!empty($row['ADMIN_PHONE'])): ?>
                                            <?= htmlspecialchars($row['ADMIN_PHONE']); ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-light)">N/A</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="role">
                                        <span class="badge role-<?= strtolower(str_replace(' ', '-', $row['ADMIN_TYPE'])) ?>">
                                            <?= htmlspecialchars($row['ADMIN_TYPE']) ?>
                                        </span>
                                    </td>

                                    <td class="status">
                                        <span class="badge status-<?= strtolower($row['ADMIN_STATUS']) ?>">
                                            <?= htmlspecialchars($row['ADMIN_STATUS']); ?>
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
                                                <a href="view_admin.php?admin_id=<?= $row['ADMIN_ID'] ?>" class="view-btn">
                                                    <i class="bi bi-eye"></i> View Details
                                                </a>

                                                <a href="edit_admin.php?admin_id=<?= $row['ADMIN_ID'] ?>" class="edit-btn">
                                                    <i class="bi bi-pencil-square"></i> Edit Staff
                                                </a>

                                                <button type="button" class="delete-btn" data-id="<?= $row['ADMIN_ID'] ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
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

// Clear search + filters
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

// delete admin
document.querySelectorAll(".delete-btn").forEach(btn => {
    btn.addEventListener("click", function () {

        const adminId = this.dataset.id; 
        const row = this.closest("tr");

        if (!confirm("Are you sure you want to delete this admin?")) {
            return;
        }

        fetch("delete_admin.php?admin_id=" + adminId)
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                row.remove();
                showToast("success", "Admin deleted successfully");
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