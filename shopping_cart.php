<?php
session_start();
require_once 'include/config.php';

if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// 1. single item delete
if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $item_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($item_id > 0) {
        // delete related addons first due to foreign key constraint
        mysqli_query($conn, "DELETE FROM cart_item_addon WHERE CART_ITEM_ID = $item_id");
        $delete_sql = "DELETE FROM cart_item WHERE CART_ITEM_ID = $item_id";
        if (mysqli_query($conn, $delete_sql)) {
            header("Location: shopping_cart.php");
            exit();
        }
    }
}

//2. delete selected items from checkbox
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_selected'])) {
    $ids_to_delete = $_POST['selected_ids'] ?? ''; 
    if (!empty($ids_to_delete)) {
        // ensure all IDs are integers 
        $ids_array = array_map('intval', explode(',', $ids_to_delete));
        $clean_ids = implode(',', $ids_array);
        
        if (!empty($clean_ids)) {
            mysqli_query($conn, "DELETE FROM cart_item_addon WHERE CART_ITEM_ID IN ($clean_ids)");
            $delete_all_sql = "DELETE FROM cart_item WHERE CART_ITEM_ID IN ($clean_ids)";
            if (mysqli_query($conn, $delete_all_sql)) {
                header("Location: shopping_cart.php");
                exit();
            }
        }
    }
}

// 3. fetch cart items for current user
$cart_sql = "SELECT ci.*, p.PRODUCT_NAME, p.COVER_IMAGE, pv.VARIANT_SIZE, pv.VARIANT_PRICE, pv.VARIANT_STOCK
        FROM cart_item ci
        JOIN cart c ON ci.CART_ID = c.CART_ID
        JOIN product p ON ci.PRODUCT_ID = p.PRODUCT_ID
        JOIN product_variant pv ON ci.VARIANT_ID = pv.VARIANT_ID
        WHERE c.CUSTOMER_ID = $customer_id
        ORDER BY ci.CREATED_AT DESC";

$cart_result = mysqli_query($conn, $cart_sql);
$cart_items = [];

// Initial subtotal is 0, we will calculate it as we loop through items
$SUB_TOTAL = 0;

while ($row = mysqli_fetch_assoc($cart_result)){
    $item_id = intval($row['CART_ITEM_ID']);
    
    // query addons for this cart item
    $addon_query = "SELECT cia.*, a.ADD_ON_NAME, a.ADD_ON_PRICE 
                    FROM cart_item_addon cia 
                    JOIN add_on a ON cia.ADD_ON_ID = a.ADD_ON_ID 
                    WHERE cia.CART_ITEM_ID = $item_id";
    $addon_res = mysqli_query($conn, $addon_query);
    
    $addons = [];
    $total_addon_price_per_item = 0; // single item's total addon price
    
    if ($addon_res) {
        while ($addon = mysqli_fetch_assoc($addon_res)) {
            $addons[] = $addon;
            // calculate total addon price for this item
            $total_addon_price_per_item += ($addon['ADD_ON_PRICE'] * $addon['QUANTITY']);
        }
    }
    
    $row['addons'] = $addons;
    // define the price of one set of this item (cake variant price + all addons price)
    $row['SINGLE_SET_PRICE'] = $row['VARIANT_PRICE'] + $total_addon_price_per_item;
    
    $cart_items[] = $row;
    
    $SUB_TOTAL += ($row['SINGLE_SET_PRICE'] * $row['QUANTITY']);
}

// 4. fetch available vouchers for this customer
$customer_tier_id = 0;
$user_tier_query = mysqli_query($conn, "SELECT TIER_ID FROM customer WHERE CUSTOMER_ID = $customer_id");
if ($user_tier_query && $tier_row = mysqli_fetch_assoc($user_tier_query)) {
    $customer_tier_id = intval($tier_row['TIER_ID']);
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

$voucher_result = mysqli_query($conn, $voucher_sql);
$my_vouchers = [];
$today = new DateTime(); // current system time

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

        // check customer_voucher expiry date
        if (!empty($v_row['CUSTOMER_EXPIRY']) && $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00 00:00:00' && $v_row['CUSTOMER_EXPIRY'] !== '0000-00-00') {
            $cv_expiry = new DateTime($v_row['CUSTOMER_EXPIRY']);
            if ($today > $cv_expiry) continue;
        }

        // check global usage limit
        if ($v_row['MAX_USAGE'] != -1 && $v_row['GLOBAL_USED_COUNT'] >= $v_row['MAX_USAGE']) {
            continue;
        }

        // check customer-specific usage limit
        $customer_used = intval($v_row['CUSTOMER_USED_COUNT'] ?? 0);
        if ($v_row['PER_USER_LIMIT'] != -1 && $customer_used >= $v_row['PER_USER_LIMIT']) {
            continue;
        }

        // all validation passed, allow this voucher
        $my_vouchers[] = $v_row;
    }
}

