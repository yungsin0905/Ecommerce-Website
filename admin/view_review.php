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

if (!$review_id || !is_numeric($review_id)) {
    die("Invalid Review ID");
}
$review_id = (int)$review_id;

// Get review details
$stmt = $conn->prepare("
    SELECT 
        r.*,
        c.CUSTOMER_NAME,
        c.CUSTOMER_EMAIL,
        o.ORDER_NO,
        o.ORDER_ID,
        p.PRODUCT_NAME,
        oi.PRODUCT_NAME_SNAPSHOT

    FROM review r
    LEFT JOIN customer c 
        ON r.CUSTOMER_ID = c.CUSTOMER_ID
    LEFT JOIN orders o 
        ON r.ORDER_ID = o.ORDER_ID
    LEFT JOIN product p
        ON r.PRODUCT_ID = p.PRODUCT_ID
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

$productDisplayName = !empty($review['PRODUCT_NAME_SNAPSHOT']) ? $review['PRODUCT_NAME_SNAPSHOT'] : (!empty($review['PRODUCT_NAME']) ? $review['PRODUCT_NAME'] : 'N/A');

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
    <title>Review Details | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
:root {
    --bg-page: #f4f5f8;
    --card-bg: #ffffff;
    --text-dark: #111827;
    --text-muted: #71717a;
    --text-light: #9ca3af;
    --accent-lime: #d4f938;
    --accent-lime-hover: #c4ec29;
    --accent-lime-subtle: #f4fde6;
    --dark-pill: #18181b;
    --dark-pill-hover: #27272a;
    --border-card: #edf0f3;
    --border-subtle: #f1f3f6;
    --shadow-soft: 0 4px 20px rgba(17, 24, 39, 0.035);
    --shadow-hover: 0 10px 28px rgba(17, 24, 39, 0.07);
    --radius-card: 20px;
    --radius-pill: 9999px;
    --font-heading: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'Inter', -apple-system, sans-serif;
}

body {
    background-color: var(--bg-page);
    font-family: var(--font-body);
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}

.view-wrapper {
    max-width: 900px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

/* Back Link */
.form-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    margin-bottom: 20px;
}

.form-back-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateX(-2px);
    text-decoration: none;
}

/* Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.dash-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
}

.dash-title-badge {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime);
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.badge-arrow i {
    color: var(--accent-lime);
}

.dash-title {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 26px;
    display: block;
}

.dash-header-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    box-shadow: var(--shadow-soft);
}

/* View Cards */
.view-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    box-shadow: var(--shadow-soft);
    padding: 24px;
    margin-bottom: 20px;
}

.view-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-subtle);
}

.view-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}

.view-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 18px;
}

@media (max-width: 650px) {
    .view-grid {
        grid-template-columns: 1fr;
    }
}

.view-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.view-item.full-width {
    grid-column: 1 / -1;
}

.view-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.view-val {
    font-size: 14.5px;
    font-weight: 600;
    color: var(--text-dark);
}

.code-pill {
    font-family: monospace;
    font-weight: 700;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 3px 10px;
    border-radius: 6px;
    font-size: 13px;
    color: var(--text-dark);
    display: inline-block;
    text-decoration: none;
    transition: all 0.2s;
}

