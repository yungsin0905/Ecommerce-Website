<?php
// ============ 临时调试开关，确认没问题后请删除这两行 ============
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ================================================================

include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

  //retrieve product information
  $query = "SELECT p.*, GROUP_CONCAT(DISTINCT c.CATEGORY_NAME SEPARATOR ', ') as CATEGORY_NAME,
  (SELECT AVG(RATING) FROM review WHERE PRODUCT_ID = p.PRODUCT_ID AND REVIEW_STATUS = 'Unhide') as CALCULATED_RATING,
  (SELECT COUNT(*) FROM review WHERE PRODUCT_ID = p.PRODUCT_ID AND REVIEW_STATUS = 'Unhide') as TOTAL_REVIEWS
  FROM product p
  LEFT JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
  LEFT JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
  WHERE p.PRODUCT_ID = $product_id
  AND p.IS_DELETED = 0 
  AND EXISTS (
              SELECT 1 FROM product_variant
              WHERE PRODUCT_ID = p.PRODUCT_ID 
              AND IS_DELETED = 0
              AND VARIANT_STATUS = 'Active'
              AND VARIANT_STOCK > 0)
  GROUP BY p.PRODUCT_ID
  LIMIT 1";
            
  $result = mysqli_query($conn, $query);
  $product = mysqli_fetch_assoc($result);

  if(!$product){
    header("Location: index.php");
    exit();
  }

  $display_rating = $product['CALCULATED_RATING'] ? round($product['CALCULATED_RATING'], 1) : 0;
  $total_reviews = $product['TOTAL_REVIEWS'];
  $features_data = !empty($product['FEATURES']) ? json_decode($product['FEATURES'], true) : [];
$packing_data  = !empty($product['PACKING_LIST']) ? json_decode($product['PACKING_LIST'], true) : [];

//====== HTMLPurifier & 自定义样式处理 description ======
require_once 'include/vendor/HTMLPurifier.standalone.php';
$config = HTMLPurifier_Config::createDefault();
$purifier = new HTMLPurifier($config);

// 彻底还原多层 HTML 实体转义
$raw_desc = (string)$product['PRODUCT_DES'];
for ($i = 0; $i < 3; $i++) {
    if (strpos($raw_desc, '&lt;') !== false || strpos($raw_desc, '&#') !== false) {
        $raw_desc = html_entity_decode($raw_desc, ENT_QUOTES, 'UTF-8');
    } else {
        break;
    }
}

// 提取 <style> 中的 CSS 规则并注入页面头部渲染，绝不让 CSS 代码漏到前台文本中
$custom_desc_css = '';
if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/is', $raw_desc, $style_matches)) {
    foreach ($style_matches[1] as $css_chunk) {
        $custom_desc_css .= "\n" . $css_chunk;
    }
    // 移除已提取的 style 块
    $raw_desc = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $raw_desc);
}

// 移除 script 标签
$raw_desc = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw_desc);

$clean_description = $purifier->purify($raw_desc);


//=============================================

  //retrieve add on
    $add_query = "SELECT pa.PRODUCT_ADDON_ID, pa.ADDON_PRICE as CUSTOM_PRICE, pa.SORT_ORDER,
      p2.PRODUCT_ID as ADDON_PRODUCT_ID, p2.PRODUCT_NAME, p2.COVER_IMAGE,
      MIN(v2.VARIANT_ID) as ADDON_VARIANT_ID,
      MIN(v2.VARIANT_PRICE) as VARIANT_PRICE,
      SUM(v2.VARIANT_STOCK) as VARIANT_STOCK
  FROM product_addon pa
  JOIN product p2 ON pa.ADDON_PRODUCT_ID = p2.PRODUCT_ID
  JOIN product_variant v2 ON v2.PRODUCT_ID = p2.PRODUCT_ID 
      AND v2.IS_DELETED = 0 AND v2.VARIANT_STATUS = 'Active'
  WHERE pa.HOST_PRODUCT_ID = $product_id 
      AND pa.IS_DELETED = 0
      AND p2.IS_DELETED = 0 AND p2.PRODUCT_STATUS = 'Active'
  GROUP BY p2.PRODUCT_ID
  ORDER BY pa.SORT_ORDER ASC";
  $add_result = mysqli_query($conn, $add_query);
  $all_addons = mysqli_fetch_all($add_result, MYSQLI_ASSOC);

  foreach ($all_addons as &$a) {
      $a['ADD_ON_ID']    = $a['ADDON_PRODUCT_ID'];
      $a['ADD_ON_NAME']  = $a['PRODUCT_NAME'];
      $a['ADD_ON_IMAGE'] = $a['COVER_IMAGE'];
      $a['ADD_ON_PRICE'] = !empty($a['CUSTOM_PRICE']) ? $a['CUSTOM_PRICE'] : $a['VARIANT_PRICE'];
      $a['ADD_ON_STOCK'] = $a['VARIANT_STOCK'];
  }
  unset($a);


  //retrieve product image
  $img_query = "SELECT IMAGE_PATH FROM product_images WHERE PRODUCT_ID = $product_id AND IS_DELETED = 0";
  $img_result = mysqli_query($conn, $img_query);
  $images = mysqli_fetch_all($img_result, MYSQLI_ASSOC);

  //retrieve product variant
  $variant_query = "SELECT * FROM product_variant WHERE PRODUCT_ID = $product_id AND VARIANT_STATUS = 'Active' 
  AND IS_DELETED = 0 AND VARIANT_STOCK > 0  
  ORDER BY VARIANT_ID ASC";
  $variant_result = mysqli_query($conn, $variant_query);
  $variants = mysqli_fetch_all($variant_result, MYSQLI_ASSOC);

  //每个 variant 算出"实际卖多少钱"（有促销价就用促销价，没有就用原价）
  foreach ($variants as &$v) {
      $v['DISPLAY_PRICE'] = !empty($v['SALE_PRICE']) ? (float)$v['SALE_PRICE'] : (float)$v['VARIANT_PRICE'];
  }
  unset($v);

  //价格区间：用 DISPLAY_PRICE（实际卖的价格）来算区间
  $display_prices = array_column($variants, 'DISPLAY_PRICE');
  $min_price = count($display_prices) > 0 ? min($display_prices) : 0;
  $max_price = count($display_prices) > 0 ? max($display_prices) : 0;

  //原价区间：用 VARIANT_PRICE 来算
  $original_prices = array_column($variants, 'VARIANT_PRICE');
  $min_original = count($original_prices) > 0 ? min($original_prices) : 0;
  $max_original = count($original_prices) > 0 ? max($original_prices) : 0;

  //是否有任何 variant 打折
  $has_discount = false;
  $discount_percents = [];
  foreach ($variants as $v) {
      if (!empty($v['SALE_PRICE']) && $v['SALE_PRICE'] < $v['VARIANT_PRICE']) {
          $has_discount = true;
          $discount_percents[] = round((($v['VARIANT_PRICE'] - $v['SALE_PRICE']) / $v['VARIANT_PRICE']) * 100);
      }
  }
  $min_discount = count($discount_percents) > 0 ? min($discount_percents) : 0;
  $max_discount = count($discount_percents) > 0 ? max($discount_percents) : 0;

  //初始价格也要用 DISPLAY_PRICE
  $initial_price = count($variants) > 0 ? $variants[0]['DISPLAY_PRICE'] : 0.0;
  $initial_stock = count($variants) > 0 ? intval($variants[0]['VARIANT_STOCK']) : 999;

  //================================================================
  
  //retrieve option groups for this product
  $option_groups = [];
  $opt_query = "SELECT * FROM product_option WHERE PRODUCT_ID = $product_id AND IS_DELETED = 0 ORDER BY OPTION_ORDER ASC";
  $opt_result = mysqli_query($conn, $opt_query);
  while ($og = mysqli_fetch_assoc($opt_result)) {
      $val_query = "SELECT * FROM product_option_value WHERE OPTION_ID = {$og['OPTION_ID']} AND IS_DELETED = 0 ORDER BY VALUE_ORDER ASC";
      $val_result = mysqli_query($conn, $val_query);
      $og['VALUES'] = mysqli_fetch_all($val_result, MYSQLI_ASSOC);
      $option_groups[] = $og;
  }

  //build variant -> option_value_id[] map (只算目前有效的 variant)
  $variant_option_map = [];
  foreach ($variants as $v) {
      $vov_query = "SELECT OPTION_VALUE_ID FROM variant_option_value WHERE VARIANT_ID = {$v['VARIANT_ID']}";
      $vov_result = mysqli_query($conn, $vov_query);
      $ids = [];
      while ($row = mysqli_fetch_assoc($vov_result)) {
          $ids[] = intval($row['OPTION_VALUE_ID']);
      }
      $variant_option_map[$v['VARIANT_ID']] = $ids;
  }

  
  $editing_cart_item = null;
  $editing_addons = [];

