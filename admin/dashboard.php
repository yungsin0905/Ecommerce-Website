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

// 5. STOCK ALERT (variant<=5 == low stock; variant=0 == out of stock)
$sql = "
SELECT 
    SUM(CASE WHEN VARIANT_STOCK <= 5 AND VARIANT_STOCK > 0 THEN 1 ELSE 0 END) AS lowVariant,
    SUM(CASE WHEN VARIANT_STOCK = 0 THEN 1 ELSE 0 END) AS outVariant
FROM product_variant
WHERE IS_DELETED = 0
";
$result    = $conn->query($sql);
$row       = $result->fetch_assoc();
$lowVariant = $row['lowVariant'] ?? 0;
$outVariant = $row['outVariant'] ?? 0;

// ORDER OVERVIEW (only count orders that are still processing or ready, completed & refunded order will not be counted in overview, as they are already done)
$today = date('Y-m-d');


// OVERDUE DELIVERY (orders that are READY but still PENDING shipment for more than 2 days)
$OVERDUE_DAYS = 2; // adjust this threshold as needed

$overdueDelivery = $conn->query("
    SELECT COUNT(*) AS c FROM orders o
    JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE o.ORDER_STATUS = 'READY'
    AND s.DELIVERY_STATUS = 'PENDING'
    AND o.READY_AT IS NOT NULL
    AND o.READY_AT <= NOW() - INTERVAL $OVERDUE_DAYS DAY
")->fetch_assoc()['c'] ?? 0;

// QUICK OVERVIEW counts
// Orders with status processing
$pendingOrders        = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE ORDER_STATUS='PROCESSING'")->fetch_assoc()['c'];
// Ready delivery where order status is ready, but delivery status still pending, means still waiting for delivery, so count in quick overview.
$pendingDelivery      = $conn->query("SELECT COUNT(*) AS c FROM orders WHERE ORDER_STATUS='READY'")->fetch_assoc()['c'];

// LOW STOCK TABLE
$lowStock = $conn->query("
    SELECT v.VARIANT_ID AS id, p.PRODUCT_NAME AS name, v.VARIANT_LABEL AS size, v.VARIANT_STOCK AS stock, 'VARIANT' AS type
    FROM product_variant v
    JOIN product p ON p.PRODUCT_ID = v.PRODUCT_ID
    WHERE v.IS_DELETED = 0 AND v.VARIANT_STOCK <= 5 AND p.IS_DELETED = 0
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
    SELECT o.ORDER_ID, o.ORDER_NO, o.CREATED_AT, s.DELIVERY_STATUS, s.SHIPPING_METHOD
    FROM shipping s JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'PENDING' AND o.ORDER_STATUS != 'REFUNDED'
    ORDER BY o.CREATED_AT ASC
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

// TOP 5 SELLING PRODUCT CHART
$topLabels = [];
$topData   = [];
$res = $conn->query("SELECT PRODUCT_NAME, SALES_COUNT FROM product ORDER BY SALES_COUNT DESC LIMIT 5");
while ($row = $res->fetch_assoc()) {
    $topLabels[] = $row['PRODUCT_NAME'];
    $topData[]   = $row['SALES_COUNT'];
}

// ================= NEW: SALES SUMMARY (today / week / month) =================
$todayRevenue = $conn->query("
    SELECT SUM(PAYMENT_AMOUNT) AS total FROM payment
    WHERE PAYMENT_STATUS = 'SUCCESS' AND DATE(TRANSACTION_DATE) = CURDATE()
")->fetch_assoc()['total'] ?? 0;

$weekRevenue = $conn->query("
    SELECT SUM(PAYMENT_AMOUNT) AS total FROM payment
    WHERE PAYMENT_STATUS = 'SUCCESS' AND YEARWEEK(TRANSACTION_DATE, 1) = YEARWEEK(CURDATE(), 1)
")->fetch_assoc()['total'] ?? 0;

$monthRevenue = $conn->query("
    SELECT SUM(PAYMENT_AMOUNT) AS total FROM payment
    WHERE PAYMENT_STATUS = 'SUCCESS'
    AND YEAR(TRANSACTION_DATE) = YEAR(CURDATE()) AND MONTH(TRANSACTION_DATE) = MONTH(CURDATE())
")->fetch_assoc()['total'] ?? 0;

// ================= NEW: OUT-OF-STOCK PRODUCTS (product 层级，非 variant 层级) =================
$outOfStockRes = $conn->query("
    SELECT p.PRODUCT_ID, p.PRODUCT_NAME
    FROM product p
    WHERE p.IS_DELETED = 0
    AND EXISTS (SELECT 1 FROM product_variant v WHERE v.PRODUCT_ID = p.PRODUCT_ID AND v.IS_DELETED = 0)
    AND NOT EXISTS (
        SELECT 1 FROM product_variant v
        WHERE v.PRODUCT_ID = p.PRODUCT_ID AND v.IS_DELETED = 0 AND v.VARIANT_STOCK > 0
    )
");
$outOfStockList  = $outOfStockRes->fetch_all(MYSQLI_ASSOC);
$outOfStockCount = count($outOfStockList);

// ================= NEW: UNSHIPPED ORDERS COUNT =================
$unshippedOrders = $conn->query("
    SELECT COUNT(*) AS c FROM shipping s
    JOIN orders o ON o.SHIPPING_ID = s.SHIPPING_ID
    WHERE s.DELIVERY_STATUS = 'PENDING' AND o.ORDER_STATUS != 'REFUNDED'
")->fetch_assoc()['c'] ?? 0;

// ================= NEW: ORDER CALENDAR (每天下单数量 + 明细) =================
$orderCalRes = $conn->query("
    SELECT ORDER_ID, ORDER_NO, TOTAL_AMOUNT, ORDER_STATUS, DATE(CREATED_AT) AS d
    FROM orders
    ORDER BY CREATED_AT ASC
");

$ordersByDate   = [];   // 'YYYY-MM-DD' => [ [order_no, amount, status], ... ]
$orderCalEvents = [];   // FullCalendar 事件（只放数量，明细另外传）
$dailyCounts    = [];

while ($row = $orderCalRes->fetch_assoc()) {
    $d = $row['d'];
    $ordersByDate[$d][] = [
        'id'     => $row['ORDER_ID'],
        'no'     => $row['ORDER_NO'],
        'amount' => (float)$row['TOTAL_AMOUNT'],
        'status' => $row['ORDER_STATUS'],
    ];
    $dailyCounts[$d] = ($dailyCounts[$d] ?? 0) + 1;
}

foreach ($dailyCounts as $d => $c) {
    $orderCalEvents[] = [
        'title'     => $c . ' order' . ($c > 1 ? 's' : ''),
        'date'      => $d,
        'display'   => 'block',
    ];
}
// ================= NEW: RECENT REVIEWS =================
$newReviews = $conn->query("
    SELECT r.RATING, r.COMMENTS, r.CREATED_AT, u.CUSTOMER_NAME, p.PRODUCT_NAME
    FROM review r
    JOIN customer u ON r.CUSTOMER_ID = u.CUSTOMER_ID
    JOIN product p ON r.PRODUCT_ID = p.PRODUCT_ID
    WHERE r.REVIEW_STATUS = 'Unhide'
    ORDER BY r.CREATED_AT DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ================= NEW: TOP RATED PRODUCTS =================
$topRated = $conn->query("
    SELECT p.PRODUCT_NAME, AVG(r.RATING) AS avgRating, COUNT(r.REVIEW_ID) AS reviewCount
    FROM product p
    JOIN review r ON r.PRODUCT_ID = p.PRODUCT_ID AND r.REVIEW_STATUS = 'Unhide'
    WHERE p.IS_DELETED = 0
    GROUP BY p.PRODUCT_ID
    ORDER BY avgRating DESC, reviewCount DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ================= NEW: MEMBERSHIP OVERVIEW =================
$membershipOverview = $conn->query("
    SELECT t.TIER_ID, t.TIER_NAME, t.MIN_SPENT, COUNT(c.CUSTOMER_ID) AS c
    FROM membership_tier t
    LEFT JOIN customer c ON c.TIER_ID = t.TIER_ID
    WHERE t.STATUS = 'Active'
    GROUP BY t.TIER_ID, t.TIER_NAME, t.MIN_SPENT
    ORDER BY t.MIN_SPENT ASC
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

/* ================= DASHBOARD HEADER ================= */
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

.dash-title {
    font-family: var(--font-heading);
    font-size: 26px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 28px;
    display: block;
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

.dash-header-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.dash-pill-badge {
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
    box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}

.dash-pill-badge i {
    color: var(--text-muted);
    font-size: 14px;
}

.dash-pill-btn-dark {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--dark-pill);
    color: #ffffff !important;
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(24, 24, 27, 0.15);
}

a.dash-pill-btn-dark,
a.dash-pill-btn-dark:link,
a.dash-pill-btn-dark:visited,
a.dash-pill-btn-dark:hover,
a.dash-pill-btn-dark:active,
a.dash-pill-btn-dark span,
a.dash-pill-btn-dark i {
    color: #ffffff !important;
}

.dash-pill-btn-dark:hover {
    background: var(--accent-lime);
    color: var(--text-dark) !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(212, 249, 56, 0.4);
}

/* ================= TOP STATS CARDS ================= */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 18px;
    margin-bottom: 20px;
}

.stat-card {
    background: var(--card-bg);
    padding: 20px 20px 18px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.25s ease;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
    border-color: #d1d5db;
}

.stat-card .stat-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.stat-card .label {
    font-family: var(--font-heading);
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: var(--text-muted);
}

.stat-pill-lime {
    background: var(--accent-lime);
    color: var(--text-dark);
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    letter-spacing: 0.2px;
    display: inline-flex;
    align-items: center;
    gap: 3px;
}

.stat-pill-dark {
    background: var(--dark-pill);
    color: #ffffff;
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
}

.stat-card .value {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 800;
    color: var(--text-dark);
    line-height: 1.15;
    margin-bottom: 4px;
    letter-spacing: -0.5px;
}

.stat-card .value small {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-muted);
}

.stat-card .stat-sub {
    font-size: 11.5px;
    color: var(--text-light);
    font-weight: 500;
}

/* Stock Alert Box in Stat Card */
.stock-alert-box {
    margin-top: 4px;
}

.stock-pill-row {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 4px;
}

.stock-pill-item {
    flex: 1;
    background: #f8fafc;
    border: 1px solid var(--border-card);
    padding: 6px 10px;
    border-radius: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
}

.stock-pill-item.low {
    border-color: #fef08a;
    background: #fefce8;
}

.stock-pill-item.out {
    border-color: #fecaca;
    background: #fef2f2;
}

.stock-pill-item span {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 11px;
}

.stock-pill-item strong {
    font-size: 13px;
    font-weight: 800;
}

.stock-pill-item.low strong { color: #ca8a04; }
.stock-pill-item.out strong { color: #dc2626; }

/* ================= SALES SUMMARY CARD ================= */
.card-sales-summary {
    background: var(--card-bg);
    border-radius: var(--radius-card);
    padding: 22px 24px;
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    margin-bottom: 20px;
}

.card-title-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.card-title {
    font-family: var(--font-heading);
    font-size: 16.5px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 16px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    letter-spacing: -0.2px;
}

.card-title-badge {
    background: var(--accent-lime);
    color: var(--text-dark);
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
}

.sales-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}

.mini-stat-card {
    background: #f8fafc;
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 16px 20px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: all 0.2s ease;
}

.mini-stat-card:hover {
    background: #ffffff;
    border-color: #cbd5e1;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.03);
}

.mini-stat-card .label {
    font-family: var(--font-heading);
    font-size: 11.5px;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
}

.mini-stat-card .label::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    box-shadow: 0 0 6px rgba(212, 249, 56, 0.8);
}

.mini-stat-card .value {
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 800;
    color: var(--text-dark);
    letter-spacing: -0.4px;
}

/* ================= DASHBOARD MAIN GRID ================= */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.dashboard-grid .full-width {
    grid-column: span 2;
}

/* GENERAL CARD */
.card {
    background: var(--card-bg);
    border-radius: var(--radius-card);
    padding: 22px 24px;
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    position: relative;
}

/* ================= CALENDAR ROW ================= */
.calendar-row {
    grid-column: span 2;
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 20px;
    align-items: start;
}

.calendar-side {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* FULLCALENDAR MODERN RETOUCH */
.fc {
    font-family: var(--font-body) !important;
}

.fc .fc-toolbar {
    margin-bottom: 16px !important;
}

.fc .fc-toolbar-title {
    font-family: var(--font-heading) !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    color: var(--text-dark) !important;
}

.fc .fc-button {
    background-color: var(--dark-pill) !important;
    color: #ffffff !important;
    border: none !important;
    padding: 6px 14px !important;
    border-radius: var(--radius-pill) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    box-shadow: none !important;
    transition: all 0.2s ease !important;
}

.fc .fc-button:hover {
    background-color: var(--accent-lime) !important;
    color: var(--text-dark) !important;
}

.fc .fc-button-primary:not(:disabled).fc-button-active, 
.fc .fc-button-primary:not(:disabled):active {
    background-color: var(--accent-lime) !important;
    color: var(--text-dark) !important;
}

.fc .fc-button-group {
    gap: 4px;
}

.fc-theme-standard td, .fc-theme-standard th {
    border-color: #f1f3f6 !important;
}

.fc .fc-col-header-cell-cushion {
    font-size: 11.5px !important;
    font-weight: 700 !important;
    color: var(--text-muted) !important;
    padding: 6px 0 !important;
    text-transform: uppercase;
}

.fc .fc-daygrid-day-number {
    font-size: 12px !important;
    color: var(--text-muted) !important;
    padding: 4px 6px !important;
    font-weight: 500 !important;
}

.fc-day-today {
    background: #f8fafc !important;
}

.fc-day-today .fc-daygrid-day-number {
    font-weight: 800 !important;
    color: var(--text-dark) !important;
}

.fc-daygrid-event {
    cursor: pointer;
    background: var(--dark-pill) !important;
    border: none !important;
    border-radius: var(--radius-pill) !important;
    padding: 2px 8px !important;
    margin: 2px !important;
}

.fc-event-title {
    color: var(--accent-lime) !important;
    font-weight: 700 !important;
    font-size: 11px !important;
}

.fc .fc-button .fc-icon,
.fc .fc-button:hover .fc-icon {
    color: #ffffff !important;
}

/* ================= QUICK OVERVIEW CARD ================= */
.quick-view {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.quick-view-items {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.quick-item {
    background: #f8fafc;
    border: 1px solid var(--border-card);
    padding: 12px 14px;
    border-radius: 14px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ================= PRODUCTION 2x2 MINI ALERTS ================= */
.production-wrapper {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.mini-alert-card {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 16px 18px;
    border-radius: 18px;
    transition: all 0.2s ease;
    border: 1px solid transparent;
}

.mini-alert-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.06);
}

.mini-alert-card.lime-theme {
    background: var(--accent-lime);
    color: var(--text-dark);
    box-shadow: 0 4px 14px rgba(212, 249, 56, 0.25);
}

.mini-alert-card.dark-theme {
    background: var(--dark-pill);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.2);
}

.mini-alert-card.soft-theme {
    background: #ffffff;
    border-color: var(--border-card);
    color: var(--text-dark);
}

.mini-alert-card .delivery-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: flex;
    align-items: center;
    gap: 6px;
    opacity: 0.85;
}

.mini-alert-card .delivery-number {
    font-family: var(--font-heading);
    font-size: 32px;
    font-weight: 800;
    line-height: 1.1;
    margin: 8px 0 4px;
    letter-spacing: -0.5px;
}

.mini-alert-card .delivery-text {
    font-size: 11.5px;
    font-weight: 500;
    opacity: 0.8;
}

.mini-alert-card .delivery-text a {
    color: inherit;
    text-decoration: underline;
    font-weight: 700;
}

/* ================= REVIEWS CARD ================= */
.review-item {
    padding: 12px 14px;
    background: #f8fafc;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    margin-bottom: 10px;
    font-size: 13px;
    transition: all 0.2s ease;
}

.review-item:hover {
    background: #ffffff;
    border-color: #cbd5e1;
}

.review-item:last-child {
    margin-bottom: 0;
}

.review-item .stars {
    color: #f59e0b;
    font-size: 12px;
    letter-spacing: 2px;
    margin-bottom: 4px;
}

.review-item .review-header {
    font-weight: 700;
    color: var(--text-dark);
}

.review-item .comment {
    color: var(--text-muted);
    margin: 4px 0 6px;
    line-height: 1.4;
}

.review-item .meta {
    color: var(--text-light);
    font-size: 11px;
    font-weight: 500;
}

/* ================= TABLES ================= */
.table-scroll {
    overflow-x: auto;
    overflow-y: auto;
    max-height: 220px;
    border-radius: 14px;
    border: 1px solid var(--border-card);
}

table {
    width: 100%;
    border-collapse: collapse;
}

table th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    color: var(--text-muted);
    font-family: var(--font-heading);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border-card);
    z-index: 2;
    text-align: left;
}

table td {
    padding: 10px 14px;
    border-bottom: 1px solid #f1f3f6;
    font-size: 13px;
    color: var(--text-dark);
    vertical-align: middle;
}

table tr:last-child td {
    border-bottom: none;
}

table tr:hover td {
    background: #fafbfc;
}

/* TABLE ACTION BUTTONS */
.view-btn, .edit-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 5px 14px;
    border: none;
    border-radius: var(--radius-pill);
    background: var(--dark-pill);
    color: #ffffff !important;
    text-decoration: none;
    cursor: pointer;
    font-size: 12px;
    font-weight: 600;
    transition: all 0.2s ease;

}

.view-btn i, .edit-btn i{
    color:white;
}

.view-btn:hover, .edit-btn:hover {
    background: var(--accent-lime);
    color: var(--text-dark) !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(212, 249, 56, 0.35);
}

.status-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: var(--radius-pill);
    display: inline-block;
}

.status-badge.out { background: #fee2e2; color: #b91c1c; }
.status-badge.low { background: #fef08a; color: #a16207; }
.status-badge.proc { background: #fef3c7; color: #b45309; }
.status-badge.ready { background: #dbeafe; color: #1e40af; }
.status-badge.done { background: #dcfce7; color: #15803d; }

/* ================= VOUCHER LIST ================= */
.voucher-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.voucher-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #f8fafc;
    border: 1px solid var(--border-card);
    border-radius: 12px;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
}

.voucher-list li:last-child {
    margin-bottom: 0;
}

.voucher-rate-pill {
    background: var(--accent-lime);
    color: var(--text-dark);
    font-weight: 800;
    font-size: 11.5px;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
}

/* ================= CHART CANVAS SIZES ================= */
.sales-trend canvas, .rankings-card canvas, .membership-card canvas {
    max-height: 220px !important;
}

/* ================= DAY ORDERS POPOVER ================= */
.day-orders-popover {
    position: absolute;
    z-index: 999;
    background: #fff;
    border: 1px solid var(--border-card);
    border-radius: 18px;
    box-shadow: 0 16px 40px rgba(0,0,0,0.12);
    padding: 16px 18px;
    width: 290px;
    display: none;
}

.day-orders-popover.show { display: block; }

.day-orders-popover .popover-title {
    font-family: var(--font-heading);
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--text-dark);
}

.day-orders-popover .popover-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px solid #f1f3f6;
    font-size: 12.5px;
}

.day-orders-popover .popover-row:last-of-type { border-bottom: none; }

.day-orders-popover .po-no { font-weight: 700; color: var(--text-dark); flex: 1 1 auto; }
.day-orders-popover .po-amt { color: var(--text-muted); font-weight: 600; white-space: nowrap; }

.po-status {
    font-size: 10.5px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    white-space: nowrap;
}
.po-status.PROCESSING { background: #fef3c7; color: #b45309; }
.po-status.READY      { background: #dbeafe; color: #1e40af; }
.po-status.COMPLETED  { background: #dcfce7; color: #15803d; }
.po-status.CANCELLED  { background: #fee2e2; color: #b91c1c; }

.day-orders-popover .popover-more {
    font-size: 12px;
    color: var(--text-muted);
    text-align: center;
    padding: 6px 0;
    font-weight: 600;
}

.day-orders-popover .popover-footer {
    margin-top: 12px;
    text-align: right;
}

.day-orders-popover .popover-footer a {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--text-dark);
    text-decoration: none;
}

.day-orders-popover .popover-footer a:hover {
    color: #4b5563;
    text-decoration: underline;
}

.day-orders-popover .popover-close {
    position: absolute;
    top: 12px;
    right: 14px;
    cursor: pointer;
    color: var(--text-light);
    font-size: 16px;
    line-height: 1;
}

@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    .calendar-row {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    .sales-summary-grid {
        grid-template-columns: 1fr;
    }
    .dashboard-grid {
        grid-template-columns: 1fr;
    }
    .dashboard-grid .full-width {
        grid-column: span 1;
    }
}
</style>
</head>

<body>
<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>

<div class="main">

    <!-- DASHBOARD TOP HEADER BAR -->
    <div class="dash-header">
        <div class="dash-title-group">
            <h1 class="dash-title">Store Performance <strong>Dashboard</strong></h1>
            <div class="dash-title-badge" title="Live Operations">
                <i class="bi bi-asterisk"></i>
                <span class="badge-arrow">↗</span>
            </div>
        </div>
        <div class="dash-header-controls">
            <div class="dash-pill-badge">
                <i class="bi bi-calendar3"></i>
                <span><?= date('D, d M Y') ?></span>
            </div>
            <div class="dash-pill-badge">
                <i class="bi bi-clock"></i>
                <span>24h Active</span>
            </div>
            <a href="manage_order.php" class="dash-pill-btn-dark">
                <span>Manage Orders</span>
                <i class="bi bi-arrow-right-short"></i>
            </a>
        </div>
    </div>

    <!-- 5 STATS CARDS -->
    <div class="stats-grid">

        <div class="stat-card">
            <div class="stat-header">
                <span class="label">Total Revenue</span>
                <span class="stat-pill-lime">↗ 100%</span>
            </div>
            <div class="value">RM <?= number_format($totalRevenue, 2) ?></div>
            <div class="stat-sub">Paid & successful payments</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="label">Total Orders</span>
                <span class="stat-pill-dark">📦 Live</span>
            </div>
            <div class="value"><?= $totalOrders ?></div>
            <div class="stat-sub">Lifetime orders placed</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="label">Total Customers</span>
                <span class="stat-pill-lime">👥 Users</span>
            </div>
            <div class="value"><?= $totalCustomers ?></div>
            <div class="stat-sub">Registered store members</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="label">Average Rating</span>
                <span class="stat-pill-lime">⭐ Verified</span>
            </div>
            <div class="value"><?= $avgRating ?> <small>/ 5.0</small></div>
            <div class="stat-sub">Overall customer score</div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <span class="label">Stock Alert</span>
                <span class="stat-pill-dark">⚡ Monitor</span>
            </div>
            <div class="stock-alert-box">
                <div class="stock-pill-row">
                    <div class="stock-pill-item low">
                        <span>Low Stock</span>
                        <strong><?= $lowVariant ?></strong>
                    </div>
                    <div class="stock-pill-item out">
                        <span>Out</span>
                        <strong><?= $outVariant ?></strong>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- SALES SUMMARY -->
    <div class="card-sales-summary">
        <div class="card-title">
            <span>Sales Summary</span>
            <span class="card-title-badge">Overview</span>
        </div>
        <div class="sales-summary-grid">
            <div class="mini-stat-card">
                <div class="label">Today</div>
                <div class="value">RM <?= number_format($todayRevenue, 2) ?></div>
            </div>
            <div class="mini-stat-card">
                <div class="label">This Week</div>
                <div class="value">RM <?= number_format($weekRevenue, 2) ?></div>
            </div>
            <div class="mini-stat-card">
                <div class="label">This Month</div>
                <div class="value">RM <?= number_format($monthRevenue, 2) ?></div>
            </div>
        </div>
    </div>

    <!-- DASHBOARD MAIN GRID -->
    <div class="dashboard-grid">

        <!-- CALENDAR + QUICK OVERVIEW ROW -->
        <div class="calendar-row">

            <div class="card calendar-card">
                <div class="card-title">
                    <span>Order Calendar</span>
                    <span class="card-title-badge">Daily Activity</span>
                </div>
                <div id="orderCalendar"></div>
            </div>

            <div class="calendar-side">

                <!-- QUICK OVERVIEW -->
                <div class="card quick-view">
                    <div class="card-title">Quick Overview</div>
                    <div class="quick-view-items">
                        <div class="quick-item">
                            <span>🟡 Processing:</span>
                            <strong><?= $pendingOrders ?></strong>
                        </div>
                        <div class="quick-item">
                            <span>🚚 Ready:</span>
                            <strong><?= $pendingDelivery ?></strong>
                        </div>
                    </div>
                </div>

                <!-- OVERDUE + UNSHIPPED + OUT OF STOCK (2x2 MINI ALERTS) -->
                <div class="production-wrapper">
                    <div class="mini-alert-card lime-theme">
                        <div class="delivery-title">⏰ Overdue</div>
                        <div class="delivery-number"><?= $overdueDelivery ?></div>
                        <div class="delivery-text">waiting over <?= $OVERDUE_DAYS ?> day(s)</div>
                    </div>
                    <div class="mini-alert-card soft-theme">
                        <div class="delivery-title">📦 Unshipped</div>
                        <div class="delivery-number"><?= $unshippedOrders ?></div>
                        <div class="delivery-text">orders waiting dispatch</div>
                    </div>
                    <div class="mini-alert-card soft-theme">
                        <div class="delivery-title">📉 Low Stock</div>
                        <div class="delivery-number"><?= $lowVariant ?></div>
                        <div class="delivery-text">variants running low</div>
                    </div>
                    <div class="mini-alert-card lime-theme">
                        <div class="delivery-title">⚠️ Out of Stock</div>
                        <div class="delivery-number"><?= $outOfStockCount ?></div>
                        <div class="delivery-text">
                            product<?= $outOfStockCount > 1 ? 's' : '' ?> zero stock
                            <?php if ($outOfStockCount > 0): ?>
                                — <a href="manage_product.php?stock=out">View</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- PRODUCT RANKINGS: BEST SELLERS -->
        <div class="card rankings-card">
            <div class="card-title">
                <span>🏆 Best Sellers</span>
                <span class="card-title-badge">Units</span>
            </div>
            <canvas id="bestSellersChart"></canvas>
        </div>

        <!-- PRODUCT RANKINGS: TOP RATED -->
        <div class="card rankings-card">
            <div class="card-title">
                <span>⭐ Top Rated Products</span>
                <span class="card-title-badge">Score</span>
            </div>
            <canvas id="topRatedChart"></canvas>
        </div>

        <!-- NEW REVIEWS -->
        <div class="card reviews-card">
            <div class="card-title">
                <span>Recent Reviews</span>
                <span class="card-title-badge">Latest 5</span>
            </div>
            <?php foreach ($newReviews as $r): ?>
            <div class="review-item">
                <div class="stars"><?php for ($i = 1; $i <= 5; $i++) echo $i <= $r['RATING'] ? '★' : '☆'; ?></div>
                <div class="review-header"><?= htmlspecialchars($r['CUSTOMER_NAME']) ?> <span style="font-weight:400;color:var(--text-muted);">on</span> <?= htmlspecialchars($r['PRODUCT_NAME']) ?></div>
                <div class="comment"><?= htmlspecialchars(mb_strimwidth($r['COMMENTS'], 0, 120, '...')) ?></div>
                <div class="meta"><?= date('d M Y, H:i', strtotime($r['CREATED_AT'])) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($newReviews)): ?>
                <p style="color:var(--text-light);font-size:13px;text-align:center;padding:20px 0;">No reviews yet.</p>
            <?php endif; ?>
        </div>

        <!-- SALES TREND -->
        <div class="card sales-trend">
            <div class="card-title">
                <span>Sales Trend</span>
                <span class="card-title-badge">Last 7 Days</span>
            </div>
            <canvas id="salesChart"></canvas>
        </div>

        <!-- LOW STOCK -->
        <div class="card low-stock">
            <div class="card-title">Low / Out of Stock (Variants & Addons)</div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th><th>Type</th><th>Stock</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $lowStock->fetch_assoc()):
                            $status = ($row['stock'] == 0) ? "OUT" : "LOW";
                            $badgeClass = ($row['stock'] == 0) ? "out" : "low";
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['name']) ?></strong>
                                <?php if ($row['type'] == 'VARIANT'): ?>
                                    <span style="font-size:12px; color:var(--text-muted);">
                                        (<?= htmlspecialchars($row['size']?? '')?>)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><span style="font-size:12px;color:var(--text-muted);font-weight:600;"><?= htmlspecialchars($row['type']) ?></span></td>
                            <td><strong><?= $row['stock'] ?></strong></td>
                            <td><span class="status-badge <?= $badgeClass ?>"><?= $status ?></span></td>
                            <td>
                                <button class="edit-btn" onclick="editStock('<?= $row['type'] ?>', <?= $row['id'] ?>, <?= $row['stock'] ?>)">
                                    <i class="bi bi-pencil-fill" style="font-size:10px;"></i> Edit
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- QUICK ORDERS -->
        <div class="card quick-orders">
            <div class="card-title">Quick Orders (Processing)</div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Order No</th><th>Total</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $quickOrders->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['ORDER_NO']) ?></strong></td>
                            <td><strong>RM <?= htmlspecialchars($row['TOTAL_AMOUNT']) ?></strong></td>
                            <td><span class="status-badge proc"><?= htmlspecialchars($row['ORDER_STATUS']) ?></span></td>
                            <td>
                                <a href="view_order.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                    View <i class="bi bi-arrow-right-short"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- QUICK DELIVERY -->
        <div class="card quick-delivery">
            <div class="card-title">Quick Delivery (Pending)</div>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Order No</th><th>Order Date</th><th>Shipping Method</th><th>Status</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $quickDelivery->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['ORDER_NO']) ?></strong></td>
                            <td><span style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars(date('d M Y, H:i', strtotime($row['CREATED_AT']))) ?></span></td>
                            <td><strong><?= htmlspecialchars($row['SHIPPING_METHOD']) ?></strong></td>
                            <td><span class="status-badge ready"><?= htmlspecialchars($row['DELIVERY_STATUS']) ?></span></td>
                            <td>
                                <a href="view_delivery.php?order_id=<?= $row['ORDER_ID'] ?>" class="view-btn">
                                    View <i class="bi bi-arrow-right-short"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MEMBERSHIP OVERVIEW -->
        <div class="card membership-card">
            <div class="card-title">
                <span>Membership Overview</span>
                <span class="card-title-badge">Tiers</span>
            </div>
            <canvas id="membershipChart"></canvas>
        </div>
        
        <!-- VOUCHERS -->
        <div class="card voucher-card">
            <div class="card-title">
                <span>Active Vouchers</span>
                <span class="card-title-badge">Live</span>
            </div>
            <div class="table-scroll">
                <ul class="voucher-list">
                    <?php while ($row = $vouchers->fetch_assoc()): ?>
                        <li>
                            <span><i class="bi bi-ticket-perforated-fill" style="margin-right:6px;color:var(--text-muted);"></i> <?= htmlspecialchars($row['VOUCHER_NAME']) ?></span>
                            <span class="voucher-rate-pill"><?= htmlspecialchars($row['DISCOUNT_RATE']) ?>% OFF</span>
                        </li>
                    <?php endwhile; ?>
                </ul>
            </div>
        </div>

    </div>

    <!-- Day orders popover -->
    <div id="dayOrdersPopover" class="day-orders-popover">
        <span class="popover-close" onclick="closeDayPopover()">✕</span>
        <div class="popover-title" id="dayPopoverTitle"></div>
        <div id="dayPopoverBody"></div>
        <div class="popover-footer">
            <a id="dayPopoverLink" href="manage_order.php">View All Orders →</a>
        </div>
    </div>

</div>
</div>

<!-- MODERN CHARTS JAVASCRIPT -->
<script>
// 1. Sales Trend Line Chart (Modern Dark Line + Neon Lime Gradient Fill + Glow Points)
const salesCanvas = document.getElementById('salesChart');
if (salesCanvas) {
    const salesCtx = salesCanvas.getContext('2d');
    const salesGradient = salesCtx.createLinearGradient(0, 0, 0, 220);
    salesGradient.addColorStop(0, 'rgba(212, 249, 56, 0.45)');
    salesGradient.addColorStop(0.7, 'rgba(212, 249, 56, 0.08)');
    salesGradient.addColorStop(1, 'rgba(212, 249, 56, 0.0)');

    new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($salesLabels) ?>,
            datasets: [{
                label: 'Sales (RM)',
                data: <?= json_encode($salesData) ?>,
                borderColor: '#18181b',
                borderWidth: 3,
                backgroundColor: salesGradient,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#d4f938',
                pointBorderColor: '#18181b',
                pointBorderWidth: 2,
                pointRadius: 4.5,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: '#18181b',
                pointHoverBorderColor: '#d4f938',
                pointHoverBorderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#18181b',
                    titleColor: '#d4f938',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12, weight: '700' },
                    bodyFont: { family: 'Inter', size: 13, weight: '600' },
                    padding: 12,
                    cornerRadius: 12,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return 'RM ' + parseFloat(context.parsed.y).toFixed(2);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11, weight: '500' } }
                },
                y: {
                    border: { dash: [5, 5], display: false },
                    grid: { color: '#f0f2f5', drawBorder: false },
                    ticks: {
                        color: '#9ca3af',
                        font: { family: 'Inter', size: 11 },
                        callback: function(value) { return 'RM ' + value; }
                    }
                }
            }
        }
    });
}

