<?php include 'include/config.php';
require_once 'include/membership_functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);

// Run membership checks BEFORE reading data so this page shows up-to-date info
check_and_process_membership($conn, $customer_id);

$customer_result = mysqli_query($conn,
    "SELECT c.*, t.TIER_NAME, t.MIN_SPENT
     FROM customer c
     LEFT JOIN membership_tier t ON c.TIER_ID = t.TIER_ID
     WHERE c.CUSTOMER_ID = $customer_id AND c.STATUS = 'Active'"
);
$customer = mysqli_fetch_assoc($customer_result);

if (!$customer) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$tier_result = mysqli_query($conn, "SELECT * FROM membership_tier WHERE STATUS = 'Active' ORDER BY MIN_SPENT ASC");
$tiers = mysqli_fetch_all($tier_result, MYSQLI_ASSOC);

$current_tier_name      = $customer['TIER_NAME'];
$current_tier_min_spent = floatval($customer['MIN_SPENT']);
$quarter_spent          = floatval($customer['QUARTER_SPENT']);

// End of the current 3-month window
$window_start = !empty($customer['TIER_WINDOW_START']) ? new DateTime($customer['TIER_WINDOW_START']) : new DateTime();
$window_end   = clone $window_start;
$window_end->modify('+3 months');
$window_end_text = $window_end->format('d M Y');

// Tiers above the current one, and the very next one
$higher_tiers = array_filter($tiers, function($t) use ($current_tier_min_spent) {
    return floatval($t['MIN_SPENT']) > $current_tier_min_spent;
});
$next_tier = !empty($higher_tiers) ? reset($higher_tiers) : null;

if ($next_tier) {
    $spend_needed  = max(0, floatval($next_tier['MIN_SPENT']) - $quarter_spent);
    $progress_text = "Spend RM " . number_format($spend_needed, 2) . " more before " . $window_end_text . " to upgrade to " . $next_tier['TIER_NAME'];
} else {
    $progress_text = "You are on the highest tier";
}

// How to keep the current tier at the end of this window
$retain_text = '';
if ($current_tier_min_spent > 0) {
    $retain_needed = max(0, $current_tier_min_spent - $quarter_spent);
    $retain_text = ($retain_needed > 0)
        ? "Spend RM " . number_format($retain_needed, 2) . " more before " . $window_end_text . " to keep " . $current_tier_name
        : "You have met the requirement to keep " . $current_tier_name . " this period";
}

