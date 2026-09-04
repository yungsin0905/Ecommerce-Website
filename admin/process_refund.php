<?php
require_once("config.php");
session_start();

//email
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'include/vendor/Exception.php';
require 'include/vendor/PHPMailer.php';
require 'include/vendor/SMTP.php';

// Get refund amount
$refund_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;

// refund process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get info
    $order_id = intval($_POST['order_id']);
    $admin_reason = trim($_POST['reason']);
    $admin_id = $_SESSION['admin_id'] ?? 1;

    // Reason required
    if (empty($admin_reason)) {
        die("Reason is required");
    }

    $conn->begin_transaction();

    // start refund
    try {

        // 1. admin input amount
        $input_amount = isset($_POST['refund_amount']) ? floatval($_POST['refund_amount']) : 0;

        // 2. get latest refund request (if exists)
        $reqStmt = $conn->prepare("
            SELECT rr.REQUEST_ID, r.REFUND_AMOUNT
            FROM refund_request rr
            LEFT JOIN refund r 
                ON rr.REQUEST_ID = r.REQUEST_ID
            WHERE rr.ORDER_ID = ?
            AND rr.REQUEST_STATUS = 'APPROVED'
            ORDER BY rr.CREATED_AT DESC
            LIMIT 1
        ");

        $reqStmt->bind_param("i", $order_id);
        $reqStmt->execute();
        $req = $reqStmt->get_result()->fetch_assoc();

        // 3. FINAL AMOUNT DECISION (IMPORTANT FIX)
        if (!empty($req) && $req['REFUND_AMOUNT'] !== null) {
            $amount = (float)$req['REFUND_AMOUNT'];   // request exists → DB value
        } else {
            $amount = $input_amount;                  // no request → admin input
        }

        $sql = "
            SELECT 
                CUSTOMER_ID,
                TOTAL_AMOUNT,
                ORDER_STATUS,
                PAYMENT_ID,
                ORDER_NO,
                ORDER_TYPE,
                CREATED_AT   
            FROM orders
            WHERE ORDER_ID = ?
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $order_id);
        $stmt->execute();

        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) {
            throw new Exception("Order not found");
        }

        // When submit, check if order status = refunded, cannot refund again.
        if ($order['ORDER_STATUS'] === 'REFUNDED') {
            throw new Exception("Already refunded");
        }

        $cust_id = $order['CUSTOMER_ID'];
        $pay_id  = $order['PAYMENT_ID'];

        // 3. validation 
        // Cannot be negative
        if ($amount <= 0) {
            throw new Exception("Invalid refund amount");
        }
        // Cannot exceed refund amount
        if ($amount > $order['TOTAL_AMOUNT']) {
           throw new Exception("Refund exceeds order amount");
        }

        // Check existing refund
        $check = $conn->prepare("
            SELECT REFUND_ID
            FROM refund
            WHERE ORDER_ID = ?
            LIMIT 1
        ");

        $check->bind_param("i", $order_id);
        $check->execute();
        $existingRefund = $check->get_result()->fetch_assoc();

        // SIMULATION: refund status always is successful
        $refundStatus = 'SUCCESSFUL';

        if ($refundStatus === 'SUCCESSFUL') {

            // Get order info
            $sql = "
                SELECT DELIVERY_DATE, ORDER_STATUS
                FROM orders
                WHERE ORDER_ID = ?
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $order_id);
            $stmt->execute();
            $orderInfo = $stmt->get_result()->fetch_assoc();

            if (!$orderInfo) {
                throw new Exception("Order not found");
            }

            $delivery_date = $orderInfo['DELIVERY_DATE'];
            $orderStatus = $orderInfo['ORDER_STATUS'];

            $today = date('Y-m-d');

            // Check allowed - Reduce --ALREADY BOOKED-- amount (production capacity).
            // Only if order is processing (haven't start to make the cake) then can reduce.
            $createdDate = date('Y-m-d', strtotime($order['CREATED_AT']));

            $can_reduce_capacity =
            ($refundStatus === 'SUCCESSFUL')
            &&
            ($orderStatus === 'PROCESSING')
            &&
            (
                $order['ORDER_TYPE'] === 'Custom'
                ||
                $delivery_date !== $createdDate
            );

            // If valid, then reduce
            if ($can_reduce_capacity) {

                if ($order['ORDER_TYPE'] === 'Custom') {
                    // Custom order then -1
                    $reduce_qty = 1;
                } else {
                    // Normal order -quantity of items
                    $itemStmt = $conn->prepare("
                        SELECT SUM(QUANTITY) AS total_qty
                        FROM order_item
                        WHERE ORDER_ID = ?
                    ");
                    $itemStmt->bind_param("i", $order_id);
                    $itemStmt->execute();
                    $item = $itemStmt->get_result()->fetch_assoc();
                    $reduce_qty = (int)($item['total_qty'] ?? 0);
                }

                if ($reduce_qty > 0) {
                    $capStmt = $conn->prepare("
                        UPDATE production_capacity
                        SET ALREADY_BOOKED = GREATEST(ALREADY_BOOKED - ?, 0) 
                        WHERE PRODUCTION_DATE = ?
                    ");
                    $capStmt->bind_param("is", $reduce_qty, $delivery_date);
                    $capStmt->execute();
                }
            }

            // Update order status to REFUNDED 
            $up_order = "
                UPDATE orders
                SET ORDER_STATUS = 'REFUNDED',
                    REFUND_DATE = NOW()
                WHERE ORDER_ID = ?
            ";

            $stmt_up = $conn->prepare($up_order);
            $stmt_up->bind_param("i", $order_id);
            $stmt_up->execute();

            // Update payment status to REFUNDED
            $up_payment = "
                UPDATE payment
                SET PAYMENT_STATUS = 'REFUNDED'
                WHERE PAYMENT_ID = ?
            ";

            $stmt_pay = $conn->prepare($up_payment);
            $stmt_pay->bind_param("i", $pay_id);
            $stmt_pay->execute();

            // Refund money back to the customer --WALLET--
            $sql_customer = "
                SELECT WALLET_BALANCE, TOTAL_SPENT
                FROM customer
                WHERE CUSTOMER_ID = ?
                FOR UPDATE
            ";

            $stmt_c = $conn->prepare($sql_customer);
            $stmt_c->bind_param("i", $cust_id);
            $stmt_c->execute();

            $customerData = $stmt_c->get_result()->fetch_assoc();

            $curr_bal = (float)($customerData['WALLET_BALANCE'] ?? 0);
            $curr_total_spent = (float)($customerData['TOTAL_SPENT'] ?? 0);

            // New wallet balance
            $new_bal = $curr_bal + $amount;

            // New total spent
            $new_total_spent = max(0, $curr_total_spent - $amount);

            // Update customer
            $up_customer = "
                UPDATE customer
                SET 
                    WALLET_BALANCE = ?,
                    TOTAL_SPENT = ?
                WHERE CUSTOMER_ID = ?
            ";

            $stmt_up_customer = $conn->prepare($up_customer);

            $stmt_up_customer->bind_param(
                "ddi",
                $new_bal,
                $new_total_spent,
                $cust_id
            );

            $stmt_up_customer->execute();

            // wallet transaction
            $ins_trans = "
            INSERT INTO wallet_transaction (
                CUSTOMER_ID,
                ORDER_ID,
                TYPE,
                TOPUP_METHODS,
                AMOUNT,
                BEFORE_BALANCE,
                AFTER_BALANCE
            )
            VALUES (?, ?, 'REFUND', 'E-WALLET', ?, ?, ?)
            ";

            $stmt_t = $conn->prepare($ins_trans);

            $stmt_t->bind_param(
                "iiddd",
                $cust_id,
                $order_id,
                $amount,
                $curr_bal,
                $new_bal
            );

            $stmt_t->execute();
        }

        // Insert info into 'Refund' table
        if ($existingRefund) {

            $updateRefund = $conn->prepare("
                UPDATE refund
                SET
                    REFUND_STATUS = ?,
                    ADMIN_ID = ?,
                    REASON = ?
                WHERE REFUND_ID = ?
            ");

            $updateRefund->bind_param(
                "sisi",
                $refundStatus,
                $admin_id,
                $admin_reason,
                $existingRefund['REFUND_ID']
            );

            $updateRefund->execute();

        } else {

            $insertRefund = $conn->prepare("
                INSERT INTO refund (
                    ORDER_ID,
                    PAYMENT_ID,
                    CUSTOMER_ID,
                    ADMIN_ID,
                    REASON,
                    REFUND_AMOUNT,
                    REFUND_STATUS,
                    CREATED_AT
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $insertRefund->bind_param(
                "iiiisds",
                $order_id,
                $pay_id,
                $cust_id,
                $admin_id,
                $admin_reason,
                $amount,
                $refundStatus
            );

            $insertRefund->execute();
        }

        // ONLY if it has refund request, but admin not yet process it and cancel & refund by himself, then auto change refund request status to rejected.
        $updateReq = $conn->prepare("
            UPDATE refund_request
            SET REQUEST_STATUS = 'REJECTED'
            WHERE ORDER_ID = ?
            AND REQUEST_STATUS = 'PENDING'
        ");

        $updateReq->bind_param("i", $order_id);
        $updateReq->execute();

        $conn->commit();

        // Then send mail if refund successful
        // Get customer email + name
        $custStmt = $conn->prepare("
            SELECT CUSTOMER_NAME, EMAIL
            FROM customer
            WHERE CUSTOMER_ID = ?
        ");
        $custStmt->bind_param("i", $cust_id);
        $custStmt->execute();
        $cust = $custStmt->get_result()->fetch_assoc();

        $is_request_refund = !empty($req); // whether there was a refund request (for email content)

        if ($refundStatus === 'SUCCESSFUL' && $cust) {

            $bakeryResult = mysqli_query($conn, "SELECT * FROM bakery_info LIMIT 1");
            $bakery = mysqli_fetch_assoc($bakeryResult);

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
                $mail->addAddress($cust['EMAIL']);

                $mail->isHTML(true);

                if ($is_request_refund) {

                    $mail->Subject = 'Your Refund Has Been Processed';

                    $mail->Body = "
                        <div style='font-family:Arial; line-height:1.6; color:#333;'>

                            <h2 style='color:#e91e63;'>".$bakery['SHOP_NAME']."</h2>

                            <p>Dear ".htmlspecialchars($cust['CUSTOMER_NAME']).",</p>

                            <p>
                                Your refund for Order <strong>#{$order['ORDER_NO']}</strong>
                                has been <b style='color:green;'>successfully processed</b>.
                            </p>

                            <p><strong>Refund Amount:</strong> RM ".number_format($amount,2)."</p>

                            <p>The amount has been credited back to your wallet.</p>

                            <hr>

                            <p>Thank you for your patience.</p>

                            <p>
                                Regards,<br>
                                <b>{$bakery['SHOP_NAME']} Team</b>
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

                    } else {

                        $mail->Subject = 'Your Order Has Been Cancelled & Refunded';

                        $mail->Body = "
                            <div style='font-family:Arial; line-height:1.6; color:#333;'>

                            <h2 style='color:#e91e63;'>".$bakery['SHOP_NAME']."</h2>

                            <p>Dear ".htmlspecialchars($cust['CUSTOMER_NAME']).",</p>

                            <p>
                                We are sorry that your Order 
                                <b>#{$order['ORDER_NO']}</b> 
                                has been cancelled by our admin.
                            </p>

                            <p>
                                <b>Refund Amount:</b> 
                                RM " . number_format($amount,2) . "
                            </p>

                            <p>
                                <b>Reason for Cancellation:</b><br>
                                " . nl2br(htmlspecialchars($admin_reason)) . "
                            </p>

                            <p>
                                The amount has been refunded to your wallet.
                            </p>

                            <hr>

                            <p>
                                Thank you for your understanding. 
                                If you have any questions, please feel free to contact us.
                            </p>

                            <p>
                                Regards,<br>
                                <b>{$bakery['SHOP_NAME']} Team</b>
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
                }

                    $mail->send();

                } catch (Exception $e) {
                    error_log("Refund email failed: " . $mail->ErrorInfo);
                }
            }

            echo "ok";

        } catch (Exception $e) {

            $conn->rollback();
            echo $e->getMessage();
        }
    }
?>