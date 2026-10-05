<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}
$admin_id = $_SESSION['admin_id'];

$tiers = $conn->query("SELECT * FROM membership_tier WHERE STATUS='Active'");

// AJAX: Check duplicate code
if (isset($_POST['ajax_check_code'])) {
    $code = $_POST['code'] ?? '';
    if (stripos($code, 'AUTO-') === 0) { echo "reserved"; exit; }
    $stmt = $conn->prepare("SELECT VOUCHER_ID FROM voucher WHERE VOUCHER_CODE = ? AND IS_DELETED = 0");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_code'])) {

    $errors = [];

    $name         = trim($_POST['name'] ?? '');
    $code         = trim($_POST['code'] ?? '');
    $type         = $_POST['type'] ?? '';
    $start        = $_POST['startDate'] ?? '';
    $expiry       = $_POST['expiryDate'] ?? '';
    $discountType = $_POST['discountType'] ?? '';
    $discount     = trim($_POST['discount'] ?? '');
    $minSpend     = $_POST['minSpend'] ?? '';
    $perUser      = $_POST['perUserLimit'] ?? '';
    $maxUsage     = $_POST['maxUsage'] ?? '';
    $tierId       = $_POST['tier_id'] ?? '';
    $status       = $_POST['status'] ?? '';
    $limitMode    = $_POST['limitMode'] ?? 'limited';
    $maxMode      = $_POST['maxMode'] ?? 'limited';

    $now = time();

    // Name
    if ($name === "") $errors[] = "Name required";

    // Code
    if ($code === "") {
        $errors[] = "Code required";
    } elseif (stripos($code, 'AUTO-') === 0) {
        $errors[] = "Code cannot start with AUTO- (reserved for system vouchers)";
    } else {
        $check = $conn->prepare("SELECT VOUCHER_ID FROM voucher WHERE VOUCHER_CODE = ? AND IS_DELETED = 0");
        $check->bind_param("s", $code);
        $check->execute();
        if ($check->get_result()->num_rows > 0) $errors[] = "Code already exists";
    }

    // Type
    if (!in_array($type, ["Tier", "Public"])) {
        $errors[] = "Invalid type";
    }

    // Start date
    if ($start === "") {
        $errors[] = "Start date required";
    } elseif (strtotime($start) === false) {
        $errors[] = "Invalid start date";
    } elseif (strtotime($start) < $now) {
        $errors[] = "Start date cannot be in the past";
    }

    // Expiry date — only required for Public
    if ($type === "Public") {
        if ($expiry === "") {
            $errors[] = "Expiry date required";
        } elseif (strtotime($expiry) === false) {
            $errors[] = "Invalid expiry date";
        } elseif (strtotime($expiry) < $now) {
            $errors[] = "Expiry date cannot be in the past";
        } elseif ($start !== "" && strtotime($expiry) < strtotime($start)) {
            $errors[] = "Expiry date cannot be earlier than start date";
        }
    }

    // Discount type
    if (!in_array($discountType, ["PERCENTAGE", "FIXED"])) {
        $errors[] = "Invalid discount type";
    }

    // Discount (int)
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

    // Min spend
    if ($minSpend === "") {
        $errors[] = "Min spend required";
    } elseif (!is_numeric($minSpend)) {
        $errors[] = "Min spend must be a number";
    } elseif ($minSpend < 0) {
        $errors[] = "Min spend cannot be negative";
    }

    // 可选：固定金额折扣不能大于最低消费
    if ($discountType === "FIXED" && is_int($discount) && is_numeric($minSpend)
        && $minSpend > 0 && $discount > $minSpend) {
        $errors[] = "Fixed discount cannot exceed min spend";
    }

    // Per user limit
    if ($limitMode === "unlimited") {
        $perUser = -1;
    } elseif ($perUser === "") {
        $errors[] = "Per user limit required";
    } elseif (filter_var($perUser, FILTER_VALIDATE_INT) === false) {
        $errors[] = "Per user limit must be a whole number";
    } elseif ($perUser < 1) {
        $errors[] = "Per user limit must be at least 1";
    }

    // Max usage
    if ($maxMode === "unlimited") {
        $maxUsage = -1;
    } elseif ($maxUsage === "") {
        $errors[] = "Max usage required";
    } elseif (filter_var($maxUsage, FILTER_VALIDATE_INT) === false) {
        $errors[] = "Max usage must be a whole number";
    } elseif ($maxUsage < 1) {
        $errors[] = "Max usage must be at least 1";
    }

    // Tier — only required for Tier type
    if ($type === "Tier") {
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

    $expiryVal = ($type === "Tier") ? null : $expiry;

    $stmt = $conn->prepare("
        INSERT INTO voucher (
            VOUCHER_NAME, VOUCHER_CODE, VOUCHER_TYPE,
            START_DATE, EXPIRY_DATE,
            DISCOUNT_TYPE, DISCOUNT_RATE, MIN_SPEND,
            PER_USER_LIMIT, MAX_USAGE, USED_COUNT,
            TIER_ID, VOUCHER_STATUS, IS_DELETED
        ) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,0)
    ");

    $minSpend = (float)$minSpend;
    $perUser  = (int)$perUser;
    $maxUsage = (int)$maxUsage;

    $stmt->bind_param(
        "ssssssidiiis",
        $name, $code, $type,
        $start, $expiryVal,
        $discountType, $discount, $minSpend,
        $perUser, $maxUsage,
        $tierId, $status
    );
$conn->begin_transaction();

try {
    if (!$stmt->execute()) throw new Exception("Insert failed");

    $newVoucherId = $stmt->insert_id;

    // Tier voucher：自动发给该 tier 的所有 Active 客户
    if ($type === "Tier") {
        // 到期日 = 开始日 + 1 个月（start 已验证不会在过去）
        $claimExpiry = date('Y-m-d', strtotime('+1 month', strtotime($start)));
        $tierIdInt   = (int)$tierId;

        $assign = $conn->prepare("
            INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, EXPIRY_DATE)
            SELECT c.CUSTOMER_ID, ?, 0, ?
            FROM customer c
            WHERE c.TIER_ID = ?
              AND c.STATUS = 'Active'
              AND NOT EXISTS (
                  SELECT 1 FROM customer_voucher cv
                  WHERE cv.CUSTOMER_ID = c.CUSTOMER_ID AND cv.VOUCHER_ID = ?
              )
        ");
        $assign->bind_param("isii", $newVoucherId, $claimExpiry, $tierIdInt, $newVoucherId);

        if (!$assign->execute()) throw new Exception("Assign failed");
    }

    // Public voucher：自动发给所有 Active 客户，到期日 = voucher 的到期日
    if ($type === "Public") {
        $publicExpiry = date('Y-m-d', strtotime($expiry));

        $assignPub = $conn->prepare("
            INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, EXPIRY_DATE)
            SELECT c.CUSTOMER_ID, ?, 0, ?
            FROM customer c
            WHERE c.STATUS = 'Active'
              AND NOT EXISTS (
                  SELECT 1 FROM customer_voucher cv
                  WHERE cv.CUSTOMER_ID = c.CUSTOMER_ID AND cv.VOUCHER_ID = ?
              )
        ");
        $assignPub->bind_param("isi", $newVoucherId, $publicExpiry, $newVoucherId);
        if (!$assignPub->execute()) throw new Exception("Assign public failed");
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
    <title>Add Voucher | Admin</title>
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

.form-back-link i:hover, .form-back-link:hover {
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

.badge-arrow i {
    color: var(--accent-lime);
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

/* Info Note Card */
.info-note-card {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 24px;
    font-size: 13px;
    color: #1e40af;
    line-height: 1.5;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.info-note-card i {
    font-size: 18px;
    color: #3b82f6;
    margin-top: 1px;
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

.form-input:disabled {
    background: #e9ecef;
    color: #6c757d;
    cursor: not-allowed;
    opacity: 0.8;
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

.form-btn i{
     color: #ffffff;
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
                <i class="bi bi-ticket-perforated"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Add Voucher</strong> Create a new promotional voucher</h1>
            </div>
        </div>
    </div>

    <div class="info-note-card">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            <div><strong>Tier Voucher:</strong> No expiry date required. Valid for 1 months once claimed by customer.</div>
            <div><strong>Public Voucher:</strong> Accessible to all users during the specified active date range.</div>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card-title">
            <i class="bi bi-sliders"></i> Voucher Configuration
            <span class="card-badge">New</span>
        </div>

        <form method="POST" enctype="multipart/form-data" id="form" novalidate>

            <div class="form-row">
                <div class="form-group">
                    <label>Type<span class="asterisk">*</span></label>
                    <select name="type" id="typeSelect" class="form-input">
                        <option value="Tier">Tier</option>
                        <option value="Public">Public</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Status<span class="asterisk">*</span></label>
                    <select name="status" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Voucher Name<span class="asterisk">*</span></label>
                <input type="text" id="nameInput" name="name" class="form-input" placeholder="e.g. New Year Special 10%" maxlength="50">
                <span id="nameError" class="error"></span>
            </div>

            <div class="form-group">
                <div class="code-header">
                    <label>Voucher Code<span class="asterisk">*</span></label>
                    <button type="button" id="autoBtn" class="code-auto-btn" title="Auto Generate Voucher Code">
                        <i class="bi bi-magic"></i> Auto
                    </button>
                </div>
                <div class="code-input-wrapper">
                    <input type="text" id="codeInput" name="code" class="form-input" placeholder="e.g. NY2026" maxlength="50" style="font-family:monospace; font-weight:600;">
                </div>
                <span id="codeError" class="error"></span>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Start Date<span class="asterisk">*</span></label>
                    <input type="datetime-local" id="startInput" name="startDate" class="form-input">
                    <span id="startError" class="error"></span>
                </div>

                <div class="form-group" id="expiryGroup">
                    <label>Expiry Date<span class="asterisk">*</span></label>
                    <input type="datetime-local" id="expiryInput" name="expiryDate" class="form-input">
                    <span id="expiryError" class="error"></span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Discount Type<span class="asterisk">*</span></label>
                    <select name="discountType" id="discountType" class="form-input">
                        <option value="PERCENTAGE">Percentage (%)</option>
                        <option value="FIXED">Fixed Amount (RM)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label id="disLabel">Discount Rate (%)<span class="asterisk">*</span></label>
                    <input type="number" id="disInput" name="discount" class="form-input" placeholder="e.g. 15" step="1" min="1">
                    <span id="disError" class="error"></span>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Min Spend (RM)<span class="asterisk">*</span></label>
                    <input type="number" id="minInput" name="minSpend" class="form-input" placeholder="e.g. 50.00" step="0.01">
                    <span id="minError" class="error"></span>
                </div>
                <div></div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Per User Limit<span class="asterisk">*</span></label>
                    <input type="number" id="limitInput" name="perUserLimit" class="form-input" placeholder="e.g. 1" step="1">
                    <label class="code-unlimited"><input type="checkbox" id="limitUnlimited"> Unlimited</label>
                    <span id="limitError" class="error"></span>
                    <input type="hidden" id="limitMode" name="limitMode" value="limited">
                </div>

                <div class="form-group">
                    <label>Max Usage Count<span class="asterisk">*</span></label>
                    <input type="number" id="maxInput" name="maxUsage" class="form-input" placeholder="e.g. 100" step="1">
                    <label class="code-unlimited"><input type="checkbox" id="maxUnlimited"> Unlimited</label>
                    <span id="maxError" class="error"></span>
                    <input type="hidden" id="maxMode" name="maxMode" value="limited">
                </div>
            </div>

            <div class="form-group" id="tierGroup">
                <label>Available for Membership Tier<span class="asterisk">*</span></label>
                <select name="tier_id" id="tierSelect" class="form-input">
                <?php
                $tiers->data_seek(0);
                while($t = $tiers->fetch_assoc()): ?>
                    <option value="<?= $t['TIER_ID'] ?>"><?= htmlspecialchars($t['TIER_NAME']) ?></option>
                <?php endwhile; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="form-btn">
                    <i class="bi bi-plus-lg"></i> Create Voucher
                </button>
            </div>

        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    const form        = document.getElementById("form");
    const typeSelect  = document.getElementById("typeSelect");
    const nameInput   = document.getElementById("nameInput");
    const codeInput   = document.getElementById("codeInput");
    const startInput  = document.getElementById("startInput");
    const expiryInput = document.getElementById("expiryInput");
    const disInput    = document.getElementById("disInput");
    const minInput    = document.getElementById("minInput");
    const limitInput  = document.getElementById("limitInput");
    const maxInput    = document.getElementById("maxInput");
    const tierSelect  = document.getElementById("tierSelect");
    const discountType = document.getElementById("discountType");
    const disLabel     = document.getElementById("disLabel");
    const expiryGroup = document.getElementById("expiryGroup");
    const tierGroup   = document.getElementById("tierGroup");

    const nameError   = document.getElementById("nameError");
    const codeError   = document.getElementById("codeError");
    const startError  = document.getElementById("startError");
    const expiryError = document.getElementById("expiryError");
    const disError    = document.getElementById("disError");
    const minError    = document.getElementById("minError");
    const limitError  = document.getElementById("limitError");
    const maxError    = document.getElementById("maxError");

    function setError(input, errorEl, msg) {
        errorEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errorEl) {
        errorEl.textContent = "";
        input.classList.remove("input-error");
    }
    function isEmpty(val) { return val.trim() === ""; }

    function applyTypeUI() {
        const type = typeSelect.value;

        if (type === "Tier") {
            expiryInput.disabled = true;
            expiryInput.value = "";
            clearError(expiryInput, expiryError);

            tierSelect.disabled = false;
            tierGroup.style.opacity = "1";
        } else {
            expiryInput.disabled = false;

            tierSelect.disabled = true;
            tierGroup.style.opacity = "0.5";
        }
    }

    typeSelect.addEventListener("change", applyTypeUI);
    applyTypeUI();

    nameInput.addEventListener("input", function () {
        isEmpty(this.value)
            ? setError(this, nameError, "Name required")
            : clearError(this, nameError);
    });

    function validateCode() {
        const code = codeInput.value.trim();
        if (code === "") { setError(codeInput, codeError, "Code required"); return; }
        if (/^auto-/i.test(code)) { setError(codeInput, codeError, "Cannot start with AUTO-"); return; }
        fetch("add_voucher.php", {
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
        const start  = new Date(startInput.value);
        const expiry = new Date(expiryInput.value);

        if (!startInput.value) {
            setError(startInput, startError, "Start date required");
        } else if (start < now) {
            setError(startInput, startError, "Cannot be in the past");
        } else {
            clearError(startInput, startError);
        }

        if (typeSelect.value === "Public") {
            if (!expiryInput.value) {
                setError(expiryInput, expiryError, "Expiry required");
            } else if (expiry < now) {
                setError(expiryInput, expiryError, "Cannot be in the past");
            } else if (startInput.value && expiry < start) {
                setError(expiryInput, expiryError, "Must be after start date");
            } else {
                clearError(expiryInput, expiryError);
            }
        }
    }
    startInput.addEventListener("change", validateDates);
    expiryInput.addEventListener("change", validateDates);

   function updateDiscountUI() {
    const isPct = discountType.value === "PERCENTAGE";
    disLabel.innerHTML = (isPct ? "Discount Rate (%)" : "Discount Amount (RM)") + '<span class="asterisk">*</span>';
    disInput.placeholder = isPct ? "e.g. 15" : "e.g. 5";
    if (isPct) disInput.max = 100; else disInput.removeAttribute("max");
    if (disInput.value !== "") validateDiscount();
}

function validateDiscount() {
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

discountType.addEventListener("change", updateDiscountUI);
disInput.addEventListener("input", validateDiscount);
updateDiscountUI();

minInput.addEventListener("input", function () {
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

    if (disInput.value !== "") validateDiscount();   // 可选：联动检查
});

    limitInput.addEventListener("input", function () {
        if (this.disabled) return;
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
        if (this.disabled) return;
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

    // Unlimited toggles
    document.getElementById("limitUnlimited").addEventListener("change", function () {
        if (this.checked) {
            document.getElementById("limitMode").value = "unlimited";
            limitInput.value = "";
            limitInput.disabled = true;
            limitInput.placeholder = "Unlimited";
            clearError(limitInput, limitError);
        } else {
            document.getElementById("limitMode").value = "limited";
            limitInput.disabled = false;
            limitInput.placeholder = "";
        }
    });

    document.getElementById("maxUnlimited").addEventListener("change", function () {
        if (this.checked) {
            document.getElementById("maxMode").value = "unlimited";
            maxInput.value = "";
            maxInput.disabled = true;
            maxInput.placeholder = "Unlimited";
            clearError(maxInput, maxError);
        } else {
            document.getElementById("maxMode").value = "limited";
            maxInput.disabled = false;
            maxInput.placeholder = "";
        }
    });

    function validateAll() {
        let ok = true;

        if (isEmpty(nameInput.value)) {
            setError(nameInput, nameError, "Name required");
            ok = false;
        }

        if (isEmpty(codeInput.value)) {
            setError(codeInput, codeError, "Code required");
            ok = false;
        }

        if (!startInput.value) {
            setError(startInput, startError, "Start date required");
            ok = false;
        }

        if (typeSelect.value === "Public" && !expiryInput.value) {
            setError(expiryInput, expiryError, "Expiry required");
            ok = false;
        }

        if (disInput.value === "") {
            setError(disInput, disError, "Discount required");
            ok = false;
        }

        if (minInput.value === "") {
            setError(minInput, minError, "Min spend required");
            ok = false;
        }

        if (!limitInput.disabled && limitInput.value === "") {
            setError(limitInput, limitError, "Required");
            ok = false;
        }

        if (!maxInput.disabled && maxInput.value === "") {
            setError(maxInput, maxError, "Required");
            ok = false;
        }

        [nameError, codeError, startError, expiryError, disError, minError, limitError, maxError].forEach(el => {
            if (el && el.textContent.trim() !== "") ok = false;
        });

        return ok;
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();

        if (!validateAll()) {
            showToast("error", "Please fix the errors");
            return;
        }

        const formData = new FormData(form);

        fetch("add_voucher.php", {
            method: "POST",
            body: formData
        })
        .then(r => r.text())
        .then(data => {
            if (data.trim() === "ok") {
                showToast("success", "Voucher created successfully");
                setTimeout(() => { window.location.href = "manage_voucher.php"; }, 800);
            } else {
                showToast("error", "Failed: " + data.trim());
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });

    // Auto generate code
    document.getElementById("autoBtn").addEventListener("click", function () {
        codeInput.value = generateCode();
        codeInput.dispatchEvent(new Event("input"));
    });
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
    setTimeout(removeToast, type === "error" ? 6000 : 3000);
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