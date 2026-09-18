<?php
session_start();
require_once 'include/config.php';

//Redirect to login page if user is not authenticated
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}


$customer_id = intval($_SESSION['CUSTOMER_ID']);


//1. Retrieve customer information
$user_sql = "SELECT CUSTOMER_ID, CUSTOMER_NAME, EMAIL, PHONE, WALLET_BALANCE 
                FROM customer 
                WHERE CUSTOMER_ID = $customer_id";
$user_result = mysqli_query($conn, $user_sql);
$user_data = mysqli_fetch_assoc($user_result);
//2. Retrieve item IDs from the cart or session
$is_buynow_flag = isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow';

if (isset($_POST['selected_items']) && is_array($_POST['selected_items']) && !empty($_POST['selected_items'])) {
    $selected_item_ids = $_POST['selected_items'];
    $_SESSION['checkout_selected_items'] = $selected_item_ids;
    unset($_SESSION['buynow_item']);
    unset($_SESSION['checkout_mode']);

} elseif ($is_buynow_flag && isset($_SESSION['buynow_item'])) {
    $selected_item_ids = [];
    unset($_SESSION['checkout_selected_items']);

} elseif (isset($_SESSION['checkout_selected_items']) && is_array($_SESSION['checkout_selected_items']) && !empty($_SESSION['checkout_selected_items'])) {
    $selected_item_ids = $_SESSION['checkout_selected_items'];

} else {
    $selected_item_ids = [];
}
// Check for applied voucher
$passed_voucher_id = isset($_POST['selected_voucher_id']) ? intval($_POST['selected_voucher_id']) : 0;

//3. Initialize variables for Order Summary
$cart_items  = [];
$SUB_TOTAL   = 0;
$SHIPPING_FEE = 0.00; // Shipping fee is not charged for this business

/**
 * Helper: fetch a variant's display label / price / stock.
 * Some products don't have variants with meaningful labels - in that
 * case VARIANT_LABEL may be NULL/empty and we just show the product name.
 */
function get_variant_row($conn, $variant_id) {
    $variant_id = intval($variant_id);
    $sql = "SELECT pv.VARIANT_ID, pv.PRODUCT_ID, pv.VARIANT_LABEL, pv.VARIANT_PRICE, 
                   pv.SALE_PRICE, pv.VARIANT_STOCK, p.PRODUCT_NAME, p.COVER_IMAGE
            FROM product_variant pv
            JOIN product p ON pv.PRODUCT_ID = p.PRODUCT_ID
            WHERE pv.VARIANT_ID = $variant_id AND p.IS_DELETED = 0 LIMIT 1";
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_assoc($res) : null;
}

/**
 * Helper: fetch add-ons for a cart item / buynow item via product_addon.
 * Each row in cart_item_addon points to a PRODUCT_ADDON_ID, which in turn
 * points to an ADDON_PRODUCT_ID (+ optional ADDON_VARIANT_ID if the addon
 * product has variants). ADDON_PRICE on product_addon overrides the
 * variant/product price when set.
 */
function get_addons_for_cart_item($conn, $cart_item_id) {
    $cart_item_id = intval($cart_item_id);
    $sql = "SELECT cia.CART_ITEM_ADDON_ID, cia.QUANTITY, cia.PRODUCT_ADD_ON_ID,
                   pa.ADDON_PRODUCT_ID, pa.ADDON_VARIANT_ID, pa.ADDON_PRICE,
                   ap.PRODUCT_NAME AS ADDON_PRODUCT_NAME,
                   apv.VARIANT_LABEL AS ADDON_VARIANT_LABEL,
                   apv.VARIANT_PRICE AS ADDON_VARIANT_PRICE
            FROM cart_item_addon cia
            JOIN product_addon pa ON cia.PRODUCT_ADD_ON_ID = pa.PRODUCT_ADDON_ID
            JOIN product ap ON pa.ADDON_PRODUCT_ID = ap.PRODUCT_ID
            LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
            WHERE cia.CART_ITEM_ID = $cart_item_id
              AND pa.IS_DELETED = 0
              AND ap.IS_DELETED = 0";
    $res = mysqli_query($conn, $sql);

    $addons = [];
    while ($row = mysqli_fetch_assoc($res)) {
        
        // ADDON_PRICE overrides; otherwise fall back to the variant price
        $unit_price = ($row['ADDON_PRICE'] !== null)
            ? floatval($row['ADDON_PRICE'])
            : floatval($row['ADDON_VARIANT_PRICE']);

        $display_name = $row['ADDON_PRODUCT_NAME'];
        if (!empty($row['ADDON_VARIANT_LABEL'])) {
            $display_name .= ' - ' . $row['ADDON_VARIANT_LABEL'];
        }

        $addons[] = [
            'PRODUCT_ADDON_ID' => $row['PRODUCT_ADDON_ID'],
            'ADDON_NAME'       => $display_name,
            'ADDON_PRICE'      => $unit_price,
            'QUANTITY'         => intval($row['QUANTITY']),
        ];
    }
    return $addons;
}

