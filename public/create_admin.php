<?php
// public/create_admin.php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

// 只允许 localhost
if (php_sapi_name() !== 'cli' &&
    !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
    die('Only localhost allowed. Delete this file after use.');
}

$username = 'admin';
$password = 'Admin@123';
$fullName = 'Super Admin';

$hash = password_hash($password, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (username, password_hash, full_name, role, must_change_password, is_active)
        VALUES (:u, :p, :n, 'ADMIN', 1, 1)";
$st = $pdo->prepare($sql);
$st->execute([
    ':u' => $username,
    ':p' => $hash,
    ':n' => $fullName,
]);

echo "Admin user created.\nUsername: $username\nPassword: $password\n";
