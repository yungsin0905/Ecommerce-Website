<?php
require_once("config.php");
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}
$admin_id = $_SESSION['admin_id'];

// error & success message
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);
$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);

// page title
$pageTitle = "Production Capacity";

// GET BAKERY OPEN DAYS
$bakeryQuery = $conn->query("
    SELECT OPEN_DAYS
    FROM bakery_info
    LIMIT 1
");

$bakeryData = $bakeryQuery->fetch_assoc();

$openDays = array_map('trim', explode(',', $bakeryData['OPEN_DAYS']));
// ALL WEEK DAYS
$allDays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
// CLOSED DAYS
$closedDays = array_diff($allDays, $openDays);

// CHECK IF DATE IS OPEN DAY
function isBakeryOpen($date, $openDays)
{
    // Convert date to Mon/Tue/Wed format
    $day = date('D', strtotime($date));

    return in_array($day, $openDays);
}

// SINGLE UPSERT (ADD / EDIT ONE DATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_single'])) {

    // Get inputs
    $date = $_POST['production_date'] ?? '';
    $max = intval($_POST['max_cakes'] ?? 0);

    // Cannot be empty
    if (!$date || $max < 0) {
        $_SESSION['error'] = "Invalid input";
        header("Location: production_capacity.php");
        exit;
    }

    // cannot select past date
    if ($date < date('Y-m-d')) {
        $_SESSION['error'] = "Past date not allowed";
        header("Location: production_capacity.php");
        exit;
    }

    // max limit
    if ($max > 500) {
        $_SESSION['error'] = "Maximum capacity is 500";
        header("Location: production_capacity.php");
        exit;
    }

    // Bakery closed day check
    if (!isBakeryOpen($date, $openDays)) {

        $_SESSION['error'] = "Cannot set capacity: bakery is closed on this day.";

        header("Location: production_capacity.php");
        exit;
    }

    // single date: check if the selected date cannot deliver (either specific date or weekly off) - if yes, not allowed to set capacity.
    $checkRule = $conn->prepare("
        SELECT STATUS, REASON 
        FROM delivery_date_rules 
        WHERE (DATE = ?) 
           OR (DAY_OF_WEEK = (WEEKDAY(?) + 1) AND DATE IS NULL)
        LIMIT 1
    ");
    $checkRule->bind_param("ss", $date, $date);
    $checkRule->execute();
    $ruleResult = $checkRule->get_result()->fetch_assoc();

    if ($ruleResult && $ruleResult['STATUS'] === 'Active') {
        $reason = !empty($ruleResult['REASON']) ? "（Reason: " . $ruleResult['REASON'] . "）" : "";
        $_SESSION['error'] = "Unable to save: this date is marked as a 'cannot deliver' day." . $reason;
        header("Location: production_capacity.php");
        exit;
    }

    // Check existing booked
    $checkBooked = $conn->prepare("
        SELECT ALREADY_BOOKED
        FROM production_capacity
        WHERE PRODUCTION_DATE = ?
    ");

    $checkBooked->bind_param("s", $date);
    $checkBooked->execute();

    $existing = $checkBooked->get_result()->fetch_assoc();

    // if admin want to change the max cakes for a day, cannot less than the already booked amount at that day
    if ($existing) {

        if ($max < $existing['ALREADY_BOOKED']) {
            $_SESSION['error'] = "Cannot set below booked quantity";
            header("Location: production_capacity.php");
            exit;
        }
    }

    // if no errors, save
    $stmt = $conn->prepare("
        INSERT INTO production_capacity (PRODUCTION_DATE, MAX_CAKES, ALREADY_BOOKED)
        VALUES (?, ?, 0)
        ON DUPLICATE KEY UPDATE
        MAX_CAKES = VALUES(MAX_CAKES)
    ");

    $stmt->bind_param("si", $date, $max);
    $stmt->execute();

    $_SESSION['success'] = "Capacity saved successfully";

    header("Location: production_capacity.php");
    exit;
}

// BULK RANGE GENERATOR (MULTIPLE DATES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bulk'])) {

    // Get inputs
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $max = intval($_POST['bulk_max'] ?? 0);

    // Cannot be empty
    if (!$start || !$end || $max < 0) {
        $_SESSION['error'] = "Invalid bulk input";
        header("Location: production_capacity.php");
        exit;
    }

    // cannot select past date
    if ($start < date('Y-m-d')) {
        $_SESSION['error'] = "Past date not allowed";
        header("Location: production_capacity.php");
        exit;
    }

    // end before start not allowed
    if ($start > $end) {
        $_SESSION['error'] = "End date must be after start date";
        header("Location: production_capacity.php");
        exit;
    }

    // max limit 
    if ($max > 500) {
        $_SESSION['error'] = "Maximum capacity is 500";
        header("Location: production_capacity.php");
        exit;
    }

    // bulk range (max 365 days)
    $current = strtotime($start);
    $endTime = strtotime($end);

    $days = ($endTime - $current) / 86400;

    if ($days > 365) {
        $_SESSION['error'] = "Maximum 365 days only";
        header("Location: production_capacity.php");
        exit;
    }

    while ($current <= $endTime) {

        $date = date("Y-m-d", $current);

        // Skip bakery closed days
        if (!isBakeryOpen($date, $openDays)) {

            $current = strtotime("+1 day", $current);
            continue;
        }

        // Bulk date: check if the selected date cannot deliver (either specific date or weekly) - if yes, not allowed to set capacity.
        $checkBulkRule = $conn->prepare("
            SELECT STATUS 
            FROM delivery_date_rules 
            WHERE (DATE = ?) 
               OR (DAY_OF_WEEK = (WEEKDAY(?) + 1) AND DATE IS NULL)
            LIMIT 1
        ");
        $checkBulkRule->bind_param("ss", $date, $date);
        $checkBulkRule->execute();
        $bulkRule = $checkBulkRule->get_result()->fetch_assoc();

        // If the selected date is a 'cannot deliver' day, skip it without error, and continue to the next day
        if ($bulkRule && $bulkRule['STATUS'] === 'Active') {
            $current = strtotime("+1 day", $current);
            continue; 
        }

        // Check existing booked
        $checkBooked = $conn->prepare("
            SELECT ALREADY_BOOKED
            FROM production_capacity
            WHERE PRODUCTION_DATE = ?
        ");

        $checkBooked->bind_param("s", $date);
        $checkBooked->execute();

        $existing = $checkBooked->get_result()->fetch_assoc();

        // If set some date range that 'max > already booked' -> not allowed
        if ($existing) {

            if ($max < $existing['ALREADY_BOOKED']) {
                $_SESSION['error'] = "Bulk failed: some dates have booked quantity higher than max";
                header("Location: production_capacity.php");
                exit;
            }
        }

        // if no errors, save
        $stmt = $conn->prepare("
            INSERT INTO production_capacity (PRODUCTION_DATE, MAX_CAKES, ALREADY_BOOKED)
            VALUES (?, ?, 0)
            ON DUPLICATE KEY UPDATE
            MAX_CAKES = VALUES(MAX_CAKES)
        ");

        $stmt->bind_param("si", $date, $max);
        $stmt->execute();

        $current = strtotime("+1 day", $current);
    }

    $_SESSION['success'] = "Bulk capacity generated successfully";

    header("Location: production_capacity.php");
    exit;
}

// Filter month & year
$month = $_GET['month'] ?? date('n');
$year  = $_GET['year'] ?? date('Y');

// Calculate remaining capacity
$sql = "
SELECT *,
(MAX_CAKES - ALREADY_BOOKED) AS remaining
FROM production_capacity
WHERE 1=1
";

if ($month != '') {
    $sql .= " AND MONTH(PRODUCTION_DATE) = '$month'";
}

if ($year != '') {
    $sql .= " AND YEAR(PRODUCTION_DATE) = '$year'";
}

$sql .= " ORDER BY PRODUCTION_DATE ASC";

$result = $conn->query($sql);

// CALENDAR DATA
$calendarEvents = [];

// Calendar filter default to current month/year
$initialDate = sprintf("%04d-%02d-01", $year, $month);

$cal = $conn->query("
    SELECT PRODUCTION_DATE, MAX_CAKES, ALREADY_BOOKED
    FROM production_capacity
");

// Loop through each date and determine status for calendar display
while ($row = $cal->fetch_assoc()) {

    $remaining = $row['MAX_CAKES'] - $row['ALREADY_BOOKED'];

    if ($remaining <= 0) {
        $color = "#fca5a5"; 
        $title = "FULL";
    } elseif ($remaining <= 5) {
        $color = "#fde68a"; 
        $title = "LOW";
    } else {
        $color = "#86efac"; 
        $title = "OK";
    }

    $calendarEvents[$row['PRODUCTION_DATE']] = [
        "title" => $title . " (" . $remaining . ")",
        "start" => $row['PRODUCTION_DATE'],
        "color" => $color,
        "priority" => 1
    ];
}

// Can't deliver days from rules (both specific date and weekly)
$ruleEvents = $conn->query("
    SELECT DATE, DAY_OF_WEEK, REASON
    FROM delivery_date_rules
    WHERE STATUS = 'Active'
");

// set calendar range for the whole year to loop through rules
$calendarStart = $year . "-01-01";
$calendarEnd   = $year . "-12-31";

while ($ruleRow = $ruleEvents->fetch_assoc()) {

    // Specific date cannot deliver
    if (!empty($ruleRow['DATE'])) {

        $dateKey = $ruleRow['DATE'];

        $calendarEvents[$dateKey] = [
            "title" => "Can't Deliver",
            "start" => $dateKey,
            "color" => "#9ca3af",
            "priority" => 2
        ];
    }

    // Weekly day cannot deliver (1-7 for Monday-Sunday)
    elseif (!empty($ruleRow['DAY_OF_WEEK'])) {

        $currentDay = strtotime($calendarStart);
        $endDay = strtotime($calendarEnd);

        while ($currentDay <= $endDay) {

            $dateStr = date('Y-m-d', $currentDay);

            // PHP N = 1~7
            $dayNumber = date('N', $currentDay);

            if ($dayNumber == $ruleRow['DAY_OF_WEEK']) {

                $calendarEvents[$dateStr] = [
                    "title" => "Can't Deliver",
                    "start" => $dateStr,
                    "color" => "#e2ecff",
                    "priority" => 2
                ];
            }

            $currentDay = strtotime("+1 day", $currentDay);
        }
    }
}

// BAKERY CLOSED DAYS (FROM OPEN_DAYS)
$calendarStart = $year . "-01-01";
$calendarEnd   = $year . "-12-31";

$currentDay = strtotime($calendarStart);
$endDay = strtotime($calendarEnd);

while ($currentDay <= $endDay) {

    $dateStr = date('Y-m-d', $currentDay);

    // Mon/Tue/Wed...
    $dayShort = date('D', $currentDay);

    // If bakery closed
    if (in_array($dayShort, $closedDays)) {

        $calendarEvents[$dateStr] = [
            "title" => "Shop Closed",
            "start" => $dateStr,
            "color" => "#d8e5ff",
            "priority" => 3
        ];
    }

    $currentDay = strtotime("+1 day", $currentDay);
}

$events = array_values($calendarEvents);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <link rel="stylesheet" href="admin_global.css">
    <title>Production Capacity</title>
<style>
body {
    font-family: var(--font-family);
    background: var(--primary-grey);
    margin:0;
    padding:20px;
}

.container {
    max-width:1200px;
    margin:auto;
    background:#fff;
    padding:20px;
    border-radius:12px;
    box-shadow:0 6px 20px rgba(0,0,0,0.08);
}

/* FORM */
.form-box {
    display:flex;
    gap:10px;
    margin-bottom:10px;
    flex-wrap:wrap;
}

input, select {
    padding:8px;
    border:1px solid #ccc;
    border-radius:6px;
}

button {
    padding:8px 12px;
    border:none;
    background:#4e73df;
    color:white;
    border-radius:6px;
    cursor:pointer;
}

button:hover{
    background:#375ac6;
}

/* TABLE */
table{
    width:100%;
    border-collapse:collapse;
    margin-top:15px;
    table-layout: fixed;  
}

.table-wrapper{
    max-height: 500px;
    overflow: auto;
}

table{
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;  
}

th, td{
    padding: 10px;
    font-size: 14px;
    text-align: left;
    word-wrap: break-word;
}

th,td{
    padding:10px;
    border-bottom:1px solid #eee;
    font-size:14px;
}

th{
    background:#f8f9fa;
    position:sticky;
    top:0;
}

.badge{
    padding:5px 10px;
    border-radius:20px;
    font-size:12px;
    font-weight:bold;
}

.badge.ok{
    background:#f0fdf4 !important;
    color:#166534 !important;
}

.badge.low{
    background:#fefce8 !important;
    color:#a16207 !important;
}

.badge.full{
    background:#fef2f2 !important;
    color:#b91c1c !important;
}

/* LAYOUT */
.grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:20px;
}

.card{
    background:#fff;
    padding:15px;
    border-radius:12px;
    box-shadow:0 2px 8px rgba(0,0,0,0.05);
}

.table-wrapper{
    max-height: 500px;   
    overflow-y: auto;
    overflow-x: auto;
    border-radius: 10px;
}

/* header sticky */
table th{
    position: sticky;
    top: 0;
    background: #f8f9fa;
    z-index: 2;
}

.fc .fc-button {
    background-color: var(--primary-dark) !important;  
    border: none !important;
    padding: 6px 10px !important;
    box-shadow: none !important;
}

.fc .fc-button:hover {
    background-color: #486ee2 !important;
}

.fc .fc-button-group {
    gap: 2px;   
}

.fc .fc-icon {
    color: white !important;  
    fill: white !important;    
}

.error-box{
    background:#f8d7da;
    color:#721c24;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    border:1px solid #f5c6cb;
    font-size:14px;
}

.error-box:not(:empty)::before {
    content: "!";
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 14px;
    height: 14px;
    background: #ef4444;
    color: #fff;
    border-radius: 50%;
    font-size: 10px;
    font-weight: 700;
    flex-shrink: 0;
}

.success-box{
    background:#d4edda;
    color:#155724;
    padding:12px;
    border-radius:8px;
    margin-bottom:15px;
    border:1px solid #c3e6cb;
    font-size:14px;
}

#calendar {
    width: 100%;
    padding-right: 4px; 
}

.fc-scroller {
    overflow: hidden !important; 
}
</style>
</head>

<body>
<div class="global-layout">
<?php include "global_layout_ctrl.php"; ?>
    <div class="main">
        <div class="container">

            <p style="font-size:14px;color:#666;margin-top:-5px;margin-bottom:20px;line-height:1.6;">
                Manage daily cake production limits for preorder and custom cake orders.
            </p>

            <?php if($error): ?>
                <div class="error-box">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if($success): ?>
                <div class="success-box">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <!-- FILTER -->
            <form method="GET" class="form-box">

                <!--YEAR -->
                <select name="year">
                    <option value="">All Year</option>
                    <?php
                        $currentYear = date('Y');

                        for($y = $currentYear - 3; $y <= $currentYear + 3; $y++):
                    ?>
                            <option value="<?= $y ?>"
                                <?= ($year == $y) ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                        <?php endfor; ?>
                </select>

                <!-- MONTH -->
                <select name="month">
                    <option value="">All Month</option>
                    <?php for($i=1;$i<=12;$i++): ?>
                        <option value="<?= $i ?>" <?= ($month == $i) ? 'selected' : '' ?>>
                            <?= date('F', mktime(0,0,0,$i,1)) ?>
                        </option>
                    <?php endfor; ?>
                </select>

                <button>Filter</button>
            </form>

            <!-- SINGLE UPSERT -->
            <form method="POST" class="form-box">
                <input type="date" name="production_date" required>
                <input type="number" name="max_cakes" placeholder="Max Cakes" min="0" step="1" required>
                <button type="submit" name="save_single">Save / Update</button>
            </form>

            <!-- BULK INSERT (MULTIPLE DAYS) -->
            <form method="POST" class="form-box">
                <input type="date" name="start_date" required>
                <input type="date" name="end_date" required>
                <input type="number" name="bulk_max" placeholder="Max Cakes" min="0" step="1" required>
                <button type="submit" name="save_bulk">Bulk Generate</button>
            </form>

            <div class="grid">

                <!-- TABLE -->
                <div class="card">

                    <h3>Capacity List</h3>
                    <div class="table-wrapper">
                        <table>
                            <tr>
                                <th>Date</th>
                                <th>Max</th>
                                <th>Booked</th>
                                <th>Remaining</th>
                                <th>Status</th>
                            </tr>

                        <?php while ($row = $result->fetch_assoc()): 

                            $remaining = $row['remaining'];

                            if ($remaining <= 0) {
                                $class = "full";
                                $status = "FULL";
                            } elseif ($remaining <= 5) {
                                $class = "low";
                                $status = "LOW";
                            } else {
                                $class = "ok";
                                $status = "OK";
                            }
                        ?>

                            <tr>
                                <td><?= $row['PRODUCTION_DATE'] ?></td>
                                <td><?= $row['MAX_CAKES'] ?></td>
                                <td><?= $row['ALREADY_BOOKED'] ?></td>
                                <td><?= $remaining ?></td>
                                <td>
                                    <span class="badge <?= $class ?>">
                                       <?= $status ?>
                                    </span>
                                </td>
                            </tr>

                        <?php endwhile; ?>

                        </table>
                    </div>
                </div>

                <!-- CALENDAR -->
                <div class="card">
                    <h3>Calendar View</h3>
                    <div id="calendar"></div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- FULLCALENDAR SCRIPT -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    var calendar = new FullCalendar.Calendar(
        document.getElementById('calendar'),
        {
            initialView: 'dayGridMonth',
            initialDate: '<?= $initialDate ?>',
            height: 500,
            events: <?= json_encode($events) ?>
        }
    );

    calendar.render();
});
</script>

</body>
</html>