//4. buy now mode, read from session
if (empty($selected_item_ids) && isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow') {
    $item = $_SESSION['buynow_item'] ?? null;

    if ($item) {
        $variant_id = intval($item['variant_id']);
        $qty        = intval($item['quantity']);

        $row = get_variant_row($conn, $variant_id);

        if ($row) {
            if ($qty > intval($row['VARIANT_STOCK'])) {
                echo "<script>alert('Sorry, not enough stock!'); window.location.href='shopping_cart.php';</script>";
                exit;
            }

            // Use SALE_PRICE if set, otherwise VARIANT_PRICE
            $unit_price = (!empty($row['SALE_PRICE'])) ? floatval($row['SALE_PRICE']) : floatval($row['VARIANT_PRICE']);

            $row['final_unit_price'] = $unit_price;
            $row['QUANTITY']         = $qty;
            $row['CART_ITEM_ID']     = 'buynow_temp';
            $row['addons']           = [];

            // Process each selected add-on and add to subtotal
            foreach (($item['selected_addons'] ?? []) as $product_addon_id) {
                $product_addon_id = intval(trim($product_addon_id));
                $aqty = intval($item['addon_qtys'][$product_addon_id] ?? 1);

                $addon_sql = "SELECT pa.PRODUCT_ADDON_ID, pa.ADDON_PRODUCT_ID, pa.ADDON_VARIANT_ID, pa.ADDON_PRICE,
                                     ap.PRODUCT_NAME AS ADDON_PRODUCT_NAME,
                                     apv.VARIANT_LABEL AS ADDON_VARIANT_LABEL,
                                     apv.VARIANT_PRICE AS ADDON_VARIANT_PRICE
                              FROM product_addon pa
                              JOIN product ap ON pa.ADDON_PRODUCT_ID = ap.PRODUCT_ID
                              LEFT JOIN product_variant apv ON pa.ADDON_VARIANT_ID = apv.VARIANT_ID
                              WHERE pa.PRODUCT_ADDON_ID = $product_addon_id
                                AND pa.IS_DELETED = 0 AND ap.IS_DELETED = 0 LIMIT 1";
                $addon_res = mysqli_query($conn, $addon_sql);
                $addon_row = mysqli_fetch_assoc($addon_res);

                if ($addon_row) {
                    $addon_unit_price = ($addon_row['ADDON_PRICE'] !== null)
                        ? floatval($addon_row['ADDON_PRICE'])
                        : floatval($addon_row['ADDON_VARIANT_PRICE']);

                    $display_name = $addon_row['ADDON_PRODUCT_NAME'];
                    if (!empty($addon_row['ADDON_VARIANT_LABEL'])) {
                        $display_name .= ' - ' . $addon_row['ADDON_VARIANT_LABEL'];
                    }

                    $row['addons'][] = [
                        'PRODUCT_ADDON_ID' => $product_addon_id,
                        'ADDON_NAME'       => $display_name,
                        'ADDON_PRICE'      => $addon_unit_price,
                        'QUANTITY'         => $aqty,
                    ];
                    $SUB_TOTAL += $addon_unit_price * $aqty;
                }
            }

            // Add item price to subtotal and push item into cart array
            $SUB_TOTAL   += $row['final_unit_price'] * $qty;
            $cart_items[] = $row;
        }
    }
}

// 5. Redirect to cart if no items are selected
if (empty($selected_item_ids) && empty($cart_items)) {
    if (!isset($_SESSION['checkout_mode']) || $_SESSION['checkout_mode'] !== 'buynow') {
        echo "<script>alert('Please select items from your cart.'); window.location.href='shopping_cart.php';</script>";
        exit;
    }
}

//6. Process standard cart items
if (!empty($selected_item_ids)) {

    // Sanitize IDs for SQL query
    $ids_string = implode(',', array_map('intval', $selected_item_ids));

    // Fetch product + variant details
    $sql = "SELECT ci.*, 
                   p.PRODUCT_NAME, p.COVER_IMAGE,
                   pv.VARIANT_LABEL, pv.VARIANT_PRICE, pv.SALE_PRICE, pv.VARIANT_STOCK
            FROM cart_item ci
            LEFT JOIN product p ON ci.PRODUCT_ID = p.PRODUCT_ID
            LEFT JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
            WHERE ci.CART_ITEM_ID IN ($ids_string)";

    $result = mysqli_query($conn, $sql);

    while ($row = mysqli_fetch_assoc($result)) {

    
        $qty = intval($row['QUANTITY']);

        // Check if stock is sufficient
        if ($qty > intval($row['VARIANT_STOCK'])) {
            echo "<script>alert('Sorry, \"" . addslashes($row['PRODUCT_NAME']) . "\" does not have enough stock!'); window.location.href='shopping_cart.php';</script>";
            exit;
        }

        // Use SALE_PRICE if set, otherwise VARIANT_PRICE
        $row['final_unit_price'] = (!empty($row['SALE_PRICE'])) ? floatval($row['SALE_PRICE']) : floatval($row['VARIANT_PRICE']);

        // Fetch add-ons for the item via the new product_addon structure
        $row['addons'] = get_addons_for_cart_item($conn, $row['CART_ITEM_ID']);
        foreach ($row['addons'] as $addon) {
            $SUB_TOTAL += $addon['ADDON_PRICE'] * $addon['QUANTITY'];
        }

        // Add item price to subtotal
        $SUB_TOTAL += $row['final_unit_price'] * $qty;

        $cart_items[] = $row;
    }
}

// 7. Get customer-specific vouchers
// First get customer's membership tier to filter tier-specific vouchers
$customer_tier_id = 0;
$tier_q = mysqli_query($conn, "SELECT TIER_ID FROM customer WHERE CUSTOMER_ID = $customer_id");
if ($tier_q && $tr = mysqli_fetch_assoc($tier_q)) {
    $customer_tier_id = intval($tr['TIER_ID']);
}

$voucher_sql = "SELECT v.VOUCHER_ID, v.VOUCHER_NAME, v.DISCOUNT_RATE, v.MIN_SPEND,
                       v.MAX_USAGE, v.USED_COUNT AS GLOBAL_USED_COUNT, v.PER_USER_LIMIT,
                       v.EXPIRY_DATE AS VOUCHER_EXPIRY, v.START_DATE, v.TIER_ID,
                       cv.USED_COUNT AS CUSTOMER_USED_COUNT,
                       cv.EXPIRY_DATE AS CUSTOMER_EXPIRY
                FROM voucher v
                INNER JOIN customer_voucher cv ON v.VOUCHER_ID = cv.VOUCHER_ID
                WHERE cv.CUSTOMER_ID = $customer_id
                  AND v.VOUCHER_STATUS = 'Active'
                  AND v.IS_DELETED = 0
                  AND (v.TIER_ID IS NULL OR v.TIER_ID = 0 OR v.TIER_ID = $customer_tier_id)";
