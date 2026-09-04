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
    $code = $_POST['code'] ?? '';
    if ($code === "") { echo "ok"; exit; }
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
        $start    = $voucher['START_DATE'];
        $discount = $voucher['DISCOUNT_RATE'];
        $minSpend = $voucher['MIN_SPEND'];
        $tierId   = $voucher['TIER_ID'];
        $perUser  = $voucher['PER_USER_LIMIT'];
        $maxUsage = $voucher['MAX_USAGE'];
        $limitMode= ($perUser == -1) ? 'unlimited' : 'limited';
        $maxMode  = ($maxUsage == -1) ? 'unlimited' : 'limited';
    } else {
        $start     = $_POST['startDate'] ?? '';
        $discount  = $_POST['discount'] ?? '';
        $minSpend  = $_POST['minSpend'] ?? '';
        $tierId    = $_POST['tier_id'] ?? '';
        $perUser   = $_POST['perUserLimit'] ?? '';
        $maxUsage  = $_POST['maxUsage'] ?? '';
        $limitMode = $_POST['limitMode'] ?? 'limited';
        $maxMode   = $_POST['maxMode'] ?? 'limited';
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
    } else {
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
        if ($discount === "" || !is_numeric($discount) || $discount < 0 || $discount > 100)
            $errors[] = "Invalid discount";

        if ($minSpend === "" || !is_numeric($minSpend) || $minSpend < 0)
            $errors[] = "Invalid min spend";

        if ($limitMode === "unlimited") {
            $perUser = -1;
        } elseif ($perUser === "" || filter_var($perUser, FILTER_VALIDATE_INT) === false || $perUser < 0) {
            $errors[] = "Invalid per user limit";
        }

        if ($maxMode === "unlimited") {
            $maxUsage = -1;
        } elseif ($maxUsage === "" || !is_numeric($maxUsage) || $maxUsage < 0) {
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

    $stmt = $conn->prepare("
        UPDATE voucher SET
            VOUCHER_NAME    = ?,
            VOUCHER_CODE    = ?,
            START_DATE      = ?,
            EXPIRY_DATE     = ?,
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
        "ssssddiissi",
        $name, $code, $start, $expiryVal,
        $discount, $minSpend, $perUser, $maxUsage,
        $tierId, $status, $voucherId
    );

    if (!$stmt->execute()) { echo "Update failed"; exit; }

    echo "ok";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
    <title>Edit Voucher</title>
<style>
.code-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.code-input-wrapper {
    display: flex;
    gap: 8px;
    align-items: center;
    margin-top: 6px;
}
.code-auto-btn {
    padding: 6px 10px;
    font-size: 12px;
    border: none;
    border-radius: 6px;
    background: #eee;
    cursor: pointer;
}
.code-auto-btn:hover { background: #ddd; }
.code-unlimited {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-top: 6px;
}
.form-input:disabled,
.form-input[readonly] {
    background: #f0f0f0;
    color: #aaa;
    cursor: not-allowed;
    opacity: 1;
}

.form-input:disabled::placeholder,
.form-input[readonly]::placeholder {
    color: #aaa !important;
    opacity: 1 !important; 
}
.form-note {
    padding: 10px 12px;
    margin-bottom: 15px;
    border-radius: 6px;
    font-size: 13px;
}
.expired-note {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
}
.started-note {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_voucher.php" class="form-back-link" title="Go Back To Voucher Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Edit Voucher</h2>

        <?php if ($isExpired): ?>
            <div class="form-note expired-note">⚠️ This voucher has expired. Only limited fields can be edited.</div>
        <?php elseif ($isStarted): ?>
            <div class="form-note started-note">ℹ️ This voucher has started. Some fields are locked.</div>
        <?php endif; ?>

        <form method="POST" id="form">

        <div class="form-group">
            <label>Type</label>
            <input type="text" class="form-input" value="<?= htmlspecialchars($voucherType) ?>" readonly>
            <input type="hidden" name="type" value="<?= htmlspecialchars($voucherType) ?>">
        </div>

        <div class="form-group">
            <label>Name<span class="asterisk">*</span></label>
            <input type="text" id="nameInput" name="name" value="<?= htmlspecialchars($voucher['VOUCHER_NAME']) ?>" class="form-input" maxlength="50">
            <span id="nameError" class="error"></span>
        </div>

        <div class="form-group">
            <div class="code-header">
                <label>Code<span class="asterisk">*</span></label>
                <button type="button" id="autoBtn" class="code-auto-btn">Auto</button>
            </div>
            <div class="code-input-wrapper">
                <input type="text" id="codeInput" name="code" value="<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?>" class="form-input" maxlength="50">
            </div>
            <span id="codeError" class="error"></span>
        </div>

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

        <div class="form-group">
            <label>Discount %<span class="asterisk">*</span></label>
            <input type="number" id="disInput" name="discount" value="<?= $voucher['DISCOUNT_RATE'] ?>" class="form-input" step="0.01" <?= $isStarted ? 'readonly' : '' ?>>
            <span id="disError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Min Spend<span class="asterisk">*</span></label>
            <input type="number" step="0.01" id="minInput" name="minSpend" value="<?= $voucher['MIN_SPEND'] ?>" class="form-input" <?= $isStarted ? 'readonly' : '' ?>>
            <span id="minError" class="error"></span>
        </div>

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
            <label>Max Usage<span class="asterisk">*</span></label>
            <input type="number" id="maxInput" name="maxUsage" value="<?= $voucher['MAX_USAGE'] == -1 ? '' : $voucher['MAX_USAGE'] ?>" class="form-input" step="1" <?= $isStarted ? 'readonly' : '' ?> <?= ($isStarted && $voucher['MAX_USAGE'] == -1) ? 'placeholder="Unlimited"' : '' ?>>
            <label class="code-unlimited">
                <input type="checkbox" id="maxUnlimited" <?= $voucher['MAX_USAGE'] == -1 ? 'checked' : '' ?> <?= $isStarted ? 'disabled' : '' ?>> Unlimited
            </label>
            <span id="maxError" class="error"></span>
            <input type="hidden" id="maxMode" name="maxMode" value="<?= $voucher['MAX_USAGE'] == -1 ? 'unlimited' : 'limited' ?>">
        </div>

        <?php if ($voucherType === 'Tier'): ?>
        <div class="form-group">
            <label>Available for Tier<span class="asterisk">*</span></label>
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

        <div class="form-group">
            <label>Status<span class="asterisk">*</span></label>
            <select name="status" class="form-input">
                <option value="Active"   <?= $voucher['VOUCHER_STATUS'] === 'Active'   ? 'selected' : '' ?>>Active</option>
                <option value="Inactive" <?= $voucher['VOUCHER_STATUS'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>

        <button type="submit" class="form-btn">Save Changes</button>

        </form>
    </div>
</div>

<script>
const voucherType    = <?= json_encode($voucherType) ?>;
const voucherStarted = <?= $isStarted ? 'true' : 'false' ?>;
const voucherExpired = <?= $isExpired ? 'true' : 'false' ?>;
const oldExpiryTime  = <?= ($voucherType === 'Public' && $voucher['EXPIRY_DATE']) ? strtotime($voucher['EXPIRY_DATE']) * 1000 : 'null' ?>;

document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form        = document.getElementById("form");

    // Inputs
    const nameInput   = document.getElementById("nameInput");
    const codeInput   = document.getElementById("codeInput");
    const startInput  = document.getElementById("startInput");
    const expiryInput = document.getElementById("expiryInput");
    const disInput    = document.getElementById("disInput");
    const minInput    = document.getElementById("minInput");
    const limitInput  = document.getElementById("limitInput");
    const maxInput    = document.getElementById("maxInput");

    // Errors
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

    // Show toast
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

    // Generate random code (format: VCH-XXXXXX)
    function generateCode() {
        const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
        let code = "VCH-";
        for (let i = 0; i < 6; i++) code += chars.charAt(Math.floor(Math.random() * chars.length));
        return code;
    }

    // Validation helpers
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

    // Unlimited toggles (init + events)
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

    // Real-time validations
    // Name validation
    nameInput.addEventListener("input", function () {
        isEmpty(this.value) ? setError(this, nameError, "Name required") : clearError(this, nameError);
    });

    // Code validation with AJAX
    function validateCode() {
        const code = codeInput.value.trim();
        if (code === "") { setError(codeInput, codeError, "Code required"); return; }
        fetch("", {
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

    // Date validation
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

        // Expiry validation only for Public vouchers
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
                }

                else if (newT < oldExpiryTime) {
                    setError(expiryInput, expiryError, "Cannot be earlier than old expiry");
                }

                else if (newT < nowT) {
                    setError(expiryInput, expiryError, "Must be a future date");
                }

                else {
                    clearError(expiryInput, expiryError);
                }
            } else {
                expiry < nowMin ? setError(expiryInput, expiryError, "Cannot be in the past") : clearError(expiryInput, expiryError);
            }
        }
    }

    // Date inputs
    startInput.addEventListener("change", validateDates);
    if (expiryInput) expiryInput.addEventListener("change", validateDates);

    // Discount validation
    disInput.addEventListener("input", function () {
        if (this.readOnly) return;
        const val = parseFloat(this.value);
        if (this.value === "")  
            setError(this, disError, "Required");
        else if (isNaN(val))    
            setError(this, disError, "Must be a number");
        else if (val < 0.01)    
            setError(this, disError, "Min 0.01%");
        else if (val > 100)     
            setError(this, disError, "Max 100%");
        else                    
            clearError(this, disError);
    });

    // Min spend validation
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
    });

    // Per user limit 
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

    // Max usage limit
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

    // Validate all
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

        // Check if any error messages are present
        const errors = [nameError, codeError, startError, expiryError, disError, minError, limitError, maxError];
        errors.forEach(el => {
            if (el && el.textContent.trim() !== "") ok = false;
        });

        return ok;
    }

    // Submit
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
                showToast("success", "Voucher updated");
                setTimeout(() => { window.location.href = "manage_voucher.php"; }, 800);
            } else {
                showToast("error", "Failed: " + data.trim());
            }
        });
    });

    // Auto generate code
    document.getElementById("autoBtn").addEventListener("click", function () {
        codeInput.value = generateCode();
        codeInput.dispatchEvent(new Event("input"));
    });

    // Init
    validateDates();
    validateCode();
});
</script>
</body>
</html>