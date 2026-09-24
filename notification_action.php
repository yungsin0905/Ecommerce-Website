<?php
session_start();
require_once 'include/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['CUSTOMER_ID'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

$customer_id = intval($_SESSION['CUSTOMER_ID']);
$action = $_POST['action'] ?? '';
$ids = $_POST['ids'] ?? [];
$ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0)));

if (empty($ids)) {
    echo json_encode(['success' => false, 'message' => 'No IDs provided']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

if ($action === 'mark_read') {
    $sql = "UPDATE customer_notification SET IS_READ = 1 WHERE CUSTOMER_ID = ? AND NOTIF_ID IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$customer_id], $ids);
    $stmt->bind_param("i" . $types, ...$params);
    $stmt->execute();
    echo json_encode(['success' => true]);

} elseif ($action === 'delete') {
    $sql = "DELETE FROM customer_notification WHERE CUSTOMER_ID = ? AND NOTIF_ID IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$customer_id], $ids);
    $stmt->bind_param("i" . $types, ...$params);
    $stmt->execute();
    echo json_encode(['success' => true]);

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}