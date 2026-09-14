<?php 
session_start();
include 'include/config.php';

// ===================== 参数收集 =====================
$current_id       = isset($_GET['id']) ? intval($_GET['id']) : 0;
$search           = isset($_GET['search']) ? trim($_GET['search']) : '';
$min              = isset($_GET['min']) ? floatval($_GET['min']) : 0;
$max              = isset($_GET['max']) ? floatval($_GET['max']) : 2000;
$selected_brands  = isset($_GET['brand']) ? (array)$_GET['brand'] : [];
$cake_type        = isset($_GET['cake_type']) ? $_GET['cake_type'] : ''; // 沿用原本变量名，代表 Best Selling / High Recommended
$sort             = isset($_GET['sort']) ? $_GET['sort'] : 'best_selling';

$header_title = "All Products";
$cat_desc     = "Browse our full range of robotics products.";
$top_id       = 0; // 目前所在的母分类 ID

// ===================== 判断标题 + 找出所属分类 =====================
if ($cake_type == 'Best Selling') {
    $header_title = "Best Selling";
    $cat_desc = "Our most popular picks loved by everyone.";
} elseif ($cake_type == 'High Recommended') {
    $header_title = "High Recommended";
    $cat_desc = "Our top-rated products with excellent reviews.";
} elseif ($current_id > 0) {
    $cat_res = $conn->query("SELECT * FROM category WHERE CATEGORY_ID = $current_id AND IS_DELETED = 0");
    if ($cat_res && $row = $cat_res->fetch_assoc()) {
        $header_title = $row['CATEGORY_NAME'];
        $cat_desc = "Explore our " . htmlspecialchars($row['CATEGORY_NAME']) . " products.";
        if ($row['PARENT_ID'] === null) {
            $top_id = $current_id;
        } else {
            $top_id = intval($row['PARENT_ID']);
        }
    }
} elseif (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $cat_res = $conn->query("SELECT * FROM category WHERE CATEGORY_NAME LIKE '%$safe_search%' AND IS_DELETED = 0 LIMIT 1");
    if ($cat_res && $cat_res->num_rows > 0) {
        $row = $cat_res->fetch_assoc();
        $header_title = $row['CATEGORY_NAME'];
        $top_id = ($row['PARENT_ID'] === null) ? intval($row['CATEGORY_ID']) : intval($row['PARENT_ID']);
        $current_id = intval($row['CATEGORY_ID']);
    } else {
        $header_title = "Search: " . htmlspecialchars($search);
        $cat_desc = "Showing results for \"" . htmlspecialchars($search) . "\".";
    }
}

// ===================== 排序 =====================
$order_by = "p.CREATED_AT DESC";
if ($sort === 'best_selling') {
    $order_by = "p.SALES_COUNT DESC";
} elseif ($sort === 'high_recommended') {
    $order_by = "p.AVG_RATING DESC";
} elseif ($sort === 'price_desc') {
    $order_by = "MIN_PRICE DESC";
} elseif ($sort === 'price_low') {
    $order_by = "MIN_PRICE ASC";
} elseif ($cake_type === 'Best Selling') {
    $order_by = "p.SALES_COUNT DESC";
}

