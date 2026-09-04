<?php
require_once("config.php");
session_start();

// Get token 
$token = $_GET['token'] ?? '';
$message = "";

// Verify token 
$stmt = $conn->prepare("
    SELECT pr.*, a.ADMIN_ID
    FROM admin_pswd_reset pr
    JOIN admin a ON pr.ADMIN_ID = a.ADMIN_ID
    WHERE pr.TOKEN = ?
    AND pr.IS_USED = 0
    AND pr.EXPIRES_AT > NOW()
    LIMIT 1
");

$stmt->bind_param("s", $token);
$stmt->execute();
$reset = $stmt->get_result()->fetch_assoc();

if (!$reset) {
    die("Invalid or expired reset link");
}

// Handle reset 
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Get inputs
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    $errors = [];

    if ($password === '' || $confirm === '') {
        $errors[] = "Password required";
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

        $stmt->bind_param("i", $reset['ADMIN_ID']);
        $stmt->execute();

        $admin = $stmt->get_result()->fetch_assoc();

        if (password_verify($password, $admin['ADMIN_PASSWORD'])) {
            $errors[] = "New password cannot be the same as the old password.";
        }
    }

    if (!empty($errors)) {
        $message = implode(" | ", $errors);

    } else {

        // if no error, hash password and update admin password.
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare("
                UPDATE admin
                SET ADMIN_PASSWORD = ?
                WHERE ADMIN_ID = ?
            ");
            $stmt->bind_param("si", $hashed, $reset['ADMIN_ID']);
            $stmt->execute();

            /* mark token used */
            $stmt = $conn->prepare("
                UPDATE admin_pswd_reset
                SET IS_USED = 1
                WHERE TOKEN = ?
            ");
            $stmt->bind_param("s", $token);
            $stmt->execute();

            $conn->commit();

            header("Location: admin_login.php?reset=success");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Reset failed";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Reset Password</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="admin_global_login.css">
<style>
</style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2 class="auth-title">Admin Reset Password</h2>
            <p class="auth-subtitle">Set a new password.</p>

            <?php if (!empty($message)): ?>
                <div class="auth-error">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">
                <div class="auth-group">
                    <label class="auth-label">New Password</label>
                    <input type="password" name="password" class="auth-input">
                </div>

                <div class="auth-group">
                    <label class="auth-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="auth-input">
                </div>

                <ul class="hints">
                    <li>8 - 12 characters</li>
                    <li>At least 1 Uppercase + lowercase</li>
                    <li>At least 1 number + symbol</li>
                </ul>

                <button type="submit" class="auth-btn">Update Password</button>
            </form>
        </div>
    </div>
</body>
</html>