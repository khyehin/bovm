<?php
// config/db.php
declare(strict_types=1);

$DB_HOST = '127.0.0.1';
$DB_NAME = 'bovm';        
$DB_USER = 'root';
$DB_PASS = '';

function get_pdo(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    return $pdo;
}
