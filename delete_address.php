<?php
include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

//verify user login
if (!isset($_SESSION['CUSTOMER_ID'])){
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_id'])) {
  $address_id = intval($_POST['address_id']);
  
  $check_query = "SELECT IS_DEFAULT FROM address
  WHERE ADDRESS_ID = $address_id
  AND CUSTOMER_ID = $customer_id";

  $check_result = mysqli_query($conn, $check_query);

  //if address no found or does not belong to this customer
  if (mysqli_num_rows($check_result) === 0) {
    header("Location: UserDashboard.php?error=address_not_found");
    exit();
  }

  $check_row = mysqli_fetch_assoc($check_result);

  //prevent deleting default address
  if ($check_row['IS_DEFAULT'] == 1) {
    header("Location: UserDashboard.php?error=cannot_delete_default");
    exit();
  }

  //proceed with deletion
  $delete_query = "DELETE FROM address WHERE ADDRESS_ID = $address_id AND CUSTOMER_ID = $customer_id";

  if(mysqli_query($conn, $delete_query)) {
    header("Location: UserDashboard.php?success=address_deleted");
  }else
  header("Location: UserDashboard.php?error=delete_failed");

}else {
  header("Location: UserDashboard.php");
}

exit();
?>