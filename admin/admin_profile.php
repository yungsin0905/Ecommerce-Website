<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

//page title
$pageTitle = "Profile";

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
        $_SESSION['errors']['newPswdErr'] = "Password must be 8–12 chars with atleast one uppercase letter(A-Z), one lowercase letter(a-z), one digit(0-9), and one special character(!@#$%^&*()_\-+=<>?).";
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
            $_SESSION['errors']['updateProfPswdErr'] = "Failed to update profile.";
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
    <title>Admin Profile</title>
    <link rel="stylesheet" href="admin_global.css">
<style>
body {
    background: var(--primary-grey);
    font-family: var(--font-family);
}

/* Page layout */
.prof-main-cont {
    display: flex;
    gap: 30px;
    padding: 30px;
    flex-wrap: wrap;
}

/* Each section */
.prof-info-sec,
.prof-pswd-sec {
    flex: 1;
    min-width: 320px;
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

/* Section title */
.prof-info-sec h3,
.prof-pswd-sec h3 {
    margin-bottom: 15px;
    font-size: 18px;
}

/* Profile info layout */
.prof-info-content-main {
    display: flex;
    gap: 20px;
    align-items: flex-start;
}

/* Profile image */
.prof-info-pic {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid #ddd;
}

/* Right side form area */
.prof-info-content-2 {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Labels spacing */
.prof-info-content-2 p {
    margin: 6px 0 4px;
    font-weight: 500;
}

/* Inputs */
.prof-info-content-2 input {
    padding: 8px 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    outline: none;
}

/* Disabled inputs */
.prof-info-content-2 input:disabled {
    background: #f5f5f5;
}

/* Buttons */
.prof-change-pic-btn {
    padding: 6px 10px;
    font-size: 13px;
    border: none;
    background: #eee;
    border-radius: 6px;
    cursor: pointer;
}

.prof-change-pic-btn:hover {
    background: #ddd;
}

.prof-update-info-btn,
.prof-update-pswd-btn {
    margin-top: 15px;
    padding: 10px;
    width: 100%;
    border: none;
    background: var(--primary-dark);
    color: var(--primary-white);
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}

.prof-update-info-btn:hover,
.prof-update-pswd-btn:hover {
    background: var(--primary-light);
}

/* Password section */
.prof-pswd-content {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Input spacing */
.prof-pswd-content input {
    padding: 8px 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
}

/* Labels */
.prof-pswd-content p {
    margin: 6px 0 4px;
    font-weight: 500;
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
}

.phone-input {
    flex: 1;
    border-radius: 0 6px 6px 0;
}

/* Error / Success */
.error {
    color: red;
    font-size: 12px;
    margin-top: -4px;
    margin-bottom: 4px;
    display: block;
}

.success-auto-hide {
    color: green;
    font-size: 13px;
    margin-top: 8px;
}

/* Image upload button row */
.prof-info-content-2-btn,
.prof-pswd-content-btn {
    margin-top: 10px;
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    left: 50%;
    padding: 12px 18px;
    border-radius: 8px;
    color: #fff;
    background: #333;
    opacity: 0;
    transform: translateX(-50%);
    transition: 0.3s;
    z-index: 9999;
    font-size: 14px;
}

.toast.show {
    opacity: 1;
    transform: translateX(0);
}

.toast.success {
    background: #28a745;
}

.toast.error {
    background: #dc3545;
}

/* Asterisk */
.asterisk {
    color: red;
}
</style>
</head>

<body>
<div class="global-layout"> 
    <?php include "global_layout_ctrl.php"; ?>
    <div class="main">

        <div class="prof-main-cont">

            <div class="prof-info-sec">
              <h3>Details</h3>

              <form method="post" enctype="multipart/form-data">

                <div class="prof-info-content-main">
                    <img src="<?= htmlspecialchars($admin_image) ?>" alt="Profile Picture" class="prof-info-pic">
                 
                    <div class="prof-info-content-2">
                        <button type="button" class="prof-change-pic-btn" onclick="document.getElementById('imageInput').click()">Change Profile Picture</button>
                        <input type="file" name="admin_image" style="display: none;" id="imageInput">
                        <span class="error" id="imageErr"><?= $errors['imageErr'] ?? '' ?></span>

                        <p>Name<span class="asterisk">*</span></p>
                        <input type="text" name="admin_name" id="nameInput" value="<?= htmlspecialchars($admin_name) ?>" oninput="validateName()">
                        <span class="error" id="nameErr"><?= $errors['nameErr'] ?? '' ?></span>

                        <p>Role<span class="asterisk">*</span></p>
                        <input type="text" id="roleInput" value="<?= htmlspecialchars($admin_role) ?>" disabled>

                        <p>Email<span class="asterisk">*</span></p>
                        <input type="email" id="emailInput" value="<?= htmlspecialchars($admin_email) ?>" disabled>

                        <p>Phone</p>
                        <div class="phone-wrapper">
                            <span class="phone-prefix">+60</span>
                            <input type="text" name="admin_phone" id="phoneInput" class="phone-input" value="<?= htmlspecialchars(preg_replace('/^\+60/', '', $admin_phone)) ?>" oninput="validatePhone()">
                        </div>
                        <span class="error" id="phoneErr"><?= $errors['phoneErr'] ?? '' ?></span>
                    </div>
                </div>

                <?php if (!empty($errors['updateProfInfoErr'])) { ?>             
                    <script>
                        window.addEventListener("DOMContentLoaded", function () {
                            showToast("<?= $errors['updateProfInfoErr'] ?>", "error");
                        });
                    </script>
                <?php } ?>
                <?php if (!empty($successes['updateProfInfoSucc'])) { ?>
                    <script>
                        window.addEventListener("DOMContentLoaded", function () {
                            showToast("<?= $successes['updateProfInfoSucc'] ?>", "success");
                        });
                    </script>
                <?php } ?>

                <div class="prof-info-content-2-btn">
                    <button type="submit" name="update_prof_info" class="prof-update-info-btn">Update</button>
                </div>

              </form>
            </div>

            <div class="prof-pswd-sec">
              <h3>Change Password</h3>

              <form method="post" class="prof-pswd-content">
                <p>Current Password<span class="asterisk">*</span></p>
                <input type="password" name="current_pswd" id="currentPswd" oninput="validatePassword()">
                <span class="error" id="currentPswdErr"><?= $errors['currentPswdErr'] ?? '' ?></span>

                <p>New Password<span class="asterisk">*</span></p>
                <input type="password" name="new_pswd" id="newPswd" oninput="validatePassword()">
                <span class="error" id="newPswdErr"><?= $errors['newPswdErr'] ?? '' ?></span>

                <p>Confirm New Password<span class="asterisk">*</span></p>
                <input type="password" name="confirm_pswd" id="confirmPswd" oninput="validatePassword()">
                <span class="error" id="confirmPswdErr"><?= $errors['confirmPswdErr'] ?? '' ?></span>

                <?php if (!empty($errors['updateProfPswdErr'])) { ?>
                    <script>
                        window.addEventListener("DOMContentLoaded", function () {
                            showToast("<?= $errors['updateProfPswdErr'] ?>", "error");
                        });
                    </script>
                <?php } ?>
                <?php if (!empty($successes['updateProfPswdSucc'])) { ?>
                    <script>
                        window.addEventListener("DOMContentLoaded", function () {
                            showToast("<?= $successes['updateProfPswdSucc'] ?>", "success");
                        });
                    </script>
                <?php } ?>

                <div class="prof-pswd-content-btn">
                  <button type="submit" name="update_prof_pswd" class="prof-update-pswd-btn">Update</button>
                </div>

              </form>
            </div>

        </div>

    </div>
</div>

<script>
// Listen for image input change (when user selects a file)
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

// Auto hide success messages after 3 seconds
setTimeout(() => {
    document.querySelectorAll('.success-auto-hide').forEach(el => {
        el.style.transition = "opacity 0.5s";
        el.style.opacity = "0";

        setTimeout(() => {
            el.remove();
        }, 500);
    });
}, 3000);

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

    // current
    if (current === "") {
        currentErr.innerText = "Current password is required";
    } else {
        currentErr.innerText = "";
    }

    // new password strength
    const strongRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_\-+=<>?]).{8,12}$/;

    if (newPwd === "") {
        newErr.innerText = "";
    } else if (!strongRegex.test(newPwd)) {
        newErr.innerText = "Password must be 8–12 chars with atleast one uppercase letter(A-Z), one lowercase letter(a-z), one digit(0-9), and one special character(!@#$%^&*()_\-+=<>?).";
    } else if (current !== "" && newPwd === current) {
        newErr.innerText = "New password cannot be same as current";
    } else {
        newErr.innerText = "";
    }

    // confirm
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
    }, 2500);
}
</script>

<div id="toast" class="toast"></div>
</body>
</html>