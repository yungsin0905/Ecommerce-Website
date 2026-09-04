<?php
session_start();

include_once 'include/config.php';

//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

$registrationMessage = "";

if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    $_SESSION = array(); // clear all session variables
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy(); 
    header("Location: login.php?status=logged_out"); // back to login page
    exit();
}

//module A : process google social login
if(isset($_POST['google_token'])){
  $id_token = $_POST['google_token'];

  $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $id_token;
  
  $response = file_get_contents($url);

  $payload = json_decode($response,true);

 // 'sub' is Google's unique, stable user ID â€” its presence confirms the token is valid
  if(isset($payload['sub'])){
    $google_id = $payload['sub'];
    $email = mysqli_real_escape_string($conn,$payload['email']);
    $full_name = mysqli_real_escape_string($conn,$payload['name']);

    //check if there are any related records int the social login table 
    $checkSocial = "SELECT CUSTOMER_ID FROM social_login WHERE PROVIDER = 'google' AND PROVIDER_USER_ID = '$google_id'";
    $socialResult = mysqli_query($conn,$checkSocial);

    if(mysqli_num_rows($socialResult)>0){
      // Existing social login link found â€” this Google account has logged in before
      $row = mysqli_fetch_assoc($socialResult);

      //checking account status
      $statusCheck = mysqli_query($conn, "SELECT STATUS FROM customer WHERE CUSTOMER_ID = '".$row['CUSTOMER_ID']."'");
      $statusRow = mysqli_fetch_assoc($statusCheck);

      if($statusRow['STATUS'] == 'Suspended') {
        //Block login if the account has been suspended
        $registrationMessage = "Your account has been suspended. Please contact support.";
      }else {
         // Account is active â€” log the user in by setting session variables
        $_SESSION['CUSTOMER_ID'] = $row['CUSTOMER_ID'];
        
        $res = mysqli_query($conn, "SELECT CUSTOMER_NAME FROM customer WHERE CUSTOMER_ID = '".$row['CUSTOMER_ID']."'");
        $u = mysqli_fetch_assoc($res);
        $_SESSION['CUSTOMER_NAME'] = $u['CUSTOMER_NAME'];
        header("Location: index.php");
        exit();
      }

    }else{
       // No existing social login link â€” check if a customer already exists with this email
      $checkCustomer = "SELECT CUSTOMER_ID FROM customer WHERE EMAIL = '$email'";
      $customerResult = mysqli_query($conn, $checkCustomer);
      
      if(mysqli_num_rows($customerResult)>0){
        // Customer with this email already exists â€” reuse their existing account
        $row = mysqli_fetch_assoc($customerResult);
        $customer_id = $row['CUSTOMER_ID'];
      }else{
         // Brand new user â€” create a new customer record (default tier, Active status, zero balances)
        $insertCustomer = "INSERT INTO customer(TIER_ID, CUSTOMER_NAME, EMAIL, STATUS, TOTAL_SPENT, WALLET_BALANCE, CREATED_AT)
        VALUES (1, '$full_name', '$email', 'Active', 0.00, 0.00, NOW())";
        mysqli_query($conn,$insertCustomer);
        $customer_id = mysqli_insert_id($conn);
      }
      // Link this Google account to the customer record for future logins
      $insertSocial = "INSERT INTO social_login (CUSTOMER_ID, SOCIAL_EMAIL,PROVIDER,PROVIDER_USER_ID)
      VALUES ('$customer_id','$email','google','$google_id')";
      mysqli_query($conn, $insertSocial);

       // Log the user in by setting session variables
      $_SESSION['CUSTOMER_ID'] = $customer_id;
      $_SESSION['CUSTOMER_NAME'] = $full_name;
      header("Location: index.php");
      exit();
    }
  }else{
     // Google token verification failed (invalid, expired, or tampered token)
    $registrationMessage = "Google Authentication Failed.";
  }
}

if (isset($_GET['error'])) {
    $registrationMessage = htmlspecialchars($_GET['error']);
}


