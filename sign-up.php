<?php 
session_start();

require_once 'include/config.php';

if (mysqli_connect_errno()) {
    die("DB Connect failed: " . mysqli_connect_error());
}

//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

$registrationMessage = "";

//module A : process google social login
if(isset($_POST['google_token'])){
  $id_token = $_POST['google_token'];

  $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $id_token;
  
  //using curl (client url library)send a network request
  $ch = curl_init();//declare
  curl_setopt($ch, CURLOPT_URL, $url); //send a request to the google verification server
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //Return the result of the request as a string.
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //Skip the strict verification of HTTPS certificates.
  $response = curl_exec($ch); //execute this request and store the result in the variable $response
  $curl_error = curl_error($ch);//record unexpected situations
  curl_close($ch);//close this connection to release server resources

  $payload = json_decode($response,true);

  if(isset($payload['sub'])){
    $google_id = $payload['sub'];
    $email = mysqli_real_escape_string($conn,$payload['email']);
    $full_name = mysqli_real_escape_string($conn,$payload['name']);

    //check if there are any related records int the social login table 
    $checkSocial = "SELECT CUSTOMER_ID FROM social_login WHERE PROVIDER = 'google' AND PROVIDER_USER_ID = '$google_id'";
    $socialResult = mysqli_query($conn,$checkSocial);

    if(mysqli_num_rows($socialResult)>0){
      
      $row = mysqli_fetch_assoc($socialResult);

      //check user status
      $statusCheck = mysqli_query($conn, "SELECT STATUS FROM customer WHERE CUSTOMER_ID = '".$row['CUSTOMER_ID']."'");
      $statusRow = mysqli_fetch_assoc($statusCheck);

      if($statusRow['STATUS'] == 'Suspended') {
        $registrationMessage = "Your account has been suspended. Please contact support.";
      }else {
      $_SESSION['CUSTOMER_ID'] = $row['CUSTOMER_ID'];
      header("Location: index.php");
      exit();
      }

    }else{
      $checkCustomer = "SELECT CUSTOMER_ID FROM customer WHERE EMAIL = '$email'";
      $customerResult = mysqli_query($conn, $checkCustomer);
      
      if(mysqli_num_rows($customerResult)>0){
        $row = mysqli_fetch_assoc($customerResult);
        $customer_id = $row['CUSTOMER_ID'];
      }else{
        $insertCustomer = "INSERT INTO customer(TIER_ID, CUSTOMER_NAME, EMAIL, PHONE, PASSWORD, STATUS, TOTAL_SPENT, WALLET_BALANCE, CREATED_AT)
        VALUES (1, '$full_name', '$email', '','','Active', 0.00, 0.00, NOW())";
        if (!mysqli_query($conn, $insertCustomer)) {
          die("Insert customer failed: " . mysqli_error($conn));
        }
        $customer_id = mysqli_insert_id($conn);
        assignTierVouchers($conn, $customer_id, 1);
      }
      
      $insertSocial = "INSERT INTO social_login (CUSTOMER_ID,PROVIDER,SOCIAL_EMAIL,PROVIDER_USER_ID)
      VALUES ('$customer_id','google','$email','$google_id')";
      mysqli_query($conn, $insertSocial);

      $_SESSION['CUSTOMER_ID'] = $customer_id;
      header("Location: index.php");
      exit();
    }
  }else{
    $registrationMessage = "Google Authentication Failed.";
  }
}

if (isset($_GET['error'])) {
    $registrationMessage = htmlspecialchars($_GET['error']);
}

