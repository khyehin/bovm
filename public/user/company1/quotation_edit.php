<?php
// public/user/company1/quotation_edit.php
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
      $cols[strtolower((string)($r['Field'] ?? ''))] = true;
    }
  } catch (Throwable $e) {}
  return $cache[$k] = $cols;
}

$txnCols = table_columns($pdo, 'customer_txn');
$hasLines = false;
try { $pdo->query("SELECT 1 FROM customer_txn_lines LIMIT 1"); $hasLines = true; } catch (Throwable $e) {}

$hasDocFlowType = isset($txnCols['doc_flow_type']);
$hasDocFlowStat = isset($txnCols['doc_flow_status']);
$hasDiscount = isset($txnCols['discount']);
$hasDeliverTo = isset($txnCols['deliver_to']);
$hasTerms = isset($txnCols['terms']);
$hasDoNumber = isset($txnCols['do_number']);
$hasSignMode = isset($txnCols['sign_mode']);
$hasSignReceive = isset($txnCols['sign_receive']);
$hasSignPayer = isset($txnCols['sign_payer']);
$hasRequireSignature = isset($txnCols['require_signature']);

$cid = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($cid <= 0) {
  header('Location: ' . url('user/company1/invoices.php'));
  exit;
}

// only category 3 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND category_id=3 LIMIT 1");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) { http_response_code(404); exit('Customer not found'); }

$txn = null;
$lines = [];
if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM customer_txn WHERE id=:id AND customer_id=:cid AND txn_type='IN' LIMIT 1");
  $st->execute([':id'=>$id, ':cid'=>$cid]);
  $txn = $st->fetch();
  if (!$txn) { http_response_code(404); exit('Transaction not found'); }
  // only allow edit quotation stage
  if (trim((string)($txn['invoice_no'] ?? '')) !== '') {
    header('Location: ' . url('user/company1/invoices.php?customer_id=' . $cid));
    exit;
  }
  if ($hasLines) {
    $st = $pdo->prepare("SELECT * FROM customer_txn_lines WHERE customer_txn_id=:tid ORDER BY line_seq ASC, id ASC");
    $st->execute([':tid'=>$id]);
    $lines = $st->fetchAll();
  }
}

