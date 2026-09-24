<?php
ob_start(); 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


include_once 'config.php';

// Lazily process due monthly vouchers / quarterly tier maintenance
if (isset($_SESSION['CUSTOMER_ID'])) {
    require_once __DIR__ . '/membership_functions.php';
    check_and_process_membership($conn, $_SESSION['CUSTOMER_ID']);
}

// Fetch recent notifications for the bell dropdown (latest 5, unread count)
$recentNotifications = [];
$unreadNotifCount = 0;

if (isset($_SESSION['CUSTOMER_ID'])) {
    $notif_customer_id = intval($_SESSION['CUSTOMER_ID']);

    $notif_sql = "SELECT n.NOTIF_ID, n.TYPE, n.REF_ID, n.MESSAGE, n.CREATED_AT, cn.IS_READ
                  FROM customer_notification cn
                  JOIN notification n ON cn.NOTIF_ID = n.NOTIF_ID
                  WHERE cn.CUSTOMER_ID = ?
                  ORDER BY n.CREATED_AT DESC
                  LIMIT 5";
    $notif_stmt = $conn->prepare($notif_sql);
    $notif_stmt->bind_param("i", $notif_customer_id);
    $notif_stmt->execute();
    $recentNotifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $unread_sql = "SELECT COUNT(*) AS c FROM customer_notification WHERE CUSTOMER_ID = ? AND IS_READ = 0";
    $unread_stmt = $conn->prepare($unread_sql);
    $unread_stmt->bind_param("i", $notif_customer_id);
    $unread_stmt->execute();
    $unreadNotifCount = $unread_stmt->get_result()->fetch_assoc()['c'] ?? 0;
}

// Map notification TYPE -> icon class, link, bootstrap icon
function getHeaderNotifDisplay($type) {
    switch ($type) {
        case 'Order':
        case 'Delivery':
            return ['icon_class' => 'icon-order', 'icon' => 'bi-truck', 'link' => 'order_history.php'];
        case 'Voucher':
            return ['icon_class' => 'icon-voucher', 'icon' => 'bi-ticket-perforated', 'link' => 'voucher.php'];
        case 'Membership':
            return ['icon_class' => 'icon-member', 'icon' => 'bi-person-badge', 'link' => 'membership.php'];
        default:
            return ['icon_class' => 'icon-order', 'icon' => 'bi-bell', 'link' => 'notification.php'];
    }
}

function header_time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return "just now";
    if ($diff < 3600) return floor($diff / 60) . " mins ago";
    if ($diff < 86400) return floor($diff / 3600) . " hour" . (floor($diff / 3600) > 1 ? "s" : "") . " ago";
    if ($diff < 604800) return floor($diff / 86400) . " day" . (floor($diff / 86400) > 1 ? "s" : "") . " ago";
    return date('d M Y', strtotime($datetime));
}

$bakery_query = "SELECT * FROM bakery_info";
$bakery_result = $conn->query($bakery_query);
$bakery_info = $bakery_result->fetch_assoc();

//category
$category_result = $conn->query("
    SELECT CATEGORY_ID, CATEGORY_NAME 
    FROM category 
    WHERE CATEGORY_STATUS = 'Active' AND PARENT_ID IS NULL AND IS_DELETED = 0
    ORDER BY CATEGORY_ID ASC
");
$categories = [];
while ($cat = $category_result->fetch_assoc()) {
    $categories[] = $cat;
}

?>
<style>
/* --- Notification Container & Bell Button --- */
.notification-container {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.bell-btn {
  border: none;
  background: transparent;
  color: var(--main-color, #80b8d2);
  font-size: 22px;
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  cursor: pointer;
  position: relative;
  transition: color 0.3s ease, background-color 0.3s ease;
  padding: 0;
  margin: 0;
}

.bell-btn:hover,
.notification-container.active .bell-btn {
  color: #3c8cb1;
  background-color: rgba(128, 184, 210, 0.12);
}

.notification-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  background-color: #ff5252;
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  min-width: 16px;
  height: 16px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
  padding: 0 3px;
  box-shadow: 0 2px 4px rgba(255, 82, 82, 0.3);
}

/* --- Notification Dropdown Menu (Styled like Products Dropdown) --- */
.notification-dropdown {
  display: none;
  position: absolute;
  top: calc(100% + 12px);
  right: 0;
  width: 320px;
  background-color: #ffffff;
  box-shadow: 0 8px 20px rgba(27, 42, 60, 0.15);
  border-radius: 8px;
  border: 1px solid rgba(201, 220, 238, 0.6);
  z-index: 1050;
  overflow: hidden;
  text-align: left;
}

.notification-container.active .notification-dropdown,
.notification-container:hover .notification-dropdown {
  display: block;
}

.notification-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  border-bottom: 1px solid #eef4f8;
  background-color: #f9fbfd;
}

.notification-header h4 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #3c8cb1;
  font-family: 'Inter', sans-serif;
}

