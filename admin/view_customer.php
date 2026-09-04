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

if (!$customerId) {
    die("Invalid request");
}

// Get customer info
$stmt1 = $conn->prepare("
    SELECT c.*, t.TIER_NAME
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
    v.VOUCHER_NAME, v.VOUCHER_CODE, v.DISCOUNT_RATE, v.VOUCHER_ID
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.title {
    margin-bottom: 15px;
}

/* CARD */
.card {
    background:#fff;
    border-radius:16px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 15px;
}

.card h3{
    margin:0 0 15px;
    font-size:18px;
    font-weight:700;
    color:#222;
}

/* TOP GRID */
.top-grid {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 16px;
    margin-bottom: 0;
}

/* PROFILE CARD */
.profile-center {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: 6px;
}

.avatar {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: #dbeafe;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 600;
    color: #1d4ed8;
    overflow: hidden;
    margin-top: 15px;
    margin-bottom: 5px;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.customer-name {
    font-weight: 600;
    color: #111827;
    margin-top: 4px;
}

.customer-email {
    font-size: 14px;
    color: #6b7280;
    margin-bottom: 4px;
}

.badge-row {
    display: flex;
    gap: 6px;
    margin-top: 2px;
}

.profile-divider {
    width: 100%;
    height: 1px;
    background: #f3f4f6;
    margin: 8px 0;
}

.profile-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px;
    width: 100%;
}

.stat-box {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 10px 12px;
    text-align: left;
}

.stat-label {
    font-size: 11px;
    color: #9ca3af;
    margin-bottom: 2px;
}

.stat-val {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
}

.register-date {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 6px;
}

/* INFO ROWS */
.info-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #f9fafb;
}

.info-row:last-child { 
    border-bottom: none; 
}

.info-label {
    font-size: 12px;
    color: #6b7280;
    min-width: 110px;
    flex-shrink: 0;
    padding-top: 1px;
}

.info-val {
    font-size: 13px;
    color: #111827;
    flex: 1;
}

/* ADDRESS */
.addr-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.addr-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px;
}

.addr-card.is-default {
    border-color: #93c5fd;
    background: #eff6ff;
}

.addr-title {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}

.default-pill {
    background: #dcfce7;
    color: #166534;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 999px;
}

/* SECTION HEADER */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}

.section-title {
    font-size: 15px;
    font-weight: 600;
    color: #111827;
}

.toggle-btn {
    background: none;
    border: none;
    font-size: 12px;
    font-weight: 600;
    color: #2563eb;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 6px;
    transition: background .15s;
}

.toggle-btn:hover { 
    background: #eff6ff; 
}

