<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// Page Title
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
}

// TOGGLE STATUS (GET)
if (isset($_GET['toggle_type']) && isset($_GET['id'])) {

    $id = (int)$_GET['id'];

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
$tiersRes = $conn->query("SELECT * FROM membership_tier ORDER BY MIN_SPENT ASC");
$tiers    = [];
if ($tiersRes) {
    $tiers = $tiersRes->fetch_all(MYSQLI_ASSOC);
}

// Calculate summary metric card data
$activeTiersCount = 0;
foreach ($tiers as $t) {
    if (($t['STATUS'] ?? '') === 'Active') {
        $activeTiersCount++;
    }
}

$openDaysArray = !empty($bakery['OPEN_DAYS']) ? explode(',', $bakery['OPEN_DAYS']) : [];
$openDaysCount = count($openDaysArray);

$formattedOpenTime  = !empty($bakery['OPEN_TIME'])  ? date("g:i A", strtotime($bakery['OPEN_TIME']))  : 'N/A';
$formattedCloseTime = !empty($bakery['CLOSE_TIME']) ? date("g:i A", strtotime($bakery['CLOSE_TIME'])) : 'N/A';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_manage.css">
<style>
:root {
    --bg-page: #f4f5f8;
    --card-bg: #ffffff;
    --text-dark: #111827;
    --text-muted: #71717a;
    --text-light: #9ca3af;
    --accent-lime: #d4f938;
    --accent-lime-hover: #c4ec29;
    --accent-lime-subtle: #f4fde6;
    --dark-pill: #18181b;
    --dark-pill-hover: #27272a;
    --border-card: #edf0f3;
    --border-subtle: #f1f3f6;
    --shadow-soft: 0 4px 20px rgba(17, 24, 39, 0.035);
    --shadow-hover: 0 10px 28px rgba(17, 24, 39, 0.07);
    --radius-card: 20px;
    --radius-pill: 9999px;
    --font-heading: 'Plus Jakarta Sans', sans-serif;
    --font-body: 'Inter', -apple-system, sans-serif;
}

body {
    background-color: var(--bg-page);
    font-family: var(--font-body);
    color: var(--text-dark);
    margin: 0;
    padding: 0;
    -webkit-font-smoothing: antialiased;
}

.main {
    padding: 24px 30px 40px !important;
}

.mgmt-main-cont {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
    flex-wrap: wrap;
    gap: 16px;
}

.dash-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
}

.dash-title-badge {
    width: 44px;
    height: 44px;
    border-radius: var(--radius-pill);
    background: var(--accent-lime);
    color: var(--text-dark);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: 800;
    box-shadow: 0 4px 12px rgba(212, 249, 56, 0.35);
    position: relative;
    cursor: default;
}

.dash-title-badge .badge-arrow {
    position: absolute;
    bottom: -2px;
    right: -2px;
    background: var(--text-dark);
    color: var(--accent-lime);
    width: 18px;
    height: 18px;
    border-radius: var(--radius-pill);
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
}

.dash-title {
    font-family: var(--font-heading);
    font-size: 24px;
    font-weight: 500;
    color: var(--text-muted);
    line-height: 1.15;
    margin: 0;
    letter-spacing: -0.5px;
}

.dash-title strong {
    color: var(--text-dark);
    font-weight: 800;
    font-size: 26px;
    display: block;
}

.dash-header-controls {
    display: flex;
    align-items: center;
    gap: 10px;
}

.live-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 16px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    box-shadow: var(--shadow-soft);
}

.pulse-dot {
    width: 8px;
    height: 8px;
    background: #22c55e;
    border-radius: 50%;
    box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(34, 197, 94, 0); }
    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
}

/* Summary Cards */
.mgmt-sum-card-cont {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
}

@media (max-width: 992px) {
    .mgmt-sum-card-cont {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 480px) {
    .mgmt-sum-card-cont {
        grid-template-columns: 1fr;
    }
}

.mgmt-sum-card {
    background: var(--card-bg);
    padding: 18px 20px;
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-width: 0;
    position: relative;
    overflow: hidden;
}

.mgmt-sum-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-hover);
    border-color: #d1d5db;
}

