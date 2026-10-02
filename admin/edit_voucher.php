<?php
date_default_timezone_set('Asia/Kuala_Lumpur');

require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}
$admin_id = $_SESSION['admin_id'];

$voucherId = $_GET['voucher_id'] ?? 0;

if (!$voucherId || !is_numeric($voucherId)) {
    die("Invalid Voucher ID");
}
$voucherId = (int)$voucherId;

// Fetch voucher
$stmt = $conn->prepare("SELECT * FROM voucher WHERE VOUCHER_ID = ? AND IS_DELETED = 0");
$stmt->bind_param("i", $voucherId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) die("Voucher not found");

$voucher = $result->fetch_assoc();

$voucherType  = $voucher['VOUCHER_TYPE']; // "Tier" or "Public"
$isStarted    = strtotime($voucher['START_DATE']) <= time();
$isExpired    = $voucherType === 'Public' && $voucher['EXPIRY_DATE'] !== null && strtotime($voucher['EXPIRY_DATE']) < time();

// Fetch tiers
$tiers = $conn->query("SELECT * FROM membership_tier WHERE STATUS='Active'");

// AJAX: Check duplicate code
if (isset($_POST['ajax_check_code'])) {
    $code = trim($_POST['code'] ?? '');
    if ($code === $voucher['VOUCHER_CODE']) { echo "ok"; exit; }   // 没改过 code
    if (stripos($code, 'AUTO-') === 0) { echo "reserved"; exit; }
    $stmt = $conn->prepare("SELECT VOUCHER_ID FROM voucher WHERE VOUCHER_CODE = ? AND VOUCHER_ID != ? AND IS_DELETED = 0");
    $stmt->bind_param("si", $code, $voucherId);
    $stmt->execute();
    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Update voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_code'])) {

    $errors = [];

    // if started, use existing values; else get from POST
    if ($isStarted) {
    $start        = $voucher['START_DATE'];
    $discountType = $voucher['DISCOUNT_TYPE'];
    $discount     = $voucher['DISCOUNT_RATE'];
    $minSpend     = $voucher['MIN_SPEND'];
    $tierId       = $voucher['TIER_ID'];
    $perUser      = $voucher['PER_USER_LIMIT'];
    $maxUsage     = $voucher['MAX_USAGE'];
    $limitMode    = ($perUser == -1) ? 'unlimited' : 'limited';
    $maxMode      = ($maxUsage == -1) ? 'unlimited' : 'limited';
    } else {
        $start        = $_POST['startDate'] ?? '';
        $discountType = $_POST['discountType'] ?? '';
        $discount     = trim($_POST['discount'] ?? '');
        $minSpend     = $_POST['minSpend'] ?? '';
        $tierId       = $_POST['tier_id'] ?? '';
        $perUser      = $_POST['perUserLimit'] ?? '';
        $maxUsage     = $_POST['maxUsage'] ?? '';
        $limitMode    = $_POST['limitMode'] ?? 'limited';
        $maxMode      = $_POST['maxMode'] ?? 'limited';
    }

    $name   = trim($_POST['name'] ?? '');
    $code   = trim($_POST['code'] ?? '');
    $expiry = $_POST['expiryDate'] ?? '';
    $status = $_POST['status'] ?? '';

    $now = time();

    // VALIDATIONS
    if ($name === "") $errors[] = "Name required";

    if ($code === "") {
        $errors[] = "Code required";
    } elseif (stripos($code, 'AUTO-') === 0 && $code !== $voucher['VOUCHER_CODE']) {
    $errors[] = "Code cannot start with AUTO- (reserved for system vouchers)";
    }else {
        $check = $conn->prepare("SELECT VOUCHER_ID FROM voucher WHERE VOUCHER_CODE = ? AND VOUCHER_ID != ? AND IS_DELETED = 0");
        $check->bind_param("si", $code, $voucherId);
        $check->execute();
        if ($check->get_result()->num_rows > 0) $errors[] = "Code already exists";
    }

    // Start date — only validate if not started (started = immutable)
    if (!$isStarted) {
        if ($start === "") {
            $errors[] = "Start date required";
        } else {
            if (strtotime($start) === false)      $errors[] = "Invalid start date";
            elseif (strtotime($start) < $now)     $errors[] = "Start date cannot be in the past";
        }
    }

    // Expiry date validation — only for Public
    if ($voucherType === 'Public') {
        if ($expiry === "") {
            $errors[] = "Expiry date required";
        } elseif (strtotime($expiry) === false) {
            $errors[] = "Invalid expiry date";
        } else {
            $newExpiry      = strtotime($expiry);
            $oldExpiry      = strtotime($voucher['EXPIRY_DATE']);
            $effectiveStart = strtotime($start);

            if ($newExpiry <= $effectiveStart) {
                $errors[] = "Expiry date cannot be earlier than or equal to start date";
            }

            if ($isExpired) {
                if ($newExpiry < $oldExpiry)                    $errors[] = "New expiry must be later than or equal to old expiry";
                elseif ($newExpiry != $oldExpiry && $newExpiry < $now) $errors[] = "Expiry date must be in the future";
            } else {
                if ($newExpiry < $now) $errors[] = "Expiry date cannot be in the past";
            }
        }
    }

    // Discount, Min Spend, Per User Limit, Max Usage — only validate if not started (started = immutable)
    if (!$isStarted) {
        if (!in_array($discountType, ["PERCENTAGE", "FIXED"])) {
            $errors[] = "Invalid discount type";
        }

        if ($discount === "") {
            $errors[] = "Discount required";
        } elseif (filter_var($discount, FILTER_VALIDATE_INT) === false) {
            $errors[] = "Discount must be a whole number";
        } else {
            $discount = (int)$discount;
            if ($discount < 1) {
                $errors[] = "Discount must be at least 1";
            } elseif ($discountType === "PERCENTAGE" && $discount > 100) {
                $errors[] = "Percentage discount cannot exceed 100%";
            }
        }

        if ($minSpend === "" || !is_numeric($minSpend) || $minSpend < 0)
            $errors[] = "Invalid min spend";

        // 可选
        if ($discountType === "FIXED" && is_int($discount) && is_numeric($minSpend)
            && $minSpend > 0 && $discount > $minSpend) {
            $errors[] = "Fixed discount cannot exceed min spend";
        }

        if ($limitMode === "unlimited") {
            $perUser = -1;
        } elseif ($perUser === "" || filter_var($perUser, FILTER_VALIDATE_INT) === false || $perUser < 1) {
            $errors[] = "Invalid per user limit";
        }

        if ($maxMode === "unlimited") {
            $maxUsage = -1;
        } elseif ($maxUsage === "" || filter_var($maxUsage, FILTER_VALIDATE_INT) === false || $maxUsage < 1) {
            $errors[] = "Invalid max usage";
        }
    }

    // Tier — only for Tier type
    if ($voucherType === 'Tier') {
        if (!is_numeric($tierId)) $errors[] = "Invalid tier";
    } else {
        $tierId = null;
    }

    // Status
    if (!in_array($status, ["Active", "Inactive"])) $errors[] = "Invalid status";

    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Expiry: Tier = NULL, Public = value
    $expiryVal = ($voucherType === 'Tier') ? null : $expiry;

    $discount  = (int)$discount;
    $minSpend  = (float)$minSpend;
    $perUser   = (int)$perUser;
    $maxUsage  = (int)$maxUsage;

    $stmt = $conn->prepare("
        UPDATE voucher SET
            VOUCHER_NAME    = ?,
            VOUCHER_CODE    = ?,
            START_DATE      = ?,
            EXPIRY_DATE     = ?,
            DISCOUNT_TYPE   = ?,
            DISCOUNT_RATE   = ?,
            MIN_SPEND       = ?,
            PER_USER_LIMIT  = ?,
            MAX_USAGE       = ?,
            TIER_ID         = ?,
            VOUCHER_STATUS  = ?,
            UPDATED_AT      = NOW()
        WHERE VOUCHER_ID = ?
    ");

    $stmt->bind_param(
        "sssssidiiisi",
        $name, $code, $start, $expiryVal,
        $discountType, $discount, $minSpend, $perUser, $maxUsage,
        $tierId, $status, $voucherId
    );

   $conn->begin_transaction();
try {
    if (!$stmt->execute()) throw new Exception("Update failed");

        if ($voucherType === 'Tier' && !$isStarted && strtotime($start) !== strtotime($voucher['START_DATE'])) {
        $newClaimExpiry = date('Y-m-d', strtotime('+1 month', strtotime($start)));
        $upd = $conn->prepare("UPDATE customer_voucher SET EXPIRY_DATE = ? WHERE VOUCHER_ID = ? AND USED_COUNT = 0");
        $upd->bind_param("si", $newClaimExpiry, $voucherId);
        if (!$upd->execute()) throw new Exception("Expiry update failed");
    }
    
    if ($voucherType === 'Tier' && !$isStarted && (int)$voucher['TIER_ID'] !== (int)$tierId) {
        $del = $conn->prepare("DELETE FROM customer_voucher WHERE VOUCHER_ID = ? AND USED_COUNT = 0");
        $del->bind_param("i", $voucherId);
        if (!$del->execute()) throw new Exception("Cleanup failed");

        $claimExpiry = date('Y-m-d', strtotime('+1 month', strtotime($start)));
        $tierIdInt   = (int)$tierId;
        $assign = $conn->prepare("
            INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, EXPIRY_DATE)
            SELECT c.CUSTOMER_ID, ?, 0, ?
            FROM customer c
            WHERE c.TIER_ID = ? AND c.STATUS = 'Active'
              AND NOT EXISTS (SELECT 1 FROM customer_voucher cv
                              WHERE cv.CUSTOMER_ID = c.CUSTOMER_ID AND cv.VOUCHER_ID = ?)
        ");
        $assign->bind_param("isii", $voucherId, $claimExpiry, $tierIdInt, $voucherId);
        if (!$assign->execute()) throw new Exception("Assign failed");
    }

    $conn->commit();
    echo "ok";
} catch (Exception $e) {
    $conn->rollback();
    echo $e->getMessage();
}
exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Voucher | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
:root {
    --bg-page: #f4f5f8;
    --card-bg: #ffffff;
    --text-dark: #111827;
    --text-muted: #71717a;
    --text-light: #9ca3af;
    --accent-lime: #d4f938;
    --accent-lime-hover: #c4ec29;
    --accent-lime-subtle: #f4fde6;
    --dark-pill: #18181b;
    --dark-pill-hover: #27272a;
    --border-card: #edf0f3;
    --border-subtle: #f1f3f6;
    --shadow-soft: 0 4px 20px rgba(17, 24, 39, 0.035);
    --shadow-hover: 0 10px 28px rgba(17, 24, 39, 0.07);
    --radius-card: 20px;
    --radius-pill: 9999px;
    --font-heading: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'Inter', -apple-system, sans-serif;
}

body {
    background-color: var(--bg-page);
    font-family: var(--font-body);
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}

.form-wrapper {
    max-width: 740px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

/* Back Link */
.form-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    margin-bottom: 20px;
}

.form-back-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateX(-2px);
    text-decoration: none;
}

/* Page Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}

.dash-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
}

.dash-title-badge {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime);
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.dash-title {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 26px;
    display: block;
}

/* Alert / Notes */
.note-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 18px;
    border-radius: 14px;
    margin-bottom: 24px;
    font-size: 13px;
    font-weight: 600;
}

