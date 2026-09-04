<?php
require_once("config.php");
session_start();

$admin_id = $_SESSION['admin_id'];

// INSERT REPLY process (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $review_id = $_POST['review_id'] ?? null;
    $reply_text = trim($_POST['reply'] ?? '');

    if (!$review_id || $reply_text == "") {
        echo "empty";
        exit;
    }

    // CHECK IF REVIEW EXISTS
    $check = $conn->prepare("
        SELECT REVIEW_ID 
        FROM review 
        WHERE REVIEW_ID = ?
    ");
    $check->bind_param("i", $review_id);
    $check->execute();

    if ($check->get_result()->num_rows == 0) {
        echo "invalid_review";
        exit;
    }

    // INSERT REPLY
    $stmt = $conn->prepare("
       INSERT INTO review_reply
       (REVIEW_ID, ADMIN_ID, REPLY_TEXT, CREATED_AT, IS_DELETED)
       VALUES (?, ?, ?, CURRENT_TIMESTAMP(), 0)
    ");

    $stmt->bind_param("iis", $review_id, $admin_id, $reply_text);

    if ($stmt->execute()) {
        echo "ok";
    } else {
        echo "fail";
    }

    exit;
}

echo "invalid_request";