// ===================== 计算所有有效产品总数 =====================
$all_products_cnt_res = $conn->query("
    SELECT COUNT(DISTINCT p.PRODUCT_ID) as cnt 
    FROM product p 
    JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
    WHERE p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'
      AND v.IS_DELETED = 0 AND v.VARIANT_STATUS = 'Active'
");
$total_all_products_count = ($all_products_cnt_res) ? $all_products_cnt_res->fetch_assoc()['cnt'] : 0;

// ===================== 全部分类清单 (母分类与子分类) =====================
$categories_tree = [];
$parent_sql = "
    SELECT c.CATEGORY_ID, c.CATEGORY_NAME,
           (SELECT COUNT(DISTINCT p.PRODUCT_ID) 
            FROM product p 
            JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID 
            JOIN category c2 ON pc.CATEGORY_ID = c2.CATEGORY_ID 
            WHERE (c2.CATEGORY_ID = c.CATEGORY_ID OR c2.PARENT_ID = c.CATEGORY_ID)
              AND p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'
           ) AS PRODUCT_COUNT
    FROM category c
    WHERE c.PARENT_ID IS NULL AND c.CATEGORY_STATUS = 'Active' AND c.IS_DELETED = 0
    ORDER BY c.CATEGORY_ID ASC
";
$parent_res = $conn->query($parent_sql);
if ($parent_res) {
    while ($p_cat = $parent_res->fetch_assoc()) {
        $sub_sql = "
            SELECT c.CATEGORY_ID, c.CATEGORY_NAME,
                   (SELECT COUNT(DISTINCT p.PRODUCT_ID) 
                    FROM product p 
                    JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID 
                    WHERE pc.CATEGORY_ID = c.CATEGORY_ID 
                      AND p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'
                   ) AS PRODUCT_COUNT
            FROM category c
            WHERE c.PARENT_ID = {$p_cat['CATEGORY_ID']} AND c.CATEGORY_STATUS = 'Active' AND c.IS_DELETED = 0
            ORDER BY c.CATEGORY_ID ASC
        ";
        $sub_res = $conn->query($sub_sql);
        $subcats = [];
        if ($sub_res) {
            while ($s_cat = $sub_res->fetch_assoc()) {
                $subcats[] = $s_cat;
            }
        }
        $p_cat['SUBCATEGORIES'] = $subcats;
        $categories_tree[] = $p_cat;
    }
}

// ===================== 品牌清单 + 各自产品数量 =====================
$brands = [];
$brand_where = "WHERE p.BRAND IS NOT NULL AND p.BRAND != '' AND p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'";
if ($current_id > 0) {
    if ($top_id === $current_id) {
        $brand_where .= " AND (c.CATEGORY_ID = $current_id OR c.PARENT_ID = $current_id)";
    } else {
        $brand_where .= " AND pc.CATEGORY_ID = $current_id";
    }
}

$brand_sql = "
    SELECT p.BRAND, COUNT(DISTINCT p.PRODUCT_ID) as cnt
    FROM product p
    JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
    JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
    $brand_where
    GROUP BY p.BRAND
    ORDER BY p.BRAND ASC
";
$brand_result = $conn->query($brand_sql);
if ($brand_result) {
    while ($row = $brand_result->fetch_assoc()) {
        $brands[] = $row;
    }
}

// ===================== WHERE 条件 =====================
$where_clause = " WHERE p.IS_DELETED = 0 AND v.IS_DELETED = 0 
    AND p.PRODUCT_STATUS = 'Active' AND v.VARIANT_STATUS = 'Active'";

// 分类筛选
if ($current_id > 0) {
    if ($top_id === $current_id) {
        $where_clause .= " AND (c.CATEGORY_ID = $current_id OR c.PARENT_ID = $current_id)";
    } else {
        $where_clause .= " AND pc.CATEGORY_ID = $current_id";
    }
}

// 品牌筛选
if (!empty($selected_brands)) {
    $safe_brands = array_map(function ($b) use ($conn) {
        return "'" . $conn->real_escape_string($b) . "'";
    }, $selected_brands);
    $where_clause .= " AND p.BRAND IN (" . implode(',', $safe_brands) . ")";
}

// Best Selling / High Recommended
if ($cake_type === 'Best Selling') {
    $where_clause .= " AND p.SALES_COUNT >= 50";
} elseif ($cake_type === 'High Recommended') {
    $where_clause .= " AND p.AVG_RATING >= 4.5";
}

// 关键字搜索
if (!empty($search) && $current_id == 0 && empty($cake_type)) {
    $safe_search = $conn->real_escape_string($search);
    $where_clause .= " AND p.PRODUCT_NAME LIKE '%$safe_search%'";
}

// ===================== 价格筛选 =====================
$having_clause = " HAVING MIN_PRICE BETWEEN $min AND $max AND SUM(v.VARIANT_STOCK) > 0 ";

// ===================== 计算总数 / 分页 =====================
$total_sql = "SELECT COUNT(*) as total FROM (
        SELECT p.PRODUCT_ID, MIN(v.VARIANT_PRICE) as MIN_PRICE
        FROM product p
        LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
        LEFT JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
        LEFT JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
        $where_clause
        GROUP BY p.PRODUCT_ID
        $having_clause
         ) as subquery";

