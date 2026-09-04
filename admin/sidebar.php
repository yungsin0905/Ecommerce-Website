<?php
   $admin_type = $_SESSION['ADMIN_TYPE'] ?? '';

   // Get bakery info
   $bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
   $bakery = mysqli_fetch_assoc($bakeryResult);

   // Get current file name
   $current_page = basename($_SERVER['PHP_SELF']);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.sidebar-cont {
   width: var(--sidebar-width);
   height: 100vh;
   background-color: var(--primary-white);
   box-shadow: 0 2px 6px rgba(0,0,0,0.2); 
   position: fixed;
   z-index: 1001;
   top: 0;
   left: 0;
}

.sidebar2 {
   height:100vh;
   width: 100%;
   padding: 0 8px;
   overflow-y: auto;
   overflow-x: hidden;
}

.sidebar-ctrl-btn {
   background: var(--primary-dark);
   position: absolute;
   top: 8px;
   right: -15px;
   width: 30px;
   height: 30px;
   border-radius: 50px;
   border: none;
   padding-top: 3px;
   cursor: pointer; 
}

.icon-expand,
.icon-collapse {
   position: absolute;
   top: 50%;
   left: 50%;
   transform: translate(-50%, -50%);
}

.icon-expand {
   display: none;
}

.icon-collapse {
   display: block;
}

.sidebar-ctrl-btn:hover {
   transform: scale(1.1);
}

.sidebar-logo {
   margin-top: 10px;
   height: 55px;
}

.sidebar-brand {
   display: flex;
   align-items: center;
   gap: 10px;
}

.brand-text {
   font-size: 18px;
   font-weight: 600;
   color: #824444;
   text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
   padding-top: 10px;
}

.sidebar-menu {
   list-style-type: none;
   margin-top: 20px;
}

.sidebar-menu li{
   padding: 10px 5px;
}

.sidebar-menu li:hover {
   background: var(--primary-light);
   border-radius: 5px;
}

.sidebar-menu li a {
   text-decoration: none;
   color: #3b3b3b;
   font-size: 17px;
}

.sidebar-icon {
   margin-right: 10px;
}

.sub-sidebar-btn {
   float: right;
   transition: 0.2s; 
}

/*Sub sidebar menu control*/
.sidebar-menu .active {
   background-color: var(--primary-light);
   border-radius: 5px;
}

.sub-sidebar-menu {
   display: none;
   list-style: none;
   padding-left: 25px;
   margin-top: 5px;
}

.sub-sidebar-menu li {
   padding: 8px 5px;
}

.sub-sidebar-menu li.sub-active {
   background-color: rgba(0, 0, 0, 0.05); 
   border-radius: 5px;
}

.sub-sidebar-menu li.sub-active a {
   color: #824444; 
   font-weight: 600;
}

.sub-sidebar-menu li a {
   font-size: 15px;
   color: #555;
}

.has-submenu.open .sub-sidebar-menu {
   display: block;
}

.has-submenu.open .sub-sidebar-btn {
   transform: rotate(90deg);
}

.icon-expand,
.icon-collapse {
    color: var(--primary-white);
    font-size: 13px;
}
</style>

