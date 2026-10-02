<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    echo "error";
    exit;
}

if (!isset($_GET['category_id'])) {
    echo "error";
    exit;
}

$categoryId = (int)$_GET['category_id'];

// Check exists
$stmt = $conn->prepare("
    SELECT CATEGORY_ID 
    FROM category 
    WHERE CATEGORY_ID = ? 
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $categoryId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

// Check whether has any sub-category (If yes, cannot delete)
$stmtChild = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM category
    WHERE PARENT_ID = ?
    AND IS_DELETED = 0
");

$stmtChild->bind_param("i", $categoryId);
$stmtChild->execute();
$childRow = $stmtChild->get_result()->fetch_assoc();

if ($childRow['total'] > 0) {
    echo "error_has_children";
    exit;
}

// Check whether has any product under this category (If yes, cannot delete)
$stmt2 = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM product_category pc
    JOIN product pr ON pr.PRODUCT_ID = pc.PRODUCT_ID AND pr.IS_DELETED = 0
    WHERE pc.CATEGORY_ID = ?
");

$stmt2->bind_param("i", $categoryId);
$stmt2->execute();
$row = $stmt2->get_result()->fetch_assoc();

if ($row['total'] > 0) {
    echo "error_used";
    exit;
}

// If no errors, delete
$stmt3 = $conn->prepare("
    UPDATE category 
    SET 
        IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE CATEGORY_ID = ?
");

$stmt3->bind_param("i", $categoryId);

if ($stmt3->execute()) {
    echo "ok";
} else {
    echo "error";
}

exit;
?>