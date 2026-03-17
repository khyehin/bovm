<?php
// public/user/txn/txns.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/i18n.php';
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

// bank label（跟 txn_view / admin txn_list 一样）
function bank_label_view(array $b): string {
    $parts = [];
    if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
    if (!empty($b['account_name'])) $parts[] = $b['account_name'];
    if (!empty($b['account_no']))   $parts[] = $b['account_no'];
    $label = implode(' · ', $parts);
    if (!empty($b['currency'])) {
        $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
    }
    return $label ?: ('Account #' . $b['id']);
}

$cid = (int)($u['customer_id'] ?? 0);
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

$currency = $customer['currency'] ?? 'MYR';

// ===== bank accounts (for filter + label map) =====
$bankRows = [];
$bankMap  = [];
try {
    $bankRows = $pdo->query("
        SELECT id, bank_code, account_name, account_no, currency
        FROM company_bank_accounts
        WHERE is_active = 1
        ORDER BY bank_code, account_name, account_no, id
    ")->fetchAll();
    foreach ($bankRows as $b) {
        $bankMap[(int)$b['id']] = bank_label_view($b);
    }
} catch (Throwable $e) {
    $bankRows = [];
    $bankMap  = [];
}

// ===== filters =====
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$type      = $_GET['type']      ?? 'ALL';   // ALL / IN / OUT（客户视角）
$status    = $_GET['status']    ?? 'ALL';   // ALL / DRAFT / SENT / PENDING / CONFIRMED
$method    = $_GET['method']    ?? 'ALL';   // ✅ ALL / BANK_<id> / ALLOCATE
$q         = trim($_GET['q']    ?? '');

// method parse（跟 admin txn_list）
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

if ($date_from !== '') {
    $where[]       = "DATE(txn_date) >= :df";
    $params[':df'] = $date_from;
}
if ($date_to !== '') {
    $where[]       = "DATE(txn_date) <= :dt";
    $params[':dt'] = $date_to;
}

/*
 * type 过滤是客户视角：
 *   客户 IN  = admin OUT
 *   客户 OUT = admin IN
 */
if ($type === 'IN') {
    $where[] = "txn_type = 'OUT'";
} elseif ($type === 'OUT') {
    $where[] = "txn_type = 'IN'";
}

if (in_array($status, ['DRAFT','SENT','PENDING','CONFIRMED'], true)) {
    $where[]           = "status = :status";
    $params[':status'] = $status;
}

// ✅ Method filter：bank 或 allocate（contra）
if ($onlyContra) {
    $where[] = "(is_contra = 1)";
} elseif ($bankFilterId > 0) {
    // 跟 admin txn_list 的 bank 过滤思路一致（IN 看 payments，OUT 看 bank_account_id + 兼容 payments）
    $where[] = "(
        (txn_type = 'IN' AND EXISTS (
            SELECT 1
            FROM customer_txn_payments p
            WHERE p.customer_txn_id = customer_txn.id
              AND p.bank_account_id = :bank_filter_id
        ))
        OR
        (txn_type = 'OUT' AND (
            customer_txn.bank_account_id = :bank_filter_id
            OR EXISTS (
                SELECT 1
                FROM customer_txn_payments p2
                WHERE p2.customer_txn_id = customer_txn.id
                  AND p2.bank_account_id = :bank_filter_id
            )
        ))
    )";
    $params[':bank_filter_id'] = $bankFilterId;
}

