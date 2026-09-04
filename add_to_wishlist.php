<?php
include 'include/config.php';
session_start();

header('Content-Type: application/json');

//check the login status
if(!isset($_SESSION['CUSTOMER_ID'])) {
  echo json_encode(['status' => 'error', 'message' => 'Please login first']);
  exit();
}

if (isset($_POST['product_id'])) {
  $customer_id = $_SESSION['CUSTOMER_ID'];
  $product_id = intval($_POST['product_id']);

  //checking whether is existing
  $check_query = "SELECT * FROM wishlist WHERE CUSTOMER_ID = $customer_id AND PRODUCT_ID = $product_id";
  $check_result = mysqli_query($conn, $check_query);

  if (mysqli_num_rows($check_result) > 0) {
    //if user want to remove the product from wishlist
    //when product has already existed in wishlist
    $delete_query = "DELETE FROM wishlist WHERE CUSTOMER_ID = $customer_id AND PRODUCT_ID = $product_id";
    if (mysqli_query($conn, $delete_query)) {
      echo json_encode(['status' => 'success', 'message' => 'Removed from Wishlist']);
    }

    //if user want to add the product into the wishlist
    //when product is not exist in wishlist
  }else {
    $insert_query = "INSERT INTO wishlist (CUSTOMER_ID, PRODUCT_ID,ADDED_DATE) VALUES ($customer_id, $product_id,NOW())";
    if (mysqli_query($conn,$insert_query)) {
      echo json_encode(['status' => 'success', 'message' => 'Added to Wishlist!']);
    }else {
      echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
  }
}
?>