// 2. Best Sellers Horizontal Pill Bar Chart (Neon Lime on Top, Matte Dark on others)
const bestSellersCanvas = document.getElementById('bestSellersChart');
if (bestSellersCanvas) {
    const topLabels = <?= json_encode($topLabels) ?>;
    const topData = <?= json_encode($topData) ?>;
    const bestSellerColors = topData.map((_, idx) => idx === 0 ? '#d4f938' : '#18181b');

    new Chart(bestSellersCanvas, {
        type: 'bar',
        data: {
            labels: topLabels,
            datasets: [{
                label: 'Units Sold',
                data: topData,
                backgroundColor: bestSellerColors,
                borderRadius: 12,
                borderSkipped: false,
                maxBarThickness: 20
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#18181b',
                    titleColor: '#d4f938',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12, weight: '700' },
                    bodyFont: { family: 'Inter', size: 13 },
                    padding: 12,
                    cornerRadius: 12,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.x + ' units sold';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#f0f2f5', drawBorder: false, borderDash: [4, 4] },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11 } }
                },
                y: {
                    grid: { display: false, drawBorder: false },
                    ticks: {
                        color: '#111827',
                        font: { family: 'Inter', size: 11, weight: '600' },
                        callback: function(value) {
                            const label = this.getLabelForValue(value);
                            return label.length > 20 ? label.substring(0, 18) + '...' : label;
                        }
                    }
                }
            }
        }
    });
}