//module B normal register
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['google_token'])) {
    
    //fetch data
    $full_name = mysqli_real_escape_string($conn,$_POST['full_name']);
    $email = mysqli_real_escape_string($conn,$_POST['email']);
    $country_code = isset($_POST['country-code']) ? $_POST['country-code'] : '';
    $phone_num = mysqli_real_escape_string($conn, $_POST['phone']);
    $phone = $country_code . $phone_num;
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // ^           : string start
    // (?=.*[a-z]) : at least include one lowcase
    // (?=.*[A-Z]) : at least include one uppercase
    // (?=.*\d)    : at least include one number
    // (?=.*[\W_]) : at least include one special symbol
    // .{8,}       : at least total 8 digits length 
    // $           : string end
    $password_pattern = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/";

    //authentication
    if (empty(trim($full_name)) || empty(trim($email)) || empty(trim($phone_num)) || empty(trim($password)) || empty(trim($confirm_password))) {
    $registrationMessage = "All fields are required!";
    } else if (str_contains($full_name, ' ')) {
    $registrationMessage = "Username cannot contain spaces!";
    }else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $registrationMessage = "Invalid email format!";
    }else if($password !== $confirm_password){
      $registrationMessage = "Passwords do not match!";
    } else if (str_contains($password, ' ')) {
        $registrationMessage = "Password cannot contain spaces!";
    }else if (str_contains($confirm_password, ' ')) {
        $registrationMessage = "Confirm Password cannot contain spaces!";
    } else if (!preg_match($password_pattern, $password)) {
        $registrationMessage = "Password does not meet the requirements";
    
      //phone number authentication
    }else if (!preg_match('/^[0-9]{9,11}$/', $phone_num)){
      $registrationMessage = "Invalid phone number format! Please enter 9-11 digits.";
    }
    

    else{
    //checking whether is email or phone have already existed
    $checkUser = "SELECT * FROM customer WHERE EMAIL = '$email' OR PHONE = '$phone' OR CUSTOMER_NAME = '$full_name'";
    $result = mysqli_query($conn,$checkUser);

    if (mysqli_num_rows($result) > 0){
      $existingUser = mysqli_fetch_assoc($result);
      
      if($existingUser['CUSTOMER_NAME'] === $full_name){
        $registrationMessage = "Username is already taken! Please choose another one.";
      }else if($existingUser['EMAIL'] === $email){
         $registrationMessage = "Email is already registered!";
      }else if ($existingUser['PHONE'] === $phone) {
        $registrationMessage = "Phone number is already linked to another account!";
      }
     
    }else {
      $hashed_password = password_hash($password, PASSWORD_DEFAULT);

      //store the registration data in session temporarily until OTP verification is successful to avoid unverified accounts in database
      $_SESSION['pending_customer'] = [
      'full_name'       => $full_name,
      'email'           => $email,
      'phone'           => $phone,
      'hashed_password' => $hashed_password,
    ];

         //Generate and insert OTP
         $otp_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
         $expired  = date('Y-m-d H:i:s', strtotime('+10 minutes'));
         $otp_type = 'register';

         $stmt = $conn->prepare(
         "INSERT INTO otp (OTP_EMAIL, OTP_CODE, TYPE, IS_USED, EXPIRED) VALUES (?, ?, ?, 0, ?)"
         );
         $stmt->bind_param('ssss', $email, $otp_code, $otp_type, $expired);
         $stmt->execute();
         $stmt->close();

         //send otp email
        $subject = 'Your Cakeology OTP Code';
        $body    = "Hi $full_name,\n\nYour OTP code is: $otp_code\n\nIt expires in 10 minutes.\n\nIf you did not request this, please ignore this email.";
        
        if (sendMail($email, $subject, $body)) {
            $_SESSION['otp_email'] = $email;
            $_SESSION['otp_type']  = 'register';
             unset($_SESSION['otp_verified']);
            header('Location: verify-otp.php');
            exit();
        } else {
            $registrationMessage = "Email could not be sent. Please try again.";
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
    <title>Sign Up Page</title>
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

      .page-logo{
        width:100px;
        height:100px;
        margin-bottom:20px;
        border-radius:50%;
        margin-left: 20px;
      }

      .page-header h1{
        font-size:25px;
        margin:0 0 10px 0;
        font-weight:700;
        color:var(--main-color);
        font-family: 'Poppins', sans-serif;
      }

      .subtitle{
        margin:20px 0 30px 0;
        color:var(--font2-color);
        font-family: 'Poppins', sans-serif;
        font-size: 12px;
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
        font-weight:bold;
        text-decoration:none;
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

      .phone-group{
        display:flex;
        justify-content:space-between;
      }

      .phone-input{
        width:88%;
        padding: 10px 15px;
        border:1px solid var(--search-border-color);
        border-radius:10px;
        background-color: transparent;
        color:var(--font2-color);
        font-size:13px;
        box-sizing:border-box;
      }

      .phone-group select{
        width:80px;
        height:41px;
        padding: 10px 15px;
        border:1px solid var(--search-border-color);
        border-radius:10px;
        background-color: transparent;
        color:var(--font2-color);
        font-size:13px;
        box-sizing:border-box;
      }

      .form-input::placeholder, .phone-input::placeholder {
        color:#7C96AA;;
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
      
      .btn-register{
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
        transition: background-color 0.5s;
      }

      .btn-register:hover {
      background-color: #f8dbdf; 
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
      .subtitle { font-family: 'Poppins', sans-serif; color: var(--font2-color); }
      .btn-register { background: var(--main-color); color: #fff; border-radius: 6px; }
      .btn-register:hover { background: var(--font2-color); }
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

        <header class="page-header">
          <img class="page-logo" src="image/cakeology logo.png">
          <h1>Join Cakeology Today</h1>
          <p class="subtitle">Unlock sweet rewards, exclusive offers, and your own custom creation</p>
        </header>

        <main class="signup-container">
          <h2>Sign Up</h2>
          <p class="login-prompt">Already have an account? <a href="login.php">Login</a></p>

          <!-- Message display -->
                  <!-- if no succes message,  check if have other error message variables -->
              <?php if (!empty($registrationMessage)): ?>
                  <div class="error-message">
                    <?php echo htmlspecialchars($registrationMessage); ?>
                  </div>

              <?php endif; ?>

          <form method="post" action="" class="register-form">
  
              <div class="form-group">
                  <label class="form-label">Username : </label>
                  <input type="text" name="full_name" class="form-control form-input" placeholder="Enter your Full Name" 
                  oninput="this.value = this.value.replace(/\s/g, '')"
                  maxlength = "20" required>
              </div>

              <div class="form-group">
                  <label class="form-label">Email :</label>
                  <input type="email" name="email" class="form-control form-input" placeholder="Enter your Email"
                  oninput="this.value = this.value.replace(/\s/g, '')" 
                  maxlength = "50" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="phone">Phone number: </label>
                <div class="phone-group">
                    <select id="country-code" name="country-code" required>
                        <option value="+60">+60</option>
                    </select>
          
                    <input type="tel" id="phone" name="phone" class="form-control phone-input"
                    maxlength="11" placeholder="123456789" 
                    pattern = "\d{9,11}"
                    title = "please enter 9 to 11 digit numbers"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                    required>
                </div>
              </div>

              <div class="form-group">
                  <label class="form-label">Password :</label>
                  <input type="password" name="password" class="form-control form-input" 
                  placeholder="Enter your Password" 
                  maxlength = "15" 
                  oninput="this.value = this.value.replace(/\s/g, '')"
                  required>
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
                <button type="submit" class="btn-register">Sign Up</button>
              </p>
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

    document.querySelector('[name="password"]').addEventListener('input', function () {
      const val = this.value;

      toggleRule('rule-length', val.length >= 8);
      toggleRule('rule-upper',  /[A-Z]/.test(val));
      toggleRule('rule-lower',  /[a-z]/.test(val));
      toggleRule('rule-number', /[0-9]/.test(val));
      toggleRule('rule-symbol', /[!@#$%^&*(),.?":{}|<>]/.test(val));
    });

    function toggleRule(id, passed) {
      const el = document.getElementById(id);
      if (passed) {
        el.classList.add('passed');
      } else {
        el.classList.remove('passed');
      }
    }
  };

  </script>
  </body>
</html>

