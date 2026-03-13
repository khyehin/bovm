<?php
// public/user/company1/txn_edit_in.php
// Company1 复用 admin 的 IN 编辑页面：布局、字段、JS 完全一样，只限制 bank 账号，并确保客户是 category 3。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

// 读取 id / customer_id / back
$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$id          = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$back        = (string)($_GET['back'] ?? $_POST['back'] ?? '');
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

// 必须是 category_id = 3 的客户 + IN 单
$st = $pdo->prepare("
  SELECT t.id, t.customer_id, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
    AND t.txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Not found');
}
if ((int)($row['category_id'] ?? 0) !== 3) {
  http_response_code(403);
  exit('Forbidden');
}
if ($customer_id <= 0) {
  $customer_id = (int)($row['customer_id'] ?? 0);
}

// User 端自己的 header / sidebar
$page_title = 'IN Transaction';
$active_nav = 'company1_invoices';
// 让后续模板也能拿到 back，用于“← Back”按钮
if ($back === '' && !empty($_SERVER['HTTP_REFERER'])) {
  $back = (string)$_SERVER['HTTP_REFERER'];
}
// 传给后面的 admin 模板使用
$_TXN_IN_BACK_URL_FROM_PORTAL = $back;

include __DIR__ . '/../include/header.php';

// 告诉 admin 模板：这是从 Company1 入口过来的，并限制 bank 白名单
if (!defined('ALLOW_TXN_IN_FROM_COMPANY1')) {
  define('ALLOW_TXN_IN_FROM_COMPANY1', true);
}
if (!defined('TXN_IN_BANK_ID_WHITELIST')) {
  // 这里只允许 id 1 和 2（你可以按需要调整）
  define('TXN_IN_BANK_ID_WHITELIST', '1,2');
}

// 直接复用 admin 的 txn_edit_in 页面（布局 / 字段 / JS 全部一致）
require __DIR__ . '/../../../public/admin/customers/txn_edit_in.php';

include __DIR__ . '/../include/footer.php';

