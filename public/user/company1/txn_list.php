<?php
// public/user/company1/txn_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

// 加载 customer（只允许 category_id = 3）
$cid = (int)($_GET['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND category_id = 3");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found or not category 3');
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
$hasDocFlowStat = isset($txnCols['doc_flow_status']);
$hasDocFlowType = isset($txnCols['doc_flow_type']);

// POST: Process → Invoice / Reject（与 invoices.php 同逻辑，跳回本页）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'set_flow_status') {
    $txnId = (int)($_POST['txn_id'] ?? 0);
    $next = strtoupper(trim((string)($_POST['next_status'] ?? '')));
    if ($txnId > 0 && in_array($next, ['REJECTED', 'PROCESSING'], true)) {
      $st = $pdo->prepare("SELECT id, customer_id, txn_type, invoice_no, txn_date FROM customer_txn WHERE id=:id LIMIT 1");
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
            if ($next === 'PROCESSING') {
              $invNo = trim((string)($row['invoice_no'] ?? ''));
              if ($invNo === '') {
                $txnDate = (string)($row['txn_date'] ?? date('Y-m-d'));
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
    $backQ = ['customer_id' => $cid];
    if (!empty($_GET['date_from'])) $backQ['date_from'] = $_GET['date_from'];
    if (!empty($_GET['date_to'])) $backQ['date_to'] = $_GET['date_to'];
    if (!empty($_GET['type']) && $_GET['type'] !== 'ALL') $backQ['type'] = $_GET['type'];
    if (!empty($_GET['status']) && $_GET['status'] !== 'ALL') $backQ['status'] = $_GET['status'];
    if (!empty($_GET['q'])) $backQ['q'] = $_GET['q'];
    header('Location: ' . url('user/company1/txn_list.php?' . http_build_query($backQ)));
    exit;
  }
}

// 复制 admin 的 detect_in_kind（简化版）
function detect_in_kind_simple(array $r): string {
  $raw = strtoupper(trim((string)($r['in_kind'] ?? '')));
  if ($raw !== '') {
    // company1 视角：IN + ALLOCATE 也当成 INVOICE 显示（不让前台看到 Allocate）
    if ($raw === 'ALLOCATE' && strtoupper(trim((string)($r['txn_type'] ?? ''))) === 'IN') {
      return 'INVOICE';
    }
    if (strpos($raw, 'BONUS') !== false) return 'BONUS';
    if (strpos($raw, 'RETURN') !== false) return 'RETURN';
    if (strpos($raw, 'INVOICE') !== false || strpos($raw, 'INV') !== false) return 'INVOICE';
  }
  $title = strtolower(trim((string)($r['title'] ?? '')));
  if ($title !== '') {
    if (strpos($title, 'bonus') !== false) return 'BONUS';
    if (strpos($title, 'repay') !== false || strpos($title, 'return') !== false) return 'RETURN';
  }
  return 'INVOICE';
}

// 过滤参数（沿用 admin 结构，但只读）
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$type      = $_GET['type']      ?? 'ALL';      // ALL / IN / OUT
$status    = $_GET['status']    ?? 'ALL';      // ALL / DRAFT / SENT / PENDING / CONFIRMED
$q         = trim($_GET['q']    ?? '');

$where  = ["customer_id = :cid"];
$params = [':cid' => $cid];

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

if (in_array($status, ['DRAFT', 'SENT', 'PENDING', 'CONFIRMED'], true)) {
  $where[]           = "status = :status";
  $params[':status'] = $status;
}

if ($q !== '') {
  $where[]      = "(title LIKE :q OR ref_no LIKE :q OR notes LIKE :q OR invoice_no LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);

// 取列表（按日期倒序）
$sql = "SELECT * FROM customer_txn WHERE $whereSql ORDER BY txn_date DESC, id DESC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// 预先算每个 txn 的 paid，用于 Pending 列
$paidByTxn = [];
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
      $stPay = $pdo->prepare("SELECT customer_txn_id, SUM(amount) AS total_paid FROM customer_txn_payments WHERE customer_txn_id IN ($in) GROUP BY customer_txn_id");
      $stPay->execute($ids);
      foreach ($stPay->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $tid = (int)($p['customer_txn_id'] ?? 0);
        if ($tid > 0) $paidByTxn[$tid] = (float)($p['total_paid'] ?? 0);
      }
    } catch (Throwable $e) {}
  }
}

