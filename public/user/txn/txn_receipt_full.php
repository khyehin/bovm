<?php
// public/user/txn/txn_receipt_full.php
// 客户端查看完整收据版面（复用 admin/customers/txn_view.php），只读不可在这里签名。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/i18n.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

// 校验交易确实属于该 customer
$st = $pdo->prepare("
  SELECT id, customer_id
  FROM customer_txn
  WHERE id = :id AND customer_id = :cid
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
if (!$st->fetch()) {
  http_response_code(404);
  exit('Transaction not found');
}

// back：来自 GET，默认回 txns 列表
$rawBack = (string)($_GET['back'] ?? '');
if ($rawBack === '') {
  $rawBack = url('user/txn/txns.php');
}

// 传给 admin 模板使用的 GET 参数
$_GET['customer_id'] = $cid;
$_GET['id']          = $id;
$_GET['back']        = $rawBack;

// 标记：允许从 portal 进入，并且在 admin 模板里只读（不显示签名表单）
if (!defined('ALLOW_TXN_VIEW_FROM_PORTAL')) {
  define('ALLOW_TXN_VIEW_FROM_PORTAL', true);
}
if (!defined('TXN_VIEW_PORTAL_READONLY')) {
  define('TXN_VIEW_PORTAL_READONLY', true);
}

$page_title = 'Receipt · #' . $id;
$active_nav = 'txns';

include __DIR__ . '/../include/header.php';

// admin 模板内部会根据 ALLOW_TXN_VIEW_FROM_PORTAL 跳过 require_admin，仅输出收据内容
require __DIR__ . '/../../../public/admin/customers/txn_view.php';

include __DIR__ . '/../include/footer.php';

