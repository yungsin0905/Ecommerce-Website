<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = intval($_SESSION['admin_id']);

// page title
$pageTitle = "Notifications";

// Filter notif (all | unread | read)
$status = $_GET['status'] ?? 'all';
// Filter type ( new order | refund request | new review | custom request)
$type   = $_GET['type'] ?? 'all';

$where = ["an.ADMIN_ID = $admin_id"];

if ($status == "unread") {
    $where[] = "an.IS_READ = 0";
} elseif ($status == "read") {
    $where[] = "an.IS_READ = 1";
}

if ($type != "all") {
    $typeEscaped = $conn->real_escape_string($type);
    $where[] = "n.TYPE = '$typeEscaped'";
}

$whereSQL = "WHERE " . implode(" AND ", $where);

// OPEN NOTIFICATION
if (isset($_GET['open_id'])) {

    $notifId = intval($_GET['open_id']);

    // Get notif (only belonging to this admin) — JOIN to get TYPE & REF_ID
    $notifQuery = $conn->query("
        SELECT n.TYPE, n.REF_ID
        FROM admin_notification an
        JOIN notification n ON an.NOTIF_ID = n.NOTIF_ID
        WHERE an.NOTIF_ID = $notifId
        AND an.ADMIN_ID = $admin_id
        LIMIT 1
    ");

    if ($notifQuery->num_rows > 0) {

        $notif = $notifQuery->fetch_assoc();

        // Mark as read
        $conn->query("
            UPDATE admin_notification
            SET IS_READ = 1
            WHERE NOTIF_ID = $notifId
            AND ADMIN_ID = $admin_id
        ");

        // Redirect based on type
        switch ($notif['TYPE']) {

            case 'Order':
                header("Location: view_order.php?order_id=" . $notif['REF_ID']);
                break;

            case 'Refund':
                header("Location: process_refund_request.php?request_id=" . $notif['REF_ID']);
                break;

            case 'Review':
                header("Location: view_review.php?review_id=" . $notif['REF_ID']);
                break;

            case 'Custom Request':
                header("Location: process_custom.php?id=" . $notif['REF_ID']);
                break;

            default:
                header("Location: notification.php");
        }

        exit();
    }
}

// Mark SINGLE notif as read
if (isset($_GET['read_id'])) {
    $id = intval($_GET['read_id']);

    $conn->query("
        UPDATE admin_notification
        SET IS_READ = 1
        WHERE NOTIF_ID = $id
        AND ADMIN_ID = $admin_id
    ");

    header("Location: notification.php");
    exit();
}

// Mark ALL notif as read
if (isset($_GET['read_all'])) {
    $conn->query("
        UPDATE admin_notification
        SET IS_READ = 1
        WHERE IS_READ = 0
        AND ADMIN_ID = $admin_id
    ");

    header("Location: notification.php");
    exit();
}

// Delete SINGLE notif (only remove the link, not the notification itself)
if (isset($_GET['delete_id'])) {

    $id = intval($_GET['delete_id']);

    $conn->query("
        DELETE FROM admin_notification
        WHERE NOTIF_ID = $id
        AND ADMIN_ID = $admin_id
    ");

    header("Location: notification.php");
    exit();
}

// Delete ALL notif for this admin
if (isset($_GET['delete_all'])) {

    $conn->query("
        DELETE FROM admin_notification
        WHERE ADMIN_ID = $admin_id
    ");

    header("Location: notification.php");
    exit();
}

// Fetch notifications — JOIN to get full data
$sql = "
    SELECT n.NOTIF_ID, n.MESSAGE, n.TYPE, n.REF_ID, n.CREATED_AT, an.IS_READ
    FROM admin_notification an
    JOIN notification n ON an.NOTIF_ID = n.NOTIF_ID
    $whereSQL
    ORDER BY n.CREATED_AT DESC
";

$result = $conn->query($sql);

// Summary counts for this admin
$cntRes = $conn->query("
    SELECT 
        COUNT(*) as total_count,
        SUM(CASE WHEN an.IS_READ = 0 THEN 1 ELSE 0 END) as unread_count,
        SUM(CASE WHEN an.IS_READ = 1 THEN 1 ELSE 0 END) as read_count,
        SUM(CASE WHEN n.TYPE = 'Order' THEN 1 ELSE 0 END) as order_count,
        SUM(CASE WHEN n.TYPE = 'Refund' THEN 1 ELSE 0 END) as refund_count
    FROM admin_notification an
    JOIN notification n ON an.NOTIF_ID = n.NOTIF_ID
    WHERE an.ADMIN_ID = $admin_id
");
$notifCounts = $cntRes ? $cntRes->fetch_assoc() : [];
$totalNotifs = (int)($notifCounts['total_count'] ?? 0);
$unreadNotifs = (int)($notifCounts['unread_count'] ?? 0);
$orderNotifs = (int)($notifCounts['order_count'] ?? 0);
$refundNotifs = (int)($notifCounts['refund_count'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | Admin</title>
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
    flex-wrap: wrap;
}

.btn-ctrl-action {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark) !important;
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    cursor: pointer;
}

.btn-ctrl-action:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}

.btn-ctrl-action.btn-dark-pill {
    background: var(--dark-pill);
    border-color: var(--dark-pill);
    color: #ffffff !important;
}

.btn-ctrl-action.btn-dark-pill i {
    color: #ffffff !important;
}

.btn-ctrl-action.btn-dark-pill:hover {
    background: var(--dark-pill-hover);
    color: #ffffff !important;
}

.btn-ctrl-action.btn-danger-pill {
    background: #fff;
    border-color: #fecaca;
    color: #dc2626 !important;
}

.btn-ctrl-action.btn-danger-pill i {
    color: #dc2626 !important;
}

.btn-ctrl-action.btn-danger-pill:hover {
    background: #fef2f2;
    border-color: #f87171;
}

/* Summary Metrics */
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
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.mgmt-sum-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
}

.mgmt-sum-card-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.mgmt-sum-card-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
}

.mgmt-sum-card-icon {
    width: 34px;
    height: 34px;
    border-radius: var(--radius-pill);
    background: #f3f4f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    color: var(--text-dark);
}

.mgmt-sum-card-value {
    font-family: var(--font-heading);
    font-size: 26px;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.mgmt-sum-card-sub {
    font-size: 12px;
    color: var(--text-light);
}

.mgmt-sum-card.highlight-unread {
    border-color: rgba(212, 249, 56, 0.6);
    background: linear-gradient(135deg, #ffffff 70%, var(--accent-lime-subtle) 100%);
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    display: inline-block;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* Filter Controls */
.filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 14px 20px;
    box-shadow: var(--shadow-soft);
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.status-pills {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f4f5f8;
    padding: 4px;
    border-radius: var(--radius-pill);
}

.status-pill-item {
    padding: 6px 14px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted) !important;
    text-decoration: none;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.status-pill-item:hover {
    color: var(--text-dark) !important;
}

.status-pill-item.active {
    background: var(--dark-pill);
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.status-pill-item.active i {
    color: #ffffff !important;
}

.status-pill-badge {
    background: var(--accent-lime);
    color: var(--text-dark) !important;
    font-size: 11px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: var(--radius-pill);
    line-height: 1.2;
}

.type-select-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}

.type-select-wrap label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-muted);
}

.styled-select {
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: #ffffff;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    outline: none;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.styled-select:focus {
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 2px rgba(24, 24, 27, 0.1);
}

/* Notifications Feed */
.notif-feed {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.notif-card-item {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: 18px;
    padding: 16px 20px;
    box-shadow: var(--shadow-soft);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    position: relative;
}

.notif-card-item:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
    border-color: #d1d5db;
}

.notif-card-item.unread {
    background: #ffffff;
    border-left: 4px solid var(--accent-lime);
}

.notif-card-item.unread::before {
    content: '';
    position: absolute;
    top: 14px;
    right: 14px;
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
}

.notif-item-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex: 1;
    min-width: 0;
}

.notif-icon-box {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}

.notif-icon-box.type-order {
    background: #ecfdf5;
    color: #059669 !important;
}
.notif-icon-box.type-order i {
    color: #059669 !important;
}

.notif-icon-box.type-refund {
    background: #fffbeb;
    color: #d97706 !important;
}
.notif-icon-box.type-refund i {
    color: #d97706 !important;
}

.notif-icon-box.type-review {
    background: #f5f3ff;
    color: #7c3aed !important;
}
.notif-icon-box.type-review i {
    color: #7c3aed !important;
}

.notif-icon-box.type-custom {
    background: #eff6ff;
    color: #2563eb !important;
}
.notif-icon-box.type-custom i {
    color: #2563eb !important;
}

.notif-icon-box.type-default {
    background: #f4f4f5;
    color: #52525b !important;
}
.notif-icon-box.type-default i {
    color: #52525b !important;
}

.notif-content {
    flex: 1;
    min-width: 0;
}

.notif-msg-link {
    display: block;
    font-size: 14.5px;
    font-weight: 600;
    color: var(--text-dark) !important;
    text-decoration: none;
    line-height: 1.4;
    margin-bottom: 5px;
    word-break: break-word;
    transition: color 0.15s;
}

.notif-card-item.unread .notif-msg-link {
    font-weight: 700;
}

.notif-msg-link:hover {
    color: #2563eb !important;
}

.notif-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    font-size: 12px;
    color: var(--text-muted);
}

.type-pill {
    padding: 2px 10px;
    border-radius: var(--radius-pill);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.type-pill.order { background: #d1fae5; color: #065f46 !important; }
.type-pill.refund { background: #fef3c7; color: #92400e !important; }
.type-pill.review { background: #ede9fe; color: #5b21b6 !important; }
.type-pill.custom { background: #dbeafe; color: #1e40af !important; }
.type-pill.default { background: #e4e4e7; color: #3f3f46 !important; }

.notif-time {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: var(--text-light);
}

.notif-item-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}

.btn-notif-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 14px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid var(--border-card);
    background: #ffffff;
    color: var(--text-dark) !important;
    transition: all 0.2s ease;
    cursor: pointer;
    gap: 5px;
}

.btn-notif-action i {
    font-size: 13px;
    color: inherit !important;
}

.btn-notif-action:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}

.btn-notif-action.btn-open {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
}

.btn-notif-action.btn-open i {
    color: #ffffff !important;
}

.btn-notif-action.btn-open:hover {
    background: var(--dark-pill-hover);
}

.btn-notif-action.btn-delete {
    color: #dc2626 !important;
    border-color: #fee2e2;
    background: #fff;
}

.btn-notif-action.btn-delete i {
    color: #dc2626 !important;
}

.btn-notif-action.btn-delete:hover {
    background: #fef2f2;
    border-color: #f87171;
}

/* Empty State */
.notif-empty-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 60px 20px;
    text-align: center;
    box-shadow: var(--shadow-soft);
}

.notif-empty-icon {
    width: 64px;
    height: 64px;
    border-radius: var(--radius-pill);
    background: #f4f5f8;
    color: var(--text-light) !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    margin-bottom: 16px;
}

.notif-empty-icon i {
    color: var(--text-light) !important;
}

.notif-empty-title {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 6px;
}

.notif-empty-desc {
    font-size: 13px;
    color: var(--text-muted);
    max-width: 360px;
    margin: 0 auto;
}

.btn-view{
    color: #ffffff;
}
</style>
</head>

<body>

<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>
    <div class="main">

        <div class="mgmt-main-cont">

            <!-- PAGE HEADER -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-bell-fill"></i>
                        <span class="badge-arrow"><i class="bi bi-broadcast"></i></span>
                    </div>
                    <div>
                        <h1 class="dash-title">System <strong>Notifications</strong></h1>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                            Real-time order notices, refund claims, and review activities
                        </div>
                    </div>
                </div>

                <div class="dash-header-controls">
                    <?php if ($unreadNotifs > 0): ?>
                        <a class="btn-ctrl-action" href="?status=<?= htmlspecialchars($status) ?>&type=<?= htmlspecialchars($type) ?>&read_all=1">
                            <i class="bi bi-check2-all"></i> Mark All Read
                        </a>
                    <?php endif; ?>

                    <?php if ($totalNotifs > 0): ?>
                        <a class="btn-ctrl-action btn-danger-pill" href="?delete_all=1"
                           onclick="return confirm('Are you sure you want to delete ALL notifications? This action cannot be reversed.')">
                            <i class="bi bi-trash3"></i> Clear All
                        </a>
                    <?php endif; ?>
                </div>
            </div>


            <!-- FILTER CONTROLS -->
            <div class="filter-card">
                <div class="status-pills">
                    <a href="?status=all&type=<?= urlencode($type) ?>" class="status-pill-item <?= $status === 'all' ? 'active' : '' ?>">
                        All Notifications
                    </a>
                    <a href="?status=unread&type=<?= urlencode($type) ?>" class="status-pill-item <?= $status === 'unread' ? 'active' : '' ?>">
                        Unread
                        <?php if ($unreadNotifs > 0): ?>
                            <span class="status-pill-badge"><?= $unreadNotifs ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="?status=read&type=<?= urlencode($type) ?>" class="status-pill-item <?= $status === 'read' ? 'active' : '' ?>">
                        Read
                    </a>
                </div>

                <form method="GET" class="type-select-wrap">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
                    <label for="type-filter">Category:</label>
                    <select name="type" id="type-filter" class="styled-select" onchange="this.form.submit()">
                        <option value="all" <?= ($type=='all')?'selected':'' ?>>All Types</option>
                        <option value="Order" <?= ($type=='Order')?'selected':'' ?>>📦 Orders</option>
                        <option value="Refund" <?= ($type=='Refund')?'selected':'' ?>>🔄 Refund Requests</option>
                        <option value="Review" <?= ($type=='Review')?'selected':'' ?>>⭐ Product Reviews</option>
                        <option value="Custom Request" <?= ($type=='Custom Request')?'selected':'' ?>>🛠️ Custom Inquiries</option>
                    </select>
                </form>
            </div>

            <!-- NOTIFICATIONS FEED -->
            <div class="notif-feed">
                <?php if ($result && $result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): 
                        $isRead = (bool)$row['IS_READ'];
                        $rawType = $row['TYPE'];
                        
                        $iconClass = "type-default";
                        $icon = "bi-bell-fill";
                        $pillClass = "default";

                        switch ($rawType) {
                            case 'Order':
                                $iconClass = "type-order";
                                $icon = "bi-bag-check-fill";
                                $pillClass = "order";
                                break;
                            case 'Refund':
                                $iconClass = "type-refund";
                                $icon = "bi-arrow-counterclockwise";
                                $pillClass = "refund";
                                break;
                            case 'Review':
                                $iconClass = "type-review";
                                $icon = "bi-star-fill";
                                $pillClass = "review";
                                break;
                            case 'Custom Request':
                                $iconClass = "type-custom";
                                $icon = "bi-sliders";
                                $pillClass = "custom";
                                break;
                        }
                    ?>
                        <div class="notif-card-item <?= $isRead ? 'read' : 'unread' ?>">
                            <div class="notif-item-left">
                                <div class="notif-icon-box <?= $iconClass ?>">
                                    <i class="bi <?= $icon ?>"></i>
                                </div>
                                <div class="notif-content">
                                    <a class="notif-msg-link" href="?open_id=<?= $row['NOTIF_ID'] ?>">
                                        <?= htmlspecialchars($row['MESSAGE']) ?>
                                    </a>
                                    <div class="notif-meta">
                                        <span class="type-pill <?= $pillClass ?>">
                                            <?= htmlspecialchars($row['TYPE']) ?>
                                        </span>
                                        <span class="notif-time">
                                            <i class="bi bi-clock"></i>
                                            <?= date("d M Y, h:i A", strtotime($row['CREATED_AT'])) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="notif-item-actions">
                                <a href="?open_id=<?= $row['NOTIF_ID'] ?>" class="btn-notif-action btn-open" title="Open and jump to details">
                                    <span class="btn-view">View</span>
                                    <i class="bi bi-arrow-right-short"></i>
                                </a>

                                <?php if (!$isRead): ?>
                                    <a href="?read_id=<?= $row['NOTIF_ID'] ?>" class="btn-notif-action" title="Mark as read">
                                        <i class="bi bi-check2"></i>
                                        <span class="btn-mark-read">Mark Read</span>
                                    </a>
                                <?php endif; ?>

                                <a class="btn-notif-action btn-delete" href="?delete_id=<?= $row['NOTIF_ID'] ?>"
                                   onclick="return confirm('Delete this notification?')" title="Delete notification">
                                    <i class="bi bi-trash3"></i>
                                </a>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="notif-empty-card">
                        <div class="notif-empty-icon">
                            <i class="bi bi-bell-slash"></i>
                        </div>
                        <div class="notif-empty-title">No notifications found</div>
                        <div class="notif-empty-desc">
                            You're all caught up! New orders, refund requests, and customer feedback will show up here.
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>
</div>

</body>
</html>