<?php
ob_start(); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include_once 'config.php';

$bakery_query = "SELECT * FROM bakery_info";
$bakery_result = $conn->query($bakery_query);
$bakery_info = $bakery_result->fetch_assoc();

//category
$category_result = $conn->query("
    SELECT CATEGORY_ID, CATEGORY_NAME 
    FROM category 
    WHERE CATEGORY_STATUS = 'Active' AND IS_DELETED = 0
    ORDER BY CATEGORY_ID ASC
");
$categories = [];
while ($cat = $category_result->fetch_assoc()) {
    $categories[] = $cat;
}

?>
 <!-- search section -->
    <header class="header">
      <div class="top-bar">

        <!-- logo + nav links grouped together -->
        <div class="left-group">
            <div class="logo"><img src="admin/<?php echo htmlspecialchars($bakery_info['SHOP_IMAGE']); ?>" alt="Logo" class="img-fluid"></div>

            <nav class="nav-main">
                <a href="about us.php">About Us</a>

                <!-- dropdown menu -->
                <div class="dropdown-container">
                    <a href="product catalogue.php?id=all" class="nav-link-item">All Cakes</a>

                    <div class="mega-menu">
                        <div class="menu-column">
                            <h4>Favourites</h4>
                            <ul>
                                <li><a href="product catalogue.php?cake_type=Best%20Selling">Best Selling</a></li>
                                <li><a href="product catalogue.php?cake_type=High%20Recommended">High Recommended</a></li>
                            </ul>
                        </div>
                        <div class="menu-column">
                            <h4>Cake Type</h4>
                            <ul>
                                <li><a href="product catalogue.php?id=all">All Cakes</a></li>
                                <?php foreach ($categories as $category): ?>
                                    <li>
                                        <a href="product catalogue.php?id=<?= $category['CATEGORY_ID'] ?>">
                                            <?= htmlspecialchars($category['CATEGORY_NAME']) ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>

                <a href="voucher.php">Voucher</a>
                <a href="Customise.php">Customise</a>
                <a href="membership.php">Membership</a>
            </nav>
        </div>

        <!-- search + action icons, grouped on the right -->
        <div class="header-actions">

            <div class="search-wrap" id="searchWrap">
                <form action="product catalogue.php" method="GET" class="search-form" id="searchForm">
                    <input type="text" name="search" id="searchInput" class="search-input"
                    placeholder="What are you looking for..." maxlength="50"
                    value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">

                    <button type="submit" class="search-toggle-btn" id="searchToggleBtn" aria-label="Search">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>

            <div class="btn-group">
                <!-- <a class="save-btn" href="Wishlist.php"><i class="bi bi-heart-fill"></i></a>
                <a class="cart-btn" href="shopping_cart.php"><i class="bi bi-cart-fill"></i></a>
                <a class="profile-btn" href="UserDashboard.php"><i class="bi bi-person-circle"></i></a>
                <?php if(isset($_SESSION['CUSTOMER_ID'])):?>
                    <a href="javascript:void(0);" onclick="confirmLogout()" class="sign-up" style="font-size: 20px;">Logout</a>
                <?php else:?>
                    <a href="sign-up.php" class="sign-up" style="font-size: 20px;">Sign Up/Login</a>
                <?php endif;?> -->

                <i class ="bi bi-list menu-icon" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu"></i>
            </div>
        </div>

      </div>

         <!-- menu collapsible bar -->
            <div class="offcanvas offcanvas-end" tabindex="-1" id="mobileMenu" aria-labelledby="mobileMenuLabel">
              <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="mobileMenuLabel" style="color: var(--main-color); font-weight: bold;">Menu</h5>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
              </div>
                <div class="offcanvas-body">
                    <ul class="list-group list-group-flush">
                  
                        <li class="list-group-item border-0"><a href="index.php" class="text-decoration-none"><i class="bi bi-house me-2"></i> Homepage</a></li>
                        <li class="list-group-item border-0"><a href="UserDashboard.php" class="text-decoration-none"><i class="bi bi-person-circle me-2"></i>User Profile</a></li>
                        <li class="list-group-item border-0"><a href="Wishlist.php" class="text-decoration-none"><i class="bi bi-heart-fill me-2"></i> Wishlist</a></li>
                        <li class="list-group-item border-0"><a href="shopping_cart.php" class="text-decoration-none"><i class="bi bi-cart-fill me-2"></i> Shopping Cart</a></li>
                        <li class="list-group-item border-0"><a href="CustomiseRequest.php" class="text-decoration-none"><i class="bi bi-envelope-paper-fill me-2"></i> Customise Request</a></li>
                        <li class="list-group-item border-0"><a href="order_history.php" class="text-decoration-none"><i class="bi bi-clipboard2-check-fill me-2"></i> Order History</a></li>
                         <!-- //if logged in will change the icon to "logged out"
                        logged out will changed it to "sign up" -->
                    
                        <?php if(isset($_SESSION['CUSTOMER_ID'])):?>
                        <li class="list-group-item border-0"><a href="javascript:void(0);" onclick="confirmLogout()" class="text-decoration-none text-danger"><i class="bi bi-box-arrow-in-left me-2"></i> Logout</a></li>
                        <?php else:?>
                        <li class="list-group-item border-0"><a href="sign-up.php" class="text-decoration-none"><i class="bi bi-box-arrow-in-right me-2"></i> Sign Up/Sign In</a></li>
                        <?php endif;?>
                    </ul>
                </div>
            </div>
    </header>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      //<!-- menu collapsible bar -->
    window.onload = function() {
      var myOffcanvas = document.getElementById('mobileMenu');
      if(myOffcanvas) {
          console.log("Offcanvas element found!");
      } else {
          console.error("Offcanvas element NOT found! Check your ID.");
      }
    };

    //logout
  function confirmLogout(){
  if(confirm("Are you sure you want to logout?"))
  window.location.href = 'login.php?logout=true';
}    

     // link copy
  function copyPageLink() {
    // get current URL
    const currentUrl = window.location.href;

    // Using clipboard API copy it
    navigator.clipboard.writeText(currentUrl).then(function() {
       
        //display notifier
        const toast = document.getElementById('copy-toast');
        toast.style.display = 'block';
        
        setTimeout(() => {
            toast.style.display = 'none';
        }, 2000);
        
        //copy failed
    }).catch(function(err) {
        console.error('Link copy failed: ', err);
        alert("Copying failed, please copy the link manually.");
    });
}

    // --- click-to-expand search ---
    document.addEventListener('DOMContentLoaded', function () {
        var searchWrap = document.getElementById('searchWrap');
        var searchBtn = document.getElementById('searchToggleBtn');
        var searchInput = document.getElementById('searchInput');

        if (searchWrap && searchBtn && searchInput) {
            searchBtn.addEventListener('click', function (e) {
                if (!searchWrap.classList.contains('active')) {
                    // first click: just open the field, don't submit yet
                    e.preventDefault();
                    searchWrap.classList.add('active');
                    searchInput.focus();
                } else if (searchInput.value.trim() === '') {
                    // already open but empty: keep focus instead of submitting
                    e.preventDefault();
                    searchInput.focus();
                }
                // otherwise let the form submit normally
            });

            // click outside closes it again if there's nothing typed
            document.addEventListener('click', function (e) {
                if (!searchWrap.contains(e.target) && searchInput.value.trim() === '') {
                    searchWrap.classList.remove('active');
                }
            });

            // Esc closes it
            searchInput.addEventListener('keyup', function (e) {
                if (e.key === 'Escape') {
                    searchWrap.classList.remove('active');
                    searchInput.blur();
                }
            });
        }
    });
    </script>