.mgmt-card-title {
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.7px;
    color: var(--text-muted);
    margin: 0 0 8px 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.mgmt-card-title .card-tag {
    font-size: 10px;
    padding: 2px 7px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    font-weight: 700;
}

.mgmt-card-value {
    font-family: var(--font-heading);
    font-size: 22px;
    font-weight: 800;
    color: var(--text-dark);
    margin: 0;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Settings Layout Grid */
.settings-layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    gap: 20px;
    align-items: start;
}

@media (max-width: 900px) {
    .settings-layout {
        grid-template-columns: 1fr;
    }
}

/* Settings Sidebar Nav */
.settings-sidebar {
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    padding: 20px;
    box-shadow: var(--shadow-soft);
    position: sticky;
    top: 24px;
}

.sidebar-section-label {
    font-family: var(--font-heading);
    font-size: 11px;
    font-weight: 800;
    color: var(--text-muted);
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 0.8px;
}

.settings-nav {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 16px;
    border-radius: 12px;
    text-decoration: none;
    color: var(--text-dark);
    font-family: var(--font-heading);
    font-size: 14px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.nav-link i {
    font-size: 16px;
    color: var(--text-muted);
    transition: color 0.2s ease;
}

.nav-link:hover {
    background: var(--bg-page);
    color: var(--text-dark);
}

.nav-link:hover i {
    color: var(--text-dark);
}

.nav-link.active {
    background: var(--dark-pill);
    color: #ffffff;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
}

.nav-link.active i {
    color: var(--accent-lime);
}

.sidebar-divider {
    border: none;
    border-top: 1px solid var(--border-subtle);
    margin: 14px 0;
}

/* Settings Content Container */
.settings-content {
    min-width: 0;
}

/* Section Cards */
.section-card {
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    padding: 28px;
    box-shadow: var(--shadow-soft);
    margin-bottom: 24px;
}

.section-header {
    margin-bottom: 22px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-subtle);
}

.section-title {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 800;
    color: var(--text-dark);
}

.section-desc {
    font-size: 13.5px;
    color: var(--text-muted);
    margin-top: 4px;
}

/* Forms */
.edit-form {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 640px) {
    .edit-form {
        grid-template-columns: 1fr;
    }
}

.edit-form .full {
    grid-column: span 2;
}

@media (max-width: 640px) {
    .edit-form .full {
        grid-column: span 1;
    }
}

.edit-form label,
.form-label {
    display: block;
    font-family: var(--font-heading);
    font-size: 13px;
    font-weight: 700;
    color: var(--text-dark);
    margin-bottom: 8px;
}

.form-input,
.edit-form input,
.edit-form textarea,
.edit-form select {
    width: 100%;
    padding: 11px 16px;
    border: 1px solid var(--border-card);
    border-radius: 12px;
    font-size: 14px;
    font-family: var(--font-body);
    color: var(--text-dark);
    outline: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
    background: #ffffff;
}

.form-input:focus,
.edit-form input:focus,
.edit-form textarea:focus,
.edit-form select:focus {
    border-color: var(--dark-pill);
    box-shadow: 0 0 0 3px rgba(212, 249, 56, 0.4);
}

.edit-form textarea {
    resize: vertical;
    min-height: 95px;
}

.form-row {
    display: flex;
    gap: 14px;
    align-items: flex-end;
    flex-wrap: wrap;
    margin-bottom: 24px;
    background: var(--bg-page);
    padding: 20px;
    border-radius: 16px;
    border: 1px solid var(--border-card);
}

.form-group {
    flex: 1;
    min-width: 200px;
}

.form-group.narrow {
    max-width: 200px;
}

/* Open Days Interactive Selector */
.days-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 6px;
}

.day-checkbox-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 16px;
    border-radius: var(--radius-pill);
    border: 1px solid var(--border-card);
    background: var(--bg-page);
    color: var(--text-dark);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    user-select: none;
}

.day-checkbox-pill:hover {
    border-color: #d1d5db;
    background: #ffffff;
}

.day-checkbox-pill input[type="checkbox"] {
    accent-color: var(--dark-pill);
    width: 16px;
    height: 16px;
    cursor: pointer;
}

/* Buttons */
.btn-save {
    grid-column: span 2;
    background: var(--dark-pill);
    color: #ffffff;
    padding: 13px 28px;
    border: none;
    border-radius: var(--radius-pill);
    cursor: pointer;
    font-family: var(--font-heading);
    font-size: 14px;
    font-weight: 700;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
}

@media (max-width: 640px) {
    .btn-save {
        grid-column: span 1;
    }
}

.btn-save:hover {
    background: var(--dark-pill-hover);
    transform: translateY(-1px);
}

