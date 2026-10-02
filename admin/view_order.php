<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get order id
$orderId = $_GET['order_id'] ?? 0;

if (!$orderId || !is_numeric($orderId)) {
    die("Invalid Order ID");
}
$orderId = (int)$orderId;

// Get order info
$sql = "
SELECT 
    o.*,
    s.DELIVERY_STATUS,
    s.SHIPPING_DATE,
    s.SHIPPING_TIME,
    s.SHIPPING_METHOD,
    py.PAYMENT_METHODS,
    py.PAYMENT_AMOUNT,
    py.PAYMENT_STATUS,
    py.TRANSACTION_DATE
FROM orders o
JOIN shipping s 
    ON o.SHIPPING_ID = s.SHIPPING_ID
LEFT JOIN payment py 
    ON o.PAYMENT_ID = py.PAYMENT_ID
WHERE o.ORDER_ID = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// GET order items + addon info
$itemSql = "
SELECT 
    oi.ORDER_ITEM_ID,
    oi.PRODUCT_ID,
    oi.QUANTITY,
    oi.CARD_TEXT,
    oi.PRODUCT_NAME_SNAPSHOT,
    oi.VARIANT_LABEL_SNAPSHOT,
    oi.VARIANT_PRICE_SNAPSHOT,
    oi.WARRANTY_SNAPSHOT,

    p.COVER_IMAGE,

    oia.ORDER_ITEM_ADDON_ID,
    oia.QUANTITY AS ADDON_QTY,
    oia.ADDON_NAME_SNAPSHOT,
    oia.ADDON_PRICE_SNAPSHOT

FROM order_item oi

LEFT JOIN product p
    ON oi.PRODUCT_ID = p.PRODUCT_ID

LEFT JOIN order_item_addon oia
    ON oi.ORDER_ITEM_ID = oia.ORDER_ITEM_ID

WHERE oi.ORDER_ID = ?
ORDER BY oi.ORDER_ITEM_ID
";

$stmt2 = $conn->prepare($itemSql);
$stmt2->bind_param("i", $orderId);
$stmt2->execute();
$result = $stmt2->get_result();

$groupedItems = [];

