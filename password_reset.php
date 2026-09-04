<?php include 'include/config.php';
session_start();

if(isset($_SESSION['CUSTOMER_ID'])){
    header("Location: index.php");
    exit();
}

$registrationMessage = "";
$messageType = "";

$token = isset($_GET['token']) ? mysqli_real_escape_string($conn, $_GET['token']) : '';

if (empty($token)) {
    header("Location: login.php");
    exit();
}

$checkTokenSql = "SELECT CUSTOMER_ID FROM pass_reset WHERE TOKEN = '$token' AND EXPIRES_AT > NOW() LIMIT 1";
$tokenResult   = mysqli_query($conn, $checkTokenSql);
$isTokenValid  = ($tokenResult && mysqli_num_rows($tokenResult) > 0);

if (!$isTokenValid) {
    $registrationMessage = "Invalid or expired token. Please request a new password reset link.";
    $messageType = "error";
} else {
    $tokenData   = mysqli_fetch_assoc($tokenResult);
    $customer_id = $tokenData['CUSTOMER_ID'];

    //custsomer status checked
    $statusResult = mysqli_query($conn, "SELECT STATUS FROM customer WHERE CUSTOMER_ID = '$customer_id'");
    $statusData   = mysqli_fetch_assoc($statusResult);

    if ($statusData['STATUS'] === 'Suspended') {
        $isTokenValid        = false;
        $registrationMessage = "Your account has been suspended. Please contact us for assistance.";
        $messageType         = "error";
    }
}

