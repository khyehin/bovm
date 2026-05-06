<?php
// public/admin/dashboard/pending.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('APP.DASH.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('tt')) {
  function tt(string $key, array $vars = [], string $fallback = ''): string
  {
    if (function_exists('t')) return t($key, $vars, $fallback);
    return $fallback !== '' ? $fallback : $key;
  }
}

function dashboard_table_columns(PDO $pdo, string $table): array
{
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $field = (string)($r['Field'] ?? '');
      if ($field !== '') $cols[$field] = true;
    }
  } catch (Throwable $e) {
  }

  return $cache[$key] = $cols;
}

$cols = dashboard_table_columns($pdo, 'customer_txn');

$where = ["t.status IN ('DRAFT','PENDING')"];
if (isset($cols['doc_flow_status'])) {
  $where[] = "UPPER(TRIM(COALESCE(t.doc_flow_status,''))) <> 'REJECTED'";
}

$missingSignParts = [];
if (isset($cols['sig_customer'])) {
  $missingSignParts[] = "COALESCE(t.sig_customer,'') = ''";
}
if (isset($cols['customer_signed_at'])) {
  $missingSignParts[] = "t.customer_signed_at IS NULL";
}
if ($missingSignParts) {
  $where[] = '(' . implode(' OR ', $missingSignParts) . ')';
}

$whereSql = implode(' AND ', $where);

$rows = [];
try {
  $st = $pdo->query("
    SELECT
      t.*,
      c.code AS customer_code,
      c.name AS customer_name
    FROM customer_txn t
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE {$whereSql}
    ORDER BY COALESCE(t.txn_date, DATE(t.created_at)) ASC, t.id ASC
  ");
  $rows = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  $rows = [];
}

$page_title = tt('admin.dashboard.pending_page_title', [], 'Pending');
include __DIR__ . '/../include/header.php';
?>

<style>
  .pending-status {
    font-size: 11px;
    padding: 3px 9px;
    border-radius: 999px;
    background: #fff1f2;
    color: #991b1b;
    border: 1px solid #fecaca;
    font-weight: 700;
  }

  .pending-muted {
    font-size: 11px;
    color: #6b7280;
    margin-top: 2px;
  }
</style>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.eyebrow', [], 'Overview')) ?></div>
          <h1 class="page-title"><?= h(tt('admin.dashboard.pending_page_title', [], 'Pending')) ?></h1>
          <div class="form-page-subtitle">
            <?= h(tt('admin.dashboard.pending_page_subtitle', [], 'Transactions currently shown in the dashboard pending badge.')) ?>
          </div>
        </div>
        <div>
          <a href="<?= h(url('admin/dashboard/index.php')) ?>" class="btn btn-light">
            &larr; <?= h(tt('admin.common.back', [], 'Back')) ?>
          </a>
        </div>
      </div>
    </div>

    <div class="admin-card">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.report.transaction.details.eyebrow', [], 'Details')) ?></div>
          <h2 class="page-title" style="font-size:16px;"><?= h(tt('admin.dashboard.pending_table_title', [], 'Pending transactions')) ?></h2>
        </div>
        <span class="pending-status"><?= count($rows) ?></span>
      </div>

      <table class="table">
        <thead>
          <tr>
            <th style="width:100px;"><?= h(tt('admin.customer_txn.field.date', [], 'Date')) ?></th>
            <th style="width:240px;"><?= h(tt('admin.report.transaction.filter.customer', [], 'Customer')) ?></th>
            <th style="width:100px;"><?= h(tt('admin.customer_txn.field.type', [], 'Type')) ?></th>
            <th><?= h(tt('admin.customer_txn.field.title', [], 'Title')) ?></th>
            <th style="width:140px;text-align:right;"><?= h(tt('admin.customer_txn.field.amount', [], 'Amount')) ?></th>
            <th style="width:110px;"><?= h(tt('admin.customer_txn.field.status', [], 'Status')) ?></th>
            <th style="width:150px;"><?= h(tt('admin.customer_txn.table.actions', [], 'Actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" style="padding:14px;font-size:13px;color:#6b7280;">
                <?= h(tt('admin.dashboard.pending_empty', [], 'No pending transactions.')) ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $tid = (int)($r['id'] ?? 0);
                $cid = (int)($r['customer_id'] ?? 0);
                $txnType = strtoupper(trim((string)($r['txn_type'] ?? '')));
                $date = $r['txn_date'] ?: substr((string)($r['created_at'] ?? ''), 0, 10);
                $customerLabel = trim((string)($r['customer_code'] ?? ''));
                if ($customerLabel !== '') $customerLabel .= ' - ';
                $customerLabel .= (string)($r['customer_name'] ?? ('#' . $cid));
                $currency = (string)($r['currency'] ?? 'MYR');
                $amount = (float)($r['order_total'] ?? 0);
                if ($amount <= 0) $amount = (float)($r['amount'] ?? 0);
                $viewUrl = $txnType === 'IN'
                  ? url('admin/customers/txn_doc_in.php?id=' . $tid . '&customer_id=' . $cid . '&doc=QUOTATION')
                  : url('admin/customers/txn_view.php?id=' . $tid . '&customer_id=' . $cid);
                $listUrl = url('admin/customers/txn_list.php?customer_id=' . $cid . '&status=' . urlencode((string)($r['status'] ?? 'PENDING')));
              ?>
              <tr>
                <td><?= h($date) ?></td>
                <td>
                  <div style="font-size:13px;font-weight:600;"><?= h($customerLabel) ?></div>
                  <div class="pending-muted">Customer #<?= (int)$cid ?></div>
                </td>
                <td>
                  <span style="font-size:12px;font-weight:700;color:<?= $txnType === 'IN' ? '#166534' : '#b91c1c' ?>;">
                    <?= h($txnType ?: '-') ?>
                  </span>
                </td>
                <td>
                  <div style="font-size:13px;font-weight:500;"><?= h($r['title'] ?: '-') ?></div>
                  <?php if (!empty($r['invoice_no'])): ?>
                    <div class="pending-muted">Invoice: <?= h($r['invoice_no']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($r['ref_no'])): ?>
                    <div class="pending-muted">Ref: <?= h($r['ref_no']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:700;"><?= h($currency) ?> <?= number_format($amount, 2) ?></td>
                <td><span class="pending-status"><?= h(strtoupper((string)($r['status'] ?? 'PENDING'))) ?></span></td>
                <td>
                  <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="<?= h($viewUrl) ?>" class="btn btn-primary" style="font-size:12px;padding:5px 10px;"><?= h(tt('admin.customer_txn.list.action_view', [], 'View')) ?></a>
                    <a href="<?= h($listUrl) ?>" class="btn btn-light" style="font-size:12px;padding:5px 10px;"><?= h(tt('admin.nav.customer_list', [], 'List')) ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
