<?php 
require_once("config.php");
session_start();

date_default_timezone_set('Asia/Kuala_Lumpur');

// Email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'include/vendor/Exception.php';
require 'include/vendor/PHPMailer.php';
require 'include/vendor/SMTP.php';

// Error & Success message
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success']);
unset($_SESSION['error']);

if(isset($_SESSION['ADMIN_ID'])){
    header("Location: admin_dashboard.php");
    exit();
}

$registrationMessage = "";
$messageType = "error";

// BAKERY INFO 
$bakery = $conn->query("SELECT * FROM bakery_info LIMIT 1")->fetch_assoc();

// Handle submit
if($_SERVER["REQUEST_METHOD"] == "POST")
{
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    $checkUser = "SELECT ADMIN_ID FROM admin WHERE ADMIN_EMAIL = '$email' LIMIT 1";
    $result = mysqli_query($conn,$checkUser);

    if(mysqli_num_rows($result)>0){
        $user = mysqli_fetch_assoc($result);
        $admin_id = $user['ADMIN_ID'];

        //generate unique token
        $token = bin2hex(random_bytes(32));

        //setup date (add 10m in current time)
        $expires_at = date("Y-m-d H:i:s", strtotime('+10 minutes') );
        $created_at = date("Y-m-d H:i:s");

        mysqli_query($conn, "DELETE FROM admin_pswd_reset WHERE ADMIN_ID = '$admin_id'");

        $insertSql = "INSERT INTO admin_pswd_reset (ADMIN_ID, RESET_EMAIL, TOKEN, EXPIRES_AT, CREATED_AT)
        VALUES ('$admin_id','$email','$token','$expires_at','$created_at')";

        if(mysqli_query($conn, $insertSql)){
            $resetLink = "http://localhost/TWPLAB/AdminModuleFYP/admin_reset_password.php?token=" . $token;// The URL maybe need to change

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
                $mail->setFrom('wongyungsin04@gmail.com', $bakery['SHOP_NAME']);
                $mail->addAddress($email); 

                // 3. email content
                $mail->isHTML(true);                                  
                $mail->Subject = 'Password Reset Request - ' . $bakery['SHOP_NAME'];   
                $mail->Body    = "Hi Admin,<br><br>
                                  You requested to reset your password. Please click the link below:<br>
                                  <a href='$resetLink'>$resetLink</a><br><br>
                                  This link will expire in 10 minutes.";
                $mail->AltBody = "Hi Admin, Please use this link to reset your password: $resetLink";

                $mail->send();
                $_SESSION['success'] = "Reset link has been sent to your email.";
                header("Location: admin_forgot_password.php");
                exit();
            } catch (Exception $e) {
                // if email cannot be sent, display the error message
                $_SESSION['error'] = "Email could not be sent.";
                header("Location: admin_forgot_password.php");
                exit();
            }

        }

    } else {
        $_SESSION['error'] = "Invalid email.";
        header("Location: admin_forgot_password.php");
        exit();
    }
}   
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="admin_global_login.css">
<style>
</style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-back">
            <a href="admin_login.php"><i class="bi bi-chevron-left"></i>Back</a>
        </div>

        <div class="auth-card">
            <h2 class="auth-title">Reset Password</h2>
            <p class="auth-subtitle">Enter your email to receive reset password email.</p>

            <?php if ($success): ?>
                <div class="auth-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">          
                <div class="auth-group">
                  <label class="auth-label">Email</label>
                  <input type="email" name="email" class="auth-input">
                </div>

                <button type="submit" class="auth-btn">Get Link</button>
            </form>
            
            <div class="auth-link">
                Remembered your password?  <a href="admin_login.php">Back to Login</a>
            </div> 
        </div>
    </div>

<script>
const msgBox = document.querySelector('.auth-error, .auth-success');

document.querySelector('.auth-form').addEventListener('input', function() {
    if (msgBox) {
        msgBox.style.display = 'none';
    }
});
</script>

</body>
</html> 