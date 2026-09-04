<?php
session_start();
require_once 'include/config.php';

// 1. Ensure user already login
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// 2. collect form data
$selected_item_ids = $_POST['selected_items'] ?? [];
$custom_ids        = $_POST['custom_ids']     ?? [];
$address_id        = intval($_POST['address_id']  ?? 0);
$delivery_date     = $_POST['delivery_date']  ?? date('Y-m-d');
$delivery_time     = $_POST['delivery_time']  ?? '';
$voucher_id        = intval($_POST['voucher_id'] ?? 0);
$payment_method    = $_POST['payment_method'] ?? '';
$ewallet_provider  = $_POST['ewallet_provider'] ?? '';

// recipient info
$full_name        = mysqli_real_escape_string($conn, $_POST['full_name']        ?? '');
$name_parts       = explode(' ', $full_name, 2);
$first_name       = $name_parts[0] ?? '';
$last_name        = $name_parts[1] ?? '';    
$phone_no         = mysqli_real_escape_string($conn, $_POST['phone']            ?? '');
$shipping_address = mysqli_real_escape_string($conn, $_POST['shipping_address'] ?? '');
$city             = mysqli_real_escape_string($conn, $_POST['city']             ?? '');
$postcode         = mysqli_real_escape_string($conn, $_POST['postcode']         ?? '');
$state            = mysqli_real_escape_string($conn, $_POST['state']            ?? '');

