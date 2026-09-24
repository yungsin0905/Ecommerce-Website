<?php
// 提取不带端口号的主机名 (如 localhost:3000 提取出 localhost)
$host_name = explode(':', $_SERVER['HTTP_HOST'])[0];

// 这里要判断处理后的 $host_name，而不是原始的 $_SERVER['HTTP_HOST']
if ($host_name == 'localhost' || $host_name == '127.0.0.1') {
     ini_set('display_errors', 1);
    error_reporting(E_ALL);
    // ---------------- 本地 XAMPP 环境 ----------------
    $servername = 'localhost';
    $username   = 'root';
    $password   = '';
    $dbname     = 'robot_shop';
} else {
    // ---------------- cPanel 线上环境 ----------------
     ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
    $servername = "localhost";
    $username   = "makerklu_wongyungsin";
    $password   = "Wy$173183";
    $dbname     = "makerklu_cytron"; 
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
} 
?>