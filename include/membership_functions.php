<?php
/**
 * Membership tier maintenance + reward logic.
 * Call check_and_process_membership($conn, $customer_id) once per page load
 * for a logged-in customer (e.g. from include/header.php) to lazily process
 * any due monthly vouchers / quarterly tier maintenance, since this project
 * has no real cron job available.
 */

/**
 * Generate a reasonably unique voucher code.
 */
function generate_voucher_code($prefix) {
    return strtoupper($prefix . '-' . substr(uniqid(), -6));
}

/**
 * Insert a new fixed-amount voucher and immediately grant it to one customer.
 * Returns the new VOUCHER_ID, or false on failure.
 */
function grant_fixed_voucher($conn, $customer_id, $voucher_name, $amount, $voucher_type = 'Public', $tier_id = null, $valid_days = 30) {
    $amount   = floatval($amount);
    $name_esc = mysqli_real_escape_string($conn, $voucher_name);
    $code_esc = mysqli_real_escape_string($conn, generate_voucher_code('AUTO'));
    $type_esc = mysqli_real_escape_string($conn, $voucher_type);
    $tier_val = ($tier_id !== null) ? intval($tier_id) : 'NULL';

    $insert_sql = "INSERT INTO voucher
            (VOUCHER_NAME, VOUCHER_CODE, VOUCHER_TYPE, START_DATE, DISCOUNT_RATE, DISCOUNT_TYPE,
             EXPIRY_DATE, MIN_SPEND, PER_USER_LIMIT, MAX_USAGE, TIER_ID, VOUCHER_STATUS)
        VALUES
            ('$name_esc', '$code_esc', '$type_esc', NOW(), $amount, 'FIXED',
             DATE_ADD(NOW(), INTERVAL $valid_days DAY), 0.00, 1, -1, $tier_val, 'Active')";

    if (!mysqli_query($conn, $insert_sql)) {
        error_log("grant_fixed_voucher: insert voucher failed - " . mysqli_error($conn));
        return false;
    }
    $voucher_id = mysqli_insert_id($conn);

    $cv_sql = "INSERT INTO customer_voucher (CUSTOMER_ID, VOUCHER_ID, USED_COUNT, EXPIRY_DATE, CLAIMED_AT)
               VALUES ($customer_id, $voucher_id, 0, DATE_ADD(CURDATE(), INTERVAL $valid_days DAY), CURDATE())";

    if (!mysqli_query($conn, $cv_sql)) {
        error_log("grant_fixed_voucher: insert customer_voucher failed - " . mysqli_error($conn));
        return false;
    }

    $message = "You received a new voucher: $voucher_name (RM" . number_format($amount, 2) . ")";
    if (mb_strlen($message) > 100) {
        $message = mb_substr($message, 0, 97) . '...';
    }
    notify_customer($conn, $customer_id, 'Voucher', $voucher_id, $message);

    return $voucher_id;
}

/**
 * Main entry point - call this once per page load for the logged-in customer.
 */
function check_and_process_membership($conn, $customer_id) {
    $customer_id = intval($customer_id);

    $sql = "SELECT TIER_ID, TIER_WINDOW_START, QUARTER_SPENT, LAST_TIER_VOUCHER_DATE,
                   MILESTONE_WINDOW_START, MILESTONE_SPENT
            FROM customer WHERE CUSTOMER_ID = $customer_id LIMIT 1";
    $res = mysqli_query($conn, $sql);
    $customer = $res ? mysqli_fetch_assoc($res) : null;
    if (!$customer) return;

    $today = new DateTime();

    // --- Safety net: initialize windows if somehow still NULL (e.g. brand new signup) ---
    if (empty($customer['TIER_WINDOW_START'])) {
        mysqli_query($conn, "UPDATE customer SET TIER_WINDOW_START = CURDATE() WHERE CUSTOMER_ID = $customer_id");
        $customer['TIER_WINDOW_START'] = $today->format('Y-m-d');
    }
    if (empty($customer['MILESTONE_WINDOW_START'])) {
        mysqli_query($conn, "UPDATE customer SET MILESTONE_WINDOW_START = CURDATE() WHERE CUSTOMER_ID = $customer_id");
        $customer['MILESTONE_WINDOW_START'] = $today->format('Y-m-d');
    }

    // --- 1. Quarterly tier maintenance first (tier may change) ---
    process_tier_maintenance($conn, $customer_id, $customer, $today);

    // re-read tier + last voucher date, since maintenance may have changed them
    $fresh_res = mysqli_query($conn, "SELECT TIER_ID, LAST_TIER_VOUCHER_DATE FROM customer WHERE CUSTOMER_ID = $customer_id");
    if ($fresh_res && $fresh = mysqli_fetch_assoc($fresh_res)) {
        $customer['TIER_ID']                = $fresh['TIER_ID'];
        $customer['LAST_TIER_VOUCHER_DATE'] = $fresh['LAST_TIER_VOUCHER_DATE'];
    }

    // --- 2. Monthly tier voucher (based on the up-to-date tier) ---
    process_monthly_tier_voucher($conn, $customer_id, $customer, $today);

    // --- 3. Milestone window expiry ---
    process_milestone_window($conn, $customer_id, $customer, $today);
}

