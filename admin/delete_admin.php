<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    echo "unauthorized";
    exit;
} 

$currentId = $_SESSION['admin_id'];

// Authentication check (Super Admin) - only SA can access
$stmt = $conn->prepare("
    SELECT ADMIN_TYPE
    FROM admin
    WHERE ADMIN_ID = ?
    AND IS_DELETED = 0
"); 

$stmt->bind_param("i", $currentId);
$stmt->execute();

$currentAdmin = $stmt->get_result()->fetch_assoc(); 

if (
    !$currentAdmin ||
    $currentAdmin['ADMIN_TYPE'] !== 'Super Admin'
) {
    echo "forbidden";
    exit;
} 

// Check parameter
if (!isset($_GET['admin_id'])) {
    echo "error";
    exit;
}

$adminId = (int) $_GET['admin_id'];

// Check exists
$stmt = $conn->prepare("
    SELECT ADMIN_ID
    FROM admin
    WHERE ADMIN_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $adminId);
$stmt->execute();

if ($stmt->get_result()->num_rows == 0) {
    echo "error";
    exit;
}

// If no errors, delete
$stmt2 = $conn->prepare("
    UPDATE admin
    SET IS_DELETED = 1,
        UPDATED_AT = CURRENT_TIMESTAMP()
    WHERE ADMIN_ID = ?
");

$stmt2->bind_param("i", $adminId);

if ($stmt2->execute()) {
    echo "ok";
} else {
    echo "error";
}

exit;
?>