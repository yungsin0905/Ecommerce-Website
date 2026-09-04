<?php
require_once("config.php");

// Get inputs
$orderId = $_POST['order_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$orderId || !$status) {
    die("Invalid input");
}

// Update status
$sql = "UPDATE orders SET ORDER_STATUS = ? WHERE ORDER_ID = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Error: " . $conn->error);
}

$stmt->bind_param("si", $status, $orderId);
$stmt->execute();

echo "success";
?>