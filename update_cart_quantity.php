<?php
require_once 'include/config.php';

// Check if item ID and new quantity are provided via POST request
if (isset($_POST['item_id']) && isset($_POST['new_qty'])) {
    $id = intval($_POST['item_id']);
    $qty = intval($_POST['new_qty']);
    
    // Ensure quantity is at least 1
    if ($qty < 1) { $qty = 1; }

    // validate product stock and daily production capacity
    $sql = "SELECT pv.VARIANT_STOCK, cap.MAX_CAKES, cap.ALREADY_BOOKED 
            FROM cart_item ci
            JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
            LEFT JOIN production_capacity cap ON cap.PRODUCTION_DATE = CURDATE()
            WHERE ci.CART_ITEM_ID = $id";
            
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    
    if ($row) {
        $stock = intval($row['VARIANT_STOCK']);
        $max_cakes = isset($row['MAX_CAKES']) ? intval($row['MAX_CAKES']) : 999;
        $already_booked = isset($row['ALREADY_BOOKED']) ? intval($row['ALREADY_BOOKED']) : 0;

        //Check if requested quantity exceeds available product stock
        if ($qty > $stock) {
            echo "out_of_stock";
            exit();
        }

        //Check if requested quantity exceeds bakery's daily production capacity
        if (($already_booked + $qty) > $max_cakes) {
            echo "out_of_capacity";
            exit();
        }
    }

    // Update the cart item quantity in the database if validations pass
    $update_sql = "UPDATE cart_item SET QUANTITY = $qty WHERE CART_ITEM_ID = $id";
    mysqli_query($conn, $update_sql);
    echo "success";
}
?>