<?php
include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

$registrationMessage = '';
$messageType = 'error';
$showForm  = true;
$verifiedSuccess = false;

// Guard 1ï¼š no otp email session, back to sign up
if (empty($_SESSION['otp_email'])) {
  header('Location: sign-up.php');
  exit;
}

//check suspended user
  $emailCheck = mysqli_real_escape_string($conn, $_SESSION['otp_email']);
  $suspendedResult = mysqli_query($conn,"SELECT STATUS FROM customer WHERE EMAIL = '$emailCheck' LIMIT 1 ");
  if ($suspendedResult && $row = mysqli_fetch_assoc($suspendedResult)) {
    if ($row['STATUS'] === 'Suspended'){
      unset($_SESSION['otp_email'], $_SESSION['otp_type'],$_SESSION['otp_page_expires']);
      $messageType = 'error';
      $registrationMessage = 'A new OTP has been sent to <strong>' . htmlspecialchars($email) . '</strong>.';
    }else {
      $registrationMessage = 'Failed to send OTP. Please try again.';
    }
  }


// Guard2: if finished verification, cannot accessed again
if (!empty($_SESSION['otp_verified'])) {
    header('Location: login.php');
    exit;
}

// Gruard 3: stay OTP page after 10 minutes
if (!empty($_SESSION['otp_page_expires']) && time() > $_SESSION['otp_page_expires']) {
    
    //clear all otp session
    unset($_SESSION['otp_email'], $_SESSION['otp_type'], $_SESSION['otp_page_expires']);
    header('Location: sign-up.php?expired=1');
    exit;
}

//first enter this page, record the expiration time (10 minutes)
if (empty($_SESSION['otp_page_expires'])) {
    $_SESSION['otp_page_expires'] = time() + 600; 
}

$email    = $_SESSION['otp_email'];
$otp_type = $_SESSION['otp_type'] ?? 'register';

