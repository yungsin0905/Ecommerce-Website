<?php
include 'include/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json');

$blocked     = [];
$blocked_dow = [];

// 1. delivery_date_rules — block specific date with reason
$rule_date_result = $conn->query("SELECT DATE, REASON FROM delivery_date_rules 
    WHERE STATUS = 'Active' AND DATE IS NOT NULL");
while ($row = $rule_date_result->fetch_assoc()) {
    $blocked[$row['DATE']] = $row['REASON'];
}

// 2. delivery_date_rules — block specific day of week (7=Sun, 1=Mon, ..., 6=Sat)
$rule_dow_result = $conn->query("SELECT DAY_OF_WEEK, REASON FROM delivery_date_rules 
    WHERE STATUS = 'Active' AND DAY_OF_WEEK IS NOT NULL AND DATE IS NULL");
while ($row = $rule_dow_result->fetch_assoc()) {
    $sql_dow = (int) $row['DAY_OF_WEEK'];
    $js_dow  = $sql_dow === 7 ? 0 : $sql_dow;
    $blocked_dow[(string) $js_dow] = $row['REASON'];
}

// 3. if the date has record in production_capacity and already fully booked
$cap_result = $conn->query("SELECT PRODUCTION_DATE, MAX_CAKES, ALREADY_BOOKED 
    FROM production_capacity");
while ($row = $cap_result->fetch_assoc()) {
    if ($row['ALREADY_BOOKED'] >= $row['MAX_CAKES']) {
        $date = $row['PRODUCTION_DATE'];
        // if already blocked by delivery_date_rules, keep that reason. Otherwise, mark as fully booked.
        if (!isset($blocked[$date])) {
            $blocked[$date] = "Fully booked on this date (max {$row['MAX_CAKES']} orders reached).";
        }
    }
}

// 4. if have record in production_capacity, but not full, consider it as available date
$available    = [];
$avail_result = $conn->query("SELECT PRODUCTION_DATE FROM production_capacity 
    WHERE ALREADY_BOOKED < MAX_CAKES");
while ($row = $avail_result->fetch_assoc()) {
    $available[] = $row['PRODUCTION_DATE'];
}

// 5. bakery open days — non-open days are also blocked
$settings  = $conn->query("SELECT OPEN_DAYS FROM bakery_info WHERE BAKERY_ID = 1 LIMIT 1")->fetch_assoc();
$open_days = explode(',', $settings['OPEN_DAYS']);

$day_name_to_js = ['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6];
$all_days       = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

foreach ($all_days as $day_name) {
    if (!in_array($day_name, $open_days)) {
        $js_dow = $day_name_to_js[$day_name];
        // only add if not already blocked by delivery_date_rules
        if (!isset($blocked_dow[(string) $js_dow])) {
            $blocked_dow[(string) $js_dow] = "We are closed on {$day_name}s.";
        }
    }
}

echo json_encode([
    'blocked_dates'   => $blocked,
    'blocked_dow'     => $blocked_dow,
    'available_dates' => $available,
    'open_days'       => $open_days,
]);
?>