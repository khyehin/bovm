<?php
// public/user/company1/invoices.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

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
  } catch (Throwable $e) {}
  return $cache[$k] = $cols;
}

$txnCols = table_columns($pdo, 'customer_txn');
$hasDocFlowType = isset($txnCols['doc_flow_type']);
$hasDocFlowStat = isset($txnCols['doc_flow_status']);

// only customers category_id=3
$allCustomers = $pdo->query("SELECT id, name, code FROM customers WHERE category_id = 3 ORDER BY name ASC, id ASC")->fetchAll();
$customerMap = [];
foreach ($allCustomers as $c) $customerMap[(int)$c['id']] = $c;

$cid = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$customer = ($cid > 0 && isset($customerMap[$cid])) ? $customerMap[$cid] : null;

// POST actions: create quotation / process to invoice / reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create_quotation') {
    $targetCid = (int)($_POST['new_customer_id'] ?? $cid);
    if ($targetCid > 0 && isset($customerMap[$targetCid])) {
      header('Location: ' . url('user/company1/quotation_edit.php?customer_id=' . $targetCid));
      exit;
    }
  } elseif ($action === 'set_flow_status') {
    $txnId = (int)($_POST['txn_id'] ?? 0);
    $next = strtoupper(trim((string)($_POST['next_status'] ?? '')));
    if ($txnId > 0 && in_array($next, ['REJECTED', 'PROCESSING'], true)) {
      $st = $pdo->prepare("SELECT id, customer_id, txn_type, invoice_no FROM customer_txn WHERE id=:id LIMIT 1");
      $st->execute([':id' => $txnId]);
      $row = $st->fetch();
      if ($row && (string)($row['txn_type'] ?? '') === 'IN') {
        $txCid = (int)($row['customer_id'] ?? 0);
        if ($txCid > 0) {
          $stc = $pdo->prepare("SELECT id FROM customers WHERE id=:id AND category_id=3 LIMIT 1");
          $stc->execute([':id' => $txCid]);
          if ($stc->fetch()) {
            $set = ['updated_at = NOW()'];
            $params = [':id' => $txnId];

            if ($hasDocFlowStat) { $set[] = "doc_flow_status = :dfs"; $params[':dfs'] = $next; }
            if ($hasDocFlowType) {
              if ($next === 'PROCESSING') $set[] = "doc_flow_type = 'NORMAL'";
              if ($next === 'REJECTED') $set[] = "doc_flow_type = 'QUOTATION'";
            }

            // when processing, ensure invoice_no exists
            if ($next === 'PROCESSING') {
              $invNo = trim((string)($row['invoice_no'] ?? ''));
              if ($invNo === '') {
                $txnDate = (string)($pdo->query("SELECT txn_date FROM customer_txn WHERE id=" . (int)$txnId)->fetchColumn() ?: date('Y-m-d'));
                $ym = date('ym', strtotime($txnDate));
                $prefix = "VM{$ym}-";
                $st2 = $pdo->prepare("SELECT invoice_no FROM customer_txn WHERE invoice_no LIKE :pfx ORDER BY id DESC LIMIT 1");
                $st2->execute([':pfx' => $prefix . '%']);
                $seqNo = 1;
                if ($r2 = $st2->fetch()) {
                  $last3 = (int)substr((string)$r2['invoice_no'], -3);
                  $seqNo = $last3 + 1;
                }
                $invNo = $prefix . str_pad((string)$seqNo, 3, '0', STR_PAD_LEFT);
                $set[] = "invoice_no = :inv";
                $params[':inv'] = $invNo;
              }
            }

            $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
          }
        }
      }
    }
    header('Location: ' . url('user/company1/invoices.php' . ($cid > 0 ? ('?customer_id=' . $cid) : '')));
    exit;
  }
}

// filters
$flow   = (string)($_GET['flow'] ?? 'ALL');          // ALL / NORMAL / QUOTATION
$status = (string)($_GET['flow_status'] ?? 'ALL');   // ALL / DRAFT / PROCESSING / REJECTED / COMPLETED
$q      = trim((string)($_GET['q'] ?? ''));
$date_from = trim((string)($_GET['date_from'] ?? ''));
$date_to   = trim((string)($_GET['date_to'] ?? ''));
$date_all  = trim((string)($_GET['date_all'] ?? ''));

