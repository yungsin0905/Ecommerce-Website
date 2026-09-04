<?php
//error report for debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 提取不带端口号的主机名 (如 localhost:3000 提取出 localhost)
$host_name = explode(':', $_SERVER['HTTP_HOST'])[0];

// 这里要判断处理后的 $host_name，而不是原始的 $_SERVER['HTTP_HOST']
if ($host_name == 'localhost' || $host_name == '127.0.0.1') {
    // ---------------- 本地 XAMPP 环境 ----------------
    $servername = 'localhost';
    $username   = 'root';
    $password   = '';
    $dbname     = 'cakeology';
} else {
    // ---------------- cPanel 线上环境 ----------------
    $servername = "localhost";
    $username   = "makerklu_wongyungsin";
    $password   = "Wy$173183";
    $dbname     = "makerklu_cytron"; 
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
} 

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

    // sent new tier vouchers
    $tier_vouchers = mysqli_query($conn, "
        SELECT VOUCHER_ID FROM voucher
        WHERE TIER_ID = $new_tier_id
        AND VOUCHER_TYPE = 'Tier'
          AND VOUCHER_STATUS = 'Active'
          AND IS_DELETED = 0
    ");

    while ($v = mysqli_fetch_assoc($tier_vouchers)) {
        $voucher_id = $v['VOUCHER_ID'];

        $exists = mysqli_query($conn, "
            SELECT CUSTOMER_VOUCHER_ID FROM customer_voucher
            WHERE CUSTOMER_ID = $customer_id AND VOUCHER_ID = $voucher_id
        ");

        if (mysqli_num_rows($exists) === 0) {
            mysqli_query($conn, "
                INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, CLAIMED_AT, EXPIRY_DATE)
                VALUES ($customer_id, $voucher_id, 0, NOW(), DATE_ADD(NOW(), INTERVAL 3 MONTH))
            ");
        }
    }
}

// send email function
date_default_timezone_set('Asia/Kuala_Lumpur');
mysqli_query($conn, "SET time_zone = '+08:00'");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer.php';
require_once __DIR__ . '/vendor/SMTP.php';

$registrationMessage = "";
$messageType = "error";

function sendMail(string $to, string $subject, string $body): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'wongyungsin04@gmail.com';
        $mail->Password   = 'jfim afvt zusc vqwg';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('wongyungsin04@gmail.com', 'Cakeology (No-Reply)');
        $mail->addAddress($to);

        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        return false;
    }
}
?>