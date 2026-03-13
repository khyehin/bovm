<?php
// public/user/company1/txn_edit_in.php
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
      $name = strtolower((string)($r['Field'] ?? ''));
      if ($name !== '') $cols[$name] = true;
    }
  } catch (Throwable $e) {}
  return $cache[$k] = $cols;
}

// minimal recompute: reuse same as admin rule
function recompute_in_txn_status(PDO $pdo, int $txnId): void {
  $colsTxn = table_columns($pdo, 'customer_txn');
  $st = $pdo->prepare("SELECT id, currency, order_total, sign_receive, sign_payer, doc_flow_type FROM customer_txn WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$txnId]);
  $txn = $st->fetch(PDO::FETCH_ASSOC);
  if (!$txn) return;

  $mainCur = strtoupper(trim((string)($txn['currency'] ?? 'MYR')));
  if ($mainCur === '') $mainCur = 'MYR';
  $orderTotal = (float)($txn['order_total'] ?? 0);

  $paid = 0.0;
  $stp = $pdo->prepare("SELECT currency, amount FROM customer_txn_payments WHERE customer_txn_id=:id");
  $stp->execute([':id'=>$txnId]);
  foreach ($stp->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $cur = strtoupper(trim((string)($p['currency'] ?? '')));
    if ($cur === '') $cur = $mainCur;
    if ($cur === $mainCur) $paid += (float)($p['amount'] ?? 0);
  }
  $paidEnough = ($orderTotal > 0 && ($paid + 0.0001) >= $orderTotal);

  $needOur = ((int)($txn['sign_receive'] ?? 0) === 1);
  $needCus = ((int)($txn['sign_payer'] ?? 0) === 1);
  $signOk = true;
  if ($needOur || $needCus) {
    $stLast = $pdo->prepare("SELECT payer_signature_image, receiver_signature_image, payer_signed_at, receiver_signed_at FROM customer_txn_payments WHERE customer_txn_id=:id ORDER BY pay_date DESC, id DESC LIMIT 1");
    $stLast->execute([':id'=>$txnId]);
    $last = $stLast->fetch(PDO::FETCH_ASSOC) ?: [];
    $cusDone = !empty($last['payer_signature_image']) || !empty($last['payer_signed_at']);
    $ourDone = !empty($last['receiver_signature_image']) || !empty($last['receiver_signed_at']);
    if ($needCus && !$cusDone) $signOk = false;
    if ($needOur && !$ourDone) $signOk = false;
  }

  $newStatus = ($paidEnough && $signOk) ? 'CONFIRMED' : 'PENDING';
  $flowType = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
  if (!in_array($flowType, ['NORMAL','QUOTATION'], true)) $flowType = 'NORMAL';
  $hasFlowStat = isset($colsTxn['doc_flow_status']);

  if ($newStatus === 'CONFIRMED') {
    $set = ["status='CONFIRMED'","confirmed_at=IFNULL(confirmed_at,NOW())","updated_at=NOW()"];
    if ($hasFlowStat && $flowType === 'NORMAL') $set[] = "doc_flow_status='COMPLETED'";
    $pdo->prepare("UPDATE customer_txn SET ".implode(',',$set)." WHERE id=:id")->execute([':id'=>$txnId]);
  } else {
    $set = ["status='PENDING'","updated_at=NOW()"];
    if ($hasFlowStat && $flowType === 'NORMAL') $set[] = "doc_flow_status='PROCESSING'";
    $pdo->prepare("UPDATE customer_txn SET ".implode(',',$set)." WHERE id=:id")->execute([':id'=>$txnId]);
  }
}

$txnCols = table_columns($pdo, 'customer_txn');
$payCols = table_columns($pdo, 'customer_txn_payments');
$hasPayBank = isset($payCols['bank_account_id']);

$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

// load txn (must be invoice stage, category3 customer)
$st = $pdo->prepare("SELECT t.*, c.category_id, c.name AS customer_name FROM customer_txn t JOIN customers c ON c.id=t.customer_id WHERE t.id=:id AND t.txn_type='IN' LIMIT 1");
$st->execute([':id'=>$id]);
$txn = $st->fetch();
if (!$txn) { http_response_code(404); exit('Not found'); }
if ((int)($txn['category_id'] ?? 0) !== 3) { http_response_code(403); exit('Forbidden'); }

$invNo = trim((string)($txn['invoice_no'] ?? ''));
$flowType = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
if ($invNo === '' && $flowType === 'QUOTATION') {
  header('Location: ' . url('user/company1/quotation_edit.php?id=' . (int)$id . '&customer_id=' . (int)$txn['customer_id']));
  exit;
}

// limited bank accounts: id 1 & 2 only
$bankRows = [];
try {
  $bankRows = $pdo->query("SELECT id, bank_code, account_name, account_no, currency FROM company_bank_accounts WHERE is_active=1 AND id IN (1,2) ORDER BY id ASC")->fetchAll();
} catch (Throwable $e) {}

// payments
$payments = [];
try {
  $st = $pdo->prepare("SELECT * FROM customer_txn_payments WHERE customer_txn_id=:id ORDER BY pay_date ASC, id ASC");
  $st->execute([':id'=>$id]);
  $payments = $st->fetchAll();
} catch (Throwable $e) {}

$ok = (string)($_GET['ok'] ?? '');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $rows = $_POST['pay'] ?? [];
  try {
    $pdo->beginTransaction();
    foreach ($rows as $idx => $row) {
      $pid = (int)($row['id'] ?? 0);
      $pay_date = trim((string)($row['pay_date'] ?? ''));
      $amount = (float)($row['amount'] ?? 0);
      $bank_id = $hasPayBank ? (int)($row['bank_account_id'] ?? 0) : 0;
      if ($pay_date === '' && $amount <= 0) continue;
      if ($bank_id && !in_array($bank_id, [1,2], true)) $bank_id = 0;

      if ($pid > 0) {
        $set = ["pay_date=:d","amount=:a","updated_at=NOW()"];
        $params = [':d'=>$pay_date,':a'=>$amount,':id'=>$pid,':tid'=>$id];
        if ($hasPayBank) { $set[]="bank_account_id=:b"; $params[':b']=$bank_id; }
        $pdo->prepare("UPDATE customer_txn_payments SET ".implode(',',$set)." WHERE id=:id AND customer_txn_id=:tid")->execute($params);
      } else {
        $cols = ['customer_txn_id','payment_seq','or_no','pay_date','currency','fx_rate','amount','created_at','updated_at'];
        $vals = [':tid',':seq','\'\'',':d',':cur',0,':a','NOW()','NOW()'];
        if ($hasPayBank) { $cols[]='bank_account_id'; $vals[]=':b'; }
        $seq = count($payments) + 1;
        $stI = $pdo->prepare("INSERT INTO customer_txn_payments (".implode(',',$cols).") VALUES (".implode(',',$vals).")");
        $stI->bindValue(':tid',$id);
        $stI->bindValue(':seq',$seq);
        $stI->bindValue(':d',$pay_date);
        $stI->bindValue(':cur',(string)($txn['currency'] ?? 'MYR'));
        $stI->bindValue(':a',$amount);
        if ($hasPayBank) $stI->bindValue(':b',$bank_id);
        $stI->execute();
      }
    }
    recompute_in_txn_status($pdo, $id);
    $pdo->commit();
  } catch (Throwable $e) {
    try { $pdo->rollBack(); } catch (Throwable $e2) {}
    $errors['global'] = 'Save failed.';
  }
  header('Location: ' . url('user/company1/txn_edit_in.php?id=' . $id . '&customer_id=' . (int)$txn['customer_id'] . '&ok=1'));
  exit;
}

