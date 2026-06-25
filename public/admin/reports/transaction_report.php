<?php
// public/admin/reports/transaction_report.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('REPORT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (function_exists('app_ensure_customer_currency_schema')) {
  app_ensure_customer_currency_schema($pdo);
}

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$hasT = function_exists('t');

/**
 * Bank label consistent with txn_list / txn_edit
 */
function bank_label(array $b): string {
  $parts = [];
  if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
  if (!empty($b['account_name'])) $parts[] = $b['account_name'];
  if (!empty($b['account_no']))   $parts[] = $b['account_no'];
  $label = implode(' · ', $parts);
  if (!empty($b['currency'])) {
    $label .= $label !== '' ? ' ['.$b['currency'].']' : '['.$b['currency'].']';
  }
  return $label ?: ('Account #'.($b['id'] ?? ''));
}

/** schema-safe columns */
function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  $k = strtolower($table);
  if (isset($cache[$k])) return $cache[$k];
  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
    }
  } catch (Throwable $e) {}
  return $cache[$k] = $cols;
}

// =====================
// Filters
// =====================
$colsTxn = table_columns($pdo, 'customer_txn');
$colsPay = table_columns($pdo, 'customer_txn_payments');

$date_from = (string)($_GET['date_from'] ?? '');
$date_to   = (string)($_GET['date_to']   ?? '');

// ✅ NEW: date_all (来自你 date_range.php hidden input)
$date_all = (string)($_GET['date_all'] ?? '0');

// （兼容旧参数）
$rangeRaw = (string)($_GET['range'] ?? ($_GET['quick'] ?? ($_GET['date_range'] ?? '')));
$range = strtoupper(trim($rangeRaw));

// ✅ 统一判定：All dates
$isAllDates = false;
if ($date_all === '1') $isAllDates = true;
if ($range === 'ALL') $isAllDates = true;
if (strtoupper(trim($date_from)) === 'ALL' && strtoupper(trim($date_to)) === 'ALL') $isAllDates = true;

// ✅ 只有非 all 才给默认本月
if (!$isAllDates) {
  if ($date_from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-01');
  if ($date_to   === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-t');
} else {
  $date_from = '';
  $date_to   = '';
}

$customer_id = (int)($_GET['customer_id'] ?? 0);

$type   = strtoupper(trim((string)($_GET['type']   ?? 'ALL')));     // ALL/IN/OUT
$status = strtoupper(trim((string)($_GET['status'] ?? 'ALL')));     // ALL/DRAFT/SENT/PENDING/CONFIRMED
$method = (string)($_GET['method'] ?? 'ALL');                       // ALL/BANK_<id>/ALLOCATE
$contra = strtoupper(trim((string)($_GET['contra'] ?? 'WITHOUT'))); // ALL/CONTRA/WITHOUT
$q      = trim((string)($_GET['q'] ?? ''));

if (!in_array($type, ['ALL','IN','OUT'], true)) $type = 'ALL';
if (!in_array($status, ['ALL','DRAFT','SENT','PENDING','CONFIRMED'], true)) $status = 'ALL';
if (!in_array($contra, ['ALL','CONTRA','WITHOUT'], true)) $contra = 'WITHOUT';

// txn_date 优先，没有就 created_at
$dateExprTxn = "COALESCE(t.txn_date, DATE(t.created_at))";

// payments date expr（schema-safe）
$payDateExpr = isset($colsPay['txn_date'])
  ? "COALESCE(p.txn_date, DATE(p.created_at))"
  : "DATE(p.created_at)";

// =====================
// Customers dropdown
// =====================
$customers = [];
try {
  $customers = $pdo->query("SELECT id, code, name FROM customers ORDER BY code ASC, name ASC")->fetchAll();
} catch (Throwable $e) {
  $customers = [];
}

// =====================
// Bank accounts for method filter + display
// =====================
$bankRows = [];
try {
  $bankRows = $pdo->query("
    SELECT id, bank_code, account_name, account_no, currency
    FROM company_bank_accounts
    WHERE is_active = 1
    ORDER BY bank_code, account_name, account_no, id
  ")->fetchAll();
} catch (Throwable $e) {
  $bankRows = [];
}
$bankAccMap = [];
foreach ($bankRows as $b) $bankAccMap[(int)$b['id']] = $b;

// parse method
$bankFilterId = 0;
$allocateOnly = false;
if (is_string($method) && strpos($method, 'BANK_') === 0) {
  $bankFilterId = (int)substr($method, 5);
  if ($bankFilterId <= 0) $bankFilterId = 0;
} elseif ($method === 'ALLOCATE') {
  $allocateOnly = true; // ✅ 只看 allocate/payment 行
}

// =====================
// Build WHERE for derived table x
// =====================
$where  = [];
$params = [];

// ✅ 关键：All 就不要加日期条件
if (!$isAllDates) {
  $where[] = "x.txn_effective_date BETWEEN :d1 AND :d2";
  $params[':d1'] = $date_from;
  $params[':d2'] = $date_to;
}

if ($customer_id > 0) {
  $where[] = "x.customer_id = :cid";
  $params[':cid'] = $customer_id;
}

if ($type === 'IN')  $where[] = "x.txn_type = 'IN'";
if ($type === 'OUT') $where[] = "x.txn_type = 'OUT'";

if (in_array($status, ['DRAFT','SENT','PENDING','CONFIRMED'], true)) {
  $where[] = "x.status = :status";
  $params[':status'] = $status;
}

// method filter
if ($allocateOnly) {
  $where[] = "x.row_kind = 'ALLOCATE'";
} elseif ($bankFilterId > 0) {
  $where[] = "(
      (x.row_kind = 'ALLOCATE' AND COALESCE(x.bank_account_id,0) = :bank_filter_id)
      OR
      (x.row_kind = 'TXN' AND (
          (x.txn_type = 'IN' AND EXISTS (
            SELECT 1 FROM customer_txn_payments p2
            WHERE p2.customer_txn_id = x.txn_id
              AND p2.bank_account_id = :bank_filter_id
          ))
          OR
          (x.txn_type = 'OUT' AND (
            COALESCE(x.bank_account_id,0) = :bank_filter_id
            OR COALESCE(x.pay_source_bank_account_id,0) = :bank_filter_id
          ))
      ))
    )";
  $params[':bank_filter_id'] = $bankFilterId;
}

// contra dropdown（allocate 行跟随 parent is_contra/source_txn_id/allocated_amount）
if (!$allocateOnly) {
  if ($contra === 'CONTRA') {
    $where[] = "(
      (COALESCE(x.is_contra,0) = 1)
      OR (x.txn_type = 'IN' AND COALESCE(x.source_txn_id,0) <> 0)
    )";
  } elseif ($contra === 'WITHOUT') {
    $where[] = "(
      (COALESCE(x.is_contra,0) = 0)
      AND NOT (
        x.txn_type = 'IN'
        AND COALESCE(x.allocated_amount,0) >= COALESCE(x.amount_raw,0)
      )
    )";
  }
}

