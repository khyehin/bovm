<?php
// public/user/txn/txn_doc_sign.php
// 顾客在文档页面上签名（只允许签 Customer 一边）
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

function cust_doc_sign_error(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

$cid = (int)($u['customer_id'] ?? 0);
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = strtoupper(trim((string)($_GET['doc'] ?? $_POST['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['QUOTATION','INVOICE','DO'], true)) $doc = 'INVOICE';

if ($cid <= 0 || $id <= 0) {
  cust_doc_sign_error('Missing id / customer_id');
}

$img = (string)($_POST['image'] ?? '');
if (strpos($img, 'data:image/png;base64,') !== 0) {
  cust_doc_sign_error('Invalid image data');
}

// 载入本客户的 IN 交易
$st = $pdo->prepare("
  SELECT *
  FROM customer_txn
  WHERE id = :id AND customer_id = :cid AND txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch(PDO::FETCH_ASSOC);
if (!$txn) {
  cust_doc_sign_error('Transaction not found', 404);
}

// 选择当前 doc 对应的 customer 签名字段
$cols = [];
foreach ($pdo->query("DESCRIBE `customer_txn`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $name = strtolower((string)($r['Field'] ?? ''));
  if ($name !== '') $cols[$name] = true;
}

$custField = 'signature_image';
if ($doc === 'QUOTATION' && isset($cols['quotation_customer_signature_image'])) {
  $custField = 'quotation_customer_signature_image';
} elseif ($doc === 'INVOICE' && isset($cols['invoice_customer_signature_image'])) {
  $custField = 'invoice_customer_signature_image';
} elseif ($doc === 'DO' && isset($cols['do_customer_signature_image'])) {
  $custField = 'do_customer_signature_image';
}

// 已经有签名就不再改
if (!empty($txn[$custField] ?? '')) {
  cust_doc_sign_error('Signature already exists', 403);
}

$set = [];
$set[] = "`$custField` = :img";
if (isset($cols['recipient_signed_at'])) {
  $set[] = "recipient_signed_at = IFNULL(recipient_signed_at, NOW())";
}
$set[] = "updated_at = NOW()";

$sql = "UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id = :id AND customer_id = :cid";
$pdo->prepare($sql)->execute([
  ':img' => $img,
  ':id'  => $id,
  ':cid' => $cid,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

