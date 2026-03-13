<?php
// public/admin/customers/txn_receipt_in.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('TXN.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function fmt_money(float $v): string
{
  return number_format($v, 2, '.', ',');
}

function amount_to_words_rm(float $amount): string
{
  $units = [
    0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
    5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
    10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
    14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
    18 => 'Eighteen', 19 => 'Nineteen'
  ];
  $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];
  $scales = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 100 => 'Hundred'];

  $amount  = round($amount, 2);
  $ringgit = (int)$amount;
  $sen     = (int)round(($amount - $ringgit) * 100);

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
  if ($ringgit > 0) $parts[] = $toWords($ringgit) . ' Ringgit';
  if ($sen > 0)     $parts[] = $toWords($sen) . ' Sen';
  if (!$parts)      $parts[] = 'Zero Ringgit';

  return 'Ringgit Malaysia ' . implode(' and ', $parts) . ' Only';
}

function table_columns(PDO $pdo, string $table): array
{
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (!empty($r['Field'])) $cols[$r['Field']] = true;
    }
  } catch (Throwable $e) {
  }
  $cache[$key] = $cols;
  return $cols;
}

/**
 * payment 换算到 base currency（txn.currency）
 */
function pay_to_base(array $p, string $baseCur): float
{
  $amt = (float)($p['amount'] ?? 0);
  $cur = strtoupper(trim((string)($p['currency'] ?? '')));
  $fx  = (float)($p['fx_rate'] ?? 0);

  $baseCur = strtoupper(trim($baseCur ?: 'MYR'));
  if ($cur === '' || $cur === $baseCur) return $amt;
  return $fx > 0 ? $amt * $fx : 0.0;
}

/**
 * ✅ 统一重算状态（按你规则）：
 * confirm 前提：paid_total(base) >= order_total
 * - 不勾签名：paid够就CONFIRMED
 * - 勾我们签：paid够 + 我们签了
 * - 勾对方签：paid够 + 对方签了
 * - 勾两个签：paid够 + 两边都签了
 * - IN（不管 INVOICE/RETURN/BONUS）：签名看最后一张 payment（统一）
 */
function recompute_in_txn_status(PDO $pdo, int $txnId): void
{
  $colsTxn = table_columns($pdo, 'customer_txn');
  $st = $pdo->prepare("SELECT id, in_kind, currency, order_total, sign_receive, sign_payer, doc_flow_type, doc_flow_status FROM customer_txn WHERE id=:id LIMIT 1");
  $st->execute([':id' => $txnId]);
  $txn = $st->fetch(PDO::FETCH_ASSOC);
  if (!$txn) return;

  $mainCur = strtoupper(trim((string)($txn['currency'] ?? 'MYR')));
  if ($mainCur === '') $mainCur = 'MYR';

  $orderTotal = (float)($txn['order_total'] ?? 0);
  $needOur = ((int)($txn['sign_receive'] ?? 0) === 1); // 我们签
  $needCus = ((int)($txn['sign_payer'] ?? 0) === 1);   // 客户签

  $paid = 0.0;
  $stp = $pdo->prepare("SELECT * FROM customer_txn_payments WHERE customer_txn_id=:id ORDER BY pay_date ASC, id ASC");
  $stp->execute([':id' => $txnId]);
  $pays = $stp->fetchAll(PDO::FETCH_ASSOC);

  foreach ($pays as $p) {
    $paid += pay_to_base($p, $mainCur);
  }

  $paidEnough = ($orderTotal > 0 && ($paid + 0.0001) >= $orderTotal);

  $signOk = true;
  if ($needOur || $needCus) {
    $last = $pays ? $pays[count($pays) - 1] : [];

    $cusDone = !empty($last['payer_signature_image'] ?? '') || !empty($last['payer_signed_at'] ?? '');
    $ourDone = !empty($last['receiver_signature_image'] ?? '') || !empty($last['receiver_signed_at'] ?? '');

    if ($needCus && !$cusDone) $signOk = false;
    if ($needOur && !$ourDone) $signOk = false;
  }

  $newStatus = ($paidEnough && $signOk) ? 'CONFIRMED' : 'PENDING';
  $flowType = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
  if (!in_array($flowType, ['NORMAL', 'QUOTATION'], true)) $flowType = 'NORMAL';
  $hasFlowStat = isset($colsTxn['doc_flow_status']);

  if ($newStatus === 'CONFIRMED') {
    $set = ["status='CONFIRMED'", "confirmed_at=IFNULL(confirmed_at,NOW())", "updated_at=NOW()"];
    if ($hasFlowStat && $flowType === 'NORMAL') $set[] = "doc_flow_status='COMPLETED'";
    $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id=:id")->execute([':id' => $txnId]);
  } else {
    $set = ["status='PENDING'", "updated_at=NOW()"];
    if ($hasFlowStat && $flowType === 'NORMAL') $set[] = "doc_flow_status='PROCESSING'";
    $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id=:id")->execute([':id' => $txnId]);
  }
}

