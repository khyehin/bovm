<?php
// public/admin/reports/customer_list_report.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('REPORT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

// ------- Date Filters -------
$today = date('Y-m-d');

$date_from = (string)($_GET['date_from'] ?? '');
$date_to   = (string)($_GET['date_to']   ?? '');

// ✅ NEW: date_all
$date_all  = (string)($_GET['date_all'] ?? '0');
$isAllDates = ($date_all === '1');

// ✅ 只有非 all 才给默认本月
if (!$isAllDates) {
    if ($date_from === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $date_from = date('Y-m-01');
    }
    if ($date_to === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $date_to = date('Y-m-t');
    }
} else {
    // all = 清空，保证后端不会误用本月
    $date_from = '';
    $date_to = '';
}

// 用在 transaction 上的日期表达式：优先 txn_date，没有就用 created_at
$dateExpr = "COALESCE(t.txn_date, DATE(t.created_at))";

// ------- 其它 Filters -------
$q          = trim($_GET['q'] ?? '');
$onlyActive = isset($_GET['only_active']) && $_GET['only_active'] === '1';

// ------- 构建 customer 基础查询 -------
$sqlBase = "SELECT c.id, c.code, c.name, c.is_active
              FROM customers c";
$whereCust   = [];
$paramsCust  = [];

if ($q !== '') {
    $whereCust[]        = "(c.name LIKE :q OR c.code LIKE :q)";
    $paramsCust[':q']   = '%'.$q.'%';
}
if ($onlyActive) {
    $whereCust[]        = "c.is_active = 1";
}

if ($whereCust) {
    $sqlBase .= " WHERE ".implode(' AND ', $whereCust);
}
$sqlBase .= " ORDER BY c.code ASC, c.name ASC";

$st = $pdo->prepare($sqlBase);
$st->execute($paramsCust);
$customers = $st->fetchAll();

$rows = [];

// totals
$total_in_normal_all   = 0.0;
$total_out_normal_all  = 0.0;
$bonus_total_all       = 0.0;
$repay_total_all       = 0.0;
$loan_total_all        = 0.0;

$net_normal_all        = 0.0;
$return_balance_all    = 0.0;

$summary_in_all        = 0.0;
$summary_out_all       = 0.0;
$summary_net_all       = 0.0;

// ------- 对每个 customer 计算（跟 txn_list 逻辑） -------
if ($customers) {
    $custIds = array_column($customers, 'id');
    $inClause = implode(',', array_fill(0, count($custIds), '?'));

    // ✅ 关键：dateWhere + paramsSum 只在非 all 才加
    $dateWhere = "";
    $paramsSum = $custIds;

    if (!$isAllDates && $date_from !== '' && $date_to !== '') {
        $dateWhere = " AND {$dateExpr} BETWEEN ? AND ? ";
        $paramsSum[] = $date_from;
        $paramsSum[] = $date_to;
    }

    $sumSql = "
        SELECT
            t.customer_id,

            SUM(CASE
                  WHEN t.txn_type = 'IN'
                   AND UPPER(COALESCE(t.in_kind,'')) NOT IN ('BONUS','RETURN')
                  THEN (t.amount - COALESCE(t.allocated_amount,0))
                  ELSE 0
                END) AS total_in_normal,

            SUM(CASE
                  WHEN t.txn_type = 'OUT'
                   AND (t.is_contra IS NULL OR t.is_contra = 0)
                   AND UPPER(COALESCE(t.out_kind,'')) <> 'LOAN'
                  THEN t.amount
                  ELSE 0
                END) AS total_out_normal,

            SUM(CASE
                  WHEN t.txn_type = 'IN'
                   AND UPPER(COALESCE(t.in_kind,'')) = 'BONUS'
                  THEN (t.amount - COALESCE(t.allocated_amount,0))
                  ELSE 0
                END) AS bonus_total,

            SUM(CASE
                  WHEN t.txn_type = 'IN'
                   AND (
                        UPPER(COALESCE(t.in_kind,'')) = 'RETURN'
                        OR (
                             (COALESCE(t.order_total,0) = 0)
                             AND (t.amount > 0)
                             AND (t.title LIKE '%Repayment%' OR t.title LIKE '%repayment%')
                           )
                      )
                  THEN (t.amount - COALESCE(t.allocated_amount,0))
                  ELSE 0
                END) AS repay_total,

            SUM(CASE
                  WHEN t.txn_type = 'OUT'
                   AND (t.is_contra IS NULL OR t.is_contra = 0)
                   AND UPPER(COALESCE(t.out_kind,'')) = 'LOAN'
                  THEN t.amount
                  ELSE 0
                END) AS loan_total

          FROM customer_txn t
         WHERE t.customer_id IN ($inClause)
         $dateWhere
      GROUP BY t.customer_id
    ";

    $st = $pdo->prepare($sumSql);
    $st->execute($paramsSum);

    $sumMap = [];
    while ($r = $st->fetch()) {
        $sumMap[(int)$r['customer_id']] = $r;
    }

    foreach ($customers as $c) {
        $cid = (int)$c['id'];
        $sum = $sumMap[$cid] ?? [
            'total_in_normal'  => 0,
            'total_out_normal' => 0,
            'bonus_total'      => 0,
            'repay_total'      => 0,
            'loan_total'       => 0,
        ];

        $in_normal   = (float)($sum['total_in_normal'] ?? 0);
        $out_normal  = (float)($sum['total_out_normal'] ?? 0);
        $bonus_total = (float)($sum['bonus_total'] ?? 0);
        $repay_total = (float)($sum['repay_total'] ?? 0);
        $loan_total  = (float)($sum['loan_total'] ?? 0);

        $net_normal = $in_normal - $out_normal;
        $return_balance = $loan_total - $repay_total;

        $summary_in  = $in_normal + $bonus_total + $repay_total;
        $summary_out = $out_normal + $loan_total;
        $summary_net = $summary_in - $summary_out;

        $total_in_normal_all  += $in_normal;
        $total_out_normal_all += $out_normal;
        $bonus_total_all      += $bonus_total;
        $repay_total_all      += $repay_total;
        $loan_total_all       += $loan_total;

        $net_normal_all       += $net_normal;
        $return_balance_all   += $return_balance;

        $summary_in_all       += $summary_in;
        $summary_out_all      += $summary_out;
        $summary_net_all      += $summary_net;

        $rows[] = [
            'id'            => $cid,
            'code'          => $c['code'] ?? '',
            'name'          => $c['name'] ?? '',
            'is_active'     => isset($c['is_active']) ? (int)$c['is_active'] : 1,

            'in_normal'     => $in_normal,
            'out_normal'    => $out_normal,
            'net_normal'    => $net_normal,

            'bonus_total'   => $bonus_total,
            'repay_total'   => $repay_total,
            'loan_total'    => $loan_total,
            'return_balance'=> $return_balance,

            'summary_in'    => $summary_in,
            'summary_out'   => $summary_out,
            'summary_net'   => $summary_net,
        ];
    }
}

