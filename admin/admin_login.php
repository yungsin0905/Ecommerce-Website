<?php
require_once("config.php");
session_start();

// Error and success message
$error = $_SESSION['error'] ?? null;
unset($_SESSION['error']);
$success = $_GET['reset'] ?? '';

// Handle submit
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Get inputs
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';

    $stmt = $conn->prepare("SELECT * FROM admin WHERE ADMIN_EMAIL = ? AND ADMIN_STATUS = 'Active' AND IS_DELETED = 0");
    $stmt->bind_param("s", $admin_email);
    $stmt->execute();

    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();

    if ($admin && password_verify($admin_password, $admin['ADMIN_PASSWORD'])) {

        // Double Check if admin is deleted or suspended
        if ($admin['IS_DELETED'] == 1) {
            $_SESSION['error'] = "Invalid email or password.";
            header("Location: admin_login.php");
            exit();
        }

        if ($admin['ADMIN_STATUS'] === 'Suspended') {
            $_SESSION['error'] = "Your account has been suspended.";
            header("Location: admin_login.php");
            exit();
        }

        session_regenerate_id(true);

        $_SESSION['admin_id'] = $admin['ADMIN_ID']; 
        $_SESSION['admin_email'] = $admin['ADMIN_EMAIL']; 
        $_SESSION['ADMIN_TYPE'] = $admin['ADMIN_TYPE'];

        // if admin is first login, go to force change password
        if ($admin['FORCE_PASSWORD_CHANGE'] == 1) {
            header("Location: admin_force_change_password.php");
        // else go to dashboard
        } else {
            header("Location: dashboard.php");
        }
        exit();

    } else {
        $_SESSION['error'] = "Invalid email or password.";
        header("Location: admin_login.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login</title>
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="admin_global_login.css">
</head>

<style>
</style>

<body>
    <div class="auth-wrapper">
        <div class="auth-card">
            <h2 class="auth-title">Admin Login</h2>
            <p class="auth-subtitle">Please enter your credentials.</p>

            <?php if ($success === 'success'): ?>
                <div class="auth-success">
                    Password reset successful. Please login.
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="auth-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form">
                <div class="auth-group">
                    <label class="auth-label">Email</label>
                    <input type="email" name="admin_email" class="auth-input">
                </div>

                <div class="auth-group">
                    <label class="auth-label">Password</label>
                    <input type="password" name="admin_password" class="auth-input">
                </div>

                <button class="auth-btn" type="submit">Login</button>
            </form>

            <div class="auth-link">
                Forgot password? <a href="admin_forgot_password.php">Reset</a>
            </div>

        </div>
    </div>
</body>
</html>