.note-banner.expired {
    background: #fffbeb;
    border: 1px solid #fef3c7;
    color: #b45309;
}

.note-banner.started {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    color: #1e40af;
}

.note-banner i {
    font-size: 18px;
}

/* Form Card */
.form-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 28px 32px;
    box-shadow: var(--shadow-soft);
    position: relative;
}

.form-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-subtle);
}

.form-card-title i {
    color: var(--text-dark);
    font-size: 16px;
}

.form-card-title .card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-muted);
    margin-left: auto;
}

/* Form Controls */
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

@media (max-width: 600px) {
    .form-row { grid-template-columns: 1fr; }
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 20px;
}

.form-group label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    margin: 0;
}

.asterisk {
    color: #ef4444;
    margin-left: 2px;
}

.form-input {
    width: 100%;
    padding: 11px 16px;
    border: 1px solid var(--border-card);
    border-radius: 12px;
    font-size: 13.5px;
    background: var(--bg-page);
    color: var(--text-dark);
    font-family: var(--font-body);
    transition: all 0.2s ease;
    box-sizing: border-box;
}

.form-input:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.form-input:disabled,
.form-input[readonly] {
    background: #f1f3f6 !important;
    color: #64748b !important;
    cursor: not-allowed;
    border-color: var(--border-card);
}

