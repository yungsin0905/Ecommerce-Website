<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

//page title
$pageTitle = "Admin Profile";

// Admin info
$stmt = $conn->prepare("SELECT * FROM admin WHERE ADMIN_ID = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();

// Error & success message
$errors = $_SESSION['errors'] ?? [];
$successes = $_SESSION['successes'] ?? [];
$old = $_SESSION['old'] ?? []; 
unset($_SESSION['errors'], $_SESSION['successes'], $_SESSION['old']);

// Get values for html
$admin_name = $old['admin_name'] ?? ($admin['ADMIN_NAME'] ?? '');
$admin_email = $admin['ADMIN_EMAIL'] ?? '';
$admin_phone = $old['admin_phone'] ?? ($admin['ADMIN_PHONE'] ?? '');
$admin_image = $admin['ADMIN_IMAGE'] ?? '';
$admin_role = $admin['ADMIN_TYPE'] ?? '';

// Information(phone, name, image) update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_prof_info'])) {

    // Get inputs
    $input_name = trim($_POST['admin_name'] ?? '');
    $input_phone = trim($_POST['admin_phone'] ?? '');

    // Auto add +60 before save
    if ($input_phone !== '') {
        $input_phone = '+60' . $input_phone;
    }
    
    $_SESSION['old']['admin_name'] = $input_name;
    $_SESSION['old']['admin_phone'] = $input_phone;

    // Name validation
    if ($input_name === '') {
        $_SESSION['errors']['nameErr'] = "Name is required";
    } elseif (strlen($input_name) > 50) {
        $_SESSION['errors']['nameErr'] = "Name cannot exceed 50 characters";
    } elseif (!preg_match("/^[a-zA-Z\s]+$/", $input_name)) {
        $_SESSION['errors']['nameErr'] = "Name can only contain letters and spaces";
    }

    // Phone validation
    if (!empty($input_phone) && !preg_match('/^\+60[0-9]{9,10}$/', $input_phone)) {
        $_SESSION['errors']['phoneErr'] = "Invalid phone number format. 9 or 10 digits";
    }

    // Image upload validation
    $image_path = $admin['ADMIN_IMAGE'];
    if (isset($_FILES['admin_image']) && $_FILES['admin_image']['error'] !== UPLOAD_ERR_NO_FILE) {

        $fileTmpPath = $_FILES['admin_image']['tmp_name'];
        $fileName = $_FILES['admin_image']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array($fileExtension, $allowedExtensions)) {
            $_SESSION['errors']['imageErr'] = "Invalid image format. Allowed: jpg, jpeg, png, gif.";
        } else {
            $uploadDir = __DIR__ . '/uploads/adminProf/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            if (move_uploaded_file($fileTmpPath, $uploadDir . $newFileName)) {
                $image_path = 'uploads/adminProf/' . $newFileName;
            } 
        }
    }

    // If no errors, update the database
    if (empty($_SESSION['errors'])) {
        $update_stmt = $conn->prepare("UPDATE admin SET ADMIN_NAME = ?, ADMIN_PHONE = ?, ADMIN_IMAGE = ? WHERE ADMIN_ID = ?");
        $update_stmt->bind_param("sssi", $input_name, $input_phone, $image_path, $admin_id);
        
        if ($update_stmt->execute()) {
            $_SESSION['successes']['updateProfInfoSucc'] = "Profile updated successfully.";
            unset($_SESSION['old']); 
        } else {
            $_SESSION['errors']['updateProfInfoErr'] = "Failed to update profile.";
        }
    }
    header("Location: admin_profile.php"); 
    exit;
}