.btn-add {
    border: none;
    background: var(--dark-pill);
    color: #ffffff !important;
    padding: 11px 22px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: var(--font-heading);
    font-size: 13.5px;
    font-weight: 700;
    white-space: nowrap;
    transition: all 0.2s ease;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
}

.btn-add i {
    color: #ffffff;
}

#tierBtnLabel
{
    color:#ffffff;
}

.btn-add:hover {
    background: var(--dark-pill-hover);
    transform: translateY(-1px);
}

.btn-toggle {
    text-decoration: none;
    background: var(--dark-pill);
    color: #ffffff;
    padding: 7px 15px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
    border: none;
}

.btn-toggle i {
    color: #ffffff;
}

.btn-toggle:hover {
    background: var(--dark-pill-hover);
    color: #ffffff;
}

.btn-edit {
    text-decoration: none;
    background: var(--bg-page);
    color: var(--text-dark);
    padding: 7px 15px;
    border-radius: var(--radius-pill);
    font-size: 12px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s ease;
    border: 1px solid var(--border-card);
    cursor: pointer;
}

.btn-edit:hover {
    background: #ffffff;
    border-color: #d1d5db;
}

.btn-cancel {
    background: var(--bg-page);
    color: var(--text-dark);
    border: 1px solid var(--border-card);
    padding: 11px 20px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    font-family: var(--font-heading);
    font-size: 13.5px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.btn-cancel:hover {
    background: #ffffff;
    border-color: #d1d5db;
}

.action-group {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
}

/* Data Table */
.mgmt-tbl {
    width: 100%;
    overflow-x: auto;
}

.mgmt-tbl table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--card-bg);
    border-radius: var(--radius-card);
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
    overflow: hidden;
}

.mgmt-tbl th {
    background: #fafbfc;
    color: var(--text-muted);
    padding: 15px 18px;
    font-family: var(--font-heading);
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    border-bottom: 1px solid var(--border-card);
    vertical-align: middle;
    text-align: left;
}

.mgmt-tbl td {
    padding: 16px 18px;
    border-bottom: 1px solid var(--border-subtle);
    vertical-align: middle;
    color: var(--text-dark);
    font-size: 14px;
}

.mgmt-tbl tbody tr {
    transition: background 0.15s ease;
}

.mgmt-tbl tbody tr:hover {
    background: #fbfcfd;
}

.mgmt-tbl tbody tr:last-child td {
    border-bottom: none;
}

.cell-name {
    font-family: var(--font-heading);
    font-weight: 700;
    color: var(--text-dark);
}

.cell-amount {
    font-weight: 600;
    color: var(--text-dark);
}

/* Badges */
.badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: var(--radius-pill);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.3px;
    text-transform: uppercase;
}

.badge-active {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge-inactive {
    background: var(--bg-page);
    color: var(--text-muted);
    border: 1px solid var(--border-card);
}

/* Image Box */
.image-upload-wrapper {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--bg-page);
    padding: 16px;
    border-radius: 16px;
    border: 1px dashed #d1d5db;
}

.image-preview-thumb {
    width: 90px;
    height: 90px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid var(--border-card);
    box-shadow: var(--shadow-soft);
}

.image-upload-controls {
    flex: 1;
}

/* Empty State */
.empty-state {
    text-align: center;
    color: var(--text-muted);
    padding: 40px 24px;
    font-size: 14px;
}

.empty-state i {
    font-size: 28px;
    display: block;
    margin-bottom: 8px;
    color: var(--text-light);
}

/* Field Validation Error */
.input-error {
    border-color: #ef4444 !important;
    box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
}

.field-error {
    color: #ef4444;
    font-size: 12.5px;
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
    font-weight: 600;
}