/* TABLE  */
.table-wrap {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

thead { 
    background: #f9fafb; 
}

th {
    font-size: 11px;
    font-weight: 600;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    white-space: nowrap;
}

td {
    padding: 10px 14px;
    font-size: 13px;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
    word-wrap: break-word;
    overflow-wrap: break-word;
}

tbody tr:last-child td { 
    border-bottom: none; 
}

tbody tr:hover { 
    background: #f9fafb; 
}

.empty-row td {
    text-align: center;
    color: #9ca3af;
    padding: 24px;
}

td a {
    display: inline-block;
    padding: 4px 10px;
    background: #eff6ff;
    color: #1d4ed8;
    text-decoration: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    transition: background .15s;
}

td a:hover { 
    background: #dbeafe; 
}

.links {
    all: unset;
    cursor: pointer;
    color: #0386a4;
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_customer.php" class="form-back-link" title="Back To Customer Page">← Back</a>

    <div class="title">Customer Details</div>

    <!-- TOP: PROFILE + PERSONAL INFO -->
    <div class="top-grid" style="margin-bottom:16px">

        <!-- Profile card -->
        <div class="card" style="margin-bottom:0">
            <div class="profile-center">

                <div class="avatar">
                    <?php if (!empty($customer['PROFILE_IMAGE'])): ?>
                        <img src="../<?= htmlspecialchars($customer['PROFILE_IMAGE']) ?>" alt="Profile">
                    <?php else: ?>
                        <?= getInitials($customer['CUSTOMER_NAME']) ?>
                    <?php endif; ?>
                </div>

                <div class="customer-name">
                    <?= htmlspecialchars($customer['CUSTOMER_NAME']) ?>
                </div>

                <div class="customer-email">
                    <?= htmlspecialchars($customer['EMAIL']) ?>
                </div>

                <div class="badge-row">
                    <?php 
                        $tName = $customer['TIER_NAME'] ?? 'None';
                        $hue = hexdec(substr(md5($tName), 0, 7)) % 360; 
                        $tBg = "hsl({$hue}, 70%, 85%)";
                        $tTxt = "hsl({$hue}, 70%, 25%)";
                        $tBorder = "hsl({$hue}, 50%, 75%)";
                    ?>
                    <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 600;">
                        <?= htmlspecialchars($tName) ?>
                    </span>

                    <span class="badge status-<?= strtolower($customer['STATUS']) ?>">
                        <?= htmlspecialchars($customer['STATUS']) ?>
                    </span>
                </div>

                <div class="profile-divider"></div>

                <div class="profile-stats">
                    <div class="stat-box">
                        <div class="stat-label">Total spent</div>
                        <div class="stat-val">RM <?= number_format($customer['TOTAL_SPENT'], 2) ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Wallet balance</div>
                        <div class="stat-val">RM <?= number_format($customer['WALLET_BALANCE'], 2) ?></div>
                    </div>
                </div>

                <div class="register-date">
                    Registered <?= date("d M Y, H:i", strtotime($customer['CREATED_AT'])) ?>
                </div>
            </div>
        </div>

        <!-- Personal info -->
        <div class="card" style="margin-bottom:0">
            <h3>Personal info</h3>

            <div class="row">
                <span class="label">Name</span>
                <span><?= htmlspecialchars($customer['CUSTOMER_NAME']) ?></span>
            </div>

            <div class="row">
                <span class="label">Email</span>
                <span><?= htmlspecialchars($customer['EMAIL']) ?></span>
            </div>

            <div class="row">
                <span class="label">Phone</span>
                <span><?= htmlspecialchars($customer['PHONE']) ?></span>
            </div>

            <div class="row">
                <span class="label">Membership tier</span>
                <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 600;">
                    <?= htmlspecialchars($tName) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Status</span>
                    <span class="badge status-<?= strtolower($customer['STATUS']) ?>">
                        <?= htmlspecialchars($customer['STATUS']) ?>
                    </span>
                </span>
            </div>

            <div class="row">
                <span class="label">Registered</span>
                <span><?= date("d M Y, H:i", strtotime($customer['CREATED_AT'])) ?></span>
            </div>

            <div class="row">
                <span class="label">Total spent</span>
                <span>RM <?= number_format($customer['TOTAL_SPENT'], 2) ?></span>
            </div>

            <div class="row">
                <span class="label">Wallet balance</span>
                <span>RM <?= number_format($customer['WALLET_BALANCE'], 2) ?></span>
            </div>
        </div>

    </div>

    <!-- ADDRESS INFO -->
    <div class="card">
        <h3>Shipping info</h3>

        <?php if ($addressResult->num_rows > 0): ?>
            <div class="addr-grid">
            <?php $index = 1; while ($addr = $addressResult->fetch_assoc()): ?>
                <div class="addr-card <?= $addr['IS_DEFAULT'] == 1 ? 'is-default' : '' ?>">
                    <div class="addr-title">
                        Address <?= $index ?>
                        <?php if ($addr['IS_DEFAULT'] == 1): ?>
                            <span class="default-pill">Default</span>
                        <?php endif; ?>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Name</span>
                        <span class="info-val"><?= htmlspecialchars($addr['FIRST_NAME'] . ' ' . $addr['LAST_NAME']) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Phone</span>
                        <span class="info-val"><?= htmlspecialchars($addr['PHONE']) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Address</span>
                        <span class="info-val">
                            <?= htmlspecialchars($addr['ADDRESS_LINE']) ?>,
                            <?= htmlspecialchars($addr['CITY']) ?>,
                            <?= htmlspecialchars($addr['POSTCODE']) ?>,
                            <?= htmlspecialchars($addr['STATE']) ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Company</span>
                        <span class="info-val"><?= !empty($addr['COMPANY']) ? htmlspecialchars($addr['COMPANY']) : 'N/A' ?></span>
                    </div>
                </div>
            <?php $index++; endwhile; ?>
            </div>
        <?php else: ?>
            <p style="color:#9ca3af;font-size:13px">No address found.</p>
        <?php endif; ?>
    </div>

    <!-- ORDER HISTORY -->
    <div class="card">
        <div class="section-header">
            <div class="section-title">Order history</div>
            <?php if (count($orders) > 3): ?>
                <button class="toggle-btn" id="toggleOrdersBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:130px">Order no</th>
                        <th style="width:110px">Date</th>
                        <th style="width:130px">Total amount</th>
                        <th style="width:120px">Status</th>
                        <th style="width:80px">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($orders) > 0): ?>
                    <?php foreach ($orders as $i => $order): ?>
                        <tr class="order-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td>
                                <a href="view_order.php?order_id=<?= $order['ORDER_ID'] ?>" class="links">
                                    #<?= htmlspecialchars($order['ORDER_NO']) ?>
                                </a>
                            </td>
                            <td><?= date("d M Y", strtotime($order['CREATED_AT'])) ?></td>
                            <td>RM <?= number_format($order['TOTAL_AMOUNT'], 2) ?></td>
                            <td>
                                <span class="badge status-<?= strtolower($order['ORDER_STATUS']) ?>">
                                    <?= htmlspecialchars($order['ORDER_STATUS']) ?>
                                </span>
                            </td>
                            <td><a href="view_order.php?order_id=<?= $order['ORDER_ID'] ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="5">No orders found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- REVIEWS -->
    <div class="card">
        <div class="section-header">
            <div class="section-title">Rating &amp; reviews history</div>
            <?php if (count($reviews) > 3): ?>
                <button class="toggle-btn" id="toggleReviewsBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:130px">Order no</th>
                        <th style="width:160px">Product</th>
                        <th style="width:100px">Date</th>
                        <th style="width:70px">Rating</th>
                        <th style="width:180px">Comment</th>
                        <th style="width:85px">Status</th>
                        <th style="width:70px">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($reviews) > 0): ?>
                    <?php foreach ($reviews as $i => $rev): ?>
                        <tr class="review-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>

                            <td>
                                <a href="view_order.php?order_id=<?= $rev['ORDER_ID'] ?>" class="links">
                                    #<?= htmlspecialchars($rev['ORDER_NO']) ?>
                                </a>
                            </td>

                            <td>
                                <?= htmlspecialchars($rev['PRODUCT_NAME_SNAPSHOT']) ?>
                            </td>

                            <td><?= date("d M Y", strtotime($rev['CREATED_AT'])) ?></td>

                            <td><?= htmlspecialchars($rev['RATING']) ?> ★</td>

                            <td><?= htmlspecialchars($rev['COMMENTS'] ?? 'N/A') ?></td>

                            <td>
                                <span class="badge status-<?= strtolower($rev['REVIEW_STATUS']) ?>">
                                    <?= htmlspecialchars($rev['REVIEW_STATUS']) ?>
                                </span>
                            </td>

                            <td><a href="view_review.php?review_id=<?= $rev['REVIEW_ID'] ?>">View</a></td>
                            
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="7">No reviews found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- WALLET TRANSACTIONS -->
    <div class="card">
        <div class="section-header">
            <div class="section-title">Wallet transactions history</div>
            <?php if (count($wallets) > 3): ?>
                <button class="toggle-btn" id="toggleWalletsBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:90px">Type</th>
                        <th style="width:130px">Method</th>
                        <th style="width:100px">Amount</th>
                        <th style="width:110px">Before</th>
                        <th style="width:110px">After</th>
                        <th style="width:110px">Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($wallets) > 0): ?>
                    <?php foreach ($wallets as $i => $wallet): ?>
                        <tr class="wallet-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td><?= htmlspecialchars($wallet['TYPE']) ?></td>
                            <td><?= htmlspecialchars($wallet['TOPUP_METHODS']) ?></td>
                            <td>RM <?= number_format($wallet['AMOUNT'], 2) ?></td>
                            <td>RM <?= number_format($wallet['BEFORE_BALANCE'], 2) ?></td>
                            <td>RM <?= number_format($wallet['AFTER_BALANCE'], 2) ?></td>
                            <td><?= date("d M Y", strtotime($wallet['CREATED_AT'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="6">No wallet transactions.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- VOUCHERS -->
    <div class="card">
        <div class="section-header">
            <div class="section-title">Voucher history</div>
            <?php if (count($vouchers) > 3): ?>
                <button class="toggle-btn" id="toggleVouchersBtn">Show all</button>
            <?php endif; ?>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:140px">Voucher name</th>
                        <th style="width:130px">Code</th>
                        <th style="width:100px">Discount</th>
                        <th style="width:100px">Used count</th>
                        <th style="width:100px">Last used</th>
                        <th style="width:120px">Expiry</th>
                        <th style="width:120px">Claimed at</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($vouchers) > 0): ?>
                    <?php foreach ($vouchers as $i => $voucher): ?>
                        <tr class="voucher-row"<?= $i >= 3 ? ' style="display:none"' : '' ?>>
                            <td><?= htmlspecialchars($voucher['VOUCHER_NAME']) ?></td>
                            <td>
                                <a href="view_voucher.php?voucher_id=<?= $voucher['VOUCHER_ID'] ?>" class="links">
                                    #<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?>
                                </a>
                            </td>
                            <td><?= $voucher['DISCOUNT_RATE'] ?>%</td>
                            <td><?= $voucher['USED_COUNT'] ?></td>
                            <td><?= $voucher['LAST_USED_AT'] ? date("d M Y", strtotime($voucher['LAST_USED_AT'])) : 'N/A' ?></td>
                            <td><?= $voucher['CUSTOMER_VOUCHER_EXPIRY'] ? date("d M Y", strtotime($voucher['CUSTOMER_VOUCHER_EXPIRY'])) : 'Claim-Based' ?></td>
                            <td><?= date("d M Y", strtotime($voucher['CLAIMED_AT'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="empty-row"><td colspan="7">No vouchers found.</td></tr>
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