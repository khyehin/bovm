<?php
// public/admin/customers/txn_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.V');   // 需要 TXN.V 权限才能看客户交易列表

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (function_exists('app_ensure_customer_currency_schema')) {
  app_ensure_customer_currency_schema($pdo);
}

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/**
 * i18n helper:
 * - if t() exists -> use it
 * - else fallback
 */
if (!function_exists('tt')) {
  function tt(string $key, string $fallback, array $params = []): string
  {
    if (function_exists('t')) return (string)t($key, $params, $fallback);
    return $fallback;
  }
}

$canFn = function_exists('can') ? 'can' : null;
$canDelete = $canFn ? $canFn('TXN.D') : true;

$cid = (int)($_GET['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

// 载入 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

/**
 * 银行 label 跟 txn_edit_in.php 一样
 */
function bank_label(array $b): string
{
  $parts = [];
  if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
  if (!empty($b['account_name'])) $parts[] = $b['account_name'];
  if (!empty($b['account_no']))   $parts[] = $b['account_no'];
  $label = implode(' · ', $parts);
  if (!empty($b['currency'])) {
    $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
  }
  return $label ?: ('Account #' . ($b['id'] ?? ''));
}

// ✅ schema-safe columns detection
function table_columns(PDO $pdo, string $table): array
{
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $f = (string)($r['Field'] ?? '');
      if ($f !== '') $cols[$f] = true;
    }
  } catch (Throwable $e) {
  }
  return $cache[$key] = $cols;
}

$txnCols = table_columns($pdo, 'customer_txn');
$payCols = table_columns($pdo, 'customer_txn_payments');
$catCols = table_columns($pdo, 'customer_categories');

if (empty($customer['currency']) && !empty($customer['category_id']) && isset($catCols['currency'])) {
  try {
    $stCur = $pdo->prepare("SELECT currency FROM customer_categories WHERE id = :id LIMIT 1");
    $stCur->execute([':id' => (int)$customer['category_id']]);
    $customer['currency'] = strtoupper(trim((string)($stCur->fetchColumn() ?: '')));
  } catch (Throwable $e) {
  }
}
if (empty($customer['currency'])) $customer['currency'] = 'MYR';

/**
 * ✅ 统一识别 in_kind（解决 in_kind='' / title 不一致）
 */
function normalize_in_kind_label(string $raw): string
{
  $raw = strtoupper(trim($raw));
  if ($raw === '') return 'INVOICE';

  $hasInvoice = (strpos($raw, 'INVOICE') !== false || preg_match('/(^|[^A-Z])INV([^A-Z]|$)/', $raw));
  $hasReturn  = (strpos($raw, 'RETURN') !== false || strpos($raw, 'REPAY') !== false);
  $hasBonus   = (strpos($raw, 'BONUS') !== false);

  if ($hasReturn) return $hasInvoice ? 'INVOICE+RETURN' : 'RETURN';
  if ($hasBonus) return $hasInvoice ? 'INVOICE+BONUS' : 'BONUS';
  return 'INVOICE';
}

function in_kind_has_part(string $kind, string $part): bool
{
  $kind = normalize_in_kind_label($kind);
  return strpos('+' . $kind . '+', '+' . strtoupper($part) . '+') !== false;
}

function detect_in_kind(array $r): string
{
  // Financial category for balances/summaries. INVOICE can be combined with RETURN/BONUS.
  $raw = normalize_in_kind_label((string)($r['in_kind'] ?? ''));
  if (in_kind_has_part($raw, 'RETURN')) return 'RETURN';
  if (in_kind_has_part($raw, 'BONUS')) return 'BONUS';
  if (in_kind_has_part($raw, 'INVOICE')) return 'INVOICE';

  // title fallback（更广的关键字）
  $title = strtolower(trim((string)($r['title'] ?? '')));

  $bonusWords = ['bonus', 'profit', 'share', 'dividend', 'commission', 'rebate'];
  foreach ($bonusWords as $w) {
    if ($w !== '' && strpos($title, $w) !== false) return 'BONUS';
  }

  $returnWords = ['repayment', 're-payment', 'repay', 'return', 'capital', 'principal'];
  foreach ($returnWords as $w) {
    if ($w !== '' && strpos($title, $w) !== false) return 'RETURN';
  }

  return 'INVOICE';
}

function display_in_kind_label(array $r): string
{
  $kind = normalize_in_kind_label((string)($r['in_kind'] ?? ''));
  if ($kind === 'INVOICE+RETURN') return 'Invoice + Repayment';
  if ($kind === 'INVOICE+BONUS') return 'Invoice + Bonus';
  if ($kind === 'RETURN') return 'Repayment';
  if ($kind === 'BONUS') return 'Bonus';
  return 'Invoice';
}

// 载入 company_bank_accounts，Method filter 用
$bankRows = $pdo->query("
  SELECT id, bank_code, account_name, account_no, currency
  FROM company_bank_accounts
  WHERE is_active = 1
  ORDER BY bank_code, account_name, account_no, id
")->fetchAll();

// bank_account_id => bank row
$bankAccMap = [];
foreach ($bankRows as $b) $bankAccMap[(int)$b['id']] = $b;

// ------- 过滤参数 ------- //
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$type      = $_GET['type']      ?? 'ALL';      // ALL / IN / OUT
$status    = $_GET['status']    ?? 'ALL';      // ALL / DRAFT / SENT / PENDING / CONFIRMED
$method    = $_GET['method']    ?? 'ALL';      // ALL / BANK_<id> / ALLOCATE
$view      = $_GET['view']      ?? 'AFTER';
$q         = trim($_GET['q']    ?? '');

// 解析 method → bank filter / allocate filter
$bankFilterId = 0;
$onlyContra   = false;

if (is_string($method) && strpos($method, 'BANK_') === 0) {
  $bankFilterId = (int)substr($method, 5);
  if ($bankFilterId <= 0) $bankFilterId = 0;
} elseif ($method === 'ALLOCATE') {
  $onlyContra = true;
}

$where  = ["customer_id = :cid"];
$params = [':cid' => $cid];

// date filter（txn_date 若为空用 created_at）
$dateExpr = "COALESCE(DATE(txn_date), DATE(created_at))";

if ($date_from !== '') {
  $where[]       = "$dateExpr >= :df";
  $params[':df'] = $date_from;
}
if ($date_to !== '') {
  $where[]       = "$dateExpr <= :dt";
  $params[':dt'] = $date_to;
}

if ($type === 'IN') {
  $where[] = "txn_type = 'IN'";
} elseif ($type === 'OUT') {
  $where[] = "txn_type = 'OUT'";
}

// Status
if (in_array($status, ['DRAFT', 'SENT', 'PENDING', 'CONFIRMED'], true)) {
  $where[]           = "status = :status";
  $params[':status'] = $status;
}

// Method filter：bank / contra
if ($onlyContra) {
  if (isset($txnCols['is_contra'])) $where[] = "(is_contra = 1)";
  else $where[] = "1=0";
} elseif ($bankFilterId > 0) {
  $bankFilterParts = [];
  if (!empty($payCols)) {
    $bankFilterParts[] = "EXISTS (
      SELECT 1
      FROM customer_txn_payments p
      WHERE p.customer_txn_id = customer_txn.id
        AND p.bank_account_id = :bank_filter_id
    )";
  }
  if (isset($txnCols['bank_account_id'])) {
    $bankFilterParts[] = "COALESCE(customer_txn.bank_account_id,0) = :bank_filter_id";
  }
  if (isset($txnCols['pay_source_bank_account_id'])) {
    $bankFilterParts[] = "COALESCE(customer_txn.pay_source_bank_account_id,0) = :bank_filter_id";
  }
  $where[] = $bankFilterParts ? "(" . implode(" OR ", $bankFilterParts) . ")" : "1=0";
  $params[':bank_filter_id'] = $bankFilterId;
}

