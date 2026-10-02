<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get category id
$categoryId = $_GET['category_id'] ?? null;

if (!$categoryId || !is_numeric($categoryId)) {
    die("Invalid Category ID");
}
$categoryId = (int)$categoryId;

// Get category info
$stmt = $conn->prepare("
    SELECT *
    FROM category
    WHERE CATEGORY_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $categoryId);
$stmt->execute();

$category = $stmt->get_result()->fetch_assoc();

if (!$category) {
    die("Category not found");
}

$isChild = !empty($category['PARENT_ID']);

// If this is a sub-category -> get its parent
$parent = null;
if ($isChild) {
    $stmtParent = $conn->prepare("
        SELECT CATEGORY_ID, CATEGORY_NAME
        FROM category
        WHERE CATEGORY_ID = ?
        AND IS_DELETED = 0
    ");
    $stmtParent->bind_param("i", $category['PARENT_ID']);
    $stmtParent->execute();
    $parent = $stmtParent->get_result()->fetch_assoc();
}

// If this is a main category -> get its sub-categories (with product count)
$children = [];
if (!$isChild) {
    $stmtChild = $conn->prepare("
        SELECT c.CATEGORY_ID, c.CATEGORY_NAME, c.CATEGORY_STATUS,
               (SELECT COUNT(*) FROM product_category pc
                  JOIN product pr ON pr.PRODUCT_ID = pc.PRODUCT_ID AND pr.IS_DELETED = 0
                 WHERE pc.CATEGORY_ID = c.CATEGORY_ID) AS PRODUCT_COUNT
        FROM category c
        WHERE c.PARENT_ID = ?
        AND c.IS_DELETED = 0
        ORDER BY c.CATEGORY_NAME ASC
    ");
    $stmtChild->bind_param("i", $categoryId);
    $stmtChild->execute();
    $children = $stmtChild->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get products that under this category
// (same source as manage_category.php product count: product_category)
$productSql = "
SELECT 
    pr.PRODUCT_ID,
    pr.PRODUCT_NAME,
    pr.PRODUCT_STATUS,
    pr.COVER_IMAGE
FROM product_category pc
JOIN product pr ON pr.PRODUCT_ID = pc.PRODUCT_ID
WHERE pc.CATEGORY_ID = ?
AND pr.IS_DELETED = 0
ORDER BY pr.PRODUCT_NAME ASC
";

$stmtProduct = $conn->prepare($productSql);
$stmtProduct->bind_param("i", $categoryId);
$stmtProduct->execute();

$products = $stmtProduct->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Details | Admin</title>
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

/* Page Header */
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

.edit-btn-pill {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-radius: var(--radius-pill);
    padding: 10px 22px;
    font-size: 13.5px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
    transition: all 0.2s ease;
    font-family: var(--font-heading);
}

.edit-btn-pill:hover {
    background: #000000;
    color: var(--accent-lime) !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(24, 24, 27, 0.25);
}

/* Card Boxes */
.detail-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 28px 32px;
    box-shadow: var(--shadow-soft);
    margin-bottom: 24px;
}

.detail-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-subtle);
}

.detail-card-title i {
    color: var(--text-dark);
    font-size: 16px;
}

.detail-card-title .card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-muted);
    margin-left: auto;
}

/* Detail Rows */
.detail-grid {
    display: flex;
    flex-direction: column;
}

.detail-row {
    display: grid;
    grid-template-columns: 160px 1fr;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid var(--border-subtle);
    gap: 16px;
}

.detail-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.detail-row:first-child {
    padding-top: 0;
}

.detail-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.detail-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-dark);
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
}

.badge.status-active {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge.status-inactive {
    background: var(--bg-page);
    color: var(--text-muted);
    border: 1px solid var(--border-card);
}

.type-tag {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-dark);
    display: inline-block;
}

.cat-link {
    color: var(--text-dark);
    text-decoration: none;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: color 0.15s ease;
}

.cat-link:hover {
    color: #2563eb;
    text-decoration: underline;
}

/* Sub-categories List */
.sub-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.sub-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 18px;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    background: var(--bg-page);
    transition: all 0.2s ease;
}

.sub-item:hover {
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: var(--shadow-soft);
}

.sub-item-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sub-item-right {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sub-count {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    background: #ffffff;
    padding: 3px 10px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
}

/* Product Grid */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
    margin-top: 8px;
}

.product-card {
    border: 1px solid var(--border-card);
    border-radius: 16px;
    overflow: hidden;
    background: var(--card-bg);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
}

.product-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--shadow-hover);
    border-color: #cbd5e1;
}

.product-thumb {
    width: 100%;
    height: 120px;
    object-fit: cover;
    background: var(--bg-page);
    display: block;
}

.product-no-img {
    height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--bg-page);
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
}

.product-info {
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    flex: 1;
    gap: 8px;
}