// Fetch active vouchers assigned to this customer that match their tier
$voucher_result = mysqli_query($conn, $voucher_sql);
$my_vouchers = [];
$today = new DateTime();

if ($voucher_result) {
    while ($v_row = mysqli_fetch_assoc($voucher_result)) {

        // check voucher start date
        if (!empty($v_row['START_DATE']) && $v_row['START_DATE'] !== '0000-00-00 00:00:00') {
            $start_time = new DateTime($v_row['START_DATE']);
            if ($today < $start_time) continue;
        }

        // check voucher expiry date
        if (!empty($v_row['VOUCHER_EXPIRY']) && $v_row['VOUCHER_EXPIRY'] !== '0000-00-00 00:00:00' && $v_row['VOUCHER_EXPIRY'] !== '0000-00-00') {
           $expiry_time = new DateTime($v_row['VOUCHER_EXPIRY']);
           if ($today > $expiry_time) continue;
         }

        //check customer_voucher expiry date
        if (!empty($v_row['CUSTOMER_EXPIRY']) && $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00 00:00:00' && $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00') {
           $cv_expiry = new DateTime($v_row['CUSTOMER_EXPIRY']);
           if ($today > $cv_expiry) continue;
        }

        //check global usage limit
        if ($v_row['MAX_USAGE'] != -1 && $v_row['GLOBAL_USED_COUNT'] >= $v_row['MAX_USAGE']) continue;

        //check per user limit
        $customer_used = intval($v_row['CUSTOMER_USED_COUNT'] ?? 0);
        if ($v_row['PER_USER_LIMIT'] != -1 && $customer_used >= $v_row['PER_USER_LIMIT']) continue;

        // All checks passed — voucher is valid for this customer
        $my_vouchers[] = $v_row;
    }
}

//8. Get customer addresses (used only for Doorstep Delivery; no shipping fee is charged)
$address_sql    = "SELECT * FROM address WHERE CUSTOMER_ID = $customer_id ORDER BY IS_DEFAULT DESC";
$address_result = mysqli_query($conn, $address_sql);

// 判断默认地址是否为居銮，决定初次加载时是否显示 Self Collection
$default_city = '';
$address_list_for_check = mysqli_query($conn, $address_sql); // 重新查一次，避免影响下面 while 循环指针
if ($row = mysqli_fetch_assoc($address_list_for_check)) {
    $default_city = strtolower(trim($row['CITY']));
}
$is_kluang_default = ($default_city === 'kluang');

