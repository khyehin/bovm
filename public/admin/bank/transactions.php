<?php
// public/admin/bank/transactions.php
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

$canFunc = function_exists('can') ? 'can' : null;
$canEdit = $canFunc ? $canFunc('BANK.TXN.E') : true;

// 1) bank_id required
if (!isset($_GET['bank_id'])) {
    http_response_code(400);
    exit(tt('admin.bank.txn.err_missing_bank', [], 'Missing bank_id'));
}
$bankId = (int)$_GET['bank_id'];
$isCash = ($bankId === 0);

if ($isCash) {
    $bank = [
        'id'           => 0,
        'bank_name'    => tt('admin.bank.cash.title', [], 'Cash account'),
        'bank_code'    => '',
        'account_name' => tt('admin.bank.cash.account_name', [], 'Cash'),
        'account_no'   => '',
        'currency'     => 'MYR',
    ];
} else {
    $st = $pdo->prepare("
        SELECT id, bank_name, bank_code, account_name, account_no, currency
          FROM company_bank_accounts
         WHERE id = :id
    ");
    $st->execute([':id' => $bankId]);
    $bank = $st->fetch();
    if (!$bank) {
        http_response_code(404);
        exit(tt('admin.bank.txn.err_bank_not_found', [], 'Bank account not found'));
    }
}

$baseCurrency = $bank['currency'] ?: 'MYR';

// --------------------------------------------------
// Remember filters per bank (session)
// --------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
$sessionKey = 'bank_txn_filters_' . (int)$bankId;

$defaultFrom = date('Y-m-01');
$defaultTo   = date('Y-m-t');

if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    unset($_SESSION[$sessionKey]);
    $_GET['date_from'] = $defaultFrom;
    $_GET['date_to']   = $defaultTo;
    $_GET['type']      = 'ALL';
    $_GET['view_cur']  = 'BASE';
    $_GET['q']         = '';
} else {
    $hasAnyFilterInGet = (
        isset($_GET['date_from']) ||
        isset($_GET['date_to'])   ||
        isset($_GET['type'])      ||
        isset($_GET['q'])         ||
        isset($_GET['view_cur'])
    );

    if ($hasAnyFilterInGet) {
        $_SESSION[$sessionKey] = [
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to'   => trim((string)($_GET['date_to'] ?? '')),
            'type'      => trim((string)($_GET['type'] ?? 'ALL')),
            'q'         => trim((string)($_GET['q'] ?? '')),
            'view_cur'  => strtoupper(trim((string)($_GET['view_cur'] ?? 'BASE'))),
        ];
    } else {
        if (!empty($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
            foreach (['date_from','date_to','type','q','view_cur'] as $k) {
                if (!isset($_GET[$k]) && isset($_SESSION[$sessionKey][$k])) {
                    $_GET[$k] = $_SESSION[$sessionKey][$k];
                }
            }
        } else {
            $_GET['date_from'] = $defaultFrom;
            $_GET['date_to']   = $defaultTo;
            $_GET['type']      = 'ALL';
            $_GET['view_cur']  = 'BASE';
            $_GET['q']         = '';

            $_SESSION[$sessionKey] = [
                'date_from' => $_GET['date_from'],
                'date_to'   => $_GET['date_to'],
                'type'      => $_GET['type'],
                'q'         => $_GET['q'],
                'view_cur'  => $_GET['view_cur'],
            ];
        }
    }
}

// 2) filters
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$type      = trim($_GET['type']      ?? 'ALL');  // ALL / IN / OUT
$q         = trim($_GET['q']         ?? '');
$view_cur  = strtoupper(trim($_GET['view_cur'] ?? 'BASE')); // BASE / MYR
if (!in_array($view_cur, ['BASE', 'MYR'], true)) $view_cur = 'BASE';

// ✅ use alias t. because we join attachments aggregate
$where  = ["t.bank_id = :bid"];
$params = [':bid' => $bankId];

if ($date_from !== '') {
    $where[]       = "t.txn_date >= :df";
    $params[':df'] = $date_from;
}
if ($date_to !== '') {
    $where[]       = "t.txn_date <= :dt";
    $params[':dt'] = $date_to;
}
if ($type === 'IN') {
    $where[] = "t.txn_type = 'IN'";
} elseif ($type === 'OUT') {
    $where[] = "t.txn_type = 'OUT'";
}
if ($q !== '') {
    $where[]      = "(t.ref_no LIKE :q OR t.description LIKE :q)";
    $params[':q'] = '%'.$q.'%';
}

$whereSql = implode(' AND ', $where);

// 3) Opening balance
$openingBase = 0.0;
$openingMyr  = 0.0;

if ($date_from !== '') {
    $st = $pdo->prepare("
        SELECT
          COALESCE(SUM(
            CASE
              WHEN currency = :base_cur THEN
                CASE WHEN txn_type = 'IN'
                     THEN amount
                     ELSE -amount
                END
              ELSE 0
            END
          ), 0) AS bal_before_base,
          COALESCE(SUM(
            CASE WHEN txn_type = 'IN'
                 THEN amount_myr
                 ELSE -amount_myr
            END
          ), 0) AS bal_before_myr
        FROM company_bank_txn
        WHERE bank_id = :bid
          AND txn_date < :df
    ");
    $st->execute([
        ':bid'      => $bankId,
        ':df'       => $date_from,
        ':base_cur' => $baseCurrency,
    ]);
    $row         = $st->fetch();
    $openingBase = (float)($row['bal_before_base'] ?? 0);
    $openingMyr  = (float)($row['bal_before_myr'] ?? 0);
}

// 4) rows + ✅ attachment count
$st = $pdo->prepare("
    SELECT
      t.id,
      t.bank_id,
      t.txn_date,
      t.txn_type,
      t.ref_no,
      t.description,
      t.currency,
      t.amount,
      t.rate_to_myr,
      t.amount_myr,
      t.created_at,
      COALESCE(f.cnt, 0) AS attach_cnt
    FROM company_bank_txn t
    LEFT JOIN (
      SELECT bank_txn_id, COUNT(*) AS cnt
      FROM company_bank_txn_files
      GROUP BY bank_txn_id
    ) f ON f.bank_txn_id = t.id
    WHERE $whereSql
    ORDER BY t.txn_date ASC, t.id ASC
");
$st->execute($params);
$rows = $st->fetchAll();

// totals
$totalInBase  = 0.0;
$totalOutBase = 0.0;
$totalInMyr   = 0.0;
$totalOutMyr  = 0.0;

foreach ($rows as $r) {
    $amtBase = (float)$r['amount'];
    $amtMyr  = (float)$r['amount_myr'];

    if ($r['txn_type'] === 'IN') {
        if ($r['currency'] === $baseCurrency) $totalInBase += $amtBase;
        $totalInMyr += $amtMyr;
    } else {
        if ($r['currency'] === $baseCurrency) $totalOutBase += $amtBase;
        $totalOutMyr += $amtMyr;
    }
}

$netBase     = $totalInBase - $totalOutBase;
$netMyr      = $totalInMyr  - $totalOutMyr;
$currentBase = $openingBase + $netBase;
$currentMyr  = $openingMyr  + $netMyr;

// display currency
if ($view_cur === 'MYR') {
    $summaryCurLabel = 'MYR';
    $openingDisplay  = $openingMyr;
    $inDisplay       = $totalInMyr;
    $outDisplay      = $totalOutMyr;
    $netDisplay      = $netMyr;
    $currentDisplay  = $currentMyr;
} else {
    $summaryCurLabel = $baseCurrency;
    $openingDisplay  = $openingBase;
    $inDisplay       = $totalInBase;
    $outDisplay      = $totalOutBase;
    $netDisplay      = $netBase;
    $currentDisplay  = $currentBase;
}

// running balance
$runningBal = ($view_cur === 'MYR') ? $openingMyr : $openingBase;
foreach ($rows as &$r) {
    $amtBase = (float)$r['amount'];
    $amtMyr  = (float)$r['amount_myr'];

    if ($view_cur === 'MYR') {
        $moveView = ($r['txn_type'] === 'IN') ? $amtMyr : -$amtMyr;
    } else {
        $moveView = ($r['currency'] === $baseCurrency)
            ? (($r['txn_type'] === 'IN') ? $amtBase : -$amtBase)
            : 0.0;
    }

    $runningBal        += $moveView;
    $r['_running_bal']  = $runningBal;
    $r['_amount_base']  = $amtBase;
    $r['_amount_myr']   = $amtMyr;
}
unset($r);

// keep filters in links
$keep = [
    'bank_id'   => $bankId,
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'type'      => $type,
    'q'         => $q,
    'view_cur'  => $view_cur,
];
$qs = http_build_query($keep);

$page_title = tt('admin.bank.txn.page_title', [], 'Bank transactions')
    . ': ' . ($bank['bank_code'] ? $bank['bank_code'] . ' · ' : '')
    . ($bank['account_name'] ?? '');

$date_from_for_include = $date_from;
$date_to_for_include   = $date_to;

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- Header -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h(tt('admin.bank.txn.eyebrow', [], 'Bank')) ?>
          </div>
          <h1 class="page-title">
            <?= h($bank['bank_name']) ?>
            <?php if (!empty($bank['bank_code'])): ?>
              <span style="font-size:13px;color:#6b7280;">(<?= h($bank['bank_code']) ?>)</span>
            <?php endif; ?>
          </h1>
          <div class="form-page-subtitle">
            <?= h($bank['account_name']) ?>
            <?php if (!empty($bank['account_no'])): ?> · <?= h($bank['account_no']) ?><?php endif; ?>
          </div>
        </div>

        <div style="display:flex;gap:8px;">
          <a href="<?= h(url('admin/bank/accounts.php')) ?>" class="btn btn-light">
            ← <?= h(tt('admin.common.back', [], 'Back')) ?>
          </a>

          <a href="<?= h(url('admin/bank/statements.php?' . $qs)) ?>" class="btn btn-light">
            <?= h(tt('admin.bank.txn.btn_statement', [], 'Bank statement')) ?>
          </a>

          <?php if ($canEdit && !$isCash): ?>
            <a href="<?= h(url('admin/bank/txn_edit.php?' . $qs)) ?>" class="btn btn-primary">
              <?= h(tt('admin.bank.txn.new_btn', [], '+ New transaction')) ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Summary -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header" style="margin-bottom:10px;">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.bank.txn.summary.eyebrow', [], 'Summary')) ?></div>
          <div class="form-page-title" style="font-size:18px;">
            <?= h(tt('admin.bank.txn.summary.title', [], 'Balance overview')) ?>
          </div>
        </div>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;">
        <div style="min-width:200px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.txn.summary.opening_simple', [], 'Opening balance')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?= h($summaryCurLabel) ?> <?= number_format($openingDisplay, 2) ?>
          </div>
        </div>

        <div style="min-width:160px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.txn.summary.in', [], 'IN this period')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?= h($summaryCurLabel) ?> <?= number_format($inDisplay, 2) ?>
          </div>
        </div>

        <div style="min-width:160px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.txn.summary.out', [], 'OUT this period')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">
            <?= h($summaryCurLabel) ?> <?= number_format($outDisplay, 2) ?>
          </div>
        </div>

        <div style="min-width:160px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.txn.summary.net', [], 'Net movement')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:<?= $netDisplay >= 0 ? '#166534' : '#b91c1c' ?>;">
            <?= h($summaryCurLabel) ?> <?= number_format($netDisplay, 2) ?>
          </div>
        </div>

        <div style="min-width:220px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.txn.summary.current_simple', [], 'Current balance')) ?></div>
          <div style="font-size:18px;font-weight:700;margin-top:4px;">
            <?= h($summaryCurLabel) ?> <?= number_format($currentDisplay, 2) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-direction:column;gap:12px;font-size:13px;">
        <input type="hidden" name="bank_id" value="<?= (int)$bankId ?>">

        <?php
          $date_from = $date_from_for_include;
          $date_to   = $date_to_for_include;
          include __DIR__ . '/../../include/date_range.php';
        ?>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">

          <div>
            <label class="field-label" style="margin-bottom:4px;">
              <?= h(tt('admin.bank.txn.filter.type', [], 'Type')) ?>
            </label>
            <select name="type" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $type === 'ALL' ? 'selected' : '' ?>><?= h(tt('admin.common.all', [], 'All')) ?></option>
              <option value="IN"  <?= $type === 'IN'  ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_in', [], 'IN')) ?></option>
              <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_out', [], 'OUT')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">
              <?= h(tt('admin.bank.txn.filter.view_cur', [], 'View currency')) ?>
            </label>
            <select name="view_cur" class="form-control" style="min-width:150px;">
              <option value="BASE" <?= $view_cur === 'BASE' ? 'selected' : '' ?>><?= h($baseCurrency . ' (account)') ?></option>
              <option value="MYR" <?= $view_cur === 'MYR' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.view_cur_myr', [], 'MYR (converted)')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">
              <?= h(tt('admin.bank.txn.filter.q', [], 'Search keyword')) ?>
            </label>
            <input type="text" name="q" class="form-control" style="min-width:220px;"
              placeholder="<?= h(tt('admin.bank.txn.filter.q_ph', [], 'Ref no. / Description')) ?>"
              value="<?= h($q) ?>">
          </div>

          <div style="margin-left:auto;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary"><?= h(tt('admin.common.apply', [], 'Apply')) ?></button>
            <a href="<?= h(url('admin/bank/transactions.php?bank_id='.$bankId.'&reset=1')) ?>" class="btn btn-light">
              <?= h(tt('admin.common.reset', [], 'Reset')) ?>
            </a>
          </div>

        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;"><?= h(tt('admin.bank.txn.col.date', [], 'Date')) ?></th>
            <th style="width:70px;"><?= h(tt('admin.bank.txn.col.type', [], 'Type')) ?></th>
            <th><?= h(tt('admin.bank.txn.col.ref', [], 'Ref')) ?></th>
            <th><?= h(tt('admin.bank.txn.col.desc', [], 'Description')) ?></th>
            <th style="width:80px;"><?= h(tt('admin.bank.txn.col.cur', [], 'Cur')) ?></th>
            <th style="width:120px;text-align:right;"><?= h(tt('admin.bank.txn.col.amount', [], 'Amount')) ?></th>
            <th style="width:120px;text-align:right;"><?= h(tt('admin.bank.txn.col.myr', [], 'MYR')) ?></th>
            <th style="width:150px;text-align:right;"><?= h('Balance (' . $summaryCurLabel . ')') ?></th>

            <!-- ✅ NEW: attachments -->
            <th style="width:90px;text-align:center;"><?= h(tt('admin.bank.txn.col.attach', [], 'Attach')) ?></th>

            <th style="width:70px;" class="table-actions-cell"><?= h(tt('admin.common.actions', [], 'Actions')) ?></th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="10" style="padding:16px;font-size:13px;color:#6b7280;">
              <?= h(tt('admin.bank.txn.empty', [], 'No transactions for this filter.')) ?>
            </td>
          </tr>
        <?php else: ?>

          <tr>
            <td></td><td></td><td></td>
            <td style="font-size:12px;color:#6b7280;">
              <?= h(tt('admin.bank.txn.row.opening', [], 'Opening balance before period')) ?>
            </td>
            <td></td><td style="text-align:right;"></td><td style="text-align:right;"></td>
            <td style="text-align:right;font-weight:600;">
              <?= number_format($view_cur === 'MYR' ? $openingMyr : $openingBase, 2) ?>
            </td>
            <td style="text-align:center;"></td>
            <td></td>
          </tr>

          <?php foreach ($rows as $r): ?>
            <?php
              $amtBase     = (float)$r['_amount_base'];
              $amtMyr      = (float)$r['_amount_myr'];
              $runningBalR = (float)$r['_running_bal'];
              $cnt         = (int)($r['attach_cnt'] ?? 0);
              $filesUrl    = url('admin/bank/txn_files.php?id='.(int)$r['id'].'&'.$qs);
            ?>
            <tr>
              <td><?= h($r['txn_date']) ?></td>
              <td>
                <?php if ($r['txn_type'] === 'IN'): ?>
                  <span style="font-size:12px;font-weight:600;color:#166534;"><?= h(tt('admin.bank.txn.type_in', [], 'IN')) ?></span>
                <?php else: ?>
                  <span style="font-size:12px;font-weight:600;color:#b91c1c;"><?= h(tt('admin.bank.txn.type_out', [], 'OUT')) ?></span>
                <?php endif; ?>
              </td>
              <td><?= h($r['ref_no'] ?? '') ?></td>
              <td><?= h($r['description'] ?? '') ?></td>
              <td><?= h($r['currency']) ?></td>
              <td style="text-align:right;"><?= number_format($amtBase, 2) ?></td>
              <td style="text-align:right;"><?= number_format($amtMyr, 2) ?></td>
              <td style="text-align:right;font-weight:600;"><?= number_format($runningBalR, 2) ?></td>

              <!-- ✅ attach -->
              <td style="text-align:center;">
                <?php if ($cnt > 0): ?>
                  <a href="<?= h($filesUrl) ?>" style="text-decoration:underline;">
                    VIEW- <?= (int)$cnt ?>
                  </a>
                <?php else: ?>
                  <span style="color:#9ca3af;">—</span>
                <?php endif; ?>
              </td>

              <td class="table-actions-cell">
                <div class="actions-menu">
                  <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                  <div class="actions-menu-dropdown">

                    <?php if ($cnt > 0): ?>
                      <a href="<?= h($filesUrl) ?>" class="actions-menu-item">
                        <?= h(tt('admin.bank.txn.action.attachments', [], 'Attachments')) ?> (<?= (int)$cnt ?>)
                      </a>
                    <?php endif; ?>

                    <?php if ($canEdit && !$isCash): ?>
                      <a href="<?= h(url('admin/bank/txn_edit.php?id='.(int)$r['id'].'&' . $qs)) ?>" class="actions-menu-item">
                        <?= h(tt('admin.common.edit', [], 'Edit')) ?>
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