/**
 * helper: build receipt meta
 */
function receipt_meta(array $txn, ?array $p, ?array $bankAccount, string $baseCurrency): array
{
  $receiptNo = '';
  $receiptDate = '';
  $docNo = (string)($txn['invoice_no'] ?? '');
  $docDate = (string)($txn['txn_date'] ?? '');

  if ($p) {
    $receiptNo = (string)($p['or_no'] ?? '');
    if ($receiptNo === '') $receiptNo = 'R' . (int)$p['id'];
    $receiptDate = (string)($p['pay_date'] ?? '');
    if ($receiptDate === '') $receiptDate = (string)($txn['txn_date'] ?? substr((string)$txn['created_at'], 0, 10));
    if ($docDate === '') $docDate = $receiptDate;
  } else {
    $receiptNo = (string)($txn['or_no'] ?: $txn['invoice_no'] ?: ('TXN-' . $txn['id']));
    $receiptDate = (string)($txn['txn_date'] ?: substr((string)$txn['created_at'], 0, 10));
    if ($docDate === '') $docDate = $receiptDate;
  }

  $chequeNo = 'CASH';
  $receivedInText = 'CASH';

  if ($bankAccount) {
    $code  = strtoupper(trim((string)($bankAccount['bank_code'] ?? '')));
    $accNo = trim((string)($bankAccount['account_no'] ?? ''));
    $accNm = trim((string)($bankAccount['account_name'] ?? ''));

    $parts = [];
    if ($code !== '')  $parts[] = $code;
    if ($accNo !== '') $parts[] = $accNo;
    if ($accNm !== '') $parts[] = $accNm;
    if ($parts) $receivedInText = implode(' - ', $parts);

    if ($code !== '' && $code !== 'CASH') $chequeNo = 'ONLINE TRANSFER';
  }

  return [
    'receiptNo' => $receiptNo,
    'receiptDate' => $receiptDate,
    'docNo' => $docNo,
    'docDate' => $docDate,
    'chequeNo' => $chequeNo,
    'receivedInText' => $receivedInText,
    'currencyCode' => $baseCurrency,
  ];
}

// ---------- params ----------
$id        = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);
$docMode   = strtoupper(trim((string)($_GET['doc'] ?? $_POST['doc'] ?? 'INVOICE')));
if (!in_array($docMode, ['INVOICE', 'DO'], true)) {
  $docMode = 'INVOICE';
}

if ($id <= 0) {
  http_response_code(400);
  exit('Missing transaction id');
}

// ---- Load txn + customer ----
$sql = "
  SELECT t.*, c.name AS customer_name, c.reg_no AS customer_reg_no
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
";
$st = $pdo->prepare($sql);
$st->execute([':id' => $id]);
$txn = $st->fetch();

if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}
if ((string)$txn['txn_type'] !== 'IN') {
  http_response_code(400);
  exit('This page is only for IN transactions.');
}

// ===== IN 类型（INVOICE / RETURN / BONUS） =====
$inKind = strtoupper(trim((string)($txn['in_kind'] ?? 'INVOICE')));
if (!in_array($inKind, ['INVOICE', 'RETURN', 'BONUS'], true)) $inKind = 'INVOICE';

// customer info
$customerName  = (string)($txn['customer_name'] ?? '');
$customerRegNo = (string)($txn['customer_reg_no'] ?? '');

// ✅ Back：永远回 txn_list（url），并把 filters 带回去
$backKeys = ['date_from','date_to','type','status','method','q'];
$qBack = ['customer_id' => (int)$txn['customer_id']];
foreach ($backKeys as $k) {
  if (isset($_GET[$k]) && $_GET[$k] !== '') $qBack[$k] = (string)$_GET[$k];
}
$back = url('admin/customers/txn_list.php' . ($qBack ? ('?' . http_build_query($qBack)) : ''));

