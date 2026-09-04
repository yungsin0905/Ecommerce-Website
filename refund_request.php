<?php
session_start();
require_once 'include/config.php';

//Redirect unauthorized users to login
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

//Validate Order ID
$customer_id = intval($_SESSION['CUSTOMER_ID']);
$order_id    = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id === 0) {
    header("Location: order_history.php");
    exit();
}

// 1. Fetch basic order information
$order_sql = "SELECT o.ORDER_ID, o.ORDER_NO, o.DELIVERY_DATE,
                     py.PAYMENT_AMOUNT
              FROM orders o
              LEFT JOIN payment py ON o.PAYMENT_ID = py.PAYMENT_ID
              WHERE o.ORDER_ID = ? AND o.CUSTOMER_ID = ?
              LIMIT 1";

$stmt = $conn->prepare($order_sql);
$stmt->bind_param("ii", $order_id, $customer_id);
$stmt->execute();
$order_result = $stmt->get_result();
$order = $order_result->fetch_assoc();

//Ensure the order exists and belongs to the logged-in customer
if (!$order) {
    echo "<script>alert('Order not found or access denied!'); window.location.href='order_history.php';</script>";
    exit;
}

// 2. Fetch order items (one row per item, addons queried separately to avoid duplicate rows)
$items_sql = "SELECT oi.ORDER_ITEM_ID,
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
              LEFT JOIN custom c  ON oi.CUSTOM_ID  = c.CUSTOM_ID
              WHERE oi.ORDER_ID = ?";

$stmt2 = $conn->prepare($items_sql);
$stmt2->bind_param("i", $order_id);
$stmt2->execute();
$items_result = $stmt2->get_result();
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// 3. Fetch add-ons for each order item individually
$addons_by_item = [];
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