// ------- Audit -------
if (function_exists('audit_log')) {
    audit_log(
        $pdo,
        'REPORT.CUSTOMER.VIEW',
        [
            'description' => 'View customer list report page',
            'q'           => $q !== '' ? $q : null,
            'only_active' => $onlyActive ? 1 : 0,
            'date_from'   => $date_from !== '' ? $date_from : null,
            'date_to'     => $date_to !== '' ? $date_to : null,
            'date_all'    => $isAllDates ? 1 : 0,
        ],
        'report_customer',
        null
    );
}

$page_title = $hasT
    ? t('admin.report.customer_list.title', [], 'Customer List Report')
    : 'Customer List Report';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.report.eyebrow', [], 'Reports') : 'Reports') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
        </div>

        <div>
          <a href="<?= h(url('admin/reports/customer_list_report_export.php?' . http_build_query($_GET))) ?>"
             class="btn btn-light">
            <?= h($hasT ? t('admin.common.export', [], 'Export') : 'Export') ?>
          </a>
        </div>
      </div>

      <form method="get"
            action="<?= h(url('admin/reports/customer_list_report.php')) ?>"
            style="margin-top:10px;display:flex;flex-direction:column;gap:10px;">

        <?php include __DIR__ . '/../../include/date_range.php'; ?>

        <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
          <div style="min-width:260px;">
            <label class="field-label" style="margin-bottom:4px;">
              <?= h($hasT ? t('admin.report.customer_list.filter.search', [], 'Search') : 'Search') ?>
            </label>
            <input type="text"
                   name="q"
                   class="form-control"
                   placeholder="<?= h($hasT ? t('admin.report.customer_list.filter.search_ph', [], 'Search by code / name...') : 'Search by code / name...') ?>"
                   value="<?= h($q) ?>">
          </div>

          <div style="display:flex;align-items:center;gap:6px;">
            <label style="font-size:13px;">
              <input type="checkbox" name="only_active" value="1" <?= $onlyActive ? 'checked' : '' ?>>
              <?= h($hasT ? t('admin.report.customer_list.filter.only_active', [], 'Only active') : 'Only active') ?>
            </label>
          </div>

          <div style="margin-left:auto;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.common.apply', [], 'Apply') : 'Apply') ?>
            </button>
            <a href="<?= h(url('admin/reports/customer_list_report.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.reset', [], 'Reset') : 'Reset') ?>
            </a>
          </div>
        </div>
      </form>
    </div>

    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:70px;"><?= h($hasT ? t('admin.report.customer_list.col.id', [], 'ID') : 'ID') ?></th>
            <th><?= h($hasT ? t('admin.report.customer_list.col.customer', [], 'Customer') : 'Customer') ?></th>
            <th style="width:220px;"><?= h($hasT ? t('admin.report.customer_list.col.code', [], 'Code') : 'Code') ?></th>

            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.total_in', [], 'Total IN (normal)') : 'Total IN (normal)') ?></th>
            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.total_out', [], 'Total OUT (normal)') : 'Total OUT (normal)') ?></th>
            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.net_normal', [], 'Net (normal)') : 'Net (normal)') ?></th>

            <th style="width:160px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.total_bonus', [], 'Total BONUS') : 'Total BONUS') ?></th>
            <th style="width:160px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.return_balance', [], 'Return (loan - repayment)') : 'Return (loan - repayment)') ?></th>

            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_in', [], 'Summary IN') : 'Summary IN') ?></th>
            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_out', [], 'Summary OUT') : 'Summary OUT') ?></th>
            <th style="width:170px; text-align:right;"><?= h($hasT ? t('admin.customer_txn.list.summary.summary_net', [], 'Summary Net') : 'Summary Net') ?></th>

            <th style="width:90px;"><?= h($hasT ? t('admin.report.customer_list.col.status', [], 'Status') : 'Status') ?></th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="12" style="padding:14px;font-size:13px;color:#6b7280;">
              <?= h($hasT ? t('admin.report.customer_list.empty', [], 'No customers found for this filter.') : 'No customers found for this filter.') ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $netNormal = (float)$r['net_normal'];
              $netNormalStyle = $netNormal > 0 ? 'color:#b91c1c;' : ($netNormal < 0 ? 'color:#166534;' : '');

              $ret = (float)$r['return_balance'];
              $retStyle = $ret > 0 ? 'color:#b91c1c;' : ($ret < 0 ? 'color:#166534;' : '');

              $snet = (float)$r['summary_net'];
              $snetStyle = $snet > 0 ? 'color:#b91c1c;' : ($snet < 0 ? 'color:#166534;' : '');
            ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>

              <td><div style="font-size:13px;font-weight:600;"><?= h($r['name']) ?></div></td>

              <td><?= h($r['code']) ?></td>

              <td style="text-align:right;">RM <?= number_format((float)$r['in_normal'], 2) ?></td>
              <td style="text-align:right;">RM <?= number_format((float)$r['out_normal'], 2) ?></td>
              <td style="text-align:right;<?= $netNormalStyle ?>">RM <?= number_format($netNormal, 2) ?></td>

              <td style="text-align:right;">RM <?= number_format((float)$r['bonus_total'], 2) ?></td>
              <td style="text-align:right;<?= $retStyle ?>">RM <?= number_format($ret, 2) ?></td>

              <td style="text-align:right;">RM <?= number_format((float)$r['summary_in'], 2) ?></td>
              <td style="text-align:right;">RM <?= number_format((float)$r['summary_out'], 2) ?></td>
              <td style="text-align:right;<?= $snetStyle ?>">RM <?= number_format($snet, 2) ?></td>

              <td>
                <?php if (!empty($r['is_active'])): ?>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#ecfdf5;color:#166534;">
                    <?= h($hasT ? t('admin.customer.status.active', [], 'Active') : 'Active') ?>
                  </span>
                <?php else: ?>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fee2e2;color:#b91c1c;">
                    <?= h($hasT ? t('admin.customer.status.inactive', [], 'Inactive') : 'Inactive') ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php
            $totalNetNormalStyle = $net_normal_all > 0 ? 'color:#b91c1c;' : ($net_normal_all < 0 ? 'color:#166534;' : '');
            $totalReturnStyle    = $return_balance_all > 0 ? 'color:#b91c1c;' : ($return_balance_all < 0 ? 'color:#166534;' : '');
            $totalSummaryNetStyle= $summary_net_all > 0 ? 'color:#b91c1c;' : ($summary_net_all < 0 ? 'color:#166534;' : '');
          ?>
          <tr>
            <td colspan="3" style="font-weight:600; text-align:right;">
              <?= h($hasT ? t('admin.report.customer_list.total_label', [], 'TOTAL') : 'TOTAL') ?>
            </td>

            <td style="text-align:right; font-weight:600;">RM <?= number_format($total_in_normal_all, 2) ?></td>
            <td style="text-align:right; font-weight:600;">RM <?= number_format($total_out_normal_all, 2) ?></td>
            <td style="text-align:right; font-weight:600;<?= $totalNetNormalStyle ?>">RM <?= number_format($net_normal_all, 2) ?></td>

            <td style="text-align:right; font-weight:600;">RM <?= number_format($bonus_total_all, 2) ?></td>
            <td style="text-align:right; font-weight:600;<?= $totalReturnStyle ?>">RM <?= number_format($return_balance_all, 2) ?></td>

            <td style="text-align:right; font-weight:600;">RM <?= number_format($summary_in_all, 2) ?></td>
            <td style="text-align:right; font-weight:600;">RM <?= number_format($summary_out_all, 2) ?></td>
            <td style="text-align:right; font-weight:600;<?= $totalSummaryNetStyle ?>">RM <?= number_format($summary_net_all, 2) ?></td>

            <td></td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
