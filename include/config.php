<?php
require_once __DIR__ . '/env.php';

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
    die("Database connection failed.");   // 不把详细错误显示给用户
}

date_default_timezone_set('Asia/Kuala_Lumpur');
mysqli_query($conn, "SET time_zone = '+08:00'");

// ── Auto-assign tier vouchers ─────────────────────────────────────
function assignTierVouchers($conn, $customer_id, $new_tier_id) {

    // delete vouchers from old tier if not used
    mysqli_query($conn, "
        DELETE cv FROM customer_voucher cv
        INNER JOIN voucher v ON cv.VOUCHER_ID = v.VOUCHER_ID
        WHERE cv.CUSTOMER_ID = $customer_id
        AND v.VOUCHER_TYPE = 'Tier'
        AND v.TIER_ID != $new_tier_id
        AND v.TIER_ID IS NOT NULL
        AND cv.USED_COUNT = 0
    ");

    // send new tier vouchers (skip auto-generated monthly rewards)
    $tier_vouchers = mysqli_query($conn, "
        SELECT VOUCHER_ID, START_DATE FROM voucher
        WHERE TIER_ID = $new_tier_id
          AND VOUCHER_TYPE = 'Tier'
          AND VOUCHER_STATUS = 'Active'
          AND IS_DELETED = 0
          AND VOUCHER_CODE NOT LIKE 'AUTO-%'
    ");

    while ($v = mysqli_fetch_assoc($tier_vouchers)) {
        $voucher_id = (int)$v['VOUCHER_ID'];
        $start      = mysqli_real_escape_string($conn, $v['START_DATE']);

        $exists = mysqli_query($conn, "
            SELECT CUSTOMER_VOUCHER_ID FROM customer_voucher
            WHERE CUSTOMER_ID = $customer_id AND VOUCHER_ID = $voucher_id
        ");

        if (mysqli_num_rows($exists) === 0) {
            mysqli_query($conn, "
                INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, CLAIMED_AT, EXPIRY_DATE)
                VALUES ($customer_id, $voucher_id, 0, NOW(),
                        DATE_ADD(GREATEST(NOW(), '$start'), INTERVAL 1 MONTH))
            ");
        }
    }
}

// ── Send email ────────────────────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer.php';
require_once __DIR__ . '/vendor/SMTP.php';

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