while ($row = $result->fetch_assoc()) {
    $id = $row['ORDER_ITEM_ID'];

    if (!isset($groupedItems[$id])) {
        $groupedItems[$id] = $row;
        $groupedItems[$id]['addons'] = [];
    }

    if (!empty($row['ADDON_NAME_SNAPSHOT'])) {
        $groupedItems[$id]['addons'][] = [
            'name' => $row['ADDON_NAME_SNAPSHOT'],
            'price' => $row['ADDON_PRICE_SNAPSHOT'],
            'qty' => $row['ADDON_QTY']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Order Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
body{
    background:#f5f7fb;
}

/* LAYOUT */
.order-layout{
    display:grid;
    grid-template-columns: 2fr 1.3fr;
    gap:14px;
    align-items:start;
}

.left-column,
.right-column{
    display:flex;
    flex-direction:column;
}

/* CARD  */
.section{
    background:#fff;
    border-radius:16px;
    padding:18px;
    box-shadow:0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 15px;
}

.section h3{
    margin:0 0 18px;
    font-size:18px;
    font-weight:700;
    color:#222;
}

/* HEADER  */
.order-header{
    background:#fff;
    border-radius:16px;
    padding:20px 22px;
    margin-bottom:14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    box-shadow:0 2px 10px rgba(0,0,0,0.05);
}

.order-title{
    font-size:20px;
    font-weight:700;
    color:#222;
}

.order-meta{
    margin-top:6px;
    color:#777;
    font-size:13px;
}

.order-actions{
    display:flex;
    gap:10px;
    align-items:center;
}

.action-btn{
    padding:3px 10px;
    border:1px solid var(--primary-light);
    border-radius:50px;
    color:var(--text-primary);
    cursor:pointer;
    text-decoration:none;
    font-size:14px;
}

/* INFO ROW */
.row{
    display:flex;
    justify-content:space-between;
    gap:20px;
    padding:10px 0;
    border-bottom:1px solid #f0f0f0;
}

.row:last-child{
    border-bottom:none;
}

.label{
    color:#666;
    font-weight:600;
    min-width:160px;
}

.row span:last-child{
    text-align:right;
    font-weight:400;
    color:#222;
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    max-width: 100%;
}

/* ORDER ITEMS */
.item-row{
    display:flex;
    align-items:flex-start;
    gap:14px;
    padding:12px 0;
    border-bottom:1px solid #f0f0f0;
}

.item-row:last-child{
    border-bottom:none;
}

.item-img{
    width:60px;
    height:60px;
    object-fit:cover;
    border-radius:10px;
    flex-shrink:0;
    border:1px solid #eee;
}

.item-details{
    flex:1;
}

.item-name{
    font-size:15px;
    font-weight:700;
    color:#1a1a1a;
    margin:0 0 4px;
    line-height:1.4;
}

.item-meta{
    font-size:13px;
    color:#666;
    margin:2px 0;
    line-height:1.5;
}

.item-quantity{
    font-size:14px;
    color:#444;
    min-width:35px;
    text-align:right;
    padding-top:2px;
    font-weight:600;
}

.item-price{
    font-size:14px;
    font-weight:500;
    color:#111;
    min-width:90px;
    text-align:right;
    padding-top:2px;
}

/* ADDONS */
.addon-list{
    margin-top:6px;
}

.addon-tag{
    display:inline-block;
    background:#f5f5f5;
    padding:4px 8px;
    border-radius:6px;
    margin-bottom:6px;
    font-size:12px;
    color:#444;
}

/* SUMMARY */
.summary-section{
    padding-top:10px;
    margin-top:10px;
    border-top:1px solid #f0f0f0;
}

.summary-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:10px;
    font-size:14px;
    color:#4a4a4a;
}

.summary-row.discount{
    color:#3f7813;
}

.summary-row.total{
    margin-top:14px;
    padding-top:14px;
    border-top:1px solid #f0f0f0;
    font-size:18px;
    font-weight:700;
    color:#000;
}

/*  PAYMENT  */
.payment-summary{
    background:linear-gradient(135deg,#ffffff,#f8fbff);
}
</style>

<body>

<div class="form-wrapper">

    <a href="manage_order.php" class="form-back-link" title="Back To Order Page">← Back</a>

    <!--  HEADER  -->
    <div class="order-header">
        <div>
            <div class="order-title">
                Order #<?= htmlspecialchars($order['ORDER_NO']) ?>
            </div>

            <div class="order-meta">
                Created on <?= date("d M Y h:i A", strtotime($order['CREATED_AT'])) ?>
            </div>
        </div>

        <div class="order-actions">

            <span class="badge status-<?= strtolower($order['ORDER_STATUS']) ?>">
                <?= htmlspecialchars($order['ORDER_STATUS']) ?>
            </span>

        </div>
    </div>

    <!--  MAIN LAYOUT  -->
    <div class="order-layout">
        <!-- LEFT COLUMN -->
        <div class="left-column">

        <div class="section">

            <div>
                <h3>Order Items</h3>

                <?php foreach ($groupedItems as $item): ?>

                    <?php
                        $itemTotal = $item['QUANTITY'] * $item['VARIANT_PRICE_SNAPSHOT'];

                        $addonTotal = 0;

                        if (!empty($item['addons'])) {
                            foreach ($item['addons'] as $addon) {
                                $addonTotal += $addon['price'] * $addon['qty'];
                            }
                        }

                        $itemTotal += $addonTotal;
                    ?>

                    <div class="item-row">

                        <img src="<?= htmlspecialchars($item['COVER_IMAGE'] ?? '') ?>" class="item-img"/>
                        <div class="item-details">
                            <p class="item-name">
                                <a href="view_product.php?product_id=<?= (int)$item['PRODUCT_ID'] ?>">
                                    <?= htmlspecialchars($item['PRODUCT_NAME_SNAPSHOT']) ?>
                                </a>
                            </p>
                            <p class="item-meta">Variant: <?= htmlspecialchars($item['VARIANT_LABEL_SNAPSHOT'] ?? 'N/A') ?></p>

                            <?php if (!empty($item['WARRANTY_SNAPSHOT'])): ?>
                                <p class="item-meta">Warranty: <?= (int)$item['WARRANTY_SNAPSHOT'] ?> month(s)</p>
                            <?php endif; ?>

                            <?php if (!empty($item['CARD_TEXT'])): ?>
                                <p class="item-meta">Note: <?= nl2br(htmlspecialchars($item['CARD_TEXT'])) ?></p>
                            <?php endif; ?>

                            <!-- ADDONS -->
                            <?php if (!empty($item['addons'])): ?>
                                <div class="addon-list">
                                    <?php foreach ($item['addons'] as $addon): ?>
                                        <span class="item-meta addon-tag">
                                            + <?= htmlspecialchars($addon['name']) ?>
                                            (×<?= (int)$addon['qty'] ?>) —
                                            RM <?= number_format($addon['price'] * $addon['qty'], 2) ?>
                                        </span>
                                        <br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>

                        <div class="item-quantity">
                            × <?= (int)$item['QUANTITY'] ?>
                        </div>

                        <div class="item-price">
                            RM <?= number_format($item['VARIANT_PRICE_SNAPSHOT'], 2) ?> / unit
                        </div>

                        <div class="item-price">
                            <b>RM <?= number_format($itemTotal, 2) ?></b>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

                <?php
                    $subtotal = $order['SUB_TOTAL'] ?? 0;
                    $rate = $order['DISCOUNT_RATE_SNAPSHOT'] ?? 0;
                    $discountAmount = $order['DISCOUNT_AMOUNT_SNAPSHOT'] ?? 0;
                    $totalPaid = $order['TOTAL_AMOUNT'] ?? 0;
                ?>

                <div class="summary-section">

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <span>RM <?= number_format($subtotal, 2) ?></span>
                    </div>

                    <div class="summary-row">
                        <span>Shipping fee</span>
                        <span>+ RM <?= number_format($order['SHIPPING_FEE_SNAPSHOT'],2) ?></span>
                    </div>

                    <?php if ($discountAmount > 0): ?>
                        <div class="summary-row discount">
                            <span>Discount
                            <?php if (!empty($order['VOUCHER_CODE_SNAPSHOT'])): ?>
                                <small>
                                   (
                                    <strong><?= htmlspecialchars($order['VOUCHER_CODE_SNAPSHOT']) ?></strong>
                                    <?php if (!empty($order['VOUCHER_NAME_SNAPSHOT'])): ?>
                                        <span style="color:#666;">
                                            - <?= htmlspecialchars($order['VOUCHER_NAME_SNAPSHOT']) ?>
                                        </span>
                                    <?php endif; ?>
                                    )
                                </small>
                            <?php endif; ?>
                            </span>

                            <span>
                            - RM <?= number_format($discountAmount, 2) ?>
                            <?php if ($rate > 0): ?>
                            (<?= $rate ?>%)
                            <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="summary-row total">
                        <span>Total</span>
                        <span>RM <?= number_format($totalPaid,2) ?></span>
                    </div>
                </div>

            </div>

            <!--  SHIPPING INFO  -->
            <div class="section">

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Shipping Info</h3>

                    <a class="action-btn" href="view_delivery.php?order_id=<?= $orderId ?>">View</a>
                </div>

                <div class="row">
                    <span class="label">Shipping Method</span>
                    <span><?= htmlspecialchars($order['SHIPPING_METHOD']) ?></span>
                </div>

                <div class="row">
                    <span class="label">Delivery Address</span>
                    <span><?= nl2br(htmlspecialchars($order['DELIVERY_ADDRESS_SNAPSHOT'] ?? '')) ?></span>
                </div>

                <div class="row">
                    <span class="label">Delivery Status</span>
                    <span class="badge status-<?= strtolower(str_replace(' ','-', $order['DELIVERY_STATUS'])) ?>">
                        <?= htmlspecialchars($order['DELIVERY_STATUS']) ?>
                    </span>
                </div>

                <?php if (!empty($order['READY_AT'])): ?>
                <div class="row">
                    <span class="label">Ready At</span>
                    <span><?= date("d M Y h:i A", strtotime($order['READY_AT'])) ?></span>
                </div>
                <?php endif; ?>

                <div class="row">
                    <span class="label">Actual Delivery Date</span>
                    <span><?= !empty($order['SHIPPING_DATE']) ? date("d M Y", strtotime($order['SHIPPING_DATE'])) : 'N/A' ?></span>
                </div>

                <div class="row">
                    <span class="label">Actual Delivery Time</span>
                    <span><?= !empty($order['SHIPPING_TIME']) ? date("h:i A", strtotime($order['SHIPPING_TIME'])) : 'N/A' ?></span>
                </div>

            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="right-column">

            <!--  CUSTOMER INFO  -->
            <div class="section">

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Customer Info</h3>

                    <a class="action-btn" href="view_customer.php?customer_id=<?= (int)$order['CUSTOMER_ID'] ?>">View</a>
                </div>

                <div class="row">
                    <span class="label">Customer Name</span>
                    <span><?= htmlspecialchars($order['CUSTOMER_NAME_SNAPSHOT']) ?></span>
                </div>

                <div class="row">
                    <span class="label">Email Address</span>
                    <span><?= htmlspecialchars($order['CUSTOMER_EMAIL_SNAPSHOT']) ?></span>
                </div>

                <div class="row">
                    <span class="label">Phone Number</span>
                    <span><?= htmlspecialchars($order['CUSTOMER_PHONE_SNAPSHOT']) ?></span>
                </div>

            </div>

            <!--  PAYMENT  -->
            <div class="section payment-summary">

                <h3>Payment Info</h3>

                <div class="row">
                    <span class="label">Payment Method</span>
                    <span><?= htmlspecialchars($order['PAYMENT_METHODS'] ?? 'N/A') ?></span>
                </div>

                <div class="row">
                    <span class="label">Payment Amount</span>
                    <span>RM <?= number_format($order['PAYMENT_AMOUNT'] ?? 0, 2) ?></span>
                </div>

                <div class="row">
                    <span class="label">Payment Status</span>
                    <span class="badge status-<?= strtolower($order['PAYMENT_STATUS'] ?? 'n-a') ?>">
                        <?= htmlspecialchars($order['PAYMENT_STATUS'] ?? 'N/A') ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Transaction Date</span>
                    <span><?= !empty($order['TRANSACTION_DATE']) ? date("d M Y h:i A", strtotime($order['TRANSACTION_DATE'])) : 'N/A' ?></span>
                </div>

            </div>

        </div>

    </div>

</div>

</body>
</html>