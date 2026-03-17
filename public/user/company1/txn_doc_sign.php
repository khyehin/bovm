<?php
// public/user/company1/txn_doc_sign.php
// Company1 在单据画板上的签名保存到 DB（同步到 admin 单据）
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

function company1_json_error(string $msg, int $code = 400): void {
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
    company1_json_error('Missing id / customer_id');
}
if ($side !== 'customer' && $side !== 'company') {
    company1_json_error('Invalid side');
}

// 只允许 Company1 查看自己名下 category 3 客户的 IN 交易
$st = $pdo->prepare("
  SELECT t.id, t.customer_id, t.txn_type, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id AND t.customer_id = :cid AND t.txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || (int)($row['category_id'] ?? 0) !== 3) {
    company1_json_error('Forbidden', 403);
}

$img = (string)($_POST['image'] ?? '');
if (strpos($img, 'data:image/png;base64,') !== 0) {
    company1_json_error('Invalid image data');
}

// 读取现有列，选择当前 doc 的签名字段
$stCols = $pdo->query("DESCRIBE `customer_txn`");
$cols = [];
foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = strtolower((string)($r['Field'] ?? ''));
    if ($name !== '') $cols[$name] = true;
}

$custField = 'signature_image';
$compField = 'payer_signature_image';
if ($doc === 'QUOTATION') {
    if (isset($cols['quotation_customer_signature_image'])) $custField = 'quotation_customer_signature_image';
    if (isset($cols['quotation_company_signature_image']))  $compField = 'quotation_company_signature_image';
} elseif ($doc === 'INVOICE') {
    if (isset($cols['invoice_customer_signature_image']))   $custField = 'invoice_customer_signature_image';
    if (isset($cols['invoice_company_signature_image']))    $compField = 'invoice_company_signature_image';
} elseif ($doc === 'DO') {
    if (isset($cols['do_customer_signature_image']))        $custField = 'do_customer_signature_image';
    if (isset($cols['do_company_signature_image']))         $compField = 'do_company_signature_image';
}

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

