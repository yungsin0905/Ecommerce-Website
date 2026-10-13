<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get customer id
$customerId = $_GET['customer_id'] ?? null;

if (!$customerId || !is_numeric($customerId)) {
    die("Invalid request");
}
$customerId = (int)$customerId;

// Get customer info
$stmt1 = $conn->prepare("
    SELECT c.CUSTOMER_ID, c.CUSTOMER_NAME, c.EMAIL, c.PHONE, c.STATUS, c.PROFILE_IMAGE,
       c.TOTAL_SPENT, c.WALLET_BALANCE, c.CREATED_AT,
       c.TIER_WINDOW_START, c.QUARTER_SPENT, c.LAST_TIER_VOUCHER_DATE,
       c.MILESTONE_WINDOW_START, c.MILESTONE_SPENT,
       t.TIER_NAME
    FROM customer c
    LEFT JOIN membership_tier t ON c.TIER_ID = t.TIER_ID
    WHERE c.CUSTOMER_ID = ?
");
$stmt1->bind_param("i", $customerId);
$stmt1->execute();
$customer = $stmt1->get_result()->fetch_assoc();

if (!$customer) {
    die("Customer not found");
}

// ===== Membership cycle info =====
$MILESTONE_TARGET = 1000; // 要和 process_payment.php 的 1000 保持一致

// 等级到期日 = TIER_WINDOW_START + 3 个月
$tierExpiryText = 'N/A';
$tierExpiryNote = '';
$tierExpiryColor = '#6b7280';
if (!empty($customer['TIER_WINDOW_START'])) {
    $exp = new DateTime($customer['TIER_WINDOW_START']);
    $exp->modify('+3 months');
    $daysLeft = (int)(new DateTime('today'))->diff($exp)->format('%r%a');
    $tierExpiryText = $exp->format('d M Y');

    if ($daysLeft < 0) {
        $tierExpiryNote  = 'Pending review (customer has not logged in since expiry)';
        $tierExpiryColor = '#dc2626';
    } elseif ($daysLeft <= 7) {
        $tierExpiryNote  = $daysLeft . ' day(s) left';
        $tierExpiryColor = '#d97706';
    } else {
        $tierExpiryNote  = $daysLeft . ' days left';
    }
}

// 下次月度 voucher 日期 = LAST_TIER_VOUCHER_DATE + 1 个月
$nextVoucherText = 'N/A';
$lastVoucherText = 'Never';
if (!empty($customer['LAST_TIER_VOUCHER_DATE'])) {
    $lastV = new DateTime($customer['LAST_TIER_VOUCHER_DATE']);
    $lastVoucherText = $lastV->format('d M Y');
    $nextV = clone $lastV;
    $nextV->modify('+1 month');
    $nextVoucherText = $nextV->format('d M Y');
} else {
    $nextVoucherText = 'On next login';
}

// Milestone（RM1000 奖励）
$msSpent     = (float)$customer['MILESTONE_SPENT'];
$msRemaining = max(0, $MILESTONE_TARGET - $msSpent);
$msPercent   = min(100, ($msSpent / $MILESTONE_TARGET) * 100);

$msExpiryText = 'N/A';
if (!empty($customer['MILESTONE_WINDOW_START'])) {
    $mExp = new DateTime($customer['MILESTONE_WINDOW_START']);
    $mExp->modify('+3 months');
    $msExpiryText = $mExp->format('d M Y');
}

// Get address info
$stmt2 = $conn->prepare("SELECT * FROM address WHERE CUSTOMER_ID = ?");
$stmt2->bind_param("i", $customerId);
$stmt2->execute();
$addressResult = $stmt2->get_result();

// Get order history info
$stmt3 = $conn->prepare("SELECT * FROM orders WHERE CUSTOMER_ID = ? ORDER BY CREATED_AT DESC");
$stmt3->bind_param("i", $customerId);
$stmt3->execute();
$orderResult = $stmt3->get_result();

$orders = [];
while ($row = $orderResult->fetch_assoc()) {
    $orders[] = $row;
}

// Get reviews info
$stmt4 = $conn->prepare("
    SELECT 
        r.REVIEW_ID,
        r.RATING,
        r.COMMENTS,
        r.CREATED_AT,
        r.REVIEW_STATUS,
        o.ORDER_NO,
        o.ORDER_ID,
        oi.PRODUCT_NAME_SNAPSHOT
    FROM review r
    LEFT JOIN orders o 
        ON r.ORDER_ID = o.ORDER_ID
    LEFT JOIN order_item oi 
        ON oi.ORDER_ID = r.ORDER_ID 
       AND oi.PRODUCT_ID = r.PRODUCT_ID
    WHERE r.CUSTOMER_ID = ?
    ORDER BY r.CREATED_AT DESC
");
$stmt4->bind_param("i", $customerId);
$stmt4->execute();
$reviewResult = $stmt4->get_result();

$reviews = [];
while ($row = $reviewResult->fetch_assoc()) {
    $reviews[] = $row;
}

// Get wallet transaction info
$stmtW = $conn->prepare("SELECT * FROM wallet_transaction WHERE CUSTOMER_ID = ? ORDER BY CREATED_AT DESC");
$stmtW->bind_param("i", $customerId);
$stmtW->execute();
$walletResult = $stmtW->get_result();

$wallets = [];
while ($row = $walletResult->fetch_assoc()) {
    $wallets[] = $row;
}

// Get voucher used info
$stmtV = $conn->prepare("
    SELECT cv.*, 
    cv.EXPIRY_DATE AS CUSTOMER_VOUCHER_EXPIRY,
    v.VOUCHER_NAME, v.VOUCHER_CODE, v.DISCOUNT_RATE, v.DISCOUNT_TYPE, v.VOUCHER_ID
    FROM customer_voucher cv
    JOIN voucher v ON cv.VOUCHER_ID = v.VOUCHER_ID
    WHERE cv.CUSTOMER_ID = ?
    ORDER BY cv.CLAIMED_AT DESC
");
$stmtV->bind_param("i", $customerId);
$stmtV->execute();
$voucherResult = $stmtV->get_result();

$vouchers = [];
while ($row = $voucherResult->fetch_assoc()) {
    $vouchers[] = $row;
}

// AVATAR INITIALS HELPER
function getInitials($name) {
    $parts = explode(' ', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: '?';
}

$tName = $customer['TIER_NAME'] ?? 'None';
$hue = hexdec(substr(md5($tName), 0, 7)) % 360; 
$tBg = "hsl({$hue}, 70%, 92%)";
$tTxt = "hsl({$hue}, 70%, 25%)";
$tBorder = "hsl({$hue}, 50%, 80%)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Details | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
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

.view-wrapper {
    max-width: 1040px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

/* Back Link */
.form-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    margin-bottom: 20px;
}

.form-back-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateX(-2px);
    text-decoration: none;
}

/* Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
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

/* Card */
.view-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-soft);
    padding: 24px;
    margin-bottom: 20px;
}

.view-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-subtle);
}

