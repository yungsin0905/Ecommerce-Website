<?php
   $admin_type = $_SESSION['ADMIN_TYPE'] ?? '';

   // Get bakery info
   $bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
   $bakery = mysqli_fetch_assoc($bakeryResult);

   // Get current file name
   $current_page = basename($_SERVER['PHP_SELF']);
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
.sidebar-cont {
   width: var(--sidebar-width);
   height: 100vh;
   background-color: #ffffff;
   border-right: 1px solid #edf0f3;
   box-shadow: 2px 0 20px rgba(17, 24, 39, 0.03); 
   position: fixed;
   z-index: 1001;
   top: 0;
   left: 0;
   transition: all 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.sidebar2 {
   height: 100vh;
   width: 100%;
   padding: 0 6px;
   overflow-y: auto;
   overflow-x: hidden;
}

.sidebar2::-webkit-scrollbar {
   width: 4px;
}
.sidebar2::-webkit-scrollbar-track {
   background: transparent;
}
.sidebar2::-webkit-scrollbar-thumb {
   background: #e2e8f0;
   border-radius: 9999px;
}
.sidebar2::-webkit-scrollbar-thumb:hover {
   background: #cbd5e1;
}

.sidebar-ctrl-btn {
   background: #18181b;
   position: absolute;
   top: 18px;
   right: -13px;
   width: 26px;
   height: 26px;
   border-radius: 9999px;
   border: 2px solid #ffffff;
   cursor: pointer; 
   display: flex;
   align-items: center;
   justify-content: center;
   box-shadow: 0 4px 10px rgba(0,0,0,0.15);
   transition: all 0.2s ease;
   z-index: 1002;
   padding: 0;
}

.icon-expand,
.icon-collapse {
   color: #ffffff;
   font-size: 11px;
   display: flex;
   align-items: center;
   justify-content: center;
}

.icon-expand {
   display: none;
}

.icon-collapse {
   display: block;
}

.sidebar-ctrl-btn:hover {
   background: #d4f938;
   transform: scale(1.12);
   box-shadow: 0 4px 12px rgba(212, 249, 56, 0.45);
}

.sidebar-ctrl-btn:hover .icon-expand,
.sidebar-ctrl-btn:hover .icon-collapse {
   color: #18181b;
}

.sidebar-brand {
   display: flex;
   align-items: center;
   gap: 10px;
   padding: 16px 12px 14px;
   border-bottom: 1px solid #f1f3f6;
   margin-bottom: 10px;
}

.sidebar-logo {
   height: 38px;
   width: auto;
   border-radius: 10px;
   object-fit: contain;
}

.brand-text {
   font-family: 'Plus Jakarta Sans', sans-serif;
   font-size: 15px;
   font-weight: 800;
   color: #111827;
   letter-spacing: -0.3px;
   white-space: nowrap;
   overflow: hidden;
   text-overflow: ellipsis;
}

.sidebar-menu {
   list-style-type: none;
   margin: 0;
   padding: 4px 4px 30px;
}

.sidebar-menu li {
   padding: 0;
   margin-bottom: 3px;
   border-radius: 9999px;
   transition: all 0.2s ease;
}

.sidebar-menu li a {
   display: flex;
   align-items: center;
   gap: 10px;
   padding: 8.5px 14px;
   border-radius: 9999px;
   text-decoration: none;
   color: #71717a;
   font-family: 'Plus Jakarta Sans', sans-serif;
   font-size: 13.5px;
   font-weight: 600;
   transition: all 0.2s ease;
}

.sidebar-icon {
   font-size: 15.5px;
   color: #8e95a5;
   width: 20px;
   text-align: center;
   flex-shrink: 0;
   transition: all 0.2s ease;
   display: inline-flex;
   align-items: center;
   justify-content: center;
}

.sidebar-menu li:hover {
   background: #f4f5f8;
}

.sidebar-menu li:hover a {
   color: #111827;
}

.sidebar-menu li:hover .sidebar-icon {
   color: #111827;
}

/* Active Menu Item (Neon Lime Capsule) */
.sidebar-menu li.active {
   background: #d4f938 !important;
   box-shadow: 0 4px 14px rgba(212, 249, 56, 0.35);
}

.sidebar-menu li.active a {
   color: #111827 !important;
   font-weight: 800 !important;
}

.sidebar-menu li.active .sidebar-icon {
   color: #111827 !important;
   font-weight: 800;
}

.sidebar-menu li.active:hover {
   background: #d4f938 !important;
}

.sub-sidebar-btn {
   margin-left: auto;
   font-size: 9px;
   transition: transform 0.2s ease; 
   opacity: 0.6;
}

/* Sub sidebar menu control */
.sub-sidebar-menu {
   display: none;
   list-style: none;
   padding-left: 20px;
   margin-top: 3px;
   margin-bottom: 4px;
}

.sub-sidebar-menu li {
   padding: 0;
   margin-bottom: 2px;
}

.sub-sidebar-menu li a {
   padding: 6px 12px;
   font-size: 12.5px;
   color: #71717a;
   font-weight: 500;
   border-radius: 9999px;
}

.sub-sidebar-menu li:hover a {
   background: #f4f5f8;
   color: #111827;
}

.sub-sidebar-menu li.sub-active a {
   background: #18181b !important;
   color: #d4f938 !important;
   font-weight: 700;
   border-radius: 9999px;
}

.has-submenu.open .sub-sidebar-menu {
   display: block;
}

.has-submenu.open .sub-sidebar-btn {
   transform: rotate(90deg);
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
