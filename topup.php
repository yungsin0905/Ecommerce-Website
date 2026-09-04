<?php
session_start();
require_once 'include/config.php';

// Redirect to login page if user is not authenticated
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];

// Retrieve the current wallet balance for the logged-in user
$query = "SELECT WALLET_BALANCE FROM customer WHERE CUSTOMER_ID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

$current_balance = 0.00;
if ($row = $result->fetch_assoc()) {
    $current_balance = $row['WALLET_BALANCE'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Topup Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/header.css?v=6.0">
    <link rel="stylesheet" href="css/footer.css?v=6.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    /* ===== Design tokens aligned with checkout.php / payment.php ===== */
    :root {
        --main-color: #80b8d2;
        --font-color: #1B2A3C;
        --secondary-color: #F4F8FC;
        --search-border-color: #C9DCEE;
        --bg-color: #FFFFFF;
        --font2-color: #52708A;
        --card-bg-color: #EBF4FC;
        --btn-hover: #3c8cb1;
        --transition: 0.35s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: var(--bg-color);
        margin: 0;
        padding: 0;
        color: var(--font-color);
    }

    .main-container {
        max-width: 600px;
        margin: 40px auto;
        padding: 20px;
        position: relative;
    }

    .history-link {
        position: absolute;
        top: 15px;
        right: 20px;
        text-decoration: none;
    }

    .btn-topup-history {
        background-color: var(--main-color);
        color: #FFFFFF;
        font-size: 13px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        border: none;
        border-radius: 20px;
        padding: 8px 16px;
        cursor: pointer;
        display: inline-block;
        line-height: 1;
        transition: var(--transition);
    }

    .btn-topup-history:hover {
        background-color: var(--btn-hover);
    }

    .balance-card {
        background: linear-gradient(135deg, #80b8d2 0%, #3c8cb1 100%);
        padding: 30px;
        border-radius: 24px;
        text-align: center;
        color: #FFFFFF;
        margin-bottom: 30px;
    }

    .balance-card p {
        margin: 0;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #EBF4FC;
        font-weight: 700;
    }

    .balance-card h2 {
        margin: 10px 0 0 0;
        font-size: 2.5rem;
        color: #FFFFFF;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
    }

    h2 {
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        text-align: center;
        margin-bottom: 30px;
        color: var(--main-color);
        font-size: 22px;
    }

    h3 {
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        color: var(--font2-color);
        font-size: 16px;
        margin-bottom: 15px;
    }

    form {
        display: flex;
        flex-direction: row;
        width: 100%;
        align-items: flex-start;
        gap: 25px;
        flex-wrap: wrap;
        box-sizing: border-box;
    }

    .topup-container {
        background: #ffffff;
        padding: 30px;
        border-radius: 20px;
        border: 1px solid var(--search-border-color);
        width: 100%;
        box-sizing: border-box;
    }

    .amount-box {
        margin-bottom: 25px;
    }

    .top-up-label {
        color: var(--font2-color);
        font-weight: 600;
        font-size: 12px;
        margin-bottom: 6px;
        display: block;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'Inter', sans-serif;
    }

    .method-group {
        margin-bottom: 20px;
        padding-bottom: 10px;
    }

    .method-options {
        display: flex;
        flex-direction: column;
        width: 100%;
    }

    .method-label {
        display: flex;
        width: 100%;
        cursor: pointer;
        align-items: center;
        justify-content: space-between;
        font-weight: 700;
        font-size: 14px;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .method-label img {
        height: 30px;
        margin-left: 10px;
    }

    .method-label input[type="radio"] {
        margin-left: auto;
        accent-color: var(--main-color);
    }

    .row-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0 20px;
        margin-bottom: 15px;
    }

    .row-group label {
        font-size: 12px;
        margin-bottom: 5px;
        margin-top: 10px;
        color: var(--font2-color);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-family: 'Inter', sans-serif;
    }

    .top-up-control {
        width: 100%;
        padding: 10px 14px;
        box-sizing: border-box;
        border: 1px solid var(--search-border-color);
        border-radius: 10px;
        font-family: 'Inter', sans-serif;
        font-size: 13px;
        color: var(--font-color);
        background-color: #fff;
        transition: border-color 0.3s, box-shadow 0.3s;
    }

    .top-up-control:focus {
        outline: none;
        border-color: var(--main-color);
        box-shadow: 0 0 0 3px rgba(46, 134, 222, 0.15);
    }

    .method-options p {
        margin-top: 5px;
        margin-bottom: 0;
    }

    #cardholder_name, #card_number {
        margin-bottom: 10px;
    }

    .details-box {
        display: none;
        padding: 15px;
        background-color: var(--secondary-color);
        border-radius: 14px;
        margin-top: 10px;
        border: 1px solid var(--search-border-color);
    }

    .details-box img {
        object-fit: contain;
        width: 30px;
        height: 30px;
    }

    .field-error {
        color: #c0392b;
        font-size: 12px;
        display: none;
        margin-top: 4px;
        font-family: 'Inter', sans-serif;
    }

    #ewallet {
        display: flex;
        flex-direction: row;
        flex-wrap: nowrap;
        gap: 15px;
        align-items: center;
        justify-content: center;
        text-align: center;
        font-size: 12px;
    }

    .wallet-option {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        padding: 10px;
        border-radius: 14px;
        flex: 1;
        gap: 8px;
        border: 1.5px solid var(--search-border-color);
        transition: var(--transition);
        background-color: #fff;
    }

    .wallet-option:hover {
        border-color: var(--main-color);
        background-color: var(--secondary-color);
    }

    .wallet-option img {
        width: 50px;
        height: 50px;
        object-fit: contain;
        margin-bottom: 8px;
        border-radius: 8px;
    }

    .wallet-option span {
        font-size: 12px;
        font-weight: 700;
        color: var(--font2-color);
        font-family: 'Inter', sans-serif;
    }

    .wallet-option input[type="radio"] {
        margin: 0;
        cursor: pointer;
        transform: scale(1.2);
        accent-color: var(--main-color);
    }

    hr {
        border: 0;
        border-top: 1px solid var(--search-border-color);
        margin: 16px 0;
    }

    .btn-pay {
        background-color: var(--main-color);
        color: #FFFFFF;
        font-size: 14px;
        font-weight: 600;
        font-family: 'Inter', sans-serif;
        border: none;
        border-radius: 20px;
        width: 100%;
        padding: 10px 22px;
        cursor: pointer;
        transition: var(--transition);
    }

    .btn-pay:hover {
        background-color: var(--btn-hover);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(27, 42, 60, 0.12);
    }
</style>
</head>
<body>
    <?php include_once 'include/header.php'; ?>

    <div class="main-container">
        <a href="topup_history.php" class="history-link">
            <button type="button" class="btn-topup-history">Topup History</button>
        </a>    

        <h2>Top-up Balance</h2>

        <div class="balance-card">
            <p>Current Balance</p>
            <h2>RM <?php echo number_format($current_balance, 2); ?></h2>
        </div>

        <form method="post" action="process_topup.php">
            <div class="topup-container">

               <div class="amount-box">
                    <label class="form-label top-up-label">Top-up Amount (RM)</label>
                    <input type="number" name="amount" class="form-control top-up-control" placeholder="Min RM 10.00" min="10" required>
                </div>
                <br>

                <h3>Select payment methods</h3>

                <div class="method-group">
                  <div class="method-options">
                    <label class="method-label">
                       <span>Credit or Debit Card</span><br>
                       <img src="icon/card.jpeg" alt="card-icon"><br>
                       <input type="radio" name="payment_method" value="CREDIT OR DEBIT CARD" onclick="toggleDetails('card-info')" required><br/>
                    </label>
             
                <div id="card-info" class="details-box"> 
                   <label for="cardholder_name" class="form-label top-up-label ">Cardholder's name</label>
                   <input type="text" id="cardholder_name" name="cardholder_name" class="form-control top-up-control" maxlength="50" required/>

                   <label for="card_number" class="form-label top-up-label">Card number</label>
                   <input type="text" id="card_number" name="card_number" class="form-control top-up-control" maxlength="16" required/>
                   <span id="card-number-error" class="field-error">Card number must be exactly 16 digits.</span>

                   <div class="row-group">
                      <div>
                         <label for="date" class="form-label top-up-label">Expiry Date</label>
                         <input type="text" id="expiry_date" name="date" class="form-control top-up-control" placeholder="MM/YY" maxlength="5" required/>
                         <span id="expiry-error" class="field-error"></span>
                      </div>
                      <div>
                         <label for="cvc" class="form-label top-up-label">CVC</label>
                         <input type="text" id="cvc" name="cvc" class="form-control top-up-control" maxlength="3" placeholder="123" required/>
                         <span id="cvc-error" class="field-error">CVC must be exactly 3 digits.</span>
                      </div>
                   </div>
                </div>
               </div>
             </div>

                <hr>

                <div class="method-group">
                    <label class="method-label">
                        <span>FPX Online Banking <img src="icon/fpx.png" alt="fpx"></span>
                        <input type="radio" name="payment_method" value="FPX" onclick="toggleDetails('none')">
                    </label>
                </div>

                <hr>

            <div class="method-group">
             <div class="method-options">
                <label class="method-label">
                   <span>E-wallet</span>
                   <input type="radio"  name="payment_method" value="E-wallet" onclick="toggleDetails('ewallet')" required><br/>
                </label>
             </div>
              <div id="ewallet" class="details-box">
    
              <label class="wallet-option">
                  <img src="icon/tng.png" alt="tng">
                  <span>Touch'n Go</span>
                  <input type="radio" name="ewallet" value="Touch'n Go" required>
              </label>

              <label class="wallet-option">
                  <img src="icon/shopee.png" alt="shopee">
                  <span>Shopee Pay</span>
                  <input type="radio" name="ewallet" value="Shopee Pay">
              </label>

              <label class="wallet-option">
                  <img src="icon/boost.png" alt="boost">
                  <span>Boost</span>
                  <input type="radio" name="ewallet" value="Boost">
              </label>
             </div>
            </div>
            <hr>
                <button type="submit" class="btn-pay" name="btn-pay">Topup Now</button>
            </div>
        </form>
    </div> 

    <?php include_once 'include/footer.php'; ?>

<script>
    // When user types in the card number field
    document.getElementById('card_number').addEventListener('input', function() {
       var val = this.value.replace(/\D/g, ''); // Remove anything that is not a number
       this.value = val;
       var err = document.getElementById('card-number-error');
       // Show error if user has typed something but it is not 16 digits yet
       err.style.display = (val.length > 0 && val.length !== 16) ? 'inline' : 'none';
    });

    // When user types in the CVC field
    document.getElementById('cvc').addEventListener('input', function() {
       var val = this.value.replace(/\D/g, ''); // Remove anything that is not a number
       this.value = val;
       var err = document.getElementById('cvc-error');
       // Show error if user has typed something but it is not 3 digits yet
       err.style.display = (val.length > 0 && val.length !== 3) ? 'inline' : 'none';
    });

    // When user types in the expiry date field
    document.getElementById('expiry_date').addEventListener('input', function() {
       var val = this.value;

       // Automatically add a slash after the user types 2 digits (the month)
       if (val.length === 2 && !val.includes('/')) {
          this.value = val + '/';
          return;
       }

       var err = document.getElementById('expiry-error');

       // Do not show any error until the user finishes typing MM/YY
       if (val.length < 5) {
          err.style.display = 'none';
          return;
       }

       // Check that the format is MM/YY (two digits, slash, two digits)
       if (!/^\d{2}\/\d{2}$/.test(val)) {
           err.textContent   = 'Please enter expiry date in MM/YY format.';
           err.style.display = 'inline';
           return;
       }

       // Split MM and YY into separate values
       var parts    = val.split('/');
       var expMonth = parseInt(parts[0]);
       var expYear  = parseInt('20' + parts[1]); // e.g. '26' becomes 2026

       // Month must be between 1 and 12
       if (expMonth < 1 || expMonth > 12) {
          err.textContent   = 'Invalid expiry month.';
          err.style.display = 'inline';
          return;
       }

       // Get the current month and year to compare
       var now = new Date();
       var thisYear  = now.getFullYear();
       var thisMonth = now.getMonth() + 1; // JavaScript months start from 0, so add 1

       // Show error if the card expiry date is in the past
       if (expYear < thisYear || (expYear === thisYear && expMonth < thisMonth)) {
          err.textContent   = 'Your card has expired. Please use a valid card.';
          err.style.display = 'inline';
       } else {
          err.style.display = 'none'; // Expiry date is valid, hide the error
       }
    });

    //Toggles the visibility of payment detail boxes (Card/E-wallet)
    function toggleDetails(showId) {
        const cardInfo = document.getElementById('card-info');
        const ewallet = document.getElementById('ewallet');
        
        cardInfo.style.display = (showId === 'card-info') ? 'block' : 'none';
        ewallet.style.display = (showId === 'ewallet') ? 'block' : 'none';

        // Update validation requirements dynamically
        cardInfo.querySelectorAll('input').forEach(i => i.required = (showId === 'card-info'));
        ewallet.querySelectorAll('input').forEach(i => i.required = (showId === 'ewallet'));
    }
</script>
</body>
</html>