.view-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

/* TOP PROFILE & PERSONAL INFO GRID */
.top-profile-grid {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 800px) {
    .top-profile-grid {
        grid-template-columns: 1fr;
    }
}

.profile-card-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 8px;
}

.avatar {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: 800;
    overflow: hidden;
    margin-bottom: 4px;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.customer-name-heading {
    font-family: var(--font-heading);
    font-size: 17px;
    font-weight: 800;
    color: var(--text-dark);
    margin: 0;
}

.customer-email-sub {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0;
}

.badge-row {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: center;
    margin: 4px 0 8px;
}

.profile-divider {
    width: 100%;
    height: 1px;
    background: var(--border-subtle);
    margin: 8px 0;
}

.profile-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    width: 100%;
}

.stat-box {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 12px 14px;
    text-align: left;
}

.stat-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin-bottom: 4px;
}

.stat-val {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 800;
    color: var(--text-dark);
}

.register-date-note {
    font-size: 11.5px;
    color: var(--text-muted);
    margin-top: 6px;
}

/* Personal Info Grid */
.view-grid-2col {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
}

@media (max-width: 600px) {
    .view-grid-2col {
        grid-template-columns: 1fr;
    }
}

.view-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.view-label {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.view-val {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
}

/* Membership Cycle Grid */
.cycle-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}

