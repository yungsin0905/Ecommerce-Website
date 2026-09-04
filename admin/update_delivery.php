<?php
require_once("config.php");

date_default_timezone_set('Asia/Kuala_Lumpur'); 
$conn->query("SET time_zone = '+08:00'");

// email
require 'include/vendor/Exception.php';
require 'include/vendor/PHPMailer.php';
require 'include/vendor/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Get inputs
$deliveryId = $_POST['delivery_id'] ?? null;
$status = strtoupper(trim($_POST['status'] ?? ''));

if (!$deliveryId || !$status) {
    die("Invalid input");
}

// Update DELIVERY status
$sql = "UPDATE shipping SET DELIVERY_STATUS = ? WHERE SHIPPING_ID = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Error (Shipping): " . $conn->error);
}

$stmt->bind_param("si", $status, $deliveryId);
$stmt->execute();

if ($status === "DELIVERED") {

    $nowDate = date('Y-m-d');
    $nowTime = date('H:i:s');

    // If delivered, Update SHIPPING date & time
    $updateShippingTime = "
        UPDATE shipping 
        SET SHIPPING_DATE = ?, SHIPPING_TIME = ?
        WHERE SHIPPING_ID = ?
    ";

    $stmt2 = $conn->prepare($updateShippingTime);

    if (!$stmt2) {
        die("SQL Error (Update Shipping Time): " . $conn->error);
    }

    $stmt2->bind_param("ssi", $nowDate, $nowTime, $deliveryId);
    $stmt2->execute();

    // Get order ID 
    $getOrderSql = "SELECT ORDER_ID FROM orders WHERE SHIPPING_ID = ?";
    $stmt3 = $conn->prepare($getOrderSql);

    if (!$stmt3) {
        die("SQL Error (Get Order): " . $conn->error);
    }

    $stmt3->bind_param("i", $deliveryId);
    $stmt3->execute();

    $result = $stmt3->get_result();
    $order = $result->fetch_assoc();

    if ($order) {
        $orderId = $order['ORDER_ID'];

        //If delivered, Update order status to COMPLETED*/
        $updateOrderSql = "
            UPDATE orders 
            SET ORDER_STATUS = 'COMPLETED' 
            WHERE ORDER_ID = ?
        ";

        $stmt4 = $conn->prepare($updateOrderSql);

        if (!$stmt4) {
            die("SQL Error (Update Order): " . $conn->error);
        }

        $stmt4->bind_param("i", $orderId);
        $stmt4->execute();

        // Then send email
        sendFinalReceiptEmail($orderId, $conn);
    }
}

