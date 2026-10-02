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
$showAuto = isset($_GET['show_auto']) && $_GET['show_auto'] == '1';
if (!$showAuto) {
    $where[] = "v.VOUCHER_CODE NOT LIKE 'AUTO-%'";
}

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
function buildUrl($page,$search,$status,$type,$limit,$sort,$order,$showAuto=false){
    $q = [
        "page"=>$page,
        "search"=>$search,
        "status"=>$status,
        "type"=>$type,
        "limit"=>$limit,
        "sort"=>$sort,
        "order"=>$order
    ];
    if ($showAuto) $q["show_auto"] = 1;
    return "?" . http_build_query($q);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Vouchers | Admin</title>
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

/* Summary Cards */
.mgmt-sum-card-cont {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 16px;
}

.mgmt-sum-card {
    background: var(--card-bg);
    padding: 18px 20px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    display: flex;
    flex-direction: column;
    gap: 8px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.mgmt-sum-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.mgmt-card-title {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
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
    width: 280px;
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
    min-width: 170px;
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

.badge.status-upcoming {
    background: #e0f2fe;
    color: #0369a1;
}

.badge.status-expired {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid var(--border-card);
}

.badge.status-fullyredeemed {
    background: #fef3c7;
    color: #b45309;
}

.badge.type-public {
    background: #dcfce7;
    color: #15803d;
}

.badge.type-tier {
    background: #f3e8ff;
    color: #7e22ce;
}

.code-pill {
    font-family: monospace;
    font-weight: 700;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 12.5px;
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

.action-btn i:hover {
    color: white;
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

.page-btn, .page-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 12px;
    min-width: 32px;
    height: 32px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: var(--card-bg);
    color: var(--text-dark);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.page-btn:hover, .page-num:hover {
    background: var(--bg-page);
    border-color: var(--dark-pill);
}

.page-num.active {
    background: var(--dark-pill);
    color: #ffffff;
    border-color: var(--dark-pill);
}

.page-ellipsis {
    color: var(--text-muted);
    padding: 0 4px;
}

.jump-page-form {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    color: var(--text-muted);
}

.jump-input {
    width: 55px;
    padding: 6px 10px;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    font-size: 13px;
    text-align: center;
    background: var(--bg-page);
}

.jump-btn {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-pill);
    padding: 6px 14px;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.jump-btn:hover {
    background: #000000;
    color: var(--accent-lime);
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    z-index: 9999;
    animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: all 0.3s ease;
}

.toast.success {
    background: var(--dark-pill);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.toast.error {
    background: #ef4444;
    color: #ffffff;
}

.toast-close-btn {
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close-btn:hover {
    opacity: 1;
}

@keyframes toastIn {
    from { opacity: 0; transform: translateY(-10px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
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
                        <i class="bi bi-ticket-perforated"></i>
                        <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
                    </div>
                    <div>
                        <h1 class="dash-title"><strong>Manage Vouchers</strong> Track promotions, discount rules and usage</h1>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Active</p>
                    <p class="mgmt-card-value"><?= htmlspecialchars($totalActive) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Inactive</p>
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

            <!-- Control Bar -->
            <div class="mgmt-ctrl">
                <form method="GET">
                    <input type="hidden" name="limit" value="<?= htmlspecialchars($limit) ?>">
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                    <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">

                    <div class="search-box">
                        <i class="bi bi-search search-icon"></i>
                        <input type="text" name="search" placeholder="Search by code or name..." class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
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
                        <i class="bi bi-plus-lg"></i> Add Voucher
                    </a>

                    <button id="toggle-col-btn" title="Toggle Columns">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="code">Code</label>
                        <label><input type="checkbox" checked data-col="name">Name</label>
                        <label><input type="checkbox" checked data-col="discount">Discount</label>
                        <label><input type="checkbox" checked data-col="tier">Tier</label>
                        <label><input type="checkbox" checked data-col="type">Type</label>
                        <label><input type="checkbox" checked data-col="minSpend">Min Spend</label>
                        <label><input type="checkbox" checked data-col="usage">Usage</label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
                        <input type="checkbox" id="show-auto-toggle" name="show_auto" value="1" <?= $showAuto ? 'checked' : '' ?>>
                            Show system vouchers
                        </label>
                        <label><input type="checkbox" checked data-col="startDate">Start Date</label>
                        <label><input type="checkbox" checked data-col="expiryDate">Expiry Date</label>
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
                            <th class="code">Code</th>
                            
                            <th class="name">
                                <a class="sort-link <?= $sort=='VOUCHER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'VOUCHER_NAME',
                                    ($sort=='VOUCHER_NAME' && $order=='ASC') ? 'DESC' : 'ASC', $showAuto) ?>">
                                    <span>Name</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="discount">
                                <a class="sort-link <?= $sort=='DISCOUNT_RATE' ?'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$type,$limit,'DISCOUNT_RATE',
    ($sort=='DISCOUNT_RATE' && $order=='ASC') ? 'DESC' : 'ASC', $showAuto) ?>".>
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
                                    ($sort=='MIN_SPEND' && $order=='ASC') ? 'DESC' : 'ASC', $showAuto) ?>">
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
                                    ($sort=='START_DATE' && $order=='ASC') ? 'DESC' : 'ASC', $showAuto) ?>">
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
                                    ($sort=='EXPIRY_DATE' && $order=='ASC') ? 'DESC' : 'ASC', $showAuto) ?>">
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
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="code">
                                        <span class="code-pill">#<?= htmlspecialchars($row['VOUCHER_CODE']) ?></span>
                                    </td>

                                    <td class="name" style="font-weight:600;">
                                        <?= htmlspecialchars($row['VOUCHER_NAME']) ?>
                                    </td>

                                   <td class="discount" style="font-weight:700;">
                                        <?= $row['DISCOUNT_TYPE'] === 'FIXED'
                                            ? 'RM ' . intval($row['DISCOUNT_RATE'])
                                            : intval($row['DISCOUNT_RATE']) . '%' ?>
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
                                                    <i class="bi bi-eye"></i> View
                                                </a>

                                                <a href="edit_voucher.php?voucher_id=<?= $row['VOUCHER_ID'] ?>" class="edit-btn">
                                                    <i class="bi bi-pencil-square"></i> Edit
                                                </a>

                                                <button class="delete-btn" data-id="<?= $row['VOUCHER_ID'] ?>">
                                                    <i class="bi bi-trash"></i> Delete
                                                </button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="11" style="text-align:center; padding:24px; color:var(--text-muted);">No vouchers found</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
                        <input type="hidden" name="order" value="<?= htmlspecialchars($order) ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                        <input type="hidden" name="page" value="1"> 
                        <input type="hidden" name="show_auto" value="<?= $showAuto ? 1 : '' ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$type,$limit,$sort,$order,$showAuto) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$type,$limit,$sort,$order,$showAuto) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$type,$limit,$sort,$order,$showAuto) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>
                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$type,$limit,$sort,$order,$showAuto) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$type,$limit,$sort,$order,$showAuto) ?>">
                            <i class="bi bi-chevron-right"></i>
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
                    <input type="hidden" name="show_auto" value="<?= $showAuto ? 1 : '' ?>">

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

const showAutoToggle = document.getElementById("show-auto-toggle");
if (showAutoToggle) {
    showAutoToggle.addEventListener("change", function () {
        const url = new URL(window.location.href);
        if (this.checked) {
            url.searchParams.set("show_auto", "1");
        } else {
            url.searchParams.delete("show_auto");
        }
        url.searchParams.set("page", "1");
        window.location.href = url.toString();
    });
}

// initialize column visibility based on localStorage
document.querySelectorAll("#toggle-col-menu input").forEach(cb => {
    const key = "col_vch_" + cb.dataset.col;
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
            localStorage.removeItem("col_vch_" + cb.dataset.col);
            applyColumn(cb.dataset.col, true);
        });
    });
}

// action menu toggle (fixed positioning & boundary aware)
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
    const savedLimit = localStorage.getItem("preferred_limit_vch");

    if (!urlLimit && savedLimit) {
        if (!sessionStorage.getItem("limit_applied_vch")) {
            sessionStorage.setItem("limit_applied_vch", "true");

            url.searchParams.set('limit', savedLimit);
            url.searchParams.delete("page");
            url.searchParams.set("page", 1);

            window.location.replace(url.toString());
        }
    } else {
        sessionStorage.removeItem("limit_applied_vch");
    }
});

// change page limit
if (limitSelect) {
    limitSelect.addEventListener("change", function() {
        const selectedValue = this.value;
        localStorage.setItem("preferred_limit_vch", selectedValue);
        
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
            } else if (data.trim() === "error_in_use") {
                showToast("error", "Cannot delete: this voucher is still active and unused for some customers");
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
    function removeToast() {
        if (removed) return;
        removed = true;
        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";
        setTimeout(() => toast.remove(), 300);
    }

    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        removeToast();
    });

    toast.addEventListener("click", removeToast);
    const duration = type === "error" ? 8000 : 3000;
    setTimeout(removeToast, duration);
}
</script>

</body>
</html>