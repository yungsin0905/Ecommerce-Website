<?php
session_start();
include 'include/config.php';

if (isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: index.php");
    exit();
}

$registrationMessage = "";
$messageType = "error";
$email = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    if ($email === '') {
        $registrationMessage = "All fields are required!";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registrationMessage = "Invalid email format!";
    } else {
        // prepared statement，避免 SQL injection
        $stmt = $conn->prepare("SELECT CUSTOMER_ID, CUSTOMER_NAME, STATUS FROM customer WHERE EMAIL = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
        // 不暴露邮箱是否存在
            $registrationMessage = "If this email is registered, a reset link has been sent.";
            $messageType = "success";
        }elseif ($user['STATUS'] === 'Suspended') {
            $registrationMessage = "Your account has been suspended. Please contact us for assistance.";
        } else {
            $customer_id = (int)$user['CUSTOMER_ID'];
            $full_name   = $user['CUSTOMER_NAME'];

            $chk = $conn->prepare("SELECT CREATED_AT FROM pass_reset WHERE CUSTOMER_ID = ? ORDER BY CREATED_AT DESC LIMIT 1");
            $chk->bind_param("i", $customer_id);
            $chk->execute();
            $last = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($last && (time() - strtotime($last['CREATED_AT'])) < 30) {
                $registrationMessage = "Please wait 30 seconds before requesting another link.";
            } else {

            $token      = bin2hex(random_bytes(32));
            $expires_at = date("Y-m-d H:i:s", strtotime('+10 minutes'));
            $created_at = date("Y-m-d H:i:s");

            $del = $conn->prepare("DELETE FROM pass_reset WHERE CUSTOMER_ID = ?");
            $del->bind_param("i", $customer_id);
            $del->execute();
            $del->close();

            $ins = $conn->prepare("INSERT INTO pass_reset (CUSTOMER_ID, RESET_EMAIL, TOKEN, EXPIRES_AT, CREATED_AT) VALUES (?,?,?,?,?)");
            $ins->bind_param("issss", $customer_id, $email, $token, $expires_at, $created_at);

            if ($ins->execute()) {
                // 自动适配本地 / 线上域名，不要写死 localhost
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $base   = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $resetLink = $base . '/password_reset.php?token=' . $token;

                $safeName = htmlspecialchars($full_name);
                $body = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:auto;'>
                    <h2 style='color:#80b8d2;'>Maker Kluang</h2>
                    <p>Dear {$safeName},</p>
                    <p>You requested to reset your password. Please click the button below:</p>
                    <p style='text-align:center;margin:30px 0;'>
                        <a href='{$resetLink}' style='background-color:#80b8d2;color:#fff;padding:12px 30px;border-radius:6px;text-decoration:none;font-weight:bold;'>Reset My Password</a>
                    </p>
                    <p>Or copy and paste this link into your browser:</p>
                    <p style='word-break:break-all;color:#888;font-size:13px;'>{$resetLink}</p>
                    <p style='color:#d93025;font-size:13px;'>This link will expire in <strong>10 minutes</strong>. Do not share it with anyone.</p>
                    <hr style='border:none;border-top:1px solid #ddd;'>
                    <p>If you did not request a password reset, please ignore this email.</p>
                    <p>Best Regards,<br><strong>Maker Kluang Team</strong></p>
                </div>";

                $alt = "Hi {$full_name}, please use this link to reset your password: {$resetLink}";

                if (sendMail($email, 'Password Reset Request - Maker Kluang', $body, $full_name, $alt)) {
                    $registrationMessage = "Reset link has been sent to your email. Please check it.";
                    $messageType = "success";
                } else {
                    $registrationMessage = "Email could not be sent. Please try again later.";
                }
            } else {
                $registrationMessage = "Something went wrong. Please try again.";
            }
            $ins->close();
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
    <title>Forgot Password Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=8.0">
    <link rel="stylesheet" href="css/footer.css?v=7.0">
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

      .page-wrapper{
        width:100%;
        max-width:700px;
        padding:40px 20px;
        position:relative;
        text-align:center;
        margin:0 auto;
      }

        /* back section */
      .back-section {
        display: flex;
        align-items: center;
        margin: 30px 0 30px 20px;

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
        text-decoration: underline;
      }

      .page-header{
        margin-bottom:30px;
        background-color: var(--bg-color);
        margin-left:-50px;
       
      }

      .page-header h1{
        font-size:28px;
        margin:0 0 10px 0;
        font-weight:700;
        color:var(--font2-color);
        font-family: 'Pacifico', cursive;
      }

      /* form */
      .signup-container{
        background-color:white;
        border-radius: 30px;
        border:2px solid var(--main-color);
        padding:40px 25px;
        box-shadow: 0 4px 15px rgba(209, 184, 165, 0.2);
        width:600px;
      }

      .signup-container h2{
        color:var(--font2-color);
        font-weight:bold;
      }

      .login-prompt{
        color:var(--font2-color);
        margin: 20px 0 25px 0;
      }

      .login-prompt a, .success-message a{
        margin-left:10px;
        color:#38b6ff;
      }

      /* --- Message hint display--- */
      .error-message, .success-message {
          padding: 12px 15px;
          border-radius: 6px;
          margin-bottom: 25px;
          font-size: 14px;
          display: flex;
          align-items: center;
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

      .form-group{
        text-align:left;
        margin-bottom:15px;
        color:var(--font2-color);
        font-weight:bold;
      }

      .form-label{
        display:block;
        font-size:15px;
        font-weight:700;
        margin-bottom:6px;
        margin-left:5px;
      }

      .form-input{
        width:100%;
        padding: 10px 15px;
        border:1px solid var(--search-border-color);
        border-radius:10px;
        background-color: transparent;
        color:var(--font2-color);
        font-size:13px;
        box-sizing:border-box;
      }

      .form-input::placeholder {
        color:#bba299;
      }

      .hints{
        padding:0 20px;
        margin: 8px 0 0 5px;
        color:#bba299;
        font-size:11px;
        font-weight:100;
        display:none;
       
    }

     input[name="password"]:focus + .hints,
        input[name="password"]:focus ~ .hints {
            display: block;
      }

      .btn-reset{
        width:70%;
        padding: 12px;
        background-color: var(--main-color);
        color: var(--font2-color); 
        border: none;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 25px;
        margin-bottom: 25px;
        transition: background-color 0.5s;
      }

      .register-link{
        color:var(--font2-color);
        font:12px;
      }

      .btn-resend {
        padding: 8px 20px;
        background-color: transparent;
        color: #388e3c;
        border: 1px solid #388e3c;
        border-radius: 15px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s;
      }

      .btn-resend:disabled {
          color: #aaa;
          border-color: #ccc;
          cursor: not-allowed;
      }

      .btn-resend:not(:disabled):hover {
          background-color: #388e3c;
          color: white;
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



      /* password reset */

      .error-message, .success-message {
        padding: 15px;
        border-radius: 10px;
        margin-bottom: 25px;
        font-size: 14px;
        display: flex;
        flex-direction: column; 
        align-items: center; 
        line-height: 1.6;    
        text-align: center;
    }

      .signup-container { border: 1px solid var(--search-border-color); border-radius: 10px; box-shadow: 0 8px 22px rgba(27,42,60,0.08); }
      .form-input { border-color: var(--search-border-color); border-radius: 6px; color: var(--font-color); }
      .form-input::placeholder { color: #7C96AA; }
      .btn-reset { background: var(--main-color); color: #fff; border-radius: 6px; }
      .btn-reset:hover { background: #6aa6c2; }
      .back-link:hover { color: var(--main-color); text-decoration: none; }
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

      <div class="main-content">

        <main class="signup-container">
          <h2>Reset Password</h2>
          <p class="login-prompt">Enter your email to receive reset password email.</p>

          <!-- Message display -->
              <?php if (!empty($registrationMessage)): ?>
                <div class="<?php echo $messageType; ?>-message">
                    <?php echo $registrationMessage; ?>

                    <?php if ($messageType === "success"): ?>
                        <!-- Resend form-->
                        <form method="post" action="" style="margin-top:15px;">
                            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                            <button type="submit" class="btn-resend" id="resendBtn" disabled>
                                Resend Link (<span id="countdown">30</span>s)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
              <?php endif; ?>

            <form method="post" action="" class="register-form">
          
                <div class="form-group">
                  <label class="form-label">Email :</label>
                  <input type="email" name="email" class="form-control form-input" placeholder="Enter your Email"
                    value="<?php echo htmlspecialchars($email); ?>"
                    oninput="this.value = this.value.replace(/\s/g, '')"
                    maxlength="50" required>
                </div>
              <p>
                <button type="submit" class="btn-reset">Sent Link</button>
              </p>

              <div class="back-links-row">
                <a href="login.php">Back to Login</a>
                <a href="sign-up.php">Sign Up</a>
              </div>
            </form>
          
        </main>

      </div>
      
    </div>

    
    <?php include 'include/footer.php'?>
    <script>
    //get the error message box//
    const errorBox = document.querySelector('.error-message');
    
    // hide error message on input//
    document.querySelector('.register-form').addEventListener('input', function() {
        if (errorBox) {
            // hide error-message when user starts typing//
            errorBox.style.display = 'none';
        }
    });

    //count down 30s
    const resendBtn = document.getElementById('resendBtn');
    const countdownEl = document.getElementById('countdown');

    if (resendBtn) {
        let seconds = 30;
        const timer = setInterval(() => {
            seconds--;
            countdownEl.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(timer);
                resendBtn.disabled = false;
                resendBtn.textContent = 'Resend Link';
            }
        }, 1000);
    }
  </script>
  </body>
</html>              
              
              
              