// 3. Top Rated Chart (Horizontal Pill Bar Chart)
const topRatedCanvas = document.getElementById('topRatedChart');
if (topRatedCanvas) {
    const topRatedLabels = <?= json_encode(array_column($topRated, 'PRODUCT_NAME')) ?>;
    const topRatedData = <?= json_encode(array_map(fn($r) => round($r['avgRating'], 1), $topRated)) ?>;
    const ratedColors = topRatedData.map((_, idx) => idx === 0 ? '#d4f938' : '#18181b');

    new Chart(topRatedCanvas, {
        type: 'bar',
        data: {
            labels: topRatedLabels,
            datasets: [{
                label: 'Average Rating',
                data: topRatedData,
                backgroundColor: ratedColors,
                borderRadius: 12,
                borderSkipped: false,
                maxBarThickness: 20
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    max: 5,
                    grid: { color: '#f0f2f5', drawBorder: false, borderDash: [4, 4] },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11 } }
                },
                y: {
                    grid: { display: false, drawBorder: false },
                    ticks: {
                        color: '#111827',
                        font: { family: 'Inter', size: 11, weight: '600' },
                        callback: function(value) {
                            const label = this.getLabelForValue(value);
                            return label.length > 20 ? label.substring(0, 18) + '...' : label;
                        }
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#18181b',
                    titleColor: '#d4f938',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12, weight: '700' },
                    bodyFont: { family: 'Inter', size: 13 },
                    padding: 12,
                    cornerRadius: 12,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return '⭐ ' + context.parsed.x + ' / 5.0';
                        }
                    }
                }
            }
        }
    });
}

