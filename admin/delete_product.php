<?php
require_once("config.php");
session_start();

if (!isset($_GET['product_id'])) {
    echo "error";
    exit;
}

if (!isset($_SESSION['admin_id'])) {
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
    FROM order_item oi
    JOIN orders o ON o.ORDER_ID = oi.ORDER_ID
    WHERE oi.PRODUCT_ID = ?
    AND o.ORDER_STATUS <> 'COMPLETED'
");
$stmt2->bind_param("i", $productId);
$stmt2->execute();

$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// Check if used as addon by other products (if yes, cannot delete)
$stmt2b = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM product_addon
    WHERE ADDON_PRODUCT_ID = ?
    AND HOST_PRODUCT_ID <> ?
    AND IS_DELETED = 0
");
$stmt2b->bind_param("ii", $productId, $productId);
$stmt2b->execute();

$usedAsAddon = $stmt2b->get_result()->fetch_assoc();

if ($usedAsAddon['total'] > 0) {
    echo "error_used_addon";
    exit;
}
//if product has been bought as addon
$stmt2c = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM order_item_addon oia
    JOIN product_addon pa ON pa.PRODUCT_ADDON_ID = oia.PRODUCT_ADDON_ID
    JOIN order_item oi ON oi.ORDER_ITEM_ID = oia.ORDER_ITEM_ID
    JOIN orders o ON o.ORDER_ID = oi.ORDER_ID
    WHERE pa.ADDON_PRODUCT_ID = ?
    AND o.ORDER_STATUS <> 'COMPLETED'
");
$stmt2c->bind_param("i", $productId);
$stmt2c->execute();

$usedAsAddonInOrder = $stmt2c->get_result()->fetch_assoc();

if ($usedAsAddonInOrder['total'] > 0) {
    echo "error_used";
    exit;
}

$conn->begin_transaction();
try {

// if no errors, delete product itself
$stmt3 = $conn->prepare("
    UPDATE product
    SET
        IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE PRODUCT_ID = ?
");
$stmt3->bind_param("i", $productId);
if (!$stmt3->execute()) {
    throw new Exception($stmt3->error);
}

// Also, delete its product variants
$stmt4 = $conn->prepare("
    UPDATE product_variant
    SET
        IS_DELETED = 1
    WHERE PRODUCT_ID = ?
");
$stmt4->bind_param("i", $productId);
if (!$stmt4->execute()) {
    throw new Exception($stmt4->error);
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
    throw new Exception($stmt5->error);
}

// Also delete product-addon mapping
$stmt6 = $conn->prepare("
    UPDATE product_addon
    SET
        IS_DELETED = 1
    WHERE HOST_PRODUCT_ID = ?
");
$stmt6->bind_param("i", $productId);
if (!$stmt6->execute()) {
    throw new Exception($stmt6->error);
}

// Also delete product-category mapping
$stmt7 = $conn->prepare("
    DELETE FROM product_category
    WHERE PRODUCT_ID = ?
");
$stmt7->bind_param("i", $productId);
if (!$stmt7->execute()) {
    throw new Exception($stmt7->error);
}

    $conn->commit();
    echo "ok";
} catch (Throwable $e) {
    $conn->rollback();
    echo "error";
}

exit;
?>