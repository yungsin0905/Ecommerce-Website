<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
} 
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Manage Reviews";

// search, filter input
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$replyStatus = $_GET['replyStatus'] ?? '';
$rating = $_GET['rating'] ?? '';
$fromDate = $_GET['fromDate'] ?? '';
$toDate = $_GET['toDate'] ?? '';

// sorting 
$sort = $_GET['sort'] ?? 'CREATED_AT';
$order = $_GET['order'] ?? 'DESC';
$allowedSort = [
    'CUSTOMER_NAME',
    'RATING',
    'CREATED_AT',
    'PRODUCT_NAME'
];
$allowedOrder = ['ASC', 'DESC'];
if (!in_array($sort, $allowedSort)) {
    $sort = 'CREATED_AT';
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

$totalAllReviews = getCount($conn, "SELECT COUNT(*) AS c FROM review")['c'] ?? 0;

$totalUnreplied = getCount($conn, "
    SELECT COUNT(*) AS r
    FROM review r
    LEFT JOIN review_reply rr 
        ON r.REVIEW_ID = rr.REVIEW_ID 
        AND rr.IS_DELETED = 0
    WHERE rr.REVIEW_ID IS NULL
")['r'] ?? 0;

$totalReplied = getCount($conn, "
    SELECT COUNT(DISTINCT r.REVIEW_ID) AS r
    FROM review r
    JOIN review_reply rr 
        ON r.REVIEW_ID = rr.REVIEW_ID 
        AND rr.IS_DELETED = 0
")['r'] ?? 0;

$avgRatingData = getCount($conn, "SELECT AVG(RATING) AS avg_r FROM review");
$avgRating = $avgRatingData && $avgRatingData['avg_r'] !== null ? number_format((float)$avgRatingData['avg_r'], 1) : "0.0";

$totalHidden = getCount($conn, "
    SELECT COUNT(*) AS r
    FROM review
    WHERE REVIEW_STATUS = 'Hide'
")['r'] ?? 0;

// search, filter conditions
$where = [];
$params = [];
$types = "";

if ($search != '') {
    $where[] = "(c.CUSTOMER_NAME LIKE ? OR o.ORDER_NO LIKE ? OR p.PRODUCT_NAME LIKE ?)";

    $searchParam = "%$search%";

    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;

    $types .= "sss";
}

if ($status != '') {
    $where[] = "r.REVIEW_STATUS = ?";
    $params[] = $status;
    $types .= "s";
}

if ($replyStatus == 'Reply') {
    $where[] = "EXISTS (
        SELECT 1 FROM review_reply rr 
        WHERE rr.REVIEW_ID = r.REVIEW_ID 
        AND rr.IS_DELETED = 0
    )";
} elseif ($replyStatus == 'Unreply') {
    $where[] = "NOT EXISTS (
        SELECT 1 FROM review_reply rr 
        WHERE rr.REVIEW_ID = r.REVIEW_ID 
        AND rr.IS_DELETED = 0
    )";
}

if ($rating != '') {
    $where[] = "r.RATING >= ?";
    $params[] = $rating;
    $types .= "i";
}

/* Date Range Filters */
if ($fromDate != '') {
    $where[] = "r.CREATED_AT >= ?";
    $params[] = $fromDate . " 00:00:00";
    $types .= "s";
}
if ($toDate != '') {
    $where[] = "r.CREATED_AT <= ?";
    $params[] = $toDate . " 23:59:59";
    $types .= "s";
}

$whereSql = "";
if (count($where) > 0) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

// get total rows for pagination
$countSql = "
SELECT COUNT(DISTINCT r.REVIEW_ID) AS total
FROM review r
JOIN customer c ON r.CUSTOMER_ID = c.CUSTOMER_ID
LEFT JOIN orders o ON r.ORDER_ID = o.ORDER_ID
LEFT JOIN product p ON r.PRODUCT_ID = p.PRODUCT_ID
LEFT JOIN (
    SELECT DISTINCT REVIEW_ID
    FROM review_reply
    WHERE IS_DELETED = 0
) rr ON r.REVIEW_ID = rr.REVIEW_ID
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

// fetch review list (With Filter, Sort, Pagination)
$listSql = "
SELECT 
    r.*, 
    c.CUSTOMER_NAME, 
    o.ORDER_NO,
    p.PRODUCT_NAME,
    CASE 
        WHEN EXISTS (
            SELECT 1 
            FROM review_reply rr 
            WHERE rr.REVIEW_ID = r.REVIEW_ID 
            AND rr.IS_DELETED = 0
        ) THEN 'Reply'
        ELSE 'Unreply'
    END AS REPLY_STATUS
FROM review r
JOIN customer c ON r.CUSTOMER_ID = c.CUSTOMER_ID
LEFT JOIN orders o ON r.ORDER_ID = o.ORDER_ID
LEFT JOIN product p ON r.PRODUCT_ID = p.PRODUCT_ID
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

// Build URL (for Pagination & Sorting Links)
function buildUrl($page, $search, $status, $replyStatus, $rating, $fromDate, $toDate, $limit, $sort, $order) {
    return "?" . http_build_query([
        "page" => $page,
        "search" => $search,
        "status" => $status,
        "replyStatus" => $replyStatus,
        "rating" => $rating,
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
    <title>Manage Reviews | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
</head>

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

.badge-arrow i {
    color: var(--accent-lime);
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
    width: 320px;
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
    box-sizing: border-box;
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

.date-range-group {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 4px 10px;
    border-radius: var(--radius-pill);
}

.date-range-group label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
}

.date-range-group input[type="date"] {
    border: none;
    background: transparent;
    font-size: 12px;
    color: var(--text-dark);
    font-family: var(--font-body);
    outline: none;
    cursor: pointer;
}

.date-range-group span {
    font-size: 12px;
    color: var(--text-muted);
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

/* Links inside Table */
.order-link {
    font-family: monospace;
    font-weight: 700;
    color: var(--text-dark);
    background: var(--bg-page);
    padding: 4px 8px;
    border-radius: 6px;
    border: 1px solid var(--border-card);
    text-decoration: none;
    transition: all 0.2s;
    display: inline-block;
}

.order-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
}

.product {
    max-width: 170px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-weight: 600;
    color: var(--text-dark);
}

.customer {
    font-weight: 600;
    color: var(--text-dark);
}

/* Rating Stars */
.rating-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 700;
    font-size: 13px;
    color: #b45309;
    background: #fef3c7;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
}

.rating-pill i {
    color: #f59e0b;
    font-size: 12px;
}

/* Comment preview */
.comment {
    max-width: 220px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    color: var(--text-muted);
}

/* Review Image */
.review-thumb {
    width: 42px;
    height: 42px;
    object-fit: cover;
    border-radius: 8px;
    border: 1px solid var(--border-card);
    transition: transform 0.2s;
}

.review-thumb:hover {
    transform: scale(1.1);
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.badge.status-unhide {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge.status-hide {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.badge.reply-status-reply {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}

.badge.reply-status-unreply {
    background: #fff4e5;
    color: #b26a00;
    border: 1px solid #ffd59e;
}

.since {
    color: var(--text-muted);
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

/* Action Menu */
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
    min-width: 145px;
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
    font-family: var(--font-body);
}

.action-menu a:hover,
.action-menu button:hover {
    background: var(--bg-page);
}

/* Modals */
.modal {
    display: none;
    position: fixed;
    z-index: 9999;
    inset: 0;
    background: rgba(17, 24, 39, 0.45);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: #ffffff;
    width: 90%;
    max-width: 480px;
    padding: 28px;
    border-radius: var(--radius-card);
    box-shadow: 0 20px 40px rgba(17, 24, 39, 0.15);
    border: 1px solid var(--border-card);
    box-sizing: border-box;
    animation: modalScaleIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalScaleIn {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.modal-title {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0;
}

.modal-close-icon {
    background: var(--bg-page);
    border: none;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-pill);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--text-muted);
    transition: all 0.2s;
}

.modal-close-icon:hover {
    background: var(--dark-pill);
    color: #ffffff;
}

.modal-textarea {
    width: 100%;
    height: 100px;
    padding: 12px;
    border-radius: 12px;
    border: 1px solid var(--border-card);
    font-family: var(--font-body);
    font-size: 13.5px;
    background: var(--bg-page);
    color: var(--text-dark);
    box-sizing: border-box;
    resize: vertical;
    transition: all 0.2s;
}

.modal-textarea:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.modal-select {
    width: 100%;
    padding: 10px 14px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    font-family: var(--font-body);
    font-size: 13.5px;
    background: var(--bg-page);
    color: var(--text-dark);
    box-sizing: border-box;
    outline: none;
    transition: all 0.2s;
}

.modal-select:focus {
    background: #ffffff;
    border-color: var(--dark-pill);
}

.modal-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 24px;
}

.btn-modal-cancel {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 9px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s;
}

.btn-modal-cancel:hover {
    background: #e4e4e7;
    color: var(--text-dark);
}

.btn-modal-save {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    padding: 9px 22px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(24, 24, 27, 0.15);
}

.btn-modal-save:hover {
    background: #000000;
    color: var(--accent-lime);
}

/* Reply List Modal */
#replyListModal .modal-content {
    max-width: 580px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

#replyListContainer {
    display: flex;
    flex-direction: column;
    gap: 12px;
    overflow-y: auto;
    padding-right: 4px;
    margin-top: 6px;
    max-height: 50vh;
}

/* Reply Card in modal */
.reply-item-card {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.reply-item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.reply-item-admin {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 700;
    color: var(--text-dark);
}

.reply-item-text {
    font-size: 13.5px;
    color: var(--text-dark);
    line-height: 1.5;
    margin: 0;
}

.reply-item-date {
    font-size: 11.5px;
    color: var(--text-muted);
}

.reply-delete-btn {
    background: #fee2e2;
    color: #dc2626;
    border: none;
    padding: 4px 10px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.reply-delete-btn:hover {
    background: #fecaca;
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
    gap: 16px;
    flex-wrap: wrap;
}

.limit-selector {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
}

.page-limit {
    border: 1px solid var(--border-card);
    background: var(--bg-page);
    border-radius: var(--radius-pill);
    padding: 6px 12px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    cursor: pointer;
    outline: none;
}

.pagination-left {
    display: flex;
    align-items: center;
}

.page-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}

.page-num, .page-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 0 8px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    color: var(--text-dark);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
}

.page-num:hover, .page-btn:hover {
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

.pagination-right {
    display: flex;
    align-items: center;
}

.jump-page-form {
    display: flex;
    align-items: center;
    gap: 8px;
}

.jump-input {
    width: 55px;
    padding: 6px 10px;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    text-align: center;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    outline: none;
}

.jump-input:focus {
    border-color: var(--dark-pill);
    background: #ffffff;
}

.jump-btn {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-pill);
    padding: 6px 14px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
}

.jump-btn:hover {
    background: #000000;
    color: var(--accent-lime);
}

.jump-total {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
}

/* Toast Notification */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    background: var(--dark-pill);
    color: #ffffff;
    padding: 12px 20px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 99999;
    animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: all 0.3s ease;
}

.toast.success {
    background: #15803d;
    border: 1px solid #22c55e;
}

.toast.error {
    background: #b91c1c;
    border: 1px solid #ef4444;
}

.toast-close-btn {
    cursor: pointer;
    font-size: 16px;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close-btn:hover {
    opacity: 1;
}

@keyframes toastIn {
    0% { transform: translateY(-20px); opacity: 0; }
    100% { transform: translateY(0); opacity: 1; }
}
</style>

<body>
  <div class="global-layout">
    <?php include "global_layout_ctrl.php" ?>
      <div class="main">

        <div class="mgmt-main-cont">
            <!-- Header -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-star-half"></i>
                        <span class="badge-arrow"><i class="bi bi-arrow-up-right"></i></span>
                    </div>
                    <div class="dash-title">
                        Review
                        <strong>Manage Reviews</strong>
                    </div>
                </div>
                <div class="dash-header-controls">
                    <div class="live-pill">
                        <span class="pulse-dot"></span>
                        <?= number_format($totalAllReviews) ?> Reviews Total
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Total Reviews <span class="card-tag">All</span></p>
                    <p class="mgmt-card-value"><?= number_format($totalAllReviews) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Unreplied <span class="card-tag" style="background:#fff4e5;color:#b26a00;">Pending</span></p>
                    <p class="mgmt-card-value" style="color:#d97706;"><?= number_format($totalUnreplied) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Replied <span class="card-tag" style="background:#dcfce7;color:#15803d;">Done</span></p>
                    <p class="mgmt-card-value" style="color:#16a34a;"><?= number_format($totalReplied) ?></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Avg. Rating <span class="card-tag" style="background:#fef3c7;color:#b45309;">Score</span></p>
                    <p class="mgmt-card-value"><?= $avgRating ?> <span style="font-size:16px;color:#f59e0b;">★</span></p>
                </div>

                <div class="mgmt-sum-card">
                    <p class="mgmt-card-title">Hidden <span class="card-tag" style="background:#fee2e2;color:#dc2626;">Hidden</span></p>
                    <p class="mgmt-card-value"><?= number_format($totalHidden) ?></p>
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
                        <input type="text" name="search" placeholder="Search customer, product, order #..." class="mgmt-search" value="<?php echo htmlspecialchars($search); ?>">
                    </div>

                    <select name="status" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Review Status</option>
                      <option value="Hide" <?php echo ($status == 'Hide') ? 'selected' : ''; ?>>Hide</option>
                      <option value="Unhide" <?php echo ($status == 'Unhide') ? 'selected' : '' ?>>Unhide</option>
                    </select>

                    <select name="replyStatus" class="mgmt-filter" onchange="this.form.submit()">
                      <option value="">All Reply Status</option>
                      <option value="Reply" <?php echo ($replyStatus == 'Reply') ? 'selected' : ''; ?>>Reply</option>
                      <option value="Unreply" <?php echo ($replyStatus == 'Unreply') ? 'selected' : ''; ?>>Unreply</option>
                    </select>

                    <select name="rating" class="mgmt-filter" onchange="this.form.submit()">
                        <option value="">All Rating</option>
                        <option value="4" <?php echo ($rating == 4) ? 'selected' : '' ?>>4.0 & above</option>
                        <option value="3" <?php echo ($rating == 3) ? 'selected' : '' ?>>3.0 & above</option>
                        <option value="2" <?php echo ($rating == 2) ? 'selected' : '' ?>>2.0 & above</option>
                        <option value="1" <?php echo ($rating == 1) ? 'selected' : '' ?>>1.0 & above</option>
                    </select>

                    <!-- Date Range Filters -->
                    <div class="date-range-group">
                        <label><i class="bi bi-calendar3"></i></label>
                        <input type="date" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>" onchange="this.form.submit()" title="From Date">
                        <span>to</span>
                        <input type="date" name="toDate" value="<?= htmlspecialchars($toDate) ?>" onchange="this.form.submit()" title="To Date">
                    </div>

                    <?php if (!empty($search) || !empty($status) || !empty($replyStatus) || !empty($rating) || !empty($fromDate) || !empty($toDate)): ?>
                        <button type="button" id="clear-ctrl-btn" title="Clear Search and Filter Input">
                            <i class="bi bi-x-circle"></i> Clear
                        </button>
                    <?php endif; ?>
                </form>

                <div class="toggle-col-cont">
                    <button id="toggle-col-btn" title="Toggle Columns">
                        <i class="bi bi-layout-three-columns"></i>
                    </button>

                    <div id="toggle-col-menu">
                        <label><input type="checkbox" checked data-col="orderNo">Order No</label>
                        <label><input type="checkbox" checked data-col="product">Product</label>
                        <label><input type="checkbox" checked data-col="customer">Customer</label>
                        <label><input type="checkbox" checked data-col="rating">Rating</label>
                        <label><input type="checkbox" checked data-col="comment">Comment</label>
                        <label><input type="checkbox" checked data-col="image">Image</label>
                        <label><input type="checkbox" checked data-col="status">Status</label>
                        <label><input type="checkbox" checked data-col="replyStatus">Reply Status</label>
                        <label><input type="checkbox" checked data-col="since">Since</label>
                        <button id="reset-col-btn" title="Reset Columns">Reset</button>
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="mgmt-tbl">
                <table>
                    <thead>
                        <tr>
                            <th class="orderNo">Order No</th>

                            <th class="product">
                                <a class="sort-link <?= $sort=='PRODUCT_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                   href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'PRODUCT_NAME',
                                   ($sort=='PRODUCT_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Product</span>
                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="customer">
                                <a class="sort-link <?= $sort=='CUSTOMER_NAME' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'CUSTOMER_NAME',
                                   ($sort=='CUSTOMER_NAME' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Customer</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="rating">
                                <a class="sort-link <?= $sort=='RATING' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'RATING',
                                   ($sort=='RATING' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                   <span>Rating</span>
                                    <span class="sort-icons">
                                       <span class="up"><i class="bi bi-chevron-up"></i></span>
                                       <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="comment">Comment</th>

                            <th class="image">Image</th>

                            <th class="status">Status</th>

                            <th class="since">
                                <a class="sort-link <?= $sort=='CREATED_AT' ? 'active-sort '.strtolower($order) : '' ?>"
                                    href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,'CREATED_AT',
                                    ($sort=='CREATED_AT' && $order=='ASC') ? 'DESC' : 'ASC') ?>">
                                    <span>Since</span>
                                    <span class="sort-icons">
                                        <span class="up"><i class="bi bi-chevron-up"></i></span>
                                        <span class="down"><i class="bi bi-chevron-down"></i></span>
                                    </span>
                                </a>
                            </th>

                            <th class="replyStatus">Reply Status</th>

                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result->num_rows == 0): ?>
                            <tr>
                                <td colspan="10" style="text-align: center; padding: 36px 20px; color: var(--text-muted);">
                                    <i class="bi bi-inbox" style="font-size: 28px; display: block; margin-bottom: 8px; color: var(--text-light);"></i>
                                    No reviews found matching the criteria.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td class="orderNo">
                                        <?php if (!empty($row['ORDER_NO'])): ?>
                                            <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="order-link">
                                                #<?= htmlspecialchars($row['ORDER_NO']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted)">N/A</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="product" title="<?= htmlspecialchars($row['PRODUCT_NAME'] ?? '', ENT_QUOTES) ?>">
                                        <?= htmlspecialchars($row['PRODUCT_NAME'] ?? 'N/A') ?>
                                    </td>
                                    
                                    <td class="customer">
                                        <?php echo htmlspecialchars($row['CUSTOMER_NAME']); ?>
                                    </td>

                                    <td class="rating">
                                        <div class="rating-pill">
                                            <i class="bi bi-star-fill"></i>
                                            <?= htmlspecialchars($row['RATING']); ?>.0
                                        </div>
                                    </td>

                                    <td class="comment" title="<?= htmlspecialchars($row['COMMENTS'] ?? '', ENT_QUOTES) ?>">
                                        <?php if (!empty(trim($row['COMMENTS'] ?? ''))): ?>
                                            <?= htmlspecialchars($row['COMMENTS']); ?>
                                        <?php else: ?>
                                            <span style="color:var(--text-light); font-style: italic;">No comment</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="image">
                                        <?php if (!empty($row['REVIEW_IMAGE'])): ?>
                                            <a href="../<?= htmlspecialchars($row['REVIEW_IMAGE']) ?>" target="_blank">
                                                <img src="../<?= htmlspecialchars($row['REVIEW_IMAGE']) ?>" alt="Review Image" class="review-thumb">
                                            </a>
                                        <?php else: ?>
                                            <span style="color:var(--text-light); font-size:12px;">None</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="status">
                                        <span class="badge status-<?= strtolower($row['REVIEW_STATUS']) ?>">
                                            <i class="bi bi-<?= strtolower($row['REVIEW_STATUS']) === 'unhide' ? 'eye-fill' : 'eye-slash-fill' ?>"></i>
                                            <?= htmlspecialchars($row['REVIEW_STATUS']); ?>
                                        </span>
                                    </td>

                                    <td class="since">
                                        <?= date("d M Y", strtotime($row['CREATED_AT'])); ?>
                                    </td>

                                    <td class="replyStatus">
                                        <span class="badge reply-status-<?= strtolower($row['REPLY_STATUS']) ?>">
                                            <i class="bi bi-<?= strtolower($row['REPLY_STATUS']) === 'reply' ? 'check2' : 'exclamation-circle' ?>"></i>
                                            <?= htmlspecialchars($row['REPLY_STATUS']); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="action-wrapper">
                                            <button class="action-btn" title="Actions">
                                                <i class="bi bi-three-dots"></i>
                                            </button>

                                            <div class="action-menu">
                                                <a href="view_review.php?review_id=<?= $row['REVIEW_ID'] ?>" class="view-btn">
                                                    <i class="bi bi-eye"></i> View
                                                </a>

                                                <button type="button" class="reply-btn" onclick="openReplyModal(<?= $row['REVIEW_ID'] ?>)">
                                                    <i class="bi bi-reply"></i> Reply
                                                </button>

                                                <button type="button" class="update-btn" onclick="openStatusModal(<?= $row['REVIEW_ID'] ?>, '<?= $row['REVIEW_STATUS'] ?>')">
                                                    <i class="bi bi-arrow-repeat"></i> Update Status
                                                </button>

                                                <button type="button" class="reply-list-btn" onclick="openReplyListModal(<?= $row['REVIEW_ID'] ?>)">
                                                    <i class="bi bi-chat-dots"></i> View Replies
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

            <!-- Reply to Review Pop Up -->
            <div id="replyModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Reply to Review</h3>
                        <button type="button" class="modal-close-icon" onclick="closeReplyModal()">&times;</button>
                    </div>

                    <form id="replyForm">
                        <input type="hidden" name="review_id" id="replyReviewId">
                        <label style="font-size: 13px; font-weight: 600; color: var(--text-dark); display: block; margin-bottom: 6px;">
                            Your Response:
                        </label>
                        <textarea name="reply" id="modalReply" maxlength="300" required placeholder="Write a professional reply to the customer..." class="modal-textarea"></textarea>
                        <div style="margin-top: 6px; display: flex; justify-content: space-between; align-items: center;">
                            <small style="color: var(--text-muted); font-size: 11.5px;">Maximum 300 characters</small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-modal-cancel" onclick="closeReplyModal()">Cancel</button>
                            <button type="submit" class="btn-modal-save">Send Reply</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Status Update Pop Up -->
            <div id="statusModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3 class="modal-title">Update Review Status</h3>
                        <button type="button" class="modal-close-icon" onclick="closeModal()">&times;</button>
                    </div>

                    <form id="statusForm">
                        <input type="hidden" name="review_id" id="statusReviewId">

                        <label style="font-size: 13px; font-weight: 600; color: var(--text-dark); display: block; margin-bottom: 8px;">
                            Select Visibility:
                        </label>
                        <select name="status" id="modalStatus" class="modal-select" required>
                            <option value="Hide">Hide (Hidden from public store)</option>
                            <option value="Unhide">Unhide (Visible to all)</option>
                        </select>

                        <div class="modal-footer">
                            <button type="button" class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
                            <button type="submit" class="btn-modal-save">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- View Reply Pop Up -->
            <div id="replyListModal" class="modal">
                <div class="modal-content">
                   <div class="modal-header">
                       <h3 class="modal-title">Review Reply History</h3>
                       <button type="button" class="modal-close-icon" onclick="closeReplyListModal()">&times;</button>
                   </div>

                   <div id="replyListContainer"></div>

                   <div class="modal-footer">
                       <button type="button" class="btn-modal-cancel" onclick="closeReplyListModal()">Close</button>
                   </div>
                </div>
            </div>

            <!-- Pagination -->
            <div class="mgmt-pagination">
                <div class="limit-selector">
                    <form method="GET">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                        <input type="hidden" name="replyStatus" value="<?= htmlspecialchars($replyStatus) ?>">
                        <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
                        <input type="hidden" name="fromDate" value="<?= htmlspecialchars($fromDate) ?>">
                        <input type="hidden" name="toDate" value="<?= htmlspecialchars($toDate) ?>">
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
                        <a class="page-btn" href="<?= buildUrl($page-1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    <?php endif; ?>

                    <!-- First page -->
                    <?php if ($start > 1): ?>
                        <a class="page-num" href="<?= buildUrl(1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">1</a>

                        <?php if ($start > 2): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Middle pages -->
                    <?php for ($i = $start; $i <= $end; $i++): ?>
                        <a class="page-num <?= $i==$page ? 'active' : '' ?>" href="<?= buildUrl($i,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Last page -->
                    <?php if ($end < $totalPages): ?>

                        <?php if ($end < $totalPages - 1): ?>
                            <span class="page-ellipsis">...</span>
                        <?php endif; ?>

                        <a class="page-num" href="<?= buildUrl($totalPages,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <?= $totalPages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                        <a class="page-btn" href="<?= buildUrl($page+1,$search,$status,$replyStatus,$rating,$fromDate,$toDate,$limit,$sort,$order) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                    </div>
                </div>

                <div class="pagination-right">
                    <form method="GET" class="jump-page-form">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <input type="hidden" name="replyStatus" value="<?= htmlspecialchars($replyStatus) ?>">
                    <input type="hidden" name="rating" value="<?= htmlspecialchars($rating) ?>">
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
        url.searchParams.delete("replyStatus");
        url.searchParams.delete("rating");
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
    closeActionMenus();

    if (toggleColMenu) {
        toggleColMenu.classList.remove("show");
    }
});

// page limit persistence
const limitSelect = document.getElementById("page-limit-select");
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

// Show Toast
function showToast(type, message) {
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

    const duration = type === "error" ? 8000 : 3000;
    setTimeout(removeToast, duration);
}

// REPLY TO REVIEW POP UP - OPEN
function openReplyModal(reviewId) {
    const modal = document.getElementById("replyModal");
    if(modal) {
        modal.style.display = "flex";
        document.getElementById("replyReviewId").value = reviewId;
        document.getElementById("modalReply").value = "";
        document.getElementById("modalReply").focus();
    }
}

// REPLY TO REVIEW POP UP - CLOSE
function closeReplyModal() {
    const modal = document.getElementById("replyModal");
    if(modal) {
        modal.style.display = "none";
    }
}

// SUBMIT review reply
const replyForm = document.getElementById("replyForm");
if (replyForm) {
    replyForm.addEventListener("submit", function(e) {
        e.preventDefault();

        const formData = new FormData(this);

        fetch("reply_review.php", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "ok") {
                showToast("success", "Reply sent successfully!");
                closeReplyModal();
                setTimeout(() => location.reload(), 800);
            } else {
                showToast("error", "Error sending reply: " + data);
            }
        })
        .catch(err => {
            showToast("error", "Error sending reply");
            console.error(err);
        });
    });
}

// UPDATE STATUS POP UP - OPEN
function openStatusModal(reviewId, currentStatus) {
    const modal = document.getElementById("statusModal");
    modal.style.display = "flex";
    document.getElementById("statusReviewId").value = reviewId;
    document.getElementById("modalStatus").value = currentStatus;
}

// UPDATE STATUS POP UP - CLOSE
function closeModal() {
    document.getElementById("statusModal").style.display = "none";
}

// UPDATE STATUS (submit via AJAX)
document.getElementById("statusForm").addEventListener("submit", function(e){
    e.preventDefault();

    const formData = new FormData(this);

    fetch("update_review.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        if (data.trim() === "success") {
            showToast("success", "Review status updated successfully!");
            closeModal();
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            showToast("error", "Update failed: " + data);
        }
    })
    .catch(err => {
        showToast("error", "Error updating status");
        console.error(err);
    });
});

