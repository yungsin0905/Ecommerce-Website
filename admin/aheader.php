<?php 
$admin_id = $_SESSION['admin_id'];

// Admin info
$stmt = $conn->prepare("SELECT ADMIN_NAME, ADMIN_EMAIL, ADMIN_IMAGE FROM admin WHERE ADMIN_ID = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc(); 

// Notification (Bell)
$notifCount = 0;

$countSql = "
    SELECT COUNT(*) as total 
    FROM admin_notification an
    WHERE an.ADMIN_ID = $admin_id 
    AND an.IS_READ = 0
";
$countResult = $conn->query($countSql);

if ($row = $countResult->fetch_assoc()) {
    $notifCount = $row['total'];
}
$notifList = [];

// Only show 8 notification records(bell)
$sql = "
    SELECT n.NOTIF_ID, n.MESSAGE, n.TYPE, n.REF_ID, n.CREATED_AT, an.IS_READ
    FROM admin_notification an
    JOIN notification n ON an.NOTIF_ID = n.NOTIF_ID
    WHERE an.ADMIN_ID = $admin_id
    ORDER BY n.CREATED_AT DESC
    LIMIT 8
";

$notifResult = $conn->query($sql);

if ($notifResult) {
    while ($row = $notifResult->fetch_assoc()) {
        $notifList[] = $row;
    }
}
?>

<style>
.header-cont {
    width: calc(100% - var(--sidebar-width));
    left: var(--sidebar-width);
    height: var(--header-height); 
    padding-left: 25px;
    padding-right: 20px;
    background-color: #ffffff;
    border-bottom: 1px solid #edf0f3;
    box-shadow: 0 1px 12px rgba(17, 24, 39, 0.02);
    position: fixed;
    top: 0;
    display:flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1000;
    transition: all 0.25s cubic-bezier(0.25, 0.46, 0.45, 0.94);
}

.header-pageTitle {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 15px;
    font-weight: 700;
    color: #111827;
    letter-spacing: -0.2px;
}

.header-bellnProfile {
    display: flex;
    align-items: center;
    gap: 16px;
}

.header-bell-btn, .header-profile-btn {
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.header-profile-pic {
    width: 32px;
    height: 32px;
    border-radius: 9999px;
    border: 1.5px solid #edf0f3;
    object-fit: cover;
    box-shadow: 0 2px 6px rgba(0,0,0,0.06);
}

.prof-dropdown-cont {
    position: absolute;
    top: calc(var(--header-height) + 8px); 
    right: 18px; 
    border: 1px solid #edf0f3;
    border-radius: 16px;
    box-shadow: 0 12px 32px rgba(17, 24, 39, 0.08);
    background: #ffffff;
    z-index: 9999;
    display: none;
    overflow: hidden;
    min-width: 200px;
}

.prof-info, .prof-menu {
    list-style: none;
    padding: 12px 14px;
    margin: 0;
}

.prof-info {
    background-color: #f8fafc;
    border-bottom: 1px solid #edf0f3;
}

.prof-info li:first-child {
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-weight: 700;
    color: #111827;
    font-size: 13.5px;
}

.prof-info li:last-child {
    font-size: 11.5px;
    color: #71717a;
    margin-top: 2px;
}

.prof-menu {
    background-color: white;
}

.prof-menu li {
    padding: 7px 8px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: background 0.15s ease;
}

.prof-menu li:hover {
    background: #f4f5f8;
}

.prof-menu li a {
    color: #111827;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.prof-menu-icon {
    font-size: 16px;
    color: #71717a;
}

/* Profile dropdown control */
.prof-dropdown-cont.show {
    display: block;
}

.notif-container {
    position: relative;
}

.notif-dropdown {
    position: absolute;
    top: calc(var(--header-height) - 5px);
    right: 0;
    width: 320px;
    background: white;
    border: 1px solid #edf0f3;
    border-radius: 16px;
    box-shadow: 0 12px 36px rgba(17, 24, 39, 0.09);
    display: none;
    z-index: 9999;
    overflow: hidden;
}

.notif-dropdown.show {
    display: block;
}

.notif-item {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f3f6;
    cursor: pointer;
    transition: background 0.15s ease;
}

.notif-item:hover {
    background: #f8fafc;
}

.notif-item.unread {
    background: #f4fde6;
}

.notif-msg {
    font-size: 13px;
    color: #111827;
    line-height: 1.35;
}

.notif-time {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 4px;
}

.notif-empty {
    padding: 24px;
    text-align: center;
    color: #9ca3af;
    font-size: 13px;
}

.notif-footer {
    text-align: center;
    padding: 10px 14px;
    background: #f8fafc;
    border-top: 1px solid #edf0f3;
}

.notif-footer a {
    text-decoration: none;
    color: #111827;
    font-weight: 700;
    font-size: 12.5px;
}

.notif-footer a:hover {
    text-decoration: underline;
}

.bell-wrapper {
    position: relative;
    display: inline-block;
    color: #71717a;
    font-size: 18px;
    transition: color 0.15s ease;
}

.bell-wrapper:hover {
    color: #111827;
}

.notif-badge {
    position: absolute;
    top: -5px;
    right: -7px;
    background: #d4f938;
    color: #111827;
    font-size: 10px;
    font-weight: 800;
    min-width: 17px;
    height: 17px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2px;
    box-shadow: 0 0 0 2px white, 0 2px 6px rgba(212, 249, 56, 0.4);
}

.header-bell-btn, 
.header-profile-btn {
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}

.header-bell-icon,
.header-profile-icon{
    font-size: 20px;
    color: #333;
}
</style>

    <div class="header-cont">

        <!--Page title-->
        <div class="header-pageTitle">
            <?= htmlspecialchars($pageTitle ?? 'Default Title') ?> <!--will be set in each page that includes this header-->
        </div>

        <div class="header-bellnProfile">
            <!--Notification-->
            <div class="notif-container">
                <button class="header-bell-btn" id="bellBtn">
                    <div class="bell-wrapper">
                        <i class="bi bi-bell header-bell-icon"></i>

                        <?php if ($notifCount > 0): ?>
                            <span class="notif-badge">
                                <?= $notifCount > 9 ? '9+' : $notifCount ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </button>

                <!-- Dropdown -->
                <div class="notif-dropdown" id="notifDropdown">

                <?php if (!empty($notifList)): ?>

                <?php foreach ($notifList as $n): ?>
                <div class="notif-item <?= $n['IS_READ'] ? '' : 'unread' ?>" onclick="window.location='notification.php?open_id=<?= $n['NOTIF_ID'] ?>'">

                    <div class="notif-msg">
                        <?= htmlspecialchars($n['MESSAGE']) ?>
                    </div>

                    <div class="notif-time">
                        <?= $n['CREATED_AT'] ?>
                    </div>

                </div>
                <?php endforeach; ?>

                <?php else: ?>
                <div class="notif-empty">No notifications</div>
                <?php endif; ?>

                <div class="notif-footer">
                    <a href="notification.php">View All</a>
                </div>

            </div>
        </div>

          <!--Profile Picture-->
          <button class="header-profile-btn">
             <?php if (!empty($admin['ADMIN_IMAGE'])): ?>
                 <img src="<?= htmlspecialchars($admin['ADMIN_IMAGE']) ?>" alt="Profile" title="Profile" class="header-profile-pic">
             <?php else: ?>
                 <i class="bi bi-person-circle header-profile-icon"></i>
             <?php endif; ?>
         </button>
       </div> 

    </div>

    <div class="prof-dropdown-cont">
       <ul class="prof-info">
         <li><?= htmlspecialchars($admin['ADMIN_NAME']) ?></li>  
         <li><?= htmlspecialchars($admin['ADMIN_EMAIL']) ?></li> 
       </ul>

       <ul class="prof-menu">
          <li>
             <a href="admin_profile.php" title="Profile">
               <i class="bi bi-person prof-menu-icon"></i>
               Profile
             </a>
          </li>
          <li>
             <a href="admin_logout.php" title="Logout">
               <i class="bi bi-box-arrow-right prof-menu-icon"></i>
               Logout
             </a>
          </li>
       </ul>
    </div>

<script>
// Profile Dropdown 
document.addEventListener("DOMContentLoaded", function () {

    const profDropdown = document.querySelector('.prof-dropdown-cont');
    const profileBtn = document.querySelector('.header-profile-btn');

    if (profileBtn && profDropdown) {
        profileBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            profDropdown.classList.toggle("show");
        });

        document.addEventListener("click", function () {
            profDropdown.classList.remove("show");
        });
    }
});

// Notification Dropdown 
const bellBtn = document.getElementById('bellBtn');
const notifDropdown = document.getElementById('notifDropdown');

if (bellBtn && notifDropdown) {

    bellBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        notifDropdown.classList.toggle("show");
    });

    document.addEventListener("click", function () {
        notifDropdown.classList.remove("show");
    });

    notifDropdown.addEventListener("click", function (e) {
        e.stopPropagation();
    });
}
</script>