function process_monthly_tier_voucher($conn, $customer_id, $customer, $today) {
    $tier_id = intval($customer['TIER_ID']);

    // If the tier has been deactivated by admin, stop granting its monthly
    // voucher immediately rather than waiting for the quarterly downgrade.
    $tier_res = mysqli_query($conn, "SELECT TIER_NAME, MONTHLY_VOUCHER_AMOUNT FROM membership_tier WHERE TIER_ID = $tier_id AND STATUS = 'Active' LIMIT 1");
    $tier = $tier_res ? mysqli_fetch_assoc($tier_res) : null;
    if (!$tier || $tier['MONTHLY_VOUCHER_AMOUNT'] === null) return; // Classic has no monthly benefit, or tier deactivated

    $due = false;
    if (empty($customer['LAST_TIER_VOUCHER_DATE'])) {
        $due = true; // never granted before
    } else {
        $last = new DateTime($customer['LAST_TIER_VOUCHER_DATE']);
        $next_due = clone $last;
        $next_due->modify('+1 month');
        if ($today >= $next_due) $due = true;
    }

    if ($due) {
        $amount = floatval($tier['MONTHLY_VOUCHER_AMOUNT']);
        $name   = $tier['TIER_NAME'] . ' Monthly Reward - ' . $today->format('M Y');

        $granted = grant_fixed_voucher($conn, $customer_id, $name, $amount, 'Public',null, 30);
        if ($granted) {
            mysqli_query($conn, "UPDATE customer SET LAST_TIER_VOUCHER_DATE = CURDATE() WHERE CUSTOMER_ID = $customer_id");
        }
    }
}

function process_tier_maintenance($conn, $customer_id, $customer, $today) {
    $window_start = new DateTime($customer['TIER_WINDOW_START']);
    $window_end   = clone $window_start;
    $window_end->modify('+3 months');

    if ($today < $window_end) return; // quarter not over yet

    $quarter_spent = floatval($customer['QUARTER_SPENT']);
    $old_tier_id   = intval($customer['TIER_ID']);

    // Find the highest tier this customer's quarter spend actually qualifies for
    $tier_res = mysqli_query($conn,
        "SELECT TIER_ID FROM membership_tier
        WHERE STATUS = 'Active' AND MIN_SPENT <= $quarter_spent
        ORDER BY MIN_SPENT DESC LIMIT 1"
    );
    $new_tier_id = $old_tier_id; // fallback: unchanged
    if ($tier_row = mysqli_fetch_assoc($tier_res)) {
        $new_tier_id = intval($tier_row['TIER_ID']);
    }

    // Apply: update tier (if changed), reset the window and quarter spend
    mysqli_query($conn,
        "UPDATE customer
         SET TIER_ID = $new_tier_id,
             QUARTER_SPENT = 0.00,
             TIER_WINDOW_START = CURDATE()
         WHERE CUSTOMER_ID = $customer_id"
    );

    // If the tier actually changed (upgrade or downgrade), hand off to the
    // existing assignTierVouchers() in config.php - it swaps out unused
    // old-tier vouchers and grants the new tier's active Tier-type vouchers.
    if ($new_tier_id !== $old_tier_id) {

        if (function_exists('assignTierVouchers')) {
            assignTierVouchers($conn, $customer_id, $new_tier_id);
        }

        // Notify the customer their tier has changed
        $old_tier_res = mysqli_query($conn, "SELECT TIER_NAME, MIN_SPENT FROM membership_tier WHERE TIER_ID = $old_tier_id LIMIT 1");
        $old_tier_row = $old_tier_res ? mysqli_fetch_assoc($old_tier_res) : null;

        $new_tier_res = mysqli_query($conn, "SELECT TIER_NAME, MIN_SPENT FROM membership_tier WHERE TIER_ID = $new_tier_id LIMIT 1");
        $new_tier_row = $new_tier_res ? mysqli_fetch_assoc($new_tier_res) : null;

        if ($new_tier_row) {
            $new_tier_name = $new_tier_row['TIER_NAME'];
            $is_upgrade = $old_tier_row && floatval($new_tier_row['MIN_SPENT']) > floatval($old_tier_row['MIN_SPENT']);

            if ($is_upgrade) {
                $message = "Congratulations! You've been upgraded to $new_tier_name tier. Enjoy your new benefits!";
            } else {
                $message = "Your membership tier has been adjusted to $new_tier_name for this period.";
            }

            notify_customer($conn, $customer_id, 'Membership', $new_tier_id, $message);
        }
    }
}

function process_milestone_window($conn, $customer_id, $customer, $today) {
    $window_start = new DateTime($customer['MILESTONE_WINDOW_START']);
    $window_end   = clone $window_start;
    $window_end->modify('+3 months');

    if ($today < $window_end) return; // window not over yet

    // Window expired without reaching the RM1000 milestone - reset, no reward.
    // (If it HAD reached 1000, process_payment.php would already have granted
    //  the reward and reset this window at that moment - see below.)
    mysqli_query($conn,
        "UPDATE customer
         SET MILESTONE_SPENT = 0.00,
             MILESTONE_WINDOW_START = CURDATE()
         WHERE CUSTOMER_ID = $customer_id"
    );
}

/**
 * Insert a notification and deliver it to one customer.
 * Returns the new NOTIF_ID, or false on failure.
 */
function notify_customer($conn, $customer_id, $type, $ref_id, $message) {
    $customer_id = intval($customer_id);
    $ref_id_val  = ($ref_id !== null) ? intval($ref_id) : null;

    $stmt = $conn->prepare("INSERT INTO notification (TYPE, REF_ID, MESSAGE) VALUES (?, ?, ?)");
    $stmt->bind_param("sis", $type, $ref_id_val, $message);
    if (!$stmt->execute()) {
        error_log("notify_customer: insert notification failed - " . $conn->error);
        return false;
    }
    $notif_id = $conn->insert_id;

    $stmt2 = $conn->prepare("INSERT INTO customer_notification (CUSTOMER_ID, NOTIF_ID, IS_READ) VALUES (?, ?, 0)");
    $stmt2->bind_param("ii", $customer_id, $notif_id);
    if (!$stmt2->execute()) {
        error_log("notify_customer: insert customer_notification failed - " . $conn->error);
        return false;
    }

    return $notif_id;
}