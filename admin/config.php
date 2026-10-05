<?php
require_once __DIR__ . '/../include/env.php';   // 按实际目录调整

// 提取不带端口号的主机名
$host_name = explode(':', $_SERVER['HTTP_HOST'])[0];

if ($host_name == 'localhost' || $host_name == '127.0.0.1') {
    ini_set('display_errors', 1);   // 本地：显示错误
} else {
    ini_set('display_errors', 0);   // 线上：不显示，只写 log
    ini_set('log_errors', 1);
}
error_reporting(E_ALL);

$conn = new mysqli(
    env('DB_HOST', 'localhost'),
    env('DB_USER'),
    env('DB_PASS', ''),
    env('DB_NAME')
);

if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    die("Database connection failed.");
}

date_default_timezone_set('Asia/Kuala_Lumpur');
$conn->query("SET time_zone = '+08:00'");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 后台有自己的 vendor，用 __DIR__ 比相对路径可靠
require_once 'include/vendor/Exception.php';
require_once 'include/vendor/PHPMailer.php';
require_once 'include/vendor/SMTP.php';

function sendMail(string $to, string $subject, string $body, string $toName = '', string $altBody = ''): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = env('MAIL_HOST');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('MAIL_USER');
        $mail->Password   = env('MAIL_PASS');
        $mail->Port       = (int) env('MAIL_PORT', 465);
        $mail->SMTPSecure = $mail->Port === 587
            ? PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer::ENCRYPTION_SMTPS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(env('MAIL_USER'), env('MAIL_FROM_NAME', 'Maker Kluang'));
        $mail->addAddress($to, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $altBody ?: strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail error: " . $mail->ErrorInfo);
        return false;
    }
}
?>