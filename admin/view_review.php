<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get review id
$review_id = $_GET['review_id'] ?? null;

if (!$review_id) {
    die("Invalid Review ID");
}

// Get review details
$stmt = $conn->prepare("
    SELECT 
        r.*,
        c.CUSTOMER_NAME,
        o.ORDER_NO,
        o.ORDER_ID,
        oi.PRODUCT_NAME_SNAPSHOT

    FROM review r
    LEFT JOIN customer c 
        ON r.CUSTOMER_ID = c.CUSTOMER_ID
    LEFT JOIN orders o 
        ON r.ORDER_ID = o.ORDER_ID
    LEFT JOIN order_item oi 
        ON oi.ORDER_ID = r.ORDER_ID 
       AND oi.PRODUCT_ID = r.PRODUCT_ID
    WHERE r.REVIEW_ID = ?
");

$stmt->bind_param("i", $review_id);
$stmt->execute();
$review = $stmt->get_result()->fetch_assoc();

if (!$review) {
    die("Review not found");
}

// GET REPLY (ADMIN RESPONSE)
$stmt2 = $conn->prepare("
    SELECT 
        rply.*,
        a.ADMIN_NAME
    FROM review_reply rply
    LEFT JOIN admin a 
        ON rply.ADMIN_ID = a.ADMIN_ID
    WHERE rply.REVIEW_ID = ?
    AND rply.IS_DELETED = 0
    ORDER BY rply.CREATED_AT DESC
");

$stmt2->bind_param("i", $review_id);
$stmt2->execute();
$replies = $stmt2->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Review Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.rating-stars {
    color: #f59e0b;
    font-size: 18px;
    letter-spacing: 2px;
}

.rating-number {
    color: #6b7280;
    font-size: 14px;
    margin-left: 6px;
    letter-spacing: 0;
}

.reply-card {
    margin-bottom: 12px;
}

.links {
    text-decoration: none;
    color: #4d4d4d;
    font-weight: 600;
}

.links:hover {
    text-decoration: underline;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_review.php" class="form-back-link" title="Go Back To Review Page">← Back</a>

    <div class="container">

        <div class="header-top">
            <div class="title">Review Details</div>
        </div>

        <div class="section">
            <h3>Basic Info</h3>

            <div class="row">
                <span class="label">Order No:</span> 
                <a href="view_order.php?order_id=<?= $review['ORDER_ID'] ?>" class="links">
                    #<?= htmlspecialchars($review['ORDER_NO']) ?>
                </a>
            </div>

            <div class="row">
                <span class="label">Customer Name:</span> 
                <span><?= htmlspecialchars($review['CUSTOMER_NAME']) ?></span>
            </div>

            <div class="row">
                <span class="label">Product Name:</span>
                <a href="view_product.php?product_id=<?= $review['PRODUCT_ID'] ?>" class="links">
                    <?= htmlspecialchars($review['PRODUCT_NAME_SNAPSHOT']) ?>
                </a>
            </div>

            <div class="row">
                <span class="label">Status:</span> 
                <span class="badge status-<?= strtolower($review['REVIEW_STATUS']) ?>">
                    <?= htmlspecialchars($review['REVIEW_STATUS']) ?>
                </span>
            </div>
        </div>

        <div class="section">
            <h3>Review Info</h3>

            <div class="row">
                <span class="label">Rating:</span>

                <span class="rating-stars">
                    <?php
                        $rating = (int)$review['RATING'];

                        for ($i = 1; $i <= 5; $i++) {
                            if ($i <= $rating) {
                               echo "★";
                            } else {
                               echo "☆";
                            }
                        }
                    ?>
                    <span class="rating-number">
                        (<?= $rating ?>/5)
                    </span>
                </span>
            </div>

            <div class="row">
                <span class="label">Comment:</span>
                <span> <?= !empty(trim($review['COMMENTS'] ?? '')) ? nl2br(htmlspecialchars($review['COMMENTS'])) : 'N/A' ?>  </span>
            </div>

            <div class="row">
                <span class="label">Attached Image:</span>
                <span>
                    <?php if (!empty($review['REVIEW_IMAGE'])): ?>
                        <img src="../<?= $review['REVIEW_IMAGE'] ?>" width="200">
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Date:</span>
                <span><?= htmlspecialchars($review['CREATED_AT']) ?></span>
            </div>
        </div>
        
        <div class="section">
            <h3>Replies</h3>

            <?php if ($replies->num_rows > 0): ?>
                <?php $index = 1; while ($r = $replies->fetch_assoc()): ?>

                <div class="reply-card">
                    <div class="row">
                        <p>Reply <?= $index ?></p>
                    </div>

                    <div class="row">
                        <span class="label">Reply Content:</span> 
                        <span><?= nl2br(htmlspecialchars($r['REPLY_TEXT'])) ?></span>
                    </div>

                    <div class="row">
                        <span class="label">Date:</span> 
                        <span><?= $r['CREATED_AT'] ?></span>
                    </div>
                </div>

                <?php $index++; ?>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No replies yet.</p>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>