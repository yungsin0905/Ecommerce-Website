<?php
require_once("config.php");
session_start();

// check admin 
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
} 
$admin_id = $_SESSION['admin_id'];

// Get voucher id
$voucherId = $_GET['voucher_id'] ?? null;

if (!$voucherId || !is_numeric($voucherId)) {
    die("Invalid Voucher ID");
}
$voucherId = (int)$voucherId;

// GET VOUCHER + TIER info
$stmt = $conn->prepare("
    SELECT 
        v.*, 
        t.TIER_NAME,

        CASE
            WHEN v.VOUCHER_STATUS = 'Inactive' THEN 'Inactive'
            WHEN NOW() < v.START_DATE THEN 'Upcoming'
            WHEN v.EXPIRY_DATE IS NOT NULL 
                 AND NOW() > v.EXPIRY_DATE THEN 'Expired'
            WHEN v.MAX_USAGE != -1 
                 AND v.USED_COUNT >= v.MAX_USAGE THEN 'Fully Redeemed'
            ELSE 'Active'
        END AS FINAL_STATUS

    FROM voucher v
    LEFT JOIN membership_tier t 
        ON v.TIER_ID = t.TIER_ID

    WHERE v.VOUCHER_ID = ?
    AND v.IS_DELETED = 0
");
$stmt->bind_param("i", $voucherId);
$stmt->execute();

$voucher = $stmt->get_result()->fetch_assoc();

if (!$voucher) {
    die("Voucher not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voucher Details | Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_global.css">
    <link rel="stylesheet" href="global_view_form.css">
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

.view-wrapper {
    max-width: 900px;
    margin: 0 auto;
    padding: 28px 24px 60px;
}

/* Back Link */
.form-back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    border: 1px solid var(--border-card);
    padding: 8px 18px;
    border-radius: var(--radius-pill);
    font-size: 13px;
    font-weight: 600;
    color: var(--text-dark);
    text-decoration: none;
    box-shadow: var(--shadow-soft);
    transition: all 0.2s ease;
    margin-bottom: 20px;
}

.form-back-link:hover {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-color: var(--dark-pill);
    transform: translateX(-2px);
    text-decoration: none;
}

/* Page Header */
.dash-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
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

.edit-btn-pill {
    background: var(--dark-pill);
    color: #ffffff !important;
    border-radius: var(--radius-pill);
    padding: 10px 22px;
    font-size: 13.5px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(24, 24, 27, 0.15);
    transition: all 0.2s ease;
    font-family: var(--font-heading);
}

.edit-btn-pill:hover {
    background: #000000;
    color: var(--accent-lime) !important;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(24, 24, 27, 0.25);
}

/* Detail Card */
.detail-card {
    background: var(--card-bg);
    border: 1px solid var(--border-card);
    border-radius: var(--radius-card);
    padding: 28px 32px;
    box-shadow: var(--shadow-soft);
    margin-bottom: 24px;
}

.detail-card-title {
    font-family: var(--font-heading);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-dark);
    margin: 0 0 20px 0;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 14px;
    border-bottom: 1px solid var(--border-subtle);
}

.detail-card-title i {
    color: var(--text-dark);
    font-size: 16px;
}

.detail-card-title .card-badge {
    font-size: 11px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: var(--radius-pill);
    background: var(--bg-page);
    color: var(--text-muted);
    margin-left: auto;
}

/* Detail Rows */
.detail-grid {
    display: flex;
    flex-direction: column;
}

.detail-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    align-items: center;
    padding: 14px 0;
    border-bottom: 1px solid var(--border-subtle);
    gap: 16px;
}

.detail-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.detail-row:first-child {
    padding-top: 0;
}

.detail-label {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
}

.detail-value {
    font-size: 14px;
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
}

.badge.status-active {
    background: var(--accent-lime);
    color: var(--text-dark);
}

.badge.status-inactive {
    background: var(--bg-page);
    color: var(--text-muted);
    border: 1px solid var(--border-card);
}

.badge.status-upcoming {
    background: #e0f2fe;
    color: #0369a1;
}

.badge.status-expired {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid var(--border-card);
}

.badge.status-fullyredeemed {
    background: #fef3c7;
    color: #b45309;
}

.badge.type-public {
    background: #dcfce7;
    color: #15803d;
}

.badge.type-tier {
    background: #f3e8ff;
    color: #7e22ce;
}

.code-pill {
    font-family: monospace;
    font-weight: 700;
    background: var(--bg-page);
    border: 1px solid var(--border-card);
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 13.5px;
    display: inline-block;
}

.highlight-stat {
    font-family: var(--font-heading);
    font-size: 18px;
    font-weight: 800;
    color: var(--text-dark);
}
</style>
</head>

<body>

