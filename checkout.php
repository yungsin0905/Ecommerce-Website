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

//2. Retrieve item IDs and custom cake IDs from the cart
$selected_item_ids = isset($_POST['selected_items']) ? $_POST['selected_items'] : [];
$custom_ids = isset($_POST['custom_ids'])     ? $_POST['custom_ids']     : [];

// Check for applied voucher
$passed_voucher_id = isset($_POST['selected_voucher_id']) ? intval($_POST['selected_voucher_id']) : 0;

//3. Initialize variables for Order Summary
$cart_items           = [];   
$SUB_TOTAL            = 0;   
$capacity_cakes_count = 0;    // Count of custom/preorder cakes (impacts production capacity)
$has_premade          = false; 
$is_custom_locked     = false; 
$locked_delivery_date = '';
$locked_delivery_slot = '';
$recipient_info       = [];  

//4. buy now mode, read from session
if (empty($selected_item_ids) && empty($custom_ids) && isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow') {
    $item = $_SESSION['buynow_item'] ?? null;

    if ($item) {
        $variant_id = intval($item['variant_id']);
        $qty        = intval($item['quantity']);

        // Fetch product and variant details from database
        $sql = "SELECT p.PRODUCT_NAME, p.COVER_IMAGE, p.ALLOW_WRITING,
                       pv.VARIANT_SIZE, pv.VARIANT_PRICE, pv.VARIANT_STOCK, pv.VARIANT_ID, pv.PRODUCT_ID
                FROM product_variant pv
                JOIN product p ON pv.PRODUCT_ID = p.PRODUCT_ID
                WHERE pv.VARIANT_ID = $variant_id AND p.IS_DELETED = 0 LIMIT 1";
        $res = mysqli_query($conn, $sql);
        $row = mysqli_fetch_assoc($res);

        if ($row) {
            if ($qty > intval($row['VARIANT_STOCK'])) {
                echo "<script>alert('Sorry, not enough stock!'); window.location.href='shopping_cart.php';</script>";
                exit;
            }

            // Build the cart item array for buynow mode
            $row['is_custom']        = false;
            $row['final_unit_price'] = floatval($row['VARIANT_PRICE']);
            $row['QUANTITY']         = $qty;
            $row['CAKE_WRITING']     = $item['cake_writing'] ?? '';
            $row['CART_ITEM_ID']     = 'buynow_temp';
            $row['addons']           = [];

            // Process each selected add-on and add to subtotal
            foreach (($item['selected_addons'] ?? []) as $addon_id) {
                $addon_id  = intval(trim($addon_id));
                $aqty      = intval($item['addon_qtys'][$addon_id] ?? 1);
                $addon_res = mysqli_query($conn, "SELECT * FROM add_on WHERE ADD_ON_ID = $addon_id AND IS_DELETED = 0");
                $addon_row = mysqli_fetch_assoc($addon_res);
                if ($addon_row) {
                    $addon_row['QUANTITY']  = $aqty;
                    $addon_row['CARD_TEXT'] = $item['card_message'] ?? '';
                    $row['addons'][]        = $addon_row;
                    $SUB_TOTAL += floatval($addon_row['ADD_ON_PRICE']) * $aqty;
                }
            }

            // Add cake price to subtotal and push item into cart array
            $SUB_TOTAL   += $row['final_unit_price'] * $qty;
            $has_premade  = true;
            $cart_items[] = $row;
        }
    }
}

// 5. Redirect to cart if no items are selected
if (empty($selected_item_ids) && empty($custom_ids) && empty($cart_items)) {
    if (!isset($_SESSION['checkout_mode']) || $_SESSION['checkout_mode'] !== 'buynow') {
        echo "<script>alert('Please select items from your cart.'); window.location.href='shopping_cart.php';</script>";
        exit;
    }
}

//6A. Process standard cart items
if (!empty($selected_item_ids)) {

    // // Sanitize IDs for SQL query
    $ids_string = implode(',', array_map('intval', $selected_item_ids));

    // Fetch product details
    $sql = "SELECT ci.*, 
                   p.PRODUCT_NAME, p.COVER_IMAGE,
                   pv.VARIANT_SIZE, pv.VARIANT_PRICE, pv.VARIANT_STOCK
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

        $row['is_custom'] = false;
        $row['final_unit_price'] = floatval($row['VARIANT_PRICE']);

        // Fetch add-ons for the item
        $addon_sql = "SELECT cia.*, ao.ADD_ON_NAME, ao.ADD_ON_PRICE
                      FROM cart_item_addon cia
                      JOIN add_on ao ON cia.ADD_ON_ID = ao.ADD_ON_ID
                      WHERE cia.CART_ITEM_ID = " . intval($row['CART_ITEM_ID']);
        $addon_result = mysqli_query($conn, $addon_sql);

        $row['addons'] = [];
        while ($addon = mysqli_fetch_assoc($addon_result)) {
            $row['addons'][] = $addon;
            // Include add-on price in subtotal
            $SUB_TOTAL += $addon['ADD_ON_PRICE'] * $addon['QUANTITY'];
        }

        // Add item price to subtotal
        $SUB_TOTAL += $row['final_unit_price'] * $qty;

        $has_premade = true;
        $cart_items[] = $row;
    }
}