// Generate & send OTP
function sendOTP(mysqli $conn, string $email, string $type = 'register'): bool {
    $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expired  = date('Y-m-d H:i:s', strtotime('+10 minutes'));

    // Invalidate old unused OTPs
    $stmt = $conn->prepare(
        "UPDATE otp SET IS_USED = 1 WHERE OTP_EMAIL = ? AND TYPE = ? AND IS_USED = 0"
    );
    $stmt->bind_param('ss', $email, $type);
    $stmt->execute();
    $stmt->close();

    // Insert new OTP
    $stmt = $conn->prepare(
        "INSERT INTO otp (OTP_EMAIL, OTP_CODE, TYPE, IS_USED, EXPIRED) VALUES (?, ?, ?, 0, ?)"
    );
    $stmt->bind_param('ssss', $email, $otp_code, $type, $expired);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) return false;

    // Use sendMail() from config.php
    $subject = 'Your Cakeology OTP Code';
    $body    = "Hi,\n\nYour OTP code is: $otp_code\n\nIt expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";

    return sendMail($email, $subject, $body);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Resend OTP
    if (isset($_POST['resend_otp'])) {
      // reset the OTP page session timer to give another 10 minutes
       $_SESSION['otp_page_expires'] = time() + 600;
 // generate and send a new OTP to the user's email
        if (sendOTP($conn, $email, $otp_type)) {
            $messageType = 'success';
            $registrationMessage = 'A new OTP has been sent to <strong>' . htmlspecialchars($email) . '</strong>.';
        } else {
            $registrationMessage = 'Failed to send OTP. Please try again.';
        }

    // Verify OTP
    } else {
        $input_otp = trim($_POST['otp'] ?? '');

        // check the OTP field is not empty
        if (empty($input_otp)) {
            $registrationMessage = 'Please enter the OTP.';
        
        // validate format: must be exactly 6 digits
        } elseif (!preg_match('/^\d{6}$/', $input_otp)) {
            $registrationMessage = 'OTP must be a 6-digit number.';

        } else {
            // Fetch latest valid OTP
            $stmt = $conn->prepare(
                "SELECT OTP_ID, OTP_CODE, EXPIRED
                 FROM otp
                 WHERE OTP_EMAIL = ? AND TYPE = ? AND IS_USED = 0
                 ORDER BY CREATED_AT DESC
                 LIMIT 1"
            );
            $stmt->bind_param('ss', $email, $otp_type);
            $stmt->execute();
            $otp_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

             // no matching unused OTP record found in the database
            if (!$otp_row) {
                $registrationMessage = 'No valid OTP found. Please request a new one.';
              
            // OTP exists but has passed its expiry time
            } elseif (new DateTime() > new DateTime($otp_row['EXPIRED'])) {
                // Expired â€” mark as used
                $stmt = $conn->prepare("UPDATE otp SET IS_USED = 1 WHERE OTP_ID = ?");
                $stmt->bind_param('i', $otp_row['OTP_ID']);
                $stmt->execute();
                $stmt->close();
                $registrationMessage = 'Your OTP has expired. Please request a new one.';
                $showForm = false;

               // submitted code does not match the stored OTP
            } elseif ($input_otp !== $otp_row['OTP_CODE']) {
                $registrationMessage = 'Invalid OTP. Please try again.';
            
            // correct OTP entered
            } else {
                //mark this OTP as used so it cannot be reused
                $stmt = $conn->prepare("UPDATE otp SET IS_USED = 1 WHERE OTP_ID = ?");
                $stmt->bind_param('i', $otp_row['OTP_ID']);
                $stmt->execute();
                $stmt->close();

                $_SESSION['otp_verified'] = true; // prevent user access the page again

                //retrieve session and insert new users
                $pending = $_SESSION['pending_customer'] ?? null;

                if($pending){
                  //if user entered the otp correct then insert the new data into customer table
                  $stmt = $conn->prepare(
                    "INSERT INTO customer (TIER_ID, CUSTOMER_NAME, EMAIL, PHONE, PASSWORD, STATUS, TOTAL_SPENT, WALLET_BALANCE, CREATED_AT)
                    VALUES (1, ?, ?, ?, ?, 'Active', 0.00, 0.00, NOW())"
                    
                  );

                  $stmt->bind_param('ssss',
                  $pending['full_name'],
                  $pending['email'],
                  $pending['phone'],
                  $pending['hashed_password']
                  );
                  $stmt->execute();
                  $customer_id = $conn->insert_id;
                  $stmt->close();

                  // assign tier vouchers
                  assignTierVouchers($conn, $customer_id, 1);

                   // pending registration data no longer needed, clear it from session
                  unset($_SESSION['pending_customer']);
                }

                 // clean up OTP-related session variables now that verification is complete
                unset($_SESSION['otp_email'], $_SESSION['otp_type'], $_SESSION['otp_page_expires']);

                $registrationMessage = 'OTP verifcation code verified successfully! Redirecting to login...';
                $messageType = 'success';
                $verifiedSuccess = true; 
                 // auto-redirect to login page after 3 seconds
                header('refresh:3;url=login.php');
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
    :root {
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

    .back-section {
      display: flex;
      align-items: center;
      margin: 30px 0 20px 20px;
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

    .back-link:hover { text-decoration: underline; }

    .page-wrapper {
      width: 100%;
      max-width: 520px;
      padding: 20px 20px 60px;
      margin: 0 auto;
      text-align: center;
    }

    .otp-card {
      background: white;
      border-radius: 28px;
      border: 2px solid var(--main-color);
      padding: 44px 40px;
      box-shadow: 0 4px 20px rgba(209, 184, 165, 0.15);
    }

    .otp-card h2 {
      font-size: 22px;
      font-weight: 700;
      color: var(--font2-color);
      margin: 0 0 10px;
    }

    .otp-subtitle {
      font-size: 13px;
      color: var(--search-border-color);
      margin: 0 0 4px;
    }

    .otp-email {
      font-size: 13px;
      color: var(--font2-color);
      font-weight: 600;
      margin: 0 0 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    .otp-email i {
      font-size: 13px;
      color: var(--search-border-color);
      cursor: pointer;
    }

    /* 6 separate OTP boxes */
    .otp-inputs {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-bottom: 30px;
    }

    .otp-box {
      width: 52px;
      height: 58px;
      border: 1.5px solid var(--main-color);
      border-radius: 12px;
      font-size: 22px;
      font-weight: 700;
      color: var(--font2-color);
      text-align: center;
      background: transparent;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
      caret-color: var(--font2-color);
    }

    .otp-box:focus {
      border-color: var(--font2-color);
      box-shadow: 0 0 0 3px rgba(240, 194, 200, 0.4);
    }

    /* hidden real input for form submission */
    #otp-hidden { display: none; }

    .btn-continue {
      width: 100%;
      padding: 14px;
      background: var(--main-color);
      color: var(--font2-color);
      border: none;
      border-radius: 14px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      margin-bottom: 20px;
      transition: opacity 0.3s;
      font-family: 'Quicksand', sans-serif;
    }

    .btn-continue:hover { opacity: 0.75; }

    .resend-row {
      font-size: 13px;
      color: var(--search-border-color);
    }

    .resend-row button {
      background: none;
      border: none;
      color: var(--font2-color);
      font-weight: 700;
      font-size: 13px;
      cursor: pointer;
      font-family: 'Quicksand', sans-serif;
      text-decoration: none;
      padding: 0;
      margin-left: 4px;
      transition: opacity 0.2s;
    }

    .resend-row button:hover { 
      opacity: 0.6; 
    }

    .back-links-row {
      margin-top: 22px;
      display: flex;
      justify-content: center;
      gap: 20px;
      font-size: 13px;
    }

    .back-links-row a {
      color: var(--font2-color);
      text-decoration: none;
      font-weight: 600;
    }

    .back-links-row a:hover { 
      text-decoration: underline; 
    }

    .error-message, .success-message {
      padding: 12px 16px;
      border-radius: 10px;
      margin-bottom: 24px;
      font-size: 13px;
      text-align: center;
      line-height: 1.6;
    }

    .error-message {
      background-color: #fff2f2;
      color: #d93025;
      border: 1px solid #ffcfcf;
    }

    .success-message {
      background-color: #f1f8e9;
      color: #388e3c;
      border: 1px solid #c8e6c9;
    }
    .otp-card { border: 1px solid var(--search-border-color); border-radius: 10px; box-shadow: 0 8px 22px rgba(27,42,60,0.08); }
    .otp-card h2 { font-family: 'Poppins', sans-serif; color: var(--font-color); }
    .otp-subtitle { color: var(--font2-color); }
    .otp-box { border-color: var(--search-border-color); border-radius: 6px; color: var(--font-color); caret-color: var(--main-color); }
    .otp-box:focus { border-color: var(--main-color); box-shadow: 0 0 0 3px rgba(46,134,222,0.18); }
    .btn-continue { background: var(--main-color); color: #fff; border-radius: 6px; font-family: 'Poppins', sans-serif; }
    .resend-row button, .back-links-row a, .back-link:hover { color: var(--main-color); }
    button, input, select, textarea { font-family: 'Inter', sans-serif; }
  </style>
  </head>
  <body>
    <?php include 'include/header.php'; ?>

    <div class="back-section">
      <a href="index.php" class="back-link">
        <i class="bi bi-chevron-left"></i>Back
      </a>
    </div>

    <div class="page-wrapper">
      <div class="otp-card">
        <h2>Verify your email</h2>
        <p class="otp-subtitle">The verification code has been sent to your email</p>
        <p class="otp-email">
          <?php echo htmlspecialchars($email); ?>
          <a href="sign-up.php"> <i class="bi bi-pencil"></a></i>
        </p>

        <?php if (!empty($registrationMessage)): ?>
          <div class="<?= $messageType === 'success' ? 'success-message' : 'error-message' ?>">
            <?php echo $registrationMessage; ?>
          </div>
        <?php endif; ?>

        <?php if ($showForm && !$verifiedSuccess): ?>

          <form method="post" action="" class="register-form" id="otp-form">
            <!-- 6 visual boxes, JS will fill hidden input -->
            <div class="otp-inputs">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1">
            </div>
            <!-- real hidden input submitted to PHP -->
            <input type="text" name="otp" id="otp-hidden">

            <button type="submit" class="btn-continue">Continue</button>

            <div class="resend-row">
              Not received yet?
              <button type="submit" name="resend_otp">Resend verification code</button>
            </div>
          </form>
        <?php endif;?>

        <div class="back-links-row">
          <a href="login.php">Back to Login</a>
          <a href="sign-up.php">Sign Up</a>
        </div>
      </div>
    </div>

    
    <?php include 'include/footer.php'?>
      <script>

        //otp box
    const boxes = document.querySelectorAll('.otp-box');
    const hidden = document.getElementById('otp-hidden');
    const errorBox = document.querySelector('.error-message');
    const resendBtn = document.querySelector('.resend-row button[name="resend_otp"]');

    boxes.forEach((box, i) => {
      box.addEventListener('input', () => {
        // only allow digits
        box.value = box.value.replace(/\D/g, '').slice(0, 1);
        if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
        // sync hidden input
        hidden.value = [...boxes].map(b => b.value).join('');
        // hide error on typing
        if (errorBox) errorBox.style.display = 'none';
      });

      box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && i > 0) boxes[i - 1].focus();
      });

      // handle paste
      box.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        [...pasted].slice(0, 6).forEach((ch, j) => { if (boxes[j]) boxes[j].value = ch; });
        hidden.value = [...boxes].map(b => b.value).join('');
        const next = Math.min(pasted.length, 5);
        boxes[next].focus();
      });
    });

    // auto-focus first box
    if (boxes.length > 0){
      boxes[0].focus();
    }
  
    //resent after 30s
    const COOLDOWN = 30; // seconds

    function startCooldown() {
      let remaining = COOLDOWN;
      resendBtn.disabled = true;

      function tick() {
        resendBtn.textContent = `Resend verification code (${remaining}s)`;
        if (remaining <= 0) {
          resendBtn.disabled = false;
          resendBtn.textContent = 'Resend verification code';
          return;
        }
        remaining--;
        setTimeout(tick, 1000);
      }
      tick();
    }

    //start cooldown
    if (resendBtn){
      startCooldown();

      // Restart cooldown when user clicks Resend
      resendBtn.addEventListener('click', () => {
      });
    }

  </script>
  </body>
</html>