$total_result = $conn->query($total_sql);
if (!$total_result) {
    die("SQL ERROR: " . $conn->error . " | Query: " . $total_sql);
}
$total_row = $total_result->fetch_assoc();
$total_records = $total_row['total'];
$per_page = 10;
$total_pages = ceil($total_records / $per_page);
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if ($page < 1) $page = 1;
$start_from = ($page - 1) * $per_page;

// ===================== 主查询 =====================
$sql = "SELECT p.*, MIN(v.VARIANT_PRICE) as MIN_PRICE, COUNT(v.VARIANT_ID) as VARIANT_COUNT
        FROM product p
        LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
        LEFT JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
        LEFT JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
        $where_clause
        GROUP BY p.PRODUCT_ID 
        $having_clause
        ORDER BY $order_by
        LIMIT $start_from, $per_page";

$product_result = $conn->query($sql);
if (!$product_result) {
    die("SQL ERROR: " . $conn->error . " | Query: " . $sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $header_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --main-color: #80b8d2;
            --main-dark: #3c8cb1;
            --font-color:#1B2A3C;
            --secondary-color:#F4F8FC;
            --rating-color:#F5A623;
            --search-border-color:#C9DCEE;
            --bg-color:#FFFFFF;
            --font2-color:#52708A;
        }
        body{ font-family: 'Inter', sans-serif; background-color: var(--bg-color); }

        .back-section{ display: flex; align-items: center; margin: 30px 0 20px 30px; }
        .back-link{ text-decoration: none; color: var(--font-color); font-size: 16px; display: flex; align-items: center; gap: 8px; margin-right: 50px; transition: color 0.3s; }
        .back-link:hover{ text-decoration:underline; }

        .title-container{ display:flex; align-items:center; gap:20px; flex-grow:1; margin:0 0 20px 0; }
        .page-title{ font-size:30px; font-weight:700; color:var(--main-color); font-family:'Poppins', sans-serif; }
        .description-section{ margin-bottom:30px; }
        .description-text{ margin-bottom:15px; width:90%; color:var(--font2-color); }

        .main-container{ display:flex; gap:40px; align-items:flex-start; padding: 0 20px; }

        .sidebar{ margin-top:50px; width:260px; flex-shrink: 0; padding:0 15px; }

        #refine-section { display: none; margin-bottom: 30px; border-bottom: 1px solid var(--search-border-color); padding-bottom: 15px; }
        .refine-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .filter-tag { background: #fff; border: 1px solid var(--search-border-color); padding: 8px 12px; margin-top: 8px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; }
        .tag-cat { font-size: 10px; color: #7C96AA; text-transform: uppercase; display: block; }
        .tag-val { font-size: 14px; font-weight: bold; color: var(--font-color); }
        .remove-btn { cursor: pointer; color: var(--main-color); margin-left: 10px; }

        .product-content{ flex-grow:1; }
        .filter-group{ margin-bottom:25px; margin-left:10px; }
        .filter-title{ font-size: 18px; font-weight: 700; margin-bottom: 15px; display: flex; justify-content: flex-start; align-items: center; gap: 10px; cursor: pointer; color:var(--main-color); }
        .filter-title i { font-size: 12px; color: var(--font2-color); transition: transform 0.3s ease; display: inline-block; }
        .filter-list.collapsed, .price-range-inputs.collapsed { display: none; }
        .filter-title.active i { transform: rotate(-90deg); }
        #clear-all:hover { color: var(--font-color); }
        .collapsed { display: none; }
        .filter-list { list-style: none; padding-left: 5px; }
        .filter-list a{ color:var(--font2-color); text-decoration:none; }
        .filter-list a:hover{ text-decoration:underline; }

        /* Category link list */
        .cat-link-list {
            list-style: none;
            padding-left: 0;
            margin: 0;
        }
        .cat-link-item {
            margin-bottom: 8px;
        }
        .subcat-link-item {
            margin-bottom: 8px;
            padding-left: 14px;
        }
        .cat-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: var(--font2-color);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.25s ease;
            width: 100%;
            padding: 6px 10px;
            border-radius: 6px;
        }
        .cat-link:hover {
            color: var(--main-color);
            background-color: var(--secondary-color);
            text-decoration: none;
        }
        .cat-link.active {
            color: var(--main-color);
            font-weight: 700;
            background-color: var(--secondary-color);
        }
        .cat-link.active .count-badge {
            color: var(--main-color);
            font-weight: 700;
        }

        .filter-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--font2-color);
            cursor: pointer;
            transition: color 0.3s;
        }
        .filter-item input[type="checkbox"] {
            margin-right: 10px;
            accent-color: var(--main-color);
            width: 16px;
            height: 16px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .filter-item:hover .cat-name-label {
            color: var(--main-color);
        }
        .filter-item input[type="checkbox"]:checked + .cat-name-label {
            color: var(--main-color);
            font-weight: 600;
        }
        .filter-item input[type="checkbox"]:checked ~ .count-badge {
            color: var(--main-color);
            font-weight: 600;
        }
        .count-badge {
            font-size: 12.5px;
            color: var(--font2-color);
            margin-left: auto;
            flex-shrink: 0;
            padding-left: 6px;
        }

        .price-range-inputs { display: flex; gap: 10px; margin-top: 10px; }
        .price-input { width: 80px; padding: 5px; border: 1px solid var(--search-border-color); border-radius: 4px; background-color: #fff; color: var(--font-color); font-size: 14px; text-align: center; }

        .toolbar { display: flex; justify-content: flex-end; margin-bottom: 30px; margin-right:28px; }
        .sort-options{ border: 1px solid var(--search-border-color); color: var(--font-color); border-radius: 4px; padding:5px; background: #fff; }

        .cake-grid{ display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; margin-bottom: 50px; }
        .cake-item { text-align: center; background: #fff; border: 1px solid var(--search-border-color); border-radius: 8px; transition: 0.35s ease; }
        .cake-item img { height:220px; width: 100%; border-radius: 8px; aspect-ratio: 1/1; object-fit: cover; }
        .cake-name{ color:var(--font2-color); font-weight:bold; font-family: 'Inter', sans-serif; margin:12px 10px 10px; font-size:13px; width:auto; }
        .cake-name a{ color:var(--font2-color); text-decoration:none; transition:0.3s; }
        .cake-name a:hover{ color:var(--main-color); }
        .stars{ color:var(--rating-color); font-size:14px; margin:0 10px 12px; }
        .price{ font-weight:bold; display:inline-block; margin-left:10px; color:var(--font-color) }
        .section-title{ color:var(--font-color); font-family: 'Poppins', sans-serif; font-weight:bold; margin-left:100px; }

        .pagination{ display:flex; justify-content: center; align-items:center; gap:15px; margin-top:40px; }
        .page-btn { width: 35px; height: 35px; display: flex; justify-content: center; align-items: center; border-radius: 4px; background-color: #fff; color: var(--font2-color); cursor: pointer; text-decoration: none; font-weight: 600; transition: 0.3s; }
        .page-btn.active { background-color: var(--main-color); color: #fff; border-color: var(--main-color); }
        .page-btn:hover:not(.active) { background-color: var(--secondary-color); color: var(--main-color); }
        .page-arrow { color: var(--font2-color); text-decoration: none; font-size: 14px; transition: color 0.3s; }
        .page-arrow:hover { color: var(--main-color); }
    </style>
</head>

<body>
    <?php include 'include/header.php'; ?>
    <div class="back-section">
        <a href="index.php" class="back-link">
            <i class="bi bi-chevron-left"></i>Back
        </a>
    </div>

    <main class="main-container">

        <aside class="sidebar">
            <div id="refine-section">
                <div class="refine-header">
                    <h5 style="color: var(--font-color); margin: 0;">Refine By</h5>
                    <button id="clear-all" class="btn btn-sm p-0" style="color: var(--font2-color); text-decoration: underline; background: none; border: none;">Clear All</button>
                </div>
                <div id="tag-container"></div>
            </div>

            <div class="filter-group">
                <div class="filter-title" onclick="toggleFilter(this)">
                    <i class="bi bi-caret-down-fill"></i>
                    Favourite
                </div>
                <ul class="filter-list">
                    <li class="filter-item"><a href="javascript:void(0)" onclick="filterByType('Best Selling')">Best Selling</a></li>
                    <li class="filter-item"><a href="javascript:void(0)" onclick="filterByType('High Recommended')">High Recommended</a></li>
                </ul>
            </div>

            <!-- Categories Filter -->
            <div class="filter-group">
                <div class="filter-title" onclick="toggleFilter(this)">
                    <i class="bi bi-caret-down-fill"></i>
                    Categories
                </div>
                <ul class="filter-list cat-link-list">
                    <!-- All Products Link -->
                    <li class="cat-link-item">
                        <a href="product catalogue.php" class="cat-link <?= ($current_id == 0 && empty($cake_type)) ? 'active' : '' ?>">
                            <span class="cat-name-label">All products</span>
                            <span class="count-badge">(<?= $total_all_products_count ?>)</span>
                        </a>
                    </li>

                    <?php foreach ($categories_tree as $parent): ?>
                        <?php if (!empty($parent['SUBCATEGORIES'])): ?>
                            <?php foreach ($parent['SUBCATEGORIES'] as $sub): ?>
                                <li class="subcat-link-item">
                                    <a href="product catalogue.php?id=<?= $sub['CATEGORY_ID'] ?>" class="cat-link <?= ($current_id == $sub['CATEGORY_ID']) ? 'active' : '' ?>">
                                        <span class="cat-name-label"><?= htmlspecialchars($sub['CATEGORY_NAME']) ?></span>
                                        <span class="count-badge">(<?= $sub['PRODUCT_COUNT'] ?>)</span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="subcat-link-item">
                                <a href="product catalogue.php?id=<?= $parent['CATEGORY_ID'] ?>" class="cat-link <?= ($current_id == $parent['CATEGORY_ID']) ? 'active' : '' ?>">
                                    <span class="cat-name-label"><?= htmlspecialchars($parent['CATEGORY_NAME']) ?></span>
                                    <span class="count-badge">(<?= $parent['PRODUCT_COUNT'] ?>)</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if (!empty($brands)): ?>
            <div class="filter-group">
                <div class="filter-title" onclick="toggleFilter(this)">
                    <i class="bi bi-caret-down-fill"></i>
                    Brands
                </div>
                <ul class="filter-list">
                    <?php foreach ($brands as $b): ?>
                        <li class="filter-item">
                            <input type="checkbox" name="brand[]" value="<?= htmlspecialchars($b['BRAND']) ?>"
                                <?= in_array($b['BRAND'], $selected_brands) ? 'checked' : '' ?>
                                onchange="applyFilters()">
                            <span class="cat-name-label"><?= htmlspecialchars($b['BRAND']) ?></span>
                            <span class="count-badge">(<?= $b['cnt'] ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="filter-group">
                <div class="filter-title" onclick="toggleFilter(this)">
                    <i class="bi bi-caret-down-fill"></i>
                    Price
                </div>
                <div class="price-range-inputs">
                    <input type="text" id="min_p" class="price-input" placeholder="Min"
                        maxlength="5"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                        value="<?php echo $min; ?>">
                    <span class="separator">-</span>
                    <input type="text" id="max_p" class="price-input" placeholder="Max"
                        maxlength="5"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                        value="<?php echo $max; ?>">
                </div>
            </div>

        </aside>

        <div class="content-wrapper" style="flex-grow: 1;">
            <div class="description-section">
                <div class="title-container">
                    <h1 class="page-title"><?php echo htmlspecialchars($header_title); ?></h1>
                </div>
                <p class="description-text">
                    <?php echo htmlspecialchars($cat_desc); ?>
                </p>
            </div>

            <section class="product-content">
                <div class="toolbar">
                    <div class="sort-dropdown">
                        <select class="sort-options">
                            <option value="best_selling" <?php echo ($sort == 'best_selling') ? 'selected' : ''; ?>>Best Selling</option>
                            <option value="high_recommended" <?php echo ($sort == 'high_recommended') ? 'selected' : ''; ?>>High Recommended</option>
                            <option value="price_desc" <?php echo ($sort == 'price_desc') ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="price_low" <?php echo ($sort == 'price_low') ? 'selected' : ''; ?>>Price: Low to High</option>
                        </select>
                    </div>
                </div>

                <div class="cake-grid">
                    <?php
                    if ($product_result && $product_result->num_rows > 0) {
                        while ($row = $product_result->fetch_assoc()) {
                            $cover = !empty($row['COVER_IMAGE'])
                                ? "admin/" . str_replace(['\\', ' ', '&'], ['/', '%20', '%26'], $row['COVER_IMAGE'])
                                : "image/placeholder.jpg";
                            ?>
                            <div class="cake-item">
                                <img src="<?php echo $cover; ?>" alt="<?php echo htmlspecialchars($row['PRODUCT_NAME']); ?>">
                                <p class="cake-name"><a href="product details.php?id=<?php echo $row['PRODUCT_ID']; ?>"><?php echo htmlspecialchars($row['PRODUCT_NAME']); ?></a></p>
                                <div class="stars">
                                    <?php
                                    $rating = round($row['AVG_RATING']);
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo ($i <= $rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
                                    }
                                    ?>
                                    <span class="ms-1">(<?php echo number_format($row['AVG_RATING'], 1); ?>)</span>
                                    <p class="price">
                                        <?php if ($row['MIN_PRICE'] !== null): ?>
                                            RM <?php echo number_format($row['MIN_PRICE'], 2); ?><?php echo ($row['VARIANT_COUNT'] > 1) ? '++' : ''; ?>
                                        <?php else: ?>
                                            no quotation
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <?php
                        }
                    } else {
                        echo "<p style='grid-column: span 5; text-align: center; color: var(--font2-color);'>No products found matching your filters.</p>";
                    }
                    ?>
                </div>

                <?php if ($total_pages > 1) : ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-arrow">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                               class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-arrow">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

        </div>
    </main>

    <?php include 'include/footer.php'; ?>
</body>
<script>
    // Best Selling / High Recommended
    function filterByType(type) {
        const params = new URLSearchParams();
        params.set('cake_type', type);
        params.set('page', 1);
        window.location.href = 'product catalogue.php?' + params.toString();
    }

    // 套用筛选（Brands checkbox / Price / Sort）
    function applyFilters() {
        const params = new URLSearchParams(window.location.search);

        const checkedBrands = document.querySelectorAll('input[name="brand[]"]:checked');
        params.delete('brand[]');
        checkedBrands.forEach(cb => params.append('brand[]', cb.value));

        const minP = document.getElementById('min_p').value || 0;
        const maxP = document.getElementById('max_p').value || 2000;
        params.set('min', minP);
        params.set('max', maxP);

        const sortElement = document.querySelector('.sort-options');
        if (sortElement) {
            params.set('sort', sortElement.value);
        }

        params.set('page', 1);
        window.location.href = window.location.pathname + '?' + params.toString();
    }

    document.addEventListener('DOMContentLoaded', function () {
        const refineSection = document.getElementById('refine-section');
        const tagContainer = document.getElementById('tag-container');
        const clearAllBtn = document.getElementById('clear-all');
        const checkboxes = document.querySelectorAll('.filter-item input[type="checkbox"]');

        window.toggleFilter = function (element) {
            element.classList.toggle('active');
            const list = element.nextElementSibling;
            if (list) {
                list.classList.toggle('collapsed');
            }
        };

        function refreshRefineArea() {
            const allCheckedBoxes = Array.from(document.querySelectorAll('.filter-item input[type="checkbox"]')).filter(i => i.checked);

            const minP = document.getElementById('min_p').value;
            const maxP = document.getElementById('max_p').value;

            const isPriceFiltered = (minP != 0 || maxP != 2000);

            if (allCheckedBoxes.length > 0 || isPriceFiltered) {
                refineSection.style.display = 'block';
                tagContainer.innerHTML = '';
                allCheckedBoxes.forEach(box => {
                    const filterGroup = box.closest('.filter-group');
                    const titleElement = filterGroup ? filterGroup.querySelector('.filter-title') : null;
                    const groupLabel = titleElement ? titleElement.innerText.trim() : 'Filter';
                    const valLabel = box.parentElement.innerText.trim();
                    const tag = document.createElement('div');
                    tag.className = 'filter-tag';
                    tag.innerHTML = `
                        <div class="tag-box">
                            <span class="tag-cat">${groupLabel}:</span>
                            <span class="tag-val">${valLabel}</span>
                        </div>
                        <span class="remove-btn" onclick="uncheck('${box.name}', '${box.value}')"><i class="bi bi-x-lg"></i></span>
                    `;
                    tagContainer.appendChild(tag);
                });

                if (isPriceFiltered) {
                    const priceTag = document.createElement('div');
                    priceTag.className = 'filter-tag';
                    priceTag.innerHTML = `
                        <div class="tag-box">
                            <span class="tag-cat">Price:</span>
                            <span class="tag-val">${minP} - ${maxP}</span>
                        </div>
                        <span class="remove-btn" onclick="resetPrice()"><i class="bi bi-x-lg"></i></span>
                    `;
                    tagContainer.appendChild(priceTag);
                }
            } else {
                refineSection.style.display = 'none';
            }
        }

        window.resetPrice = function () {
            document.getElementById('min_p').value = 0;
            document.getElementById('max_p').value = 2000;
            applyFilters();
        };

        checkboxes.forEach(box => {
            box.addEventListener('change', refreshRefineArea);
        });

        window.uncheck = function (name, val) {
            let target = null;
            if (name) {
                target = document.querySelector(`input[name="${name}"][value="${val}"]`);
            } else {
                target = Array.from(document.querySelectorAll('.filter-item input[type="checkbox"]')).find(i => i.value === val);
            }
            if (target) {
                target.checked = false;
                refreshRefineArea();
                applyFilters();
            }
        };

        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function () {
                const url = new URL(window.location.href);
                const params = new URLSearchParams(url.search);

                const currentType = params.get('cake_type');
                const currentId = params.get('id');

                const newParams = new URLSearchParams();
                if (currentType) newParams.set('cake_type', currentType);
                if (currentId) newParams.set('id', currentId);
                newParams.set('page', 1);

                document.querySelectorAll('.filter-item input[type="checkbox"]').forEach(i => i.checked = false);
                document.getElementById('min_p').value = 0;
                document.getElementById('max_p').value = 2000;

                refreshRefineArea();
                window.location.href = window.location.pathname + '?' + newParams.toString();
            });
        }

        document.querySelectorAll('.price-input').forEach(input => {
            input.addEventListener('keypress', function (e) {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
        });

        const sortSelect = document.querySelector('.sort-options');
        if (sortSelect) {
            sortSelect.addEventListener('change', applyFilters);
        }

        // 初始化：把网址上已经有的 brand[] 打勾状态还原
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.getAll('brand[]').forEach(val => {
            const cb = document.querySelector(`input[name="brand[]"][value="${val}"]`);
            if (cb) cb.checked = true;
        });

        refreshRefineArea();
    });
</script>
</html>