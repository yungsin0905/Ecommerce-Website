<?php
session_start();
require_once 'include/config.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);
$refund_id   = isset($_GET['refund_id'])  ? intval($_GET['refund_id'])  : 0;
$request_id  = isset($_GET['request_id']) ? intval($_GET['request_id']) : 0;

if ($refund_id <= 0 && $request_id <= 0) {
    header("Location: order_history.php");
    exit();
}

// If refund_id is provided, query directly from the refund table
// If only request_id is provided (REJECTED case where no refund record exists yet), query from refund_request table
if ($refund_id > 0) {
    $refund_sql = "SELECT r.REFUND_ID, r.ORDER_ID, r.REASON, r.REFUND_AMOUNT,
                          r.REFUND_STATUS, r.CREATED_AT,
                          rr.REASON AS CUSTOMER_REASON,
                          rr.REQUEST_STATUS
                   FROM refund r
                   LEFT JOIN refund_request rr ON r.REQUEST_ID = rr.REQUEST_ID
                   WHERE r.REFUND_ID = ? AND r.CUSTOMER_ID = ?
                   LIMIT 1";
    $stmt = $conn->prepare($refund_sql);
    $stmt->bind_param("ii", $refund_id, $customer_id);
} else {
    $refund_sql = "SELECT NULL AS REFUND_ID, rr.ORDER_ID, NULL AS REASON, NULL AS REFUND_AMOUNT,
                          NULL AS REFUND_STATUS, rr.CREATED_AT,
                          rr.REASON AS CUSTOMER_REASON,
                          rr.REQUEST_STATUS
                   FROM refund_request rr
                   WHERE rr.REQUEST_ID = ? AND rr.CUSTOMER_ID = ?
                   LIMIT 1";
    $stmt = $conn->prepare($refund_sql);
    $stmt->bind_param("ii", $request_id, $customer_id);
}

$stmt->execute();
$refund_data = $stmt->get_result()->fetch_assoc();

// Redirect back to order history if no matching refund record is found
if (!$refund_data) {
    echo "<script>alert('Refund record not found!'); 
    window.location.href='order_history.php';</script>";
    exit;
}

$order_id = intval($refund_data['ORDER_ID']);

// 2. Fetch all items in the order 
$items_sql = "SELECT oi.ORDER_ITEM_ID,
                     oi.PRODUCT_NAME_SNAPSHOT AS PRODUCT_NAME,
                     oi.VARIANT_SIZE_SNAPSHOT  AS VARIANT_SIZE,
                     oi.QUANTITY,
                     oi.CAKE_WRITING,
                     oi.CUSTOM_ID,
                     p.COVER_IMAGE,
                     c.IDEAL_FLAVOUR,
                     c.CUSTOM_DES,
                     c.STYLE_NAME_SNAPSHOT
              FROM order_item oi
              LEFT JOIN product p ON oi.PRODUCT_ID = p.PRODUCT_ID
              LEFT JOIN custom c  ON oi.CUSTOM_ID  = c.CUSTOM_ID
              WHERE oi.ORDER_ID = ?";