// 4. Membership Overview Chart
const membershipCanvas = document.getElementById('membershipChart');
if (membershipCanvas) {
    const memLabels = <?= json_encode(array_column($membershipOverview, 'TIER_NAME')) ?>;
    const memData = <?= json_encode(array_column($membershipOverview, 'c')) ?>;
    const memColors = memData.map((_, idx) => idx === (memData.length - 1) ? '#d4f938' : '#18181b');

    new Chart(membershipCanvas, {
        type: 'bar',
        data: {
            labels: memLabels,
            datasets: [{
                label: 'Customers',
                data: memData,
                backgroundColor: memColors,
                borderRadius: 12,
                borderSkipped: false,
                maxBarThickness: 22
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#18181b',
                    titleColor: '#d4f938',
                    bodyColor: '#ffffff',
                    titleFont: { family: 'Plus Jakarta Sans', size: 12, weight: '700' },
                    bodyFont: { family: 'Inter', size: 13 },
                    padding: 12,
                    cornerRadius: 12,
                    displayColors: false,
                    callbacks: {
                        label: function(context) {
                            return context.parsed.x + ' Members';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#f0f2f5', drawBorder: false, borderDash: [4, 4] },
                    ticks: { color: '#9ca3af', font: { family: 'Inter', size: 11 } }
                },
                y: {
                    grid: { display: false, drawBorder: false },
                    ticks: { color: '#111827', font: { family: 'Inter', size: 11, weight: '600' } }
                }
            }
        }
    });
}
</script>

<!-- QUICK UPDATE STOCK SCRIPT -->
<script>
function editStock(type, id, currentStock) {
    let newStock = prompt("Enter new stock:", currentStock);
    if (newStock === null) return;
    newStock = newStock.trim();
    if (newStock === "") {
        alert("Stock cannot be empty.");
        return;
    }
    if (!/^\d+$/.test(newStock)) {
        alert("Stock must be a valid whole number.");
        return;
    }
    newStock = parseInt(newStock);
    if (newStock < 0) {
        alert("Stock cannot be negative.");
        return;
    }
    if (newStock > 9999) {
        alert("Stock is too large.");
        return;
    }

    fetch("quick_update_stock.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "type=" + encodeURIComponent(type) + "&id=" + encodeURIComponent(id) + "&stock=" + encodeURIComponent(newStock)
    })
    .then(res => res.text())
    .then(() => {
        alert("Stock updated successfully!");
        location.reload();
    });
}
</script>

<!-- CALENDAR AND POPOVER SCRIPT -->
<script>
const ordersByDate = <?= json_encode($ordersByDate) ?>;

const statusLabel = {
    PROCESSING: 'Processing',
    READY: 'Ready',
    COMPLETED: 'Completed',
    CANCELLED: 'Cancelled'
};

function closeDayPopover() {
    document.getElementById('dayOrdersPopover').classList.remove('show');
}

function openDayPopover(dateStr, anchorEl) {
    const orders = ordersByDate[dateStr] || [];
    const popover = document.getElementById('dayOrdersPopover');
    const title   = document.getElementById('dayPopoverTitle');
    const body    = document.getElementById('dayPopoverBody');
    const link    = document.getElementById('dayPopoverLink');

    title.textContent = orders.length + ' Order' + (orders.length !== 1 ? 's' : '') + ' — ' + dateStr;

    const MAX_SHOW = 5;
    const shown = orders.slice(0, MAX_SHOW);

    body.innerHTML = shown.map(o => `
        <div class="popover-row">
            <span class="po-no">${escapeHtml(o.no)}</span>
            <span class="po-amt">RM ${o.amount.toFixed(2)}</span>
            <span class="po-status ${o.status}">${statusLabel[o.status] || o.status}</span>
        </div>
    `).join('') || `<p style="font-size:13px;color:#999;">No orders on this date.</p>`;

    if (orders.length > MAX_SHOW) {
        body.innerHTML += `<div class="popover-more">+ ${orders.length - MAX_SHOW} more</div>`;
    }

    link.href = 'manage_order.php?date=' + encodeURIComponent(dateStr);

    const rect = anchorEl.getBoundingClientRect();
    const scrollY = window.scrollY || document.documentElement.scrollTop;
    const scrollX = window.scrollX || document.documentElement.scrollLeft;
    popover.style.top  = (rect.bottom + scrollY + 6) + 'px';
    popover.style.left = (rect.left + scrollX) + 'px';

    popover.classList.add('show');
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('orderCalendar');
    if (calendarEl) {
        const calendar = new FullCalendar.Calendar(calendarEl, {
            initialView: 'dayGridMonth',
            height: 420,
            events: <?= json_encode($orderCalEvents) ?>,
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                info.jsEvent.stopPropagation();
                openDayPopover(info.event.startStr, info.el);
            }
        });
        calendar.render();
    }

    document.addEventListener('click', function (e) {
        const popover = document.getElementById('dayOrdersPopover');
        if (popover && !popover.contains(e.target) && !e.target.closest('.fc-daygrid-event')) {
            popover.classList.remove('show');
        }
    });
});
</script>
</body>
</html>