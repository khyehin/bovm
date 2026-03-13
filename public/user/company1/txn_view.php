<?php
// public/user/company1/txn_view.php
// Company1 查看单笔交易：参考 user/txn/txn_view.php，但允许 Company1 用户查看 category 3 客户的交易。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$tid = (int)($_GET['id'] ?? 0);
if ($tid <= 0) { http_response_code(400); exit('Missing transaction id'); }

// 载入 txn + customer（只允许 category_id = 3）
$st = $pdo->prepare("
  SELECT t.*, c.name AS customer_name, c.code AS customer_code, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
  LIMIT 1
");
$st->execute([':id' => $tid]);
$txn = $st->fetch();
if (!$txn) { http_response_code(404); exit('Transaction not found'); }
if ((int)($txn['category_id'] ?? 0) !== 3) { http_response_code(403); exit('Forbidden'); }

$customerName = (string)($txn['customer_name'] ?? '');
$customerCode = (string)($txn['customer_code'] ?? '');
$currency     = (string)($txn['currency'] ?? 'MYR');

$adminType = strtoupper((string)($txn['txn_type'] ?? ''));
$tDate     = (string)($txn['txn_date'] ?? substr((string)($txn['created_at'] ?? ''), 0, 10));
$amount    = (float)($txn['amount'] ?? 0);
$status    = strtoupper((string)($txn['status'] ?? 'DRAFT'));
$title     = (string)($txn['title'] ?? '');
$refNo     = (string)($txn['ref_no'] ?? '');
$notes     = (string)($txn['notes'] ?? '');

// IN / OUT 标签
if ($adminType === 'OUT') {
  $typeLabel = 'OUT';
  $typeColor = '#b91c1c';
} else {
  $typeLabel = 'IN';
  $typeColor = '#166534';
}

$page_title = 'Transaction detail';
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow" style="max-width:900px;">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">Customer</div>
          <h2 class="form-page-title">
            <?= h($customerName) ?>
            <?php if ($customerCode !== ''): ?>
              <span style="font-size:13px;font-weight:500;color:#6b7280;">(<?= h($customerCode) ?>)</span>
            <?php endif; ?>
          </h2>
          <div class="form-page-subtitle">
            Transaction detail for this customer.
          </div>
        </div>
        <div class="form-page-meta" style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="<?= h(url('user/company1/txn_list.php?customer_id=' . (int)$txn['customer_id'])) ?>" class="btn btn-light">← Back to transactions</a>
        </div>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:16px;">
        <div>
          <div style="font-size:12px;color:#6b7280;">Date</div>
          <div style="font-size:14px;font-weight:600;"><?= h($tDate) ?></div>
        </div>
        <div>
          <div style="font-size:12px;color:#6b7280;">Type</div>
          <div style="font-size:14px;font-weight:700;color:<?= h($typeColor) ?>;"><?= h($typeLabel) ?></div>
        </div>
        <div>
          <div style="font-size:12px;color:#6b7280;">Status</div>
          <div style="font-size:11px;padding:3px 9px;border-radius:999px;background:#e5e7eb;color:#374151;display:inline-block;">
            <?= h($status) ?>
          </div>
        </div>
        <div>
          <div style="font-size:12px;color:#6b7280;">Amount</div>
          <div style="font-size:16px;font-weight:700;"><?= h($currency) ?> <?= number_format($amount, 2) ?></div>
        </div>
      </div>

      <div style="margin-bottom:12px;">
        <div style="font-size:12px;color:#6b7280;">Title</div>
        <div style="font-size:14px;font-weight:600;"><?= h($title !== '' ? $title : '-') ?></div>
      </div>

      <div style="margin-bottom:12px;">
        <div style="font-size:12px;color:#6b7280;">Reference</div>
        <div style="font-size:14px;"><?= h($refNo !== '' ? $refNo : '-') ?></div>
      </div>

      <div>
        <div style="font-size:12px;color:#6b7280;">Notes</div>
        <div style="font-size:13px;white-space:pre-line;"><?= h($notes !== '' ? $notes : '-') ?></div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

