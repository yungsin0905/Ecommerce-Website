<?php
require_once("config.php");
session_start();

header('Content-Type: text/plain');

if (!isset($_POST['reply_id'])) {
    echo "error";
    exit;
}

$reply_id = (int)$_POST['reply_id'];

// Check exists
$stmt = $conn->prepare("
    SELECT REPLY_ID 
    FROM review_reply 
    WHERE REPLY_ID = ? 
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $reply_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "error";
    exit;
}

// If no errors, delete
$stmt2 = $conn->prepare("
    UPDATE review_reply
    SET IS_DELETED = 1
    WHERE REPLY_ID = ?
");

$stmt2->bind_param("i", $reply_id);

if ($stmt2->execute()) {
    echo "Deleted Successfuly";
} else {
    echo "error";
}

exit;
?>