<?php
require_once("config.php");
session_start();

// Authentication Check (Super Admin) - only SA can access can Normal Admin
if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
}

$admin_id = $_SESSION['admin_id'];

$stmt = $conn->prepare("
    SELECT ADMIN_TYPE 
    FROM admin 
    WHERE ADMIN_ID = ? AND IS_DELETED = 0
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();

if (!$currentAdmin || $currentAdmin['ADMIN_TYPE'] !== 'Super Admin') {
    die("Access denied");
}

// Get admin id
$adminId = $_GET['admin_id'] ?? 0;

if (!$adminId || !is_numeric($adminId)) {
    die("Invalid Admin ID");
}

// Fetch Admin
$stmt = $conn->prepare("
    SELECT *
    FROM admin
    WHERE ADMIN_ID = ?
    AND IS_DELETED = 0
");

$stmt->bind_param("i", $adminId);
$stmt->execute();

$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    die("Admin not found");
}

// Reset admin password
if (isset($_POST['reset_password'])) {

    function generatePassword() {

        $upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $lower = "abcdefghijklmnopqrstuvwxyz";
        $number = "0123456789";
        $symbol = "!@#$%^&*()_-+=<>?";

        $password = "";

        // 1 of each type
        $password .= $upper[rand(0, strlen($upper)-1)];
        $password .= $lower[rand(0, strlen($lower)-1)];
        $password .= $number[rand(0, strlen($number)-1)];
        $password .= $symbol[rand(0, strlen($symbol)-1)];

        $all = $upper . $lower . $number . $symbol;

        // random extra chars (4~8)
        $extraLen = rand(4, 8);

        for ($i = 0; $i < $extraLen; $i++) {
            $password .= $all[rand(0, strlen($all)-1)];
        }

        return str_shuffle($password);
    }

    $tempPassword = generatePassword();

    // Hash password
    $hashed = password_hash($tempPassword, PASSWORD_DEFAULT);

    // Update admin password
    // If reset, that admin need to force change password again when next time login
    $stmt = $conn->prepare("
        UPDATE admin
        SET
            ADMIN_PASSWORD = ?,
            FORCE_PASSWORD_CHANGE = 1
        WHERE ADMIN_ID = ?
    ");

    $stmt->bind_param("si", $hashed, $adminId);

    if (!$stmt->execute()) {
        echo "failed";
        exit;
    }

    echo $tempPassword;
    exit;
}

// Update Admin process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_email'])) {

    // Get inputs
    $name   = trim($_POST['name'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $status = $_POST['status'] ?? '';

    $errors = [];

    // Name Required + Format + Length
    if ($name === '') {
        $errors[] = "Name is required";
    } elseif (strlen($name) > 50) {
        $errors[] = "Name cannot exceed 50 characters";
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) {
        $errors[] = "Name can only contain letters and spaces";
    }

    // Phone validation
    if ($phone !== '') {
        if (!preg_match('/^\+60[0-9]{9,10}$/', $phone)) {
            $errors[] = "Invalid phone number format. 9 or 10 digits";
        }
    }

    // Status validation
    if (!in_array($status, ['Active', 'Suspended'])) {
        $errors[] = "Invalid status";
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Final update admin
    $stmt = $conn->prepare("
        UPDATE admin
        SET
            ADMIN_NAME = ?,
            ADMIN_PHONE = ?,
            ADMIN_STATUS = ?,
            UPDATED_AT = NOW()
        WHERE ADMIN_ID = ?
    ");

    $stmt->bind_param(
        "sssi",
        $name,
        $phone,
        $status,
        $adminId
    );

    if (!$stmt->execute()) {
        echo "Update failed";
        exit;
    }

    echo "ok";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Admin</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
.phone-wrapper {
    display: flex;
    align-items: stretch;
    width: 100%;
}

.phone-prefix {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 12px;
    background: #f5f5f5;
    border: 1px solid #ccc;
    border-right: none;
    border-radius: 6px 0 0 6px;
    height: 42px;
    box-sizing: border-box;
}

.phone-input {
    flex: 1;
    border-radius: 0 6px 6px 0;
    height: 42px;
    box-sizing: border-box;
    border: 1px solid #ccc;
    margin: 0;
}

.secondary-btn {
    margin-top: 10px;
    background: #f5f5f5;
    color: #333;
}

.secondary-btn:hover {
    background: #e5e5e5;
}

.form-hint{
    display:block;
    margin-top:8px;
    margin-bottom:8px;
    font-size:12px;
    color:#777;
    line-height:1.4;
}

.readonly-input{
    background: #f3f3f3;
    cursor: not-allowed;
    opacity: 0.7;
}
</style>
</head>

<body>

<div class="form-wrapper">

    <a href="manage_admin.php" class="form-back-link" title="Go Back to Admin Page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Edit Admin</h2>

        <form method="POST" id="form">

            <!-- NAME -->
            <div class="form-group">
                <label>Name<span class="asterisk">*</span></label>
                <input type="text" id="nameInput" name="name" class="form-input" maxlength="50"
                    value="<?= htmlspecialchars($admin['ADMIN_NAME']) ?>"
                >
                <span id="nameError" class="error"></span>
            </div>

            <!-- EMAIL -->
            <div class="form-group">
                <label>Email<span class="asterisk">*</span></label>
                <input type="email" id="emailInput" class="form-input readonly-input" readonly
                    value="<?= htmlspecialchars($admin['ADMIN_EMAIL']) ?>"
                >
            </div>

            <!-- PHONE -->
            <div class="form-group">
                <label>Phone</label>
                <div class="phone-wrapper">
                    <span class="phone-prefix">+60</span>

                    <input type="text" id="phoneInput" name="phone_display" class="form-input phone-input"
                        value="<?= !empty($admin['ADMIN_PHONE']) ? htmlspecialchars(substr($admin['ADMIN_PHONE'], 3)) : '' ?>"
                    >
                </div>
                <span id="phoneError" class="error"></span>
            </div>

            <!-- STATUS -->
            <div class="form-group">
                <label>Status<span class="asterisk">*</span></label>
                <select name="status" id="statusInput" class="form-input">
                    <option value="Active"
                        <?= $admin['ADMIN_STATUS'] === 'Active' ? 'selected' : '' ?>>
                        Active
                    </option>

                    <option value="Suspended"
                        <?= $admin['ADMIN_STATUS'] === 'Suspended' ? 'selected' : '' ?>>
                        Suspended
                    </option>
                </select>
                <span id="statusError" class="error"></span>
            </div>

            <button type="submit" class="form-btn">
                Save Changes
            </button>

            <div><br><hr><br></div>

            <button type="button" id="resetPwdBtn" class="form-btn secondary-btn">
                Reset Password
            </button>
            <small class="form-hint">
                Use this if the admin forgot their password or account access may be compromised.
            </small>

        </form>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form = document.getElementById("form");

    // Inputs
    const nameInput = document.getElementById("nameInput");
    const phoneInput = document.getElementById("phoneInput");
    const resetPwdBtn = document.getElementById("resetPwdBtn");

    // Error message elements
    const nameError = document.getElementById("nameError");
    const phoneError = document.getElementById("phoneError");

    // Reset password process 
    resetPwdBtn.addEventListener("click", function () {

        if (!confirm("Reset this admin password?")) {
            return;
        }

        const formData = new FormData();
        formData.append("reset_password", "1");

        fetch("edit_admin.php?admin_id=<?= $adminId ?>", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "failed") {
                showToast("error", "Reset failed");
            } else {
                alert(
                    "Temporary Password:\n\n" + data +
                    "\n\nGive this password to the admin."
                );

                showToast("success", "Password reset successful");
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });

    // Error handling functions
    function setError(input, errEl, msg) {
        errEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errEl) {
        errEl.textContent = "";
        input.classList.remove("input-error");
    }

    // Real-time validation for Name
    nameInput.addEventListener("input", function () {

        const val = this.value;

        if (val.trim() === "") {
            setError(this, nameError, "Name required");
        }
        else if (val.length > 50) {
            setError(this, nameError, "Name cannot exceed 50 characters");
        }
        else if (!/^[a-zA-Z\s]+$/.test(val)) {
            setError(this, nameError, "Name can only contain letters and spaces");
        }
        else {
            clearError(this, nameError);
        }
    });

    // Real-time validation for Phone
    phoneInput.addEventListener("input", function () {

        const val = this.value.trim();

        if (val === "") {
            clearError(this, phoneError);
            return;
        }

        if (!/^\d{9,10}$/.test(val)) {
            setError(this, phoneError, "Enter 9-10 digits");
        } else {
            clearError(this, phoneError);
        }
    });

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        let ok = true;

        const name = nameInput.value.trim();
        if (name === "") {
            setError(nameInput, nameError, "Name required");
            ok = false;
        } else if (name.length > 50) {
            setError(nameInput, nameError, "Name cannot exceed 50 characters");
            ok = false;
        } else if (!/^[a-zA-Z\s]+$/.test(name)) {
            setError(nameInput, nameError, "Name can only contain letters and spaces");
            ok = false;
        }

        const phone = phoneInput.value.trim();
        if (phone !== '' && !/^\d{9,10}$/.test(phone)) {
            setError(phoneInput, phoneError, "Enter 9-10 digits");
            ok = false;
        }

        if (!ok) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        // Store +60.. (phone)
        if (phone !== '') {
            formData.append("phone", "+60" + phone);
        } else {
            formData.append("phone", "");
        }

        fetch("edit_admin.php?admin_id=<?= $adminId ?>", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {

                showToast("success", "Admin updated");

                setTimeout(() => {
                    window.location.href = "manage_admin.php";
                }, 800);

            } else {

                showToast("error", data);
            }
        })
        .catch(() => {
            showToast("error", "Network error");
        });
    });

});

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

    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation(); 
        removeToast();
    });

    toast.addEventListener("click", removeToast);

    function removeToast() {
        if (removed) return;
        removed = true;

        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";

        setTimeout(() => toast.remove(), 300);
    }

    const duration = type === "error" ? 8000 : 3000;

    setTimeout(removeToast, duration);
}
</script>

</body>
</html>