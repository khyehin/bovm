<?php
// public/admin/bank/txn_delete.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.TXN.E');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id      = (int)($_GET['id'] ?? 0);
$bank_id = (int)($_GET['bank_id'] ?? 0);

if ($id <= 0 || $bank_id <= 0) {
    http_response_code(400);
    exit('Missing id / bank_id');
}

$st = $pdo->prepare("DELETE FROM company_bank_txn WHERE id = :id AND bank_id = :bid");
$st->execute([':id' => $id, ':bid' => $bank_id]);

header('Location: ' . url('admin/bank/transactions.php?bank_id='.$bank_id));
exit;
