<?php
// public/user/company1/txn_receipt_in.php
// Company1 版收据：复用 admin/customers/txn_receipt_in.php，但权限改为 Company1（category 1），
// 允许查看 category 3 客户的 IN 单据收款记录，且 UI/签名逻辑与 admin 完全一致。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id        = (int)($_GET['id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$back      = (string)($_GET['back'] ?? '');

if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

// 校验：txn 必须是 IN（Company1 入口按 customer_id 过滤；不要硬卡 category_id，避免不同客户分类导致 forbidden）
$st = $pdo->prepare("
  SELECT t.id, t.customer_id, t.txn_type, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  http_response_code(404);
  exit('Transaction not found');
}
if ((string)($row['txn_type'] ?? '') !== 'IN') {
  http_response_code(400);
  exit('This page is only for IN transactions.');
}
if ($customerId > 0 && (int)($row['customer_id'] ?? 0) !== $customerId) {
  http_response_code(403);
  exit('Forbidden');
}

// Back：优先用 URL 里的 back，否则回 Company1 txn_view
$_TXN_RECEIPT_IN_BACK_URL_FROM_PORTAL = $back !== '' ? $back : url('user/company1/txn_view.php?id=' . (int)$id);

// 告诉 admin 模板：这是 portal 入口（跳过 require_admin + 不输出 admin header/footer）
if (!defined('ALLOW_TXN_RECEIPT_IN_FROM_PORTAL')) define('ALLOW_TXN_RECEIPT_IN_FROM_PORTAL', true);
if (!defined('ALLOW_TXN_RECEIPT_IN_FROM_COMPANY1')) define('ALLOW_TXN_RECEIPT_IN_FROM_COMPANY1', true);

// Company1 layout
$page_title = 'Receipt · #' . $id;
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';

// 复用 admin 收据页（包含 3 方签名与状态同步逻辑）
require __DIR__ . '/../../../public/admin/customers/txn_receipt_in.php';

include __DIR__ . '/../include/footer.php';

