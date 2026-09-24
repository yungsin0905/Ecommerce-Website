<?php 
include 'include/config.php';
require_once 'include/membership_functions.php';
session_start();

if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);

// Lazily process due membership/voucher checks first (may generate new notifications)
check_and_process_membership($conn, $customer_id);

// Fetch this customer's notifications
$sql = "SELECT n.NOTIF_ID, n.TYPE, n.REF_ID, n.MESSAGE, n.CREATED_AT, cn.IS_READ
        FROM customer_notification cn
        JOIN notification n ON cn.NOTIF_ID = n.NOTIF_ID
        WHERE cn.CUSTOMER_ID = ?
        ORDER BY n.CREATED_AT DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$totalUnread = 0;
foreach ($notifications as $n) {
    if (!$n['IS_READ']) $totalUnread++;
}

// Map TYPE -> icon class, link, and link label
function getNotifDisplay($type, $ref_id) {
    switch ($type) {
        case 'Order':
        case 'Delivery':
            return [
                'icon_class' => 'notif-icon-order',
                'icon'       => $type === 'Delivery' ? 'bi-truck' : 'bi-check-circle',
                'link'       => 'order_history.php',
                'link_label' => 'View Order',
                'link_icon'  => 'bi-eye'
            ];
        case 'Voucher':
            return [
                'icon_class' => 'notif-icon-voucher',
                'icon'       => 'bi-ticket-perforated',
                'link'       => 'voucher.php',
                'link_label' => 'View Vouchers',
                'link_icon'  => 'bi-ticket-detailed'
            ];
        case 'Membership':
            return [
                'icon_class' => 'notif-icon-member',
                'icon'       => 'bi-person-badge',
                'link'       => 'membership.php',
                'link_label' => 'Check Membership',
                'link_icon'  => 'bi-star'
            ];
        default:
            return [
                'icon_class' => 'notif-icon-system',
                'icon'       => 'bi-heart',
                'link'       => 'index.php',
                'link_label' => 'Explore',
                'link_icon'  => 'bi-shop'
            ];
    }
}

