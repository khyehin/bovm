<?php
// public/user/txn/txn_sign.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_login();
require_once __DIR__ . '/../../../config/i18n.php';

$u = current_user();
if (!$u || ($u['role'] ?? '') !== 'CUSTOMER') {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('No customer linked');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

/**
 * ✅ 安全 back（只允许同域 / 相对）
 */
$back = (string)($_GET['back'] ?? '');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');

$normalizeInternal = function (string $b) use ($host): string {
  $b = trim($b);
  if ($b === '') return '';

  // full url: only allow same host
  if (preg_match('#^https?://#i', $b)) {
    $bHost = (string)(parse_url($b, PHP_URL_HOST) ?? '');
    if ($bHost !== '' && $bHost !== $host) return '';
    return $b;
  }

  // absolute path
  if (strpos($b, '/') === 0) return $b;

  // relative -> url()
  return url($b);
};

$back = $normalizeInternal($back);
if ($back === '') {
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $ref = $normalizeInternal($ref);
  $back = $ref !== '' ? $ref : url('user/txn/txns.php?filter=pending');
}

/**
 * ✅ 载入 txn：必须属于当前 customer
 */
$st = $pdo->prepare("
  SELECT id, customer_id, txn_type, status, is_contra, require_signature, signature_image, payer_signature_image
  FROM customer_txn
  WHERE id = :id AND customer_id = :cid
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();
if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}

// 只允许 OUT + 非 contra + require_signature=1 的签名入口
$isOut       = (string)($txn['txn_type'] ?? '') === 'OUT';
$isContra    = (int)($txn['is_contra'] ?? 0) === 1;
$needSig     = (int)($txn['require_signature'] ?? 0) === 1;
$status      = strtoupper((string)($txn['status'] ?? 'DRAFT'));

// 如果不符合签名条件，就回列表（避免用户乱进）
if (!$isOut || $isContra || !$needSig) {
  header('Location: ' . $back);
  exit;
}

// 之前跳转到 txn_receipt.php 做签名；现在收据页已停用，直接回原页面。
header('Location: ' . $back);
exit;
