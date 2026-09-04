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
$orderId = $_GET['order_id'] ?? null;
 
if (!$orderId) {
    die("Invalid Order ID");
}
 
// GET DELIVERY + ORDER INFO
$sql = "
SELECT 
    o.*,
 
    s.DELIVERY_STATUS,
    s.SHIPPING_DATE,
    s.SHIPPING_TIME
 
FROM orders o
 
JOIN shipping s 
    ON o.SHIPPING_ID = s.SHIPPING_ID
 
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
 
// GET CUSTOM ORDER INFO
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
 
    $stmt2 = $conn->prepare($customSql);
    $stmt2->bind_param("i", $orderId);
    $stmt2->execute();
 
    $custom = $stmt2->get_result()->fetch_assoc();
}
 
// GET ORDER ITEMS + ADDON (NORMAL) INFO
$itemSql = "
SELECT 
    oi.ORDER_ITEM_ID,
    oi.QUANTITY,
    oi.CAKE_WRITING,
    oi.CARD_TEXT,
 
    oi.PRODUCT_NAME_SNAPSHOT,
    oi.VARIANT_SIZE_SNAPSHOT,
 
    oia.ADDON_NAME_SNAPSHOT,
    oia.QUANTITY AS ADDON_QTY
 
FROM order_item oi
 
LEFT JOIN order_item_addon oia
    ON oi.ORDER_ITEM_ID = oia.ORDER_ITEM_ID
 
WHERE oi.ORDER_ID = ?
 
ORDER BY oi.ORDER_ITEM_ID
";
 
$stmt3 = $conn->prepare($itemSql);
$stmt3->bind_param("i", $orderId);
$stmt3->execute();
 
$result = $stmt3->get_result();
 
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
    <title>View Delivery Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.title {
    margin-bottom: 15px;
}

/* CARD  */
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
 
/* TWO-COL GRID (top row) */
.two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
 
/* ADDON TAG  */
.addon-tag {
    display: inline-block;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    padding: 3px 10px;
    margin: 2px 2px 2px 0;
    border-radius: 6px;
    font-size: 12px;
    color: #374151;
}
 
/* ITEM CARD  */
.item-card {
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 10px;
}

.item-card:last-child { 
    margin-bottom: 0; 
}
 
.item-title {
    font-size: 13px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid #e5e7eb;
}
 
/* REF IMAGE */
.ref-image {
    width: 180px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
    margin-top: 4px;
}
 
/* NA TEXT */
.na { 
    color: #9ca3af; 
}

.links {
    text-decoration: none;
    color: #4d4d4d;
    font-weight: 600;
}

.links:hover {
    text-decoration: underline;  
}
</style>
</head>
 