@media (max-width: 768px) {
    .cycle-grid {
        grid-template-columns: 1fr;
    }
}

.progress-bar-wrap {
    width: 100%;
    height: 10px;
    background: var(--border-card);
    border-radius: var(--radius-pill);
    overflow: hidden;
    margin: 8px 0 6px;
}

.progress-fill {
    height: 100%;
    background: #18181b;
    border-radius: var(--radius-pill);
    transition: width 0.4s ease;
}

/* Address Cards Grid */
.addr-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}

@media (max-width: 650px) {
    .addr-grid {
        grid-template-columns: 1fr;
    }
}

.addr-card {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.addr-card.is-default {
    border-color: #93c5fd;
    background: #eff6ff;
}

.addr-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text-dark);
}

.default-pill {
    background: #dcfce7;
    color: #15803d;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
}

.info-row {
    display: flex;
    gap: 8px;
    font-size: 13px;
}

.info-label {
    color: var(--text-muted);
    min-width: 70px;
    font-weight: 500;
}

.info-val {
    color: var(--text-dark);
    font-weight: 600;
}

/* Tables inside cards */
.table-wrap {
    border: 1px solid var(--border-card);
    border-radius: 14px;
    overflow: hidden;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
}

thead {
    background: #fafbfc;
}

th {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid var(--border-card);
    white-space: nowrap;
}

td {
    padding: 12px 14px;
    font-size: 13px;
    color: var(--text-dark);
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background: #fbfcfd;
}

.code-pill {
    font-family: monospace;
    font-weight: 700;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 12.5px;
    color: var(--text-dark);
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
}

.code-pill:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
}

.action-link-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 12px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    color: var(--text-dark);
    text-decoration: none;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s;
}

.action-link-btn:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
}

.toggle-btn {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-dark);
    cursor: pointer;
    padding: 5px 14px;
    transition: all 0.2s;
}

.toggle-btn:hover {
    background: var(--dark-pill);
    color: #ffffff;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
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

.rating-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 700;
    font-size: 12.5px;
    color: #b45309;
    background: #fef3c7;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
}

.rating-pill i {
    color: #f59e0b;
}
</style>
</head>

