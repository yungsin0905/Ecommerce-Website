<?php
session_start();
require_once 'include/config.php';

// 1. Check if user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// 2. Make sure user came from checkout
$selected_item_ids = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
$custom_ids        = isset($_POST['custom_ids'])     ? $_POST['custom_ids']     : [];

// Check if it's a "Buy Now" single product purchase
$is_buynow = (isset($_POST['checkout_mode']) && $_POST['checkout_mode'] === 'buynow') ||
             (isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow');
// Redirect to cart if no items selected and not a buy-now action
if (empty($selected_item_ids) && empty($custom_ids) && !$is_buynow) {
    header("Location: shopping_cart.php");
    exit;
}

// 3. Get customer information
$user_sql    = "SELECT CUSTOMER_ID, CUSTOMER_NAME, EMAIL, PHONE, WALLET_BALANCE
                FROM customer
                WHERE CUSTOMER_ID = $customer_id";
$user_result = mysqli_query($conn, $user_sql);
$user_data   = mysqli_fetch_assoc($user_result);


// 4. Carry over delivery info from checkout page
$address_id        = intval($_POST['address_id']      ?? 0);
$delivery_date     = $_POST['delivery_date']   ?? date('Y-m-d');
$delivery_time     = $_POST['delivery_time']   ?? '';
$passed_voucher_id = intval($_POST['voucher_id'] ?? 0);

$full_name        = $_POST['full_name']        ?? '';
$phone_no         = $_POST['phone']            ?? '';
$shipping_address = $_POST['shipping_address'] ?? '';
$city             = $_POST['city']             ?? '';
$postcode         = $_POST['postcode']         ?? '';
$state            = $_POST['state']            ?? '';


// 5. Shipping Fee Calculation
$SHIPPING_FEE = 0.00;

if ($address_id > 0) {
    // Normal/buynow: get fee from selected address
    $addr_sql = "SELECT dc.DELIVERY_FEE
                 FROM address a
                 LEFT JOIN delivery_coverage dc
                        ON a.POSTCODE = dc.POSTCODE
                       AND dc.STATUS = 'Active'
                 WHERE a.ADDRESS_ID  = $address_id
                   AND a.CUSTOMER_ID = $customer_id";
    $addr_res = mysqli_query($conn, $addr_sql);
    if ($addr_row = mysqli_fetch_assoc($addr_res)) {
        $SHIPPING_FEE = !empty($addr_row['DELIVERY_FEE'])
                        ? floatval($addr_row['DELIVERY_FEE'])
                        : 0.00;
    }
} elseif (!empty($custom_ids)) {
    // Custom cake: address_id is 0, get fee directly from postcode 81000
    $fee_res = mysqli_query($conn,
        "SELECT DELIVERY_FEE FROM delivery_coverage 
         WHERE POSTCODE = '81000' AND STATUS = 'Active' LIMIT 1"
    );
    if ($fee_row = mysqli_fetch_assoc($fee_res)) {
        $SHIPPING_FEE = floatval($fee_row['DELIVERY_FEE']);
    }
}

// 6A. pre-made cart items 
$cart_items           = [];
$SUB_TOTAL            = 0;
$capacity_cakes_count = 0;

// Handle buy now mode — fetch product data directly from session instead of cart
if ($is_buynow && empty($selected_item_ids) && isset($_SESSION['buynow_item'])) {
    $item = $_SESSION['buynow_item'];
    $variant_id = intval($item['variant_id']);
    $qty = intval($item['quantity']);

    $sql = "SELECT p.PRODUCT_NAME, p.COVER_IMAGE, p.ALLOW_WRITING,
                   pv.VARIANT_SIZE, pv.VARIANT_PRICE, pv.VARIANT_STOCK, pv.VARIANT_ID, pv.PRODUCT_ID
            FROM product_variant pv
            JOIN product p ON pv.PRODUCT_ID = p.PRODUCT_ID
            WHERE pv.VARIANT_ID = $variant_id AND p.IS_DELETED = 0 LIMIT 1";
    $res = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($res);

    if ($row) {
        $addon_total = 0;
        $addons = [];
        // Calculate dynamic add-on pricing for Buy Now
        foreach (($item['selected_addons'] ?? []) as $addon_id) {
            $addon_id = intval(trim($addon_id));
            $aqty = intval($item['addon_qtys'][$addon_id] ?? 1);
            $addon_res = mysqli_query($conn, "SELECT * FROM add_on WHERE ADD_ON_ID = $addon_id AND IS_DELETED = 0");
            $addon_row = mysqli_fetch_assoc($addon_res);
            if ($addon_row) {
                $addon_row['QUANTITY'] = $aqty;
                $addon_row['CARD_TEXT'] = $item['card_message'] ?? '';
                $addons[] = $addon_row;
                $addon_total += floatval($addon_row['ADD_ON_PRICE']) * $aqty;
            }
        }

        $unit_price = floatval($row['VARIANT_PRICE']);
        $row['is_custom'] = false;
        $row['final_unit_price'] = $unit_price;
        $row['SINGLE_SET_PRICE'] = $unit_price + $addon_total;
        $row['QUANTITY'] = $qty;
        $row['CAKE_WRITING'] = $item['cake_writing'] ?? '';
        $row['CART_ITEM_ID'] = 'buynow_temp';
        $row['addons'] = $addons;

        $SUB_TOTAL += $row['SINGLE_SET_PRICE'] * $qty;
        $cart_items[] = $row;
    }
}

// Handle items selected from the user's saved shopping cart
if (!empty($selected_item_ids)) {
    $ids_string = implode(',', array_map('intval', $selected_item_ids));

    $sql = "SELECT ci.*, p.PRODUCT_NAME, p.COVER_IMAGE,
                   pv.VARIANT_SIZE, pv.VARIANT_PRICE, pv.VARIANT_STOCK
            FROM cart_item ci
            LEFT JOIN product p ON ci.PRODUCT_ID = p.PRODUCT_ID
            LEFT JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
            WHERE ci.CART_ITEM_ID IN ($ids_string)";

    $result = mysqli_query($conn, $sql);

    while ($row = mysqli_fetch_assoc($result)) {
        $qty = intval($row['QUANTITY']);
        $row['is_custom']        = false;
        $row['final_unit_price'] = floatval($row['VARIANT_PRICE']);

        // Fetch add-ons associated with specific cart items
        $addon_sql    = "SELECT cia.*, ao.ADD_ON_NAME, ao.ADD_ON_PRICE
                         FROM cart_item_addon cia
                         JOIN add_on ao ON cia.ADD_ON_ID = ao.ADD_ON_ID
                         WHERE cia.CART_ITEM_ID = " . intval($row['CART_ITEM_ID']);
        $addon_result = mysqli_query($conn, $addon_sql);

        $row['addons']  = [];
        $addon_total_for_item = 0;

        while ($addon = mysqli_fetch_assoc($addon_result)) {
            $row['addons'][]       = $addon;
            $addon_total_for_item += floatval($addon['ADD_ON_PRICE']) * intval($addon['QUANTITY']);
        }

        $row['SINGLE_SET_PRICE'] = $row['final_unit_price'] + $addon_total_for_item;
        $SUB_TOTAL              += $row['SINGLE_SET_PRICE'] * $qty;

        $cart_items[] = $row;
    }
}

// 6B. custom cake items
$is_custom_locked   = false;
$locked_delivery_date = '';
$locked_delivery_slot = '';

if (!empty($custom_ids)) {

    $is_custom_locked = true;
    $c_ids_string     = implode(',', array_map('intval', $custom_ids));

    $c_sql    = "SELECT * FROM custom
                 WHERE CUSTOM_ID IN ($c_ids_string)
                   AND CUSTOMER_ID = $customer_id
                   AND IS_DELETED  = 0";
    $c_result = mysqli_query($conn, $c_sql);

    $first_custom = true;
    while ($row = mysqli_fetch_assoc($c_result)) {

        $qty   = intval($row['QUANTITY']);
        $price = floatval($row['QUOTED_PRICE']); // total price, no multiply

        $SUB_TOTAL            += $price;
        $capacity_cakes_count += $qty;

        // Sync delivery slot times from custom records
        if ($first_custom) {
           $locked_delivery_date = $row['DELIVERY_DATE'];

           $delivery_slot_str  = $row['DELIVERY_SLOT']; // "2:00 PM - 4:00 PM"
           $matched_start_time = '';
           $custom_start       = trim(explode(' - ', $delivery_slot_str)[0] ?? '');
           $custom_start_ts    = strtotime($custom_start);

           $all_slots_q = mysqli_query($conn, "SELECT SLOT_ID, START_TIME FROM delivery_slots WHERE STATUS = 'Active'");
           while ($sr = mysqli_fetch_assoc($all_slots_q)) {
               if (strtotime($sr['START_TIME']) === $custom_start_ts) {
               $matched_start_time = $sr['START_TIME'];
               break;
              }
           }

           $locked_delivery_slot = $matched_start_time ?: '';
           $first_custom = false;
        }

        // Format custom cake item for checkout
        $cart_items[] = [
            'is_custom'        => true,
            'CART_ITEM_ID'     => 'custom_' . $row['CUSTOM_ID'],
            'CUSTOM_ID'        => $row['CUSTOM_ID'],
            'PRODUCT_NAME'     => $row['STYLE_NAME_SNAPSHOT'],
            'COVER_IMAGE'      => $row['REF_IMAGE'],
            'final_unit_price' => $price,
            'SINGLE_SET_PRICE' => $price,
            'QUANTITY'         => $qty,
            'VARIANT_SIZE'     => $row['SIZE'],
            'IDEAL_FLAVOUR'    => $row['IDEAL_FLAVOUR'],
            'CUSTOM_DES'       => $row['CUSTOM_DES'],
            'addons'           => [],
            'CAKE_WRITING'     => '',
        ];
    }
}


// 7. Check available vouchers
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
                  AND v.IS_DELETED    = 0
                  AND (v.TIER_ID IS NULL OR v.TIER_ID = 0 OR v.TIER_ID = $customer_tier_id)";

$voucher_result = mysqli_query($conn, $voucher_sql);
$my_vouchers    = [];
$today          = new DateTime();

if ($voucher_result) {
    while ($v_row = mysqli_fetch_assoc($voucher_result)) {

        // Check date validity (Start/Expiry)
        if (!empty($v_row['START_DATE']) && $v_row['START_DATE'] !== '0000-00-00 00:00:00') {
            $start_time = new DateTime($v_row['START_DATE']);
            if ($today < $start_time) continue;
        }

        if (!empty($v_row['VOUCHER_EXPIRY']) &&
            $v_row['VOUCHER_EXPIRY'] !== '0000-00-00 00:00:00' &&
            $v_row['VOUCHER_EXPIRY'] !== '0000-00-00') {
            $expiry_time = new DateTime($v_row['VOUCHER_EXPIRY']);
            if ($today > $expiry_time) continue;
        }

        if (!empty($v_row['CUSTOMER_EXPIRY']) &&
            $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00 00:00:00' &&
            $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00') {
            $cv_expiry = new DateTime($v_row['CUSTOMER_EXPIRY']);
            if ($today > $cv_expiry) continue;
        }
         // Check global/user usage limits
        if ($v_row['MAX_USAGE'] != -1 && $v_row['GLOBAL_USED_COUNT'] >= $v_row['MAX_USAGE']) continue;

        $customer_used = intval($v_row['CUSTOMER_USED_COUNT'] ?? 0);
        if ($v_row['PER_USER_LIMIT'] != -1 && $customer_used >= $v_row['PER_USER_LIMIT']) continue;

        $v_row['is_eligible'] = ($SUB_TOTAL >= floatval($v_row['MIN_SPEND']));

        $my_vouchers[] = $v_row;
    }
}


// 8. Calculate discount and final total 
$DISCOUNT_AMOUNT = 0.00;

if ($passed_voucher_id > 0) {
    foreach ($my_vouchers as $v) {
        if (intval($v['VOUCHER_ID']) === $passed_voucher_id && $v['is_eligible']) {
            $DISCOUNT_AMOUNT = $SUB_TOTAL * (floatval($v['DISCOUNT_RATE']) / 100);
            break;
        }
    }
}

$TOTAL_AMOUNT = $SUB_TOTAL - $DISCOUNT_AMOUNT + $SHIPPING_FEE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
    /* ===== Design tokens aligned with checkout.php ===== */
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
        width: 66%;
        height: 2px;
        background: var(--accent-blue);
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
        border-color: var(--accent-blue);
        color: var(--accent-blue);
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

    /* Two-column layout (matches checkout.php) */
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

    .left-panel p{
        font-size:12px;
        color:#b4b4b4;
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

    .payment-control {
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

    .payment-control:focus {
        outline: none;
        border-color: var(--main-color);
        box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
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

    /* Payment method options */
    .method-group { 
        margin-bottom: 16px; 
    }

    .method-label {
        display: flex;
        width: 100%;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        font-weight: 700;
        font-size: 14px;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .method-label img {
        height: 30px;
        margin-left: 10px;
    }

    .method-label input[type="radio"] {
        accent-color: var(--main-color);
    }

    .details-box {
        padding: 12px;
        display: none;
        background: var(--secondary-color);
        border-radius: 12px;
        margin-top: 10px;
        border: 1px solid var(--search-border-color);
    }

    .row-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0 20px;
        margin-bottom: 15px;
    }

    .row-group label {
        font-size: 12px;
        margin-bottom: 5px;
        margin-top: 10px;
        color: var(--font2-color);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'Inter', sans-serif;
    }

    #ewallet {
        display: none;
        flex-direction: row;
        flex-wrap: nowrap;
        gap: 15px;
        align-items: center;
        justify-content: center;
        text-align: center;
        font-size: 12px;
    }

    #ewallet img {
        display: block;
        width: 50px;
        height: 50px;
        margin: 5px auto;
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(27, 42, 60, 0.1);
    }

    #ewallet label {
        color: var(--font2-color);
        font-weight: 700;
        font-size: 12px;
        font-family: 'Inter', sans-serif;
    }

    .field-error {
        color: #c0392b;
        font-size: 12px;
        display: none;
        margin-top: 4px;
        font-family: 'Inter', sans-serif;
    }

    /* Recommended badge*/
    .text-highlight {
        background-color: #EBF4FC;
        color: var(--btn-hover);
        letter-spacing: 0.5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-weight: 700;
        font-size: 11px;
        border: 1px solid var(--main-color);
        display: inline-block;
    }

    .text-danger {
        font-size: 13px;
        color: #c0392b;
        font-weight: 700;
    }

    .btn-topup {
        background-color: transparent;
        color: var(--font2-color);
        border: 1px solid var(--font2-color);
        border-radius: 20px;
        padding: 3px 10px;
        font-size: 12px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        margin-left: 10px;
    }

    .btn-topup:hover {
        background-color: var(--card-bg-color);
        color: var(--main-color);
        border-color: var(--main-color);
    }

    .btn-pay {
        background-color: var(--main-color);
        color: #FFFFFF;
        font-size: 14px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        border: none;
        border-radius: 20px;
        width: 100%;
        padding: 10px 22px;
        cursor: pointer;
        margin-top: 10px;
        transition: var(--transition);
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

    /* Order summary */
    .cake-details {
        display: flex;
        gap: 16px;
        padding-bottom: 16px;
    }

    .cake-image img {
        width: 90px;
        height: 90px;
        border-radius: 10px;
        object-fit: cover;
        border: 1px solid var(--search-border-color);
    }

    .cake-text p {
        font-size: 13px;
        line-height: 1.5;
        margin: 2px 0;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .cake-text .size-qty   { 
        color: var(--font2-color); 
        opacity: 0.85; 
    }

    .cake-text .unit-price { 
        color: var(--font-color); 
        font-weight: 700; 
        font-family: 'Poppins', sans-serif;
    }

    .cake-text .text-muted { 
        color: var(--font2-color); 
        opacity: 0.85; 
    }

    .custom-badge {
        display: inline-block;
        background: var(--card-bg-color);
        color: var(--main-color);
        font-size: 11px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 50px;
        margin-bottom: 4px;
        border: 1px solid var(--search-border-color);
    }

    /* Add-ons*/
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

    .voucher-row { 
        display: flex; 
        gap: 10px;
        margin: 20px 0;
        width: 100%;
    }

    .promo-input {
        padding: 8px 14px;
        border: 1px solid var(--search-border-color);
        border-radius: 20px;
        background-color: var(--secondary-color);
        width: 100%;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        color: var(--font-color);
        transition: border-color 0.2s, box-shadow 0.2s;
    }

    .promo-input:focus {
        border-color: var(--main-color);
        outline: none;
        box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
        background-color: white;
    }

    .price-details {
        margin: 20px 0;
    }

    .price-details p {
        font-size: 14px;
        color: var(--font2-color);
        display: flex;
        justify-content: space-between;
        font-family: 'Inter', sans-serif;
    }

    /* Discount*/
    #display-discount {
        color: #27ae60;
        font-weight: 700;
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

    /* Voucher Popup */
    .voucher-popup-overlay {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0; top: 0;
        width: 100%; height: 100%;
        background: rgba(27, 42, 60, 0.4);
    }

    .voucher-popup-box {
        background: white;
        width: 360px;
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
    <!-- progress bar -->
    <div class="step-container">
        <div class="step-line"></div>
        <div class="step-line-progress"></div>
        <div class="step-icon step-done">
            <div class="step-circle"><i class="bi bi-cart-check-fill"></i></div>
            <span>Your selection</span>
        </div>
        <div class="step-icon step-done">
            <div class="step-circle"><i class="bi bi-geo-alt-fill"></i></div>
            <span>Checkout</span>
        </div>
        <div class="step-icon step-active">
            <div class="step-circle"><i class="bi bi-credit-card"></i></div>
            <span>Make Payment</span>
        </div>
        <div class="step-icon step-inactive">
            <div class="step-circle"><i class="bi bi-check2-circle"></i></div>
            <span>Complete</span>
        </div>
    </div>

    <form method="post" action="process_payment.php" onsubmit="return validatePayment()" class="main-form">

        <?php foreach ($selected_item_ids as $id): ?>
            <input type="hidden" name="selected_items[]" value="<?php echo intval($id); ?>">
        <?php endforeach; ?>

        <?php foreach ($custom_ids as $cid): ?>
            <input type="hidden" name="custom_ids[]" value="<?php echo intval($cid); ?>">
        <?php endforeach; ?>

        <input type="hidden" name="address_id" value="<?php echo $address_id; ?>">
        <input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($delivery_date); ?>">
        <input type="hidden" name="delivery_time" value="<?php echo htmlspecialchars(!empty($locked_delivery_slot) ? $locked_delivery_slot : $delivery_time); ?>">
        <input type="hidden" name="full_name" value="<?php echo htmlspecialchars($full_name); ?>">
        <input type="hidden" name="phone" value="<?php echo htmlspecialchars($phone_no); ?>">
        <input type="hidden" name="shipping_address" value="<?php echo htmlspecialchars($shipping_address); ?>">
        <input type="hidden" name="city" value="<?php echo htmlspecialchars($city); ?>">
        <input type="hidden" name="postcode" value="<?php echo htmlspecialchars($postcode); ?>">
        <input type="hidden" name="state" value="<?php echo htmlspecialchars($state); ?>">

        <input type="hidden" id="input_sub_total" name="sub_total" value="<?php echo $SUB_TOTAL; ?>">
        <input type="hidden" id="input_shipping_fee" name="shipping_fee" value="<?php echo $SHIPPING_FEE; ?>">
        <input type="hidden" id="input_discount_amount" name="discount_amount" value="<?php echo $DISCOUNT_AMOUNT; ?>">
        <input type="hidden" id="input_final_amount" name="final_amount" value="<?php echo $TOTAL_AMOUNT; ?>">
        <input type="hidden" id="input_voucher_id" name="voucher_id" value="<?php echo $passed_voucher_id; ?>">

        <?php if ($is_buynow): ?>
           <input type="hidden" name="checkout_mode" value="buynow">
        <?php endif; ?>

        <!-- Payment Method -->
        <div class="left-panel">
            <h3>Select Payment Method</h3>
            <p>Logged in as <strong><?php echo htmlspecialchars($user_data['EMAIL']); ?></strong></p>
            <hr>

            <!-- Option 1: Wallet Balance -->
            <div class="method-group">
                <label class="method-label" id="balance-label">
                    <span>
                        <strong>Balance (RM <?php echo number_format($user_data['WALLET_BALANCE'], 2); ?>)</strong>

                        <?php if ($user_data['WALLET_BALANCE'] < $TOTAL_AMOUNT): ?>
                            <span class="text-danger ms-2" id="balance-error">(Insufficient Balance)</span>
                        <?php endif; ?>

                        <button type="button" class="btn-topup" onclick="window.location.href='topup.php'">
                            + Top Up
                        </button>

                        <div style="margin-top: 5px;">
                            <small class="text-highlight">Recommended: Fast &amp; Secure</small>
                        </div>
                    </span>

                    <input type="radio" name="payment_method" value="Balance" id="radio-balance"
                           <?php echo ($user_data['WALLET_BALANCE'] < $TOTAL_AMOUNT) ? 'disabled' : ''; ?>>
                </label>
            </div>
            <hr>

            <!-- Option 2: Credit / Debit Card -->
            <div class="method-group">
                <label class="method-label">
                    <span>Credit or Debit Card</span>
                    <img src="icon/card.jpeg" alt="card">
                    <input type="radio" name="payment_method" value="Card">
                </label>

                <div id="card-info" class="details-box">
                    <label class="form-label mt-2">Cardholder's Name</label>
                    <input type="text" id="cardholder_name" name="cardholder_name" class="form-control payment-control mb-2" maxlength="50">

                    <label class="form-label">Card Number</label>
                    <input type="text" id="card_number" name="card_number" class="form-control payment-control mb-2" maxlength="16">
                    <span id="card-number-error" class="field-error">Card number must be exactly 16 digits.</span>

                    <div class="row-group">
                        <div>
                            <label class="form-label">Expiry Date</label>
                            <input type="text" id="expiry_date" name="date" class="form-control payment-control" placeholder="MM/YY">
                            <span id="expiry-error" class="field-error"></span>
                        </div>
                        <div>
                            <label class="form-label">CVC</label>
                            <input type="text" id="cvc" name="cvc" class="form-control payment-control" maxlength="3">
                            <span id="cvc-error" class="field-error">CVC must be exactly 3 digits.</span>
                        </div>
                    </div>
                </div>
            </div>
            <hr>

            <!-- Option 3: FPX -->
            <div class="method-group">
                <label class="method-label">
                    <span>FPX</span>
                    <img src="icon/fpx.png" alt="fpx">
                    <input type="radio" name="payment_method" value="FPX">
                </label>
            </div>
            <hr>

            <!-- Option 4: E-Wallet -->
            <div class="method-group">
                <label class="method-label">
                    <span>E-Wallet</span>
                    <input type="radio" name="payment_method" value="E-wallet">
                </label>

                <div id="ewallet" class="details-box">
                    <label>
                        <input type="radio" name="ewallet_provider" value="Touch n Go">
                        <img src="icon/tng.png" alt="Touch n Go">
                        Touch'n Go
                    </label>
                    <label>
                        <input type="radio" name="ewallet_provider" value="Shopee Pay">
                        <img src="icon/shopee.png" alt="Shopee Pay">
                        Shopee Pay
                    </label>
                    <label>
                        <input type="radio" name="ewallet_provider" value="Boost">
                        <img src="icon/boost.png" alt="Boost">
                        Boost
                    </label>
                </div>
            </div>
            <hr>
            <button type="submit" class="btn-pay">Pay Now</button>
        </div>

        <!-- Order Summary -->
        <div class="right-panel">
            <h3>Order Summary</h3>
            <hr>

            <?php foreach ($cart_items as $index => $item): ?>
            <div class="cake-details">

                <input type="hidden" name="cart_items[<?php echo $index; ?>][cart_item_id]"
                       value="<?php echo htmlspecialchars($item['CART_ITEM_ID']); ?>">
                <input type="hidden" name="cart_items[<?php echo $index; ?>][custom_id]"
                       value="<?php echo isset($item['CUSTOM_ID']) ? intval($item['CUSTOM_ID']) : 0; ?>">

                <div class="cake-image">
                    <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_cake.png'; ?>" alt="cake">
                </div>

                <div class="cake-text">
                    <?php if ($item['is_custom']): ?>
                        <span class="custom-badge">✦ Custom Cake</span>
                    <?php endif; ?>

                    <p><strong><?php echo htmlspecialchars($item['PRODUCT_NAME'] ?? 'Custom Cake'); ?></strong></p>

                    <p class="size-qty">
                        <small>Size: <?php echo htmlspecialchars($item['VARIANT_SIZE'] ?? 'N/A'); ?></small>
                        &nbsp;|&nbsp;
                        <small>Qty: <?php echo intval($item['QUANTITY']); ?></small>
                    </p>

                    <p class="unit-price">
                        <small>Unit Price: RM <?php echo number_format($item['final_unit_price'], 2); ?></small>
                    </p>

                    <?php if ($item['is_custom']): ?>
                        <?php if (!empty($item['IDEAL_FLAVOUR'])): ?>
                            <p><small class="text-muted">
                                <i class="bi bi-cake2"></i>
                                Flavour: <?php echo htmlspecialchars($item['IDEAL_FLAVOUR']); ?>
                            </small></p>
                        <?php endif; ?>

                        <?php if (!empty($item['CUSTOM_DES'])): ?>
                            <p><small class="text-muted">
                                <i class="bi bi-chat-left-text"></i>
                                Note: <?php echo htmlspecialchars($item['CUSTOM_DES']); ?>
                            </small></p>
                        <?php endif; ?>

                    <?php else: ?>
                        <?php if (!empty($item['CAKE_WRITING'])): ?>
                            <p><small class="text-muted">
                                <i class="bi bi-pen"></i>
                                Writing: "<?php echo htmlspecialchars($item['CAKE_WRITING']); ?>"
                            </small></p>
                        <?php endif; ?>

                        <?php if (!empty($item['addons'])): ?>
                            <div class="addon-box">
                                <strong>Add-ons:</strong>
                                <?php foreach ($item['addons'] as $addon): ?>
                                    <div>
                                        • <?php echo htmlspecialchars($addon['ADD_ON_NAME']); ?>
                                        (RM<?php echo number_format($addon['ADD_ON_PRICE'], 2); ?>
                                        x <?php echo intval($addon['QUANTITY']); ?>)

                                        <?php if (!empty($addon['CARD_TEXT'])): ?>
                                            <br>
                                            <span style="color: #888;">
                                                Card: "<?php echo htmlspecialchars($addon['CARD_TEXT']); ?>"
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <hr>
            <?php endforeach; ?>

            <!-- Voucher -->
            <div class="mb-3">
                <label class="form-label"><strong>Promo Code:</strong></label>
                <div class="voucher-row">
                    <select id="voucher_select" class="promo-input">
                        <option value="0" data-rate="0" data-min="0">-- Select Voucher --</option>

                        <?php foreach ($my_vouchers as $v):
                           $v_selected = (intval($v['VOUCHER_ID']) === $passed_voucher_id) ? 'selected' : '';
                           $label_text = $v['VOUCHER_NAME'] . ' (' . $v['DISCOUNT_RATE'] . '% OFF)';
                           if (!$v['is_eligible']) {
                               $label_text .= ' — Min spend RM ' . number_format($v['MIN_SPEND'], 2);
                           }
                        ?>
                           <option value="<?php echo $v['VOUCHER_ID']; ?>"
                                   data-rate="<?php echo $v['DISCOUNT_RATE']; ?>"
                                   data-min="<?php echo $v['MIN_SPEND']; ?>"
                                   <?php echo $v_selected; ?>>
                                <?php echo htmlspecialchars($label_text); ?>
                           </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-apply" onclick="applyVoucher()">Apply</button>
                </div>
            </div>

            <!-- Price breakdown -->
            <div class="price-details">
                <p>
                    <span>Subtotal</span>
                    <span id="display-subtotal">RM <?php echo number_format($SUB_TOTAL, 2); ?></span>
                </p>
                <p>
                    <span>Discount</span>
                    <span id="display-discount">- RM <?php echo number_format($DISCOUNT_AMOUNT, 2); ?></span>
                </p>
                <p>
                    <span>Delivery Fee</span>
                    <span id="display-shipping">RM <?php echo number_format($SHIPPING_FEE, 2); ?></span>
                </p>
            </div>

            <div class="total-box">
                <span>Total Price</span>
                <span id="display-total">RM <?php echo number_format($TOTAL_AMOUNT, 2); ?></span>
            </div>
        </div>
    </form>
</div>
<!-- Voucher Popup -->
<div id="voucherPopup" class="voucher-popup-overlay">
    <div class="voucher-popup-box">
        <div class="voucher-popup-icon"><i class="bi bi-ticket-perforated-fill" style="color: var(--accent-blue); font-size: 38px;"></i></div>
        <div class="voucher-popup-title" id="voucherPopupTitle"></div>
        <div class="voucher-popup-msg"  id="voucherPopupMsg"></div>
        <button class="voucher-popup-btn" onclick="closeVoucherPopup()">OK</button>
    </div>
</div>
<script>
const BASE_SUBTOTAL  = <?php echo json_encode($SUB_TOTAL); ?>;
const SHIPPING_FEE   = <?php echo json_encode($SHIPPING_FEE); ?>;
const WALLET_BALANCE = <?php echo json_encode(floatval($user_data['WALLET_BALANCE'])); ?>;

// Toggle payment form sections based on selected payment method
document.querySelectorAll('input[name="payment_method"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        var cardBox    = document.getElementById('card-info');
        var ewalletBox = document.getElementById('ewallet');

        // Toggle 'required' attribute for validation
        cardBox.style.display    = (this.value === 'Card')     ? 'block' : 'none';
        ewalletBox.style.display = (this.value === 'E-wallet') ? 'flex'  : 'none';

        cardBox.querySelectorAll('input').forEach(function(input) {
            if (this.value === 'Card' && input.type !== 'checkbox') {
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        }.bind(this));

        ewalletBox.querySelectorAll('input[name="ewallet_provider"]').forEach(function(input) {
            if (this.value === 'E-wallet') {
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        }.bind(this));
    });
});

function showVoucherPopup(title, msgHtml) {
    document.getElementById('voucherPopupTitle').textContent = title;
    document.getElementById('voucherPopupMsg').innerHTML     = msgHtml;
    document.getElementById('voucherPopup').style.display   = 'block';
}

function closeVoucherPopup() {
    document.getElementById('voucherPopup').style.display = 'none';
}

// Voucher application handler
function applyVoucher() {
    var sel      = document.getElementById('voucher_select');
    var opt      = sel.options[sel.selectedIndex];
    var minSpend = parseFloat(opt.getAttribute('data-min')) || 0;
    var rate     = parseFloat(opt.getAttribute('data-rate')) || 0;

    if (sel.value === '0') {
        showVoucherPopup(
            'No Voucher Selected',
            'Please select a voucher first.'
        );
        return;
    }

    if (BASE_SUBTOTAL < minSpend) {
        showVoucherPopup(
            'Voucher Cannot Be Applied',
            'Minimum spend required: <span>RM ' + minSpend.toFixed(2) + '</span><br>' +
            'Your current subtotal: <span>RM ' + BASE_SUBTOTAL.toFixed(2) + '</span><br><br>' +
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

// Main function to update UI totals and check wallet feasibility
function updateTotal() {
    var sel  = document.getElementById('voucher_select');
    var opt  = sel.options[sel.selectedIndex];
    var discountRate = parseFloat(opt.getAttribute('data-rate')) || 0;
    var minSpend = parseFloat(opt.getAttribute('data-min'))  || 0;

    var discount = 0;
    if (BASE_SUBTOTAL >= minSpend && discountRate > 0) {
        discount = BASE_SUBTOTAL * (discountRate / 100);
    } else if (discountRate > 0) {
        sel.value = '0';
        discount  = 0;
    }

    var finalTotal = BASE_SUBTOTAL - discount + SHIPPING_FEE;

    //Update displays
    document.getElementById('display-discount').textContent = '- RM ' + discount.toFixed(2);
    document.getElementById('display-total').textContent    = 'RM '   + finalTotal.toFixed(2);

    document.getElementById('input_discount_amount').value = discount.toFixed(2);
    document.getElementById('input_final_amount').value    = finalTotal.toFixed(2);
    document.getElementById('input_voucher_id').value      = sel.value;

    // Validate wallet balance against total
    var balanceRadio = document.getElementById('radio-balance');
    var balanceError = document.getElementById('balance-error');

    if (balanceRadio) {
        if (WALLET_BALANCE < finalTotal) {
            balanceRadio.disabled = true;
            if (balanceRadio.checked) balanceRadio.checked = false;
            if (balanceError) balanceError.style.display = 'inline';
        } else {
            balanceRadio.disabled = false;
            if (balanceError) balanceError.style.display = 'none';
        }
    }
}

// Input sanitization and error handling for card fields
document.getElementById('card_number').addEventListener('input', function() {
    var val = this.value.replace(/\D/g, '');
    this.value = val;
    var err = document.getElementById('card-number-error');
    err.style.display = (val.length > 0 && val.length !== 16) ? 'inline' : 'none';
});

document.getElementById('cvc').addEventListener('input', function() {
    var val = this.value.replace(/\D/g, '');
    this.value = val;
    var err = document.getElementById('cvc-error');
    err.style.display = (val.length > 0 && val.length !== 3) ? 'inline' : 'none';
});

// Expiry date formatter (MM/YY) and validation
document.getElementById('expiry_date').addEventListener('input', function() {
    var val = this.value;

    if (val.length === 2 && !val.includes('/')) {
        this.value = val + '/';
        return;
    }

    var err = document.getElementById('expiry-error');

    if (val.length < 5) {
        err.style.display = 'none';
        return;
    }

    if (!/^\d{2}\/\d{2}$/.test(val)) {
        err.textContent   = 'Please enter expiry date in MM/YY format.';
        err.style.display = 'inline';
        return;
    }

    var parts    = val.split('/');
    var expMonth = parseInt(parts[0]);
    var expYear  = parseInt('20' + parts[1]);

    if (expMonth < 1 || expMonth > 12) {
        err.textContent   = 'Invalid expiry month.';
        err.style.display = 'inline';
        return;
    }

    var now       = new Date();
    var thisYear  = now.getFullYear();
    var thisMonth = now.getMonth() + 1;

    if (expYear < thisYear || (expYear === thisYear && expMonth < thisMonth)) {
        err.textContent   = 'Your card has expired. Please use a valid card.';
        err.style.display = 'inline';
    } else {
        err.style.display = 'none';
    }
});

// Final checkout validation before form submission
function validatePayment() {
    var method = document.querySelector('input[name="payment_method"]:checked');
    if (!method) {
        alert('Please select a payment method.');
        return false;
    }

    if (method.value === 'Card') {
        var cardNumber = document.getElementById('card_number').value;
        var cvc        = document.getElementById('cvc').value;
        var expiry     = document.getElementById('expiry_date').value;

        if (!/^\d{16}$/.test(cardNumber)) {
            document.getElementById('card-number-error').style.display = 'inline';
            return false;
        }

        if (!/^\d{3}$/.test(cvc)) {
            document.getElementById('cvc-error').style.display = 'inline';
            return false;
        }

        if (!/^\d{2}\/\d{2}$/.test(expiry)) {
            var err = document.getElementById('expiry-error');
            err.textContent   = 'Please enter expiry date in MM/YY format.';
            err.style.display = 'inline';
            return false;
        }

        if (document.getElementById('expiry-error').style.display === 'inline') {
            return false;
        }
    }

    if (method.value === 'E-wallet') {
        var provider = document.querySelector('input[name="ewallet_provider"]:checked');
        if (!provider) {
            alert('Please select an e-wallet provider.');
            return false;
        }
    }

    return true;
}

window.onload = function() {
    updateTotal();
};
</script>
<?php include_once 'include/footer.php'; ?>
</body>
</html>