$page_title = 'Edit IN';
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow" style="max-width:980px;">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">Company1</div>
          <h2 class="form-page-title"><?= h((string)($txn['customer_name'] ?? '')) ?></h2>
          <div class="form-page-subtitle">Invoice No: <?= h($invNo ?: '—') ?></div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('user/company1/invoices.php?customer_id=' . (int)$txn['customer_id'])) ?>" class="btn btn-light">← Back</a>
        </div>
      </div>

      <?php if ($ok === '1'): ?><div class="alert-success" style="margin-bottom:12px;">Saved.</div><?php endif; ?>
      <?php if (!empty($errors['global'])): ?><div class="alert-error" style="margin-bottom:12px;"><?= h($errors['global']) ?></div><?php endif; ?>

      <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$id . '&customer_id=' . (int)$txn['customer_id'] . '&doc=INVOICE')) ?>" target="_blank" class="btn btn-light btn-sm">View Invoice</a>
        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . (int)$id . '&customer_id=' . (int)$txn['customer_id'] . '&doc=DO')) ?>" target="_blank" class="btn btn-light btn-sm">View DO</a>
      </div>

      <form method="post">
        <div style="font-weight:700;margin-bottom:6px;">Payments (bank only id 1 &amp; 2)</div>
        <table class="table">
          <thead>
            <tr>
              <th style="width:160px;">Date</th>
              <th>Bank</th>
              <th style="width:160px;text-align:right;">Amount</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $rows = $payments;
              $rows[] = ['id'=>0,'pay_date'=>'','bank_account_id'=>0,'amount'=>0];
            ?>
            <?php foreach ($rows as $i => $p): ?>
              <tr>
                <td>
                  <input type="hidden" name="pay[<?= (int)$i ?>][id]" value="<?= (int)($p['id'] ?? 0) ?>">
                  <input type="date" name="pay[<?= (int)$i ?>][pay_date]" class="form-control" value="<?= h((string)($p['pay_date'] ?? '')) ?>">
                </td>
                <td>
                  <?php if ($hasPayBank): ?>
                    <select name="pay[<?= (int)$i ?>][bank_account_id]" class="form-control">
                      <option value="0">—</option>
                      <?php foreach ($bankRows as $b): ?>
                        <?php $bid = (int)($b['id'] ?? 0); ?>
                        <option value="<?= $bid ?>" <?= ((int)($p['bank_account_id'] ?? 0) === $bid) ? 'selected' : '' ?>>
                          <?= h((string)($b['bank_code'] ?? '') . ' · ' . (string)($b['account_no'] ?? '') . ' · ' . (string)($b['account_name'] ?? '')) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td style="text-align:right;">
                  <input type="number" step="0.01" min="0" name="pay[<?= (int)$i ?>][amount]" class="form-control" style="text-align:right;" value="<?= h((float)($p['amount'] ?? 0)) ?>">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="display:flex;gap:10px;margin-top:14px;">
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

