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

if (!$categoryId) {
    die("Invalid Category ID");
}

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

// Get products that under this category
$productSql = "
SELECT 
    PRODUCT_ID,
    PRODUCT_NAME,
    PRODUCT_STATUS,
    COVER_IMAGE
FROM product
WHERE CATEGORY_ID = ?
AND IS_DELETED = 0
ORDER BY PRODUCT_NAME ASC
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
    <title>View Category Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.product-list {
    margin-top: 30px;
}

.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill,minmax(100px,1fr));
    gap: 8px;
    margin-top: 6px;
}

.product-card {
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    overflow: hidden;
    background: #fff;
    max-width: 110px;
}

.product-card img {
    width: 100%;
    height: 55px;
    object-fit: cover;
    display: block;
}

.product-info {
    padding: 4px;
}

.product-name {
    font-size: 10px;
    font-weight: 600;
    margin-bottom: 1px;
    line-height: 1.1;
}

.product-meta {
    font-size: 8px;
    margin-bottom: 0;
    color: #6b7280;
}

.product-link {
    font-size: 8px;
    margin-top: 1px;
    color: blue;
    text-decoration: none;
}

.product-link:hover {
    text-decoration: underline;
}

.product-card .badge {
    font-size: 7px;
    padding: 1px 3px;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_category.php" class="form-back-link" title="Go Back To Category Page">← Back</a>

    <div class="container">

        <div class="header-top">
            <div class="title">Category Details</div>

            <a href="edit_category.php?category_id=<?= $categoryId ?>" class="edit-btn">
                <i class="bi bi-pencil-square"></i> Edit
            </a>
        </div>

        <div class="row">
            <span class="label">Name:</span>
            <div class="value">
                <?= htmlspecialchars($category['CATEGORY_NAME']) ?>
            </div>
        </div>

        <div class="row">
            <span class="label">Status:</span>
            <div class="value">
                <?php if ($category['CATEGORY_STATUS'] == 'Active') { ?>
                    <span class="badge status-active">Active</span>
                <?php } else { ?>
                    <span class="badge status-inactive">Inactive</span>
                <?php } ?>
            </div>
        </div>

        <div class="row">
            <span class="label">Description:</span>
            <div class="value">
                <?= !empty($category['CATEGORY_DES']) ? nl2br(htmlspecialchars($category['CATEGORY_DES'])) : "N/A"?>
            </div>
        </div>

        <div class="row">
            <span class="label">Image:</span>
            <div class="value">

                <?php if (!empty($category['CATEGORY_IMAGE'])) { ?>
                    <img src="<?= $category['CATEGORY_IMAGE'] ?>">
                <?php } else { ?>
                    <span>N/A</span>
                <?php } ?>

            </div>
        </div>

        <!-- PRODUCTS UNDER THIS CATEGORY -->
        <div class="row product-list">
            <span class="label">Products:</span>

            <div class="value" style="width:100%;">

                <?php if ($products->num_rows > 0): ?>

                    <div class="product-grid">

                        <?php while($p = $products->fetch_assoc()): ?>

                            <div class="product-card">

                                <?php if (!empty($p['COVER_IMAGE'])): ?>

                                    <img src="<?= htmlspecialchars($p['COVER_IMAGE']) ?>">

                                <?php else: ?>

                                    <div style="height:180px;display:flex;align-items:center;justify-content:center;background:#f3f4f6;">
                                        No Image
                                    </div>

                                <?php endif; ?>

                                <div class="product-info">

                                    <div class="product-name">
                                        <?= htmlspecialchars($p['PRODUCT_NAME']) ?>
                                    </div>

                                    <div style="margin-top:8px;">
                                        <span class="badge status-<?= strtolower($p['PRODUCT_STATUS']) ?>">
                                            <?= $p['PRODUCT_STATUS'] ?>
                                        </span>
                                    </div>

                                    <a href="view_product.php?product_id=<?= $p['PRODUCT_ID'] ?>" class="product-link">
                                        View Product →
                                    </a>

                                </div>

                            </div>

                        <?php endwhile; ?>

                    </div>

                <?php else: ?>

                    <span>No products under this category</span>

                <?php endif; ?>

            </div>
        </div>

    </div>

</div>

</body>
</html>