$where = ["t.txn_type = 'IN'", "(UPPER(COALESCE(t.in_kind,'')) = '' OR UPPER(COALESCE(t.in_kind,'')) LIKE '%INVOICE%')"];
$params = [];

// limit to category 3 customers only
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

$page_title = 'Company1 · Invoices & Quotations';
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow" style="max-width:1100px;">
      <div class="form-page-header" style="margin-bottom:16px;">
        <div>
          <div class="form-page-eyebrow">Customers</div>
          <h2 class="form-page-title">
            <?php if ($customer): ?>
              <?= h($customer['name'] ?? '') ?>
              <?php if (!empty($customer['code'])): ?>
                <span style="font-size:13px;font-weight:500;color:#6b7280;">
                  (<?= h((string)$customer['code']) ?>)
                </span>
              <?php endif; ?>
            <?php else: ?>
              Invoices &amp; Quotations
            <?php endif; ?>
          </h2>
          <div class="form-page-subtitle">
            Create quotation, process to invoice, and view document status.
          </div>
        </div>
        <div class="form-page-meta" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <a href="<?= h(url('user/company1/customers.php')) ?>" class="btn btn-light">Customer List</a>
          <?php if ($customer): ?>
            <a href="<?= h(url('user/company1/invoices.php')) ?>" class="btn btn-light">All invoices</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($customer): ?>
        <div class="admin-card" style="margin-bottom:14px;">
          <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:12px;font-size:13px;">
            <div>
              <div style="font-weight:600;margin-bottom:4px;"><?= h($customer['name'] ?? '') ?></div>
              <?php if (!empty($customer['code'])): ?>
                <div style="color:#6b7280;">Code: <?= h((string)$customer['code']) ?></div>
              <?php endif; ?>
              <?php
                try {
                  $stInfo = $pdo->prepare("SELECT contact_name, contact_phone, contact_email, address1, address2, address3, city, state, postcode, country FROM customers WHERE id = :id");
                  $stInfo->execute([':id' => $cid]);
                  $info = $stInfo->fetch() ?: [];
                } catch (Throwable $e) {
                  $info = [];
                }
              ?>
              <?php if (!empty($info['contact_name']) || !empty($info['contact_phone']) || !empty($info['contact_email'])): ?>
                <div style="margin-top:4px;color:#374151;">
                  <?php if (!empty($info['contact_name'])): ?>
                    <div>Contact: <?= h((string)$info['contact_name']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($info['contact_phone'])): ?>
                    <div>Phone: <?= h((string)$info['contact_phone']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($info['contact_email'])): ?>
                    <div>Email: <?= h((string)$info['contact_email']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="max-width:280px;color:#6b7280;">
              <?php
                $addrLines = [];
                if (!empty($info['address1'])) $addrLines[] = (string)$info['address1'];
                if (!empty($info['address2'])) $addrLines[] = (string)$info['address2'];
                if (!empty($info['address3'])) $addrLines[] = (string)$info['address3'];
                $cityLine = trim(((string)($info['postcode'] ?? '') . ' ' . (string)($info['city'] ?? '')));
                if ($cityLine !== '') $addrLines[] = $cityLine;
                $stateCountry = trim(((string)($info['state'] ?? '') . ', ' . (string)($info['country'] ?? '')));
                if ($stateCountry !== ',') $addrLines[] = $stateCountry;
              ?>
              <?php if ($addrLines): ?>
                <div style="white-space:pre-line;"><?= h(implode("\n", $addrLines)) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="admin-card" style="margin-bottom:14px;">
        <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="action" value="create_quotation">
          <div>
            <label class="field-label" style="margin-bottom:4px;">Customer for new quotation</label>
            <input type="hidden" name="new_customer_id" id="newCustomerId" value="">
            <input type="text" class="form-control" id="newCustomerSearch" list="customerSuggestList" style="min-width:280px;" placeholder="Type customer name / code..." autocomplete="off" required>
            <datalist id="customerSuggestList">
              <?php foreach ($allCustomers as $c): ?>
                <?php $label = (string)$c['name'] . (!empty($c['code']) ? (' (' . $c['code'] . ')') : ''); ?>
                <option value="<?= h($label) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>
          <div><button type="submit" class="btn btn-primary">+ New Quotation</button></div>
        </form>
      </div>

      <div class="admin-card" style="margin-bottom:14px;">
        <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:13px;">
          <div>
            <label class="field-label" style="margin-bottom:4px;">Customer</label>
            <select name="customer_id" class="form-control" style="min-width:220px;">
              <option value="0">All</option>
              <?php foreach ($allCustomers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cid) ? 'selected' : '' ?>>
                  <?= h((string)$c['name'] . (!empty($c['code']) ? (' (' . $c['code'] . ')') : '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">Keyword</label>
            <input type="text" name="q" class="form-control" style="min-width:180px;" value="<?= h($q) ?>" placeholder="Title, Invoice No...">
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
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="<?= h(url('user/company1/invoices.php')) ?>" class="btn btn-light">Reset</a>
          </div>
        </form>
      </div>

      <div class="admin-card">
        <table class="table">
          <thead>
            <tr>
              <th style="width:110px;">Date</th>
              <th style="width:220px;">Customer</th>
              <th style="width:130px;">Invoice No</th>
              <th style="width:110px;">Doc type</th>
              <th style="width:130px;">Process</th>
              <th>Title</th>
              <th style="width:150px;text-align:right;">Amount</th>
              <th style="width:80px;" class="table-actions-cell">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="8" style="padding:14px;color:#6b7280;">No items.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $rowCid = (int)($r['customer_id'] ?? 0);
                  $dt = (string)($r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10));
                  $invNo = trim((string)($r['invoice_no'] ?? ''));
                  $dfTypeLabel = ($invNo !== '') ? 'Invoice' : 'Quotation';
                  $dfStat = strtoupper((string)($hasDocFlowStat ? ($r['doc_flow_status'] ?? 'DRAFT') : 'DRAFT'));
                  if (!in_array($dfStat, ['DRAFT', 'PROCESSING', 'REJECTED', 'COMPLETED'], true)) $dfStat = 'DRAFT';
                  $statusLabel = ($dfStat === 'COMPLETED') ? 'Complete' : ucfirst(strtolower($dfStat));
                  $statusStyle = 'background:#e5e7eb;color:#374151;';
                  if ($dfStat === 'REJECTED') $statusStyle = 'background:#fee2e2;color:#b91c1c;';
                  elseif ($dfStat === 'PROCESSING') $statusStyle = 'background:#dbeafe;color:#1d4ed8;';
                  elseif ($dfStat === 'COMPLETED') $statusStyle = 'background:#dcfce7;color:#166534;';
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
                  $isQuotationRow = ($invNo === '');
                ?>
                <tr>
                  <td><?= h($dt) ?></td>
                  <td><?= h((string)($r['customer_name'] ?? '')) ?></td>
                  <td><?= $invNo !== '' ? h($invNo) : '—' ?></td>
                  <td><?= h($dfTypeLabel) ?></td>
                  <td>
                    <?php if (!$isQuotationRow): ?>
                      <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>"><?= h($statusLabel) ?></span>
                    <?php else: ?>
                      <?php if ($dfStat === 'REJECTED'): ?>
                        <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>"><?= h($statusLabel) ?></span>
                      <?php else: ?>—<?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td><?= h($title) ?></td>
                  <td style="text-align:right;"><?= h($cur) ?> <?= number_format($amt, 2) ?></td>
                  <td class="table-actions-cell">
                    <div class="actions-menu">
                      <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                      <div class="actions-menu-dropdown">
                        <?php if ($isQuotationRow): ?>
                          <a href="<?= h(url('user/company1/quotation_edit.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid)) ?>" class="actions-menu-item">Edit Quotation</a>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="set_flow_status">
                            <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="next_status" value="PROCESSING">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">Process → Invoice</button>
                          </form>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="set_flow_status">
                            <input type="hidden" name="txn_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="next_status" value="REJECTED">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">Reject</button>
                          </form>
                          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=QUOTATION')) ?>" class="actions-menu-item" target="_blank">View Quotation</a>
                        <?php else: ?>
                          <a href="<?= h(url('user/company1/txn_edit_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid)) ?>" class="actions-menu-item">View / edit IN</a>
                          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=INVOICE')) ?>" class="actions-menu-item" target="_blank">View Invoice</a>
                          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . (int)$r['id'] . '&customer_id=' . $rowCid . '&doc=DO')) ?>" class="actions-menu-item" target="_blank">View DO</a>
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
</div>

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
  var form = input.closest('form');
  if (form) {
    form.addEventListener('submit', function(e){
      sync();
      if (!hid.value) { e.preventDefault(); input.focus(); }
    });
  }
})();
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>

