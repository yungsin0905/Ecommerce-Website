<?php
require_once("config.php");
session_start();

header('Content-Type: text/plain');

if (!isset($_GET['voucher_id'])) {
    echo "error";
    exit;
}

$voucherId = (int)$_GET['voucher_id'];

// 1. Check voucher exists
$stmt = $conn->prepare("SELECT 1 FROM voucher WHERE VOUCHER_ID = ? AND IS_DELETED = 0");
$stmt->bind_param("i", $voucherId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

// 2. Check if any customer still holds an unused, unexpired copy
$stmtInUse = $conn->prepare("
    SELECT 1 FROM customer_voucher
    WHERE VOUCHER_ID = ?
      AND USED_COUNT = 0
      AND EXPIRY_DATE >= CURDATE()
    LIMIT 1
");
$stmtInUse->bind_param("i", $voucherId);
$stmtInUse->execute();
if ($stmtInUse->get_result()->num_rows > 0) {
    echo "error_in_use";
    exit;
}

// 3. Check if voucher already used in orders (kept for order-history integrity)
$stmt2 = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE VOUCHER_ID = ?");
$stmt2->bind_param("i", $voucherId);
$stmt2->execute();
$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// 4. If no errors, soft-delete
$stmt3 = $conn->prepare("
    UPDATE voucher
    SET IS_DELETED = 1, UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE VOUCHER_ID = ?
");
$stmt3->bind_param("i", $voucherId);

if ($stmt3->execute()) {
    echo "ok";
} else {
    echo "error";
}
exit;
?>