// ====== 所有 payment（所有 receipts）======
$st = $pdo->prepare("
  SELECT *
  FROM customer_txn_payments
  WHERE customer_txn_id = :tid
  ORDER BY pay_date ASC, id ASC
");
$st->execute([':tid' => $id]);
$allPays = $st->fetchAll(PDO::FETCH_ASSOC);
$receiptCount = count($allPays);

// paymentId 有指定才是“单张查看 + 可签名”
$payment = null;
if ($paymentId > 0) {
  foreach ($allPays as $p) {
    if ((int)$p['id'] === $paymentId) {
      $payment = $p;
      break;
    }
  }
  if (!$payment) {
    http_response_code(404);
    exit('Payment not found');
  }
}

// 取 bank info（只在单张显示时用）
$bankAccount = null;
if ($payment) {
  $bankId = (int)($payment['bank_account_id'] ?? 0);
  if ($bankId > 0) {
    $st = $pdo->prepare("
      SELECT id, bank_code, account_name, account_no, currency
      FROM company_bank_accounts
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $bankId]);
    $bankAccount = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

// ---- base amounts ----
$baseCurrency = (string)($txn['currency'] ?: 'MYR');
$origAmount   = (float)($txn['order_total'] ?? 0);

// ---- signature & chop switches ----
// sign_mode: SIGN_AND_CHOP（默认）/ CHOP_ONLY / SIGN_ONLY（预留）
$txnCols = table_columns($pdo, 'customer_txn');
$signMode = 'SIGN_AND_CHOP';
if (isset($txnCols['sign_mode'])) {
  $m = strtoupper(trim((string)($txn['sign_mode'] ?? '')));
  if (in_array($m, ['SIGN_AND_CHOP', 'CHOP_ONLY', 'SIGN_ONLY'], true)) {
    $signMode = $m;
  }
}
$signReceive = !empty($txn['sign_receive']); // 我们签
$signPayer   = !empty($txn['sign_payer']);   // 客户签

// logo / chop
$logoUrl = url('admin/assets/img/vmlogo.png');
$chopUrl = url('admin/assets/img/vmchop.png');

// 依赖 $chopUrl 的开关放在 logo / chop 定义之后
$needOurSig  = ($signMode !== 'CHOP_ONLY') && $signReceive;
$showChop    = ($chopUrl !== '') && ($signMode !== 'SIGN_ONLY');

// pay signature columns exist?
$payCols = table_columns($pdo, 'customer_txn_payments');
$canPaySig = isset($payCols['receiver_signature_image']) && isset($payCols['payer_signature_image']);
$company = function_exists('get_company') ? get_company() : ['name' => 'VISION MIX SDN BHD', 'reg_no' => '1622729-U', 'address' => ['LOT 3A-02A, 4TH FLOOR ENDAH PARADE,', 'NO.1 JALAN 1/149E, BANDAR BARU SRI PETALING,', '57000 KUALA LUMPUR'], 'phone' => '', 'email' => ''];
$companyName = (string)($company['name'] ?? '');
$companyRegNo = (string)($company['reg_no'] ?? '');
$companyAddress = (array)($company['address'] ?? []);
$companyPhone = (string)($company['phone'] ?? '');
$companyEmail = (string)($company['email'] ?? '');

// ✅ 标题（重点：不要出现 “IN Receipt / IN Official Receipt / …IN…”）
if ($inKind === 'RETURN') {
  $headerEyebrow = 'Loan repayment';
  $pageTitleText = 'Loan Repayment Receipt';
  $linePurpose   = 'Loan repayment';
} elseif ($inKind === 'BONUS') {
  $headerEyebrow = 'Bonus / profit share';
  $pageTitleText = 'Bonus Receipt';
  $linePurpose   = 'Bonus / profit share';
} else {
  $headerEyebrow = 'Official Receipt';
  $pageTitleText = 'Official Receipt';
  $linePurpose   = 'Payment For Account';
}

if ($inKind === 'INVOICE' && $docMode === 'DO') {
  $headerEyebrow = 'Delivery Order';
  $pageTitleText = 'Delivery Order';
}

$docNoLabel = ($docMode === 'DO') ? 'DO No' : 'Invoice No';
$docLinePurpose = ($inKind === 'INVOICE' && $docMode === 'DO') ? 'Delivery Order' : $linePurpose;

// ========== VIEW MODE ==========
$showAll = ($paymentId <= 0);

// ======== POST: save signature (only when single receipt mode) ========
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_sign') {
  $pid = (int)($_POST['payment_id'] ?? 0);

  if ($pid <= 0) {
    $errors['signature'] = 'Missing payment_id.';
  } elseif (!$canPaySig) {
    $errors['signature'] = 'Payment signature columns not found.';
  } elseif (!$signReceive && !$signPayer) {
    $errors['signature'] = 'Signature not required.';
  } else {
    $recvPosted  = (string)($_POST['receiver_signature'] ?? ''); // customer canvas
    $payerPosted = (string)($_POST['payer_signature'] ?? '');    // our canvas

    $hasNewRecv  = (strpos($recvPosted,  'data:image/png;base64,') === 0);
    $hasNewPayer = (strpos($payerPosted, 'data:image/png;base64,') === 0);

    $valid = false;
    if ($signPayer && $hasNewRecv)    $valid = true; // customer
    if ($signReceive && $hasNewPayer) $valid = true; // our

    if (!$valid) {
      $errors['signature'] = 'Please sign at least one enabled side before saving.';
    } else {
      $set = [];
      $params = [':pid' => $pid, ':tid' => $id];

      if ($signPayer && $hasNewRecv) {
        $set[] = "payer_signature_image = :cus_sig";
        $params[':cus_sig'] = $recvPosted;
        if (isset($payCols['payer_signed_at'])) $set[] = "payer_signed_at = IFNULL(payer_signed_at, NOW())";
      }
      if ($signReceive && $hasNewPayer) {
        $set[] = "receiver_signature_image = :our_sig";
        $params[':our_sig'] = $payerPosted;
        if (isset($payCols['receiver_signed_at'])) $set[] = "receiver_signed_at = IFNULL(receiver_signed_at, NOW())";
      }

      $set[] = "updated_at = NOW()";
      $sqlUp = "UPDATE customer_txn_payments SET " . implode(', ', $set) . " WHERE id=:pid AND customer_txn_id=:tid";
      $pdo->prepare($sqlUp)->execute($params);

      recompute_in_txn_status($pdo, (int)$id);

      header('Location: ' . url('admin/customers/txn_receipt_in.php?id=' . $id . '&payment_id=' . $pid));
      exit;
    }
  }
}

// ✅ 页面标题
$page_title = $pageTitleText . ' #TXN-' . $id;
include __DIR__ . '/../include/header.php';
?>

<style>
@media print {
  @page { margin: 6mm 5mm; }
  @page :first { margin-top: 0; margin-left: 5mm; margin-right: 5mm; margin-bottom: 6mm; }
  html, body { margin:0!important; padding:0!important; background:#fff!important; }
  body * { visibility:hidden!important; }
  #receipt-print-area, #receipt-print-area * { visibility:visible!important; }
  .admin-main, .admin-main-inner, .admin-card { margin:0!important; padding:0!important; }
  #receipt-print-area {
    position: static!important;
    width: 100%!important;
    margin: 0!important;
    padding: 0!important;
  }

  .admin-header, .admin-sidebar, .admin-footer, .form-page-header, .form-section { display:none!important; }

  .no-print { display:none!important; visibility:hidden!important; }

  .single-receipt-block {
    margin: 0!important;
    padding: 0!important;
    page-break-after: always;
    break-after: page;
  }
  .single-receipt-block:first-child {
    margin-top: 0!important;
    padding-top: 0!important;
  }
  .single-receipt-block:last-child {
    page-break-after: auto;
    break-after: auto;
  }

  .receipt-shell {
    border: none!important;
    box-shadow: none!important;
    page-break-inside: avoid!important;
    width: 100%!important;
    max-width: none!important;
    margin: 0!important;
    padding: 16px 20px!important;
    border-radius: 0!important;
  }

  /* print: no signature boxes, use underline style */
  .receipt-sig-box {
    border: 0 !important;
    border-radius: 0 !important;
    padding: 0 !important;
  }
  .receipt-sig-box::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    border-top: 1px solid #000;
  }
}

