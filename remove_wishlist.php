<?php
include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['CUSTOMER_ID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Please login first']);
    exit();
}

$customer_id = $_SESSION['CUSTOMER_ID'];

if (isset($_POST['clear_all'])) {
    // clear all
    $delete_query = "DELETE FROM wishlist WHERE CUSTOMER_ID = $customer_id";
    if (mysqli_query($conn, $delete_query)) {
        echo json_encode(['status' => 'success', 'message' => 'Wishlist cleared!']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit();
}

if (isset($_POST['product_ids'])) {
    // delete selected
    $product_ids = json_decode($_POST['product_ids'], true);
    if (empty($product_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'No items selected']);
        exit();
    }

    // Sanitize each product ID by forcing it to an integer (prevents SQL injection),
    // then join them into a comma-separated string for use in an SQL IN (...) clause
    $ids = implode(',', array_map('intval', $product_ids));

    //delete saved items from wishlist
    $delete_query = "DELETE FROM wishlist WHERE CUSTOMER_ID = $customer_id AND PRODUCT_ID IN ($ids)";
    if (mysqli_query($conn, $delete_query)) {
        echo json_encode(['status' => 'success', 'message' => 'Removed from wishlist']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit();
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
?>