//9. Handle new address submission
if (isset($_POST['save_new_address'])) {

    // Sanitize all inputs before inserting into database
    $fname = mysqli_real_escape_string($conn, $_POST['new_fname']);
    $lname = mysqli_real_escape_string($conn, $_POST['new_lname']);
    $phone = mysqli_real_escape_string($conn, $_POST['new_phone']);
    $addr  = mysqli_real_escape_string($conn, $_POST['new_address']);
    $city  = mysqli_real_escape_string($conn, $_POST['new_city']);
    $post  = mysqli_real_escape_string($conn, $_POST['new_postcode']);
    $state = mysqli_real_escape_string($conn, $_POST['new_state']);

    mysqli_query($conn, "INSERT INTO address 
                         (CUSTOMER_ID, FIRST_NAME, LAST_NAME, PHONE, ADDRESS_LINE, CITY, POSTCODE, STATE, IS_DEFAULT)
                         VALUES ('$customer_id','$fname','$lname','$phone','$addr','$city','$post','$state', 0)");

    $address_saved_flag = true;

    // Refresh address list
    $address_result = mysqli_query($conn, "SELECT * FROM address WHERE CUSTOMER_ID = $customer_id ORDER BY ADDRESS_ID DESC");
}

// 10. Get shop pickup info for Self Collection
$bakery_res  = mysqli_query($conn, "SELECT SHOP_NAME, BAKERY_DES, ADDRESS, CITY, STATE, POSTCODE, PHONE, EMAIL FROM bakery_info LIMIT 1");
$bakery_info = $bakery_res ? mysqli_fetch_assoc($bakery_res) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/header.css?v=7.0">
    <link rel="stylesheet" href="css/footer.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --main-color: #80b8d2;
            --font-color: #1B2A3C;
            --secondary-color: #F4F8FC;
            --rating-color: #F5A623;
            --search-border-color: #C9DCEE;
            --bg-color: #FFFFFF;
            --font2-color: #52708A;
            --card-bg-color: #EBF4FC;
            --btn-hover: #3c8cb1;
            --edit-blue: #2E86DE;
            --delete-red: #E74C3C;
            --price-color: #2E86DE;
            --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            margin: 0;
            padding: 0;
            color: var(--font-color);
        }

        .contain-box {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Progress bar */
        .step-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            max-width: 900px;
            margin: 20px auto 40px;
            padding: 0 20px;
        }

        .step-line {
            position: absolute;
            top: 22px;
            left: 60px;
            right: 60px;
            height: 2px;
            background: var(--search-border-color);
            z-index: 1;
        }

        .step-line-progress {
            position: absolute;
            top: 22px;
            left: 60px;
            width: 33%;
            height: 2px;
            background: var(--main-color);
            z-index: 1;
        }

        .step-icon {
            z-index: 2;
            text-align: center;
            font-size: 13.5px;
            font-weight: 500;
            padding: 0 12px;
            background: var(--bg-color);
            color: var(--font2-color);
            font-family: 'Inter', sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .step-circle {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: var(--transition);
            background: #FFFFFF;
            border: 2px solid var(--search-border-color);
            color: var(--font2-color);
        }

        /* Done state */
        .step-icon.step-done .step-circle {
            background: #e8f4f9;
            border-color: var(--main-color);
            color: var(--main-color);
        }
        .step-icon.step-done span {
            color: var(--font-color);
            font-weight: 600;
        }

        /* Active state */
        .step-icon.step-active .step-circle {
            background: linear-gradient(135deg, #80b8d2 0%, #3c8cb1 100%);
            border-color: #3c8cb1;
            color: #FFFFFF;
            box-shadow: 0 4px 14px rgba(60, 140, 177, 0.35);
        }
        .step-icon.step-active span {
            color: var(--font-color);
            font-weight: 700;
        }

        /* Inactive state */
        .step-icon.step-inactive .step-circle {
            background: #F4F8FC;
            border-color: var(--search-border-color);
            color: var(--font2-color);
        }

        /* Left and right panel layout */
        .main-form {
            display: flex;
            gap: 30px;
            margin-top: 40px;
            align-items: flex-start;
        }

        .left-panel {
            width: 60%;
            background: white;
            border: 1px solid var(--search-border-color);
            border-radius: 20px;
            padding: 28px;
            box-sizing: border-box;
        }

        .right-panel {
            width: 40%;
            background: #e0eef4;
            border-radius: 20px;
            padding: 28px;
            box-sizing: border-box;
        }

        .left-panel p {
            font-size: 13px;
            color: var(--font2-color);
        }

        h3 {
            color: var(--main-color);
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            font-size: 25px;
            margin-bottom: 16px;
            margin-top: 0;
        }

        hr {
            border: 0;
            border-top: 1px solid var(--search-border-color);
            margin: 16px 0;
        }

        .form-group {
            margin-bottom: 16px;
            width: 100%;
            flex: 1;
        }

        .form-label {
            font-weight: 600;
            font-size: 12px;
            margin-bottom: 6px;
            display: block;
            color: var(--font2-color);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-family: 'Inter', sans-serif;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--search-border-color);
            border-radius: 10px;
            background: white;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            color: var(--font-color);
            box-sizing: border-box;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--main-color);
            box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
        }

        .input-readonly {
            background: #e9ecef;
            cursor: not-allowed;
        }

        .row-group {
            display: flex;
            gap: 20px;
        }

        .phone-group {
            display: flex;
            gap: 10px;
        }

        #country-code {
            width: 120px;
            flex-shrink: 0;
        }

        /* Shipping method */
        .shipping-method-group {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }

        .shipping-method-option {
            flex: 1;
            border: 1px solid var(--search-border-color);
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            font-family: 'Inter', sans-serif;
            font-size: 13.5px;
            font-weight: 500;
            color: var(--font2-color);
            transition: var(--transition);
        }

        .shipping-method-option.active {
            border-color: var(--main-color);
            background: var(--secondary-color);
            color: var(--font-color);
            font-weight: 600;
        }

        .shipping-method-option input {
            accent-color: var(--main-color);
        }

        /* Delivery info box */
        .shipping-address-card {
            background-color: var(--secondary-color);
            border: 1px solid var(--search-border-color);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .delivery-info-box {
            border: 1px solid var(--search-border-color);
            border-radius: 12px;
            padding: 14px;
            background: var(--secondary-color);
            margin-bottom: 16px;
        }

        .text-danger {
            font-size: 13px;
            font-weight: 600;
            color: #e74c3c;
            margin: 2px 0;
        }

        .text-success {
            font-size: 13px;
            color: #27ae60;
            margin: 2px 0;
        }

        .input-readonly {
            background: #e9ecef;
            cursor: not-allowed;
        }

        /* Pickup info for self collection */
        .pickup-box {
            background: var(--secondary-color);
            border: 1px solid var(--search-border-color);
            border-left: 4px solid var(--main-color);
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }

        .pickup-box h6 {
            font-size: 14px;
            font-weight: 700;
            color: var(--font-color);
            font-family: 'Poppins', sans-serif;
            margin: 0 0 8px;
        }

        .pickup-box p {
            font-size: 13px;
            color: var(--font2-color);
            margin: 4px 0;
        }

        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 24px;
        }

        .btn-back {
            background-color: transparent;
            color: var(--font2-color);
            border: 1px solid var(--font2-color);
            border-radius: 20px;
            padding: 10px 22px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }

        .btn-back:hover {
            background-color: var(--card-bg-color);
            color: var(--main-color);
            border-color: var(--main-color);
            text-decoration: none;
        }

        .btn-pay {
            background-color: var(--main-color);
            color: #FFFFFF;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: 20px;
            padding: 10px 24px;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-pay:hover {
            background-color: #3c8cb1;
            color: #FFFFFF;
        }

        .btn-apply {
            background-color: var(--main-color);
            color: white;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: 20px;
            padding: 8px 18px;
            cursor: pointer;
            white-space: nowrap;
            font-size: 13px;
            transition: var(--transition);
        }

        .btn-apply:hover {
            background-color: #3c8cb1;
        }

        /* Order Summary */
        .item-details {
            display: flex;
            gap: 16px;
            padding-bottom: 16px;
        }

        .item-image img {
            width: 90px;
            height: 90px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--search-border-color);
        }

        .item-text p {
            font-size: 13px;
            line-height: 1.5;
            margin: 2px 0;
            color: var(--font2-color);
            font-family: 'Inter', sans-serif;
        }

        .item-text .size-qty {
            color: var(--font2-color);
            opacity: 0.85;
        }

        .item-text .unit-price {
            color: var(--font-color);
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
        }

        /* Voucher */
        .voucher-row {
            display: flex;
            gap: 10px;
            margin-top: 6px;
            width: 100%;
        }

        /* Add-on */
        .addon-box {
            background: var(--secondary-color);
            padding: 8px 10px;
            border-radius: 8px;
            margin-top: 6px;
            font-size: 12px;
            border: 1px solid var(--search-border-color);
            border-left: 3px solid var(--main-color);
            color: var(--font2-color);
        }

        /* Price details */
        .price-details p {
            font-size: 14px;
            color: var(--font2-color);
            display: flex;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
        }

        .total-box {
            font-size: 17px;
            font-weight: 700;
            color: var(--font-color);
            display: flex;
            justify-content: space-between;
            border-top: 2px solid var(--search-border-color);
            padding-top: 10px;
            font-family: 'Poppins', sans-serif;
        }

        /* Added address popup & Voucher popup matching payment.php */
        .modal-overlay,
        .voucher-popup-overlay {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(27, 42, 60, 0.4);
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background: white;
            width: 480px;
            max-width: 90%;
            margin: 100px auto;
            padding: 28px 24px;
            border-radius: 20px;
            border: 1.5px dashed var(--search-border-color);
            position: relative;
            box-shadow: 0 4px 30px rgba(46, 134, 222, 0.15);
        }

        .modal-content h4 {
            color: var(--font-color);
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            margin-bottom: 20px;
            text-align: center;
        }

        .modal-content input.form-control {
            border-radius: 12px;
            border: 1px solid var(--search-border-color);
            background-color: var(--secondary-color);
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            color: var(--font-color);
            padding: 10px 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .modal-content input.form-control:focus {
            border-color: var(--main-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
            background-color: white;
        }

        .close-modal {
            position: absolute;
            right: 20px;
            top: 18px;
            cursor: pointer;
            font-size: 24px;
            color: var(--font2-color);
            transition: color 0.2s;
            line-height: 1;
        }

        .close-modal:hover {
            color: var(--main-color);
        }

        /* Voucher Popup */
        .voucher-popup-box {
            background: white;
            width: 360px;
            max-width: 90%;
            margin: 160px auto;
            padding: 28px 24px;
            border-radius: 20px;
            border: 1.5px dashed var(--search-border-color);
            text-align: center;
            position: relative;
            box-shadow: 0 4px 30px rgba(46, 134, 222, 0.15);
        }

        .voucher-popup-icon { 
            font-size: 36px; 
            margin-bottom: 10px; 
        }

        .voucher-popup-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--font-color);
            margin-bottom: 8px;
            font-family: 'Poppins', sans-serif;
        }

        .voucher-popup-msg {
            font-size: 13px;
            color: var(--font2-color);
            line-height: 1.6;
            margin-bottom: 20px;
            font-family: 'Inter', sans-serif;
        }

        .voucher-popup-msg span { 
            color: var(--main-color); 
            font-weight: 700; 
        }

        .voucher-popup-btn {
            background: var(--main-color);
            color: #FFFFFF;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: 20px;
            padding: 8px 28px;
            cursor: pointer;
            font-size: 14px;
            transition: var(--transition);
            box-shadow: 0 4px 12px rgba(46, 134, 222, 0.25);
        }

        .voucher-popup-btn:hover { 
            background: var(--btn-hover); 
        }
    </style>
