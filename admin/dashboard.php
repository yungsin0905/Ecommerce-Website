<?php
require_once("config.php");
session_start();

$pageTitle = "Dashboard";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// 1. TOTAL REVENUE (only successful payment)
$sql = "SELECT SUM(PAYMENT_AMOUNT) AS totalRevenue 
        FROM payment 
        WHERE PAYMENT_STATUS = 'SUCCESS'";
$result = $conn->query($sql);
$totalRevenue = $result->fetch_assoc()['totalRevenue'] ?? 0;

// 2. TOTAL ORDERS
$sql = "SELECT COUNT(*) AS totalOrders FROM orders";
$result = $conn->query($sql);
$totalOrders = $result->fetch_assoc()['totalOrders'] ?? 0;

// 3. TOTAL CUSTOMERS
$sql = "SELECT COUNT(*) AS totalCustomers FROM customer";
$result = $conn->query($sql);
$totalCustomers = $result->fetch_assoc()['totalCustomers'] ?? 0;

// 4. TOTAL RATING
$sql = "SELECT AVG(RATING) AS avgRating FROM review";
$result = $conn->query($sql);
$avgRating = round($result->fetch_assoc()['avgRating'] ?? 0, 1);

// 5. STOCK ALERT (variant<=5, add-on<=5 == low stock; variant=0, add-on=0 == out of stock)
$sql = "
SELECT 
    SUM(CASE WHEN type = 'VARIANT' AND stock <= 5 AND stock > 0 THEN 1 ELSE 0 END) AS lowVariant,
    SUM(CASE WHEN type = 'VARIANT' AND stock = 0 THEN 1 ELSE 0 END) AS outVariant,
    SUM(CASE WHEN type = 'ADD_ON' AND stock <= 5 AND stock > 0 THEN 1 ELSE 0 END) AS lowAddon,
    SUM(CASE WHEN type = 'ADD_ON' AND stock = 0 THEN 1 ELSE 0 END) AS outAddon
FROM (
    SELECT VARIANT_STOCK AS stock, 'VARIANT' AS type FROM product_variant WHERE IS_DELETED = 0
    UNION ALL
    SELECT ADD_ON_STOCK AS stock, 'ADD_ON' AS type FROM add_on WHERE IS_DELETED = 0
) AS stock_table
";
$result    = $conn->query($sql);
$row       = $result->fetch_assoc();
$lowVariant = $row['lowVariant'] ?? 0;
$outVariant = $row['outVariant'] ?? 0;
$lowAddon   = $row['lowAddon']   ?? 0;
$outAddon   = $row['outAddon']   ?? 0;

// ORDER OVERVIEW (only count orders that are still processing or ready, completed & refunded order will not be counted in overview, as they are already done)
$today = date('Y-m-d');

// TODAY ORDERS — split by Normal / Custom
$sameDayNormal = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE DATE(DELIVERY_DATE) = '$today'
    AND ORDER_TYPE = 'Normal'
    AND ORDER_STATUS IN ('PROCESSING','READY')
")->fetch_assoc()['c'];

$sameDayCustom = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE DATE(DELIVERY_DATE) = '$today'
    AND ORDER_TYPE = 'Custom'
    AND ORDER_STATUS IN ('PROCESSING','READY')
")->fetch_assoc()['c'];

// FUTURE ORDERS — split by Normal / Custom
$preOrderNormal = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE DELIVERY_DATE > CURDATE()
    AND ORDER_TYPE = 'Normal'
    AND ORDER_STATUS IN ('PROCESSING','READY')
")->fetch_assoc()['c'];