$stmt2 = $conn->prepare($items_sql);
$stmt2->bind_param("i", $order_id);
$stmt2->execute();
$items = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// 3. Fetch add-ons for each item individually to avoid duplicate rows
foreach ($items as $item) {
    $oid = intval($item['ORDER_ITEM_ID']);
    $addon_stmt = $conn->prepare(
        "SELECT ADDON_NAME_SNAPSHOT FROM order_item_addon WHERE ORDER_ITEM_ID = ?"
    );
    $addon_stmt->bind_param("i", $oid);
    $addon_stmt->execute();
    $addon_res = $addon_stmt->get_result();
    $addons_by_item[$oid] = [];
    while ($a = $addon_res->fetch_assoc()) {
        $addons_by_item[$oid][] = $a['ADDON_NAME_SNAPSHOT'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --main-color: rgb(240, 194, 200);
        --font-color: rgb(101, 54, 31);
        --secondary-color: #fff6e6;
        --bg-color: #fffdf9;
        --font2-color: #936752;
        --card-bg-color: #f9d9d9;
        --btn-hover: rgb(220, 169, 176);
    }

    body {
        font-family: 'Quicksand', sans-serif;
        background-color: var(--bg-color);
        margin: 0;
        padding: 0;
        color: var(--font-color);
    }

    .refund-container {
        background-color: white;
        padding: 40px;
        border-radius: 24px;
        box-shadow: 0 4px 30px rgba(101, 54, 31, 0.07);
        max-width: 750px;
        margin: 60px auto;
        border: 1px solid rgba(240, 194, 200, 0.4);
    }

    h2 {
        text-align: center;
        color: var(--font2-color);
        font-weight: 700;
        font-family: 'Quicksand', sans-serif;
        margin-bottom: 30px;
        border-bottom: 2px solid #fceee9;
        padding-bottom: 15px;
        font-size: 22px;
    }

    h3 {
        color: var(--font2-color);
        font-size: 16px;
        font-weight: 700;
        font-family: 'Quicksand', sans-serif;
        margin-bottom: 15px;
    }

    hr {
        border: 0;
        border-top: 2px solid #fceee9;
        margin: 25px 0;
    }

    .info-row {
        display: flex;
        padding: 10px 0;
        border-bottom: 1px solid rgba(255, 246, 230, 0.8);
        font-size: 14px;
    }

    .info-label {
        font-weight: 700;
        width: 160px;
        flex-shrink: 0;
        color: var(--font2-color);
    }

    .info-value {
        color: var(--font-color);
        font-size: 14px;
    }

    .status-badge {
        display: inline-block;
        padding: 3px 14px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 700;
        background-color: var(--card-bg-color);
        color: var(--font-color);
        border: 1px solid var(--main-color);
    }

    .cake-item-card {
        display: flex;
        align-items: flex-start;
        gap: 20px;
        background: #fffcfb;
        padding: 15px;
        border-radius: 14px;
        margin-bottom: 15px;
        border: 1.5px solid #f0e4df;
        border-left: 4px solid var(--main-color);
    } 

    .cake-image img {
        width: 100px;
        height: 100px;
        object-fit: cover;
        border-radius: 10px;
        border: 2px solid rgba(240, 194, 200, 0.5);
        transition: transform 0.3s ease;
    }

    .cake-image img:hover {
        transform: scale(1.05);
    }

    .cake-details {
        flex: 1;
    }

    .cake-details p {
        margin: 3px 0;
        font-size: 13px;
        color: var(--font2-color);
        border-bottom: none;
        padding-bottom: 0;
        display: block;
    }

    .custom-badge {
        display: inline-block;
        background: var(--card-bg-color);
        color: var(--font-color);
        font-size: 11px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 50px;
        margin-bottom: 6px;
        border: 1px solid var(--main-color);
    }

    .addon-box {
        background: #fff1fd;
        padding: 6px 10px;
        border-radius: 6px;
        margin-top: 6px;
        font-size: 12px;
        border-left: 3px solid var(--main-color);
        color: var(--font2-color);
    }

    .refund-info-box {
        background-color: var(--secondary-color);
        padding: 20px;
        border-radius: 14px;
        margin-top: 10px;
        border: 1px solid rgba(240, 194, 200, 0.3);
    }

    .status-req-PENDING {
        background-color: #fff3cd;
        color: #7d6008;
        border: 1px solid #ffe082;
    }

    .status-req-APPROVED {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #b2dfdb;
    }

    .status-req-REJECTED {
        background-color: #f9d9d9;
        color: var(--font-color);
        border: 1px solid var(--main-color);
    }

    .btn-back {
        display: block;
        width: 180px;
        margin: 30px auto 0;
        padding: 10px;
        background-color: var(--main-color);
        border: none;
        border-radius: 20px;
        color: var(--font-color);
        font-weight: 700;
        font-size: 14px;
        font-family: 'Quicksand', sans-serif;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: center;
        text-decoration: none;
    }

    .btn-back:hover {
        background-color: var(--btn-hover);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(101, 54, 31, 0.12);
        color: var(--font-color);
    }
</style>
</head>
<body>
    <?php include_once 'include/header.php'; ?>

    <div class="refund-container">
        <h2>Refund Details</h2>

        <!-- Items in the refunded order -->
        <h3>Items in This Order</h3>
        <?php foreach ($items as $item):
            $oid    = intval($item['ORDER_ITEM_ID']);
            $addons = $addons_by_item[$oid] ?? [];
        ?>
        <div class="cake-item-card">
            <div class="cake-image">
                <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_cake.png'; ?>" alt="Cake">
            </div>
            <div class="cake-details">
                <?php if (!empty($item['CUSTOM_ID'])): ?>
                    <span class="custom-badge"> Custom Cake</span>
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
                        <strong>Add-ons:</strong> <?php echo htmlspecialchars(implode(', ', $addons)); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <hr>

        <!-- Refund information -->
        <h3>Refund Information</h3>
        <div class="refund-info-box">
            <?php if (!empty($refund_data['CUSTOMER_REASON'])): ?>
            <div class="info-row">
                <span class="info-label">Request Reason:</span>
                <span class="info-value"><?php echo htmlspecialchars($refund_data['CUSTOMER_REASON']  ?? ''); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label">Refund Reason:</span>
                <span class="info-value"><?php echo htmlspecialchars($refund_data['REASON'] ?? ''); ?></span>
            </div>
            <?php if ($refund_data['REFUND_AMOUNT'] !== null): ?>
            <div class="info-row">
                <span class="info-label">Refund Amount:</span>
                <span class="info-value">RM <?php echo number_format($refund_data['REFUND_AMOUNT'], 2); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($refund_data['REQUEST_STATUS'])): ?>
            <div class="info-row">
                 <span class="info-label">Request Status:</span>
                 <span class="info-value">
                    <span class="status-badge status-req-<?php echo htmlspecialchars($refund_data['REQUEST_STATUS']); ?>">
                       <?php echo htmlspecialchars($refund_data['REQUEST_STATUS']); ?>
                    </span>
                 </span>
            </div>
            <?php endif; ?>
            <?php if (!empty($refund_data['REFUND_STATUS'])): ?>
            <div class="info-row">
                <span class="info-label">Refund Status:</span>
                <span class="info-value">
                    <span class="status-badge"><?php echo htmlspecialchars($refund_data['REFUND_STATUS']); ?></span>
                </span>
            </div>
            <?php endif; ?>

            <div class="info-row">
                <span class="info-label">Request Date:</span>
                <span class="info-value"><?php echo date("d M Y, H:i", strtotime($refund_data['CREATED_AT'])); ?></span>
            </div>
        </div>

        <a href="order_history.php" class="btn-back">Back to Orders</a>
    </div>

    <?php include_once 'include/footer.php'; ?>
</body>
</html>
