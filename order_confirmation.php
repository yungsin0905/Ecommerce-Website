<?php
session_start();
require_once 'include/config.php';

// 1. Auth check 
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit;
}
$customer_id = intval($_SESSION['CUSTOMER_ID']);

// 2. Check if payment failed
$is_failed   = isset($_GET['failed']) && $_GET['failed'] == '1';
$failed_type = $_GET['type'] ?? 'premade'; // 'custom' or 'premade'

// If success, get order info 
$order = null;
$order_items = [];

if (!$is_failed) {

    $order_id = intval($_GET['order_id'] ?? 0);
    $order_no = htmlspecialchars($_GET['order_no'] ?? '');

    // Make sure order_id exists
    if ($order_id <= 0) {
        header("Location: index.php");
        exit;
    }

    // Make sure this order belongs to this customer
    $order_result = mysqli_query($conn,
        "SELECT o.*, p.PAYMENT_METHODS
         FROM orders o
         LEFT JOIN payment p ON o.PAYMENT_ID = p.PAYMENT_ID
         WHERE o.ORDER_ID = $order_id AND o.CUSTOMER_ID = $customer_id
         LIMIT 1"
    );
    $order = mysqli_fetch_assoc($order_result);

    if (!$order) {
        header("Location: index.php");
        exit;
    }

    // 4. Get order items + their add-ons 
    $items_result = mysqli_query($conn,
        "SELECT * FROM order_item WHERE ORDER_ID = $order_id"
    );

    while ($row = mysqli_fetch_assoc($items_result)) {

        $order_item_id = intval($row['ORDER_ITEM_ID']);

        // Fetch add-ons for this item
        $addon_result = mysqli_query($conn,
            "SELECT * FROM order_item_addon WHERE ORDER_ITEM_ID = $order_item_id"
        );

        $row['addons']      = [];
        $row['addon_total'] = 0;

        while ($addon = mysqli_fetch_assoc($addon_result)) {
            $row['addons'][]     = $addon;
            $row['addon_total'] += floatval($addon['ADDON_PRICE_SNAPSHOT']) * intval($addon['QUANTITY']);
        }

        $order_items[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_failed ? 'Payment Failed' : 'Order Confirmed!'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        /* ===== Design tokens aligned with checkout.php / payment.php ===== */
        :root {
            --main-color: #80b8d2;
            --font-color: #1B2A3C;
            --secondary-color: #F4F8FC;
            --search-border-color: #C9DCEE;
            --bg-color: #FFFFFF;
            --font2-color: #52708A;
            --card-bg-color: #EBF4FC;
            --btn-hover: #3c8cb1;
            --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        body {
            background-color: var(--bg-color);
            font-family: 'Inter', sans-serif;
            color: var(--font-color);
            margin: 0;
            padding: 0;
        }

        /* Main wrapper */
        .confirm-wrapper {
            max-width: 600px;
            margin: 50px auto;
            padding: 0 20px 60px;
            text-align: center;
        }

        /* Icon circle */
        .icon-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: popIn 0.5s ease;
        }

        .icon-circle i {
            font-size: 50px;
        }

        .icon-circle.success {
            background-color: #d4edda;
        }

        .icon-circle.success i {
            color: #28a745;
        }

        .icon-circle.failed {
            background-color: var(--card-bg-color);
        }

        .icon-circle.failed i {
            color: var(--font-color);
        }

        h2 {
            color: var(--main-color);
            font-size: 26px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            margin-bottom: 5px;
        }

        .subtitle {
            color: var(--font2-color);
            font-size: 14px;
            margin-bottom: 30px;
            opacity: 0.85;
        }

        /* White info card */
        .info-card {
            background-color: #ffffff;
            border: 1px solid var(--search-border-color);
            border-radius: 20px;
            padding: 25px;
            text-align: left;
            margin-bottom: 20px;
        }

        .info-card h5 {
            color: var(--font2-color);
            margin-bottom: 15px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            border-bottom: 1px solid var(--search-border-color);
            padding-bottom: 8px;
        }

        /* Label / value rows */
        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin-bottom: 10px;
            color: var(--font-color);
        }

        .info-label {
            color: var(--font2-color);
            flex-shrink: 0;
            opacity: 0.85;
        }

        .info-value {
            font-weight: 700;
            color: var(--font-color);
            text-align: right;
            max-width: 65%;
        }

        /* Total row */
        .total-row .info-label,
        .total-row .info-value {
            font-size: 15px;
            color: var(--font-color);
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
        }

        /* Item rows */
        .item-row {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            padding: 7px 0;
            border-bottom: 1px solid var(--search-border-color);
            color: var(--font2-color);
            font-size: 13px;
        }

        .item-row.no-border {
            border-bottom: none;
        }

        .item-top-line {
            display: flex;
            justify-content: space-between;
            width: 100%;
            font-weight: 700;
            color: var(--font-color);
        }

        .item-muted {
            color: #9DB4C7;
            font-weight: 400;
        }

        .item-sub {
            font-size: 12px;
            color: var(--font2-color);
            padding-left: 4px;
            opacity: 0.85;
        }

        .item-card-text {
            color: #9DB4C7;
        }

        /* Custom cake badge */
        .custom-badge {
            background: var(--card-bg-color);
            color: var(--main-color);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 50px;
            margin-right: 5px;
            border: 1px solid var(--search-border-color);
        }

        /* Countdown box */
        .redirect-box {
            background-color: var(--secondary-color);
            border: 1px solid var(--search-border-color);
            border-radius: 14px;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-size: 14px;
            color: var(--font2-color);
        }

        .progress-bar-wrap {
            background-color: rgba(128, 184, 210, 0.25);
            border-radius: 10px;
            height: 6px;
            margin-top: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background-color: var(--main-color);
            border-radius: 10px;
            width: 100%;
            animation: shrink 5s linear forwards;
        }

        /* Buttons */
        .btn-pink {
            display: inline-block;
            background-color: var(--main-color);
            color: #FFFFFF;
            font-weight: 600;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            padding: 10px 28px;
            border-radius: 20px;
            text-decoration: none;
            transition: var(--transition);
            margin: 5px;
        }

        .btn-pink:hover {
            background-color: var(--btn-hover);
            color: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(27, 42, 60, 0.12);
        }

        .btn-outline {
            display: inline-block;
            background-color: #ffffff;
            color: var(--font2-color);
            font-weight: 600;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            padding: 10px 28px;
            border-radius: 20px;
            text-decoration: none;
            border: 1px solid var(--search-border-color);
            transition: var(--transition);
            margin: 5px;
        }

        .btn-outline:hover {
            background-color: var(--secondary-color);
            color: var(--main-color);
            border-color: var(--main-color);
            transform: translateY(-2px);
        }

        @keyframes popIn {
            0%   { transform: scale(0); opacity: 0; }
            80%  { transform: scale(1.1); }
            100% { transform: scale(1);  opacity: 1; }
        }

        @keyframes shrink {
            from { width: 100%; }
            to   { width: 0%; }
        }
            
    </style>
</head>
<body>

<?php include 'include/header.php'; ?>

<div class="confirm-wrapper">

<?php if ($is_failed): ?>

    <!-- ══════════════ PAYMENT FAILED ══════════════ -->
    <div class="icon-circle failed">
        <i class="bi bi-x-lg"></i>
    </div>

    <h2>Payment Failed</h2>
    <p class="subtitle">Don't worry, no money was deducted from your account.</p>

    <div class="info-card">
        <p style="margin:0; font-size:14px; color:#555;">
            <i class="bi bi-exclamation-circle me-2 text-danger"></i>
            Something went wrong while processing your payment. Please try again.
        </p>
    </div>

    <!-- Button depends on order type -->
    <?php if ($failed_type === 'custom'): ?>
        <a href="CustomiseRequest.php" class="btn-pink">
            <i class="bi bi-arrow-left me-1"></i> Back to Custom Request
        </a>
    <?php else: ?>
        <a href="shopping_cart.php" class="btn-pink">
            <i class="bi bi-cart me-1"></i> Back to Cart
        </a>
    <?php endif; ?>

    <a href="index.php" class="btn-outline">
        <i class="bi bi-house me-1"></i> Go to Homepage
    </a>

<?php else: ?>

    <!-- ══════════════ PAYMENT SUCCESS ══════════════ -->
    <div class="icon-circle success">
        <i class="bi bi-check-lg"></i>
    </div>

    <h2>Payment Successful!</h2>
    <p class="subtitle">Thank you for your order. We will start preparing your cake soon.</p>

    <!-- Order details -->
    <div class="info-card">
        <h5><i class="bi bi-receipt me-2"></i>Order Details</h5>

        <div class="info-row">
            <span class="info-label">Order Number</span>
            <span class="info-value"><?php echo htmlspecialchars($order['ORDER_NO']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Payment Method</span>
            <span class="info-value"><?php echo htmlspecialchars($order['PAYMENT_METHODS'] ?? '-'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Delivery Date</span>
            <span class="info-value"><?php echo date('d M Y', strtotime($order['DELIVERY_DATE'])); ?>
            </span>
        </div>
        <div class="info-row">
            <span class="info-label">Delivery Time</span>
            <span class="info-value"><?php echo htmlspecialchars($order['DELIVERY_SLOT_SNAPSHOT'] ?? '-'); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Deliver To</span>
            <span class="info-value"><?php echo htmlspecialchars($order['DELIVERY_ADDRESS_SNAPSHOT']); ?></span>
        </div>

        <?php if (!empty($order['VOUCHER_NAME_SNAPSHOT'])): ?>
        <div class="info-row">
            <span class="info-label">Voucher Used</span>
            <span class="info-value">
                <?php echo htmlspecialchars($order['VOUCHER_NAME_SNAPSHOT']); ?>
                (<?php echo $order['DISCOUNT_RATE_SNAPSHOT']; ?>% OFF)
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Price breakdown -->
    <div class="info-card">
        <h5><i class="bi bi-cash-coin me-2"></i>Price Breakdown</h5>

        <div class="info-row">
            <span class="info-label">Subtotal</span>
            <span class="info-value">RM <?php echo number_format($order['SUB_TOTAL'], 2); ?></span>
        </div>

        <?php if ($order['DISCOUNT_AMOUNT_SNAPSHOT'] > 0): ?>
        <div class="info-row">
            <span class="info-label">Discount</span>
            <span class="info-value">- RM <?php echo number_format($order['DISCOUNT_AMOUNT_SNAPSHOT'], 2); ?></span>
        </div>
        <?php endif; ?>

        <div class="info-row">
            <span class="info-label">Delivery Fee</span>
            <span class="info-value">RM <?php echo number_format($order['SHIPPING_FEE_SNAPSHOT'], 2); ?></span>
        </div>

        <div class="info-row total-row">
            <span class="info-label">Total Paid</span>
            <span class="info-value">RM <?php echo number_format($order['TOTAL_AMOUNT'], 2); ?></span>
        </div>
    </div>

    <!-- Items ordered -->
    <div class="info-card">
        <h5><i class="bi bi-box-seam me-2"></i>Items Ordered</h5>

        <?php if (empty($order_items)): ?>
            <p style="font-size:13px; color:#888;">No items found.</p>

        <?php else: ?>
            <?php foreach ($order_items as $index => $item):
                $item_unit_price = floatval($item['VARIANT_PRICE_SNAPSHOT']);
                $item_total      = ($item_unit_price + $item['addon_total']) * intval($item['QUANTITY']);
                $is_custom       = !empty($item['CUSTOM_ID']);

                // Add no-border class to the last item
                $is_last = ($index === count($order_items) - 1);
            ?>

            <div class="item-row <?php echo $is_last ? 'no-border' : ''; ?>">

                <!-- Name + total price -->
                <div class="item-top-line">
                    <span>
                        <?php if ($is_custom): ?>
                            <span class="custom-badge">✦ Custom</span>
                        <?php endif; ?>

                        <?php echo htmlspecialchars($item['PRODUCT_NAME_SNAPSHOT']); ?>
                        <span class="item-muted"> x<?php echo intval($item['QUANTITY']); ?></span>

                        <?php if (!empty($item['VARIANT_SIZE_SNAPSHOT'])): ?>
                            <span class="item-muted"> (<?php echo htmlspecialchars($item['VARIANT_SIZE_SNAPSHOT']); ?>)</span>
                        <?php endif; ?>
                    </span>
                    <span>RM <?php echo number_format($item_total, 2); ?></span>
                </div>

                <!-- Cake writing -->
                <?php if (!empty($item['CAKE_WRITING'])): ?>
                    <div class="item-sub">
                        <i class="bi bi-pen"></i>
                        Writing: "<?php echo htmlspecialchars($item['CAKE_WRITING']); ?>"
                    </div>
                <?php endif; ?>

                <!-- Add-ons -->
                <?php if (!empty($item['addons'])): ?>
                    <div class="item-sub">
                        <?php foreach ($item['addons'] as $addon): ?>
                            <div>
                                + <?php echo htmlspecialchars($addon['ADDON_NAME_SNAPSHOT']); ?>
                                (RM <?php echo number_format($addon['ADDON_PRICE_SNAPSHOT'], 2); ?>
                                x <?php echo intval($addon['QUANTITY']); ?>)

                                <?php if (!empty($addon['CARD_TEXT'])): ?>
                                    <br>
                                    <span class="item-card-text">
                                        Card: "<?php echo htmlspecialchars($addon['CARD_TEXT']); ?>"
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Countdown -->
    <div class="redirect-box">
        <span id="countdown-text">
            Redirecting to homepage in <strong>5</strong> seconds...
        </span>
        <div class="progress-bar-wrap">
            <div class="progress-bar-fill"></div>
        </div>
    </div>

    <!-- Buttons -->
    <a href="index.php" class="btn-pink">
        <i class="bi bi-house me-1"></i> Go to Homepage
    </a>
    <a href="order_history.php" class="btn-outline">
        <i class="bi bi-clock-history me-1"></i> View My Orders
    </a>

<?php endif; ?>

</div>

<script>
    // Only run countdown if payment was successful
    <?php if (!$is_failed): ?>
    let seconds = 5;
    const countdownText = document.getElementById('countdown-text');

    const timer = setInterval(function () {
        seconds--;

        countdownText.innerHTML =
            'Redirecting to homepage in <strong>' + seconds + '</strong> second' +
            (seconds !== 1 ? 's' : '') + '...';

        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = 'index.php';
        }
    }, 1000);
    <?php endif; ?>
</script>

<?php include 'include/footer.php'; ?>
</body>
</html>