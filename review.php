<?php
session_start();
require_once 'include/config.php'; 

// Check if user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);
$order_id    = isset($_GET['order_id'])   ? intval($_GET['order_id'])   : 0;
$product_id  = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

// Basic validation for missing Order ID
if ($order_id == 0) {
    echo "<script>alert('Error: Missing Order ID.'); window.location.href='order_history.php';</script>";
    exit;
}

// Verify the order belongs to this customer and is COMPLETED
$stmt_verify = $conn->prepare("SELECT ORDER_ID, ORDER_NO FROM orders WHERE ORDER_ID = ? AND CUSTOMER_ID = ? AND ORDER_STATUS = 'COMPLETED' LIMIT 1");
$stmt_verify->bind_param("ii", $order_id, $customer_id);
$stmt_verify->execute();
$order_info = $stmt_verify->get_result()->fetch_assoc();

if (!$order_info) {
    echo "<script>alert('Access denied or order not completed.'); window.location.href='order_history.php';</script>";
    exit;
}

// product_id exists = show review form
if ($product_id > 0) {

    // Check this product is actually in this order
    $stmt_check = $conn->prepare("SELECT oi.PRODUCT_ID FROM order_item oi WHERE oi.ORDER_ID = ? AND oi.PRODUCT_ID = ? LIMIT 1");
    $stmt_check->bind_param("ii", $order_id, $product_id);
    $stmt_check->execute();
    if (!$stmt_check->get_result()->fetch_assoc()) {
        echo "<script>alert('Product not found in this order.'); window.location.href='review.php?order_id=" . $order_id . "';</script>";
        exit;
    }

    // Check if already reviewed
    $stmt_reviewed = $conn->prepare("SELECT REVIEW_ID FROM review WHERE ORDER_ID = ? AND PRODUCT_ID = ? AND CUSTOMER_ID = ? LIMIT 1");
    $stmt_reviewed->bind_param("iii", $order_id, $product_id, $customer_id);
    $stmt_reviewed->execute();
    if ($stmt_reviewed->get_result()->fetch_assoc()) {
        echo "<script>alert('You have already reviewed this product.'); window.location.href='review.php?order_id=" . $order_id . "';</script>";
        exit;
    }

    // Get product details
    $stmt_info = $conn->prepare("SELECT p.PRODUCT_NAME, p.COVER_IMAGE FROM product p WHERE p.PRODUCT_ID = ? LIMIT 1");
    $stmt_info->bind_param("i", $product_id);
    $stmt_info->execute();
    $product = $stmt_info->get_result()->fetch_assoc();

    // Handle POST submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $post_order_id   = intval($_POST['order_id']);
        $post_product_id = intval($_POST['product_id']);
        $rating          = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $comment         = trim($_POST['comment']);
        $review_image    = "";

        if ($rating < 1 || $rating > 5) {
            echo "<script>alert('Please select a rating star!');</script>";
        } else {
            // Handle image upload
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
                $target_dir = "uploads/reviews/";
                if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

                $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $file_extension = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));

                if (in_array($file_extension, $allowed_types)) {
                    $file_name = "REV_" . time() . "_" . $customer_id . "." . $file_extension;
                    if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_dir . $file_name)) {
                        $review_image = $target_dir . $file_name;
                    }
                }
            }

            // Insert review
            $stmt_insert = $conn->prepare("INSERT INTO review (CUSTOMER_ID, PRODUCT_ID, ORDER_ID, RATING, COMMENTS, REVIEW_IMAGE, REVIEW_STATUS, CREATED_AT) VALUES (?, ?, ?, ?, ?, ?, 'Unhide', NOW())");
            $stmt_insert->bind_param("iiiiss", $customer_id, $post_product_id, $post_order_id, $rating, $comment, $review_image);

            if ($stmt_insert->execute()) {

                // Update product AVG_RATING
                $stmt_avg = $conn->prepare("UPDATE product SET AVG_RATING = (SELECT AVG(RATING) FROM review WHERE PRODUCT_ID = ? AND REVIEW_STATUS = 'Unhide') WHERE PRODUCT_ID = ?");
                $stmt_avg->bind_param("ii", $post_product_id, $post_product_id);
                $stmt_avg->execute();

                // Insert admin notification
                $notif_message = "You have one new review";
                $notif_type = "Review";
                $review_id = $stmt_insert->insert_id;

                $stmt_notif = $conn->prepare("INSERT INTO notification (TYPE, REF_ID, MESSAGE) VALUES (?, ?, ?)");
                $stmt_notif->bind_param("sis", $notif_type, $review_id, $notif_message);
                $stmt_notif->execute();

                $notif_id = $conn->insert_id; // get the new notification's ID

                // Insert into admin_notification for ALL admins
                $stmt_admins = $conn->prepare("SELECT ADMIN_ID FROM admin");
                $stmt_admins->execute();
                $admins = $stmt_admins->get_result()->fetch_all(MYSQLI_ASSOC);

                $stmt_admin_notif = $conn->prepare("INSERT INTO admin_notification (ADMIN_ID, NOTIF_ID, IS_READ) VALUES (?, ?, 0)");
                foreach ($admins as $admin) {
                    $stmt_admin_notif->bind_param("ii", $admin['ADMIN_ID'], $notif_id);
                    $stmt_admin_notif->execute();
                }

                // Go back to product list to review next item
                echo "<script>alert('Thank you for your review!'); window.location.href='review.php?order_id=" . $post_order_id . "';</script>";
                exit;
            } else {
                echo "<script>alert('Failed to submit review. Please try again.');</script>";
            }
        }
    }
}

