<?php 
include 'include/config.php';
session_start();

if (!function_exists('getCategoryIcon')) {
    function getCategoryIcon($cat_name) {
        $name = strtolower($cat_name);
        if (strpos($name, 'main board') !== false || strpos($name, 'microcontroller') !== false) {
            return 'bi-cpu';
        } elseif (strpos($name, 'kit') !== false || strpos($name, 'bundle') !== false) {
            return 'bi-box-seam';
        } elseif (strpos($name, 'expansion') !== false || strpos($name, 'shield') !== false) {
            return 'bi-motherboard';
        } elseif (strpos($name, 'power') !== false || strpos($name, 'wire') !== false || strpos($name, 'cable') !== false || strpos($name, 'battery') !== false) {
            return 'bi-lightning-charge';
        } elseif (strpos($name, 'accessori') !== false || strpos($name, 'part') !== false || strpos($name, 'tool') !== false) {
            return 'bi-tools';
        } elseif (strpos($name, 'robot') !== false || strpos($name, 'car') !== false) {
            return 'bi-robot';
        } elseif (strpos($name, 'sensor') !== false) {
            return 'bi-broadcast-pin';
        }
        return 'bi-grid-fill';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Homepage</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=7.0">
    <link rel="stylesheet" href="css/footer.css?v=7.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root
      {
        --main-color: #80b8d2;
        --main-dark: #3c8cb1;
        --font-color:#1B2A3C;
        --secondary-color:#F4F8FC;
        --section-alt-bg: #F7FAFD;
        --card-bg-color: #EBF4FC;
        --rating-color:#F5A623;
        --search-border-color:#C9DCEE;
        --border-subtle: #E2EDF7;
        --bg-color: #FFFFFF;
        --font2-color:#52708A;
        /*hover effect*/
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
      }

      body{
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        width:100%;
        overflow-x: hidden;
      }
      
      /* hero */
      .hero-section
      {
        background: linear-gradient(155deg, #E6F1FA 0%, #F2F7FC 55%, #EBF3FA 100%);
        position: relative;
        min-height: 440px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        padding: 60px 80px 85px;
        margin-bottom: 0;
      }

      .hero-section::before {
        content: '';
        position: absolute;
        right: -60px;
        top: -60px;
        width: 440px;
        height: 440px;
        background: radial-gradient(circle, rgba(128, 184, 210, 0.3) 0%, rgba(128, 184, 210, 0) 70%);
        border-radius: 50%;
        filter: blur(25px);
        pointer-events: none;
      }

      .hero-section::after {
        content: '';
        position: absolute;
        right: 140px;
        bottom: -60px;
        width: 280px;
        height: 280px;
        background: radial-gradient(circle, rgba(60, 140, 177, 0.18) 0%, rgba(60, 140, 177, 0) 70%);
        border-radius: 50%;
        filter: blur(30px);
        pointer-events: none;
      }

      .hero-wave {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        line-height: 0;
        overflow: hidden;
        pointer-events: none;
        z-index: 3;
      }

      .hero-wave svg {
        position: relative;
        display: block;
        width: 100%;
        height: 36px;
      }

      .hero-wave path {
        fill: #FFFFFF;
      }

      .hero-eyebrow{
        display: inline-block;
        color: var(--main-color);
        font-weight: 700;
        font-size: 13px;
        letter-spacing: 1.5px;
        text-transform: uppercase;
        margin-bottom: 14px;
      }

      .container{
        position: relative; 
        z-index: 2; 
        max-width: 550px; 
        text-align: left;
        margin-left: 0;
      }

      .container h1{
        font-family: 'Poppins', sans-serif;
        font-size: 44px;
        font-weight: 800;
        color: var(--font-color);
        line-height: 1.25;
        margin-bottom: 15px;
      }

      .container h1 span {
        color: var(--main-color);
      }

      .container p {
        font-size: 17px;
        color: var(--font2-color);
        margin-bottom: 32px;
        line-height: 1.6;
        opacity: 0.9;
      }

      .learn-btn{
        display: inline-block;
        background: var(--main-color);
        border-radius: 8px;
        transition: 0.3s all ease;
        border: none;
        
      }

      .learn-btn a{
        display: block;
        color: #FFFFFF;
        font-weight: 600;
        padding: 14px 36px;
        text-decoration: none;
        font-family: 'Inter', sans-serif;
        font-size: 16px;
      }

      .learn-btn:hover{
        background: var(--font2-color);
        
      }

      /* main content */
      .main-content{
        background-color: #FFFFFF;
        padding-bottom: 0;
      }

      /* section layered styling */
      .content-section {
        position: relative;
        padding: 55px 0 65px;
      }

      .best-selling-section,
      .best-selling {
        background-color: #FFFFFF;
      }

      .best-selling-section {
        padding-top: 45px;
      }

      .recommended-section {
        background-color: var(--secondary-color);
        border-top: 1px solid var(--border-subtle);
        border-bottom: 1px solid var(--border-subtle);
      }

      .more-categories-section {
        background-color: #FFFFFF;
      }

      .section-title{
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        margin-left: 0px;
        margin-bottom: 30px;
        position: relative;
        display: inline-block;
      }

      .section-title::after {
        content: '';
        position: absolute;
        bottom: -8px; left: 0;
        width: 50px; height: 3px;
        background: var(--main-color);
        border-radius: 2px;
      }
      
      .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0 80px;
        max-width: 1400px;
        margin: 0 auto 20px;
      }

      .view-all-btn {
        color: var(--font2-color);
        text-decoration: none;
        font-weight: 600;
        font-size: 15px;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 5px;
      }

      .view-all-btn:hover {
        color: var(--main-color);
        transform: translateX(5px);
      }

      /* slider */
      .slider-wrapper {
        position: relative; 
        display: flex;
        align-items: center;
        padding: 0 80px;
        background-color: transparent;
        max-width: 1400px;
        margin: 0 auto;
      }

      .cake-grid{
        display: flex;
        justify-content: flex-start;
        gap: 30px;
        overflow-x: auto;
        scroll-behavior: smooth;
        scrollbar-width: none;
        padding: 20px 10px;
        width: 100%;
      }

      .cake-grid.centered{
        justify-content: center;
      }

      .cake-grid::-webkit-scrollbar{
        display: none;
      }

      .cake-item {
        flex: 0 0 calc(20% - 12px);
        min-width: 200px;
        text-align: center;
        background: #fff;
        border: 1px solid var(--search-border-color);
        border-radius: 8px;
        transition: 0.35s ease;
      }

      .cake-item:hover {
        transform: translateY(-8px);
        box-shadow: 0 14px 28px rgba(46,134,222,0.15);
      }

      .cake-item img {
        height:220px;
        width: 100%;
        border-radius: 8px;
        aspect-ratio: 1/1;
        object-fit: cover;
      }

      .cake-img-wrap {
          position: relative;
      }
      .badge-best-seller,
      .badge-discount {
          position: absolute;
          top: 8px;
          left: 8px;
          font-size: 11px;
          font-weight: 700;
          padding: 4px 8px;
          border-radius: 4px;
          color: #fff;
          z-index: 2;
      }
      .badge-best-seller {
          background-color: #f5a623;
      }
      .badge-discount {
          background-color: #3c8cb1;
      }
      .original-price {
          text-decoration: line-through;
          color: #999;
          font-size: 12px;
          font-weight: normal;
          margin-left: 6px;
      }

      .cake-name{
        color:var(--font2-color);
        font-weight:bold;
        font-family: 'Inter', sans-serif;
        margin:12px 10px 10px;
        font-size:13px;
        width:auto;
      }

      .cake-name a{
        color:var(--font2-color);
        text-decoration:none;
        transition:0.3s;
      }

      .cake-name a:hover{
        color:var(--main-color);
      }

      .stars{
        color:var(--rating-color);
        font-size:14px;
        margin:0 10px 12px;
      }
      
      .price{
        font-weight:bold;
        display:inline-block;
        margin-left:10px;
        color:var(--font-color)
      }

      /* arrow button */
      .slide-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: white;
        border: 1px solid var(--search-border-color);
        border-radius: 50%;
        width: 50px;
        height: 50px;
        cursor: pointer;
        z-index: 10;
        color: var(--font-color);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 12px rgba(27, 42, 60, 0.1);
        transition: 0.3s;
      }

      .prev-btn { left: 20px; }
      .next-btn { right: 20px; }

      .slide-arrow:hover {
        background: var(--main-color);
        color: white;
        border-color: var(--main-color);
        transform: translateY(-50%) scale(1.1);
      }

      /* categories */
      .more-categories-section {
        background-color: var(--bg-color);
      }

      .categories-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 20px;
        padding: 10px 80px 20px;
        max-width: 1400px;
        margin: 0 auto;
      }

      .category-card {
        background: #ffffff;
        border: 1.5px solid var(--border-subtle);
        border-radius: 14px;
        padding: 18px 20px;
        text-decoration: none;
        color: var(--font-color);
        display: flex;
        align-items: center;
        gap: 16px;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(27, 42, 60, 0.03);
      }

      .category-card:hover {
        transform: translateY(-4px);
        border-color: var(--main-color);
        box-shadow: 0 12px 26px rgba(60, 140, 177, 0.14);
        background: linear-gradient(135deg, #ffffff 0%, #f4f9fd 100%);
        color: var(--font-color);
      }

      .category-icon-wrap {
        width: 50px;
        height: 50px;
        border-radius: 12px;
        background: linear-gradient(135deg, #EBF4FC 0%, #DCEEFB 100%);
        color: var(--main-dark);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
        transition: var(--transition);
      }

      .category-card:hover .category-icon-wrap {
        background: linear-gradient(135deg, var(--main-color) 0%, var(--main-dark) 100%);
        color: #ffffff;
        transform: scale(1.08) rotate(3deg);
      }

      .category-info {
        flex: 1;
        min-width: 0;
      }

      .category-name {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        font-size: 14.5px;
        color: var(--font-color);
        margin: 0 0 3px 0;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        transition: var(--transition);
      }

      .category-card:hover .category-name {
        color: var(--main-dark);
      }

      .category-count {
        font-size: 12px;
        color: var(--font2-color);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 5px;
      }

      .category-arrow {
        color: var(--font2-color);
        opacity: 0.4;
        font-size: 14px;
        transition: var(--transition);
        flex-shrink: 0;
      }

      .category-card:hover .category-arrow {
        opacity: 1;
        color: var(--main-dark);
        transform: translateX(4px);
      }

      .text-center {
        color: var(--font2-color);
        padding: 40px;
      }

      /* perks & rewards (Membership & Voucher) */
      .perks-section {
        background: linear-gradient(180deg, var(--secondary-color) 0%, #EAF3FB 100%);
        border-top: 1px solid var(--border-subtle);
        padding: 60px 0 85px;
        margin: 0;
      }

      .perks-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        padding: 10px 80px 20px;
        max-width: 1400px;
        margin: 0 auto;
      }

      .perk-card {
        background: #FFFFFF;
        border: 1px solid var(--search-border-color);
        border-radius: 10px;
        padding: 30px 28px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        transition: var(--transition);
        box-shadow: 0 2px 10px rgba(27, 42, 60, 0.04);
      }

      .perk-card:hover {
        border-color: var(--main-color);
        box-shadow: 0 10px 24px rgba(27, 42, 60, 0.08);
      }

      .perk-content {
        position: relative;
        z-index: 1;
      }

      .perk-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: var(--secondary-color);
        color: var(--font2-color);
        border: 1px solid var(--search-border-color);
        padding: 4px 10px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        margin-bottom: 16px;
      }

      .perk-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 14px;
      }

      .perk-icon-wrap {
        width: 44px;
        height: 44px;
        border-radius: 8px;
        background: var(--secondary-color);
        border: 1px solid var(--search-border-color);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: var(--main-color);
        flex-shrink: 0;
        transition: var(--transition);
      }

      .perk-card:hover .perk-icon-wrap {
        background: var(--main-color);
        color: #FFFFFF;
        border-color: var(--main-color);
      }

      .perk-title {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: var(--font-color);
        margin: 0;
      }

      .perk-desc {
        color: var(--font2-color);
        font-size: 13.5px;
        line-height: 1.6;
        margin-bottom: 18px;
      }

      .perk-features {
        list-style: none;
        padding: 0;
        margin: 0 0 24px 0;
        display: flex;
        flex-direction: column;
        gap: 8px;
      }

      .perk-features li {
        font-size: 13px;
        color: var(--font-color);
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 500;
      }

      .perk-features li i {
        color: var(--main-color);
        font-size: 14px;
      }

      .perk-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: var(--main-color);
        color: #FFFFFF !important;
        text-decoration: none;
        padding: 10px 22px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 13.5px;
        font-family: 'Inter', sans-serif;
        transition: var(--transition);
        align-self: flex-start;
      }

      .perk-btn:hover {
        background: var(--font2-color);
        color: #FFFFFF !important;
      }

      /* Media Queries for Split-Screen, Tablet, and Mobile Responsiveness */

      /* Split Screen & Desktop (992px - 1199px) */
      @media (max-width: 1199px) {
        .hero-section {
          padding: 50px 50px 75px;
          min-height: 380px;
        }
        .container h1 {
          font-size: 38px;
        }
        .section-header,
        .slider-wrapper,
        .categories-grid,
        .perks-grid {
          padding-left: 50px;
          padding-right: 50px;
        }
        .prev-btn { left: 10px; width: 44px; height: 44px; }
        .next-btn { right: 10px; width: 44px; height: 44px; }
        .cake-item {
          flex: 0 0 calc(25% - 15px);
          min-width: 190px;
        }
        .categories-grid {
          grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        }
      }

      /* Tablet & Medium Split Screen (768px - 991px) */
      @media (max-width: 991px) {
        .hero-section {
          padding: 45px 32px 65px;
          min-height: 340px;
        }
        .container h1 {
          font-size: 32px;
        }
        .container p {
          font-size: 15px;
          margin-bottom: 24px;
        }
        .content-section {
          padding: 38px 0 42px;
        }
        .section-header,
        .slider-wrapper,
        .categories-grid,
        .perks-grid {
          padding-left: 32px;
          padding-right: 32px;
        }
        .section-title {
          font-size: 22px;
          margin-bottom: 20px;
        }
        .prev-btn { left: 6px; width: 38px; height: 38px; font-size: 14px; }
        .next-btn { right: 6px; width: 38px; height: 38px; font-size: 14px; }
        .cake-item {
          flex: 0 0 calc(33.333% - 14px);
          min-width: 175px;
        }
        .cake-item img {
          height: 180px;
        }
        .categories-grid {
          grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
          gap: 14px;
        }
        .category-card {
          padding: 14px;
          gap: 12px;
        }
        .category-icon-wrap {
          width: 42px;
          height: 42px;
          font-size: 18px;
        }
        .category-name {
          font-size: 13.5px;
        }
        .perks-grid {
          grid-template-columns: 1fr;
          gap: 20px;
        }
      }

      /* Mobile Phone View (< 768px) */
      @media (max-width: 767px) {
        .hero-section {
          padding: 35px 20px 55px;
          min-height: auto;
          text-align: center;
          justify-content: center;
        }
        .container {
          max-width: 100%;
          text-align: center;
          margin: 0 auto;
        }
        .hero-eyebrow {
          font-size: 11px;
          letter-spacing: 1px;
          margin-bottom: 8px;
        }
        .container h1 {
          font-size: 26px;
          margin-bottom: 12px;
          line-height: 1.3;
        }
        .container p {
          font-size: 14px;
          margin-bottom: 20px;
          line-height: 1.5;
        }
        .learn-btn a {
          padding: 10px 24px;
          font-size: 14px;
        }
        .content-section {
          padding: 30px 0 35px;
        }
        .section-header {
          padding: 0 16px;
          margin-bottom: 14px;
        }
        .section-title {
          font-size: 19px;
          margin-bottom: 0;
        }
        .section-title::after {
          bottom: -5px;
          width: 36px;
          height: 2.5px;
        }
        .view-all-btn {
          font-size: 13px;
        }
        .slider-wrapper {
          padding: 0 16px;
        }
        .slide-arrow {
          display: none !important;
        }
        .cake-grid {
          gap: 12px;
          padding: 10px 2px 14px;
        }
        .cake-item {
          flex: 0 0 155px;
          min-width: 155px;
          border-radius: 8px;
        }
        .cake-item img {
          height: 150px;
          border-radius: 8px 8px 0 0;
        }
        .cake-name {
          font-size: 12px;
          margin: 8px 6px 6px;
        }
        .stars {
          font-size: 11px;
          margin: 0 6px 8px;
        }
        .price {
          font-size: 13px;
          margin-left: 4px;
        }
        .badge-best-seller,
        .badge-discount {
          font-size: 9px;
          padding: 2px 6px;
        }
        .categories-grid {
          grid-template-columns: repeat(2, 1fr);
          gap: 10px;
          padding: 10px 16px 20px;
        }
        .category-card {
          padding: 12px 10px;
          gap: 10px;
          border-radius: 10px;
        }
        .category-icon-wrap {
          width: 36px;
          height: 36px;
          font-size: 16px;
          border-radius: 8px;
        }
        .category-name {
          font-size: 12.5px;
        }
        .category-count {
          font-size: 11px;
        }
        .perks-section {
          padding: 35px 0 45px;
        }
        .perks-grid {
          grid-template-columns: 1fr;
          padding: 10px 16px 20px;
          gap: 16px;
        }
        .perk-card {
          padding: 20px 18px;
          border-radius: 10px;
        }
        .perk-title {
          font-size: 17px;
        }
        .perk-desc {
          font-size: 12.5px;
          margin-bottom: 14px;
        }
        .perk-features li {
          font-size: 12px;
        }
        .perk-btn {
          padding: 9px 18px;
          font-size: 13px;
          width: 100%;
          text-align: center;
          justify-content: center;
        }
      }

      /* Extra Small Phone (< 400px) */
      @media (max-width: 399px) {
        .cake-item {
          flex: 0 0 140px;
          min-width: 140px;
        }
        .cake-item img {
          height: 135px;
        }
        .categories-grid {
          grid-template-columns: repeat(2, 1fr);
          gap: 8px;
        }
      }

    </style>
