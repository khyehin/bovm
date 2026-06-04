<?php
// public/admin/customers/invoices.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.V');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('tt')) {
  function tt(string $key, string $fallback, array $params = []): string
  {
    if (function_exists('t')) return (string)t($key, $params, $fallback);
    return $fallback;
  }
}

function table_columns(PDO $pdo, string $table): array
{
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
$hasAddrSnap1    = isset($txnCols['customer_addr1_snapshot']);
$hasAddrSnap2    = isset($txnCols['customer_addr2_snapshot']);
$hasAddrSnap3    = isset($txnCols['customer_addr3_snapshot']);
$hasAddrSnapMeta = isset($txnCols['customer_addr_city_state_postcode_snapshot']);
$hasCanEditFn   = function_exists('can');
$canEdit        = $hasCanEditFn ? (bool)can('TXN.E') : true;

$allCustomers = $pdo->query("SELECT id, name, code FROM customers ORDER BY name ASC, id ASC")->fetchAll();
$customerMap = [];
foreach ($allCustomers as $c) {
  $customerMap[(int)$c['id']] = $c;
}

$cid = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$customer = ($cid > 0 && isset($customerMap[$cid])) ? $customerMap[$cid] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_quotation') {
    $targetCid = (int)($_POST['new_customer_id'] ?? $cid);
    if ($targetCid > 0 && isset($customerMap[$targetCid])) {
      header('Location: ' . url('admin/customers/quotation_edit.php?customer_id=' . $targetCid));
      exit;
    }
  } elseif ($action === 'set_flow_status') {
    $txnId = (int)($_POST['txn_id'] ?? 0);
    $next = strtoupper(trim((string)($_POST['next_status'] ?? '')));
    $goEdit = ((int)($_POST['go_edit'] ?? 0) === 1);
    $updatedCustomerId = 0;

    if ($txnId > 0 && in_array($next, ['REJECTED', 'PROCESSING', 'COMPLETED'], true)) {
      $st = $pdo->prepare("SELECT id, customer_id, txn_type FROM customer_txn WHERE id=:id LIMIT 1");
      $st->execute([':id' => $txnId]);
      $row = $st->fetch();

      if ($row && (string)($row['txn_type'] ?? '') === 'IN') {
        $updatedCustomerId = (int)($row['customer_id'] ?? 0);
        $set = ['updated_at = NOW()'];
        $params = [':id' => $txnId];

        if ($hasDocFlowStat) {
          $set[] = "doc_flow_status = :dfs";
          $params[':dfs'] = $next;
        }
        if ($hasDocFlowType) {
          if ($next === 'PROCESSING' || $next === 'COMPLETED') {
            $set[] = "doc_flow_type = 'NORMAL'";
          } elseif ($next === 'REJECTED') {
            $set[] = "doc_flow_type = 'QUOTATION'";
          }
        }

        // 如果切到 COMPLETED：写入“完成时地址快照”，避免之后客户地址变更影响已完成单据打印
        if (
          $next === 'COMPLETED'
          && ($hasAddrSnap1 || $hasAddrSnap2 || $hasAddrSnap3 || $hasAddrSnapMeta)
          && $updatedCustomerId > 0
        ) {
          $stC = $pdo->prepare("
            SELECT address1, address2, address3, city, state, postcode
            FROM customers
            WHERE id = :cid
            LIMIT 1
          ");
          $stC->execute([':cid' => $updatedCustomerId]);
          $c = $stC->fetch(PDO::FETCH_ASSOC) ?: [];

          $addrLine4 = trim(implode(' ', array_filter([
            (string)($c['city'] ?? ''),
            (string)($c['state'] ?? ''),
            (string)($c['postcode'] ?? ''),
          ])));

          $params[':cas1'] = (string)($c['address1'] ?? '');
          $params[':cas2'] = (string)($c['address2'] ?? '');
          $params[':cas3'] = (string)($c['address3'] ?? '');
          $params[':cam']  = $addrLine4;

          if ($hasAddrSnap1)    $set[] = "customer_addr1_snapshot = IFNULL(customer_addr1_snapshot, :cas1)";
          if ($hasAddrSnap2)    $set[] = "customer_addr2_snapshot = IFNULL(customer_addr2_snapshot, :cas2)";
          if ($hasAddrSnap3)    $set[] = "customer_addr3_snapshot = IFNULL(customer_addr3_snapshot, :cas3)";
          if ($hasAddrSnapMeta) $set[] = "customer_addr_city_state_postcode_snapshot = IFNULL(customer_addr_city_state_postcode_snapshot, :cam)";
        }

        $sql = "UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id = :id";
        $pdo->prepare($sql)->execute($params);
      }
    }

    if ($goEdit && $txnId > 0 && $updatedCustomerId > 0) {
      header('Location: ' . url('admin/customers/txn_edit_in.php?id=' . $txnId . '&customer_id=' . $updatedCustomerId));
      exit;
    }

    $backQuery = [
      'customer_id' => $cid,
      'flow' => (string)($_GET['flow'] ?? 'ALL'),
      'flow_status' => (string)($_GET['flow_status'] ?? 'ALL'),
    ];
    if ($backQuery['customer_id'] <= 0) unset($backQuery['customer_id']);
    header('Location: ' . url('admin/customers/invoices.php?' . http_build_query($backQuery)));
    exit;
  } elseif ($action === 'delete_txn') {
    $txnId = (int)($_POST['txn_id'] ?? 0);
    if ($txnId > 0) {
      try {
        // safety: only IN txn
        $pdo->prepare("DELETE FROM customer_txn WHERE id = :id AND txn_type = 'IN'")->execute([':id' => $txnId]);
      } catch (Throwable $e) {
      }
    }
    $backQuery = [
      'customer_id' => $cid,
      'flow' => (string)($_GET['flow'] ?? 'ALL'),
      'flow_status' => (string)($_GET['flow_status'] ?? 'ALL'),
    ];
    if ($backQuery['customer_id'] <= 0) unset($backQuery['customer_id']);
    header('Location: ' . url('admin/customers/invoices.php?' . http_build_query($backQuery)));
    exit;
  }
}