if (isset($_GET['cart_item_id'])) {
    $cart_item_id = intval($_GET['cart_item_id']);

    // 1. main item
    $edit_sql = "SELECT * FROM cart_item WHERE CART_ITEM_ID = $cart_item_id";
    $edit_res = mysqli_query($conn, $edit_sql);

    if ($edit_res && mysqli_num_rows($edit_res) > 0) {
        $editing_cart_item = mysqli_fetch_assoc($edit_res);

        // 2. addons
       $addon_sql = "SELECT * FROM cart_item_addon WHERE CART_ITEM_ID = $cart_item_id";
       $addon_res = mysqli_query($conn, $addon_sql);

       $editing_card_text = '';

       while ($row = mysqli_fetch_assoc($addon_res)) {

          $editing_addons[$row['ADD_ON_ID']] = $row['QUANTITY'];

          // greeting card addon
          if ($row['ADD_ON_ID'] == 3) {
              $editing_card_text = $row['CARD_TEXT'];
          }
     }
   }
}

  //sort review
  $sort = isset($_GET['sort']) ? $_GET['sort'] : 'high_ratings';
  $order_by = "r.CREATED_AT DESC"; 
  if ($sort === 'new_ratings') {
    $order_by = "r.CREATED_AT DESC";
  } else if ($sort === 'high_ratings') {
    $order_by = "r.RATING DESC";
  } 


  //retrieve review
  $review_query = "SELECT r.*, u.CUSTOMER_NAME, u.PROFILE_IMAGE,
  GROUP_CONCAT(w.REPLY_TEXT ORDER BY w.CREATED_AT ASC SEPARATOR '||') as REPLY_TEXT,
  GROUP_CONCAT(w.CREATED_AT ORDER BY w.CREATED_AT ASC SEPARATOR '||') as REPLY_DATE
  FROM review r
  JOIN customer u ON r.CUSTOMER_ID = u.CUSTOMER_ID
  LEFT JOIN review_reply w ON w.REVIEW_ID = r.REVIEW_ID AND (w.IS_DELETED = 0 OR w.IS_DELETED IS NULL)
  WHERE r.PRODUCT_ID = $product_id
  AND r.REVIEW_STATUS = 'Unhide'
  GROUP BY r.REVIEW_ID
  ORDER BY $order_by";

  $reviews_result = mysqli_query($conn, $review_query);
  $reviews = mysqli_fetch_all($reviews_result, MYSQLI_ASSOC);
  $review_count = mysqli_num_rows($reviews_result);

  //check if product is in wishlist
  $in_wishlist = false;
  if (isset($_SESSION['CUSTOMER_ID'])) {
    $wl_check = mysqli_query($conn, "SELECT WISHLIST_ID FROM wishlist WHERE CUSTOMER_ID = {$_SESSION['CUSTOMER_ID']} AND PRODUCT_ID = $product_id");
    $in_wishlist = mysqli_num_rows($wl_check) > 0;
  }

  //image path
  function resolveAddonImage($path)
  {
    if (empty($path)) return null;

    //admin/
    if (str_starts_with($path, 'admin/')) {
      return $path;
    }

    //image/
     if (str_starts_with($path, 'image/')) {
        return $path;
    }

    return 'admin/' . $path;
  }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
      <link rel="stylesheet" href="css/header.css?v=6.0">
      <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root
      {
        --main-color:#80b8d2;
        --font-color:#1B2A3C;
        --secondary-color:#F4F8FC;
        --rating-color:#F5A623;
        --search-border-color:#C9DCEE;
        --bg-color:#FFFFFF;
        --font2-color:#52708A;
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        margin: 0;
        padding: 0;
      }

      .page-wrapper {
        max-width: 1000px;
        margin: 0 auto;
        padding: 0 20px;
      }

      /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 30px -100px;

      }

      .back-link {
        text-decoration: none;
        color: var(--font2-color);
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: color 0.3s;
      }

      .back-link:hover {
        color: var(--main-color);
        text-decoration: none;
      }

      /* product image and details layout */
      .product-container {
        display: grid;
        grid-template-columns: 1fr 1.15fr;  
        gap: 50px; 
        margin-bottom: 40px;
        margin-left: -100px;
        align-items: start;
      }

      .product-gallery {
        width: 100%;
        min-width: 0;
        position: sticky;
        top: 20px;
      }

      .main-image {
        width: 100%;
        aspect-ratio: 1 / 1;
        background: #ffffff;
        border: 1px solid var(--search-border-color);
        border-radius: 10px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        box-shadow: 0 4px 15px rgba(27,42,60,0.06);
      }

      .main-image img {
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: 100%;
        object-fit: contain; /* 保证任意尺寸/比例的商品图片统一完整居中于 1:1 正方形框内，不拉伸变形也不撑大容器 */
        object-position: center;
        cursor: zoom-in;
        display: block;
        padding: 18px; /* 留出电商白边内边距，统一视觉规范 */
        background: #ffffff;
        transition: transform 0.25s ease;
      }

      .main-image:hover img {
        transform: scale(1.03);
      }

      .thumbnail-list {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 15px;
      }

      .thumbnail-list img {
        width: 75px;
        height: 75px;
        aspect-ratio: 1 / 1;
        object-fit: contain;
        object-position: center;
        cursor: pointer;
        border-radius: 6px;
        border: 1.5px solid var(--search-border-color);
        background: #ffffff;
        padding: 4px;
        transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
      }

      .thumbnail-list img:hover,
      .thumbnail-list img.active-thumb {
        border-color: var(--main-color);
        transform: translateY(-2px);
        box-shadow: 0 3px 8px rgba(0,0,0,0.08);
      }

      /* full-width product info accordion (Description, Features, Packing List) */
      .product-info-section {
        margin-top: 40px;
        margin-bottom: 50px;
        margin-left: -100px;
      }

      .accordion {
        border-top: 1px solid var(--search-border-color);
       
        
      }

      .accordion-item {
        border: none;
        border-bottom: 1px solid var(--search-border-color);
        
      }

      .accordion-header {
        padding: 18px 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 600;
        font-size: 16px;
        cursor: pointer;
        color: var(--font-color);
        transition: color 0.2s ease;
      }

      .accordion-header:hover {
        color: var(--main-color);
      }

      .accordion-header span {
        font-size: 18px;
        font-weight: 600;
        color: var(--font2-color);
      }

      .accordion-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        font-size: 14.5px;
        color: var(--font2-color);
      }

      .accordion-item.active .accordion-content {
        max-height: 2500px;
        padding-bottom: 20px;
        overflow-y: visible;
      }

      .description-content {
        line-height: 1.75;
        color: var(--font-color);
        font-size: 14.5px;
      }

      .description-content img {
        max-width: 100%;
        height: auto;
        border-radius: 6px;
      }

      /* Built-in rich layout helper classes for description */
      .description-content .desc-grid-2 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
        margin: 20px 0;
      }

      .description-content .desc-grid-3,
      .description-content .compare-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin: 20px 0;
      }

      .description-content .desc-grid-4 {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin: 20px 0;
      }

      .description-content .desc-card,
      .description-content .compare-item {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 20px 16px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        display: flex;
        flex-direction: column;
        align-items: center;
      }

      .description-content .desc-card:hover,
      .description-content .compare-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.08);
      }

      .description-content .compare-item img,
      .description-content .desc-card img {
        max-height: 160px;
        width: auto;
        margin-bottom: 12px;
        object-fit: contain;
      }

      .description-content .compare-item h4,
      .description-content .compare-item h5,
      .description-content .desc-card h4,
      .description-content .desc-card h5 {
        font-size: 16px;
        font-weight: 600;
        color: var(--font-color);
        margin: 8px 0 6px;
      }

      .description-content .desc-note,
      .description-content .compare-note {
        background: #f8fafc;
        border-left: 4px solid var(--main-color);
        padding: 14px 18px;
        border-radius: 0 8px 8px 0;
        margin: 18px 0;
        color: #475569;
        font-size: 14px;
      }

      .description-content .desc-banner {
        width: 100%;
        border-radius: 10px;
        overflow: hidden;
        margin: 20px 0;
      }

      .description-content .desc-banner img {
        width: 100%;
        height: auto;
        display: block;
      }

      .description-content table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        font-size: 14px;
      }

      .description-content th,
      .description-content td {
        padding: 12px 16px;
        border: 1px solid #e2e8f0;
        text-align: left;
      }

      .description-content th {
        background-color: #f1f5f9;
        font-weight: 600;
        color: var(--font-color);
      }

      .description-content tr:nth-child(even) {
        background-color: #f8fafc;
      }

      /* product details */
      .product-title {
        font-size: 28px;
        color: var(--font2-color);
        margin-bottom: 10px;
        font-weight: bold;
        font-family: 'Poppins', sans-serif;
      }

      .product-title .variant-label {
        display: block;
        font-size: 15px;
        font-weight: 500;
        color: var(--font2-color);
        margin-top: 4px;
    }

      .wishlist-btn{
        background:none;
        border:none;
        padding:5px;
        cursor:pointer;
        font-size:24px;
        color: var(--font2-color);
        transition: transform 0.2s ease, color 0.2s ease;
        display: flex;
        align-items: center;
      }

      .wishlist-btn.active i::before {
        content: "\f415"; /*bootstrap icon heart-fill encoding*/
        color: var(--main-color);
      }

      .category-tag {
        display: inline-block;
        border: 1px solid var(--search-border-color);
        border-radius: 6px;
        font-size: 12px;
        background-color: var(--secondary-color);
        padding: 4px 12px;
        margin-bottom: 15px;
      }

      .category-tag a {
        text-decoration: none;
        color: var(--main-color);
      }

      .price {
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 25px;
        color: var(--main-color);
      }

      .price-block {
      margin-bottom: 25px;
      }
      .discount-line {
          display: flex;
          align-items: center;
          gap: 10px;
          margin-top: 4px;
      }
      .original-price {
          text-decoration: line-through;
          color: #999;
          font-size: 14px;
      }
      .discount-badge {
          background: #e74c3c;
          color: #fff;
          font-size: 12px;
          font-weight: bold;
          padding: 2px 8px;
          border-radius: 4px;
      }

      /* selector group */
      .selector-group {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        color: var(--font2-color);
      }

        .option-btn-group {
          display: flex;
          flex-wrap: wrap;
          gap: 8px;
          margin-top: 15px;
      }
      .option-btn {
          padding: 8px 14px;
          border: 1px solid var(--search-border-color);
          border-radius: 4px;
          background: #fff;
          color: var(--font2-color);
          cursor: pointer;
          font-size: 13px;
          transition:0.3s;
      }
      .option-btn:hover {
          background: var(--secondary-color);
          color: var(--main-color);
      }
      .option-btn.selected {
          border-color: var(--main-color);
          color: var(--main-color);
          font-weight: bold;
          background: var(--secondary-color);
      }
      .option-btn.auto-selected {
          opacity: 0.7;
          cursor: not-allowed;
      }
      .option-btn[style*="display: none"] {
          display: none;
      }

      .quantity-input {
        display: flex;
        border: 1px solid var(--search-border-color);
        border-radius: 4px;
      }

      .quantity-input button {
        background: none;
        border: none;
        padding: 5px 12px;
        cursor: pointer;
      }

      .quantity-input input {
        width: 50px;
        text-align: center;
        border: none;
        border-left: 1px solid var(--search-border-color);
        border-right: 1px solid var(--search-border-color);
        background: none;
        color: var(--font-color);
      }

      select {
        width: 160px;
        padding: 6px;
        border: 1px solid var(--search-border-color);
        color: var(--font-color);
        border-radius: 4px;
      }

      #decoration{
        width: 160px;
        padding: 6px;
        border: 1px solid var(--search-border-color);
        color: var(--font-color);
        border-radius: 4px;
      }


      /* add ons */
      .add-ons {
        margin-top: 40px;
      }

      .add-ons h3 {
        font-size: 18px;
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
        margin-bottom: 10px;
      }

      hr {
        border: 0;
        border-top: 1px solid var(--search-border-color);
        margin-bottom: 20px;
      }

      .addon-item {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 20px;
      }

      .addon-item img {
        width: 80px;
        height: 80px;
        border-radius: 6px;
        object-fit: cover;
        border: 1px solid var(--search-border-color);
      }

      .addon-info {
        flex-grow: 1;
      }

      .addon-info p {
        margin: 0;
        font-weight: bold;
        font-size: 14px;
        color:var(--font2-color);
      }

      .addon-info span{
        font-size:13px;
        color:var(--font2-color);
      }

      .addon-info small {
        color: #e74c3c;
      }

      textarea {
        width: 100%;
        height: 80px;
        margin-top: 10px;
        padding: 10px;
        border: 1px solid var(--search-border-color);
        border-radius: 4px;
        resize: none;
      }

        #cardMessageInput{
        width: 100%; 
        height: 60px; 
        border: 1px solid var(--search-border-color);
        border-radius: 4px; 
        padding: 10px;
      }

      #cardMessageInput:required:invalid {
      border: 1px solid #ff4d4d ;
      }

      /* checkout summary */
      .checkout-summary {
      border-radius: 8px;
      }

      .subtotal-section span{
        font-size:15px;
      }

      .button-area button{
        border-radius: 6px;
        padding:13px;
        font-size:13px;
        border:none;
        width: 100%; 
        cursor: pointer;
        transition: 0.5s;
      }

      .btn-add{
        background-color:var(--main-color);
        color: #fff;
      }

      .btn-pay{
        background-color:var(--font-color);
        color: #fff;
      }

      .btn-add:hover,.btn-pay:hover{
        opacity:0.6;

      }

      /* reviews section */
      .reviews-section {
        margin-top: 60px;
        margin-left:-100px;
        padding-top: 40px;
       
      }

      .stars {
        color: #f1c40f; 
        font-size: 18px;
        margin: 10px 0;
      }

      .review-card {
        background: #fff;
        padding: 20px;
        border-radius: 8px;
        margin-top: 20px;
        border: 1px solid var(--search-border-color);
      }

      .user-info {
        display: flex;
        gap: 12px;
        align-items: center;
        font-size: 14px;
      }

      .avatar img {
        border-radius: 50%;
        width: 45px;
        height: 45px;
      }

      /* image zoom */
      .lightbox{
        display:none;
        position:fixed;
        z-index:9999;
        left:0;
        top:0;
        width:100%;
        height:100%;
        background-color:rgba(0,0,0,0.9);
        cursor:zoom-out;
        align-items:center;
        justify-content:center;
      }

      .lightbox-content{
        max-width:90%;
        max-height:85%;
        border-radius:4px;
        box-shadow:0 0 20px rgba(0,0,0,0.5);
        animation:zoomIn 0.3s ease;
      }

      .close-btn{
        position:absolute;
        top:30px;
        right:50px;
        color:white;
        font-size:50px;
        font-weight:bold;
        cursor:pointer;
      }

    

      /* zoom animation */
      @keyframes zoomIn {
        from { transform: scale(0.8); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
      }

      @media (max-width: 992px) {
        .product-container {
          grid-template-columns: 1fr;
          gap: 35px;
          margin-left: 0;
        }
        .back-section {
          margin-left: 0;
        }
        .product-info-section {
          margin-left: 0;
        }
        .reviews-section {
          margin-left: 0;
        }
      }
    </style>
    <?php if (!empty($custom_desc_css)): ?>
    <style id="custom-product-desc-css">
      <?= $custom_desc_css ?>
    </style>
    <?php endif; ?>
</head>

<body>
  <?php include 'include/header.php'; ?>

  <div class="page-wrapper">
    
    <div class="back-section">
      <a href="index.php" class="back-link">
        <i class="bi bi-chevron-left"></i>Back
      </a>
    </div>

    <form id="productForm" action="add_to_cart.php" method="POST">
      <?php if ($editing_cart_item): ?>
        <input type="hidden" name="cart_item_id"
        value="<?php echo $editing_cart_item['CART_ITEM_ID']; ?>">
      <?php endif; ?>

      <input type="hidden" name="product_id" value="<?php echo intval($product_id); ?>">
      <div class="product-container">
    <!-- product image -->

          <div class="product-gallery">
              <div class="main-image">
                  <img id="mainImg" src="admin/<?php echo htmlspecialchars($product['COVER_IMAGE']); ?>" alt="Main Product">
              </div>
              <?php if (!empty($images)): ?>
              <div class="thumbnail-list">
                  <img src="admin/<?php echo htmlspecialchars($product['COVER_IMAGE']); ?>" class="active-thumb" onclick="switchMainImage(this.src, this)" alt="Thumb">
                  <?php foreach ($images as $img):?>
                  <img src="admin/<?php echo htmlspecialchars($img['IMAGE_PATH']); ?>" onclick="switchMainImage(this.src, this)" alt="Thumb">
                  <?php endforeach;?>
              </div>
              <?php endif; ?>
          </div>

          <div class="product-details">
            <div class="header-section">
              <div class="title-wrapper d-flex align-items-center justify-content-between">
                <h1 class="product-title" data-base-name="<?php echo htmlspecialchars($product['PRODUCT_NAME']); ?>"><?php echo htmlspecialchars($product['PRODUCT_NAME']);?></h1>
                <a class="wishlist-btn <?php echo $in_wishlist ? 'active' : ''; ?>" data-product-id="<?php echo $product_id;?>">
                <i class="bi <?php echo $in_wishlist ? 'bi-heart-fill' : 'bi-heart'; ?>"></i></a>
              </div>
              <span class="category-tag"><a href="product catalogue.php?id=<?php echo $product['CATEGORY_NAME'];?>"><?php echo htmlspecialchars($product['CATEGORY_NAME']);?></a></span>
              <div class="product-meta" style="font-size: 13px; color: var(--font2-color); margin-bottom: 10px;">
                <?php if (!empty($product['BRAND'])): ?>
                    <span>Brand: <strong><?php echo htmlspecialchars($product['BRAND']); ?></strong></span>
                <?php endif; ?>
                <?php if (!empty($product['PRODUCT_CODE'])): ?>
                    <span style="margin-left: 15px;">Code: <strong><?php echo htmlspecialchars($product['PRODUCT_CODE']); ?></strong></span>
                <?php endif; ?>
              </div>
              <div class="price-block">
                  <div class="price">
                      RM <span id="displayPrice">
                          <?php 
                          if ($min_price == $max_price) {
                              echo number_format($min_price, 2);
                          } else {
                              echo number_format($min_price, 2) . ' - ' . number_format($max_price, 2);
                          }
                          ?>
                      </span>
                  </div>

                  <?php if ($has_discount): ?>
                  <div class="discount-line" id="discountLine">
                      <span class="original-price" id="originalPriceText">
                          RM <?php 
                              if ($min_original == $max_original) {
                                  echo number_format($min_original, 2);
                              } else {
                                  echo number_format($min_original, 2) . ' - RM' . number_format($max_original, 2);
                              }
                          ?>
                      </span>
                      <span class="discount-badge" id="discountBadge">
                          -<?php echo ($min_discount == $max_discount) ? $min_discount : $min_discount . '~' . $max_discount; ?>%
                      </span>
                  </div>
                  <?php endif; ?>
              </div>
            </div>
            
            
            <div class="selectors">
              <div class="selector-group">

                <!-- quantity -->
                <label>Quantity:</label>
                <div class="quantity-input">
                  <button type="button" onclick="changeQty(this, -1)">-</button>
                  <input type="text" name="quantity" value="1" data-max="<?= $initial_stock;?>" readonly>
                  <button type="button" onclick="changeQty(this, 1)">+</button>
                </div>
              </div>

              <!-- select size -->
              <?php if (!empty($option_groups)): ?>
                <?php foreach ($option_groups as $idx => $group): ?>
                      <div class="selector-group" style="display:block;">
                          <label><?php echo htmlspecialchars($group['OPTION_NAME']); ?></label>
                          <div class="option-btn-group" data-level="<?php echo $idx; ?>">
                              <?php foreach ($group['VALUES'] as $val): ?>
                                  <button type="button" class="option-btn" data-value-id="<?php echo $val['OPTION_VALUE_ID']; ?>">
                                      <?php echo htmlspecialchars($val['VALUE_NAME']); ?>
                                  </button>
                              <?php endforeach; ?>
                          </div>
                      </div>
                  <?php endforeach; ?>
                  <input type="hidden" id="variantSelect" name="variant_id" value="" data-price="0" data-stock="0">
              <?php else: ?>
                  <input type="hidden" id="variantSelect" name="variant_id"
                      value="<?php echo $variants[0]['VARIANT_ID'] ?? ''; ?>"
                      data-price="<?php echo $initial_price; ?>"
                      data-stock="<?php echo $initial_stock; ?>">
              <?php endif; ?>

            <!-- add on -->
            <div class="add-ons">
              <h3>Add On</h3>
              <hr>
              <?php if (count($all_addons) > 0): ?>
                <?php foreach ($all_addons as $addon):?>
                  <?php 
                    $checked = false;
                    $addonQty = 1;
                    if ($editing_cart_item && isset($editing_addons[$addon['ADD_ON_ID']])) {
                        $checked = true;
                        $addonQty = $editing_addons[$addon['ADD_ON_ID']];
                    }
                  ?>
                  <div class="addon-item">
                    <input type="checkbox" name ="selected_addons[]" 
                    value="<?php echo $addon['ADD_ON_ID'];?>" 
                    <?php if ($checked) echo 'checked'; ?>
                    <?php if ($addon['ADD_ON_STOCK'] <= 0) echo 'disabled'; ?>
                    data-price="<?php echo $addon['ADD_ON_PRICE'];?>" 
                    onclick="calculationTotal(); <?php if($addon['ADD_ON_ID'] == 3): ?>toggleCardRequired(this);<?php endif;?>"> 
                    
                    <?php if(!empty($addon['ADD_ON_IMAGE'])): ?>
                      <?php $imgSrc = resolveAddonImage($addon['ADD_ON_IMAGE']); ?>
                      <img src="<?= htmlspecialchars($imgSrc) ?>" alt="<?= htmlspecialchars($addon['ADD_ON_NAME']) ?>">
                    <?php else: ?>
                      <div style="width:80px; height:80px; background:var(--secondary-color); display:flex; align-items:center; justify-content:center; border-radius:6px; font-size:10px; color:var(--font2-color);">No Image</div>
                    <?php endif;?>

                    <div class="addon-info">
                      <p><?php echo htmlspecialchars($addon['ADD_ON_NAME']);?></p>
                      <span>RM <?php echo number_format($addon['ADD_ON_PRICE'],2);?></span>
                      <?php if ($addon['ADD_ON_STOCK'] <= 0): ?>
                        <small>Out of stock</small>
                      <?php endif; ?>
                    </div>

                    <div class="quantity-input">
                      <button type="button" <?php if ($addon['ADD_ON_STOCK'] <= 0) echo 'disabled'; ?> onclick="changeQty(this, -1); calculationTotal()">-</button>
                      <input type="text" name="addon_qty[<?php echo trim($addon['ADD_ON_ID']); ?>]" value="<?php echo $addonQty; ?>" data-max="<?php echo $addon['ADD_ON_STOCK']; ?>" readonly>
                      <button type="button" <?php if ($addon['ADD_ON_STOCK'] <= 0) echo 'disabled'; ?> onclick="changeQty(this, 1); calculationTotal()">+</button>
                    </div>
                  </div>

                  <?php if($addon['ADD_ON_ID'] == 3):?>
                    <div id="cardMessageArea" style="margin-left: 30px; margin-top: 10px; <?php echo $checked ? 'display:block;' : 'display:none;'; ?>">
                      <?php
                      $card_text = '';

                      if (isset($editing_card_text)) {
                          $card_text = $editing_card_text;
                      }
                      ?>
                      <textarea id="cardMessageInput" name="card_message" placeholder="Write your greeting message here..."
                      
                      maxlength="50"><?php echo $card_text; ?></textarea>
                      <small style="color: var(--font2-color); float: right;">
                        <span id="cardCount">0</span> / 50
                      </small>
                    </div>
                  <?php endif;?>
                <?php endforeach;?>
              <?php endif;?>

            </div>

             <!-- checkout summary section -->
       <div class="checkout-summary mt-4 p-3" >
          <div class="subtotal-section d-flex justify-content-between mb-2">
              <span>Product Price (x<span id="summaryQty">1</span>)</span>
              <span>RM <span id="summaryProductPrice"><?php echo number_format($initial_price, 2); ?></span></span>
          </div>
          <div class="subtotal-section d-flex justify-content-between mb-2">
              <span>Options Price (Add-ons)</span>
              <span>RM <span id="summaryOptionPrice">0.00</span></span>
          </div>
          <hr>
          <div class="total-section d-flex justify-content-between align-items-center mb-3">
              <strong style="font-size: 1.2rem;">Total</strong>
              <strong style="font-size: 1.2rem; color: var(--font-color);">RM <span id="summaryTotal"><?php echo number_format($initial_price, 2); ?></span></strong>
          </div>
          
          <div class="row g-2">
            <div class="button-area col-6">
              <button type="button" id="addToCartBtn" name="add_to_cart" class=" btn-add">ADD TO CART</button>
            </div>
            <div class="button-area col-6">
              <button type="button" id="buyNowBtn" name="buy_now" class=" btn-pay">CHECK OUT</button>
            </div>
            </div>
          </div>
        </div>
      </div>
      </div>  
    </form>

    <!-- product info accordion (Description, Features, Packing List) -->
    <div class="product-info-section">
      <div class="accordion">
          <!-- Description Accordion Item -->
          <div class="accordion-item active">
              <div class="accordion-header">Description <span>-</span></div>
              <div class="accordion-content">
                  <div class="description-content">
                      <?php echo !empty($clean_description) ? $clean_description : '<p>No description available for this product.</p>'; ?>
                  </div>
              </div>
          </div>

          <!-- Features Accordion Item -->
          <div class="accordion-item">
              <div class="accordion-header">Features <span>+</span></div>
              <div class="accordion-content">
                  <?php if (!empty($features_data)): ?>
                      <?php foreach ($features_data as $group): ?>
                          <?php if (!empty($group['category'])): ?>
                              <p style="font-weight:bold; margin-bottom:4px; color: var(--font-color);"><?php echo htmlspecialchars($group['category']); ?></p>
                          <?php endif; ?>
                          <ul style="margin-bottom:12px;">
                              <?php foreach ($group['points'] as $point): ?>
                                  <li>
                                      <?php echo is_array($point) ? htmlspecialchars($point['text']) : htmlspecialchars($point); ?>
                                      <?php if (is_array($point) && !empty($point['sub_points'])): ?>
                                          <ul>
                                              <?php foreach ($point['sub_points'] as $sub): ?>
                                                  <li><?php echo htmlspecialchars($sub); ?></li>
                                              <?php endforeach; ?>
                                          </ul>
                                      <?php endif; ?>
                                  </li>
                              <?php endforeach; ?>
                          </ul>
                      <?php endforeach; ?>
                  <?php else: ?>
                      <p>No features listed.</p>
                  <?php endif; ?>
              </div>
          </div>

          <!-- Packing List Accordion Item -->
          <div class="accordion-item">
              <div class="accordion-header">Packing List <span>+</span></div>
              <div class="accordion-content">
                  <?php if (!empty($packing_data)): ?>
                      <?php foreach ($packing_data as $group): ?>
                          <?php if (!empty($group['group'])): ?>
                              <p style="font-weight:bold; margin-bottom:4px; color: var(--font-color);"><?php echo htmlspecialchars($group['group']); ?></p>
                          <?php endif; ?>
                          <ul style="margin-bottom:12px;">
                              <?php foreach ($group['items'] as $item): ?>
                                  <li><?php echo intval($item['qty']); ?> x <?php echo htmlspecialchars($item['item']); ?></li>
                              <?php endforeach; ?>
                          </ul>
                      <?php endforeach; ?>
                  <?php else: ?>
                      <p>No packing list available.</p>
                  <?php endif; ?>
              </div>
          </div>
      </div>
    </div>

    <!-- reviews -->
    <div class="reviews-section">
      <h3>Customer Reviews</h3>
        <div class="review-header d-flex align-items-center gap-3">
          <div class="stars">
            <?php for ($i=1; $i<=5; $i++) {
              echo ($i <= round($display_rating)) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
            }
            ?>
          </div>
          <span class="review-count text-muted">Based on <?php echo $review_count;?> reviews</span>
          
          <select onchange="location.href='?id=<?php echo $product_id; ?>&sort='+this.value" style="margin-left: auto; width: auto;">
            <option value="high_ratings" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'high_ratings') ? 'selected' : ''; ?>>Highest Ratings</option>
            <option value = "new_ratings" <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'new_ratings') ? 'selected' : ''; ?>>Latest Ratings</option>
          </select>
        </div>

        <?php if ($review_count > 0) : ?>
          <?php foreach($reviews as $r): ?>
            <div class="review-card">
              <div class="user-info">
                <div class="avatar">
                  <?php 
                 // determine avatar if its value is null
                  if ($r['PROFILE_IMAGE'] === null || $r['PROFILE_IMAGE'] === '') {
                      // if no profile image, use default avatar
                      $avatar_url = 'image/user image/user_default.jpg';
                  } else {
                      // if profile image exist, use the uploaded image
                      $avatar_url = $r['PROFILE_IMAGE'];
                  }
                  ?>
                  <img src="<?php echo htmlspecialchars($avatar_url, ENT_QUOTES); ?>" alt="User">
                </div>
                <div>
                  <p class="username mb-0" style="font-weight: bold;"><?php echo htmlspecialchars($r['CUSTOMER_NAME']);?></p>
                  <small class="text-muted"><?php echo date('d-m-Y', strtotime($r['CREATED_AT']));?></small>
                </div>
              </div>
              <div class="stars small">
                <?php for ($i=1; $i<=5; $i++){
                  echo ($i <= $r['RATING']) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
                }
                ?>
              </div>

              <p class="reviews-content"><?php echo htmlspecialchars($r['COMMENTS']);?></p>
              <?php if ($r['REVIEW_IMAGE']):?>
                <img src="<?php echo htmlspecialchars ($r['REVIEW_IMAGE']);?>" alt="review" style ="width: 80px; border-radius: 4px; cursor:pointer;" onclick="openLightbox(this.src)">
              <?php endif;?>

              <?php if(!empty($r['REPLY_TEXT'])) :?>
                <?php 
                  $replies = explode('||', $r['REPLY_TEXT']);
                  $reply_dates = explode('||', $r['REPLY_DATE']);
                ?>
                <?php foreach($replies as $idx => $reply_text): ?>
                  <div class="admin-reply mt-3 p-3" style="background-color: var(--secondary-color); border-left: 4px solid var(--main-color); border-radius: 4px;">
                    <p class="mb-1" style="font-weight: bold; color: var(--font-color);">
                      <i class="bi bi-reply-fill"></i> Admin Response:
                    </p>
                    <p class="mb-0" style="font-size: 14px; color: var(--font2-color);">
                      <?php echo htmlspecialchars($reply_text); ?>
                    </p>
                    <small class="text-muted" style="font-size: 11px;">
                      Replied on: <?php echo date('d-m-Y', strtotime($reply_dates[$idx])); ?>
                    </small>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div> 
          <?php endforeach;?>
        <?php else:?>
          <p class="text-muted">No reviews yet for this product.</p>
        <?php endif;?>
    </div>

    <!-- lightbox -->
    <div id="imageLightbox" class="lightbox" onclick="closeLightbox()">
      <span class="close-btn"> &times;</span>
      <img class="lightbox-content" id="expandedImg">
    </div>
  </div>

  <?php include 'include/footer.php'?>

  <script>
    const productVariants = <?php echo json_encode($variants); ?>;
    const variantOptionMap = <?php echo json_encode($variant_option_map); ?>;
    const optionGroups = <?php echo json_encode($option_groups); ?>;
    let selectedOptions = {}; // level(数字) -> value_id

    function findMatchingVariants(partialIds) {
        return Object.keys(variantOptionMap).filter(vid =>
            partialIds.every(sel => variantOptionMap[vid].includes(sel))
        );
    }

    function selectOption(level, valueId, btn) {
        selectedOptions[level] = valueId;
        // 这一层之後的选择要重新算，先清掉更深层的选择
        Object.keys(selectedOptions).forEach(k => {
            if (parseInt(k) > level) delete selectedOptions[k];
        });

        btn.parentElement.querySelectorAll('.option-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');

        updateAvailableOptions();
        updateSelectedVariant();
    }

    function updateAvailableOptions() {
        optionGroups.forEach((group, idx) => {
            if (idx === 0) return; // 第一层永远全部可选

            const priorIds = [];
            for (let i = 0; i < idx; i++) {
                if (selectedOptions[i] !== undefined) priorIds.push(selectedOptions[i]);
            }
            if (priorIds.length < idx) return; // 前面几层还没选完，先不处理这层

            const matchingVids = findMatchingVariants(priorIds);
            const validValueIds = new Set();
            matchingVids.forEach(vid => {
                variantOptionMap[vid].forEach(oid => {
                    if (group.VALUES.some(v => v.OPTION_VALUE_ID == oid)) validValueIds.add(oid);
                });
            });

            const container = document.querySelector(`.option-btn-group[data-level="${idx}"]`);
            const buttons = container.querySelectorAll('.option-btn');
            const onlyOne = validValueIds.size === 1;

            buttons.forEach(btn => {
                const vid = parseInt(btn.dataset.valueId);
                if (validValueIds.has(vid)) {
                    btn.style.display = '';
                    btn.disabled = false;
                    btn.classList.remove('auto-selected');
                    if (onlyOne) {
                        btn.classList.add('selected', 'auto-selected');
                        btn.disabled = true;
                        selectedOptions[idx] = vid;
                    }
                } else {
                    btn.style.display = 'none';
                    btn.classList.remove('selected');
                }
            });
        });
    }

    function updateSelectedVariant() {
        const selectedIds = Object.values(selectedOptions);
        if (selectedIds.length < optionGroups.length) return;

        const matches = findMatchingVariants(selectedIds)
            .filter(vid => variantOptionMap[vid].length === selectedIds.length);
        if (matches.length === 0) return;

        const variant = productVariants.find(v => v.VARIANT_ID == matches[0]);
        if (!variant) return;

        const hidden = document.getElementById('variantSelect');
        hidden.value = variant.VARIANT_ID;
        hidden.dataset.price = variant.DISPLAY_PRICE;
        hidden.dataset.stock = variant.VARIANT_STOCK;

        const qtyInput = document.querySelector('input[name="quantity"]');
        qtyInput.setAttribute('data-max', variant.VARIANT_STOCK);
        if (parseInt(qtyInput.value) > variant.VARIANT_STOCK) qtyInput.value = variant.VARIANT_STOCK;
        updateQtyButtonStates(qtyInput.parentElement);

        //更新价格显示：从区间变成单一价格
        const displayPrice = document.getElementById('displayPrice');
        if (displayPrice) displayPrice.innerText = parseFloat(variant.DISPLAY_PRICE).toFixed(2);
        
        //更新标题副名字
        const titleEl = document.querySelector('.product-title');
        if (titleEl) {
            const baseName = titleEl.dataset.baseName;
            titleEl.innerHTML = variant.VARIANT_LABEL 
                ? baseName + '<span class="variant-label">' + variant.VARIANT_LABEL + '</span>' 
                : baseName;
        }

        //更新折扣显示
        const discountLine = document.getElementById('discountLine');
        const originalPriceText = document.getElementById('originalPriceText');
        const discountBadge = document.getElementById('discountBadge');

        if (variant.SALE_PRICE && parseFloat(variant.SALE_PRICE) < parseFloat(variant.VARIANT_PRICE)) {
            const percent = Math.round((variant.VARIANT_PRICE - variant.SALE_PRICE) / variant.VARIANT_PRICE * 100);
            if (originalPriceText) originalPriceText.innerText = 'RM ' + parseFloat(variant.VARIANT_PRICE).toFixed(2);
            if (discountBadge) discountBadge.innerText = '-' + percent + '%';
            if (discountLine) discountLine.style.display = 'flex';
        } else {
            if (discountLine) discountLine.style.display = 'none';
        }

        calculationTotal();
    }

    document.querySelectorAll('.option-btn-group').forEach(container => {
        const level = parseInt(container.dataset.level);
        container.querySelectorAll('.option-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                selectOption(level, parseInt(this.dataset.valueId), this);
            });
        });
    });



    // info collapse
  document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
      const item = header.parentElement;

      //convert to current clicking status
      item.classList.toggle('active');

      //changing symbol "+" to "-"
      const icon = header.querySelector('span');
      if (item.classList.contains('active')){
        icon.textContent = '-';
      } else{
        icon.textContent = '+';
      }
    });
  });

  function switchMainImage(src, thumbEl){
    const mainImg = document.getElementById('mainImg');
    if (mainImg) mainImg.src = src;
    document.querySelectorAll('.thumbnail-list img').forEach(t => t.classList.remove('active-thumb'));
    if (thumbEl) thumbEl.classList.add('active-thumb');
  }

  //zooom image

  function openLightbox(src){
    if(!src) return;
    const lightbox = document.getElementById('imageLightbox');
    const expandedImg = document.getElementById('expandedImg');

    lightbox.style.display = 'flex';
    expandedImg.src = src;
    document.body.style.overflow = 'hidden';
  }

  function closeLightbox(){
    const lightbox = document.getElementById('imageLightbox');
    lightbox.style.display = 'none';
    document.body.style.overflow = 'auto';
  }

    document.addEventListener('DOMContentLoaded',function(){
    const images = document.querySelectorAll('.main-image img, .thumbnail-list img, .review-card img');

    images.forEach(img => {
      img.style.cursor = 'zoom-in';
      img.onclick = function(){
        openLightbox(this.src);
      };
    });

    calculationTotal();
  });

  //add to wishlist process
  document.querySelector('.wishlist-btn').addEventListener('click',function(e) {
    e.preventDefault();

    const btn = this;
    const productId = btn.getAttribute('data-product-id');
    const icon = btn.querySelector('i');

    //sent the AJAX request
    fetch('add_to_wishlist.php', {
      method: 'POST',
      headers: {
      'X-Requested-With': 'XMLHttpRequest' ,
      'Content-Type': 'application/x-www-form-urlencoded' 
      },
      body:'product_id=' + productId
    })

    .then(response => response.json())
    .then(data => {
      if(data.status === 'success') {
        icon.classList.toggle('bi-heart');
        icon.classList.toggle('bi-heart-fill');
        btn.classList.toggle('active');
        alert(data.message);
      }else if (data.status == 'error') {
        alert(data.message);
        if(data.message === 'Please login first') {
          window.location.href = 'login.php';
        }
      }
    })
    .catch(error => console.error('Error:' , error));
  });

  //add to cart process
  document.getElementById('addToCartBtn').addEventListener('click', function() {
    //check whether if selected the variant
    const variantId = document.getElementById('variantSelect').value;
    if (!variantId) {
      alert("Please select a variant first!");
      return;
    }

    //verify when user check the card box but field is empty
     const cardCheckbox = document.querySelector('input[name="selected_addons[]"][value="3"]');
      const cardMessage = document.getElementById('cardMessageInput');
      if (cardCheckbox && cardCheckbox.checked && cardMessage.value.trim() === '') {
      alert("Please write your greeting message for the card!");
      cardMessage.focus();
      return;
    }  
  

    const form = document.getElementById('productForm');
    const formData = new FormData(form);

    fetch('add_to_cart.php', {
      method: 'POST',
      body: formData
    })

    .then(response => {
        return response.json();
    })

    .then(data => {
      if (data.status === 'success') {
          if (data.redirect){
            window.location.href = data.redirect;
          }else {
            alert("✨ " + data.message);
          }
        
      }else {
        //haven't login and redirect user to login
        alert(data.message);
            if (data.message === 'Please login first') {
                window.location.href = 'login.php';
            }
        }
  })
  .catch(error => {
          console.error('Error:', error);
          alert("Ops! error details:" + error.message);
      });
  });

  //check out process
  document.getElementById('buyNowBtn').addEventListener('click', function() {
    const variantId = document.getElementById('variantSelect').value;
    if (!variantId){
      alert('Please select a size first!');
      return;
    }
    
    //verify when user check the card box but field is empty
     const cardCheckbox = document.querySelector('input[name="selected_addons[]"][value="3"]');
      const cardMessage = document.getElementById('cardMessageInput');
      if (cardCheckbox && cardCheckbox.checked && cardMessage.value.trim() === '') {
      alert("Please write your greeting message for the card!");
      cardMessage.focus();
      return;
    }  
  
    const form = document.getElementById('productForm');
    const formData = new FormData(form);
    formData.append('buy_now', '1');

    fetch('add_to_cart.php', {
        method: 'POST',
        body: formData
    })

    .then(response => response.json()) 
    .then(data => {
      if (data.status === 'success') {
        if (data.redirect) {
          window.location.href = data.redirect;
        } else {
          window.location.href = 'payment.php'; 
        }
      } else {
        alert(data.message);
        if (data.message === 'Please login first') {
          window.location.href = 'login.php';
        }
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert("Ops! error details: " + error.message);
    });
  });



  //update price and sync product quantity limit to selected variant stock
  function updatePrice(){
    const variantSelect = document.getElementById('variantSelect');
    const qtyInput = document.querySelector('input[name="quantity"]');
    if (variantSelect && qtyInput) {
      const selectOption = variantSelect.options[variantSelect.selectedIndex];
      const stock = parseInt(selectOption.getAttribute('data-stock'), 10) || 1;
      qtyInput.setAttribute('data-max', stock);

      let qty = parseInt(qtyInput.value, 10) || 1;
      if (qty > stock) {
        qtyInput.value = stock;
      }
      updateQtyButtonStates(qtyInput.parentElement);
    }
    calculationTotal();
  }

  function updateQtyButtonStates(qtyContainer) {
    const input = qtyContainer.querySelector('input');
    if (!input) return;
    const maxStock = parseInt(input.getAttribute('data-max'), 10) || 999;
    const value = parseInt(input.value, 10) || 1;
    const minusBtn = qtyContainer.querySelector('button:first-child');
    const plusBtn = qtyContainer.querySelector('button:last-child');
    if (minusBtn) minusBtn.disabled = value <= 1;
    if (plusBtn) plusBtn.disabled = value >= maxStock;
  }


  //total calculation
  function calculationTotal(){
    //get the basic unit price
    const variantSelect = document.getElementById('variantSelect');
    if (!variantSelect) return;

    //get the variant price
    const unitPrice = parseFloat(variantSelect.dataset.price) || 0;

    //get the quantity
    const qtyInput = document.querySelector('input[name="quantity"]');
    const qty = parseInt(qtyInput.value) || 1;

    //calculate the addon total price
    let totalAddonPrice = 0;

    document.querySelectorAll('input[name="selected_addons[]"]:checked').forEach(checkbox => {
      
      //find the corresponding quantity of addon
      const addonId = checkbox.value;
      const addonQtyInput = document.querySelector(`input[name="addon_qty[${addonId.trim()}]"]`);
      const addonQty = (addonQtyInput && addonQtyInput.value) ? parseInt(addonQtyInput.value) : 1;
      
      const addonPrice = parseFloat(checkbox.getAttribute('data-price')) || 0;
      totalAddonPrice += (addonPrice * addonQty);
    });

    //update ui display
    const productSubtotal = unitPrice * qty;
    const finalTotal = productSubtotal + totalAddonPrice;

    //update checkout summary
    const summaryQty = document.getElementById('summaryQty');
    const summaryProductPrice = document.getElementById('summaryProductPrice');
    const summaryOptionPrice = document.getElementById('summaryOptionPrice');
    const summaryTotal = document.getElementById('summaryTotal');
    const displayPrice = document.getElementById('displayPrice');

    if (summaryQty) summaryQty.innerText = qty;
    if (summaryProductPrice) summaryProductPrice.innerText = productSubtotal.toFixed(2);
    if (summaryOptionPrice) summaryOptionPrice.innerText = totalAddonPrice.toFixed(2);
    if (summaryTotal) summaryTotal.innerText = finalTotal.toFixed(2);

  }

  //qty button
  function changeQty(btn, delta) {
    const qtyContainer = btn.parentElement;
    const input = qtyContainer.querySelector('input');
    const maxStock = parseInt(input.getAttribute('data-max'), 10) || 999;
    let value = parseInt(input.value, 10) || 1;

    value += delta;
    input.value = Math.min(Math.max(1, value), maxStock);

    updateQtyButtonStates(qtyContainer);
    calculationTotal();
  }

  document.addEventListener('DOMContentLoaded', function() {
    updatePrice();
    document.querySelectorAll('.quantity-input').forEach(updateQtyButtonStates);

    // card message word count
    const cardInput = document.getElementById('cardMessageInput');
    const cardCount = document.getElementById('cardCount');
    if (cardInput && cardCount) {
        cardCount.textContent = cardInput.value.length;
        cardInput.addEventListener('input', function() {
            cardCount.textContent = this.value.length;
        });
    }

    // cake writing word count
    const cakeInput = document.getElementById('cakeWritingId');
    if (cakeInput) {
        const cakeCounter = document.createElement('small');
        cakeCounter.style.cssText = 'color: var(--font2-color); float: right;';
        cakeInput.insertAdjacentElement('afterend', cakeCounter);

        cakeInput.setAttribute('maxlength', '50');
        cakeInput.addEventListener('input', function() {
            document.getElementById('cakeCount').textContent = this.value.length;
        });
    }
  });

  //if card text selected is required to fill up the textbox
  function toggleCardRequired(checkbox){
    const messageArea = document.getElementById('cardMessageArea');
    const messageInput = document.getElementById('cardMessageInput');
    if (checkbox.checked) {
       //required to filled
        messageArea.style.display = 'block';
        messageInput.setAttribute('required', 'required');
    }else {
      messageArea.style.display = 'none';
      messageInput.removeAttribute('required');
      messageInput.value = '';
      cardCount.textContent = cardInput.value.length;
      
      //word count
      const counter = document.getElementById('cardCount');
        if (counter) counter.textContent = '0';
    }

  }


</script>
</body>


</html>