<?php
session_start();
require_once 'include/config.php';
require_once 'include/membership_functions.php';

// 1. Ensure user already logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// 2. Collect form data
$selected_item_ids = $_POST['selected_items'] ?? [];
if (empty($selected_item_ids) && isset($_SESSION['checkout_selected_items']) && is_array($_SESSION['checkout_selected_items'])) {
    $selected_item_ids = $_SESSION['checkout_selected_items'];
}
$selected_item_ids = array_values(array_unique(array_filter(
    array_map('intval', $selected_item_ids),
    fn($v) => $v > 0
)));

$address_id       = intval($_POST['address_id']    ?? 0);
$delivery_date    = $_POST['delivery_date']         ?? date('Y-m-d');
$delivery_time    = $_POST['delivery_time']         ?? '';
$voucher_id       = intval($_POST['voucher_id']     ?? 0);
$payment_method   = $_POST['payment_method']        ?? '';
$ewallet_provider = $_POST['ewallet_provider']      ?? '';

// Recipient info
$full_name  = mysqli_real_escape_string($conn, $_POST['full_name'] ?? '');
$name_parts = explode(' ', $full_name, 2);
$first_name = $name_parts[0] ?? '';
$last_name  = $name_parts[1] ?? '';

$phone_no         = mysqli_real_escape_string($conn, $_POST['phone']            ?? '');
$shipping_address = mysqli_real_escape_string($conn, $_POST['shipping_address'] ?? '');
$city             = mysqli_real_escape_string($conn, $_POST['city']             ?? '');
$postcode         = mysqli_real_escape_string($conn, $_POST['postcode']         ?? '');
$state            = mysqli_real_escape_string($conn, $_POST['state']            ?? '');