// Password update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_prof_pswd'])) {
    $current_pswd = $_POST['current_pswd'] ?? '';
    $new_pswd = $_POST['new_pswd'] ?? '';
    $confirm_pswd = $_POST['confirm_pswd'] ?? '';

    // current password validation
    if ($current_pswd === '') {
        $_SESSION['errors']['currentPswdErr'] = "Current password is required";
    } elseif (!password_verify($current_pswd, $admin['ADMIN_PASSWORD'])) {
        $_SESSION['errors']['currentPswdErr'] = "Current password is incorrect";
    }

    // new password validation
    if ($new_pswd === '') { 
        $_SESSION['errors']['newPswdErr'] = "New password is required"; 
    // Password rule
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_\-+=<>?]).{8,12}$/', $new_pswd)) {
        $_SESSION['errors']['newPswdErr'] = "Password must be 8–12 chars with at least one uppercase letter (A-Z), one lowercase letter (a-z), one digit (0-9), and one special character.";
    } elseif (password_verify($new_pswd, $admin['ADMIN_PASSWORD'])) {
        $_SESSION['errors']['newPswdErr'] = "New password cannot be same as current.";
    } 

    // confirm password validation
    if ($confirm_pswd === '') { 
        $_SESSION['errors']['confirmPswdErr'] = "Please confirm your new password";
    } elseif ($new_pswd !== $confirm_pswd) {
        $_SESSION['errors']['confirmPswdErr'] = "Passwords do not match";
    }

    // If no errors, update the password in the database
    if (empty($_SESSION['errors'])) {
        $hashed = password_hash($new_pswd, PASSWORD_DEFAULT);
        $pswd_stmt = $conn->prepare("UPDATE admin SET ADMIN_PASSWORD = ? WHERE ADMIN_ID = ?");
        $pswd_stmt->bind_param("si", $hashed, $admin_id);
        
        if ($pswd_stmt->execute()) {
            $_SESSION['successes']['updateProfPswdSucc'] = "Password updated successfully.";
        } else {
            $_SESSION['errors']['updateProfPswdErr'] = "Failed to update password.";
        }
    }
    header("Location: admin_profile.php"); 
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
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

.main {
    padding: 24px 30px 60px !important;
}

.prof-main-cont {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

/* Page Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
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

/* Grid Layout for Cards */
.prof-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    align-items: flex-start;
}

@media (max-width: 960px) {
    .prof-grid {
        grid-template-columns: 1fr;
    }
}

/* Card Box */
.prof-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 28px 32px;
    box-shadow: var(--shadow-soft);
    position: relative;
}

.prof-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 22px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-subtle);
}

.prof-card-title i {
    color: var(--text-dark);
    font-size: 16px;
}

.prof-card-title .card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-muted);
    margin-left: auto;
}

/* Avatar Section */
.avatar-wrap {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 24px;
    padding: 16px 20px;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    border-radius: 16px;
}

.prof-info-pic {
    width: 84px;
    height: 84px;
    border-radius: var(--radius-pill);
    object-fit: cover;
    border: 3px solid #ffffff;
    box-shadow: 0 4px 14px rgba(17, 24, 39, 0.08);
    background: #ffffff;
    flex-shrink: 0;
}

.avatar-actions {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.prof-change-pic-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    font-size: 12.5px;
    font-weight: 700;
    border: 1px solid var(--border-card);
    border-radius: var(--radius-pill);
    background: #ffffff;
    color: var(--text-dark);
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: var(--shadow-soft);
    width: fit-content;
}

.prof-change-pic-btn:hover {
    background: var(--dark-pill);
    color: #ffffff;
    border-color: var(--dark-pill);
}

.avatar-hint {
    font-size: 11.5px;
    color: var(--text-muted);
}

/* Form Controls */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 18px;
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

/* Phone input group */
.phone-wrapper {
    display: flex;
    align-items: stretch;
    width: 100%;
}

.phone-prefix {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    background: #e5e7eb;
    border: 1px solid var(--border-card);
    border-right: none;
    border-radius: 12px 0 0 12px;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--text-dark);
}

.phone-input {
    flex: 1;
    border-radius: 0 12px 12px 0 !important;
}

/* Security Note */
.security-note {
    background: #f8fafc;
    border: 1px solid var(--border-card);
    border-radius: 14px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 12.5px;
    color: var(--text-muted);
    line-height: 1.5;
}

.security-note ul {
    margin: 6px 0 0;
    padding-left: 18px;
}

.security-note li {
    margin-bottom: 2px;
}

.error {
    color: #ef4444;
    font-size: 12px;
    margin-top: 4px;
    font-weight: 500;
    display: block;
}

