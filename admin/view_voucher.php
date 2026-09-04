<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get voucher id
$voucherId = $_GET['voucher_id'] ?? null;

if (!$voucherId) {
    die("Invalid Voucher ID");
}

// GET VOUCHER + TIER info
$stmt = $conn->prepare("
    SELECT 
        v.*, 
        t.TIER_NAME,

        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END AS FINAL_STATUS

    FROM voucher v
    LEFT JOIN membership_tier t 
        ON v.TIER_ID = t.TIER_ID

    WHERE v.VOUCHER_ID = ?
    AND v.IS_DELETED = 0
");
$stmt->bind_param("i", $voucherId);
$stmt->execute();

$voucher = $stmt->get_result()->fetch_assoc();

if (!$voucher) {
    die("Voucher not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Voucher Details</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
<style>
.status-expired {
    background: #f0f0f0;
    color: #666666;
    border: 1px solid #d4d4d4;
}

.status-fullyredeemed {
    background: #fff4e5;
    color: #b36b00;
    border: 1px solid #ffd08a;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_voucher.php" class="form-back-link" title="Go Back To Voucher Page">← Back</a>

    <div class="container">

        <div class="header-top">
            <div class="title">Voucher Details</div>

            <a href="edit_voucher.php?voucher_id=<?= $voucherId ?>" class="edit-btn">
               <i class="bi bi-pencil-square"></i>Edit
            </a>
        </div>

        <div class="section">
            <h3>Basic Info</h3>

            <div class="row">
                <span class="label">Code:</span> 
                <span>#<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?></span>
            </div>

            <div class="row">
                <span class="label">Name:</span> 
                <span><?= htmlspecialchars($voucher['VOUCHER_NAME']) ?></span>
            </div>

            <div class="row">
                <span class="label">Type:</span> 
                <span class="badge type-<?= strtolower($voucher['VOUCHER_TYPE']) ?>">
                    <?= htmlspecialchars($voucher['VOUCHER_TYPE']) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Status:</span>
                <span class="badge status-<?= strtolower(str_replace(' ', '', $voucher['FINAL_STATUS'])) ?>">
                    <?= htmlspecialchars($voucher['FINAL_STATUS']) ?>
                </span>
            </div>
        </div>

        <div class="section">
            <h3>Validity</h3>

            <div class="row">
                <span class="label">Start Date:</span> 
                <span><?= date("d M Y H:i", strtotime($voucher['START_DATE'])) ?></span>
            </div>

            <div class="row">
                <span class="label">Expiry Date:</span> 
                <span><?= !empty($voucher['EXPIRY_DATE']) ? date("d M Y H:i", strtotime($voucher['EXPIRY_DATE'])) : "Claim-based voucher: valid for 3 months from the time it is assigned to the customer." ?></span>
            </div>
        </div>

        <div class="section">
            <h3>Discount & Rules</h3>

            <div class="row">
                <?php 
                    $tName = !empty($voucher['TIER_NAME']) ? $voucher['TIER_NAME'] : 'All';
                    $hue = hexdec(substr(md5($tName), 0, 7)) % 360; 
                    $tBg = "hsl({$hue}, 70%, 85%)";
                    $tTxt = "hsl({$hue}, 70%, 25%)";
                    $tBorder = "hsl({$hue}, 50%, 75%)";
                ?>

                <span class="label">Applicable To:</span>
                <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 600;">
                    <?= htmlspecialchars($tName) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Discount Rate:</span> 
                <span><?= $voucher['DISCOUNT_RATE'] ?>%</span>
            </div>

            <div class="row">
                <span class="label">Min Spend:</span> 
                <span>RM <?= number_format($voucher['MIN_SPEND'], 2) ?></span>
            </div>

            <div class="row">
                <span class="label">Per User Limit:</span> 
                <span>
                    <?= $voucher['PER_USER_LIMIT'] == -1 ? 'Unlimited' : $voucher['PER_USER_LIMIT'] ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Max Usage:</span>
                <span>
                    <?= $voucher['MAX_USAGE'] == -1 ? 'Unlimited' : $voucher['MAX_USAGE'] ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Already Used:</span> 
                <span>
                    <?= $voucher['USED_COUNT'] ?> / 
                    <?= $voucher['MAX_USAGE'] == -1 ? 'Unlimited' : $voucher['MAX_USAGE'] ?>
                </span>
            </div>
        </div>

        <div class="section">
            <h3>System Info</h3>

            <div class="row">
                <span class="label">Created At:</span> 
                <span><?= date("d M Y H:i", strtotime($voucher['CREATED_AT'])) ?></span>
            </div>

            <div class="row">
                <span class="label">Updated At:</span>
                <span><?= !empty($voucher['UPDATED_AT']) ? date("d M Y H:i", strtotime($voucher['UPDATED_AT'])) : 'N/A' ?></span>
            </div>
        </div>

    </div>

</body>
</html>