//no product_id = show product list
else {
    // Get all products in this order with their review status
    $stmt_items = $conn->prepare("
        SELECT 
            p.PRODUCT_ID,
            oi.PRODUCT_NAME_SNAPSHOT AS PRODUCT_NAME,
            p.COVER_IMAGE,
            (SELECT COUNT(*) FROM review r WHERE r.ORDER_ID = ? AND r.PRODUCT_ID = p.PRODUCT_ID AND r.CUSTOMER_ID = ?) AS IS_REVIEWED
        FROM order_item oi
        INNER JOIN product p ON oi.PRODUCT_ID = p.PRODUCT_ID
        WHERE oi.ORDER_ID = ?
    ");
    $stmt_items->bind_param("iii", $order_id, $customer_id, $order_id);
    $stmt_items->execute();
    $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Review</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css">
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
            --star-gold: #ffca08;
            --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        body {
            background-color: var(--bg-color);
            color: var(--font-color);
            font-family: 'Inter', sans-serif;
        }

        .main-container {
            display: flex;
            justify-content: center;
            padding: 60px 20px;
        }

        .box {
            background: #ffffff;
            max-width: 600px;
            width: 100%;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(27, 42, 60, 0.05);
            border: 1px solid var(--search-border-color);
        }

        h2 {
            text-align: center;
            font-weight: 700;
            font-family: 'Poppins', sans-serif;
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 15px;
            margin-bottom: 25px;
            color: var(--main-color);
            font-size: 24px;
        }

        .order-no {
            text-align: center;
            color: var(--font2-color);
            margin-bottom: 25px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }   

        /* Product List */
        .product-card {
            display: flex;
            align-items: center;
            gap: 20px;
            background: var(--secondary-color);
            border-radius: 14px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid var(--search-border-color);
        }

        .product-card img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 10px;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(27, 42, 60, 0.08);
        }

        .product-card .product-name {
            flex: 1;
            font-weight: 600;
            font-size: 14px;
            color: var(--font-color);
            font-family: 'Poppins', sans-serif;
        }

        .btn-write-review {
            background-color: var(--main-color);
            color: #FFFFFF;
            border: none;
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
            white-space: nowrap;
        }

        .btn-write-review:hover {
            background-color: var(--btn-hover);
            color: #FFFFFF;
            
        }

        .btn-reviewed {
            background-color: #E4EBF1;
            color: #9DB4C7;
            border: none;
            border-radius: 20px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            cursor: not-allowed;
            white-space: nowrap;
        }

        /* Review Form */
        .order-info-box {
            background-color: var(--secondary-color);
            padding: 15px;
            border-radius: 14px;
            margin-bottom: 25px;
            text-align: center;
            border: 1px solid var(--search-border-color);
        }

        .order-info-box img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 12px;
            margin: 10px 0;
            border: 1px solid var(--search-border-color);
            box-shadow: 0 2px 10px rgba(27, 42, 60, 0.08);
        }

        .star-rating {
            display: flex;
            gap: 10px;
            margin: 10px 0 20px 0;
        }

        .star {
            font-size: 40px;
            color: #D3E0EA;
            cursor: pointer;
            transition: transform 0.2s, color 0.2s;
        }

        .star:hover {
            transform: scale(1.2);
        }

        .star.active {
            color: var(--star-gold);
        }

        label {
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
            color: var(--font-color);
            font-size: 13px;
            font-family: 'Inter', sans-serif;
        }

        textarea {
            width: 100%;
            border: 1px solid var(--search-border-color);
            border-radius: 10px;
            padding: 12px 15px;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            color: var(--font-color);
            transition: border-color 0.3s, box-shadow 0.3s;
            resize: vertical;
            background-color: #fff;
        }

        textarea:focus {
            outline: none;
            border-color: var(--main-color);
            box-shadow: 0 0 0 0.25rem rgba(128, 184, 210, 0.25);
        }

        small{
            display: block;
            font-size: 11px;
            font-family: 'Inter', sans-serif;
            color: var(--font2-color);         
            margin-bottom: 20px;
        }

        .btn-submit {
            background-color: var(--main-color);
            color: #FFFFFF;
            width: 100%;
            padding: 11px;
            border: none;
            border-radius: 25px;
            font-weight: 600;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            margin-top: 25px;
            transition: var(--transition);
            cursor: pointer;
        }

        .btn-submit:hover {
            background-color: var(--btn-hover);
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: var(--font2-color);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: var(--transition);
        }

        .back-link:hover {
            color: var(--main-color);
        }
    </style>
