<?php
// public/admin/bank/account_delete.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.ACCOUNT.E');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v ?? '', ENT_QUOTES, 'UTF-8');
    }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Missing id');
}

// 1) 检查银行账户存在
$st = $pdo->prepare("SELECT * FROM company_bank_accounts WHERE id = :id");
$st->execute([':id' => $id]);
$acc = $st->fetch();

if (!$acc) {
    http_response_code(404);
    exit('Bank account not found');
}

// 2) 检查是否有交易记录（不能删）
$st = $pdo->prepare("
    SELECT COUNT(*) FROM company_bank_txn WHERE bank_id = :id
");
$st->execute([':id' => $id]);
$txnCount = (int)$st->fetchColumn();

if ($txnCount > 0) {
    // 有交易 → 不允许删除
    $msg = urlencode("Cannot delete. This bank account has transactions.");
    header("Location: " . url("admin/bank/accounts.php?err=$msg"));
    exit;
}

// 3) 开始删除
try {
    $pdo->beginTransaction();

    // 删除 bank account
    $del = $pdo->prepare("DELETE FROM company_bank_accounts WHERE id = :id LIMIT 1");
    $del->execute([':id' => $id]);

    $pdo->commit();

    header("Location: " . url("admin/bank/accounts.php?ok=del"));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $msg = urlencode("Delete failed: " . $e->getMessage());
    header("Location: " . url("admin/bank/accounts.php?err=$msg"));
    exit;
}
