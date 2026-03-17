<?php
// public/admin/customers/txn_view.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

// 允许从 customer portal 复用本页面打印收据：
// 若定义 ALLOW_TXN_VIEW_FROM_PORTAL=true，则跳过 require_admin，由外层负责权限 & header/footer。
$allowFromPortal = defined('ALLOW_TXN_VIEW_FROM_PORTAL') && ALLOW_TXN_VIEW_FROM_PORTAL === true;

if (!$allowFromPortal) {
    require_admin();
    require_perm('TXN.V');   // ★ 需要有 TXN.V 才能看交易收据
}

// ★ 自动处理两边签名 & 自动 CONFIRMED 的 helper
require_once __DIR__ . '/../../../app/txn_sign_status.php';

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/* ============================================================
   ✅ POB (Pay On Behalf) 同步：让 B 的 IN 跟着 OUT 状态
   - OUT(C) pay_source_type=CUSTOMER + pay_source_customer_id=B
   - 自动生成/更新：B 的 IN (in_kind=RETURN) notes 带 marker
   - OUT: DRAFT => delete B-IN
   - OUT: SENT/CONFIRMED => upsert B-IN status 同步
   ============================================================ */

function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  $st = $pdo->query("DESCRIBE `$table`");
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!empty($r['Field'])) $cols[$r['Field']] = true;
  }
  $cache[$key] = $cols;
  return $cols;
}

function filter_fields_by_table(PDO $pdo, string $table, array $fields): array {
  $cols = table_columns($pdo, $table);
  $out = [];
  foreach ($fields as $k => $v) {
    if (isset($cols[$k])) $out[$k] = $v;
  }
  return $out;
}

function insert_row(PDO $pdo, string $table, array $fields): int {
  $fields = filter_fields_by_table($pdo, $table, $fields);
  if (!$fields) throw new RuntimeException("No valid fields to insert into {$table}");

  $cols = array_keys($fields);
  $phs  = array_map(fn($c) => ':' . $c, $cols);

  $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ")
          VALUES (" . implode(',', $phs) . ")";
  $st = $pdo->prepare($sql);

  $params = [];
  foreach ($fields as $k => $v) $params[':' . $k] = $v;
  $st->execute($params);

  return (int)$pdo->lastInsertId();
}