.form-input.input-error {
    border-color: #ef4444;
    background: #fff5f5;
}

.code-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.code-input-wrapper {
    display: flex;
    gap: 8px;
    align-items: center;
}

.code-auto-btn {
    padding: 6px 14px;
    font-size: 12px;
    font-weight: 700;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.2s ease;
}

.code-auto-btn:hover {
    background: var(--dark-pill);
    color: #ffffff;
    border-color: var(--dark-pill);
}

.code-unlimited {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    cursor: pointer;
}

.error {
    color: #ef4444;
    font-size: 12px;
    margin-top: 4px;
    font-weight: 500;
}

/* Actions */
.form-actions {
    margin-top: 28px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.form-btn {
    background: var(--dark-pill);
    color: #ffffff;
    border: none;
    border-radius: var(--radius-pill);
    padding: 12px 28px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
    font-family: var(--font-heading);
}

.form-btn:hover {
    background: #000000;
    color: var(--accent-lime);
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(24, 24, 27, 0.25);
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 12px 20px;
    border-radius: var(--radius-pill);
    font-size: 13.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    z-index: 9999;
    animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: all 0.3s ease;
}

.toast.success {
    background: var(--dark-pill);
    color: #ffffff;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.toast.error {
    background: #ef4444;
    color: #ffffff;
}

.toast-close-btn {
    cursor: pointer;
    font-size: 16px;
    line-height: 1;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.toast-close-btn:hover {
    opacity: 1;
}

@keyframes toastIn {
    from { opacity: 0; transform: translateY(-10px) scale(0.95); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_voucher.php" class="form-back-link" title="Go Back To Voucher Page">
        <i class="bi bi-arrow-left"></i> Back to Vouchers
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-pencil-square"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Edit Voucher</strong> Update voucher details and rules</h1>
            </div>
        </div>
    </div>

    <?php if ($isExpired): ?>
        <div class="note-banner expired">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>This voucher has expired. Only limited fields (such as extended expiry date & status) can be edited.</span>
        </div>
    <?php elseif ($isStarted): ?>
        <div class="note-banner started">
            <i class="bi bi-info-circle-fill"></i>
            <span>This voucher has started. Core financial discount & rule fields are locked.</span>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <div class="form-card-title">
            <i class="bi bi-sliders"></i> Voucher Details
            <span class="card-badge">ID #<?= $voucherId ?></span>
        </div>

        <form method="POST" id="form" novalidate>

            <div class="form-row">
                <div class="form-group">
                    <label>Type</label>
                    <input type="text" class="form-input" value="<?= htmlspecialchars($voucherType) ?>" readonly>
                    <input type="hidden" name="type" value="<?= htmlspecialchars($voucherType) ?>">
                </div>

                <div class="form-group">
                    <label>Status<span class="asterisk">*</span></label>
                    <select name="status" class="form-input">
                        <option value="Active"   <?= $voucher['VOUCHER_STATUS'] === 'Active'   ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $voucher['VOUCHER_STATUS'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Voucher Name<span class="asterisk">*</span></label>
                <input type="text" id="nameInput" name="name" value="<?= htmlspecialchars($voucher['VOUCHER_NAME']) ?>" class="form-input" maxlength="50">
                <span id="nameError" class="error"></span>
            </div>

            <div class="form-group">
                <div class="code-header">
                    <label>Voucher Code<span class="asterisk">*</span></label>
                    <button type="button" id="autoBtn" class="code-auto-btn" title="Auto Generate Code">
                        <i class="bi bi-magic"></i> Auto
                    </button>
                </div>
                <div class="code-input-wrapper">
                    <input type="text" id="codeInput" name="code" value="<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?>" class="form-input" maxlength="50" style="font-family:monospace; font-weight:600;">
                </div>
                <span id="codeError" class="error"></span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Start Date<span class="asterisk">*</span></label>
                    <input type="datetime-local" id="startInput" name="startDate" value="<?= date('Y-m-d\TH:i', strtotime($voucher['START_DATE'])) ?>" class="form-input" <?= $isStarted ? 'readonly' : '' ?>>
                    <span id="startError" class="error"></span>
                </div>

                <?php if ($voucherType === 'Public'): ?>
                <div class="form-group">
                    <label>Expiry Date<span class="asterisk">*</span></label>
                    <input type="datetime-local" id="expiryInput" name="expiryDate" value="<?= $voucher['EXPIRY_DATE'] ? date('Y-m-d\TH:i', strtotime($voucher['EXPIRY_DATE'])) : '' ?>" class="form-input">
                    <span id="expiryError" class="error"></span>
                </div>
                <?php else: ?>
                    <input type="hidden" name="expiryDate" value="">
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Discount Type<span class="asterisk">*</span></label>
                    <select id="discountType" name="discountType" class="form-input" <?= $isStarted ? 'disabled' : '' ?>>
                        <option value="PERCENTAGE" <?= $voucher['DISCOUNT_TYPE'] === 'PERCENTAGE' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="FIXED"      <?= $voucher['DISCOUNT_TYPE'] === 'FIXED' ? 'selected' : '' ?>>Fixed Amount (RM)</option>
                    </select>
                    <?php if ($isStarted): ?>
                        <input type="hidden" name="discountType" value="<?= htmlspecialchars($voucher['DISCOUNT_TYPE']) ?>">
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label id="disLabel">Discount Rate (%)<span class="asterisk">*</span></label>
                    <input type="number" id="disInput" name="discount" value="<?= (int)$voucher['DISCOUNT_RATE'] ?>" class="form-input" step="1" min="1" <?= $isStarted ? 'readonly' : '' ?>>
                    <span id="disError" class="error"></span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Min Spend (RM)<span class="asterisk">*</span></label>
                    <input type="number" step="0.01" id="minInput" name="minSpend" value="<?= $voucher['MIN_SPEND'] ?>" class="form-input" <?= $isStarted ? 'readonly' : '' ?>>
                    <span id="minError" class="error"></span>
                </div>
                <div></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Per User Limit<span class="asterisk">*</span></label>
                    <input type="number" id="limitInput" name="perUserLimit" value="<?= $voucher['PER_USER_LIMIT'] == -1 ? '' : $voucher['PER_USER_LIMIT'] ?>" class="form-input" step="1" <?= $isStarted ? 'readonly' : '' ?> <?= ($isStarted && $voucher['PER_USER_LIMIT'] == -1) ? 'placeholder="Unlimited"' : '' ?>>
                    <label class="code-unlimited">
                        <input type="checkbox" id="limitUnlimited" <?= $voucher['PER_USER_LIMIT'] == -1 ? 'checked' : '' ?> <?= $isStarted ? 'disabled' : '' ?>> Unlimited
                    </label>
                    <span id="limitError" class="error"></span>
                    <input type="hidden" id="limitMode" name="limitMode" value="<?= $voucher['PER_USER_LIMIT'] == -1 ? 'unlimited' : 'limited' ?>">
                </div>

                <div class="form-group">
                    <label>Max Usage Count<span class="asterisk">*</span></label>
                    <input type="number" id="maxInput" name="maxUsage" value="<?= $voucher['MAX_USAGE'] == -1 ? '' : $voucher['MAX_USAGE'] ?>" class="form-input" step="1" <?= $isStarted ? 'readonly' : '' ?> <?= ($isStarted && $voucher['MAX_USAGE'] == -1) ? 'placeholder="Unlimited"' : '' ?>>
                    <label class="code-unlimited">
                        <input type="checkbox" id="maxUnlimited" <?= $voucher['MAX_USAGE'] == -1 ? 'checked' : '' ?> <?= $isStarted ? 'disabled' : '' ?>> Unlimited
                    </label>
                    <span id="maxError" class="error"></span>
                    <input type="hidden" id="maxMode" name="maxMode" value="<?= $voucher['MAX_USAGE'] == -1 ? 'unlimited' : 'limited' ?>">
                </div>
            </div>

            <?php if ($voucherType === 'Tier'): ?>
            <div class="form-group">
                <label>Available for Membership Tier<span class="asterisk">*</span></label>
                <select name="tier_id" id="tierSelect" class="form-input" <?= $isStarted ? 'disabled' : '' ?>>
                    <?php while($t = $tiers->fetch_assoc()): ?>
                        <option value="<?= $t['TIER_ID'] ?>" <?= $voucher['TIER_ID'] == $t['TIER_ID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t['TIER_NAME']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <?php if ($isStarted): ?>
                    <input type="hidden" name="tier_id" value="<?= $voucher['TIER_ID'] ?>">
                <?php endif; ?>
            </div>
            <?php else: ?>
                <input type="hidden" name="tier_id" value="">
            <?php endif; ?>

            <div class="form-actions">
                <button type="submit" class="form-btn">
                    <i class="bi bi-check2"></i> Save Changes
                </button>
            </div>

        </form>
    </div>
</div>

<script>
const voucherType    = <?= json_encode($voucherType) ?>;
const voucherStarted = <?= $isStarted ? 'true' : 'false' ?>;
const voucherExpired = <?= $isExpired ? 'true' : 'false' ?>;
const oldExpiryTime  = <?= ($voucherType === 'Public' && $voucher['EXPIRY_DATE']) ? strtotime($voucher['EXPIRY_DATE']) * 1000 : 'null' ?>;
const originalCode = <?= json_encode($voucher['VOUCHER_CODE']) ?>; 

document.addEventListener("DOMContentLoaded", function () {

    const form        = document.getElementById("form");
    const nameInput   = document.getElementById("nameInput");
    const codeInput   = document.getElementById("codeInput");
    const startInput  = document.getElementById("startInput");
    const expiryInput = document.getElementById("expiryInput");
    const disInput    = document.getElementById("disInput");
    const minInput    = document.getElementById("minInput");
    const limitInput  = document.getElementById("limitInput");
    const maxInput    = document.getElementById("maxInput");
    const discountType = document.getElementById("discountType");
    const disLabel     = document.getElementById("disLabel");

    const nameError   = document.getElementById("nameError");
    const codeError   = document.getElementById("codeError");
    const startError  = document.getElementById("startError");
    const expiryError = document.getElementById("expiryError");
    const disError    = document.getElementById("disError");
    const minError    = document.getElementById("minError");
    const limitError  = document.getElementById("limitError");
    const maxError    = document.getElementById("maxError");

    const limitUnlimited = document.getElementById("limitUnlimited");
    const maxUnlimited   = document.getElementById("maxUnlimited");
    const limitMode      = document.getElementById("limitMode");
    const maxMode        = document.getElementById("maxMode");

    function setError(input, errorEl, msg) {
        if (!input || !errorEl) return;
        errorEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errorEl) {
        if (!input || !errorEl) return;
        errorEl.textContent = "";
        input.classList.remove("input-error");
    }
    function isEmpty(val) { return val.trim() === ""; }

    function applyUnlimited(checkbox, input, modeInput) {
        if (!checkbox || !input) return;
        if (checkbox.checked) {
            modeInput.value   = "unlimited";
            input.value       = "";
            input.readOnly    = true;
            input.placeholder = "Unlimited";
            clearError(input, input === limitInput ? limitError : maxError);
        } else {
            if (!voucherStarted) {
                modeInput.value   = "limited";
                input.readOnly    = false;
                input.placeholder = "";
            }
        }
    }

    applyUnlimited(limitUnlimited, limitInput, limitMode);
    applyUnlimited(maxUnlimited,   maxInput,   maxMode);

    if (limitUnlimited) limitUnlimited.addEventListener("change", () => applyUnlimited(limitUnlimited, limitInput, limitMode));
    if (maxUnlimited)   maxUnlimited.addEventListener("change",   () => applyUnlimited(maxUnlimited,   maxInput,   maxMode));

    nameInput.addEventListener("input", function () {
        isEmpty(this.value) ? setError(this, nameError, "Name required") : clearError(this, nameError);
    });

    function validateCode() {
        const code = codeInput.value.trim();
        if (code === "") { setError(codeInput, codeError, "Code required"); return; }
        if (code === originalCode) { clearError(codeInput, codeError); return; }
        if (/^auto-/i.test(code)) { setError(codeInput, codeError, "Cannot start with AUTO-"); return; }
        fetch("edit_voucher.php?voucher_id=<?= $voucherId ?>", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "ajax_check_code=1&code=" + encodeURIComponent(code)
        })
        .then(r => r.text())
        .then(data => {
            data.trim() === "exists"
                ? setError(codeInput, codeError, "Code already exists")
                : clearError(codeInput, codeError);
        });
    }
    codeInput.addEventListener("input", validateCode);

    function validateDates() {
        const now    = new Date();
        const nowMin = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), now.getMinutes());
        const start  = new Date(startInput.value);

        if (!startInput.value) {
            setError(startInput, startError, "Start date required");
        } else if (isNaN(start.getTime())) {
            setError(startInput, startError, "Invalid date");
        } else if (!voucherStarted && start < nowMin) {
            setError(startInput, startError, "Cannot be in the past");
        } else {
            clearError(startInput, startError);
        }

        if (voucherType === 'Public' && expiryInput) {
            const expiry = new Date(expiryInput.value);

            if (!expiryInput.value) {
                setError(expiryInput, expiryError, "Expiry required");
            } else if (isNaN(expiry.getTime())) {
                setError(expiryInput, expiryError, "Invalid date");
            } else if (startInput.value && !isNaN(start.getTime()) && expiry <= start) {
                setError(expiryInput, expiryError, "Must be after start date");
            } else if (voucherExpired) {
                const newT = expiry.getTime();
                const nowT = nowMin.getTime();

                if (Math.abs(newT - oldExpiryTime) < 60000) {
                    clearError(expiryInput, expiryError);
                } else if (newT < oldExpiryTime) {
                    setError(expiryInput, expiryError, "Cannot be earlier than old expiry");
                } else if (newT < nowT) {
                    setError(expiryInput, expiryError, "Must be a future date");
                } else {
                    clearError(expiryInput, expiryError);
                }
            } else {
                expiry < nowMin ? setError(expiryInput, expiryError, "Cannot be in the past") : clearError(expiryInput, expiryError);
            }
        }
    }

    startInput.addEventListener("change", validateDates);
    if (expiryInput) expiryInput.addEventListener("change", validateDates);

   function updateDiscountUI() {
    const isPct = discountType.value === "PERCENTAGE";
    disLabel.innerHTML = (isPct ? "Discount Rate (%)" : "Discount Amount (RM)") + '<span class="asterisk">*</span>';
    if (isPct) disInput.max = 100; else disInput.removeAttribute("max");
}

    function validateDiscount() {
        if (disInput.readOnly) return;
        const raw    = disInput.value.trim();
        const num    = Number(raw);
        const isPct  = discountType.value === "PERCENTAGE";
        const minVal = parseFloat(minInput.value);

        if (raw === "")
            setError(disInput, disError, "Required");
        else if (isNaN(num))
            setError(disInput, disError, "Must be a number");
        else if (!Number.isInteger(num))
            setError(disInput, disError, "Must be whole number");
        else if (num < 1)
            setError(disInput, disError, "Min 1");
        else if (isPct && num > 100)
            setError(disInput, disError, "Max 100%");
        else if (!isPct && minVal > 0 && num > minVal)   // 可选
            setError(disInput, disError, "Cannot exceed min spend");
        else
            clearError(disInput, disError);
    }

    discountType.addEventListener("change", () => { updateDiscountUI(); validateDiscount(); });
    disInput.addEventListener("input", validateDiscount);
    updateDiscountUI();

    minInput.addEventListener("input", function () {
        if (this.readOnly) return;
        const val = parseFloat(this.value);
        const rawValue = this.value.trim();
        const isNegativeString = /^-/.test(rawValue);

        if (this.validity.rangeUnderflow || val < 0 || isNegativeString)
            setError(this, minError, "Cannot be negative");
        else if (rawValue === "")
            setError(this, minError, "Required");
        else if (isNaN(val))
            setError(this, minError, "Must be a number");
        else
            clearError(this, minError);

        if (disInput.value !== "") validateDiscount();   // 可选
    });
    limitInput.addEventListener("input", function () {
        if (this.readOnly) return;
        const val = this.value;
        const num = Number(val);
        if (val === "")                        
            setError(this, limitError, "Required");
        else if (!Number.isInteger(num))       
            setError(this, limitError, "Must be whole number");
        else if (num < 1)                      
            setError(this, limitError, "Min 1");
        else                                   
            clearError(this, limitError);
    });

    maxInput.addEventListener("input", function () {
        if (this.readOnly) return;
        const val = this.value;
        const num = Number(val);
        if (val === "")                        
            setError(this, maxError, "Required");
        else if (!Number.isInteger(num))       
            setError(this, maxError, "Must be whole number");
        else if (num < 1)                      
            setError(this, maxError, "Min 1");
        else                                   
            clearError(this, maxError);
    });

    function validateAll() {
        let ok = true;
        validateDates();

        if (isEmpty(nameInput.value))  { setError(nameInput, nameError, "Name required"); ok = false; }
        if (isEmpty(codeInput.value))  { setError(codeInput, codeError, "Code required"); ok = false; }
        
        if (!voucherStarted) {
            if (!startInput.value) ok = false;
            if (disInput.value === "") { 
                setError(disInput, disError, "Discount required"); ok = false; 
            }
            if (minInput.value === "") { 
                setError(minInput, minError, "Min spend required"); ok = false; 
            }
            if (!limitUnlimited.checked && limitInput.value === "") { 
                setError(limitInput, limitError, "Required"); ok = false; 
            }
            if (!maxUnlimited.checked && maxInput.value === "") { 
                setError(maxInput, maxError, "Required"); ok = false; 
            }
        }

        if (voucherType === 'Public' && expiryInput && !expiryInput.value) ok = false;

        const errors = [nameError, codeError, startError, expiryError, disError, minError, limitError, maxError];
        errors.forEach(el => {
            if (el && el.textContent.trim() !== "") ok = false;
        });

        return ok;
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        if (!validateAll()) { 
            showToast("error", "Please fix the errors"); return; 
        }

        const formData = new FormData(form);
        fetch("edit_voucher.php?voucher_id=<?= $voucherId ?>", { method: "POST", body: formData })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === "ok") {
                showToast("success", "Voucher updated successfully");
                setTimeout(() => { window.location.href = "manage_voucher.php"; }, 800);
            } else {
                showToast("error", "Failed: " + data.trim());
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });

    document.getElementById("autoBtn").addEventListener("click", function () {
        codeInput.value = generateCode();
        codeInput.dispatchEvent(new Event("input"));
    });

    validateDates();
    validateCode();
});

function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    const text = document.createElement("span");
    text.innerText = message;

    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);
    document.body.appendChild(toast);

    let removed = false;
    function removeToast() {
        if (removed) return;
        removed = true;
        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";
        setTimeout(() => toast.remove(), 300);
    }

    closeBtn.addEventListener("click", e => { e.stopPropagation(); removeToast(); });
    toast.addEventListener("click", removeToast);
    setTimeout(removeToast, type === "error" ? 8000 : 3000);
}

function generateCode() {
    const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    let code = "VCH-";
    for (let i = 0; i < 6; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return code;
}
</script>
</body>
</html>