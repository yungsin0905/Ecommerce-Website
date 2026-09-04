<?php
require_once("config.php");
session_start();

header('Content-Type: text/plain');

if (!isset($_GET['voucher_id'])) {
    echo "error";
    exit;
}

$voucherId = (int)$_GET['voucher_id'];

// Check exists
$stmt = $conn->prepare("
    SELECT VOUCHER_ID, VOUCHER_STATUS
    FROM voucher
    WHERE VOUCHER_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $voucherId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

$voucher = $result->fetch_assoc();

// CHECK IF VOUCHER IS USED IN ORDER , If YES THEN CANNOT DELETE
$stmt2 = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM orders
    WHERE VOUCHER_ID = ?
");

$stmt2->bind_param("i", $voucherId);
$stmt2->execute();

$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// If no errors, delete
$stmt3 = $conn->prepare("
    UPDATE voucher
    SET 
        IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
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