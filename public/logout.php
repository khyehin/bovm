<?php
// public/logout.php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

auth_logout();
header('Location: index.php');
exit;