// save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $txn_date   = (string)($_POST['txn_date'] ?? date('Y-m-d'));
  $title      = trim((string)($_POST['title'] ?? 'Quotation'));
  if ($title === '') $title = 'Quotation';
  $order_total = (float)($_POST['order_total'] ?? 0);
  $discount    = $hasDiscount ? (float)($_POST['discount'] ?? 0) : 0;
  $deliver_to  = $hasDeliverTo ? trim((string)($_POST['deliver_to'] ?? '')) : '';
  $terms       = $hasTerms ? trim((string)($_POST['terms'] ?? '')) : '';
  $do_number   = $hasDoNumber ? trim((string)($_POST['do_number'] ?? '')) : '';
  $notes       = trim((string)($_POST['notes'] ?? ''));

  $sign_mode   = $hasSignMode ? strtoupper(trim((string)($_POST['sign_mode'] ?? 'SIGN_AND_CHOP'))) : 'SIGN_AND_CHOP';
  if (!in_array($sign_mode, ['CHOP_ONLY', 'SIGN_AND_CHOP', 'SIGN_ONLY'], true)) $sign_mode = 'SIGN_AND_CHOP';
  $sign_payer  = isset($_POST['sign_payer']) ? 1 : 0;
  $sign_receive = ($sign_mode === 'CHOP_ONLY') ? 0 : 1;

  if ($id > 0) {
    $sets = ["txn_date=:txn_date","title=:title","order_total=:order_total","updated_at=NOW()"];
    $params = [':txn_date'=>$txn_date,':title'=>$title,':order_total'=>$order_total,':id'=>$id,':cid'=>$cid];
    if ($hasSignReceive) { $sets[]="sign_receive=:sr"; $params[':sr']=$sign_receive; }
    if ($hasSignPayer) { $sets[]="sign_payer=:sp"; $params[':sp']=$sign_payer; }
    if ($hasRequireSignature) { $sets[]="require_signature=:rs"; $params[':rs']=($sign_receive||$sign_payer)?1:0; }
    if ($hasDiscount) { $sets[]="discount=:disc"; $params[':disc']=$discount; }
    if ($hasDeliverTo) { $sets[]="deliver_to=:dt"; $params[':dt']=$deliver_to; }
    if ($hasTerms) { $sets[]="terms=:terms"; $params[':terms']=$terms; }
    if ($hasDoNumber) { $sets[]="do_number=:don"; $params[':don']=$do_number; }
    if ($hasSignMode) { $sets[]="sign_mode=:sm"; $params[':sm']=$sign_mode; }
    $sets[]="notes=:notes"; $params[':notes']=$notes;
    if ($hasDocFlowType) $sets[]="doc_flow_type='QUOTATION'";
    if ($hasDocFlowStat) $sets[]="doc_flow_status='DRAFT'";
    $pdo->prepare("UPDATE customer_txn SET ".implode(',',$sets)." WHERE id=:id AND customer_id=:cid")->execute($params);

    if ($hasLines) {
      $pdo->prepare("DELETE FROM customer_txn_lines WHERE customer_txn_id=:tid")->execute([':tid'=>$id]);
      $linePosts = $_POST['lines'] ?? [];
      $seq=1;
      foreach ($linePosts as $row) {
        $desc = trim((string)($row['description'] ?? ''));
        $qty  = (float)($row['quantity'] ?? 1);
        $unit = (float)($row['unit_price'] ?? 0);
        $amt  = (float)($row['amount'] ?? 0);
        if ($desc === '' && $amt <= 0 && $unit <= 0) continue;
        if ($amt <= 0 && $qty > 0 && $unit > 0) $amt = $qty * $unit;
        $pdo->prepare("INSERT INTO customer_txn_lines (customer_txn_id,line_seq,description,quantity,unit_price,amount) VALUES (:tid,:seq,:d,:q,:u,:a)")
          ->execute([':tid'=>$id,':seq'=>$seq++,':d'=>$desc,':q'=>$qty,':u'=>$unit,':a'=>$amt]);
      }
    }
  } else {
    $cols = ['customer_id','txn_type','in_kind','txn_date','currency','title','amount','order_total','invoice_no','status','notes','created_at','updated_at'];
    $vals = [':customer_id','\'IN\'','\'INVOICE\'',':txn_date','\'MYR\'',':title',0,':order_total','\'\'','\'PENDING\'',':notes','NOW()','NOW()'];
    if ($hasDocFlowType) { $cols[]='doc_flow_type'; $vals[]="'QUOTATION'"; }
    if ($hasDocFlowStat) { $cols[]='doc_flow_status'; $vals[]="'DRAFT'"; }
    if ($hasDiscount) { $cols[]='discount'; $vals[]=':discount'; }
    if ($hasDeliverTo) { $cols[]='deliver_to'; $vals[]=':deliver_to'; }
    if ($hasTerms) { $cols[]='terms'; $vals[]=':terms'; }
    if ($hasDoNumber) { $cols[]='do_number'; $vals[]=':do_number'; }
    if ($hasSignMode) { $cols[]='sign_mode'; $vals[]=':sign_mode'; }
    if ($hasSignReceive) { $cols[]='sign_receive'; $vals[]=':sign_receive'; }
    if ($hasSignPayer) { $cols[]='sign_payer'; $vals[]=':sign_payer'; }
    if ($hasRequireSignature) { $cols[]='require_signature'; $vals[]=':require_signature'; }
    $sql = "INSERT INTO customer_txn (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
    $st = $pdo->prepare($sql);
    $st->bindValue(':customer_id',$cid);
    $st->bindValue(':txn_date',$txn_date);
    $st->bindValue(':title',$title);
    $st->bindValue(':order_total',$order_total);
    $st->bindValue(':notes',$notes);
    if ($hasSignReceive) $st->bindValue(':sign_receive',$sign_receive);
    if ($hasSignPayer) $st->bindValue(':sign_payer',$sign_payer);
    if ($hasRequireSignature) $st->bindValue(':require_signature',($sign_receive||$sign_payer)?1:0);
    if ($hasDiscount) $st->bindValue(':discount',$discount);
    if ($hasDeliverTo) $st->bindValue(':deliver_to',$deliver_to);
    if ($hasTerms) $st->bindValue(':terms',$terms);
    if ($hasDoNumber) $st->bindValue(':do_number',$do_number);
    if ($hasSignMode) $st->bindValue(':sign_mode',$sign_mode);
    $st->execute();
    $id = (int)$pdo->lastInsertId();

    if ($hasLines && !empty($_POST['lines'])) {
      $linePosts = $_POST['lines'] ?? [];
      $seq=1;
      foreach ($linePosts as $row) {
        $desc = trim((string)($row['description'] ?? ''));
        $qty  = (float)($row['quantity'] ?? 1);
        $unit = (float)($row['unit_price'] ?? 0);
        $amt  = (float)($row['amount'] ?? 0);
        if ($desc === '' && $amt <= 0 && $unit <= 0) continue;
        if ($amt <= 0 && $qty > 0 && $unit > 0) $amt = $qty * $unit;
        $pdo->prepare("INSERT INTO customer_txn_lines (customer_txn_id,line_seq,description,quantity,unit_price,amount) VALUES (:tid,:seq,:d,:q,:u,:a)")
          ->execute([':tid'=>$id,':seq'=>$seq++,':d'=>$desc,':q'=>$qty,':u'=>$unit,':a'=>$amt]);
      }
    }
  }

  header('Location: ' . url('user/company1/quotation_edit.php?id=' . $id . '&customer_id=' . $cid . '&ok=1'));
  exit;
}

