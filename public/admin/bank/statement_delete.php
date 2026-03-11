<?php
// public/admin/bank/statement_delete.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.STMT.E');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id      = (int)($_GET['id'] ?? 0);
$bank_id = (int)($_GET['bank_id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    exit('Missing id');
}

$st = $pdo->prepare("
    SELECT id, bank_id, file_path
      FROM company_bank_statements
     WHERE id = :id
     LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    exit('Statement not found');
}

$realBankId = (int)$row['bank_id'];
if ($bank_id <= 0) {
    $bank_id = $realBankId;
}

// 删除文件
$filePath = $row['file_path'];
if ($filePath !== '') {
    $fullPath = realpath(__DIR__ . '/../../../' . $filePath);
    // 只在 uploads 目录下才删，避免误删
    if ($fullPath && str_contains($fullPath, realpath(__DIR__ . '/../../../uploads'))) {
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

// 删除记录
$del = $pdo->prepare("DELETE FROM company_bank_statements WHERE id = :id");
$del->execute([':id' => $id]);

// 回去 statement 列表
$redirect = url('admin/bank/statements.php?bank_id=' . $bank_id);
header('Location: ' . $redirect);
exit;
