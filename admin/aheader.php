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
    padding-right: 15px;
    background-color: var(--primary-white);
    box-shadow: 0 2px 4px rgba(82, 82, 82, 0.2);
    position: fixed;
    top: 0;
    display:flex;
    justify-content: space-between;
    align-items: center;
    z-index: 1000;
}

.header-pageTitle {
    font-size: 18px;
    font-weight: bold;
    color: #333;
}

.header-bellnProfile {
    display: flex;
    gap: 18px;
}

.header-bell-btn, .header-profile-btn {
    background: none;
    border: none;
    cursor: pointer;
}

.header-profile-pic {
    width: 27px;
    height: 27px;
    border-radius: 50%;
}

.prof-dropdown-cont {
    position: absolute;
    top: calc(var(--header-height) + 5px); 
    right: 15px; 
    border: 1px solid #ccc;
    box-shadow: 1px 1px 3px rgba(0, 0, 0, 0.81);
    z-index: 9999;
    display: none;
}

.prof-info, .prof-menu {
    list-style: none;
    padding: 10px;
}

.prof-info li, .prof-menu li {
    margin: 5px 0;
}

.prof-info {
    background-color: var(--primary-light);
}

.prof-menu {
    background-color: white;
}

.prof-menu li{
    padding: 5px 0;
    border-bottom: 1px solid var(--primary-grey);
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
}

.prof-menu li a{
    color: black;
    text-decoration: none;
}

.prof-menu-icon{
    font-size: 18px;
    color: #333;
}

/*Profile dropdown control*/
.prof-dropdown-cont.show {
    display: block;
}

/* */
.notif-container {
    position: relative;
}

.notif-dropdown {
    position: absolute;
    top: 35px;
    right: 0;
    width: 300px;
    background: white;
    border: 1px solid #ccc;
    box-shadow: 0 3px 8px rgba(0,0,0,0.2);
    display: none;
    z-index: 9999;
}

.notif-dropdown.show {
    display: block;
}

.notif-item {
    padding: 10px;
    border-bottom: 1px solid #eee;
    cursor: pointer;
}

.notif-item:hover {
    background: #f5f5f5;
}

.notif-item.unread {
    background: #fff3f3;
    font-weight: bold;
}

.notif-msg {
    font-size: 14px;
}

.notif-time {
    font-size: 11px;
    color: gray;
}

.notif-empty {
    padding: 15px;
    text-align: center;
    color: gray;
}

.notif-footer {
    text-align: center;
    padding: 10px;
    background: #fafafa;
}

.notif-footer a {
    text-decoration: none;
    color: black;
    font-weight: bold;
}

.bell-wrapper{
    position: relative;
    display: inline-block;
}

.notif-badge{
    position: absolute;
    top: -6px;
    right: -6px;
    background: red;
    color: white;
    font-size: 11px;
    font-weight: bold;
    min-width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2px;
    box-shadow: 0 0 0 2px white;
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