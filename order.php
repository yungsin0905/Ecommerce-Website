<?php
session_start();
require_once 'include/config.php';

// Ensure the user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$current_customer_id = intval($_SESSION['CUSTOMER_ID']);
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($order_id <= 0) {
    header("Location: order_history.php");
    exit;
}

// Fetch order history, joining payment, refund, and shipping tables
$order_sql = "SELECT 
    o.ORDER_ID,
    o.ORDER_NO, 
    o.CREATED_AT, 
    o.ORDER_STATUS, 
    o.DELIVERY_DATE,
    o.DELIVERY_SLOT_SNAPSHOT,
    o.DELIVERY_ADDRESS_SNAPSHOT,
    o.CUSTOMER_NAME_SNAPSHOT,
    o.CUSTOMER_PHONE_SNAPSHOT,
    o.CUSTOMER_EMAIL_SNAPSHOT,
    o.SUB_TOTAL,
    o.DISCOUNT_AMOUNT_SNAPSHOT,
    o.SHIPPING_FEE_SNAPSHOT,
    o.TOTAL_AMOUNT,
    s.DELIVERY_STATUS AS SHIPPING_STATUS,
    py.PAYMENT_METHODS,
    py.PAYMENT_AMOUNT,
    py.PAYMENT_STATUS,
    py.TRANSACTION_DATE
FROM orders o
LEFT JOIN shipping s ON o.SHIPPING_ID = s.SHIPPING_ID
LEFT JOIN payment py ON o.PAYMENT_ID = py.PAYMENT_ID
WHERE o.ORDER_ID = $order_id AND o.CUSTOMER_ID = $current_customer_id
LIMIT 1";

$order_result = mysqli_query($conn, $order_sql);

if (!$order_result) {
    die("Query Failed: " . mysqli_error($conn));
}

$order_info = mysqli_fetch_assoc($order_result);

// Ensure the order exists and belongs to the current user
if (!$order_info) {
    echo "<script>alert('Order not found or access denied!'); window.location.href='order_history.php';</script>";
    exit;
}

// Fetch individual order items and product details
$items_sql = "SELECT 
    oi.ORDER_ITEM_ID,
    oi.PRODUCT_NAME_SNAPSHOT AS PRODUCT_NAME,
    oi.VARIANT_SIZE_SNAPSHOT AS VARIANT_SIZE,
    oi.QUANTITY,
    oi.CAKE_WRITING,
    oi.CUSTOM_ID,
    p.COVER_IMAGE,
    c.IDEAL_FLAVOUR,
    c.CUSTOM_DES,
    c.STYLE_NAME_SNAPSHOT
FROM order_item oi
LEFT JOIN product p ON oi.PRODUCT_ID = p.PRODUCT_ID 
LEFT JOIN custom c ON oi.CUSTOM_ID = c.CUSTOM_ID
WHERE oi.ORDER_ID = $order_id";
$items_result = mysqli_query($conn, $items_sql);

if (!$items_result) {
    die("Items Query Failed: " . mysqli_error($conn));
}

$items = [];
while ($row = mysqli_fetch_assoc($items_result)) {
    $items[] = $row;
}

$status = strtoupper($order_info['SHIPPING_STATUS'] ?? 'PENDING');

// Fetch add-ons per order item
$addons_by_item = [];
foreach ($items as $item) {
    $oid = intval($item['ORDER_ITEM_ID']);
    if ($oid && !isset($addons_by_item[$oid])) {
        $addon_q = mysqli_query($conn,
            "SELECT oia.ADDON_NAME_SNAPSHOT, oia.ADDON_PRICE_SNAPSHOT, oia.QUANTITY,
                    oi.CARD_TEXT
             FROM order_item_addon oia
             LEFT JOIN order_item oi ON oia.ORDER_ITEM_ID = oi.ORDER_ITEM_ID
             WHERE oia.ORDER_ITEM_ID = $oid"
        );
        $addons_by_item[$oid] = [];
        while ($a = mysqli_fetch_assoc($addon_q)) {
            $addons_by_item[$oid][] = $a;
        }
    }
}

