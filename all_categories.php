<?php
session_start();
include 'include/config.php';

// 1. 抓所有母分类
$parent_categories = [];
$parent_result = $conn->query("
    SELECT CATEGORY_ID, CATEGORY_NAME 
    FROM category 
    WHERE PARENT_ID IS NULL AND CATEGORY_STATUS = 'Active' AND IS_DELETED = 0
    ORDER BY CATEGORY_ID ASC
");
while ($row = $parent_result->fetch_assoc()) {
    $parent_categories[] = $row;
}

// 2. 每个母分类各自抓最多7个代表产品
$sections = [];
foreach ($parent_categories as $parent) {
    $parent_id = $parent['CATEGORY_ID'];

    $stmt = $conn->prepare("
        SELECT p.PRODUCT_ID, p.PRODUCT_NAME, p.COVER_IMAGE, p.AVG_RATING,
               MIN(v.VARIANT_PRICE) AS MIN_PRICE
        FROM product p
        JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
        JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
        LEFT JOIN product_variant v 
               ON p.PRODUCT_ID = v.PRODUCT_ID 
              AND v.IS_DELETED = 0 AND v.VARIANT_STATUS = 'Active'
        WHERE (c.CATEGORY_ID = ? OR c.PARENT_ID = ?)
          AND p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'
        GROUP BY p.PRODUCT_ID
        ORDER BY p.SALES_COUNT DESC
        LIMIT 7
    ");
    $stmt->bind_param("ii", $parent_id, $parent_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $products = [];
    while ($p = $result->fetch_assoc()) {
        $products[] = $p;
    }

    // 这个母分类底下暂时没有产品，就不显示这一整排
    if (count($products) > 0) {
        $sections[] = [
            'category_id'   => $parent_id,
            'category_name' => $parent['CATEGORY_NAME'],
            'products'      => $products
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Categories</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/header.css?v=7.0">
    <link rel="stylesheet" href="css/footer.css?v=7.0">
    <style>
        :root {
            --main-color: #80b8d2;
            --font-color: #1B2A3C;
            --font2-color: #52708A;
            --search-border-color: #C9DCEE;
            --main-dark: #3c8cb1;
            --secondary-color:#F4F8FC;
            --section-alt-bg: #F7FAFD;
            --card-bg-color: #EBF4FC;
            --rating-color:#F5A623;
            --border-subtle: #E2EDF7;
            --bg-color: #FFFFFF;
            --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }
        body { font-family: 'Inter', sans-serif; }

        .back-section {
            display: flex;
            align-items: center;
            margin: 30px 0 10px 20px; 
        }

        .back-link {
            text-decoration: none;
            color: var(--font2-color);
            font-size: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }

        .back-link:hover {
            color: var(--main-color);
            text-decoration: none;
        }

        .category-section { 
            margin: 40px 100px 0 100px; 
            padding: 0 30px 20px 30px;
            border-radius: 8px;
            background: #f3f9ff;
        }
        .category-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 15px;
        }
        .category-title {
            font-size: 22px; 
            font-weight: 700; 
            color: var(--main-color);
            margin-top: 30px;
        }
        .more-link { color: var(--font2-color); text-decoration: none; font-size: 14px; }
        .more-link:hover { color: var(--main-color); }

        .product-row {
            display: flex; gap: 14px; overflow-x: auto; padding-bottom: 10px;
        }
        .product-row .cake-item {
            flex: 0 0 180px;
            text-align: center;
            background: #fff;
            border: 1px solid var(--search-border-color);
            border-radius: 8px;
        }
        .product-row .cake-item img {
            height: 160px; width: 100%; border-radius: 8px 8px 0 0;
            object-fit: cover;
        }
        .cake-name {
            color: var(--font2-color); font-weight: bold; font-size: 13px;
            margin: 10px 8px;
        }
        .cake-name a { color: var(--font2-color); text-decoration: none; }
        .cake-name a:hover { color: var(--main-color); }
        .price { font-weight: bold; color: var(--font-color); margin: 0 8px 10px; display:block; }
    </style>
</head>
<body>
    <?php include 'include/header.php'; ?>

    <div class="container-fluid">
        <div class="back-section">
            <a href="index.php" class="back-link">
                <i class="bi bi-chevron-left"></i>Back
            </a>
        </div>
    </div>

    <?php foreach ($sections as $section): ?>
        <section class="category-section">
            <div class="category-header">
                <h2 class="category-title"><?= htmlspecialchars($section['category_name']) ?></h2>
                <a href="product catalogue.php?id=<?= $section['category_id'] ?>" class="more-link">
                    More <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <div class="product-row">
                <?php foreach ($section['products'] as $p): ?>
                    <div class="cake-item">
                        <img src="admin/<?= str_replace(['\\',' ','&'], ['/','%20','%26'], $p['COVER_IMAGE']) ?>"
                             alt="<?= htmlspecialchars($p['PRODUCT_NAME']) ?>">
                        <p class="cake-name">
                            <a href="product details.php?id=<?= $p['PRODUCT_ID'] ?>">
                                <?= htmlspecialchars($p['PRODUCT_NAME']) ?>
                            </a>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <?php include 'include/footer.php'; ?>
</body>
</html>
