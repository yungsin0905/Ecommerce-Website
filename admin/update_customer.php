<?php
require_once("config.php");

header('Content-Type: application/json');

// Get inputs
$customerId = $_POST['customer_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$customerId || !$status) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid input"
    ]);
    exit;
}

// check active orders (if order status=PROCESSING， READY, then cannot update to suspended)
$check = $conn->prepare("
    SELECT COUNT(*) as total
    FROM orders
    WHERE CUSTOMER_ID = ?
    AND ORDER_STATUS IN ('PROCESSING','READY')
");

$check->bind_param("i", $customerId);
$check->execute();
$result = $check->get_result()->fetch_assoc();

if ($status == "Suspended" && $result['total'] > 0) {
    echo json_encode([
        "status" => "error",
        "message" => "Customer has active orders"
    ]);
    exit;
}

// update status
$stmt = $conn->prepare("UPDATE customer SET STATUS = ? WHERE CUSTOMER_ID = ?");
$stmt->bind_param("si", $status, $customerId);
$stmt->execute();

echo json_encode([
    "status" => "success",
    "message" => "Status updated successfully"
]);