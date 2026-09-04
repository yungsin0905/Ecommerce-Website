<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get product id
$productId = $_GET['product_id'] ?? null;

if (!$productId) {
    die("Invalid Product ID");
}

// Get product info
$sql = "
SELECT p.*, c.CATEGORY_NAME
FROM product p
JOIN category c ON p.CATEGORY_ID = c.CATEGORY_ID
WHERE p.PRODUCT_ID = ? 
AND p.IS_DELETED = 0
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Error: " . $conn->error);
}

$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    die("Product not found");
}

// Get extra images
$imageSql = "
SELECT * FROM product_images
WHERE PRODUCT_ID = ? AND IS_DELETED = 0
";

$stmtImg = $conn->prepare($imageSql);
$stmtImg->bind_param("i", $productId);
$stmtImg->execute();
$images = $stmtImg->get_result();

// Get product variants
$variantSql = "
SELECT * 
FROM product_variant
WHERE PRODUCT_ID = ? 
AND IS_DELETED = 0
";

$stmtVar = $conn->prepare($variantSql);
$stmtVar->bind_param("i", $productId);
$stmtVar->execute();
$variants = $stmtVar->get_result();

$activeVariantSql = "
SELECT * 
FROM product_variant
WHERE PRODUCT_ID = ? 
AND IS_DELETED = 0
AND VARIANT_STATUS = 'Active'
";

$stmtAll = $conn->prepare($activeVariantSql);
$stmtAll->bind_param("i", $productId);
$stmtAll->execute();
$activeVariants = $stmtAll->get_result();

// Get assigned addon
$addonSql = "
SELECT a.*
FROM add_on a
JOIN product_addon pa ON a.ADD_ON_ID = pa.ADD_ON_ID
WHERE pa.PRODUCT_ID = ?
AND a.IS_DELETED = 0
";

$stmtAddon = $conn->prepare($addonSql);
$stmtAddon->bind_param("i", $productId);
$stmtAddon->execute();
$addons = $stmtAddon->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Product Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
/* VARIANT TABLE */
.variant,
.performance {
    display: block;
    margin-top: 30px;
}

.variant table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 12px;
    overflow: hidden;
    border-radius: 12px;
    background: #fff;
}

/* header */
.variant th {
    background: #f8fafc;
    padding: 14px 16px;
    text-align: left;
    font-size: 14px;
    font-weight: 600;
    color: #374151;
    border-bottom: 1px solid #e5e7eb;
}

/* body */
.variant td {
    padding: 14px 16px;
    font-size: 14px;
    color: #4b5563;
    border-bottom: 1px solid #f1f5f9;
}

/* zebra */
.variant tbody tr:nth-child(even) {
    background: #fcfcfc;
}

/* hover */
.variant tbody tr:hover {
    background: #f8fafc;
    transition: 0.2s;
}

/* last row border remove */
.variant tr:last-child td {
    border-bottom: none;
}

.tag{
    background:#f3f4f6;
    border:1px solid #e5e7eb;
    padding:6px 12px;
    border-radius:999px;
    font-size:13px;
    color:#374151;
    transition:0.2s;
    cursor:default;
}

.addon {
    margin-top: 30px;
}

.alert-warning {
    display: block;
    padding: 12px 16px;
    margin: 15px 0;
    border-radius: 8px;
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
}

.addon-link{
    text-decoration:none;
    display:inline-block;
}

.addon-link:hover{
    background:#e5e7eb;
    transform:translateY(-1px);
}

