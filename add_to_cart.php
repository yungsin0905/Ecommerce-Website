<?php
ob_start();
session_start();  
include 'include/config.php';


file_put_contents('debug.log', print_r($_POST, true));

// 1. check login
if(!isset($_SESSION['CUSTOMER_ID'])) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Please login first']);
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];

// 2. retrieve product id
if (isset($_POST['product_id']) && !empty($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);
    $variant_id = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
    $qty = isset($_POST['quantity']) ? intval($_POST['quantity']) : 1;

    // edit mode
    $editing_cart_item_id = isset($_POST['cart_item_id'])
        ? intval($_POST['cart_item_id'])
        : 0;

     // convert in to string
    $cake_writing = !empty($_POST['cake_writing']) ? mysqli_real_escape_string($conn, $_POST['cake_writing']) : "";
    $card_text = !empty($_POST['card_message']) ? mysqli_real_escape_string($conn, $_POST['card_message']) : "";

    // handle null value
    $cake_val = $cake_writing ? "'$cake_writing'" : "NULL";
    $card_val = $card_text ? "'$card_text'" : "NULL";

    // C. process addon
    $selected_addons = $_POST['selected_addons'] ?? [];
    $addon_qtys = $_POST['addon_qty'] ?? [];

    // A. create a new data
    $cart_res = mysqli_query($conn, "SELECT CART_ID FROM cart WHERE CUSTOMER_ID = $customer_id LIMIT 1");
    if (mysqli_num_rows($cart_res) > 0) {
        $cart_id = mysqli_fetch_assoc($cart_res)['CART_ID'];
    } else {
        mysqli_query($conn, "INSERT INTO cart (CUSTOMER_ID, CREATED_AT) VALUES ($customer_id, NOW())");
        $cart_id = mysqli_insert_id($conn);
    }

    //variant stock verify is existed and enough
    $variant_res = mysqli_query($conn, 
        "SELECT VARIANT_STOCK FROM product_variant 
        WHERE VARIANT_ID = $variant_id 
        AND PRODUCT_ID = $product_id 
        AND IS_DELETED = 0 
        AND VARIANT_STATUS = 'Active' 
        LIMIT 1"
    );

    if (!$variant_res || mysqli_num_rows($variant_res) == 0) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Invalid product variant.']);
        exit();
    }

    $variant_row = mysqli_fetch_assoc($variant_res);
    $variant_stock = intval($variant_row['VARIANT_STOCK']);

     $is_buynow = isset($_POST['buy_now']) ? 1 : 0;

    //verify product stock
    if ($editing_cart_item_id > 0 || $is_buynow) {
        if ($qty > $variant_stock) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'message' => "Insufficient stock! Product stock {$variant_stock} left."
            ]);
            exit();
        }

    //add new mode
    } else {
    $cart_qty_res = mysqli_query($conn,
        "SELECT COALESCE(SUM(QUANTITY), 0) as total 
         FROM cart_item 
         WHERE CART_ID = $cart_id 
         AND VARIANT_ID = $variant_id 
         AND IS_BUYNOW = 0"
    );
    $cart_qty_row = mysqli_fetch_assoc($cart_qty_res);
    $cart_already_qty = intval($cart_qty_row['total']);

    if (($cart_already_qty + $qty) > $variant_stock) {
        $available = max(0, $variant_stock - $cart_already_qty);
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status'  => 'error',
            'message' => $available > 0
                ? "Insufficient stock! Only {$available} more can be added (stock: {$variant_stock}, in cart: {$cart_already_qty})."
                : "Insufficient stock! You have already reached the stock limit in your cart."
        ]);
        exit();
    }
}

    //verify add on stock
    foreach ($selected_addons as $addon_id) {
        $addon_id_clean = intval(trim($addon_id));
        $aqty = isset($addon_qtys[$addon_id_clean]) ? intval($addon_qtys[$addon_id_clean]) : 1;

        $addon_res = mysqli_query($conn,
            "SELECT ADD_ON_NAME, ADD_ON_STOCK 
            FROM add_on 
            WHERE ADD_ON_ID = $addon_id_clean 
            AND IS_DELETED = 0 
            AND ADD_ON_STATUS = 'Active' 
            LIMIT 1"
        );

        if (!$addon_res || mysqli_num_rows($addon_res) == 0) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid add-on selected.']);
            exit();
        }

        $addon_row = mysqli_fetch_assoc($addon_res);

        if ($aqty > intval($addon_row['ADD_ON_STOCK'])) {
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'  => 'error',
                'message' => "Add-on \"{$addon_row['ADD_ON_NAME']}\" insufficient stock, only {$addon_row['ADD_ON_STOCK']} left."
            ]);
            exit();
        }
    }

        if ($is_buynow) {
            $_SESSION['buynow_item'] = [
            'product_id'     => $product_id,
            'variant_id'     => $variant_id,
            'quantity'       => $qty,
            'cake_writing'   => $cake_writing,
            'card_message'   => $card_text,
            'selected_addons'=> $selected_addons,
            'addon_qtys'     => $addon_qtys,
        ];
        $_SESSION['checkout_mode'] = 'buynow';

        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'redirect' => 'checkout.php']);
        exit();
    }

    $existing_item_id = 0;

    //check if same item exists in cart (only for add to cart, not buy now or edit)
    if ($editing_cart_item_id == 0) {

        $check_sql = "
            SELECT CART_ITEM_ID
            FROM cart_item
            WHERE CART_ID = $cart_id
            AND PRODUCT_ID = $product_id
            AND VARIANT_ID = $variant_id
            AND IS_BUYNOW = $is_buynow
            AND (CAKE_WRITING <=> $cake_val)
        ";

        $result = mysqli_query($conn, $check_sql);

        if ($result) {

            while ($row = mysqli_fetch_assoc($result)) {

                $cart_item_id = $row['CART_ITEM_ID'];

                // existing addons
                $existing_addons = [];

                $addon_sql = "
                    SELECT ADD_ON_ID, QUANTITY
                    FROM cart_item_addon
                    WHERE CART_ITEM_ID = $cart_item_id
                ";

                $addon_res = mysqli_query($conn, $addon_sql);

                while ($a = mysqli_fetch_assoc($addon_res)) {

                    $existing_addons[] =
                        intval($a['ADD_ON_ID']) .
                        ':' .
                        intval($a['QUANTITY']);
                }

                // current addons
                $current_addons = [];

                foreach ($selected_addons as $addon_id) {

                    $addon_id_clean = intval(trim($addon_id));

                    $aqty = isset($addon_qtys[$addon_id_clean])
                        ? intval($addon_qtys[$addon_id_clean])
                        : 1;

                    $current_addons[] =
                        $addon_id_clean . ':' . $aqty;
                }

                sort($existing_addons);
                sort($current_addons);

                // same addon combination
                if ($existing_addons == $current_addons) {

                    $existing_item_id = $cart_item_id;
                    break;
                }
            }
        }
    }

    //edit item
    if ($editing_cart_item_id > 0) {

        $update_sql = "
            UPDATE cart_item SET
            VARIANT_ID = $variant_id,
            QUANTITY = $qty,
            CAKE_WRITING = $cake_val,
            IS_BUYNOW = $is_buynow
            WHERE CART_ITEM_ID = $editing_cart_item_id
        ";

        if (!mysqli_query($conn, $update_sql)) {

            ob_end_clean();
            header('Content-Type: application/json');

            echo json_encode([
                'status' => 'error',
                'message' => 'Database UPDATE error: '
                    . mysqli_error($conn)
            ]);

            exit();
        }

        // delete old addons
        mysqli_query(
            $conn,
            "DELETE FROM cart_item_addon
             WHERE CART_ITEM_ID = $editing_cart_item_id"
        );

        // insert new addons
        foreach ($selected_addons as $addon_id) {
            $addon_id = intval($addon_id);
            $aqty = isset($addon_qtys[$addon_id]) ? intval($addon_qtys[$addon_id]) : 1;
            $addon_sql = "INSERT INTO cart_item_addon (CART_ITEM_ID, ADD_ON_ID, QUANTITY, CARD_TEXT, CREATED_AT) 
              VALUES ($editing_cart_item_id, $addon_id, $aqty, $card_val, NOW())";
            mysqli_query($conn, $addon_sql); 
        }

    //same item exists, just update quantity
    } else if ($existing_item_id > 0) {

        if (!mysqli_query(
            $conn,
            "UPDATE cart_item
             SET QUANTITY = QUANTITY + $qty
             WHERE CART_ITEM_ID = $existing_item_id"
        )) {

            ob_end_clean();
            header('Content-Type: application/json');

            echo json_encode([
                'status' => 'error',
                'message' => 'Database UPDATE error: '
                    . mysqli_error($conn)
            ]);

            exit();
        }

    //new item
    } else {

        $insert_item_sql = "
            INSERT INTO cart_item (
                CART_ID,
                PRODUCT_ID,
                VARIANT_ID,
                QUANTITY,
                CAKE_WRITING,
                IS_BUYNOW,
                CREATED_AT
            )
            VALUES (
                $cart_id,
                $product_id,
                $variant_id,
                $qty,
                $cake_val,
                $is_buynow,
                NOW()
            )
        ";

        if (!mysqli_query($conn, $insert_item_sql)) {

            ob_end_clean();
            header('Content-Type: application/json');

            echo json_encode([
                'status' => 'error',
                'message' =>
                    'Database INSERT cart_item error: '
                    . mysqli_error($conn)
            ]);

            exit();
        }

        $cart_item_id = mysqli_insert_id($conn);

        // insert addons
        foreach ($selected_addons as $addon_id) {

            $addon_id_clean = intval(trim($addon_id));

            $aqty = isset($addon_qtys[$addon_id_clean])
                ? intval($addon_qtys[$addon_id_clean])
                : 1;

            $addon_sql = "
                INSERT INTO cart_item_addon
                (
                    CART_ITEM_ID,
                    ADD_ON_ID,
                    QUANTITY,
                    CARD_TEXT,
                    CREATED_AT
                )
                VALUES
                (
                    $cart_item_id,
                    $addon_id_clean,
                    $aqty,
                    $card_val,
                    NOW()
                )
            ";

            mysqli_query($conn, $addon_sql);
        }
    }
    // D. response success
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success', 'message' => 'Added to cart successfully!']);
    exit();

} else {
    // 3. if haven't receive product id
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request: Missing product ID']);
    exit();
}