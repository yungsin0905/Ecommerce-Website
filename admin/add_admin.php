<?php
require_once("config.php");
session_start();

// email
require 'include/vendor/Exception.php';
require 'include/vendor/PHPMailer.php';
require 'include/vendor/SMTP.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// BAKERY INFO 
$bakery = $conn->query("SELECT * FROM bakery_info LIMIT 1")->fetch_assoc();

// Authentication Check (Super Admin) - only SA can access can Normal Admin
if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized");
}

$admin_id = $_SESSION['admin_id'];

$stmt = $conn->prepare("
    SELECT ADMIN_TYPE 
    FROM admin 
    WHERE ADMIN_ID = ? 
    AND IS_DELETED = 0
");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$currentAdmin = $stmt->get_result()->fetch_assoc();

if (!$currentAdmin || $currentAdmin['ADMIN_TYPE'] !== 'Super Admin') {
    die("Access denied");
}

// AJAX: Check duplicate email - frontend validation - used for real-time validation on email input
// Cannot add admin with same email
if (isset($_POST['ajax_check_email'])) {

    $email = trim($_POST['email'] ?? '');

    $stmt = $conn->prepare("
        SELECT ADMIN_ID 
        FROM admin 
        WHERE ADMIN_EMAIL = ? 
        AND IS_DELETED = 0
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();

    echo ($stmt->get_result()->num_rows > 0) ? "exists" : "ok";
    exit;
}

// Insert admin process or preparation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_check_email'])) {

    // Get inputs
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $errors = [];

    // Name Required + Format + Length
    if ($name === '') {
        $errors[] = "Name is required";
    } elseif (strlen($name) > 50) {
        $errors[] = "Name cannot exceed 50 characters";
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $name)) {
        $errors[] = "Name can only contain letters and spaces";
    }

    // Email Required + Format + Email Duplicate
    if ($email === '') {
        $errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    } else {
        $stmt = $conn->prepare("
            SELECT ADMIN_ID 
            FROM admin 
            WHERE ADMIN_EMAIL = ? AND IS_DELETED = 0
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = "Email already exists";
        }
    }

    // Password Required + Strength
    if ($password === '') {
        $errors[] = "Password is required";

    } else {
        // Password rule
        if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_\-+=<>?]).{8,12}$/', $password)) {
            $errors[] = "Password must be 8–12 chars with atleast one uppercase letter(A-Z), one lowercase letter(a-z), one digit(0-9), and one special character(!@#$%^&*()_\-+=<>?).";
        }
    }

    // Phone validation
    if ($phone !== '') {
        if (!preg_match('/^\+60[0-9]{9,10}$/', $phone)) {
            $errors[] = "Invalid phone number format. 9 or 10 digits";
        }
    }

    // Stop if errors
    if (!empty($errors)) {
        echo implode(" | ", $errors);
        exit;
    }

    // Final insert admin
    // Hash the password before storing
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        INSERT INTO admin 
        (ADMIN_NAME, ADMIN_EMAIL, ADMIN_PHONE, ADMIN_PASSWORD, ADMIN_TYPE, ADMIN_STATUS, CREATED_AT, IS_DELETED, FORCE_PASSWORD_CHANGE)
        VALUES (?, ?, ?, ?, 'Admin', 'Active', CURRENT_TIMESTAMP(), 0, 1)
    ");

    $stmt->bind_param(
        "ssss",
        $name,
        $email,
        $phone,
        $hashedPassword
    );

    if (!$stmt->execute()) {
        echo "Insert failed";
        exit;
    }

    // SEND EMAIL
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; 
        $mail->SMTPAuth = true;
        $mail->Username = 'wongyungsin04@gmail.com';
        $mail->Password = 'jfim afvt zusc vqwg';
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom('wongyungsin04@gmail.com', 'Admin System - ' . $bakery['SHOP_NAME']);
        $mail->addAddress($email, $name);

        $mail->isHTML(true);
        $mail->Subject = "Your Admin Account Created";

        $mail->Body = "
            <h3>Welcome $name</h3>
            <p>Your admin account has been created.</p>
            <p><b>Email:</b> $email</p>
            <p><b>Temporary Password:</b> $password</p>
            <p>Please login and change your password immediately.</p>
            <p>
                👉 <a href='http://localhost/TWPLAB/AdminModuleFYP/admin_login.php'>
                    Admin Login
                </a>
            </p>            
        ";

        $mail->send();

    } catch (Exception $e) {
        error_log("Email send failed: " . $e->getMessage());
    }
    // END EMAIL

    echo "ok";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_add_edit_form.css">