.row .last {
    border-bottom: none;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_product.php" class="form-back-link" title="Go Back To Product Page">← Back</a>

    <div class="container">

        <div class="header-top">
            <div class="title">Product Details</div>

            <a href="edit_product.php?product_id=<?= $productId ?>" class="edit-btn">
                <i class="bi bi-pencil-square"></i> Edit
            </a>
        </div>

        <?php if ($activeVariants->num_rows == 0): ?>
            <div class="alert-warning">
                ⚠ This product has variants but none are active (not sellable)
            </div>
        <?php endif; ?>

        <!-- Basic info -->
        <div class="row">
            <span class="label">Name:</span>
            <span><?= htmlspecialchars($product['PRODUCT_NAME']) ?></span>
        </div>

        <div class="row">
            <span class="label">Category:</span>
            <span><?= htmlspecialchars($product['CATEGORY_NAME']) ?></span>
        </div>

        <div class="row">
            <span class="label">Status:</span>
            <span class="badge status-<?= strtolower($product['PRODUCT_STATUS']) ?>">
                <?= $product['PRODUCT_STATUS'] ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Cake Writing:</span>
            <span>
                <?= $product['ALLOW_WRITING'] == 1 ? 'Allowed' : 'Not Allowed' ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Description:</span>
            <span><?= !empty($product['PRODUCT_DES']) ? htmlspecialchars($product['PRODUCT_DES']) : 'N/A' ?></span>
        </div>

        <div class="row">
            <span class="label">Ingredients:</span>
            <span><?= !empty($product['INGREDIENTS']) ? htmlspecialchars($product['INGREDIENTS']) : 'N/A' ?></span>
        </div>

        <div class="row">
            <span class="label">Allergen:</span>
            <span><?= !empty($product['INGREDIENTS']) ? htmlspecialchars($product['ALLERGEN']) : 'N/A' ?></span>
        </div>

        <div class="row">
            <span class="label">Cover Image:</span>
            <span>
                <?php if (!empty($product['COVER_IMAGE'])): ?>
                    <img src="<?= $product['COVER_IMAGE'] ?>">
                <?php else: ?>
                    N/A
                <?php endif; ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Extra Image:</span>
            <span>
                <?php if ($images->num_rows > 0): ?>
                    <?php while($img = $images->fetch_assoc()): ?>
                        <img src="<?= $img['IMAGE_PATH'] ?>" style="margin-right:10px;">
                    <?php endwhile; ?>
                <?php else: ?>
                        N/A
                <?php endif; ?>
            </span>
        </div>

        <!-- VARIANTS -->
        <div class="row variant">
            <span class="label">Variants:</span>

            <table>
                <tr>
                    <th>Size</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                </tr>

                <?php if ($variants->num_rows > 0): ?>
                    <?php while($v = $variants->fetch_assoc()): ?>
                        <tr>
                            <td><?= $v['VARIANT_SIZE'] ?> inch</td>
                            <td>RM <?= $v['VARIANT_PRICE'] ?></td>
                            <td><?= $v['VARIANT_STOCK'] ?></td>
                            <td>
                                <span class="badge status-<?= strtolower($v['VARIANT_STATUS']) ?>">
                                    <?= $v['VARIANT_STATUS'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center;">No variants</td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <!-- Assigned ADD-ONS -->
        <div class="row addon">
            <span class="label">Assigned Add-ons:</span>

            <?php if ($addons->num_rows > 0): ?>

                <?php while($a = $addons->fetch_assoc()): ?>
    
                    <a href="view_addon.php?addon_id=<?= $a['ADD_ON_ID'] ?>" class="tag addon-link">
                        <?= htmlspecialchars($a['ADD_ON_NAME']) ?>
                        - RM<?= htmlspecialchars($a['ADD_ON_PRICE']) ?>
                    </a>

                <?php endwhile; ?>

            <?php else: ?>

                <p>No add-ons assigned</p>

            <?php endif; ?>
        </div>

        <!-- Performance -->
        <div class="row performance">
            <span class="label">Performance:</span>

            <div class="row" style="margin-top:10px;">
                <span class="label" style="font-weight: 350;">Total Sales</span> 
                <span><?= $product['SALES_COUNT'] ?> qty</span>
            </div>

            <div class="row last">
                <span class="label" style="font-weight: 350;">Average Rating</span> 
                <span><?= $product['AVG_RATING'] ?> ★</span>
            </div>
        </div>

    </div>

</body>
</html>