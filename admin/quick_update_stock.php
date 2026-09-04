<?php
require_once("config.php");

$type = $_POST['type'];
$id = $_POST['id'];
$stock = $_POST['stock'];

// validate stock
if (!ctype_digit($stock)) {
    die("Invalid stock value");
}

$stock = (int)$stock;

if ($stock < 0 || $stock > 9999) {
    die("Stock out of range");
}

// Update stock for add-on
if ($type == "ADD_ON") {

    $conn->query("
        UPDATE add_on 
        SET ADD_ON_STOCK = $stock 
        WHERE ADD_ON_ID = $id
    ");

// Update stock for product variant
} else if ($type == "VARIANT") {

    $conn->query("
        UPDATE product_variant 
        SET VARIANT_STOCK = $stock 
        WHERE VARIANT_ID = $id
    ");
}

echo "success";
?>