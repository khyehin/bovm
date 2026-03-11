<?php
// public/admin/dashboard/index.php
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

function fmt_rm(float $v): string
{
  $sign = $v < 0 ? '-' : '';
  return $sign . 'RM ' . number_format(abs($v), 2);
}

// ---- 本月范围 ----
$today      = date('Y-m-d');
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-t');

// ===============================
// A) CUSTOMER TXN SUMMARY (after contra) - 本月
// ===============================
$st = $pdo->prepare("
  SELECT
    SUM(CASE
          WHEN txn_type = 'IN'
           AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
           AND UPPER(COALESCE(in_kind,'')) NOT IN ('BONUS','RETURN')
          THEN (amount - COALESCE(allocated_amount,0))
          ELSE 0
        END) AS total_in_normal,

    SUM(CASE
          WHEN txn_type = 'OUT'
           AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
           AND (is_contra IS NULL OR is_contra = 0)
           AND UPPER(COALESCE(out_kind,'')) <> 'LOAN'
          THEN amount
          ELSE 0
        END) AS total_out_normal,

    SUM(CASE
          WHEN txn_type = 'IN'
           AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
           AND UPPER(COALESCE(in_kind,'')) = 'BONUS'
          THEN (amount - COALESCE(allocated_amount,0))
          ELSE 0
        END) AS bonus_total,

    SUM(CASE
          WHEN txn_type = 'IN'
           AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
           AND (
                UPPER(COALESCE(in_kind,'')) = 'RETURN'
                OR (
                     (COALESCE(order_total,0) = 0)
                     AND (amount > 0)
                     AND (title LIKE '%Repayment%' OR title LIKE '%repayment%')
                   )
              )
          THEN (amount - COALESCE(allocated_amount,0))
          ELSE 0
        END) AS repay_total,

    SUM(CASE
          WHEN txn_type = 'OUT'
           AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
           AND (is_contra IS NULL OR is_contra = 0)
           AND UPPER(COALESCE(out_kind,'')) = 'LOAN'
          THEN amount
          ELSE 0
        END) AS loan_total
  FROM customer_txn
");
$st->execute([':d1' => $monthStart, ':d2' => $monthEnd]);
$sumMonth = $st->fetch() ?: [];

$total_in_month  = (float)($sumMonth['total_in_normal'] ?? 0);
$total_out_month = (float)($sumMonth['total_out_normal'] ?? 0);

// Pending（本月 invoice 未付）
$pending_month = 0.0;
try {
  $st = $pdo->prepare("
    SELECT
      t.id,
      COALESCE(t.order_total,0) AS order_total,
      COALESCE(SUM(p.amount),0) AS paid_total
    FROM customer_txn t
    LEFT JOIN customer_txn_payments p ON p.customer_txn_id = t.id
    WHERE t.txn_type = 'IN'
      AND (t.is_contra IS NULL OR t.is_contra = 0)
      AND (t.status IS NULL OR t.status <> 'CONFIRMED')
      AND (UPPER(COALESCE(t.in_kind,'')) = '' OR UPPER(COALESCE(t.in_kind,'')) = 'INVOICE')
      AND DATE(COALESCE(t.txn_date, t.created_at)) BETWEEN :d1 AND :d2
    GROUP BY t.id
  ");
  $st->execute([':d1' => $monthStart, ':d2' => $monthEnd]);
  while ($r = $st->fetch()) {
    $order  = (float)($r['order_total'] ?? 0);
    $paid   = (float)($r['paid_total'] ?? 0);
    $unpaid = $order - $paid;
    if ($unpaid > 0.0001) $pending_month += $unpaid;
  }
} catch (Throwable $e) {
  $pending_month = 0.0;
}

// ===============================
// B) RETURN & BONUS (ALL TIME)  Return balance = Loan - Repayment
// ===============================
$return_balance_all = 0.0;
$bonus_all = 0.0;

try {
  $st = $pdo->query("
    SELECT
      SUM(CASE
            WHEN txn_type = 'OUT'
             AND (is_contra IS NULL OR is_contra = 0)
             AND UPPER(COALESCE(out_kind,'')) = 'LOAN'
            THEN amount
            ELSE 0
          END) AS loan_total,

      SUM(CASE
            WHEN txn_type = 'IN'
             AND (
                  UPPER(COALESCE(in_kind,'')) = 'RETURN'
                  OR (
                       (COALESCE(order_total,0) = 0)
                       AND (amount > 0)
                       AND (title LIKE '%Repayment%' OR title LIKE '%repayment%')
                     )
                )
            THEN (amount - COALESCE(allocated_amount,0))
            ELSE 0
          END) AS repay_total,

      SUM(CASE
            WHEN txn_type = 'IN'
             AND UPPER(COALESCE(in_kind,'')) = 'BONUS'
            THEN (amount - COALESCE(allocated_amount,0))
            ELSE 0
          END) AS bonus_total
    FROM customer_txn
  ");
  $r = $st->fetch() ?: [];
  $loan_total_all  = (float)($r['loan_total'] ?? 0);
  $repay_total_all = (float)($r['repay_total'] ?? 0);
  $bonus_all       = (float)($r['bonus_total'] ?? 0);
  $return_balance_all = $loan_total_all - $repay_total_all;
} catch (Throwable $e) {
  $return_balance_all = 0.0;
  $bonus_all = 0.0;
}

// ===============================
// C) BANK TOTAL CURRENT BALANCE + small lines
// ===============================
$bankTotalMyr = 0.0;
$bankLines = [];

try {
  $st = $pdo->query("
    SELECT
      a.id,
      a.bank_name,
      a.bank_code,
      a.account_name,
      a.account_no,
      a.currency,
      COALESCE(SUM(
        CASE WHEN t.txn_type='IN' THEN t.amount_myr ELSE -t.amount_myr END
      ),0) AS current_myr
    FROM company_bank_accounts a
    LEFT JOIN company_bank_txn t ON t.bank_id = a.id
    WHERE a.is_active = 1
    GROUP BY a.id
    ORDER BY a.sort_order ASC, a.bank_name ASC, a.id ASC
  ");
  $bankLines = $st->fetchAll() ?: [];
  foreach ($bankLines as $b) $bankTotalMyr += (float)($b['current_myr'] ?? 0);
} catch (Throwable $e) {
  $bankLines = [];
  $bankTotalMyr = 0.0;
}

// ===============================
// D) Pending signature count
// ===============================
$pendingSigCount = 0;
try {
  $st = $pdo->query("
    SELECT COUNT(*) AS c
    FROM customer_txn
    WHERE status IN ('DRAFT','PENDING')
      AND (
           (COALESCE(sig_customer,'') = '')
        OR (customer_signed_at IS NULL)
      )
  ");
  $pendingSigCount = (int)($st->fetchColumn() ?? 0);
} catch (Throwable $e) {
  try {
    $st = $pdo->query("
      SELECT COUNT(*) AS c
      FROM customer_txn
      WHERE status IN ('DRAFT','PENDING')
    ");
    $pendingSigCount = (int)($st->fetchColumn() ?? 0);
  } catch (Throwable $e2) {
    $pendingSigCount = 0;
  }
}

// ===============================
// E) Latest bank IN/OUT
// ===============================
$recentIn = [];
$recentOut = [];
try {
  $recentIn = $pdo->query("
    SELECT
      t.*,
      b.bank_code,
      b.account_name,
      b.account_no,
      b.currency AS bank_currency
    FROM company_bank_txn t
    LEFT JOIN company_bank_accounts b ON b.id = t.bank_id
    WHERE t.txn_type = 'IN'
    ORDER BY t.txn_date DESC, t.id DESC
    LIMIT 8
  ")->fetchAll();

  $recentOut = $pdo->query("
    SELECT
      t.*,
      b.bank_code,
      b.account_name,
      b.account_no,
      b.currency AS bank_currency
    FROM company_bank_txn t
    LEFT JOIN company_bank_accounts b ON b.id = t.bank_id
    WHERE t.txn_type = 'OUT'
    ORDER BY t.txn_date DESC, t.id DESC
    LIMIT 8
  ")->fetchAll();
} catch (Throwable $e) {
  $recentIn = [];
  $recentOut = [];
}

// ===============================
// F) Charts
// ===============================
$pieIn  = max(0.0, $total_in_month);
$pieOut = max(0.0, $total_out_month);

$barLabels = [];
$barIn = [];
$barOut = [];

try {
  for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime(date('Y-m-01') . " -$i months");
    $barLabels[] = date('Y-m', $ts);
  }

  foreach ($barLabels as $ym) {
    $d1 = $ym . '-01';
    $d2 = date('Y-m-t', strtotime($d1));

    $st = $pdo->prepare("
      SELECT
        SUM(CASE
              WHEN txn_type='IN'
               AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
               AND UPPER(COALESCE(in_kind,'')) NOT IN ('BONUS','RETURN')
              THEN (amount - COALESCE(allocated_amount,0))
              ELSE 0
            END) AS in_normal,
        SUM(CASE
              WHEN txn_type='OUT'
               AND DATE(COALESCE(txn_date, created_at)) BETWEEN :d1 AND :d2
               AND (is_contra IS NULL OR is_contra=0)
               AND UPPER(COALESCE(out_kind,'')) <> 'LOAN'
              THEN amount
              ELSE 0
            END) AS out_normal
      FROM customer_txn
    ");
    $st->execute([':d1' => $d1, ':d2' => $d2]);
    $r = $st->fetch() ?: [];
    $barIn[]  = (float)($r['in_normal'] ?? 0);
    $barOut[] = (float)($r['out_normal'] ?? 0);
  }
} catch (Throwable $e) {
  $barLabels = [];
  $barIn = [];
  $barOut = [];
}

// ---- audit ----
if (function_exists('audit_log')) {
  audit_log(
    $pdo,
    'APP.DASH.VIEW',
    [
      'description' => 'View admin dashboard',
      'month_start' => $monthStart,
      'month_end'   => $monthEnd,
    ],
    'dashboard',
    null
  );
}

$page_title = function_exists('t')
  ? t('admin.dashboard.title', [], 'Dashboard')
  : 'Dashboard';

include __DIR__ . '/../include/header.php';
?>

<style>
  .dashboard-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-top: 6px;
  }

  .pending-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid #fecaca;
    background: #fff1f2;
    font-size: 12px;
    color: #991b1b;
    font-weight: 700;
  }

  .dashboard-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 10px;
  }

  @media (max-width: 1024px) {
    .dashboard-cards {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  .dashboard-card-metric {
    padding: 14px 16px;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    background: #ffffff;
  }

  .k {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #6b7280;
    margin-bottom: 4px;
  }

  .v {
    font-size: 20px;
    font-weight: 800;
    margin-bottom: 2px;
    color: #111827;
  }

  .sub {
    font-size: 12px;
    color: #6b7280;
  }

  .v-green {
    color: #166534;
  }

  .v-red {
    color: #b91c1c;
  }

  .v-gray {
    color: #111827;
  }

  .bank-total {
    font-size: 16px;
    font-weight: 800;
    margin-top: 2px;
  }

  .bank-mini-list {
    margin-top: 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .bank-line {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: baseline;
    font-size: 12px;
    color: #374151;
    padding: 2px 0;
  }

  .bank-line .left {
    min-width: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .bank-line .right {
    font-weight: 800;
    white-space: nowrap;
  }

  .dashboard-split {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
  }

  @media (max-width: 1024px) {
    .dashboard-split {
      grid-template-columns: minmax(0, 1fr);
    }
  }

  /* charts */
  .chart-wrap {
    display: flex;
    flex-wrap: wrap;
    /* ✅ 不够宽就自动换行 */
    gap: 14px;
    align-items: flex-start;
    justify-content: flex-start;
    /* ✅ 不要 space-between */
    margin-top: 6px;
    min-width: 0;
  }

  .chart-canvas {
    flex: 0 0 220px;
    /* 固定 220 */
    width: 220px;
    max-width: 100%;
  }

  .chart-side {
    flex: 1 1 260px;
    /* ✅ 右边这块可以缩，也可以换行 */
    min-width: 0;
    font-size: 12px;
    color: #374151;
  }

  /* ✅ 当卡片变窄：右边整块掉去下一行（占满宽度） */
  @media (max-width: 900px) {
    .chart-side {
      flex-basis: 100%;
    }
  }

  /* row 不变 */
  .chart-side .row {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 6px 0;
    border-top: 1px solid #f1f5f9;
    min-width: 0;
  }

  .chart-side .row:first-child {
    border-top: 0;
  }

  .chart-side .label {
    color: #6b7280;
    white-space: nowrap;
  }

  .chart-side .num {
    font-weight: 800;
    white-space: nowrap;
  }


  @media (max-width: 640px) {
    .chart-wrap {
      flex-direction: column;
      align-items: stretch;
    }

    .chart-canvas {
      width: 220px;
      max-width: 100%;
    }

    .chart-side .label {
      white-space: normal;
    }
  }

  /* ✅ table 小屏：横向滚动，不跑位 */
  .table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }

  .table-wrap .table {
    min-width: 720px;
    /* 桌面正常；小屏可滚 */
  }

  @media (max-width: 640px) {
    .table-wrap .table {
      min-width: 680px;
    }
  }

  /* ✅ 让长内容不要把整行撑爆 */
  .td-bank,
  .td-desc {
    white-space: normal;
    word-break: break-word;
  }

  .td-date,
  .td-amt,
  .td-ref {
    white-space: nowrap;
  }

  /* 可选：表格内容垂直对齐更好看 */
  .table th,
  .table td {
    vertical-align: top;
  }
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- Header -->
    <div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.eyebrow', [], 'Overview')) ?></div>
          <h1 class="page-title"><?= h($page_title) ?></h1>

          <div class="dashboard-topline">
            <div class="pending-chip">
              <?= h(tt('admin.dashboard.pending_sig', [], 'Pending signature')) ?>:
              <span><?= (int)$pendingSigCount ?></span>
            </div>
          </div>
        </div>
      </div>

      <div class="dashboard-cards">

        <!-- LEFT -->
        <div class="dashboard-card-metric">
          <div class="k"><?= h(tt('admin.dashboard.this_month', [], 'This month')) ?></div>

          <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <div style="min-width:160px;">
              <div class="k"><?= h(tt('admin.dashboard.total_in', [], 'Total IN')) ?></div>
              <div class="v v-green"><?= fmt_rm($total_in_month) ?></div>
            </div>

            <div style="min-width:160px;">
              <div class="k"><?= h(tt('admin.dashboard.total_out', [], 'Total OUT')) ?></div>
              <div class="v v-red"><?= fmt_rm($total_out_month) ?></div>
            </div>

            <div style="min-width:160px;">
              <div class="k"><?= h(tt('admin.dashboard.pending', [], 'Pending')) ?></div>
              <div class="v v-red"><?= fmt_rm($pending_month) ?></div>
            </div>
          </div>

          <div class="sub" style="margin-top:6px;">
            <?= h($monthStart) ?> – <?= h($monthEnd) ?>
          </div>
        </div>

        <!-- MIDDLE -->
        <div class="dashboard-card-metric">
          <div class="k"><?= h(tt('admin.dashboard.all_time', [], 'All time')) ?></div>

          <?php $returnColor = ($return_balance_all > 0) ? '#b91c1c' : '#166534'; ?>

          <div style="display:flex;gap:14px;flex-wrap:wrap;">
            <div style="min-width:180px;">
              <div class="k"><?= h(tt('admin.dashboard.return_balance', [], 'Return balance')) ?></div>
              <div class="v" style="color:<?= $returnColor ?>;"><?= fmt_rm($return_balance_all) ?></div>
            </div>

            <div style="min-width:180px;">
              <div class="k"><?= h(tt('admin.dashboard.bonus', [], 'Bonus')) ?></div>
              <div class="v v-gray"><?= fmt_rm($bonus_all) ?></div>
            </div>
          </div>
        </div>

        <!-- RIGHT -->
        <div class="dashboard-card-metric">
          <div class="k"><?= h(tt('admin.dashboard.bank_total', [], 'Bank total current balance')) ?></div>

          <div class="bank-total" style="color:<?= $bankTotalMyr >= 0 ? '#166534' : '#b91c1c' ?>;">
            <?= h(fmt_rm($bankTotalMyr)) ?>
          </div>

          <?php if (!$bankLines): ?>
            <div style="margin-top:8px;font-size:12px;color:#6b7280;">
              <?= h(tt('admin.dashboard.no_bank', [], 'No active bank accounts.')) ?>
            </div>
          <?php else: ?>
            <div class="bank-mini-list">
              <?php foreach ($bankLines as $b): ?>
                <?php
                $bid  = (int)($b['id'] ?? 0);
                $name = trim((string)($b['bank_name'] ?? ''));
                $code = trim((string)($b['bank_code'] ?? ''));
                $acc  = trim((string)($b['account_name'] ?? ''));
                if ($name === '') $name = '#' . $bid;
                $bal = (float)($b['current_myr'] ?? 0);

                $left = ($code !== '' ? $code . ' · ' : '') . $name;
                if ($acc !== '') $left .= ' · ' . $acc;
                ?>
                <div class="bank-line">
                  <div class="left"><?= h($left) ?></div>
                  <div class="right" style="color:<?= $bal >= 0 ? '#166534' : '#b91c1c' ?>;">
                    <?= h(fmt_rm($bal)) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- Charts -->
    <div class="dashboard-split" style="margin-bottom:18px;">

      <!-- Pie card -->
      <div class="admin-card">
        <div class="admin-card-header">
          <div>
            <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.charts', [], 'Charts')) ?></div>
            <h2 class="page-title" style="font-size:16px;"><?= h(tt('admin.dashboard.pie_title', [], 'This month IN vs OUT')) ?></h2>
          </div>
        </div>

        <?php
        $pieTotal = $pieIn + $pieOut;
        $pctIn  = $pieTotal > 0 ? ($pieIn / $pieTotal * 100) : 0;
        $pctOut = $pieTotal > 0 ? ($pieOut / $pieTotal * 100) : 0;
        ?>

        <div class="chart-wrap">
          <div class="chart-canvas">
            <canvas id="pieChart" height="160"></canvas>
          </div>
          <div class="chart-side">
            <div class="row">
              <div class="label"><?= h(tt('admin.dashboard.total_in', [], 'Total IN')) ?></div>
              <div class="num" style="color:#166534;">
                <?= h(fmt_rm($pieIn)) ?> (<?= number_format($pctIn, 1) ?>%)
              </div>
            </div>
            <div class="row">
              <div class="label"><?= h(tt('admin.dashboard.total_out', [], 'Total OUT')) ?></div>
              <div class="num" style="color:#b91c1c;">
                <?= h(fmt_rm($pieOut)) ?> (<?= number_format($pctOut, 1) ?>%)
              </div>
            </div>
            <div class="row">
              <div class="label"><?= h(tt('admin.dashboard.total', [], 'Total')) ?></div>
              <div class="num"><?= h(fmt_rm($pieTotal)) ?></div>
            </div>
            <div class="sub" style="margin-top:6px;">
              <?= h($monthStart) ?> – <?= h($monthEnd) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Bar card -->
      <div class="admin-card">
        <div class="admin-card-header">
          <div>
            <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.charts', [], 'Charts')) ?></div>
            <h2 class="page-title" style="font-size:16px;"><?= h(tt('admin.dashboard.bar_title', [], 'Last 6 months (IN / OUT)')) ?></h2>
          </div>
        </div>

        <div style="max-width:100%;">
          <canvas id="barChart" height="160"></canvas>
        </div>
      </div>

    </div>

    <!-- Latest transactions -->
    <div class="dashboard-split">

      <!-- Latest IN -->
      <div class="admin-card">
        <div class="admin-card-header">
          <div>
            <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.bank_in', [], 'Bank IN')) ?></div>
            <h2 class="page-title" style="font-size:16px;"><?= h(tt('admin.dashboard.latest_in', [], 'Latest bank IN')) ?></h2>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:90px;"><?= h(tt('admin.dashboard.col.date', [], 'Date')) ?></th>
                <th style="width:220px;"><?= h(tt('admin.dashboard.col.bank', [], 'Bank')) ?></th>
                <th><?= h(tt('admin.dashboard.col.desc', [], 'Description')) ?></th>
                <th style="width:150px;"><?= h(tt('admin.dashboard.col.amount', [], 'Amount')) ?></th>
                <th style="width:120px;"><?= h(tt('admin.dashboard.col.ref', [], 'Ref')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$recentIn): ?>
                <tr>
                  <td colspan="5" style="padding:14px;font-size:13px;color:#6b7280;">
                    <?= h(tt('admin.dashboard.in_empty', [], 'No bank IN transactions yet.')) ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentIn as $tRow): ?>
                  <?php
                  $d         = $tRow['txn_date'] ?? '';
                  $amountMyr = (float)($tRow['amount_myr'] ?? 0);
                  $curr      = $tRow['currency'] ?: 'MYR';

                  $bankLabelParts = [];
                  if (!empty($tRow['bank_code']))    $bankLabelParts[] = $tRow['bank_code'];
                  if (!empty($tRow['account_name'])) $bankLabelParts[] = $tRow['account_name'];
                  if (!empty($tRow['account_no']))   $bankLabelParts[] = $tRow['account_no'];
                  $bankLabel = implode(' · ', $bankLabelParts);
                  if ($bankLabel === '') $bankLabel = '-';
                  ?>
                  <tr>
                    <td class="td-date"><?= h($d) ?></td>
                    <td class="td-bank">
                      <div style="font-size:13px;font-weight:600;"><?= h($bankLabel) ?></div>
                    </td>
                    <td class="td-desc"><?= h($tRow['description'] ?? '-') ?></td>
                    <td class="td-amt">
                      <div style="font-size:13px;font-weight:800;color:#166534;">
                        <?= h($curr) ?> <?= number_format((float)$tRow['amount'], 2) ?>
                      </div>
                      <div style="font-size:11px;color:#6b7280;"><?= h(fmt_rm($amountMyr)) ?></div>
                    </td>
                    <td class="td-ref"><?= !empty($tRow['ref_no']) ? h($tRow['ref_no']) : '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Latest OUT -->
      <div class="admin-card">
        <div class="admin-card-header">
          <div>
            <div class="form-page-eyebrow"><?= h(tt('admin.dashboard.bank_out', [], 'Bank OUT')) ?></div>
            <h2 class="page-title" style="font-size:16px;"><?= h(tt('admin.dashboard.latest_out', [], 'Latest bank OUT')) ?></h2>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:90px;"><?= h(tt('admin.dashboard.col.date', [], 'Date')) ?></th>
                <th style="width:220px;"><?= h(tt('admin.dashboard.col.bank', [], 'Bank')) ?></th>
                <th><?= h(tt('admin.dashboard.col.desc', [], 'Description')) ?></th>
                <th style="width:150px;"><?= h(tt('admin.dashboard.col.amount', [], 'Amount')) ?></th>
                <th style="width:120px;"><?= h(tt('admin.dashboard.col.ref', [], 'Ref')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$recentOut): ?>
                <tr>
                  <td colspan="5" style="padding:14px;font-size:13px;color:#6b7280;">
                    <?= h(tt('admin.dashboard.out_empty', [], 'No bank OUT transactions yet.')) ?>
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentOut as $tRow): ?>
                  <?php
                  $d         = $tRow['txn_date'] ?? '';
                  $amountMyr = (float)($tRow['amount_myr'] ?? 0);
                  $curr      = $tRow['currency'] ?: 'MYR';

                  $bankLabelParts = [];
                  if (!empty($tRow['bank_code']))    $bankLabelParts[] = $tRow['bank_code'];
                  if (!empty($tRow['account_name'])) $bankLabelParts[] = $tRow['account_name'];
                  if (!empty($tRow['account_no']))   $bankLabelParts[] = $tRow['account_no'];
                  $bankLabel = implode(' · ', $bankLabelParts);
                  if ($bankLabel === '') $bankLabel = '-';
                  ?>
                  <tr>
                    <td class="td-date"><?= h($d) ?></td>
                    <td class="td-bank">
                      <div style="font-size:13px;font-weight:600;"><?= h($bankLabel) ?></div>
                    </td>
                    <td class="td-desc"><?= h($tRow['description'] ?? '-') ?></td>
                    <td class="td-amt">
                      <div style="font-size:13px;font-weight:800;color:#b91c1c;">
                        <?= h($curr) ?> -<?= number_format((float)$tRow['amount'], 2) ?>
                      </div>
                      <div style="font-size:11px;color:#6b7280;"><?= h(fmt_rm($amountMyr)) ?></div>
                    </td>
                    <td class="td-ref"><?= !empty($tRow['ref_no']) ? h($tRow['ref_no']) : '-' ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  (function() {
    function fmtRM(n) {
      const v = Number(n || 0);
      const sign = v < 0 ? '-' : '';
      const abs = Math.abs(v);
      return sign + 'RM ' + abs.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    // Pie
    const pieIn = <?= json_encode($pieIn) ?>;
    const pieOut = <?= json_encode($pieOut) ?>;

    const pieCtx = document.getElementById('pieChart');
    if (pieCtx) {
      new Chart(pieCtx, {
        type: 'pie',
        data: {
          labels: ['IN', 'OUT'],
          datasets: [{
            data: [pieIn, pieOut],
            backgroundColor: ['#166534', '#b91c1c'],
            borderColor: '#ffffff',
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'bottom'
            },
            tooltip: {
              callbacks: {
                label: function(ctx) {
                  const label = ctx.label || '';
                  const val = ctx.parsed || 0;
                  return label + ': ' + fmtRM(val);
                }
              }
            }
          }
        }
      });
    }

    // Bar
    const barLabels = <?= json_encode($barLabels) ?>;
    const barIn = <?= json_encode($barIn) ?>;
    const barOut = <?= json_encode($barOut) ?>;

    const barCtx = document.getElementById('barChart');
    if (barCtx) {
      new Chart(barCtx, {
        type: 'bar',
        data: {
          labels: barLabels,
          datasets: [{
              label: 'IN',
              data: barIn,
              backgroundColor: '#166534'
            },
            {
              label: 'OUT',
              data: barOut,
              backgroundColor: '#b91c1c'
            }
          ]
        },
        options: {
          responsive: true,
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                callback: function(v) {
                  return fmtRM(v);
                }
              }
            }
          },
          plugins: {
            legend: {
              position: 'bottom'
            },
            tooltip: {
              callbacks: {
                label: function(ctx) {
                  return (ctx.dataset.label || '') + ': ' + fmtRM(ctx.parsed.y);
                }
              }
            }
          }
        }
      });
    }
  })();
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>