function update_row(PDO $pdo, string $table, int $id, array $fields, string $pk = 'id'): void {
  $fields = filter_fields_by_table($pdo, $table, $fields);
  unset($fields[$pk]);
  if (!$fields) return;

  $sets = [];
  $params = [':_id' => $id];
  foreach ($fields as $k => $v) {
    $sets[] = "`$k` = :$k";
    $params[":$k"] = $v;
  }

  $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$pk` = :_id";
  $st  = $pdo->prepare($sql);
  $st->execute($params);
}

function find_pob_in(PDO $pdo, int $payerCid, string $marker): ?array {
  $sql = "SELECT * FROM customer_txn
          WHERE txn_type='IN'
            AND customer_id = :cid
            AND notes LIKE :mk
          ORDER BY id DESC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':cid' => $payerCid,
    ':mk'  => '%' . $marker . '%',
  ]);
  $r = $st->fetch();
  return $r ?: null;
}

function delete_pob_in(PDO $pdo, int $payerCid, string $marker): void {
  $cols = table_columns($pdo, 'customer_txn');
  if (!isset($cols['notes'])) return;

  $sql = "DELETE FROM customer_txn
          WHERE txn_type='IN'
            AND customer_id = :cid
            AND notes LIKE :mk";
  $pdo->prepare($sql)->execute([
    ':cid' => $payerCid,
    ':mk'  => '%' . $marker . '%',
  ]);
}

/**
 * ✅ 同步：让 B 的 IN 跟 OUT 状态一致
 * - OUT DRAFT: delete B-IN
 * - OUT SENT/CONFIRMED: upsert B-IN (status 同步, amount 同步)
 */
function sync_pob_in_from_out(PDO $pdo, array $outTxn, array $customerC): void {
  if (($outTxn['txn_type'] ?? '') !== 'OUT') return;

  $pst = strtoupper((string)($outTxn['pay_source_type'] ?? 'BANK'));
  if ($pst !== 'CUSTOMER') return;

  $payerCid = (int)($outTxn['pay_source_customer_id'] ?? 0);
  if ($payerCid <= 0) return;

  $outId   = (int)($outTxn['id'] ?? 0);
  if ($outId <= 0) return;

  $marker = '[POB OUT#' . $outId . ']';

  $status = strtoupper((string)($outTxn['status'] ?? 'DRAFT'));
  if ($status === 'PENDING') $status = 'SENT';

  if ($status === 'DRAFT') {
    delete_pob_in($pdo, $payerCid, $marker);
    return;
  }

  if (!in_array($status, ['SENT', 'CONFIRMED'], true)) {
    delete_pob_in($pdo, $payerCid, $marker);
    return;
  }

  $amount = (float)($outTxn['amount'] ?? 0);
  if ($amount <= 0) return;

  $txnDate = (string)($outTxn['txn_date'] ?? date('Y-m-d'));
  $cName   = (string)($customerC['name'] ?? 'Customer');

  $title = 'IN Repayment (Paid to ' . $cName . ')';
  $notes = $marker . ' Auto-generated repayment for paying customer. Linked OUT #' . $outId . '.';

  $fields = [
    'customer_id' => $payerCid,
    'txn_type'    => 'IN',
    'txn_date'    => $txnDate,
    'title'       => $title,
    'notes'       => $notes,

    'in_kind'          => 'RETURN',
    'amount'           => $amount,
    'allocated_amount' => 0,
    'order_total'      => 0,

    'status' => $status,

    'is_contra' => 0,

    'method'                 => 'CUSTOMER',
    'pay_source_type'        => 'CUSTOMER',
    'pay_source_customer_id' => null,
    'bank_account_id'        => null,

    'currency' => $outTxn['currency'] ?? null,
    'fx_rate'  => $outTxn['fx_rate'] ?? null,

    'require_signature' => 0,

    'updated_at' => date('Y-m-d H:i:s'),
  ];

  $existing = find_pob_in($pdo, $payerCid, $marker);
  if ($existing) {
    update_row($pdo, 'customer_txn', (int)$existing['id'], $fields);
  } else {
    $fields['created_at'] = date('Y-m-d H:i:s');
    insert_row($pdo, 'customer_txn', $fields);
  }
}

function upload_href(?string $fp): string {
  $fp = trim((string)$fp);
  if ($fp === '') return '';

  $fp = ltrim($fp, '/');

  if (strpos($fp, 'public/uploads/') === 0) $fp = substr($fp, strlen('public/'));
  if (strpos($fp, 'uploads/uploads/') === 0) $fp = substr($fp, strlen('uploads/'));
  if (strpos($fp, 'uploads/') !== 0) $fp = 'uploads/' . $fp;

  return '../' . $fp;
}

function bank_label_view(array $b): string {
  $parts = [];
  if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
  if (!empty($b['account_name'])) $parts[] = $b['account_name'];
  if (!empty($b['account_no']))   $parts[] = $b['account_no'];
  $label = implode(' · ', $parts);
  if (!empty($b['currency'])) $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
  return $label ?: ('Account #' . ($b['id'] ?? ''));
}

function amount_to_words(float $amount, string $currencyCode): string {
  $currencyCode = strtoupper(trim($currencyCode ?: 'MYR'));

  $units = [
    0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
    5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
    10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
    14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
    18 => 'Eighteen', 19 => 'Nineteen'
  ];
  $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];
  $scales = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 100 => 'Hundred'];

  $prefix = 'Ringgit Malaysia';
  $unitWord = 'Ringgit';
  $centWord = 'Sen';

  if ($currencyCode === 'USD') {
    $prefix = 'United States Dollar'; $unitWord = 'Dollar'; $centWord = 'Cent';
  } elseif ($currencyCode === 'SGD') {
    $prefix = 'Singapore Dollar'; $unitWord = 'Dollar'; $centWord = 'Cent';
  } elseif ($currencyCode !== 'MYR') {
    $prefix = $currencyCode; $unitWord = 'Unit'; $centWord = 'Cent';
  }

  $amount  = round($amount, 2);
  $whole   = (int)$amount;
  $decimal = (int)round(($amount - $whole) * 100);

  $toWords = function ($n) use ($units, $tens, $scales, &$toWords): string {
    if ($n < 20) return $units[$n];
    if ($n < 100) {
      $t = intdiv($n, 10);
      $r = $n % 10;
      return $tens[$t] . ($r ? ' ' . $units[$r] : '');
    }
    foreach ($scales as $value => $name) {
      if ($n >= $value) {
        $count = intdiv($n, $value);
        $rem   = $n % $value;
        $res   = $toWords($count) . ' ' . $name;
        if ($rem) $res .= $rem < 100 ? ' and ' . $toWords($rem) : ' ' . $toWords($rem);
        return $res;
      }
    }
    return '';
  };

  $parts = [];
  if ($whole > 0) $parts[] = $toWords($whole) . ' ' . $unitWord;
  if ($decimal > 0) $parts[] = $toWords($decimal) . ' ' . $centWord;
  if (!$parts) $parts[] = 'Zero ' . $unitWord;

  return $prefix . ' ' . implode(' and ', $parts) . ' Only';
}

/* ===== params ===== */
$cid        = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$id         = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$payment_id = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);

if ($cid <= 0 || $id <= 0) {
  http_response_code(400);
  exit('Missing parameters');
}

$back = $_GET['back'] ?? url('admin/customers/txn_list.php?customer_id=' . $cid);

/* ===== customer ===== */
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

/* ===== txn ===== */
$st = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id AND customer_id = :cid");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();
if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}

$isIn     = (($txn['txn_type'] ?? '') === 'IN');
$isOut    = (($txn['txn_type'] ?? '') === 'OUT');
$isContra = ((int)($txn['is_contra'] ?? 0) === 1);

$inKind = strtoupper(trim((string)($txn['in_kind'] ?? 'INVOICE')));
if (!in_array($inKind, ['INVOICE', 'RETURN', 'BONUS'], true)) $inKind = 'INVOICE';

$isInInvoice    = ($isIn && $inKind === 'INVOICE');
$isInNonInvoice = ($isIn && !$isInInvoice);

/* ✅ multi receipt mode: IN non-invoice and no payment_id => show all payment receipts */
$multiReceiptMode = ($isInNonInvoice && $payment_id <= 0);

// 默认不显示签名表单，后面根据条件再决定（避免未定义变量警告）
$showForm = false;

try { if ($isOut) sync_pob_in_from_out($pdo, $txn, $customer); } catch (Throwable $e) {}

/* ===== attachments (txn_files) ===== */
$attachments = [];
try {
  $st = $pdo->prepare("
    SELECT id, file_path, file_name, file_mime, created_at
      FROM customer_txn_files
     WHERE txn_id = :tid
  ORDER BY id ASC
  ");
  $st->execute([':tid' => $txn['id']]);
  $attachments = $st->fetchAll();
} catch (Throwable $e) { $attachments = []; }

/* ===== payments ===== */
$paymentLines        = [];
$paymentAttachments  = [];
$txnAttachmentsNotes = [];
$bankMap             = [];
$currentPayment      = null;

if ($isIn) {
  $st = $pdo->prepare("
    SELECT *
      FROM customer_txn_payments
     WHERE customer_txn_id = :tid
  ORDER BY payment_seq ASC, id ASC
  ");
  $st->execute([':tid' => $txn['id']]);
  $paymentLines = $st->fetchAll();

  $bankRows = $pdo->query("
    SELECT id, bank_code, account_name, account_no, currency
      FROM company_bank_accounts
     WHERE is_active = 1
  ORDER BY bank_code, account_name, account_no, id
  ")->fetchAll();
  foreach ($bankRows as $b) $bankMap[(int)$b['id']] = bank_label_view($b);

  if ($payment_id > 0 && $paymentLines) {
    foreach ($paymentLines as $pl) {
      if ((int)$pl['id'] === $payment_id) { $currentPayment = $pl; break; }
    }
  }

  if ($paymentLines) {
    $payIds = [];
    foreach ($paymentLines as $pl) {
      $pid = (int)($pl['id'] ?? 0);
      if ($pid > 0) $payIds[] = $pid;
    }
    $payIds = array_values(array_unique($payIds));
    if ($payIds) {
      $in  = implode(',', array_fill(0, count($payIds), '?'));
      try {
        $stA = $pdo->prepare("
          SELECT *
            FROM customer_txn_payment_attachments
           WHERE payment_id IN ($in)
        ORDER BY id ASC
        ");
        $stA->execute($payIds);
        foreach ($stA->fetchAll() as $ra) {
          $pid = (int)$ra['payment_id'];
          $paymentAttachments[$pid][] = $ra;
        }
      } catch (Throwable $e) {}
    }
  }

  try {
    $stT = $pdo->prepare("
      SELECT *
        FROM customer_txn_attachments
       WHERE customer_txn_id = :tid
    ORDER BY id ASC
    ");
    $stT->execute([':tid' => $txn['id']]);
    $txnAttachmentsNotes = $stT->fetchAll();
  } catch (Throwable $e) {}
}

/* ===== payer company/staff ===== */
$payerCompany = null;
$payerStaff   = null;

if (!empty($txn['payer_company_id'])) {
  $st = $pdo->prepare("SELECT id, name, reg_no FROM payer_companies WHERE id = :id");
  $st->execute([':id' => $txn['payer_company_id']]);
  $payerCompany = $st->fetch();
}
if (!empty($txn['payer_staff_id'])) {
  $st = $pdo->prepare("SELECT id, staff_name, ic_no FROM payer_company_staff WHERE id = :id");
  $st->execute([':id' => $txn['payer_staff_id']]);
  $payerStaff = $st->fetch();
}

$company_name     = $payerCompany['name']        ?? 'Vision Mix Sdn. Bhd.';
$company_reg_no   = $payerCompany['reg_no']      ?? '1622729-U';
$company_rep_name = $payerStaff['staff_name']    ?? 'Chong Ngan Xiong';
$company_rep_nric = $payerStaff['ic_no']         ?? '830204-10-5115';

$errors = [];

/* ===== signature requirement ===== */
$needSignature = (
  (($isOut) || $isInNonInvoice)
  && (int)($txn['require_signature'] ?? 0) === 1
  && !$isContra
);

/* ===== detect both signed (for this view) ===== */
if ($isInNonInvoice && $payment_id > 0 && $currentPayment) {
  $hasRecvSig  = !empty($currentPayment['receiver_signature_image'] ?? '');
  $hasPayerSig = !empty($currentPayment['payer_signature_image'] ?? '');
} else {
  $hasRecvSig  = !empty($txn['signature_image'] ?? '');
  $hasPayerSig = !empty($txn['payer_signature_image'] ?? '');
}
$bothSignedThisSide = ($hasRecvSig && $hasPayerSig);

/* ===== POST: save signatures (admin side) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $needSignature) {

  $payment_id = (int)($_POST['payment_id'] ?? $payment_id);

  $recvSig  = $_POST['receiver_signature'] ?? '';
  $payerSig = $_POST['payer_signature'] ?? '';

  if (
    (strpos((string)$recvSig, 'data:image/png;base64,') !== 0) &&
    (strpos((string)$payerSig, 'data:image/png;base64,') !== 0)
  ) {
    $errors['receiver_signature'] = t(
      'admin.customer_txn.view.error.sign_required',
      [],
      'Please sign at least one side before saving.'
    );
  }

  if (!$errors) {
    $params = [':id' => $id, ':cid' => $cid];
    $set = [];

    $newCustomerSigned = false;
    $newPayerSigned    = false;

    // ✅ OUT: recvSig = customer(recipient), payerSig = company(payer)
    // ✅ IN non-invoice: we still store into txn.signature_image & txn.payer_signature_image (and also payment line if payment_id exists)
    if ($isIn) {
      if (strpos((string)$recvSig, 'data:image/png;base64,') === 0) {
        $set[]               = "signature_image = :recv_sig";
        $params[':recv_sig'] = $recvSig;
      }
      if (strpos((string)$payerSig, 'data:image/png;base64,') === 0) {
        $set[]                = "payer_signature_image = :payer_sig";
        $params[':payer_sig'] = $payerSig;
        $newCustomerSigned    = true;
        $newPayerSigned       = true;
      }
    } else {
      if (strpos((string)$recvSig, 'data:image/png;base64,') === 0) {
        $set[]               = "signature_image = :recv_sig";
        $params[':recv_sig'] = $recvSig;
        $newCustomerSigned   = true;
      }
      if (strpos((string)$payerSig, 'data:image/png;base64,') === 0) {
        $set[]                = "payer_signature_image = :payer_sig";
        $params[':payer_sig'] = $payerSig;
        $newPayerSigned       = true;
      }
    }

    // ✅ also update payment line signatures for IN non-invoice
    if ($isInNonInvoice && $payment_id > 0) {
      $setPay    = [];
      $paramsPay = [':pid' => $payment_id, ':tid' => $txn['id']];

      if ($newCustomerSigned) {
        $setPay[]               = "receiver_signature_image = :recv_sig";
        $paramsPay[':recv_sig'] = $recvSig;
      }
      if ($newPayerSigned) {
        $setPay[]                = "payer_signature_image = :payer_sig";
        $paramsPay[':payer_sig'] = $payerSig;
      }
      if ($setPay) {
        $setPay[] = "updated_at = NOW()";
        $sqlPay   = "UPDATE customer_txn_payments
                     SET " . implode(', ', $setPay) . "
                     WHERE id = :pid AND customer_txn_id = :tid";
        $pdo->prepare($sqlPay)->execute($paramsPay);
      }
    }

    if ($set) {
      $set[] = "updated_at = NOW()";
      $sql = "UPDATE customer_txn
              SET " . implode(', ', $set) . "
              WHERE id = :id AND customer_id = :cid";
      $pdo->prepare($sql)->execute($params);
    }

    // ✅ auto mark signed / maybe confirm
    if ($isIn) {
      if (!empty($recvSig) && strpos((string)$recvSig, 'data:image/png;base64,') === 0) {
        txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'recipient');
      }
      if (!empty($payerSig) && strpos((string)$payerSig, 'data:image/png;base64,') === 0) {
        txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'payer');
      }
    } else {
      if ($newCustomerSigned) txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'recipient');
      if ($newPayerSigned)    txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'payer');
    }

    // ✅ sync POB again after signing if OUT
    try {
      $stR = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id AND customer_id = :cid");
      $stR->execute([':id' => $id, ':cid' => $cid]);
      $txn2 = $stR->fetch();
      if ($txn2 && (($txn2['txn_type'] ?? '') === 'OUT')) {
        sync_pob_in_from_out($pdo, $txn2, $customer);
      }
    } catch (Throwable $e) {}

    header('Location: ' . $back);
    exit;
  }
}

/* ===== display vars ===== */
// ✅ txn header date (fallback). Receipt date for IN non-invoice will be taken from payment pay_date (inside loop).
$txn_date = $txn['txn_date'] ?: substr((string)($txn['created_at'] ?? ''), 0, 10);

$mainCur       = $txn['currency'] ?: 'MYR';
$displayAmount = 0.0;
if ($isInNonInvoice && $payment_id > 0 && $currentPayment) {
  $mainCur       = $currentPayment['currency'] ?: ($txn['currency'] ?: 'MYR');
  $displayAmount = (float)($currentPayment['amount'] ?? 0);
} else {
  $displayAmount = $isInNonInvoice ? (float)($txn['order_total'] ?? 0) : (float)($txn['amount'] ?? 0);
}
$amountStrHeader = $mainCur . ' ' . number_format($displayAmount, 2);

/* ✅ receipt name / ic */
$rec_name = (string)($txn['recipient_name'] ?: ($customer['default_receipt_name'] ?? $customer['contact_name'] ?? ''));
$rec_nric = (string)($txn['recipient_nric'] ?: ($customer['default_receipt_nric'] ?? ''));

$vmChopUrl = url('admin/assets/img/vmchop.png');

$page_title = t('admin.customer_txn.view.page_title', [], 'Receipt / Confirmation');

if (!$allowFromPortal) {
    include __DIR__ . '/../include/header.php';
}
?>

<style>
  .sig-canvas{
    width:100%;
    max-width:100%;
    height:180px;
    border-radius:10px;
    background:#f9fafb;
    border:1px dashed #d1d5db;
    touch-action:none;
  }
  .sig-meta-line{
    font-size:11px;
    color:#4b5563;
    line-height:1.45;
  }

  /* ✅ screen: signature equal height layout */
  .sig-row{
    display:flex;
    gap:24px;
    align-items:stretch;
    flex-wrap:nowrap;
  }
  .sig-col{
    flex:1 1 0;
    min-width:0;
    display:flex;
    flex-direction:column;
  }
  .sig-image-box{
    height:180px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#fff;
    margin-bottom:6px;
    position:relative;
  }
  .sig-placeholder{
    font-size:12px;
    color:#9ca3af;
    text-align:left;
    width:100%;
  }
  .sig-meta{ margin-top:auto; }
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?php if ($isInInvoice): ?>
              <?= h(t('admin.customer_txn.view.eyebrow_in', [], 'IN transaction')) ?>
            <?php else: ?>
              <?= h(t('admin.customer_txn.view.eyebrow_out', [], 'Receipt preview')) ?>
            <?php endif; ?>
          </div>
          <h2 class="form-page-title"><?= h($customer['name']) ?></h2>
          <div class="form-page-subtitle">
            <?= h(t('admin.customer_txn.view.txn_label', [], 'Transaction')) ?>
            #<?= h($txn['id']) ?>
            · <?= h($txn['txn_type']) ?>
            · <?= h($amountStrHeader) ?>
          </div>
        </div>
        <div class="form-page-meta" style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">
          <span class="badge-soft"><?= h(strtoupper((string)$txn['status'])) ?></span>
          <?php if (!$isInInvoice): ?>
            <button type="button" class="btn btn-light btn-sm" onclick="printReceipt();">
              <?= h(t('admin.customer_txn.view.print_btn', [], 'Print / PDF')) ?>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($isInInvoice): ?>
        <div class="alert-info">
          <?= nl2br(h(t('admin.customer_txn.view.invoice_info', [], "This view is mainly for OUT / RETURN / BONUS receipts.\nFor INVOICE type, please continue to use your existing invoice print page."))) ?>
          <div style="margin-top:10px; display:flex; justify-content:space-between;">
            <a href="<?= h($back) ?>" class="btn btn-light"><?= h(t('admin.common.back', [], 'Back')) ?></a>
          </div>
        </div>
      <?php else: ?>

        <?php
          // payer/receiver blocks
          if ($isIn) {
            $payerNameBlock     = $customer['name'];
            $payerRegBlock      = $customer['reg_no'] ?? '';
            $receiverNameBlock  = $company_name;
            $receiverRegBlock   = $company_reg_no;
            $receiverIsCustomer = false;
          } else {
            $payerNameBlock     = $company_name;
            $payerRegBlock      = $company_reg_no;
            $receiverNameBlock  = $customer['name'];
            $receiverRegBlock   = $customer['reg_no'] ?? '';
            $receiverIsCustomer = true;
          }

          // signature title keys
          if ($isIn) {
            $leftSigKey  = 'admin.customer_txn.view.sig_customer_title_in';
            $rightSigKey = 'admin.customer_txn.view.sig_payer_title_in';
          } else {
            $leftSigKey  = 'admin.customer_txn.view.sig_customer_title_out';
            $rightSigKey = 'admin.customer_txn.view.sig_payer_title_out';
          }

          // receipt items (IN non-invoice: multi mode shows all payments)
          $receiptItems = [];
          if ($isInNonInvoice) {
            if ($multiReceiptMode) {
              $receiptItems = $paymentLines ?: [];
            } else {
              if ($payment_id > 0 && $currentPayment) $receiptItems = [$currentPayment];
              elseif ($paymentLines) $receiptItems = [$paymentLines[0]];
            }
          } else {
            $receiptItems = [null];
          }
        ?>

        <div id="receipt-print-area"
             style="border:1px solid var(--border); border-radius:14px; padding:24px 28px; background:#ffffff; margin-bottom:22px;">

          <?php if (empty($receiptItems)): ?>
            <p style="font-size:13px;color:#6b7280;">
              <?= h(t('admin.customer_txn.view.no_items', [], 'No receipt items to display.')) ?>
            </p>
          <?php else: ?>
            <?php foreach ($receiptItems as $idx => $onePayment): ?>
              <?php
                // ✅ receipt date (IMPORTANT FIX):
                // IN non-invoice: use payment pay_date; else fallback to txn_date.
                if ($isInNonInvoice && is_array($onePayment) && !empty($onePayment['pay_date'])) {
                  $receiptDate = (string)$onePayment['pay_date'];
                } else {
                  $receiptDate = (string)$txn_date;
                }

                // amount/currency
                if ($isInNonInvoice && is_array($onePayment)) {
                  $curLocal    = $onePayment['currency'] ?: ($txn['currency'] ?: 'MYR');
                  $amountLocal = (float)($onePayment['amount'] ?? 0.0);
                } else {
                  $curLocal    = $txn['currency'] ?: 'MYR';
                  $amountLocal = $isInNonInvoice ? (float)($txn['order_total'] ?? 0.0) : (float)($txn['amount'] ?? 0.0);
                }
                $amountStrLocal  = $curLocal . ' ' . number_format($amountLocal, 2);
                $amountWordLocal = amount_to_words($amountLocal, $curLocal);

                // method label (only show for IN payment lines like you wanted)
                $methodLabelLocal = '';
                if ($isInNonInvoice && is_array($onePayment)) {
                  $bid = (int)($onePayment['bank_account_id'] ?? 0);
                  $methodLabelLocal = ($bid > 0 && isset($bankMap[$bid])) ? $bankMap[$bid] : '';
                }

                // signatures (image)
                if ($isInNonInvoice && is_array($onePayment)) {
                  $recvSigImg  = (string)($onePayment['receiver_signature_image'] ?? '');
                  $payerSigImg = (string)($onePayment['payer_signature_image'] ?? '');
                } else {
                  $recvSigImg  = (string)($txn['signature_image'] ?? '');
                  $payerSigImg = (string)($txn['payer_signature_image'] ?? '');
                }

                // ✅ name/nric mapping (match your user receipt behavior)
                $leftName  = $isIn ? $company_rep_name : ($rec_name ?: '-');
                $leftNric  = $isIn ? $company_rep_nric : $rec_nric;
                $rightName = $isIn ? ($rec_name ?: '-') : $company_rep_name;
                $rightNric = $isIn ? $rec_nric : $company_rep_nric;

                // ✅ chop follows company side (IN: company is LEFT; OUT: company is RIGHT)
                $showChopLeft  = ($isIn  && $recvSigImg  !== '' && $vmChopUrl);
                $showChopRight = ($isOut && $payerSigImg !== '' && $vmChopUrl);
              ?>

              <div class="single-receipt-block">
                <div style="text-align:center; font-size:16px; font-weight:600; letter-spacing:0.12em; color:#1f2937; margin-bottom:18px;">
                  <?= h(t('admin.customer_txn.view.receipt_title', [], 'RECEIPT')) ?>
                  <?php if ($multiReceiptMode && is_array($onePayment)): ?>
                    <span style="font-size:11px;color:#6b7280;margin-left:6px;">#<?= (int)($idx + 1) ?></span>
                  <?php endif; ?>
                </div>

                <p style="font-size:13px; margin-bottom:4px;">
                  <strong><?= h(t('admin.customer_txn.field.date', [], 'Date')) ?>:</strong>
                  <?= ' ' . h($receiptDate) ?>
                </p>

                <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                  <strong><?= h(t('admin.customer_txn.view.received_from', [], 'Received from (Payer):')) ?></strong><br>
                  <?= h($payerNameBlock) ?>
                  <?php if ($payerRegBlock): ?> (<?= h($payerRegBlock) ?>) <?php endif; ?><br>

                  <?php if ($isOut): ?>
                    <?= h(t('admin.customer_txn.view.rep', [], 'Rep:')) ?>
                    <?= ' ' . h($company_rep_name) ?>
                    <?php if ($company_rep_nric): ?>
                      (<?= h(t('admin.customer_txn.view.nric', [], 'NRIC:')) . ' ' . h($company_rep_nric) ?>)
                    <?php endif; ?>
                  <?php endif; ?>
                </p>

                <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                  <strong><?= h(t('admin.customer_txn.view.received_by', [], 'Received by / On behalf of (Receiver):')) ?></strong><br>
                  <?= h($receiverNameBlock) ?>
                  <?php if ($receiverRegBlock): ?> (<?= h($receiverRegBlock) ?>) <?php endif; ?><br>
                </p>

                <?php if ($needSignature): ?>
                  <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                    <strong><?= h(t('admin.customer_txn.view.recipient', [], 'Customer representative (who signs):')) ?></strong><br>
                    <?php if ($rec_name): ?>
                      • <?= h($rec_name) ?><br>
                      <?php if ($rec_nric): ?>
                        • <?= h(t('admin.customer_txn.view.nric', [], 'NRIC:')) ?> <?= h($rec_nric) ?><br>
                      <?php endif; ?>
                    <?php else: ?>
                      • <?= h(t('admin.customer_txn.view.recipient_fill', [], '(please fill in customer recipient name)')) ?>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>

                <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                  <strong><?= h(t('admin.customer_txn.field.amount', [], 'Amount')) ?>:</strong>
                  <?= ' ' . h($amountStrLocal) ?><br>
                  (<?= h($amountWordLocal) ?>)
                </p>

                <?php if ($methodLabelLocal !== ''): ?>
                  <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                    <strong><?= h(t('admin.customer_txn.field.method', [], 'Method')) ?>:</strong>
                    <?= ' ' . h($methodLabelLocal) ?>
                  </p>
                <?php endif; ?>

                <p style="font-size:13px; margin-top:12px; margin-bottom:18px;">
                  <?= h(t('admin.customer_txn.view.receipt_confirm', [], 'This receipt confirms the above amount has been received.')) ?>
                </p>

                <?php if ($needSignature): ?>
                  <div style="margin-top:24px;">
                    <div class="sig-row">
                      <!-- LEFT -->
                      <div class="sig-col">
                        <p style="font-size:13px; font-weight:600; margin-bottom:6px;">
                          <?= h(t($leftSigKey, [], 'Signature')) ?>
                        </p>

                        <div class="sig-image-box">
                          <?php if ($recvSigImg): ?>
                            <img src="<?= h($recvSigImg) ?>" alt="Signature"
                                 style="max-width:100%; max-height:170px; object-fit:contain; display:block;">
                            <?php if ($showChopLeft): ?>
                              <img src="<?= h($vmChopUrl) ?>" alt="Company chop"
                                   style="position:absolute; right:10px; bottom:4px; max-height:80px; opacity:0.9;">
                            <?php endif; ?>
                          <?php else: ?>
                            <div class="sig-placeholder">
                              <?= h(t('admin.customer_txn.view.sig_customer_none', [], 'No signature yet.')) ?>
                            </div>
                          <?php endif; ?>
                        </div>

                        <!-- ✅ ALWAYS 3 lines, no more "..." -->
                        <div class="sig-meta">
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.name_label', [], 'Name:')) ?></strong>
                            <?= ' ' . h($leftName ?: '-') ?>
                          </div>
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.nric_label', [], 'NRIC:')) ?></strong>
                            <?= ' ' . h($leftNric ?: '-') ?>
                          </div>
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.date_label', [], 'Date:')) ?></strong>
                            <?= ' ' . h($receiptDate) ?>
                          </div>
                        </div>
                      </div>

                      <!-- RIGHT -->
                      <div class="sig-col">
                        <p style="font-size:13px; font-weight:600; margin-bottom:6px;">
                          <?= h(t($rightSigKey, [], 'Signature')) ?>
                        </p>

                        <div class="sig-image-box">
                          <?php if ($payerSigImg): ?>
                            <img src="<?= h($payerSigImg) ?>" alt="Signature"
                                 style="max-width:100%; max-height:170px; object-fit:contain; display:block;">
                            <?php if ($showChopRight): ?>
                              <img src="<?= h($vmChopUrl) ?>" alt="Company chop"
                                   style="position:absolute; right:10px; bottom:4px; max-height:80px; opacity:0.9;">
                            <?php endif; ?>
                          <?php else: ?>
                            <div class="sig-placeholder">
                              <?= h(t('admin.customer_txn.view.sig_payer_none', [], 'No signature yet.')) ?>
                            </div>
                          <?php endif; ?>
                        </div>

                        <!-- ✅ ALWAYS 3 lines -->
                        <div class="sig-meta">
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.name_label', [], 'Name:')) ?></strong>
                            <?= ' ' . h($rightName ?: '-') ?>
                          </div>
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.nric_label', [], 'NRIC:')) ?></strong>
                            <?= ' ' . h($rightNric ?: '-') ?>
                          </div>
                          <div class="sig-meta-line">
                            <strong><?= h(t('admin.customer_txn.view.date_label', [], 'Date:')) ?></strong>
                            <?= ' ' . h($receiptDate) ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endif; ?>

              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <?php
          // portal 复用时只读，不在这里签名（客户在自己的专用签名页签）
          $portalReadonly = defined('TXN_VIEW_PORTAL_READONLY') && TXN_VIEW_PORTAL_READONLY === true;
          $showForm = (!$allowFromPortal && $needSignature && !$bothSignedThisSide && !$multiReceiptMode);
        ?>
        <?php if ($showForm): ?>
          <div class="form-section">
            <div class="form-section-header">
              <div>
                <div class="form-section-title"><?= h(t('admin.customer_txn.view.sign_here_title', [], 'Sign here')) ?></div>
                <div class="form-section-desc">
                  <?= h(t('admin.customer_txn.view.sign_here_desc', [], 'Either side can sign first. Status will become CONFIRMED only after customer signs.')) ?>
                </div>
              </div>
            </div>

            <form method="post" id="sign-form">
              <input type="hidden" name="customer_id" value="<?= h($cid) ?>">
              <input type="hidden" name="id" value="<?= h($id) ?>">
              <input type="hidden" name="payment_id" value="<?= h($payment_id) ?>">
              <input type="hidden" name="receiver_signature" id="receiver_signature" value="">
              <input type="hidden" name="payer_signature" id="payer_signature" value="">

              <div style="display:flex; flex-wrap:wrap; gap:20px;">
                <div style="flex:1; min-width:260px;">
                  <div style="font-size:12px; color:var(--muted); margin-bottom:6px;">
                    <?= h(t('admin.customer_txn.view.canvas_customer_tip', [], $isIn ? 'Customer (Payer) – sign inside the box' : 'Customer (Receiver) – sign inside the box')) ?>
                  </div>
                  <canvas id="sig-receiver" class="sig-canvas"></canvas>
                  <?php if (isset($errors['receiver_signature'])): ?>
                    <div class="form-error"><?= h($errors['receiver_signature']) ?></div>
                  <?php endif; ?>
                  <button type="button" class="btn btn-light" id="btn-clear-receiver" style="margin-top:6px;">
                    <?= h(t('admin.customer_txn.view.clear_btn', [], 'Clear')) ?>
                  </button>
                </div>

                <div style="flex:1; min-width:260px;">
                  <div style="font-size:12px; color:var(--muted); margin-bottom:6px;">
                    <?= h(t('admin.customer_txn.view.canvas_payer_tip', [], $isIn ? 'Our company (Receiver) – sign inside the box' : 'Our company (Payer) – sign inside the box')) ?>
                  </div>
                  <canvas id="sig-payer" class="sig-canvas"></canvas>
                  <button type="button" class="btn btn-light" id="btn-clear-payer" style="margin-top:6px;">
                    <?= h(t('admin.customer_txn.view.clear_btn', [], 'Clear')) ?>
                  </button>
                </div>
              </div>

              <div style="margin-top:10px; display:flex; justify-content:space-between;">
                <a href="<?= h($back) ?>" class="btn btn-light"><?= h(t('admin.common.back', [], 'Back')) ?></a>
                <button type="submit" class="btn btn-primary"><?= h(t('admin.customer_txn.view.save_signatures', [], 'Save signatures')) ?></button>
              </div>
            </form>
          </div>
        <?php else: ?>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
            <a href="<?= h($back) ?>" class="btn btn-light"><?= h(t('admin.common.back', [], 'Back')) ?></a>
            <?php if ($needSignature): ?>
              <span style="font-size:12px;color:#6b7280;">
                <?= h(t('admin.customer_txn.view.sign_done', [], 'Signatures captured / no further signature required here.')) ?>
              </span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php endif; ?>

    </div>

  </div>
</div>

<?php if ($showForm): ?>
  <script src="<?= h(url('assets/js/sign_pad.js')) ?>"></script>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      const form = document.getElementById("sign-form");
      if (!form) return;

      const signRequiredMsg = <?= json_encode(
        t('admin.customer_txn.view.error.sign_required', [], 'Please sign at least one side before saving.')
      ) ?>;

      const receiverPad = window.VMSignPad("sig-receiver", "btn-clear-receiver", { lineWidth: 2 });
      const payerPad    = window.VMSignPad("sig-payer", "btn-clear-payer", { lineWidth: 2 });

      form.addEventListener("submit", function(e) {
        if (!receiverPad || !payerPad) return;

        if (!receiverPad.hasDrawn() && !payerPad.hasDrawn()) {
          e.preventDefault();
          alert(signRequiredMsg);
          return;
        }

        document.getElementById("receiver_signature").value = receiverPad.hasDrawn() ? receiverPad.getImage() : "";
        document.getElementById("payer_signature").value    = payerPad.hasDrawn() ? payerPad.getImage() : "";
      });
    });
  </script>
<?php endif; ?>

<script>
  // ✅ Admin receipt print: 保持左右同高 + 不换行 + 每张一页
  function printReceipt() {
    const el = document.getElementById('receipt-print-area');
    if (!el) { alert('Receipt not found'); return; }

    const w = window.open('', '_blank', 'width=900,height=650');
    if (!w) { alert('Popup blocked'); return; }

    const html = `
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Receipt</title>
  <style>
    @page { margin: 12mm; }
    body { font-family: Arial, sans-serif; color:#111; }

    /* ✅ 不要打印外框 / 阴影 */
    #receipt-print-area{
      border:0 !important; box-shadow:none !important; border-radius:0 !important;
      padding:0 !important; margin:0 !important; background:#fff !important;
    }

    /* ✅ 每张 receipt 一页（最后一张不额外分页） */
    .single-receipt-block{
      page-break-after: always;
      break-after: page;
      page-break-inside: avoid;
      break-inside: avoid;
    }
    .single-receipt-block:last-child{
      page-break-after: auto;
      break-after: auto;
    }

    img { max-width:100%; }
    a { color:#111; text-decoration:none; }

    /* ✅ 强制 print 时签名左右不变上下 */
    .sig-row{
      display:flex !important;
      gap:24px !important;
      align-items:stretch !important;
      flex-wrap:nowrap !important;
    }
    .sig-col{
      flex:1 1 0 !important;
      min-width:0 !important;
      display:flex !important;
      flex-direction:column !important;
    }
    .sig-image-box{
      height:180px !important;
      display:flex !important;
      align-items:center !important;
      justify-content:center !important;
      position:relative !important;
      background:#fff !important;
      margin-bottom:6px !important;
    }
    .sig-placeholder{ font-size:12px !important; color:#9ca3af !important; width:100% !important; }
    .sig-meta{ margin-top:auto !important; }
    .sig-meta-line{ font-size:11px !important; color:#4b5563 !important; line-height:1.35 !important; }

  </style>
</head>
<body>
  <div id="receipt-print-area">${el.innerHTML}</div>
  <script>
    window.onload = function(){
      window.focus();
      window.print();
      setTimeout(()=>window.close(), 250);
    };
  <\/script>
</body>
</html>`;

    w.document.open();
    w.document.write(html);
    w.document.close();
  }
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
