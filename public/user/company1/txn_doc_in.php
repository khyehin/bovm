<?php
// public/user/company1/txn_doc_in.php
// Company1 版单据查看：复用 admin 的 txn_doc_in 模板，但只要求登录的是 Company1（category 1），
// 可以查看它名下 category 3 客户的 IN 单据。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cid  = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;
$back = (string)($_GET['back'] ?? '');
if ($id <= 0 || $cid <= 0) {
  http_response_code(400);
  exit('Missing id / customer_id');
}

// 只允许查看 category_id = 3 的 customer + IN 交易
$st = $pdo->prepare("
  SELECT t.id, t.customer_id, t.txn_type, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id AND t.customer_id = :cid AND t.txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$row = $st->fetch();
if (!$row || (int)($row['category_id'] ?? 0) !== 3) {
  http_response_code(403);
  exit('Forbidden');
}

// 允许从 portal 复用 admin 模板，并标记是 Company1 入口
if (!defined('ALLOW_TXN_DOC_FROM_PORTAL')) {
  define('ALLOW_TXN_DOC_FROM_PORTAL', true);
}
if (!defined('ALLOW_TXN_DOC_FROM_COMPANY1')) {
  define('ALLOW_TXN_DOC_FROM_COMPANY1', true);
}

// 当前 doc 类型（INVOICE / QUOTATION / DO）
$doc = strtoupper(trim((string)($_GET['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['INVOICE', 'QUOTATION', 'DO'], true)) {
  $doc = 'INVOICE';
}
$_GET['id'] = $id;
$_GET['customer_id'] = $cid;
$_GET['doc'] = $doc;

// Back：优先用 URL 里的 back，没有就退回 invoices 列表
if ($back === '' && !empty($_SERVER['HTTP_REFERER'])) {
  $back = (string)$_SERVER['HTTP_REFERER'];
}
$_TXN_DOC_BACK_URL_FROM_PORTAL = $back !== '' ? $back : url('user/company1/invoices.php?customer_id=' . $cid);

$page_title = 'Document · #' . $id;
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';

// 直接复用 admin 的 txn_doc_in（里面自己负责 Back / Print / 签名控制 / Quotation·Invoice·DO 快速按钮）
require __DIR__ . '/../../../public/admin/customers/txn_doc_in.php';

include __DIR__ . '/../include/footer.php';
