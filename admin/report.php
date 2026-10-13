<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "Sales Report";

// Get inputs (filter)
$type = $_GET['type'] ?? 'weekly';
$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;

$sql = "";

// WEEKLY REPORT
if ($type === 'weekly') {

    $sql = "
        SELECT 
            YEARWEEK(CREATED_AT, 1) AS period_key,
            MIN(DATE(CREATED_AT)) AS week_start,
            MAX(DATE(CREATED_AT)) AS week_end,
            COUNT(ORDER_ID) AS total_orders,
            COALESCE(SUM(TOTAL_AMOUNT),0) AS total_sales,
            COALESCE(SUM(TOTAL_AMOUNT),0) / NULLIF(COUNT(ORDER_ID),0) AS avg_order_value
        FROM orders
        WHERE ORDER_STATUS = 'COMPLETED'
        GROUP BY YEARWEEK(CREATED_AT, 1)
        ORDER BY period_key DESC
    ";

// MONTHLY REPORT
} elseif ($type === 'monthly') {

    $sql = "
        SELECT 
            DATE_FORMAT(CREATED_AT, '%Y-%m') AS period,
            COUNT(ORDER_ID) AS total_orders,
            COALESCE(SUM(TOTAL_AMOUNT),0) AS total_sales,
            COALESCE(SUM(TOTAL_AMOUNT),0) / NULLIF(COUNT(ORDER_ID),0) AS avg_order_value
        FROM orders
        WHERE ORDER_STATUS = 'COMPLETED'
        GROUP BY DATE_FORMAT(CREATED_AT, '%Y-%m')
        ORDER BY period DESC
    ";

// CUSTOM RANGE
} elseif ($type === 'custom' && $from && $to) {

    $sql = "
        SELECT 
            DATE(CREATED_AT) AS period,
            COUNT(ORDER_ID) AS total_orders,
            COALESCE(SUM(TOTAL_AMOUNT),0) AS total_sales,
            COALESCE(SUM(TOTAL_AMOUNT),0) / NULLIF(COUNT(ORDER_ID),0) AS avg_order_value
        FROM orders
        WHERE ORDER_STATUS = 'COMPLETED'
        AND CREATED_AT BETWEEN ? AND ?
        GROUP BY DATE(CREATED_AT)
        ORDER BY period DESC
    ";

    $fromDT = $from . " 00:00:00";
    $toDT   = $to . " 23:59:59";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $fromDT, $toDT);
    $stmt->execute();
    $result = $stmt->get_result();

} else {
    die("Invalid report type");
}

// EXECUTE (NON-CUSTOM)
if ($type !== 'custom') {
    $result = $conn->query($sql);
}

// PRODUCTS SOLD PER PERIOD
$productsByPeriod = [];

$periodExprMap = [
    'weekly'  => "YEARWEEK(o.CREATED_AT, 1)",
    'monthly' => "DATE_FORMAT(o.CREATED_AT, '%Y-%m')",
    'custom'  => "DATE(o.CREATED_AT)"
];
$periodExpr = $periodExprMap[$type];

$pSql = "
    SELECT 
        $periodExpr AS period_key,
        COALESCE(oi.PRODUCT_NAME_SNAPSHOT, 'Unknown product') AS PRODUCT_NAME,
        SUM(oi.QUANTITY) AS qty_sold,
        SUM(oi.QUANTITY * COALESCE(oi.VARIANT_PRICE_SNAPSHOT, 0)) AS revenue
    FROM orders o
    JOIN ORDER_ITEM oi ON o.ORDER_ID = oi.ORDER_ID
    WHERE o.ORDER_STATUS = 'COMPLETED'
";

if ($type === 'custom') {
    $pSql .= " AND o.CREATED_AT BETWEEN ? AND ? ";
}

$pSql .= "
    GROUP BY period_key, PRODUCT_NAME
    ORDER BY period_key DESC, qty_sold DESC
";

if ($type === 'custom') {
    $pStmt = $conn->prepare($pSql);
    $pStmt->bind_param("ss", $fromDT, $toDT);
    $pStmt->execute();
    $pResult = $pStmt->get_result();
} else {
    $pResult = $conn->query($pSql);
}

if ($pResult) {
    while ($p = $pResult->fetch_assoc()) {
        $productsByPeriod[(string)$p['period_key']][] = $p;
    }
}

