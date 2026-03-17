<?php
// public/admin/customers/txn_doc_sign.php
// 保存单据上的签名（admin 使用），签到 customer_txn（customer/company 两边）
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.E');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$cid = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$side = strtolower(trim((string)($_GET['side'] ?? $_POST['side'] ?? '')));
$doc  = strtoupper(trim((string)($_GET['doc'] ?? $_POST['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['QUOTATION','INVOICE','DO'], true)) $doc = 'INVOICE';

if ($id <= 0 || $cid <= 0) {
    json_error('Missing id / customer_id');
}
if ($side !== 'customer' && $side !== 'company') {
    json_error('Invalid side');
}

// 载入 txn，确保属于该 customer
$st = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id AND customer_id = :cid LIMIT 1");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch(PDO::FETCH_ASSOC);
if (!$txn) {
    json_error('Transaction not found', 404);
}

$img = (string)($_POST['image'] ?? '');
if (strpos($img, 'data:image/png;base64,') !== 0) {
    json_error('Invalid image data');
}

// 选择当前 doc 对应的签名字段（如果存在的话）
$cols = array_change_key_case($txn, CASE_LOWER);
$custField = 'signature_image';
$compField = 'payer_signature_image';
if ($doc === 'QUOTATION') {
    if (array_key_exists('quotation_customer_signature_image', $cols)) $custField = 'quotation_customer_signature_image';
    if (array_key_exists('quotation_company_signature_image', $cols))  $compField = 'quotation_company_signature_image';
} elseif ($doc === 'INVOICE') {
    if (array_key_exists('invoice_customer_signature_image', $cols))   $custField = 'invoice_customer_signature_image';
    if (array_key_exists('invoice_company_signature_image', $cols))    $compField = 'invoice_company_signature_image';
} elseif ($doc === 'DO') {
    if (array_key_exists('do_customer_signature_image', $cols))        $custField = 'do_customer_signature_image';
    if (array_key_exists('do_company_signature_image', $cols))         $compField = 'do_company_signature_image';
}

// side=customer  -> 当前 doc 对应 customer 字段 + recipient_signed_at
// side=company   -> 当前 doc 对应 company 字段 + payer_signed_at
$set = [];
$params = [':img' => $img, ':id' => $id, ':cid' => $cid];
if ($side === 'customer') {
    $set[] = "`$custField` = :img";
    $set[] = "recipient_signed_at = IFNULL(recipient_signed_at, NOW())";
} else {
    $set[] = "`$compField` = :img";
    $set[] = "payer_signed_at = IFNULL(payer_signed_at, NOW())";
}
$set[] = "updated_at = NOW()";

$sql = "UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id = :id AND customer_id = :cid";
$pdo->prepare($sql)->execute($params);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