//6B. Process Custom Cakes
if (!empty($custom_ids)) {

    $is_custom_locked = true; 
    $c_ids_string     = implode(',', array_map('intval', $custom_ids));

    // Fetch custom cake records belonging to this customer
    $c_sql    = "SELECT * FROM custom 
                 WHERE CUSTOM_ID IN ($c_ids_string) 
                 AND CUSTOMER_ID = $customer_id 
                 AND IS_DELETED = 0";
    $c_result = mysqli_query($conn, $c_sql);

    while ($row = mysqli_fetch_assoc($c_result)) {

        $qty   = intval($row['QUANTITY']);
        $price = floatval($row['QUOTED_PRICE']);

        // Add custom price to subtotal and track production capacity
        $SUB_TOTAL            += $price * $qty;
        $capacity_cakes_count += $qty;

        // Retrieve recipient and date/time info from the first custom cake
        if (empty($recipient_info)) {
           $locked_delivery_date = $row['DELIVERY_DATE'];
           $locked_delivery_slot = $row['DELIVERY_SLOT'];
           preg_match('/(\d{5})\s*$/', $row['RECIPIENT_ADDR'], $pc_match);
           $recipient_info = [
             'name'     => $row['RECIPIENT_NAME'],
             'email'    => $row['RECIPIENT_EMAIL'],
             'phone'    => $row['RECIPIENT_PHONE'],
             'addr'     => $row['RECIPIENT_ADDR'],
             'postcode' => $pc_match[1] ?? '', 
          ];
        }

        //Format custom cake as a standard item
        $cart_items[] = [
            'is_custom'        => true,
            'CART_ITEM_ID'     => 'custom_' . $row['CUSTOM_ID'],
            'CUSTOM_ID'        => $row['CUSTOM_ID'],
            'PRODUCT_NAME'     => $row['STYLE_NAME_SNAPSHOT'],
            'COVER_IMAGE'      => $row['REF_IMAGE'],
            'final_unit_price' => $price,
            'QUANTITY'         => $qty,
            'VARIANT_SIZE'     => $row['SIZE'],
            'IDEAL_FLAVOUR'    => $row['IDEAL_FLAVOUR'],
            'CUSTOM_DES'       => $row['CUSTOM_DES'],
            'addons'           => [],
        ];
    }
}

// 7. Get bakery operating days
$bakery_res = mysqli_query($conn, "SELECT OPEN_DAYS FROM bakery_info LIMIT 1");
$bakery     = mysqli_fetch_assoc($bakery_res);

$open_days = array_map('trim', explode(',', $bakery['OPEN_DAYS']));

// 8. Identify and collect disabled delivery dates
$disabled_rules = [];
$rules_res = mysqli_query($conn, "SELECT * FROM delivery_date_rules WHERE STATUS = 'Disabled'");
while ($row = mysqli_fetch_assoc($rules_res)) {
    $disabled_rules[] = $row;
}

// Generate a map of disabled dates for the next 90 days for UI validation
$disabled_date_reasons = [];
for ($i = 0; $i <= 90; $i++) {
    $check_date = date('Y-m-d', strtotime("+$i days"));
    $php_dow    = (int) date('N', strtotime($check_date)); // 1=Mon ... 7=Sun
    foreach ($disabled_rules as $rule) {
        // Rule 1: Specific date disabled
        if (!empty($rule['DATE']) && empty($rule['DAY_OF_WEEK']) && $rule['DATE'] == $check_date) {
            $disabled_date_reasons[$check_date] = $rule['REASON'];
            break;
        }
        // Rule 2: Recurring day of the week disabled
        if (!empty($rule['DAY_OF_WEEK']) && empty($rule['DATE']) && (int)$rule['DAY_OF_WEEK'] === $php_dow) {
            $disabled_date_reasons[$check_date] = $rule['REASON'];
            break;
        }
    }
}
$disabled_date_reasons_json = json_encode($disabled_date_reasons);

//9: Calculate available dates based on capacity and business rules
$allowed_dates = []; 
$capacity_map  = []; 