//password format checking
if ($_SERVER["REQUEST_METHOD"] == "POST" && $isTokenValid) {
    $password         = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty(trim($password)) || empty(trim($confirm_password))){
      $registrationMessage = "All fields are required!";
    }else if ($password !== $confirm_password) {
        $registrationMessage = "Passwords do not match.";
    } elseif (strlen($password) < 8) {
        $registrationMessage = "Password must be at least 8 characters.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $registrationMessage = "Password must include at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $password)) {
        $registrationMessage = "Password must include at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $registrationMessage = "Password must include at least one number.";
    } elseif (!preg_match('/[\W_]/', $password)) {
        $registrationMessage = "Password must include at least one symbol.";
    }else if (str_contains($password, ' ')) {
        $registrationMessage = "Password cannot contain spaces!";
    }else if (str_contains($confirm_password, ' ')) {
        $registrationMessage = "Confirm Password cannot contain spaces!";
    } else {
      //checking old password
        $oldPassResult = mysqli_query($conn, "SELECT PASSWORD FROM customer WHERE CUSTOMER_ID = '$customer_id'");
        $oldPassData   = mysqli_fetch_assoc($oldPassResult);

        if (password_verify($password, $oldPassData['PASSWORD'])) {
            $registrationMessage = "New password cannot be the same as your old password.";
        } else {
          //update new password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $updateSql = "UPDATE customer SET PASSWORD = '$hashed_password' WHERE CUSTOMER_ID = '$customer_id'";

            if (mysqli_query($conn, $updateSql)) {
                mysqli_query($conn, "DELETE FROM pass_reset WHERE CUSTOMER_ID = '$customer_id'");
                $registrationMessage = "Password has been reset successfully! Redirecting to login...";
                $messageType         = "success";
                header("refresh:3;url=login.php");
            } else {
                $registrationMessage = "Something went wrong. Please try again later.";
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
    <title>Password Reset Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=5.0">
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

      .password-rules {
        list-style: none;
        padding: 0;
        margin: 0;
        font-size: 12px;
        max-height: 0;
        overflow: hidden;
        opacity: 0;
        transition: all 0.4s ease;
      }

      .password-rules li {
        color: #bbb;
        padding: 2px 0;
      }

      .password-rules li::before {
        content: 'âœ—  ';
        color: #e57373;
      }

      .password-rules li.passed::before {
        content: 'âœ“  ';
        color: #66bb6a;
      }

      .password-rules li.passed {
        color: #66bb6a;
      }

     input[name="password"]:focus + .password-rules,
        input[name="password"]:focus ~ .password-rules {
            display: block;
      }
      .form-group:focus-within .password-rules {
          max-height: 200px;    
          opacity: 1;            
          margin-top: 10px;     
          margin-bottom: 10px;
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

      .register-link a{
        color:var(--font2-color);
        text-decoration:none;
        font-weight:bold;
      }

      .register-link a:hover{
        text-decoration:underline;
      }

      .page-header h1, .signup-container h2 { font-family: 'Poppins', sans-serif; color: var(--font-color); }
      .signup-container { border: 1px solid var(--search-border-color); border-radius: 10px; box-shadow: 0 8px 22px rgba(27,42,60,0.08); }
      .form-input { border-color: var(--search-border-color); border-radius: 6px; color: var(--font-color); }
      .form-input::placeholder, .hints { color: #7C96AA; }
      .btn-reset { background: var(--main-color); color: #fff; border-radius: 6px; }
      .btn-reset:hover { background: #1B5FB0; }
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
          <p class="login-prompt">Enter your email and new password to reset.</p>

          <!-- Message display -->
              <?php if (!empty($registrationMessage)): ?>
                  <div class="<?= $messageType === 'success' ? 'success-message' : 'error-message' ?>">
                      <?php echo htmlspecialchars($registrationMessage); ?>
                  </div>
              <?php endif; ?>
          
          <?php if ($isTokenValid && $messageType !== 'success'): ?>
          <form method="post" action="" class="register-form">
              <div class="form-group">
                  <label class="form-label">New Password :</label>
                  <input type="password" name="password" class="form-control form-input" placeholder="Enter your Password" 
                  oninput="this.value = this.value.replace(/\s/g, '')"
                  maxlength = "15" required>
                  <ul class="password-rules mt-2" id="pwRules">
                    <li id="rule-length">  At least 8 characters</li>
                    <li id="rule-upper">   At least one uppercase (A-Z)</li>
                    <li id="rule-lower">   At least one lowercase (a-z)</li>
                    <li id="rule-number">  At least one number (0-9)</li>
                    <li id="rule-symbol">  At least one symbol (!@#$...)</li>
                  </ul>
                </div>
                
                <div class="form-group">
                  <label class="form-label">Confirm Password :</label>
                  <input type="password" name="confirm_password" class="form-control form-input" placeholder="Enter your Confirm Password" 
                  oninput="this.value = this.value.replace(/\s/g, '')"
                  maxlength = "15" required>
              </div>

              <p>
                <button type="submit" class="btn-reset">Reset Password</button>
              </p>

              <div class="register-link">
                    Remembered your password? <a href="login.php">Back to Login</a>
              </div>
              
          </form>
        <?php endif;?>
        </main>

      </div>
      
    </div>

    
    <?php include 'include/footer.php'?>
    <script>
    //get the error message box//
    const errorBox = document.querySelector('.error-message');
    
    // hide error message on input//
    const registerForm = document.querySelector('.register-form');
    if (registerForm) {
        registerForm.addEventListener('input', function() {
            if (errorBox) errorBox.style.display = 'none';
        });
    }

    //password rules
    const pwInput = document.querySelector('[name="password"]');
    if (pwInput) {
        pwInput.addEventListener('input', function () {
            const val = this.value;
            toggleRule('rule-length', val.length >= 8);
            toggleRule('rule-upper',  /[A-Z]/.test(val));
            toggleRule('rule-lower',  /[a-z]/.test(val));
            toggleRule('rule-number', /[0-9]/.test(val));
            toggleRule('rule-symbol', /[!@#$%^&*(),.?":{}|<>]/.test(val));
        });
    }

    function toggleRule(id, passed) {
      const el = document.getElementById(id);
      if (passed) {
        el.classList.add('passed');
      } else {
        el.classList.remove('passed');
      }
    }
  </script>
  </body>
</html>

