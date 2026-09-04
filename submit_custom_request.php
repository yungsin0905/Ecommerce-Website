<?php
include 'include/config.php';
 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
ini_set('display_errors', 1);
error_reporting(E_ALL);
 
// check login status
if (!isset($_SESSION['CUSTOMER_ID'])) {
    header("Location: login.php");
    exit();
}
 
// post submission check
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_request'])) {
    header("Location: Customise.php");
    exit();
}

//retrieve form data
$customer_id     = $_SESSION['CUSTOMER_ID'];
$recipient_name  = trim($_POST['RECIPIENT_NAME']  ?? '');
$recipient_email = trim($_POST['RECIPIENT_EMAIL'] ?? '');
$country_code    = trim($_POST['COUNTRY_CODE']    ?? '+60');
$phone_number    = trim($_POST['PHONE_NUMBER']    ?? '');
$recipient_phone = $country_code . $phone_number;
$delivery_date   = trim($_POST['DELIVERY_DATE']   ?? '');
$cater_count     = (int) ($_POST['CATER_COUNT']   ?? 0);
$size            = trim($_POST['SIZE']            ?? '');
$quantity     = (int) ($_POST['QUANTITY']   ?? 0);
$ideal_flavour   = trim($_POST['IDEAL_FLAVOUR']   ?? '');
$recipient_addr  = trim($_POST['RECIPIENT_ADDR']  ?? '');
$city            = trim($_POST['CITY']            ?? '');
$custom_des      = trim($_POST['CUSTOM_DES']      ?? '');
$cake_style_name = trim($_POST['CAKE_STYLE']      ?? '');
$budget_raw      = trim($_POST['BUDGET']          ?? '');
$budget          = ($budget_raw !== '') ? (float) preg_replace('/[^0-9.]/', '', $budget_raw) : 0.00;
$slot_id = (int) ($_POST['SLOT_ID'] ?? 0);

//check the bakery operation settings
$setting_result = $conn->query("SELECT OPEN_DAYS, OPEN_TIME, CLOSE_TIME FROM bakery_info WHERE BAKERY_ID = 1 LIMIT 1");
// Error handling: if the query failed (e.g. SQL error) or returned no rows
// (e.g. no matching BAKERY_ID record), redirect back to the form
if (!$setting_result || $setting_result->num_rows === 0) {
    $_SESSION['form_errors'] = ["System error: unable to load bakery settings. Please try again."];
    $_SESSION['form_old'] = $_POST;
    header("Location: Customise.php");
    exit();
}

$settings = $setting_result->fetch_assoc();

$open_days_arr  = explode(',', $settings['OPEN_DAYS']);
$open_time      = $settings['OPEN_TIME'];
$close_time     = $settings['CLOSE_TIME'];

//form validation
$errors = [];

//verify the fields cannnot be null
if ($recipient_name === '')
    $errors[] = "Recipient name is required.";
 
if ($recipient_email === '' || !filter_var($recipient_email, FILTER_VALIDATE_EMAIL))
    $errors[] = "A valid recipient email is required.";
 
if ($phone_number === '')
    $errors[] = "Recipient phone is required.";
 
if ($delivery_date === '')
    $errors[] = "Delivery date is required.";

if ($budget <= 0)
    $errors[] = "Budget is required.";

//delivery date must be before delivery date 3 days
$min_date = date('Y-m-d', strtotime('+3 days'));
if ($delivery_date !== '' && $delivery_date < $min_date)
    $errors[] = "Custom cakes must be ordered at least 3 days in advance.";