$preOrderCustom = $conn->query("
    SELECT COUNT(*) AS c FROM orders
    WHERE DELIVERY_DATE > CURDATE()
    AND ORDER_TYPE = 'Custom'
    AND ORDER_STATUS IN ('PROCESSING','READY')
")->fetch_assoc()['c'];

// TODAY DELIVERY （orders with delivery date = today, status = ready, delivery status = pending）
$todayDelivery = $conn->query("
    SELECT COUNT(*) AS c FROM orders o
    JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE o.DELIVERY_DATE = CURDATE()
    AND o.ORDER_STATUS = 'READY'
    AND s.DELIVERY_STATUS = 'PENDING'
")->fetch_assoc()['c'] ?? 0;

// QUICK OVERVIEW counts
// Orders with status processing
$pendingOrders        = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE ORDER_STATUS='PROCESSING'")->fetch_assoc()['c'];
// Ready delivery where order status is ready, but delivery status still pending, means still waiting for delivery, so count in quick overview.
$pendingDelivery      = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE ORDER_STATUS='READY'")->fetch_assoc()['c'];
// Custom request with status pending
$pendingCustom        = $conn->query("SELECT COUNT(*) AS c FROM custom WHERE STATUS='Pending'")->fetch_assoc()['c'];
// Refund request with status pending
$pendingRefundRequest = $conn->query("SELECT COUNT(*) AS c FROM refund_request WHERE REQUEST_STATUS='PENDING'")->fetch_assoc()['c'];
// Refund that already approved by admin, but still waiting for finance team to process, so count in quick overview.
$pendingRefund        = $conn->query("
    SELECT COUNT(*) AS c FROM refund r
    JOIN refund_request rr ON r.REQUEST_ID = rr.REQUEST_ID
    WHERE r.REFUND_STATUS = 'PENDING'
    AND rr.REQUEST_STATUS = 'APPROVED'
")->fetch_assoc()['c'] ?? 0;

// LOW STOCK TABLE
$lowStock = $conn->query("
    SELECT v.VARIANT_ID AS id, p.PRODUCT_NAME AS name, v.VARIANT_SIZE AS size, v.VARIANT_STOCK AS stock, 'VARIANT' AS type
    FROM product_variant v
    JOIN product p ON p.PRODUCT_ID = v.PRODUCT_ID
    WHERE v.IS_DELETED = 0 AND v.VARIANT_STOCK <= 5 AND p.IS_DELETED = 0
    UNION
    SELECT ADD_ON_ID, ADD_ON_NAME, NULL, ADD_ON_STOCK, 'ADD_ON'
    FROM add_on WHERE IS_DELETED = 0 AND ADD_ON_STOCK <= 5
");

// QUICK ORDERS (Processing)
$quickOrders = $conn->query("
    SELECT ORDER_ID, ORDER_NO, ORDER_STATUS, TOTAL_AMOUNT
    FROM orders
    WHERE ORDER_STATUS = 'PROCESSING'
    ORDER BY CREATED_AT DESC
");

// QUICK DELIVERY (Pending)
$quickDelivery = $conn->query("
    SELECT o.ORDER_ID, o.ORDER_NO, o.DELIVERY_DATE, 
           o.DELIVERY_SLOT_SNAPSHOT, s.DELIVERY_STATUS
    FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'PENDING'
    AND o.ORDER_STATUS != 'REFUNDED'
    ORDER BY o.DELIVERY_DATE ASC
");

// REFUND REQUESTS (Pending)
$refundRequests = $conn->query("
    SELECT r.REQUEST_ID, r.REASON, r.REQUEST_STATUS, r.REQUEST_DATE, o.ORDER_NO
    FROM refund_request r
    JOIN orders o ON r.ORDER_ID = o.ORDER_ID
    WHERE r.REQUEST_STATUS = 'PENDING'
    ORDER BY r.REQUEST_DATE DESC
");

// CUSTOM REQUESTS (Pending)
$customRequests = $conn->query("
    SELECT RECIPIENT_NAME, STYLE_NAME_SNAPSHOT, BUDGET, STATUS, CUSTOM_ID
    FROM custom
    WHERE STATUS = 'Pending'
    ORDER BY CREATED_AT DESC
");

// VOUCHERS (Currently Active Period)
$vouchers = $conn->query("
    SELECT VOUCHER_NAME, DISCOUNT_RATE
    FROM voucher
    WHERE VOUCHER_STATUS = 'Active'
    AND START_DATE <= NOW()
    AND IS_DELETED = 0
    AND (
        VOUCHER_TYPE = 'Tier'
        OR (EXPIRY_DATE IS NOT NULL AND EXPIRY_DATE >= NOW())
    )
");

// SALES CHART (7 DAYS)
$salesLabels = [];
$salesData   = [];
$sql = "
    SELECT DATE(TRANSACTION_DATE) AS date, SUM(PAYMENT_AMOUNT) AS total
    FROM payment
    WHERE PAYMENT_STATUS = 'SUCCESS'
    GROUP BY DATE(TRANSACTION_DATE)
    ORDER BY date DESC
    LIMIT 7
";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $salesLabels[] = $row['date'];
    $salesData[]   = $row['total'];
}
$salesLabels = array_reverse($salesLabels);
$salesData   = array_reverse($salesData);

// CALENDAR EVENTS
$sql = "
    SELECT ORDER_NO, DELIVERY_DATE, ORDER_STATUS, CREATED_AT, DELIVERY_SLOT_SNAPSHOT
    FROM orders 
    WHERE DELIVERY_DATE IS NOT NULL
";
$result = $conn->query($sql);
$events = [];
while ($row = $result->fetch_assoc()) {
    if ($row['ORDER_STATUS'] == 'PROCESSING' || $row['ORDER_STATUS'] == 'READY') {

        $slotTime = $row['DELIVERY_SLOT_SNAPSHOT'];

        // if order is ready, means ready to deliver on delivery date, so show truck icon. if still processing, means still preparing.
        // completed & refunded order will not be shown on calendar.
        if ($row['ORDER_STATUS'] == 'PROCESSING') {
            $prefix = "🎂 Processing";
            $color = "#9fdfff"; 
        } else {
            $prefix = "🚚 Ready";
            $color = "#60ffca"; 
        }

        $events[] = [
            "title" => $prefix . ": " . $row['ORDER_NO'] . " [" . $slotTime . "]",
            "start" => $row['DELIVERY_DATE'],
            "backgroundColor" => $color,
            "borderColor" => $color,
            "textColor" => "#ffffff"
        ];
    }
}

// TOP 5 SELLING PRODUCT CHART
$topLabels = [];
$topData   = [];
$res = $conn->query("SELECT PRODUCT_NAME, SALES_COUNT FROM product ORDER BY SALES_COUNT DESC LIMIT 5");
while ($row = $res->fetch_assoc()) {
    $topLabels[] = $row['PRODUCT_NAME'];
    $topData[]   = $row['SALES_COUNT'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
    <title>Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body {
    background-color: var(--primary-grey);
}

/* DASHBOARD GRID */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 20px;
}

/* TOP STATS */
.stats-grid {
    grid-column: span 2;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 20px;
}

.stat-card {
    background: #fff;
    padding: 10px 15px;
    border-radius: 10px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    display: flex;
    flex-direction: column;
    justify-content: center;
    border: 1px solid var(--primary-border);
    border-left-color: var(--primary-dark);
}

.stat-card:hover {
    transform: translateY(-1px);
    border-color: var(--primary-dark);
}

.stat-card .label {
    margin-bottom: 5px;
    font-size: 13px;
    font-weight: 500;
}

.stat-card .value {
    font-weight: bold;
    font-size: 18px;
    color: var(--primary-dark);
}

.stock-alert-box { 
    margin-top: 5px; 
}

.stock-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f8f9fc;
    padding: 1px 2px;
    border-radius: 8px;
    margin-bottom: 2px;
    font-size: 13px;
}

.stock-title     { 
    font-weight: 600; 
    color: #444; 
}

.low-stock-text  { 
    color: #ffb700; 
    font-weight: 600; 
}

.out-stock { 
    color: #ff3b30; 
    font-weight: 600; 
}

/* GENERAL CARD */
.card {
    background: #fff;
    border-radius: 12px;
    padding: 14px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    border: 1px solid var(--primary-border);
}

.card-title { 
    font-size: 18px; 
    font-weight: 600; 
    margin-bottom: 15px; 
}

/* CALENDAR */
.calendar-card { 
    grid-column: 1; 
    grid-row: 1 / span 2; 
}

.fc .fc-button {
    background-color: var(--primary-dark) !important;
    border: none !important;
    padding: 6px 10px !important;
    box-shadow: none !important;
}

.fc .fc-button:hover { 
    background-color: #486ee2 !important; 
}

.fc .fc-button-group { 
    gap: 2px; 
}

.fc .fc-icon { 
    color: white !important; 
    fill: white !important; 
}

/* QUICK VIEW */
.quick-view {
    grid-column: 2;
    grid-row: 1;
    height: auto;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.quick-view .card-title { 
    width: 100%; 
}

.quick-item {
    flex: 1 1 calc(50% - 10px);
    font-size: 14px;
    background: #f8f9fc;
    padding: 5px;
    border-radius: 8px;
    margin-bottom: 0 !important;
}

.refund-box { 
    border-left: 3px solid var(--warning); 
}

/* ORDER OVERVIEW */
.production-wrapper {
    grid-column: 2;
    grid-row: 2;
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 15px;
}

.production-overview h4 {
    margin: 12px 0 6px 0;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #adadad;
}

.overview-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 5px 8px;
    background: #f8f9fc;
    border-radius: 7px;
    margin-bottom: 5px;
    font-size: 13px;
}

.overview-label { 
    color: #6b7280; 
    font-weight: 500; 
}

.overview-row strong {
    font-size: 16px;
    font-weight: 700;
    color: #111827;
}

/* DELIVERY ALERT */
.delivery-alert-card {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    background: var(--primary-dark);
}

.delivery-alert-card:hover {
    transform: scale(1.05);
}

.delivery-number { 
    font-size: 60px; 
    font-weight: bold; 
    color: var(--primary-white); 
    line-height: 1; 
}

.delivery-text   { 
    margin-top: 10px; 
    font-size: 15px; 
    color: var(--primary-white); 
}

/* GRID PLACEMENTS */
.sales-trend   { grid-column: 2; grid-row: 3; }
.top-products  { grid-column: 1; grid-row: 3; }
.low-stock     { grid-column: 2; grid-row: 4; }
.quick-orders  { grid-column: 1; grid-row: 4; }
.quick-delivery{ grid-column: 1; grid-row: 5; }
.refund-card   { grid-column: 2; grid-row: 5; }
.custom-card   { grid-column: 2; grid-row: 6; }
.voucher-card  { grid-column: 1; grid-row: 6; }

.sales-trend, .low-stock, .quick-orders,
.quick-delivery, .refund-card, .voucher-card { 
    min-height: 320px; 
}

/* TABLE */
.table-scroll {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 220px;
    border-radius: 10px;
}

.table-scroll th { 
    position: sticky; 
    top: 0; 
    background: #fff; 
    z-index: 1; 
}

table { 
    width: 100%; 
    border-collapse: collapse; 
}

table th, table td { 
    padding: 8px 10px; 
    border-bottom: 1px solid #eee; 
    text-align: left; 
    font-size: 13px; 
}

/* TABLE BUTTONS */
.view-btn, .edit-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 8px;
    background: #4e73df;
    color: white;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

/* CHART SIZE */
.sales-trend canvas, .top-products canvas { 
    max-height: 220px !important; 
}

/* VOUCHER */
.voucher-card ul { 
    margin: 0; 
    padding-left: 18px; 
}

.voucher-card li { 
    margin-bottom: 10px; 
}

.voucher-list li::marker { 
    color: var(--primary-dark); 
}

.sales-trend canvas,
.top-products canvas {
    width: 100% !important;
}
</style>
</head>

<body>
<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>

<div class="main">

    <!-- STATS -->
    <div class="stats-grid">

        <div class="stat-card primary">
            <div class="label">TOTAL REVENUE</div>
            <div class="value">RM <?= number_format($totalRevenue, 2) ?></div>
        </div>

        <div class="stat-card success">
            <div class="label">TOTAL ORDERS</div>
            <div class="value"><?= $totalOrders ?></div>
        </div>

        <div class="stat-card info">
            <div class="label">TOTAL CUSTOMERS</div>
            <div class="value"><?= $totalCustomers ?></div>
        </div>

        <div class="stat-card success">
            <div class="label">AVERAGE RATING</div>
            <div class="value">⭐ <?= $avgRating ?>/5</div>
        </div>

        <div class="stat-card warning">
            <div class="label">STOCK ALERT</div>
            <div class="stock-alert-box">
                <div class="stock-row">
                    <span class="stock-title">Variant</span>
                    <span class="low-stock-text">Low <?= $lowVariant ?></span>
                    <span class="out-stock">Out <?= $outVariant ?></span>
                </div>
                <div class="stock-row">
                    <span class="stock-title">Addon</span>
                    <span class="low-stock-text">Low <?= $lowAddon ?></span>
                    <span class="out-stock">Out <?= $outAddon ?></span>
                </div>
            </div>
        </div>

    </div>

    <div class="dashboard-grid">

        <!-- CALENDAR -->
        <div class="card calendar-card">
            <div class="card-title">🎂 Calendar</div>
            <div id="calendar"></div>
        </div>

        <!-- QUICK OVERVIEW -->
        <div class="card quick-view">
            <div class="card-title">Quick Overview</div>
            <div class="quick-item">🟡 Processing Orders: <?= $pendingOrders ?></div>
            <div class="quick-item">🚚 Ready Delivery: <?= $pendingDelivery ?></div>
            <div class="quick-item">🎂 Custom Request: <?= $pendingCustom ?></div>
            <div class="quick-item">💸 Refund Request: <?= $pendingRefundRequest ?></div>
            <div class="quick-item refund-box">💸 Wait for Refund: <?= $pendingRefund ?></div>
        </div>

        <div class="production-wrapper">

            <!-- ORDERS OVERVIEW -->
            <div class="card production-overview">
                <div class="card-title">Orders Overview</div>

                <h4>TODAY ORDERS</h4>
                <div class="overview-row">
                    <span class="overview-label">Normal</span>
                    <strong><?= $sameDayNormal ?></strong>
                </div>
                <div class="overview-row">
                    <span class="overview-label">Custom</span>
                    <strong><?= $sameDayCustom ?></strong>
                </div>

                <h4>FUTURE ORDERS</h4>
                <div class="overview-row">
                    <span class="overview-label">Normal</span>
                    <strong><?= $preOrderNormal ?></strong>
                </div>
                <div class="overview-row">
                    <span class="overview-label">Custom</span>
                    <strong><?= $preOrderCustom ?></strong>
                </div>
            </div>

            <!-- DELIVERY ALERT -->
            <div class="card delivery-alert-card">
                <div class="card-title"><span class="delivery-text">🚚 Today Delivery</span></div>
                <div class="delivery-number"><?= $todayDelivery ?></div>
                <div class="delivery-text">delivery(s) scheduled today</div>
            </div>

        </div>

        <!-- TOP PRODUCTS -->
        <div class="card top-products">
            <div class="card-title">Top 5 Sales Products</div>
            <canvas id="topProductChart"></canvas>
        </div>

        <!-- SALES TREND -->
        <div class="card sales-trend">
            <div class="card-title">Sales Trend (Last 7 Days)</div>
            <canvas id="salesChart"></canvas>
        </div>

        <!-- LOW STOCK -->
        <div class="card low-stock">
            <div class="card-title">Low / Out of Stock (Variants & Addons)</div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Name</th><th>Type</th><th>Stock</th><th>Status</th><th>Action</th>
                    </tr>
                    <?php while ($row = $lowStock->fetch_assoc()):
                        $status = ($row['stock'] == 0) ? "OUT" : "LOW";
                    ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($row['name']) ?>

                            <?php if ($row['type'] == 'VARIANT'): ?>
                                <span style="font-size:12px; color:#666;">
                                    (<?= htmlspecialchars($row['size']) ?> inch)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['type']) ?></td>
                        <td><?= $row['stock'] ?></td>
                        <td><?= $status ?></td>
                        <td>
                            <button class="edit-btn" onclick="editStock('<?= $row['type'] ?>', <?= $row['id'] ?>, <?= $row['stock'] ?>)">Edit</button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

        <!-- QUICK ORDERS -->
        <div class="card quick-orders">
            <div class="card-title">Quick Orders (Processing)</div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Order No</th><th>Total</th><th>Status</th><th>Action</th>
                    </tr>
                    <?php while ($row = $quickOrders->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['ORDER_NO']) ?></td>
                        <td>RM <?= htmlspecialchars($row['TOTAL_AMOUNT']) ?></td>
                        <td><?= htmlspecialchars($row['ORDER_STATUS']) ?></td>
                        <td>
                            <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

        <!-- QUICK DELIVERY -->
        <div class="card quick-delivery">
            <div class="card-title">Quick Delivery (Pending)</div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Order No</th><th>Delivery Date</th><th>Time</th><th>Status</th><th>Action</th>
                    </tr>
                    <?php while ($row = $quickDelivery->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['ORDER_NO']) ?></td>
                        <td><?= htmlspecialchars($row['DELIVERY_DATE']) ?></td>
                        <td><?= htmlspecialchars($row['DELIVERY_SLOT_SNAPSHOT']) ?></td>
                        <td><?= htmlspecialchars($row['DELIVERY_STATUS']) ?></td>
                        <td>
                            <a href="view_delivery.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

        <!-- REFUND REQUESTS -->
        <div class="card refund-card">
            <div class="card-title">Refund Requests (Pending)</div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Order No</th><th>Reason</th><th>Request Date</th><th>Status</th><th>Action</th>
                    </tr>
                    <?php while ($row = $refundRequests->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['ORDER_NO']) ?></td>
                        <td><?= htmlspecialchars(substr($row['REASON'], 0, 40)) ?></td>
                        <td><?= htmlspecialchars($row['REQUEST_DATE']) ?></td>
                        <td><?= htmlspecialchars($row['REQUEST_STATUS']) ?></td>
                        <td>
                            <a href="process_refund_request.php?request_id=<?= $row['REQUEST_ID'] ?>" class="view-btn">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

        <!-- CUSTOM REQUESTS -->
        <div class="card custom-card">
            <div class="card-title">Custom Request (Pending)</div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Recipient</th><th>Cake</th><th>Budget</th><th>Status</th><th>Action</th>
                    </tr>
                    <?php while ($row = $customRequests->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['RECIPIENT_NAME']) ?></td>
                        <td><?= htmlspecialchars($row['STYLE_NAME_SNAPSHOT']) ?></td>
                        <td>RM <?= htmlspecialchars($row['BUDGET']) ?></td>
                        <td><?= htmlspecialchars($row['STATUS']) ?></td>
                        <td>
                            <a href="process_custom.php?id=<?= $row['CUSTOM_ID'] ?>" class="view-btn">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </table>
            </div>
        </div>

        <!-- VOUCHERS -->
        <div class="card voucher-card">
            <div class="card-title">Active Vouchers</div>
            <div class="table-scroll">
                <ul class="voucher-list">
                    <?php while ($row = $vouchers->fetch_assoc()): ?>
                        <li><?= htmlspecialchars($row['VOUCHER_NAME']) ?> - <?= htmlspecialchars($row['DISCOUNT_RATE']) ?>%</li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>

    </div>

</div>
</div>

<!-- CHART -->
<script>
// Sales Chart
new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($salesLabels) ?>,
        datasets: [{
            label: 'Sales (RM)',
            data: <?= json_encode($salesData) ?>
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false
    }
});

// Top Selling Product Chart
new Chart(document.getElementById('topProductChart'), {
    type: 'pie',
    data: {
        labels: <?= json_encode($topLabels) ?>,
        datasets: [{
            data: <?= json_encode($topData) ?>,
            backgroundColor: [
                '#4e73df',
                '#1cc88a',
                '#36b9cc',
                '#f6c23e',
                '#e74a3b'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>

<!-- CALENDAR -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    window.calendar = new FullCalendar.Calendar(
        document.getElementById('calendar'),
        {
            initialView: 'dayGridMonth',
            events: <?= json_encode($events) ?>,
            height: 'auto',
            eventClick: function(info) {
                alert(info.event.title);
            }
        }
    );

    calendar.render();
});
</script>

<!-- QUICK UPDATE STOCK -->
<script>
function editStock(type, id, currentStock) {

    let newStock = prompt("Enter new stock:", currentStock);

    // user cancel
    if (newStock === null) return;

    // remove spaces
    newStock = newStock.trim();

    // empty
    if (newStock === "") {
        alert("Stock cannot be empty.");
        return;
    }

    // integer only
    if (!/^\d+$/.test(newStock)) {
        alert("Stock must be a valid whole number.");
        return;
    }

    // convert to number
    newStock = parseInt(newStock);

    // negative protection
    if (newStock < 0) {
        alert("Stock cannot be negative.");
        return;
    }

    // optional max limit
    if (newStock > 9999) {
        alert("Stock is too large.");
        return;
    }

    fetch("quick_update_stock.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "type=" + encodeURIComponent(type) +
            "&id=" + encodeURIComponent(id) +
            "&stock=" + encodeURIComponent(newStock)
    })
    .then(res => res.text())
    .then(() => {
        alert("Stock updated!");
        location.reload();
    });
}
</script>

</body>
</html>