// EMAIL FUNCTION
function sendFinalReceiptEmail($orderId, $conn) {

    // ORDER INFO
    $stmt = $conn->prepare("SELECT * FROM orders WHERE ORDER_ID = ?");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    if (!$order) return;

    $orderType = $order['ORDER_TYPE']; // 'Normal' or 'Custom'

    // BAKERY INFO 
    $bakery = $conn->query("SELECT * FROM bakery_info LIMIT 1")->fetch_assoc();

    // BUILD ITEM ROWS
    $itemRows = "";

    $subtotal = $order['SUB_TOTAL'] ?? 0;
    $discount = $order['DISCOUNT_AMOUNT_SNAPSHOT'] ?? 0;
    $shipping = $order['SHIPPING_FEE_SNAPSHOT'] ?? 0;
    $total = $order['TOTAL_AMOUNT'] ?? 0;

    // ORDER ITEMS
    $stmt2 = $conn->prepare("
        SELECT oi.ORDER_ITEM_ID, oi.PRODUCT_NAME_SNAPSHOT, oi.VARIANT_SIZE_SNAPSHOT,
               oi.QUANTITY, oi.VARIANT_PRICE_SNAPSHOT,
               oi.CAKE_WRITING, oi.CARD_TEXT, oi.CUSTOM_ID
        FROM order_item oi
        WHERE oi.ORDER_ID = ?
    ");
    $stmt2->bind_param("i", $orderId);
    $stmt2->execute();
    $items = $stmt2->get_result();

    while ($row = $items->fetch_assoc()) {

        // CUSTOM order type
        if ($orderType === 'Custom' && $row['CUSTOM_ID']) {
            // Get info from custom table
            $stmtC = $conn->prepare("
                SELECT STYLE_NAME_SNAPSHOT, SIZE, QUOTED_PRICE, IDEAL_FLAVOUR, CUSTOM_DES, QUANTITY
                FROM custom WHERE CUSTOM_ID = ?
            ");
            $stmtC->bind_param("i", $row['CUSTOM_ID']);
            $stmtC->execute();
            $custom = $stmtC->get_result()->fetch_assoc();

            $price = $custom['QUOTED_PRICE'] ?? 0;
            $qty = $custom['QUANTITY'] ?? 1;
            $lineTotal = $price;

            $itemRows .= "
                <tr>
                    <td>" . htmlspecialchars($custom['STYLE_NAME_SNAPSHOT'] ?? 'Custom Cake') . "</td>
                    <td>" . htmlspecialchars($custom['SIZE'] ?? '-') . "</td>
                    <td>{$qty}</td>
                    <td>RM " . number_format($lineTotal, 2) . "</td>
                </tr>
            ";

            if (!empty($custom['IDEAL_FLAVOUR']) || !empty($custom['CUSTOM_DES'])) {
                $itemRows .= "
                    <tr>
                        <td colspan='4' style='font-size:12px;color:#777;padding-left:16px;'>
                            " . (!empty($custom['IDEAL_FLAVOUR']) ? "Flavour: " . htmlspecialchars($custom['IDEAL_FLAVOUR']) . "<br>" : "") . "
                            " . (!empty($custom['CUSTOM_DES']) ? "Note: " . htmlspecialchars($custom['CUSTOM_DES']) : "") . "
                        </td>
                    </tr>
                ";
            }

        } else {
            // Normal order type
            $lineTotal = $row['QUANTITY'] * $row['VARIANT_PRICE_SNAPSHOT'];

            $itemRows .= "
                <tr>
                    <td>" . htmlspecialchars($row['PRODUCT_NAME_SNAPSHOT']) . "
                        " . (!empty($row['CAKE_WRITING']) ? "<br><small style='color:#999'>Writing: " . htmlspecialchars($row['CAKE_WRITING']) . "</small>" : "") . "
                        " . (!empty($row['CARD_TEXT']) ? "<br><small style='color:#999'>Card: " . htmlspecialchars($row['CARD_TEXT']) . "</small>" : "") . "
                    </td>
                    <td>" . htmlspecialchars($row['VARIANT_SIZE_SNAPSHOT']) . "</td>
                    <td>{$row['QUANTITY']}</td>
                    <td>RM " . number_format($lineTotal, 2) . "</td>
                </tr>
            ";

            // Get Addon info (only for normal order type)
            $stmtA = $conn->prepare("
                SELECT ADDON_NAME_SNAPSHOT, QUANTITY, ADDON_PRICE_SNAPSHOT
                FROM order_item_addon
                WHERE ORDER_ITEM_ID = ?
            ");
            $stmtA->bind_param("i", $row['ORDER_ITEM_ID']);
            $stmtA->execute();
            $addons = $stmtA->get_result();

            while ($addon = $addons->fetch_assoc()) {
                $addonTotal = $addon['QUANTITY'] * $addon['ADDON_PRICE_SNAPSHOT'];

                $itemRows .= "
                    <tr style='background:#fafafa;'>
                        <td style='padding-left:20px;font-size:13px;color:#666'>
                            ➕ " . htmlspecialchars($addon['ADDON_NAME_SNAPSHOT']) . "
                        </td>
                        <td>-</td>
                        <td>{$addon['QUANTITY']}</td>
                        <td>RM " . number_format($addonTotal, 2) . "</td>
                    </tr>
                ";
            }
        }
    }

    // EMAIL SUBJECT & TITLE 
    $emailTitle = $orderType === 'Custom'
        ? "🎂 Custom Order Completed"
        : "🎉 Order Completed";

    // SEND EMAIL
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'wongyungsin04@gmail.com';
        $mail->Password = 'jfim afvt zusc vqwg';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom('wongyungsin04@gmail.com', $bakery['SHOP_NAME']);
        $mail->addAddress($order['CUSTOMER_EMAIL_SNAPSHOT'], $order['CUSTOMER_NAME_SNAPSHOT']);
        $mail->isHTML(true);
        $mail->Subject = "Order Completed - #" . $order['ORDER_NO'];

        $mail->Body = "
        <div style='font-family:Arial;padding:20px;background:#f6f7fb'>
            <div style='max-width:600px;margin:auto;background:#fff;padding:20px;border-radius:10px'>

                <h2 style='color:#333'>{$emailTitle}</h2>

                <p>Hi <b>" . htmlspecialchars($order['CUSTOMER_NAME_SNAPSHOT']) . "</b>,</p>
                <p>Your order has been successfully delivered and completed.</p>
                <h3>Order #{$order['ORDER_NO']}</h3>

                <table width='100%' border='1' cellspacing='0' cellpadding='8'
                    style='border-collapse:collapse;border-color:#eee;'>
                    <tr style='background:#f9f9f9;'>
                        <th>Product</th><th>Size/Variant</th><th>Qty</th><th>Total</th>
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
                    <strong>{$bakery['SHOP_NAME']} Team</strong>
                </p>
                <hr style='border:none;border-top:1px solid #eee;'>
                <p style='color:#999;font-size:12px;'>
                    {$bakery['SHOP_NAME']}<br>
                    {$bakery['ADDRESS']}, {$bakery['POSTCODE']} {$bakery['CITY']}, {$bakery['STATE']}<br>
                    Phone: {$bakery['PHONE']}<br>
                    Email: {$bakery['EMAIL']}
                </p>
            </div>
        </div>
        ";

        $mail->send();

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("Email Send Failed: " . $e->getMessage());
    }
}

echo "success";
exit;
?>