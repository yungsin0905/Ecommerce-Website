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
$productId = $_GET['product_id'] ?? 0;
if (!$productId || !is_numeric($productId)) die("Invalid Product ID");
$productId = (int)$productId;

$stmt = $conn->prepare("SELECT * FROM product WHERE PRODUCT_ID = ? AND IS_DELETED = 0");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) die("Product not found");

$stmt = $conn->prepare("
    SELECT c.CATEGORY_NAME, c.PARENT_ID
    FROM product_category pc
    JOIN category c ON c.CATEGORY_ID = pc.CATEGORY_ID
    WHERE pc.PRODUCT_ID = ? AND c.IS_DELETED = 0
    ORDER BY (c.PARENT_ID IS NOT NULL), c.CATEGORY_NAME
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("
    SELECT IMAGE_PATH FROM product_images
    WHERE PRODUCT_ID = ? AND IS_DELETED = 0
    GROUP BY IMAGE_PATH
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("
    SELECT v.*,
           GROUP_CONCAT(pov.VALUE_NAME ORDER BY po.OPTION_ORDER, pov.VALUE_ORDER SEPARATOR ' / ') AS OPTION_TEXT
    FROM product_variant v
    LEFT JOIN variant_option_value vov ON vov.VARIANT_ID = v.VARIANT_ID
    LEFT JOIN product_option_value pov ON pov.OPTION_VALUE_ID = vov.OPTION_VALUE_ID
    LEFT JOIN product_option po ON po.OPTION_ID = pov.OPTION_ID
    WHERE v.PRODUCT_ID = ? AND v.IS_DELETED = 0
    GROUP BY v.VARIANT_ID ORDER BY v.VARIANT_ID
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$variants = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$activeCount = count(array_filter($variants, fn($v) => $v['VARIANT_STATUS'] === 'Active'));

$stmt = $conn->prepare("
    SELECT pa.ADDON_PRODUCT_ID, pa.ADDON_PRICE,
           p.PRODUCT_NAME, v.VARIANT_LABEL, v.VARIANT_PRICE,
           (SELECT GROUP_CONCAT(pov.VALUE_NAME ORDER BY po.OPTION_ORDER SEPARATOR ' / ')
              FROM variant_option_value vov
              JOIN product_option_value pov ON pov.OPTION_VALUE_ID = vov.OPTION_VALUE_ID
              JOIN product_option po ON po.OPTION_ID = pov.OPTION_ID
             WHERE vov.VARIANT_ID = pa.ADDON_VARIANT_ID) AS OPTION_TEXT
    FROM product_addon pa
    JOIN product p ON p.PRODUCT_ID = pa.ADDON_PRODUCT_ID
    LEFT JOIN product_variant v ON v.VARIANT_ID = pa.ADDON_VARIANT_ID
    WHERE pa.HOST_PRODUCT_ID = ? AND pa.IS_DELETED = 0
    ORDER BY pa.SORT_ORDER
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$addons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$packing  = json_decode($product['PACKING_LIST'] ?? '[]', true) ?: [];
$features = json_decode($product['FEATURES'] ?? '[]', true) ?: [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Product Details | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
    <link rel="stylesheet" href="css/description.css?v=1">
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
    max-width: 1100px;
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

/* Alert */
.alert-warning-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    margin-bottom: 24px;
    border-radius: 14px;
    background: #fffbeb;
    border: 1px solid #fef3c7;
    color: #b45309;
    font-size: 13.5px;
    font-weight: 600;
}

.alert-warning-card i {
    font-size: 18px;
    color: #f59e0b;
}

/* Detail Card */
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
    grid-template-columns: 180px 1fr;
    align-items: flex-start;
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
    padding-top: 2px;
}

.detail-value {
    font-size: 14px;
    font-weight: 500;
    color: var(--text-dark);
}

/* Badges & Tags */
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

.cat-tags-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tag-pill {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 5px 12px;
    border-radius: var(--radius-pill);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-dark);
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

/* Gallery & Media */
.image-gallery {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: center;
}

.image-thumb-card {
    border-radius: 14px;
    border: 1px solid var(--border-card);
    overflow: hidden;
    background: var(--bg-page);
    display: inline-block;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.image-thumb-card:hover {
    transform: scale(1.03);
    box-shadow: var(--shadow-hover);
}

.image-thumb-card img {
    display: block;
    height: 100px;
    width: 100px;
    object-fit: cover;
}

.cover-img-preview {
    max-width: 180px;
    border-radius: 14px;
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    display: block;
}

/* Table */
.table-responsive {
    width: 100%;
    overflow-x: auto;
    border-radius: 14px;
    border: 1px solid var(--border-card);
}

.mgmt-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: #ffffff;
}

.mgmt-table th {
    background: #fafbfc;
    color: var(--text-muted);
    padding: 13px 16px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border-bottom: 1px solid var(--border-card);
    text-align: left;
}

.mgmt-table td {
    padding: 13px 16px;
    border-bottom: 1px solid var(--border-subtle);
    font-size: 13.5px;
    color: var(--text-dark);
    vertical-align: middle;
}

.mgmt-table tbody tr:hover {
    background: #fbfcfd;
}

.mgmt-table tbody tr:last-child td {
    border-bottom: none;
}

.price-strike {
    color: var(--text-muted);
    margin-right: 6px;
    font-size: 12.5px;
}

.price-active {
    font-weight: 700;
    color: var(--text-dark);
}

.stock-pill {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    padding: 3px 10px;
    font-weight: 700;
    font-size: 12px;
    color: var(--text-dark);
    display: inline-block;
}

/* Addons List */
.addon-cards-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.addon-pill-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    transition: all 0.2s ease;
}

.addon-pill-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(24, 24, 27, 0.15);
}

.addon-pill-link .addon-price {
    background: #ffffff;
    color: var(--text-dark);
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 700;
    border: 1px solid var(--border-card);
    transition: all 0.2s;
}

.addon-pill-link:hover .addon-price {
    background: var(--accent-lime);
    color: var(--text-dark);
    border-color: var(--accent-lime);
}

/* Features & Packing List */
.section-item-group {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 16px 20px;
    margin-bottom: 12px;
}

.section-item-group:last-child {
    margin-bottom: 0;
}

.section-item-title {
    font-family: var(--font-heading);
    font-weight: 700;
    font-size: 14px;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.section-item-note {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 8px;
}

.clean-list {
    margin: 0;
    padding-left: 20px;
    color: var(--text-dark);
    font-size: 13.5px;
    line-height: 1.6;
}

.clean-list li {
    margin-bottom: 4px;
}

/* Stat Box */
.stat-box-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.stat-box {
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 16px;
    padding: 18px 22px;
}

.stat-box-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin-bottom: 6px;
}

.stat-box-val {
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 800;
    color: var(--text-dark);
}

.rating-star-badge {
    color: #f59e0b;
    font-size: 18px;
}
</style>
</head>

<body>

<div class="view-wrapper">

    <a href="manage_product.php" class="form-back-link" title="Go Back To Product Page">
        <i class="bi bi-arrow-left"></i> Back to Products
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-box-seam"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Product Details</strong> View complete product specifications</h1>
            </div>
        </div>

        <a href="edit_product.php?product_id=<?= $productId ?>" class="edit-btn-pill">
            <i class="bi bi-pencil-square"></i> Edit Product
        </a>
    </div>

    <?php if ($activeCount == 0): ?>
        <div class="alert-warning-card">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>This product has variants but none are active (it will not be available for sale).</span>
        </div>
    <?php endif; ?>

    <!-- BASIC INFORMATION -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-info-circle"></i> Basic Information
            <span class="card-badge">ID #<?= $productId ?></span>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Product Name</div>
                <div class="detail-value" style="font-weight:700; font-size:15px;"><?= htmlspecialchars($product['PRODUCT_NAME']) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Product Code</div>
                <div class="detail-value"><span class="tag-pill" style="font-family:monospace;"><?= htmlspecialchars($product['PRODUCT_CODE']) ?></span></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Brand</div>
                <div class="detail-value"><?= htmlspecialchars($product['BRAND'] ?: 'N/A') ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Warranty</div>
                <div class="detail-value"><?= $product['WARRANTY'] !== null ? (int)$product['WARRANTY'] . ' months' : 'N/A' ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Categories</div>
                <div class="detail-value">
                    <div class="cat-tags-wrap">
                        <?php foreach ($categories as $c): ?>
                            <span class="tag-pill"><i class="bi bi-folder"></i> <?= htmlspecialchars($c['CATEGORY_NAME']) ?></span>
                        <?php endforeach; ?>
                        <?php if (!$categories) echo '<span style="color:var(--text-muted);">N/A</span>'; ?>
                    </div>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="badge status-<?= strtolower($product['PRODUCT_STATUS']) ?>">
                        <?= htmlspecialchars($product['PRODUCT_STATUS']) ?>
                    </span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Description</div>
                <div class="detail-value">
                    <div class="description-content" style="background:var(--bg-page); padding:16px 20px; border-radius:14px; border:1px solid var(--border-card);">
                        <?= $product['PRODUCT_DES'] ?: '<span style="color:var(--text-muted);">No description provided</span>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MEDIA / IMAGES -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-images"></i> Product Media
            <span class="card-badge"><?= (!empty($product['COVER_IMAGE']) ? 1 : 0) + count($images) ?> Images</span>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Cover Image</div>
                <div class="detail-value">
                    <?php if (!empty($product['COVER_IMAGE'])): ?>
                        <img src="<?= htmlspecialchars($product['COVER_IMAGE']) ?>" class="cover-img-preview" alt="Cover Image">
                    <?php else: ?>
                        <span style="color:var(--text-muted);">N/A</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Extra Images</div>
                <div class="detail-value">
                    <?php if ($images): ?>
                        <div class="image-gallery">
                            <?php foreach ($images as $img): ?>
                                <div class="image-thumb-card">
                                    <img src="<?= htmlspecialchars($img['IMAGE_PATH']) ?>" alt="Extra Image">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">No extra images</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- PRODUCT VARIANTS -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-layers"></i> Product Variants
            <span class="card-badge"><?= count($variants) ?> Total (<?= $activeCount ?> Active)</span>
        </div>

        <div class="table-responsive">
            <table class="mgmt-table">
                <thead>
                    <tr>
                        <th>Variant</th>
                        <th>SKU</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($variants): ?>
                        <?php foreach ($variants as $v): ?>
                            <tr>
                                <td style="font-weight:600;"><?= htmlspecialchars($v['OPTION_TEXT'] ?: ($v['VARIANT_LABEL'] ?: 'Default')) ?></td>
                                <td><span style="font-family:monospace; color:var(--text-muted);"><?= htmlspecialchars($v['SKU'] ?: '-') ?></span></td>
                                <td>
                                    <?php if ($v['SALE_PRICE'] !== null && $v['SALE_PRICE'] !== ''): ?>
                                        <s class="price-strike">RM <?= number_format($v['VARIANT_PRICE'], 2) ?></s>
                                        <span class="price-active">RM <?= number_format($v['SALE_PRICE'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="price-active">RM <?= number_format($v['VARIANT_PRICE'], 2) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="stock-pill"><?= (int)$v['VARIANT_STOCK'] ?></span>
                                </td>
                                <td>
                                    <span class="badge status-<?= strtolower($v['VARIANT_STATUS']) ?>">
                                        <?= htmlspecialchars($v['VARIANT_STATUS']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; padding:24px; color:var(--text-muted);">No variants defined</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ASSIGNED ADD-ONS -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-puzzle"></i> Assigned Add-ons
            <span class="card-badge"><?= count($addons) ?> Items</span>
        </div>

        <?php if ($addons): ?>
            <div class="addon-cards-grid">
                <?php foreach ($addons as $a):
                    $price = $a['ADDON_PRICE'] ?? $a['VARIANT_PRICE'];
                ?>
                    <a href="view_product.php?product_id=<?= (int)$a['ADDON_PRODUCT_ID'] ?>" class="addon-pill-link">
                        <i class="bi bi-link-45deg"></i>
                        <span><?= htmlspecialchars($a['PRODUCT_NAME']) ?></span>
                        <span style="color:var(--text-muted); font-size:12px;">(<?= htmlspecialchars($a['OPTION_TEXT'] ?: 'Any option') ?>)</span>
                        <?php if ($price !== null): ?>
                            <span class="addon-price">RM <?= number_format($price, 2) ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="color:var(--text-muted); font-size:13.5px;">No add-ons assigned to this product.</div>
        <?php endif; ?>
    </div>

    <!-- FEATURES -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-stars"></i> Product Features
        </div>

        <?php if ($features): ?>
            <div>
                <?php foreach ($features as $g): ?>
                    <div class="section-item-group">
                        <?php if (!empty($g['category'])): ?>
                            <div class="section-item-title"><?= htmlspecialchars($g['category']) ?></div>
                        <?php endif; ?>
                        <ul class="clean-list">
                            <?php foreach ($g['points'] as $p): ?>
                                <?php if (is_string($p)): ?>
                                    <li><?= htmlspecialchars($p) ?></li>
                                <?php else: ?>
                                    <li>
                                        <strong><?= htmlspecialchars($p['text']) ?></strong>
                                        <?php if (!empty($p['sub_points'])): ?>
                                            <ul class="clean-list" style="margin-top:4px;">
                                                <?php foreach ($p['sub_points'] as $sp): ?>
                                                    <li><?= htmlspecialchars($sp) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="color:var(--text-muted); font-size:13.5px;">No product features listed.</div>
        <?php endif; ?>
    </div>

    <!-- PACKING LIST -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-box"></i> Packing List
        </div>

        <?php if ($packing): ?>
            <div>
                <?php foreach ($packing as $g): ?>
                    <div class="section-item-group">
                        <?php if (!empty($g['group'])): ?>
                            <div class="section-item-title"><?= htmlspecialchars($g['group']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($g['note'])): ?>
                            <div class="section-item-note"><?= htmlspecialchars($g['note']) ?></div>
                        <?php endif; ?>
                        <ul class="clean-list">
                            <?php foreach ($g['items'] as $it): ?>
                                <li><strong><?= (int)$it['qty'] ?> ×</strong> <?= htmlspecialchars($it['item']) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($g['image'])): ?>
                            <div style="margin-top:12px;">
                                <img src="<?= htmlspecialchars($g['image']) ?>" style="max-width:240px; border-radius:12px; border:1px solid var(--border-card);">
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="color:var(--text-muted); font-size:13.5px;">No packing list specifications provided.</div>
        <?php endif; ?>
    </div>

    <!-- PERFORMANCE -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-graph-up-arrow"></i> Performance
        </div>

        <div class="stat-box-row">
            <div class="stat-box">
                <div class="stat-box-label">Total Sales</div>
                <div class="stat-box-val"><?= (int)$product['SALES_COUNT'] ?> <span style="font-size:14px; font-weight:500; color:var(--text-muted);">qty</span></div>
            </div>

            <div class="stat-box">
                <div class="stat-box-label">Average Rating</div>
                <div class="stat-box-val">
                    <?= $product['SALES_COUNT'] > 0 || (float)$product['AVG_RATING'] > 0
                        ? number_format((float)$product['AVG_RATING'], 1) . ' <span class="rating-star-badge">★</span>'
                        : '<span style="font-size:14px; font-weight:500; color:var(--text-muted);">No ratings yet</span>' ?>
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>