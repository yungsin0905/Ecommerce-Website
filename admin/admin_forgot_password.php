<?php
session_start();
require_once("config.php");

// 登录状态的键名要和 admin_login.php 保持一致
if (isset($_SESSION['admin_id'])) {
    header("Location: admin_dashboard.php");
    exit();
}

// Error & Success message（flash）
$success = $_SESSION['success'] ?? null;
$error   = $_SESSION['error'] ?? null;
unset($_SESSION['success'], $_SESSION['error']);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email.";
        header("Location: admin_forgot_password.php");
        exit();
    }

    // 只允许未删除、未停用的管理员
    $stmt = $conn->prepare("
        SELECT ADMIN_ID, ADMIN_NAME
        FROM admin
        WHERE ADMIN_EMAIL = ? AND IS_DELETED = 0 AND ADMIN_STATUS = 'Active'
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 不论邮箱是否存在，都显示同一句话，避免泄露
    $genericMsg = "If this email is registered, a reset link has been sent.";

    if (!$admin) {
        $_SESSION['success'] = $genericMsg;
        header("Location: admin_forgot_password.php");
        exit();
    }

    $admin_id = (int)$admin['ADMIN_ID'];
    $name     = $admin['ADMIN_NAME'];

    // 30 秒冷却
    $chk = $conn->prepare("SELECT CREATED_AT FROM admin_pswd_reset WHERE ADMIN_ID = ? ORDER BY CREATED_AT DESC LIMIT 1");
    $chk->bind_param("i", $admin_id);
    $chk->execute();
    $last = $chk->get_result()->fetch_assoc();
    $chk->close();

    if ($last && (time() - strtotime($last['CREATED_AT'])) < 30) {
        $_SESSION['success'] = $genericMsg;
        header("Location: admin_forgot_password.php");
        exit();
    }

    $token      = bin2hex(random_bytes(32));
    $expires_at = date("Y-m-d H:i:s", strtotime('+10 minutes'));
    $created_at = date("Y-m-d H:i:s");

    $del = $conn->prepare("DELETE FROM admin_pswd_reset WHERE ADMIN_ID = ?");
    $del->bind_param("i", $admin_id);
    $del->execute();
    $del->close();

    $ins = $conn->prepare("INSERT INTO admin_pswd_reset (ADMIN_ID, RESET_EMAIL, TOKEN, EXPIRES_AT, CREATED_AT) VALUES (?,?,?,?,?)");
    $ins->bind_param("issss", $admin_id, $email, $token, $expires_at, $created_at);

    if (!$ins->execute()) {
        $ins->close();
        $_SESSION['error'] = "Something went wrong. Please try again.";
        header("Location: admin_forgot_password.php");
        exit();
    }
    $ins->close();

    // 自动适配本地 / 线上域名（admin_reset_password.php 要和本文件同一目录）
    $scheme    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $resetLink = $scheme . '://' . $_SERVER['HTTP_HOST']
               . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\')
               . '/admin_reset_password.php?token=' . $token;

    $safeName = htmlspecialchars($name);
    $body = "
    <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;max-width:600px;margin:auto;'>
        <h3>Hi {$safeName},</h3>
        <p>You requested to reset your admin password. Please click the button below:</p>
        <p style='text-align:center;margin:25px 0;'>
            <a href='{$resetLink}' style='background-color:#80b8d2;color:#fff;padding:10px 24px;border-radius:6px;text-decoration:none;font-weight:bold;'>Reset Password</a>
        </p>
        <p style='word-break:break-all;color:#888;font-size:13px;'>{$resetLink}</p>
        <p style='color:#d93025;font-size:13px;'>This link will expire in <strong>10 minutes</strong>. Do not share it with anyone.</p>
        <p>If you did not request this, please ignore this email.</p>
    </div>";

    $alt = "Hi {$name}, please use this link to reset your password: {$resetLink}";

    if (sendMail($email, 'Password Reset Request - Maker Kluang', $body, $name, $alt)) {
        $_SESSION['success'] = $genericMsg;
    } else {
        $_SESSION['error'] = "Email could not be sent. Please try again later.";
    }
    header("Location: admin_forgot_password.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Forgot Password</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="admin_global_login.css">
<style>
</style>
</head>

<body>
    <div class="auth-wrapper">
        <div class="auth-back">
            <a href="admin_login.php"><i class="bi bi-chevron-left"></i>Back</a>
        </div>

        <div class="auth-card">
            <h2 class="auth-title">Reset Password</h2>
            <p class="auth-subtitle">Enter your email to receive reset password email.</p>

            <?php if ($success): ?>
                <div class="auth-success">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="auth-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="auth-form">          
                <div class="auth-group">
                  <label class="auth-label">Email</label>
                  <input type="email" name="email" class="auth-input">
                </div>

                <button type="submit" class="auth-btn">Get Link</button>
            </form>
            
            <div class="auth-link">
                Remembered your password?  <a href="admin_login.php">Back to Login</a>
            </div> 
        </div>
    </div>

<script>
const msgBox = document.querySelector('.auth-error, .auth-success');

document.querySelector('.auth-form').addEventListener('input', function() {
    if (msgBox) {
        msgBox.style.display = 'none';
    }
});
</script>

</body>
</html> 