/* Actions */
.form-actions {
    margin-top: 24px;
    display: flex;
    justify-content: flex-end;
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
    width: 100%;
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
    opacity: 0;
    transform: translateY(-10px) scale(0.95);
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    pointer-events: none;
}

.toast.show {
    opacity: 1;
    transform: translateY(0) scale(1);
    pointer-events: auto;
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
</style>
</head>

<body>
<div class="global-layout"> 
    <?php include "global_layout_ctrl.php"; ?>
    <div class="main">

        <div class="prof-main-cont">

            <!-- Page Header -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-person-badge"></i>
                        <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
                    </div>
                    <div>
                        <h1 class="dash-title"><strong>Admin Profile</strong> Manage your account information and credentials</h1>
                    </div>
                </div>
            </div>

            <!-- Profile Grid -->
            <div class="prof-grid">

                <!-- CARD 1: PERSONAL DETAILS -->
                <div class="prof-card">
                    <div class="prof-card-title">
                        <i class="bi bi-person"></i> Personal Details
                        <span class="card-badge">Profile</span>
                    </div>

                    <form method="post" enctype="multipart/form-data">

                        <div class="avatar-wrap">
                            <img src="<?= !empty($admin_image) ? htmlspecialchars($admin_image) : 'images/default_avatar.png' ?>" alt="Profile Picture" class="prof-info-pic">
                            <div class="avatar-actions">
                                <button type="button" class="prof-change-pic-btn" onclick="document.getElementById('imageInput').click()">
                                    <i class="bi bi-camera"></i> Change Photo
                                </button>
                                <input type="file" name="admin_image" style="display: none;" id="imageInput" accept="image/jpeg,image/png,image/gif">
                                <span class="avatar-hint">JPG, PNG, or GIF format</span>
                                <span class="error" id="imageErr"><?= $errors['imageErr'] ?? '' ?></span>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Name<span class="asterisk">*</span></label>
                            <input type="text" name="admin_name" id="nameInput" class="form-input" value="<?= htmlspecialchars($admin_name) ?>" oninput="validateName()" maxlength="50">
                            <span class="error" id="nameErr"><?= $errors['nameErr'] ?? '' ?></span>
                        </div>

                        <div class="form-group">
                            <label>Role</label>
                            <input type="text" id="roleInput" class="form-input" value="<?= htmlspecialchars($admin_role) ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" id="emailInput" class="form-input" value="<?= htmlspecialchars($admin_email) ?>" disabled>
                        </div>

                        <div class="form-group">
                            <label>Phone Number</label>
                            <div class="phone-wrapper">
                                <span class="phone-prefix">+60</span>
                                <input type="text" name="admin_phone" id="phoneInput" class="form-input phone-input" placeholder="123456789" value="<?= htmlspecialchars(preg_replace('/^\+60/', '', $admin_phone)) ?>" oninput="validatePhone()">
                            </div>
                            <span class="error" id="phoneErr"><?= $errors['phoneErr'] ?? '' ?></span>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_prof_info" class="form-btn">
                                <i class="bi bi-check2"></i> Update Profile
                            </button>
                        </div>

                    </form>
                </div>

                <!-- CARD 2: CHANGE PASSWORD -->
                <div class="prof-card">
                    <div class="prof-card-title">
                        <i class="bi bi-shield-lock"></i> Security & Password
                        <span class="card-badge">Credentials</span>
                    </div>

                    <div class="security-note">
                        <strong>Password Requirements:</strong>
                        <ul>
                            <li>8 to 12 characters in length</li>
                            <li>At least 1 uppercase (A-Z) & 1 lowercase (a-z)</li>
                            <li>At least 1 digit (0-9) & 1 special character (!@#$%^&*...)</li>
                        </ul>
                    </div>

                    <form method="post">

                        <div class="form-group">
                            <label>Current Password<span class="asterisk">*</span></label>
                            <input type="password" name="current_pswd" id="currentPswd" class="form-input" placeholder="Enter current password" oninput="validatePassword()">
                            <span class="error" id="currentPswdErr"><?= $errors['currentPswdErr'] ?? '' ?></span>
                        </div>

                        <div class="form-group">
                            <label>New Password<span class="asterisk">*</span></label>
                            <input type="password" name="new_pswd" id="newPswd" class="form-input" placeholder="Enter new secure password" oninput="validatePassword()">
                            <span class="error" id="newPswdErr"><?= $errors['newPswdErr'] ?? '' ?></span>
                        </div>

                        <div class="form-group">
                            <label>Confirm New Password<span class="asterisk">*</span></label>
                            <input type="password" name="confirm_pswd" id="confirmPswd" class="form-input" placeholder="Re-enter new password" oninput="validatePassword()">
                            <span class="error" id="confirmPswdErr"><?= $errors['confirmPswdErr'] ?? '' ?></span>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="update_prof_pswd" class="form-btn">
                                <i class="bi bi-shield-check"></i> Update Password
                            </button>
                        </div>

                    </form>
                </div>

            </div>

        </div>

    </div>
</div>

<div id="toast" class="toast"></div>

<script>
// Image preview on file selection
document.getElementById('imageInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            document.querySelector('.prof-info-pic').src = event.target.result;
        };
        reader.readAsDataURL(file);
    }
});

