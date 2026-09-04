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
    $stmt = $conn->prepare("SELECT VOUCHER_ID FROM voucher WHERE VOUCHER_CODE = ? AND IS_DELETED = 0");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_code'])) {

    $errors = [];

    $name      = trim($_POST['name'] ?? '');
    $code      = trim($_POST['code'] ?? '');
    $type      = $_POST['type'] ?? '';
    $start     = $_POST['startDate'] ?? '';
    $expiry    = $_POST['expiryDate'] ?? '';
    $discount  = $_POST['discount'] ?? '';
    $minSpend  = $_POST['minSpend'] ?? '';
    $perUser   = $_POST['perUserLimit'] ?? '';
    $maxUsage  = $_POST['maxUsage'] ?? '';
    $tierId    = $_POST['tier_id'] ?? '';
    $status    = $_POST['status'] ?? '';
    $limitMode = $_POST['limitMode'] ?? 'limited';
    $maxMode   = $_POST['maxMode'] ?? 'limited';

    $now = time();

    // Name
    if ($name === "") $errors[] = "Name required";

    // Code
    if ($code === "") {
        $errors[] = "Code required";
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

    // Discount
    if ($discount === "") {
        $errors[] = "Discount required";
    } elseif (!is_numeric($discount)) {
        $errors[] = "Discount must be a number";
    } elseif ($discount < 0.01) {
        $errors[] = "Discount must be at least 0.01%";
    } elseif ($discount > 100) {
        $errors[] = "Discount cannot exceed 100%";
    }

    // Min spend
    if ($minSpend === "") {
        $errors[] = "Min spend required";
    } elseif (!is_numeric($minSpend)) {
        $errors[] = "Min spend must be a number";
    } elseif ($minSpend < 0) {
        $errors[] = "Min spend cannot be negative";
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
        $tierId = null; // Public: tier_id is NULL
    }

    // Status
    if (!in_array($status, ["Active", "Inactive"])) $errors[] = "Invalid status";

    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Expiry: Tier = NULL, Public = value
    $expiryVal = ($type === "Tier") ? null : $expiry;

    // Insert voucher
    $stmt = $conn->prepare("
        INSERT INTO voucher (
            VOUCHER_NAME, VOUCHER_CODE, VOUCHER_TYPE,
            START_DATE, EXPIRY_DATE,
            DISCOUNT_RATE, MIN_SPEND,
            PER_USER_LIMIT, MAX_USAGE, USED_COUNT,
            TIER_ID, VOUCHER_STATUS, IS_DELETED
        ) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,'0')
    ");

    $stmt->bind_param(
        "sssssdiisis",
        $name, $code, $type,
        $start, $expiryVal,
        $discount, $minSpend,
        $perUser, $maxUsage,
        $tierId, $status
    );

    if (!$stmt->execute()) {
        echo "Insert failed";
        exit;
    }

    // Auto-distribution handled by MySQL Event Scheduler at start date (Only if Public type)

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
    <title>Add Voucher</title>
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
    white-space: nowrap;
}