$cap_res = mysqli_query($conn, "SELECT PRODUCTION_DATE, MAX_CAKES, ALREADY_BOOKED 
                                 FROM production_capacity 
                                 ORDER BY PRODUCTION_DATE ASC");

while ($row = mysqli_fetch_assoc($cap_res)) {

    $date      = $row['PRODUCTION_DATE'];
    $remaining = intval($row['MAX_CAKES']) - intval($row['ALREADY_BOOKED']);
    $php_dow   = (int) date('N', strtotime($date)); // 1=Mon ... 7=Sun

    // Store capacity info for this date
    $capacity_map[$date] = [
        'max'       => intval($row['MAX_CAKES']),
        'booked'    => intval($row['ALREADY_BOOKED']),
        'remaining' => $remaining,
    ];

    // Filter by operating days
    $day_abbr = date('D', strtotime($date)); 
    if (!in_array($day_abbr, $open_days)) {
        continue; 
    }

    // Filter by disabled date rules
    $is_disabled = false;
    foreach ($disabled_rules as $rule) {
        if (!empty($rule['DATE']) && empty($rule['DAY_OF_WEEK']) && $rule['DATE'] == $date) {
            $is_disabled = true;
            break;
        }
        if (!empty($rule['DAY_OF_WEEK']) && empty($rule['DATE']) && (int)$rule['DAY_OF_WEEK'] === $php_dow) {
            $is_disabled = true;
            break;
        }
    }
    if ($is_disabled) {
        continue; 
    }

    // Date passed all checks — add to allowed list
    $allowed_dates[] = $date;
}

$allowed_dates_json = json_encode($allowed_dates);
$capacity_map_json  = json_encode($capacity_map);

// 10. Perform final backend capacity check
if ($capacity_cakes_count > 0 && isset($_POST['delivery_date'])) {

    $delivery_date = mysqli_real_escape_string($conn, $_POST['delivery_date']);

    $check_res = mysqli_query($conn, "SELECT (MAX_CAKES - ALREADY_BOOKED) AS remaining 
                                      FROM production_capacity 
                                      WHERE PRODUCTION_DATE = '$delivery_date'");
    $cap_row   = mysqli_fetch_assoc($check_res);

    if ($cap_row && $capacity_cakes_count > $cap_row['remaining']) {
        $slots_left = max(0, intval($cap_row['remaining']));
        echo "<script>
                alert('Sorry, not enough production slots on that date.\\nOnly $slots_left slot(s) remaining. Please go back and choose another date.');
                window.location.href='shopping_cart.php';
              </script>";
        exit;
    }
}

// 11. Get active delivery time slots
$active_slots = [];
$slot_sql = "SELECT SLOT_ID, START_TIME, END_TIME,
                    TIME_FORMAT(START_TIME, '%h:%i %p') AS START_SHORT,
                    TIME_FORMAT(END_TIME,   '%h:%i %p') AS END_SHORT
             FROM delivery_slots
             WHERE STATUS = 'Active'
             ORDER BY START_TIME ASC";
$slot_res = mysqli_query($conn, $slot_sql);
while ($row = mysqli_fetch_assoc($slot_res)) {
    $active_slots[] = $row;
}

// 12. Get customer-specific vouchers
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

        // check voucher  start date
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

//13. Get customer addresses and calculate shipping fee
$SHIPPING_FEE   = 0.00;
$address_sql    = "SELECT a.*, dc.DELIVERY_FEE
                   FROM address a
                   LEFT JOIN delivery_coverage dc ON a.POSTCODE = dc.POSTCODE
                   WHERE a.CUSTOMER_ID = $customer_id
                   ORDER BY a.IS_DEFAULT DESC";
$address_result = mysqli_query($conn, $address_sql);

// Set shipping fee based on the default address
if (mysqli_num_rows($address_result) > 0) {
    $first_addr = mysqli_fetch_assoc($address_result);
    if (!empty($first_addr['DELIVERY_FEE'])) {
        $SHIPPING_FEE = floatval($first_addr['DELIVERY_FEE']);
    }
    // Reset pointer so address dropdown can loop through all addresses again
    mysqli_data_seek($address_result, 0); 
}


// Custom cake: use recipient postcode to get shipping fee
if ($is_custom_locked && !empty($recipient_info['postcode'])) {
    $pc = mysqli_real_escape_string($conn, $recipient_info['postcode']);
    $fee_res = mysqli_query($conn, "SELECT DELIVERY_FEE FROM delivery_coverage 
                                    WHERE POSTCODE = '$pc' AND STATUS = 'Active' LIMIT 1");
    $fee_row = mysqli_fetch_assoc($fee_res);
    if ($fee_row) {
        $SHIPPING_FEE = floatval($fee_row['DELIVERY_FEE']);
    }
}

//14. Handle new address submission
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

    echo "<script>alert('New address saved successfully!');</script>";

    // Refresh address list
    $address_result = mysqli_query($conn, "SELECT a.*, dc.DELIVERY_FEE
                                           FROM address a
                                           LEFT JOIN delivery_coverage dc ON a.POSTCODE = dc.POSTCODE
                                           WHERE a.CUSTOMER_ID = $customer_id
                                           ORDER BY a.ADDRESS_ID DESC");
}

// 15. Prepare delivery date range for the date picker
$min_delivery_date = !empty($allowed_dates) ? $allowed_dates[0] : date('Y-m-d');
$max_delivery_date = !empty($allowed_dates) ? $allowed_dates[count($allowed_dates) - 1] : date('Y-m-d');

// Count total quantity of pre-made items (used for capacity calculation)
$premade_qty = 0;
foreach ($cart_items as $item) {
    if (!$item['is_custom']) {
        $premade_qty += intval($item['QUANTITY']);
    }
}
$today_str = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css">
    <style>
        :root {
            --bg-main: #fff2ed;
            --primary-pink: #ffb6c1;
            --btn-hover: #ff99aa;
            --price-color:  #d2691e;
            --border-color: #bba299;
            --font-main: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-main);
            margin: 0; padding: 0;
            color: #333;
        }

        .contain-box {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* progress bar */
        .step-container { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            position: relative; 
        }

        .step-line      { 
            position: absolute; 
            left: 110px; 
            width: 1000px; 
            height: 2px; 
            background: #333; 
        }

        .step-icon { 
            z-index: 2; 
            text-align: center; 
            font-size: 14px; 
            padding: 0 10px; 
            background: var(--bg-main); 
        }
        
        .step-icon img { 
            width: 35px; 
            display: block; 
            margin: 0 auto 10px; 
        }

        /* left and right panel layout */
        .main-form  { 
            display: flex; 
            gap: 30px; 
            margin-top: 40px; 
            align-items: flex-start; 
        }
        
        .left-panel { 
            width: 60%; 
            background: white; 
            border: dashed 2px var(--btn-hover); 
            border-radius: 10px; 
            padding: 20px; 
        }
        
        .right-panel { 
            width: 40%; 
            background: white; 
            border: dashed 2px var(--btn-hover); 
            border-radius: 10px; 
            padding: 20px; 
        }

        .form-group { 
            margin-bottom: 12px; 
            width: 100%; 
            flex: 1; 
        }
        
        .form-label { 
            font-weight: bold; 
            font-size: 14px; 
            margin-bottom: 6px; 
            display: inline-block; 
        }
       
        .form-control {
            width: 100%; 
            padding: 10px 14px;
            border: 2px solid var(--border-color); 
            border-radius: 12px;
            background: white; 
            font-family: var(--font-main);
            box-sizing: border-box;
        }
       
        .form-control:focus { 
            outline: none; 
            border-color: var(--primary-pink); 
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
            border: 2px solid var(--border-color); 
            border-radius: 12px; 
            padding: 8px; 
        }

        /* delivery info */
        .delivery-info-box { 
            border: 1px solid var(--border-color); 
            border-radius: 12px; 
            padding: 12px; 
            background: #fff9f6; 
            margin-bottom: 16px; 
        }
       
        .text-danger { 
            font-size: 13px; 
            font-weight: bold; 
            color: #dc3545; 
            margin: 2px 0; 
        }
       
        .text-success { 
            font-size: 13px; 
            color: #198754; 
            margin: 2px 0; 
        }

        .input-readonly { 
            background: #e9ecef; 
            cursor: not-allowed; 
        }

        /* capacity hint */
        #capacity_hint { 
            font-size: 13px; 
            margin-top: 4px; 
            display: block; 
        }
       
        .hint-ok { 
            color: #198754; 
        }
       
        .hint-warn { 
            color: #dc3545; 
            font-weight: bold; 
        }

        /* recipient info for custom cake  */
        .recipient-box { 
            background: #fff3f5; 
            border: 1px solid var(--primary-pink); 
            border-radius: 10px; 
            padding: 12px 16px; 
            margin-bottom: 12px; 
        }
       
        .recipient-box h6 { 
            font-size: 13px; 
            font-weight: bold; 
            color: #c0392b; 
            margin: 0 0 8px; 
        }
       
        .recipient-box p  { 
            font-size: 13px; 
            color: #555; 
            margin: 2px 0; 
        }

        .button-group { 
            display: flex; 
            gap: 15px; 
            margin-top: 20px; 
        }
       
        .btn-back, .btn-pay {
            background: var(--primary-pink); 
            color: black;
            font-size: 16px; 
            font-weight: bold;
            border: none; 
            border-radius: 8px;
            padding: 12px 20px; 
            cursor: pointer; 
            text-decoration: none;
            display: inline-flex; 
            align-items: center;
        }
       
        .btn-back:hover, .btn-pay:hover { 
            background: var(--btn-hover); 
        }
       
        .btn-apply {
            background: var(--primary-pink); 
            color: black; 
            font-weight: bold;
            border: 2px solid var(--border-color); 
            border-radius: 12px;
            padding: 10px 18px; 
            cursor: pointer; 
            margin-left: 10px;
        }
       
        .btn-apply:hover { 
            background: var(--btn-hover); 
        }

        /* Order Summary */
        .cake-details { 
            display: flex; 
            gap: 16px; 
            padding-bottom: 16px; 
        }
       
        .cake-image img { 
            width: 90px; 
            height: 90px; 
            border-radius: 8px; 
            object-fit: cover; 
        }
       
        .cake-text p { 
            font-size: 13px; 
            line-height: 1.5; 
            margin: 2px 0; 
        }
       
        .cake-text .size-qty { 
            color: #666; 
        }
       
        .cake-text .unit-price{ 
            color: var(--price-color); 
            font-weight: bold; 
        }
       
        .cake-text .text-muted{ 
            color: #666; 
        }

        /* Voucher */
        .voucher-row { 
            display: flex; 
        }

        /* custom cake label */
        .custom-badge {
            display: inline-block; 
            background: #ffe0e6; 
            color: #c0392b;
            font-size: 11px; 
            font-weight: bold;
            padding: 2px 8px; 
            border-radius: 20px; 
            margin-bottom: 4px;
        }

        /* Add-on */
        .addon-box {
            background: #fdf6f0; 
            padding: 8px; 
            border-radius: 5px;
            margin-top: 6px; 
            font-size: 12px;
            border-left: 3px solid var(--primary-pink);
        }

        /* price details */
        .price-details p { 
            font-size: 14px; 
            color: #666; 
            display: flex; 
            justify-content: space-between; 
        }
       
        .total-box { 
            font-size: 18px; 
            font-weight: bold; 
            color: var(--price-color); 
            display: flex; 
            justify-content: space-between; 
            border-top: 1px solid #eee; 
            padding-top: 10px; 
        }

        /* Added address popup */
        .modal-overlay { 
            display: none; 
            position: fixed; 
            z-index: 1000; 
            left: 0; 
            top: 0; 
            width: 100%; 
            height: 100%; 
            background: rgba(0,0,0,0.5); 
        }
       
        .modal-content { 
            background: white; 
            width: 500px; 
            margin: 50px auto; 
            padding: 25px; 
            border-radius: 15px; 
            position: relative; 
        }
       
        .close-modal { 
            position: absolute; 
            right: 20px; 
            top: 15px; 
            cursor: pointer; 
            font-size: 24px; 
            color: #666; 
        }
    </style>
</head>
<body>

<?php include_once 'include/header.php'; ?>

<div class="contain-box">

    <!-- Progress bar -->
    <div class="step-container">
        <div class="step-line"></div>
        <div class="step-icon"><img src="icon/done.png"      alt="1"/> Your selection</div>
        <div class="step-icon"><img src="icon/active2.png"   alt="2"/> Checkout</div>
        <div class="step-icon"><img src="icon/inactive3.png" alt="3"/> Make Payment</div>
        <div class="step-icon"><img src="icon/inactive4.png" alt="4"/> Complete</div>
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
            <?php foreach ($custom_ids as $cid): ?>
                <input type="hidden" name="custom_ids[]" value="<?php echo intval($cid); ?>">
            <?php endforeach; ?>

            <input type="hidden" name="sub_total"       id="input_sub_total"       value="<?php echo $SUB_TOTAL; ?>">
            <input type="hidden" name="shipping_fee"    id="input_shipping_fee"    value="<?php echo $SHIPPING_FEE; ?>">
            <input type="hidden" name="discount_amount" id="input_discount_amount" value="0">
            <input type="hidden" name="final_amount"    id="input_final_amount"    value="<?php echo $SUB_TOTAL + $SHIPPING_FEE; ?>">

            <?php if (isset($_SESSION['checkout_mode']) && $_SESSION['checkout_mode'] === 'buynow'): ?>
                <input type="hidden" name="checkout_mode" value="buynow">
            <?php endif; ?>

            <!-- Delivery address -->
            <div class="form-group">
                <label class="form-label">Choose an Address:</label>
                <select name="address_id" class="form-control" onchange="autoFill(this)" required>
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
                            data-fee="<?php   echo isset($addr['DELIVERY_FEE']) ? floatval($addr['DELIVERY_FEE']) : 0; ?>"
                            <?php echo $addr['IS_DEFAULT'] ? 'selected' : ''; ?>>
                            <?php echo ($addr['IS_DEFAULT'] ? '[Default] ' : '') . htmlspecialchars($addr['ADDRESS_LINE']); ?>
                        </option>
                    <?php endwhile; ?>
                    <option value="new">+ Add New Address</option>
                </select>
            </div>

            <!-- delivery date and delivery slot -->
            <div class="row-group">
                <div class="form-group">
                    <label for="delivery_date" class="form-label">Delivery Date:</label>
                    <input type="date" id="delivery_date" name="delivery_date" class="form-control <?php echo $is_custom_locked ? 'input-readonly' : ''; ?>"
                           min="<?php echo $min_delivery_date; ?>"
                           max="<?php echo $max_delivery_date; ?>"
                           value="<?php echo $is_custom_locked ? htmlspecialchars($locked_delivery_date) : ''; ?>"
                           onchange="onDateChange()" <?php echo $is_custom_locked ? 'readonly' : ''; ?> required/>
                    <small id="capacity_hint"></small>
                </div>

                <div class="form-group">
                    <label for="delivery_time" class="form-label">Delivery Time Slot:</label>
                    <select id="delivery_time" name="delivery_time" class="form-control"
                            <?php echo $is_custom_locked ? 'disabled' : 'required'; ?>>
                            <option value="">-- Select Time Slot --</option>
                            <?php foreach ($active_slots as $slot):
                            //If it is custom cake
                            $selected = ($is_custom_locked && $slot['START_TIME'] == $locked_delivery_slot) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $slot['START_TIME']; ?>" <?php echo $selected; ?>>
                                <?php echo $slot['START_SHORT'] . ' - ' . $slot['END_SHORT']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                        <?php if ($is_custom_locked): ?>
                           <input type="hidden" name="delivery_time" value="<?php echo htmlspecialchars($locked_delivery_slot); ?>">
                        <?php endif; ?>
                </div>

            </div>

            <!-- Delivery information -->
            <div class="delivery-info-box">
                <h6>Delivery Information</h6>

                <?php if ($is_custom_locked): ?>
                    <!-- custom cake -->
                    <p class="text-danger">
                        <i class="bi bi-lock-fill"></i> Custom cakes must be ordered 3–5 days in advance. Delivery date and time are fixed based on your custom order.
                    </p>

                <?php elseif ($has_premade): ?>
                    <!-- pre-made cake -->
                    <p class="text-success">
                        <i class="bi bi-clock-history"></i> Same-day delivery (2–4 hours) is available for pre-made cakes.
                    </p>

                <?php endif; ?>
            </div>

            <!-- Custom Cake's recipient info -->
            <?php if ($is_custom_locked && !empty($recipient_info)): ?>
            <div class="recipient-box">
                <h6><i class="bi bi-person-heart"></i> Recipient Info (from your custom order)</h6>
                <p><strong>Name:</strong>    <?php echo htmlspecialchars($recipient_info['name']);  ?></p>
                <p><strong>Email:</strong>   <?php echo htmlspecialchars($recipient_info['email']); ?></p>
                <p><strong>Phone:</strong>   <?php echo htmlspecialchars($recipient_info['phone']); ?></p>
                <p><strong>Address:</strong> <?php echo htmlspecialchars($recipient_info['addr']);  ?></p>
            </div>
            <?php endif; ?>

            <!-- name -->
            <div class="form-group">
                <label for="full_name" class="form-label">Full Name:</label>
                <input type="text" id="full_name" name="full_name" class="form-control"
                    <?php echo $is_custom_locked ? 'readonly' : ''; ?> required/>
            </div>

            <!-- phone -->
            <div class="form-group">
                <label for="phone" class="form-label">Phone Number:</label>
                <div class="phone-group">
                    <select id="country-code" name="country-code" class="form-control">
                        <option value="+60">+60 (MY)</option>
                    </select>
                    <input type="text" id="phone" name="phone" class="form-control" maxlength="15" placeholder="123456789" required>
                </div>
            </div>

            <!-- address -->
            <div class="form-group">
                <label for="address" class="form-label">Address:</label>
                <input type="text" id="address" name="shipping_address" class="form-control"
                       <?php echo $is_custom_locked ? 'readonly' : ''; ?> required/>
            </div>

            <div class="row-group">
                <div class="form-group">
                    <label for="city" class="form-label">City:</label>
                    <input type="text" id="city" name="city" class="form-control" value="Kulai" readonly>
                </div>
                <div class="form-group">
                    <label for="company" class="form-label">Company (Optional):</label>
                    <input type="text" id="company" name="company" class="form-control"/>
                </div>
            </div>

            <div class="row-group">
                <div class="form-group">
                    <label for="postcode" class="form-label">Postcode:</label>
                    <input type="text" id="postcode" name="postcode" class="form-control" value="81000" readonly required/>
                </div>
                <div class="form-group">
                    <label for="state" class="form-label">State:</label>
                    <input type="text" id="state" name="state" class="form-control" value="Johor" readonly/>
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
            <div class="cake-details">
                <input type="hidden" name="cart_items[<?php echo $index; ?>][cart_item_id]" value="<?php echo htmlspecialchars($item['CART_ITEM_ID']); ?>">
                <input type="hidden" name="cart_items[<?php echo $index; ?>][custom_id]"    value="<?php echo isset($item['CUSTOM_ID']) ? intval($item['CUSTOM_ID']) : 0; ?>">

                <!-- Cake image -->
                <div class="cake-image">
                    <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_cake.png'; ?>" alt="cake">
                </div>

                <!-- Cake details -->
                <div class="cake-text">
                    <!-- custom cake  -->
                    <?php if ($item['is_custom']): ?>
                        <span class="custom-badge">✦ Custom Cake</span>
                    <?php endif; ?>

                    <!-- cake name -->
                    <p><strong><?php echo htmlspecialchars($item['PRODUCT_NAME'] ?? 'Custom Cake'); ?></strong></p>

                    <!-- size and quantity-->
                    <p class="size-qty">
                        <small>Size: <?php echo htmlspecialchars($item['VARIANT_SIZE'] ?? 'N/A'); ?></small>
                        &nbsp;|&nbsp;
                        <small>Qty: <?php echo intval($item['QUANTITY']); ?></small>
                    </p>

                    <!-- unit price -->
                    <p class="unit-price">
                        <small>Unit Price: RM <?php echo number_format($item['final_unit_price'], 2); ?></small>
                    </p>

                    <!-- custom cake details -->
                    <?php if ($item['is_custom']): ?>
                        <?php if (!empty($item['IDEAL_FLAVOUR'])): ?>
                            <p><small class="text-muted"><i class="bi bi-cake2"></i> Flavour: <?php echo htmlspecialchars($item['IDEAL_FLAVOUR']); ?></small></p>
                        <?php endif; ?>
                        <?php if (!empty($item['CUSTOM_DES'])): ?>
                            <p><small class="text-muted"><i class="bi bi-chat-left-text"></i> Note: <?php echo htmlspecialchars($item['CUSTOM_DES']); ?></small></p>
                        <?php endif; ?>

                    <!-- pre-made cake's cake writing and add-on -->
                    <?php else: ?>
                        <?php if (!empty($item['CAKE_WRITING'])): ?>
                            <p><small class="text-muted"><i class="bi bi-pen"></i> Writing: <?php echo htmlspecialchars($item['CAKE_WRITING']); ?></small></p>
                        <?php endif; ?>
                        <?php if (!empty($item['addons'])): ?>
                            <div class="addon-box">
                                <strong>Add-ons:</strong>
                                <?php foreach ($item['addons'] as $addon): ?>
                                    <div>• <?php echo htmlspecialchars($addon['ADD_ON_NAME']); ?> (x<?php echo $addon['QUANTITY']; ?>) - RM <?php echo number_format($addon['ADD_ON_PRICE'] * $addon['QUANTITY'], 2); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
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
                    <button type="button" class="btn-apply" onclick="updateTotal()">Apply</button>
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
            <?php foreach ($custom_ids as $cid): ?>
                <input type="hidden" name="custom_ids[]" value="<?php echo intval($cid); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="selected_voucher_id" value="<?php echo $passed_voucher_id; ?>">

            <div class="d-flex gap-2 mb-2">
                <input type="text" name="new_fname"    placeholder="First Name"  class="form-control" required>
                <input type="text" name="new_lname"    placeholder="Last Name"   class="form-control" required>
            </div>
            <input type="text" name="new_phone"    placeholder="Phone Number" class="form-control mb-2" required>
            <input type="text" name="new_address"  placeholder="Address Line" class="form-control mb-2" required>
            <div class="d-flex gap-2 mb-2">
                <input type="text"   name="new_city"     placeholder="City"     class="form-control" value="Kulai" readonly>
                <input type="text"   name="new_postcode" placeholder="Postcode" class="form-control" value="81000" readonly>
                <input type="hidden" name="new_state"    value="Johor">
            </div>
            <button type="submit" name="save_new_address" class="btn-pay">Save Address</button>
        </form>
    </div>
</div>

<script>
const baseSubtotal      = <?php echo json_encode($SUB_TOTAL); ?>;
let   currentShipping   = <?php echo json_encode($SHIPPING_FEE); ?>;
const capacityData      = <?php echo $capacity_map_json; ?>;
const customCapCakes    = <?php echo json_encode($capacity_cakes_count); ?>; 
const premadeQty        = <?php echo json_encode($premade_qty); ?>;         
const todayStr          = '<?php echo $today_str; ?>';
const openDays          = <?php echo json_encode($open_days); ?>;
const allowedDates      = <?php echo $allowed_dates_json; ?>;
const isCustomLocked    = <?php echo $is_custom_locked ? 'true' : 'false'; ?>;

// Map of disabled dates and their specific reasons: { '2025-12-25': 'Public Holiday', ... }
const disabledReasons   = <?php echo $disabled_date_reasons_json; ?>;

// Calculates total cakes requiring production capacity based on selected date
function calcCapCakes(dateStr) {
    var premade = (dateStr && dateStr !== todayStr) ? premadeQty : 0;
    return customCapCakes + premade;
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
        document.getElementById('postcode').value   = '';
        currentShipping = 0;
        updateTotal();
        return;
    }

    var opt = select.options[select.selectedIndex];

    // Validation: Enforce delivery area (Kulai only)
    if ((opt.getAttribute('data-city') || '').toLowerCase() !== 'kulai') {
        alert('Sorry, we only deliver within Kulai, Johor.');
        select.selectedIndex = 0;
        return;
    }

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
    document.getElementById('address').value    = opt.getAttribute('data-addr')  || '';
    document.getElementById('postcode').value   = opt.getAttribute('data-post')  || '';

    currentShipping = parseFloat(opt.getAttribute('data-fee')) || 0;
    updateTotal();
}

// Delivery Date Logic
function onDateChange() {
    validateDeliveryDate();
    showCapacityHint();
}


// Validates selected date against business rules and production capacity
function validateDeliveryDate() {
    if (isCustomLocked) return true;

    var dateInput = document.getElementById('delivery_date');
    var dateStr   = dateInput.value;
    if (!dateStr) return true;

    // Ensure date is within business operating days
    var d = new Date(dateStr);
    var dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var dayName  = dayNames[d.getUTCDay()];
    if (openDays.indexOf(dayName) === -1) {
        alert('Sorry, we are closed on ' + dayName + '. Please choose another date.');
        dateInput.value = '';
        showCapacityHint();
        return false;
    }

    // Ensure date is in the allowed delivery list
    if (allowedDates.indexOf(dateStr) === -1) {
        var reason = disabledReasons[dateStr] ? '\nReason: ' + disabledReasons[dateStr] : '';
        alert('Sorry, this date is not available for delivery.' + reason + '\nPlease choose another date.');
        dateInput.value = '';
        showCapacityHint();
        return false;
    }

    // Production capacity validation
    var totalCap = calcCapCakes(dateStr);
    if (totalCap > 0 && capacityData[dateStr] !== undefined) {
        var remaining = capacityData[dateStr].remaining;
        if (totalCap > remaining) { 
            var parts = dateStr.split("-"); 
            var displayDate = parts[2] + "-" + parts[1] + "-" + parts[0];
            
            var goBack = confirm(
                'Sorry, not enough production capacity on ' + displayDate + '.\n' +
                'You need ' + totalCap + ' items but only ' + remaining + ' remaining.\n\n' +
                'Click OK to choose another date.\n' +
                'Click Cancel to go back and modify your quantity.'
            );
            
            if (goBack) {
                dateInput.value = '';
                showCapacityHint();
            } else {
                history.back();
            }
            return false;
        }
    }

    return true;
}


// Displays real-time capacity information to the user
function showCapacityHint() {
    var hint    = document.getElementById('capacity_hint');
    var dateStr = document.getElementById('delivery_date').value;

    if (!dateStr || !capacityData[dateStr]) {
        hint.textContent = '';
        hint.className   = '';
        return;
    }

    var remaining = capacityData[dateStr].remaining;
    var max       = capacityData[dateStr].max;

    var totalCap = calcCapCakes(dateStr);

    if (totalCap === 0) {
        hint.textContent = '';
        hint.className   = '';
        return;

    } else if (remaining <= 0) {
        hint.textContent = 'Fully booked! No stocks remaining.';
        hint.className   = 'hint-warn';

    } else if (totalCap > remaining) {
        hint.textContent = 'Not enough stocks! You need ' + totalCap + ', only ' + remaining + ' left.';
        hint.className   = 'hint-warn';

    } else {
        hint.textContent = 'Available stocks: ' + remaining + ' / ' + max;
        hint.className   = 'hint-ok';
    }
}


// Form Submission & Price Calculation
function validateFormBeforeSubmit() {

    if (!validateDeliveryDate()) {
        return false;
    }

    // Redundant capacity check for security
    if (!isCustomLocked) {
        var dateStr  = document.getElementById('delivery_date').value;
        var totalCap = calcCapCakes(dateStr);
        if (totalCap > 0 && capacityData[dateStr] !== undefined && totalCap > capacityData[dateStr].remaining) {
            var goBack = confirm(
                'Sorry, not enough production capacity on this date.\n\n' +
                'Click OK to choose another date.\n' +
                'Click Cancel to go back and modify your quantity.'
            );
            if (goBack) {
                document.getElementById('delivery_date').value = '';
                showCapacityHint();
            } else {
                history.back();
            }
            return false;
        }
    }

    // Ensure time slot is selected (not required for locked custom cakes)
    if (!isCustomLocked) {
        var timeSlot = document.getElementById('delivery_time').value;
        if (!timeSlot) {
            alert('Please select a delivery time slot.');
            return false;
        }
    }

    return true; 
}

// Updates summary totals and hidden form inputs
function updateTotal() {
    var voucherSelect  = document.getElementById('voucher_select');
    var selectedOption = voucherSelect.options[voucherSelect.selectedIndex];
    var discountRate   = parseFloat(selectedOption.getAttribute('data-rate')) || 0;

    var minSpend = parseFloat(selectedOption.getAttribute('data-min')) || 0;
    var discount = (baseSubtotal >= minSpend && discountRate > 0) ? baseSubtotal * (discountRate / 100) : 0;
    var finalTotal = baseSubtotal - discount + currentShipping;

    document.getElementById('display-discount').textContent = '- RM ' + discount.toFixed(2);
    document.getElementById('display-shipping').textContent = 'RM '   + currentShipping.toFixed(2);
    document.getElementById('display-total').textContent    = 'RM '   + finalTotal.toFixed(2);

    // Sync values for submission
    document.getElementById('input_shipping_fee').value    = currentShipping.toFixed(2);
    document.getElementById('input_discount_amount').value = discount.toFixed(2);
    document.getElementById('input_final_amount').value    = finalTotal.toFixed(2);
}


// Initialization on page load
window.onload = function() {
    <?php if ($is_custom_locked && !empty($recipient_info)): ?>
    // Auto-populate recipient details for custom orders
    var fullName = "<?php echo addslashes($recipient_info['name']); ?>";
    document.getElementById('full_name').value = fullName;
    var rawPhone = "<?php echo addslashes($recipient_info['phone']); ?>";
    if (rawPhone.startsWith('+60')) {
        rawPhone = rawPhone.substring(3);
    }
    document.getElementById('phone').value = rawPhone;
    document.getElementById('address').value    = "<?php echo addslashes($recipient_info['addr']); ?>";
    <?php endif; ?>

    // auto-select default address if available
    var addrSelect = document.querySelector('select[name="address_id"]');

    <?php if ($is_custom_locked): ?>
       // Custom cake: reset address select, fill recipient info manually
       addrSelect.value = '';   
       addrSelect.required = false;
    <?php else: ?>
       // Pre-made: auto-select default address
       if (addrSelect && addrSelect.value && addrSelect.value !== 'new') {
           autoFill(addrSelect);
       }
<?php endif; ?>

    showCapacityHint();
    updateTotal();
};
</script>
<?php include_once 'include/footer.php'; ?>
</body>
</html>