$TOTAL_AMOUNT = $SUB_TOTAL;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
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
        /* hover effect */
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    body {
        background-color: var(--bg-color);
        color: var(--font-color);
        font-family: 'Inter', sans-serif;
    }

    .cart-container {
        display: flex;
        max-width: 85%;
        margin: 0 auto;
        padding: 40px 30px;
        gap: 40px;
        justify-content: space-between;
        align-items: flex-start;
    }

    .cart-left {
        flex: 1;
        padding: 25px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);
        background-color: white;
        box-sizing: border-box;
    }

    .cart-right {
        flex: 0 0 350px;
        max-width: 350px;
        box-sizing: border-box;
    }

    h2, h3 {
        color: var(--font-color);
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        margin-bottom: 20px;
    }

    .cart-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }

    .cart-table th {
        border-bottom: 2px solid var(--search-border-color);
        padding: 15px 10px;
        text-align: left;
        color: var(--font2-color);
        font-weight: 600;
        font-size: 13px;
    }

    .cart-table td {
        padding: 20px 10px;
        border-bottom: 1px solid #EDF2F7;
        text-align: left;
        vertical-align: top;
        font-size: 13px;
        color: var(--font-color);
    }

    .cart-table input[type="checkbox"] {
        accent-color: var(--main-color);
        cursor: pointer;
    }

    .cart-table .bg-light {
        background-color: var(--secondary-color) !important;
        border: 1px solid var(--search-border-color);
    }

    .cart-img {
        width: 80px;
        border-radius: 10px;
        display: block;
        border: 1px solid var(--search-border-color);
    }

    .item-price {
        font-weight: 700;
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
    }

    .qty-btn {
        background-color: var(--secondary-color);
        border: 1px solid var(--search-border-color);
        padding: 5px 10px;
        cursor: pointer;
        border-radius: 8px;
        color: var(--font-color);
        font-weight: 700;
        transition: 0.2s;
    }

    .qty-btn:hover:not(:disabled) {
        background-color: var(--card-bg-color);
        color: var(--main-color);
        border-color: var(--main-color);
    }

    .qty-btn:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }

    .qty-input {
        width: 60px;
        text-align: center;
        border: 1px solid var(--search-border-color);
        margin: 0 5px;
        border-radius: 8px;
        background-color: white;
        display: inline-block;
        font-family: 'Inter', sans-serif;
        color: var(--font-color);
    }

    .cart-table td .btn-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        background-color: transparent;
        border: none;
        outline: none;
        padding: 5px;
        cursor: pointer;
        transition: transform 0.2s ease, filter 0.2s ease;
        width: auto;
        height: auto;
        border-radius: 0;
        box-shadow: none;
    }

    .cart-table td .btn-edit {
        color: var(--edit-blue);
    }

    .cart-table td .btn-delete {
        color: var(--delete-red);
    }

    .btn-action i {
        font-size: 20px;
        line-height: 1;
    }

    .btn-action:hover {
        transform: scale(1.15);
        filter: brightness(1.15);
    }

    .summary-card {
        background-color: white;
        padding: 30px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);

    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 15px;
        font-size: 14px;
        color: var(--font2-color);
    }

    .total-row {
        border-top: 2px solid var(--search-border-color);
        padding-top: 15px;
        font-weight: 700;
        font-size: 1.1rem;
        color: var(--font-color);
        display: flex;
        justify-content: space-between;
        font-family: 'Poppins', sans-serif;
    }

    .promo-box {
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
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .promo-input:focus {
        border-color: var(--main-color);
        outline: none;
        box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
        background-color: white;
    }

    .promo-btn {
        background-color: var(--main-color);
        color: white;
        border: none;
        padding: 8px 18px;
        border-radius: 20px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 13px;
        transition: 0.3s;
        white-space: nowrap;
    }

    .promo-btn:hover {
        background-color: var(--btn-hover);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(46, 134, 222, 0.3);
    }

    .btn-block {
        display: block;
        text-align: center;
        text-decoration: none;
        padding: 12px;
        border-radius: 20px;
        margin-bottom: 12px;
        font-weight: 600;
        font-size: 14px;
        font-family: 'Inter', sans-serif;
        transition: 0.3s;
    }

    .btn-checkout {
        background-color: var(--main-color);
        color: #FFFFFF;
        border: 1px solid var(--main-color);

    }

    .btn-checkout:hover {
        background-color: var(--btn-hover);
        color: #FFFFFF;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(46, 134, 222, 0.35);
    }

    .btn-continue {
        background-color: var(--secondary-color);
        color: var(--font2-color);
        border: 1px solid var(--search-border-color);
    }

    .btn-continue:hover {
        background-color: var(--card-bg-color);
        color: var(--main-color);
        border-color: var(--main-color);
        transform: translateY(-2px);
    }

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
        transition: 0.3s;
        box-shadow: 0 4px 12px rgba(46, 134, 222, 0.25);
    }

    .voucher-popup-btn:hover {
        background: var(--btn-hover);
    }

    @media (max-width: 768px) {
        .cart-container {
            flex-direction: column;
            padding: 20px 15px;
            gap: 20px;
        }

        .cart-left {
            flex: 0 0 100%;
            width: 100%;
            padding: 15px;
        }

        .cart-right {
            width: 100%;
            margin-top: 10px;
        }

        .cart-table {
            display: block;
            white-space: nowrap;
        }

        .btn-block {
            padding: 14px;
            font-size: 14px;
        }

        .promo-box {
            flex-direction: column;
        }

        .promo-btn {
            width: 100%;
        }
}
</style>
</head>
<body>
   <?php include 'include/header.php'; ?>
   <div class="cart-container">
       <div class="cart-left">
        <h2>Shopping Cart</h2>
        
        <!--Delete Selected Button-->
        <button type="button" id="deleteSelectedBtn" class="btn btn-outline-danger btn-sm mb-3" style="display: none;">
            <i class="bi bi-trash"></i> Delete Selected
        </button>
        
        <form id="deleteForm" method="POST" style="display:none;">
            <input type="hidden" name="delete_selected" value="1">
            <input type="hidden" name="selected_ids" id="selectedIdsInput">
        </form>
        
        <form action="checkout.php" method="POST" id="checkoutForm">
            <input type="hidden" name="selected_voucher_id" id="selected_voucher_id_hidden" value="0">
            <table class="cart-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" checked></th>
                        <th>Image</th>
                        <th>Details</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th colspan="2" class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($cart_items)): ?>
                        <tr><td colspan="7" class="text-center">Your cart is empty.</td></tr> 
                    <?php else: ?>
                        <?php foreach($cart_items as $item): ?>
                        <tr data-id="<?php echo $item['CART_ITEM_ID']; ?>" data-single-set-price="<?php echo $item['SINGLE_SET_PRICE']; ?>">
                            <td>
                                <input type="checkbox" name="selected_items[]" class="item-checkbox" value="<?php echo $item['CART_ITEM_ID']; ?>" checked>
                            </td>
                            <!--Cake Image-->
                            <td><img src="<?php echo htmlspecialchars($item['COVER_IMAGE']); ?>" class="cart-img" alt="cake"></td>
                            <td>
                                <strong><?php echo htmlspecialchars($item['PRODUCT_NAME']); ?></strong><br>
                                <small class="text-muted">Size: <?php echo htmlspecialchars($item['VARIANT_SIZE']); ?></small><br>
                                
                                <?php if (!empty($item['CAKE_WRITING'])): ?>
                                    <small class="text-success"> Cake Writing: "<?php echo htmlspecialchars($item['CAKE_WRITING']); ?>"</small><br>
                                <?php endif; ?>

                                <!--Add-ons-->
                                <?php if(!empty($item['addons'])): ?>
                                    <div class="mt-1 p-2 bg-light rounded" style="font-size: 0.85rem;">
                                        <strong>Add-ons:</strong><br>
                                        <?php foreach($item['addons'] as $addon): ?>
                                            <span class="text-secondary">
                                                + <?php echo htmlspecialchars($addon['ADD_ON_NAME']); ?> 
                                                (RM<?php echo number_format($addon['ADD_ON_PRICE'], 2); ?> x <?php echo $addon['QUANTITY']; ?>)
                                            </span><br>
                                            <?php 
                                            $isCardAddon = stripos($addon['ADD_ON_NAME'], 'card') !== false;
                                            if (!empty($addon['CARD_TEXT']) && $isCardAddon): 
                                            ?>
                                                <span class="text-info ps-2">Card: "<?php echo htmlspecialchars($addon['CARD_TEXT']); ?>"</span><br>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <!--Quantity Button-->
                                <div class="btn-qty d-flex align-items-center">
                                   <button type="button" class="qty-btn minus" <?php echo ($item['VARIANT_STOCK'] <= 0) ? 'disabled' : ''; ?>>-</button>
                                   
                                   <input type="number" class="qty-input form-control" 
                                      value="<?php echo $item['QUANTITY']; ?>" 
                                      max="<?php echo $item['VARIANT_STOCK']; ?>" 
                                      min="1" 
                                   <?php echo ($item['VARIANT_STOCK'] <= 0) ? 'disabled' : ''; ?> readonly>
                                   
                                   <button type="button" class="qty-btn plus" <?php echo ($item['VARIANT_STOCK'] <= 0) ? 'disabled' : ''; ?>>+</button>
                                </div>
                                <?php if($item['VARIANT_STOCK'] <= 5 && $item['VARIANT_STOCK'] > 0): ?>
                                    <small class="text-danger d-block text-center mt-1">Only <?php echo $item['VARIANT_STOCK']; ?> left</small>
                                <?php elseif($item['VARIANT_STOCK'] <= 0): ?>
                                    <small class="text-danger d-block text-center mt-1">Out of Stock</small>
                                <?php endif; ?>
                            </td>
                            <td class="item-price">
                                RM <?php echo number_format($item['SINGLE_SET_PRICE'] * $item['QUANTITY'], 2); ?>
                            </td>
                            <td>
                                <!--Edit and Delete Button-->
                                <a href="product details.php?id=<?php echo $item['PRODUCT_ID']; ?>&cart_item_id=<?php echo $item['CART_ITEM_ID']; ?>" 
                                class="btn-action btn-edit" title="Edit Options">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </td>
                            <td>
                                <a href="shopping_cart.php?action=delete&id=<?php echo $item['CART_ITEM_ID']; ?>" class="btn-action btn-delete" onclick="return confirm('Remove this item from cart?')" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                      <?php endif; ?>
                </tbody>
            </table>
         </form>
        </div>

        <!--Order Summary-->
        <div class="cart-right">
            <div class="summary-card">
                <h3>Order Summary</h3>
                
                <div class="summary-row">
                    <span>Subtotal</span>
                    <span id="display-subtotal">RM <?php echo number_format($SUB_TOTAL, 2); ?></span>
                </div>
                
                <div class="summary-row">
                    <span>Delivery Fee</span>
                    <span id="display-shipping" class="text-muted">Calculated at checkout</span>
                </div>

                <div class="summary-row">
                    <span>Discount</span>
                    <span id="display-discount">RM 0.00</span>
                </div>

                <!--Voucher-->
                <div class="promo-box">
                    <select id="voucher_select" class="promo-input">
                        <option value="0" data-min="0" data-id="0">-- Select a Voucher --</option>
                        <?php foreach($my_vouchers as $v): ?>
                           <option value="<?php echo $v['DISCOUNT_RATE']; ?>" 
                                   data-min="<?php echo htmlspecialchars($v['MIN_SPEND']); ?>"
                                   data-id="<?php echo $v['VOUCHER_ID']; ?>">
                                   <?php echo htmlspecialchars($v['VOUCHER_NAME']); ?> (<?php echo $v['DISCOUNT_RATE']; ?>% Off)
                            </option>
                         <?php endforeach; ?>
                    </select>
                    <button type="button" id="applyVoucherBtn" class="promo-btn">Apply</button>
                </div>

                <div class="total-row">
                    <span>Total</span>
                    <span id="display-total">RM <?php echo number_format($TOTAL_AMOUNT, 2); ?></span>
                </div>

                <button type="submit" form="checkoutForm" class="btn-block btn-checkout mt-4">PROCEED TO CHECKOUT</button>
                <a href="index.php" class="btn-block btn-continue">CONTINUE SHOPPING</a>
            </div>
        </div>
     </div>
   </div>

   <!-- Voucher Popup -->
  <div id="voucherPopup" class="voucher-popup-overlay">
    <div class="voucher-popup-box">
        <div class="voucher-popup-icon">🎟️</div>
        <div class="voucher-popup-title" id="voucherPopupTitle"></div>
        <div class="voucher-popup-msg"  id="voucherPopupMsg"></div>
        <button class="voucher-popup-btn" onclick="closeVoucherPopup()">OK</button>
    </div>
  </div>
