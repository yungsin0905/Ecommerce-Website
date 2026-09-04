<?php
require_once("config.php");
session_start();

date_default_timezone_set('Asia/Kuala_Lumpur');

// email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'include/vendor/Exception.php';
require 'include/vendor/PHPMailer.php';
require 'include/vendor/SMTP.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

$id = $_GET['id'] ?? null;
if (!$id) die("Invalid Request ID");

// Fetch custom info
$stmt = $conn->prepare("
    SELECT c.*, cu.CUSTOMER_NAME, cu.EMAIL, cu.PHONE
    FROM custom c
    LEFT JOIN customer cu ON c.CUSTOMER_ID = cu.CUSTOMER_ID
    WHERE c.CUSTOM_ID = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("Request not found");
}

$data = $result->fetch_assoc();


// EXPIRED CHECK
$isExpired    = false;
$expireReason = null; //determine specific expire reason for better display

// Helper: detect expire reason from data
function detectExpireReason($data) {

    // 1. Response Deadline expired (time-based only)
    if (!empty($data['RESPONSE_DEADLINE'])) {
        if (time() > strtotime($data['RESPONSE_DEADLINE'])) {
            return "Response Deadline Expired";
        }
    }

    // 2. Delivery Slot end time
    preg_match(
        '/(\d{1,2}:\d{2}\s?[APMapm]{2})\s*-\s*(\d{1,2}:\d{2}\s?[APMapm]{2})/',
        $data['DELIVERY_SLOT'],
        $matches
    );
    
    if ($matches) {
        $endTimeStr = $data['DELIVERY_DATE'] . ' ' . trim($matches[2]);
        $dt = DateTime::createFromFormat('Y-m-d g:i A', $endTimeStr, new DateTimeZone('Asia/Kuala_Lumpur'));
        $endTime = $dt ? $dt->getTimestamp() : false;
        if ($endTime && time() > $endTime) {
            return "Delivery Slot Expired";
        }
    }

    // 3. Delivery date fully passed (fallback)
    if (time() > strtotime($data['DELIVERY_DATE'] . " 23:59:59")) {
        return "Delivery Date Expired";
    }

    return null;
}

