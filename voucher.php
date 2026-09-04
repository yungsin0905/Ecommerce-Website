<?php include 'include/config.php';
session_start();

//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

//verify user login
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];


//get customer's tier info
$customer_query = "SELECT c.TIER_ID, c.TOTAL_SPENT, mt.TIER_NAME
                   FROM customer c
                   LEFT JOIN membership_tier mt ON c.TIER_ID = mt.TIER_ID
                   WHERE c.CUSTOMER_ID = '$customer_id'
                   AND c.STATUS = 'Active'";

$customer_result = mysqli_query($conn, $customer_query);
$customer = mysqli_fetch_assoc($customer_result);

$customer_tier_id = $customer['TIER_ID'];
$customer_total_spent = $customer['TOTAL_SPENT'];
assignTierVouchers($conn, $customer_id, $customer_tier_id);


//get all active, non-deleted vouchers
// //expired date based on voucher type
$voucher_result = mysqli_query($conn, "
        SELECT 
        v.*,
        mt.TIER_NAME,
        cv.CUSTOMER_VOUCHER_ID,
        cv.USED_COUNT  AS CUSTOMER_USED_COUNT,
        cv.CLAIMED_AT,
        cv.EXPIRY_DATE AS CUSTOMER_EXPIRY_DATE,
        cv.LAST_USED_AT,
        CASE 
            WHEN v.VOUCHER_TYPE = 'Public' THEN v.EXPIRY_DATE
            ELSE cv.EXPIRY_DATE
        END AS EFFECTIVE_EXPIRY_DATE

    FROM customer_voucher cv
    INNER JOIN voucher v ON cv.VOUCHER_ID = v.VOUCHER_ID
    LEFT JOIN membership_tier mt ON v.TIER_ID = mt.TIER_ID
    WHERE cv.CUSTOMER_ID = $customer_id
      AND v.IS_DELETED = 0
      AND v.VOUCHER_STATUS = 'Active'
      AND (
            (v.VOUCHER_TYPE = 'Public' AND v.TIER_ID IS NULL)
            OR
            (v.VOUCHER_TYPE = 'Tier' AND v.TIER_ID = $customer_tier_id AND cv.CUSTOMER_VOUCHER_ID IS NOT NULL)
          )
    ORDER BY cv.EXPIRY_DATE ASC
");

$available_vouchers = [];
$today = new DateTime();


function formatDate($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00 00:00:00' || $dateStr === '0000-00-00') {
        return 'N/A';
    }
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr)
         ?: DateTime::createFromFormat('Y-m-d', $dateStr);
    return $date ? $date->format('d M, Y') : 'N/A';
}

