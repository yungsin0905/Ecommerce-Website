<?php
require_once("config.php");
session_start();

if (!isset($_GET['product_id'])) {
    echo "error";
    exit;
}

$productId = (int)$_GET['product_id'];

// Check exists
$stmt = $conn->prepare("
    SELECT PRODUCT_ID
    FROM product
    WHERE PRODUCT_ID = ?
    AND IS_DELETED = 0
");
$stmt->bind_param("i", $productId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

// Check if used in orders (if yes, cannot delete)
$stmt2 = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM order_item
    WHERE PRODUCT_ID = ?
");
$stmt2->bind_param("i", $productId);
$stmt2->execute();

$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// if no errors, delete product itself
$stmt3 = $conn->prepare("
    UPDATE product
    SET
        IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE PRODUCT_ID = ?
");
$stmt3->bind_param("i", $productId);

$success = $stmt3->execute();

// Also, delete its product variants
$stmt4 = $conn->prepare("
    UPDATE product_variant
    SET
        IS_DELETED = 1
    WHERE PRODUCT_ID = ?
");
$stmt4->bind_param("i", $productId);

if (!$stmt4->execute()) {
    $success = false;
}

// Also, delete its product images (Extra images)
$stmt5 = $conn->prepare("
    UPDATE product_images
    SET
        IS_DELETED = 1
    WHERE PRODUCT_ID = ?
");
$stmt5->bind_param("i", $productId);

if (!$stmt5->execute()) {
    $success = false;
}

// Also delete product-addon mapping
$stmt6 = $conn->prepare("
    DELETE FROM product_addon
    WHERE PRODUCT_ID = ?
");
$stmt6->bind_param("i", $productId);

if (!$stmt6->execute()) {
    $success = false;
}

echo $success ? "ok" : "error";

exit;
?>