.notification-header .mark-all-read {
  font-size: 11px;
  font-weight: 600;
  color: var(--main-color, #80b8d2);
  background-color: rgba(128, 184, 210, 0.15);
  padding: 3px 8px;
  border-radius: 12px;
}

.notification-list {
  max-height: 320px;
  overflow-y: auto;
}

.notification-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 18px;
  text-decoration: none !important;
  color: var(--font-color, #1B2A3C);
  border-bottom: 1px solid #f4f7f9;
  transition: background-color 0.2s ease;
}

.notification-item:hover {
  background-color: #f7fafc;
}

.notification-item.unread {
  background-color: #f0f7fc;
}

.notification-item.unread:hover {
  background-color: #e5f2fa;
}

.notification-icon {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  flex-shrink: 0;
}

.icon-order {
  background-color: #e3f2fd;
  color: #1e88e5;
}

.icon-voucher {
  background-color: #fff3e0;
  color: #fb8c00;
}

.icon-member {
  background-color: #e8f5e9;
  color: #43a047;
}

.notification-content {
  flex: 1;
}

.notification-title {
  margin: 0 0 2px 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--font-color, #1B2A3C);
}

.notification-text {
  margin: 0 0 4px 0;
  font-size: 12px;
  color: var(--font2-color, #52708A);
  line-height: 1.4;
}

.notification-time {
  font-size: 10px;
  color: #9db4c7;
}

.notification-footer {
  padding: 10px 18px;
  text-align: center;
  background-color: #f9fbfd;
  border-top: 1px solid #eef4f8;
}

.notification-footer a {
  font-size: 12px;
  font-weight: 600;
  color: var(--main-color, #80b8d2);
  text-decoration: none !important;
}

.notification-footer a:hover {
  color: #3c8cb1;
  text-decoration: underline !important;
}
</style>
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
                    <a href="all_categories.php" class="nav-link-item">Products</a>

                    <div class="mega-menu">
                        <div class="menu-column">
                            <h4>Favourites</h4>
                            <ul>
                                <li><a href="product catalogue.php?cake_type=Best%20Selling">Best Selling</a></li>
                                <li><a href="product catalogue.php?cake_type=High%20Recommended">High Recommended</a></li>
                            </ul>
                        </div>
                        <div class="menu-column">
                            <h4>Categories</h4>
                            <ul>
                                <li><a href="all_categories.php">All Categories</a></li>
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

            <!-- Notification Container (Bell Icon & Dropdown) -->
            <div class="notification-container" id="notificationContainer">
                <button type="button" class="bell-btn" id="bellToggleBtn" aria-label="Notifications">
                    <i class="bi bi-bell"></i>
                    <?php if ($unreadNotifCount > 0): ?>
                        <span class="notification-badge"><?= $unreadNotifCount ?></span>
                    <?php endif; ?>
                </button>

                <!-- Notification Dropdown Menu -->
                <div class="notification-dropdown" id="notificationDropdown">
                    <div class="notification-header">
                        <h4>Notifications</h4>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="mark-all-read"><?= $unreadNotifCount ?> New</span>
                        <?php endif; ?>
                    </div>

                    <div class="notification-list">
                        <?php if (empty($recentNotifications)): ?>
                            <div style="padding: 24px 18px; text-align: center; color: var(--font2-color); font-size: 13px;">
                                No notifications yet.
                            </div>
                        <?php else: ?>
                            <?php foreach ($recentNotifications as $n):
                                $display = getHeaderNotifDisplay($n['TYPE']);
                                $unreadClass = $n['IS_READ'] ? '' : 'unread';
                            ?>
                                <a href="<?= $display['link'] ?>" class="notification-item <?= $unreadClass ?>">
                                    <div class="notification-icon <?= $display['icon_class'] ?>">
                                        <i class="bi <?= $display['icon'] ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <p class="notification-title"><?= htmlspecialchars($n['TYPE']) ?></p>
                                        <p class="notification-text"><?= htmlspecialchars($n['MESSAGE']) ?></p>
                                        <span class="notification-time"><?= header_time_ago($n['CREATED_AT']) ?></span>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
        
                    <div class="notification-footer">
                        <a href="notification.php">View All Messages</a>
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <i class="bi bi-list menu-icon" data-bs-toggle="offcanvas" data-bs-target="#mobileMenu"></i>
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
                        <li class="list-group-item border-0"><a href="order_history.php" class="text-decoration-none"><i class="bi bi-clipboard2-check-fill me-2"></i> Order History</a></li>
                        <li class="list-group-item border-0"><a href="notification.php" class="text-decoration-none"><i class="bi bi-bell-fill me-2"></i> Notifications</a></li>
                        <li class="list-group-item border-0"><a href="topup.php" class="text-decoration-none"><i class="bi bi-credit-card-2-back-fill me-2"></i> Top-Up</a></li>
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

        // --- Bell notification toggle ---
        var notifContainer = document.getElementById('notificationContainer');
        var bellBtn = document.getElementById('bellToggleBtn');

        if (notifContainer && bellBtn) {
            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                notifContainer.classList.toggle('active');
            });

            document.addEventListener('click', function (e) {
                if (!notifContainer.contains(e.target)) {
                    notifContainer.classList.remove('active');
                }
            });
        }
    });
    </script>