// CASE 1: Already expired in database (MySQL Event handled it)
if ($data['STATUS'] == 'Expired') {

    // Already marked Expired by MySQL Event, just detect reason for display
    $isExpired    = true;
    $expireReason = detectExpireReason($data) ?? "Expired";

// CASE 2: Active requests that may have just expired
// (Pending / Quoted are still eligible for expiration check)
} elseif ($data['STATUS'] == 'Pending' || $data['STATUS'] == 'Quoted') {

    $expireReason = detectExpireReason($data);

    if ($expireReason !== null) {
        $isExpired = true;

        // Update STATUS to Expired in DB (capacity release handled by MySQL Event)
        $autoExpire = $conn->prepare("
            UPDATE custom
            SET STATUS = 'Expired',
                REJECTED_BY = NULL
                WHERE CUSTOM_ID = ? AND STATUS IN ('Pending', 'Quoted')
        ");
        $autoExpire->bind_param("i", $id);
        $autoExpire->execute();

        if ($autoExpire->affected_rows > 0) {
            // Refresh $data
            $stmt->execute();
            $result = $stmt->get_result();
            $data   = $result->fetch_assoc();
        }
    }
}

// PROCESS FORM
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Block if expired or not Pending
    if ($isExpired || $data['STATUS'] != 'Pending') {
        $_SESSION['toast'] = [
            'type'    => 'error',
            'message' => 'This request has already expired or been processed.'
        ];
        header("Location: process_custom.php?id=" . $id);
        exit();
    }

    $status            = $_POST['status'] ?? '';
    $quoted_price      = $_POST['quoted_price'] ?? null;
    $rejected_by       = null;
    $response_deadline = $_POST['response_deadline'] ?? null;

    $allowedStatus = ['Quoted', 'Rejected'];

    // VALIDATION
    if (!in_array($status, $allowedStatus)) {
        $_SESSION['toast'] = [
            'type' => 'error', 
            'message' => 'Invalid Status'
        ];
        header("Location: process_custom.php?id=" . $id);
        exit();
    }

    if ($status == "Quoted" && $quoted_price <= 0) {
        $_SESSION['toast'] = [
            'type' => 'error', 
            'message' => 'Quoted price must be greater than 0 and cannot be empty'
        ];
        header("Location: process_custom.php?id=" . $id);
        exit();
    }

    if ($status === "Quoted") {

        if (empty($response_deadline)) {
            $_SESSION['toast'] = [
                'type' => 'error', 
                'message' => 'Response deadline is required when quoting'
            ];
            header("Location: process_custom.php?id=" . $id);
            exit();
        }

        $timestamp = strtotime($response_deadline);

        // Validate datetime format
        if ($timestamp === false) {
            $_SESSION['toast'] = [
                'type' => 'error', 
                'message' => 'Invalid response deadline'
            ];
            header("Location: process_custom.php?id=" . $id);
            exit();
        }

        $created        = strtotime($data['CREATED_AT']);
        $delivery_limit = strtotime($data['DELIVERY_DATE'] . " 00:00:00") - 86400 + 86399;

        // Ensure deadline is not before request creation
        if ($timestamp <= $created) {
            $_SESSION['toast'] = [
                'type' => 'error', 
                'message' => 'Deadline cannot be earlier than request creation time'
            ];
            header("Location: process_custom.php?id=" . $id);
            exit();
        }

        // Ensure deadline is at least 5 minutes from now (to prevent immediate expiration)
        if ($timestamp < time() + 300) { 
            $_SESSION['toast'] = [
                'type' => 'error',
                'message' => 'Deadline must be at least 5 minutes from now'
            ];
            header("Location: process_custom.php?id=" . $id);
            exit();
        }

        // Ensure deadline is at least 1 day before delivery date
        if ($timestamp > $delivery_limit) {
            $_SESSION['toast'] = [
                'type' => 'error', 
                'message' => 'Deadline must be at least 1 day before delivery date'
            ];
            header("Location: process_custom.php?id=" . $id);
            exit();
        }

        $response_deadline = date('Y-m-d H:i:s', $timestamp);

    } else {
        $response_deadline = null;
    }

    if ($status == "Rejected") {
        $quoted_price = null;
        $rejected_by  = "Admin";

        // Admin manually rejects -> release capacity here (since MySQL Event only handles auto-expire)
        $delivery_date_only = date('Y-m-d', strtotime($data['DELIVERY_DATE']));
        $conn->query("
            UPDATE production_capacity
            SET ALREADY_BOOKED = GREATEST(ALREADY_BOOKED - 1, 0)
            WHERE PRODUCTION_DATE = '$delivery_date_only'
        ");
    }

    // UPDATE CUSTOM REQUEST IN DATABASE
    $update = $conn->prepare("
        UPDATE custom
        SET STATUS = ?, QUOTED_PRICE = ?, REJECTED_BY = ?, RESPONSE_DEADLINE = ?
        WHERE CUSTOM_ID = ?
    ");
    $update->bind_param("sdssi", $status, $quoted_price, $rejected_by, $response_deadline, $id);
    $update->execute();

    // Send email
    $bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
    $bakery       = mysqli_fetch_assoc($bakeryResult);

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'wongyungsin04@gmail.com';
        $mail->Password   = 'jfim afvt zusc vqwg';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('wongyungsin04@gmail.com', $bakery['SHOP_NAME']);
        $mail->addAddress($data['EMAIL']);
        $mail->isHTML(true);

        // QUOTED EMAIL
        if ($status == 'Quoted') {

            $deadlineFormatted = date("d M Y, h:i A", strtotime($response_deadline));

            $mail->Subject = 'Custom Cake Quotation - ' . $bakery['SHOP_NAME'];
            $mail->Body    = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
                    <h2 style='color:#e91e63;'>" . $bakery['SHOP_NAME'] . "</h2>

                    <p>Dear " . htmlspecialchars($data['CUSTOMER_NAME']) . ",</p>

                    <p>Your custom cake request submitted on <strong> " . date("d M Y, h:i A", strtotime($data['CREATED_AT'])) . " </strong> has been reviewed and we are pleased to provide you with a quotation.</p>

                    <table style='border-collapse:collapse;width:100%;max-width:400px;margin:16px 0;'>
                        <tr>
                            <td style='padding:10px 14px;background:#fdf2f8;border:1px solid #f0d6e8;font-weight:bold;color:#555;width:45%;'>Quoted Price</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #f0d6e8;font-weight:bold;color:#e91e63;font-size:16px;'>RM " . number_format($quoted_price, 2) . "</td>
                        </tr>
                        <tr>
                            <td style='padding:10px 14px;background:#fdf2f8;border:1px solid #f0d6e8;font-weight:bold;color:#555;'>Response Deadline</td>
                            <td style='padding:10px 14px;background:#fff;border:1px solid #f0d6e8;font-weight:bold;color:#c0392b;'>" . $deadlineFormatted . "</td>
                        </tr>
                    </table>

                    <p style='background:#fff8e1;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:4px;margin:16px 0;'>
                        ⚠️ <strong>Important:</strong> Please use our website to accept or reject this quotation before " . $deadlineFormatted . ".<br>
                        If no response is received by the deadline, this request will be <strong>automatically expired and treated as a rejection</strong>.
                    </p>

                    <hr style='border:none;border-top:1px solid #ddd;margin:20px 0;'>

                    <p>Thank you for choosing " . $bakery['SHOP_NAME'] . ".</p>

                    <br>

                    <p>
                        Best Regards,<br>
                        <strong>" . $bakery['SHOP_NAME'] . " Team</strong>
                    </p>

                    <br>

                    <p>
                        " . $bakery['ADDRESS'] . ",
                        " . $bakery['POSTCODE'] . "
                        " . $bakery['CITY'] . ",
                        " . $bakery['STATE'] . "<br>
                        Phone: " . $bakery['PHONE'] . "<br>
                        Email: " . $bakery['EMAIL'] . "
                    </p>
                </div>
            ";
        }

        // REJECTED EMAIL
        elseif ($status == 'Rejected' && $rejected_by == 'Admin') {

            $mail->Subject = 'Custom Cake Request Update - ' . $bakery['SHOP_NAME'];
            $mail->Body    = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>
                    <h2 style='color:#e91e63;'>" . $bakery['SHOP_NAME'] . "</h2>

                    <p>Dear " . htmlspecialchars($data['CUSTOMER_NAME']) . ",</p>

                    <p>Thank you for your custom cake request submitted on <strong> " . date("d M Y, h:i A", strtotime($data['CREATED_AT'])) . " </strong>.</p>

                    <p>After careful review, we regret to inform you that we are unable to proceed with this request at the moment.</p>

                    <p>We sincerely apologize for any inconvenience caused and appreciate your understanding.</p>

                    <br>

                    <p>
                        Best Regards,<br>
                        <strong>" . $bakery['SHOP_NAME'] . " Team</strong>
                    </p>

                    <br>

                    <p>
                        " . $bakery['ADDRESS'] . ",
                        " . $bakery['POSTCODE'] . "
                        " . $bakery['CITY'] . ",
                        " . $bakery['STATE'] . "<br>
                        Phone: " . $bakery['PHONE'] . "<br>
                        Email: " . $bakery['EMAIL'] . "
                    </p>
                </div>
            ";
        }

        $mail->send();

        $_SESSION['toast'] = ['type' => 'success', 'message' => 'Request updated successfully!'];

    } catch (Exception $e) {
        $_SESSION['toast'] = ['type' => 'error', 'message' => 'Updated but email failed to send!'];
    }

    header("Location: process_custom.php?id=" . $id);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Custom Request</title>
    <link rel="stylesheet" href="admin_global.css">
<style>
body {
    font-family: var(--font-family);
    background: var(--primary-grey);
    margin: 0;
    padding: 20px;
}

.form-wrapper {
    max-width: 1000px;
    margin: 40px auto;
    padding: 0 16px;
}

.form-back-link {
    display: inline-flex;
    gap: 6px;
    margin-bottom: 15px;
    margin-left: 42px;
    text-decoration: none;
    color: #6b7280;
    font-size: 14px;
    font-weight: 500;
}

.form-back-link:hover {
    color: #111827;
    text-decoration: underline;
}

.form-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.container {
    max-width: 900px;
    margin: auto;
    background: #fff;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
}

h2 { 
    margin-bottom: 20px; 
    color: #2c3e50; }

h3 {
    margin-top: 40px;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #eee;
    color: #34495e;
    font-size: 16px;
}

.row {
    display: flex;
    margin-bottom: 12px;
    align-items: flex-start;
}

.row label {
    margin-bottom: 5px;
    width: 180px;
    color: #555;
}

.row div { 
    flex: 1; 
    color: #333; 
}

input[type="number"],
input[type="datetime-local"],
select {
    width: 100%;
    max-width: 300px;
    padding: 8px 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    outline: none;
}

input:focus, select:focus {
    border-color: #4a90e2;
    box-shadow: 0 0 3px rgba(74,144,226,0.3);
}

.status-box { 
    text-align: 
    center; margin: 20px 0; 
}

.status-badge {
    display: inline-block;
    padding: 12px 25px;
    border-radius: 30px;
    font-size: 18px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.status-pending  { 
    background: #fff3cd; 
    color: #856404; 
}

.status-quoted   { 
    background: #d1ecf1; 
    color: #0c5460; 
}

.status-accepted { 
    background: #d1e7dd; 
    color: #0f5132; 
}

.status-rejected { 
    background: #f8d7da; 
    color: #721c24; 
}

.status-expired  { 
    background: #f3e8ff; 
    color: #6b21a8; 
}

.expired-banner {
    margin-top: 10px;
    display: inline-block;
    background: #fee2e2;
    color: #b91c1c;
    padding: 6px 18px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.btn {
    display: inline-block;
    padding: 10px 14px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 14px;
    border: none;
    cursor: pointer;
    margin-top: 15px;
}

.btn-save { 
    background: var(--primary-dark); 
    color: white; 
}

.btn-save:hover { 
    background: var(--primary-light); 
    color: var(--primary-dark); 
}

.btn-back { 
    background: #6c757d; 
    color: white; 
    margin-left: 8px; 
}

.btn-back:hover { 
    background: #5a6268; 
}

img { 
    margin-top: 10px; 
    border-radius: 10px; 
    border: 1px solid #ddd; 
}

.row div[style] {
    background: #f9f9f9;
    padding: 12px;
    border-radius: 8px;
    border: 1px solid #eee;
}

.toast {
    position: fixed;
    top: 24px;
    left: 50%;
    transform: translateX(-50%);
    padding: 12px 40px 12px 18px;
    border-radius: 8px;
    color: #fff !important;
    font-size: 14px;
    z-index: 9999;
    opacity: 1;
    transition: all 0.4s ease;
    min-width: 180px;
    text-align: center;
}

.toast span { 
    color: #fff !important; 
}

.toast.success { 
    background: #22c55e; 
}

.toast.error   { 
    background: #ef4444; 
}

.toast-close-btn {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 22px;
    height: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    font-weight: bold;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
    line-height: 1;
}

.toast-close-btn:hover {
    background: rgba(255,255,255,0.35);
    transform: scale(1.1);
}

.custom-result {
    margin-top: 20px;
    padding: 4px 10px;
    background: #edecec;
    border-radius: 8px;
}
</style>
</head>

<body>
<div class="form-wrapper">
    <a href="manage_custom_request.php" class="form-back-link" title="Go Back To Custom Request Page">← Back</a>

    <div class="container">

        <h2 class="form-title">
            Customization Request #<?= $data['CUSTOM_ID'] ?>
            <span style="margin-left:10px;font-size:14px;font-weight:normal;">
                Created at: <?= date("d M Y H:i", strtotime($data['CREATED_AT'])) ?>
            </span>
        </h2>

        <!-- STATUS BADGE -->
        <div class="status-box">
            <div class="status-badge status-<?= strtolower($data['STATUS']) ?>">
                <?= $data['STATUS'] ?>
            </div>

            <?php if ($isExpired): ?>
                <div>
                    <span class="expired-banner">⚠ <?= htmlspecialchars($expireReason) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- CUSTOMER INFO -->
        <h3>
            Customer Info
            <span style="font-size:12px;color:#888;font-weight:normal;">
                (Person who made the request, may differ from recipient)
            </span>
        </h3>

        <div class="row"><label>Customer Name:</label>
            <div><?= htmlspecialchars($data['CUSTOMER_NAME']) ?></div>
        </div>

        <div class="row"><label>Email:</label>
            <div><?= htmlspecialchars($data['EMAIL']) ?></div>
        </div>

        <div class="row"><label>Phone:</label>
            <div><?= htmlspecialchars($data['PHONE']) ?></div>
        </div>

        <!-- CUSTOM DETAILS -->
        <h3>Custom Details</h3>

        <div class="row"><label>Cake Style:</label>
            <div><?= htmlspecialchars($data['STYLE_NAME_SNAPSHOT']) ?></div>
        </div>

        <div class="row"><label>Cake Size:</label>
            <div><?= htmlspecialchars($data['SIZE']) ?: 'N/A' ?></div>
        </div>

        <div class="row"><label>Catering Quantity:</label>
            <div><?= $data['CATER_COUNT'] ?></div>
        </div>

        <div class="row"><label>Quantity:</label>
            <div><?= $data['QUANTITY'] ?></div>
        </div>

        <div class="row"><label>Ideal Flavour:</label>
            <div><?= htmlspecialchars($data['IDEAL_FLAVOUR']) ?: 'N/A' ?></div>
        </div>

        <div class="row"><label>Reference:</label>
            <?php if (!empty($data['REF_IMAGE'])): ?>
                <img src="../<?= htmlspecialchars($data['REF_IMAGE']) ?>" style="max-width:250px;border-radius:8px;">
            <?php else: ?>
                <p>N/A</p>
            <?php endif; ?>
        </div>

        <div class="row"><label>Budget:</label>
            <div><?= $data['BUDGET'] ? 'RM ' . number_format($data['BUDGET'], 2) : 'N/A' ?></div>
        </div>

        <!-- DESCRIPTION -->
        <h3>Description</h3>

        <div class="row">
            <div style="background:#f9f9f9;padding:10px;border-radius:6px;">
                <?= nl2br(htmlspecialchars($data['CUSTOM_DES'])) ?>
            </div>
        </div>

        <!-- DELIVERY -->
        <h3>Delivery Details</h3>

        <div class="row"><label>Delivery Date:</label>
            <div><?= date("d M Y", strtotime($data['DELIVERY_DATE'])) ?></div>
        </div>

        <div class="row"><label>Delivery Time:</label>
            <div><?= htmlspecialchars($data['DELIVERY_SLOT']) ?></div>
        </div>

        <div class="row"><label>Delivery Address:</label>
            <div><?= nl2br(htmlspecialchars($data['RECIPIENT_ADDR'])) ?></div>
        </div>

        <!-- RECIPIENT INFO -->
        <h3>Recipient Info
            <span style="font-size:12px;color:#888;font-weight:normal;">
                (Actual recipient of the custom cake)
            </span>
        </h3>

        <div class="row"><label>Recipient Name:</label>
            <div><?= htmlspecialchars($data['RECIPIENT_NAME']) ?></div>
        </div>

        <div class="row"><label>Recipient Email:</label>
            <div><?= htmlspecialchars($data['RECIPIENT_EMAIL']) ?></div>
        </div>

        <div class="row"><label>Recipient Phone:</label>
            <div><?= htmlspecialchars($data['RECIPIENT_PHONE']) ?></div>
        </div>

        <!-- ACTION -->
        <h3>Action</h3>

        <div class="row">
            <span class="badge status-<?= strtolower($data['STATUS']) ?>">
                <?= $data['STATUS'] ?>
            </span>
        </div>

        <!-- PROCESS FORM: only if Pending and NOT expired -->
        <?php if ($data['STATUS'] == 'Pending' && !$isExpired): ?>

        <form method="POST">

            <div class="row">
                <label>Quoted Price (RM)</label>
                <input type="number" name="quoted_price" id="quoted_price" placeholder="(Not including shipping fee)" step="0.01" min="0">
            </div>

            <div class="row">
                <label>Response Deadline</label>
                <input type="datetime-local" name="response_deadline" id="response_deadline"
                       max="<?= date('Y-m-d\TH:i', strtotime($data['DELIVERY_DATE'] . ' -1 day 23:59')) ?>">
                </div>

            <div class="row">
                <label>Update Status</label>
                <select name="status" id="status" required>
                    <option value="Quoted">Quoted</option>
                    <option value="Rejected">Rejected</option>
                </select>
            </div>

            <button class="btn btn-save" type="submit">Submit</button>
        </form>

        <?php else: ?>

            <!-- Show quoted price and deadline for already processed requests -->
            <?php if (in_array($data['STATUS'], ['Quoted', 'Expired', 'Accepted'])): ?>
                <label>Quoted Price: </label>
                <span style="font-weight:bold;color:green;">RM <?= number_format($data['QUOTED_PRICE'], 2) ?></span>

                <br><br>

                <label>Response Deadline: </label>
                <span style="font-weight:bold;color:#c0392b;"> <?= !empty($data['RESPONSE_DEADLINE']) ? date("d M Y, h:i A", strtotime($data['RESPONSE_DEADLINE'])) : 'N/A' ?></span>

            <?php endif; ?>

            <!-- Show specific messages for expired/quoted/accepted/rejected -->
            <div class="custom-result">
                <?php if ($data['STATUS'] == 'Expired'): ?>
                    <p style="color:#6b21a8;font-weight:bold;">
                        ⚠ This request has expired and no further action can be taken.
                    </p>

                <?php elseif ($data['STATUS'] == 'Quoted'): ?>
                    <p>This request has already been quoted.</p>

                <?php elseif ($data['STATUS'] == 'Accepted'): ?>
                    <p>This request has been accepted and <strong>paid</strong> by the customer.</p>

                <?php elseif ($data['STATUS'] == 'Rejected'): ?>
                    <p>
                        This request has been rejected by
                        <strong><?= htmlspecialchars($data['REJECTED_BY'] ?? 'Unknown') ?></strong>.
                    </p>

                <?php else: ?>
                    <p>This request has already been processed.</p>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>
</div>

<script>
// Show toast
function showToast(type, message) {
    const toast = document.createElement("div");
    toast.className = "toast " + type;

    const text = document.createElement("span");
    text.innerText = message;

    const closeBtn = document.createElement("span");
    closeBtn.innerHTML = "×";
    closeBtn.className = "toast-close-btn";

    toast.appendChild(text);
    toast.appendChild(closeBtn);
    document.body.appendChild(toast);

    let removed = false;

    function removeToast() {
        if (removed) return;
        removed = true;
        toast.style.opacity = "0";
        toast.style.transform = "translateX(120%)";
        setTimeout(() => toast.remove(), 300);
    }

    closeBtn.addEventListener("click", function(e) {
        e.stopPropagation();
        removeToast();
    });

    toast.addEventListener("click", removeToast);

    const duration = (type === "error") ? 8000 : 3000;
    setTimeout(removeToast, duration);
}

// Display toast from session
<?php if (isset($_SESSION['toast'])): ?>
document.addEventListener("DOMContentLoaded", function() {
    showToast(
        "<?= $_SESSION['toast']['type'] ?>",
        "<?= addslashes($_SESSION['toast']['message']) ?>"
    );
});
<?php unset($_SESSION['toast']); ?>
<?php endif; ?>

// Interdependent fields logic
const quotedPrice = document.getElementById("quoted_price");
const status      = document.getElementById("status");
const deadline    = document.getElementById("response_deadline");

if (status) {
    function toggleFields() {
        if (status.value === "Quoted") {
            quotedPrice.disabled = false;
            deadline.disabled    = false;
        } else {
            quotedPrice.value    = "";
            quotedPrice.disabled = true;
            deadline.value       = "";
            deadline.disabled    = true;
        }
    }

    quotedPrice.addEventListener("input", function() {
        if (quotedPrice.value.trim() !== "") {
            status.value = "Quoted";
            toggleFields();
        }
    });

    status.addEventListener("change", toggleFields);
    toggleFields();
}

// Deadline min value logic
function updateDeadlineMin() {
    const now = new Date(Date.now() + 5 * 60 * 1000); // current time + 5 minutes
    const pad = n => String(n).padStart(2, '0');
    const minVal = `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    if (deadline) deadline.min = minVal;
}

updateDeadlineMin();
setInterval(updateDeadlineMin, 60000); // Update every minute
</script>

</body>
</html>