//Module B login process
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['google_token'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $password = $_POST['password'];

    //1. search user trhough email
    $sql = "SELECT * FROM customer WHERE EMAIL = '$email'";
    $result = $conn-> query($sql);

    if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();

    //verify enter empty field
      if (empty(trim($email)) || empty(trim($password))) {
          $registrationMessage = "All fields are required!";
      
      //verify email format
      } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $registrationMessage = "Invalid email format!";
      
      //verify user is already suspended
      } else if ($user['STATUS'] === 'Suspended') {
          $registrationMessage = "Your account is suspended. Please contact support.";
      //verify the password is enter space value
      } else if (str_contains($password, ' ')) {
      $registrationMessage = "Password cannot contain spaces!";
      
      //verify password correction
      } else if (!password_verify($password, $user['PASSWORD'])) {
          $registrationMessage = "Invalid password. Please try again.";
      } else {
          $_SESSION['CUSTOMER_ID'] = $user['CUSTOMER_ID'];
          $_SESSION['CUSTOMER_NAME'] = $user['CUSTOMER_NAME'];
          $_SESSION['email'] = $user['EMAIL'];
          session_write_close();
          header("Location: index.php");
          exit();
      }
    } else {
        $registrationMessage = "Email not found. Please sign up first.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=5.0">
    <link rel="stylesheet" href="css/footer.css?v=5.0">
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


      /* page header */
      .page-header{
        margin-bottom:30px;
        background-color: var(--bg-color);
        margin-left:-50px;
       
      }

      .page-logo{
        width:100px;
        height:100px;
        margin-bottom:20px;
        border-radius:50%;
        margin-left: 20px;
      }

      .page-header h1{
        font-size:28px;
        margin:0 0 10px 0;
        font-weight:700;
        color:var(--main-color);
         font-family: 'Poppins', sans-serif; 
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
        color:var(--font2-color);
        text-decoration:none;
        font-weight:bold;
      }

      .login-prompt a:hover{
        text-decoration:underline;
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

      .btn-login{
        width:70%;
        padding: 12px;
        background-color: var(--main-color);
        color: var(--font2-color); 
        border: none;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        margin-top: 10px;
        margin-bottom: 25px;
        transition: background-color 0.5s;
      }

      .btn-login:hover {
      background-color: #f8dbdf; 
      }

      /* forgot password */
      .forgot-password{
        display:flex;
        justify-content:flex-end;
        margin-right:20px;
      }

      .forgot-password a{
        color:var(--font2-color);
        font-size:15px;
      }

      .forgot-password a:hover{
        color:#38b6ff;
      }

      /* social */
      .social-login p {
      font-size: 12px;
      margin: 0 0 15px 0;
      position: relative;
      color:var(--font2-color);
      }

      .social-signup{
        display:flex;
        justify-content:center;
        gap:20px;
      }

      .social-signup img{
        width: 30px;
        height:30px;
        transition:transform 0.2s;
      }

      .social-signup img:hover{
        transform: scale(1.1);
      }
      
      
      .signup-container { border: 1px solid var(--search-border-color); border-radius: 10px; box-shadow: 0 8px 22px rgba(27,42,60,0.08); }
      .form-input { border-color: var(--search-border-color); border-radius: 6px; color: var(--font-color); }
      .form-input::placeholder, .hints { color: #7C96AA; }
      .btn-login { background: var(--main-color); color: #fff; border-radius: 6px; }
      .btn-login:hover { background: var(--font2-color); }
      .back-link:hover, .forgot-password a:hover { color: var(--main-color); text-decoration: none; }
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

        <header class="page-header">
          <img class="page-logo" src="image/cakeology logo.png">
          <h1>Welcome back to Cakeology</h1>
        </header>

        <main class="signup-container">
          <h2>Login</h2>
          <p class="login-prompt">Haven't an account? <a href="sign-up.php">Sign Up</a></p>

          <!-- Message display -->
              <?php if (!empty($registrationMessage)): ?>
                  <div class="error-message">
                      <?php echo htmlspecialchars($registrationMessage); ?>
                  </div>
              <?php endif; ?>

          <form method="post" action="" class="register-form">

              <div class="form-group">
                  <label class="form-label">Email :</label>
                  <input type="email" name="email" class="form-control form-input" placeholder="Enter your Email"
                  oninput="this.value = this.value.replace(/\s/g, '')" 
                  maxlength = "50" required>
              </div>

              <div class="form-group">
                  <label class="form-label">Password :</label>
                  <input type="password" name="password" class="form-control form-input" placeholder="Enter your Password" 
                  oninput="this.value = this.value.replace(/\s/g, '')"
                  maxlength = "15" required>
                </div>

              <p>
                <button type="submit" class="btn-login">Login</button>
              </p>
              
              <div class="forgot-password">
                <a href="forgot password.php">Forgot Password? </a>
              </div>
          </form>
          
          <div class="social-login">
            <p>or</p>
            <div class="social-signup">
                    <div id="google-login-btn"><img src="image/logo/Google.png" alt="Google"/></div>
                    
            </div> 
          </div>
          
        </main>

      </div>
      
    </div>

    
    <?php include 'include/footer.php'?>
     <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>

      //google login function
      function handleCredentialResponse(response){
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';//submit current page
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'google_token';
        input.value = response.credential;
        
        form.appendChild(input);
        document.body.appendChild(form);
        form.submit();
      }

      window.onload = function () {
        //initialize
        google.accounts.id.initialize({
            client_id: "548197528942-k72t8ba1so3tgr8unchbhop5trpan7r2.apps.googleusercontent.com", 
            callback: handleCredentialResponse,
             ux_mode: "popup",
             use_fedcm_for_prompt: true

        });

       const loginBtn = document.getElementById("google-login-btn");
       if (loginBtn) {
            google.accounts.id.renderButton(
                loginBtn,
                { theme: "outline", size: "large", width: "250" }
            );
        }

        google.accounts.id.prompt();

  
    //get the error message box//
    const errorBox = document.querySelector('.error-message');
    
    // hide error message on input//
    document.querySelector('.register-form').addEventListener('input', function() {
        if (errorBox) {
            // hide error-message when user starts typing//
            errorBox.style.display = 'none';
        }
    });
  };

  </script>
  </body>
</html>