// 🔍 搜索
if ($q !== '') {
  $likeParts = [];
  if (isset($txnCols['title']))      $likeParts[] = "title LIKE :q";
  if (isset($txnCols['ref_no']))     $likeParts[] = "ref_no LIKE :q";
  if (isset($txnCols['remark']))     $likeParts[] = "remark LIKE :q";
  if (isset($txnCols['notes']))      $likeParts[] = "notes LIKE :q";
  if (isset($txnCols['invoice_no'])) $likeParts[] = "invoice_no LIKE :q";

  if ($likeParts) {
    $where[]      = "(" . implode(" OR ", $likeParts) . ")";
    $params[':q'] = '%' . $q . '%';
  }
}

$whereSql = implode(' AND ', $where);

// ------- Summary（AFTER contra，MYR） ------- //
$allocExpr    = isset($txnCols['allocated_amount']) ? "COALESCE(allocated_amount,0)" : "0";
$isContraExpr = isset($txnCols['is_contra']) ? "COALESCE(is_contra,0)" : "0";
$inKindExpr   = isset($txnCols['in_kind']) ? "UPPER(COALESCE(in_kind,''))" : "''";
$outKindExpr  = isset($txnCols['out_kind']) ? "UPPER(COALESCE(out_kind,''))" : "''";

$repayLike = "0=1";
if (isset($txnCols['title'])) {
  $repayLike = "(title LIKE '%Repayment%' OR title LIKE '%repayment%' OR title LIKE '%Return%' OR title LIKE '%return%')";
}

$sumSql = "
  SELECT
    SUM(CASE
      WHEN txn_type = 'IN'
        AND $inKindExpr NOT LIKE '%BONUS%'
        AND $inKindExpr NOT LIKE '%RETURN%'
        AND $inKindExpr NOT LIKE '%REPAY%'
      THEN (amount - $allocExpr)
      ELSE 0
    END) AS total_in_normal,

    SUM(CASE
      WHEN txn_type = 'OUT'
        AND ($isContraExpr = 0)
        AND $outKindExpr <> 'LOAN'
      THEN amount
      ELSE 0
    END) AS total_out_normal,

    SUM(CASE
      WHEN txn_type = 'IN' AND $inKindExpr LIKE '%BONUS%'
      THEN (amount - $allocExpr)
      ELSE 0
    END) AS bonus_total,

    SUM(CASE
      WHEN txn_type = 'IN'
        AND (
          ($inKindExpr LIKE '%RETURN%' OR $inKindExpr LIKE '%REPAY%')
          OR (
            (COALESCE(order_total,0) = 0)
            AND (amount > 0)
            AND ($repayLike)
          )
        )
      THEN (amount - $allocExpr)
      ELSE 0
    END) AS repay_total,

    SUM(CASE
      WHEN txn_type = 'OUT'
        AND ($isContraExpr = 0)
        AND $outKindExpr = 'LOAN'
      THEN amount
      ELSE 0
    END) AS loan_total
  FROM customer_txn
  WHERE $whereSql
";

$st = $pdo->prepare($sumSql);
$st->execute($params);
$sumRow = $st->fetch() ?: [
  'total_in_normal'  => 0,
  'total_out_normal' => 0,
  'bonus_total'      => 0,
  'repay_total'      => 0,
  'loan_total'       => 0,
];

$total_in_normal  = (float)($sumRow['total_in_normal']  ?? 0);
$total_out_normal = (float)($sumRow['total_out_normal'] ?? 0);
$bonus_total      = (float)($sumRow['bonus_total']      ?? 0);
$repay_total      = (float)($sumRow['repay_total']      ?? 0);
$loan_total       = (float)($sumRow['loan_total']       ?? 0);

$net_normal      = $total_in_normal - $total_out_normal;
$return_balance  = $loan_total - $repay_total;
$summary_in      = $total_in_normal + $bonus_total + $repay_total;
$summary_out     = $total_out_normal + $loan_total;
$summary_net     = $summary_in - $summary_out;

$summary_label = tt('admin.customer_txn.list.summary.after', 'After contra (all figures)');

// ------- 取列表 ------- //
$sql = "SELECT *
  FROM customer_txn
  WHERE $whereSql
  ORDER BY txn_date DESC, id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// 🔥 收集 payer ids + txn ids
$payerCompanyIds = [];
$payerStaffIds   = [];
$txnIds          = [];

foreach ($rows as $r) {
  if (!empty($r['payer_company_id'])) $payerCompanyIds[(int)$r['payer_company_id']] = true;
  if (!empty($r['payer_staff_id']))   $payerStaffIds[(int)$r['payer_staff_id']] = true;
  $txnIds[(int)($r['id'] ?? 0)] = true;
}

$payerCompaniesMap = [];
$payerStaffMap     = [];