// 3. No items selected — allow buynow mode to pass through
$is_buynow = (isset($_POST['checkout_mode']) && $_POST['checkout_mode'] === 'buynow') ||
             (isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow');

if (empty($selected_item_ids) && empty($custom_ids) && !$is_buynow) {
    header("Location: shopping_cart.php");
    exit;
}

// 4. Retrieve customer information
$user_result = mysqli_query($conn,
    "SELECT * FROM customer WHERE CUSTOMER_ID = $customer_id"
);
$user_data = mysqli_fetch_assoc($user_result);

// 5. Calculate Subtotal
$sub_total = 0.00;  

if ($is_buynow && empty($selected_item_ids) && isset($_SESSION['buynow_item'])) {
    //Process buy now session item pricing
    $item       = $_SESSION['buynow_item'];
    $variant_id = intval($item['variant_id']);
    $qty        = intval($item['quantity']);

    $v_res = mysqli_query($conn, "SELECT VARIANT_PRICE FROM product_variant WHERE VARIANT_ID = $variant_id");
    if ($v_row = mysqli_fetch_assoc($v_res)) {
        $sub_total += floatval($v_row['VARIANT_PRICE']) * $qty;
    }

    foreach (($item['selected_addons'] ?? []) as $addon_id) {
        $addon_id  = intval(trim($addon_id));
        $aqty      = intval($item['addon_qtys'][$addon_id] ?? 1);
        $a_res     = mysqli_query($conn, "SELECT ADD_ON_PRICE FROM add_on WHERE ADD_ON_ID = $addon_id AND IS_DELETED = 0");
        if ($a_row = mysqli_fetch_assoc($a_res)) {
            $sub_total += floatval($a_row['ADD_ON_PRICE']) * $aqty;
        }
    }
}

if (!empty($selected_item_ids)) {
    //Process cart items pricing
    $ids_string  = implode(',', array_map('intval', $selected_item_ids));
    $item_result = mysqli_query($conn,
       "SELECT ci.QUANTITY, pv.VARIANT_PRICE
        FROM cart_item ci
        JOIN cart c ON ci.CART_ID = c.CART_ID
        LEFT JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
        WHERE ci.CART_ITEM_ID IN ($ids_string)
          AND c.CUSTOMER_ID = $customer_id"
    );
    while ($item = mysqli_fetch_assoc($item_result)) {
        $sub_total += floatval($item['VARIANT_PRICE']) * intval($item['QUANTITY']);
    }

    foreach ($selected_item_ids as $cart_item_id) {
        $cart_item_id = intval($cart_item_id);
        $addon_result = mysqli_query($conn,
            "SELECT cia.QUANTITY, ao.ADD_ON_PRICE
             FROM cart_item_addon cia
             JOIN add_on ao ON cia.ADD_ON_ID = ao.ADD_ON_ID
             WHERE cia.CART_ITEM_ID = $cart_item_id"
        );
        while ($addon = mysqli_fetch_assoc($addon_result)) {
            $sub_total += floatval($addon['ADD_ON_PRICE']) * intval($addon['QUANTITY']);
        }
    }
}

// Process Custom order pricing (quoted price is total, not per quantity)
if (!empty($custom_ids)) {
    $c_ids_string = implode(',', array_map('intval', $custom_ids));
    $c_result     = mysqli_query($conn,
        "SELECT QUOTED_PRICE
         FROM custom
         WHERE CUSTOM_ID IN ($c_ids_string)
           AND CUSTOMER_ID = $customer_id
           AND IS_DELETED = 0"
    );
    while ($c_item = mysqli_fetch_assoc($c_result)) {
        $sub_total += floatval($c_item['QUOTED_PRICE']);
    }
}

// 6. Check delivery fee
$shipping_fee = 0.00;
if ($address_id > 0) {
    $addr_result = mysqli_query($conn,
        "SELECT dc.DELIVERY_FEE
         FROM address a
         LEFT JOIN delivery_coverage dc
                ON a.POSTCODE = dc.POSTCODE
               AND dc.STATUS = 'Active'
         WHERE a.ADDRESS_ID  = $address_id
           AND a.CUSTOMER_ID = $customer_id"
    );
    if ($addr_row = mysqli_fetch_assoc($addr_result)) {
        $shipping_fee = floatval($addr_row['DELIVERY_FEE'] ?? 0);
    }
} elseif (!empty($custom_ids)) {
    // Custom cake: address_id is 0, use hardcoded postcode 81000
    $fee_res = mysqli_query($conn,
        "SELECT DELIVERY_FEE FROM delivery_coverage 
         WHERE POSTCODE = '81000' AND STATUS = 'Active' LIMIT 1"
    );
    if ($fee_row = mysqli_fetch_assoc($fee_res)) {
        $shipping_fee = floatval($fee_row['DELIVERY_FEE']);
    }
}

// 7. Validate voucher
$voucher_data             = null;
$verified_discount_amount = 0.00;
$voucher_discount_rate    = 0.00;
$voucher_name_snap        = '';
$voucher_code_snap        = '';

if ($voucher_id > 0) {
    $v_result = mysqli_query($conn,
        "SELECT v.*, cv.USED_COUNT AS CUSTOMER_USED_COUNT
         FROM voucher v
         INNER JOIN customer_voucher cv ON v.VOUCHER_ID = cv.VOUCHER_ID
         WHERE v.VOUCHER_ID   = $voucher_id
           AND cv.CUSTOMER_ID = $customer_id
           AND v.VOUCHER_STATUS = 'Active'
           AND v.IS_DELETED   = 0"
    );
    if ($v_row = mysqli_fetch_assoc($v_result)) {
        $today = new DateTime();

        // Check date ranges and usage limits
        $start_ok = true;
        if (!empty($v_row['START_DATE']) && $v_row['START_DATE'] !== '0000-00-00 00:00:00') {
            $start_ok = ($today >= new DateTime($v_row['START_DATE']));
        }

        $expiry_ok = true;
        if (!empty($v_row['EXPIRY_DATE']) &&
            $v_row['EXPIRY_DATE'] !== '0000-00-00 00:00:00' &&
            $v_row['EXPIRY_DATE'] !== '0000-00-00') {
            $expiry_ok = ($today <= new DateTime($v_row['EXPIRY_DATE']));
        }

        $global_ok = ($v_row['MAX_USAGE'] == -1 || $v_row['USED_COUNT'] < $v_row['MAX_USAGE']);
        $user_ok   = ($v_row['PER_USER_LIMIT'] == -1 || intval($v_row['CUSTOMER_USED_COUNT']) < $v_row['PER_USER_LIMIT']);
        $spend_ok  = ($sub_total >= floatval($v_row['MIN_SPEND']));

        if ($start_ok && $expiry_ok && $global_ok && $user_ok && $spend_ok) {
            $voucher_data             = $v_row;
            $voucher_discount_rate    = floatval($v_row['DISCOUNT_RATE']);
            $verified_discount_amount = $sub_total * ($voucher_discount_rate / 100);
            $voucher_name_snap        = $v_row['VOUCHER_NAME'];
            $voucher_code_snap        = $v_row['VOUCHER_CODE'];
        } else {
            $voucher_id = 0;
        }
    }
}

// 8. Calculate final amount
$final_amount = $sub_total - $verified_discount_amount + $shipping_fee;

// 9. Check wallet balance
if ($payment_method === 'Balance') {
    if (floatval($user_data['WALLET_BALANCE']) < $final_amount) {
        echo "<script>
            alert('Insufficient wallet balance!');
            window.history.back();
        </script>";
        exit;
    }
}

// 10. Determine payment method name
$method_map = [
    'Balance'  => 'WALLET',
    'Card'     => 'CREDIT OR DEBIT CARD',
    'FPX'      => 'FPX',
    'E-wallet' => 'E-WALLET',
];
$payment_method_to_save = $method_map[$payment_method] ?? $payment_method;

// 11. format delivery time slot
$slot_id = 0;
$delivery_slot_snapshot = $delivery_time;

if (!empty($delivery_time)) {
    $dt_esc = mysqli_real_escape_string($conn, $delivery_time);

    $slot_q = mysqli_query($conn,
        "SELECT SLOT_ID,
                TIME_FORMAT(START_TIME, '%h:%i %p') AS START_SHORT,
                TIME_FORMAT(END_TIME,   '%h:%i %p') AS END_SHORT
         FROM delivery_slots
         WHERE STATUS = 'Active'
         AND (
             START_TIME = '$dt_esc'
             OR TIME_FORMAT(START_TIME, '%H:%i') = LEFT('$dt_esc', 5)
             OR TIME_FORMAT(START_TIME, '%H:%i:%s') = '$dt_esc'
         )
         LIMIT 1"
    );
    if ($slot_row = mysqli_fetch_assoc($slot_q)) {
        $slot_id = intval($slot_row['SLOT_ID']);
        $delivery_slot_snapshot = $slot_row['START_SHORT'] . ' - ' . $slot_row['END_SHORT'];
    }
}

// 12. ORDER_TYPE
$order_type = !empty($custom_ids) ? 'Custom' : 'Normal';

// 13. Check delivery coverage ID
$coverage_id = 0;
if ($address_id > 0) {
    $addr_q = mysqli_query($conn,
        "SELECT POSTCODE FROM address
         WHERE ADDRESS_ID = $address_id AND CUSTOMER_ID = $customer_id"
    );
    if ($addr_row = mysqli_fetch_assoc($addr_q)) {
        $pc    = mysqli_real_escape_string($conn, $addr_row['POSTCODE']);
        $cov_q = mysqli_query($conn,
            "SELECT COVERAGE_ID FROM delivery_coverage
             WHERE POSTCODE = '$pc' AND STATUS = 'Active'
             LIMIT 1"
        );
        if ($cov_row = mysqli_fetch_assoc($cov_q)) {
            $coverage_id = intval($cov_row['COVERAGE_ID']);
        }
    }
}
if ($coverage_id === 0 && !empty($postcode)) {
    $pc_esc = mysqli_real_escape_string($conn, $postcode);
    $cov_q  = mysqli_query($conn,
        "SELECT COVERAGE_ID FROM delivery_coverage
         WHERE POSTCODE = '$pc_esc' AND STATUS = 'Active'
         LIMIT 1"
    );
    if ($cov_row = mysqli_fetch_assoc($cov_q)) {
        $coverage_id = intval($cov_row['COVERAGE_ID']);
    }
}

// 14. Initialize Order Snapshot Data
$order_no = 'ORD-' . strtoupper(uniqid());

$delivery_address_snapshot = trim("$first_name $last_name") . ', ' .
                             $shipping_address . ', ' .
                             $city . ', ' .
                             $postcode . ', ' .
                             $state;

// Before starting the payment process, check if the custom cake is still available to pay.
if (!empty($custom_ids)) {

    // Convert custom_ids array to "1,2,3" string for use in SQL
    // Using a different variable name to avoid confusion with $c_ids_string
    $check_ids_string = implode(',', array_map('intval', $custom_ids));

    // Check if these custom cakes have already been paid (IS_DELETED = 0 means not yet paid)
    $check_result = mysqli_query($conn,
        "SELECT COUNT(*) AS cnt 
         FROM custom
         WHERE CUSTOM_ID IN ($check_ids_string)
           AND CUSTOMER_ID = $customer_id
           AND IS_DELETED = 0"
    );
    $check_row = mysqli_fetch_assoc($check_result);

     

    // If cnt = 0, these custom cakes have already been paid (IS_DELETED is already 1)
    if (intval($check_row['cnt']) === 0) {

         // Try to find the existing successful order in the orders table
        $existing_result = mysqli_query($conn,
            "SELECT o.ORDER_ID, o.ORDER_NO 
             FROM orders o
             JOIN order_item oi ON o.ORDER_ID = oi.ORDER_ID
             WHERE oi.CUSTOM_ID IN ($check_ids_string)
               AND o.CUSTOMER_ID = $customer_id
             ORDER BY o.CREATED_AT DESC 
             LIMIT 1"
        );
        $existing_order = mysqli_fetch_assoc($existing_result);

        if ($existing_order) {
            // Found the existing order — redirect to the confirmation page to show it
            header("Location: order_confirmation.php?order_id=" . $existing_order['ORDER_ID'] . "&order_no=" . urlencode($existing_order['ORDER_NO']));
        } else {
            // order not found
            header("Location: order_confirmation.php?failed=1&type=custom");
        }
        exit;
    }
}

// 15. Begin transaction
mysqli_begin_transaction($conn);

try {
    // a. Deduct wallet balance if paying by Balance
    $before_balance        = 0.00;
    $new_balance           = 0.00;
    $wallet_transaction_id = 0;

    if ($payment_method === 'Balance') {
        $before_balance = floatval($user_data['WALLET_BALANCE']);
        $new_balance    = $before_balance - $final_amount;

        mysqli_query($conn,
            "UPDATE customer
             SET WALLET_BALANCE = $new_balance
             WHERE CUSTOMER_ID  = $customer_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to deduct wallet balance: " . mysqli_error($conn));
        }
    }

    // b. Insert shipping record first
    mysqli_query($conn,
        "INSERT INTO shipping (DELIVERY_STATUS, SHIPPING_DATE, SHIPPING_TIME)
         VALUES ('PENDING', NULL, NULL)"
    );
    
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to insert shipping record: " . mysqli_error($conn));
    }
    $shipping_id = mysqli_insert_id($conn);

    // c. Insert order record
    $voucher_id_val   = ($voucher_id > 0) ? $voucher_id : 'NULL';
    $address_id_val   = ($address_id > 0) ? $address_id : 'NULL';
    $slot_id_val      = ($slot_id > 0)    ? $slot_id    : 'NULL';
    $discount_rate_f  = number_format($voucher_discount_rate,    2, '.', '');
    $discount_amt_f   = number_format($verified_discount_amount, 2, '.', '');
    $sub_total_f      = number_format($sub_total,    2, '.', '');
    $final_amount_f   = number_format($final_amount, 2, '.', '');
    $shipping_fee_f   = number_format($shipping_fee, 2, '.', '');

    $order_no_esc      = mysqli_real_escape_string($conn, $order_no);
    $order_type_esc    = mysqli_real_escape_string($conn, $order_type);
    $delivery_date_esc = mysqli_real_escape_string($conn, $delivery_date);
    $delivery_slot_esc = mysqli_real_escape_string($conn, $delivery_slot_snapshot);
    $delivery_addr_esc = mysqli_real_escape_string($conn, $delivery_address_snapshot);
    $voucher_name_esc  = mysqli_real_escape_string($conn, $voucher_name_snap);
    $voucher_code_esc  = mysqli_real_escape_string($conn, $voucher_code_snap);
    $cust_name_snap    = mysqli_real_escape_string($conn, trim("$first_name $last_name"));
    $cust_phone_snap   = mysqli_real_escape_string($conn, $phone_no);
    $cust_email_snap   = mysqli_real_escape_string($conn, $user_data['EMAIL']);

    mysqli_query($conn,
        "INSERT INTO orders (
            CUSTOMER_ID, ADDRESS_ID, VOUCHER_ID, PAYMENT_ID,
            SLOT_ID, COVERAGE_ID, SHIPPING_ID,
            SUB_TOTAL, TOTAL_AMOUNT, SHIPPING_FEE_SNAPSHOT,
            ORDER_NO, ORDER_STATUS, ORDER_TYPE,
            DELIVERY_DATE, DELIVERY_SLOT_SNAPSHOT, DELIVERY_ADDRESS_SNAPSHOT,
            VOUCHER_CODE_SNAPSHOT, VOUCHER_NAME_SNAPSHOT,
            DISCOUNT_RATE_SNAPSHOT, DISCOUNT_AMOUNT_SNAPSHOT,
            CUSTOMER_NAME_SNAPSHOT, CUSTOMER_PHONE_SNAPSHOT, CUSTOMER_EMAIL_SNAPSHOT,
            CREATED_AT
         ) VALUES (
            $customer_id, $address_id_val, $voucher_id_val, NULL,
            $slot_id_val, $coverage_id, $shipping_id,
            $sub_total_f, $final_amount_f, $shipping_fee_f,
            '$order_no_esc', 'PROCESSING', '$order_type_esc',
            '$delivery_date_esc', '$delivery_slot_esc', '$delivery_addr_esc',
            '$voucher_code_esc', '$voucher_name_esc',
            $discount_rate_f, $discount_amt_f,
            '$cust_name_snap', '$cust_phone_snap', '$cust_email_snap',
            NOW()
         )"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to insert order: " . mysqli_error($conn));
    }
    $order_id = mysqli_insert_id($conn);

    // link order to shipping record
    mysqli_query($conn,
        "UPDATE shipping SET ORDER_ID = $order_id WHERE SHIPPING_ID = $shipping_id"
    );

    // d. Insert payment
    $pay_method_esc = mysqli_real_escape_string($conn, $payment_method_to_save);
    $pay_amount     = number_format($final_amount, 2, '.', '');

    mysqli_query($conn,
        "INSERT INTO payment
           (ORDER_ID, PAYMENT_METHODS, PAYMENT_AMOUNT, PAYMENT_STATUS, TRANSACTION_DATE)
        VALUES
           ($order_id, '$pay_method_esc', $pay_amount, 'SUCCESS', NOW())"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to insert payment record: " . mysqli_error($conn));
    }
    $payment_id = mysqli_insert_id($conn);

    // Update order with real PAYMENT_ID
    mysqli_query($conn,
        "UPDATE orders SET PAYMENT_ID = $payment_id WHERE ORDER_ID = $order_id"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to link payment to order: " . mysqli_error($conn));
    }

    // e. Wallet transaction record
    if ($payment_method === 'Balance') {
        $before_bal_f = number_format($before_balance, 2, '.', '');
        $after_bal_f  = number_format($new_balance,    2, '.', '');
        $pay_amt_f    = number_format($final_amount,   2, '.', '');

        mysqli_query($conn,
            "INSERT INTO wallet_transaction (CUSTOMER_ID, ORDER_ID, TYPE, TOPUP_METHODS, AMOUNT, BEFORE_BALANCE, AFTER_BALANCE, CREATED_AT)
                VALUES ($customer_id, $order_id, 'PAYMENT', 'NONE', $pay_amt_f, $before_bal_f, $after_bal_f, NOW())");
        
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to insert wallet transaction: " . mysqli_error($conn));
        }
        $wallet_transaction_id = mysqli_insert_id($conn);

        mysqli_query($conn,
            "UPDATE payment
             SET WALLET_TRANSACTION_ID = $wallet_transaction_id
             WHERE PAYMENT_ID = $payment_id"
        );
    }

    // f(i). Insert pre-made cake order items
    if (!empty($selected_item_ids)) {
        $ids_string  = implode(',', array_map('intval', $selected_item_ids));
        $item_result = mysqli_query($conn,
            "SELECT ci.*, p.PRODUCT_NAME, pv.VARIANT_SIZE, pv.VARIANT_PRICE
             FROM cart_item ci
             LEFT JOIN product p          ON ci.PRODUCT_ID  = p.PRODUCT_ID
             LEFT JOIN product_variant pv ON ci.VARIANT_ID  = pv.VARIANT_ID
             WHERE ci.CART_ITEM_ID IN ($ids_string)"
        );

        while ($item = mysqli_fetch_assoc($item_result)) {
            $product_id   = intval($item['PRODUCT_ID']);
            $variant_id   = intval($item['VARIANT_ID']);
            $qty          = intval($item['QUANTITY']);
            $cart_item_id = intval($item['CART_ITEM_ID']);

            $cake_writing = mysqli_real_escape_string($conn, $item['CAKE_WRITING'] ?? '');
            $p_name_snap  = mysqli_real_escape_string($conn, $item['PRODUCT_NAME']);
            $v_size_snap  = mysqli_real_escape_string($conn, $item['VARIANT_SIZE']);
            $v_price_snap = number_format(floatval($item['VARIANT_PRICE']), 2, '.', '');

            mysqli_query($conn,
                "INSERT INTO order_item
                     (ORDER_ID, PRODUCT_ID, VARIANT_ID, CUSTOM_ID, QUANTITY,
                      CAKE_WRITING, CARD_TEXT,
                      PRODUCT_NAME_SNAPSHOT, VARIANT_SIZE_SNAPSHOT, VARIANT_PRICE_SNAPSHOT)
                 VALUES
                     ($order_id, $product_id, $variant_id, NULL, $qty,
                      '$cake_writing', '',
                      '$p_name_snap', '$v_size_snap', $v_price_snap)"
            );
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to insert order item: " . mysqli_error($conn));
            }
            $order_item_id = mysqli_insert_id($conn);

            //update product stock
            mysqli_query($conn,
                "UPDATE product_variant
                 SET VARIANT_STOCK = VARIANT_STOCK - $qty
                 WHERE VARIANT_ID  = $variant_id"
            );

            mysqli_query($conn,
                "UPDATE product
                 SET SALES_COUNT = SALES_COUNT + $qty
                 WHERE PRODUCT_ID = $product_id"
            );

            //process addons for cart items
            $addon_result = mysqli_query($conn,
                "SELECT cia.*, ao.ADD_ON_NAME, ao.ADD_ON_PRICE
                 FROM cart_item_addon cia
                 JOIN add_on ao ON cia.ADD_ON_ID = ao.ADD_ON_ID
                 WHERE cia.CART_ITEM_ID = $cart_item_id"
            );

            while ($addon = mysqli_fetch_assoc($addon_result)) {
                $addon_id       = intval($addon['ADD_ON_ID']);
                $addon_qty      = intval($addon['QUANTITY']);
                $addon_name_esc = mysqli_real_escape_string($conn, $addon['ADD_ON_NAME']);
                $addon_price_f  = number_format(floatval($addon['ADD_ON_PRICE']), 2, '.', '');
                $card_text_esc  = mysqli_real_escape_string($conn, $addon['CARD_TEXT'] ?? '');

                mysqli_query($conn,
                    "INSERT INTO order_item_addon
                         (ORDER_ITEM_ID, ADD_ON_ID, QUANTITY,
                          ADDON_NAME_SNAPSHOT, ADDON_PRICE_SNAPSHOT)
                     VALUES
                         ($order_item_id, $addon_id, $addon_qty,
                          '$addon_name_esc', $addon_price_f)"
                );
                if (mysqli_errno($conn)) {
                    throw new Exception("Failed to insert add-on: " . mysqli_error($conn));
                }

                mysqli_query($conn,
                    "UPDATE add_on SET ADD_ON_STOCK = ADD_ON_STOCK - $addon_qty WHERE ADD_ON_ID = $addon_id");

                if (mysqli_errno($conn)) {
                     throw new Exception("Failed to deduct add-on stock: " . mysqli_error($conn));
                }

                if (!empty($addon['CARD_TEXT'])) {
                    mysqli_query($conn, "UPDATE order_item SET CARD_TEXT = '$card_text_esc' WHERE ORDER_ITEM_ID = $order_item_id");
                }
            }

            // Cleanup cart
            mysqli_query($conn, "DELETE FROM cart_item_addon WHERE CART_ITEM_ID = $cart_item_id");
            mysqli_query($conn, "DELETE FROM cart_item WHERE CART_ITEM_ID = $cart_item_id");
        }
    }

    // f(ii). Insert custom cake order items
    if (!empty($custom_ids)) {
        $c_ids_string = implode(',', array_map('intval', $custom_ids));
        $c_result     = mysqli_query($conn,
            "SELECT * FROM custom
             WHERE CUSTOM_ID IN ($c_ids_string)
               AND CUSTOMER_ID = $customer_id
               AND IS_DELETED  = 0"
        );

        while ($c_item = mysqli_fetch_assoc($c_result)) {
            $custom_id  = intval($c_item['CUSTOM_ID']);
            $qty        = intval($c_item['QUANTITY']);
            $price_snap = number_format(floatval($c_item['QUOTED_PRICE']), 2, '.', '');
            $name_snap  = mysqli_real_escape_string($conn, $c_item['STYLE_NAME_SNAPSHOT'] ?? 'Custom Cake');
            $size_snap  = mysqli_real_escape_string($conn, $c_item['SIZE'] ?? '');

            mysqli_query($conn,
                "INSERT INTO order_item
                     (ORDER_ID, PRODUCT_ID, VARIANT_ID, CUSTOM_ID, QUANTITY,
                      CAKE_WRITING, CARD_TEXT,
                      PRODUCT_NAME_SNAPSHOT, VARIANT_SIZE_SNAPSHOT, VARIANT_PRICE_SNAPSHOT)
                 VALUES
                     ($order_id, NULL, NULL, $custom_id, $qty,
                      '', '',
                      '$name_snap', '$size_snap', $price_snap)"
            );
            if (mysqli_errno($conn)) {
                throw new Exception("Inserting custom cake order item failed: " . mysqli_error($conn));
            }

            mysqli_query($conn,
                "UPDATE custom SET STATUS = 'Accepted' WHERE CUSTOM_ID = $custom_id"
            );
        }
    }

    // f(iii). Insert buynow order item
    if ($is_buynow && empty($selected_item_ids) && isset($_SESSION['buynow_item'])) {
       $item  = $_SESSION['buynow_item'];
       $variant_id = intval($item['variant_id']);
       $qty  = intval($item['quantity']);

       $prod_res = mysqli_query($conn, "SELECT p.PRODUCT_NAME, p.PRODUCT_ID, pv.VARIANT_SIZE, pv.VARIANT_PRICE
                                        FROM product_variant pv
                                        JOIN product p ON pv.PRODUCT_ID = p.PRODUCT_ID
                                        WHERE pv.VARIANT_ID = $variant_id AND p.IS_DELETED = 0 LIMIT 1");
       $prod_row = mysqli_fetch_assoc($prod_res);

       if ($prod_row) {
           $product_id   = intval($prod_row['PRODUCT_ID']);
           $p_name_snap  = mysqli_real_escape_string($conn, $prod_row['PRODUCT_NAME']);
           $v_size_snap  = mysqli_real_escape_string($conn, $prod_row['VARIANT_SIZE']);
           $v_price_snap = number_format(floatval($prod_row['VARIANT_PRICE']), 2, '.', '');
           $cake_writing = mysqli_real_escape_string($conn, $item['cake_writing'] ?? '');

           mysqli_query($conn,
               "INSERT INTO order_item
                    (ORDER_ID, PRODUCT_ID, VARIANT_ID, CUSTOM_ID, QUANTITY,
                     CAKE_WRITING, CARD_TEXT,
                     PRODUCT_NAME_SNAPSHOT, VARIANT_SIZE_SNAPSHOT, VARIANT_PRICE_SNAPSHOT)
                VALUES
                    ($order_id, $product_id, $variant_id, NULL, $qty,
                    '$cake_writing', '',
                    '$p_name_snap', '$v_size_snap', $v_price_snap)"
           );
           if (mysqli_errno($conn)) {
               throw new Exception("Failed to insert buynow order item: " . mysqli_error($conn));
           }
           $order_item_id = mysqli_insert_id($conn);

           mysqli_query($conn, "UPDATE product_variant SET VARIANT_STOCK = VARIANT_STOCK - $qty WHERE VARIANT_ID = $variant_id");
           mysqli_query($conn, "UPDATE product SET SALES_COUNT = SALES_COUNT + $qty WHERE PRODUCT_ID = $product_id");

           foreach (($item['selected_addons'] ?? []) as $addon_id) {
               $addon_id  = intval(trim($addon_id));
               $aqty      = intval($item['addon_qtys'][$addon_id] ?? 1);
               $a_res     = mysqli_query($conn, "SELECT * FROM add_on WHERE ADD_ON_ID = $addon_id AND IS_DELETED = 0");
               $a_row     = mysqli_fetch_assoc($a_res);
               if ($a_row) {
                   $addon_name_esc = mysqli_real_escape_string($conn, $a_row['ADD_ON_NAME']);
                   $addon_price_f  = number_format(floatval($a_row['ADD_ON_PRICE']), 2, '.', '');
                   $card_text_esc  = mysqli_real_escape_string($conn, $item['card_message'] ?? '');
 
                   mysqli_query($conn,
                       "INSERT INTO order_item_addon
                            (ORDER_ITEM_ID, ADD_ON_ID, QUANTITY, ADDON_NAME_SNAPSHOT, ADDON_PRICE_SNAPSHOT)
                        VALUES
                            ($order_item_id, $addon_id, $aqty, '$addon_name_esc', $addon_price_f)"
                   );
                   mysqli_query($conn, "UPDATE add_on SET ADD_ON_STOCK = ADD_ON_STOCK - $aqty WHERE ADD_ON_ID = $addon_id");
               }
           }
       }

       unset($_SESSION['buynow_item']);
       unset($_SESSION['checkout_mode']);
   }

    // g. Update production capacity for the specific delivery date
   $total_capacity_cakes = 0;
   $today_str = date('Y-m-d');

   if ($is_buynow || !empty($selected_item_ids)) {
       $premade_qty_q = mysqli_query($conn,
           "SELECT SUM(QUANTITY) AS TQ
            FROM order_item
            WHERE ORDER_ID = $order_id AND CUSTOM_ID IS NULL"
       );
       if ($premade_qty_row = mysqli_fetch_assoc($premade_qty_q)) {
           $total_capacity_cakes += intval($premade_qty_row['TQ']);
       }
   }

   if (($total_capacity_cakes > 0) && ($delivery_date !== $today_str)){
       $production_date_esc = mysqli_real_escape_string($conn, $delivery_date);
       mysqli_query($conn,
           "UPDATE production_capacity
            SET ALREADY_BOOKED = ALREADY_BOOKED + $total_capacity_cakes
            WHERE PRODUCTION_DATE = '$production_date_esc'"
       );
       if (mysqli_errno($conn)) {
            throw new Exception("Capacity update failed: " . mysqli_error($conn));
       }
    }

    // h. Update voucher usage count
    if ($voucher_id > 0 && $voucher_data !== null) {
        mysqli_query($conn,
            "UPDATE voucher
             SET USED_COUNT = USED_COUNT + 1
             WHERE VOUCHER_ID = $voucher_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to update global voucher usage: " . mysqli_error($conn));
        }

        mysqli_query($conn,
            "UPDATE customer_voucher
             SET USED_COUNT   = USED_COUNT + 1,
                 LAST_USED_AT = NOW()
             WHERE CUSTOMER_ID = $customer_id
               AND VOUCHER_ID  = $voucher_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to update customer voucher usage: " . mysqli_error($conn));
        }
    }

    // i. Update total spending and membership tier
    $spend_f = number_format($final_amount, 2, '.', '');

    mysqli_query($conn,
        "UPDATE customer
         SET TOTAL_SPENT = TOTAL_SPENT + $spend_f
         WHERE CUSTOMER_ID = $customer_id"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to update total spending: " . mysqli_error($conn));
    }

    $spent_result    = mysqli_query($conn,
        "SELECT TOTAL_SPENT FROM customer WHERE CUSTOMER_ID = $customer_id"
    );
    $spent_row       = mysqli_fetch_assoc($spent_result);
    $new_total_spent = floatval($spent_row['TOTAL_SPENT']);

    $tier_result = mysqli_query($conn,
        "SELECT TIER_ID FROM membership_tier
         WHERE STATUS = 'Active'
           AND MIN_SPENT <= $new_total_spent
         ORDER BY MIN_SPENT DESC
         LIMIT 1"
    );
    if ($tier_row = mysqli_fetch_assoc($tier_result)) {
        $new_tier_id = intval($tier_row['TIER_ID']);
        mysqli_query($conn,
            "UPDATE customer
             SET TIER_ID = $new_tier_id
             WHERE CUSTOMER_ID = $customer_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Membership tier update failed: " . mysqli_error($conn));
        }
    } 

    // All successful — commit
    mysqli_commit($conn);

    // ✅ Insert admin notification for new order
    $notif_message = "You have one new order";
    $notif_type    = "Order";

    $stmt_notif = $conn->prepare("INSERT INTO notification (TYPE, REF_ID, MESSAGE) VALUES (?, ?, ?)");
    $stmt_notif->bind_param("sis", $notif_type, $order_id, $notif_message);
    $stmt_notif->execute();

    $notif_id = $conn->insert_id;

    // ✅ Insert into admin_notification for ALL admins
    $stmt_admins = $conn->prepare("SELECT ADMIN_ID FROM admin");
    $stmt_admins->execute();
    $admins = $stmt_admins->get_result()->fetch_all(MYSQLI_ASSOC);

    $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (ADMIN_ID, NOTIF_ID, IS_READ) VALUES (?, ?, 0)");
    foreach ($admins as $admin) {
        $stmt_admin_notif->bind_param("ii", $admin['ADMIN_ID'], $notif_id);
        $stmt_admin_notif->execute();
    }

    header("Location: order_confirmation.php?order_id=$order_id&order_no=" . urlencode($order_no));
    exit;

} catch (Exception $e) {
    //rollback changes an error
    mysqli_rollback($conn);
    error_log("process_payment.php error: " . $e->getMessage());

    file_put_contents(
        __DIR__ . '/payment_debug.txt',
        date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n",
        FILE_APPEND
    );

    if (!empty($custom_ids)) {
        header("Location: order_confirmation.php?failed=1&type=custom");
    } else {
        header("Location: order_confirmation.php?failed=1&type=premade");
    }
    exit;
}