<body>
<div class="form-wrapper">
 
    <a href="manage_delivery.php" class="form-back-link" title="Back To Delivery Page">← Back</a>
 
    <div class="title">Delivery details</div>
 
    <!-- TOP: ORDER INFO + CUSTOMER INFO -->
    <div class="two-col">
 
        <!-- Order Info -->
        <div class="card" style="margin-bottom:0">
            <h3>Order info</h3>
 
            <div class="row">
                <span class="label">Order no</span>
                <a href="view_order.php?order_id=<?= $order['ORDER_ID'] ?>" class="links">
                    #<?= $order['ORDER_NO'] ?>
                </a>
            </div>

            <div class="row">
                <span class="label">Order type</span>
                <span class="badge order-<?= strtolower($order['ORDER_TYPE']) ?>">
                    <?= htmlspecialchars($order['ORDER_TYPE']) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Order status</span>
                <span class="value">
                    <span class="badge status-<?= strtolower($order['ORDER_STATUS']) ?>">
                        <?= htmlspecialchars($order['ORDER_STATUS']) ?>
                    </span>
                </span>
            </div>
        </div>
 
        <!-- Customer Info -->
        <div class="card" style="margin-bottom:0">
            <h3>Customer info</h3>
 
            <div class="row">
                <span class="label">Name</span>
                <span class="value"><?= htmlspecialchars($order['CUSTOMER_NAME_SNAPSHOT']) ?></span>
            </div>
            <div class="row">
                <span class="label">Email</span>
                <span class="value"><?= htmlspecialchars($order['CUSTOMER_EMAIL_SNAPSHOT']) ?></span>
            </div>
            <div class="row">
                <span class="label">Phone</span>
                <span class="value"><?= htmlspecialchars($order['CUSTOMER_PHONE_SNAPSHOT']) ?></span>
            </div>
        </div>
 
    </div>
 
    <!-- DELIVERY INFO -->
    <div class="card">
        <h3>Delivery info</h3>
 
        <div class="row">
            <span class="label">Delivery status</span>
            <span class="value">
                <span class="badge status-<?= strtolower(str_replace(' ', '-', $order['DELIVERY_STATUS'])) ?>">
                    <?= htmlspecialchars($order['DELIVERY_STATUS']) ?>
                </span>
            </span>
        </div>
 
        <!-- Custom -->
        <?php if ($isCustom && $custom): ?>
 
            <div class="row">
                <span class="label">Recipient name</span>
                <span class="value"><?= htmlspecialchars($custom['RECIPIENT_NAME']) ?></span>
            </div>

            <div class="row">
                <span class="label">Recipient email</span>
                <span class="value"><?= htmlspecialchars($custom['RECIPIENT_EMAIL']) ?></span>
            </div>

            <div class="row">
                <span class="label">Recipient phone</span>
                <span class="value"><?= htmlspecialchars($custom['RECIPIENT_PHONE']) ?></span>
            </div>

            <hr>
            
            <div class="row">
                <span class="label">Delivery address</span>
                <span class="value"><strong><?= nl2br(htmlspecialchars($order['DELIVERY_ADDRESS_SNAPSHOT'])) ?></strong></span>
            </div>

            <div class="row">
                <span class="label">Expected delivery date</span>
                <span class="value"><strong><?= date("d M Y", strtotime($order['DELIVERY_DATE'])) ?></strong></span>
            </div>

            <div class="row">
                <span class="label">Expected delivery time</span>
                <span class="value"><strong><?= htmlspecialchars($order['DELIVERY_SLOT_SNAPSHOT']) ?></strong></span>
            </div>
 
        <!-- Normal -->
        <?php else: ?>
 
            <div class="row">
                <span class="label">Delivery address</span>
                <span class="value"><strong><?= nl2br(htmlspecialchars($order['DELIVERY_ADDRESS_SNAPSHOT'])) ?></strong></span>
            </div>

            <div class="row">
                <span class="label">Expected delivery date</span>
                <span class="value"><strong><?= date("d M Y", strtotime($order['DELIVERY_DATE'])) ?></strong></span>
            </div>

            <div class="row">
                <span class="label">Expected delivery time</span>
                <span class="value"><strong><?= htmlspecialchars($order['DELIVERY_SLOT_SNAPSHOT']) ?></strong></span>
            </div>
 
        <?php endif; ?>
 
    </div>
 
    <!-- ACTUAL DELIVERY INFO -->
    <div class="card">
        <h3>Actual delivery info</h3>
 
        <div class="row">
            <span class="label">Actual delivery date</span>
            <span class="value">
                <?= !empty($order['SHIPPING_DATE'])
                    ? date("d M Y", strtotime($order['SHIPPING_DATE']))
                    : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Actual delivery time</span>
            <span class="value">
                <?= !empty($order['SHIPPING_TIME'])
                    ? date("h:i A", strtotime($order['SHIPPING_TIME']))
                    : '<span class="na">N/A</span>' ?>
            </span>
        </div>
    </div>
 
    <!-- CUSTOM CAKE DETAILS -->
    <?php if ($isCustom && $custom): ?>
    <div class="card">
        <h3>Custom cake details</h3>

        <div class="row">
            <span class="label">Cake style</span>
            <span class="value"><?= htmlspecialchars($custom['STYLE_NAME_SNAPSHOT']) ?></span>
        </div>

        <div class="row">
            <span class="label">Cake size</span>
            <span class="value">
                <?= !empty($custom['SIZE']) ? htmlspecialchars($custom['SIZE']) : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Cake Quantity</span>
            <span class="value">
                <?= !empty($custom['QUANTITY']) ? $custom['QUANTITY'] : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Cater count</span>
            <span class="value">
                <?= !empty($custom['CATER_COUNT']) ? htmlspecialchars($custom['CATER_COUNT']) : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Ideal flavour</span>
            <span class="value">
                <?= !empty($custom['IDEAL_FLAVOUR']) ? htmlspecialchars($custom['IDEAL_FLAVOUR']) : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Custom description</span>
            <span class="value">
                <?= !empty($custom['CUSTOM_DES']) ? nl2br(htmlspecialchars($custom['CUSTOM_DES'])) : '<span class="na">N/A</span>' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Reference image</span>
            <span class="value">
                <?php if (!empty($custom['REF_IMAGE'])): ?>
                    <img src="../<?= htmlspecialchars($custom['REF_IMAGE']) ?>" class="ref-image" alt="Reference image">
                <?php else: ?>
                    <span class="na">N/A</span>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php endif; ?>
 
    <!-- ORDER ITEMS -->
    <?php if (!$isCustom): ?>
    <div class="card">
        <h3>Order items</h3>
 
        <?php $itemNo = 1; foreach ($groupedItems as $item): ?>
        <div class="item-card">
            <div class="item-title">Item <?= $itemNo++ ?></div>
 
            <div class="row">
                <span class="label">Product</span>
                <span class="value"><?= htmlspecialchars($item['PRODUCT_NAME_SNAPSHOT']) ?></span>
            </div>

            <div class="row">
                <span class="label">Variant</span>
                <span class="value"><?= htmlspecialchars($item['VARIANT_SIZE_SNAPSHOT']) ?> inch</span>
            </div>

            <div class="row">
                <span class="label">Quantity</span>
                <span class="value"><?= $item['QUANTITY'] ?></span>
            </div>

            <div class="row">
                <span class="label">Add-ons</span>
                <span class="value">
                    <?php if (!empty($item['addons'])): ?>
                        <?php foreach ($item['addons'] as $addon): ?>
                            <span class="addon-tag">
                                <?= htmlspecialchars($addon['name']) ?> (x<?= $addon['qty'] ?>)
                            </span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="na">N/A</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Cake writing</span>
                <span class="value">
                    <?= !empty($item['CAKE_WRITING']) ? htmlspecialchars($item['CAKE_WRITING']) : '<span class="na">N/A</span>' ?>
                </span>
            </div>
            
            <div class="row">
                <span class="label">Card text</span>
                <span class="value">
                    <?= !empty($item['CARD_TEXT']) ? htmlspecialchars($item['CARD_TEXT']) : '<span class="na">N/A</span>' ?>
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
 
</div>

</body>
</html>