<style>
/* Password */
.password-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
}

.auto-btn {
    padding: 6px 10px;
    font-size: 12px;
    border: none;
    border-radius: 6px;
    background: #eee;
    cursor: pointer;
}

.auto-btn:hover {
    background: #ddd;
}

/* Phone */
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
    font-size: 14px;
    height: 42px; 
    box-sizing: border-box;
}

.phone-input {
    flex: 1;
    border-radius: 0 6px 6px 0;
    height: 42px; 
    box-sizing: border-box;
    border: 1px solid #ccc;
    outline: none; 
    margin: 0; 
}

/* Hints */
.hints {
    font-size: 10px;
    color: #777;
    padding-left: 15px;
    margin-top: -8px;
}
</style>
</head>

<body>
<div class="form-wrapper">

    <a href="manage_admin.php" class="form-back-link" title="Go back to Manage Admin page">← Back</a>

    <div class="form-container">

        <h2 class="form-title">Add Admin</h2>

        <form method="POST" enctype="multipart/form-data" id="form">

        <div class="form-group">
            <label>Name:<span class="asterisk">*</span></label>
            <input type="text" id="nameInput" name="name" class="form-input" maxlength="50">
            <span id="nameError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Email:<span class="asterisk">*</span></label>
            <input type="email" name="email" id="emailInput" class="form-input">
            <span id="emailError" class="error"></span>
        </div>

        <div class="form-group">
            <label>Phone:</label>

            <div class="phone-wrapper">
                <span class="phone-prefix">+60</span>
                <input type="text" id="phoneInput" name="phone" class="form-input phone-input">
            </div>

            <span id="phoneError" class="error"></span>
        </div>

        <div class="form-group">
            <div class="password-header">
                <label>Temporary Password:<span class="asterisk">*</span></label>
                <div>
                    <button type="button" id="autoPwdBtn" class="auto-btn" title="Auto generate password">Auto</button>
                    <button type="button" class="auto-btn" title="Show / Hide password">
                        <i class="bi bi-eye-slash" id="togglePassword"></i>
                    </button>
                </div>
            </div>
            <input type="password" name="password" id="pswdInput" class="form-input">
            <span id="pswdError" class="error"></span>

            <ul class="hints">
                <li>8 - 12 characters</li>
                <li>At least 1 Uppercase + lowercase</li>
                <li>At least 1 number + symbol</li>
            </ul>
        </div>

        <button type="submit" class="form-btn">Create</button>

        </form>

    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    // Form
    const form = document.getElementById("form");

    // Inputs
    const nameInput = document.getElementById("nameInput");
    const emailInput = document.getElementById("emailInput");
    const phoneInput = document.getElementById("phoneInput");
    const pswdInput = document.getElementById("pswdInput");

    // Error message elements
    const nameError = document.getElementById("nameError");
    const emailError = document.getElementById("emailError");
    const phoneError = document.getElementById("phoneError");
    const pswdError = document.getElementById("pswdError");

    const autoPwdBtn = document.getElementById("autoPwdBtn");
    const toggleIcon = document.getElementById("togglePassword");

    // Error handling functions
    function setError(input, errEl, msg) {
        errEl.textContent = msg;
        input.classList.add("input-error");
    }
    function clearError(input, errEl) {
        errEl.textContent = "";
        input.classList.remove("input-error");
    }
    function isEmpty(val) {
        return val.trim() === "";
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

    // Real-time validation for Email with AJAX check
    function validateEmailFormat(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function validateEmail() {
        const email = emailInput.value.trim();

        if (email === "") {
            setError(emailInput, emailError, "Email required");
            return;
        }

        if (!validateEmailFormat(email)) {
            setError(emailInput, emailError, "Invalid email format");
            return;
        }

        // Clear previous error before AJAX check
        clearError(emailInput, emailError);

        fetch("", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: "ajax_check_email=1&email=" + encodeURIComponent(email)
        })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === "exists") {
                setError(emailInput, emailError, "Email already exists");
            } else {
                clearError(emailInput, emailError);
            }
        })
        .catch(err => console.error("Error:", err));
    }
    emailInput.addEventListener("input", validateEmail);

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

    // Real-time validation for Password
    function validatePassword(val) {

        // Password rule
        if (val.length < 8) return "Min 8 characters";
        if (val.length > 12) return "Max 12 characters";
        if (!/[A-Z]/.test(val)) return "Need 1 uppercase";
        if (!/[a-z]/.test(val)) return "Need 1 lowercase";
        if (!/[0-9]/.test(val)) return "Need 1 number";
        if (!/[!@#$%^&*()_\-+=<>?]/.test(val)) return "Need 1 symbol(!@#$%^&*()_\-+=<>?)";

        return null;
    }
    pswdInput.addEventListener("input", function () {

        const msg = validatePassword(this.value);

        if (this.value === "") {
            setError(this, pswdError, "Password required");
        } else if (msg) {
            setError(this, pswdError, msg);
        } else {
            clearError(this, pswdError);
        }
    });

    // Auto generate password button
    autoPwdBtn.addEventListener("click", function () {
        pswdInput.value = generatePassword();
        pswdInput.dispatchEvent(new Event("input"));
    });

    // Auto generate password function
    function generatePassword() {

        const upper = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        const lower = "abcdefghijklmnopqrstuvwxyz";
        const numbers = "0123456789";
        const symbols = "!@#$%^&*()_-+=<>?";

        let pwd = "";

        // 1 of each type
        pwd += upper[Math.floor(Math.random() * upper.length)];
        pwd += lower[Math.floor(Math.random() * lower.length)];
        pwd += numbers[Math.floor(Math.random() * numbers.length)];
        pwd += symbols[Math.floor(Math.random() * symbols.length)];

        const all = upper + lower + numbers + symbols;

        // random length between 4 to 8 more chars
        const extraLen = Math.floor(Math.random() * 5) + 4; 
        // total = 8 to 12

        for (let i = 0; i < extraLen; i++) {
            pwd += all[Math.floor(Math.random() * all.length)];
        }

        return pwd.split('').sort(() => Math.random() - 0.5).join('');
    }

    // Show / Hide password
    toggleIcon.addEventListener("click", function () {

        if (pswdInput.type === "password") {
            pswdInput.type = "text";
            this.classList.remove("bi-eye-slash");
            this.classList.add("bi-eye");
        } else {
            pswdInput.type = "password";
            this.classList.remove("bi-eye");
            this.classList.add("bi-eye-slash");
        }
    });

    // Final validation before submit
    function validateAll() {

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

        const email = emailInput.value.trim();
        if (email === "") {
            setError(emailInput, emailError, "Email required");
            ok = false;
        } else if (!validateEmailFormat(email)) {
            setError(emailInput, emailError, "Invalid email");
            ok = false;
        }

        const phone = phoneInput.value.trim();
        if (phone !== '' && !/^\d{9,10}$/.test(phone)) {
            setError(phoneInput, phoneError, "Enter 9-10 digits");
            ok = false;
        }

        const pwdMsg = validatePassword(pswdInput.value);
        if (pswdInput.value === "") {
            setError(pswdInput, pswdError, "Password required");
            ok = false;
        } else if (pwdMsg) {
            setError(pswdInput, pswdError, pwdMsg);
            ok = false;
        }

        return ok;
    }

    // Form submit
    form.addEventListener("submit", function (e) {

        e.preventDefault();

        // Stop submit if validation fail
        if (!validateAll()) {
            showToast("error", "Please fix the errors");
            return;
        }

        // If all validations pass, submit form data via AJAX
        const formData = new FormData(form);

        // Store +60.. (phone)
        const phone = phoneInput.value.trim();
        if (phone !== "") {
            formData.set("phone", "+60" + phone);
        }

        fetch("", {
            method: "POST",
            body: formData
        })
        .then(res => res.text())
        .then(data => {

            if (data.trim() === "ok") {
                showToast("success", "Admin created");

                setTimeout(() => {
                    window.location.href = "manage_admin.php";
                }, 800);

            } else {
                showToast("error", "Failed");
            }
        });
    });

});

// Show toast 
function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    // Message text
    const text = document.createElement("span");
    text.innerText = message;

    // Close button
    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);

    document.body.appendChild(toast);

    let removed = false;

    // Close button event listener
    closeBtn.addEventListener("click", (e) => {
        e.stopPropagation(); 
        removeToast();
    });

    // Click toast to remove
    toast.addEventListener("click", removeToast);

    function removeToast() {
        if (removed) return;
        removed = true;

        toast.style.opacity = "0";
        toast.style.transform = "translateX(100%)";

        setTimeout(() => toast.remove(), 300);
    }

    // Auto-remove after duration (8s for error, 3s for success)
    const duration = type === "error" ? 8000 : 3000;

    setTimeout(removeToast, duration);
}
</script>

</body>
</html>