<?php include 'include/config.php';
session_start();

if(isset($_SESSION['CUSTOMER_ID'])){
    header("Location: index.php");
    exit();
}

//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

$bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
$bakery = mysqli_fetch_assoc($bakeryResult);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once 'include/vendor/Exception.php';
require_once 'include/vendor/PHPMailer.php';
require_once 'include/vendor/SMTP.php';


$registrationMessage = "";
$messageType = "error";

if($_SERVER["REQUEST_METHOD"] == "POST")
{
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    if (empty(trim($email))){
      $registrationMessage = "All fields are required!";
    //verify the email format
    }else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registrationMessage = "Invalid email format!";
        $messageType = "error";
    }else {
    
    //verify whether user is existing
    $checkUser = "SELECT CUSTOMER_ID, CUSTOMER_NAME , STATUS FROM customer WHERE EMAIL = '$email' LIMIT 1";
    $result = mysqli_query($conn,$checkUser);

    if(mysqli_num_rows($result)>0){

        $user = mysqli_fetch_assoc($result);
        
        // Check if account is suspended
          if($user['STATUS'] === 'Suspended') {
            $registrationMessage = "Your account has been suspended. Please contact us for assistance.";
            $messageType = "error";
        } else {
            $customer_id = $user['CUSTOMER_ID'];
            $full_name = $user['CUSTOMER_NAME'];

        //generate unique token
        $token = bin2hex(random_bytes(32));

        //setup date (add 10m in current time)
        $expires_at = date("Y-m-d H:i:s", strtotime('+10 minutes') );
        $created_at = date("Y-m-d H:i:s");

        mysqli_query($conn, "DELETE FROM pass_reset WHERE CUSTOMER_ID = '$customer_id'");

        $insertSql = "INSERT INTO pass_reset (CUSTOMER_ID, RESET_EMAIL, TOKEN, EXPIRES_AT, CREATED_AT)
        VALUES ('$customer_id','$email','$token','$expires_at','$created_at')";

        if(mysqli_query($conn, $insertSql)){
            $resetLink = "http://localhost/TWPLAB/password_reset.php?token=" . $token;

            //email sent
            $mail = new PHPMailer(true);

            try {
                // 1. server configuration
                $mail->isSMTP();                                            
                $mail->Host       = 'smtp.gmail.com';                     
                $mail->SMTPAuth   = true;                                   
                $mail->Username   = 'wongyungsin04@gmail.com'; // sender's email
                $mail->Password   = 'jfim afvt zusc vqwg'; // google application password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         
                $mail->Port       = 587;                                    

                // 2. setting sent and receive
                $mail->setFrom('wongyungsin04@gmail.com', 'Cakeology (No-Reply)');
                $mail->addAddress($email, $full_name); 

                // 3. email content
                $mail->isHTML(true);                                  
                $mail->Subject = 'Password Reset Request - Cakeology';
                $mail->Body    = "<div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:auto;'>
                                    
                                    <h2 style='color:#e91e63;'>Cakeology</h2>

                                    <p>Dear " . htmlspecialchars($full_name) . ",</p>

                                    <p>You requested to reset your password. Please click the button below to proceed:</p>

                                    <p style='text-align:center;margin:30px 0;'>
                                        <a href='$resetLink' 
                                          style='background-color:#f0c2c8;color:#936752;padding:12px 30px;
                                                  border-radius:20px;text-decoration:none;font-weight:bold;font-size:15px;'>
                                            Reset My Password
                                        </a>
                                    </p>

                                    <p>Or copy and paste this link into your browser:</p>
                                    <p style='word-break:break-all;color:#888;font-size:13px;'>$resetLink</p>

                                    <p style='color:#d93025;font-size:13px;'>âš ï¸ This link will expire in <strong>10 minutes</strong>. Do not share this link with anyone.</p>

                                    <hr style='border:none;border-top:1px solid #ddd;margin:20px 0;'>

                                    <p>If you did not request a password reset, please ignore this email.</p>

                                    <p>
                                        Best Regards,<br>
                                        <strong>Cakeology Team</strong>
                                    </p>

                                    <hr style='border:none;border-top:1px solid #ddd;margin:20px 0;'>

                                  <p style='font-size:12px;color:#999;'>
                                      {$bakery['ADDRESS']}, {$bakery['POSTCODE']} {$bakery['CITY']}, {$bakery['STATE']}<br>
                                      Phone: {$bakery['PHONE']}<br>
                                      Email: {$bakery['EMAIL']}
                                  </p>

                                </div>";
                $mail->AltBody = "Hi $full_name, Please use this link to reset your password: $resetLink";

                $mail->send();
                $registrationMessage = "Reset link has been sent to your email. Please check it.";
                $messageType = "success";
            } catch (Exception $e) {
                // if email cannot be sent, display the error message
                $registrationMessage = "Email could not be sent. Error: {$mail->ErrorInfo}";
                $messageType = "error";
            }
        

        }

      }

    }else {
        $registrationMessage = "No user found with this email.";
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
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
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

      .btn-reset:hover {
      background-color: #f8dbdf; 
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
      .btn-reset:hover { background: ; }
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
                  oninput="this.value = this.value.replace(/\s/g, '')" 
                  maxlength = "50" required>
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
              
              
              