.code-auto-btn:hover { background: #ddd; }
.code-unlimited {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-top: 6px;
}

.form-input:disabled {
    background: #f0f0f0;
    color: #aaa;
    cursor: not-allowed;
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_voucher.php" class="form-back-link" title="Go Back To Voucher Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Add Voucher</h2>

        <p style="margin-top:8px; margin-bottom:20px; font-size:13px; color:#666; line-height:1.5;">
            <strong>Tier:</strong> <small>No expiry date is required. Once claimed, it is valid for 3 months from the claim date.</small><br>
            <strong>Public:</strong> <small>Available to all users within the active date range.</small>
        </p>

        <form method="POST" enctype="multipart/form-data" id="form" novalidate>

        <div class="form-group">
            <label>Type<span class="asterisk">*</span></label>
            <select name="type" id="typeSelect" class="form-input">
                <option value="Tier">Tier</option>
                <option value="Public">Public</option>
            </select>
        </div>

        <div class="form-group">
            <label>Name<span class="asterisk">*</span></label>
            <input type="text" id="nameInput" name="name" class="form-input" maxlength="50">
            <span id="nameError" class="error"></span>
        </div>

        <div class="form-group">
            <div class="code-header">
                <label>Code<span class="asterisk">*</span></label>
                <button type="button" id="autoBtn" class="code-auto-btn" title="Auto Generate Voucher Code">Auto</button>
            </div>
            <div class="code-input-wrapper">
                <input type="text" id="codeInput" name="code" class="form-input" maxlength="50">
            </div>
            <span id="codeError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Start Date<span class="asterisk">*</span></label>
            <input type="datetime-local" id="startInput" name="startDate" class="form-input">
            <span id="startError" class="error"></span>
        </div>

        <!-- Expiry Date: only for Public -->
        <div class="form-group" id="expiryGroup">
            <label>Expiry Date<span class="asterisk">*</span></label>
            <input type="datetime-local" id="expiryInput" name="expiryDate" class="form-input">
            <span id="expiryError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Discount %<span class="asterisk">*</span></label>
            <input type="number" id="disInput" name="discount" class="form-input" step="0.01">
            <span id="disError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Min Spend<span class="asterisk">*</span></label>
            <input type="number" id="minInput" name="minSpend" class="form-input" step="0.01">
            <span id="minError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Per User Limit<span class="asterisk">*</span></label>
            <input type="number" id="limitInput" name="perUserLimit" class="form-input" step="1">
            <label class="code-unlimited"><input type="checkbox" id="limitUnlimited"> Unlimited</label>
            <span id="limitError" class="error"></span>
            <input type="hidden" id="limitMode" name="limitMode" value="limited">
        </div>

        <div class="form-group">
            <label>Max Usage<span class="asterisk">*</span></label>
            <input type="number" id="maxInput" name="maxUsage" class="form-input" step="1">
            <label class="code-unlimited"><input type="checkbox" id="maxUnlimited"> Unlimited</label>
            <span id="maxError" class="error"></span>
            <input type="hidden" id="maxMode" name="maxMode" value="limited">
        </div>

        <!-- Tier: only for Tier type -->
        <div class="form-group" id="tierGroup">
            <label>Available for Tier<span class="asterisk">*</span></label>
            <select name="tier_id" id="tierSelect" class="form-input">
            <?php
            $tiers->data_seek(0);
            while($t = $tiers->fetch_assoc()): ?>
                <option value="<?= $t['TIER_ID'] ?>"><?= htmlspecialchars($t['TIER_NAME']) ?></option>
            <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Status<span class="asterisk">*</span></label>
            <select name="status" class="form-input">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
        </div>

        <button type="submit" class="form-btn">Create</button>

        </form>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form        = document.getElementById("form");

    // Inputs
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
    const expiryGroup = document.getElementById("expiryGroup");
    const tierGroup   = document.getElementById("tierGroup");

    // Error message elements
    const nameError   = document.getElementById("nameError");
    const codeError   = document.getElementById("codeError");
    const startError  = document.getElementById("startError");
    const expiryError = document.getElementById("expiryError");
    const disError    = document.getElementById("disError");
    const minError    = document.getElementById("minError");
    const limitError  = document.getElementById("limitError");
    const maxError    = document.getElementById("maxError");

    // Helpers
    function setError(input, errorEl, msg) {
        errorEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errorEl) {
        errorEl.textContent = "";
        input.classList.remove("input-error");
    }
    function isEmpty(val) { return val.trim() === ""; }

    // Type toggle
    function applyTypeUI() {
        const type = typeSelect.value;

        if (type === "Tier") {
            expiryInput.disabled = true;
            expiryInput.value = "";
            expiryInput.style.background = "#f0f0f0";
            expiryInput.style.cursor = "not-allowed";
            clearError(expiryInput, expiryError);

            tierSelect.disabled = false;
            tierSelect.style.background = "";
            tierSelect.style.cursor = "";
            tierGroup.style.opacity = "1";

        } else {
            expiryInput.disabled = false;
            expiryInput.style.background = "";
            expiryInput.style.cursor = "";

            tierSelect.disabled = true;
            tierSelect.style.background = "#f0f0f0";
            tierSelect.style.cursor = "not-allowed";
            tierGroup.style.opacity = "0.5";
        }
    }

    typeSelect.addEventListener("change", applyTypeUI);
    applyTypeUI();

    // Real-time validations
    nameInput.addEventListener("input", function () {
        isEmpty(this.value)
            ? setError(this, nameError, "Name required")
            : clearError(this, nameError);
    });

    function validateCode() {
        const code = codeInput.value.trim();
        if (code === "") {
            setError(codeInput, codeError, "Code required");
            return;
        }
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

    // Discount validation
    disInput.addEventListener("input", function () {
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

    // Per user limit and max usage validation
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

    // Max usage validation
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
    // Per user limit toggle
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

    // Max usage toggle
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

    // Full validation before submit
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

        // Check any existing visible errors
        [nameError, codeError, startError, expiryError, disError, minError, limitError, maxError].forEach(el => {
            if (el && el.textContent.trim() !== "") ok = false;
        });

        return ok;
    }

    // Form submit
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
                showToast("success", "Voucher created");
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
});

// Show Toast
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

// Auto code generator (format: VCH-XXXXXX)
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