<div class="view-wrapper">

    <a href="manage_voucher.php" class="form-back-link" title="Go Back To Voucher Page">
        <i class="bi bi-arrow-left"></i> Back to Vouchers
    </a>

    <div class="dash-header">
        <div class="dash-title-group">
            <div class="dash-title-badge">
                <i class="bi bi-ticket-perforated"></i>
                <div class="badge-arrow"><i class="bi bi-arrow-up-right"></i></div>
            </div>
            <div>
                <h1 class="dash-title"><strong>Voucher Details</strong> Detailed configuration and limits</h1>
            </div>
        </div>

        <a href="edit_voucher.php?voucher_id=<?= $voucherId ?>" class="edit-btn-pill">
            <i class="bi bi-pencil-square"></i> Edit Voucher
        </a>
    </div>

    <!-- BASIC INFORMATION -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-info-circle"></i> Basic Information
            <span class="card-badge">ID #<?= $voucherId ?></span>
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Voucher Code</div>
                <div class="detail-value">
                    <span class="code-pill">#<?= htmlspecialchars($voucher['VOUCHER_CODE']) ?></span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Voucher Name</div>
                <div class="detail-value"><?= htmlspecialchars($voucher['VOUCHER_NAME']) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Type</div>
                <div class="detail-value">
                    <span class="badge type-<?= strtolower($voucher['VOUCHER_TYPE']) ?>">
                        <?= htmlspecialchars($voucher['VOUCHER_TYPE']) ?>
                    </span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="badge status-<?= strtolower(str_replace(' ', '', $voucher['FINAL_STATUS'])) ?>">
                        <?= htmlspecialchars($voucher['FINAL_STATUS']) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- VALIDITY PERIOD -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-calendar-range"></i> Validity Period
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Start Date</div>
                <div class="detail-value"><?= date("d M Y, H:i", strtotime($voucher['START_DATE'])) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Expiry Date</div>
                <div class="detail-value">
                    <?= !empty($voucher['EXPIRY_DATE']) ? date("d M Y, H:i", strtotime($voucher['EXPIRY_DATE'])) : "Claim-based voucher: valid for 3 months from the time it is assigned to the customer." ?>
                </div>
            </div>
        </div>
    </div>

    <!-- DISCOUNT & USAGE RULES -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-percent"></i> Discount & Usage Rules
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Applicable To</div>
                <div class="detail-value">
                    <?php 
                        $tName = !empty($voucher['TIER_NAME']) ? $voucher['TIER_NAME'] : 'All';
                        $hue = hexdec(substr(md5($tName), 0, 7)) % 360; 
                        $tBg = "hsl({$hue}, 70%, 92%)";
                        $tTxt = "hsl({$hue}, 70%, 25%)";
                        $tBorder = "hsl({$hue}, 50%, 80%)";
                    ?>
                    <span class="badge" style="background-color: <?= $tBg ?>; color: <?= $tTxt ?>; border: 1px solid <?= $tBorder ?>; font-weight: 700;">
                        <i class="bi bi-award me-1"></i> <?= htmlspecialchars($tName) ?>
                    </span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Discount Rate</div>
                <div class="detail-value">
                    <span class="highlight-stat"><?= $voucher['DISCOUNT_RATE'] ?>% OFF</span>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Min Spend</div>
                <div class="detail-value">RM <?= number_format($voucher['MIN_SPEND'], 2) ?></div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Per User Limit</div>
                <div class="detail-value">
                    <?= $voucher['PER_USER_LIMIT'] == -1 ? '<span style="color:var(--text-muted);">Unlimited</span>' : htmlspecialchars($voucher['PER_USER_LIMIT']) . ' time(s)' ?>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Max Usage</div>
                <div class="detail-value">
                    <?= $voucher['MAX_USAGE'] == -1 ? '<span style="color:var(--text-muted);">Unlimited</span>' : htmlspecialchars($voucher['MAX_USAGE']) . ' total redemptions' ?>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Already Used</div>
                <div class="detail-value">
                    <span class="code-pill">
                        <?= $voucher['USED_COUNT'] ?> / <?= $voucher['MAX_USAGE'] == -1 ? '∞' : $voucher['MAX_USAGE'] ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- SYSTEM INFO -->
    <div class="detail-card">
        <div class="detail-card-title">
            <i class="bi bi-clock-history"></i> System Information
        </div>

        <div class="detail-grid">
            <div class="detail-row">
                <div class="detail-label">Created At</div>
                <div class="detail-value" style="color:var(--text-muted); font-weight:500;">
                    <?= date("d M Y, H:i", strtotime($voucher['CREATED_AT'])) ?>
                </div>
            </div>

            <div class="detail-row">
                <div class="detail-label">Updated At</div>
                <div class="detail-value" style="color:var(--text-muted); font-weight:500;">
                    <?= !empty($voucher['UPDATED_AT']) ? date("d M Y, H:i", strtotime($voucher['UPDATED_AT'])) : 'N/A' ?>
                </div>
            </div>
        </div>
    </div>

</div>

</body>
</html>