<body>
<div class="view-wrapper">

    <a href="manage_customer.php" class="form-back-link" title="Back To Customers Page">
        <i class="bi bi-arrow-left"></i> Back to Customers
    </a>

    <!-- Dash Header -->
    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-person-badge"></i>
                <span class="badge-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="dash-title">
                Customer Details
                <strong>#<?= htmlspecialchars($customer['CUSTOMER_ID']) ?> - <?= htmlspecialchars($customer['CUSTOMER_NAME']) ?></strong>
            </div>
        </div>

        <div class="dash-header-controls">
            <span class="badge status-<?= strtolower($customer['STATUS']) ?>" style="font-size: 13px; padding: 6px 16px;">
                <i class="bi bi-<?= strtolower($customer['STATUS']) === 'active' ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                <?= htmlspecialchars($customer['STATUS']) ?>
            </span>
        </div>
    </div>

    <!-- TOP: PROFILE + PERSONAL INFO -->
    <div class="top-profile-grid">

        <!-- Profile card -->
        <div class="view-card" style="margin-bottom:0">
            <div class="profile-card-inner">
                <div class="avatar">
                    <?php if (!empty($customer['PROFILE_IMAGE'])): ?>
                        <img src="../<?= htmlspecialchars($customer['PROFILE_IMAGE']) ?>" alt="Profile">
                    <?php else: ?>
                        <?= getInitials($customer['CUSTOMER_NAME']) ?>
                    <?php endif; ?>
                </div>

                <div class="customer-name-heading">
                    <?= htmlspecialchars($customer['CUSTOMER_NAME']) ?>
                </div>

                <div class="customer-email-sub">
                    <?= htmlspecialchars($customer['EMAIL']) ?>
                </div>

                <div class="badge-row">
                    <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 700;">
                        <i class="bi bi-award"></i> <?= htmlspecialchars($tName) ?>
                    </span>

                    <span class="badge status-<?= strtolower($customer['STATUS']) ?>">
                        <i class="bi bi-<?= strtolower($customer['STATUS']) === 'active' ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                        <?= htmlspecialchars($customer['STATUS']) ?>
                    </span>
                </div>

                <div class="profile-divider"></div>

                <div class="profile-stats-grid">
                    <div class="stat-box">
                        <div class="stat-label">Total Spent</div>
                        <div class="stat-val">RM <?= number_format((float)$customer['TOTAL_SPENT'], 2) ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Wallet Balance</div>
                        <div class="stat-val">RM <?= number_format((float)$customer['WALLET_BALANCE'], 2) ?></div>
                    </div>
                </div>

                <div class="register-date-note">
                    <i class="bi bi-calendar3"></i> Joined <?= date("d M Y, h:i A", strtotime($customer['CREATED_AT'])) ?>
                </div>
            </div>
        </div>

        <!-- Personal info -->
        <div class="view-card" style="margin-bottom:0">
            <div class="view-card-header">
                <h3 class="view-card-title"><i class="bi bi-person-lines-fill"></i> Personal Information</h3>
            </div>

            <div class="view-grid-2col">
                <div class="view-item">
                    <span class="view-label">Full Name</span>
                    <span class="view-val"><?= htmlspecialchars($customer['CUSTOMER_NAME']) ?></span>
                </div>

                <div class="view-item">
                    <span class="view-label">Email Address</span>
                    <span class="view-val"><?= htmlspecialchars($customer['EMAIL']) ?></span>
                </div>

                <div class="view-item">
                    <span class="view-label">Phone Number</span>
                    <span class="view-val"><?= !empty($customer['PHONE']) ? htmlspecialchars($customer['PHONE']) : '<span style="color:var(--text-light)">N/A</span>' ?></span>
                </div>

                <div class="view-item">
                    <span class="view-label">Membership Tier</span>
                    <div style="margin-top:2px;">
                        <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 700;">
                            <?= htmlspecialchars($tName) ?>
                        </span>
                    </div>
                </div>

                <div class="view-item">
                    <span class="view-label">Account Status</span>
                    <div style="margin-top:2px;">
                        <span class="badge status-<?= strtolower($customer['STATUS']) ?>">
                            <i class="bi bi-<?= strtolower($customer['STATUS']) === 'active' ? 'check-circle-fill' : 'slash-circle-fill' ?>"></i>
                            <?= htmlspecialchars($customer['STATUS']) ?>
                        </span>
                    </div>
                </div>

                <div class="view-item">
                    <span class="view-label">Registration Date</span>
                    <span class="view-val"><?= date("d M Y, h:i A", strtotime($customer['CREATED_AT'])) ?></span>
                </div>

                <div class="view-item">
                    <span class="view-label">Lifetime Spent</span>
                    <span class="view-val" style="color:#15803d; font-weight:800;">RM <?= number_format((float)$customer['TOTAL_SPENT'], 2) ?></span>
                </div>

                <div class="view-item">
                    <span class="view-label">Current Wallet Balance</span>
                    <span class="view-val" style="color:#0369a1; font-weight:800;">RM <?= number_format((float)$customer['WALLET_BALANCE'], 2) ?></span>
                </div>
            </div>
        </div>

    </div>

    <!-- MEMBERSHIP CYCLE -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-arrow-repeat"></i> Membership Tier & Cycle</h3>
        </div>

        <div class="cycle-grid">
            <div class="stat-box">
                <div class="stat-label">Current Tier</div>
                <div class="stat-val"><?= htmlspecialchars($tName) ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-label">Quarter Spent (Window)</div>
                <div class="stat-val">RM <?= number_format((float)$customer['QUARTER_SPENT'], 2) ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-label">Window Start</div>
                <div class="stat-val">
                    <?= !empty($customer['TIER_WINDOW_START']) ? date("d M Y", strtotime($customer['TIER_WINDOW_START'])) : 'N/A' ?>
                </div>
            </div>

            <div class="stat-box">
                <div class="stat-label">Tier Expiry (Review Date)</div>
                <div class="stat-val"><?= $tierExpiryText ?></div>
                <?php if ($tierExpiryNote): ?>
                    <div style="font-size:11.5px;font-weight:700;color:<?= $tierExpiryColor ?>;margin-top:2px;">
                        <?= htmlspecialchars($tierExpiryNote) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="stat-box">
                <div class="stat-label">Last Tier Voucher</div>
                <div class="stat-val"><?= $lastVoucherText ?></div>
            </div>

            <div class="stat-box">
                <div class="stat-label">Next Tier Voucher</div>
                <div class="stat-val"><?= $nextVoucherText ?></div>
            </div>
        </div>

        <!-- Milestone progress -->
        <div style="margin-top:20px; background:var(--bg-page); border:1px solid var(--border-card); border-radius:14px; padding:16px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px; flex-wrap:wrap; gap:8px;">
                <span style="font-weight:700;font-size:13.5px;color:var(--text-dark);">
                    <i class="bi bi-gift" style="color:#d97706;"></i> RM<?= number_format($MILESTONE_TARGET) ?> Milestone Reward (RM10 Voucher)
                </span>
                <span style="font-size:13px;font-weight:600;color:var(--text-muted);">
                    RM <?= number_format($msSpent, 2) ?> / <?= number_format($MILESTONE_TARGET) ?> &middot; 
                    <strong style="color:#15803d;">RM <?= number_format($msRemaining, 2) ?> left</strong>
                </span>
            </div>
            <div class="progress-bar-wrap">
                <div class="progress-fill" style="width:<?= $msPercent ?>%"></div>
            </div>
            <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px;">
                <i class="bi bi-clock-history"></i> Milestone window ends: <?= $msExpiryText ?>
            </div>
        </div>
    </div>

    <!-- ADDRESS INFO -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-geo-alt"></i> Shipping & Address Information</h3>
        </div>

        <?php if ($addressResult->num_rows > 0): ?>
            <div class="addr-grid">
            <?php $index = 1; while ($addr = $addressResult->fetch_assoc()): ?>
                <div class="addr-card <?= $addr['IS_DEFAULT'] == 1 ? 'is-default' : '' ?>">
                    <div class="addr-title">
                        <span>Address <?= $index ?></span>
                        <?php if ($addr['IS_DEFAULT'] == 1): ?>
                            <span class="default-pill"><i class="bi bi-check2"></i> Default</span>
                        <?php endif; ?>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Name:</span>
                        <span class="info-val"><?= htmlspecialchars($addr['FIRST_NAME'] . ' ' . $addr['LAST_NAME']) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Phone:</span>
                        <span class="info-val"><?= htmlspecialchars($addr['PHONE']) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Address:</span>
                        <span class="info-val">
                            <?= htmlspecialchars($addr['ADDRESS_LINE']) ?>,
                            <?= htmlspecialchars($addr['CITY']) ?>,
                            <?= htmlspecialchars($addr['POSTCODE']) ?>,
                            <?= htmlspecialchars($addr['STATE']) ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Company:</span>
                        <span class="info-val"><?= !empty($addr['COMPANY']) ? htmlspecialchars($addr['COMPANY']) : '<span style="color:var(--text-light)">N/A</span>' ?></span>
                    </div>
                </div>
            <?php $index++; endwhile; ?>
            </div>
        <?php else: ?>
            <p style="color:var(--text-muted);font-size:13.5px;margin:0">No shipping addresses registered for this customer.</p>
        <?php endif; ?>
    </div>

    <!-- ORDER HISTORY -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-bag-check"></i> Order History (<?= count($orders) ?>)</h3>
            <?php if (count($orders) > 3): ?>
                <button type="button" class="toggle-btn" id="toggleOrdersBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:140px">Order No</th>
                        <th style="width:120px">Date</th>
                        <th style="width:130px">Total Amount</th>
                        <th style="width:120px">Status</th>
                        <th style="width:90px">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($orders) > 0): ?>
                    <?php foreach ($orders as $i => $order): ?>
                        <tr class="order-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td>
                                <a href="view_order.php?order_id=<?= (int)$order['ORDER_ID'] ?>" class="code-pill">
                                    #<?= htmlspecialchars($order['ORDER_NO']) ?>
                                </a>
                            </td>
                            <td><?= date("d M Y", strtotime($order['CREATED_AT'])) ?></td>
                            <td style="font-weight:700;">RM <?= number_format((float)$order['TOTAL_AMOUNT'], 2) ?></td>
                            <td>
                                <span class="badge status-<?= strtolower($order['ORDER_STATUS']) ?>">
                                    <?= htmlspecialchars($order['ORDER_STATUS']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_order.php?order_id=<?= (int)$order['ORDER_ID'] ?>" class="action-link-btn">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- REVIEWS -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-chat-quote"></i> Rating &amp; Reviews History (<?= count($reviews) ?>)</h3>
            <?php if (count($reviews) > 3): ?>
                <button type="button" class="toggle-btn" id="toggleReviewsBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:140px">Order No</th>
                        <th style="width:180px">Product</th>
                        <th style="width:110px">Date</th>
                        <th style="width:80px">Rating</th>
                        <th>Comment</th>
                        <th style="width:90px">Status</th>
                        <th style="width:90px">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $i => $rev): ?>
                        <tr class="review-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td>
                                <a href="view_order.php?order_id=<?= (int)$rev['ORDER_ID'] ?>" class="code-pill">
                                    #<?= htmlspecialchars($rev['ORDER_NO']) ?>
                                </a>
                            </td>
                            <td style="font-weight:600;">
                                <?= htmlspecialchars($rev['PRODUCT_NAME_SNAPSHOT'] ?? 'N/A') ?>
                            </td>
                            <td><?= date("d M Y", strtotime($rev['CREATED_AT'])) ?></td>
                            <td>
                                <div class="rating-pill">
                                    <i class="bi bi-star-fill"></i> <?= htmlspecialchars($rev['RATING']) ?>.0
                                </div>
                            </td>
                            <td style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($rev['COMMENTS'] ?? '', ENT_QUOTES) ?>">
                                <?= !empty(trim($rev['COMMENTS'] ?? '')) ? htmlspecialchars($rev['COMMENTS']) : '<span style="color:var(--text-light); font-style:italic;">No comment</span>' ?>
                            </td>
                            <td>
                                <span class="badge status-<?= strtolower($rev['REVIEW_STATUS']) ?>">
                                    <?= htmlspecialchars($rev['REVIEW_STATUS']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="view_review.php?review_id=<?= (int)$rev['REVIEW_ID'] ?>" class="action-link-btn">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No reviews found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- WALLET TRANSACTIONS -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-wallet2"></i> Wallet Transactions History (<?= count($wallets) ?>)</h3>
            <?php if (count($wallets) > 3): ?>
                <button type="button" class="toggle-btn" id="toggleWalletsBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:100px">Type</th>
                        <th style="width:140px">Method</th>
                        <th style="width:110px">Amount</th>
                        <th style="width:120px">Before</th>
                        <th style="width:120px">After</th>
                        <th style="width:120px">Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($wallets) > 0): ?>
                    <?php foreach ($wallets as $i => $wallet): ?>
                        <tr class="wallet-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td>
                                <span class="badge" style="background:var(--bg-page); color:var(--text-dark); border:1px solid var(--border-card);">
                                    <?= htmlspecialchars($wallet['TYPE']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($wallet['TOPUP_METHODS'] ?? 'N/A') ?></td>
                            <td style="font-weight:700; color:#15803d;">RM <?= number_format((float)$wallet['AMOUNT'], 2) ?></td>
                            <td>RM <?= number_format((float)$wallet['BEFORE_BALANCE'], 2) ?></td>
                            <td style="font-weight:600;">RM <?= number_format((float)$wallet['AFTER_BALANCE'], 2) ?></td>
                            <td><?= date("d M Y", strtotime($wallet['CREATED_AT'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-muted);">No wallet transactions recorded.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- VOUCHERS -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-ticket-perforated"></i> Voucher Claim &amp; Usage History (<?= count($vouchers) ?>)</h3>
            <?php if (count($vouchers) > 3): ?>
                <button type="button" class="toggle-btn" id="toggleVouchersBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:150px">Voucher Name</th>
                        <th style="width:140px">Code</th>
                        <th style="width:100px">Discount</th>
                        <th style="width:100px">Used Count</th>
                        <th style="width:110px">Last Used</th>
                        <th style="width:120px">Expiry</th>
                        <th style="width:120px">Claimed At</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($vouchers) > 0): ?>
                    <?php foreach ($vouchers as $i => $voucher): ?>
                        <tr class="voucher-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td style="font-weight:600;"><?= htmlspecialchars($voucher['VOUCHER_NAME']) ?></td>
                            <td>
                                <a href="view_voucher.php?voucher_id=<?= (int)$voucher['VOUCHER_ID'] ?>" class="code-pill">
                                    #<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?>
                                </a>
                            </td>
                            <td style="font-weight:700; color:#b45309;">
                                <?= strtoupper($voucher['DISCOUNT_TYPE'] ?? '') === 'FIXED'
                                    ? 'RM ' . number_format((float)$voucher['DISCOUNT_RATE'], 2)
                                    : rtrim(rtrim(number_format((float)$voucher['DISCOUNT_RATE'], 2), '0'), '.') . '%' ?>
                            </td>
                            <td><?= (int)$voucher['USED_COUNT'] ?></td>
                            <td><?= $voucher['LAST_USED_AT'] ? date("d M Y", strtotime($voucher['LAST_USED_AT'])) : '<span style="color:var(--text-light)">N/A</span>' ?></td>
                            <td><?= $voucher['CUSTOMER_VOUCHER_EXPIRY'] ? date("d M Y", strtotime($voucher['CUSTOMER_VOUCHER_EXPIRY'])) : '<span style="color:var(--text-muted)">Claim-Based</span>' ?></td>
                            <td><?= date("d M Y", strtotime($voucher['CLAIMED_AT'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-muted);">No vouchers found for this customer.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// Toggle Show All / Show Less (list expansion control) - show 3
function makeToggle(btnId, rowClass) {
    const btn = document.getElementById(btnId);
    if (!btn) return;
    let expanded = false;
    btn.addEventListener('click', function () {
        expanded = !expanded;
        document.querySelectorAll('.' + rowClass).forEach(function (row, i) {
            if (i >= 3) row.style.display = expanded ? '' : 'none';
        });
        btn.textContent = expanded ? 'Show less' : 'Show all';
    });
}

makeToggle('toggleOrdersBtn',  'order-row');
makeToggle('toggleReviewsBtn', 'review-row');
makeToggle('toggleWalletsBtn', 'wallet-row');
makeToggle('toggleVouchersBtn','voucher-row');
</script>

</body>
</html>
