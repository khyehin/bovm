<?php
// public/user/txn/invoices.php
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

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

require_once __DIR__ . '/../../../config/i18n.php';

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

// columns
function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  $k = strtolower($table);
  if (isset($cache[$k])) return $cache[$k];
  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $name = (string)($r['Field'] ?? '');
      if ($name !== '') $cols[$name] = true;
    }
  } catch (Throwable $e) {
  }
  return $cache[$k] = $cols;
}

$txnCols        = table_columns($pdo, 'customer_txn');
$hasDocFlowType = isset($txnCols['doc_flow_type']);
$hasDocFlowStat = isset($txnCols['doc_flow_status']);

// base where (all IN for this customer; do not restrict by in_kind to avoid missing data)
$where  = ["customer_id = :cid", "txn_type = 'IN'"];
$params = [':cid' => $cid];

// flow filter
$flow   = $_GET['flow'] ?? 'ALL';
$fstat  = $_GET['flow_status'] ?? 'ALL';

if ($hasDocFlowType && $flow !== 'ALL') {
  if (in_array($flow, ['NORMAL', 'QUOTATION'], true)) {
    $where[]              = "doc_flow_type = :flow_type";
    $params[':flow_type'] = $flow;
  }
}
if ($hasDocFlowStat && $fstat !== 'ALL') {
  if (in_array($fstat, ['DRAFT', 'PROCESSING', 'REJECTED', 'COMPLETED'], true)) {
    $where[]                 = "doc_flow_status = :flow_status";
    $params[':flow_status']  = $fstat;
  }
}

$sql = "SELECT * FROM customer_txn WHERE " . implode(' AND ', $where) . " ORDER BY txn_date DESC, id DESC";
$st  = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// 预先检查每个 IN 是否有 payment，用来控制是否显示 Receipt 按钮
$hasPaymentByTxn = [];
if ($rows) {
  $ids = [];
  foreach ($rows as $r) {
    $tid = (int)($r['id'] ?? 0);
    if ($tid > 0) $ids[$tid] = true;
  }
  $ids = array_keys($ids);
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
      $stPay = $pdo->prepare("SELECT customer_txn_id, COUNT(*) AS cnt FROM customer_txn_payments WHERE customer_txn_id IN ($in) GROUP BY customer_txn_id");
      $stPay->execute($ids);
      foreach ($stPay->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $tid = (int)($p['customer_txn_id'] ?? 0);
        if ($tid > 0 && (int)($p['cnt'] ?? 0) > 0) {
          $hasPaymentByTxn[$tid] = true;
        }
      }
    } catch (Throwable $e) {
      // ignore
    }
  }
}

