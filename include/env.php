<?php
function loadEnv(array $candidates): void {
    foreach ($candidates as $path) {
        if (!is_readable($path)) continue;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));

            // 去掉首尾引号
            if (strlen($val) >= 2 &&
                (($val[0] === '"' && substr($val, -1) === '"') ||
                 ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            $_ENV[$key] = $val;
        }
        return; // 找到第一个存在的就停
    }
    error_log("ENV file not found");
}

function env(string $key, $default = null) {
    return $_ENV[$key] ?? $default;
}

// 按顺序找 .env：先找网站目录外面（线上），再找项目根目录（本地）
loadEnv([
    dirname($_SERVER['DOCUMENT_ROOT']) . '/.env',   // cPanel: /home/用户名/.env
    dirname(__DIR__) . '/.env',                     // 本地: TWPLAB/.env
]);