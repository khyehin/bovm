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

/** description 中的单位标记：第一行 `[[UNIT:SET]]` */
function parse_unit_marker(string $desc): array {
  $desc = (string)$desc;
  if (preg_match('/^\[\[UNIT:(.*?)\]\]\s*(\r\n|\r|\n)?/u', $desc, $m)) {
    $unitLabel = trim((string)($m[1] ?? ''));
    $rest = substr($desc, strlen((string)$m[0]));
    return [$unitLabel, $rest];
  }
  return ['', $desc];
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
$hasRequireSignQuotation = isset($txnCols['require_sign_quotation']);

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
  $st->execute([':id' => $id, ':cid' => $cid]);
  $txn = $st->fetch();
  if (!$txn) { http_response_code(404); exit('Transaction not found'); }
  // only allow edit quotation stage
  if (trim((string)($txn['invoice_no'] ?? '')) !== '') {
    header('Location: ' . url('user/company1/invoices.php?customer_id=' . $cid));
    exit;
  }
  if ($hasLines) {
    $st = $pdo->prepare("SELECT * FROM customer_txn_lines WHERE customer_txn_id=:tid ORDER BY line_seq ASC, id ASC");
    $st->execute([':tid' => $id]);
    $lines = $st->fetchAll();
  }
}

// 若 DB 没有 discount 字段：用行合计推算一个“显示用 discount”
$virtualDiscount = 0.0;
if (!$hasDiscount && !empty($lines)) {
  $lineSum = 0.0;
  foreach ($lines as $ln) {
    $a = (float)($ln['amount'] ?? 0);
    if (abs($a) < 0.0001) {
      $q = (float)($ln['quantity'] ?? 0);
      $u = (float)($ln['unit_price'] ?? 0);
      $a = $q * $u;
    }
    if ($a > 0) $lineSum += $a;
  }
  $virtualDiscount = max(0, $lineSum - (float)($txn['order_total'] ?? 0));
}

$isQuotation = true;
if ($txn && $hasDocFlowType) {
  $isQuotation = (strtoupper((string)($txn['doc_flow_type'] ?? '')) === 'QUOTATION');
}

// save / process
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['_action'] ?? 'save');

  if ($action === 'process') {
    if ($id <= 0) {
      header('Location: ' . url('user/company1/quotation_edit.php?customer_id=' . $cid));
      exit;
    }
    $st = $pdo->prepare("SELECT id, invoice_no, doc_flow_type, txn_date FROM customer_txn WHERE id = :id AND customer_id = :cid LIMIT 1");
    $st->execute([':id' => $id, ':cid' => $cid]);
    $row = $st->fetch();
    if (!$row) {
      header('Location: ' . url('user/company1/invoices.php?customer_id=' . $cid));
      exit;
    }
    $invNo = trim((string)($row['invoice_no'] ?? ''));
    if ($invNo === '' && $hasDocFlowType) {
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
    }
    $sets = ["doc_flow_type = 'NORMAL'", "doc_flow_status = 'PROCESSING'", "updated_at = NOW()"];
    $params = [':id' => $id, ':cid' => $cid];
    if ($invNo !== '') {
      $sets[] = "invoice_no = :inv_no";
      $params[':inv_no'] = $invNo;
    }
    $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $sets) . " WHERE id = :id AND customer_id = :cid")->execute($params);
    header('Location: ' . url('user/company1/txn_edit_in.php?id=' . $id . '&customer_id=' . $cid));
    exit;
  }

  // 同步可编辑的 customer 显示信息（To / Add / Tel / Email / Attn）
  $custNamePosted = trim((string)($_POST['customer_name'] ?? ''));
  $custAddrPosted = (string)($_POST['customer_address'] ?? '');
  $custTelPosted  = trim((string)($_POST['customer_tel'] ?? ''));
  $custEmailPosted = trim((string)($_POST['customer_email'] ?? ''));
  $custAttnPosted = trim((string)($_POST['customer_attn'] ?? ''));
  if ($custNamePosted !== '' || trim($custAddrPosted) !== '' || $custTelPosted !== '' || $custEmailPosted !== '' || $custAttnPosted !== '') {
    $addrLines = preg_split('/\r\n|\r|\n/', (string)$custAddrPosted);
    $addrLines = array_values(array_filter(array_map('trim', $addrLines), static fn($x) => $x !== ''));
    $a1 = $addrLines[0] ?? '';
    $a2 = $addrLines[1] ?? '';
    $a3 = $addrLines[2] ?? '';
    $pdo->prepare("
      UPDATE customers
         SET name = :name,
             address1 = :a1,
             address2 = :a2,
             address3 = :a3,
             contact_phone = :tel,
             contact_email = :email,
             contact_name = :attn
       WHERE id = :cid
         AND category_id = 3
       LIMIT 1
    ")->execute([
      ':name' => $custNamePosted !== '' ? $custNamePosted : (string)($customer['name'] ?? ''),
      ':a1'   => $a1 !== '' ? $a1 : (string)($customer['address1'] ?? ''),
      ':a2'   => $a2 !== '' ? $a2 : (string)($customer['address2'] ?? ''),
      ':a3'   => $a3 !== '' ? $a3 : (string)($customer['address3'] ?? ''),
      ':tel'  => $custTelPosted !== '' ? $custTelPosted : (string)($customer['contact_phone'] ?? ''),
      ':email'=> $custEmailPosted !== '' ? $custEmailPosted : (string)($customer['contact_email'] ?? ''),
      ':attn' => $custAttnPosted !== '' ? $custAttnPosted : (string)($customer['contact_name'] ?? ''),
      ':cid'  => $cid,
    ]);
    // reload for render
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND category_id=3 LIMIT 1");
    $st->execute([':id' => $cid]);
    $customer = $st->fetch() ?: $customer;
  }

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
    if ($hasRequireSignQuotation) { $sets[]="require_sign_quotation=:rsq"; $params[':rsq']=$sign_payer?1:0; }
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
        $unitLabel = trim((string)($row['unit_label'] ?? ''));
        $descRaw = (string)($row['description'] ?? '');
        [, $descNoMarker] = parse_unit_marker($descRaw);
        $desc = trim((string)$descNoMarker);
        if ($unitLabel !== '') {
          $desc = '[[UNIT:' . $unitLabel . "]]\n" . $desc;
        }
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
    if ($hasRequireSignQuotation) { $cols[]='require_sign_quotation'; $vals[]=':require_sign_quotation'; }
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
    if ($hasRequireSignQuotation) $st->bindValue(':require_sign_quotation',$sign_payer?1:0);
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
        $unitLabel = trim((string)($row['unit_label'] ?? ''));
        $descRaw = (string)($row['description'] ?? '');
        [, $descNoMarker] = parse_unit_marker($descRaw);
        $desc = trim((string)$descNoMarker);
        if ($unitLabel !== '') {
          $desc = '[[UNIT:' . $unitLabel . "]]\n" . $desc;
        }
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
$customerAddr = array_filter([
  (string)($customer['address1'] ?? ''),
  (string)($customer['address2'] ?? ''),
  (string)($customer['address3'] ?? ''),
  trim(implode(' ', array_filter([$customer['city'] ?? '', $customer['state'] ?? '', $customer['postcode'] ?? '']))),
]);
$customerTel = (string)($customer['contact_phone'] ?? '');
$customerEmail = (string)($customer['contact_email'] ?? '');
$customerAttn = (string)($customer['contact_name'] ?? '');
$customerAddrText = implode("\n", array_values(array_filter($customerAddr, static fn($x) => trim((string)$x) !== '')));

