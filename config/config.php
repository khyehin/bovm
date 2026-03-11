<?php
// config/config.php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 时区
date_default_timezone_set('Asia/Kuala_Lumpur');

// 必须先定义 BASE_URL，所有 url() 要用
define('BASE_URL', '');

// 默认语言
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}