</head>
<body>

<?php include_once 'include/header.php'; ?>

<div class="contain-box">

    <!-- Progress bar -->
    <div class="step-container">
        <div class="step-line"></div>
        <div class="step-line-progress"></div>
        <div class="step-icon step-done">
            <div class="step-circle"><i class="bi bi-cart-check-fill"></i></div>
            <span>Your selection</span>
        </div>
        <div class="step-icon step-active">
            <div class="step-circle"><i class="bi bi-geo-alt-fill"></i></div>
            <span>Checkout</span>
        </div>
        <div class="step-icon step-inactive">
            <div class="step-circle"><i class="bi bi-credit-card"></i></div>
            <span>Make Payment</span>
        </div>
        <div class="step-icon step-inactive">
            <div class="step-circle"><i class="bi bi-check2-circle"></i></div>
            <span>Complete</span>
        </div>
    </div>

    <form method="post" action="payment.php" onsubmit="return validateFormBeforeSubmit()" class="main-form">

        <!-- Delivery details -->
        <div class="left-panel">
            <p>Logged in as <strong><?php echo htmlspecialchars($user_data['EMAIL']); ?></strong></p>
            <hr>
            <h3>Delivery Details</h3>

            <?php foreach ($selected_item_ids as $id): ?>
                <input type="hidden" name="selected_items[]" value="<?php echo intval($id); ?>">
            <?php endforeach; ?>

            <input type="hidden" name="sub_total"       id="input_sub_total"       value="<?php echo $SUB_TOTAL; ?>">
            <input type="hidden" name="shipping_fee"    id="input_shipping_fee"    value="<?php echo $SHIPPING_FEE; ?>">
            <input type="hidden" name="discount_amount" id="input_discount_amount" value="0">
            <input type="hidden" name="final_amount"    id="input_final_amount"    value="<?php echo $SUB_TOTAL + $SHIPPING_FEE; ?>">

            <?php if (isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow'): ?>
                <input type="hidden" name="checkout_mode" value="buynow">
            <?php endif; ?>

            <!-- Shipping Method & Choose Address Light Blue Box -->
            <div class="shipping-address-card">
                <!-- Shipping method -->
                <div class="form-group mb-3">
                    <label class="form-label">Shipping Method:</label>
                    <div class="shipping-method-group">
                        <label class="shipping-method-option active" id="label_doorstep">
                            <input type="radio" name="shipping_method" value="doorstep" checked onchange="onShippingMethodChange()">
                            <span><i class="bi bi-truck"></i> Doorstep Delivery</span>
                        </label>
                        <label class="shipping-method-option" id="label_self_collection" style="<?php echo $is_kluang_default ? '' : 'display:none;'; ?>">
                            <input type="radio" name="shipping_method" value="self_collection" id="selfCollectionRadio" onchange="onShippingMethodChange()">
                            <span><i class="bi bi-shop"></i> Self Collection <small style="opacity:0.7;">(Kluang only)</small></span>
                        </label>
                    </div>
                </div>

                <!-- Doorstep delivery: address selection -->
                <div id="doorstep_address_select" class="form-group mb-0">
                    <label class="form-label">Choose an Address:</label>
                    <select name="address_id" id="address_id" class="form-control" onchange="autoFill(this)">
                        <option value="">-- Select Address --</option>
                        <?php while ($addr = mysqli_fetch_assoc($address_result)): ?>
                            <option value="<?php echo $addr['ADDRESS_ID']; ?>"
                                data-fname="<?php echo htmlspecialchars($addr['FIRST_NAME']); ?>"
                                data-lname="<?php echo htmlspecialchars($addr['LAST_NAME']); ?>"
                                data-phone="<?php echo htmlspecialchars($addr['PHONE']); ?>"
                                data-addr="<?php  echo htmlspecialchars($addr['ADDRESS_LINE']); ?>"
                                data-city="<?php  echo htmlspecialchars($addr['CITY']); ?>"
                                data-state="<?php echo htmlspecialchars($addr['STATE']); ?>"
                                data-post="<?php  echo htmlspecialchars($addr['POSTCODE']); ?>"
                                <?php echo $addr['IS_DEFAULT'] ? 'selected' : ''; ?>>
                                <?php echo ($addr['IS_DEFAULT'] ? '[Default] ' : '') . htmlspecialchars($addr['ADDRESS_LINE']); ?>
                            </option>
                        <?php endwhile; ?>
                        <option value="new">+ Add New Address</option>
                    </select>
                </div>
            </div>

            <!-- Doorstep delivery: detail fields -->
            <div id="doorstep_section">

                <!-- name -->
                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" class="form-control"/>
                </div>

                <!-- phone -->
                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number:</label>
                    <div class="phone-group">
                        <select id="country-code" name="country-code" class="form-control">
                            <option value="+60">+60 (MY)</option>
                        </select>
                        <input type="text" id="phone" name="phone" class="form-control" maxlength="15" placeholder="123456789">
                    </div>
                </div>

                <!-- address -->
                <div class="form-group">
                    <label for="address" class="form-label">Address:</label>
                    <input type="text" id="address" name="shipping_address" class="form-control input-readonly" readonly/>
                </div>

                <div class="row-group">
                    <div class="form-group">
                        <label for="city" class="form-label">City:</label>
                        <input type="text" id="city" name="city" class="form-control input-readonly" readonly/>
                    </div>
                    <div class="form-group">
                        <label for="company" class="form-label">Company (Optional):</label>
                        <input type="text" id="company" name="company" class="form-control input-readonly" readonly/>
                    </div>
                </div>

                <div class="row-group">
                    <div class="form-group">
                        <label for="postcode" class="form-label">Postcode:</label>
                        <input type="text" id="postcode" name="postcode" class="form-control input-readonly" readonly/>
                    </div>
                    <div class="form-group">
                        <label for="state" class="form-label">State:</label>
                        <input type="text" id="state" name="state" class="form-control input-readonly" readonly/>
                    </div>
                </div>

                <div class="delivery-info-box">
                    <p class="text-success"><i class="bi bi-truck"></i> Delivered nationwide within Malaysia. Delivery fee is currently free. Self collection is only available in Kluang.</p>
                </div>

            </div>

            <!-- Self collection: show pickup location -->
            <div id="self_collection_section" style="display:none;">
                <div class="pickup-box">
                    <h6><i class="bi bi-geo-alt-fill"></i> Pickup Location</h6>
                    <?php if ($bakery_info): ?>
                        <?php if (!empty($bakery_info['SHOP_NAME'])): ?>
                            <p><strong><?php echo htmlspecialchars($bakery_info['SHOP_NAME']); ?></strong></p>
                        <?php endif; ?>
                        <?php if (!empty($bakery_info['ADDRESS'])): ?>
                            <p><?php echo htmlspecialchars($bakery_info['ADDRESS']); ?><?php echo !empty($bakery_info['POSTCODE']) ? ', ' . htmlspecialchars($bakery_info['POSTCODE']) : ''; ?><?php echo !empty($bakery_info['CITY']) ? ' ' . htmlspecialchars($bakery_info['CITY']) : ''; ?><?php echo !empty($bakery_info['STATE']) ? ', ' . htmlspecialchars($bakery_info['STATE']) : ''; ?></p>
                        <?php endif; ?>
                        <?php if (!empty($bakery_info['PHONE'])): ?>
                            <p>Phone: <?php echo htmlspecialchars($bakery_info['PHONE']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($bakery_info['EMAIL'])): ?>
                            <p>Email: <?php echo htmlspecialchars($bakery_info['EMAIL']); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p class="text-danger"><i class="bi bi-info-circle"></i> We'll notify you once your order is ready for pickup.</p>
                    <p class="text-danger"><i class="bi bi-telephone-fill"></i> After placing your order, please reach out to us via the phone/email above to confirm your pickup arrangement. </p>
                </div>
            </div>

            <div class="button-group">
                <a href="shopping_cart.php" class="btn-back">Back to Cart</a>
                <button type="submit" class="btn-pay">Proceed to Payment </button>
            </div>

        </div>

        <!-- Order Summary-->
        <div class="right-panel">
            <h3>Order Summary</h3>
            <hr>

            <?php foreach ($cart_items as $index => $item): ?>
            <div class="item-details">
                <input type="hidden" name="cart_items[<?php echo $index; ?>][cart_item_id]" value="<?php echo htmlspecialchars($item['CART_ITEM_ID']); ?>">

                <!-- item image -->
                <div class="item-image">
                    <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_product.png'; ?>" alt="product">
                </div>

                <!-- item details -->
                <div class="item-text">

                    <!-- product name -->
                    <p><strong><?php echo htmlspecialchars($item['PRODUCT_NAME']); ?></strong></p>

                    <!-- variant label and quantity -->
                    <p class="size-qty">
                        <?php if (!empty($item['VARIANT_LABEL'])): ?>
                            <small><?php echo htmlspecialchars($item['VARIANT_LABEL']); ?></small>
                            &nbsp;|&nbsp;
                        <?php endif; ?>
                        <small>Qty: <?php echo intval($item['QUANTITY']); ?></small>
                    </p>

                    <!-- unit price -->
                    <p class="unit-price">
                        <small>Unit Price: RM <?php echo number_format($item['final_unit_price'], 2); ?></small>
                    </p>

                    <!-- add-ons -->
                    <?php if (!empty($item['addons'])): ?>
                        <div class="addon-box">
                            <strong>Add-ons:</strong>
                            <?php foreach ($item['addons'] as $addon): ?>
                                <div>• <?php echo htmlspecialchars($addon['ADDON_NAME']); ?> (x<?php echo $addon['QUANTITY']; ?>) - RM <?php echo number_format($addon['ADDON_PRICE'] * $addon['QUANTITY'], 2); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            <hr>
            <?php endforeach; ?>

            <!-- Voucher -->
            <div class="form-group">
                <label class="form-label">Promo Code:</label>
                <div class="voucher-row">
                    <select id="voucher_select" name="voucher_id" class="form-control">
                        <option value="0" data-rate="0">-- Select Voucher --</option>
                        <?php foreach ($my_vouchers as $v):
                            $selected = ($v['VOUCHER_ID'] == $passed_voucher_id) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $v['VOUCHER_ID']; ?>"
                                    data-rate="<?php echo $v['DISCOUNT_RATE']; ?>"
                                    data-min="<?php echo htmlspecialchars($v['MIN_SPEND']); ?>"
                                    <?php echo $selected; ?>>
                                <?php echo htmlspecialchars($v['VOUCHER_NAME']); ?> (<?php echo $v['DISCOUNT_RATE']; ?>% OFF)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-apply" onclick="applyVoucher()">Apply</button>
                </div>
            </div>
            <br>

            <!-- Price details -->
            <div class="price-details">
                <p><span>Subtotal</span><span id="display-subtotal">RM <?php echo number_format($SUB_TOTAL, 2); ?></span></p>
                <p><span>Discount</span><span id="display-discount">- RM 0.00</span></p>
                <p><span>Delivery Fee</span> <span id="display-shipping">RM <?php echo number_format($SHIPPING_FEE, 2); ?></span></p>
            </div>

            <div class="total-box">
                <span>Total Price</span>
                <span id="display-total">RM <?php echo number_format($SUB_TOTAL + $SHIPPING_FEE, 2); ?></span>
            </div>
         </div>
    </form>
</div>

<!-- Added Address Pop-up Box -->
<div id="addressModal" class="modal-overlay">
    <div class="modal-content">
        <span class="close-modal" onclick="document.getElementById('addressModal').style.display='none'">&times;</span>
        <h4>Add New Address</h4>

        <form method="post" action="checkout.php">
            <?php foreach ($selected_item_ids as $id): ?>
                <input type="hidden" name="selected_items[]" value="<?php echo intval($id); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="selected_voucher_id" value="<?php echo $passed_voucher_id; ?>">

            <div class="d-flex gap-2 mb-2">
                <input type="text" name="new_fname"    placeholder="First Name"  class="form-control" required>
                <input type="text" name="new_lname"    placeholder="Last Name"   class="form-control" required>
            </div>
            <input type="text" name="new_phone"    placeholder="Phone Number" class="form-control mb-2" required>
            <input type="text" name="new_address"  placeholder="Address Line" class="form-control mb-2" required>
            <div class="d-flex gap-2 mb-2">
                <input type="text" name="new_city"     placeholder="City"     class="form-control" required>
                <input type="text" name="new_postcode" placeholder="Postcode" class="form-control" required>
                <input type="text" name="new_state"    placeholder="State"    class="form-control" required>
            </div>
            <button type="submit" name="save_new_address" class="btn-pay">Save Address</button>
        </form>
    </div>
</div>

<!-- Voucher Popup -->
<div id="voucherPopup" class="voucher-popup-overlay">
    <div class="voucher-popup-box">
        <div class="voucher-popup-icon"><i class="bi bi-ticket-perforated-fill" style="color: var(--main-color); font-size: 38px;"></i></div>
        <div class="voucher-popup-title" id="voucherPopupTitle"></div>
        <div class="voucher-popup-msg"  id="voucherPopupMsg"></div>
        <button class="voucher-popup-btn" onclick="closeVoucherPopup()">OK</button>
    </div>
</div>

<script>
const baseSubtotal    = <?php echo json_encode($SUB_TOTAL); ?>;
const shippingFee     = <?php echo json_encode($SHIPPING_FEE); ?>;

// Voucher popup helpers
function showVoucherPopup(title, msgHtml) {
    document.getElementById('voucherPopupTitle').textContent = title;
    document.getElementById('voucherPopupMsg').innerHTML     = msgHtml;
    document.getElementById('voucherPopup').style.display   = 'block';
}

function closeVoucherPopup() {
    document.getElementById('voucherPopup').style.display = 'none';
}

// Voucher application handler (mirrors payment.php's behaviour)
function applyVoucher() {
    var sel      = document.getElementById('voucher_select');
    var opt      = sel.options[sel.selectedIndex];
    var minSpend = parseFloat(opt.getAttribute('data-min')) || 0;
    var rate     = parseFloat(opt.getAttribute('data-rate')) || 0;

    if (sel.value === '0') {
        showVoucherPopup('No Voucher Selected', 'Please select a voucher first.');
        return;
    }

    if (baseSubtotal < minSpend) {
        showVoucherPopup(
            'Voucher Cannot Be Applied',
            'Minimum spend required: <span>RM ' + minSpend.toFixed(2) + '</span><br>' +
            'Your current subtotal: <span>RM ' + baseSubtotal.toFixed(2) + '</span><br><br>' +
            'Please select a different voucher.'
        );
        sel.value = '0';
        updateTotal();
        return;
    }

    showVoucherPopup(
        'Voucher Applied!',
        'You get <span>' + rate + '% off</span> your order.'
    );
    updateTotal();
}

// Address Auto-fill Functionality
function autoFill(select) {

    // Trigger modal if user selects "Add New Address"
    if (select.value === 'new') {
        document.getElementById('addressModal').style.display = 'block';
        select.selectedIndex = 0;
        return;
    }

    // Reset form if no valid address is selected
    if (select.value === '') {
        document.getElementById('full_name').value = '';
        document.getElementById('phone').value      = '';
        document.getElementById('address').value    = '';
        document.getElementById('city').value        = '';
        document.getElementById('postcode').value   = '';
        document.getElementById('state').value       = '';
        updateSelfCollectionVisibility('');
        return;
    }

    var opt = select.options[select.selectedIndex];

    // Populate form fields with selected address data
    var fname = opt.getAttribute('data-fname') || '';
    var lname = opt.getAttribute('data-lname') || '';
    document.getElementById('full_name').value = (fname + ' ' + lname).trim();

    var addrPhone = opt.getAttribute('data-phone') || '';
    if (addrPhone.startsWith('+60')) {
        addrPhone = addrPhone.substring(3);
    } else if (addrPhone.startsWith('0')) {
        addrPhone = addrPhone.substring(1);
    }
    document.getElementById('phone').value = addrPhone;

    document.getElementById('address').value  = opt.getAttribute('data-addr')  || '';
    document.getElementById('city').value      = opt.getAttribute('data-city')  || '';
    document.getElementById('postcode').value = opt.getAttribute('data-post')  || '';
    document.getElementById('state').value     = opt.getAttribute('data-state') || '';

    // 根据当前选中地址的城市，判断是否显示 Self Collection
    updateSelfCollectionVisibility(opt.getAttribute('data-city') || '');
}

// 新增函数：判断城市是否为 Kluang，决定 Self Collection 是否可选
function updateSelfCollectionVisibility(city) {
    var selfCollectionLabel = document.getElementById('label_self_collection');
    var selfCollectionRadio = document.getElementById('selfCollectionRadio');
    var isKluang = city.trim().toLowerCase() === 'kluang';

    if (isKluang) {
        selfCollectionLabel.style.display = '';
    } else {
        selfCollectionLabel.style.display = 'none';

        // 如果用户之前选了 self collection，但换成了非居銮地址，强制切回 doorstep
        if (selfCollectionRadio.checked) {
            document.querySelector('input[name="shipping_method"][value="doorstep"]').checked = true;
            onShippingMethodChange();
        }
    }
}

// Toggle between Doorstep Delivery and Self Collection
function onShippingMethodChange() {
    var method       = document.querySelector('input[name="shipping_method"]:checked').value;
    var doorstep     = document.getElementById('doorstep_section');
    var doorstepAddr = document.getElementById('doorstep_address_select');
    var pickup       = document.getElementById('self_collection_section');

    var addressFields = ['address_id', 'full_name', 'phone', 'address', 'city', 'postcode', 'state'];

    if (method === 'doorstep') {
        if (doorstep) doorstep.style.display = '';
        if (doorstepAddr) doorstepAddr.style.display = '';
        if (pickup) pickup.style.display   = 'none';
        document.getElementById('label_doorstep').classList.add('active');
        document.getElementById('label_self_collection').classList.remove('active');
        addressFields.forEach(function(id) {
            var el = document.getElementById(id);
            if (el && (id === 'address_id' || id === 'full_name' || id === 'phone' || id === 'address' || id === 'postcode')) {
                el.required = true;
            }
        });
    } else {
        if (doorstep) doorstep.style.display = 'none';
        if (doorstepAddr) doorstepAddr.style.display = 'none';
        if (pickup) pickup.style.display   = '';
        document.getElementById('label_self_collection').classList.add('active');
        document.getElementById('label_doorstep').classList.remove('active');
        addressFields.forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.required = false;
        });
    }
}

