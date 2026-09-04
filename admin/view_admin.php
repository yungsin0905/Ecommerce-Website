<?php
require_once("config.php");
session_start();

// AUthentication check (Super Admin) only SA can access
if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
} 
$current_id = $_SESSION['admin_id']; 

$stmt = $conn->prepare("
    SELECT ADMIN_TYPE 
    FROM admin 
    WHERE ADMIN_ID = ? AND IS_DELETED = 0
");
$stmt->bind_param("i", $current_id);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();

if (!$currentAdmin || $currentAdmin['ADMIN_TYPE'] !== 'Super Admin') {
    die("Access denied");
} 

// Get admin id
$adminId = $_GET['admin_id'] ?? null;
if (!$adminId) {
    die("Invalid ID");
}

// Get admin info
$stmt = $conn->prepare("
    SELECT * 
    FROM admin 
    WHERE ADMIN_ID = ? AND IS_DELETED = 0
");
$stmt->bind_param("i", $adminId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    die("Admin not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Admin Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.role-admin {
    background: #f1f1f1;
    color: #555;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_admin.php" class="form-back-link" title="Go Back To Admin Page">← Back</a>

    <div class="container">

        <div class="header-top">
            <div class="title">Admin Details</div>

            <a href="edit_admin.php?admin_id=<?= $adminId ?>" class="edit-btn">
               <i class="bi bi-pencil-square"></i>Edit
            </a>
        </div>

        <div class="row">
            <span class="label">Name:</span>
            <span><?= htmlspecialchars($admin['ADMIN_NAME']) ?></span>
        </div>

        <div class="row">
            <span class="label">Role:</span>
            <span class="badge role-<?= strtolower(str_replace(' ', '', $admin['ADMIN_TYPE'])) ?>">
                <?= htmlspecialchars($admin['ADMIN_TYPE']) ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Status:</span>
            <span class="badge status-<?= strtolower($admin['ADMIN_STATUS']) ?>">
                <?= htmlspecialchars($admin['ADMIN_STATUS']); ?>
            </span>
        </div>

        <div class="row">
            <span class="label">Email:</span>
            <span><?= htmlspecialchars($admin['ADMIN_EMAIL']) ?></span>
        </div>

        <div class="row">
            <span class="label">Phone:</span>
            <span><?= !empty($admin['ADMIN_PHONE']) ? htmlspecialchars($admin['ADMIN_PHONE']) : 'N/A' ?></span>
        </div>

        <div class="row">
            <span class="label">Created At</span>
            <span><?= date("d M Y H:i", strtotime($admin['CREATED_AT'])) ?></span>
        </div>

        <div class="row">
            <span class="label">Updated At</span>
            <span><?= !empty($admin['UPDATED_AT']) ? date("d M Y H:i", strtotime($admin['UPDATED_AT'])) : 'N/A' ?></span>
        </div>

    </div>
</div>

</body>
</html>