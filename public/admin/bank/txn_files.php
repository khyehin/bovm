<?php
// public/admin/bank/txn_files.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('BANK.TXN.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('tt')) {
  function tt(string $key, array $vars = [], string $fallback = ''): string {
    if (function_exists('t')) return t($key, $vars, $fallback);
    return $fallback !== '' ? $fallback : $key;
  }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

// where user came from: all / bank
$from = strtolower(trim((string)($_GET['from'] ?? '')));
if (!in_array($from, ['all', 'bank'], true)) $from = 'bank';

// keep filters for back & edit
$keep = $_GET;
unset($keep['id']); // keep everything else (bank_id, date filters, etc.)
$qs = http_build_query($keep);

// load txn
$st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id");
$st->execute([':id' => $id]);
$txn = $st->fetch();
if (!$txn) {
  http_response_code(404);
  exit(tt('admin.bank.txn_files.not_found', [], 'Transaction not found'));
}

// load files (your schema: bank_txn_id)
$st = $pdo->prepare("
  SELECT id, file_name, file_path, file_mime, file_size, created_at
    FROM company_bank_txn_files
   WHERE bank_txn_id = :id
   ORDER BY id ASC
");
$st->execute([':id' => $id]);
$files = $st->fetchAll();

// Back URL 결정
$bankId = (int)($_GET['bank_id'] ?? 0);
if ($from === 'all') {
  $backUrl = url('admin/bank/transactions_all.php?' . $qs);
} else {
  // bank list 必须有 bank_id；没有就用 txn 自己的 bank_id 兜底
  if ($bankId <= 0) $bankId = (int)($txn['bank_id'] ?? 0);

  // 如果 qs 里没有 bank_id，补进去
  if (strpos('&'.$qs.'&', 'bank_id=') === false) {
    $backQs = $qs === '' ? ('bank_id=' . $bankId) : ('bank_id=' . $bankId . '&' . $qs);
  } else {
    $backQs = $qs;
  }
  $backUrl = url('admin/bank/transactions.php?' . $backQs);
}

$page_title = tt('admin.bank.txn_files.title', [], 'Attachments');

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.bank.txn_files.eyebrow', [], 'BANK TRANSACTION')) ?></div>
          <h2 class="form-page-title"><?= h($page_title) ?></h2>
          <div class="form-page-subtitle" style="font-size:13px;color:#6b7280;">
            #<?= (int)$txn['id'] ?> · <?= h($txn['txn_date'] ?? '') ?> · <?= h($txn['txn_type'] ?? '') ?>
            <?php if (!empty($txn['ref_no'])): ?> · <?= h($txn['ref_no']) ?><?php endif; ?>
          </div>
        </div>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-light" href="<?= h($backUrl) ?>">
          ← <?= h(tt('admin.common.back', [], 'Back')) ?>
        </a>

        <?php if (function_exists('can') ? can('BANK.TXN.E') : true): ?>
          <a class="btn btn-primary" href="<?= h(url('admin/bank/txn_edit.php?id='.(int)$txn['id'].'&'.$qs)) ?>">
            <?= h(tt('admin.common.edit', [], 'Edit')) ?>
          </a>
        <?php endif; ?>
      </div>

      <div style="margin-top:16px;">
        <?php if (!$files): ?>
          <div style="padding:12px;color:#6b7280;font-size:13px;">
            <?= h(tt('admin.bank.txn_files.empty', [], 'No attachments.')) ?>
          </div>
        <?php else: ?>
          <ul style="list-style:none;padding-left:0;font-size:13px;">
            <?php foreach ($files as $f): ?>
              <li style="padding:10px 0;border-bottom:1px solid #eee;">
                <a href="<?= h(url($f['file_path'])) ?>" target="_blank" style="text-decoration:underline;">
                  <?= h($f['file_name'] ?: basename((string)$f['file_path'])) ?>
                </a>
                <div style="font-size:11px;color:#6b7280;margin-top:3px;">
                  <?php if (!empty($f['file_mime'])): ?><?= h($f['file_mime']) ?> · <?php endif; ?>
                  <?php if (!empty($f['file_size'])): ?><?= number_format((int)$f['file_size'] / 1024, 1) ?> KB · <?php endif; ?>
                  <?= h($f['created_at'] ?? '') ?>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
