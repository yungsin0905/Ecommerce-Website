<?php
require_once("config.php");
session_start();

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

$id = $_GET['request_id'] ?? null;

if (!$id) {
    die("Invalid Refund ID");
}

// Get bakery info
$bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
$bakery = mysqli_fetch_assoc($bakeryResult);

// Fetch refund request info
$stmt = $conn->prepare("
    SELECT r.*, 
           o.ORDER_NO, 
           o.ORDER_STATUS,

           c.CUSTOMER_NAME, 
           c.EMAIL,

           p.PAYMENT_ID,
           p.PAYMENT_AMOUNT,

           rf.REFUND_STATUS,
           rf.REFUND_AMOUNT

    FROM refund_request r

    JOIN orders o 
        ON r.ORDER_ID = o.ORDER_ID

    JOIN customer c 
        ON r.CUSTOMER_ID = c.CUSTOMER_ID

    LEFT JOIN payment p 
        ON r.ORDER_ID = p.ORDER_ID

    LEFT JOIN refund rf 
        ON r.REQUEST_ID = rf.REQUEST_ID

    WHERE r.REQUEST_ID = ?
");

$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("Refund request not found");
}

// Update process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? null;

    /* APPROVE */
    if ($action == 'approve') {

        $refundAmount = (float)($_POST['refund_amount'] ?? 0);

        /* validation */
        if (
            $refundAmount <= 0 ||
            $refundAmount > $data['PAYMENT_AMOUNT']
        ) {
            $_SESSION['toast'] = [
               'type' => 'error',
               'message' => 'Invalid refund amount.'
            ];

            header("Location: process_refund_request.php?request_id=$id");
            exit;
          }

        // Update status
        $stmt = $conn->prepare("
            UPDATE refund_request
            SET REQUEST_STATUS = 'APPROVED',
                UPDATED_AT = NOW()
            WHERE REQUEST_ID = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // ONLY If approved, insert data into refund table for later refund process
        $insert = $conn->prepare("
            INSERT INTO refund (
                REQUEST_ID,
                ORDER_ID,
                PAYMENT_ID,
                CUSTOMER_ID,
                REFUND_AMOUNT,
                REFUND_STATUS,
                CREATED_AT
            )
           VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())
        ");

        /* Data need for insert */
        $requestId   = $id;
        $orderId     = $data['ORDER_ID'];
        $paymentId   = $data['PAYMENT_ID'];
        $customerId  = $data['CUSTOMER_ID'];
        $amount      = $refundAmount;

        $insert->bind_param(
            "iiiid",
            $requestId,
            $orderId,
            $paymentId,
            $customerId,
            $amount
        );

        $insert->execute();

        // Then send email to customer
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

            $mail->Subject = 'Refund Request Approved';

            $mail->Body = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>

                    <h2 style='color:#e91e63;'>".$bakery['SHOP_NAME']."</h2>

                    <p>Dear ".htmlspecialchars($data['CUSTOMER_NAME']).",</p>

                    <p>
                        Your refund request for Order
                        <strong>".htmlspecialchars($data['ORDER_NO'])."</strong>
                        has been approved.
                    </p>

                    <p>
                        <strong>Refund Amount:</strong> RM ".
                        number_format($refundAmount, 2)."
                    </p>

                    <p>
                        Our team will process the refund shortly.
                    </p>

                    <hr style='border:none;border-top:1px solid #ddd;margin:20px 0;'>

                    <p>Thank you for your patience and support.</p>

                    <br>

                    <p>
                        Best Regards,<br>
                        <strong>".$bakery['SHOP_NAME']." Team</strong>
                    </p>

                    <br>

                    <p>
                        ".$bakery['ADDRESS'].", 
                        ".$bakery['POSTCODE']." 
                        ".$bakery['CITY'].", 
                        ".$bakery['STATE']."<br>

                        Phone: ".$bakery['PHONE']."<br>

                        Email: ".$bakery['EMAIL']."
                    </p>

                </div>
            ";

            $mail->send();

        } catch (Exception $e) {

            error_log($mail->ErrorInfo);
        }

        $_SESSION['toast'] = [
            'type' => 'success',
             'message' => 'Refund approved successfully.'
        ];

        header("Location: process_refund_request.php?request_id=$id");
        exit;
    }

    /* REJECT */
    if ($action == 'reject') {

        // Update status
        $stmt = $conn->prepare("
            UPDATE refund_request
            SET REQUEST_STATUS = 'REJECTED',
                UPDATED_AT = NOW()
            WHERE REQUEST_ID = ?
        ");
        $stmt->bind_param("i", $id);
        $stmt->execute();

        // Then send email to customer
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

            $mail->Subject = 'Refund Request Update';

            $mail->Body = "
                <div style='font-family:Arial,sans-serif;line-height:1.6;color:#333;'>

                    <h2 style='color:#e91e63;'>".$bakery['SHOP_NAME']."</h2>

                    <p>Dear ".htmlspecialchars($data['CUSTOMER_NAME']).",</p>

                    <p>
                        We regret to inform you that your refund request
                        for Order
                        <strong>".htmlspecialchars($data['ORDER_NO'])."</strong>
                        has been <span style='color:#dc3545;font-weight:bold;'>rejected</span>.
                    </p>

                    <p>
                        If you need further clarification,
                        please contact our support team.
                    </p>

                    <hr style='border:none;border-top:1px solid #ddd;margin:20px 0;'>

                    <p>We appreciate your understanding.</p>

                    <br>

                    <p>
                        Best Regards,<br>
                        <strong>".$bakery['SHOP_NAME']." Team</strong>
                    </p>

                    <br>

                    <p>
                        ".$bakery['ADDRESS'].", 
                        ".$bakery['POSTCODE']." 
                        ".$bakery['CITY'].", 
                        ".$bakery['STATE']."<br>

                        Phone: ".$bakery['PHONE']."<br>

                        Email: ".$bakery['EMAIL']."
                    </p>

                </div>
            ";

            $mail->send();
 
        } catch (Exception $e) {

            error_log($mail->ErrorInfo);
        }

        $_SESSION['toast'] = [
            'type' => 'success',
            'message' => 'Refund request rejected.'
        ];

        header("Location: process_refund_request.php?request_id=$id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Requests</title>
    <link rel="stylesheet" href="admin_global.css">
<style>
body { 
    font-family: var(--font-family); 
    background: var(--primary-grey); 
}

h2 {
    margin-bottom: 35px;
}

p {
    margin-bottom: 20px;
}

div {
    margin-bottom: 20px;
}

.form-title {
    display: flex; 
    justify-content: space-between; 
    align-items: center;
}

.form-wrapper {
    max-width: 650px;  
    margin: 40px auto; 
    padding: 0 16px;
    
}

.form-back-link {
    display: inline-flex;
    gap: 6px;
    margin-bottom: 15px;
    margin-left: 0;     
    text-decoration: none;
    color: #6b7280;
    font-size: 14px;
    font-weight: 500;
}

.form-back-link:hover{
    color:#111827;
    text-decoration:underline;
}

.container {
    width: 100%;         
    background: #fff;
    padding: 15px 20px;
    border-radius: 10px;
    box-sizing: border-box; 
    box-shadow: 0 6px 20px rgba(0,0,0,0.08);
}

.status {
    font-weight: bold;
    padding: 5px 10px;
    border-radius: 6px;
}

.PENDING { 
    background: orange; 
    color: white; 
}
        
.APPROVED { 
    background: blue; 
    color: white; 
}

.REJECTED { 
    background: red; 
    color: white; 
}

button {
    padding: 10px 15px;
    margin-top: 10px;
    cursor: pointer;
    border-radius: 6px;
}

button:hover {
    opacity: 0.10;
}

.approve { 
    background: green; 
    color: white; 
    border: none;  
}

.reject { 
    background: red; 
    color: white; 
    border: none;  
}

.next-step {
    margin-top: 20px;
    padding: 12px;
    background: #eef;
    border-radius: 8px;
}

.order-link {
    color:#0d6efd;
    text-decoration:none;
    font-weight:600;
}

.order-link:hover{
    text-decoration: underline;
}

.refund-result {
    margin-top: 20px;
    padding: 4px 10px;
    background: #edecec;
    border-radius: 8px;
}

/* Toast */
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

.toast.error {
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
    background: rgba(255, 255, 255, 0.2);
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
    line-height: 1;
}

.toast-close-btn:hover {
    background: rgba(255, 255, 255, 0.35);
    transform: scale(1.1);
}

.attachment-row {
    display: flex;
    align-items: flex-start;  
    gap: 10px;
}

.attachment-row img {
    max-width: 200px;
    border-radius: 6px;
}
</style>
</head>

<body>
<div class="form-wrapper">
    <a href="manage_refund_request.php" class="form-back-link" title="Go Back To Refund Request Page">← Back</a>

    <div class="container">

        <h2 class="form-title">Refund Request
            <span style="margin-left: 10px;font-size: 14px;font-weight: normal;">Created at: <?= date("d M Y H:i", strtotime($data['CREATED_AT'])) ?></span>
        </h2>

        <p>
            <label>Order No:</label> 
            <a href="view_order.php?order_id=<?= $data['ORDER_ID'] ?>" class="order-link" title="View Order Details">
                <?= htmlspecialchars($data['ORDER_NO']) ?>
            </a>
        </p>

        <p>
            <label>Customer: </label> 
            <?= htmlspecialchars($data['CUSTOMER_NAME']) ?>
        </p>

        <p>
            <label>Email: </label> 
            <?= htmlspecialchars($data['EMAIL']) ?>
        </p>

        <hr><br>

        <p>
            <label>Reason: </label>
            <?= nl2br(htmlspecialchars($data['REASON'])) ?>
        </p>

        <div class="attachment-row">
            <label>Attachment:</label>

            <?php if (!empty($data['ATTACHMENT'])): ?>
                <img src="../<?= htmlspecialchars($data['ATTACHMENT']) ?>">
            <?php else: ?>
                N/A
            <?php endif; ?>
        </div>

        <p>
            <label>Payment Amount: </label> 
            RM <?= $data['PAYMENT_AMOUNT'] ?? 0 ?>
        </p>

        <hr><br>
    
        <p class="status-cont"><label>Status: </label>
            <span class="status <?= $data['REQUEST_STATUS'] ?>">
                <?= $data['REQUEST_STATUS'] ?>
            </span>
        </p>

        <!-- Action area -->
        <?php if ($data['REQUEST_STATUS'] == 'PENDING'): ?>

            <hr>

            <form method="POST">

                <div style="margin-bottom:15px;">
                    <label>Refund Amount (RM):</label><br>

                    <input type="number" name="refund_amount" step="0.01" min="0" max="<?= $data['PAYMENT_AMOUNT'] ?>" value="<?= number_format($data['PAYMENT_AMOUNT'],2,'.','') ?>" required
                    style="padding:8px;width:200px;margin-top:6px;">

                    <div style="margin-top:5px;font-size:13px;color:gray;">
                        Maximum refundable amount:
                        RM <?= number_format($data['PAYMENT_AMOUNT'],2) ?>
                    </div>
                </div>

                <button class="approve" name="action" value="approve" onclick="return confirm('Approve this refund request?')">
                    Approve Refund
                </button>

                <button class="reject" name="action" value="reject" onclick="return confirm('Reject this refund request?')">
                    Reject Refund
                </button>
            </form>

        <?php elseif ($data['REQUEST_STATUS'] === 'APPROVED' && ($data['REFUND_STATUS'] ?? '') !== 'SUCCESSFUL'): ?>

            <div class="next-step">
                <h3>Next Step Required</h3>

                <p>This refund has been approved.</p>

                <p><label>Order No: </label> 
                    <span id="orderNo"><?= htmlspecialchars($data['ORDER_NO']) ?></span>
                </p>

                <button onclick="copyOrderNo()" title="Copy Order No">Copy Order No</button>

                <a href="manage_order.php?search=<?= $data['ORDER_NO'] ?>">
                    <button title="Go to Manage Orders Page">Go to Manage Orders</button>
                </a>

                <p style="margin-top:10px;color:gray;">
                    👉 Need to process the refund
                </p>
            </div>

        <?php else: ?>

            <div class="refund-result">
            
                <?php if ($data['REQUEST_STATUS'] == 'REJECTED'): ?>
                    <p><label>This refund request has been rejected.</label></p>

                <?php elseif (($data['REFUND_STATUS'] ?? '') == 'SUCCESSFUL'): ?>
                    <p><label>This refund has been successfully processed.</label></p>

                    <p>
                        <label>Refund Amount:</label>
                        RM <?= number_format($data['REFUND_AMOUNT'], 2) ?>
                    </p>

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

    closeBtn.addEventListener("click", function (e) {
        e.stopPropagation();
        removeToast();
    });

    toast.addEventListener("click", removeToast);

    const duration = (type === "error") ? 8000 : 3000;
    setTimeout(removeToast, duration);
}

// AUTO SHOW PHP SESSION TOAST
<?php if (isset($_SESSION['toast'])): ?>
document.addEventListener("DOMContentLoaded", function () {
    showToast(
        "<?= $_SESSION['toast']['type'] ?>",
        "<?= $_SESSION['toast']['message'] ?>"
    );
});
<?php unset($_SESSION['toast']); ?>
<?php endif; ?>

// copy order no to clipboard
function copyOrderNo() {
    const text = document.getElementById("orderNo").innerText;
    navigator.clipboard.writeText(text);
    showToast("success", "Order No copied!");
}
</script>

</body>
</html>