<script>
    function showVoucherPopup(title, msgHtml) {
    document.getElementById('voucherPopupTitle').textContent = title;
    document.getElementById('voucherPopupMsg').innerHTML = msgHtml;
    document.getElementById('voucherPopup').style.display = 'block';
}

function closeVoucherPopup() {
    document.getElementById('voucherPopup').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.getElementById('selectAll');
    const itemCheckboxes = document.querySelectorAll('.item-checkbox');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const voucherSelect = document.getElementById('voucher_select');
    const hiddenVoucherInput = document.getElementById('selected_voucher_id_hidden');
    const checkoutForm = document.getElementById('checkoutForm');

    // 1. Initialize the display state of the bulk delete button
    toggleDeleteBtn();
    updateTotalSummary();

    if (deleteBtn) {
        deleteBtn.addEventListener('click', function() {
            if (confirm('Delete selected items?')) {
                const selectedIds = Array.from(document.querySelectorAll('.item-checkbox:checked')).map(cb => cb.closest('tr').getAttribute('data-id'));
                document.getElementById('selectedIdsInput').value = selectedIds.join(',');
                document.getElementById('deleteForm').submit();
            }
        });
    }

    function toggleDeleteBtn() {
        const checkedCount = document.querySelectorAll('.item-checkbox:checked').length;
        if(deleteBtn) {
            deleteBtn.style.display = checkedCount > 0 ? 'inline-block' : 'none';
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            itemCheckboxes.forEach(cb => cb.checked = this.checked);
            updateTotalSummary();
            toggleDeleteBtn();
        });
    }

    itemCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateTotalSummary();
            toggleDeleteBtn();
            // If one item is not selected, uncheck the select all box
            if(!this.checked && selectAll) selectAll.checked = false;
            // If all items are selected, check the select all box
            if(document.querySelectorAll('.item-checkbox:checked').length === itemCheckboxes.length && selectAll) {
                selectAll.checked = true;
            }
        });
    });

