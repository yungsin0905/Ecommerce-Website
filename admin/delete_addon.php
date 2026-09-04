<?php
require_once("config.php");
session_start();

if (!isset($_GET['addon_id'])) {
    echo "error";
    exit;
}

$addon_id = (int)$_GET['addon_id'];

// Check if exists
$stmt = $conn->prepare("
    SELECT ADD_ON_ID
    FROM add_on
    WHERE ADD_ON_ID = ?
    AND IS_DELETED = 0
");
$stmt->bind_param("i", $addon_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

// Check if used in orders (if yes, cannot delete)
$stmt2 = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM order_item_addon
    WHERE ADD_ON_ID = ?
");
$stmt2->bind_param("i", $addon_id);
$stmt2->execute();

$used = $stmt2->get_result()->fetch_assoc();

if ($used['total'] > 0) {
    echo "error_used";
    exit;
}

// if no errors, delete
$stmt3 = $conn->prepare("
    UPDATE add_on 
    SET 
        IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE ADD_ON_ID = ?
");

$stmt3->bind_param("i", $addon_id);

if ($stmt3->execute()) {
    echo "ok";
} else {
    echo "error";
}

exit;
?>