$page_title = $id > 0 ? ('Edit Quotation · ' . $customerName) : ('New Quotation · ' . $customerName);
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<style>
.quotation-form textarea.line-desc{
  width:100%;
  min-height:54px;
  resize:vertical;
  white-space:pre-wrap;
}
.quotation-form .line-unitlabel{
  text-transform: uppercase;
}
</style>

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
            <input type="text" name="customer_name" class="form-control" value="<?= h($customerName) ?>" style="max-width:420px;">

            <div class="field-label" style="margin:10px 0 4px;">Add.</div>
            <textarea name="customer_address" class="form-control" rows="3" style="max-width:520px;white-space:pre-wrap;"><?= h($customerAddrText) ?></textarea>

            <div class="field-label" style="margin:10px 0 4px;">Tel</div>
            <input type="text" name="customer_tel" class="form-control" value="<?= h($customerTel) ?>" style="max-width:260px;">

            <div class="field-label" style="margin:10px 0 4px;">Email</div>
            <input type="text" name="customer_email" class="form-control" value="<?= h($customerEmail) ?>" style="max-width:320px;">

            <div class="field-label" style="margin:10px 0 4px;">Attn.</div>
            <input type="text" name="customer_attn" class="form-control" value="<?= h($customerAttn) ?>" style="max-width:260px;">
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
                <?php [$unitLabel, $descShown] = parse_unit_marker((string)($line['description'] ?? '')); ?>
                <tr>
                  <td><?= (int)$no ?></td>
                  <td>
                    <textarea name="lines[<?= (int)$no ?>][description]" class="form-control line-desc" rows="3"><?= h($descShown) ?></textarea>
                  </td>
                  <td><input type="number" step="0.0001" min="0" name="lines[<?= (int)$no ?>][quantity]" class="form-control line-qty" value="<?= h((float)($line['quantity'] ?? 1)) ?>"></td>
                  <td>
                    <input type="text" name="lines[<?= (int)$no ?>][unit_label]" class="form-control line-unitlabel" value="<?= h($unitLabel) ?>" placeholder="SET / PAX" style="margin-bottom:6px;">
                    <input type="number" step="0.01" min="0" name="lines[<?= (int)$no ?>][unit_price]" class="form-control line-unit" value="<?= ((float)($line['unit_price'] ?? 0)) > 0 ? h((float)($line['unit_price'] ?? 0)) : '' ?>" placeholder="0.00">
                  </td>
                  <td><input type="number" step="0.01" min="0" name="lines[<?= (int)$no ?>][amount]" class="form-control line-amount text-right" value="<?= ((float)($line['amount'] ?? 0)) > 0 ? h((float)($line['amount'] ?? 0)) : '' ?>" placeholder=""></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td>1</td>
                <td><textarea name="lines[1][description]" class="form-control line-desc" rows="3"></textarea></td>
                <td><input type="number" step="0.0001" min="0" name="lines[1][quantity]" class="form-control line-qty" value="1"></td>
                <td>
                  <input type="text" name="lines[1][unit_label]" class="form-control line-unitlabel" value="" placeholder="SET / PAX" style="margin-bottom:6px;">
                  <input type="number" step="0.01" min="0" name="lines[1][unit_price]" class="form-control line-unit" value="" placeholder="0.00">
                </td>
                <td><input type="number" step="0.01" min="0" name="lines[1][amount]" class="form-control line-amount text-right" value="" placeholder=""></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
        <div style="margin-bottom:8px;">
          <button type="button" class="btn btn-light btn-sm" id="btnAddLine">+ Add line</button>
        </div>

        <div style="max-width:320px;margin-left:auto;margin-bottom:16px;">
          <div class="form-group">
            <label class="field-label">Discount</label>
            <input type="number" step="0.01" min="0" name="discount" id="totalDiscount" class="form-control" value="<?= h($hasDiscount ? ($txn['discount'] ?? 0) : $virtualDiscount) ?>">
          </div>
          <div style="font-size:14px;font-weight:600;">Total Amount: <span id="grandTotal"><?= number_format((float)($txn['order_total'] ?? 0), 2, '.', '') ?></span></div>
          <input type="hidden" name="order_total" id="orderTotal" value="<?= h($txn['order_total'] ?? 0) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= h($txn['notes'] ?? '') ?></textarea>
        </div>

        <?php if ($hasSignMode || $hasSignPayer): ?>
        <div class="form-group" style="margin-top:14px;">
          <div class="field-label" style="margin-bottom:6px;">Our side (receipt)</div>
          <div style="display:flex;flex-wrap:wrap;gap:12px;">
            <?php
            $curSignMode = strtoupper((string)($txn['sign_mode'] ?? 'SIGN_AND_CHOP'));
            if (!in_array($curSignMode, ['CHOP_ONLY', 'SIGN_AND_CHOP', 'SIGN_ONLY'], true)) $curSignMode = 'SIGN_AND_CHOP';
            ?>
            <?php if ($hasSignMode): ?>
            <label style="display:flex;align-items:center;gap:6px;">
              <input type="radio" name="sign_mode" value="CHOP_ONLY" <?= $curSignMode === 'CHOP_ONLY' ? 'checked' : '' ?>>
              <span>Chop only</span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;">
              <input type="radio" name="sign_mode" value="SIGN_AND_CHOP" <?= $curSignMode === 'SIGN_AND_CHOP' ? 'checked' : '' ?>>
              <span>Sign and chop</span>
            </label>
            <?php endif; ?>
          </div>
          <?php if ($hasSignPayer): ?>
          <div style="margin-top:8px;">
            <label style="display:flex;align-items:center;gap:6px;">
              <input type="checkbox" name="sign_payer" value="1" <?= !empty($txn['sign_payer']) ? 'checked' : '' ?>>
              <span>Require customer signature</span>
            </label>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
          <button type="submit" class="btn btn-primary" name="_action" value="save">Save Quotation</button>
          <?php if ($id > 0): ?>
            <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . (int)$id . '&customer_id=' . (int)$cid . '&doc=QUOTATION')) ?>" target="_blank" class="btn btn-light">Print / PDF</a>
          <?php endif; ?>
          <?php if ($id > 0 && $isQuotation): ?>
            <button type="submit" class="btn btn-primary" name="_action" value="process" onclick="return confirm('Turn this quotation into Invoice and go to Edit IN?');">Process → Invoice</button>
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
      if (u > 0) {
        amt.value = (q * u).toFixed(2);
        recalc();
      } else {
        recalc();
      }
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
        '<td><textarea name="lines[' + idx + '][description]" class="form-control line-desc" rows="3"></textarea></td>' +
        '<td><input type="number" step="0.0001" min="0" name="lines[' + idx + '][quantity]" class="form-control line-qty" value="1"></td>' +
        '<td>' +
          '<input type="text" name="lines[' + idx + '][unit_label]" class="form-control line-unitlabel" placeholder="SET / PAX" style="margin-bottom:6px;">' +
          '<input type="number" step="0.01" min="0" name="lines[' + idx + '][unit_price]" class="form-control line-unit" value="" placeholder="0.00">' +
        '</td>' +
        '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][amount]" class="form-control line-amount text-right" value="" placeholder=""></td>';
      lineBody.appendChild(tr);
      bindLine(tr);
    });
  }
})();
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>