</head>
<body>
    <?php include_once 'include/header.php'; ?>
    <div class="main-container">
        <div class="box">

        <?php if ($product_id > 0): ?>
        <!-- Review Form -->
            <h2>Write a Review</h2>
            <p class="order-no">Order No: #<?php echo htmlspecialchars($order_info['ORDER_NO']); ?></p>

            <!-- order details -->
            <div class="order-info-box">
                <img src="<?php echo !empty($product['COVER_IMAGE']) ? htmlspecialchars($product['COVER_IMAGE']) : 'icon/default_cake.png'; ?>"
                     alt="<?php echo htmlspecialchars($product['PRODUCT_NAME']); ?>">
                <p><strong><?php echo htmlspecialchars($product['PRODUCT_NAME']); ?></strong></p>
            </div>

            <!-- Review -->
            <form method="post" enctype="multipart/form-data" id="reviewForm">
                <input type="hidden" name="order_id"   value="<?php echo $order_id; ?>">
                <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                <label>Rating:</label>
                <div class="star-rating">
                    <span class="star" data-value="1">★</span>
                    <span class="star" data-value="2">★</span>
                    <span class="star" data-value="3">★</span>
                    <span class="star" data-value="4">★</span>
                    <span class="star" data-value="5">★</span>
                </div>
                <input type="hidden" name="rating" id="rating-value" value="0">

                <label>Your Comments:</label>
                <textarea name="comment" rows="4" placeholder="How was the cake? Share your thoughts..." maxlength="300"></textarea>
                <small>Max 300 characters</small>

                <label class="mt-3">Upload Photo (Optional):</label>
                <input type="file" name="photo" class="form-control" accept="image/*">

                <button type="submit" class="btn-submit">Submit Review</button>
            </form>

            <a href="review.php?order_id=<?php echo $order_id; ?>" class="back-link"> Back to Product List</a>

        <?php else: ?>
        <!-- Product List  -->
            <h2>Review Your Order</h2>
            <p class="order-no">Order No: #<?php echo htmlspecialchars($order_info['ORDER_NO']); ?></p>

            <?php foreach ($items as $item): ?>
                <div class="product-card">
                    <img src="<?php echo !empty($item['COVER_IMAGE']) ? htmlspecialchars($item['COVER_IMAGE']) : 'icon/default_cake.png'; ?>"
                         alt="<?php echo htmlspecialchars($item['PRODUCT_NAME']); ?>">

                    <span class="product-name"><?php echo htmlspecialchars($item['PRODUCT_NAME']); ?></span>

                    <?php if ($item['IS_REVIEWED'] > 0): ?>
                        <span class="btn-reviewed">Reviewed</span>
                    <?php else: ?>
                        <a href="review.php?order_id=<?php echo $order_id; ?>&product_id=<?php echo $item['PRODUCT_ID']; ?>" class="btn-write-review">
                            Write Review
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <a href="order_history.php" class="back-link">Back to Order History</a>

        <?php endif; ?>

        </div>
    </div>
    <?php include_once 'include/footer.php'; ?>

    <?php if ($product_id > 0): ?>
    <script>
        const stars = document.querySelectorAll('.star');
        const ratingInput = document.getElementById('rating-value');

        stars.forEach(star => {
            // Hover effect: highlight stars up to the hover position
            star.addEventListener('mouseover', () => {
                const val = parseInt(star.getAttribute('data-value'));
                stars.forEach((s, idx) => s.classList.toggle('active', idx < val));
            });

            // Click effect: lock the rating
            star.addEventListener('click', () => {
                const val = star.getAttribute('data-value');
                ratingInput.value = val;
                stars.forEach((s, idx) => s.classList.toggle('active', idx < parseInt(val)));
            });
        });

        // Reset to user's selection when mouse leaves the star container
        document.querySelector('.star-rating').addEventListener('mouseleave', () => {
            const selected = parseInt(ratingInput.value);
            stars.forEach((s, idx) => s.classList.toggle('active', idx < selected));
        });

        // Form validation before submission
        document.getElementById('reviewForm').onsubmit = function(e) {
            if (ratingInput.value == "0") {
                e.preventDefault();
                alert('Please select a star rating!');
            }
        };
    </script>
    <?php endif; ?>
</body>
</html>