$flow   = (string)($_GET['flow'] ?? 'ALL');          // ALL / NORMAL / QUOTATION
$status = (string)($_GET['flow_status'] ?? 'ALL');   // ALL / DRAFT / PROCESSING / REJECTED / COMPLETED
$q      = trim((string)($_GET['q'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$date_all  = trim((string)($_GET['date_all'] ?? ''));

$where = ["t.txn_type = 'IN'", "(UPPER(COALESCE(t.in_kind,'')) = '' OR UPPER(COALESCE(t.in_kind,'')) LIKE '%INVOICE%')"];
$params = [];

// 只看 category_id = 3
$where[] = "c.category_id = 3";

if ($cid > 0) {
  $where[] = "t.customer_id = :cid";
  $params[':cid'] = $cid;
}

if ($hasDocFlowType && in_array($flow, ['NORMAL', 'QUOTATION'], true)) {
  $where[] = "t.doc_flow_type = :flow_type";
  $params[':flow_type'] = $flow;
}

if ($hasDocFlowStat && in_array($status, ['DRAFT', 'PROCESSING', 'REJECTED', 'COMPLETED'], true)) {
  $where[] = "t.doc_flow_status = :flow_status";
  $params[':flow_status'] = $status;
}

if ($q !== '') {
  $where[] = "(t.title LIKE :q OR t.invoice_no LIKE :q OR t.notes LIKE :q OR t.ref_no LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

if ($date_all !== '1' && $date_from !== '') {
  $where[] = "COALESCE(t.txn_date, DATE(t.created_at)) >= :date_from";
  $params[':date_from'] = $date_from;
}
if ($date_all !== '1' && $date_to !== '') {
  $where[] = "COALESCE(t.txn_date, DATE(t.created_at)) <= :date_to";
  $params[':date_to'] = $date_to;
}

$sql = "
  SELECT t.*, c.name AS customer_name, c.code AS customer_code
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY t.txn_date DESC, t.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// auto-sync: if invoice already CONFIRMED, mark flow status COMPLETED
if ($hasDocFlowStat && $rows) {
  $toComplete = [];
  foreach ($rows as $r) {
    $invNo = trim((string)($r['invoice_no'] ?? ''));
    if ($invNo === '') continue; // quotation
    $stt = strtoupper(trim((string)($r['status'] ?? '')));
    $dfs = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
    if ($stt === 'CONFIRMED' && $dfs !== 'COMPLETED') {
      $toComplete[] = (int)($r['id'] ?? 0);
    }
  }
  $toComplete = array_values(array_filter($toComplete, fn($x) => $x > 0));
  if ($toComplete) {
    $in = implode(',', array_fill(0, count($toComplete), '?'));
    try {
      if ($hasAddrSnap1 || $hasAddrSnap2 || $hasAddrSnap3 || $hasAddrSnapMeta) {
        $setParts = ["t.doc_flow_status='COMPLETED'", "t.updated_at=NOW()"];
        if ($hasAddrSnap1)    $setParts[] = "t.customer_addr1_snapshot = IFNULL(t.customer_addr1_snapshot, c.address1)";
        if ($hasAddrSnap2)    $setParts[] = "t.customer_addr2_snapshot = IFNULL(t.customer_addr2_snapshot, c.address2)";
        if ($hasAddrSnap3)    $setParts[] = "t.customer_addr3_snapshot = IFNULL(t.customer_addr3_snapshot, c.address3)";
        if ($hasAddrSnapMeta) $setParts[] = "t.customer_addr_city_state_postcode_snapshot = IFNULL(t.customer_addr_city_state_postcode_snapshot, CONCAT_WS(' ', NULLIF(c.city,''), NULLIF(c.state,''), NULLIF(c.postcode,'')))";

        $sqlAuto = "
          UPDATE customer_txn t
          JOIN customers c ON c.id = t.customer_id
          SET " . implode(', ', $setParts) . "
          WHERE t.id IN ($in)
        ";
        $pdo->prepare($sqlAuto)->execute($toComplete);
      } else {
        $pdo->prepare("UPDATE customer_txn SET doc_flow_status='COMPLETED', updated_at=NOW() WHERE id IN ($in)")
          ->execute($toComplete);
      }
      // also reflect in memory so UI shows immediately
      foreach ($rows as &$r) {
        if (in_array((int)($r['id'] ?? 0), $toComplete, true)) {
          $r['doc_flow_status'] = 'COMPLETED';
        }
      }
      unset($r);
    } catch (Throwable $e) {}
  }
}

if ($customer) {
  $page_title = tt('admin.customer_invoices.title', 'Invoices & Quotations: %s', ['s' => (string)($customer['name'] ?? '')]);
} else {
  $page_title = tt('admin.customer_invoices.title_all', 'Invoices & Quotations');
}

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h('Customers') ?></div>
          <h1 class="page-title">
            <?php if ($customer): ?>
              <?= h($customer['name'] ?? '') ?>
              <?php if (!empty($customer['code'])): ?>
                <span style="font-size:13px;font-weight:500;color:#6b7280;">(<?= h($customer['code']) ?>)</span>
              <?php endif; ?>
            <?php else: ?>
              <?= h('Invoices & Quotations') ?>
            <?php endif; ?>
          </h1>
          <div class="form-page-subtitle">
            <?= h('Create quotation, process to invoice, and manage flow status.') ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <a href="<?= h(url('admin/customers/list.php')) ?>" class="btn btn-light">
            <?= h('Customer List') ?>
          </a>
          <?php if ($customer): ?>
            <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . (int)$customer['id'])) ?>" class="btn btn-light">
              <?= h('Back to transactions') ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="admin-card" style="margin-bottom:18px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:10px;flex-wrap:wrap;">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:13px;">
          <input type="hidden" name="flow" value="<?= h($flow) ?>">
          <input type="hidden" name="flow_status" value="<?= h($status) ?>">

          <div>
            <label class="field-label" style="margin-bottom:4px;">Customer</label>
            <select name="customer_id" class="form-control" style="min-width:220px;">
              <option value="0">All customers</option>
              <?php foreach ($allCustomers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cid) ? 'selected' : '' ?>>
                  <?= h((string)$c['name'] . (!empty($c['code']) ? (' (' . $c['code'] . ')') : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <button type="submit" class="btn btn-primary">Apply customer</button>
          </div>
        </form>

        <?php if ($canEdit): ?>
          <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="action" value="create_quotation">
            <?php if ($cid > 0): ?>
              <input type="hidden" name="new_customer_id" value="<?= (int)$cid ?>">
            <?php else: ?>
              <div>
                <label class="field-label" style="margin-bottom:4px;">Customer for new quotation</label>
                <input type="hidden" name="new_customer_id" id="newCustomerId" value="">
                <input
                  type="text"
                  class="form-control"
                  id="newCustomerSearch"
                  list="customerSuggestList"
                  style="min-width:260px;"
                  placeholder="Type customer name / code..."
                  autocomplete="off"
                  required
                >
                <datalist id="customerSuggestList">
                  <?php foreach ($allCustomers as $c): ?>
                    <?php $label = (string)$c['name'] . (!empty($c['code']) ? (' (' . $c['code'] . ')') : ''); ?>
                    <option value="<?= h($label) ?>"></option>
                  <?php endforeach; ?>
                </datalist>
              </div>
            <?php endif; ?>
            <div>
              <button type="submit" class="btn btn-primary">+ Add New Quotation</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canEdit && $cid <= 0): ?>
    <script>
    (function(){
      var input = document.getElementById('newCustomerSearch');
      var hid = document.getElementById('newCustomerId');
      if (!input || !hid) return;

      var map = {};
      <?php foreach ($allCustomers as $c): ?>
        map[<?= json_encode((string)$c['name'] . (!empty($c['code']) ? (' (' . $c['code'] . ')') : ''), JSON_UNESCAPED_SLASHES) ?>] = <?= (int)$c['id'] ?>;
      <?php endforeach; ?>

      function sync(){
        var v = (input.value || '').trim();
        hid.value = map[v] ? String(map[v]) : '';
      }
      input.addEventListener('change', sync);
      input.addEventListener('blur', sync);

      // block submit if not selected
      var form = input.closest('form');
      if (form) {
        form.addEventListener('submit', function(e){
          sync();
          if (!hid.value) {
            e.preventDefault();
            input.focus();
          }
        });
      }
    })();
    </script>
    <?php endif; ?>

    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:13px;">
        <?php if ($cid > 0): ?>
          <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
        <?php endif; ?>

        <div>
          <label class="field-label" style="margin-bottom:4px;">Keyword</label>
          <input type="text" name="q" class="form-control" style="min-width:180px;" value="<?= h($q) ?>" placeholder="Title, Invoice No, Notes...">
        </div>
        <?php include __DIR__ . '/../../include/date_range.php'; ?>
        <div>
          <label class="field-label" style="margin-bottom:4px;">Flow type</label>
          <select name="flow" class="form-control" style="min-width:150px;">
            <option value="ALL" <?= $flow === 'ALL' ? 'selected' : '' ?>>All</option>
            <option value="NORMAL" <?= $flow === 'NORMAL' ? 'selected' : '' ?>>Invoice</option>
            <option value="QUOTATION" <?= $flow === 'QUOTATION' ? 'selected' : '' ?>>Quotation</option>
          </select>
        </div>
        <div>
          <label class="field-label" style="margin-bottom:4px;">Flow status</label>
          <select name="flow_status" class="form-control" style="min-width:150px;">
            <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>All</option>
            <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
            <option value="PROCESSING" <?= $status === 'PROCESSING' ? 'selected' : '' ?>>Processing</option>
            <option value="REJECTED" <?= $status === 'REJECTED' ? 'selected' : '' ?>>Rejected</option>
            <option value="COMPLETED" <?= $status === 'COMPLETED' ? 'selected' : '' ?>>Complete</option>
          </select>
        </div>
        <div style="margin-left:auto;display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary"><?= h(tt('admin.common.apply', 'Apply')) ?></button>
          <a href="<?= h(url('admin/customers/invoices.php' . ($cid > 0 ? ('?customer_id=' . $cid) : ''))) ?>" class="btn btn-light">
            <?= h(tt('admin.common.reset', 'Reset')) ?>
          </a>
        </div>
      </form>
    </div>

    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;">Date</th>
            <?php if ($cid <= 0): ?>
              <th style="width:210px;">Customer</th>
            <?php endif; ?>
            <th style="width:130px;">Invoice No</th>
            <th style="width:110px;">Doc type</th>
            <th style="width:130px;">Process</th>
            <th>Title</th>
            <th style="width:150px;text-align:right;">Amount</th>
            <th style="width:60px;" class="table-actions-cell">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="<?= $cid <= 0 ? 8 : 7 ?>" style="padding:14px;font-size:13px;color:#6b7280;">
                No invoices / quotations for this filter.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
              $rowCid = (int)($r['customer_id'] ?? 0);
              $dt = (string)($r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10));
              $invNo = trim((string)($r['invoice_no'] ?? ''));
              $cur = (string)($r['currency'] ?? 'MYR');
              $amt = (float)($r['order_total'] ?? 0);
              if ($amt <= 0) $amt = (float)($r['amount'] ?? 0);
              $customerName = trim((string)($r['customer_name'] ?? ''));
              $curCode = strtoupper(trim($cur));
              $moneyPrefix = ($curCode === 'MYR') ? 'RM ' : (($curCode !== '') ? ($curCode . ' ') : '');
              $moneyText = $moneyPrefix . number_format($amt, 2);
              if ($invNo === '') {
                $title = "QUOTATION '" . $customerName . "' " . $moneyText;
              } else {
                $title = "INVOICE - " . $invNo . " - " . $customerName . " - " . $moneyText;
              }

              $dfType = strtoupper((string)($hasDocFlowType ? ($r['doc_flow_type'] ?? 'NORMAL') : 'NORMAL'));
              if (!in_array($dfType, ['NORMAL', 'QUOTATION'], true)) $dfType = 'NORMAL';
              $dfStat = strtoupper((string)($hasDocFlowStat ? ($r['doc_flow_status'] ?? 'DRAFT') : 'DRAFT'));
              if (!in_array($dfStat, ['DRAFT', 'PROCESSING', 'REJECTED', 'COMPLETED'], true)) $dfStat = 'DRAFT';

              $dfTypeLabel = (trim((string)($r['invoice_no'] ?? '')) !== '') ? 'Invoice' : 'Quotation';
              $statusLabel = ($dfStat === 'COMPLETED') ? 'Complete' : ucfirst(strtolower($dfStat));
              $statusStyle = 'background:#e5e7eb;color:#374151;';
              if ($dfStat === 'REJECTED') $statusStyle = 'background:#fee2e2;color:#b91c1c;';
              elseif ($dfStat === 'PROCESSING') $statusStyle = 'background:#dbeafe;color:#1d4ed8;';
              elseif ($dfStat === 'COMPLETED') $statusStyle = 'background:#dcfce7;color:#166534;';

              $isQuotationRow = ($invNo === '');
              ?>
              <tr>
                <td><?= h($dt) ?></td>
                <?php if ($cid <= 0): ?>
                  <td>
                    <?= h((string)($r['customer_name'] ?? '')) ?>
                    <?php if (!empty($r['customer_code'])): ?>
                      <span style="color:#6b7280;">(<?= h((string)$r['customer_code']) ?>)</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td><?= $invNo !== '' ? h($invNo) : '—' ?></td>
                <td><?= h($dfTypeLabel) ?></td>
                <td>
                  <?php if (!$isQuotationRow): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>">
                      <?= h($statusLabel) ?>
                    </span>
                  <?php else: ?>
                    <?php if ($dfStat === 'REJECTED'): ?>
                      <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>">
                        <?= h($statusLabel) ?>
                      </span>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td><?= h($title) ?></td>
                <td style="text-align:right;"><?= h($cur) ?> <?= number_format($amt, 2) ?></td>
                <td class="table-actions-cell">
                  <div class="actions-menu">
                    <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                    <div class="actions-menu-dropdown">
                      <?php if ($dfStat === 'COMPLETED'): ?>
                        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=DO')) ?>" class="actions-menu-item">View DO</a>
                        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=QUOTATION')) ?>" class="actions-menu-item">View Quotation</a>
                        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=INVOICE')) ?>" class="actions-menu-item">View Invoice</a>

                        <?php if ($canEdit && $dfStat !== 'REJECTED'): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
                            <input type="hidden" name="action" value="set_flow_status">
                            <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="next_status" value="REJECTED">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">
                              Rejected
                            </button>
                          </form>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
                            <input type="hidden" name="action" value="delete_txn">
                            <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;color:#b91c1c;" onclick="return confirm('Delete this transaction?');">
                              Delete
                            </button>
                          </form>
                        <?php endif; ?>
                      <?php else: ?>
                        <?php if ($canEdit && trim((string)($r['invoice_no'] ?? '')) === ''): ?>
                          <a href="<?= h(url('admin/customers/quotation_edit.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid)) ?>" class="actions-menu-item">Edit Quotation</a>
                        <?php endif; ?>
                        <?php if (!$isQuotationRow): ?>
                          <a href="<?= h(url('admin/customers/txn_edit_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid)) ?>" class="actions-menu-item">View / edit IN</a>
                        <?php endif; ?>
                        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=QUOTATION')) ?>" class="actions-menu-item">View Quotation</a>
                        <?php if (!$isQuotationRow): ?>
                          <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=INVOICE')) ?>" class="actions-menu-item">View Invoice</a>
                          <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=DO')) ?>" class="actions-menu-item">View DO</a>
                        <?php endif; ?>

                        <?php if ($canEdit): ?>
                          <?php if ($dfStat !== 'PROCESSING'): ?>
                            <form method="post" style="margin:0;">
                              <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="action" value="set_flow_status">
                              <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                              <input type="hidden" name="next_status" value="PROCESSING">
                              <input type="hidden" name="go_edit" value="1">
                              <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">
                                Process (go to edit IN)
                              </button>
                            </form>
                          <?php endif; ?>
                          <?php if (!$isQuotationRow && $dfStat !== 'COMPLETED'): ?>
                            <form method="post" style="margin:0;">
                              <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="action" value="set_flow_status">
                              <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                              <input type="hidden" name="next_status" value="COMPLETED">
                              <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">
                                Set Complete
                              </button>
                            </form>
                          <?php endif; ?>
                          <?php if ($dfStat !== 'REJECTED'): ?>
                            <form method="post" style="margin:0;">
                              <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="action" value="set_flow_status">
                              <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                              <input type="hidden" name="next_status" value="REJECTED">
                              <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">
                                Set Reject
                              </button>
                            </form>
                          <?php endif; ?>
                        <?php endif; ?>
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

