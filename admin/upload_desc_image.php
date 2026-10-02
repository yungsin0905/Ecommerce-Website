<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$type  = finfo_file($finfo, $_FILES['file']['tmp_name']);
finfo_close($finfo);

if (!isset($allowed[$type]) || $_FILES['file']['size'] > 2 * 1024 * 1024) {
    echo json_encode(['error' => 'Only JPG, PNG, WEBP (max 2MB)']);
    exit;
}

$dir = "uploads/product/description/";
if (!is_dir($dir)) mkdir($dir, 0777, true);

$path = $dir . time() . "_" . uniqid() . "." . $allowed[$type];
if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
    echo json_encode(['error' => 'Upload failed']);
    exit;
}

// 用站点根路径 + 当前脚本所在的目录名，拼出一个从域名根目录开始的绝对路径，
// 这样无论后台页面本身位于哪个子目录，浏览器都能正确解析，不会因为
// "相对于当前页面路径" 而多拼出一层重复的目录（比如 admin/admin/...）。
$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/';
$scriptDir = trim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$scriptDir = basename($scriptDir); // 取当前脚本所在目录名，例如 "admin"

echo json_encode(['location' => $siteRoot . $scriptDir . '/' . $path]);