.code-pill:hover {
    background: var(--dark-pill);
    color: #ffffff;
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.badge.status-unhide {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge.status-hide {
    background: #fee2e2;
    color: #dc2626;
    border: 1px solid #fecaca;
}

.rating-pill-lg {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #fef3c7;
    color: #b45309;
    padding: 6px 14px;
    border-radius: var(--radius-pill);
    font-weight: 700;
    font-size: 14px;
}

.rating-stars {
    color: #f59e0b;
    font-size: 16px;
    letter-spacing: 1px;
}

.comment-box {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 12px;
    padding: 16px;
    font-size: 14px;
    color: var(--text-dark);
    line-height: 1.6;
}

.review-img-preview {
    max-width: 280px;
    border-radius: 14px;
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    display: block;
    margin-top: 6px;
}

/* Reply Cards */
.reply-card {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.reply-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.reply-author {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dark);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.reply-body {
    font-size: 14px;
    color: var(--text-dark);
    line-height: 1.5;
    margin: 0;
}

.reply-time {
    font-size: 12px;
    color: var(--text-muted);
}
</style>
</head>

<body>

<div class="view-wrapper">
    <a href="manage_review.php" class="form-back-link" title="Go Back To Manage Reviews">
        <i class="bi bi-arrow-left"></i> Back to Reviews
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-star-half"></i>
                <span class="badge-arrow"><i class="bi bi-arrow-up-right"></i></span>
            </div>
            <div class="dash-title">
                Review Details
                <strong>#<?= htmlspecialchars($review['REVIEW_ID']) ?></strong>
            </div>
        </div>

        <div class="dash-header-controls">
            <div class="live-pill">
                <span class="badge status-<?= strtolower($review['REVIEW_STATUS']) ?>">
                    <i class="bi bi-<?= strtolower($review['REVIEW_STATUS']) === 'unhide' ? 'eye-fill' : 'eye-slash-fill' ?>"></i>
                    <?= htmlspecialchars($review['REVIEW_STATUS']) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Basic & Order Info -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-info-circle"></i> Customer & Order Details</h3>
        </div>

        <div class="view-grid">
            <div class="view-item">
                <span class="view-label">Order Number</span>
                <span class="view-val">
                    <?php if (!empty($review['ORDER_NO'])): ?>
                        <a href="view_order.php?order_id=<?= (int)$review['ORDER_ID'] ?>" class="code-pill">
                            #<?= htmlspecialchars($review['ORDER_NO']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted)">N/A</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="view-item">
                <span class="view-label">Customer Name</span>
                <span class="view-val"><?= htmlspecialchars($review['CUSTOMER_NAME'] ?? 'Guest Customer') ?></span>
            </div>

            <div class="view-item">
                <span class="view-label">Product Reviewed</span>
                <span class="view-val">
                    <?php if (!empty($review['PRODUCT_ID'])): ?>
                        <a href="view_product.php?product_id=<?= (int)$review['PRODUCT_ID'] ?>" class="code-pill">
                            <?= htmlspecialchars($productDisplayName) ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($productDisplayName) ?>
                    <?php endif; ?>
                </span>
            </div>

            <div class="view-item">
                <span class="view-label">Review Date</span>
                <span class="view-val"><?= date("d M Y, h:i A", strtotime($review['CREATED_AT'])) ?></span>
            </div>
        </div>
    </div>

    <!-- Review Content -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-chat-quote"></i> Review Content</h3>
        </div>

        <div class="view-grid">
            <div class="view-item full-width">
                <span class="view-label">Rating Given</span>
                <div style="margin-top: 4px;">
                    <span class="rating-pill-lg">
                        <span class="rating-stars">
                            <?php
                                $rating = (int)$review['RATING'];
                                for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $rating ? "★" : "☆";
                                }
                            ?>
                        </span>
                        (<?= $rating ?> / 5.0)
                    </span>
                </div>
            </div>

            <div class="view-item full-width">
                <span class="view-label">Customer Comment</span>
                <div class="comment-box" style="margin-top: 4px;">
                    <?= !empty(trim($review['COMMENTS'] ?? '')) ? nl2br(htmlspecialchars($review['COMMENTS'])) : '<span style="color:var(--text-muted); font-style: italic;">No written comment provided.</span>' ?>
                </div>
            </div>

            <?php if (!empty($review['REVIEW_IMAGE'])): ?>
            <div class="view-item full-width">
                <span class="view-label">Attached Photo</span>
                <a href="../<?= htmlspecialchars($review['REVIEW_IMAGE']) ?>" target="_blank">
                    <img src="../<?= htmlspecialchars($review['REVIEW_IMAGE']) ?>" alt="Review Attachment" class="review-img-preview">
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Replies -->
    <div class="view-card">
        <div class="view-card-header">
            <h3 class="view-card-title"><i class="bi bi-reply-all"></i> Responses & Replies (<?= $replies->num_rows ?>)</h3>
        </div>

        <?php if ($replies->num_rows > 0): ?>
            <?php while ($r = $replies->fetch_assoc()): ?>
                <div class="reply-card">
                    <div class="reply-header">
                        <span class="reply-author">
                            <i class="bi bi-shield-check" style="color:#15803d;"></i>
                            <?= htmlspecialchars($r['ADMIN_NAME'] ?? 'Admin') ?>
                        </span>
                        <span class="reply-time"><i class="bi bi-clock"></i> <?= date("d M Y, h:i A", strtotime($r['CREATED_AT'])) ?></span>
                    </div>
                    <p class="reply-body"><?= nl2br(htmlspecialchars($r['REPLY_TEXT'])) ?></p>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 13.5px;">
                <i class="bi bi-chat-left-dots" style="font-size: 26px; display: block; margin-bottom: 6px; color: var(--text-light);"></i>
                No replies have been posted for this review yet.
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>