/* Toast */
.toast {
    position: fixed;
    top: 24px;
    right: 24px;
    padding: 14px 20px;
    border-radius: var(--radius-pill);
    color: #ffffff !important;
    font-family: var(--font-heading);
    font-size: 13.5px;
    font-weight: 700;
    z-index: 9999;
    box-shadow: 0 10px 28px rgba(17, 24, 39, 0.15);
    display: flex;
    align-items: center;
    gap: 12px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.toast.success { background: #15803d; }
.toast.error   { background: #b91c1c; }

.toast-close-btn {
    cursor: pointer;
    opacity: 0.8;
    font-size: 16px;
    line-height: 1;
}

.toast-close-btn:hover { opacity: 1; }
</style>
</head>

<body>
<div class="global-layout">
    <?php include "global_layout_ctrl.php"; ?>
    <div class="main">
        <div class="mgmt-main-cont">

            <!-- Page Header -->
            <div class="dash-header">
                <div class="dash-title-group">
                    <div class="dash-title-badge">
                        <i class="bi bi-gear-fill"></i>
                        <div class="badge-arrow">↗</div>
                    </div>
                    <div>
                        <h1 class="dash-title">System <strong>Settings & Configuration</strong></h1>
                    </div>
                </div>
                <div class="dash-header-controls">
                    <span class="live-pill"><span class="pulse-dot"></span> System Configured</span>
                </div>
            </div>

            <!-- Settings Layout Grid -->
            <div class="settings-layout">

                <!-- SIDEBAR -->
                <aside class="settings-sidebar">
                    <div class="sidebar-section-label">Configuration</div>
                    <nav class="settings-nav">
                        <a href="?tab=bakery" class="nav-link <?= $activeTab==='bakery'?'active':'' ?>">
                            <i class="bi bi-shop"></i> Bakery Info
                        </a>
                        <hr class="sidebar-divider">
                        <a href="?tab=tiers" class="nav-link <?= $activeTab==='tiers'?'active':'' ?>">
                            <i class="bi bi-gem"></i> Membership Tiers
                        </a>
                    </nav>
                </aside>

                <!-- CONTENT AREA -->
                <div class="settings-content">

                    <!-- TAB 1: BAKERY INFO -->
                    <?php if ($activeTab === 'bakery'): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <div class="section-title">Shop Details & Business Hours</div>
                            <div class="section-desc">Manage store profile, contact details, address, and operating schedule.</div>
                        </div>

                        <form method="POST" class="edit-form" enctype="multipart/form-data">
                            <input type="hidden" name="update_bakery" value="1">
                            <input type="hidden" name="existing_shop_image" value="<?= htmlspecialchars($bakery['SHOP_IMAGE'] ?? '') ?>">

                            <!-- Shop Image Upload Container -->
                            <div class="full">
                                <label>Shop Banner / Image</label>
                                <div class="image-upload-wrapper">
                                    <?php if (!empty($bakery['SHOP_IMAGE'])): ?>
                                        <img src="<?= htmlspecialchars($bakery['SHOP_IMAGE']) ?>" class="image-preview-thumb" alt="Shop Image">
                                    <?php else: ?>
                                        <div class="image-preview-thumb" style="display:flex;align-items:center;justify-content:center;background:#fff;color:var(--text-light)">
                                            <i class="bi bi-image" style="font-size:24px"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="image-upload-controls">
                                        <input type="file" name="shop_image" accept="image/*" class="<?= hasError('shop_image') ? 'input-error' : '' ?>">
                                        <small style="color:var(--text-muted);font-size:12px;margin-top:6px;display:block">Accepted formats: JPG, PNG, WEBP. Max 2MB. Leave blank to retain current image.</small>
                                        <?php if (hasError('shop_image')): ?><div class="field-error"><?= getError('shop_image') ?></div><?php endif; ?>
                                    </div>
                                </div>
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
                                <input name="phone" class="<?= fieldClass('phone') ?>" value="<?= htmlspecialchars($_POST['phone'] ?? $bakery['PHONE'] ?? '') ?>" placeholder="e.g. 011-12345678" maxlength="20" required>
                                <?php if (hasError('phone')): ?><div class="field-error"><?= getError('phone') ?></div><?php endif; ?>
                            </div>

                            <!-- Email -->
                            <div>
                                <label>Email Address <span style="color:#ef4444">*</span></label>
                                <input type="email" name="email" class="<?= fieldClass('email') ?>" value="<?= htmlspecialchars($_POST['email'] ?? $bakery['EMAIL'] ?? '') ?>" placeholder="contact@bakery.com" maxlength="255" required>
                                <?php if (hasError('email')): ?><div class="field-error"><?= getError('email') ?></div><?php endif; ?>
                            </div>

                            <!-- Postcode -->
                            <div>
                                <label>Postcode <span style="color:#ef4444">*</span></label>
                                <input name="postcode" class="<?= fieldClass('postcode') ?>" value="<?= htmlspecialchars($_POST['postcode'] ?? $bakery['POSTCODE'] ?? '') ?>" placeholder="e.g. 81000" maxlength="5" pattern="[0-9]{5}" title="5-digit postcode" required>
                                <?php if (hasError('postcode')): ?><div class="field-error"><?= getError('postcode') ?></div><?php endif; ?>
                            </div>

                            <!-- Address -->
                            <div class="full">
                                <label>Street Address <span style="color:#ef4444">*</span></label>
                                <textarea name="address" class="<?= hasError('address') ? 'input-error' : '' ?>" placeholder="Full street address..." required><?= htmlspecialchars($_POST['address'] ?? $bakery['ADDRESS'] ?? '') ?></textarea>
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
                                <textarea name="bakery_des" class="<?= hasError('bakery_des') ? 'input-error' : '' ?>" placeholder="Short introduction for your bakery..." maxlength="300" required><?= htmlspecialchars($_POST['bakery_des'] ?? $bakery['BAKERY_DES'] ?? '') ?></textarea>
                                <small style="color:var(--text-muted);font-size:12px;margin-top:4px;display:block;text-align:right">Maximum 300 characters</small>
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
                                <label>Operating Days <span style="color:#ef4444">*</span></label>
                                <?php
                                    $savedDays = explode(',', $_POST['open_days_raw'] ?? $bakery['OPEN_DAYS'] ?? '');
                                    if (!empty($_POST['open_days']) && is_array($_POST['open_days'])) {
                                        $savedDays = $_POST['open_days'];
                                    }
                                    $dayOptions = [
                                       'Mon' => 'Monday', 'Tue' => 'Tuesday', 'Wed' => 'Wednesday',
                                       'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday', 'Sun' => 'Sunday'
                                    ];
                                ?>
                                <div class="days-grid">
                                    <?php foreach ($dayOptions as $val => $label): ?>
                                        <label class="day-checkbox-pill">
                                            <input type="checkbox" name="open_days[]" value="<?= $val ?>" <?= in_array($val, $savedDays) ? 'checked' : '' ?>>
                                            <span><?= $label ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (hasError('open_days')): ?><div class="field-error" style="margin-top:8px"><?= getError('open_days') ?></div><?php endif; ?>
                            </div>

                            <button type="submit" class="btn-save">
                                <i class="bi bi-check-circle-fill"></i> Save Bakery Settings
                            </button>
                        </form>
                    </div>

                    <!-- TAB 2: MEMBERSHIP TIERS -->
                    <?php elseif ($activeTab === 'tiers'): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <div class="section-title" id="tierFormTitle">Add New Membership Tier</div>
                            <div class="section-desc">Define customer spending tiers and minimum qualifying thresholds.</div>
                        </div>

                        <!-- Add/Edit Inline Form -->
                        <form method="POST" class="form-row" id="tierForm">
                            <input type="hidden" name="save_tier" value="1">
                            <input type="hidden" name="tier_id" id="tier_id" value="">

                            <div class="form-group">
                                <label class="form-label">Tier Name <span style="color:#ef4444">*</span></label>
                                <input type="text" class="<?= fieldClass('tier_name') ?>" name="tier_name" id="tier_name" placeholder="e.g. Gold" maxlength="50" value="<?= htmlspecialchars($_POST['tier_name'] ?? '') ?>" required>
                                <?php if (hasError('tier_name')): ?><div class="field-error"><?= getError('tier_name') ?></div><?php endif; ?>
                            </div>

                            <div class="form-group narrow">
                                <label class="form-label">Minimum Spent (RM) <span style="color:#ef4444">*</span></label>
                                <input type="number" class="<?= fieldClass('min_spent') ?>" name="min_spent" id="min_spent" min="0" step="0.01" placeholder="0.00" value="<?= htmlspecialchars($_POST['min_spent'] ?? '') ?>" required>
                                <?php if (hasError('min_spent')): ?><div class="field-error"><?= getError('min_spent') ?></div><?php endif; ?>
                            </div>

                            <div style="display:flex;gap:8px;align-items:flex-end">
                                <button type="submit" class="btn-add">
                                    <i class="bi bi-plus-lg"></i>
                                    <span id="tierBtnLabel">Add Tier</span>
                                </button>
                                <button type="button" class="btn-cancel" id="tierCancelBtn" onclick="resetTierForm()" style="display:none">Cancel</button>
                            </div>
                        </form>

                        <!-- Tiers Table -->
                        <div class="mgmt-tbl">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Tier Name</th>
                                        <th>Minimum Spent</th>
                                        <th>Status</th>
                                        <th style="text-align:right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($tiers)): ?>
                                        <?php foreach ($tiers as $row): ?>
                                        <tr>
                                            <td><span class="cell-name"><?= htmlspecialchars($row['TIER_NAME']) ?></span></td>
                                            <td><span class="cell-amount">RM <?= number_format($row['MIN_SPENT'], 2) ?></span></td>
                                            <td>
                                                <span class="badge badge-<?= strtolower($row['STATUS']) ?>">
                                                    <?= htmlspecialchars($row['STATUS']) ?>
                                                </span>
                                            </td>
                                            <td style="text-align:right">
                                                <div class="action-group" style="justify-content:flex-end">
                                                    <button class="btn-edit" onclick="editTier(<?= (int)$row['TIER_ID'] ?>, '<?= addslashes($row['TIER_NAME']) ?>', '<?= $row['MIN_SPENT'] ?>', <?= $row['IS_DEFAULT'] ?>)">
                                                        <i class="bi bi-pencil-square"></i> Edit
                                                    </button>
                                                    <?php if ($row['IS_DEFAULT']): ?>
                                                        <span class="btn-toggle" style="opacity:0.4;cursor:not-allowed;" title="Default tier status cannot be modified">
                                                            <i class="bi bi-dash-circle"></i> Locked
                                                        </span>
                                                    <?php else: ?>
                                                        <a class="btn-toggle" href="?toggle_type=tier&id=<?= (int)$row['TIER_ID'] ?>">
                                                            <i class="bi bi-arrow-repeat"></i> Toggle
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="empty-state">
                                                <i class="bi bi-gem"></i> No membership tiers configured yet.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
// Toast Notification
function showToast(type, message) {
    document.querySelectorAll('.toast').forEach(t => t.remove());
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    
    const icon = document.createElement('i');
    icon.className = type === 'success' ? 'bi bi-check-circle-fill' : 'bi bi-exclamation-triangle-fill';
    
    const text = document.createElement('span');
    text.innerText = message;
    
    const closeBtn = document.createElement('span');
    closeBtn.innerHTML = '&times;';
    closeBtn.className = 'toast-close-btn';
    
    toast.appendChild(icon);
    toast.appendChild(text);
    toast.appendChild(closeBtn);
    document.body.appendChild(toast);
    
    let removed = false;
    function removeToast() {
        if (removed) return; 
        removed = true;
        toast.style.opacity = '0'; 
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 300);
    }
    
    closeBtn.addEventListener('click', e => { e.stopPropagation(); removeToast(); });
    toast.addEventListener('click', removeToast);
    setTimeout(removeToast, type === 'error' ? 8000 : 3500);
}

<?php if ($success): ?>showToast('success', 'Settings updated successfully.');<?php endif; ?>
<?php if (!empty($errors)): ?>showToast('error', 'Please resolve the highlighted validation errors.');<?php endif; ?>
<?php if (isset($_GET['error']) && $_GET['error'] === 'default_toggle'): ?>
showToast('error', 'Default tier status cannot be deactivated.');
<?php endif; ?>

// Edit Tier Inline Form Handler
function editTier(id, name, minSpent, isDefault) {
    document.getElementById('tier_id').value   = id;
    document.getElementById('tier_name').value = name;
    document.getElementById('min_spent').value = minSpent;

    const minSpentInput = document.getElementById('min_spent');
    if (isDefault == 1) {
        minSpentInput.readOnly = true;
        minSpentInput.style.background = 'var(--bg-page)';
        minSpentInput.style.cursor = 'not-allowed';
    } else {
        minSpentInput.readOnly = false;
        minSpentInput.style.background = '#ffffff';
        minSpentInput.style.cursor = '';
    }

    document.getElementById('tierFormTitle').textContent  = 'Edit Membership Tier';
    document.getElementById('tierBtnLabel').textContent   = 'Update Tier';
    document.getElementById('tierCancelBtn').style.display = 'inline-block';
    document.getElementById('tierForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function resetTierForm() {
    document.getElementById('tier_id').value   = '';
    document.getElementById('tier_name').value = '';
    document.getElementById('min_spent').value = '';
    
    const minSpentInput = document.getElementById('min_spent');
    minSpentInput.readOnly = false;
    minSpentInput.style.background = '#ffffff';
    minSpentInput.style.cursor = '';
    
    document.getElementById('tierFormTitle').textContent   = 'Add New Membership Tier';
    document.getElementById('tierBtnLabel').textContent    = 'Add Tier';
    document.getElementById('tierCancelBtn').style.display = 'none';
}
</script>
</body>
</html>