// 2. Quantity adjustment 
document.querySelectorAll('.qty-btn').forEach(button => {
    button.addEventListener('click', function() {
        const row = this.closest('tr');
        const input = row.querySelector('.qty-input');
        let val = parseInt(input.value);
        const max = parseInt(input.getAttribute('max'));

        if (this.classList.contains('plus') && val < max) val++;
        else if (this.classList.contains('minus') && val > 1) val--;

        input.value = val; 
        updateTotalSummary();

        //send new quantity to server via AJAX
        const formData = new FormData();
        formData.append('item_id', row.getAttribute('data-id'));
        formData.append('new_qty', val);

        fetch('update_cart_quantity.php', { method: 'POST', body: formData })
        .then(res => res.text()) // accept plain text
        .then(data => {
            const result = data.trim();
            // handle out of stock or over capacity response from server
            if (result === "out_of_stock") {
                alert("Sorry, this cake variant is out of stock!");
                location.reload(); 
            } else if (result === "out_of_capacity") {
                alert("Sorry, our kitchen is fully booked for today!");
                location.reload(); 
            } else if (result === "success") {
                console.log("Quantity updated.");
            }
        })
        .catch(err => console.error(err));
    });
});

    // 3. voucher application logic
    document.getElementById('applyVoucherBtn').addEventListener('click', function() {
        let subtotal = 0;
        document.querySelectorAll('.cart-table tbody tr[data-id]').forEach(row => {
            const checkbox = row.querySelector('.item-checkbox');
            if (checkbox && checkbox.checked) {
                const singlePrice = parseFloat(row.getAttribute('data-single-set-price'));
                const qty = parseInt(row.querySelector('.qty-input').value);
                subtotal += (singlePrice * qty);
            }
        });

        const selectedOption = voucherSelect.options[voucherSelect.selectedIndex];
        const minSpend = parseFloat(selectedOption.getAttribute('data-min')) || 0;
        const discountRate = parseFloat(voucherSelect.value) || 0;
        
        if (voucherSelect.value === "0") {
           showVoucherPopup('No Voucher Selected', 'Please select a voucher first.');
           return;
        }
        if (subtotal < minSpend) {
            showVoucherPopup(
            'Voucher Cannot Be Applied',
            'Minimum spend required: <span>RM ' + minSpend.toFixed(2) + '</span><br>' +
            'Your current subtotal: <span>RM ' + subtotal.toFixed(2) + '</span><br><br>' +
            'Please add more items or select a different voucher.'
            );
            voucherSelect.value = "0";
            updateTotalSummary();
            return;
        }
        showVoucherPopup('Voucher Applied!', 'You get <span>' + discountRate + '% off</span> your order.');
        updateTotalSummary();
    });

    // 4. real-time cart summary calculation
    function updateTotalSummary() {
        let subtotal = 0;
        
        // Loop through each cart item row to calculate subtotal based on checked items
        document.querySelectorAll('.cart-table tbody tr[data-id]').forEach(row => {
            const checkbox = row.querySelector('.item-checkbox');
            const qtyInput = row.querySelector('.qty-input');
            
            // get the pre-calculated total price for this item set (cake variant price + all addon prices)
            const singleSetPrice = parseFloat(row.getAttribute('data-single-set-price')) || 0;
            const qty = parseInt(qtyInput.value) || 1;
            
            const rowTotal = singleSetPrice * qty;
            
            // render the total price for this row (this will update if quantity changes or if addons are edited)
            row.querySelector('.item-price').innerText = 'RM ' + rowTotal.toFixed(2);
            
            // sum up total only for selected items
            if (checkbox && checkbox.checked) {
                subtotal += rowTotal;
            }
        });

        const selectedOption = voucherSelect.options[voucherSelect.selectedIndex];
        
        // sync the selected Voucher ID to the hidden input for POST submission to checkout.php
        hiddenVoucherInput.value = selectedOption.getAttribute('data-id') || 0;

        let discount = 0;
        const minSpend = parseFloat(selectedOption.getAttribute('data-min')) || 0;
        const discountRate = parseFloat(voucherSelect.value) || 0;

        // if the minimum spending requirement is met, apply the discount
        if (subtotal >= minSpend && discountRate > 0) {
            discount = subtotal * (discountRate / 100);
        } else if (discountRate > 0) {
            // if the quantity is reduced during editing, causing the amount to be insufficient, automatically revoke the voucher
            voucherSelect.value = "0";
            hiddenVoucherInput.value = "0";
            discount = 0;
        }

        // summary card display
        document.getElementById('display-subtotal').innerText = 'RM ' + subtotal.toFixed(2);
        document.getElementById('display-discount').innerText = '- RM ' + discount.toFixed(2);
        
        const finalTotal = subtotal - discount;
        document.getElementById('display-total').innerText = 'RM ' + (finalTotal > 0 ? finalTotal : 0).toFixed(2);
    }

    // 5. prevent submitting checkout without selecting any items
    if(checkoutForm) {
        checkoutForm.addEventListener('submit', function(e) {
            const checkedCount = document.querySelectorAll('.item-checkbox:checked').length;
            if(checkedCount === 0) {
                e.preventDefault();
                alert('Please select at least one item to checkout.');
            }
        });
    }
});
</script>
</body>
<?php include 'include/footer.php'; ?>
</html>
