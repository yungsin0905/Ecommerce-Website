<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    echo "error";
    exit;
}

// Get inputs
$orderId = $_POST['order_id'] ?? null;
$status  = $_POST['status'] ?? null;

$allowedStatus = ['PROCESSING', 'READY', 'COMPLETED', 'CANCELLED'];

if (!$orderId || !is_numeric($orderId) || !in_array($status, $allowedStatus, true)) {
    echo "error";
    exit;
}

$orderId = (int)$orderId;

// Update status
// READY_AT is only set the first time an order becomes READY (COALESCE keeps
// the original time if it's already set, so re-saving READY won't overwrite it)
$sql = "
    UPDATE orders 
    SET ORDER_STATUS = ?,
        READY_AT = CASE 
            WHEN ? = 'READY' THEN COALESCE(READY_AT, NOW()) 
            ELSE READY_AT 
        END
    WHERE ORDER_ID = ?
";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo "error";
    exit;
}

$stmt->bind_param("ssi", $status, $status, $orderId);

echo $stmt->execute() ? "success" : "error";