while ($row = mysqli_fetch_assoc($voucher_result)) {
/// Get the voucher's effective expiry date for this customer
    $effective_expiry = $row['EFFECTIVE_EXPIRY_DATE'];
     $is_expired = false;

      // Check expiry only if a valid date exists 
    if (!empty($effective_expiry) &&
      $effective_expiry !== '0000-00-00 00:00:00' 
      && $effective_expiry !== '0000-00-00') {
      $expiry = new DateTime($effective_expiry);
      $is_expired = $expiry < $today;
    }

     // How many times this specific customer has used this voucher (default 0 if not set)
    $customer_used = $row['CUSTOMER_USED_COUNT'] ?? 0;
    
    // -1 is used as a convention to mean "unlimited" for both limit types
    $both_unlimited = ($row['PER_USER_LIMIT'] == -1 && $row['MAX_USAGE'] == -1);

    if ($both_unlimited) {
        // if max usage and user limit are -1, It always shows no matter how many times it's used.
        $show = true;
    } else {
        // If any one has a limitation, hide it after use.
        $per_user_ok  = $row['PER_USER_LIMIT'] == -1 || $customer_used < $row['PER_USER_LIMIT'];
        $max_usage_ok = $row['MAX_USAGE'] == -1 || $row['USED_COUNT'] < $row['MAX_USAGE'];
        $show = $per_user_ok && $max_usage_ok;
    }

    if (!$is_expired && $show) {
        $row['DISPLAY_EXPIRY'] = $effective_expiry;
        $available_vouchers[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher</title>
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
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --accent-blue: #3c8cb1;
        --rating-color: #F5A623;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        /* hover effect */
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
      }

      body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        color: var(--font-color);
        margin: 0;
        padding: 0;
      }

      /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 10px 0;
        padding: 0 10px;
        position: relative;
        z-index: 10;
      }

      .back-link {
        text-decoration: none;
        color: var(--font2-color);
        font-size: 16px;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        transition: var(--transition);
      }

      .back-link:hover {
        color: var(--accent-blue);
        text-decoration: none;
      }

      .voucher-section {
        max-width: 1060px;
        margin: 0 auto;
        padding: 0 20px 60px;
      }

      .voucher-title {
        color: var(--font-color);
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        font-size: 32px;
        margin-bottom: 8px;
      }

      .group-label {
        color: var(--font2-color);
        font-family: 'Poppins', sans-serif;
        font-size: 14px;
        font-weight: 700;
        margin: 25px 0 15px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
      }

      .voucher-group {
        margin-bottom: 50px;
      }

      /* Modern ticket grid */
      .voucher-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(460px, 1fr));
        gap: 22px;
        margin-top: 15px;
      }

      .voucher-card {
        background: #ffffff;
        border: 1px solid var(--search-border-color);
        border-radius: 16px;
        display: flex;
        position: relative;
        overflow: hidden;
        box-shadow: 0 4px 18px rgba(27, 42, 60, 0.05);
        transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
      }

      .voucher-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px rgba(60, 140, 177, 0.16);
        border-color: var(--main-color);
      }

      /* Left discount column */
      .voucher-left {
        width: 155px;
        flex-shrink: 0;
        background: linear-gradient(145deg, #e8f4f9 0%, #cfe6f4 100%);
        padding: 24px 14px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
        position: relative;
      }

      .discount-wrap {
        color: var(--font-color);
        display: flex;
        align-items: baseline;
        justify-content: center;
        line-height: 1;
        margin-bottom: 2px;
      }

      .discount-value {
        font-family: 'Poppins', sans-serif;
        font-size: 40px;
        font-weight: 800;
        color: var(--font-color);
      }

      .discount-symbol {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
        font-weight: 700;
        margin-left: 2px;
        color: var(--accent-blue);
      }

      .discount-type {
        font-family: 'Poppins', sans-serif;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 1.5px;
        color: var(--font2-color);
        margin-left: 4px;
      }

      .min-spend-badge {
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        font-weight: 600;
        color: var(--font-color);
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid var(--search-border-color);
        padding: 4px 10px;
        border-radius: 20px;
        margin-top: 10px;
        white-space: nowrap;
        box-shadow: 0 2px 5px rgba(27, 42, 60, 0.04);
      }

      /* Ticket perforation divider */
      .voucher-cutout {
        position: relative;
        width: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
      }

      .voucher-cutout .dashed-line {
        height: 75%;
        border-left: 2px dashed #C9DCEE;
      }

      .voucher-cutout .notch {
        position: absolute;
        width: 20px;
        height: 20px;
        background: var(--bg-color);
        border: 1px solid var(--search-border-color);
        border-radius: 50%;
        z-index: 2;
      }

      .voucher-cutout .notch-top {
        top: -10px;
        clip-path: polygon(0 50%, 100% 50%, 100% 100%, 0 100%);
      }

      .voucher-cutout .notch-bottom {
        bottom: -10px;
        clip-path: polygon(0 0, 100% 0, 100% 50%, 0 50%);
      }

      /* Right details column */
      .voucher-right {
        flex: 1;
        padding: 20px 22px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
      }

      .voucher-top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        margin-bottom: 6px;
      }

      .voucher-tier-badge {
        font-family: 'Inter', sans-serif;
        font-size: 11.5px;
        font-weight: 600;
        padding: 4px 12px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
      }

      .voucher-tier-badge.tier-public {
        background: var(--secondary-color);
        color: var(--accent-blue);
        border: 1px solid var(--search-border-color);
      }

      .voucher-tier-badge.tier-member {
        background: #eaf4fb;
        color: var(--font-color);
        border: 1px solid #bddcee;
      }

      .status-ready-badge {
        font-family: 'Inter', sans-serif;
        font-size: 11.5px;
        font-weight: 600;
        color: #176534;
        background: #e8f8ed;
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
      }

      .voucher-card-title {
        font-family: 'Poppins', sans-serif;
        font-size: 20px;
        font-weight: 700;
        color: var(--font-color);
        margin: 4px 0 6px;
        line-height: 1.3;
      }

      .voucher-subtext {
        font-family: 'Inter', sans-serif;
        font-size: 12.5px;
        color: var(--font2-color);
        margin: 0 0 14px;
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .voucher-bottom-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-top: 1px solid #f0f5fa;
        padding-top: 12px;
        margin-top: auto;
      }

      .expiry-text {
        font-family: 'Inter', sans-serif;
        font-size: 12px;
        color: var(--font2-color);
        display: flex;
        align-items: center;
        gap: 5px;
      }

      .btn-use {
        font-family: 'Inter', sans-serif;
        font-size: 12.5px;
        font-weight: 600;
        color: #ffffff;
        background: var(--accent-blue);
        padding: 6px 14px;
        border-radius: 8px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: var(--transition);
        box-shadow: 0 2px 8px rgba(60, 140, 177, 0.25);
      }

      .btn-use:hover {
        background: #2b7091;
        color: #ffffff;
        transform: translateX(2px);
      }

      @media (max-width: 600px) {
        .voucher-grid {
          grid-template-columns: 1fr;
        }

        .voucher-card {
          flex-direction: column;
        }

        .voucher-left {
          width: 100%;
          padding: 16px;
        }

        .voucher-cutout {
          display: none;
        }

        .voucher-bottom-bar {
          flex-direction: column;
          align-items: flex-start;
          gap: 10px;
        }

        .btn-use {
          width: 100%;
          justify-content: center;
        }
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
  </div>

  <section class="voucher-section">
    <h2 class="voucher-title">Vouchers</h2>
    
    <!-- available voucher -->
    <div class="voucher-group">
      <h3 class="group-label">Available Vouchers</h3>
      
      <?php if (empty($available_vouchers)):?>
        <p style="color:var(--font2-color); opacity:0.7;">No vouchers available for you right now.</p>
      <?php else:?>
        <div class="voucher-grid">
          <?php foreach ($available_vouchers as $v):
            $isClaimed = !is_null($v['CUSTOMER_VOUCHER_ID']);
          ?>
            <div class="voucher-card">
              <!-- Left Discount Area -->
              <div class="voucher-left">
                <div class="discount-wrap">
                  <span class="discount-value"><?= $v['DISCOUNT_RATE'] ?></span><span class="discount-symbol">%</span>
                  <span class="discount-type">OFF</span>
                </div>
                <div class="min-spend-badge">
                  Min. Spend RM <?= number_format($v['MIN_SPEND'], 0) ?>
                </div>
              </div>

              <!-- Ticket Divider -->
              <div class="voucher-cutout">
                <span class="notch notch-top"></span>
                <span class="dashed-line"></span>
                <span class="notch notch-bottom"></span>
              </div>

              <!-- Right Content Area -->
              <div class="voucher-right">
                <div class="voucher-top-bar">
                  <span class="voucher-tier-badge <?= ($v['VOUCHER_TYPE'] === 'Public') ? 'tier-public' : 'tier-member' ?>">
                    <i class="bi <?= ($v['VOUCHER_TYPE'] === 'Public') ? 'bi-globe2' : 'bi-award-fill' ?>"></i>
                    <?= ($v['VOUCHER_TYPE'] === 'Public') ? 'All Customers' : 'Only for ' . htmlspecialchars($v['TIER_NAME']) ?>
                  </span>
                  <span class="status-ready-badge">
                    <i class="bi bi-check-circle-fill"></i> Ready to Use
                  </span>
                </div>

                <h3 class="voucher-card-title"><?= htmlspecialchars($v['VOUCHER_NAME']) ?></h3>
                
                <p class="voucher-subtext">
                  <i class="bi bi-tag-fill"></i> Shop name
                </p>

                <div class="voucher-bottom-bar">
                  <div class="expiry-text">
                    <i class="bi bi-clock"></i> Valid until <?= formatDate($v['DISPLAY_EXPIRY']) ?>
                  </div>
                  <a href="product catalogue.php" class="btn-use">Use Now <i class="bi bi-arrow-right"></i></a>
                </div>
              </div>
            </div>
          <?php endforeach;?>
        </div>
      <?php endif;?>
    </div>
  </section>

  <?php include 'include/footer.php'?>
</body>
</html>
