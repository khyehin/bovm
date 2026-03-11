<?php
// public/login.php  — 兼容旧链接，直接丢去 index.php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

header('Location: ' . url('index.php'));
exit;
