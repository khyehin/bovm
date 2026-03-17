<?php
// public/user/company1/txn_doc_require.php
// Company1 在单据页面勾选/取消「Customer / Company signature」需求标记（per-doc）
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

function c1_doc_req_error(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$cid = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$doc = strtoupper(trim((string)($_GET['doc'] ?? $_POST['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['QUOTATION','INVOICE','DO'], true)) $doc = 'INVOICE';

if ($id <= 0 || $cid <= 0) {
    c1_doc_req_error('Missing id / customer_id');
}

$needCustomer = isset($_POST['need_customer']) && $_POST['need_customer'] === '1';
$needCompany  = isset($_POST['need_company']) && $_POST['need_company'] === '1';

// 只允许 Company1 操作自己名下 category 3 客户的 IN 交易
$st = $pdo->prepare("
  SELECT t.*, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id AND t.customer_id = :cid AND t.txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch(PDO::FETCH_ASSOC);
if (!$txn || (int)($txn['category_id'] ?? 0) !== 3) {
    c1_doc_req_error('Forbidden', 403);
}

$cols = [];
foreach ($pdo->query("DESCRIBE `customer_txn`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $name = strtolower((string)($r['Field'] ?? ''));
    if ($name !== '') $cols[$name] = true;
}

$set = [];
$params = [':id' => $id, ':cid' => $cid];

if ($doc === 'QUOTATION' && isset($cols['require_sign_quotation'])) {
    $set[] = "require_sign_quotation = :rq";
    $params[':rq'] = $needCustomer ? 1 : 0;
}
if ($doc === 'INVOICE' && isset($cols['require_sign_invoice'])) {
    $set[] = "require_sign_invoice = :ri";
    $params[':ri'] = $needCustomer ? 1 : 0;
}
if ($doc === 'DO' && isset($cols['require_sign_do'])) {
    $set[] = "require_sign_do = :rd";
    $params[':rd'] = $needCustomer ? 1 : 0;
}

if (isset($cols['sign_receive'])) {
    $set[] = "sign_receive = :sr";
    $params[':sr'] = $needCompany ? 1 : 0;
}

if (!$set) {
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$set[] = "updated_at = NOW()";
$sql = "UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id = :id AND customer_id = :cid";
$pdo->prepare($sql)->execute($params);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

