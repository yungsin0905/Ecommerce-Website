<?php
require_once("config.php");
session_start();

header('Content-Type: text/plain');

// 管理员才能删除
if (!isset($_SESSION['admin_id'])) {
    echo "error";
    exit;
}

if (!isset($_GET['voucher_id'])) {
    echo "error";
    exit;
}

$voucherId = (int)$_GET['voucher_id'];

// 1. Check voucher exists
$stmt = $conn->prepare("SELECT 1 FROM voucher WHERE VOUCHER_ID = ? AND IS_DELETED = 0");
$stmt->bind_param("i", $voucherId);
$stmt->execute();
if ($stmt->get_result()->num_rows == 0) {
    echo "error";
    exit;
}

// 2. Check if voucher already used in orders (kept for order-history integrity)
$stmt2 = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE VOUCHER_ID = ?");
$stmt2->bind_param("i", $voucherId);
$stmt2->execute();
$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// 3. Clean up unused customer copies + soft-delete (all or nothing)
$conn->begin_transaction();
try {
    $del = $conn->prepare("DELETE FROM customer_voucher WHERE VOUCHER_ID = ? AND USED_COUNT = 0");
    $del->bind_param("i", $voucherId);
    if (!$del->execute()) throw new Exception("cleanup failed");

    $upd = $conn->prepare("
        UPDATE voucher
        SET IS_DELETED = 1, UPDATED_AT = CURRENT_TIMESTAMP()
        WHERE VOUCHER_ID = ?
    ");
    $upd->bind_param("i", $voucherId);
    if (!$upd->execute()) throw new Exception("delete failed");

    $conn->commit();
    echo "ok";
} catch (Exception $e) {
    $conn->rollback();
    echo "error";
}
exit;
?>