// -------- Summary（简化版，结构跟 admin 一样） -------- //
$total_in_normal  = 0.0;
$total_out_normal = 0.0;
$bonus_total      = 0.0;
$repay_total      = 0.0;
$loan_total       = 0.0;

foreach ($rows as $r) {
  $tType = strtoupper(trim((string)($r['txn_type'] ?? '')));
  if ($tType === 'IN') {
    $kind = detect_in_kind_simple($r); // INVOICE / BONUS / RETURN
    $amt  = (float)($r['amount'] ?? 0);
    if ($kind === 'BONUS') {
      $bonus_total += $amt;
    } elseif ($kind === 'RETURN') {
      $repay_total += $amt;
    } else {
      $total_in_normal += $amt;
    }
  } elseif ($tType === 'OUT') {
    $total_out_normal += (float)($r['amount'] ?? 0);
  }
}

$net_normal     = $total_in_normal - $total_out_normal;
$return_balance = $loan_total - $repay_total; // 这里 loan_total 简化为 0
$summary_in     = $total_in_normal + $bonus_total + $repay_total;
$summary_out    = $total_out_normal + $loan_total;
$summary_net    = $summary_in - $summary_out;
$summary_label  = 'After contra (all figures)';

// Pending payment：显示还没给的钱（order_total - 已付款）
$pending_total = 0.0;
foreach ($rows as $r) {
  $tType = strtoupper(trim((string)($r['txn_type'] ?? '')));
  if ($tType !== 'IN') continue;
  if (strtoupper(trim((string)($r['doc_flow_status'] ?? ''))) === 'REJECTED') continue;
  if (strtoupper(trim((string)($r['status'] ?? ''))) === 'CONFIRMED') continue;

  $tid = (int)($r['id'] ?? 0);
  $paid = (float)($paidByTxn[$tid] ?? 0);
  $orderTotal = (float)($r['order_total'] ?? 0);
  $target = $orderTotal > 0 ? $orderTotal : (float)($r['amount'] ?? 0);
  $unpaid = $target - $paid;
  if ($unpaid > 0.0001) {
    $pending_total += $unpaid;
  }
}