// 4. Handle refund form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_refund'])) {
    $reason     = $conn->real_escape_string(trim($_POST['reason']));
    $attachment = '';

    // File Upload: Process image attachment if provided
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === 0) {
        $target_dir = "uploads/refunds/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        $file_name   = time() . "_" . basename($_FILES["photo"]["name"]);
        $target_file = $target_dir . $file_name;
        if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
            $attachment = $target_file;
        }
    }

    // Insert refund request into the database (fields: ORDER_ID, CUSTOMER_ID, REASON, ATTACHMENT, REQUEST_STATUS, REQUEST_DATE, CREATED_AT, UPDATED_AT)
    $insert_stmt = $conn->prepare(
        "INSERT INTO refund_request (ORDER_ID, CUSTOMER_ID, REASON, ATTACHMENT, REQUEST_STATUS, REQUEST_DATE, CREATED_AT, UPDATED_AT)
         VALUES (?, ?, ?, ?, 'PENDING', NOW(), NOW(), NOW())"
    );
    $insert_stmt->bind_param("iiss", $order_id, $customer_id, $reason, $attachment);

    if ($insert_stmt->execute()) {

        // Insert admin notification
        $notif_message = "You have one new refund request";
        $notif_type = "Refund";
        $refund_id = $conn->insert_id; // get new refund request's ID

        $stmt_notif = $conn->prepare("INSERT INTO notification (TYPE, REF_ID, MESSAGE) VALUES (?, ?, ?)");
        $stmt_notif->bind_param("sis", $notif_type, $refund_id, $notif_message);
        $stmt_notif->execute();

        $notif_id = $conn->insert_id;

        // Insert into admin_notification for ALL admins
        $stmt_admins = $conn->prepare("SELECT ADMIN_ID FROM admin");
        $stmt_admins->execute();
        $admins = $stmt_admins->get_result()->fetch_all(MYSQLI_ASSOC);

        $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (ADMIN_ID, NOTIF_ID, IS_READ) VALUES (?, ?, 0)");
        foreach ($admins as $admin) {
            $stmt_admin_notif->bind_param("ii", $admin['ADMIN_ID'], $notif_id);
            $stmt_admin_notif->execute();
        }

        echo "<script>alert('Refund request submitted successfully!'); window.location.href='order_history.php';</script>";
        exit;
        
    } else {
        $error_msg = "Error: " . $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Request</title>
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
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        color: var(--font-color);
        margin: 0;
        padding: 0;
    }

    .main-container {
        max-width: 650px;
        margin: 60px auto;
        background-color: #ffffff;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 4px 20px rgba(27, 42, 60, 0.05);
        border: 1px solid var(--search-border-color);
    }

    h2 {
        color: var(--main-color);
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        text-align: center;
        border-bottom: 2px solid var(--secondary-color);
        padding-bottom: 15px;
        margin-bottom: 30px;
        font-size: 24px;
    }
  
    h3 {
        color: var(--font-color);
        margin-bottom: 20px;
        font-size: 16px;
        font-weight: 600;
        font-family: 'Poppins', sans-serif;
    }

    .order-info {
        background-color: var(--secondary-color);
        padding: 20px;
        border-radius: 14px;
        margin-bottom: 25px;
        border: 1px solid var(--search-border-color);
    }

    .order-info p {
        margin: 8px 0;
        font-size: 14px;
        color: var(--font2-color);
    } 

    .order-info strong {
        color: var(--font-color);
    }

    .cake-item-card {
        display: flex;
        align-items: center;
        gap: 20px;
        background: #ffffff;
        padding: 15px;
        border-radius: 14px;
        margin-bottom: 15px;
        border: 1px solid var(--search-border-color);
        border-left: 4px solid var(--main-color);
    }

    .cake-image img {
        width: 100px;
        height: 100px;
        object-fit: cover;
        border-radius: 10px;
    }

    .cake-details {
        flex-grow: 1;
    }

    .cake-details p {
        margin: 3px 0;
        font-size: 13px;
        color: var(--font2-color);
    }

    .cake-details strong {
        color: var(--font2-color);
        font-weight: 600;
    }

    .custom-badge {
        display: inline-block;
        background: var(--card-bg-color);
        color: var(--main-color);
        font-size: 11px;
        font-weight: 700;
        padding: 2px 10px;
        border-radius: 50px;
        margin-bottom: 6px;
        border: 1px solid var(--main-color);
        font-family: 'Inter', sans-serif;
    }

    .addon-box {
        background: var(--secondary-color);
        padding: 6px 10px;
        border-radius: 6px;
        margin-top: 6px;
        font-size: 12px;
        border-left: 3px solid var(--main-color);
        color: var(--font2-color);
    }

    hr {
        border: 0;
        border-top: 1px solid var(--search-border-color);
        margin: 25px 0;
    }

    label {
        font-weight: 600;
        display: block;
        margin-bottom: 8px;
        color: var(--font-color);
        font-size: 13px;
    }

    textarea {
        width: 100%;
        height: 100px;
        padding: 12px 15px;
        border: 1px solid var(--search-border-color);
        border-radius: 10px;
        box-sizing: border-box;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        color: var(--font-color);
        background-color: #fff;
        margin-bottom: 2px;
        transition: border-color 0.3s, box-shadow 0.3s;
        resize: vertical;
    }

    textarea:focus {
        outline: none;
        border-color: var(--main-color);
        box-shadow: 0 0 0 0.25rem rgba(128, 184, 210, 0.25);
        background-color: #fff;
    }

    small{
        display: block;
        font-size: 11px;
        font-family: 'Inter', sans-serif;
        color: var(--font2-color);           
        margin-bottom: 20px;
    }

    input[type="file"] {
        margin-bottom: 20px;
        font-size: 13px;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .refund-form-box {
        background-color: var(--secondary-color);
        border-radius: 14px;
        padding: 25px;
        border: 1.5px dashed var(--search-border-color);
        margin-top: 30px;
    }

    .btn-group-custom {
        display: flex;
        gap: 15px;
        flex-wrap: wrap;
    }

    .btn-submit {
        background-color: var(--main-color);
        color: #FFFFFF;
        padding: 9px 26px;
        border: none;
        border-radius: 25px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        font-size: 13px;
        font-family: 'Inter', sans-serif;
        text-decoration: none;
        display: inline-block;
    }

    .btn-submit:hover {
        background-color: var(--btn-hover);
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(60, 140, 177, 0.25);
        color: #FFFFFF;
    }

    .btn-back {
        background-color: #FFFFFF;
        border: 1px solid var(--search-border-color);
        color: var(--font-color);
        padding: 9px 26px;
        border-radius: 25px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        font-size: 13px;
        font-family: 'Inter', sans-serif;
        text-decoration: none;
        display: inline-block;
    }

    .btn-back:hover {
        background-color: var(--secondary-color);
        border-color: var(--main-color);
        transform: translateY(-2px);
        color: var(--font-color);
    }

    .error-msg {
        color: #c0392b;
        background: #fff0f0;
        padding: 10px 15px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 13px;
        border: 1px solid #f9d9d9;
    }
</style>
</head>
<body>
    <?php include_once 'include/header.php'; ?>

    <div class="main-container">
        <h2>Refund Request</h2>

        <?php if (!empty($error_msg)): ?>
            <div class="error-msg"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>

        <!-- Order summary -->
        <div class="order-info">
            <p><strong>Order No:</strong> <?php echo htmlspecialchars($order['ORDER_NO']); ?></p>
            <p><strong>Delivery Date:</strong> <?php echo date("d M Y", strtotime($order['DELIVERY_DATE'])); ?></p>
            <p><strong>Total Payment:</strong> RM <?php echo number_format($order['PAYMENT_AMOUNT'], 2); ?></p>
        </div>

        <hr>

        <!-- List of items in the order (display only) -->
        <h3>Items in this Order</h3>
        <?php foreach ($items as $item):
            $oid    = intval($item['ORDER_ITEM_ID']);
            $addons = $addons_by_item[$oid] ?? [];
        ?>
        <div class="cake-item-card">
            <div class="cake-image">
                <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'image/product/default.png'; ?>" alt="Cake">
            </div>
            <div class="cake-details">
                <?php if (!empty($item['CUSTOM_ID'])): ?>
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
                        <strong>Add-ons:</strong> <?php echo htmlspecialchars(implode(', ', $addons)); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <hr>

        <!-- Refund form (applies to the entire order) -->
        <div class="refund-form-box">
            <h3>Submit Refund Request</h3>
            <form method="post" enctype="multipart/form-data">
                <label for="reason">Refund Reason:</label>
                <textarea id="reason" name="reason" placeholder="Please describe your reason for refund..." maxlength="100" required></textarea>
                <small>Max 100 characters</small>

                <label for="photo">Upload Photo (optional):</label>
                <input type="file" id="photo" name="photo" accept="image/*">

                <div class="btn-group-custom">
                    <button type="submit" name="submit_refund" class="btn-submit">Submit Request</button>
                    <a href="order_history.php" class="btn-back">Back to Orders</a>
                </div>
            </form>
        </div>
    </div>

    <?php include_once 'include/footer.php'; ?>
</body>
</html>