$page_title = t('portal.invoices.title', [], 'Invoices & Quotations');
$active_nav = 'invoices';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(t('portal.invoices.eyebrow', [], 'Customer portal')) ?></div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h(t('portal.invoices.subtitle', [], 'View all your invoices and quotations, and open receipts.')) ?>
          </div>
        </div>
      </div>
    </div>

    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:13px;">
        <div>
          <label class="field-label" style="margin-bottom:4px;"><?= h(t('portal.invoices.filter.flow', [], 'Flow type')) ?></label>
          <select name="flow" class="form-control" style="min-width:150px;">
            <option value="ALL" <?= $flow === 'ALL' ? 'selected' : '' ?>><?= h(t('portal.common.all', [], 'All')) ?></option>
            <option value="NORMAL" <?= $flow === 'NORMAL' ? 'selected' : '' ?>><?= h(t('portal.invoices.flow.normal', [], 'Invoice')) ?></option>
            <option value="QUOTATION" <?= $flow === 'QUOTATION' ? 'selected' : '' ?>><?= h(t('portal.invoices.flow.quotation', [], 'Quotation')) ?></option>
          </select>
        </div>

        <div>
          <label class="field-label" style="margin-bottom:4px;"><?= h(t('portal.invoices.filter.status', [], 'Flow status')) ?></label>
          <select name="flow_status" class="form-control" style="min-width:150px;">
            <option value="ALL" <?= $fstat === 'ALL' ? 'selected' : '' ?>><?= h(t('portal.common.all', [], 'All')) ?></option>
            <option value="DRAFT" <?= $fstat === 'DRAFT' ? 'selected' : '' ?>><?= h('Draft') ?></option>
            <option value="PROCESSING" <?= $fstat === 'PROCESSING' ? 'selected' : '' ?>><?= h('Processing') ?></option>
            <option value="REJECTED" <?= $fstat === 'REJECTED' ? 'selected' : '' ?>><?= h('Rejected') ?></option>
            <option value="COMPLETED" <?= $fstat === 'COMPLETED' ? 'selected' : '' ?>><?= h('Completed') ?></option>
          </select>
        </div>

        <div style="margin-left:auto;display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary"><?= h(t('portal.common.apply', [], 'Apply')) ?></button>
          <a href="<?= h(url('user/txn/invoices.php')) ?>" class="btn btn-light">
            <?= h(t('portal.common.reset', [], 'Reset')) ?>
          </a>
        </div>
      </form>
    </div>

    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;"><?= h(t('portal.invoices.col.date', [], 'Date')) ?></th>
            <th style="width:130px;"><?= h(t('portal.invoices.col.invoice_no', [], 'Invoice No')) ?></th>
            <th style="width:110px;"><?= h(t('portal.invoices.col.type', [], 'Type')) ?></th>
            <th style="width:120px;"><?= h(t('portal.invoices.col.status', [], 'Status')) ?></th>
            <th><?= h(t('portal.invoices.col.title', [], 'Title')) ?></th>
            <th style="width:150px;text-align:right;"><?= h(t('portal.invoices.col.amount', [], 'Amount')) ?></th>
            <th style="width:200px;"><?= h(t('portal.invoices.col.actions', [], 'Actions')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" style="padding:14px;font-size:13px;color:#6b7280;">
                <?= h(t('portal.invoices.empty', [], 'No invoices or quotations yet.')) ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
              $dt      = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);
              $invNo   = (string)($r['invoice_no'] ?? '');
              $title   = (string)($r['title'] ?? '');
              if ($title === '') $title = 'Invoice';
              $cur     = (string)($r['currency'] ?? 'MYR');
              $amt     = (float)($r['order_total'] ?? 0);
              if ($amt <= 0) $amt = (float)($r['amount'] ?? 0);

              $dfType  = $hasDocFlowType ? (string)($r['doc_flow_type'] ?? 'NORMAL') : 'NORMAL';
              $dfStat  = $hasDocFlowStat ? (string)($r['doc_flow_status'] ?? 'DRAFT') : 'DRAFT';

              $dfTypeLabel = ($dfType === 'QUOTATION')
                ? t('portal.invoices.type.quotation', [], 'Quotation')
                : t('portal.invoices.type.invoice', [], 'Invoice');

              // 彩色状态样式（和 admin 风格接近）
              $dfStatUpper = strtoupper($dfStat);
              $dfStatLabel = ($dfStatUpper === 'COMPLETED')
                ? 'Complete'
                : ucfirst(strtolower($dfStatUpper));
              $statusStyle = 'background:#e5e7eb;color:#374151;'; // 默认灰色
              if ($dfStatUpper === 'PROCESSING') {
                $statusStyle = 'background:#dbeafe;color:#1d4ed8;'; // 蓝
              } elseif ($dfStatUpper === 'COMPLETED') {
                $statusStyle = 'background:#dcfce7;color:#166534;'; // 绿
              } elseif ($dfStatUpper === 'REJECTED') {
                $statusStyle = 'background:#fee2e2;color:#b91c1c;'; // 红
              }

              $back = url('user/txn/invoices.php?' . http_build_query($_GET));
              $viewReceiptUrl = url('user/txn/txn_invoice_in.php?id=' . (int)$r['id'] . '&back=' . rawurlencode($back));

              // 文档查看（版式与 admin 一致）
              $viewInvoiceUrl   = url('user/txn/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $cid . '&doc=INVOICE');
              $viewQuotationUrl = url('user/txn/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $cid . '&doc=QUOTATION');
              $viewDoUrl        = url('user/txn/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $cid . '&doc=DO');

              $isRejectedQuotation = ($dfType === 'QUOTATION' && $dfStatUpper === 'REJECTED');
              // 只有已经生成 invoice_no 且 flow type 不是 QUOTATION 才算“有发票”，才能显示 Invoice/DO
              $canViewInvoiceDo = ($invNo !== '' && $dfType !== 'QUOTATION');
              $hasAnyPayment = !empty($hasPaymentByTxn[(int)$r['id']]);
              ?>
              <tr>
                <td><?= h($dt) ?></td>
                <td><?= $invNo !== '' ? h($invNo) : '—' ?></td>
                <td><?= h($dfTypeLabel) ?></td>
                <td>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>">
                    <?= h($dfStatLabel) ?>
                  </span>
                </td>
                <td><?= h($title) ?></td>
                <td style="text-align:right;"><?= h($cur) ?> <?= number_format($amt, 2) ?></td>
                <td>
                  <div style="display:flex;flex-wrap:wrap;gap:4px;">
                    <?php if (!$isRejectedQuotation && $hasAnyPayment): ?>
                      <a href="<?= h($viewReceiptUrl) ?>" class="btn btn-xs btn-light">
                        <?= h(t('portal.invoices.action.view_receipts', [], 'Receipt')) ?>
                      </a>
                    <?php endif; ?>
                    <?php if (!$isRejectedQuotation && $canViewInvoiceDo): ?>
                      <a href="<?= h($viewInvoiceUrl) ?>" class="btn btn-xs btn-light" target="_blank">
                        Invoice
                      </a>
                      <a href="<?= h($viewDoUrl) ?>" class="btn btn-xs btn-light" target="_blank">
                        DO
                      </a>
                    <?php endif; ?>
                    <a href="<?= h($viewQuotationUrl) ?>" class="btn btn-xs btn-light" target="_blank">
                      Quotation
                    </a>
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

