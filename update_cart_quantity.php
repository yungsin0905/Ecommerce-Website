<?php
session_start();
require_once 'include/config.php';
require_once 'include/pricing_functions.php';

if (!isset($_SESSION['CUSTOMER_ID'])) {
    echo "error";
    exit();
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// Check if item ID and new quantity are provided via POST request
if (isset($_POST['item_id']) && isset($_POST['new_qty'])) {
    $id = intval($_POST['item_id']);
    $qty = intval($_POST['new_qty']);
    
    // Ensure quantity is at least 1
    if ($qty < 1) { $qty = 1; }

    // validate the cart item belongs to this customer, and the product / variant is still valid
    $sql = "SELECT ci.CART_ID, ci.VARIANT_ID, pv.VARIANT_STOCK
            FROM cart_item ci
            JOIN cart c ON ci.CART_ID = c.CART_ID
            JOIN product p ON ci.PRODUCT_ID = p.PRODUCT_ID
            JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
            WHERE ci.CART_ITEM_ID = $id
              AND c.CUSTOMER_ID = $customer_id
              AND p.IS_DELETED = 0
              AND pv.IS_DELETED = 0
              AND pv.VARIANT_STATUS = 'Active'";

    $result = mysqli_query($conn, $sql);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    if (!$row) {
        echo "error";
        exit();
    }

    $stock = intval($row['VARIANT_STOCK']);
    $cart_id = intval($row['CART_ID']);
    $variant_id = intval($row['VARIANT_ID']);

    // quantity of the same variant already held by other items in this cart
    $others_res = mysqli_query($conn,
        "SELECT COALESCE(SUM(QUANTITY), 0) AS total
         FROM cart_item
         WHERE CART_ID = $cart_id
           AND VARIANT_ID = $variant_id
           AND CART_ITEM_ID <> $id
           AND IS_BUYNOW = 0"
    );
    $others_qty = intval(mysqli_fetch_assoc($others_res)['total'] ?? 0);

    //Check if requested quantity exceeds available product stock
    if (($qty + $others_qty) > $stock) {
        echo "out_of_stock";
        exit();
    }

    // Update the cart item quantity in the database if validations pass
    $update_sql = "UPDATE cart_item SET QUANTITY = $qty WHERE CART_ITEM_ID = $id AND CART_ID = $cart_id";
    if (!mysqli_query($conn, $update_sql)) {
        echo "error";
        exit();
    }
    echo "success";
}
?>