/* 屏幕：一页一张分页 + 收据框样式 */
.single-receipt-block {
  page-break-after: always;
  break-after: page;
  margin: 0!important;
}
.single-receipt-block:last-child {
  page-break-after: auto;
  break-after: auto;
}

.receipt-shell {
  border:1px solid #e5e7eb;
  border-radius:16px;
  padding:18px 22px;
  background:#fff;
  font-size:13px;
  color:#111827;
  page-break-inside: avoid!important;
  width: 100%!important;
  margin: 0 auto!important;
}
.receipt-header { width:100%; margin-bottom:10px; }
.receipt-header td { vertical-align:top; }
.receipt-logo img { max-height:55px; }
.receipt-company-block { font-size:10px; line-height:1.4; }
.receipt-company-name { font-size:13px; font-weight:bold; text-transform:uppercase; }
.receipt-meta-block { font-size:10px; text-align:right; line-height:1.5; }
.receipt-meta-block .label { font-weight:bold; display:inline-block; min-width:110px; }
.receipt-line { border-top:1px solid #000; margin:8px 0; }
.receipt-label-row { font-size:10px; margin-bottom:3px; }
.receipt-label-row .label { display:inline-block; min-width:90px; font-weight:bold; }
.receipt-table { width:100%; border-collapse:collapse; font-size:10px; margin-top:6px; }
.receipt-table th, .receipt-table td { border:1px solid #000; padding:3px 4px; }
.receipt-table th { background:#f3f4f6; text-align:left; }
.text-right { text-align:right; }
.receipt-amount-words { margin-top:12px; font-size:10px; font-weight:bold; }
.receipt-nb { margin-top:12px; font-size:9px; }
.receipt-nb-title { font-weight:bold; }
.receipt-sign-row { width:100%; margin-top:22px; }
.receipt-sign-cell { width:50%; font-size:9px; vertical-align:top; }
.receipt-sign-block { width:260px; max-width:100%; }
.receipt-sig-box { position:relative; border:1px solid #9ca3af; border-radius:6px; padding:6px; height:80px; text-align:center; margin-bottom:4px; overflow:hidden; }
.receipt-sig-main { max-width:100%; max-height:70px; }
.receipt-chop { position:absolute; right:4px; bottom:3px; max-height:55px; opacity:0.95; }

.sig-canvas { width:100%; max-width:100%; height:180px; border-radius:10px; background:#f9fafb; border:1px dashed #d1d5db; touch-action:none; }
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow"><?= h($headerEyebrow) ?></div>
          <h2 class="form-page-title"><?= h($customerName) ?></h2>
          <div class="form-page-subtitle">
            Txn #<?= (int)$txn['id'] ?> · <?= h($baseCurrency) ?> <?= fmt_money($origAmount) ?>
            <?php if ($showAll): ?>
              · <span style="color:#6b7280;">All receipts</span>
            <?php else: ?>
              · <span style="color:#2563eb;">Receipt: <?= h((string)($payment['or_no'] ?? ('R'.$paymentId))) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-page-meta" style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
          <span class="badge-soft"><?= h(strtoupper((string)($txn['status'] ?? ''))) ?></span>

          <?php if ($receiptCount > 0): ?>
            <div style="font-size:11px;color:#6b7280;text-align:right;">
              Receipts: <?= (int)$receiptCount ?> total
            </div>
          <?php endif; ?>

          <div class="no-print" style="display:flex;gap:6px;margin-top:6px;">
            <a href="<?= h($back) ?>" class="btn btn-light btn-sm"><?= h(t('admin.common.back', [], 'Back')) ?></a>
            <button type="button" class="btn btn-light btn-sm" onclick="window.print();">Print</button>
          </div>
        </div>
      </div>

      <?php if (!empty($errors['signature'])): ?>
        <div class="alert-error no-print" style="margin-bottom:10px;"><?= h($errors['signature']) ?></div>
      <?php endif; ?>

      <div id="receipt-print-area" style="margin-top:10px;">

        <?php if ($showAll): ?>

          <?php if (!$allPays): ?>
            <div class="receipt-shell">No payment receipts yet.</div>
          <?php else: ?>

            <?php
              $paidToDate = 0.0;
              foreach ($allPays as $idx => $p):
                $thisBase = pay_to_base($p, $baseCurrency);
                $paidToDate += $thisBase;
                $balance = max(0, $origAmount - $paidToDate);

                $cusSig = $canPaySig ? (string)($p['payer_signature_image'] ?? '') : '';
                $ourSig = $canPaySig ? (string)($p['receiver_signature_image'] ?? '') : '';

                $b = null;
                $bid = (int)($p['bank_account_id'] ?? 0);
                if ($bid > 0) {
                  $stB = $pdo->prepare("SELECT id, bank_code, account_name, account_no, currency FROM company_bank_accounts WHERE id=:id LIMIT 1");
                  $stB->execute([':id'=>$bid]);
                  $b = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
                }

                $meta = receipt_meta($txn, $p, $b, $baseCurrency);
                if ($docMode === 'DO' && !empty($txn['do_date'])) {
                  $meta['docDate'] = (string)$txn['do_date'];
                }
                $amountWords = amount_to_words_rm($thisBase);
            ?>

            <div class="single-receipt-block" style="margin-bottom:14px;">
              <div class="receipt-shell">

                <table class="receipt-header">
                  <tr>
                    <td class="receipt-logo" width="25%"><img src="<?= h($logoUrl) ?>" alt="Logo"></td>
                    <td class="receipt-company-block" width="45%">
                      <div class="receipt-company-name"><?= h($companyName) ?><?= $companyRegNo !== '' ? ' (' . h($companyRegNo) . ')' : '' ?></div>
                      <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
                      <div><?= h($line) ?></div>
                      <?php endforeach; ?>
                      <?php if ($companyPhone !== ''): ?>
                      <div style="margin-top:4px;"><span class="label">Tel:</span> <?= h($companyPhone) ?></div>
                      <?php endif; ?>
                      <?php if ($companyEmail !== ''): ?>
                      <div><span class="label">Email:</span> <?= h($companyEmail) ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="receipt-meta-block" width="30%">
                      <div><span class="label">Official Receipt No :</span> <?= h($meta['receiptNo']) ?></div>
                      <div><span class="label">Date :</span> <?= h($meta['receiptDate']) ?></div>
                      <div><span class="label">Cheque No :</span> <?= h($meta['chequeNo']) ?></div>
                      <div><span class="label">Received in :</span> <?= h($meta['receivedInText']) ?></div>
                    </td>
                  </tr>
                </table>

                <div class="receipt-line"></div>

                <div class="receipt-label-row"><span class="label">Received From :</span><span><?= h($customerName) ?></span></div>
                <div class="receipt-label-row"><span class="label"><?= h($docNoLabel) ?> :</span><span><?= h($meta['docNo'] ?: '-') ?></span></div>

                <table class="receipt-table">
                  <thead>
                    <tr>
                      <th style="width:50px;">A/C</th>
                      <th>Description</th>
                      <th style="width:130px;">Payment For Account</th>
                      <th style="width:80px;">Doc No</th>
                      <th style="width:70px;">Doc Date</th>
                      <th style="width:85px;" class="text-right">Org. Amt (<?= h($meta['currencyCode']) ?>)</th>
                      <th style="width:85px;" class="text-right">Paid (<?= h($meta['currencyCode']) ?>)</th>
                      <th style="width:85px;" class="text-right">Balance (<?= h($meta['currencyCode']) ?>)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>1</td>
                      <td><?= h($customerName) ?></td>
                      <td><?= h($docLinePurpose) ?></td>
                      <td><?= h($meta['docNo'] ?: '-') ?></td>
                      <td><?= h($meta['docDate'] ?: '-') ?></td>
                      <td class="text-right"><?= fmt_money($origAmount) ?></td>
                      <td class="text-right"><?= fmt_money($thisBase) ?></td>
                      <td class="text-right"><?= fmt_money($balance) ?></td>
                    </tr>
                  </tbody>
                </table>

                <div class="receipt-amount-words"><?= h($amountWords) ?></div>

                <div class="receipt-nb">
                  <div class="receipt-nb-title">N.B.</div>
                  <div>Validity of this receipt is subject to clearing of cheque / transfer.</div>
                </div>

                <table class="receipt-sign-row">
                  <tr>
                    <?php if ($signPayer): ?>
                    <td class="receipt-sign-cell">
                      <div class="receipt-sign-block">
                        <div style="margin-bottom:4px;">DATE:</div>
                        <div style="border-bottom:1px solid #000;min-height:20px;margin-bottom:12px;"></div>
                        <div style="font-weight:bold;margin-bottom:4px;">RECEIVED BY AND COMPANY STAMP:</div>
                        <div class="receipt-sig-box">
                          <?php if ($cusSig !== ''): ?>
                            <img src="<?= h($cusSig) ?>" class="receipt-sig-main" alt="Customer Signature">
                          <?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <?php endif; ?>
                    <td class="receipt-sign-cell" style="text-align:right;">
                      <div class="receipt-sign-block" style="margin-left:auto;">
                        <div style="font-weight:bold;margin-bottom:4px;"><?= h($companyName) ?></div>
                        <div class="receipt-sig-box" style="height:80px;">
                          <?php if ($needOurSig && $ourSig !== ''): ?>
                            <img src="<?= h($ourSig) ?>" class="receipt-sig-main" alt="Our Signature">
                          <?php endif; ?>
                          <?php if ($showChop): ?>
                            <img src="<?= h($chopUrl) ?>" class="receipt-chop" alt="">
                          <?php endif; ?>
                        </div>
                        <div class="receipt-sign-line" style="margin-top:4px;border-top:1px solid #000;padding-top:2px;">
                          <?= $needOurSig ? "Company's Stamp &amp; Signature" : "Company's Stamp" ?>
                        </div>
                      </div>
                    </td>
                  </tr>
                </table>

                <?php if (($signReceive || $signPayer) && $canPaySig): ?>
                  <div class="no-print" style="margin-top:10px; text-align:right;">
                    <a class="btn btn-light btn-sm"
                      href="<?= h(url('admin/customers/txn_receipt_in.php?id='.$id.'&payment_id='.(int)$p['id'])) ?>">
                      Sign this receipt
                    </a>
                  </div>
                <?php endif; ?>

              </div>
            </div>

            <?php endforeach; ?>
          <?php endif; ?>

          <div class="no-print" style="margin-top:12px;display:flex;justify-content:space-between;">
            <a href="<?= h($back) ?>" class="btn btn-light"><?= h(t('admin.common.back', [], 'Back')) ?></a>
          </div>

        <?php else: ?>

          <?php
            $paidToDate = 0.0;
            $thisBase = 0.0;

            foreach ($allPays as $p) {
              $base = pay_to_base($p, $baseCurrency);
              $paidToDate += $base;
              if ((int)$p['id'] === $paymentId) {
                $thisBase = $base;
                break;
              }
            }
            $balance = max(0, $origAmount - $paidToDate);

            $cusSig = $canPaySig ? (string)($payment['payer_signature_image'] ?? '') : '';
            $ourSig = $canPaySig ? (string)($payment['receiver_signature_image'] ?? '') : '';

            $meta = receipt_meta($txn, $payment, $bankAccount, $baseCurrency);
            if ($docMode === 'DO' && !empty($txn['do_date'])) {
              $meta['docDate'] = (string)$txn['do_date'];
            }
            $amountWords = amount_to_words_rm($thisBase);
          ?>

          <div class="single-receipt-block">
            <div class="receipt-shell">

              <table class="receipt-header">
                <tr>
                  <td class="receipt-logo" width="25%"><img src="<?= h($logoUrl) ?>" alt="Logo"></td>
                  <td class="receipt-company-block" width="45%">
                    <div class="receipt-company-name"><?= h($companyName) ?><?= $companyRegNo !== '' ? ' (' . h($companyRegNo) . ')' : '' ?></div>
                    <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
                    <div><?= h($line) ?></div>
                    <?php endforeach; ?>
                    <?php if ($companyPhone !== ''): ?>
                    <div style="margin-top:4px;"><span class="label">Tel:</span> <?= h($companyPhone) ?></div>
                    <?php endif; ?>
                    <?php if ($companyEmail !== ''): ?>
                    <div><span class="label">Email:</span> <?= h($companyEmail) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="receipt-meta-block" width="30%">
                    <div><span class="label">Official Receipt No :</span> <?= h($meta['receiptNo']) ?></div>
                    <div><span class="label">Date :</span> <?= h($meta['receiptDate']) ?></div>
                    <div><span class="label">Cheque No :</span> <?= h($meta['chequeNo']) ?></div>
                    <div><span class="label">Received in :</span> <?= h($meta['receivedInText']) ?></div>
                  </td>
                </tr>
              </table>

              <div class="receipt-line"></div>

              <div class="receipt-label-row"><span class="label">Received From :</span><span><?= h($customerName) ?></span></div>
              <div class="receipt-label-row"><span class="label"><?= h($docNoLabel) ?> :</span><span><?= h($meta['docNo'] ?: '-') ?></span></div>

              <table class="receipt-table">
                <thead>
                  <tr>
                    <th style="width:50px;">A/C</th>
                    <th>Description</th>
                    <th style="width:130px;">Payment For Account</th>
                    <th style="width:80px;">Doc No</th>
                    <th style="width:70px;">Doc Date</th>
                    <th style="width:85px;" class="text-right">Org. Amt (<?= h($meta['currencyCode']) ?>)</th>
                    <th style="width:85px;" class="text-right">Paid (<?= h($meta['currencyCode']) ?>)</th>
                    <th style="width:85px;" class="text-right">Balance (<?= h($meta['currencyCode']) ?>)</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>1</td>
                    <td><?= h($customerName) ?></td>
                    <td><?= h($docLinePurpose) ?></td>
                    <td><?= h($meta['docNo'] ?: '-') ?></td>
                    <td><?= h($meta['docDate'] ?: '-') ?></td>
                    <td class="text-right"><?= fmt_money($origAmount) ?></td>
                    <td class="text-right"><?= fmt_money($thisBase) ?></td>
                    <td class="text-right"><?= fmt_money($balance) ?></td>
                  </tr>
                </tbody>
              </table>

              <div class="receipt-amount-words"><?= h($amountWords) ?></div>

              <div class="receipt-nb">
                <div class="receipt-nb-title">N.B.</div>
                <div>Validity of this receipt is subject to clearing of cheque / transfer.</div>
              </div>

              <table class="receipt-sign-row">
                <tr>
                  <?php if ($signPayer): ?>
                  <td class="receipt-sign-cell">
                    <div class="receipt-sign-block">
                      <div style="margin-bottom:4px;">DATE:</div>
                      <div style="border-bottom:1px solid #000;min-height:20px;margin-bottom:12px;"></div>
                      <div style="font-weight:bold;margin-bottom:4px;">RECEIVED BY AND COMPANY STAMP:</div>
                      <div class="receipt-sig-box">
                        <?php if ($cusSig !== ''): ?>
                          <img src="<?= h($cusSig) ?>" class="receipt-sig-main" alt="Customer Signature">
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <?php endif; ?>
                  <td class="receipt-sign-cell" style="text-align:right;">
                    <div class="receipt-sign-block" style="margin-left:auto;">
                      <div style="font-weight:bold;margin-bottom:4px;"><?= h($companyName) ?></div>
                      <div class="receipt-sig-box" style="height:80px;">
                        <?php if ($needOurSig && $ourSig !== ''): ?>
                          <img src="<?= h($ourSig) ?>" class="receipt-sig-main" alt="Our Signature">
                        <?php endif; ?>
                        <?php if ($showChop): ?>
                          <img src="<?= h($chopUrl) ?>" class="receipt-chop" alt="">
                        <?php endif; ?>
                      </div>
                      <div class="receipt-sign-line" style="margin-top:4px;border-top:1px solid #000;padding-top:2px;">
                        <?= $needOurSig ? "Company's Stamp &amp; Signature" : "Company's Stamp" ?>
                      </div>
                    </div>
                  </td>
                </tr>
              </table>

            </div>
          </div>

          <?php
            $needSignUI = $canPaySig && (
              ($signPayer && $cusSig === '')
              || ($needOurSig && $ourSig === '')
            );
          ?>
          <?php if ($needSignUI): ?>
            <div class="form-section no-print" style="margin-top:16px;">
              <div class="form-section-header">
                <div>
                  <div class="form-section-title">Sign here</div>
                  <div class="form-section-desc">
                    This signature is saved per receipt (payment_id: <?= (int)$paymentId ?>).
                    Status will become CONFIRMED only when payment is fully paid and required signatures are done.
                  </div>
                </div>
              </div>

              <form method="post" id="sign-form">
                <input type="hidden" name="_action" value="save_sign">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="payment_id" value="<?= (int)$paymentId ?>">
                <input type="hidden" name="receiver_signature" id="receiver_signature">
                <input type="hidden" name="payer_signature" id="payer_signature">

                <div style="display:flex;flex-wrap:wrap;gap:20px;">
                  <?php if ($signPayer): ?>
                    <div style="flex:1;min-width:260px;">
                      <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Customer signature</div>
                      <canvas id="sig-customer" class="sig-canvas"></canvas>
                      <button type="button" class="btn btn-light btn-sm" id="btn-clear-customer" style="margin-top:6px;">Clear</button>
                    </div>
                  <?php endif; ?>

                  <?php if ($needOurSig): ?>
                    <div style="flex:1;min-width:260px;">
                      <div style="font-size:12px;color:#6b7280;margin-bottom:6px;">Our signature (Vision Mix)</div>
                      <canvas id="sig-payer" class="sig-canvas"></canvas>
                      <button type="button" class="btn btn-light btn-sm" id="btn-clear-payer" style="margin-top:6px;">Clear</button>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="no-print" style="margin-top:12px;display:flex;justify-content:space-between;">
                  <a href="<?= h(url('admin/customers/txn_receipt_in.php?id='.$id)) ?>" class="btn btn-light">Back to all receipts</a>
                  <button type="submit" class="btn btn-primary">Save signatures</button>
                </div>
              </form>
            </div>
          <?php else: ?>
            <div class="no-print" style="margin-top:12px;display:flex;justify-content:space-between;">
              <a href="<?= h(url('admin/customers/txn_receipt_in.php?id='.$id)) ?>" class="btn btn-light">Back to all receipts</a>
            </div>
          <?php endif; ?>

        <?php endif; ?>

      </div><!-- /print-area -->

    </div>
  </div>
</div>

<?php if (!$showAll && $canPaySig && (($signPayer) || ($needOurSig))): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('sign-form');
  if (!form) return;
  const signRequiredMsg = 'Please sign at least one enabled side before saving.';

  function setupPad(canvasId, clearBtnId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;
    const ctx = canvas.getContext('2d');

    function resizeCanvasKeep() {
      const rect = canvas.getBoundingClientRect();

      const temp = document.createElement('canvas');
      temp.width = canvas.width;
      temp.height = canvas.height;
      temp.getContext('2d').drawImage(canvas, 0, 0);

      canvas.width = rect.width;
      canvas.height = rect.height;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(temp, 0, 0, temp.width, temp.height, 0, 0, canvas.width, canvas.height);
    }

    resizeCanvasKeep();
    window.addEventListener('resize', resizeCanvasKeep);

    let drawing=false, lastX=0, lastY=0, hasDrawn=false;

    function getPos(e) {
      const rect = canvas.getBoundingClientRect();
      if (e.touches && e.touches.length > 0) {
        return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
      }
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function startDraw(e){ e.preventDefault(); drawing=true; const p=getPos(e); lastX=p.x; lastY=p.y; }
    function draw(e){
      if(!drawing) return;
      e.preventDefault();
      const p=getPos(e);
      ctx.strokeStyle='#111827';
      ctx.lineWidth=2;
      ctx.lineCap='round';
      ctx.beginPath(); ctx.moveTo(lastX,lastY); ctx.lineTo(p.x,p.y); ctx.stroke();
      lastX=p.x; lastY=p.y; hasDrawn=true;
    }
    function endDraw(e){ if(!drawing) return; e.preventDefault(); drawing=false; }

    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);

    canvas.addEventListener('touchstart', startDraw, { passive:false });
    canvas.addEventListener('touchmove',  draw,      { passive:false });
    canvas.addEventListener('touchend',   endDraw,   { passive:false });
    canvas.addEventListener('touchcancel',endDraw,   { passive:false });

    const clearBtn = document.getElementById(clearBtnId);
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
      });
    }

    return {
      hasDrawn:()=>hasDrawn,
      getImage:()=>hasDrawn ? canvas.toDataURL('image/png') : ''
    };
  }

  const customerPad = <?= $signPayer ? 'setupPad("sig-customer","btn-clear-customer")' : 'null' ?>;
  const payerPad    = <?= $signReceive ? 'setupPad("sig-payer","btn-clear-payer")'   : 'null' ?>;

  form.addEventListener('submit', function (e) {
    let hasAny = false;

    if (customerPad && customerPad.hasDrawn()) {
      hasAny = true;
      document.getElementById('receiver_signature').value = customerPad.getImage();
    }
    if (payerPad && payerPad.hasDrawn()) {
      hasAny = true;
      document.getElementById('payer_signature').value = payerPad.getImage();
    }

    if (!hasAny) {
      e.preventDefault();
      alert(signRequiredMsg);
    }
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../include/footer.php'; ?>
