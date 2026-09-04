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
$orderId = $_GET['order_id'] ?? 1;

if (!$orderId) {
    die("Invalid Order ID");
}

// Get order info
$sql = "
SELECT 
    o.*,
    s.DELIVERY_STATUS,
    s.SHIPPING_DATE,
    s.SHIPPING_TIME,
    p.PAYMENT_METHODS,
    p.PAYMENT_AMOUNT,
    p.PAYMENT_STATUS,
    p.TRANSACTION_DATE
FROM orders o
JOIN shipping s 
    ON o.SHIPPING_ID = s.SHIPPING_ID
LEFT JOIN payment p 
    ON o.PAYMENT_ID = p.PAYMENT_ID
WHERE o.ORDER_ID = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    die("Order not found");
}

// Determine whether is custom or normal order
$isCustom = ($order['ORDER_TYPE'] === 'Custom');

// DELIVERY TYPE LOGIC
$createdDate = date('Y-m-d', strtotime($order['CREATED_AT']));

$isSameDay = ($createdDate == $order['DELIVERY_DATE']);

if ($isSameDay) {
    $deliveryType = "Same Day";
    $deliveryClass = "same-day";
} else {
    $deliveryType = "Pre-order";
    $deliveryClass = "pre-order";
}

// GET order items + addon info
$itemSql = "
SELECT 
    oi.ORDER_ITEM_ID,
    oi.PRODUCT_ID,
    oi.QUANTITY,
    oi.CAKE_WRITING,
    oi.CARD_TEXT,

    oi.PRODUCT_NAME_SNAPSHOT,
    oi.VARIANT_SIZE_SNAPSHOT,
    oi.VARIANT_PRICE_SNAPSHOT,

    p.COVER_IMAGE,

    oia.ADD_ON_ID,
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
            'id' => $row['ADD_ON_ID'],
            'name' => $row['ADDON_NAME_SNAPSHOT'],
            'price' => $row['ADDON_PRICE_SNAPSHOT'],
            'qty' => $row['ADDON_QTY']
        ];
    }
}

// Get custom info
$custom = null;

if ($isCustom) {

    $customSql = "
    SELECT 
        c.*
    FROM order_item oi
    INNER JOIN custom c 
        ON oi.CUSTOM_ID = c.CUSTOM_ID
    WHERE oi.ORDER_ID = ?
    AND c.IS_DELETED = 0
    LIMIT 1
    ";

    $stmt3 = $conn->prepare($customSql);
    $stmt3->bind_param("i", $orderId);
    $stmt3->execute();

    $custom = $stmt3->get_result()->fetch_assoc();
}

// Get REFUND REQUEST INFO
$refundRequest = null;

$refundReqSql = "
SELECT *
FROM refund_request
WHERE ORDER_ID = ?
ORDER BY REQUEST_ID DESC
LIMIT 1
";

$stmt4 = $conn->prepare($refundReqSql);
$stmt4->bind_param("i", $orderId);
$stmt4->execute();

$refundRequest = $stmt4->get_result()->fetch_assoc();

$approvedRefund = null;

if ($refundRequest && $refundRequest['REQUEST_STATUS'] === 'APPROVED') {
    $approvedRefund = $refundRequest;
}

// Get REFUND INFO
$refund = null;

$refundSql = "
SELECT 
    r.*,
    a.ADMIN_NAME
FROM refund r
LEFT JOIN admin a 
    ON r.ADMIN_ID = a.ADMIN_ID
WHERE r.ORDER_ID = ?
ORDER BY r.CREATED_AT DESC
LIMIT 1
";

$stmt5 = $conn->prepare($refundSql);
$stmt5->bind_param("i", $orderId);
$stmt5->execute();

$refund = $stmt5->get_result()->fetch_assoc();
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

.payment-total{
    margin-top:16px;
    padding-top:16px;
    border-top:2px dashed #ddd;
}

.payment-total .amount{
    font-size:30px;
    font-weight:700;
    color:var(--primary-dark);
}

/*  REFUND  */
.refund-box{
    background:#fff8f8;
    border:1px solid #ffd6d6;
}

/*  IMAGE  */
.preview-image{
    max-width:280px;
    border-radius:12px;
    border:1px solid #eee;
}

/*  CUSTOM SECTION */
.custom-row{
    padding-bottom:4px;
}
</style>

<body>