// EXPORT CSV
if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report.csv"');

    $output = fopen("php://output", "w");

    if ($type === 'weekly') {
        fputcsv($output, ['Week','Start Date','End Date','Total Orders','Total Sales (RM)','Avg Order Value (RM)','Products Sold']);
    } else {
        fputcsv($output, ['Period','Total Orders','Total Sales (RM)','Avg Order Value (RM)','Products Sold']);
    }

    $exportResult = ($type === 'custom') ? $result : $conn->query($sql);

    while ($row = $exportResult->fetch_assoc()) {

        $key = (string)($row['period'] ?? $row['period_key']);
        $items = [];
        foreach ($productsByPeriod[$key] ?? [] as $p) {
            $items[] = $p['PRODUCT_NAME'] . ' x' . $p['qty_sold'];
        }
        $productsText = implode('; ', $items);

        if ($type === 'weekly') {
            fputcsv($output, [
                $row['period_key'],
                $row['week_start'],
                $row['week_end'],
                $row['total_orders'],
                number_format($row['total_sales'], 2),
                number_format($row['avg_order_value'], 2),
                $productsText
            ]);
        } else {
            fputcsv($output, [
                $row['period'] ?? $row['period_key'],
                $row['total_orders'],
                number_format($row['total_sales'], 2),
                number_format($row['avg_order_value'], 2),
                $productsText
            ]);
        }
    }

    fclose($output);
    exit();
}

// Buffer rows for summary metrics calculation
$reportRows = [];
$totalRevenue = 0;
$totalOrders = 0;

if ($result && $result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {
        $reportRows[] = $r;
        $totalRevenue += (float)$r['total_sales'];
        $totalOrders += (int)$r['total_orders'];
    }
}
$overallAov = $totalOrders > 0 ? ($totalRevenue / $totalOrders) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report | Admin</title>
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
    font-size: 24px;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.2;
    margin-bottom: 4px;
}

.mgmt-sum-card-sub {
    font-size: 12px;
    color: var(--text-light);
}

.mgmt-sum-card.highlight-revenue {
    border-color: rgba(212, 249, 56, 0.6);
    background: linear-gradient(135deg, #ffffff 70%, var(--accent-lime-subtle) 100%);
}

/* Filter Controls Toolbar */
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

.period-pills {
    display: flex;
    align-items: center;
    gap: 6px;
    background: #f4f5f8;
    padding: 4px;
    border-radius: var(--radius-pill);
}

.period-pill-item {
    padding: 6px 16px;
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

.period-pill-item:hover {
    color: var(--text-dark) !important;
}

.period-pill-item.active {
    background: var(--dark-pill);
    color: #ffffff !important;
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.custom-range-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.date-input-wrap {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #f8fafc;
    border: 1px solid var(--border-card);
    padding: 5px 12px;
    border-radius: var(--radius-pill);
    font-size: 13px;
}

.date-input-wrap i {
    color: var(--text-muted);
}

.date-input-wrap input[type="date"] {
    border: none;
    background: transparent;
    font-size: 12.5px;
    font-family: var(--font-body);
    color: var(--text-dark);
    outline: none;
    cursor: pointer;
}

.btn-filter-apply {
    background: var(--dark-pill);
    color: #ffffff !important;
    border: none;
    padding: 7px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-filter-apply i
{
    color: #ffffff !important;
}
.btn-filter-apply:hover {
    background: var(--dark-pill-hover);
}

/* Report Table Container */
.report-table-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}

.report-table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.report-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    text-align: left;
}

.report-table th {
    background: #fcfdfe;
    padding: 14px 20px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border-card);
    white-space: nowrap;
}

.report-table td {
    padding: 16px 20px;
    font-size: 13.5px;
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
}

.report-table tbody tr {
    transition: background-color 0.15s ease;
}

.report-table tbody tr:hover {
    background-color: #fafbfd;
}

.report-table tbody tr:last-child td {
    border-bottom: none;
}

/* Period Label */
.period-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
    color: var(--text-dark);
    background: #f4f5f8;
    padding: 5px 12px;
    border-radius: var(--radius-pill);
    font-size: 13px;
}

.period-badge i {
    color: var(--text-muted);
}

.week-range-text {
    font-size: 12.5px;
    color: var(--text-muted);
    white-space: nowrap;
}

