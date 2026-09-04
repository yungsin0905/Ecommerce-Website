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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification</title>
    <link rel="stylesheet" href="admin_global.css">
<style>
body {
    background: var(--primary-grey);
    font-family: var(--font-family);
}

.container {
    padding: 20px;
}

h2 {
    margin-bottom: 20px;
    font-size: 24px;
    font-weight: 700;
}

.top-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
}

.top-bar .btn {
    padding: 8px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    background: #f2f2f2;
    color: #333;
    border: 1px solid #ddd;
    transition: 0.2s;
}

.top-bar .btn:hover {
    background: var(--primary-dark);
    color: #fff;
    border-color: var(--primary-dark);
}

.notif-card {
    position: relative;
    background: #fff;
    border: 1px solid #e6e6e6;
    border-radius: 10px;
    padding: 15px 18px;
    margin-bottom: 12px;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    transition: 0.2s;
}

.notif-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 14px rgba(0,0,0,0.08);
}

.notif-card.unread {
    border-left: 5px solid #e91e63;
    background: #fff8fa;
}

.notif-card.unread::before {
    content: "NEW";
    font-size: 10px;
    background: #e91e63;
    color: #fff;
    padding: 2px 6px;
    border-radius: 4px;
    position: absolute;
    top: 12px;
    right: 12px;
}

.notif-card > div:first-child {
    font-size: 15px;
    font-weight: 500;
    margin-bottom: 8px;
    color: #222;
}

.meta {
    font-size: 12px;
    color: #777;
    margin-bottom: 10px;
}

.action a {
    display: inline-block;
    font-size: 13px;
    color: #e91e63;
    text-decoration: none;
    font-weight: 600;
}

.action a:hover {
    text-decoration: underline;
}

.container p {
    color: #888;
    font-size: 14px;
    margin-top: 10px;
}

.delete-link{
    margin-left:12px;
    color:#dc2626 !important;
}

.delete-link:hover{
    color:#b91c1c !important;
}

.btn-delete-all{
    background:#fee2e2 !important;
    color:#b91c1c !important;
    border:1px solid #fecaca !important;
}

.btn-delete-all:hover{
    background:#dc2626 !important;
    color:#fff !important;
    border-color:#dc2626 !important;
}

.notif-link{
    color:#222;
    text-decoration:none;
    display:block;
}

.notif-link:hover{
    color:var(--primary-dark);
}

.top-bar select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #fff;
    font-size: 14px;
    color: #333;
    outline: none;
    cursor: pointer;
    transition: 0.2s;
}

.top-bar select:hover {
    border-color: var(--primary-dark);
}

.top-bar select:focus {
    border-color: var(--primary-dark);
    box-shadow: 0 0 0 2px rgba(78,115,223,0.15);
}

.top-bar form {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
</style>
</head>

<body>

<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>
    <div class="main">

        <div class="container">

            <h2>🔔 Notifications</h2>

            <!-- FILTER -->
            <div class="top-bar">
                <form method="GET" class="top-bar">
                    <!-- STATUS -->
                    <select name="status" onchange="this.form.submit()">
                        <option value="all" <?= ($status=='all')?'selected':'' ?>>All Status</option>
                        <option value="unread" <?= ($status=='unread')?'selected':'' ?>>Unread</option>
                        <option value="read" <?= ($status=='read')?'selected':'' ?>>Read</option>
                    </select>

                    <!-- TYPE -->
                    <select name="type" onchange="this.form.submit()">
                        <option value="all" <?= ($type=='all')?'selected':'' ?>>All Type</option>
                        <option value="Order" <?= ($type=='Order')?'selected':'' ?>>Order</option>
                        <option value="Refund" <?= ($type=='Refund')?'selected':'' ?>>Refund</option>
                        <option value="Review" <?= ($type=='Review')?'selected':'' ?>>Review</option>
                        <option value="Custom Request" <?= ($type=='Custom Request')?'selected':'' ?>>Custom</option>
                    </select>

                    <a class="btn" href="?read_all=1">Mark All as Read</a>

                    <a class="btn btn-delete-all" href="?delete_all=1"
                       onclick="return confirm('Are you sure you want to delete ALL notifications?')">
                       Delete All
                    </a>
                </form>
            </div>

            <!-- LIST -->
            <?php if ($result->num_rows > 0): ?>
                <?php while ($row = $result->fetch_assoc()): ?>

                    <div class="notif-card <?= $row['IS_READ'] ? '' : 'unread' ?>">

                        <!-- MESSAGE -->
                        <div>
                            <a class="notif-link" href="?open_id=<?= $row['NOTIF_ID'] ?>">
                                <?= htmlspecialchars($row['MESSAGE']) ?>
                            </a>
                        </div>

                        <!-- META -->
                        <div class="meta">
                            Type: <?= $row['TYPE'] ?> |
                            <?= $row['CREATED_AT'] ?>
                        </div>

                        <!-- ACTION -->
                        <div class="action">

                            <?php if (!$row['IS_READ']): ?>
                                <a href="?read_id=<?= $row['NOTIF_ID'] ?>">
                                    Mark as read
                                </a>
                            <?php endif; ?>

                            <a class="delete-link" href="?delete_id=<?= $row['NOTIF_ID'] ?>"
                               onclick="return confirm('Delete this notification?')">
                               Delete
                            </a>

                        </div>

                    </div>

                <?php endwhile; ?>

            <?php else: ?>
                <p>No notifications found.</p>
            <?php endif; ?>

        </div>

    </div>
</div>

<script>
</script>

</body>
</html>