<div class="form-wrapper">

    <a href="manage_order.php" class="form-back-link" title="Back To Order Page">← Back</a>

    <!--  HEADER  -->
    <div class="order-header">
        <div>
            <div class="order-title">
                Order #<?= $order['ORDER_NO'] ?>
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
            <!--  CUSTOM ORDER  -->
            <?php if ($isCustom && $custom): ?>

            <div class="custom-row">

                <h3 style="display:flex;justify-content:space-between;align-items:center;">
                    Custom Cake Details

                    <span class="badge order-<?= strtolower($order['ORDER_TYPE']) ?>">
                         <?= htmlspecialchars($order['ORDER_TYPE']) ?>
                    </span>
                </h3>

                <div class="row">
                    <span class="label">Recipient Name</span>
                    <span><?= htmlspecialchars($custom['RECIPIENT_NAME']) ?></span>
                </div>

                <div class="row">
                    <span class="label">Recipient Email</span>
                    <span><?= htmlspecialchars($custom['RECIPIENT_EMAIL']) ?></span>
                </div>

                <div class="row">
                    <span class="label">Recipient Phone</span>
                    <span><?= htmlspecialchars($custom['RECIPIENT_PHONE']) ?></span>
                </div>

                <hr>

                <div class="row">
                    <span class="label">Cake Style</span>
                    <span><?= !empty($custom['STYLE_NAME_SNAPSHOT']) ? htmlspecialchars($custom['STYLE_NAME_SNAPSHOT']) : 'N/A' ?></span>
                </div>

                <div class="row">
                    <span class="label">Cake Size</span>
                    <span><?= !empty($custom['SIZE']) ? htmlspecialchars($custom['SIZE']) : 'N/A' ?></span>
                </div>

                <div class="row">
                    <span class="label">Quantity</span>
                    <span><?= !empty($custom['QUANTITY']) ? $custom['QUANTITY'] : 'N/A' ?></span>
                </div>

                <div class="row">
                    <span class="label">Cater Count</span>
                    <span><?= !empty($custom['CATER_COUNT']) ?  $custom['CATER_COUNT'] : 'N/A' ?></span>
                </div>

                <div class="row">
                    <span class="label">Preferred Flavour</span>

                    <span>
                        <?= !empty($custom['IDEAL_FLAVOUR']) ? htmlspecialchars($custom['IDEAL_FLAVOUR']) : 'N/A' ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Custom Description</span>

                    <span>
                        <?= !empty($custom['CUSTOM_DES']) ? nl2br(htmlspecialchars($custom['CUSTOM_DES'])) : 'N/A' ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Reference Image</span>

                    <span>
                        <?php if (!empty($custom['REF_IMAGE'])): ?>

                            <img src="../<?= htmlspecialchars($custom['REF_IMAGE']) ?>" class="preview-image">

                        <?php else: ?>

                            N/A

                        <?php endif; ?>
                    </span>
                </div>

            </div>

            <?php endif; ?>

            <!--  ORDER ITEMS (Normal order) -->
            <?php if (!$isCustom): ?>

            <div>

                <h3 style="display:flex;justify-content:space-between;align-items:center;">
                    Order Items

                    <span class="badge order-<?= strtolower($order['ORDER_TYPE']) ?>">
                         <?= htmlspecialchars($order['ORDER_TYPE']) ?>
                    </span>
                </h3>

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

                        <img src="<?= htmlspecialchars($item['COVER_IMAGE']) ?>" class="item-img"/>
                        <div class="item-details">
                            <p class="item-name">
                                <a href="view_product.php?product_id=<?= $item['PRODUCT_ID'] ?>">
                                    <?= htmlspecialchars($item['PRODUCT_NAME_SNAPSHOT']) ?>
                                </a>
                            </p>
                            <p class="item-meta">Size: <?= htmlspecialchars($item['VARIANT_SIZE_SNAPSHOT']) ?> inch</p>
                            <p class="item-meta">Cake Writing: <?= !empty($item['CAKE_WRITING']) ? htmlspecialchars($item['CAKE_WRITING']) : 'N/A' ?></p>
                            <p class="item-meta">Card Message: <?= !empty($item['CARD_TEXT']) ? htmlspecialchars($item['CARD_TEXT']) : 'N/A' ?></p>

                            <br>
                            
                            <!-- ADDONS -->
                            <?php if (!empty($item['addons'])): ?>
                                <div class="addon-list">
                                    <?php foreach ($item['addons'] as $addon): ?>
                                        <span class="item-meta addon-tag">
                                            +
                                            <a href="view_addon.php?addon_id=<?= $addon['id'] ?>">
                                                <?= htmlspecialchars($addon['name']) ?>
                                            </a>
                                            (×<?= $addon['qty'] ?>) +
                                             RM <?= number_format($addon['price'] * $addon['qty'], 2) ?>
                                        </span>
                                        <br>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                           
                        </div>

                        <div class="item-quantity">
                            × <?= $item['QUANTITY'] ?>
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

            <?php endif; ?>

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
                                    <a href="view_voucher.php?voucher_id=<?= $order['VOUCHER_ID'] ?>" class="links">
                                        <strong><?= htmlspecialchars($order['VOUCHER_CODE_SNAPSHOT']) ?></strong>
                                    </a>

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
                    <span class="label">Shipping Type</span>

                    <span class="badge delivery-<?= $deliveryClass ?>">
                        <?= $deliveryType ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Delivery Address</span>

                    <span>
                        <?= nl2br(htmlspecialchars($order['DELIVERY_ADDRESS_SNAPSHOT'])) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Expected Delivery Date</span>

                    <span>
                        <?= date("d M Y", strtotime($order['DELIVERY_DATE'])) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Expected Delivery Time</span>

                    <span>
                        <?= htmlspecialchars(($order['DELIVERY_SLOT_SNAPSHOT'])) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Delivery Status</span>

                    <span class="badge status-<?= strtolower(str_replace(' ','-', $order['DELIVERY_STATUS'])) ?>">
                        <?= htmlspecialchars($order['DELIVERY_STATUS']) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Actual Delivery Date</span>

                    <span>
                        <?= !empty($order['SHIPPING_DATE']) ? date("d M Y", strtotime($order['SHIPPING_DATE'])) : 'N/A' ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Actual Delivery Time</span>

                    <span>
                        <?= !empty($order['SHIPPING_TIME']) ? date("h:i A", strtotime($order['SHIPPING_TIME'])) : 'N/A' ?>
                    </span>
                </div>

            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div class="right-column">

            <!--  CUSTOMER INFO  -->
            <div class="section">

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <h3>Customer Info</h3>

                    <a class="action-btn" href="view_customer.php?customer_id=<?= $order['CUSTOMER_ID'] ?>">View</a>
                </div>

                <div class="row">
                    <span class="label">Customer Name</span>

                    <span>
                        <?= htmlspecialchars($order['CUSTOMER_NAME_SNAPSHOT']) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Email Address</span>

                    <span>
                        <?= htmlspecialchars($order['CUSTOMER_EMAIL_SNAPSHOT']) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Phone Number</span>

                    <span>
                        <?= htmlspecialchars($order['CUSTOMER_PHONE_SNAPSHOT']) ?>
                    </span>
                </div>

            </div>

            <!--  PAYMENT  -->
            <div class="section payment-summary">

                <h3>Payment Info</h3>

                <div class="row">
                    <span class="label">Payment Method</span>

                    <span>
                        <?= htmlspecialchars($order['PAYMENT_METHODS']) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Payment Amount</span>

                    <span>
                        RM <?= number_format($order['PAYMENT_AMOUNT'], 2) ?>
                    </span>
                </div>

                <div class="row">
                    <span class="label">Payment Status</span>

                    <span class="badge status-<?= strtolower($order['PAYMENT_STATUS']) ?>">
                        <?= htmlspecialchars($order['PAYMENT_STATUS']) ?>
                    </span>
                </div>

            </div>

            <!--  REFUND INFO  -->
            <div class="section refund-box">

                <h3>Refund Info</h3>

                <?php if ($refund): ?>

                    <!-- refund that request by customer-->
                    <?php if (!empty($refund['REQUEST_ID'])): ?>
                        <div class="row">
                            <span class="label" style="color:#d63333; font-weight:700;">
                                Request by customer
                            </span>

                            <span>
                                <a href="process_refund_request.php?request_id=<?= $refund['REQUEST_ID'] ?>" class="action-btn">
                                    View Request
                                </a>
                            </span>
                        </div>

                    <!-- refund by admin hisself -->
                    <?php else: ?>
                        <div class="row">
                            <span class="label" style="color:#d63333; font-weight:700;">
                                Refund by admin
                            </span>

                            <span></span>
                        </div>

                    <?php endif; ?>

                    <div class="row">
                        <span class="label">Refund Status</span>

                        <span class="badge status-<?= strtolower($refund['REFUND_STATUS']) ?>">
                            <?= htmlspecialchars($refund['REFUND_STATUS']) ?>
                        </span>
                    </div>

                    <div class="row">
                        <span class="label">Refund Amount</span>

                        <span>
                            RM <?= number_format($refund['REFUND_AMOUNT'], 2) ?>
                        </span>
                    </div>

                    <div class="row">
                        <span class="label">Refund Date</span>

                        <span>
                            <small><?= date("d M Y h:i A", strtotime($order['REFUND_DATE'])) ?></small>
                        </span>
                    </div>

                    <div class="row">
                        <span class="label">Admin Note</span>

                        <span>
                            <small><?= nl2br(htmlspecialchars($refund['REASON'])) ?></small>
                        </span>
                    </div>

                <?php else: ?>

                    <p>No refund record found.</p>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

</body>
</html>