// search：title / ref_no / notes(if exists)
if ($q !== '') {
  $conds = [];
  $conds[] = "x.title LIKE :q";
  $conds[] = "x.ref_no LIKE :q";
  if (isset($colsTxn['notes'])) $conds[] = "x.notes LIKE :q";
  $where[] = "(" . implode(" OR ", $conds) . ")";
  $params[':q'] = '%'.$q.'%';
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

// =====================
// Summary（只算 customer_txn）
// =====================
$sumWhere = "1=1";
$sumParams = [];

if (!$isAllDates) {
  $sumWhere .= " AND {$dateExprTxn} BETWEEN :d1 AND :d2";
  $sumParams[':d1'] = $date_from;
  $sumParams[':d2'] = $date_to;
}

if ($customer_id > 0) {
  $sumWhere .= " AND t.customer_id = :cid";
  $sumParams[':cid'] = $customer_id;
}

$sumSql = "
  SELECT
    SUM(CASE
      WHEN t.txn_type = 'IN'
       AND UPPER(COALESCE(t.in_kind,'')) LIKE '%INVOICE%'
       AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%RETURN%'
       AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%REPAY%'
       AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%BONUS%'
      THEN COALESCE(t.order_total,0)
      ELSE 0
    END) AS total_in_invoice,

    SUM(CASE
      WHEN t.txn_type = 'IN'
       AND UPPER(COALESCE(t.in_kind,'')) LIKE '%BONUS%'
      THEN COALESCE(t.amount,0)
      ELSE 0
    END) AS bonus_total,

    SUM(CASE
      WHEN t.txn_type = 'IN'
       AND (
            (UPPER(COALESCE(t.in_kind,'')) LIKE '%RETURN%' OR UPPER(COALESCE(t.in_kind,'')) LIKE '%REPAY%')
            OR t.title LIKE '%Repayment%'
            OR t.title LIKE '%repayment%'
            OR t.title LIKE '%Return%'
            OR t.title LIKE '%return%'
          )
      THEN COALESCE(t.amount,0)
      ELSE 0
    END) AS repay_total,

    SUM(CASE
      WHEN t.txn_type = 'OUT'
       AND COALESCE(t.is_contra,0) = 0
       AND UPPER(COALESCE(t.out_kind,'')) <> 'LOAN'
      THEN COALESCE(t.amount,0)
      ELSE 0
    END) AS total_out_normal,

    SUM(CASE
      WHEN t.txn_type = 'OUT'
       AND COALESCE(t.is_contra,0) = 0
       AND UPPER(COALESCE(t.out_kind,'')) = 'LOAN'
      THEN COALESCE(t.amount,0)
      ELSE 0
    END) AS loan_total
  FROM customer_txn t
  WHERE {$sumWhere}
";
$st = $pdo->prepare($sumSql);
$st->execute($sumParams);
$sumRow = $st->fetch() ?: [];

$total_in_normal  = (float)($sumRow['total_in_invoice'] ?? 0);
$total_out_normal = (float)($sumRow['total_out_normal'] ?? 0);
$bonus_total      = (float)($sumRow['bonus_total'] ?? 0);
$repay_total      = (float)($sumRow['repay_total'] ?? 0);
$loan_total       = (float)($sumRow['loan_total'] ?? 0);

$net_normal      = $total_in_normal - $total_out_normal;
$return_balance  = $loan_total - $repay_total;
$summary_in      = $total_in_normal + $bonus_total + $repay_total;
$summary_out     = $total_out_normal + $loan_total;
$summary_net     = $summary_in - $summary_out;

// =====================
// Derived table (TXN + ALLOCATE rows)
// =====================
$derivedSql = "
  SELECT * FROM (
    /* ===== TXN rows ===== */
    SELECT
      'TXN' AS row_kind,
      t.id  AS row_id,
      t.id  AS txn_id,
      t.customer_id,
      DATE({$dateExprTxn}) AS txn_effective_date,
      t.txn_type,
      t.in_kind,
      t.out_kind,
      t.status,
      t.title,
      t.ref_no,
      " . (isset($colsTxn['notes']) ? "t.notes" : "NULL") . " AS notes,
      t.method,
      t.pay_source_type,
      " . (isset($colsTxn['pay_source_method']) ? "t.pay_source_method" : "NULL") . " AS pay_source_method,
      " . (isset($colsTxn['pay_source_bank_account_id']) ? "t.pay_source_bank_account_id" : "NULL") . " AS pay_source_bank_account_id,
      t.bank_account_id,
      t.currency,
      t.order_currency,
      t.order_total,
      t.amount          AS amount_raw,
      t.allocated_amount,
      t.is_contra,
      t.source_txn_id,
      t.created_at
    FROM customer_txn t

    UNION ALL

    /* ===== ALLOCATE rows (payments) ===== */
    SELECT
      'ALLOCATE' AS row_kind,
      p.id       AS row_id,
      t.id       AS txn_id,
      t.customer_id,
      DATE({$payDateExpr}) AS txn_effective_date,
      'IN'       AS txn_type,
      'ALLOCATE' AS in_kind,
      NULL       AS out_kind,
      t.status   AS status,
      CONCAT('Allocate · ', COALESCE(t.title,'')) AS title,
      NULL       AS ref_no,
      NULL       AS notes,
      'BANK'     AS method,
      NULL       AS pay_source_type,
      NULL       AS pay_source_method,
      NULL       AS pay_source_bank_account_id,
      p.bank_account_id,
      COALESCE(NULLIF(t.order_currency,''), t.currency, 'MYR') AS currency,
      t.order_currency,
      NULL       AS order_total,
      p.amount   AS amount_raw,
      t.allocated_amount,
      t.is_contra,
      t.source_txn_id,
      p.created_at
    FROM customer_txn_payments p
    JOIN customer_txn t ON t.id = p.customer_txn_id
  ) x
";

// =====================
// Pagination
// =====================
$sqlCount = "SELECT COUNT(*) FROM ({$derivedSql}) x WHERE {$whereSql}";
$st = $pdo->prepare($sqlCount);
$st->execute($params);
$totalRows = (int)$st->fetchColumn();

$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// =====================
// Detail rows (TXN + ALLOCATE)
// =====================
$sqlDetail = "
  SELECT
    x.*,
    c.code AS customer_code,
    c.name AS customer_name
  FROM ({$derivedSql}) x
  LEFT JOIN customers c ON c.id = x.customer_id
  WHERE {$whereSql}
  ORDER BY x.txn_effective_date DESC, x.row_kind DESC, x.row_id DESC
  LIMIT {$perPage} OFFSET {$offset}
";
$st = $pdo->prepare($sqlDetail);
$st->execute($params);
$rows = $st->fetchAll();

// =====================
// Payments aggregation (for invoice method + pending calc)
// =====================
$paidRawByTxn = []; // tid => sum(amount)
$bankIdsByTxn = []; // tid => [bank_id=>true]
$txnIds = [];

foreach ($rows as $r) {
  $tid = (int)($r['txn_id'] ?? 0);
  if ($tid > 0) $txnIds[$tid] = true;
}

if ($txnIds) {
  $ids = array_keys($txnIds);
  $inClause = implode(',', array_fill(0, count($ids), '?'));
  try {
    $st = $pdo->prepare("
      SELECT customer_txn_id, bank_account_id, amount
      FROM customer_txn_payments
      WHERE customer_txn_id IN ($inClause)
      ORDER BY customer_txn_id, id
    ");
    $st->execute($ids);
    while ($p = $st->fetch()) {
      $tid = (int)$p['customer_txn_id'];
      $amt = (float)($p['amount'] ?? 0);
      if (!isset($paidRawByTxn[$tid])) $paidRawByTxn[$tid] = 0.0;
      $paidRawByTxn[$tid] += $amt;

      $bid = (int)($p['bank_account_id'] ?? 0);
      if ($bid > 0) {
        if (!isset($bankIdsByTxn[$tid])) $bankIdsByTxn[$tid] = [];
        $bankIdsByTxn[$tid][$bid] = true;
      }
    }
  } catch (Throwable $e) {
    $paidRawByTxn = [];
    $bankIdsByTxn = [];
  }
}

// Pending total: 只算 INVOICE( TXN 行 )，not confirmed, not contra => order_total - paid_raw
$pending_total = 0.0;
foreach ($rows as $r) {
  if (($r['row_kind'] ?? '') !== 'TXN') continue;
  if (($r['txn_type'] ?? '') !== 'IN') continue;
  if ((int)($r['is_contra'] ?? 0) === 1) continue;

  $inKind = strtoupper((string)($r['in_kind'] ?? ''));
  if ($inKind !== 'INVOICE') continue;

  if (($r['status'] ?? '') === 'CONFIRMED') continue;

  $tid = (int)($r['txn_id'] ?? 0);
  $order_total = (float)($r['order_total'] ?? 0);
  $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
  $unpaid = $order_total - $paid_raw;
  if ($unpaid > 0.0001) $pending_total += $unpaid;
}

// =====================
// Audit
// =====================
if (function_exists('audit_log')) {
  audit_log(
    $pdo,
    'REPORT.TRANSACTION.VIEW',
    [
      'description' => 'View transaction report page',
      'date_from'   => $date_from !== '' ? $date_from : null,
      'date_to'     => $date_to !== '' ? $date_to : null,
      'date_all'    => $isAllDates ? 1 : 0,
      'customer_id' => $customer_id ?: null,
      'type'        => $type,
      'status'      => $status,
      'method'      => $method,
      'contra'      => $contra,
      'q'           => $q !== '' ? $q : null,
      'page'        => $page,
      'range'       => $rangeRaw !== '' ? $rangeRaw : null,
    ],
    'report_transaction',
    null
  );
}

$page_title = $hasT ? t('admin.report.transaction.title', [], 'Transaction Report') : 'Transaction Report';
include __DIR__ . '/../include/header.php';

// small text
$txt_bank_allocate = $hasT ? t('admin.customer_txn.list.filter.bank_allocate', [], 'Bank / Allocate') : 'Bank / Allocate';
$txt_allocate_contra_only = $hasT ? t('admin.customer_txn.list.filter.allocate_contra_only', [], 'Allocate') : 'Allocate';
$txt_contra_only = $hasT ? t('admin.customer_txn.list.filter.contra_only', [], 'Contra only') : 'Contra only';
$txt_after_contra = $hasT ? t('admin.customer_txn.list.summary.after', [], 'After contra') : 'After contra';
$txt_unpaid_amount = $hasT ? t('admin.customer_txn.list.summary.unpaid_amount', [], 'Unpaid amount') : 'Unpaid amount';
$txt_outstanding_owe_us = $hasT ? t('admin.customer_txn.list.return_still_owing', [], 'Outstanding (owe us)') : 'Outstanding (owe us)';
$txt_capital_returned = $hasT ? t('admin.customer_txn.list.return_profit', [], 'Capital returned') : 'Capital returned';
$txt_in_plus = $hasT ? t('admin.customer_txn.list.summary.in_plus', [], 'IN + Bonus + Repayment') : 'IN + Bonus + Repayment';
$txt_out_plus = $hasT ? t('admin.customer_txn.list.summary.out_plus', [], 'OUT + Loan') : 'OUT + Loan';

$txt_total_records_per_page = $hasT
  ? t('admin.report.transaction.details.badge', [], 'Total: %d records · %d per page')
  : 'Total: %d records · %d per page';

$txt_no_rows = $hasT ? t('admin.report.transaction.empty', [], 'No transactions found.') : 'No transactions found.';

$txt_page_line = $hasT ? t('admin.common.page_line', [], 'Page %d / %d') : 'Page %d / %d';
$txt_prev = $hasT ? t('admin.common.prev', [], 'Prev') : 'Prev';
$txt_next = $hasT ? t('admin.common.next', [], 'Next') : 'Next';
?>

<style>
  .dashboard-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;}
  .dashboard-card-metric{padding:14px 16px;border-radius:14px;border:1px solid #e5e7eb;background:#fff;}
  .dashboard-card-label{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px;}
  .dashboard-card-value{font-size:20px;font-weight:700;margin-bottom:2px;}
  .dashboard-card-sub{font-size:12px;color:#6b7280;}
  .badge-soft-pill{font-size:11px;padding:3px 9px;border-radius:999px;border:1px solid #e5e7eb;background:#f9fafb;color:#4b5563;}
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h($hasT ? t('admin.report.eyebrow', [], 'Reports') : 'Reports') ?></div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
        </div>

        <div>
          <a href="<?= h(url('admin/reports/transaction_report_export.php?' . http_build_query($_GET))) ?>" class="btn btn-light">
            <?= h($hasT ? t('admin.common.export', [], 'Export') : 'Export') ?>
          </a>
        </div>
      </div>

      <form method="get" action="<?= h(url('admin/reports/transaction_report.php')) ?>"
            style="margin-top:10px;display:flex;flex-direction:column;gap:10px;">

        <?php include __DIR__ . '/../../include/date_range.php'; ?>

        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">

          <div style="min-width:240px;">
            <label class="field-label" style="margin-bottom:4px;">
              <?= h($hasT ? t('admin.report.transaction.filter.customer', [], 'Customer') : 'Customer') ?>
            </label>
            <select name="customer_id" class="form-control">
              <option value="0"><?= h($hasT ? t('admin.report.transaction.filter.customer_all', [], 'All customers') : 'All customers') ?></option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $customer_id === (int)$c['id'] ? 'selected' : '' ?>>
                  <?= h(($c['code'] ? $c['code'].' · ' : '').$c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h($hasT ? t('admin.report.transaction.filter.type', [], 'Type') : 'Type') ?></label>
            <select name="type" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $type==='ALL'?'selected':'' ?>><?= h($hasT ? t('admin.common.all', [], 'All') : 'All') ?></option>
              <option value="IN"  <?= $type==='IN'?'selected':'' ?>>IN</option>
              <option value="OUT" <?= $type==='OUT'?'selected':'' ?>>OUT</option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h($hasT ? t('common.status', [], 'Status') : 'Status') ?></label>
            <select name="status" class="form-control" style="min-width:140px;">
              <option value="ALL" <?= $status==='ALL'?'selected':'' ?>><?= h($hasT ? t('admin.common.all', [], 'All') : 'All') ?></option>
              <option value="DRAFT" <?= $status==='DRAFT'?'selected':'' ?>><?= h($hasT ? t('admin.customer_txn.status.draft', [], 'DRAFT') : 'DRAFT') ?></option>
              <option value="SENT"  <?= $status==='SENT'?'selected':'' ?>><?= h($hasT ? t('admin.customer_txn.status.sent', [], 'SENT') : 'SENT') ?></option>
              <option value="PENDING" <?= $status==='PENDING'?'selected':'' ?>><?= h($hasT ? t('admin.customer_txn.status.pending', [], 'PENDING') : 'PENDING') ?></option>
              <option value="CONFIRMED" <?= $status==='CONFIRMED'?'selected':'' ?>><?= h($hasT ? t('admin.customer_txn.status.confirmed', [], 'CONFIRMED') : 'CONFIRMED') ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h($txt_bank_allocate) ?></label>
            <select name="method" class="form-control" style="min-width:280px;">
              <option value="ALL" <?= $method==='ALL'?'selected':'' ?>><?= h($hasT ? t('admin.common.all', [], 'All') : 'All') ?></option>
              <?php foreach ($bankRows as $b): ?>
                <?php $val = 'BANK_'.(int)$b['id']; ?>
                <option value="<?= h($val) ?>" <?= $method===$val?'selected':'' ?>>
                  <?= h(bank_label($b)) ?>
                </option>
              <?php endforeach; ?>
              <option value="ALLOCATE" <?= $method==='ALLOCATE'?'selected':'' ?>><?= h($txt_allocate_contra_only) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h($hasT ? t('admin.report.transaction.filter.contra', [], 'Contra') : 'Contra') ?></label>
            <select name="contra" class="form-control" style="min-width:160px;">
              <option value="ALL" <?= $contra==='ALL'?'selected':'' ?>><?= h($hasT ? t('admin.common.all', [], 'All') : 'All') ?></option>
              <option value="CONTRA" <?= $contra==='CONTRA'?'selected':'' ?>><?= h($txt_contra_only) ?></option>
              <option value="WITHOUT" <?= $contra==='WITHOUT'?'selected':'' ?>><?= h($txt_after_contra) ?></option>
            </select>
          </div>

          <div style="min-width:240px;">
            <label class="field-label" style="margin-bottom:4px;"><?= h($hasT ? t('admin.customer_txn.list.filter.search', [], 'Search') : 'Search') ?></label>
            <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
                   placeholder="<?= h($hasT ? t('admin.customer_txn.list.filter.q_ph', [], 'Title / Ref / Notes') : 'Title / Ref / Notes') ?>">
          </div>

          <div style="margin-left:auto;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"><?= h($hasT ? t('admin.common.apply', [], 'Apply') : 'Apply') ?></button>
            <a href="<?= h(url('admin/reports/transaction_report.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.reset', [], 'Reset') : 'Reset') ?>
            </a>
          </div>
        </div>
      </form>

      <div class="dashboard-cards" style="margin-top:12px;">
        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.total_in', [], 'Total IN') : 'Total IN') ?></div>
          <div class="dashboard-card-value" style="color:#166534;">MYR <?= number_format($total_in_normal, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_after_contra) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.total_out', [], 'Total OUT') : 'Total OUT') ?></div>
          <div class="dashboard-card-value" style="color:#b91c1c;">MYR <?= number_format($total_out_normal, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_after_contra) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.net_normal', [], 'Net') : 'Net') ?></div>
          <div class="dashboard-card-value" style="color:<?= $net_normal>=0?'#b91c1c':'#166534' ?>;">
            MYR <?= number_format(abs($net_normal), 2) ?>
          </div>
          <div class="dashboard-card-sub">
            <?= h($net_normal>0
              ? t('admin.customers.list.net_label_we_owe', [], 'We owe customer')
              : ($net_normal<0 ? t('admin.customers.list.net_label_cust_owe', [], 'Customer owes us') : t('admin.customers.list.net_label_balanced', [], 'Balanced'))
            ) ?>
          </div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.pending', [], 'Pending payment') : 'Pending payment') ?></div>
          <div class="dashboard-card-value" style="color:#0f766e;">MYR <?= number_format($pending_total, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_unpaid_amount) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.return_balance', [], 'Return') : 'Return') ?></div>
          <div class="dashboard-card-value" style="color:<?= $return_balance>0?'#b91c1c':'#166534' ?>;">
            MYR <?= number_format(abs($return_balance), 2) ?>
          </div>
          <div class="dashboard-card-sub"><?= h($return_balance>0 ? $txt_outstanding_owe_us : $txt_capital_returned) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.total_bonus', [], 'Total BONUS') : 'Total BONUS') ?></div>
          <div class="dashboard-card-value">MYR <?= number_format($bonus_total, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_after_contra) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_in', [], 'Summary total IN') : 'Summary total IN') ?></div>
          <div class="dashboard-card-value">MYR <?= number_format($summary_in, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_in_plus) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_out', [], 'Summary total OUT') : 'Summary total OUT') ?></div>
          <div class="dashboard-card-value">MYR <?= number_format($summary_out, 2) ?></div>
          <div class="dashboard-card-sub"><?= h($txt_out_plus) ?></div>
        </div>

        <div class="dashboard-card-metric">
          <div class="dashboard-card-label"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_net', [], 'Summary net') : 'Summary net') ?></div>
          <div class="dashboard-card-value" style="color:<?= $summary_net>=0?'#b91c1c':'#166534' ?>;">
            MYR <?= number_format(abs($summary_net), 2) ?>
          </div>
          <div class="dashboard-card-sub">
            <?= h($summary_net>0
              ? t('admin.customers.list.net_label_we_owe', [], 'We owe customer')
              : ($summary_net<0 ? t('admin.customers.list.net_label_cust_owe', [], 'Customer owes us') : t('admin.customers.list.net_label_balanced', [], 'Balanced'))
            ) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Detail table -->
    <div class="admin-card">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h($hasT ? t('admin.report.transaction.details.eyebrow', [], 'Details') : 'Details') ?></div>
          <h2 class="page-title" style="font-size:16px;"><?= h($hasT ? t('admin.report.transaction.details.title', [], 'Transactions') : 'Transactions') ?></h2>
        </div>
        <div>
          <span class="badge-soft-pill">
            <?= h(sprintf($txt_total_records_per_page, (int)$totalRows, (int)$perPage)) ?>
          </span>
        </div>
      </div>

      <table class="table">
        <thead>
          <tr>
            <th style="width:95px;"><?= h($hasT ? t('admin.customer_txn.field.date', [], 'Date') : 'Date') ?></th>
            <th style="width:220px;"><?= h($hasT ? t('admin.report.transaction.filter.customer', [], 'Customer') : 'Customer') ?></th>
            <th style="width:110px;"><?= h($hasT ? t('admin.customer_txn.field.type', [], 'Type') : 'Type') ?></th>
            <th style="width:260px;"><?= h($hasT ? t('admin.customer_txn.field.method', [], 'Method') : 'Method') ?></th>
            <th style="width:140px;"><?= h($hasT ? t('admin.customer_txn.field.amount', [], 'Amount') : 'Amount') ?></th>
            <th style="width:140px;"><?= h($hasT ? t('admin.customer_txn.list.pending', [], 'Pending') : 'Pending') ?></th>
            <th style="width:110px;"><?= h($hasT ? t('admin.customer_txn.field.status', [], 'Status') : 'Status') ?></th>
            <th><?= h($hasT ? t('admin.customer_txn.field.title', [], 'Title') : 'Title') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" style="padding:14px;font-size:13px;color:#6b7280;"><?= h($txt_no_rows) ?></td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $rowKind = (string)($r['row_kind'] ?? 'TXN');
              $tid = (int)($r['txn_id'] ?? 0);

              $isIn = (($r['txn_type'] ?? '') === 'IN');
              $isContra = ((int)($r['is_contra'] ?? 0) === 1);

              $inKind  = strtoupper((string)($r['in_kind'] ?? ''));
              $outKind = strtoupper((string)($r['out_kind'] ?? ''));

              $date = $r['txn_effective_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);
              $custLabel = trim(($r['customer_code'] ? $r['customer_code'].' · ' : '').($r['customer_name'] ?? ''));

              $isAllocateTxn = ($rowKind === 'TXN' && $isIn) && (
                $inKind === 'ALLOCATE'
                || $isContra
                || (int)($r['source_txn_id'] ?? 0) > 0
                || stripos((string)($r['title'] ?? ''), 'allocate') !== false
              );

              $typeMain = $r['txn_type'] ?? '';
              $typeSub  = '';
              if ($rowKind === 'ALLOCATE') {
                $typeSub = 'ALLOCATE';
              } else {
                if ($isIn) {
                  if ($isAllocateTxn) $typeSub = 'ALLOCATE';
                  else $typeSub = $inKind !== '' ? $inKind : '';
                } else {
                  $typeSub = $outKind !== '' ? $outKind : '';
                }
              }

              $displayCurrency = (string)($r['currency'] ?? 'MYR');
              $displayAmount = 0.0;

              if ($rowKind === 'ALLOCATE') {
                $displayAmount = (float)($r['amount_raw'] ?? 0);
              } else {
                if ($isIn) {
                  if ($isAllocateTxn) {
                    $displayAmount = (float)($r['amount_raw'] ?? 0);
                  } elseif ($inKind === 'INVOICE') {
                    $displayAmount = (float)($r['order_total'] ?? 0);
                    $oc = trim((string)($r['order_currency'] ?? ''));
                    if ($oc !== '') $displayCurrency = $oc;
                  } else {
                    $displayAmount = (float)($r['amount_raw'] ?? 0);
                  }
                } else {
                  $displayAmount = (float)($r['amount_raw'] ?? 0);
                }
              }

              $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
              $unpaid = 0.0;
              if ($rowKind === 'TXN' && $isIn && $inKind === 'INVOICE' && !$isContra && !$isAllocateTxn && ($r['status'] ?? '') !== 'CONFIRMED') {
                $invoiceTotal = (float)($r['order_total'] ?? 0);
                $unpaid = max(0.0, $invoiceTotal - $paid_raw);
              }

              $methodLabel = '-';
              $bankLabels = [];

              if ($rowKind === 'ALLOCATE') {
                $bid = (int)($r['bank_account_id'] ?? 0);
                if ($bid > 0 && isset($bankAccMap[$bid])) $bankLabels[] = bank_label($bankAccMap[$bid]);
              } elseif ($isIn && $inKind === 'INVOICE') {
                if (!empty($bankIdsByTxn[$tid])) {
                  foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                    if (isset($bankAccMap[(int)$bid])) $bankLabels[] = bank_label($bankAccMap[(int)$bid]);
                  }
                }
              } elseif (!$isIn) {
                $outBankId = (int)($r['bank_account_id'] ?? 0);
                if ($outBankId > 0 && isset($bankAccMap[$outBankId])) $bankLabels[] = bank_label($bankAccMap[$outBankId]);
              }

              $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
              if (!$isIn && strtoupper((string)($r['pay_source_type'] ?? '')) === 'CUSTOMER') {
                $inMethod = strtoupper(trim((string)($r['pay_source_method'] ?? 'OTHER')));
                if ($inMethod === '') $inMethod = 'OTHER';
                $inBankId = (int)($r['pay_source_bank_account_id'] ?? 0);
                $inBank = ($inBankId > 0 && isset($bankAccMap[$inBankId])) ? bank_label($bankAccMap[$inBankId]) : '';
                $outMethod = strtoupper(trim((string)($r['method'] ?? 'CASH'))) ?: 'CASH';
                $methodLabel = 'IN: ' . $inMethod . ($inBank !== '' ? ' -> ' . $inBank : '');
                $methodLabel .= ' / OUT: ' . $outMethod . ($bankLabels ? ' -> ' . implode(' / ', $bankLabels) : '');
              } elseif ($bankLabels) $methodLabel = implode(' / ', $bankLabels);
              else {
                $m = strtoupper((string)($r['method'] ?? ''));
                $methodLabel = $m !== '' ? $m : '-';
              }

              $amtStyle = $isIn ? 'color:#166534;font-weight:700;' : 'color:#b91c1c;font-weight:700;';
            ?>
            <tr>
              <td><?= h($date) ?></td>
              <td><?= $custLabel !== '' ? h($custLabel) : '-' ?></td>

              <td>
                <div style="font-size:12px;font-weight:700;color:<?= $isIn?'#166534':'#b91c1c' ?>;"><?= h($typeMain) ?></div>
                <?php if ($typeSub !== ''): ?>
                  <div style="font-size:10px;color:#6b7280;"><?= h($typeSub) ?></div>
                <?php endif; ?>
                <?php if ($isContra): ?>
                  <div style="font-size:10px;color:#6b7280;"><?= h($hasT ? t('admin.customer_txn.badge.contra', [], 'Contra') : 'Contra') ?></div>
                <?php endif; ?>
              </td>

              <td><div style="font-size:13px;font-weight:500;"><?= h($methodLabel) ?></div></td>

              <td style="<?= $amtStyle ?>">
                <?= h($displayCurrency) ?>
                <?= $isIn ? '+' : '-' ?><?= number_format((float)$displayAmount, 2) ?>
              </td>

              <td style="text-align:right;">
                <?php if ($unpaid > 0.0001): ?>
                  <?= h($displayCurrency) ?> <?= number_format($unpaid, 2) ?>
                <?php else: ?>
                  –
                <?php endif; ?>
              </td>

              <td><?= h($hasT ? t('admin.customer_txn.status.'.strtolower((string)($r['status'] ?? 'draft')), [], (string)($r['status'] ?? '')) : ($r['status'] ?? '')) ?></td>
              <td><?= h($r['title'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>

      <?php if ($totalPages > 1): ?>
        <div style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
          <div style="font-size:12px;color:#6b7280;">
            <?= h(sprintf($txt_page_line, (int)$page, (int)$totalPages)) ?>
          </div>
          <div style="display:flex;gap:6px;">
            <?php $baseParams = $_GET; unset($baseParams['page']); ?>
            <?php if ($page > 1): ?>
              <a class="btn btn-light" href="<?= h(url('admin/reports/transaction_report.php?'.http_build_query($baseParams + ['page'=>$page-1]))) ?>">
                ← <?= h($txt_prev) ?>
              </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a class="btn btn-light" href="<?= h(url('admin/reports/transaction_report.php?'.http_build_query($baseParams + ['page'=>$page+1]))) ?>">
                <?= h($txt_next) ?> →
              </a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
