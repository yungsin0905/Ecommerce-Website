<?php 
include 'include/config.php';
session_start();
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
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root
      {
        --main-color: #80b8d2;
        --font-color:#1B2A3C;
        --secondary-color:#F4F8FC;
        --rating-color:#F5A623;
        --search-border-color:#C9DCEE;
        --bg-color:#FFFFFF;
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
        background: var(--secondary-color);
        position: relative;
        height: 420px;
        border-radius: 0;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        padding: 0 80px;
        margin-bottom: 60px;
        border-bottom: 1px solid var(--search-border-color);
      }

      .hero-section::before {
        content: '';
        position: absolute;
        right: -100px; top: -60px;
        width: 480px; height: 480px;
        background: var(--main-color);
        opacity: 0.08;
        border-radius: 40px;
        transform: rotate(18deg);
      }

      .hero-section::after {
        content: '';
        position: absolute;
        right: 80px; bottom: -180px;
        width: 260px; height: 260px;
        background: var(--main-color);
        opacity: 0.06;
        border-radius: 30px;
        transform: rotate(18deg);
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
        color: var(--bg-color);
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
        background-color: var(--bg-color);
        padding-bottom: 80px;
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
        padding: 0 100px;
        margin-bottom: 20px;
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

      .best-selling{
        margin: 60px 0;
      }

      /* slider */
      .slider-wrapper {
        position: relative; 
        display: flex;
        align-items: center;
        padding: 0 80px;
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
      .more-categories {
        margin: 50px 0 60px;
      }
 
      .more-categories h2 {
        text-align: left;
      }
 
      .categories {
        display: flex;
        justify-content: flex-start;
        gap: 25px;
        padding: 10px 100px 20px;
        flex-wrap: wrap;
      }
 
      .cat-item {
        text-align: center;
        width: 160px;
        background: #fff;
        border: 1px solid var(--search-border-color);
        border-radius: 10px;
        padding: 15px 12px;
        transition: var(--transition);
      }
 
      .cat-item:hover {
        transform: translateY(-6px);
        box-shadow: 0 15px 30px rgba(27, 42, 60, 0.1);
        border-color: var(--main-color);
      }
 
      .cat-item img {
        width: 100%;
        height: 100px;
        border-radius: 8px;
        object-fit: cover;
        margin-bottom: 12px;
        background-color: var(--secondary-color);
        transition: var(--transition);
      }
 
      .categories span {
        font-weight: 700;
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
        font-size: 13px;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-height: 34px;
      }
 
      .categories a {
        text-decoration: none;
        color: var(--font-color);
        transition: var(--transition);
        display: flex;
        flex-direction: column;
        align-items: center;
      }
 
      .categories a:hover span {
        color: var(--main-color);
        text-decoration: underline;
      }

      .text-center {
        color: var(--font2-color);
        padding: 40px;
      }

      /* perks & rewards (Membership & Voucher) */
      /* perks & rewards (Membership & Voucher) - Professional Tech/E-commerce Style */
      .perks-section {
        margin: 50px 0 30px;
      }

      .perks-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 25px;
        padding: 10px 100px 20px;
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
        transform: translateY(-5px);
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

      @media (max-width: 992px) {
        .perks-grid {
          grid-template-columns: 1fr;
          padding: 10px 30px 20px;
        }
        .section-header {
          padding: 0 30px;
        }
        .categories {
          padding: 10px 30px 20px;
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
    </section>

    <!-- main-content -->
    <section class="main-content">
      <!-- best selling -->
      <div class="best-selling">
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
                $sql =  "SELECT p.*, MIN(v.VARIANT_PRICE) as MIN_PRICE FROM product p
                        LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
                        INNER JOIN category c ON c.CATEGORY_ID = p.CATEGORY_ID
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
      <div class="best-selling">
        <div class="section-header">
          <h2 class="section-title">High Recommended</h2>
           

          <a href="product catalogue.php?cake_type=High%20Recommended" class="view-all-btn">
                View All <i class="bi bi-arrow-right"></i>
            </a>
        </div>
        
           
        <div class="slider-wrapper">
          <div class="cake-grid" id="productSlider2">
              <?php
              $sql_rec = "SELECT p.*, MIN(v.VARIANT_PRICE) as MIN_PRICE
                        FROM product p
                        LEFT JOIN product_variant v ON p.PRODUCT_ID = v.PRODUCT_ID
                        INNER JOIN category c ON c.CATEGORY_ID = p.CATEGORY_ID
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
                    ?>
                    <div class="cake-item">
                        <img src="admin/<?php echo $row['COVER_IMAGE']; ?>" alt="<?php echo htmlspecialchars($row['PRODUCT_NAME']); ?>">
                        <p class="cake-name">
                            <a href="product details.php?id=<?php echo $row['PRODUCT_ID']; ?>">
                                <?php echo htmlspecialchars($row['PRODUCT_NAME']); ?>
                            </a>
                        </p>
                        <div class="stars">
                            <?php
                            $rating = round($row['AVG_RATING']);
                            for ($i = 1; $i <= 5; $i++) {
                                echo ($i <= $rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';
                            }
                            ?>
                            <span class="ms-1">(<?php echo number_format($row['AVG_RATING'], 1); ?>)</span>
                            <p class="price">RM <?php echo number_format($row['MIN_PRICE'], 2); ?></p>
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
        <div class="more-categories">
          <div class="section-header">
            <h2 class="section-title">More Categories</h2>
          </div>
          <div class="categories">
            <?php 
            // Retrieve all active categories
            $cat_sql = "SELECT * FROM category WHERE CATEGORY_STATUS = 'Active' AND IS_DELETED = 0";
            $cat_result = $conn->query($cat_sql);
    
            if ($cat_result && $cat_result->num_rows > 0) {
              while ($cat_row = $cat_result->fetch_assoc()) { ?>
                <div class="cat-item">
                  <a href="product catalogue.php?id=<?php echo htmlspecialchars($cat_row['CATEGORY_ID']); ?>">
                    <img src="admin/<?php echo $cat_row['CATEGORY_IMAGE']; ?>" 
                        alt="<?php echo htmlspecialchars($cat_row['CATEGORY_NAME']); ?>">
                    <span><?php echo htmlspecialchars($cat_row['CATEGORY_NAME']); ?></span>
                  </a>
                </div>
              <?php }
            } ?>
          </div>
        </div>

        <!-- Membership & Voucher Section -->
        <div class="perks-section">
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
