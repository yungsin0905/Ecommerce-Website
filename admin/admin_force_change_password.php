<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// Error message
$message = "";

// Handle submit
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Get inputs
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $errors = [];

    if ($password === '' || $confirm === '') {
        $errors[] = "Password is required";
    }

    if ($password !== $confirm) {
        $errors[] = "Password not match";
    }

    // Password rule
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()_\-+=<>?]).{8,12}$/', $password)) {
        $errors[] = "Password must be 8–12 chars with atleast one uppercase letter(A-Z), one lowercase letter(a-z), one digit(0-9), and one special character(!@#$%^&*()_\-+=<>?).";
    }

    if (empty($errors)) {

        // Check same password
        $stmt = $conn->prepare("
            SELECT ADMIN_PASSWORD
            FROM admin
            WHERE ADMIN_ID = ?
        ");

        $stmt->bind_param("i", $admin_id);
        $stmt->execute();

        $admin = $stmt->get_result()->fetch_assoc();

        if (password_verify($password, $admin['ADMIN_PASSWORD'])) {
            $errors[] = "New password cannot be the same as the old password.";
        }
    }

    // if any errors, shows message
    if (!empty($errors)) {
        $message = implode(" | ", $errors);

    } else {
    // if no, hash password and insert
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE admin
            SET ADMIN_PASSWORD = ?,
                FORCE_PASSWORD_CHANGE = 0
            WHERE ADMIN_ID = ?
        ");

        $stmt->bind_param("si", $hashed, $admin_id);

        if ($stmt->execute()) {

            // redirect to dashboard if login successfully
            header("Location: dashboard.php?first_login=done");
            exit();

        } else {
            $message = "Failed to update password";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Force Change Password</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="admin_global_login.css">
<style>
.auth-card {
    max-width: 450px;
}
</style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2 class="auth-title">Set New Password</h2>
            <p class="auth-subtitle">You must change your temporary password before continuing.</p>

            <?php if (!empty($message)): ?>
                <div class="auth-error">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="auth-group">
                    <label class="auth-label">New Password</label>
                    <input type="password" name="password" class="auth-input">
                </div>

                <div class="auth-group">
                    <label class="auth-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="auth-input">
                </div>

                <ul class="hints">
                    <li>8-12 characters</li>
                    <li>At least 1 Uppercase + lowercase</li>
                    <li>At least 1 number + symbol</li>
                </ul>

                <button type="submit" class="auth-btn">Update Password</button>
            </form>
        </div>
    </div>
</body>
</html>