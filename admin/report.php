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

// EXPORT CSV
if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sales_report.csv"');

    $output = fopen("php://output", "w");

    if ($type === 'weekly') {
        fputcsv($output, [
            'Week',
            'Start Date',
            'End Date',
            'Total Orders',
            'Total Sales (RM)',
            'Avg Order Value (RM)'
        ]);
    } else {
        fputcsv($output, [
            'Period',
            'Total Orders',
            'Total Sales (RM)',
            'Avg Order Value (RM)'
        ]);
    }

    $exportResult = ($type === 'custom') ? $result : $conn->query($sql);

    while ($row = $exportResult->fetch_assoc()) {

        if ($type === 'weekly') {
            fputcsv($output, [
                $row['period_key'],
                $row['week_start'],
                $row['week_end'],
                $row['total_orders'],
                number_format($row['total_sales'], 2),
                number_format($row['avg_order_value'], 2)
            ]);
        } else {
            fputcsv($output, [
                $row['period'] ?? $row['period_key'],
                $row['total_orders'],
                number_format($row['total_sales'], 2),
                number_format($row['avg_order_value'], 2)
            ]);
        }
    }

    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Report</title>
    <link rel="stylesheet" href="admin_global.css">
<style>
body {
    font-family: var(--font-family);
    background: var(--primary-grey);
}

.container {
    padding: 24px;
}

h2 {
    text-align: center;
    margin-bottom: 20px;
}

.filter {
    text-align: center;
    margin-bottom: 20px;
}

.filter a {
    margin: 0 10px;
    text-decoration: none;
    font-weight: 600;
    color: #111827;
}

form {
    text-align: center;
    margin-bottom: 20px;
}

input {
    padding: 6px 10px;
    margin: 0 5px;
}

button {
    padding: 6px 12px;
    background: var(--primary-dark);
    color: var(--primary-white);
    border: none;
    cursor: pointer;
    border-radius: 6px;
}

table {
    width: 90%;
    margin: auto;
    border-collapse: collapse;
    background: white;
    border-radius: 10px;
    overflow: hidden;
}

th, td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: center;
}

th {
    background: #f9fafb;
    font-size: 12px;
    text-transform: uppercase;
}

/* Active tab */
.filter a {
    margin: 0 10px;
    text-decoration: none;
    font-weight: 600;
    color: #111827;
    padding: 8px 14px;
    border-radius: 8px;
    transition: 0.2s;
}

.filter a:hover {
    background: #e5e7eb;
}

.filter a.active {
    background: var(--primary-dark);
    color: var(--primary-white);
}
</style>
</head>

<body>
    
<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>

<div class="main container">

    <h2>Sales Report (<?= ucfirst($type) ?>)</h2>

    <!-- FILTER -->
    <div class="filter">
        <a href="?type=weekly" class="<?= $type === 'weekly' ? 'active' : '' ?>">
            Weekly
        </a>

        <a href="?type=monthly" class="<?= $type === 'monthly' ? 'active' : '' ?>">
            Monthly
        </a>

        <a href="?type=<?= $type ?>&from=<?= urlencode($from ?? '') ?>&to=<?= urlencode($to ?? '') ?>&export=excel">
            Export CSV
        </a>
    </div>

    <!-- CUSTOM FILTER -->
    <form method="GET">
        <input type="hidden" name="type" value="custom">

        <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" required>
        <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" required>

        <button type="submit">Filter</button>
    </form>

    <!-- TABLE -->
    <table>
        <tr>
            <th>Period</th>

            <?php if ($type === 'weekly') echo "<th>Week Range</th>"; ?>

            <th>Total Orders</th>
            <th>Total Sales (RM)</th>
            <th>Avg Order (RM)</th>
        </tr>

        <?php if ($result && $result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['period'] ?? $row['period_key'] ?></td>

                    <?php if ($type === 'weekly'): ?>
                        <td><?= $row['week_start'] ?> ~ <?= $row['week_end'] ?></td>
                    <?php endif; ?>

                    <td><?= (int)$row['total_orders'] ?></td>
                    <td>RM <?= number_format($row['total_sales'], 2) ?></td>
                    <td>RM <?= number_format($row['avg_order_value'], 2) ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
 
            <tr>
                <td colspan="5">No data found</td>
            </tr>
        <?php endif; ?>
    </table>

</div>
</div>

</body>
</html>