/* Numbers & Highlights */
.orders-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: #f1f5f9;
    color: #334155 !important;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
    font-weight: 700;
    font-size: 12.5px;
}

.sales-val {
    font-weight: 700;
    font-size: 14px;
    color: var(--text-dark);
}

.avg-val {
    font-weight: 600;
    color: var(--text-muted);
    font-size: 13.5px;
}

/* Products Sold Chips */
.products-sold-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.product-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f8fafc;
    border: 1px solid var(--border-subtle);
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 12.5px;
    max-width: fit-content;
}

.product-name {
    font-weight: 600;
    color: var(--text-dark);
}

.product-qty-badge {
    background: var(--dark-pill);
    color: #ffffff !important;
    font-size: 11px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: var(--radius-pill);
}

.product-revenue {
    color: #059669;
    font-size: 11.5px;
    font-weight: 600;
}

.more-products-badge {
    font-size: 11.5px;
    color: var(--text-muted);
    font-style: italic;
    padding-left: 4px;
}

/* Empty State */
.empty-state {
    padding: 60px 20px;
    text-align: center;
}

.empty-icon {
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

.empty-icon i {
    color: var(--text-light) !important;
}

.empty-title {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 6px;
}

.empty-desc {
    font-size: 13px;
    color: var(--text-muted);
    max-width: 380px;
    margin: 0 auto;
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
                        <i class="bi bi-graph-up-arrow"></i>
                        <span class="badge-arrow"><i class="bi bi-bar-chart-fill"></i></span>
                    </div>
                    <div>
                        <h1 class="dash-title">Sales <strong>Analytics & Reports</strong></h1>
                        <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                            Performance breakdown across weekly, monthly, and custom intervals
                        </div>
                    </div>
                </div>

                <div class="dash-header-controls">
                    <a class="btn-ctrl-action btn-dark-pill" 
                       href="?type=<?= urlencode($type) ?>&from=<?= urlencode($from ?? '') ?>&to=<?= urlencode($to ?? '') ?>&export=excel" 
                       title="Download full report as CSV file">
                        <i class="bi bi-file-earmark-spreadsheet-fill"></i> Export CSV
                    </a>
                </div>
            </div>

            <!-- METRIC CARDS -->
            <div class="mgmt-sum-card-cont">
                <div class="mgmt-sum-card highlight-revenue">
                    <div class="mgmt-sum-card-top">
                        <span class="mgmt-sum-card-title">Gross Revenue</span>
                        <div class="mgmt-sum-card-icon" style="background: var(--accent-lime); color: var(--text-dark);">
                            <i class="bi bi-currency-dollar" style="color:var(--text-dark)!important;"></i>
                        </div>
                    </div>
                    <div class="mgmt-sum-card-value">RM <?= number_format($totalRevenue, 2) ?></div>
                    <div class="mgmt-sum-card-sub">Completed orders in current view</div>
                </div>

                <div class="mgmt-sum-card">
                    <div class="mgmt-sum-card-top">
                        <span class="mgmt-sum-card-title">Completed Orders</span>
                        <div class="mgmt-sum-card-icon" style="background: #ecfdf5; color: #059669;">
                            <i class="bi bi-bag-check-fill" style="color:#059669!important;"></i>
                        </div>
                    </div>
                    <div class="mgmt-sum-card-value"><?= number_format($totalOrders) ?></div>
                    <div class="mgmt-sum-card-sub">Successfully fulfilled sales</div>
                </div>

                <div class="mgmt-sum-card">
                    <div class="mgmt-sum-card-top">
                        <span class="mgmt-sum-card-title">Avg. Order Value (AOV)</span>
                        <div class="mgmt-sum-card-icon" style="background: #eff6ff; color: #2563eb;">
                            <i class="bi bi-wallet2" style="color:#2563eb!important;"></i>
                        </div>
                    </div>
                    <div class="mgmt-sum-card-value">RM <?= number_format($overallAov, 2) ?></div>
                    <div class="mgmt-sum-card-sub">Average ticket size</div>
                </div>

                <div class="mgmt-sum-card">
                    <div class="mgmt-sum-card-top">
                        <span class="mgmt-sum-card-title">Reporting Window</span>
                        <div class="mgmt-sum-card-icon">
                            <i class="bi bi-calendar3"></i>
                        </div>
                    </div>
                    <div class="mgmt-sum-card-value" style="font-size: 20px;">
                        <?= ucfirst($type) ?>
                    </div>
                    <div class="mgmt-sum-card-sub">
                        <?= $type === 'custom' && $from && $to ? htmlspecialchars("$from to $to") : 'Aggregated intervals' ?>
                    </div>
                </div>
            </div>

            <!-- FILTER CONTROLS TOOLBAR -->
            <div class="filter-card">
                <div class="period-pills">
                    <a href="?type=weekly" class="period-pill-item <?= $type === 'weekly' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-week"></i> Weekly
                    </a>
                    <a href="?type=monthly" class="period-pill-item <?= $type === 'monthly' ? 'active' : '' ?>">
                        <i class="bi bi-calendar-month"></i> Monthly
                    </a>
                </div>

                <!-- CUSTOM DATE RANGE FORM -->
                <form method="GET" class="custom-range-form">
                    <input type="hidden" name="type" value="custom">

                    <div class="date-input-wrap">
                        <i class="bi bi-calendar-event"></i>
                        <input type="date" name="from" value="<?= htmlspecialchars($from ?? '') ?>" required title="From Date">
                    </div>

                    <span style="color: var(--text-light); font-size: 13px;">to</span>

                    <div class="date-input-wrap">
                        <i class="bi bi-calendar-event"></i>
                        <input type="date" name="to" value="<?= htmlspecialchars($to ?? '') ?>" required title="To Date">
                    </div>

                    <button type="submit" class="btn-filter-apply">
                        <i class="bi bi-funnel-fill"></i> Filter Range
                    </button>
                </form>
            </div>

            <!-- REPORT DATA TABLE -->
            <div class="report-table-card">
                <div class="report-table-wrapper">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Period</th>
                                <?php if ($type === 'weekly'): ?>
                                    <th>Week Range</th>
                                <?php endif; ?>
                                <th>Total Orders</th>
                                <th>Total Sales</th>
                                <th>Avg. Order Value</th>
                                <th>Top Products Sold</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reportRows)): ?>
                                <?php foreach ($reportRows as $row): 
                                    $key = (string)($row['period'] ?? $row['period_key']);
                                ?>
                                    <tr>
                                        <td>
                                            <div class="period-badge">
                                                <i class="bi bi-calendar4-event"></i>
                                                <?= htmlspecialchars($row['period'] ?? $row['period_key']) ?>
                                            </div>
                                        </td>

                                        <?php if ($type === 'weekly'): ?>
                                            <td>
                                                <span class="week-range-text">
                                                    <?= date("d M Y", strtotime($row['week_start'])) ?> ~ <?= date("d M Y", strtotime($row['week_end'])) ?>
                                                </span>
                                            </td>
                                        <?php endif; ?>

                                        <td>
                                            <span class="orders-pill">
                                                <i class="bi bi-receipt"></i>
                                                <?= (int)$row['total_orders'] ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="sales-val">
                                                RM <?= number_format($row['total_sales'], 2) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <span class="avg-val">
                                                RM <?= number_format($row['avg_order_value'], 2) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php if (!empty($productsByPeriod[$key])): ?>
                                                <div class="products-sold-list">
                                                    <?php foreach (array_slice($productsByPeriod[$key], 0, 3) as $p): ?>
                                                        <div class="product-chip">
                                                            <span class="product-name"><?= htmlspecialchars($p['PRODUCT_NAME']) ?></span>
                                                            <span class="product-qty-badge">× <?= (int)$p['qty_sold'] ?></span>
                                                            <span class="product-revenue">RM <?= number_format($p['revenue'], 2) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>

                                                    <?php $extra = count($productsByPeriod[$key]) - 3; ?>
                                                    <?php if ($extra > 0): ?>
                                                        <div class="more-products-badge">
                                                            + <?= $extra ?> more item<?= $extra > 1 ? 's' : '' ?> (exported in CSV)
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-light); font-size: 13px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="<?= $type === 'weekly' ? '6' : '5' ?>">
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class="bi bi-bar-chart"></i>
                                            </div>
                                            <div class="empty-title">No sales records found</div>
                                            <div class="empty-desc">
                                                There are no completed orders recorded for this period. Try switching to a different time range or weekly/monthly view.
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>
</div>

</body>
</html>