// 3. Detect buy-now mode
$is_buynow = (isset($_POST['checkout_mode']) && $_POST['checkout_mode'] === 'buynow') ||
             (isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow');

if (empty($selected_item_ids) && !$is_buynow) {
    header("Location: shopping_cart.php");
    exit;
}

// 4. Retrieve customer information
$user_result = mysqli_query($conn, "SELECT * FROM customer WHERE CUSTOMER_ID = $customer_id");
$user_data   = mysqli_fetch_assoc($user_result);

if (!$user_data) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 5. Recalculate subtotal server-side (never trust the amount posted from the browser)
$sub_total = 0.00;

// 5a. Buy-now item pricing
if ($is_buynow && empty($selected_item_ids) && isset($_SESSION['buynow_item'])) {
    $bn_item    = $_SESSION['buynow_item'];
    $variant_id = intval($bn_item['variant_id'] ?? 0);
    $qty        = intval($bn_item['quantity']   ?? 1);

    if ($variant_id > 0) {
        $v_res = mysqli_query($conn, "SELECT VARIANT_PRICE, SALE_PRICE FROM product_variant WHERE VARIANT_ID = $variant_id");
        if ($v_row = mysqli_fetch_assoc($v_res)) {
            $unit_price = (!empty($v_row['SALE_PRICE']) && floatval($v_row['SALE_PRICE']) > 0)
                          ? floatval($v_row['SALE_PRICE'])
                          : floatval($v_row['VARIANT_PRICE']);
            $sub_total += $unit_price * $qty;
        }

        foreach (($bn_item['addon_qtys'] ?? []) as $addon_id => $addon_qty) {
            $addon_id  = intval($addon_id);
            $addon_qty = intval($addon_qty);
            if ($addon_id <= 0 || $addon_qty <= 0) continue;

            $a_res = mysqli_query($conn,
                "SELECT pa.ADDON_PRICE AS OVERRIDE_PRICE, apv.VARIANT_PRICE, apv.SALE_PRICE
                 FROM product_addon pa
                 LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
                 WHERE pa.PRODUCT_ADDON_ID = $addon_id AND pa.IS_DELETED = 0"
            );
            if ($a_row = mysqli_fetch_assoc($a_res)) {
                if ($a_row['OVERRIDE_PRICE'] !== null) {
                    $addon_unit_price = floatval($a_row['OVERRIDE_PRICE']);
                } elseif (!empty($a_row['SALE_PRICE']) && floatval($a_row['SALE_PRICE']) > 0) {
                    $addon_unit_price = floatval($a_row['SALE_PRICE']);
                } else {
                    $addon_unit_price = floatval($a_row['VARIANT_PRICE'] ?? 0);
                }
                $sub_total += $addon_unit_price * $addon_qty;
            }
        }
    }
}

// 5b. Cart item pricing
if (!empty($selected_item_ids)) {
    $ids_string  = implode(',', $selected_item_ids);
    $item_result = mysqli_query($conn,
        "SELECT ci.CART_ITEM_ID, ci.PRODUCT_ID, ci.VARIANT_ID, ci.QUANTITY,
                p.PRODUCT_NAME, p.WARRANTY, pv.VARIANT_LABEL, pv.VARIANT_PRICE, pv.SALE_PRICE
        FROM cart_item ci
        LEFT JOIN product p          ON ci.PRODUCT_ID = p.PRODUCT_ID
        LEFT JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
        WHERE ci.CART_ITEM_ID IN ($ids_string)"
    );
    while ($item = mysqli_fetch_assoc($item_result)) {
        $unit_price = (!empty($item['SALE_PRICE']) && floatval($item['SALE_PRICE']) > 0)
                      ? floatval($item['SALE_PRICE'])
                      : floatval($item['VARIANT_PRICE']);
        $sub_total += $unit_price * intval($item['QUANTITY']);

        $cart_item_id = intval($item['CART_ITEM_ID']);
        $addon_result = mysqli_query($conn,
                "SELECT cia.QUANTITY AS ADDON_QTY, pa.PRODUCT_ADDON_ID, pa.ADDON_PRICE AS OVERRIDE_PRICE,
                        pa.ADDON_VARIANT_ID,
                        ap.PRODUCT_NAME AS ADDON_PRODUCT_NAME,
                        apv.VARIANT_PRICE, apv.SALE_PRICE
                FROM cart_item_addon cia
                JOIN product_addon pa ON cia.PRODUCT_ADD_ON_ID = pa.PRODUCT_ADDON_ID
                JOIN product ap ON pa.ADDON_PRODUCT_ID = ap.PRODUCT_ID
                LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
                WHERE cia.CART_ITEM_ID = $cart_item_id"
            );
        while ($addon = mysqli_fetch_assoc($addon_result)) {
            if ($addon['OVERRIDE_PRICE'] !== null) {
                $addon_unit_price = floatval($addon['OVERRIDE_PRICE']);
            } elseif (!empty($addon['SALE_PRICE']) && floatval($addon['SALE_PRICE']) > 0) {
                $addon_unit_price = floatval($addon['SALE_PRICE']);
            } else {
                $addon_unit_price = floatval($addon['VARIANT_PRICE'] ?? 0);
            }
            $sub_total += $addon_unit_price * intval($addon['ADDON_QTY']);
        }
    }
}

// 6. Shipping fee — free nationwide delivery for now
$shipping_fee = 0.00;

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
            $voucher_data          = $v_row;
            $voucher_discount_rate = floatval($v_row['DISCOUNT_RATE']);

            // Support both percentage and fixed-amount vouchers.
            // NOTE: existing DISCOUNT_TYPE values default to 'PERCENTAGE', so
            // any voucher created before this change behaves exactly as before.
            if (isset($v_row['DISCOUNT_TYPE']) && $v_row['DISCOUNT_TYPE'] === 'FIXED') {
                $verified_discount_amount = min($voucher_discount_rate, $sub_total);
            } else {
                $verified_discount_amount = $sub_total * ($voucher_discount_rate / 100);
            }

            $voucher_name_snap = $v_row['VOUCHER_NAME'];
            $voucher_code_snap = $v_row['VOUCHER_CODE'];
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

// 11. Format delivery address snapshot
$delivery_address_snapshot = trim("$first_name $last_name") . ', ' .
                             $shipping_address . ', ' .
                             $city . ', ' .
                             $postcode . ', ' .
                             $state;

$order_no = 'ORD-' . strtoupper(uniqid());

// 12. Begin transaction
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
        "INSERT INTO shipping (ORDER_ID, DELIVERY_STATUS)
         VALUES (0, 'PENDING')"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to insert shipping record: " . mysqli_error($conn));
    }
    $shipping_id = mysqli_insert_id($conn);

    // c. Insert order record
    $voucher_id_val  = ($voucher_id > 0) ? $voucher_id : 'NULL';
    $address_id_val  = ($address_id > 0) ? $address_id : 'NULL';
    $discount_rate_f = number_format($voucher_discount_rate,    2, '.', '');
    $discount_amt_f  = number_format($verified_discount_amount, 2, '.', '');
    $sub_total_f     = number_format($sub_total,    2, '.', '');
    $final_amount_f  = number_format($final_amount, 2, '.', '');
    $shipping_fee_f  = number_format($shipping_fee, 2, '.', '');

    $order_no_esc      = mysqli_real_escape_string($conn, $order_no);
    $delivery_addr_esc = mysqli_real_escape_string($conn, $delivery_address_snapshot);
    $voucher_name_esc  = mysqli_real_escape_string($conn, $voucher_name_snap);
    $voucher_code_esc  = mysqli_real_escape_string($conn, $voucher_code_snap);
    $cust_name_snap    = mysqli_real_escape_string($conn, trim("$first_name $last_name"));
    $cust_phone_snap   = mysqli_real_escape_string($conn, $phone_no);
    $cust_email_snap   = mysqli_real_escape_string($conn, $user_data['EMAIL']);

    mysqli_query($conn,
        "INSERT INTO orders (
            CUSTOMER_ID, ADDRESS_ID, VOUCHER_ID, PAYMENT_ID, SHIPPING_ID,
            SUB_TOTAL, TOTAL_AMOUNT, SHIPPING_FEE_SNAPSHOT,
            ORDER_NO, ORDER_STATUS,
            DELIVERY_ADDRESS_SNAPSHOT,
            VOUCHER_CODE_SNAPSHOT, VOUCHER_NAME_SNAPSHOT,
            DISCOUNT_RATE_SNAPSHOT, DISCOUNT_AMOUNT_SNAPSHOT,
            CUSTOMER_NAME_SNAPSHOT, CUSTOMER_PHONE_SNAPSHOT, CUSTOMER_EMAIL_SNAPSHOT,
            CREATED_AT
         ) VALUES (
            $customer_id, $address_id_val, $voucher_id_val, NULL, $shipping_id,
            $sub_total_f, $final_amount_f, $shipping_fee_f,
            '$order_no_esc', 'PROCESSING',
            '$delivery_addr_esc',
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

    // Link order to shipping record
    mysqli_query($conn, "UPDATE shipping SET ORDER_ID = $order_id WHERE SHIPPING_ID = $shipping_id");
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to link shipping to order: " . mysqli_error($conn));
    }

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
    mysqli_query($conn, "UPDATE orders SET PAYMENT_ID = $payment_id WHERE ORDER_ID = $order_id");
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to link payment to order: " . mysqli_error($conn));
    }

    // e. Wallet transaction record
    if ($payment_method === 'Balance') {
        $before_bal_f = number_format($before_balance, 2, '.', '');
        $after_bal_f  = number_format($new_balance,    2, '.', '');
        $pay_amt_f    = number_format($final_amount,   2, '.', '');

        mysqli_query($conn,
            "INSERT INTO wallet_transaction
                (CUSTOMER_ID, ORDER_ID, TYPE, TOPUP_METHODS, AMOUNT, BEFORE_BALANCE, AFTER_BALANCE, CREATED_AT)
             VALUES
                ($customer_id, $order_id, 'PAYMENT', 'NONE', $pay_amt_f, $before_bal_f, $after_bal_f, NOW())"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to insert wallet transaction: " . mysqli_error($conn));
        }
        $wallet_transaction_id = mysqli_insert_id($conn);

        mysqli_query($conn,
            "UPDATE payment
             SET WALLET_TRANSACTION_ID = $wallet_transaction_id
             WHERE PAYMENT_ID = $payment_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to link wallet transaction to payment: " . mysqli_error($conn));
        }
    }
    // f(i). Insert order items from cart (normal checkout)
    if (!empty($selected_item_ids)) {
        $ids_string  = implode(',', $selected_item_ids);
        $item_result = mysqli_query($conn,
            "SELECT ci.CART_ITEM_ID, ci.PRODUCT_ID, ci.VARIANT_ID, ci.QUANTITY,
                    p.PRODUCT_NAME, pv.VARIANT_LABEL, pv.VARIANT_PRICE, pv.SALE_PRICE
             FROM cart_item ci
             LEFT JOIN product p          ON ci.PRODUCT_ID = p.PRODUCT_ID
             LEFT JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
             WHERE ci.CART_ITEM_ID IN ($ids_string)"
        );

        while ($item = mysqli_fetch_assoc($item_result)) {
            $product_id   = intval($item['PRODUCT_ID']);
            $variant_id   = intval($item['VARIANT_ID']);
            $qty          = intval($item['QUANTITY']);
            $cart_item_id = intval($item['CART_ITEM_ID']);

            $unit_price = (!empty($item['SALE_PRICE']) && floatval($item['SALE_PRICE']) > 0)
                          ? floatval($item['SALE_PRICE'])
                          : floatval($item['VARIANT_PRICE']);

            $p_name_snap  = mysqli_real_escape_string($conn, $item['PRODUCT_NAME']);
            $v_label_snap = mysqli_real_escape_string($conn, $item['VARIANT_LABEL']);
            $v_price_snap = number_format($unit_price, 2, '.', '');
            $warranty_snap = (!empty($item['WARRANTY']) && intval($item['WARRANTY']) > 0) ? intval($item['WARRANTY']) : 'NULL';

            mysqli_query($conn,
                "INSERT INTO order_item
                    (ORDER_ID, PRODUCT_ID, VARIANT_ID, QUANTITY,
                    PRODUCT_NAME_SNAPSHOT, VARIANT_LABEL_SNAPSHOT, VARIANT_PRICE_SNAPSHOT, WARRANTY_SNAPSHOT)
                VALUES
                    ($order_id, $product_id, $variant_id, $qty,
                    '$p_name_snap', '$v_label_snap', $v_price_snap, $warranty_snap)"
            );
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to insert order item: " . mysqli_error($conn));
            }
            $order_item_id = mysqli_insert_id($conn);

            // Deduct stock, bump sales count
            mysqli_query($conn, "UPDATE product_variant SET VARIANT_STOCK = VARIANT_STOCK - $qty WHERE VARIANT_ID = $variant_id");
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to update variant stock: " . mysqli_error($conn));
            }

            mysqli_query($conn, "UPDATE product SET SALES_COUNT = SALES_COUNT + $qty WHERE PRODUCT_ID = $product_id");
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to update sales count: " . mysqli_error($conn));
            }

            // Process add-ons for this cart item
            $addon_result = mysqli_query($conn,
                "SELECT cia.QUANTITY AS ADDON_QTY, pa.PRODUCT_ADDON_ID, pa.ADDON_PRICE AS OVERRIDE_PRICE,
                        ap.PRODUCT_NAME AS ADDON_PRODUCT_NAME,
                        apv.VARIANT_PRICE, apv.SALE_PRICE
                 FROM cart_item_addon cia
                 JOIN product_addon pa ON cia.PRODUCT_ADD_ON_ID = pa.PRODUCT_ADDON_ID
                 JOIN product ap ON pa.ADDON_PRODUCT_ID = ap.PRODUCT_ID
                 LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
                 WHERE cia.CART_ITEM_ID = $cart_item_id"
            );

            while ($addon = mysqli_fetch_assoc($addon_result)) {
                $addon_id = intval($addon['PRODUCT_ADDON_ID']);
                $aqty     = intval($addon['ADDON_QTY']);

                if ($addon['OVERRIDE_PRICE'] !== null) {
                    $addon_unit_price = floatval($addon['OVERRIDE_PRICE']);
                } elseif (!empty($addon['SALE_PRICE']) && floatval($addon['SALE_PRICE']) > 0) {
                    $addon_unit_price = floatval($addon['SALE_PRICE']);
                } else {
                    $addon_unit_price = floatval($addon['VARIANT_PRICE'] ?? 0);
                }

                $addon_name_esc = mysqli_real_escape_string($conn, $addon['ADDON_PRODUCT_NAME']);
                $addon_price_f  = number_format($addon_unit_price, 2, '.', '');

                mysqli_query($conn,
                    "INSERT INTO order_item_addon
                         (ORDER_ITEM_ID, PRODUCT_ADDON_ID, QUANTITY,
                          ADDON_NAME_SNAPSHOT, ADDON_PRICE_SNAPSHOT)
                     VALUES
                         ($order_item_id, $addon_id, $aqty,
                          '$addon_name_esc', $addon_price_f)"
                );
                    // Deduct stock from the addon's own variant, if it has one
                if (!empty($addon['ADDON_VARIANT_ID'])) {
                    $addon_variant_id = intval($addon['ADDON_VARIANT_ID']);
                    mysqli_query($conn, "UPDATE product_variant SET VARIANT_STOCK = VARIANT_STOCK - $aqty WHERE VARIANT_ID = $addon_variant_id");
                    if (mysqli_errno($conn)) {
                        throw new Exception("Failed to deduct addon stock: " . mysqli_error($conn));
                    }
                }
                if (mysqli_errno($conn)) {
                    throw new Exception("Failed to insert order item addon: " . mysqli_error($conn));
                }
            }

            // Cleanup cart
            mysqli_query($conn, "DELETE FROM cart_item_addon WHERE CART_ITEM_ID = $cart_item_id");
            mysqli_query($conn, "DELETE FROM cart_item WHERE CART_ITEM_ID = $cart_item_id");
        }
    } elseif ($is_buynow && empty($selected_item_ids) && isset($_SESSION['buynow_item'])) {
        // f(ii). Insert order item for buy-now purchase
        $bn_item    = $_SESSION['buynow_item'];
        $variant_id = intval($bn_item['variant_id'] ?? 0);
        $qty        = intval($bn_item['quantity']   ?? 1);

        $prod_res = mysqli_query($conn,
            "SELECT p.PRODUCT_ID, p.PRODUCT_NAME, p.WARRANTY, pv.VARIANT_LABEL, pv.VARIANT_PRICE, pv.SALE_PRICE
            FROM product_variant pv
            JOIN product p ON pv.PRODUCT_ID = p.PRODUCT_ID
            WHERE pv.VARIANT_ID = $variant_id AND p.IS_DELETED = 0 LIMIT 1"
        );
        $prod_row = mysqli_fetch_assoc($prod_res);

        if ($prod_row) {
            $product_id = intval($prod_row['PRODUCT_ID']);
            $unit_price = (!empty($prod_row['SALE_PRICE']) && floatval($prod_row['SALE_PRICE']) > 0)
                          ? floatval($prod_row['SALE_PRICE'])
                          : floatval($prod_row['VARIANT_PRICE']);

            $p_name_snap  = mysqli_real_escape_string($conn, $prod_row['PRODUCT_NAME']);
            $v_label_snap = mysqli_real_escape_string($conn, $prod_row['VARIANT_LABEL']);
            $v_price_snap = number_format($unit_price, 2, '.', '');
            $warranty_snap = (!empty($prod_row['WARRANTY']) && intval($prod_row['WARRANTY']) > 0) ? intval($prod_row['WARRANTY']) : 'NULL';

            mysqli_query($conn,
                "INSERT INTO order_item
                    (ORDER_ID, PRODUCT_ID, VARIANT_ID, QUANTITY,
                    PRODUCT_NAME_SNAPSHOT, VARIANT_LABEL_SNAPSHOT, VARIANT_PRICE_SNAPSHOT, WARRANTY_SNAPSHOT)
                VALUES
                    ($order_id, $product_id, $variant_id, $qty,
                    '$p_name_snap', '$v_label_snap', $v_price_snap, $warranty_snap)"
            );
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to insert buy-now order item: " . mysqli_error($conn));
            }
            $order_item_id = mysqli_insert_id($conn);

            mysqli_query($conn, "UPDATE product_variant SET VARIANT_STOCK = VARIANT_STOCK - $qty WHERE VARIANT_ID = $variant_id");
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to update variant stock: " . mysqli_error($conn));
            }

            mysqli_query($conn, "UPDATE product SET SALES_COUNT = SALES_COUNT + $qty WHERE PRODUCT_ID = $product_id");
            if (mysqli_errno($conn)) {
                throw new Exception("Failed to update sales count: " . mysqli_error($conn));
            }

            // Add-ons chosen at buy-now time
            foreach (($bn_item['addon_qtys'] ?? []) as $addon_id => $addon_qty) {
                $addon_id  = intval($addon_id);
                $addon_qty = intval($addon_qty);
                if ($addon_id <= 0 || $addon_qty <= 0) continue;

                $a_res = mysqli_query($conn,
                    "SELECT pa.ADDON_PRICE AS OVERRIDE_PRICE, pa.ADDON_VARIANT_ID,
                            ap.PRODUCT_NAME AS ADDON_PRODUCT_NAME,
                            apv.VARIANT_PRICE, apv.SALE_PRICE
                    FROM product_addon pa
                    JOIN product ap ON pa.ADDON_PRODUCT_ID = ap.PRODUCT_ID
                    LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
                    WHERE pa.PRODUCT_ADDON_ID = $addon_id AND pa.IS_DELETED = 0"
                );
                $a_row = mysqli_fetch_assoc($a_res);

                if ($a_row) {
                    if ($a_row['OVERRIDE_PRICE'] !== null) {
                        $addon_unit_price = floatval($a_row['OVERRIDE_PRICE']);
                    } elseif (!empty($a_row['SALE_PRICE']) && floatval($a_row['SALE_PRICE']) > 0) {
                        $addon_unit_price = floatval($a_row['SALE_PRICE']);
                    } else {
                        $addon_unit_price = floatval($a_row['VARIANT_PRICE'] ?? 0);
                    }

                    $addon_name_esc = mysqli_real_escape_string($conn, $a_row['ADDON_PRODUCT_NAME']);
                    $addon_price_f  = number_format($addon_unit_price, 2, '.', '');

                    mysqli_query($conn,
                        "INSERT INTO order_item_addon
                             (ORDER_ITEM_ID, PRODUCT_ADDON_ID, QUANTITY,
                              ADDON_NAME_SNAPSHOT, ADDON_PRICE_SNAPSHOT)
                         VALUES
                             ($order_item_id, $addon_id, $addon_qty,
                              '$addon_name_esc', $addon_price_f)"
                    );
                    if (mysqli_errno($conn)) {
                        throw new Exception("Failed to insert buy-now order item addon: " . mysqli_error($conn));
                    }
                }
            }
        }

        unset($_SESSION['buynow_item']);
        unset($_SESSION['checkout_mode']);
        unset($_SESSION['checkout_selected_items']);
    }

    // g. Update voucher usage count
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

    // h. Update spending totals (lifetime stat + the two rolling reward windows).
    //    Tier upgrades/downgrades are NOT decided here anymore - they're only
    //    evaluated when this customer's TIER_WINDOW_START closes, handled by
    //    process_tier_maintenance() in include/membership_functions.php
    //    (triggered on page load via include/header.php). Keeping a single
    //    source of truth for tier changes avoids the old conflict where this
    //    lifetime-total check could immediately re-upgrade someone the
    //    quarterly check had just downgraded.
    $spend_f = number_format($final_amount, 2, '.', '');

    mysqli_query($conn,
        "UPDATE customer
         SET TOTAL_SPENT     = TOTAL_SPENT + $spend_f,
             QUARTER_SPENT   = QUARTER_SPENT + $spend_f,
             MILESTONE_SPENT = MILESTONE_SPENT + $spend_f
         WHERE CUSTOMER_ID = $customer_id"
    );
    if (mysqli_errno($conn)) {
        throw new Exception("Failed to update customer spending totals: " . mysqli_error($conn));
    }


    // h1.5. Immediate upgrade check: if this order's updated QUARTER_SPENT already
    // qualifies for a higher tier than the customer currently holds, upgrade now.
    // Downgrades still only happen at quarter-end via process_tier_maintenance().
    $qs_result = mysqli_query($conn, "SELECT TIER_ID, QUARTER_SPENT FROM customer WHERE CUSTOMER_ID = $customer_id");
    $qs_row    = mysqli_fetch_assoc($qs_result);
    $current_tier_id       = intval($qs_row['TIER_ID']);
    $current_quarter_spent = floatval($qs_row['QUARTER_SPENT']);

    $current_min_result = mysqli_query($conn, "SELECT MIN_SPENT FROM membership_tier WHERE TIER_ID = $current_tier_id");
    $current_min_row    = mysqli_fetch_assoc($current_min_result);
    $current_min_spent  = floatval($current_min_row['MIN_SPENT'] ?? 0);

    $upgrade_check = mysqli_query($conn,
        "SELECT TIER_ID FROM membership_tier
        WHERE STATUS = 'Active'
        AND MIN_SPENT <= $current_quarter_spent
        AND MIN_SPENT > $current_min_spent
        ORDER BY MIN_SPENT DESC LIMIT 1"
    );

        if ($upgrade_row = mysqli_fetch_assoc($upgrade_check)) {
        $eligible_tier_id = intval($upgrade_row['TIER_ID']);
        // Upgrading restarts the 3-month tier window immediately, rather than
        // letting the new tier ride out whatever time was left on the old one.
        mysqli_query($conn,
            "UPDATE customer
             SET TIER_ID = $eligible_tier_id,
                 TIER_WINDOW_START = CURDATE()
             WHERE CUSTOMER_ID = $customer_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to apply immediate tier upgrade: " . mysqli_error($conn));
        }
        if (function_exists('assignTierVouchers')) {
            assignTierVouchers($conn, $customer_id, $eligible_tier_id);
        }

         // Notify the customer about the tier upgrade
        $eligible_tier_name_res = mysqli_query($conn,
            "SELECT TIER_NAME FROM membership_tier WHERE TIER_ID = $eligible_tier_id LIMIT 1"
        );
        $eligible_tier_name_row = $eligible_tier_name_res ? mysqli_fetch_assoc($eligible_tier_name_res) : null;

        if ($eligible_tier_name_row) {
            $eligible_tier_name = $eligible_tier_name_row['TIER_NAME'];
            notify_customer($conn, $customer_id, 'Membership', $eligible_tier_id,
                "Congratulations! You've been upgraded to $eligible_tier_name tier. Enjoy your new benefits!"
            );
        }

        // Grant the new tier's monthly voucher right away, instead of making
        // the customer wait until the regular monthly schedule catches up.
        // Any voucher already granted under the previous (lower) tier is
        // untouched - this only adds a new one for the tier just reached.
        $new_tier_res = mysqli_query($conn,
            "SELECT TIER_NAME, MONTHLY_VOUCHER_AMOUNT FROM membership_tier
             WHERE TIER_ID = $eligible_tier_id AND STATUS = 'Active' LIMIT 1"
        );
        $new_tier = $new_tier_res ? mysqli_fetch_assoc($new_tier_res) : null;

        if ($new_tier && $new_tier['MONTHLY_VOUCHER_AMOUNT'] !== null) {
            $monthly_amount = floatval($new_tier['MONTHLY_VOUCHER_AMOUNT']);
            $voucher_name   = $new_tier['TIER_NAME'] . ' Monthly Reward - ' . (new DateTime())->format('M Y');

            $granted = grant_fixed_voucher($conn, $customer_id, $voucher_name, $monthly_amount, 'Public', null, 30);
            if ($granted) {
                // Reset the monthly clock so next month's scheduled check
                // waits a full month from today, not from before the upgrade.
                mysqli_query($conn, "UPDATE customer SET LAST_TIER_VOUCHER_DATE = CURDATE() WHERE CUSTOMER_ID = $customer_id");
                if (mysqli_errno($conn)) {
                    throw new Exception("Failed to update LAST_TIER_VOUCHER_DATE after upgrade: " . mysqli_error($conn));
                }
            }
        }
    }
    // h1. Single-order reward: any order over RM100 grants an immediate RM5
    //     voucher, regardless of membership tier.
    if ($final_amount > 100) {
        grant_fixed_voucher($conn, $customer_id, 'Order Reward - Spend RM100+', 5.00, 'Public', null, 30);
    }

    // h2. Milestone reward: cumulative spend of RM1000 within the rolling
    //     3-month milestone window grants a RM10 voucher and restarts that
    //     window immediately (doesn't wait for the window to naturally expire).
    $milestone_result = mysqli_query($conn, "SELECT MILESTONE_SPENT FROM customer WHERE CUSTOMER_ID = $customer_id");
    $milestone_row    = mysqli_fetch_assoc($milestone_result);
    $milestone_spent  = floatval($milestone_row['MILESTONE_SPENT']);

    if ($milestone_spent >= 1000) {
        grant_fixed_voucher($conn, $customer_id, 'Milestone Reward - RM1000 Spent', 10.00, 'Public', null, 30);
        mysqli_query($conn,
            "UPDATE customer
             SET MILESTONE_SPENT = 0.00,
                 MILESTONE_WINDOW_START = CURDATE()
             WHERE CUSTOMER_ID = $customer_id"
        );
        if (mysqli_errno($conn)) {
            throw new Exception("Failed to reset milestone window: " . mysqli_error($conn));
        }
    }

    // All successful — commit
    mysqli_commit($conn);

    // i. Insert admin notification for new order
    $notif_message = "You have one new order";
    $notif_type    = "Order";

    $stmt_notif = $conn->prepare("INSERT INTO notification (TYPE, REF_ID, MESSAGE) VALUES (?, ?, ?)");
    $stmt_notif->bind_param("sis", $notif_type, $order_id, $notif_message);
    $stmt_notif->execute();
    $notif_id = $conn->insert_id;

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
    // Rollback on any error
    mysqli_rollback($conn);
    error_log("process_payment.php error: " . $e->getMessage());

    file_put_contents(
        __DIR__ . '/payment_debug.txt',
        date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n",
        FILE_APPEND
    );

    header("Location: order_confirmation.php?failed=1&type=premade");
    exit;
}