</head>
<body>
<?php include 'include/header.php';?>

    <!-- banner -->
    <section class="hero-section">
      <div class="container">
        <span class="hero-eyebrow">Freshly Baked Daily</span>
        <h1>Every slice is a<br><span>sweet moment</span></h1>
        <p>Fresh-baked cakes made for every occasion — birthdays, anniversaries, or just because.</p>
        <button class="learn-btn"><a href="about us.php">Learn More</a></button>
      </div>
      <div class="hero-wave">
        <svg viewBox="0 0 1440 60" fill="none" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
          <path d="M0,24 C360,56 1080,-10 1440,24 L1440,60 L0,60 Z"></path>
        </svg>
      </div>
    </section>

    <!-- main-content -->
    <section class="main-content">
      <!-- best selling -->
      <div class="content-section best-selling-section">
        <div class="section-header">
          <h2 class="section-title">Best Selling</h2>
          <a href="product catalogue.php?cake_type=Best%20Selling" class="view-all-btn">
                View All <i class="bi bi-arrow-right"></i>
          </a>
        </div>
        
          <div class="slider-wrapper">
            <div class="cake-grid" id="productSlider1">
                <?php 
                //retrieve sql
                $sql =  "SELECT p.*, MIN(v.VARIANT_PRICE) as MIN_PRICE, COUNT(v.VARIANT_ID) as VARIANT_COUNT,
                        (SELECT COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE)
                        FROM product_variant v2
                        WHERE v2.PRODUCT_ID = p.PRODUCT_ID AND v2.IS_DELETED = 0 AND v2.VARIANT_STATUS = 'Active'
                        ORDER BY COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE) ASC LIMIT 1) as DISPLAY_MIN_PRICE,
                        (SELECT v2.VARIANT_PRICE
                        FROM product_variant v2
                        WHERE v2.PRODUCT_ID = p.PRODUCT_ID AND v2.IS_DELETED = 0 AND v2.VARIANT_STATUS = 'Active'
                        ORDER BY COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE) ASC LIMIT 1) as ORIGINAL_PRICE_OF_MIN
                        FROM product p
                        LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
                        LEFT JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
                        LEFT JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
                        WHERE p.PRODUCT_STATUS = 'Active' 
                        AND p.IS_DELETED = 0
                        AND p.SALES_COUNT >= 50
                        AND c.CATEGORY_STATUS = 'Active'
                        AND c.IS_DELETED = 0
                        AND EXISTS (
                            SELECT 1 FROM product_variant 
                            WHERE PRODUCT_ID = p.PRODUCT_ID 
                            AND IS_DELETED = 0
                            AND VARIANT_STATUS = 'Active'
                            AND VARIANT_STOCK > 0
                            )
                        GROUP BY p.PRODUCT_ID 
                        ORDER BY p.SALES_COUNT DESC
                        LIMIT 10";
                $result = $conn->query($sql);

                if ($result && $result->num_rows > 0) {
                  while ($row=$result->fetch_assoc()){
                    ?>
              <div class="cake-item">
                  <img src="admin/<?php echo $row['COVER_IMAGE'];?>" alt="<?php echo htmlspecialchars($row['PRODUCT_NAME']);?>">
                  <p class="cake-name">
                    <a href="product details.php?id=<?php echo $row['PRODUCT_ID']; ?>">
                      <?php echo htmlspecialchars($row['PRODUCT_NAME']);?>
                    </a>
                  </p>
                  <div class="stars">
                    <?php
                      $rating = round($row['AVG_RATING']);
                      for ($i=1; $i<=5; $i++) {
                          echo ($i <= $rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
                      }
                    ?>
                    <span class="ms-1">(<?php echo number_format($row['AVG_RATING'], 1); ?>)</span>
                    <p class="price">RM <?php echo number_format($row['MIN_PRICE'], 2);?></p>
                  </div>
              </div>
              <?php
                  }
                } else {
                  echo "<p class='text-center'>No best-selling products at the moment</p>";
                }
              ?>
            </div>

            <button class="slide-arrow prev-btn" onclick="moveSlider(-1, 'productSlider1')">
               <i class="bi bi-chevron-left"></i>
            </button>
            <button class="slide-arrow next-btn" onclick="moveSlider(1, 'productSlider1')">
                <i class="bi bi-chevron-right"></i>
            </button>
          </div>
      </div>

      <!-- high recommended -->
      <div class="content-section recommended-section">
        <div class="section-header">
          <h2 class="section-title">High Recommended</h2>
           

          <a href="product catalogue.php?cake_type=High%20Recommended" class="view-all-btn">
                View All <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        
           
        <div class="slider-wrapper">
          <div class="cake-grid" id="productSlider2">
              <?php
              $sql_rec = "SELECT p.*, MIN(v.VARIANT_PRICE) as MIN_PRICE, COUNT(v.VARIANT_ID) as VARIANT_COUNT,
                (SELECT COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE)
                FROM product_variant v2
                WHERE v2.PRODUCT_ID = p.PRODUCT_ID AND v2.IS_DELETED = 0 AND v2.VARIANT_STATUS = 'Active'
                ORDER BY COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE) ASC LIMIT 1) as DISPLAY_MIN_PRICE,
                (SELECT v2.VARIANT_PRICE
                FROM product_variant v2
                WHERE v2.PRODUCT_ID = p.PRODUCT_ID AND v2.IS_DELETED = 0 AND v2.VARIANT_STATUS = 'Active'
                ORDER BY COALESCE(NULLIF(v2.SALE_PRICE,0), v2.VARIANT_PRICE) ASC LIMIT 1) as ORIGINAL_PRICE_OF_MIN
                FROM product p
                LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
                LEFT JOIN product_category pc ON p.PRODUCT_ID = pc.PRODUCT_ID
                LEFT JOIN category c ON pc.CATEGORY_ID = c.CATEGORY_ID
                WHERE p.PRODUCT_STATUS = 'Active'
                AND p.IS_DELETED = 0
                AND p.AVG_RATING >= 4.5 
                AND c.CATEGORY_STATUS = 'Active'
                AND c.IS_DELETED = 0
                AND EXISTS (
                    SELECT 1 FROM product_variant
                    WHERE PRODUCT_ID = p.PRODUCT_ID 
                    AND IS_DELETED = 0
                    AND VARIANT_STATUS = 'Active'
                    AND VARIANT_STOCK > 0
                )
                GROUP BY p.PRODUCT_ID 
                ORDER BY p.AVG_RATING DESC, p.SALES_COUNT DESC
                LIMIT 10";

              $result_rec = $conn->query($sql_rec);

              if ($result_rec && $result_rec->num_rows > 0) {
                while ($row = $result_rec->fetch_assoc()) {
                  $display_price = $row['DISPLAY_MIN_PRICE'];
                  $original_price = $row['ORIGINAL_PRICE_OF_MIN'];
                  $has_discount = ($original_price > $display_price);
                  $discount_percent = $has_discount ? round((($original_price - $display_price) / $original_price) * 100) : 0;
                  $is_best_seller = ($row['SALES_COUNT'] >= 50);
                    ?>
                    <div class="cake-item">
                      <div class="cake-img-wrap">
                          <img src="admin/<?php echo $row['COVER_IMAGE'];?>" alt="<?php echo htmlspecialchars($row['PRODUCT_NAME']);?>">
                          <?php if ($is_best_seller): ?>
                              <span class="badge-best-seller">BEST SELLER</span>
                          <?php elseif ($has_discount): ?>
                              <span class="badge-discount">-<?php echo $discount_percent; ?>%</span>
                          <?php endif; ?>
                      </div>
                      <p class="cake-name">
                          <a href="product details.php?id=<?php echo $row['PRODUCT_ID']; ?>">
                              <?php echo htmlspecialchars($row['PRODUCT_NAME']);?>
                          </a>
                      </p>
                      <div class="stars">
                          <?php
                              $rating = round($row['AVG_RATING']);
                              for ($i=1; $i<=5; $i++) {
                                  echo ($i <= $rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
                              }
                          ?>
                          <span class="ms-1">(<?php echo number_format($row['AVG_RATING'], 1); ?>)</span>
                          <p class="price">
                              RM <?php echo number_format($display_price, 2);?>
                              <?php if ($has_discount): ?>
                                  <span class="original-price">RM <?php echo number_format($original_price, 2); ?></span>
                              <?php endif; ?>
                          </p>
                      </div>
                    </div>
                    <?php
                }
              } else {
                echo "<p class='text-center'>Currently no highly rated products.</p>";
              }
              ?>
            </div>
            <button class="slide-arrow prev-btn" onclick="moveSlider(-1, 'productSlider2')">
              <i class="bi bi-chevron-left"></i>
            </button>
            <button class="slide-arrow next-btn" onclick="moveSlider(1, 'productSlider2')">
               <i class="bi bi-chevron-right"></i>
            </button>
          </div>
        </div>

        <!-- more categories -->
        <div class="content-section more-categories-section">
          <div class="section-header">
            <h2 class="section-title">Explore Categories</h2>
          </div>
          <div class="categories-grid">
            <?php 
              // Retrieve all active categories (leaf subcategories and standalone categories)
              $cat_sql = "
                    SELECT c.CATEGORY_ID, c.CATEGORY_NAME, c.PARENT_ID,
                          (SELECT COUNT(DISTINCT pc.PRODUCT_ID) 
                            FROM product_category pc 
                            JOIN product p ON p.PRODUCT_ID = pc.PRODUCT_ID 
                            JOIN category c2 ON pc.CATEGORY_ID = c2.CATEGORY_ID
                            WHERE (c2.CATEGORY_ID = c.CATEGORY_ID OR c2.PARENT_ID = c.CATEGORY_ID)
                              AND p.IS_DELETED = 0 AND p.PRODUCT_STATUS = 'Active'
                          ) AS PRODUCT_COUNT
                    FROM category c
                    WHERE c.CATEGORY_STATUS = 'Active' AND c.PARENT_ID IS NULL AND c.IS_DELETED = 0
                    ORDER BY c.CATEGORY_ID ASC
                ";
              $cat_result = $conn->query($cat_sql);

              if ($cat_result && $cat_result->num_rows > 0) {
                while ($cat_row = $cat_result->fetch_assoc()) { 
                  $cat_icon = getCategoryIcon($cat_row['CATEGORY_NAME']);
                  $count = intval($cat_row['PRODUCT_COUNT']);
                ?>
                  <a href="product catalogue.php?id=<?php echo intval($cat_row['CATEGORY_ID']); ?>" class="category-card">
                    <div class="category-icon-wrap">
                      <i class="bi <?php echo $cat_icon; ?>"></i>
                    </div>
                    <div class="category-info">
                      <div class="category-name"><?php echo htmlspecialchars($cat_row['CATEGORY_NAME']); ?></div>
                      <div class="category-count">
                        <span><?php echo $count; ?> <?php echo $count === 1 ? 'Product' : 'Products'; ?></span>
                      </div>
                    </div>
                    <i class="bi bi-chevron-right category-arrow"></i>
                  </a>
                <?php }
              } else {
                echo "<p class='text-muted' style='padding: 20px 80px;'>No categories found.</p>";
              } ?>
          </div>
        </div>

        <!-- Membership & Voucher Section -->
        <div class="content-section perks-section">
          <div class="section-header">
            <h2 class="section-title">Membership Rewards & Vouchers</h2>
          </div>
          <div class="perks-grid">
            <!-- Membership Card -->
            <div class="perk-card">
              <div class="perk-content">
                <span class="perk-badge"><i class="bi bi-person-badge"></i> Tier Program</span>
                <div class="perk-header">
                  <div class="perk-icon-wrap">
                    <i class="bi bi-shield-check"></i>
                  </div>
                  <div>
                    <h3 class="perk-title">Member Rewards Program</h3>
                  </div>
                </div>
                <p class="perk-desc">
                  Maximize your purchasing value with our multi-tier reward system (Bronze, Silver, Gold). Unlock tier-exclusive pricing, special rebates, and automatic tier voucher allocations on all products.
                </p>
                <ul class="perk-features">
                  <li><i class="bi bi-check-circle-fill"></i> Tier upgrade privileges & exclusive member rates</li>
                  <li><i class="bi bi-check-circle-fill"></i> Automatic tier-based voucher rewards</li>
                  <li><i class="bi bi-check-circle-fill"></i> Priority support & VIP promotional access</li>
                </ul>
              </div>
              <a href="membership.php" class="perk-btn">
                View Membership <i class="bi bi-arrow-right"></i>
              </a>
            </div>

            <!-- Voucher Card -->
            <div class="perk-card">
              <div class="perk-content">
                <span class="perk-badge"><i class="bi bi-tag"></i> Platform Promotions</span>
                <div class="perk-header">
                  <div class="perk-icon-wrap">
                    <i class="bi bi-ticket-detailed-fill"></i>
                  </div>
                  <div>
                    <h3 class="perk-title">Discount & Promo Vouchers</h3>
                  </div>
                </div>
                <p class="perk-desc">
                  Collect active discount vouchers and coupon codes to apply instant price reductions on your hardware, components, modules, and bulk procurement orders at checkout.
                </p>
                <ul class="perk-features">
                  <li><i class="bi bi-check-circle-fill"></i> Instant deductions on checkout & procurement</li>
                  <li><i class="bi bi-check-circle-fill"></i> Sitewide promotional & seasonal campaigns</li>
                  <li><i class="bi bi-check-circle-fill"></i> Additional stackable tier discounts</li>
                </ul>
              </div>
              <a href="voucher.php" class="perk-btn">
                Claim Vouchers <i class="bi bi-arrow-right"></i>
              </a>
            </div>
          </div>
        </div>
    </section>

    <?php include 'include/footer.php';?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  //scrollbar function
  function moveSlider(direction, sliderId) {
    //"sliderId" represents the id of the slider we want to control,

    // it allows we to have multiple sliders on the same page and control them independently.
    const slider = document.getElementById(sliderId);
     //scrolling distance = width of the visible area of the container
    if (slider) {
        const scrollAmount = slider.offsetWidth * 0.8; // scroll 80% of width
        slider.scrollBy({
            left: direction * scrollAmount,
            behavior: 'smooth'
        });
    }
}

  // only center the card row when all cards already fit without needing to scroll;
  // otherwise keep it left-aligned so every card (including the first one) stays reachable
  function updateSliderCentering(sliderId) {
    const slider = document.getElementById(sliderId);
    if (!slider) return;
    if (slider.scrollWidth <= slider.clientWidth + 1) {
        slider.classList.add('centered');
    } else {
        slider.classList.remove('centered');
    }
  }

  function updateAllSliders() {
    updateSliderCentering('productSlider1');
    updateSliderCentering('productSlider2');
  }

  window.addEventListener('load', updateAllSliders);
  window.addEventListener('resize', updateAllSliders);
</script>
</body>
</html>
