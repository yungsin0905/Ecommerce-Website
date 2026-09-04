<?php
session_start();
require_once 'include/config.php';

// 1. Ensure user is logged in
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}

// Ensure the page was reached via POST submission from the top-up form
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['btn-pay'])) {
    header("Location: topup.php");
    exit();
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);
$amount = floatval($_POST['amount']);
$method = $_POST['payment_method'] ?? 'Unknown';

// 2. Ensure top-up amount meets the minimum requirement
if ($amount < 10.00) {
    echo "<script>alert('Minimum top-up amount is RM 10.00'); history.back();</script>";
    exit();
}

// 3. Initiate Database Transaction
$conn->begin_transaction();

try {
    // Lock the customer's record to prevent other processes from changing the balance at the same time, ensuring our math stays accurate
    $stmt1 = $conn->prepare("SELECT WALLET_BALANCE FROM customer WHERE CUSTOMER_ID = ? FOR UPDATE");
    $stmt1->bind_param("i", $customer_id);
    $stmt1->execute();
    $result = $stmt1->get_result();
    $row = $result->fetch_assoc();
    
    if (!$row) {
        throw new Exception("User not found.");
    }

    $before_balance = floatval($row['WALLET_BALANCE']);
    $after_balance = $before_balance + $amount;

    // 4. Update the customer's wallet balance
    $stmt2 = $conn->prepare("UPDATE customer SET WALLET_BALANCE = ?, UPDATED_AT = NOW() WHERE CUSTOMER_ID = ?");
    $stmt2->bind_param("di", $after_balance, $customer_id);
    $stmt2->execute();

    // 5. Insert transaction record for audit and history tracking
    $stmt3 = $conn->prepare("INSERT INTO wallet_transaction 
        (CUSTOMER_ID, AMOUNT, TYPE, TOPUP_METHODS, BEFORE_BALANCE, AFTER_BALANCE, CREATED_AT) 
        VALUES (?, ?, 'TOPUP', ?, ?, ?, NOW())");
    $stmt3->bind_param("idsdd", $customer_id, $amount, $method, $before_balance, $after_balance);
    $stmt3->execute();

    // Commit transaction: All changes are now permanently saved
    $conn->commit();

    echo "<script>
            alert('Top-up Successfully!');
            window.location.href='topup_history.php';
          </script>";

} catch (Exception $e) {
    // If any error occurs, rollback the transaction to maintain data integrity
    $conn->rollback();
    echo "<script>
            alert('Top-up failed. Please try again later.');
            window.location.href='topup.php';
          </script>";
}
?>