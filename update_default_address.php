<?php 
include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['CUSTOMER_ID'])){
  echo json_encode(['success' => false, 'message' => 'Unauthorized']);
  exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['address_id'])) {
  $address_id = intval($_POST['address_id']);
  $customer_id = $_SESSION['CUSTOMER_ID'];

  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  try{
    mysqli_begin_transaction($conn);

    //set all the users address is non-default (0)
    $sql1 = "UPDATE address SET IS_DEFAULT = 0 WHERE CUSTOMER_ID = $customer_id";
    mysqli_query($conn, $sql1);

    //set the selected address is default (1)
    $sql2 = "UPDATE address SET IS_DEFAULT = 1 WHERE ADDRESS_ID = $address_id AND CUSTOMER_ID = $customer_id";
    mysqli_query($conn, $sql2);

    if (mysqli_affected_rows($conn) === 0) {
      throw new Exception ('Address not found or does not belong to this user.');
    }

    mysqli_commit($conn);
    echo json_encode(['success' => true]);

  }catch (Exception $e) {
    mysqli_rollback($conn);
    error_log('set_default_address error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
  }
}else{
  echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>