$page_title = 'Customer Transactions';
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- 顶部标题（对齐 admin） -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">Customer</div>
          <h1 class="page-title">
            <?= h($customer['name'] ?? '') ?>
            <?php if (!empty($customer['code'])): ?>
              <span style="font-size:13px;font-weight:500;color:#6b7280;">
                (<?= h($customer['code']) ?>)
              </span>
            <?php endif; ?>
          </h1>
          <div class="form-page-subtitle">
            View invoices / payouts, customer payments and contra allocations.
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="<?= h(url('user/company1/customers.php')) ?>" class="btn btn-light">← Back to customers</a>
          <a href="<?= h(url('user/company1/invoices.php?customer_id=' . $cid)) ?>" class="btn btn-light">Invoices / Quotations</a>
          <a href="<?= h(url('user/company1/quotation_edit.php?customer_id=' . $cid)) ?>" class="btn btn-primary">+ New Quotation</a>
          <a href="<?= h(url('user/company1/customer_edit.php?id=' . $cid)) ?>" class="btn btn-light">Edit customer</a>
          <a href="<?= h(url('user/users/users.php?customer_id=' . $cid)) ?>" class="btn btn-light">Login users</a>
        </div>
      </div>
    </div>

    <!-- Summary 卡片（样式跟 admin 一样） -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header" style="margin-bottom:16px;">
        <div>
          <div class="form-page-eyebrow">Summary</div>
          <div class="form-page-title" style="font-size:18px;"><?= h($summary_label) ?></div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">
        <div style="min-width:170px;">
          <div style="color:#6b7280;">Total IN</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($total_in_normal, 2) ?></div>
        </div>

        <div style="min-width:170px;">
          <div style="color:#6b7280;">Total OUT</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($total_out_normal, 2) ?></div>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;">Net</div>
          <?php if ($net_normal > 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;">MYR <?= number_format($net_normal, 2) ?></div>
            <div style="font-size:12px;color:#b91c1c;">We owe customer</div>
          <?php elseif ($net_normal < 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;">MYR <?= number_format(abs($net_normal), 2) ?></div>
            <div style="font-size:12px;color:#166534;">Customer owes us</div>
          <?php else: ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">MYR 0.00</div>
            <div style="font-size:12px;color:#6b7280;">Balanced</div>
          <?php endif; ?>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;">Pending payment</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:#0f766e;">
            <?= h($customer['currency'] ?? 'MYR') ?> <?= number_format($pending_total, 2) ?>
          </div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">
        <div style="min-width:210px;">
          <div style="color:#6b7280;">Return</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">MYR <?= number_format($return_balance, 2) ?></div>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;">Total BONUS</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">MYR <?= number_format($bonus_total, 2) ?></div>
        </div>
      </div>

      <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px;">
        <div style="min-width:170px;">
          <div style="color:#6b7280;">Summary total IN</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($summary_in, 2) ?></div>
        </div>

        <div style="min-width:170px;">
          <div style="color:#6b7280;">Summary total OUT</div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($summary_out, 2) ?></div>
        </div>

        <div style="min-width:210px;">
          <div style="color:#6b7280;">Summary net</div>
          <?php if ($summary_net > 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;">MYR <?= number_format($summary_net, 2) ?></div>
            <div style="font-size:12px;color:#b91c1c;">We owe customer</div>
          <?php elseif ($summary_net < 0): ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;">MYR <?= number_format(abs($summary_net), 2) ?></div>
            <div style="font-size:12px;color:#166534;">Customer owes us</div>
          <?php else: ?>
            <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">MYR 0.00</div>
            <div style="font-size:12px;color:#6b7280;">Balanced</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Filter 区 -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-direction:column;gap:12px;font-size:13px;">
        <input type="hidden" name="customer_id" value="<?= h($cid) ?>">

        <?php include __DIR__ . '/../../include/date_range.php'; ?>

        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
          <div>
            <label class="field-label" style="margin-bottom:4px;">Type</label>
            <select name="type" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $type === 'ALL' ? 'selected' : '' ?>>All</option>
              <option value="IN" <?= $type === 'IN' ? 'selected' : '' ?>>IN only</option>
              <option value="OUT" <?= $type === 'OUT' ? 'selected' : '' ?>>OUT only</option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">Status</label>
            <select name="status" class="form-control" style="min-width:120px;">
              <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>All</option>
              <option value="DRAFT" <?= $status === 'DRAFT' ? 'selected' : '' ?>>DRAFT</option>
              <option value="SENT" <?= $status === 'SENT' ? 'selected' : '' ?>>SENT</option>
              <option value="PENDING" <?= $status === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
              <option value="CONFIRMED" <?= $status === 'CONFIRMED' ? 'selected' : '' ?>>CONFIRMED</option>
            </select>
          </div>

          <div>
            <label class="field-label" style="margin-bottom:4px;">Search</label>
            <input type="text" name="q" class="form-control" style="min-width:220px;"
                   value="<?= h($q) ?>" placeholder="Title / Ref / Invoice no.">
          </div>

          <div style="margin-left:auto;display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a href="<?= h(url('user/company1/txn_list.php?customer_id=' . $cid)) ?>" class="btn btn-light">Reset</a>
          </div>
        </div>
      </form>
    </div>

    <!-- Transaction 列表 -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:120px;">Date</th>
            <th style="width:80px;">Type</th>
            <th style="width:130px;">Method</th>
            <th>Title</th>
            <th style="text-align:right;width:140px;">Amount</th>
            <th style="text-align:right;width:140px;">Pending</th>
            <th style="width:90px;">Status</th>
            <th style="width:80px;" class="table-actions-cell">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="8" style="padding:16px;color:#6b7280;font-size:13px;">No transactions.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $tType   = strtoupper(trim((string)($r['txn_type'] ?? '')));
                $inKind  = ($tType === 'IN') ? detect_in_kind_simple($r) : '';
                $date    = (string)($r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10));
                $title   = trim((string)($r['title'] ?? ''));
                if ($title === '') {
                  if ($tType === 'IN' && $inKind === 'INVOICE') $title = 'Invoice';
                  elseif ($tType === 'IN' && $inKind === 'BONUS') $title = 'Bonus';
                  elseif ($tType === 'IN' && $inKind === 'RETURN') $title = 'Repayment';
                  else $title = '-';
                }
                $cur        = (string)($r['currency'] ?? ($customer['currency'] ?? 'MYR'));
                $amt        = (float)($r['amount'] ?? 0);
                $statusRow  = strtoupper(trim((string)($r['status'] ?? 'DRAFT')));
                $dfs        = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
                $flowType   = strtoupper(trim((string)($r['doc_flow_type'] ?? '')));
                $invoiceNoR = trim((string)($r['invoice_no'] ?? ''));
                $isRejected = ($dfs === 'REJECTED');
                if ($isRejected) {
                  $statusRow = 'REJECTED';
                }
                $statusStyle = 'background:#e5e7eb;color:#374151;';
                if ($statusRow === 'CONFIRMED') $statusStyle = 'background:#ecfdf5;color:#166534;';
                elseif ($statusRow === 'PENDING') $statusStyle = 'background:#dbeafe;color:#1d4ed8;';
                elseif ($statusRow === 'SENT') $statusStyle = 'background:#fef9c3;color:#854d0e;';

                $isQuotationRow = ($tType === 'IN' && $inKind === 'INVOICE' && $invoiceNoR === '');

                // 是否已经进入 invoice / DO 流程（可以看 invoice / DO / receipt）
                $hasInvoice     = ($invoiceNoR !== '');
                $canViewInvoiceDo = (!$isRejected && $hasInvoice && $flowType !== 'QUOTATION');

                // Method label（简化版）
                if ($tType === 'IN') {
                  if ($inKind === 'BONUS')      $methodLabel = 'Bonus';
                  elseif ($inKind === 'RETURN') $methodLabel = 'Return';
                  else                          $methodLabel = 'Invoice';
                } else {
                  $methodLabel = 'OUT';
                }

                // Pending amount（IN 才显示）
                $pendingDisplay = '—';
                if ($tType === 'IN') {
                  $tid   = (int)($r['id'] ?? 0);
                  $paid  = (float)($paidByTxn[$tid] ?? 0);
                  $total = (float)($r['order_total'] ?? 0);
                  if ($total <= 0) $total = (float)($r['amount'] ?? 0);
                  $unpaid = $total - $paid;
                  if ($unpaid > 0.0001 && strtoupper(trim((string)($r['status'] ?? ''))) !== 'CONFIRMED') {
                    $pendingDisplay = $cur . ' ' . number_format($unpaid, 2);
                  }
                }

                // View 链接：报价行直接进 Quotation 文档，其它走 txn_view 详情
                $txnIdRow = (int)($r['id'] ?? 0);
                if ($isQuotationRow) {
                  $viewHref = url('user/company1/txn_doc_in.php?id=' . $txnIdRow . '&customer_id=' . $cid . '&doc=QUOTATION');
                } else {
                  $viewHref = url('user/company1/txn_view.php?id=' . $txnIdRow);
                }

                // Company1 查看 receipt 用的 back（回到当前列表）
                $backHere        = url('user/company1/txn_list.php?customer_id=' . $cid);
                $viewInvoiceHref = url('user/company1/txn_doc_in.php?id=' . $txnIdRow . '&customer_id=' . $cid . '&doc=INVOICE');
                $viewDoHref      = url('user/company1/txn_doc_in.php?id=' . $txnIdRow . '&customer_id=' . $cid . '&doc=DO');
                // Company1 必须走 company1 receipt 入口（否则 user portal 会 Transaction not found）
                // 不带 payment_id 时，收据页会显示 all receipts 列表
                $viewReceiptHref = url('user/company1/txn_receipt_in.php?id=' . $txnIdRow . '&customer_id=' . (int)$cid . '&back=' . rawurlencode($backHere));
              ?>
              <tr>
                <td><?= h($date) ?></td>
                <td>
                  <?php if ($tType === 'IN'): ?>
                    <span style="font-size:12px;font-weight:700;color:#166534;">IN</span>
                  <?php else: ?>
                    <span style="font-size:12px;font-weight:700;color:#b91c1c;">OUT</span>
                  <?php endif; ?>
                </td>
                <td><?= h($methodLabel) ?></td>
                <td><?= h($title) ?></td>
                <td style="text-align:right;"><?= h($cur) ?> <?= number_format($amt, 2) ?></td>
                <td style="text-align:right;"><?= h($pendingDisplay) ?></td>
                <td>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;<?= h($statusStyle) ?>">
                    <?= h($statusRow) ?>
                  </span>
                </td>
                <td class="table-actions-cell">
                  <div class="actions-menu">
                    <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                    <div class="actions-menu-dropdown">
                      <a href="<?= h($viewHref) ?>" class="actions-menu-item"<?= $isQuotationRow ? ' target="_blank"' : '' ?>>View</a>

                      <?php if ($isQuotationRow): ?>
                        <?php if (!$isRejected): ?>
                          <a href="<?= h(url('user/company1/quotation_edit.php?id=' . $txnIdRow . '&customer_id=' . $cid)) ?>" class="actions-menu-item">Edit Quotation</a>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="set_flow_status">
                            <input type="hidden" name="txn_id" value="<?= $txnIdRow ?>">
                            <input type="hidden" name="next_status" value="PROCESSING">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">Process → Invoice</button>
                          </form>
                          <form method="post" style="margin:0;">
                            <input type="hidden" name="action" value="set_flow_status">
                            <input type="hidden" name="txn_id" value="<?= $txnIdRow ?>">
                            <input type="hidden" name="next_status" value="REJECTED">
                            <button type="submit" class="actions-menu-item" style="width:100%;text-align:left;border:none;background:transparent;cursor:pointer;">Reject</button>
                          </form>
                        <?php endif; ?>
                        <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $txnIdRow . '&customer_id=' . $cid . '&doc=QUOTATION')) ?>" class="actions-menu-item" target="_blank">View Quotation</a>
                      <?php else: ?>
                        <?php if ($tType === 'IN' && $inKind === 'INVOICE'): ?>
                          <a href="<?= h(url('user/company1/txn_edit_in.php?id=' . $txnIdRow . '&customer_id=' . $cid)) ?>" class="actions-menu-item">Edit IN invoice</a>
                          <?php if ($canViewInvoiceDo): ?>
                            <a href="<?= h($viewInvoiceHref) ?>" class="actions-menu-item" target="_blank">View Invoice</a>
                            <a href="<?= h($viewDoHref) ?>" class="actions-menu-item" target="_blank">View DO</a>
                            <a href="<?= h($viewReceiptHref) ?>" class="actions-menu-item" target="_blank">View Receipt</a>
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