//operation datetime validation
if ($delivery_date !== '') {
    $delivery_ts = strtotime($delivery_date);
    $day_of_week = date('D', $delivery_ts);       
    $dow_php    = (int) date('w', $delivery_ts); 
    $dow_num    = $dow_php === 0 ? 7 : $dow_php; // convert to 1=Mon,...,7=Sun for SQL query
    $safe_date   = $conn->real_escape_string($delivery_date);

    //checking operation day
    if (!in_array($day_of_week, $open_days_arr)) {
        $errors[] = "We are closed on {$day_of_week}. Please select an open day (" . implode(', ', $open_days_arr) . ").";
    }

    //checking delivery rules
    $rule_date_row = $conn->query("SELECT REASON FROM delivery_date_rules 
        WHERE STATUS = 'Active' AND DATE = '$safe_date' LIMIT 1")->fetch_assoc();
    if ($rule_date_row) {
        $errors[] = "This date is unavailable: " . $rule_date_row['REASON'];
    }

    //checking delivery rules by day of week
    $rule_dow_row = $conn->query("SELECT REASON FROM delivery_date_rules 
        WHERE STATUS = 'Active' AND DAY_OF_WEEK = $dow_num AND DATE IS NULL LIMIT 1")->fetch_assoc();
    if ($rule_dow_row) {
        $errors[] = "Orders on {$day_of_week} are unavailable: " . $rule_dow_row['REASON'];
    }
}

if ($slot_id <= 0)
        $errors[] = "Please select a delivery time slot.";

if ($cater_count <= 0)
    $errors[] = "Cater count must be a positive number.";

if ($quantity <= 0)
    $errors[] = "Quantity must be a positive number.";

if ($recipient_addr === '')
    $errors[] = "Delivery address is required.";
 
if (mb_strlen($recipient_addr) > 50)
    $errors[] = "Address must not exceed 50 characters.";
 
if ($city === '')
    $errors[] = "City is required.";
 
if ($custom_des === '')
    $errors[] = "Custom design description is required.";
 
if (mb_strlen($custom_des) >200)
    $errors[] = "Custom design description must not exceed 200 characters.";
 
if ($cake_style_name === '')
    $errors[] = "Cake style is required.";
 
if (!empty($errors)) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

//delivery slot validation
$slot_result = $conn->query("SELECT SLOT_ID, START_TIME, END_TIME FROM delivery_slots WHERE SLOT_ID = $slot_id AND STATUS = 'Active' LIMIT 1");
$slot_row = $slot_result->fetch_assoc();

if (!$slot_row) {
    $_SESSION['form_errors'] = ["Selected time slot is invalid or no longer available."];
    $_SESSION['form_old'] = $_POST;
    header("Location: Customise.php");
    exit();
}

//check cake style id
$safe_style_name = mysqli_real_escape_string($conn, $cake_style_name);
$style_query = $conn->query("SELECT STYLE_ID, STYLE_NAME FROM cake_style WHERE STYLE_NAME = '$safe_style_name'
AND STATUS = 'Active' LIMIT 1");

$style_result = $style_query->fetch_assoc();

if (!$style_result){
  $_SESSION['form_errors'] = ["Selected cake style is invalid or no longer available."];
  $_SESSION['form_old'] = $_POST;
  header('Location: Customise.php');
  exit();
}

$style_id = $style_result['STYLE_ID'];
$style_name_snapshot = $style_result['STYLE_NAME'];

//check deilivery_coverage
$safe_city = $conn->real_escape_string($city);
$coverage_query = $conn->query("SELECT POSTCODE, STATE FROM delivery_coverage WHERE CITY = '$safe_city' 
AND STATUS = 'Active' LIMIT 1");
$coverage_result = $coverage_query->fetch_assoc();

if (!$coverage_result) {
    $_SESSION['form_errors'] = ["Selected city is not within our delivery coverage area."];
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

$postcode = $coverage_result['POSTCODE'];
$state = $coverage_result['STATE'];

//image upload handling
$ref_image_path = null;

if (isset($_FILES['REF_IMAGE']) && $_FILES['REF_IMAGE']['error']===UPLOAD_ERR_OK) {

$allowed_mime = ['image/jpg','image/jpeg','image/png'];
$file_mime = mime_content_type($_FILES['REF_IMAGE']['tmp_name']);
$file_size = $_FILES['REF_IMAGE']['size'];
$max_size = 5 * 1024 * 1024; //5MB

//if the submitted file does not meet the requirements
if (!in_array($file_mime, $allowed_mime)) {
        $_SESSION['form_errors'] = ["Reference image must be JPG, JPEG or PNG."];
        $_SESSION['form_old']    = $_POST;
        header("Location: Customise.php");
        exit();
    }


 if ($file_size > $max_size) {
        $_SESSION['form_errors'] = ["Reference image must not exceed 5 MB."];
        $_SESSION['form_old']    = $_POST;
        header("Location: Customise.php");
        exit();
  }

  $upload_dir = 'image/custom/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
 
    $ext         = strtolower(pathinfo($_FILES['REF_IMAGE']['name'], PATHINFO_EXTENSION));
    $unique_name = 'ref_' . $customer_id . '_' . time() . '.' . $ext;
    $destination = $upload_dir . $unique_name;
 
    if (!move_uploaded_file($_FILES['REF_IMAGE']['tmp_name'], $destination)) {
        $_SESSION['form_errors'] = ["Failed to upload reference image. Please try again."];
        $_SESSION['form_old']    = $_POST;
        header("Location: Customise.php");
        exit();
    }
 
    $ref_image_path = $destination;

} elseif (isset($_FILES['REF_IMAGE']) && $_FILES['REF_IMAGE']['error'] !== UPLOAD_ERR_NO_FILE) {
    $_SESSION['form_errors'] = ["Image upload error (code: " . $_FILES['REF_IMAGE']['error'] . "). Please try again."];
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

// Insert into custom table — sanitize and type-cast all form fields before building the SQL query
//escape variables
$esc_customer_id        = (int) $customer_id;
$esc_recipient_name     = $conn->real_escape_string($recipient_name);
$esc_recipient_email    = $conn->real_escape_string($recipient_email);
$esc_recipient_phone    = $conn->real_escape_string($recipient_phone);
$esc_cater_count        = (int) $cater_count;
$esc_quantity           = (int) $quantity;
$esc_style_id           = (int) $style_id;
$esc_size               = $conn->real_escape_string($size);
$esc_delivery_date      = $conn->real_escape_string($delivery_date);
$esc_delivery_slot      = $conn->real_escape_string(
    date('H:i', strtotime($slot_row['START_TIME'])) . ' - ' . 
    date('H:i', strtotime($slot_row['END_TIME']))
); 
$esc_custom_des         = $conn->real_escape_string($custom_des);
$esc_ref_image          = $ref_image_path !== null ? "'" . $conn->real_escape_string($ref_image_path) . "'" : "NULL";
$esc_ideal_flavour      = $conn->real_escape_string($ideal_flavour);
$full_address           = $recipient_addr . ", " . $city . ", " . $state . " " . $postcode;
$esc_recipient_addr     = $conn->real_escape_string($full_address);
$esc_budget             = (float) $budget;
$esc_style_name_snap    = $conn->real_escape_string($style_name_snapshot);

//checking production capacity
$delivery_date_only = date('Y-m-d', strtotime($delivery_date));
$capacity_query = "SELECT CAPACITY_ID, MAX_CAKES, ALREADY_BOOKED 
                   FROM production_capacity 
                   WHERE PRODUCTION_DATE = '$delivery_date_only' 
                   LIMIT 1";
$capacity_result = $conn->query($capacity_query);
$capacity_row = $capacity_result->fetch_assoc();

//cannnot choose date with no capacity record
if (!$capacity_row) {
    $_SESSION['form_errors'] = ["Sorry, " . date('d/m/Y', strtotime($delivery_date_only)) . " is not available for delivery. Please choose another date."];
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

//check if capacity is full
if ($capacity_row['ALREADY_BOOKED'] >= $capacity_row['MAX_CAKES']) {
    $_SESSION['form_errors'] = ["Sorry, " . date('d/m/Y', strtotime($delivery_date_only)) . " is fully booked. Please choose another date."];
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

//insert the order into custom table
$custom_sql = "INSERT INTO custom (
                    CUSTOMER_ID, RECIPIENT_NAME, RECIPIENT_EMAIL, RECIPIENT_PHONE,
                    CATER_COUNT, STYLE_ID, SIZE, QUANTITY, DELIVERY_DATE, DELIVERY_SLOT,
                    CUSTOM_DES, REF_IMAGE, IDEAL_FLAVOUR, RECIPIENT_ADDR,
                    STATUS, BUDGET, QUOTED_PRICE, IS_DELETED, STYLE_NAME_SNAPSHOT
               ) VALUES (
                    '$esc_customer_id', '$esc_recipient_name', '$esc_recipient_email', '$esc_recipient_phone',
                    '$esc_cater_count', '$esc_style_id', '$esc_size', '$esc_quantity', '$esc_delivery_date', '$esc_delivery_slot',
                    '$esc_custom_des', $esc_ref_image, '$esc_ideal_flavour', '$esc_recipient_addr',
                    'Pending', '$esc_budget', NULL, 0, '$esc_style_name_snap'
               )";

$custom_result = $conn->query($custom_sql);

//error handling
if (!$custom_result) {
    error_log("custom_order insert error: " . $conn->error);
    $_SESSION['form_errors'] = ["Failed to submit your request. Please try again."];
    $_SESSION['form_old']    = $_POST;
    header("Location: Customise.php");
    exit();
}

// CREATE NOTIFICATION -> (Admin)
// Get CUSTOM_ID immediately after custom insert
$new_custom_id = $conn->insert_id;

// Insert shared notification
$notif_sql = "
INSERT INTO notification
(TYPE, REF_ID, MESSAGE)
VALUES
('Custom Request', $new_custom_id, 'You have one new custom request')
";

$conn->query($notif_sql);

// Get notification ID
$notif_id = $conn->insert_id;

// Create unread notification record for every admin
$conn->query("
    INSERT INTO admin_notification (ADMIN_ID, NOTIF_ID, IS_READ)
    SELECT ADMIN_ID, $notif_id, 0
    FROM admin
");

//update product capacity
if ($capacity_row) {
    $cap_id = $capacity_row['CAPACITY_ID'];
    $conn->query("UPDATE production_capacity 
                  SET ALREADY_BOOKED = ALREADY_BOOKED + 1 
                  WHERE CAPACITY_ID = $cap_id");
}

//redirect to confirmation page
unset($_SESSION['form_errors'], $_SESSION['form_old']);
$_SESSION['form_success'] = "Your custom cake request (#" . $new_custom_id . ") has been submitted successfully! We'll get back to you with a quote soon.";
header("Location: Customise.php");
exit();


?>