<div class="sidebar-cont">

   <!--sidebar toggle button-->
   <button class="sidebar-ctrl-btn">
      <i class="bi bi-chevron-right icon-expand"></i>
      <i class="bi bi-chevron-left icon-collapse"></i>
   </button>

   <div class="sidebar2">

      <!--sidebar logo-->
      <div class="sidebar-brand">
         <img src="<?= htmlspecialchars($bakery['SHOP_IMAGE']) ?>" alt="Logo" class="sidebar-logo">
         <span class="brand-text"><?= htmlspecialchars($bakery['SHOP_NAME']) ?></span>
      </div>

      <!--sidebar menu-->
      <ul class="sidebar-menu">

         <li class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
            <a href="dashboard.php">
               <i class="bi bi-speedometer2 sidebar-icon"></i>
               <span class="sidebar-text">Dashboard</span>  
            </a>
         </li>

         <!-- order main menu with sub menu -->
         <?php 
            $is_order_group = in_array($current_page, ['manage_order.php', 'manage_custom_request.php', 'manage_refund_request.php']);
            $order_open_class = in_array($current_page, ['manage_custom_request.php', 'manage_refund_request.php']) ? 'open' : '';
         ?>
         <li class="has-submenu <?= $is_order_group ? 'active' : '' ?> <?= $order_open_class ?>">
            <a href="manage_order.php">
               <i class="bi bi-bag-check sidebar-icon"></i>
               <span class="sidebar-text">Order</span>
               <span class="sub-sidebar-btn">▶</span>
            </a>

            <ul class="sub-sidebar-menu">

               <li class="<?= $current_page == 'manage_custom_request.php' ? 'sub-active' : '' ?>">
                  <a href="manage_custom_request.php">
                     <i class="bi bi-palette sidebar-icon"></i><br>
                     <span class="sidebar-text">Customization request</span>
                  </a>
               </li>

               <li class="<?= $current_page == 'manage_refund_request.php' ? 'sub-active' : '' ?>">
                  <a href="manage_refund_request.php">
                     <i class="bi bi-cash-coin sidebar-icon"></i><br>
                     <span class="sidebar-text">Refund requests</span>
                  </a>
               </li>
            </ul>
         </li>

         <li class="<?= $current_page == 'manage_delivery.php' ? 'active' : '' ?>">
            <a href="manage_delivery.php">
               <i class="bi bi-truck sidebar-icon"></i>
               <span class="sidebar-text">Delivery</span>
            </a>
         </li>

         <li class="<?= $current_page == 'manage_category.php' ? 'active' : '' ?>">
            <a href="manage_category.php">
               <i class="bi bi-grid sidebar-icon"></i>
               <span class="sidebar-text">Category</span>
            </a>
         </li>

         <li class="<?= $current_page == 'manage_product.php' ? 'active' : '' ?> has-submenu">
            <a href="manage_product.php">
               <i class="bi bi-cake2 sidebar-icon"></i>
               <span class="sidebar-text">Product</span>
            </a>
         </li>

         <li class="<?= $current_page == 'manage_addon.php' ? 'active' : '' ?>">
            <a href="manage_addon.php">
               <i class="bi bi-plus-square sidebar-icon"></i>
               <span class="sidebar-text">Addon</span>
            </a>
         </li>
            
         <li class="<?= $current_page == 'manage_voucher.php' ? 'active' : '' ?>">
            <a href="manage_voucher.php">
               <i class="bi bi-ticket-perforated sidebar-icon"></i>
               <span class="sidebar-text">Voucher</span>
            </a>
         </li>

         <li class="<?= $current_page == 'manage_customer.php' ? 'active' : '' ?>">
            <a href="manage_customer.php">
               <i class="bi bi-people sidebar-icon"></i>
               <span class="sidebar-text">Customer</span>
            </a>
         </li>

         <li class="<?= $current_page == 'manage_review.php' ? 'active' : '' ?>">
            <a href="manage_review.php">
               <i class="bi bi-star sidebar-icon"></i>
               <span class="sidebar-text">Review</span>
            </a>
         </li>

         <li class="<?= $current_page == 'production_capacity.php' ? 'active' : '' ?>">
            <a href="production_capacity.php">
               <i class="bi bi-building-gear sidebar-icon"></i>
               <span class="sidebar-text">Production</span>
            </a>
         </li>

         <?php if ($admin_type === 'Super Admin'): ?>
            <li class="<?= $current_page == 'manage_admin.php' ? 'active' : '' ?>">
               <a href="manage_admin.php">
                  <i class="bi bi-person-gear sidebar-icon"></i>
                  <span class="sidebar-text">Admin</span>
               </a>
            </li>
         <?php endif; ?>

         <li class="<?= $current_page == 'notification.php' ? 'active' : '' ?>">
            <a href="notification.php">
               <i class="bi bi-bell sidebar-icon"></i>
               <span class="sidebar-text">Notification</span>
            </a>
         </li>

         <li class="<?= $current_page == 'report.php' ? 'active' : '' ?>">
            <a href="report.php">
               <i class="bi bi-graph-up sidebar-icon"></i>
               <span class="sidebar-text">Report</span>
            </a>
         </li>

         <li class="<?= $current_page == 'settings.php' ? 'active' : '' ?>">
            <a href="settings.php">
               <i class="bi bi-gear sidebar-icon"></i>
               <span class="sidebar-text">Settings</span>
            </a>
         </li>

      </ul>

   </div>
</div>

<script>
// Sidebar submenu toggle
document.addEventListener("DOMContentLoaded", () => {
    const items = document.querySelectorAll(".has-submenu");

    items.forEach(item => {
        const btn = item.querySelector(".sub-sidebar-btn");
        if (!btn) return;

        btn.addEventListener("click", (e) => {
      
            e.preventDefault();
            e.stopPropagation(); 
            
            item.classList.toggle("open");
        });
    });
});
</script>