// Updates summary totals and hidden form inputs
function updateTotal() {
    var voucherSelect  = document.getElementById('voucher_select');
    var selectedOption = voucherSelect.options[voucherSelect.selectedIndex];
    var discountRate   = parseFloat(selectedOption.getAttribute('data-rate')) || 0;

    var minSpend = parseFloat(selectedOption.getAttribute('data-min')) || 0;
    var discount = (baseSubtotal >= minSpend && discountRate > 0) ? baseSubtotal * (discountRate / 100) : 0;
    var finalTotal = baseSubtotal - discount + shippingFee;

    document.getElementById('display-discount').textContent = '- RM ' + discount.toFixed(2);
    document.getElementById('display-shipping').textContent = 'RM '   + shippingFee.toFixed(2);
    document.getElementById('display-total').textContent    = 'RM '   + finalTotal.toFixed(2);

    // Sync values for submission
    document.getElementById('input_discount_amount').value = discount.toFixed(2);
    document.getElementById('input_final_amount').value    = finalTotal.toFixed(2);
}

// Form Submission validation
function validateFormBeforeSubmit() {
    var method = document.querySelector('input[name="shipping_method"]:checked').value;

    if (method === 'doorstep') {
        var addressId = document.getElementById('address_id').value;
        if (!addressId || addressId === 'new') {
            showVoucherPopup('Address Required', 'Please select or add a delivery address.');
            return false;
        }
    }

    return true;
}

// Initialization on page load
window.onload = function() {
    var addrSelect = document.getElementById('address_id');
    if (addrSelect && addrSelect.value && addrSelect.value !== 'new') {
        autoFill(addrSelect);
    }
    onShippingMethodChange();
    updateTotal();
    <?php if (!empty($address_saved_flag)): ?>
        showVoucherPopup('Address Saved', 'New address saved successfully!');
    <?php endif; ?>
};
</script>
<?php include_once 'include/footer.php'; ?>
</body>
</html>