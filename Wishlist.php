<?php
include 'include/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

//check the login status
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];


$wishlist_query = "SELECT w.*,p.PRODUCT_NAME, p.COVER_IMAGE,p.PRODUCT_ID, p.AVG_RATING,
(SELECT MIN(VARIANT_PRICE) FROM product_variant WHERE PRODUCT_ID = p.PRODUCT_ID 
AND IS_DELETED = 0) as MIN_PRICE
FROM wishlist w
JOIN product p ON w.PRODUCT_ID = p.PRODUCT_ID 
WHERE w.CUSTOMER_ID = $customer_id
AND p.IS_DELETED = 0
AND EXISTS (
              SELECT 1 FROM product_variant
              WHERE PRODUCT_ID = p.PRODUCT_ID 
              AND IS_DELETED = 0
              AND VARIANT_STATUS = 'Active'
              AND VARIANT_STOCK > 0)";

$wishlist_result = mysqli_query($conn, $wishlist_query);
$wishlists = mysqli_fetch_all($wishlist_result, MYSQLI_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wishlist</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="css/header.css?v=5.0">
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
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        margin: 0;
        padding: 0;
      }

      /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 30px 10px;

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

      ..container {
        max-width: 860px;
        margin: 0 auto;
        padding: 0 32px 60px;
      }
 
      .wishlist-section {
        background: #fff;
        border-radius: 10px;
        border: 1px solid var(--search-border-color);
        padding: 28px 28px 8px;
        margin-bottom: 40px;
        box-shadow: 0 4px 16px rgba(27,42,60,0.06);
      }
 
      .wishlist-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid var(--search-border-color);
      }
 
      .wishlist-header h2 {
        margin: 0;
        font-size: 20px;
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
      }
 
      .count-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--main-color);
        color: #fff;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        padding: 2px 10px;
        letter-spacing: 0.2px;
      }
 
      .btn-text {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 7px 16px;
        border-radius: 6px;
        border: 1px solid var(--search-border-color);
        background: transparent;
        color: var(--font2-color);
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: background 0.18s, border-color 0.18s, color 0.18s;
        font-family: 'Inter', sans-serif;
      }
 
      .btn-text:hover {
        background: var(--secondary-color);
        border-color: var(--main-color);
        color: var(--main-color);
      }
 
      #deleteSelected {
        color: #a94040;
        border-color: rgba(169, 64, 64, 0.28);
      }
 
      #deleteSelected:hover {
        background: rgba(169, 64, 64, 0.07);
        border-color: rgba(169, 64, 64, 0.5);
        color: #8b2c2c;
      }
 
      .header-actions {
        display: flex;
        gap: 8px;
      }
 
      .wishlist-card {
        display: flex;
        align-items: center;
        gap: 18px;
        padding: 16px 0;
        border-bottom: 1px solid var(--search-border-color);
        position: relative;
        transition: background 0.15s;
      }
 
      .wishlist-card:last-child {
        border-bottom: none;
      }
 
      .wishlist-info {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
 
      .product-thumb {
        width: 90px;
        height: 90px;
        object-fit: cover;
        border-radius: 10px;
        border: 1px solid var(--search-border-color);
        flex-shrink: 0;
        transition: transform 0.2s;
      }
 
      .product-thumb:hover {
        transform: scale(1.03);
      }
 
      .product-name {
        margin: 0;
        font-size: 15px;
        color: var(--font2-color);
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        display: block;
        transition: color 0.2s;
      }
 
      .product-name:hover {
        color: var(--main-color);
      }
 
      .price {
        color: var(--font-color);
        font-size: 15px;
        font-weight: 600;
        margin: 0;
      }
 
      .stars {
        color: var(--rating-color);
        font-size: 13px;
        display: flex;
        gap: 1px;
      }
 
      .card-checkbox {
        width: 18px;
        height: 18px;
        accent-color: var(--main-color);
        cursor: pointer;
        flex-shrink: 0;
        margin-left: 8px;
      }
 
      .empty-wishlist {
        text-align: center;
        padding: 56px 0 40px;
        color: var(--font2-color);
      }
 
      .empty-wishlist p {
        font-size: 15px;
        opacity: 0.55;
        margin-bottom: 16px;
      }
 
      .empty-wishlist .back-link {
        justify-content: center;
        font-size: 14px;
        font-weight: 600;
        opacity: 1;
        background: var(--main-color);
        color: var(--font-color);
        padding: 9px 22px;
        border-radius: 6px;
        display: inline-flex;
        text-decoration: none;
        transition: opacity 0.2s;
      }
 
      .empty-wishlist .back-link:hover {
        opacity: 0.85;
        text-decoration: none;
      }
 
      /* select-all bar */
      .select-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
        padding: 6px 2px 10px;
        border-bottom: 1px dashed var(--search-border-color);
      }
 
      .select-bar label {
        font-size: 13px;
        color: var(--font2-color);
        cursor: pointer;
        user-select: none;
      }
 
      #selectAll {
        width: 16px;
        height: 16px;
        accent-color: var(--main-color);
        cursor: pointer;
      }
    </style>
  </head>

  <body>
  <?php include 'include/header.php'?>
 
  <div class="container">
    <div class="back-section">
        <a href="index.php" class="back-link">
          <i class="bi bi-chevron-left"></i>Back
        </a>
    </div>
 
    <section class="wishlist-section">
      <div class="wishlist-header">
        <h2>
          Wishlist
          <?php if (count($wishlists) > 0): ?>
            <span class="count-badge"><?php echo count($wishlists); ?></span>
          <?php endif; ?>
        </h2>
        <div class="header-actions">
          <a class="btn-text" id="clearAll"><i class="bi bi-trash3"></i> Clear</a>
          <a class="btn-text" id="deleteSelected"><i class="bi bi-x-circle"></i> Delete</a>
        </div>
      </div>
 
      <?php if (count($wishlists) > 0):?>
 
        <div class="select-bar">
          <input type="checkbox" id="selectAll">
          <label for="selectAll">Select all</label>
        </div>
 
        <?php foreach($wishlists as $item):?>
          <?php $rating = $item['AVG_RATING'] ? round($item['AVG_RATING']) : 0;?>
            <div class="wishlist-card">
              <a href="product details.php?id=<?php echo $item['PRODUCT_ID'];?>">
                <img class="product-thumb" src="<?php echo htmlspecialchars ($item['COVER_IMAGE']);?>" alt = "<?php echo htmlspecialchars($item['PRODUCT_NAME']);?>">
              </a>
              <div class="wishlist-info">
                <a href="product details.php?id=<?php echo $item['PRODUCT_ID'];?>" class="product-name">
                  <h3 class="product-name"​><?php echo htmlspecialchars($item['PRODUCT_NAME']);?></h3>
                </a>
                <p class="price">RM <?php echo number_format($item['MIN_PRICE'],2);?></p>
                <div class="stars">
                  <?php for ($i = 1; $i<=5; $i++):?>
                    <?php echo ($i <= $rating) ? '<i class="bi bi-star-fill"></i>' : '<i class="bi bi-star"></i>';?>
                  <?php endfor;?>
                </div>
              </div>
              <input type="checkbox" class="card-checkbox" value="<?php echo $item['PRODUCT_ID'];?>">
            </div>
          <?php endforeach;?>
        <?php else: ?>
          <div class="empty-wishlist">
            <p>Your wishlist is empty</p>
            <a href="index.php" class="back-link">Browse Products</a>
          </div>
        <?php endif;?>
      </section>
    </div>
 
  <?php include 'include/footer.php'?>

  <script>
    // delete selected wishlist items
    document.getElementById('deleteSelected').addEventListener('click', function() {
      const checked = document.querySelectorAll('.card-checkbox:checked');
      if (checked.length === 0) {
        alert('Please select at least one item to delete.');
        return;
      }
      
      if (!confirm('Are you sure you want to remove selected items from your wishlist?')) return;

      const productIds = Array.from(checked).map(cb => cb.value);

      fetch('remove_wishlist.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'product_ids=' + JSON.stringify(productIds)
      })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          alert(data.message);
          location.reload();
        } else {
          alert(data.message);
        }
      })
      .catch(error => console.error('Error:', error));
    });

    //clear all wishlist items
    document.getElementById('clearAll').addEventListener('click', function() {
    
      if (!confirm('Are you sure you want to remove all items from your wishlist?')) {
        return;
      }

      fetch('remove_wishlist.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: 'clear_all=1'
      })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          alert(data.message);
          location.reload();
        } else {
          alert(data.message);
        }
      })
      .catch(error => console.error('Error:', error));
    });

    //Select All checkbox
    document.getElementById('selectAll').addEventListener('change', function() {
      const allCheckboxes = document.querySelectorAll('.card-checkbox');
      allCheckboxes.forEach(cb => cb.checked = this.checked);
    });

    //if manually cancel any one of them, it will automatically cancel select all
    document.querySelectorAll('.card-checkbox').forEach(function(cb) {
      cb.addEventListener('change', function() {
        const all = document.querySelectorAll('.card-checkbox');
        const checked = document.querySelectorAll('.card-checkbox:checked');
        document.getElementById('selectAll').checked = all.length === checked.length;
      });
    });
        
  </script>
</body>
</html>
