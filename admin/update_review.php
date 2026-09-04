<?php
require_once("config.php");

// Get inputs
$reviewId = $_POST['review_id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$reviewId || !$status) {
    die("Invalid input");
}

// Update status
$sql = "UPDATE review SET REVIEW_STATUS = ? WHERE REVIEW_ID = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("SQL Error: " . $conn->error);
}

$stmt->bind_param("si", $status, $reviewId);
$stmt->execute();

echo "success";
?>