.product-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dark);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.product-bottom {
    margin-top: auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-top: 6px;
}

.product-link {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-dark);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.15s ease;
}

.product-link:hover {
    color: #2563eb;
    transform: translateX(2px);
}

.empty-state {
    padding: 24px;
    text-align: center;
    color: var(--text-muted);
    font-size: 13.5px;
    font-weight: 500;
    background: var(--bg-page);
    border-radius: 14px;
    border: 1px dashed var(--border-card);
}
</style>
</head>

<body>

<div class="view-wrapper">

    <a href="manage_category.php" class="form-back-link" title="Go Back To Category Page">
        <i class="bi bi-arrow-left"></i> Back to Categories
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-folder2-open"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Category Details</strong> View category information</h1>
            </div>
        </div>

        <a href="edit_category.php?category_id=<?= $categoryId ?>" class="edit-btn-pill">
            <i class="bi bi-pencil-square"></i> Edit Category
        </a>
    </div>

    <!-- GENERAL INFORMATION -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-info-circle"></i> General Information
            <span class="card-badge">ID #<?= $categoryId ?></span>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Name</div>
                <div class="detail-value"><?= htmlspecialchars($category['CATEGORY_NAME']) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Type</div>
                <div class="detail-value">
                    <span class="type-tag"><?= $isChild ? "Sub-category" : "Main Category" ?></span>
                </div>
            </div>

            <?php if ($isChild): ?>
            <div class="detail-row">
                <div class="detail-label">Parent Category</div>
                <div class="detail-value">
                    <?php if ($parent): ?>
                        <a class="cat-link" href="view_category.php?category_id=<?= (int)$parent['CATEGORY_ID'] ?>">
                            <i class="bi bi-folder"></i> <?= htmlspecialchars($parent['CATEGORY_NAME']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">N/A</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <?php if ($category['CATEGORY_STATUS'] === 'Active') { ?>
                        <span class="badge status-active">Active</span>
                    <?php } else { ?>
                        <span class="badge status-inactive">Inactive</span>
                    <?php } ?>
                </div>
            </div>

            <?php if (!empty($category['CATEGORY_IMAGE'])): ?>
            <div class="detail-row">
                <div class="detail-label">Image</div>
                <div class="detail-value">
                    <img src="<?= htmlspecialchars($category['CATEGORY_IMAGE']) ?>" style="max-width:140px; border-radius:12px; border:1px solid var(--border-card);">
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SUB-CATEGORIES (main category only) -->
    <?php if (!$isChild): ?>
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-diagram-3"></i> Sub-categories
            <span class="card-badge"><?= count($children) ?> Total</span>
        </div>

        <?php if ($children): ?>
            <div class="sub-list">
                <?php foreach ($children as $c): ?>
                    <div class="sub-item">
                        <div class="sub-item-left">
                            <i class="bi bi-arrow-return-right" style="color:var(--text-muted);"></i>
                            <a class="cat-link" href="view_category.php?category_id=<?= (int)$c['CATEGORY_ID'] ?>">
                                <?= htmlspecialchars($c['CATEGORY_NAME']) ?>
                            </a>
                        </div>
                        <div class="sub-item-right">
                            <span class="badge status-<?= strtolower($c['CATEGORY_STATUS']) ?>">
                                <?= htmlspecialchars($c['CATEGORY_STATUS']) ?>
                            </span>
                            <span class="sub-count"><?= (int)$c['PRODUCT_COUNT'] ?> products</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No sub-categories under this main category.</div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- PRODUCTS UNDER THIS CATEGORY -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-box-seam"></i> Products in this Category
            <span class="card-badge"><?= $products->num_rows ?> Products</span>
        </div>

        <?php if ($products->num_rows > 0): ?>
            <div class="product-grid">
                <?php while($p = $products->fetch_assoc()): ?>
                    <div class="product-card">
                        <?php if (!empty($p['COVER_IMAGE'])): ?>
                            <img src="<?= htmlspecialchars($p['COVER_IMAGE']) ?>" class="product-thumb" alt="<?= htmlspecialchars($p['PRODUCT_NAME']) ?>">
                        <?php else: ?>
                            <div class="product-no-img">No Image</div>
                        <?php endif; ?>

                        <div class="product-info">
                            <div class="product-name" title="<?= htmlspecialchars($p['PRODUCT_NAME']) ?>">
                                <?= htmlspecialchars($p['PRODUCT_NAME']) ?>
                            </div>

                            <div class="product-bottom">
                                <span class="badge status-<?= strtolower($p['PRODUCT_STATUS']) ?>">
                                    <?= htmlspecialchars($p['PRODUCT_STATUS']) ?>
                                </span>
                                <a href="view_product.php?product_id=<?= (int)$p['PRODUCT_ID'] ?>" class="product-link">
                                    View <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">No products assigned to this category.</div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>