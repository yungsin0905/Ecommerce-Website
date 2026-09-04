<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// page title
$pageTitle = "System Settings";

// VALIDATION HELPER
$errors = [];

function addError($field, $msg) {
    global $errors;
    $errors[$field] = $msg;
}

function hasError($field) {
    global $errors;
    return isset($errors[$field]);
}

function getError($field) {
    global $errors;
    return $errors[$field] ?? '';
}

function fieldClass($field) {
    return hasError($field) ? 'form-input input-error' : 'form-input';
}

function modalFieldClass($field) {
    return hasError($field) ? 'modal-input input-error' : 'modal-input';
}

// ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // BAKERY INFO UPDATE 
    if (isset($_POST['update_bakery'])) {

        // Shop Name
        if (empty(trim($_POST['shop_name'])))
            addError('shop_name', 'Shop name is required.');
        elseif (mb_strlen(trim($_POST['shop_name'])) > 100)
            addError('shop_name', 'Shop name must be 100 characters or less.');

        // Phone 
        if (empty(trim($_POST['phone'])))
            addError('phone', 'Phone number is required.');
        elseif (!preg_match('/^(\+?60|0)[1-9][0-9]{7,9}$/', preg_replace('/[\s\-()]/', '', $_POST['phone'])))
            addError('phone', 'Invalid Malaysian phone number (e.g. 011-12345678 or +60112345678).');

        // Email
        if (empty(trim($_POST['email'])))
            addError('email', 'Email address is required.');
        elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL))
            addError('email', 'Invalid email address.');

        // Postcode
        if (empty(trim($_POST['postcode'])))
            addError('postcode', 'Postcode is required.');
        elseif (!preg_match('/^[0-9]{5}$/', trim($_POST['postcode'])))
            addError('postcode', 'Postcode must be exactly 5 digits.');

        // Address
        if (empty(trim($_POST['address'])))
            addError('address', 'Address is required.');

        // City
        if (empty(trim($_POST['city'])))
            addError('city', 'City is required.');

        // State
        if (empty(trim($_POST['state'])))
            addError('state', 'State is required.');

        // Bakery Description
        if (empty(trim($_POST['bakery_des'])))
            addError('bakery_des', 'Bakery description is required.');
        elseif (mb_strlen(trim($_POST['bakery_des'])) > 255)
            addError('bakery_des', 'Description must be 255 characters or less.');

        // Open Time
        if (empty($_POST['open_time']))
           addError('open_time', 'Opening time is required.');

        // Close Time
        if (empty($_POST['close_time']))
            addError('close_time', 'Closing time is required.');
        elseif (!empty($_POST['open_time']) && !empty($_POST['close_time']) && $_POST['close_time'] <= $_POST['open_time'])
            addError('close_time', 'Closing time must be after opening time.');

        // Shop Image
        if (!empty($_FILES['shop_image']['name'])) {
            $allowedExt = ['jpg','jpeg','png','webp'];
            $ext = strtolower(pathinfo($_FILES['shop_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt))
                addError('shop_image', 'Image must be JPG, PNG, or WEBP.');
            elseif ($_FILES['shop_image']['size'] > 2 * 1024 * 1024)
                addError('shop_image', 'Image must be 2MB or less.');
        }

        $shopImage = $_POST['existing_shop_image'] ?? '';

        if (empty($errors) && !empty($_FILES['shop_image']['name'])) {
            $uploadDir  = 'uploads/bakery/';
            $ext        = pathinfo($_FILES['shop_image']['name'], PATHINFO_EXTENSION);
            $newName    = 'shop_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $newName;
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            if (move_uploaded_file($_FILES['shop_image']['tmp_name'], $targetPath)) {
                $shopImage = $targetPath;
            }
        }

        // if no errors, save
        if (empty($errors)) {
            $openDays = !empty($_POST['open_days']) ? implode(',', $_POST['open_days']) : null;
            $stmt = $conn->prepare("
                UPDATE bakery_info
                SET SHOP_NAME=?, PHONE=?, EMAIL=?, ADDRESS=?, CITY=?, STATE=?, POSTCODE=?,
                    SHOP_IMAGE=?, BAKERY_DES=?, OPEN_TIME=?, CLOSE_TIME=?, OPEN_DAYS=?
                WHERE BAKERY_ID=1
            ");
            $stmt->bind_param("ssssssssssss",
                $_POST['shop_name'], $_POST['phone'], $_POST['email'],
                $_POST['address'], $_POST['city'], $_POST['state'],
                $_POST['postcode'], $shopImage,
                $_POST['bakery_des'], $_POST['open_time'], $_POST['close_time'], $openDays
            );
            $stmt->execute();
            header("Location: settings.php?tab=bakery&success=1");
            exit;
        }
        $activeTab = 'bakery';
    }

    // ADD / EDIT DELIVERY COVERAGE
    if (isset($_POST['save_coverage'])) {

        // Postcode
        if (empty(trim($_POST['postcode'] ?? '')))
            addError('cov_postcode', 'Postcode is required.');
        elseif (!preg_match('/^[0-9]{5}$/', trim($_POST['postcode'])))
            addError('cov_postcode', 'Postcode must be exactly 5 digits.');

        // City
        if (empty(trim($_POST['city'] ?? '')))
            addError('cov_city', 'City is required.');

        // State
        if (empty(trim($_POST['state'] ?? '')))
            addError('cov_state', 'State is required.');

        // Delivery fee
        if (!isset($_POST['fee']) || $_POST['fee'] === '')
            addError('cov_fee', 'Delivery fee is required.');
        elseif (!is_numeric($_POST['fee']) || (float)$_POST['fee'] < 0)
            addError('cov_fee', 'Fee must be a positive number.');

        if (empty($errors)) {
            $covId = !empty($_POST['coverage_id']) ? (int)$_POST['coverage_id'] : 0;
            $dup = $conn->prepare("SELECT COVERAGE_ID FROM delivery_coverage WHERE POSTCODE = ? AND COVERAGE_ID != ?");
            $dup->bind_param("si", $_POST['postcode'], $covId);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0)
                addError('cov_postcode', 'This postcode already exists.');
        }

        // if no errors, save
        if (empty($errors)) {
            if (!empty($_POST['coverage_id'])) {
                $stmt = $conn->prepare("UPDATE delivery_coverage SET POSTCODE=?, CITY=?, STATE=?, DELIVERY_FEE=? WHERE COVERAGE_ID=?");
                $stmt->bind_param("sssdi", $_POST['postcode'], $_POST['city'], $_POST['state'], $_POST['fee'], $_POST['coverage_id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO delivery_coverage (POSTCODE, CITY, STATE, DELIVERY_FEE) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("sssd", $_POST['postcode'], $_POST['city'], $_POST['state'], $_POST['fee']);
            }
            $stmt->execute();
            header("Location: settings.php?tab=coverage&success=1");
            exit;
        }
        $activeTab = 'coverage';
    }

    // ADD / EDIT DELIVERY RULE 
    if (isset($_POST['save_rule'])) {

        $ruleMode = $_POST['rule_mode'] ?? 'date';

        // date
        if ($ruleMode === 'date' && empty($_POST['date']))
            addError('rule_date', 'Please select a date.');
        elseif ($ruleMode === 'date' && !empty($_POST['date'])) {
            $d = DateTime::createFromFormat('Y-m-d', $_POST['date']);
            if (!$d) {
                addError('rule_date', 'Invalid date format.');
            } elseif ($_POST['date'] < date('Y-m-d')) {
                addError('rule_date', 'Date cannot be in the past.');
            }
        }

        // day
        if ($ruleMode === 'day' && empty($_POST['day_of_week']))
            addError('rule_day', 'Please select a day of the week.');

        if (empty($errors) && ($ruleMode ?? 'date') === 'date' && !empty($_POST['date'])) {
            $ruleId = !empty($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;
            $dup = $conn->prepare("SELECT RULE_ID FROM delivery_date_rules WHERE DATE = ? AND RULE_ID != ?");
            $dup->bind_param("si", $_POST['date'], $ruleId);
            $dup->execute();
        if ($dup->get_result()->num_rows > 0)
            addError('rule_date', 'This date already has a rule.');
        }

        if (empty($errors) && ($ruleMode ?? 'date') === 'day' && !empty($_POST['day_of_week'])) {
            $ruleId = !empty($_POST['rule_id']) ? (int)$_POST['rule_id'] : 0;
            $dup = $conn->prepare("SELECT RULE_ID FROM delivery_date_rules WHERE DAY_OF_WEEK = ? AND RULE_ID != ?");
            $dup->bind_param("ii", $_POST['day_of_week'], $ruleId);
            $dup->execute();
        if ($dup->get_result()->num_rows > 0)
            addError('rule_day', 'This day already has a rule.');
        }

        // if no errors, save
        if (empty($errors)) {
            $date      = null;
            $dayOfWeek = null;
            $reason    = trim($_POST['reason'] ?? '');

            if ($ruleMode === 'date' && !empty($_POST['date'])) {
                $date = $_POST['date'];
            } elseif ($ruleMode === 'day' && !empty($_POST['day_of_week'])) {
                $dayOfWeek = (int)$_POST['day_of_week'];
            }

            if (!empty($_POST['rule_id'])) {
                $stmt = $conn->prepare("UPDATE delivery_date_rules SET DATE=?, DAY_OF_WEEK=?, REASON=? WHERE RULE_ID=?");
                $stmt->bind_param("sisi", $date, $dayOfWeek, $reason, $_POST['rule_id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO delivery_date_rules (DATE, DAY_OF_WEEK, REASON, STATUS) VALUES (?, ?, ?, 'Active')");
                $stmt->bind_param("sis", $date, $dayOfWeek, $reason);
            }
            $stmt->execute();
            header("Location: settings.php?tab=rules&success=1");
            exit;
        }
        $activeTab = 'rules';
    }

    // ADD / EDIT DELIVERY SLOT
    if (isset($_POST['save_slot'])) {

        // date 
        if (empty($_POST['start']))
            addError('slot_start', 'Start time is required.');
        if (empty($_POST['end']))
            addError('slot_end', 'End time is required.');
        if (!empty($_POST['start']) && !empty($_POST['end']) && $_POST['end'] <= $_POST['start'])
            addError('slot_end', 'End time must be after start time.');

        if (empty($errors)) {
            $slotId = !empty($_POST['slot_id']) ? (int)$_POST['slot_id'] : 0;
            $dup = $conn->prepare("SELECT SLOT_ID FROM delivery_slots WHERE START_TIME = ? AND END_TIME = ? AND SLOT_ID != ?");
            $dup->bind_param("ssi", $_POST['start'], $_POST['end'], $slotId);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0)
                addError('slot_end', 'This time slot already exists.');
        }

        // if no errors, save
        if (empty($errors)) {
            if (!empty($_POST['slot_id'])) {
                $stmt = $conn->prepare("UPDATE delivery_slots SET START_TIME=?, END_TIME=? WHERE SLOT_ID=?");
                $stmt->bind_param("ssi", $_POST['start'], $_POST['end'], $_POST['slot_id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO delivery_slots (START_TIME, END_TIME, STATUS) VALUES (?, ?, 'Active')");
                $stmt->bind_param("ss", $_POST['start'], $_POST['end']);
            }
            $stmt->execute();
            header("Location: settings.php?tab=slots&success=1");
            exit;
        }
        $activeTab = 'slots';
    }

    // ADD / EDIT MEMBERSHIP TIER 
    if (isset($_POST['save_tier'])) {

        // tier name
        if (empty(trim($_POST['tier_name'] ?? '')))
            addError('tier_name', 'Tier name is required.');
        elseif (mb_strlen(trim($_POST['tier_name'])) > 50)
            addError('tier_name', 'Tier name must be 50 characters or less.');

        // min spent 
        if (!isset($_POST['min_spent']) || $_POST['min_spent'] === '')
            addError('min_spent', 'Minimum spent is required.');
        elseif (!is_numeric($_POST['min_spent']) || (float)$_POST['min_spent'] < 0)
            addError('min_spent', 'Must be a positive number.');

        if (empty($errors)) {
            $tierId = !empty($_POST['tier_id']) ? (int)$_POST['tier_id'] : 0;
            $name = trim($_POST['tier_name']);
            $dup = $conn->prepare("SELECT TIER_ID FROM membership_tier WHERE TIER_NAME = ? AND TIER_ID != ?");
            $dup->bind_param("si", $name, $tierId);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0)
                addError('tier_name', 'This tier name already exists.');
        }

        // if no errors, save
        if (empty($errors)) {
            $name   = trim($_POST['tier_name']);
            $min    = (float)$_POST['min_spent'];
            $tierId = !empty($_POST['tier_id']) ? (int)$_POST['tier_id'] : 0;

        if ($tierId) {
            // Check if default tier - block min_spent change
            $check = $conn->prepare("SELECT IS_DEFAULT, MIN_SPENT FROM membership_tier WHERE TIER_ID = ?");
            $check->bind_param("i", $tierId);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();

            if ($existing && $existing['IS_DEFAULT'] == 1) {
                $min = (float)$existing['MIN_SPENT']; // Force keep original min_spent
            }

            $stmt = $conn->prepare("UPDATE membership_tier SET TIER_NAME=?, MIN_SPENT=? WHERE TIER_ID=?");
            $stmt->bind_param("sdi", $name, $min, $tierId);
        } else {
            $stmt = $conn->prepare("INSERT INTO membership_tier (TIER_NAME, MIN_SPENT, STATUS) VALUES (?, ?, 'Active')");
            $stmt->bind_param("sd", $name, $min);
        }
            $stmt->execute();
            header("Location: settings.php?tab=tiers&success=1");
            exit;
        }
        $activeTab = 'tiers';
    }

    // ADD / EDIT CAKE STYLE
    if (isset($_POST['save_style'])) {

        // cake style name
        if (empty(trim($_POST['style_name'] ?? '')))
            addError('style_name', 'Style name is required.');
        elseif (mb_strlen(trim($_POST['style_name'])) > 100)
            addError('style_name', 'Style name must be 100 characters or less.');

        if (empty($errors)) {
            $styleId = !empty($_POST['style_id']) ? (int)$_POST['style_id'] : 0;
            $name = trim($_POST['style_name']);
            $dup = $conn->prepare("SELECT STYLE_ID FROM cake_style WHERE STYLE_NAME = ? AND STYLE_ID != ?");
            $dup->bind_param("si", $name, $styleId);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0)
                addError('style_name', 'This style name already exists.');
        }

        // if no errors, save
        if (empty($errors)) {
            $name = trim($_POST['style_name']);

            if (!empty($_POST['style_id'])) {
                $stmt = $conn->prepare("UPDATE cake_style SET STYLE_NAME=? WHERE STYLE_ID=?");
                $stmt->bind_param("si", $name, $_POST['style_id']);
            } else {
                $stmt = $conn->prepare("INSERT INTO cake_style (STYLE_NAME, STATUS) VALUES (?, 'Active')");
                $stmt->bind_param("s", $name);
            }
            $stmt->execute();
            header("Location: settings.php?tab=styles&success=1");
            exit;
        }
        $activeTab = 'styles';
    }
}

// TOGGLE STATUS (GET)
if (isset($_GET['toggle_type']) && isset($_GET['id'])) {

    $id = (int)$_GET['id'];

    // delivery slot
    if ($_GET['toggle_type'] === 'slot') {
        $conn->query("UPDATE delivery_slots SET STATUS = IF(STATUS='Active','Inactive','Active') WHERE SLOT_ID = $id");
        header("Location: settings.php?tab=slots"); exit;
    }

    // delivery coverage
    if ($_GET['toggle_type'] === 'coverage') {
        $conn->query("UPDATE delivery_coverage SET STATUS = IF(STATUS='Active','Inactive','Active') WHERE COVERAGE_ID = $id");
        header("Location: settings.php?tab=coverage"); exit;
    }

    // delivery date rules
    if ($_GET['toggle_type'] === 'rule') {
        $conn->query("UPDATE delivery_date_rules SET STATUS = IF(STATUS='Active','Inactive','Active') WHERE RULE_ID = $id");
        header("Location: settings.php?tab=rules"); exit;
    }

    $table = ($_GET['toggle_type'] === 'tier') ? 'membership_tier' : 'cake_style';
    $pk    = ($_GET['toggle_type'] === 'tier') ? 'TIER_ID'          : 'STYLE_ID';
    $tab   = ($_GET['toggle_type'] === 'tier') ? 'tiers'            : 'styles';

    // Block toggling default tier
    if ($_GET['toggle_type'] === 'tier') {
        $check = $conn->prepare("SELECT IS_DEFAULT FROM membership_tier WHERE TIER_ID = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        if ($row && $row['IS_DEFAULT'] == 1) {
            header("Location: settings.php?tab=tiers&error=default_toggle"); exit;
        }
    }

    $conn->query("UPDATE $table SET STATUS = IF(STATUS='Active','Inactive','Active') WHERE $pk = $id");
    header("Location: settings.php?tab=$tab"); exit;
}

// ACTIVE TAB
$activeTab = $activeTab ?? ($_GET['tab'] ?? 'bakery');
$success   = isset($_GET['success']);

// FETCH DATA
$bakery   = $conn->query("SELECT * FROM bakery_info LIMIT 1")->fetch_assoc();
$coverage = $conn->query("SELECT * FROM delivery_coverage ORDER BY STATE ASC, CITY ASC, POSTCODE ASC");
$rules    = $conn->query("SELECT * FROM delivery_date_rules ORDER BY DATE ASC, DAY_OF_WEEK ASC");
$slots    = $conn->query("SELECT * FROM delivery_slots ORDER BY START_TIME ASC");
$tiers    = $conn->query("SELECT * FROM membership_tier ORDER BY MIN_SPENT ASC");
$styles   = $conn->query("SELECT * FROM cake_style ORDER BY STYLE_NAME ASC");

function getDayName($dayNo) {
    $days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    return $days[$dayNo] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
<style>
body { 
    margin:0; 
    background:var(--primary-grey);
    font-family: var(--font-family);
}

.global-layout { 
    display:flex; 
    min-height:100vh; 
}

.main { 
    flex:1; 
    overflow-x:hidden; 
}

/* SETTINGS LAYOUT */
.settings-layout { 
    display:flex; 
    gap:24px; 
    padding:24px; 
}

/* SIDEBAR */
.settings-sidebar {
    width:220px; 
    flex-shrink:0; 
    background:#fff;
    border-radius:12px; 
    padding:20px;
    box-shadow:0 2px 10px rgba(0,0,0,0.06);
    height:fit-content; 
    position:sticky; 
    top:24px;
}

.sidebar-section-label {
    font-size:11px; 
    font-weight:700; 
    color:#9ca3af;
    margin-bottom:10px; 
    text-transform:uppercase; 
    letter-spacing:.7px;
}

.nav-link {
    display:flex; 
    align-items:center; 
    gap:10px;
    padding:10px 12px; 
    border-radius:8px; 
    text-decoration:none;
    color:#374151; 
    margin-bottom:4px;
    transition:background .15s,color .15s; font-size:14px; font-weight:500;
}

.nav-link:hover { 
    background:var(--primary-light); 
}

.nav-link.active { 
    background:var(--primary-dark); 
    color: var(--primary-white); 
}

.nav-link i { 
    margin-right: 3px;
    color: var(--primary-light);
}

.sidebar-divider { 
    border:none; 
    border-top:1px solid #f0f0f0; 
    margin:14px 0; 
}

/* CONTENT */
.settings-content { 
    flex:1; 
    min-width:0; 
}

.page-header { 
    margin-bottom:22px; 
}

.page-title { 
    margin:0; 
    font-size:24px; 
    font-weight:700; 
    color:#111827; 
}

.page-subtitle { 
    margin-top:5px; 
    color:#6b7280; 
    font-size:14px; 
}

/* CARDS */
.section-card {
    background:#fff; 
    border-radius:14px; 
    padding:24px;
    box-shadow:0 2px 10px rgba(0,0,0,0.05); 
    margin-bottom:24px;
}

.section-header { 
    margin-bottom:18px; 
    padding-bottom:16px; 
    border-bottom:1px solid #f3f4f6; 
}

.section-title { 
    font-size:16px; 
    font-weight:700; 
    color:#111827; 
}

.section-desc { 
    font-size:13px; 
    color:#9ca3af; 
    margin-top:3px; 
}

/* FORMS */
.edit-form { 
    display:grid; 
    grid-template-columns:1fr 1fr; 
    gap:16px; 
}

.edit-form .full { 
    grid-column:span 2; 
}

.edit-form label { 
    display:block; 
    font-size:13px; 
    font-weight:600; 
    color:#374151; 
    margin-bottom:6px; 
}

.edit-form input,.edit-form textarea,.edit-form select {
    width:100%; 
    padding:10px 12px; 
    border:1px solid #e5e7eb;
    border-radius:8px; 
    font-size:14px; 
    outline:none;
    transition:border-color .2s,box-shadow .2s; 
    box-sizing:border-box; 
    background:#fff;
}

.edit-form input:focus,.edit-form textarea:focus,.edit-form select:focus {
    border-color: var(--primary-dark); 
}

.edit-form textarea { 
    resize:vertical; 
    min-height:90px; 
}

.form-row { 
    display:flex; 
    gap:12px; 
    align-items:flex-end; 
    flex-wrap:wrap; 
    margin-bottom:20px; 
}

.form-group { 
    flex:1; 
}

.form-group.narrow { 
    max-width:180px; 
}

.form-label { 
    display:block; 
    font-size:13px; 
    font-weight:600; 
    color:#374151; 
    margin-bottom:6px; 
}

.form-input {
    width:100%; 
    padding:10px 12px; 
    border:1px solid #e5e7eb;
    border-radius:8px; 
    font-size:14px; 
    outline:none;
    transition:border-color .2s,box-shadow .2s; 
    box-sizing:border-box; 
    background:#fff;
}

.form-input:focus { 
    border-color: var(--primary-dark); 
}

/* RULE FORM */
.rule-form-wrapper { 
    margin-bottom:20px; 
    padding-bottom:20px; 
    border-bottom:1px solid #f3f4f6; 
}

.rule-mode-tabs {
    display:flex; 
    margin-bottom:16px;
    border:1px solid #e5e7eb; 
    border-radius:8px; 
    overflow:hidden; 
    width:fit-content;
}

.rule-mode-tab {
    padding:8px 20px; 
    font-size:13px; 
    font-weight:600;
    cursor:pointer; 
    background:#f9fafb; 
    color:#6b7280;
    border:none; 
    transition:background .15s,color .15s;
}

.rule-mode-tab.active { 
    background: var(--primary-dark); 
    color: var(--primary-white); 
}

.rule-fields { 
    display:flex; 
    gap:12px; 
    align-items:flex-end; 
    flex-wrap:wrap; 
}

.rule-fields .fg { 
    flex:1; 
    min-width:140px;
}

.rule-fields label { 
    display:block; 
    font-size:13px; 
    font-weight:600; 
    color:#374151; 
    margin-bottom:6px; 
}

.rule-fields input,.rule-fields select {
    width:100%; 
    padding:10px 12px; 
    border:1px solid #e5e7eb;
    border-radius:8px; 
    font-size:14px; 
    outline:none;
    transition:border-color .2s; 
    box-sizing:border-box; 
    background:#fff;
}

.rule-fields input:focus,.rule-fields select:focus { 
    border-color: var(--primary-dark); 
}

/* SLOT FORM */
.slot-form-row {
    display:flex; 
    gap:12px; 
    align-items:flex-end; 
    flex-wrap:wrap;
    margin-bottom:20px; 
    padding-bottom:20px; 
    border-bottom:1px solid #f3f4f6;
}

.slot-form-row .fg { 
    flex:1; 
    min-width:120px; 
}

.slot-form-row label { 
    display:block; 
    font-size:13px; 
    font-weight:600; 
    color:#374151; 
    margin-bottom:6px; 
}

.slot-form-row input[type="time"] {
    width:100%; 
    padding:10px 12px; 
    border:1px solid #e5e7eb;
    border-radius:8px; 
    font-size:14px; 
    outline:none; 
    box-sizing:border-box; 
    transition:border-color .2s;
}

.slot-form-row input[type="time"]:focus { 
    border-color:#111827; 
}

/* BUTTONS */
.btn-save { 
    grid-column:span 2; 
    background: var(--primary-dark); 
    color: var(--primary-white); 
    padding:11px; 
    border:none; 
    border-radius:8px; 
    cursor:pointer; 
    font-size:14px; 
    font-weight:600; 
    transition:background .15s; 
}

.btn-add { 
    border:none; 
    background: var(--primary-dark); 
    color: var(--primary-white); 
    padding:10px 16px; 
    border-radius:8px; 
    cursor:pointer; 
    display:inline-flex; 
    align-items:center; 
    gap:6px; 
    font-size:13px; 
    font-weight:600; 
    white-space:nowrap; 
    transition:background .15s; 
    text-decoration:none; 
}

.btn-toggle { 
    text-decoration:none; 
    background:#f3f4f6; 
    color:#374151; 
    padding:6px 12px; 
    border-radius:6px; 
    font-size:12px; 
    font-weight:600; 
    display:inline-block; 
    transition:background .15s;
    border:1px solid #e5e7eb; 
}

.btn-edit { 
    text-decoration:none; 
    background:#eff6ff; 
    color:#1d4ed8; 
    padding:6px 12px; 
    border-radius:6px; 
    font-size:12px; 
    font-weight:600; 
    display:inline-block; 
    transition:background .15s; 
    border:1px solid #bfdbfe; 
    cursor:pointer; 
}

.btn-cancel { 
    background:#f3f4f6; 
    color:#374151; 
    border:1px solid #e5e7eb; 
    padding:10px 18px; 
    border-radius:8px; 
    cursor:pointer; 
    font-size:13px; 
    font-weight:600; 
    transition:background .15s; 
}

.btn-cancel:hover { 
    background:#e5e7eb; 
}

.btn-confirm { 
    background: var(--primary-dark);
    color: var(--primary-white); 
    border:none; 
    padding:10px 18px; 
    border-radius:8px; 
    cursor:pointer; 
    font-size:13px; 
    font-weight:600; 
    transition:background .15s; 
}

.btn-save:hover,
.btn-add:hover,
.btn-toggle:hover,
.btn-edit:hover,
.btn-confirm:hover { 
    background: var(--primary-light); 
}

button i {
    color: var(--primary-light);
}

.action-group { 
    display:flex; 
    gap:6px; 
    align-items:center; 
    flex-wrap:wrap; 
}

/* TABLES */
.data-table { 
    width:100%; 
    border-collapse:collapse; 
    font-size:14px; 
}

.data-table th { 
    background:#f9fafb; 
    padding:12px 14px; 
    text-align:left; 
    border-bottom:1px solid #e5e7eb; 
    font-size:12px; 
    font-weight:700; 
    color:#6b7280; 
    text-transform:uppercase; 
    letter-spacing:.5px; 
}

.data-table td { 
    padding:13px 14px; 
    border-bottom:1px solid #f3f4f6; 
    vertical-align:middle; 
    color:#374151; 
}

.data-table tbody tr:last-child td { 
    border-bottom:none; 
}

.data-table tbody tr:hover td {
    background:#fafafa; 
}

.cell-name { 
    font-weight:600; 
    color:#111827; 
}

.cell-amount { 
    font-weight:600; 
    color:#111827; 
}

/* BADGES */
.badge { 
    padding:4px 10px; 
    border-radius:999px; 
    font-size:11px; 
    font-weight:700; 
    display:inline-block; 
    text-transform:uppercase; 
    letter-spacing:.4px; 
}

.badge-active    { 
    background:#dcfce7; 
    color:#166534;
}

.badge-inactive  { 
    background:#fee2e2; 
    color:#991b1b; 
}

.badge-date      { 
    background:#dbeafe; 
    color:#1e40af; 

}
.badge-recurring { 
    background:#fef3c7; 
    color:#92400e; 
}

/* EMPTY STATE */
.empty-state { 
    text-align:center;
    color:#9ca3af; 
    padding:32px 24px; 
    font-size:14px; 
}

/* TABLE TOOLBAR */
.table-toolbar { 
    display:flex; 
    justify-content:space-between; 
    align-items:center; 
    margin-bottom:16px; 
    flex-wrap:wrap; 
    gap:10px; 
}

.table-count { 
    font-size:13px; 
    color:#9ca3af; 
}

/* MODAL */
.modal-overlay { 
    display:none; 
    position:fixed; 
    inset:0; 
    background:rgba(0,0,0,0.45); 
    z-index:1000; 
    align-items:center; 
    justify-content:center; 
}

.modal-overlay.open { 
    display:flex; 
}

.modal-box { 
    background:#fff; 
    border-radius:16px;
    padding:28px; 
    width:100%; 
    max-width:480px; 
    box-shadow:0 20px 60px rgba(0,0,0,0.18); 
    animation:slideUp .25s ease; 
}

@keyframes slideUp { 
    from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} 
}

.modal-header { 
    display:flex; 
    justify-content:space-between; 
    align-items:center; 
    margin-bottom:22px; 
}

.modal-title { 
    font-size:17px; 
    font-weight:700; 
    color:#111827; 
}

.modal-close { 
    background:none; 
    border:none; 
    cursor:pointer; 
    padding:4px; 
    color:#9ca3af; 
    line-height:1; 
    font-size:20px; 
}

.modal-close:hover { 
    color:#111827; 
}

.modal-form-grid { 
    display:grid; 
    grid-template-columns:1fr 1fr; 
    gap:14px; 
}

.modal-form-grid .full { 
    grid-column:span 2; 
}

.modal-label { 
    display:block; 
    font-size:13px; 
    font-weight:600; 
    color:#374151; 
    margin-bottom:6px; 
}

.modal-input { 
    width:100%; 
    padding:10px 12px; 
    border:1px solid #e5e7eb; 
    border-radius:8px; 
    font-size:14px; 
    outline:none; 
    box-sizing:border-box; 
    transition:border-color .2s,box-shadow .2s; 
}

.modal-input:focus { 
    border-color: var(--primary-dark); 
}

.modal-footer { 
    display:flex; 
    gap:10px; 
    justify-content:flex-end; 
    margin-top:22px; 
}

/* VALIDATION */
.input-error { 
    border-color:#ef4444 !important; 
    box-shadow:0 0 0 3px rgba(239,68,68,.08) !important; 
}

.field-error { 
    color:#ef4444; 
    font-size:12px; 
    margin-top:5px; 
    display:flex; 
    align-items:center; 
    gap:4px; 
    font-weight:500; 
}

.field-error::before { 
    content:"!"; 
    display:inline-flex; 
    align-items:center; 
    justify-content:center;
    width:14px; height:14px; 
    background:#ef4444; 
    color:#fff; 
    border-radius:50%; 
    font-size:10px; 
    font-weight:700; 
    flex-shrink:0; 
}

/* TOAST */
.toast {
    position:fixed; 
    top:24px; 
    left:50%; 
    transform:translateX(-50%);
    padding:12px 40px 12px 18px; 
    border-radius:8px; 
    color:#fff !important;
    font-size:14px; 
    z-index:9999; 
    opacity:1; 
    transition:all .4s ease;
    min-width:180px; 
    text-align:center;
}

.toast span { 
    color:#fff !important; 
}

.toast.success { 
    background:#22c55e; 
}

.toast.error   { 
    background:#ef4444; 
}

.toast-close-btn {
    position:absolute; 
    top:6px; 
    right:6px; 
    width:22px; 
    height:22px;
    display:flex; 
    align-items:center; 
    justify-content:center;
    font-size:16px; 
    font-weight:bold; 
    border-radius:50%;
    background:rgba(255,255,255,0.2); 
    color:white; 
    cursor:pointer;
    transition:all .2s ease; 
    line-height:1;
}

.toast-close-btn:hover { 
    background:rgba(255,255,255,0.35); 
    transform:scale(1.1); 
}
</style>
</head>

<body>
<div class="global-layout">
    <?php include "global_layout_ctrl.php"; ?>
    <div class="main">
        <div class="settings-layout">

            <!-- SIDEBAR -->
            <aside class="settings-sidebar">
                <div class="sidebar-section-label">Configuration</div>
                <a href="?tab=bakery"   class="nav-link <?= $activeTab==='bakery'?'active':'' ?>">
                    <i class="bi bi-shop"></i> Bakery Info</a>
                <a href="?tab=coverage" class="nav-link <?= $activeTab==='coverage'?'active':'' ?>">
                    <i class="bi bi-geo-alt"></i> Delivery Coverage</a>
                <a href="?tab=rules"    class="nav-link <?= $activeTab==='rules'?'active':'' ?>">
                    <i class="bi bi-journal-text"></i> Delivery Rules</a>
                <a href="?tab=slots"    class="nav-link <?= $activeTab==='slots'?'active':'' ?>">
                    <i class="bi bi-clock"></i> Delivery Slots</a>
                <hr class="sidebar-divider">
                <a href="?tab=tiers"    class="nav-link <?= $activeTab==='tiers'?'active':'' ?>">
                    <i class="bi bi-gem"></i> Membership Tiers</a>
                <a href="?tab=styles"   class="nav-link <?= $activeTab==='styles'?'active':'' ?>">
                    <i class="bi bi-palette"></i>Cake Styles</a>
            </aside>

            <!-- CONTENT -->
            <div class="settings-content">

                <!-- BAKERY INFO -->
                <?php if ($activeTab === 'bakery'): ?>
                <div class="page-header">
                    <h1 class="page-title">Bakery Information</h1>
                    <p class="page-subtitle">Manage your bakery's details and contact information.</p>
                </div>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title">Shop Details</div>
                        <div class="section-desc">This information may appear on receipts, website, and customer communications.</div>
                    </div>

                    <form method="POST" class="edit-form" enctype="multipart/form-data">
                        <input type="hidden" name="update_bakery" value="1">
                        <input type="hidden" name="existing_shop_image" value="<?= htmlspecialchars($bakery['SHOP_IMAGE'] ?? '') ?>">

                        <!-- Shop Image -->
                        <div class="full">
                            <label>Shop Image</label>
                            <?php if (!empty($bakery['SHOP_IMAGE'])): ?>
                                <div style="margin-bottom:10px">
                                    <img src="<?= htmlspecialchars($bakery['SHOP_IMAGE']) ?>"
                                    style="width:120px;height:120px;object-fit:cover;border-radius:10px;border:1px solid #e5e7eb;">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="shop_image" accept="image/*" class="<?= hasError('shop_image') ? 'input-error' : '' ?>"
                            style="width:100%;padding:8px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;box-sizing:border-box;background:#fff">
                            <small style="color:#9ca3af;font-size:12px;margin-top:4px;display:block">Accepted: JPG, PNG, WEBP. Max 2MB. Leave empty to keep current image.</small>
                            <?php if (hasError('shop_image')): ?><div class="field-error"><?= getError('shop_image') ?></div><?php endif; ?>
                        </div>

                        <!-- Shop Name -->
                        <div>
                            <label>Shop Name <span style="color:#ef4444">*</span></label>
                            <input name="shop_name" class="<?= fieldClass('shop_name') ?>" value="<?= htmlspecialchars($_POST['shop_name'] ?? $bakery['SHOP_NAME'] ?? '') ?>" placeholder="Your shop name" required maxlength="100">
                            <?php if (hasError('shop_name')): ?><div class="field-error"><?= getError('shop_name') ?></div><?php endif; ?>
                        </div>

                        <!-- Phone -->
                        <div>
                            <label>Phone Number <span style="color:#ef4444">*</span></label>
                            <input name="phone" class="<?= fieldClass('phone') ?>" value="<?= htmlspecialchars($_POST['phone'] ?? $bakery['PHONE'] ?? '') ?>" placeholder="e.g. +60112345678" maxlength="20" required>
                            <?php if (hasError('phone')): ?><div class="field-error"><?= getError('phone') ?></div><?php endif; ?>
                        </div>

                        <!-- Email -->
                        <div>
                            <label>Email Address <span style="color:#ef4444">*</span></label>
                            <input type="email" name="email" class="<?= fieldClass('email') ?>" value="<?= htmlspecialchars($_POST['email'] ?? $bakery['EMAIL'] ?? '') ?>" placeholder="abc@bakery.com" maxlength="255" required>
                            <?php if (hasError('email')): ?><div class="field-error"><?= getError('email') ?></div><?php endif; ?>
                        </div>

                        <!-- Postcode -->
                        <div>
                            <label>Postcode <span style="color:#ef4444">*</span></label>
                            <input name="postcode" class="<?= fieldClass('postcode') ?>" value="<?= htmlspecialchars($_POST['postcode'] ?? $bakery['POSTCODE'] ?? '') ?>" placeholder="e.g. xxxxx" maxlength="5" pattern="[0-9]{5}" title="5-digit postcode" required>
                            <?php if (hasError('postcode')): ?><div class="field-error"><?= getError('postcode') ?></div><?php endif; ?>
                        </div>

                        <!-- Address -->
                        <div class="full">
                            <label>Address <span style="color:#ef4444">*</span></label>
                            <textarea name="address" class="<?= hasError('address') ? 'input-error' : '' ?>" placeholder="Street address..." required><?= htmlspecialchars($_POST['address'] ?? $bakery['ADDRESS'] ?? '') ?></textarea>
                            <?php if (hasError('address')): ?><div class="field-error"><?= getError('address') ?></div><?php endif; ?>
                        </div>
 
                        <!-- City -->
                        <div>
                            <label>City <span style="color:#ef4444">*</span></label>
                            <input name="city" class="<?= fieldClass('city') ?>" value="<?= htmlspecialchars($_POST['city'] ?? $bakery['CITY'] ?? '') ?>" placeholder="e.g. Kulai" required>
                            <?php if (hasError('city')): ?><div class="field-error"><?= getError('city') ?></div><?php endif; ?>
                        </div>

                        <!-- State -->
                        <div>
                            <label>State <span style="color:#ef4444">*</span></label>
                            <input name="state" class="<?= fieldClass('state') ?>" value="<?= htmlspecialchars($_POST['state'] ?? $bakery['STATE'] ?? '') ?>" placeholder="e.g. Johor" required>
                            <?php if (hasError('state')): ?><div class="field-error"><?= getError('state') ?></div><?php endif; ?>
                        </div>

                        <!-- Bakery Description -->
                        <div class="full">
                            <label>Bakery Description <span style="color:#ef4444">*</span></label>
                            <textarea name="bakery_des" class="<?= hasError('bakery_des') ? 'input-error' : '' ?>" placeholder="Describe your bakery..." maxlength="300" required><?= htmlspecialchars($_POST['bakery_des'] ?? $bakery['BAKERY_DES'] ?? '') ?></textarea>
                            <small style="color:#9ca3af;font-size:12px;margin-top:4px;display:block;text-align:right;">Maximum 300 characters</small>
                            <?php if (hasError('bakery_des')): ?><div class="field-error"><?= getError('bakery_des') ?></div><?php endif; ?>
                        </div>

                        <!-- Open Time -->
                        <div>
                            <label>Opening Time <span style="color:#ef4444">*</span></label>
                            <input type="time" name="open_time" class="<?= fieldClass('open_time') ?>" value="<?= htmlspecialchars($_POST['open_time'] ?? $bakery['OPEN_TIME'] ?? '') ?>" required>
                            <?php if (hasError('open_time')): ?><div class="field-error"><?= getError('open_time') ?></div><?php endif; ?>
                        </div>

                        <!-- Close Time -->
                        <div>
                            <label>Closing Time <span style="color:#ef4444">*</span></label>
                            <input type="time" name="close_time" class="<?= fieldClass('close_time') ?>" value="<?= htmlspecialchars($_POST['close_time'] ?? $bakery['CLOSE_TIME'] ?? '') ?>" required>
                            <?php if (hasError('close_time')): ?><div class="field-error"><?= getError('close_time') ?></div><?php endif; ?>
                        </div>

                        <!-- Open Days -->
                        <div class="full">
                            <label>Open Days <span style="color:#ef4444">*</span></label>
                            <?php
                                $savedDays = explode(',', $_POST['open_days_raw'] ?? $bakery['OPEN_DAYS'] ?? '');
                                // If submitted as array (on validation fail), use that
                                if (!empty($_POST['open_days']) && is_array($_POST['open_days'])) {
                                    $savedDays = $_POST['open_days'];
                                }
                                $dayOptions = [
                                   'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday',
                                   'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'
                                ];
                            ?>
                            <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:4px">
                                <?php foreach ($dayOptions as $val => $label): ?>
                                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:500;font-size:14px;color:#374151;background:#f9fafb;border:1px solid <?= hasError('open_days') ? '#ef4444' : '#e5e7eb' ?>;border-radius:8px;padding:8px 14px;user-select:none;">
                                        <input type="checkbox" name="open_days[]" value="<?= $val ?>"
                                        style="width:15px;height:15px;accent-color:var(--primary-dark);cursor:pointer"
                                        <?= in_array($val, $savedDays) ? 'checked' : '' ?>>
                                        <?= $label ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <?php if (hasError('open_days')): ?><div class="field-error" style="margin-top:6px"><?= getError('open_days') ?></div><?php endif; ?>
                        </div>

                        <button type="submit" class="btn-save">Save Changes</button>
                    </form>
                </div>

                <!-- DELIVERY COVERAGE -->
                <?php elseif ($activeTab === 'coverage'): ?>
                <div class="page-header">
                    <h1 class="page-title">Delivery Coverage</h1>
                    <p class="page-subtitle">Manage supported delivery areas and fees.</p>
                </div>
                <div class="section-card">
                    <div class="table-toolbar">
                        <div class="table-count">
                            <?php $cnt=$coverage?$coverage->num_rows:0; echo "$cnt area".($cnt!=1?'s':'')." configured"; $coverage->data_seek(0); ?>
                        </div>
                        <button class="btn-add" onclick="openCoverageModal()">
                            <i class="bi bi-plus-lg"></i>
                            Add Coverage
                        </button>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr><th>Postcode</th><th>City</th><th>State</th><th>Delivery Fee</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($coverage && $coverage->num_rows > 0): ?>
                                <?php while($row = $coverage->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['POSTCODE']) ?></td>
                                    <td class="cell-name"><?= htmlspecialchars($row['CITY']) ?></td>
                                    <td><?= htmlspecialchars($row['STATE']) ?></td>
                                    <td class="cell-amount">RM <?= number_format($row['DELIVERY_FEE'],2) ?></td>
                                    <td><span class="badge badge-<?= strtolower($row['STATUS']) ?>"><?= htmlspecialchars($row['STATUS']) ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-edit" onclick="editCoverage(<?= $row['COVERAGE_ID'] ?>,'<?= addslashes($row['POSTCODE']) ?>','<?= addslashes($row['CITY']) ?>','<?= addslashes($row['STATE']) ?>','<?= $row['DELIVERY_FEE'] ?>')">Edit</button>
                                            <a class="btn-toggle" href="?toggle_type=coverage&id=<?= $row['COVERAGE_ID'] ?>">Toggle</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="6"><i class="bi bi-geo-alt"></i>No delivery coverage areas configured yet.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Coverage Modal -->
                <div class="modal-overlay" id="coverageModal">
                    <div class="modal-box">
                        <div class="modal-header">
                            <div class="modal-title" id="coverageModalTitle">Add Delivery Coverage</div>
                            <button class="modal-close" onclick="closeCoverageModal()">&times;</button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="save_coverage" value="1">
                            <input type="hidden" name="coverage_id" id="modal_coverage_id" value="">
                            <div class="modal-form-grid">
                                <div>
                                    <label class="modal-label">Postcode</label>
                                    <input class="<?= modalFieldClass('cov_postcode') ?>" name="postcode" id="modal_postcode" placeholder="e.g. 15000" required pattern="[0-9]{5}" maxlength="5">
                                    <?php if (hasError('cov_postcode')): ?><div class="field-error"><?= getError('cov_postcode') ?></div><?php endif; ?>
                                </div>
                                <div>
                                    <label class="modal-label">Delivery Fee (RM)</label>
                                    <input class="<?= modalFieldClass('cov_fee') ?>" type="number" name="fee" id="modal_fee" step="0.01" min="0" placeholder="0.00" required>
                                    <?php if (hasError('cov_fee')): ?><div class="field-error"><?= getError('cov_fee') ?></div><?php endif; ?>
                                </div>
                                <div>
                                    <label class="modal-label">City</label>
                                    <input class="<?= modalFieldClass('cov_city') ?>" name="city" id="modal_city" placeholder="e.g. Kota Bharu" required maxlength="100">
                                    <?php if (hasError('cov_city')): ?><div class="field-error"><?= getError('cov_city') ?></div><?php endif; ?>
                                </div>
                                <div>
                                    <label class="modal-label">State</label>
                                    <input class="<?= modalFieldClass('cov_state') ?>" name="state" id="modal_state" placeholder="e.g. Kelantan" required maxlength="100">
                                    <?php if (hasError('cov_state')): ?><div class="field-error"><?= getError('cov_state') ?></div><?php endif; ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn-cancel" onclick="closeCoverageModal()">Cancel</button>
                                <button type="submit" class="btn-confirm">Save Coverage</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- DELIVERY RULES -->
                <?php elseif ($activeTab === 'rules'): ?>
                <div class="page-header">
                    <h1 class="page-title">Delivery Date Rules</h1>
                    <p class="page-subtitle">Block specific dates or recurring days of the week from delivery.</p>
                </div>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title" id="ruleFormTitle">Add New Rule</div>
                        <div class="section-desc">Customers cannot select these dates for delivery, and admins are not allowed to set production capacity on them.</div>
                    </div>
                    <form method="POST" class="rule-form-wrapper" id="ruleForm">
                        <input type="hidden" name="save_rule" value="1">
                        <input type="hidden" name="rule_id" id="rule_id" value="">
                        <input type="hidden" name="rule_mode" id="rule_mode" value="date">

                        <div class="rule-mode-tabs">
                            <button type="button" class="rule-mode-tab active" id="tab_date" onclick="switchRuleMode('date')">Specific Date</button>
                            <button type="button" class="rule-mode-tab" id="tab_day" onclick="switchRuleMode('day')">Recurring Day</button>
                        </div>

                        <div class="rule-fields">
                            <div class="fg" id="field_date">
                                <label>Date</label>
                                <input type="date" name="date" id="input_date" class="<?= hasError('rule_date') ? 'form-input input-error' : 'form-input' ?>" value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">
                                <?php if (hasError('rule_date')): ?><div class="field-error"><?= getError('rule_date') ?></div><?php endif; ?>
                            </div>
                            <div class="fg" id="field_day" style="display:none">
                                <label>Day of Week</label>
                                <select name="day_of_week" id="input_day" class="<?= hasError('rule_day') ? 'form-input input-error' : 'form-input' ?>">
                                    <option value="">-- Select Day --</option>
                                    <option value="1">Monday</option>
                                    <option value="2">Tuesday</option>
                                    <option value="3">Wednesday</option>
                                    <option value="4">Thursday</option>
                                    <option value="5">Friday</option>
                                    <option value="6">Saturday</option>
                                    <option value="7">Sunday</option>
                                </select>
                                <?php if (hasError('rule_day')): ?><div class="field-error"><?= getError('rule_day') ?></div><?php endif; ?>
                            </div>
                            <div class="fg" style="flex:2">
                                <label>Reason (optional)</label>
                                <input type="text" name="reason" id="input_reason" value="<?= htmlspecialchars($_POST['reason'] ?? '') ?>" placeholder="e.g. Public holiday, No delivery on Sundays...">
                            </div>
                            <div style="display:flex;gap:8px;align-items:flex-end">
                                <button type="submit" class="btn-add" style="margin-bottom:0">
                                    <i class="bi bi-plus-lg"></i>
                                    <span id="ruleBtnLabel" style="color:white;">Add</span>
                                </button>
                                <button type="button" class="btn-cancel" id="ruleCancelBtn" onclick="resetRuleForm()" style="display:none">Cancel</button>
                            </div>
                        </div>
                    </form>

                    <table class="data-table">
                        <thead>
                            <tr><th>Type</th><th>Date / Day</th><th>Reason</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($rules && $rules->num_rows > 0): ?>
                                <?php while($row = $rules->fetch_assoc()): ?>
                                <?php $isDateRule=!empty($row['DATE']); $isDayRule=!empty($row['DAY_OF_WEEK']); ?>
                                <tr>
                                    <td>
                                        <?php if ($isDateRule): ?><span class="badge badge-date">Specific Date</span>
                                        <?php elseif ($isDayRule): ?><span class="badge badge-recurring">Recurring</span>
                                        <?php else: ?><span>-</span><?php endif; ?>
                                    </td>
                                    <td class="cell-name">
                                        <?php if ($isDateRule): ?>
                                            <?= date('d M Y', strtotime($row['DATE'])) ?>
                                            <br><small style="color:#6b7280;font-weight:400"><?= date('l', strtotime($row['DATE'])) ?></small>
                                        <?php elseif ($isDayRule): ?>
                                            Every <?= getDayName($row['DAY_OF_WEEK']) ?>
                                        <?php else: ?>-<?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['REASON'] ?? '-') ?></td>
                                    <td><span class="badge badge-<?= strtolower($row['STATUS']) ?>"><?= htmlspecialchars($row['STATUS']) ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-edit" onclick="editRule(
                                                <?= (int)$row['RULE_ID'] ?>,
                                                '<?= $isDateRule ? 'date' : 'day' ?>',
                                                '<?= addslashes($row['DATE'] ?? '') ?>',
                                                <?= (int)($row['DAY_OF_WEEK'] ?? 0) ?>,
                                                '<?= addslashes($row['REASON'] ?? '') ?>'
                                            )">Edit</button>
                                            <a class="btn-toggle" href="?toggle_type=rule&id=<?= (int)$row['RULE_ID'] ?>">Toggle</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><i class="bi bi-geo-alt"></i>No delivery rules configured yet.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- DELIVERY SLOTS -->
                <?php elseif ($activeTab === 'slots'): ?>
                <div class="page-header">
                    <h1 class="page-title">Delivery Slots</h1>
                    <p class="page-subtitle">Manage available delivery time slots.</p>
                </div>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title" id="slotFormTitle">Add New Slot</div>
                        <div class="section-desc">New slots are active by default. Customers can only select these slots for delivery.</div>
                    </div>
                    <form method="POST" class="slot-form-row" id="slotForm">
                        <input type="hidden" name="save_slot" value="1">
                        <input type="hidden" name="slot_id" id="slot_id" value="">
                        <div class="fg">
                            <label>Start Time</label>
                            <input type="time" name="start" id="slot_start" class="<?= hasError('slot_start') ? 'input-error' : '' ?>" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box" value="<?= htmlspecialchars($_POST['start'] ?? '') ?>" required>
                            <?php if (hasError('slot_start')): ?><div class="field-error"><?= getError('slot_start') ?></div><?php endif; ?>
                        </div>
                        <div class="fg">
                            <label>End Time</label>
                            <input type="time" name="end" id="slot_end" class="<?= hasError('slot_end') ? 'input-error' : '' ?>" style="width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:14px;outline:none;box-sizing:border-box" value="<?= htmlspecialchars($_POST['end'] ?? '') ?>" required>
                            <?php if (hasError('slot_end')): ?><div class="field-error"><?= getError('slot_end') ?></div><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:flex-end">
                            <button type="submit" class="btn-add" style="margin-bottom:0">
                                <i class="bi bi-plus-lg"></i>
                                <span id="slotBtnLabel" style="color:white;">Add Slot</span>
                            </button>
                            <button type="button" class="btn-cancel" id="slotCancelBtn" onclick="resetSlotForm()" style="display:none">Cancel</button>
                        </div>
                    </form>
                    <table class="data-table">
                        <thead>
                            <tr><th>Start Time</th><th>End Time</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($slots && $slots->num_rows > 0): ?>
                                <?php while($row = $slots->fetch_assoc()): ?>
                                <tr>
                                    <td class="cell-name"><?= date("h:i A", strtotime($row['START_TIME'])) ?></td>
                                    <td><?= date("h:i A", strtotime($row['END_TIME'])) ?></td>
                                    <td><span class="badge badge-<?= strtolower($row['STATUS']) ?>"><?= htmlspecialchars($row['STATUS']) ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-edit" onclick="editSlot(<?= (int)$row['SLOT_ID'] ?>,'<?= substr($row['START_TIME'],0,5) ?>','<?= substr($row['END_TIME'],0,5) ?>')">Edit</button>
                                            <a class="btn-toggle" href="?toggle_type=slot&id=<?= (int)$row['SLOT_ID'] ?>">Toggle</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><i class="bi bi-geo-alt"></i>No delivery slots added yet.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- MEMBERSHIP TIERS -->
                <?php elseif ($activeTab === 'tiers'): ?>
                <div class="page-header">
                    <h1 class="page-title">Membership Tiers</h1>
                    <p class="page-subtitle">Define spending thresholds for membership tiers.</p>
                </div>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title" id="tierFormTitle">Add New Tier</div>
                        <div class="section-desc">New tiers are active by default. Customers must meet the minimum spending requirement to qualify for each tier.</div>
                    </div>
                    <form method="POST" class="form-row" id="tierForm">
                        <input type="hidden" name="save_tier" value="1">
                        <input type="hidden" name="tier_id" id="tier_id" value="">
                        <div class="form-group">
                            <label class="form-label">Tier Name</label>
                            <input type="text" class="<?= fieldClass('tier_name') ?>" name="tier_name" id="tier_name" placeholder="e.g. Gold" maxlength="50" value="<?= htmlspecialchars($_POST['tier_name'] ?? '') ?>" required>
                            <?php if (hasError('tier_name')): ?><div class="field-error"><?= getError('tier_name') ?></div><?php endif; ?>
                        </div>
                        <div class="form-group narrow">
                            <label class="form-label">Minimum Spent (RM)</label>
                            <input type="number" class="<?= fieldClass('min_spent') ?>" name="min_spent" id="min_spent" min="0" step="0.01" placeholder="0.00" value="<?= htmlspecialchars($_POST['min_spent'] ?? '') ?>" required>
                            <?php if (hasError('min_spent')): ?><div class="field-error"><?= getError('min_spent') ?></div><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:flex-end">
                            <button type="submit" class="btn-add">
                                <i class="bi bi-plus-lg"></i>
                                <span id="tierBtnLabel" style="color:white">Add Tier</span>
                            </button>
                            <button type="button" class="btn-cancel" id="tierCancelBtn" onclick="resetTierForm()" style="display:none">Cancel</button>
                        </div>
                    </form>
                    <table class="data-table">
                        <thead>
                            <tr><th>Tier Name</th><th>Minimum Spent</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($tiers && $tiers->num_rows > 0): ?>
                                <?php while($row = $tiers->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="cell-name"><?= htmlspecialchars($row['TIER_NAME']) ?></span></td>
                                    <td><span class="cell-amount">RM <?= number_format($row['MIN_SPENT'],2) ?></span></td>
                                    <td><span class="badge badge-<?= strtolower($row['STATUS']) ?>"><?= htmlspecialchars($row['STATUS']) ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-edit" onclick="editTier(<?= (int)$row['TIER_ID'] ?>,'<?= addslashes($row['TIER_NAME']) ?>','<?= $row['MIN_SPENT'] ?>',<?= $row['IS_DEFAULT'] ?>)">Edit</button>
                                            <?php if ($row['IS_DEFAULT']): ?>
                                                <span class="btn-toggle" style="opacity:0.4;cursor:not-allowed;" title="Default tier cannot be toggled">Toggle</span>
                                            <?php else: ?>
                                                <a class="btn-toggle" href="?toggle_type=tier&id=<?= (int)$row['TIER_ID'] ?>">Toggle</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4"><i class="bi bi-geo-alt"></i>No membership tiers added yet.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- CAKE STYLES -->
                <?php elseif ($activeTab === 'styles'): ?>
                <div class="page-header">
                    <h1 class="page-title">Cake Styles</h1>
                    <p class="page-subtitle">Manage cake style options shown to customers.</p>
                </div>
                <div class="section-card">
                    <div class="section-header">
                        <div class="section-title" id="styleFormTitle">Add New Style</div>
                        <div class="section-desc">Styles are active by default. This information will be used in the cake customization form.</div>
                    </div>
                    <form method="POST" class="form-row" id="styleForm">
                        <input type="hidden" name="save_style" value="1">
                        <input type="hidden" name="style_id" id="style_id" value="">
                        <div class="form-group">
                            <label class="form-label">Style Name</label>
                            <input type="text" class="<?= fieldClass('style_name') ?>" name="style_name" id="style_name" placeholder="e.g. Minimalist" maxlength="100" value="<?= htmlspecialchars($_POST['style_name'] ?? '') ?>" required>
                            <?php if (hasError('style_name')): ?><div class="field-error"><?= getError('style_name') ?></div><?php endif; ?>
                        </div>
                        <div style="display:flex;gap:8px;align-items:flex-end">
                            <button type="submit" class="btn-add">
                                <i class="bi bi-plus-lg"></i>
                                <span id="styleBtnLabel" style="color:white">Add Style</span>
                            </button>
                            <button type="button" class="btn-cancel" id="styleCancelBtn" onclick="resetStyleForm()" style="display:none">Cancel</button>
                        </div>
                    </form>
                    <table class="data-table">
                        <thead>
                            <tr><th>Style Name</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($styles && $styles->num_rows > 0): ?>
                                <?php while($row = $styles->fetch_assoc()): ?>
                                <tr>
                                    <td><span class="cell-name"><?= htmlspecialchars($row['STYLE_NAME']) ?></span></td>
                                    <td><span class="badge badge-<?= strtolower($row['STATUS']) ?>"><?= htmlspecialchars($row['STATUS']) ?></span></td>
                                    <td>
                                        <div class="action-group">
                                            <button class="btn-edit" onclick="editStyle(<?= (int)$row['STYLE_ID'] ?>,'<?= addslashes($row['STYLE_NAME']) ?>')">Edit</button>
                                            <a class="btn-toggle" href="?toggle_type=style&id=<?= (int)$row['STYLE_ID'] ?>">Toggle</a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3"><i class="bi bi-geo-alt"></i>No cake styles added yet.</div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
// Show toast
function showToast(type, message) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const text = document.createElement('span');
    text.innerText = message;
    const closeBtn = document.createElement('span');
    closeBtn.innerHTML = '&times;';
    closeBtn.className = 'toast-close-btn';
    toast.appendChild(text);
    toast.appendChild(closeBtn);
    document.body.appendChild(toast);
    let removed = false;
    function removeToast() {
        if (removed) return; removed = true;
        toast.style.opacity = '0'; toast.style.transform = 'translateX(100%)';
        setTimeout(() => toast.remove(), 300);
    }
    closeBtn.addEventListener('click', e => { e.stopPropagation(); removeToast(); });
    toast.addEventListener('click', removeToast);
    setTimeout(removeToast, type === 'error' ? 8000 : 3000);
}

<?php if ($success): ?>showToast('success', 'Changes saved successfully');<?php endif; ?>
<?php if (!empty($errors)): ?>showToast('error', 'Please fix the errors');<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'default_toggle'): ?>
showToast('error', 'Default tier cannot be deactivated.');
<?php endif; ?>

// Prevent past dates in rule form
const inputDate = document.getElementById('input_date');
if (inputDate) {
    const today = new Date();
    const pad = n => String(n).padStart(2, '0');
    inputDate.min = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
}

// RULE MODE SWITCHER
function switchRuleMode(mode) {
    document.getElementById('rule_mode').value = mode;
    const tabDate = document.getElementById('tab_date');
    const tabDay  = document.getElementById('tab_day');
    const fieldDate = document.getElementById('field_date');
    const fieldDay  = document.getElementById('field_day');
    const inputDate = document.getElementById('input_date');
    const inputDay  = document.getElementById('input_day');
    if (mode === 'date') {
        tabDate.classList.add('active'); tabDay.classList.remove('active');
        fieldDate.style.display = ''; fieldDay.style.display = 'none';
        inputDate.required = true; inputDay.required = false; inputDay.value = '';
    } else {
        tabDay.classList.add('active'); tabDate.classList.remove('active');
        fieldDay.style.display = ''; fieldDate.style.display = 'none';
        inputDay.required = true; inputDate.required = false; inputDate.value = '';
    }
}

// EDIT RULE (inline form)
function editRule(id, mode, date, dayOfWeek, reason) {
    document.getElementById('rule_id').value     = id;
    document.getElementById('input_reason').value = reason;
    document.getElementById('ruleFormTitle').textContent = 'Edit Rule';
    document.getElementById('ruleBtnLabel').textContent  = 'Update';
    document.getElementById('ruleCancelBtn').style.display = '';
    switchRuleMode(mode);
    if (mode === 'date') {
        document.getElementById('input_date').value = date;
    } else {
        document.getElementById('input_day').value = dayOfWeek;
    }
    document.getElementById('ruleForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function resetRuleForm() {
    document.getElementById('rule_id').value = '';
    document.getElementById('input_date').value = '';
    document.getElementById('input_day').value = '';
    document.getElementById('input_reason').value = '';
    document.getElementById('ruleFormTitle').textContent = 'Add New Rule';
    document.getElementById('ruleBtnLabel').textContent  = 'Add';
    document.getElementById('ruleCancelBtn').style.display = 'none';
    switchRuleMode('date');
}

<?php if (!empty($errors) && $activeTab === 'rules'): ?>
window.addEventListener('DOMContentLoaded', function() {
    switchRuleMode('<?= htmlspecialchars($_POST['rule_mode'] ?? 'date') ?>');
});
<?php endif; ?>

//  EDIT SLOT (inline form)
function editSlot(id, start, end) {
    document.getElementById('slot_id').value    = id;
    document.getElementById('slot_start').value = start;
    document.getElementById('slot_end').value   = end;
    document.getElementById('slotFormTitle').textContent  = 'Edit Slot';
    document.getElementById('slotBtnLabel').textContent   = 'Update';
    document.getElementById('slotCancelBtn').style.display = '';
    document.getElementById('slotForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function resetSlotForm() {
    document.getElementById('slot_id').value    = '';
    document.getElementById('slot_start').value = '';
    document.getElementById('slot_end').value   = '';
    document.getElementById('slotFormTitle').textContent   = 'Add New Slot';
    document.getElementById('slotBtnLabel').textContent    = 'Add Slot';
    document.getElementById('slotCancelBtn').style.display = 'none';
}

// EDIT TIER (inline form)
function editTier(id, name, minSpent, isDefault) {
    document.getElementById('tier_id').value   = id;
    document.getElementById('tier_name').value = name;
    document.getElementById('min_spent').value = minSpent;

    // Lock min_spent for default tier
    const minSpentInput = document.getElementById('min_spent');
    if (isDefault == 1) {
        minSpentInput.readOnly = true;
        minSpentInput.style.background = '#f3f4f6';
        minSpentInput.style.cursor = 'not-allowed';
    } else {
        minSpentInput.readOnly = false;
        minSpentInput.style.background = '';
        minSpentInput.style.cursor = '';
    }

    document.getElementById('tierFormTitle').textContent  = 'Edit Tier';
    document.getElementById('tierBtnLabel').textContent   = 'Update';
    document.getElementById('tierCancelBtn').style.display = '';
    document.getElementById('tierForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function resetTierForm() {
    document.getElementById('tier_id').value   = '';
    document.getElementById('tier_name').value = '';
    document.getElementById('min_spent').value = '';
    const minSpentInput = document.getElementById('min_spent');
    minSpentInput.readOnly = false;
    minSpentInput.style.background = '';
    minSpentInput.style.cursor = '';
    document.getElementById('tierFormTitle').textContent   = 'Add New Tier';
    document.getElementById('tierBtnLabel').textContent    = 'Add Tier';
    document.getElementById('tierCancelBtn').style.display = 'none';
}

// EDIT STYLE (inline form)
function editStyle(id, name) {
    document.getElementById('style_id').value   = id;
    document.getElementById('style_name').value = name;
    document.getElementById('styleFormTitle').textContent  = 'Edit Style';
    document.getElementById('styleBtnLabel').textContent   = 'Update';
    document.getElementById('styleCancelBtn').style.display = '';
    document.getElementById('styleForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function resetStyleForm() {
    document.getElementById('style_id').value   = '';
    document.getElementById('style_name').value = '';
    document.getElementById('styleFormTitle').textContent   = 'Add New Style';
    document.getElementById('styleBtnLabel').textContent    = 'Add Style';
    document.getElementById('styleCancelBtn').style.display = 'none';
}

// COVERAGE MODAL
<?php if (!empty($errors) && $activeTab === 'coverage'): ?>
window.addEventListener('DOMContentLoaded', function() {
    document.getElementById('coverageModal').classList.add('open');
    <?php if (!empty($_POST['coverage_id'])): ?>
    document.getElementById('modal_coverage_id').value = '<?= (int)$_POST['coverage_id'] ?>';
    <?php endif; ?>
    document.getElementById('modal_postcode').value = '<?= htmlspecialchars($_POST['postcode'] ?? '') ?>';
    document.getElementById('modal_city').value     = '<?= htmlspecialchars($_POST['city'] ?? '') ?>';
    document.getElementById('modal_state').value    = '<?= htmlspecialchars($_POST['state'] ?? '') ?>';
    document.getElementById('modal_fee').value      = '<?= htmlspecialchars($_POST['fee'] ?? '') ?>';
});
<?php endif; ?>

function openCoverageModal() {
    document.getElementById('coverageModalTitle').textContent  = 'Add Delivery Coverage';
    document.getElementById('modal_coverage_id').value = '';
    document.getElementById('modal_postcode').value = '';
    document.getElementById('modal_city').value     = '';
    document.getElementById('modal_state').value    = '';
    document.getElementById('modal_fee').value      = '';
    document.getElementById('coverageModal').classList.add('open');
}
function editCoverage(id, postcode, city, state, fee) {
    document.getElementById('coverageModalTitle').textContent  = 'Edit Delivery Coverage';
    document.getElementById('modal_coverage_id').value = id;
    document.getElementById('modal_postcode').value    = postcode;
    document.getElementById('modal_city').value        = city;
    document.getElementById('modal_state').value       = state;
    document.getElementById('modal_fee').value         = fee;
    document.getElementById('coverageModal').classList.add('open');
}
function closeCoverageModal() {
    document.getElementById('coverageModal').classList.remove('open');
}
document.getElementById('coverageModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeCoverageModal();
});
</script>

</body>
</html>