if ($q !== '') {
    $where[] = "(title LIKE :q OR ref_no LIKE :q)";
    $params[':q'] = '%'.$q.'%';
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT *
        FROM customer_txn
        WHERE $whereSql
        ORDER BY txn_date DESC, id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

/**
 * Pending：
 * - admin IN + INVOICE 才有 pending（order_total - sum(payments.amount)）
 * - admin OUT / BONUS / RETURN pending = 0
 *
 * 先收集当前 rows 内所有 admin IN 的 id 去 customer_txn_payments 捞总付款 + bank ids（用于显示 method label）
 */
$paidRawByTxn = [];   // tid => sum(amount)
$bankIdsByTxn = [];   // tid => [bank_id => true]
$inTxnIds     = [];

foreach ($rows as $r) {
    if (($r['txn_type'] ?? '') === 'IN') {
        $inTxnIds[] = (int)$r['id'];
    }
}

if ($inTxnIds) {
    $inClause = implode(',', array_fill(0, count($inTxnIds), '?'));
    try {
        $stPay = $pdo->prepare("
            SELECT customer_txn_id, bank_account_id, amount
              FROM customer_txn_payments
             WHERE customer_txn_id IN ($inClause)
          ORDER BY customer_txn_id, id
        ");
        $stPay->execute($inTxnIds);
        while ($p = $stPay->fetch()) {
            $tid = (int)($p['customer_txn_id'] ?? 0);
            if ($tid <= 0) continue;

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

$page_title = t('txn.list.page_title');
$active_nav = 'txns';

include __DIR__ . '/../include/header.php';
?>

<style>
.txn-row-clickable { cursor: pointer; }
.txn-row-clickable:hover td { background: #f9fafb; }
</style>

<div class="admin-card" style="margin-bottom:18px;">
  <div class="admin-card-header">
    <div>
      <div class="form-page-eyebrow"><?= h(t('txn.list.eyebrow_customer')) ?></div>
      <h1 class="page-title">
        <?= h($customer['name']) ?>
        <?php if (!empty($customer['code'])): ?>
          <span style="font-size:13px;font-weight:500;color:#6b7280;">
            (<?= h($customer['code']) ?>)
          </span>
        <?php endif; ?>
      </h1>
      <div class="form-page-subtitle">
        <?= h(t('txn.list.subtitle')) ?>
      </div>
    </div>
  </div>

  <!-- Filter -->
  <form method="get" style="display:flex; flex-direction:column; gap:12px; font-size:13px;">
    <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
      <div><?php include __DIR__ . '/../../include/date_range.php'; ?></div>

      <div>
        <label class="field-label" style="margin-bottom:4px;"><?= h(t('txn.list.filter.type')) ?></label>
        <select name="type" class="form-control" style="min-width:120px;">
          <option value="ALL" <?= $type==='ALL' ? 'selected' : '' ?>><?= h(t('txn.list.filter.type_all')) ?></option>
          <option value="IN"  <?= $type==='IN'  ? 'selected' : '' ?>><?= h(t('txn.list.filter.type_in')) ?></option>
          <option value="OUT" <?= $type==='OUT' ? 'selected' : '' ?>><?= h(t('txn.list.filter.type_out')) ?></option>
        </select>
      </div>

      <div>
        <label class="field-label" style="margin-bottom:4px;"><?= h(t('txn.list.filter.status')) ?></label>
        <select name="status" class="form-control" style="min-width:120px;">
          <option value="ALL" <?= $status==='ALL' ? 'selected' : '' ?>><?= h(t('txn.list.filter.status_all')) ?></option>
          <option value="DRAFT"    <?= $status==='DRAFT'    ? 'selected' : '' ?>><?= h(t('status.draft')) ?></option>
          <option value="SENT"     <?= $status==='SENT'     ? 'selected' : '' ?>><?= h(t('status.sent')) ?></option>
          <option value="PENDING"  <?= $status==='PENDING'  ? 'selected' : '' ?>><?= h(t('status.pending')) ?></option>
          <option value="CONFIRMED"<?= $status==='CONFIRMED'? 'selected' : '' ?>><?= h(t('status.confirmed')) ?></option>
        </select>
      </div>

      <!-- ✅ Method: bank / allocate -->
      <div>
        <label class="field-label" style="margin-bottom:4px;">
          <?= h(t('txn.list.filter.method', [], 'Bank / Allocate')) ?>
        </label>
        <select name="method" class="form-control" style="min-width:260px;">
          <option value="ALL" <?= $method==='ALL' ? 'selected' : '' ?>>
            <?= h(t('common.all', [], 'All')) ?>
          </option>

          <?php foreach ($bankRows as $b): ?>
            <?php $val = 'BANK_' . (int)$b['id']; ?>
            <option value="<?= h($val) ?>" <?= $method===$val ? 'selected' : '' ?>>
              <?= h(bank_label_view($b)) ?>
            </option>
          <?php endforeach; ?>

          <option value="ALLOCATE" <?= $method==='ALLOCATE' ? 'selected' : '' ?>>
            <?= h(t('txn.list.filter.allocate_only', [], 'Allocate (contra only)')) ?>
          </option>
        </select>
      </div>

      <div style="min-width:220px;">
        <label class="field-label" style="margin-bottom:4px;"><?= h(t('txn.list.filter.keyword')) ?></label>
        <input type="text" name="q" class="form-control"
               placeholder="<?= h(t('txn.list.filter.keyword_ph')) ?>"
               value="<?= h($q) ?>">
      </div>

      <div style="margin-left:auto; display:flex; gap:8px;">
        <button type="submit" class="btn btn-primary"><?= h(t('common.apply')) ?></button>
        <a href="<?= h(url('user/txn/txns.php')) ?>" class="btn btn-light"><?= h(t('common.reset')) ?></a>
      </div>
    </div>
  </form>
</div>

<div class="admin-card">
  <table class="table">
    <thead>
      <tr>
        <th style="width:110px;"><?= h(t('txn.list.col.date')) ?></th>
        <th style="width:90px;"><?= h(t('txn.list.col.type')) ?></th>
        <th style="width:240px;"><?= h(t('txn.list.col.method')) ?></th>
        <th><?= h(t('txn.list.col.title')) ?></th>
        <th style="width:140px;text-align:right;"><?= h(t('txn.list.col.amount')) ?></th>
        <th style="width:140px;text-align:right;"><?= h(t('txn.list.col.pending')) ?></th>
        <th style="width:90px;"><?= h(t('txn.list.col.status')) ?></th>
        <th style="width:90px;"><?= h(t('txn.list.col.action', [], 'Action')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr>
        <td colspan="8" style="padding:14px;font-size:13px;color:#6b7280;">
          <?= h(t('txn.list.empty')) ?>
        </td>
      </tr>
    <?php else: ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $tid      = (int)($r['id'] ?? 0);
          $amount   = (float)($r['amount'] ?? 0);
          $isContra = (int)($r['is_contra'] ?? 0) === 1;

          $adminType = (string)($r['txn_type'] ?? '');
          $inKind    = strtoupper((string)($r['in_kind'] ?? 'INVOICE'));

          // 客户角度 type
          if ($adminType === 'OUT') {
              $custType  = t('txn.type.in');
              $typeColor = '#166534';
          } else {
              $custType  = t('txn.type.out');
              $typeColor = '#b91c1c';
          }

          // Method label（bank label 优先）
          $methodLabel = '-';
          $bankLabels  = [];

          if ($adminType === 'IN') {
              if ($inKind === 'INVOICE') {
                  if (!empty($bankIdsByTxn[$tid])) {
                      foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                          if (isset($bankMap[$bid])) $bankLabels[] = $bankMap[$bid];
                      }
                  }
              } else {
                  $m = strtoupper((string)($r['method'] ?? ($r['pay_source_type'] ?? '')));
                  $methodLabel = $m !== '' ? $m : '-';
              }
          } else {
              $outBankId = (int)($r['bank_account_id'] ?? 0);
              if ($outBankId > 0 && isset($bankMap[$outBankId])) $bankLabels[] = $bankMap[$outBankId];

              if (!empty($bankIdsByTxn[$tid])) {
                  foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                      if (isset($bankMap[$bid])) $bankLabels[] = $bankMap[$bid];
                  }
              }

              if (!$bankLabels) {
                  $m = strtoupper((string)($r['method'] ?? ''));
                  $methodLabel = $m !== '' ? $m : '-';
              }
          }

          $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
          if ($bankLabels) $methodLabel = implode(' / ', $bankLabels);

          // Pending：只有 admin IN + INVOICE，且流程未 REJECTED
          $pendingVal = 0.0;
          if ($adminType === 'IN' && $inKind === 'INVOICE') {
              $orderTotal = (float)($r['order_total'] ?? $amount);
              $paidRaw    = (float)($paidRawByTxn[$tid] ?? 0);

              $pendingVal = max(0.0, $orderTotal - $paidRaw);

              // 已确认或流程已 REJECTED：不再显示 pending
              $docFlowStat = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
              if (($r['status'] ?? '') === 'CONFIRMED' || $docFlowStat === 'REJECTED') {
                  $pendingVal = 0.0;
              }
          }

          $rowCurrency = $r['currency'] ?: $currency;
          $displayDate = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);

          // ✅ 全部 action 统一进 txn_view（避免 OUT 单误跳到 IN 专用的 receipt 页面）
          $targetUrl = url('user/txn/txn_view.php?id=' . $tid);
        ?>

        <tr class="txn-row-clickable" onclick="window.location.href='<?= h($targetUrl) ?>'">
          <td><?= h($displayDate) ?></td>

          <td>
            <div style="display:flex;flex-direction:column;gap:2px;">
              <span style="font-size:12px;font-weight:600;color:<?= h($typeColor) ?>;">
                <?= h($custType) ?>
              </span>
              <?php if ($isContra): ?>
                <span style="font-size:10px;color:#6b7280;"><?= h(t('txn.badge.contra')) ?></span>
              <?php endif; ?>
              <?php if ($adminType === 'IN' && $inKind !== 'INVOICE'): ?>
                <span style="font-size:10px;color:#6b7280;"><?= h($inKind) ?></span>
              <?php endif; ?>
            </div>
          </td>

          <td><?= h($methodLabel) ?></td>

          <td>
            <div style="font-size:13px;font-weight:500;">
              <?= h($r['title'] ?? '') ?>
            </div>
            <?php if (!empty($r['ref_no'])): ?>
              <div style="font-size:11px;color:#6b7280;"><?= h(t('txn.list.ref_prefix')) ?> <?= h($r['ref_no']) ?></div>
            <?php endif; ?>
          </td>

          <td style="text-align:right;">
            <?= h($rowCurrency) ?> <?= number_format($amount, 2) ?>
          </td>

          <td style="text-align:right;">
            <?= h($rowCurrency) ?> <?= number_format($pendingVal, 2) ?>
          </td>

          <?php
            $rawStatus = (string)($r['status'] ?? '');
            $flowStat  = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
            // doc_flow_status = REJECTED 时，优先显示 REJECTED
            if ($flowStat === 'REJECTED') {
              $displayStatus = 'REJECTED';
            } else {
              $displayStatus = strtoupper($rawStatus ?: 'DRAFT');
            }
          ?>
          <td>
            <?php if ($displayStatus === 'CONFIRMED'): ?>
              <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#ecfdf5; color:#166534;">
                <?= h(t('status.confirmed')) ?>
              </span>
            <?php elseif ($displayStatus === 'PENDING'): ?>
              <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#dbeafe; color:#1d4ed8;">
                <?= h(t('status.pending')) ?>
              </span>
            <?php elseif ($displayStatus === 'SENT'): ?>
              <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#fef9c3; color:#854d0e;">
                <?= h(t('status.sent')) ?>
              </span>
            <?php elseif ($displayStatus === 'REJECTED'): ?>
              <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#fee2e2; color:#b91c1c;">
                REJECTED
              </span>
            <?php else: ?>
              <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#e5e7eb; color:#374151;">
                <?= h(t('status.draft')) ?>
              </span>
            <?php endif; ?>
          </td>

          <td onclick="event.stopPropagation();">
            <a href="<?= h($targetUrl) ?>" class="btn btn-light btn-sm">
              <?= h(t('txn.list.btn_view', [], 'View')) ?>
            </a>
          </td>
        </tr>

      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