if ($payerCompanyIds) {
  $ids      = array_keys($payerCompanyIds);
  $inClause = implode(',', array_fill(0, count($ids), '?'));
  try {
    $st = $pdo->prepare("SELECT id, name, reg_no FROM payer_companies WHERE id IN ($inClause)");
    $st->execute($ids);
    while ($row = $st->fetch()) $payerCompaniesMap[(int)$row['id']] = $row;
  } catch (Throwable $e) {
  }
}

if ($payerStaffIds) {
  $ids      = array_keys($payerStaffIds);
  $inClause = implode(',', array_fill(0, count($ids), '?'));
  try {
    $st = $pdo->prepare("SELECT id, staff_name, ic_no FROM payer_company_staff WHERE id IN ($inClause)");
    $st->execute($ids);
    while ($row = $st->fetch()) $payerStaffMap[(int)$row['id']] = $row;
  } catch (Throwable $e) {
  }
}

// 🔢 payments 汇总（用于 IN paid / bank labels / last signature）
$paidRawByTxn     = [];
$bankIdsByTxn     = [];
$lastPaySigByTxn  = [];

if ($txnIds && !empty($payCols)) {
  $ids      = array_keys($txnIds);
  $inClause = implode(',', array_fill(0, count($ids), '?'));

  $extraCols = [];
  if (isset($payCols['payer_signature_image']))    $extraCols[] = 'payer_signature_image';
  if (isset($payCols['receiver_signature_image'])) $extraCols[] = 'receiver_signature_image';
  if (isset($payCols['payer_signed_at']))          $extraCols[] = 'payer_signed_at';
  if (isset($payCols['receiver_signed_at']))       $extraCols[] = 'receiver_signed_at';
  $extraSel = $extraCols ? (', ' . implode(', ', $extraCols)) : '';

  try {
    $st = $pdo->prepare("
      SELECT customer_txn_id, bank_account_id, amount, pay_date, id $extraSel
      FROM customer_txn_payments
      WHERE customer_txn_id IN ($inClause)
      ORDER BY customer_txn_id, pay_date ASC, id ASC
    ");
    $st->execute($ids);

    while ($row = $st->fetch()) {
      $tid = (int)($row['customer_txn_id'] ?? 0);
      if ($tid <= 0) continue;

      $amt = (float)($row['amount'] ?? 0);
      if (!isset($paidRawByTxn[$tid])) $paidRawByTxn[$tid] = 0.0;
      $paidRawByTxn[$tid] += $amt;

      $bid = (int)($row['bank_account_id'] ?? 0);
      if ($bid > 0) {
        if (!isset($bankIdsByTxn[$tid])) $bankIdsByTxn[$tid] = [];
        $bankIdsByTxn[$tid][$bid] = true;
      }

      if ($extraCols) {
        $lastPaySigByTxn[$tid] = [
          'payer_signature_image'    => (string)($row['payer_signature_image'] ?? ''),
          'receiver_signature_image' => (string)($row['receiver_signature_image'] ?? ''),
          'payer_signed_at'          => (string)($row['payer_signed_at'] ?? ''),
          'receiver_signed_at'       => (string)($row['receiver_signed_at'] ?? ''),
        ];
      }
    }
  } catch (Throwable $e) {
  }
}

// ✅ Pending total（INVOICE + RETURN + BONUS）
$pending_total = 0.0;
foreach ($rows as $r) {
  $tType = strtoupper(trim((string)($r['txn_type'] ?? '')));
  if ($tType !== 'IN') continue;

  if ((int)($r['is_contra'] ?? 0) === 1) continue;
  if (strpos((string)($r['notes'] ?? ''), '[POB OUT#') !== false) continue;
  if (strtoupper(trim((string)($r['doc_flow_status'] ?? ''))) === 'REJECTED') continue;
  if (strtoupper(trim((string)($r['status'] ?? ''))) === 'CONFIRMED') continue;

  $tid    = (int)($r['id'] ?? 0);
  $inKind = detect_in_kind($r);

  $paid_raw = (float)($paidRawByTxn[$tid] ?? 0.0);
  if ($paid_raw <= 0.000001) $paid_raw = (float)($r['amount'] ?? 0.0);

  if ($inKind === 'INVOICE') {
    $order_total = (float)($r['order_total'] ?? 0);
    $unpaid = $order_total - $paid_raw;
    if ($unpaid > 0.0001) $pending_total += $unpaid;
  } elseif (in_array($inKind, ['RETURN', 'BONUS'], true)) {
    $target = (float)($r['amount'] ?? 0);
    $unpaid = $target - $paid_raw;
    if ($unpaid > 0.0001) $pending_total += $unpaid;
  }
}

/**
 * ⭐ Contra summary：把所有 is_contra = 1 的记录按「日期 + payer_company_id」合并
 */
$normalRows      = [];
$contraSumByKey  = [];

foreach ($rows as $r) {
  $isContra = (int)($r['is_contra'] ?? 0) === 1;

  if ($isContra) {
    $dtRaw = '';
    if (!empty($r['txn_date'])) $dtRaw = (string)$r['txn_date'];
    elseif (!empty($r['created_at'])) $dtRaw = substr((string)$r['created_at'], 0, 10);

    $dateKey = substr($dtRaw, 0, 10);
    $pcId = (int)($r['payer_company_id'] ?? 0);

    if ($dateKey === '' || $pcId <= 0) {
      $normalRows[] = $r;
      continue;
    }

    $key = $dateKey . '#' . $pcId;
    if (!isset($contraSumByKey[$key])) {
      $contraSumByKey[$key] = ['date' => $dateKey, 'pc_id' => $pcId, 'amount' => 0.0];
    }
    $contraSumByKey[$key]['amount'] += (float)($r['amount'] ?? 0);
  } else {
    $normalRows[] = $r;
  }
}

$summaryRows   = [];
$baseCurrency  = strtoupper(trim((string)($customer['currency'] ?? 'MYR'))) ?: 'MYR';

foreach ($contraSumByKey as $info) {
  $summaryRows[] = [
    '_is_summary_contra' => true,
    'txn_date'           => $info['date'],
    'created_at'         => $info['date'] . ' 00:00:00',
    'txn_type'           => 'OUT',
    'method'             => 'OTHER',
    'title'              => tt('admin.customer_txn.list.contra_summary_title', 'Transaction allocate (contra total)'),
    'amount'             => $info['amount'],
    'currency'           => $baseCurrency,
    'status'             => 'CONFIRMED',
    'is_contra'          => 1,
    'payer_company_id'   => $info['pc_id'],
  ];
}

$rows = array_merge($normalRows, $summaryRows);

usort($rows, function (array $a, array $b): int {
  $da = !empty($a['txn_date']) ? substr((string)$a['txn_date'], 0, 10) : substr((string)($a['created_at'] ?? ''), 0, 10);
  $db = !empty($b['txn_date']) ? substr((string)$b['txn_date'], 0, 10) : substr((string)($b['created_at'] ?? ''), 0, 10);
  if ($da === $db) return 0;
  return $da < $db ? 1 : -1;
});

// ------- 页面展示 ------- //
$baseTitle  = tt('admin.customer_txn.list.title', 'Customer Transactions');
$page_title = $baseTitle . ': ' . ($customer['name'] ?? '');
$ok = $_GET['ok'] ?? '';

include __DIR__ . '/../include/header.php';

?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- 顶部标题 -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.customer_txn.list.eyebrow', 'Customer')) ?></div>
          <h1 class="page-title">
            <?= h($customer['name'] ?? '') ?>
            <?php if (!empty($customer['code'])): ?>
              <span style="font-size:13px;font-weight:500;color:#6b7280;">
                (<?= h($customer['code']) ?>)
              </span>
            <?php endif; ?>
          </h1>
          <div class="form-page-subtitle">
            <?= h(tt('admin.customer_txn.list.subtitle', 'View invoices / payouts, customer payments and contra allocations.')) ?>
          </div>
        </div>
        <div style="display:flex; gap:8px;">
          <a href="<?= h(url('admin/customers/user_list.php?customer_id=' . $cid)) ?>" class="btn btn-light">
            <?= h(tt('admin.customer_txn.list.user_detail_btn', 'User detail')) ?>
          </a>

          <a href="<?= h(url('admin/customers/customer_detail_export.php?' . http_build_query($_GET))) ?>" class="btn btn-light">
            <?= h(tt('admin.common.export', 'Export')) ?>
          </a>

          <a href="<?= h(url('admin/customers/list.php')) ?>" class="btn btn-light">
            ← <?= h(tt('admin.customer_txn.list.back_to_customers', 'Back to customers')) ?>
          </a>
          <a href="<?= h(url('admin/customers/txn_edit.php?customer_id=' . $cid)) ?>" class="btn btn-primary">
            <?= h(tt('admin.customer_txn.list.new_btn', '+ New Transaction')) ?>
          </a>
          <a href="<?= h(url('admin/customers/invoices.php?customer_id=' . $cid)) ?>" class="btn btn-light">
            <?= h('Invoices / Quotations') ?>
          </a>
        </div>
      </div>

      <?php if ($ok === 'alloc'): ?>
        <div class="alert-success" style="margin-top:4px;"><?= h(tt('admin.customer_txn.list.alloc_ok', 'Allocation completed.')) ?></div>
      <?php elseif ($ok === '1'): ?>
        <div class="alert-success" style="margin-top:4px;"><?= h(tt('admin.customer_txn.list.save_ok', 'Transaction saved.')) ?></div>
      <?php elseif ($ok === 'del'): ?>
        <div class="alert-success" style="margin-top:4px;"><?= h(tt('admin.customer_txn.list.delete_ok', 'Transaction deleted.')) ?></div>
      <?php endif; ?>
    </div>

    <!-- Summary 卡片 -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header" style="margin-bottom:16px;">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.customer_txn.list.summary.eyebrow', 'Summary')) ?></div>
          <div class="form-page-title" style="font-size:18px;"><?= h($summary_label) ?></div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">
        <div style="min-width:170px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.total_in', 'Total IN')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;"><?= h($baseCurrency) ?> <?= number_format($total_in_normal, 2) ?></div>
        </div>

        <div style="min-width:170px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.total_out', 'Total OUT')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;"><?= h($baseCurrency) ?> <?= number_format($total_out_normal, 2) ?></div>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.net_normal', 'Net')) ?></div>
          <?php if ($net_normal > 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;"><?= h($baseCurrency) ?> <?= number_format($net_normal, 2) ?></div>
            <div style="font-size:12px;color:#b91c1c;"><?= h(tt('admin.customers.list.net_label_we_owe', 'We owe customer')) ?></div>
          <?php elseif ($net_normal < 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;"><?= h($baseCurrency) ?> <?= number_format(abs($net_normal), 2) ?></div>
            <div style="font-size:12px;color:#166534;"><?= h(tt('admin.customers.list.net_label_cust_owe', 'Customer owes us')) ?></div>
          <?php else: ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;"><?= h($baseCurrency) ?> 0.00</div>
            <div style="font-size:12px;color:#6b7280;"><?= h(tt('admin.customers.list.net_label_balanced', 'Balanced')) ?></div>
          <?php endif; ?>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.pending', 'Pending payment')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:#0f766e;">
            <?= h($baseCurrency) ?> <?= number_format($pending_total, 2) ?>
          </div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">
        <div style="min-width:210px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.return_balance', 'Return')) ?></div>
          <?php if ($return_balance > 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;"><?= h($baseCurrency) ?> <?= number_format($return_balance, 2) ?></div>
            <div style="font-size:12px;color:#b91c1c;"><?= h(tt('admin.customer_txn.list.return_still_owing', 'Customer still has our capital (outstanding)')) ?></div>
          <?php elseif ($return_balance < 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;"><?= h($baseCurrency) ?> <?= number_format(abs($return_balance), 2) ?></div>
            <div style="font-size:12px;color:#166534;"><?= h(tt('admin.customer_txn.list.return_profit', 'Capital fully returned')) ?></div>
          <?php else: ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;"><?= h($baseCurrency) ?> 0.00</div>
            <div style="font-size:12px;color:#6b7280;"><?= h(tt('admin.customer_txn.list.return_balanced', 'Capital fully returned')) ?></div>
          <?php endif; ?>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.total_bonus', 'Total BONUS')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;"><?= h($baseCurrency) ?> <?= number_format($bonus_total, 2) ?></div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px;">
        <div style="min-width:170px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.summary_in', 'Summary total IN')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;"><?= h($baseCurrency) ?> <?= number_format($summary_in, 2) ?></div>
        </div>

        <div style="min-width:170px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.summary_out', 'Summary total OUT')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;"><?= h($baseCurrency) ?> <?= number_format($summary_out, 2) ?></div>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;"><?= h(tt('admin.customer_txn.list.summary.summary_net', 'Summary net')) ?></div>
          <?php if ($summary_net > 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;"><?= h($baseCurrency) ?> <?= number_format($summary_net, 2) ?></div>
            <div style="font-size:12px;color:#b91c1c;"><?= h(tt('admin.customers.list.net_label_we_owe', 'We owe customer')) ?></div>
          <?php elseif ($summary_net < 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;"><?= h($baseCurrency) ?> <?= number_format(abs($summary_net), 2) ?></div>
            <div style="font-size:12px;color:#166534;"><?= h(tt('admin.customers.list.net_label_cust_owe', 'Customer owes us')) ?></div>
          <?php else: ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;"><?= h($baseCurrency) ?> 0.00</div>
            <div style="font-size:12px;color:#6b7280;"><?= h(tt('admin.customers.list.net_label_balanced', 'Balanced')) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Filter 区 -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex; flex-direction:column; gap:12px; font-size:13px;">
        <input type="hidden" name="customer_id" value="<?= h($cid) ?>">

        <?php include __DIR__ . '/../../include/date_range.php'; ?>

        <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.customer_txn.list.filter.type', 'Type')) ?></label>
            <select name="type" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $type === 'ALL' ? 'selected' : '' ?>><?= h(tt('admin.common.all', 'All')) ?></option>
              <option value="IN" <?= $type === 'IN'  ? 'selected' : '' ?>><?= h(tt('admin.reports.in_only', 'IN only')) ?></option>
              <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>><?= h(tt('admin.reports.out_only', 'OUT only')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.customer_txn.field.status', 'Status')) ?></label>
            <select name="status" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>><?= h(tt('admin.common.all', 'All')) ?></option>
              <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.status.draft', 'DRAFT')) ?></option>
              <option value="SENT" <?= $status === 'SENT'  ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.status.sent', 'SENT')) ?></option>
              <option value="PENDING" <?= $status === 'PENDING' ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.status.pending', 'PENDING')) ?></option>
              <option value="CONFIRMED" <?= $status === 'CONFIRMED' ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.status.confirmed', 'CONFIRMED')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">Bank / Allocate</label>
            <select name="method" class="form-control" style="min-width:220px;">
              <option value="ALL" <?= $method === 'ALL' ? 'selected' : '' ?>><?= h(tt('admin.common.all', 'All')) ?></option>
              <?php foreach ($bankRows as $b): ?>
                <?php $val = 'BANK_' . (int)$b['id'];
                $label = bank_label($b); ?>
                <option value="<?= h($val) ?>" <?= $method === $val ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
              <option value="ALLOCATE" <?= $method === 'ALLOCATE' ? 'selected' : '' ?>>Allocate (contra only)</option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.customer_txn.list.filter.contra_view', 'Contra view')) ?></label>
            <select name="view" class="form-control" style="min-width:170px;">
              <option value="AFTER" <?= $view === 'AFTER'  ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.list.summary.after', 'After contra')) ?></option>
              <option value="BEFORE" <?= $view === 'BEFORE' ? 'selected' : '' ?>><?= h(tt('admin.customer_txn.list.summary.before', 'Without contra (not used)')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.customer_txn.list.filter.search', 'Search')) ?></label>
            <input type="text" name="q" class="form-control" style="min-width:220px;"
              value="<?= h($q) ?>" placeholder="Title / Ref / (Remark if available)">
          </div>

          <div style="margin-left:auto; display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary"><?= h(tt('admin.common.apply', 'Apply')) ?></button>
            <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $cid)) ?>" class="btn btn-light"><?= h(tt('admin.common.reset', 'Reset')) ?></a>
          </div>

        </div>
      </form>
    </div>

    <!-- Transaction 列表 -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:120px;"><?= h(tt('admin.customer_txn.field.date', 'Date')) ?></th>
            <th style="width:90px;"><?= h(tt('admin.customer_txn.field.type', 'Type')) ?></th>
            <th style="width:130px;"><?= h(tt('admin.customer_txn.field.method', 'Method')) ?></th>
            <th><?= h(tt('admin.customer_txn.field.title', 'Title')) ?></th>
            <th style="text-align:right;width:140px;"><?= h(tt('admin.customer_txn.field.amount', 'Amount')) ?></th>
            <th style="text-align:right;width:140px;"><?= h(tt('admin.customer_txn.list.pending', 'Pending')) ?></th>
            <th style="width:90px;"><?= h(tt('admin.customer_txn.field.status', 'Status')) ?></th>
            <th style="width:60px;" class="table-actions-cell"><?= h(tt('admin.common.actions', 'Actions')) ?></th>
          </tr>
        </thead>

        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="8" style="padding:16px; color:#6b7280; font-size:13px;">
                <?= h(tt('admin.customer_txn.list.empty', 'No transactions for this filter. Try another date range.')) ?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php $isSummaryContra = !empty($r['_is_summary_contra']); ?>

              <?php if ($isSummaryContra): ?>
                <?php
                $displayDate     = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);
                $displayCurrency = $r['currency'] ?? ($customer['currency'] ?? 'MYR');
                $displayAmount   = (float)($r['amount'] ?? 0);

                $pcId    = (int)($r['payer_company_id'] ?? 0);
                $pcLabel = '';
                if ($pcId && isset($payerCompaniesMap[$pcId])) {
                  $pc = $payerCompaniesMap[$pcId];
                  $pcLabel = (string)($pc['name'] ?? '');
                  if (!empty($pc['reg_no'])) $pcLabel .= ' · ' . $pc['reg_no'];
                }
                ?>
                <tr>
                  <td><?= h($displayDate) ?></td>
                  <td><span style="font-size:12px;font-weight:700;color:#6b7280;"><?= h(tt('admin.customer_txn.type.contra_summary', 'CONTRA')) ?></span></td>
                  <td>
                    <div style="font-size:13px;font-weight:500;"><?= h(tt('admin.customer_txn.method.other', 'Other')) ?></div>
                  </td>
                  <td>
                    <div style="font-size:13px;font-weight:500;"><?= h((string)($r['title'] ?? '')) ?></div>
                    <?php if ($pcLabel): ?>
                      <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                        <?= h(tt('admin.customer_txn.list.contra_summary_company', 'Contra to company:')) ?> <?= ' ' . h($pcLabel) ?>
                      </div>
                    <?php endif; ?>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                      <?= h(tt('admin.customer_txn.list.contra_summary_desc', 'Total amount allocated (contra) for this date.')) ?>
                    </div>
                  </td>
                  <td style="text-align:right;"><?= h($displayCurrency) ?> <?= number_format($displayAmount, 2) ?></td>
                  <td style="text-align:right;">–</td>
                  <td>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#ecfdf5; color:#166534;">
                      <?= h(tt('admin.customer_txn.status.confirmed', 'CONFIRMED')) ?>
                    </span>
                  </td>
                  <td class="table-actions-cell">–</td>
                </tr>
                <?php continue; ?>
              <?php endif; ?>

              <?php
              // ✅ 关键：统一正规化 txn_type（修复 "IN " / "in" 导致 receipt 不出现）
              $tType = strtoupper(trim((string)($r['txn_type'] ?? '')));

              $isContra  = (int)($r['is_contra'] ?? 0) === 1;
              $tid       = (int)($r['id'] ?? 0);

              $inKind  = detect_in_kind($r);
              $outKind = strtoupper(trim((string)($r['out_kind'] ?? 'NORMAL')));

              $txnCurrency = (string)($r['currency'] ?? ($customer['currency'] ?? 'MYR'));

              $invoiceTotal = 0.0;
              if ($tType === 'IN' && $inKind === 'INVOICE') {
                $invoiceTotal = (float)($r['order_total'] ?? 0);

                // ✅ allocate 生成的 IN 通常没有 order_total，会是 0
                // 这时候用 amount 当作显示与判定用的 total
                if ($invoiceTotal <= 0.000001) {
                  $invoiceTotal = (float)($r['amount'] ?? 0.0);
                }
              }

              // ✅ Amount display
              if ($isContra) {
                // contra / allocate：永远用 amount（不看 order_total）
                $displayAmount   = (float)($r['amount'] ?? 0);
                $displayCurrency = $txnCurrency;
              } else {
                if ($tType === 'IN') {
                  if ($inKind === 'INVOICE') {
                    $displayAmount   = $invoiceTotal;
                    $displayCurrency = $txnCurrency;
                  } else {
                    $displayAmount   = (float)($r['amount'] ?? 0);
                    $displayCurrency = $txnCurrency;
                  }
                } else {
                  $displayAmount   = (float)($r['amount'] ?? 0);
                  $displayCurrency = $txnCurrency;
                }
              }

              // ✅ paid_raw
              $paid_raw = 0.0;
              if ($tType === 'IN') {
                $paid_raw = (float)($paidRawByTxn[$tid] ?? 0.0);
                if ($paid_raw <= 0.000001) $paid_raw = (float)($r['amount'] ?? 0.0);
              } else {
                $paid_raw = (float)($paidRawByTxn[$tid] ?? 0.0);
              }

              // ✅ Pending
              $unpaid = 0.0;
              if ($tType === 'IN' && !$isContra) {
                if ($inKind === 'INVOICE') {
                  $unpaid = max(0.0, $invoiceTotal - $paid_raw);
                  if (strtoupper(trim((string)($r['status'] ?? ''))) === 'CONFIRMED') $unpaid = 0.0;
                } elseif (in_array($inKind, ['RETURN', 'BONUS'], true)) {
                  $target = (float)($r['amount'] ?? 0.0);
                  $unpaid = max(0.0, $target - $paid_raw);
                  if (strtoupper(trim((string)($r['status'] ?? ''))) === 'CONFIRMED') $unpaid = 0.0;
                }
              }

              // Allocate（只允许 INVOICE）
              $paid_myr      = (float)($r['amount'] ?? 0);
              $allocated_myr = (float)($r['allocated_amount'] ?? 0);
              $alloc_avail   = max(0, $paid_myr - $allocated_myr);

              $canAlloc = (
                $tType === 'IN'
                && $inKind === 'INVOICE'
                && !$isContra
                && $alloc_avail > 0.0001
              );

              $uiInvoiceLabel = ($canAlloc ? 'Allocate' : 'Invoice');
              // OUT payer info
              $payerCompanyLabel = '';
              $payerStaffLabel   = '';

              if ($tType === 'OUT') {
                $pcId = (int)($r['payer_company_id'] ?? 0);
                $psId = (int)($r['payer_staff_id'] ?? 0);

                if ($pcId && isset($payerCompaniesMap[$pcId])) {
                  $pc = $payerCompaniesMap[$pcId];
                  $payerCompanyLabel = (string)($pc['name'] ?? '');
                  if (!empty($pc['reg_no'])) $payerCompanyLabel .= ' · ' . $pc['reg_no'];
                }
                if ($psId && isset($payerStaffMap[$psId])) {
                  $ps = $payerStaffMap[$psId];
                  $payerStaffLabel = (string)($ps['staff_name'] ?? '');
                  if (!empty($ps['ic_no'])) $payerStaffLabel .= ' · ' . $ps['ic_no'];
                }
              }

              // Method label
              $methodLabel = '-';
              $bankLabels  = [];

              if ($tType === 'IN') {
                if (!empty($bankIdsByTxn[$tid])) {
                  foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                    if (isset($bankAccMap[$bid])) $bankLabels[] = bank_label($bankAccMap[$bid]);
                  }
                } else {
                  $m = strtoupper((string)($r['method'] ?? ($r['pay_source_type'] ?? '')));
                  if ($m !== '') $methodLabel = $m;
                }
              } else {
                $outBankId = (int)($r['bank_account_id'] ?? 0);
                if ($outBankId > 0 && isset($bankAccMap[$outBankId])) $bankLabels[] = bank_label($bankAccMap[$outBankId]);
              }

              $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
              if ($tType === 'OUT' && strtoupper((string)($r['pay_source_type'] ?? '')) === 'CUSTOMER') {
                $inMethod = strtoupper(trim((string)($r['pay_source_method'] ?? 'OTHER')));
                if ($inMethod === '') $inMethod = 'OTHER';
                $inBankId = (int)($r['pay_source_bank_account_id'] ?? 0);
                $inBank = ($inBankId > 0 && isset($bankAccMap[$inBankId])) ? bank_label($bankAccMap[$inBankId]) : '';
                $outMethod = strtoupper(trim((string)($r['method'] ?? 'CASH'))) ?: 'CASH';
                $methodLabel = 'IN: ' . $inMethod . ($inBank !== '' ? ' -> ' . $inBank : '');
                $methodLabel .= ' / OUT: ' . $outMethod . ($bankLabels ? ' -> ' . implode(' / ', $bankLabels) : '');
              } elseif ($bankLabels) {
                $methodLabel = implode(' / ', $bankLabels);
              }

              $displayDate = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);

              // Type labels
              $typeMain = '';
              $typeSub  = '';

              if ($tType === 'IN') {
                $typeMain = tt('admin.customer_txn.type.in', 'IN');

                if ($inKind === 'RETURN') {
                  $typeSub = display_in_kind_label($r);
                } elseif ($inKind === 'BONUS') {
                  $typeSub = display_in_kind_label($r);
                } elseif ($inKind === 'INVOICE') {
                  $typeSub = $canAlloc ? tt('admin.customer_txn.type.allocate', 'Allocate') : 'Invoice';
                } else {
                  $typeSub = $inKind ?: '';
                }
              } else {
                $typeMain = tt('admin.customer_txn.type.out', 'OUT');
                if ($outKind === 'LOAN') $typeSub = 'Loan';
              }

              $titleText = (string)($r['title'] ?? '');
              if ($titleText === '') {
                if ($tType === 'IN' && $inKind === 'RETURN') $titleText = display_in_kind_label($r);
                elseif ($tType === 'IN' && $inKind === 'BONUS') $titleText = display_in_kind_label($r);
                elseif ($tType === 'IN' && $inKind === 'INVOICE') $titleText = $uiInvoiceLabel;
                else $titleText = '-';
              }
              // ✅ If user saved title as "Invoice", still show "Allocate" when canAlloc
              if ($canAlloc && $tType === 'IN' && $inKind === 'INVOICE') {
                $tLower = strtolower(trim($titleText));
                if ($tLower === '' || $tLower === 'invoice') {
                  $titleText = $uiInvoiceLabel; // "Allocate"
                }
              }
              // 列表页标题统一格式（仅 INVOICE 类 IN 单据）
              if ($tType === 'IN' && $inKind === 'INVOICE') {
                $invoiceNoR = trim((string)($r['invoice_no'] ?? ''));
                $titleAmt = (float)($r['order_total'] ?? 0);
                if ($titleAmt <= 0) $titleAmt = (float)($r['amount'] ?? 0);
                $curCode = strtoupper(trim((string)$displayCurrency));
                $moneyPrefix = ($curCode === 'MYR') ? 'RM ' : (($curCode !== '') ? ($curCode . ' ') : '');
                $moneyText = $moneyPrefix . number_format($titleAmt, 2);
                if ($invoiceNoR === '') {
                  $titleText = "QUOTATION '" . (string)($customer['name'] ?? '') . "' " . $moneyText;
                } else {
                  $titleText = "INVOICE - " . $invoiceNoR . " - " . (string)($customer['name'] ?? '') . " - " . $moneyText;
                }
              }
              // ✅ 状态显示层修正（勾签名但没签完 => 强制 PENDING）
              $displayStatus = strtoupper(trim((string)($r['status'] ?? 'PENDING')));

              // ✅ Quotation 被 Reject：优先显示 REJECTED（不要被签名/付款逻辑覆盖成 PENDING）
              $docFlowStatus = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
              if ($docFlowStatus === 'REJECTED') {
                $displayStatus = 'REJECTED';
              }

              // ✅ 只有当 DB 不是 CONFIRMED 时，才用签名/付款逻辑去推导显示
              if ($displayStatus !== 'CONFIRMED' && $displayStatus !== 'REJECTED' && $tType === 'IN' && !$isContra) {

                $needRecv = !empty($r['sign_receive']); // 我方签
                $needPay  = !empty($r['sign_payer']);   // 客户签

                $sigOk = true;

                if ($needRecv || $needPay) {
                  $payerDone = false;
                  $recvDone  = false;

                  if (isset($lastPaySigByTxn[$tid])) {
                    $last = $lastPaySigByTxn[$tid];
                    $payerDone = !empty($last['payer_signature_image']) || !empty($last['payer_signed_at']);
                    $recvDone  = !empty($last['receiver_signature_image']) || !empty($last['receiver_signed_at']);
                  }

                  if ($needPay && !$payerDone) $sigOk = false;
                  if ($needRecv && !$recvDone) $sigOk = false;
                }

                $paidEnough = true;
                if ($inKind === 'INVOICE') {
                  $paidEnough = ($invoiceTotal > 0 && ($invoiceTotal - $paid_raw) <= 0.0001);
                } elseif (in_array($inKind, ['RETURN', 'BONUS'], true)) {
                  $target = (float)($r['amount'] ?? 0.0);
                  $paidEnough = ($target > 0 && ($target - $paid_raw) <= 0.0001);
                }

                if (!$sigOk) $displayStatus = 'PENDING';
                else $displayStatus = $paidEnough ? 'CONFIRMED' : 'PENDING';
              }
              ?>

              <tr>
                <td><?= h($displayDate) ?></td>

                <td>
                  <div style="display:flex;flex-direction:column;gap:2px;">
                    <?php if ($tType === 'IN'): ?>
                      <span style="font-size:12px;font-weight:700;color:#166534;"><?= h($typeMain) ?></span>
                    <?php else: ?>
                      <span style="font-size:12px;font-weight:700;color:#b91c1c;"><?= h($typeMain) ?></span>
                    <?php endif; ?>

                    <?php if ($typeSub !== ''): ?>
                      <span style="font-size:10px;color:#6b7280;"><?= h($typeSub) ?></span>
                    <?php endif; ?>

                    <?php if ($isContra): ?>
                      <span style="font-size:10px;color:#6b7280;"><?= h(tt('admin.customer_txn.badge.contra', 'Contra')) ?></span>
                    <?php endif; ?>
                  </div>
                </td>

                <td>
                  <div style="font-size:13px;font-weight:500;"><?= h($methodLabel) ?></div>
                </td>

                <td>
                  <div style="font-size:13px;font-weight:500;"><?= h($titleText) ?></div>

                  <?php if (!empty($r['ref_no'])): ?>
                    <div style="font-size:11px;color:#6b7280;">
                      <?= h(tt('admin.customer_txn.field.ref_no', 'Reference no.')) ?>: <?= h((string)$r['ref_no']) ?>
                    </div>
                  <?php endif; ?>

                  <?php if ($tType === 'IN'): ?>
                    <div style="font-size:11px;color:#0f766e;margin-top:2px;">
                      <?= h(tt('admin.customer_txn.list.paid_label', 'Paid by payer:')) ?>
                      <?= ' ' . h($displayCurrency) . ' ' . number_format($paid_raw, 2) ?>
                    </div>

                    <?php if ($inKind === 'INVOICE' && $alloc_avail > 0.0001): ?>
                      <div style="font-size:11px;color:#0369a1;margin-top:2px;">
                        <?= h(tt('admin.customer_txn.list.alloc_avail_label', 'Available to allocate (paid, MYR):')) ?>
                        <?= ' MYR ' . number_format($alloc_avail, 2) ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($tType === 'OUT' && ($payerCompanyLabel || $payerStaffLabel)): ?>
                    <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                      <?php if ($payerCompanyLabel): ?>
                        <div><?= h(tt('admin.customer_txn.list.payer_label', 'Payer:')) ?> <?= ' ' . h($payerCompanyLabel) ?></div>
                      <?php endif; ?>
                      <?php if ($payerStaffLabel): ?>
                        <div><?= h(tt('admin.customer_txn.list.staff_label', 'Staff:')) ?> <?= ' ' . h($payerStaffLabel) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>

                <td style="text-align:right;"><?= h($displayCurrency) ?> <?= number_format((float)$displayAmount, 2) ?></td>

                <td style="text-align:right;">
                  <?php if ($displayStatus !== 'REJECTED' && $tType === 'IN' && !$isContra && $unpaid > 0.0001): ?>
                    <?= h($displayCurrency) ?> <?= number_format($unpaid, 2) ?>
                  <?php else: ?>
                    –
                  <?php endif; ?>
                </td>

                <td>
                  <?php if ($displayStatus === 'CONFIRMED'): ?>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#ecfdf5; color:#166534;"><?= h(tt('admin.customer_txn.status.confirmed', 'CONFIRMED')) ?></span>
                  <?php elseif ($displayStatus === 'REJECTED'): ?>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#fee2e2; color:#b91c1c;"><?= h(tt('admin.customer_txn.status.rejected', 'REJECTED')) ?></span>
                  <?php elseif ($displayStatus === 'PENDING'): ?>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#dbeafe; color:#1d4ed8;"><?= h(tt('admin.customer_txn.status.pending', 'PENDING')) ?></span>
                  <?php elseif ($displayStatus === 'SENT'): ?>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#fef9c3; color:#854d0e;"><?= h(tt('admin.customer_txn.status.sent', 'SENT')) ?></span>
                  <?php else: ?>
                    <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#e5e7eb; color:#374151;"><?= h(tt('admin.customer_txn.status.draft', 'DRAFT')) ?></span>
                  <?php endif; ?>
                </td>

                <td class="table-actions-cell">
                  <?php
                    $tTypeRow = strtoupper(trim((string)($r['txn_type'] ?? '')));
                    $ikRow    = detect_in_kind($r); // INVOICE / BONUS / RETURN
                    $isInInvoice = ($tTypeRow === 'IN' && in_kind_has_part((string)($r['in_kind'] ?? ''), 'INVOICE'));
                    $rowId = (int)$r['id'];
                    $viewUrl = url('admin/customers/txn_view.php?id=' . $rowId . '&customer_id=' . $cid);
                    $editUrl = url('admin/customers/txn_edit.php?id=' . $rowId . '&customer_id=' . $cid);
                    $editInUrl = url('admin/customers/txn_edit_in.php?id=' . $rowId . '&customer_id=' . $cid);
                    $quotationUrl = url('admin/customers/txn_doc_in.php?id=' . $rowId . '&customer_id=' . $cid . '&doc=QUOTATION');
                    $invoiceUrl = url('admin/customers/txn_doc_in.php?id=' . $rowId . '&customer_id=' . $cid . '&doc=INVOICE');
                    $doUrl = url('admin/customers/txn_doc_in.php?id=' . $rowId . '&customer_id=' . $cid . '&doc=DO');
                    $receiptInUrl = url('admin/customers/txn_receipt_in.php?id=' . $rowId . '&customer_id=' . $cid);
                  ?>
                  <div class="actions-menu">
                    <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>

                    <div class="actions-menu-dropdown">
                      <!-- View: 直接进 QUOTATION 版单据（admin txn_doc_in，会根据 flow 决定能否看 INVOICE/DO） -->
                      <a href="<?= h($viewUrl) ?>" class="actions-menu-item">
                        <?= h(tt('admin.customer_txn.list.action_view', 'View receipt / sign')) ?>
                      </a>

                      <?php if ($isInInvoice): ?>
                        <a href="<?= h($quotationUrl) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_quotation', 'Quotation')) ?>
                        </a>
                        <a href="<?= h($invoiceUrl) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_invoice', 'Invoice')) ?>
                        </a>
                        <a href="<?= h($doUrl) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_do', 'DO')) ?>
                        </a>
                        <a href="<?= h($receiptInUrl) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_receipt_in', 'IN Receipt')) ?>
                        </a>
                      <?php endif; ?>

                      <!-- 普通 Edit：非 IN-INVOICE 才需要；IN-INVOICE 用下面专用的 Edit IN -->
                      <?php if (!$isInInvoice): ?>
                      <a href="<?= h($editUrl) ?>" class="actions-menu-item">
                        <?= h(tt('admin.common.edit', 'Edit')) ?>
                      </a>
                      <?php endif; ?>

                      <!-- ✅ 只要是 IN invoice 都给 IN Receipt / Invoice 编辑入口 -->
                      <?php if ($isInInvoice): ?>
                        <a href="<?= h($editInUrl) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_invoice_edit', 'View / edit IN invoice')) ?>
                        </a>
                      <?php endif; ?>


                      <?php if ($canAlloc): ?>
                        <a href="<?= h(url('admin/customers/txn_allocate.php?customer_id=' . $cid . '&source_id=' . (int)$r['id'])) ?>" class="actions-menu-item">
                          <?= h(tt('admin.customer_txn.list.action_allocate', 'Allocate')) ?>
                        </a>
                      <?php endif; ?>

                      <?php if ($canDelete): ?>
                        <a href="<?= h(url('admin/customers/txn_delete.php?id=' . (int)$r['id'] . '&customer_id=' . $cid)) ?>"
                          class="actions-menu-item"
                          onclick="return confirm('Delete this transaction?');">
                          <?= h(tt('admin.common.delete', 'Delete')) ?>
                        </a>
                      <?php endif; ?>
                    </div>
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
