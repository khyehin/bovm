<?php
// public/user/txn/txn_doc_in.php
// 用户门户版：复用 admin/doc 模板，但只允许查看自己公司的单据
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

// 先取 transaction，确认是当前登录客户的
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

$st = $pdo->prepare("SELECT customer_id FROM customer_txn WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Transaction not found');
}

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0 || (int)$row['customer_id'] !== $cid) {
  http_response_code(403);
  exit('Forbidden');
}

// 通过后，引入 admin 的文档模板，但使用 user portal 的 layout（header/sidebar）
if (!defined('ALLOW_TXN_DOC_FROM_PORTAL')) {
  define('ALLOW_TXN_DOC_FROM_PORTAL', true);
}

$page_title = 'Document · #' . $id;
$active_nav = 'invoices';
include __DIR__ . '/../include/header.php';

// admin 模板内部会检测 ALLOW_TXN_DOC_FROM_PORTAL=true：
// - 跳过 require_admin
// - 不再输出 admin header/sidebar，只输出文档内容本身
require __DIR__ . '/../../../public/admin/customers/txn_doc_in.php';

include __DIR__ . '/../include/footer.php';

