<?php
session_start();
require_once 'config.php';   // 路径按你的目录调整，例如 '../include/config.php'

if (!isset($_SESSION['admin_id'])) {
    echo "error";
    exit;
}

$deliveryId = (int)($_POST['delivery_id'] ?? 0);
$status     = strtoupper(trim($_POST['status'] ?? ''));

if (!$deliveryId || $status === '') {
    die("Invalid input");
}

// 先读取旧状态，避免重复发信
$old = $conn->prepare("SELECT DELIVERY_STATUS FROM shipping WHERE SHIPPING_ID = ?");
$old->bind_param("i", $deliveryId);
$old->execute();
$oldRow = $old->get_result()->fetch_assoc();
$old->close();

if (!$oldRow) {
    die("Shipping not found");
}
$alreadyDelivered = strtoupper($oldRow['DELIVERY_STATUS']) === 'DELIVERED';

if ($status === "DELIVERED" && !$alreadyDelivered) {
    $nowDate = date('Y-m-d');
    $nowTime = date('H:i:s');

    // 一次更新状态 + 时间
    $stmt = $conn->prepare("UPDATE shipping SET DELIVERY_STATUS = ?, SHIPPING_DATE = ?, SHIPPING_TIME = ? WHERE SHIPPING_ID = ?");
    $stmt->bind_param("sssi", $status, $nowDate, $nowTime, $deliveryId);
    $stmt->execute();
    $stmt->close();

    // 找订单
    $stmt = $conn->prepare("SELECT ORDER_ID FROM orders WHERE SHIPPING_ID = ?");
    $stmt->bind_param("i", $deliveryId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($order) {
        $orderId = (int)$order['ORDER_ID'];

        $stmt = $conn->prepare("UPDATE orders SET ORDER_STATUS = 'COMPLETED' WHERE ORDER_ID = ?");
        $stmt->bind_param("i", $orderId);
        $stmt->execute();
        $stmt->close();

        sendFinalReceiptEmail($orderId, $conn);
    }
} else {
    // 其他状态，或已经是 DELIVERED，只更新状态，不发信
    $stmt = $conn->prepare("UPDATE shipping SET DELIVERY_STATUS = ? WHERE SHIPPING_ID = ?");
    $stmt->bind_param("si", $status, $deliveryId);
    $stmt->execute();
    $stmt->close();
}

// ================= EMAIL FUNCTION =================
function sendFinalReceiptEmail($orderId, $conn) {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE ORDER_ID = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) return;

    $subtotal = $order['SUB_TOTAL'] ?? 0;
    $discount = $order['DISCOUNT_AMOUNT_SNAPSHOT'] ?? 0;
    $shipping = $order['SHIPPING_FEE_SNAPSHOT'] ?? 0;
    $total    = $order['TOTAL_AMOUNT'] ?? 0;

    // 商品列表（字段名请对照你自己的 order_item 表）
    $stmt2 = $conn->prepare("
        SELECT PRODUCT_NAME_SNAPSHOT, VARIANT_SIZE_SNAPSHOT, QUANTITY, VARIANT_PRICE_SNAPSHOT
        FROM order_item WHERE ORDER_ID = ?
    ");
    $stmt2->bind_param("i", $orderId);
    $stmt2->execute();
    $items = $stmt2->get_result();

    $itemRows = "";
    while ($row = $items->fetch_assoc()) {
        $lineTotal = $row['QUANTITY'] * $row['VARIANT_PRICE_SNAPSHOT'];
        $itemRows .= "
            <tr>
                <td>" . htmlspecialchars($row['PRODUCT_NAME_SNAPSHOT']) . "</td>
                <td>" . htmlspecialchars($row['VARIANT_SIZE_SNAPSHOT'] ?? '-') . "</td>
                <td>{$row['QUANTITY']}</td>
                <td>RM " . number_format($lineTotal, 2) . "</td>
            </tr>";
    }
    $stmt2->close();

    $name    = htmlspecialchars($order['CUSTOMER_NAME_SNAPSHOT']);
    $orderNo = htmlspecialchars($order['ORDER_NO']);

    $body = "
    <div style='font-family:Arial;padding:20px;background:#f6f7fb'>
        <div style='max-width:600px;margin:auto;background:#fff;padding:20px;border-radius:10px'>
            <h2 style='color:#333'>🎉 Order Completed</h2>
            <p>Hi <b>{$name}</b>,</p>
            <p>Your order has been successfully delivered and completed.</p>
            <h3>Order #{$orderNo}</h3>

            <table width='100%' border='1' cellspacing='0' cellpadding='8'
                   style='border-collapse:collapse;border-color:#eee;'>
                <tr style='background:#f9f9f9;'>
                    <th>Product</th><th>Variant</th><th>Qty</th><th>Total</th>
                </tr>
                {$itemRows}
            </table>

            <br>
            <table width='100%' cellpadding='6'>
                <tr><td>Subtotal</td><td align='right'>RM " . number_format($subtotal, 2) . "</td></tr>
                <tr><td>Shipping Fee</td><td align='right'>RM " . number_format($shipping, 2) . "</td></tr>
                <tr><td>Discount</td><td align='right'>- RM " . number_format($discount, 2) . "</td></tr>
                <tr>
                    <td><b>Total Paid</b></td>
                    <td align='right'><b>RM " . number_format($total, 2) . "</b></td>
                </tr>
            </table>

            <br>
            <p style='color:#777;font-size:12px'>
                Thank you for shopping with us ❤️<br>
                Best Regards,<br>
                <strong>Maker Kluang Team</strong>
            </p>
        </div>
    </div>";

    $alt = "Hi {$order['CUSTOMER_NAME_SNAPSHOT']}, your order #{$order['ORDER_NO']} has been delivered and completed. Total paid: RM " . number_format($total, 2);

    $ok = sendMail(
        $order['CUSTOMER_EMAIL_SNAPSHOT'],
        "Order Completed - #" . $order['ORDER_NO'],
        $body,
        $order['CUSTOMER_NAME_SNAPSHOT'],
        $alt
    );

    if (!$ok) {
        error_log("Final receipt email failed for order #" . $order['ORDER_NO']);
    }
}

echo "success";
exit;
?>