// Real time Name validation
function validateName() {
    const name = document.getElementById("nameInput").value;
    const err = document.getElementById("nameErr");

    if (name.trim() === "") {
        err.innerText = "Name is required";
    } else if (name.length > 50) {
        err.innerText = "Name cannot exceed 50 characters";
    } else if (!/^[a-zA-Z\s]+$/.test(name)) {
        err.innerText = "Name can only contain letters and spaces";
    } else {
        err.innerText = "";
    }
}

// Real time Phone validation
function validatePhone() {
    const phone = document.getElementById("phoneInput").value.trim();
    const err = document.getElementById("phoneErr");

    if (phone.trim() === "") {
        err.innerText = "";
        return;
    }

    const regex = /^[0-9]{9,10}$/; 
    if (!regex.test(phone)) {
        err.innerText = "Enter 9-10 digits";
    } else {
        err.innerText = "";
    }
}

// Realtime Password validation
function validatePassword() {
    const current = document.getElementById("currentPswd").value;
    const newPwd = document.getElementById("newPswd").value;
    const confirm = document.getElementById("confirmPswd").value;

    const currentErr = document.getElementById("currentPswdErr");
    const newErr = document.getElementById("newPswdErr");
    const confirmErr = document.getElementById("confirmPswdErr");

    if (current === "") {
        currentErr.innerText = "Current password is required";
    } else {
        currentErr.innerText = "";
    }

    const strongRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_\-+=<>?]).{8,12}$/;

    if (newPwd === "") {
        newErr.innerText = "";
    } else if (!strongRegex.test(newPwd)) {
        newErr.innerText = "Password must be 8–12 chars with at least one uppercase, one lowercase, one digit, and one special character.";
    } else if (current !== "" && newPwd === current) {
        newErr.innerText = "New password cannot be same as current";
    } else {
        newErr.innerText = "";
    }

    if (confirm === "") {
        confirmErr.innerText = "";
    } else if (newPwd !== confirm) {
        confirmErr.innerText = "Passwords do not match";
    } else {
        confirmErr.innerText = "";
    }
}

// Show toast
function showToast(message, type = "success") {
    const toast = document.getElementById("toast");
    toast.textContent = message;
    toast.className = "toast show " + type;

    setTimeout(() => {
        toast.classList.remove("show");
    }, 3000);
}

// PHP Session Feedback
<?php if (!empty($errors['updateProfInfoErr'])): ?>
    showToast("<?= addslashes($errors['updateProfInfoErr']) ?>", "error");
<?php endif; ?>
<?php if (!empty($successes['updateProfInfoSucc'])): ?>
    showToast("<?= addslashes($successes['updateProfInfoSucc']) ?>", "success");
<?php endif; ?>
<?php if (!empty($errors['updateProfPswdErr'])): ?>
    showToast("<?= addslashes($errors['updateProfPswdErr']) ?>", "error");
<?php endif; ?>
<?php if (!empty($successes['updateProfPswdSucc'])): ?>
    showToast("<?= addslashes($successes['updateProfPswdSucc']) ?>", "success");
<?php endif; ?>
</script>

</body>
</html>