function getTierClass($name){
    $map = [
        'Classic'   => 'bronze-tier',
        'Silver'    => 'silver-tier',
        'Gold'      => 'gold-tier',
        'Platinum'  => 'platinum-tier',
        'Plantinum' => 'platinum-tier'   // spelling used in your database
    ];
    return $map[$name] ?? 'bronze-tier';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=8.0">
    <link rel="stylesheet" href="css/footer.css?v=7.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
      :root {
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
        margin: 30px 0 10px 20px; 
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

      .main-content {
        max-width: 1060px;
        margin: 0 auto;
        padding: 10px 20px 60px;
      }

      .page-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        color: var(--font-color);
        font-size: 32px;
        margin-bottom: 25px;
      }

      /* Tier Card Styles */
      .member-tier-section {
        width: 100%;
        min-height: 200px; 
        border-radius: 24px;
        margin-bottom: 25px;
        padding: 35px 40px;
        box-sizing: border-box;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(27, 42, 60, 0.12);
        transition: transform 0.35s ease, box-shadow 0.35s ease;
      }

      .member-tier-section:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(27, 42, 60, 0.18);
      }

      /* Decorative tech background grid overlay */
      .member-tier-section::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: 
          radial-gradient(circle at 85% 50%, rgba(255, 255, 255, 0.15) 0%, transparent 60%),
          linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px),
          linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
        background-size: 100% 100%, 30px 30px, 30px 30px;
        pointer-events: none;
      }

      .bronze-tier {
        background: linear-gradient(135deg, #2D2420 0%, #5E463B 45%, #9E745A 100%);
        color: #ffffff;
        border: 1px solid rgba(158, 116, 90, 0.4);
      }

      .silver-tier {
        background: linear-gradient(135deg, #243444 0%, #48647E 50%, #8CA9C4 100%);
        color: #ffffff;
        border: 1px solid rgba(140, 169, 196, 0.4);
      }

      .gold-tier {
        background: linear-gradient(135deg, #132233 0%, #1e3d5a 45%, #c29938 100%);
        color: #ffffff;
        border: 1px solid rgba(194, 153, 56, 0.4);
      }

      .platinum-tier {
        background: linear-gradient(135deg, #1a1a2e 0%, #3d3d5c 50%, #b8b8d1 100%);
        color: #ffffff;
        border: 1px solid rgba(184, 184, 209, 0.4);
      }

      .tier-content {
        position: relative;
        z-index: 2;
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .member-tier {
        font-family: 'Inter', sans-serif;
        font-size: 15px;
        font-weight: 600;
        margin: 0;
        letter-spacing: 1px;
        text-transform: uppercase;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: rgba(255, 255, 255, 0.85);
      }

      .tier-name {
        font-family: 'Poppins', sans-serif;
        font-size: 48px;
        font-weight: 800;
        margin: 0;
        line-height: 1;
        letter-spacing: 1px;
        color: #ffffff;
        text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
      }

      .progress-text {
        font-family: 'Inter', sans-serif;
        font-size: 15px;
        font-weight: 500;
        margin: 0;
        color: rgba(255, 255, 255, 0.9);
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .tier-icon-wrap {
        position: relative;
        z-index: 2;
        font-size: 80px;
        color: rgba(255, 255, 255, 0.22);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 20px;
        transition: transform 0.35s ease, color 0.35s ease;
      }

      .member-tier-section:hover .tier-icon-wrap {
        transform: scale(1.08) rotate(5deg);
        color: rgba(255, 255, 255, 0.35);
      }

      /* Rules & Benefits Section */
      .member-benefits-section {
        margin-top: 50px;
        margin-bottom: 50px;
      }

      .title-section {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
      }

      .join-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        color: var(--main-color);
        font-size: 24px;
        margin: 0;
      }

      .voucher-btn {
        background-color: var(--accent-blue);
        text-decoration: none;
        color: #ffffff;
        border: none;
        padding: 12px 24px;
        font-family: 'Inter', sans-serif;
        font-size: 15px;
        font-weight: 600;
        border-radius: 12px;
        cursor: pointer;
        transition: var(--transition);
        box-shadow: 0 4px 14px rgba(60, 140, 177, 0.28);
        display: inline-flex;
        align-items: center;
        gap: 8px;
      }
      
      .voucher-btn:hover {
        background-color: #2b7091;
        color: #ffffff;
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(60, 140, 177, 0.38);
      }

      .rules-content {
        background: #ffffff;
        border: 1px solid var(--search-border-color);
        border-radius: 16px;
        padding: 28px 32px;
        box-shadow: 0 4px 18px rgba(27, 42, 60, 0.04);
      }

      .rules-content ol {
        margin: 0;
        padding-left: 20px;
        color: var(--font2-color);
        font-size: 14.5px;
        line-height: 1.9;
      }

      .rules-content ol li {
        margin-bottom: 8px;
      }

      .rules-content ol li strong {
        color: var(--font-color);
      }

      /* Upgrade Section */
      .upgrade-section {
        margin-top: 60px;
        padding-top: 30px;
        border-top: 1px solid var(--search-border-color);
      }

      .upgrade-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        color: var(--font-color);
        font-size: 22px;
        margin: 0 0 25px 0;
      }

      .tier-locked {
        opacity: 0.72;
        filter: grayscale(40%);
        transition: var(--transition);
      }

      .tier-locked:hover {
        opacity: 0.95;
        filter: grayscale(0%);
      }

      @media (max-width: 768px) {
        .member-tier-section {
          padding: 25px 20px;
          min-height: auto;
        }

        .tier-name {
          font-size: 36px;
        }

        .tier-icon-wrap {
          font-size: 50px;
          margin-right: 0;
        }

        .title-section {
          flex-direction: column;
          align-items: flex-start;
        }

        .voucher-btn {
          width: 100%;
          justify-content: center;
        }
      }
    </style>
</head>

<body>
  <?php include 'include/header.php';?>

  <div class="container-fluid">
    <div class="back-section">
      <a href="index.php" class="back-link">
        <i class="bi bi-chevron-left"></i>Back
      </a>
    </div>
  </div>

  <div class="main-content">
    <div class="member-title">
      <h1 class="page-title">Membership</h1>
    </div>
    
    <div class="member-tier-section <?php echo getTierClass($current_tier_name);?>">
      <div class="tier-content">
        <p class="member-tier"><i class="bi bi-shield-check"></i> Your Member tier <i class="bi bi-chevron-right"></i></p>
        <h2 class="tier-name"><?php echo htmlspecialchars($current_tier_name); ?></h2>
        <p class="progress-text"><?php echo htmlspecialchars($progress_text); ?></p>
        <?php if ($retain_text): ?>
          <p class="progress-text"><i class="bi bi-arrow-repeat"></i> <?php echo htmlspecialchars($retain_text); ?></p>
        <?php endif; ?>
      </div>
      <div class="tier-icon-wrap">
        <i class="bi bi-cpu-fill"></i>
      </div>
    </div>

    <div class="member-benefits-section">
      <div class="title-section">
        <h2 class="join-title">Store Membership Benefits</h2>
        <a href="voucher.php" class="voucher-btn"><i class="bi bi-ticket-perforated"></i> View your vouchers</a>
      </div>
      
      <div class="rules-content">
        <ol>
          <li>Sign up as a member to start enjoying loyalty rewards on hardware and components</li>
          <li>Your spending is tracked in 3-month periods. Reach a higher tier's target and you are upgraded immediately; if you fall short at the end of a period, you move to the highest tier your spending qualifies for.</li>
          <?php foreach ($tiers as $tier): ?>
            <li>
              <strong><?= htmlspecialchars($tier['TIER_NAME']) ?>:</strong>
              <?php if (floatval($tier['MIN_SPENT']) > 0): ?>
                Spend <strong>RM <?= number_format($tier['MIN_SPENT'], 2) ?></strong> every 3 months to unlock and keep this tier.
              <?php else: ?>
                Granted upon registration.
              <?php endif; ?>
              Free shipping.
              <?php if (!empty($tier['MONTHLY_VOUCHER_AMOUNT'])): ?>
                A <strong>RM <?= number_format($tier['MONTHLY_VOUCHER_AMOUNT'], 0) ?> voucher</strong> every month and a free gift.
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
          <li><strong>All tiers:</strong> get a RM5 voucher for every order over RM100, and a RM10 voucher when you spend RM1,000 within 3 months.</li>
          <li>Vouchers are valid for 30 days after they are issued.</li>
        </ol>
      </div>

        <?php if (!empty($higher_tiers)): ?>
        <div class="upgrade-section">
          <h3 class="upgrade-title">Upgrade Your Membership</h3>

          <?php foreach ($higher_tiers as $tier): ?>
          <div class="member-tier-section <?php echo getTierClass($tier['TIER_NAME']); ?> tier-locked">
            <div class="tier-content">
              <p class="member-tier"><i class="bi bi-lock-fill"></i> Higher Tier</p>
              <h2 class="tier-name"><?php echo htmlspecialchars($tier['TIER_NAME']); ?></h2>
              <p class="progress-text">
                Unlock by spending RM <?= number_format($tier['MIN_SPENT'], 2); ?> within 3 months
                <?php if (!empty($tier['MONTHLY_VOUCHER_AMOUNT'])): ?>
                  &middot; RM <?= number_format($tier['MONTHLY_VOUCHER_AMOUNT'], 0) ?> monthly voucher
                <?php endif; ?>
              </p>
            </div>
            <div class="tier-icon-wrap">
              <i class="bi bi-award-fill"></i>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div> 
  </div> 

  <?php include 'include/footer.php';?>
</body>
</html>