// Human-readable "time ago" formatting
function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff / 60) . " mins ago";
    if ($diff < 86400) return floor($diff / 3600) . " hour" . (floor($diff / 3600) > 1 ? "s" : "") . " ago";
    if ($diff < 604800) return floor($diff / 86400) . " day" . (floor($diff / 86400) > 1 ? "s" : "") . " ago";
    return date('d M Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    
    <!-- Bootstrap CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/header.css?v=8.0">
    <link rel="stylesheet" href="css/footer.css">
    
    <!-- Custom Page Styles -->
    <style>
        :root {
            --main-color: #80b8d2;
            --secondary-color: #3c8cb1;
            --font-color: #1B2A3C;
            --font2-color: #52708A;
            --bg-light: #F8FAFC;
        }

        body {
            background-color: white
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: var(--font-color);
        }

        .notification-page {
            max-width: 1000px;
            margin: 40px auto 60px auto;
            padding: 0 20px;
        }

        .page-header-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: 0 4px 16px rgba(27, 42, 60, 0.05);
            margin-bottom: 24px;
            border: 1px solid rgba(201, 220, 238, 0.5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .page-header-title {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-header-icon {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            background: rgba(128, 184, 210, 0.15);
            color: var(--secondary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .page-header-title h2 {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            color: var(--font-color);
        }

        .page-header-title p {
            margin: 4px 0 0 0;
            font-size: 14px;
            color: var(--font2-color);
        }

        /* Action Toolbar */
        .toolbar-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px 24px;
            box-shadow: 0 4px 16px rgba(27, 42, 60, 0.04);
            margin-bottom: 20px;
            border: 1px solid rgba(201, 220, 238, 0.5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .select-all-container {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            user-select: none;
            color: var(--font-color);
        }

        .select-all-container input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--main-color);
            cursor: pointer;
        }

        .btn-action {
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .btn-mark-read {
            background-color: #e8f4fa;
            color: var(--secondary-color);
            border: 1px solid #c8e2f0;
        }

        .btn-mark-read:hover {
            background-color: var(--secondary-color);
            color: #ffffff;
            border-color: var(--secondary-color);
        }

        .btn-delete-selected {
            background-color: #fdeded;
            color: #d32f2f;
            border: 1px solid #f9c7c7;
        }

        .btn-delete-selected:hover {
            background-color: #d32f2f;
            color: #ffffff;
            border-color: #d32f2f;
        }

        .filter-pills {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn {
            background: transparent;
            border: none;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--font2-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-btn:hover {
            color: var(--secondary-color);
            background-color: rgba(128, 184, 210, 0.1);
        }

        .filter-btn.active {
            background-color: var(--main-color);
            color: #ffffff;
        }

        /* Notification List */
        .notification-list-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(27, 42, 60, 0.05);
            border: 1px solid rgba(201, 220, 238, 0.5);
            overflow: hidden;
        }

        .notif-row {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 20px 24px;
            border-bottom: 1px solid #edf2f7;
            transition: background-color 0.2s ease, opacity 0.3s ease, transform 0.3s ease;
            position: relative;
        }

        .notif-row:last-child {
            border-bottom: none;
        }

        .notif-row.unread {
            background-color: #f4f9fd;
        }

        .notif-row.unread::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: var(--main-color);
        }

        .notif-row:hover {
            background-color: #f8fafc;
        }

        .notif-checkbox {
            margin-top: 10px;
        }

        .notif-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--main-color);
            cursor: pointer;
        }

        .notif-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .notif-icon-order {
            background-color: #e3f2fd;
            color: #1e88e5;
        }

        .notif-icon-voucher {
            background-color: #fff3e0;
            color: #fb8c00;
        }

        .notif-icon-member {
            background-color: #e8f5e9;
            color: #43a047;
        }

        .notif-icon-system {
            background-color: #f3e5f5;
            color: #8e24aa;
        }

        .notif-body {
            flex: 1;
        }

        .notif-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 6px;
        }

        .notif-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
            color: var(--font-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .unread-dot {
            width: 8px;
            height: 8px;
            background-color: #ff5252;
            border-radius: 50%;
            display: inline-block;
        }

        .notif-time {
            font-size: 12px;
            color: #9db4c7;
            font-weight: 500;
        }

        .notif-text {
            font-size: 14px;
            color: var(--font2-color);
            margin: 0 0 10px 0;
            line-height: 1.5;
        }

        .notif-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .notif-btn-link {
            font-size: 12px;
            font-weight: 600;
            color: var(--secondary-color);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .notif-btn-link:hover {
            text-decoration: underline;
            color: var(--main-color);
        }

        .notif-btn-delete {
            color: #e53935;
        }

        .notif-btn-delete:hover {
            color: #b71c1c;
        }

        /* Empty State */
        .empty-state {
            padding: 60px 20px;
            text-align: center;
            display: none;
        }

        .empty-state-icon {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 16px;
        }

        .empty-state h4 {
            font-size: 18px;
            font-weight: 700;
            color: var(--font-color);
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 14px;
            color: var(--font2-color);
            margin: 0;
        }

        /* Toast feedback */
        .toast-notification {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background-color: var(--font-color);
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
            font-size: 14px;
            font-weight: 500;
            z-index: 2000;
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .toast-notification.show {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <!-- Header Include -->
    <?php include 'include/header.php'; ?>

    <div class="notification-page">

        <!-- Page Header -->
        <div class="page-header-card">
            <div class="page-header-title">
                <div class="page-header-icon">
                    <i class="bi bi-bell-fill"></i>
                </div>
                <div>
                    <h2>Notifications</h2>
                    <p>Stay updated with your latest orders, vouchers, and membership rewards.</p>
                </div>
            </div>
            <div>
                <span class="badge rounded-pill bg-primary px-3 py-2 fs-6" id="totalUnreadBadge"><?= $totalUnread ?> Unread</span>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="toolbar-card">
            <div class="toolbar-left">
                <label class="select-all-container">
                    <input type="checkbox" id="selectAllCheckbox">
                    <span>Select All</span>
                </label>
                <button type="button" class="btn btn-action btn-mark-read" id="btnMarkRead">
                    <i class="bi bi-check2-all"></i> Mark as Read
                </button>
                <button type="button" class="btn btn-action btn-delete-selected" id="btnDeleteSelected">
                    <i class="bi bi-trash"></i> Delete Selected
                </button>
            </div>
            
            <div class="filter-pills">
                <button type="button" class="filter-btn active" data-filter="all">All (<span id="countAll"><?= count($notifications) ?></span>)</button>
<button type="button" class="filter-btn" data-filter="unread">Unread (<span id="countUnread"><?= $totalUnread ?></span>)</button>
            </div>
        </div>

        <!-- Notification List -->
        <div class="notification-list-card" id="notifListCard">

            <!-- Item -->
            <?php if (empty($notifications)): ?>
                <div class="empty-state" id="emptyState" style="display: block;">
                    <div class="empty-state-icon"><i class="bi bi-bell-slash"></i></div>
                    <h4>No Notifications Found</h4>
                    <p>You're all caught up! Check back later for new updates.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): 
                    $display = getNotifDisplay($n['TYPE'], $n['REF_ID']);
                    $status  = $n['IS_READ'] ? 'read' : 'unread';
                ?>
                <div class="notif-row <?= $status ?>" data-id="<?= $n['NOTIF_ID'] ?>" data-status="<?= $status ?>">
                    <div class="notif-checkbox">
                        <input type="checkbox" class="item-checkbox" value="<?= $n['NOTIF_ID'] ?>">
                    </div>
                    <div class="notif-icon-wrap <?= $display['icon_class'] ?>">
                        <i class="bi <?= $display['icon'] ?>"></i>
                    </div>
                    <div class="notif-body">
                        <div class="notif-head">
                            <h5 class="notif-title">
                                <?= htmlspecialchars($n['TYPE']) ?>
                                <?php if (!$n['IS_READ']): ?><span class="unread-dot"></span><?php endif; ?>
                            </h5>
                            <span class="notif-time"><?= time_ago($n['CREATED_AT']) ?></span>
                        </div>
                        <p class="notif-text"><?= htmlspecialchars($n['MESSAGE']) ?></p>
                        <div class="notif-actions">
                            <a href="<?= $display['link'] ?>" class="notif-btn-link"><i class="bi <?= $display['link_icon'] ?>"></i> <?= $display['link_label'] ?></a>
                            <?php if (!$n['IS_READ']): ?>
                                <button type="button" class="notif-btn-link btn-single-read"><i class="bi bi-check2"></i> Mark as Read</button>
                            <?php endif; ?>
                            <button type="button" class="notif-btn-link notif-btn-delete btn-single-delete"><i class="bi bi-trash"></i> Delete</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="empty-state" id="emptyState"></div>
            <?php endif; ?>

            <!-- Empty State -->
            <div class="empty-state" id="emptyState">
                <div class="empty-state-icon">
                    <i class="bi bi-bell-slash"></i>
                </div>
                <h4>No Notifications Found</h4>
                <p>You're all caught up! Check back later for new updates.</p>
            </div>

        </div>

    </div>

    <!-- Feedback Toast -->
    <div class="toast-notification" id="toastNotif">
        <i class="bi bi-info-circle-fill text-info"></i>
        <span id="toastMsg">Action completed successfully.</span>
    </div>

    <!-- Footer Include -->
    <?php include 'include/footer.php'; ?>

    <!-- Interactive JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectAllCheckbox = document.getElementById('selectAllCheckbox');
            const itemCheckboxes = document.querySelectorAll('.item-checkbox');
            const btnMarkRead = document.getElementById('btnMarkRead');
            const btnDeleteSelected = document.getElementById('btnDeleteSelected');
            const filterBtns = document.querySelectorAll('.filter-btn');
            const toastNotif = document.getElementById('toastNotif');
            const toastMsg = document.getElementById('toastMsg');
            const emptyState = document.getElementById('emptyState');

            function showToast(message) {
                toastMsg.innerText = message;
                toastNotif.classList.add('show');
                setTimeout(() => {
                    toastNotif.classList.remove('show');
                }, 2500);
            }

            function syncHeaderBellBadge(unreadCount) {
                const bellBadge = document.querySelector('.notification-badge');
                const markAllRead = document.querySelector('.mark-all-read');

                if (unreadCount > 0) {
                    if (bellBadge) {
                        bellBadge.textContent = unreadCount;
                    } else {
                        const bellBtn = document.getElementById('bellToggleBtn');
                        if (bellBtn) {
                            const span = document.createElement('span');
                            span.className = 'notification-badge';
                            span.textContent = unreadCount;
                            bellBtn.appendChild(span);
                        }
                    }
                    if (markAllRead) markAllRead.textContent = unreadCount + ' New';
                } else {
                    if (bellBadge) bellBadge.remove();
                    if (markAllRead) markAllRead.remove();
                }
            }

            function updateCounts() {
                const allRows = document.querySelectorAll('.notif-row');
                const totalItems = allRows.length;
                const totalUnread = document.querySelectorAll('.notif-row.unread').length;
                
                document.getElementById('countAll').innerText = totalItems;
                document.getElementById('countUnread').innerText = totalUnread;
                document.getElementById('totalUnreadBadge').innerText = totalUnread + ' Unread';

                // Empty state should reflect the currently active filter, not just total count
                const activeFilter = document.querySelector('.filter-btn.active')?.getAttribute('data-filter') || 'all';
                const visibleCount = document.querySelectorAll('.notif-row:not([style*="display: none"])').length;

                if (visibleCount === 0) {
                    emptyState.style.display = 'block';
                } else {
                    emptyState.style.display = 'none';
                }

                syncHeaderBellBadge(totalUnread);
            }

            // Select All Checkbox
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function () {
                    const isChecked = this.checked;
                    document.querySelectorAll('.notif-row:not([style*="display: none"]) .item-checkbox').forEach(cb => {
                        cb.checked = isChecked;
                    });
                });
            }

            // Mark Selected as Read
           if (btnMarkRead) {
                btnMarkRead.addEventListener('click', function () {
                    const checkedItems = document.querySelectorAll('.item-checkbox:checked');
                    if (checkedItems.length === 0) {
                        showToast('Please select at least one notification.');
                        return;
                    }

                    const ids = Array.from(checkedItems).map(cb => cb.value);

                    fetch('notification_action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&' + ids.map(id => 'ids[]=' + encodeURIComponent(id)).join('&')
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            showToast('Something went wrong. Please try again.');
                            return;
                        }
                        checkedItems.forEach(cb => {
                            const row = cb.closest('.notif-row');
                            if (row && row.classList.contains('unread')) {
                                row.classList.remove('unread');
                                row.classList.add('read');
                                row.setAttribute('data-status', 'read');
                                const dot = row.querySelector('.unread-dot');
                                if (dot) dot.remove();
                                const singleReadBtn = row.querySelector('.btn-single-read');
                                if (singleReadBtn) singleReadBtn.remove();
                            }
                            cb.checked = false;
                        });
                        if (selectAllCheckbox) selectAllCheckbox.checked = false;
                        updateCounts();
                        showToast('Selected notifications marked as read.');
                    });
                });
            }

            // Delete Selected
            if (btnDeleteSelected) {
                btnDeleteSelected.addEventListener('click', function () {
                    const checkedItems = document.querySelectorAll('.item-checkbox:checked');
                    if (checkedItems.length === 0) {
                        showToast('Please select at least one notification.');
                        return;
                    }

                    if (!confirm('Are you sure you want to delete selected notifications?')) return;

                    const ids = Array.from(checkedItems).map(cb => cb.value);

                    fetch('notification_action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=delete&' + ids.map(id => 'ids[]=' + encodeURIComponent(id)).join('&')
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            showToast('Something went wrong. Please try again.');
                            return;
                        }
                        checkedItems.forEach(cb => {
                            const row = cb.closest('.notif-row');
                            if (row) {
                                row.style.opacity = '0';
                                row.style.transform = 'translateX(20px)';
                                setTimeout(() => {
                                    row.remove();
                                    updateCounts();
                                }, 300);
                            }
                        });
                        if (selectAllCheckbox) selectAllCheckbox.checked = false;
                        showToast('Selected notifications deleted.');
                    });
                });
            }

            // Single Read Button
            document.querySelectorAll('.btn-single-read').forEach(btn => {
                btn.addEventListener('click', function () {
                    const row = this.closest('.notif-row');
                    const id = row.getAttribute('data-id');

                    fetch('notification_action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=mark_read&ids[]=' + encodeURIComponent(id)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            showToast('Something went wrong. Please try again.');
                            return;
                        }
                        row.classList.remove('unread');
                        row.classList.add('read');
                        row.setAttribute('data-status', 'read');
                        const dot = row.querySelector('.unread-dot');
                        if (dot) dot.remove();
                        this.remove();
                        updateCounts();
                        showToast('Marked as read.');
                    });
                });
            });

            // Single Delete Button
            document.querySelectorAll('.btn-single-delete').forEach(btn => {
                btn.addEventListener('click', function () {
                    const row = this.closest('.notif-row');
                    const id = row.getAttribute('data-id');

                    fetch('notification_action.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'action=delete&ids[]=' + encodeURIComponent(id)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (!data.success) {
                            showToast('Something went wrong. Please try again.');
                            return;
                        }
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(20px)';
                        setTimeout(() => {
                            row.remove();
                            updateCounts();
                            showToast('Notification deleted.');
                        }, 300);
                    });
                });
            });

            // Filter Tabs
            filterBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');

                    const filter = this.getAttribute('data-filter');
                    const rows = document.querySelectorAll('.notif-row');

                    rows.forEach(row => {
                        if (filter === 'all') {
                            row.style.display = 'flex';
                        } else if (filter === 'unread') {
                            if (row.classList.contains('unread')) {
                                row.style.display = 'flex';
                            } else {
                                row.style.display = 'none';
                            }
                        }
                    });

                    if (selectAllCheckbox) selectAllCheckbox.checked = false;
                    document.querySelectorAll('.item-checkbox').forEach(cb => cb.checked = false);
                    updateCounts();
                });
            });
        });
    </script>

</body>
</html>
