<?php
// public/admin/bank/transactions_all.php
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

// translate helper (3 languages)
if (!function_exists('tt')) {
    function tt(string $key, array $vars = [], string $fallback = ''): string {
        if (function_exists('t')) return t($key, $vars, $fallback);
        return $fallback !== '' ? $fallback : $key;
    }
}

$canFn   = function_exists('can') ? 'can' : null;
$canEdit = $canFn ? $canFn('BANK.TXN.E') : true;

// session (remember filters)
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$sessionKey = 'bank_txn_all_filters';

// ========== 1) 载入所有 active bank accounts ========== //
$st = $pdo->query("
    SELECT id, bank_code, bank_name, account_name, account_no, currency
      FROM company_bank_accounts
     WHERE is_active = 1
  ORDER BY sort_order ASC, bank_name ASC, id ASC
");
$banks = $st->fetchAll();

$allBankIds = array_map(static fn($b) => (int)$b['id'], $banks);

// ---------- defaults ----------
$defaultFrom    = date('Y-m-01');
$defaultTo      = date('Y-m-t');
$defaultType    = 'ALL';
$defaultQ       = '';
$defaultBankIds = $allBankIds; // 默认全选

// ---------- reset ----------
if (isset($_GET['reset']) && (string)$_GET['reset'] === '1') {
    unset($_SESSION[$sessionKey]);
    $_GET['date_from'] = $defaultFrom;
    $_GET['date_to']   = $defaultTo;
    $_GET['type']      = $defaultType;
    $_GET['q']         = $defaultQ;
    $_GET['bank_ids']  = $defaultBankIds;
    $_GET['bank_ids_present'] = '1';
} else {
    $hasAnyFilterInGet = (
        isset($_GET['date_from']) ||
        isset($_GET['date_to'])   ||
        isset($_GET['type'])      ||
        isset($_GET['q'])         ||
        isset($_GET['bank_ids_present']) ||
        isset($_GET['bank_ids'])
    );

    if ($hasAnyFilterInGet) {
        $_SESSION[$sessionKey] = [
            'date_from' => trim((string)($_GET['date_from'] ?? '')),
            'date_to'   => trim((string)($_GET['date_to'] ?? '')),
            'type'      => strtoupper(trim((string)($_GET['type'] ?? $defaultType))),
            'q'         => trim((string)($_GET['q'] ?? '')),
            'bank_ids_present' => isset($_GET['bank_ids_present']) ? '1' : '0',
            'bank_ids'  => $_GET['bank_ids'] ?? null,
        ];
    } else {
        if (!empty($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
            $saved = $_SESSION[$sessionKey];
            foreach (['date_from','date_to','type','q'] as $k) {
                if (!isset($_GET[$k]) && isset($saved[$k])) {
                    $_GET[$k] = $saved[$k];
                }
            }
            if (!isset($_GET['bank_ids_present']) && isset($saved['bank_ids_present'])) {
                $_GET['bank_ids_present'] = $saved['bank_ids_present'];
            }
            if (!isset($_GET['bank_ids']) && array_key_exists('bank_ids', $saved)) {
                $_GET['bank_ids'] = $saved['bank_ids'];
            }
        } else {
            $_GET['date_from'] = $defaultFrom;
            $_GET['date_to']   = $defaultTo;
            $_GET['type']      = $defaultType;
            $_GET['q']         = $defaultQ;
            $_GET['bank_ids']  = $defaultBankIds;
            $_GET['bank_ids_present'] = '1';

            $_SESSION[$sessionKey] = [
                'date_from' => $_GET['date_from'],
                'date_to'   => $_GET['date_to'],
                'type'      => $_GET['type'],
                'q'         => $_GET['q'],
                'bank_ids_present' => '1',
                'bank_ids'  => $_GET['bank_ids'],
            ];
        }
    }
}

// ========== 2) Filters ========== //
$date_from = (string)($_GET['date_from'] ?? '');
$date_to   = (string)($_GET['date_to']   ?? '');
$type      = strtoupper(trim((string)($_GET['type'] ?? 'ALL')));
$q         = trim((string)($_GET['q'] ?? ''));

// ===== 选中的 bank（checkbox: bank_ids[]）=====
$selectedBankIds  = $_GET['bank_ids'] ?? null;
$bankIds          = [];
$bankIdsPresent   = isset($_GET['bank_ids_present']);

if (is_array($selectedBankIds)) {
    $tmp = [];
    foreach ($selectedBankIds as $bid) $tmp[(int)$bid] = true;

    $validMap = array_flip($allBankIds);
    foreach (array_keys($tmp) as $bid) {
        if (isset($validMap[$bid])) $bankIds[] = $bid;
    }
    if (!$bankIds && $allBankIds) $bankIds = $allBankIds;
} else {
    if ($bankIdsPresent) $bankIds = [];
    else $bankIds = $allBankIds;
}

$where  = [];
$params = [];

// bank 多选
if ($bankIds) {
    $inParts = [];
    foreach ($bankIds as $i => $bid) {
        $ph = ':b' . $i;
        $inParts[]   = $ph;
        $params[$ph] = $bid;
    }
    $where[] = 't.bank_id IN (' . implode(',', $inParts) . ')';
} else {
    $where[] = '1=0';
}

// 日期
if ($date_from !== '') {
    $where[]       = "t.txn_date >= :df";
    $params[':df'] = $date_from;
}
if ($date_to !== '') {
    $where[]       = "t.txn_date <= :dt";
    $params[':dt'] = $date_to;
}

// 类型
if ($type === 'IN') {
    $where[] = "t.txn_type = 'IN'";
} elseif ($type === 'OUT') {
    $where[] = "t.txn_type = 'OUT'";
}

// 关键字
if ($q !== '') {
    $where[] = "(t.description LIKE :q
             OR t.ref_no LIKE :q
             OR a.bank_name LIKE :q
             OR a.account_name LIKE :q
             OR a.account_no LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$whereSql = $where ? implode(' AND ', $where) : '1=1';

// ========== 3) Opening balance（MYR，总和所有选中银行） ========== //
$openingMyr = 0.0;

if ($date_from !== '' && $bankIds) {
    $openInParts = [];
    $openParams  = [];
    foreach ($bankIds as $i => $bid) {
        $ph = ':ob' . $i;
        $openInParts[]   = $ph;
        $openParams[$ph] = $bid;
    }
    $openParams[':odf'] = $date_from;

    $st = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN txn_type = 'IN'
                 THEN amount_myr
                 ELSE -amount_myr
            END
        ), 0) AS bal_before
        FROM company_bank_txn
        WHERE bank_id IN (" . implode(',', $openInParts) . ")
          AND txn_date < :odf
    ");
    $st->execute($openParams);
    $openingMyr = (float)($st->fetchColumn() ?? 0);
}

// ========== 4) 查询期间内流水 + 附件数量 ========== //
$sql = "
    SELECT
      t.*,
      a.bank_code,
      a.bank_name,
      a.account_name,
      a.account_no,
      a.currency AS bank_currency,
      COALESCE(f.file_count, 0) AS file_count
    FROM company_bank_txn t
    LEFT JOIN company_bank_accounts a ON a.id = t.bank_id
    LEFT JOIN (
        SELECT bank_txn_id, COUNT(*) AS file_count
          FROM company_bank_txn_files
         GROUP BY bank_txn_id
    ) f ON f.bank_txn_id = t.id
    WHERE $whereSql
    ORDER BY t.txn_date DESC, t.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// 汇总：期间 IN / OUT / NET（MYR）
$totalInMyr  = 0.0;
$totalOutMyr = 0.0;
foreach ($rows as $r) {
    $amtMyr = (float)($r['amount_myr'] ?? 0);
    if (($r['txn_type'] ?? '') === 'IN') $totalInMyr += $amtMyr;
    else $totalOutMyr += $amtMyr;
}
$netMyr     = $totalInMyr - $totalOutMyr;
$currentMyr = $openingMyr + $netMyr;

// keep query for links (preserve filters)
$keep = [
    'date_from' => $date_from,
    'date_to'   => $date_to,
    'type'      => $type,
    'q'         => $q,
    'bank_ids_present' => '1',
];
$qs = http_build_query($keep);
foreach ($bankIds as $bid) {
    $qs .= '&bank_ids[]=' . urlencode((string)$bid);
}

// 页面标题
$page_title = tt('admin.bank.all_txn.page_title', [], 'All bank transactions');

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- Header -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.bank.all_txn.eyebrow', [], 'BANK SUMMARY')) ?></div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h(tt('admin.bank.all_txn.subtitle', [], 'Combined view of all bank / wallet accounts in MYR.')) ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;">
          <?php if ($canEdit): ?>
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
        <div><div class="form-page-eyebrow"><?= h(tt('admin.bank.all_txn.summary', [], 'SUMMARY')) ?></div></div>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;">
        <div style="min-width:220px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.all_txn.opening', [], 'Opening balance')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($openingMyr, 2) ?></div>
        </div>

        <div style="min-width:220px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.all_txn.current', [], 'Current balance')) ?></div>
          <div style="font-size:18px;font-weight:700;margin-top:4px;">MYR <?= number_format($currentMyr, 2) ?></div>
        </div>
      </div>

      <div style="margin-top:14px;border-top:1px solid #e5e7eb;padding-top:10px;display:flex;flex-wrap:wrap;gap:18px;font-size:13px;">
        <div style="min-width:180px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.all_txn.total_in', [], 'Total IN')) ?> :</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($totalInMyr, 2) ?></div>
        </div>

        <div style="min-width:180px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.all_txn.total_out', [], 'Total OUT')) ?> :</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($totalOutMyr, 2) ?></div>
        </div>

        <div style="min-width:180px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.all_txn.net', [], 'Net')) ?> :</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:<?= $netMyr >= 0 ? '#166534' : '#b91c1c' ?>;">
            MYR <?= number_format($netMyr, 2) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-direction:column;gap:12px;font-size:13px;">
        <input type="hidden" name="bank_ids_present" value="1">

        <?php
          $date_from = $date_from;
          $date_to   = $date_to;
          include __DIR__ . '/../../include/date_range.php';
        ?>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.bank.all_txn.filter.type', [], 'Type')) ?></label>
            <select name="type" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $type === 'ALL' ? 'selected' : '' ?>><?= h(tt('admin.common.all', [], 'All')) ?></option>
              <option value="IN"  <?= $type === 'IN' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_in', [], 'IN')) ?></option>
              <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_out', [], 'OUT')) ?></option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.bank.all_txn.filter.search', [], 'Search')) ?></label>
            <input type="text" name="q" class="form-control" style="min-width:200px;"
                   value="<?= h($q) ?>"
                   placeholder="<?= h(tt('admin.bank.all_txn.filter.search_ph', [], 'Description / Ref / Bank / Acc no')) ?>">
          </div>
        </div>

        <div style="margin-top:4px;">
          <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.bank.all_txn.filter.accounts', [], 'Accounts')) ?></label>

          <?php if (!$banks): ?>
            <div style="font-size:12px;color:#6b7280;"><?= h(tt('admin.bank.all_txn.no_accounts', [], 'No active bank accounts.')) ?></div>
          <?php else: ?>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
              <?php foreach ($banks as $b): ?>
                <?php
                  $bid     = (int)$b['id'];
                  $checked = in_array($bid, $bankIds, true) ? 'checked' : '';
                ?>
                <label style="font-size:12px;display:flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;border:1px solid #e5e7eb;">
                  <input type="checkbox" name="bank_ids[]" value="<?= $bid ?>" <?= $checked ?>>
                  <span>
                    <strong><?= h($b['bank_code'] ?: ('#'.$bid)) ?></strong>
                    <span style="color:#6b7280;"><?= ' · ' . h($b['bank_name']) . ' · ' . h($b['account_name']) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px;">
          <button type="submit" class="btn btn-primary"><?= h(tt('admin.common.apply', [], 'Apply')) ?></button>
          <a href="<?= h(url('admin/bank/transactions_all.php?reset=1')) ?>" class="btn btn-light">
            <?= h(tt('admin.common.reset', [], 'Reset')) ?>
          </a>
        </div>
      </form>
    </div>

    <!-- Table -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;"><?= h(tt('admin.bank.all_txn.col.date', [], 'Date')) ?></th>
            <th style="width:70px;"><?= h(tt('admin.bank.all_txn.col.type', [], 'Type')) ?></th>
            <th><?= h(tt('admin.bank.all_txn.col.account', [], 'Account')) ?></th>
            <th><?= h(tt('admin.bank.all_txn.col.desc', [], 'Description')) ?></th>
            <th style="text-align:right;width:140px;"><?= h(tt('admin.bank.all_txn.col.amount', [], 'Amount')) ?></th>
            <th style="text-align:right;width:140px;"><?= h(tt('admin.bank.all_txn.col.rate', [], 'Rate → MYR')) ?></th>
            <th style="text-align:right;width:140px;"><?= h(tt('admin.bank.all_txn.col.myr', [], 'MYR')) ?></th>
            <th style="width:90px;"><?= h(tt('admin.bank.all_txn.col.attach', [], 'Attach')) ?></th>
            <th style="width:60px;" class="table-actions-cell"><?= h(tt('admin.common.actions', [], 'Actions')) ?></th>
          </tr>
        </thead>

        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="9" style="padding:16px;font-size:13px;color:#6b7280;">
              <?= h(tt('admin.bank.all_txn.empty', [], 'No transactions for this filter.')) ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $bankName     = (string)($r['bank_name'] ?? '');
              $accountName  = (string)($r['account_name'] ?? '');
              $bankCode     = (string)($r['bank_code'] ?? ('#'.(int)($r['bank_id'] ?? 0)));
              $bankCurrency = (string)($r['bank_currency'] ?? 'MYR');
              $currency     = (string)($r['currency'] ?? $bankCurrency);

              $editUrl = url('admin/bank/txn_edit.php?id='.(int)$r['id'].'&bank_id='.(int)$r['bank_id'].'&'.$qs);

              // ✅ attachments view (simple page)
              $filesUrl = url('admin/bank/txn_files.php?id='.(int)$r['id'].'&from=all&'.$qs);
              $fileCount = (int)($r['file_count'] ?? 0);
            ?>
            <tr>
              <td><?= h($r['txn_date']) ?></td>
              <td>
                <?php if (($r['txn_type'] ?? '') === 'IN'): ?>
                  <span style="font-size:11px;font-weight:600;color:#166534;"><?= h(tt('admin.bank.txn.type_in', [], 'IN')) ?></span>
                <?php else: ?>
                  <span style="font-size:11px;font-weight:600;color:#b91c1c;"><?= h(tt('admin.bank.txn.type_out', [], 'OUT')) ?></span>
                <?php endif; ?>
              </td>

              <td>
                <div style="font-size:13px;font-weight:600;"><?= h($bankName) ?></div>
                <div style="font-size:12px;color:#4b5563;"><?= h($accountName) ?></div>
                <div style="font-size:11px;color:#6b7280;">
                  <?= h($bankCode) ?>
                  <?php if (!empty($r['account_no'])): ?> · <?= h($r['account_no']) ?><?php endif; ?>
                </div>
              </td>

              <td>
                <div style="font-size:13px;font-weight:500;"><?= h($r['description'] ?? '') ?></div>
                <?php if (!empty($r['ref_no'])): ?>
                  <div style="font-size:11px;color:#6b7280;">
                    <?= h(tt('admin.bank.all_txn.ref', [], 'Ref')) ?>: <?= h($r['ref_no']) ?>
                  </div>
                <?php endif; ?>
              </td>

              <td style="text-align:right;"><?= h($currency) ?> <?= number_format((float)($r['amount'] ?? 0), 2) ?></td>
              <td style="text-align:right;">
                <?php if ($currency === 'MYR' || empty($r['rate_to_myr'])): ?>–
                <?php else: ?><?= number_format((float)$r['rate_to_myr'], 4) ?><?php endif; ?>
              </td>
              <td style="text-align:right;">MYR <?= number_format((float)($r['amount_myr'] ?? 0), 2) ?></td>

              <!-- ✅ Attachments column -->
              <td>
                <?php if ($fileCount > 0): ?>
                  <a href="<?= h($filesUrl) ?>" class="btn btn-light" style="padding:4px 8px;font-size:12px;">
                    VIEW- <?= $fileCount ?>
                  </a>
                <?php else: ?>
                  <span style="color:#9ca3af;">–</span>
                <?php endif; ?>
              </td>

              <td class="table-actions-cell">
                <div class="actions-menu">
                  <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                  <div class="actions-menu-dropdown">
                    <?php if ($canEdit): ?>
                      <a href="<?= h($editUrl) ?>" class="actions-menu-item">
                        <?= h(tt('admin.common.edit', [], 'Edit')) ?>
                      </a>
                    <?php endif; ?>

                    <a href="<?= h($filesUrl) ?>" class="actions-menu-item">
                      <?= h(tt('admin.bank.all_txn.attachments', [], 'Attachments')) ?>
                      <?= $fileCount > 0 ? ' ('.$fileCount.')' : '' ?>
                    </a>
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