// DELETE REPLY LIST
function deleteReply(replyId) {
    if (!replyId) {
        showToast("error", "No reply found");
        return;
    }

    if (!confirm("Are you sure you want to delete this reply?")) {
        return;
    }

    const formData = new FormData();
    formData.append("reply_id", replyId);

    fetch("delete_reply.php", {
        method: "POST",
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        showToast("success", "Reply deleted successfully!");
        setTimeout(() => location.reload(), 800);
    })
    .catch(err => {
        showToast("error", "Error deleting reply");
        console.error(err);
    });
}

// VIEW REPLY LIST POP UP - OPEN
function openReplyListModal(reviewId) {
    const modal = document.getElementById("replyListModal");
    modal.style.display = "flex";
    document.getElementById("replyListContainer").innerHTML = "<div style='text-align:center;padding:20px;color:var(--text-muted);'><i class='bi bi-arrow-repeat' style='animation:spin 1s linear infinite;display:inline-block;'></i> Loading replies...</div>";

    fetch("get_reply_list.php?review_id=" + reviewId)
        .then(res => res.text())
        .then(html => {
            document.getElementById("replyListContainer").innerHTML = html;
        })
        .catch(err => {
            document.getElementById("replyListContainer").innerHTML = "<p style='color:#dc2626;'>Failed to load replies.</p>";
        });
}

// VIEW REPLY LIST POP UP - CLOSE
function closeReplyListModal() {
    document.getElementById("replyListModal").style.display = "none";
}

// Close Modals on backdrop click
window.addEventListener("click", function (e) {
    const replyModal = document.getElementById("replyModal");
    const statusModal = document.getElementById("statusModal");
    const replyListModal = document.getElementById("replyListModal");

    if (e.target === replyModal) closeReplyModal();
    if (e.target === statusModal) closeModal();
    if (e.target === replyListModal) closeReplyListModal();
});
</script>

</body>
</html>