if (!$txn) {
  $txn = [
    'txn_date' => date('Y-m-d'),
    'title' => 'Quotation',
    'order_total' => 0,
    'discount' => 0,
    'deliver_to' => '',
    'terms' => '',
    'do_number' => '',
    'notes' => '',
  ];
}

$customerName = (string)($customer['name'] ?? '');

$page_title = $id > 0 ? ('Edit Quotation · ' . $customerName) : ('New Quotation · ' . $customerName);
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated" style="max-width:900px;">
      <div class="form-page-header" style="margin-bottom:16px;">
        <div>
          <div class="form-page-eyebrow">Company1</div>
          <h2 class="form-page-title"><?= h($customerName) ?></h2>
          <div class="form-page-subtitle">Quotation editor.</div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('user/company1/invoices.php?customer_id=' . (int)$cid)) ?>" class="btn btn-light">← Back</a>
        </div>
      </div>

      <?php if (!empty($_GET['ok'])): ?>
        <div class="alert-success" style="margin-bottom:12px;">Quotation saved.</div>
      <?php endif; ?>

      <form method="post" class="quotation-form">
        <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="form-grid form-grid-2" style="margin-bottom:16px;">
          <div>
            <div class="field-label" style="margin-bottom:4px;">To</div>
            <div style="font-size:14px;"><?= h($customerName) ?></div>
          </div>
          <div>
            <div style="font-size:18px;font-weight:700;margin-bottom:12px;">QUOTATION</div>
            <div class="form-group">
              <label class="field-label">Date</label>
              <input type="date" name="txn_date" class="form-control" value="<?= h($txn['txn_date'] ?? date('Y-m-d')) ?>">
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="field-label">Title</label>
          <input type="text" name="title" class="form-control" value="<?= h($txn['title'] ?? 'Quotation') ?>">
        </div>

        <table class="table" style="margin-bottom:12px;">
          <thead>
            <tr>
              <th style="width:50px;">NO.</th>
              <th>Description</th>
              <th style="width:90px;">Quantity</th>
              <th style="width:110px;">Unit Price</th>
              <th style="width:120px;text-align:right;">Amount</th>
            </tr>
          </thead>
          <tbody id="lineBody">
            <?php if (!empty($lines)): ?>
              <?php foreach ($lines as $i => $line): $no = $i + 1; ?>
                <tr>
                  <td><?= (int)$no ?></td>
                  <td><input type="text" name="lines[<?= (int)$no ?>][description]" class="form-control" value="<?= h($line['description'] ?? '') ?>"></td>
                  <td><input type="number" step="0.0001" min="0" name="lines[<?= (int)$no ?>][quantity]" class="form-control line-qty" value="<?= h((float)($line['quantity'] ?? 1)) ?>"></td>
                  <td><input type="number" step="0.01" min="0" name="lines[<?= (int)$no ?>][unit_price]" class="form-control line-unit" value="<?= h((float)($line['unit_price'] ?? 0)) ?>"></td>
                  <td><input type="number" step="0.01" min="0" name="lines[<?= (int)$no ?>][amount]" class="form-control line-amount text-right" value="<?= h((float)($line['amount'] ?? 0)) ?>"></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td>1</td>
                <td><input type="text" name="lines[1][description]" class="form-control" value=""></td>
                <td><input type="number" step="0.0001" min="0" name="lines[1][quantity]" class="form-control line-qty" value="1"></td>
                <td><input type="number" step="0.01" min="0" name="lines[1][unit_price]" class="form-control line-unit" value="0"></td>
                <td><input type="number" step="0.01" min="0" name="lines[1][amount]" class="form-control line-amount text-right" value="0"></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        <div style="margin-bottom:8px;">
          <button type="button" class="btn btn-light btn-sm" id="btnAddLine">+ Add line</button>
        </div>

        <div style="max-width:320px;margin-left:auto;margin-bottom:16px;">
          <?php if ($hasDiscount): ?>
          <div class="form-group">
            <label class="field-label">Discount</label>
            <input type="number" step="0.01" min="0" name="discount" id="totalDiscount" class="form-control" value="<?= h($txn['discount'] ?? 0) ?>">
          </div>
          <?php endif; ?>
          <div style="font-size:14px;font-weight:600;">Total Amount: <span id="grandTotal"><?= number_format((float)($txn['order_total'] ?? 0), 2, '.', '') ?></span></div>
          <input type="hidden" name="order_total" id="orderTotal" value="<?= h($txn['order_total'] ?? 0) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= h($txn['notes'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
          <button type="submit" class="btn btn-primary">Save Quotation</button>
          <?php if ($id > 0): ?>
            <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . (int)$id . '&customer_id=' . (int)$cid . '&doc=QUOTATION')) ?>" target="_blank" class="btn btn-light">Print / PDF</a>
          <?php endif; ?>
          <a href="<?= h(url('user/company1/invoices.php?customer_id=' . (int)$cid)) ?>" class="btn btn-light">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  var lineBody = document.getElementById('lineBody');
  var btnAddLine = document.getElementById('btnAddLine');
  var totalDiscount = document.getElementById('totalDiscount');
  var orderTotal = document.getElementById('orderTotal');
  var grandTotal = document.getElementById('grandTotal');

  function lineIndex() { return (lineBody.querySelectorAll('tr').length + 1); }
  function recalc() {
    var total = 0;
    lineBody.querySelectorAll('tr').forEach(function(tr){
      var amt = parseFloat(tr.querySelector('.line-amount').value) || 0;
      total += amt;
    });
    var disc = totalDiscount ? (parseFloat(totalDiscount.value) || 0) : 0;
    var grand = Math.max(0, total - disc);
    if (orderTotal) orderTotal.value = grand.toFixed(2);
    if (grandTotal) grandTotal.textContent = grand.toFixed(2);
  }
  function bindLine(tr) {
    var qty = tr.querySelector('.line-qty');
    var unit = tr.querySelector('.line-unit');
    var amt = tr.querySelector('.line-amount');
    function updateAmount() {
      var q = parseFloat(qty.value) || 0;
      var u = parseFloat(unit.value) || 0;
      amt.value = (q * u).toFixed(2);
      recalc();
    }
    if (qty) qty.addEventListener('input', updateAmount);
    if (unit) unit.addEventListener('input', updateAmount);
    if (amt) amt.addEventListener('input', recalc);
  }

  lineBody.querySelectorAll('tr').forEach(bindLine);
  if (totalDiscount) totalDiscount.addEventListener('input', recalc);
  recalc();

  if (btnAddLine) {
    btnAddLine.addEventListener('click', function(){
      var idx = lineIndex();
      var tr = document.createElement('tr');
      tr.innerHTML = '<td>' + idx + '</td>' +
        '<td><input type="text" name="lines[' + idx + '][description]" class="form-control"></td>' +
        '<td><input type="number" step="0.0001" min="0" name="lines[' + idx + '][quantity]" class="form-control line-qty" value="1"></td>' +
        '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][unit_price]" class="form-control line-unit" value="0"></td>' +
        '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][amount]" class="form-control line-amount text-right" value="0"></td>';
      lineBody.appendChild(tr);
      bindLine(tr);
    });
  }
})();
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>