// Check refund status
$check_refund = $conn->prepare("
    SELECT rr.REQUEST_ID, rr.REQUEST_STATUS, r.REFUND_ID, r.REFUND_STATUS
    FROM refund_request rr
    LEFT JOIN refund r ON rr.REQUEST_ID = r.REQUEST_ID
    WHERE rr.ORDER_ID = ?
    LIMIT 1
");
$check_refund->bind_param("i", $order_id);
$check_refund->execute();
$refund_res       = $check_refund->get_result()->fetch_assoc();

$has_request       = !empty($refund_res);
$request_status    = $refund_res['REQUEST_STATUS'] ?? null;
$refund_status     = $refund_res['REFUND_STATUS'] ?? null;
$is_pending        = ($request_status === 'PENDING' || $refund_status === 'PENDING');
$linked_refund_id  = intval($refund_res['REFUND_ID'] ?? 0);
$linked_req_id     = intval($refund_res['REQUEST_ID'] ?? 0);

// Check review status
$review_sql = "SELECT COUNT(DISTINCT r.PRODUCT_ID) as REVIEWED_COUNT 
               FROM review r 
               WHERE r.ORDER_ID = $order_id AND r.CUSTOMER_ID = $current_customer_id";
$review_result = mysqli_query($conn, $review_sql);
$review_row = mysqli_fetch_assoc($review_result);

// Only count non-custom items (CUSTOM_ID IS NULL means normal product)
$total_products_sql = "SELECT COUNT(DISTINCT oi.PRODUCT_ID) as TOTAL_PRODUCTS 
                       FROM order_item oi 
                       WHERE oi.ORDER_ID = $order_id 
                       AND oi.CUSTOM_ID IS NULL";
$total_products_result = mysqli_query($conn, $total_products_sql);
$total_products_row = mysqli_fetch_assoc($total_products_result);

$total_items = $total_products_row['TOTAL_PRODUCTS'];
// If all items are custom cakes, no review needed
$already_reviewed = ($total_items == 0) ? true : ($review_row['REVIEWED_COUNT'] >= $total_items);

//Calculate refund eligibility (valid within 2 days of delivery)
$is_completed = ($order_info['ORDER_STATUS'] === 'COMPLETED');
$is_refunded  = ($order_info['ORDER_STATUS'] === 'REFUNDED');
$refund_deadline = null;
$refund_expired  = false;

if ($is_completed) {
    // use orders' CREATED_AT or DELIVERY_DATE and counted from 2 days
    $completed_time  = strtotime($order_info['DELIVERY_DATE']);
    $refund_deadline = $completed_time + (2 * 24 * 60 * 60); // +2 days
    $refund_expired  = (time() > $refund_deadline);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    :root{
        --main-color: #80b8d2;
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --rating-color: #F5A623;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        --card-bg-color: #EBF4FC;
        --btn-hover: #3c8cb1;
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    body{
        background-color: var(--bg-color);
        font-family: 'Inter', sans-serif;
        color: var(--font2-color);
    }

    .order-container{
        background-color: #fff;
        width: 100%;
        max-width: 860px;
        margin: 40px auto;
        padding: 36px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);
        box-shadow: 0 4px 20px rgba(27, 42, 60, 0.05);
    }

    h2{
        text-align: center;
        margin-bottom: 28px;
        color: var(--main-color);
        font-weight: 700;
        font-size: 24px;
        font-family: 'Poppins', sans-serif;
    }

    h3{
        color: var(--font-color);
        font-weight: 600;
        font-size: 16px;
        margin-bottom: 14px;
        font-family: 'Poppins', sans-serif;
    }

    /* --- Status bar --- */
    .status-bar{
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 10px 20px;
        margin-bottom: 35px;
        position: relative;
    }

    .step-line{
        position: absolute;
        background-color: var(--search-border-color);
        z-index: 1;
        top: 34px;
        left: 70px;
        right: 70px;
        height: 3px;
        border-radius: 2px;
    }

    .status-icon{
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
        color: #9DB4C7;
        font-size: 13px;
        font-weight: 500;
        font-family: 'Inter', sans-serif;
        position: relative;
        z-index: 2;
        background-color: transparent;
        padding: 0 5px;
        min-width: 100px;
        text-align: center;
    }

    .status-icon .icon-box{
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background-color: #FFFFFF;
        border: 2px solid var(--search-border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: #9DB4C7;
        transition: var(--transition);
    }

    .status-icon.active{
        color: var(--font-color);
        font-weight: 600;
    }

    .status-icon.active .icon-box{
        background-color: var(--main-color);
        border-color: var(--main-color);
        color: #FFFFFF;
        box-shadow: 0 4px 14px rgba(128, 184, 210, 0.35);
        transform: scale(1.08);
    }

    /* --- Order info grid --- */
    .order-info{
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
        background: var(--secondary-color);
        border: 1px solid var(--search-border-color);
        border-radius: 16px;
        overflow: hidden;
        margin-bottom: 28px;
    }

    .order-info p{
        margin: 0;
        padding: 12px 18px;
        border-bottom: 1px solid var(--search-border-color);
        border-right: 1px solid var(--search-border-color);
        font-size: 13px;
        color: var(--font-color);
        font-family: 'Inter', sans-serif;
    }

    .order-info p:nth-child(2n){
        border-right: none;
    }

    .order-info p:nth-last-child(-n+2){
        border-bottom: none;
    }

    .order-info strong{
        display: block;
        font-size: 11px;
        font-weight: 700;
        color: var(--font2-color);
        text-transform: uppercase;
        letter-spacing: 0.6px;
        margin-bottom: 3px;
    }

    /* --- Cake item card --- */
    .cake-item-card{
        display: flex;
        gap: 20px;
        background: #FFFFFF;
        border: 1px solid var(--search-border-color);
        border-left: 4px solid var(--main-color);
        border-radius: 16px;
        padding: 18px 20px;
        margin-bottom: 16px;
        align-items: flex-start;
    }

    .cake-image img{
        width: 120px;
        height: 120px;
        object-fit: cover;
        border-radius: 14px;
        box-shadow: 0 3px 12px rgba(27, 42, 60, 0.08);
    }

    .cake-details{
        flex: 1;
        margin-bottom: 0;
    }

    .cake-details p{
        margin-bottom: 4px;
        line-height: 1.5;
        font-size: 13px;
        color: var(--font2-color);
    }

    .cake-details p:first-child{
        font-size: 15px;
        font-weight: 700;
        color: var(--font-color);
        margin-bottom: 8px;
        font-family: 'Poppins', sans-serif;
    }

    .cake-details strong{
        color: var(--font-color);
        font-weight: 600;
    }

    .addon-box{
        background: var(--secondary-color);
        border-left: 3px solid var(--main-color);
        border-radius: 0 10px 10px 0;
        padding: 9px 13px;
        margin-top: 10px;
        font-size: 12px;
        color: var(--font2-color);
    }

    /* --- Payment --- */
    .payment-info{
        background: var(--secondary-color);
        padding: 20px 22px;
        border-radius: 16px;
        border: 1px solid var(--search-border-color);
        margin-bottom: 4px;
    }

    .payment-info p{
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0;
        padding: 7px 0;
        border-bottom: 1px dashed var(--search-border-color);
        font-size: 13px;
        color: var(--font2-color);
    }

    .payment-info p:last-child{
        border-bottom: none;
        padding-top: 12px;
        margin-top: 4px;
        font-size: 15px;
        font-weight: 700;
        color: var(--font-color);
    }

    .payment-info strong{
        color: var(--font-color);
        font-weight: 600;
    }

    /* --- Buttons --- */
    .button-group{
        display: flex;
        gap: 12px;
        margin-top: 28px;
        justify-content: center;
        flex-wrap: wrap;
        align-items: flex-start;
    }

    button{
        min-width: 150px;
        padding: 10px 26px;
        background-color: var(--main-color);
        border: none;
        border-radius: 25px;
        color: #FFFFFF;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        cursor: pointer;
        transition: var(--transition);
    }

    button:hover{
        background-color: var(--btn-hover);
        transform: translateY(-2px);
    }

    .btn-back{
        background-color: #fff;
        border: 1px solid var(--search-border-color);
        color: var(--font-color);
    }

    .btn-back:hover{
        background-color: var(--secondary-color);
        border-color: var(--main-color);
        color: var(--font-color);
        transform: translateY(-2px);
    }

    .btn-disabled {
        background-color: #E4EBF1;
        color: #9DB4C7;
        cursor: not-allowed;
        min-width: 150px;
        padding: 10px 26px;
        border: none;
        border-radius: 25px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
    }

    .btn-disabled:hover{
        background-color: #E4EBF1;
        transform: none;
    }

    .refund-notice{
        text-align: center;
    }

    .refund-expired-msg{
        color: #c96b6b;
        font-size: 12px;
        margin-top: 6px;
        font-weight: 600;
    }

    .refund-available-msg{
        color: #2d7a5e;
        font-size: 12px;
        margin-top: 6px;
        font-weight: 600;
    }

    hr{
        border: none;
        border-top: 1px solid var(--search-border-color);
        margin: 26px 0;
    }

    a{
        text-decoration: none;
    }

    .text-highlight{
        color: var(--main-color);
        font-weight: 700;
    }

    .custom-badge{
        display: inline-block;
        background: var(--card-bg-color);
        color: var(--main-color);
        border: 1px solid var(--main-color);
        font-size: 11px;
        font-weight: 700;
        padding: 3px 12px;
        border-radius: 50px;
        margin-bottom: 8px;
        letter-spacing: 0.3px;
        font-family: 'Inter', sans-serif;
    }
</style>
</head>
<body>
    <?php include_once 'include/header.php'; ?>
    <div class="order-container">
        <h2>Order Details</h2>

        <!-- Delivery status bar -->
        <div class="status-bar">
            <div class="step-line"></div>
            <div class="status-icon <?php echo in_array($status, ['PENDING', 'OUT FOR DELIVERY', 'DELIVERED']) ? 'active' : ''; ?>">
                <div class="icon-box">
                    <i class="bi bi-clock-history"></i>
                </div>
                <span>Pending</span>
            </div>
            <div class="status-icon <?php echo in_array($status, ['OUT FOR DELIVERY', 'DELIVERED']) ? 'active' : ''; ?>">
                <div class="icon-box">
                    <i class="bi bi-truck"></i>
                </div>
                <span>Out for Delivery</span>
            </div>
            <div class="status-icon <?php echo ($status === 'DELIVERED') ? 'active' : ''; ?>">
                <div class="icon-box">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <span>Delivered</span>
            </div>
        </div>

        <!-- Order info -->
        <div class="order-info">
            <p><strong>Name:</strong> <?php echo htmlspecialchars($order_info['CUSTOMER_NAME_SNAPSHOT']); ?></p>
            <p><strong>Email:</strong> <?php echo htmlspecialchars($order_info['CUSTOMER_EMAIL_SNAPSHOT']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($order_info['CUSTOMER_PHONE_SNAPSHOT']); ?></p>
            <p><strong>Order No:</strong> <?php echo htmlspecialchars($order_info['ORDER_NO']); ?></p>
            <p><strong>Order Date:</strong> <?php echo date("d M Y, H:i", strtotime($order_info['CREATED_AT'])); ?></p>
            <p><strong>Order Status:</strong> <span class="text-highlight"><?php echo htmlspecialchars($order_info['ORDER_STATUS']); ?></span></p>
            <p><strong>Delivery Status:</strong><span class="text-highlight"> <?php echo htmlspecialchars($order_info['SHIPPING_STATUS'] ?? 'Pending'); ?></p>
            <p><strong>Delivery Date:</strong> <?php echo date("d M Y", strtotime($order_info['DELIVERY_DATE'])); ?></p>
            <p><strong>Delivery Slot:</strong> <?php echo htmlspecialchars($order_info['DELIVERY_SLOT_SNAPSHOT']); ?></p>
            <p><strong>Delivery Address:</strong> <?php echo htmlspecialchars($order_info['DELIVERY_ADDRESS_SNAPSHOT']); ?></p>
        </div>
        <hr>

        <!-- Item ordered -->
        <h3>Items Ordered</h3>
        <?php foreach ($items as $item): 
            $item_id = intval($item['ORDER_ITEM_ID']);
            $is_custom = !empty($item['CUSTOM_ID']);
            $addons = $addons_by_item[$item_id] ?? [];
        ?>
        <div class="cake-item-card">
            <div class="cake-image">
                <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_cake.png'; ?>" alt="Cake Image">
            </div>
            <div class="cake-details">
                <?php if ($is_custom): ?>
                    <span class="custom-badge">✦ Custom Cake</span>
                <?php endif; ?>

                <p><strong>Cake Name:</strong> <?php echo htmlspecialchars($item['PRODUCT_NAME']); ?></p>
                <p><strong>Size:</strong> <?php echo htmlspecialchars($item['VARIANT_SIZE'] ?? 'N/A'); ?></p>
                <p><strong>Quantity:</strong> <?php echo intval($item['QUANTITY']); ?></p>

                <?php if (!empty($item['IDEAL_FLAVOUR'])): ?>
                    <p><strong>Flavour:</strong> <?php echo htmlspecialchars($item['IDEAL_FLAVOUR']); ?></p>
                <?php endif; ?>

                <?php if (!empty($item['STYLE_NAME_SNAPSHOT'])): ?>
                    <p><strong>Style:</strong> <?php echo htmlspecialchars($item['STYLE_NAME_SNAPSHOT']); ?></p>
                <?php endif; ?>

                <?php if (!empty($item['CUSTOM_DES'])): ?>
                    <p><strong>Customization:</strong> <?php echo htmlspecialchars($item['CUSTOM_DES']); ?></p>
                <?php endif; ?>

                <?php if (!empty($item['CAKE_WRITING'])): ?>
                    <p><strong>Cake Writing:</strong> "<?php echo htmlspecialchars($item['CAKE_WRITING']); ?>"</p>
                <?php endif; ?>

                <?php if (!empty($addons)): ?>
                    <div class="addon-box">
                        <strong>Add-ons:</strong>
                        <?php foreach ($addons as $addon): ?>
                            <div>
                                • <?php echo htmlspecialchars($addon['ADDON_NAME_SNAPSHOT']); ?>
                                (RM <?php echo number_format($addon['ADDON_PRICE_SNAPSHOT'], 2); ?>
                                x <?php echo intval($addon['QUANTITY']); ?>)
                                <?php if (!empty($addon['CARD_TEXT'])): ?>
                                    <br><span style="color:#888;">Card: "<?php echo htmlspecialchars($addon['CARD_TEXT']); ?>"</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <hr>

        <!-- payment info -->
        <h3>Payment Details</h3>
        <div class="payment-info">
            <!--- SUB_TOTAL now correctly from orders table via separate query -->
            <p><strong>Subtotal:</strong> RM <?php echo number_format($order_info['SUB_TOTAL'], 2); ?></p>

            <?php if (!empty($order_info['DISCOUNT_AMOUNT_SNAPSHOT']) && $order_info['DISCOUNT_AMOUNT_SNAPSHOT'] > 0): ?>
                <p><strong>Discount:</strong> - RM <?php echo number_format($order_info['DISCOUNT_AMOUNT_SNAPSHOT'], 2); ?></p>
            <?php endif; ?>

            <p><strong>Delivery Fee:</strong> RM <?php echo number_format($order_info['SHIPPING_FEE_SNAPSHOT'], 2); ?></p>

            <!--- Use TOTAL_AMOUNT for the final amount display -->
            <p><strong>Total Amount:</strong> <span class="text-highlight">RM <?php echo number_format($order_info['TOTAL_AMOUNT'], 2); ?></span></p>
          
            <p><strong>Payment Method:</strong> <?php echo htmlspecialchars($order_info['PAYMENT_METHODS'] ?? 'N/A'); ?></p>

            <p><strong>Payment Status:</strong><span class="text-highlight"> <?php echo htmlspecialchars($order_info['PAYMENT_STATUS'] ?? 'N/A'); ?></p>

            <p><strong>Transaction Date:</strong> <?php echo !empty($order_info['TRANSACTION_DATE']) ? date("d M Y, H:i", strtotime($order_info['TRANSACTION_DATE'])) : 'N/A'; ?></p>
        </div>
        <!-- Buttons -->
       <div class="button-group">
        <a href="order_history.php">
           <button class="btn-back">Back to Order History</button>
        </a>

        <?php if ($is_completed || $is_refunded): ?>
        <?php if ($has_request): ?>
          <?php if ($is_pending): ?>
           <button disabled class="btn-disabled">Refund Pending</button>
          <?php else: ?>
            <a href="refund.php?refund_id=<?php echo $linked_refund_id; ?>&request_id=<?php echo $linked_req_id; ?>&order_id=<?php echo $order_id; ?>">
                <button>Refund Status</button>
            </a>
        <?php endif; ?>

        <?php elseif ($refund_expired): ?>
            <div class="refund-notice">
                <button disabled class="btn-disabled">Request Refund</button>
                <p class="refund-expired-msg">Refund period has expired (within 2 days of delivery only).</p>
            </div>

        <?php else: ?>
            <div class="refund-notice">
                <a href="refund_request.php?order_id=<?php echo $order_id; ?>">
                    <button>Request Refund</button>
                </a>
                <p class="refund-available-msg">Refund available until: <?php echo date("d M Y, H:i", $refund_deadline); ?></p>
            </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($is_completed): ?>
           <?php if ($already_reviewed): ?>
            <button class="btn-disabled" disabled>Reviewed</button>
           <?php else: ?>
            <a href="review.php?order_id=<?php echo $order_id; ?>">
                <button>Review Order</button>
            </a>
        <?php endif; ?>
      <?php endif; ?>
     </div>
    </div>
</body>
<?php include_once 'include/footer.php'; ?>
</html>
