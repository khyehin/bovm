<?php
// public/user/txn/txn_invoice_in.php
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
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function fmt_money(float $v): string
{
  return number_format($v, 2, '.', ',');
}

/**
 * ✅ back URL 修复版：
 * - https?:// 原样返回
 * - javascript: 拦掉
 * - /xxx 这种站内绝对路径：直接返回（不要再 url()，避免重复 base）
 * - xxx 相对路径：会自动剥掉重复 base（例如 bovm/public/），再 url()
 */
function safe_back_url(string $back): string
{
  $back = trim($back);
  if ($back === '') return '';

  if (preg_match('#^https?://#i', $back)) return $back;
  if (preg_match('#^\s*javascript:#i', $back)) return '';

  // 已经是站内绝对路径：直接用（避免 url() 再加一次 base）
  if ($back[0] === '/') return $back;

  // ---- 自动剥掉重复 base，例如 "bovm/public/..." ----
  $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
  // /bovm/public/user/txn/xxx.php -> dirname(3) = /bovm/public
  $basePath = rtrim(dirname($script, 3), '/'); // like "/bovm/public"
  $baseRel  = ltrim($basePath, '/');          // like "bovm/public"

  $back = ltrim($back, '/');

  if ($baseRel !== '') {
    $prefix = $baseRel . '/';
    while (strpos($back, $prefix) === 0) {
      $back = substr($back, strlen($prefix));
      $back = ltrim($back, '/');
    }
  }

  return url($back);
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

/** payment 换算到 base currency（txn.currency） */
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
 * ✅ build Received in (IN): bank 优先；否则显示 RECEIVED FROM: xxx
 * (schema-safe)
 */
function build_received_in(PDO $pdo, ?array $payment, ?array $bankAccount, array $payCols): array
{
  $chequeNo = 'CASH';
  $receivedInText = 'CASH';

  if (!$payment) return [$chequeNo, $receivedInText];

  // 1) bank 优先
  if ($bankAccount) {
    $code  = strtoupper(trim((string)($bankAccount['bank_code'] ?? '')));
    $accNo = trim((string)($bankAccount['account_no'] ?? ''));
    $accNm = trim((string)($bankAccount['account_name'] ?? ''));

    $parts = [];
    if ($code !== '')  $parts[] = $code;
    if ($accNo !== '') $parts[] = $accNo;
    if ($accNm !== '') $parts[] = $accNm;

    $receivedInText = $parts ? implode(' - ', $parts) : 'BANK';
    $chequeNo = ($code !== '' && $code !== 'CASH') ? 'ONLINE TRANSFER' : 'CASH';
    return [$chequeNo, $receivedInText];
  }

  // 2) 非 bank：从 payment 字段找 “从谁收到”
  $fromText = '';
  $tryTextCols = [
    'received_from_text', 'received_from', 'receive_from',
    'payer_name', 'payer_company', 'payer_company_name',
    'from_name', 'from_company', 'from_company_name',
    'pay_from_text', 'pay_from', 'remark_from'
  ];
  foreach ($tryTextCols as $c) {
    if (isset($payCols[$c])) {
      $v = trim((string)($payment[$c] ?? ''));
      if ($v !== '') { $fromText = $v; break; }
    }
  }

  // 3) stored customer_id（存在才会用）
  if ($fromText === '') {
    $tryIdCols = ['payer_customer_id', 'from_customer_id', 'received_from_customer_id'];
    foreach ($tryIdCols as $c) {
      if (isset($payCols[$c])) {
        $pid = (int)($payment[$c] ?? 0);
        if ($pid > 0) {
          $st = $pdo->prepare("SELECT name FROM customers WHERE id=:id LIMIT 1");
          $st->execute([':id' => $pid]);
          $nm = (string)($st->fetchColumn() ?: '');
          if ($nm !== '') { $fromText = $nm; break; }
        }
      }
    }
  }

  if ($fromText !== '') {
    $receivedInText = 'RECEIVED FROM: ' . $fromText;
    $chequeNo = 'INTERNAL / THIRD PARTY';
    return [$chequeNo, $receivedInText];
  }

  // 4) fallback：method/channel/type（存在才会用）
  $method = '';
  $tryMethodCols = ['method', 'pay_method', 'payment_method', 'channel', 'type'];
  foreach ($tryMethodCols as $c) {
    if (isset($payCols[$c])) {
      $method = strtoupper(trim((string)($payment[$c] ?? '')));
      if ($method !== '') break;
    }
  }
  if ($method !== '') {
    $receivedInText = $method;
    $chequeNo = ($method === 'BANK' || $method === 'TRANSFER') ? 'ONLINE TRANSFER' : 'CASH';
  }

  return [$chequeNo, $receivedInText];
}

/** helper: build receipt meta（跟 admin 结构一样） */
function receipt_meta(PDO $pdo, array $txn, array $p, ?array $bankAccount, array $payCols, string $baseCurrency): array
{
  $receiptNo = (string)($p['or_no'] ?? '');
  if ($receiptNo === '') $receiptNo = 'R' . (int)$p['id'];

  $receiptDate = (string)($p['pay_date'] ?? '');
  if ($receiptDate === '') $receiptDate = (string)($txn['txn_date'] ?? substr((string)$txn['created_at'], 0, 10));

  $docNo = (string)($txn['invoice_no'] ?? '');
  $docDate = (string)($txn['txn_date'] ?? '');
  if ($docDate === '') $docDate = $receiptDate;

  [$chequeNo, $receivedInText] = build_received_in($pdo, $p, $bankAccount, $payCols);

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

/* ---------------- params ---------------- */
$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) { http_response_code(400); exit('Missing customer_id'); }

$id        = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing transaction id'); }

/**
 * ✅ rawBack：
 * - 优先使用 GET/POST 的 back
 * - 若没有，就用当前页的 REQUEST_URI（保证永远是站内路径，不会变成重复 base）
 */
$rawBack = (string)($_GET['back'] ?? $_POST['back'] ?? '');
if ($rawBack === '') {
  $rawBack = (string)($_SERVER['REQUEST_URI'] ?? '');
}

$backUrl = safe_back_url($rawBack);
if ($backUrl === '') $backUrl = url('user/txn/txn_view.php?id=' . $id);

/* ---------------- Load txn + customer（限制在当前 customer 底下） ---------------- */
$sql = "
  SELECT
    t.*,
    c.name   AS customer_name,
    c.reg_no AS customer_reg_no
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
    AND t.customer_id = :cid
  LIMIT 1
";
$st = $pdo->prepare($sql);
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();

if (!$txn) { http_response_code(404); exit('Transaction not found'); }
if ((string)$txn['txn_type'] !== 'IN') { http_response_code(400); exit('This page is only for IN transactions.'); }

$customerName  = (string)($txn['customer_name'] ?? '');
$customerRegNo = (string)($txn['customer_reg_no'] ?? '');

$baseCurrency = (string)($txn['currency'] ?: 'MYR');
$origAmount   = (float)($txn['order_total'] ?? 0);
$invoiceNo    = (string)($txn['invoice_no'] ?? '');

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
$signReceive = !empty($txn['sign_receive']); // 我们签（客户页一般只是显示）
$signPayer   = !empty($txn['sign_payer']);   // 客户签（客户页要签的是这个）

$payCols   = table_columns($pdo, 'customer_txn_payments');
$canPaySig = isset($payCols['receiver_signature_image']) && isset($payCols['payer_signature_image']);

// logo / chop
$logoUrl = url('admin/assets/img/vmlogo.png');
$chopUrl = url('admin/assets/img/vmchop.png');
$company = function_exists('get_company') ? get_company() : ['name' => 'VISION MIX SDN BHD', 'reg_no' => '1622729-U', 'address' => ['LOT 3A-02A, 4TH FLOOR ENDAH PARADE,', 'NO.1 JALAN 1/149E, BANDAR BARU SRI PETALING,', '57000 KUALA LUMPUR'], 'phone' => '', 'email' => ''];
$companyName = (string)($company['name'] ?? '');
$companyRegNo = (string)($company['reg_no'] ?? '');
$companyAddress = (array)($company['address'] ?? []);
$companyPhone = (string)($company['phone'] ?? '');
$companyEmail = (string)($company['email'] ?? '');

// ---- all payments (all receipts) ----
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
    if ((int)$p['id'] === $paymentId) { $payment = $p; break; }
  }
}

// user 版本：永远显示「单张 receipt」，如果没有指定 payment_id，就默认用最后一张（最新一笔）
if (!$payment) {
  if ($allPays) {
    $payment = $allPays[count($allPays) - 1];
    $paymentId = (int)($payment['id'] ?? 0);
  } else {
    http_response_code(404);
    exit('No payments for this invoice.');
  }
}

$showAll = false;

// ======== POST: save signature (customer only, single receipt mode) ========
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['_action'] ?? '') === 'save_sign')) {
  $pid = (int)($_POST['payment_id'] ?? 0);
  $rawBack = (string)($_POST['back'] ?? $rawBack); // 保留 back

  if ($pid <= 0) {
    $errors['signature'] = 'Missing payment_id.';
  } elseif (!$canPaySig) {
    $errors['signature'] = 'Payment signature columns not found.';
  } elseif (!$signPayer) {
    $errors['signature'] = 'Signature not required.';
  } else {
    $recvPosted  = (string)($_POST['receiver_signature'] ?? ''); // customer canvas
    $hasNewRecv  = (strpos($recvPosted, 'data:image/png;base64,') === 0);

    if (!$hasNewRecv) {
      $errors['signature'] = t('cust.txn.sign.error_sign_box', [], 'Please sign inside the signature box before saving.');
    } else {
      // prevent overwrite
      $st = $pdo->prepare("SELECT payer_signature_image FROM customer_txn_payments WHERE id=:pid AND customer_txn_id=:tid LIMIT 1");
      $st->execute([':pid'=>$pid, ':tid'=>$id]);
      $existing = (string)($st->fetchColumn() ?: '');
      if ($existing !== '') {
        $errors['signature'] = 'Signature already exists.';
      } else {
        $set = ["payer_signature_image = :cus_sig", "updated_at = NOW()"];
        $params = [':cus_sig'=>$recvPosted, ':pid'=>$pid, ':tid'=>$id];
        if (isset($payCols['payer_signed_at'])) $set[] = "payer_signed_at = IFNULL(payer_signed_at, NOW())";

        $sqlUp = "UPDATE customer_txn_payments SET " . implode(', ', $set) . " WHERE id=:pid AND customer_txn_id=:tid LIMIT 1";
        $pdo->prepare($sqlUp)->execute($params);

        // ✅ 回到同一张 receipt（保留 back）—— 修复版
        $go = url('user/txn/txn_invoice_in.php?id='.$id.'&payment_id='.$pid.'&back='.rawurlencode($rawBack));
        header('Location: ' . $go);
        exit;
      }
    }
  }
}

// 页面标题
$page_title = 'Invoice #' . ($invoiceNo ?: ('TXN-' . (int)$txn['id']));
$active_nav = 'txns';

include __DIR__ . '/../include/header.php';
?>
<style>
@media print {
  @page { margin: 10mm; }
  html, body { margin:0!important; padding:0!important; background:#fff!important; }
  body * { visibility:hidden!important; }
  #receipt-print-area, #receipt-print-area * { visibility:visible!important; }
  #receipt-print-area {
    position: static!important;
    width: 100%!important;
    margin: 0!important;
    padding: 0!important;
  }

  .admin-main,
  .admin-main-inner,
  .admin-card {
    margin:0!important;
    padding:0!important;
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

/* 屏幕：一页一张分页 + 收据框样式（和 admin 一样） */
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
          <div class="form-page-eyebrow">Official Receipt</div>
          <h2 class="form-page-title"><?= h($customerName) ?></h2>
          <div class="form-page-subtitle">
            Txn #<?= (int)$txn['id'] ?>
            <?php if ($invoiceNo !== ''): ?> · Invoice No: <?= h($invoiceNo) ?><?php endif; ?>
            · <span style="color:#2563eb;">Receipt: <?= h((string)($payment['or_no'] ?? ('R'.$paymentId))) ?></span>
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
            <a href="<?= h($backUrl) ?>" class="btn btn-light btn-sm">Back</a>
            <button type="button" class="btn btn-light btn-sm" onclick="window.print();">Print</button>
          </div>
        </div>
      </div>

      <?php if (!empty($errors['signature'])): ?>
        <div class="alert-error no-print" style="margin-bottom:10px;"><?= h($errors['signature']) ?></div>
      <?php endif; ?>

      <?php if ($receiptCount > 1): ?>
        <div class="no-print" style="margin-top:8px;font-size:12px;">
          <strong>All receipts:</strong>
          <ul style="margin:6px 0 0 18px; padding:0; list-style:disc;">
            <?php foreach ($allPays as $p): ?>
              <?php
                $rid   = (int)($p['id'] ?? 0);
                $orNo  = (string)($p['or_no'] ?? ('R'.$rid));
                $rdate = (string)($p['pay_date'] ?? '');
                $link  = url('user/txn/txn_invoice_in.php?id='.$id.'&payment_id='.$rid.'&back='.rawurlencode($backUrl));
              ?>
              <li>
                <a href="<?= h($link) ?>" style="text-decoration:none;">
                  <?= h($orNo) ?><?php if ($rdate !== ''): ?> — <?= h($rdate) ?><?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div id="receipt-print-area" style="margin-top:10px;">

        <?php
            // 单张：算到该 payment 为止的 balance
            $paidToDate = 0.0;
            $thisBase = 0.0;
            foreach ($allPays as $p) {
              $base = pay_to_base($p, $baseCurrency);
              $paidToDate += $base;
              if ((int)$p['id'] === $paymentId) { $thisBase = $base; break; }
            }
            $balance = max(0, $origAmount - $paidToDate);

            // bank info
            $bankAccount = null;
            $bid = (int)($payment['bank_account_id'] ?? 0);
            if ($bid > 0) {
              $stB = $pdo->prepare("SELECT id, bank_code, account_name, account_no, currency FROM company_bank_accounts WHERE id=:id LIMIT 1");
              $stB->execute([':id'=>$bid]);
              $bankAccount = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            $meta = receipt_meta($pdo, $txn, $payment, $bankAccount, $payCols, $baseCurrency);
            $amountWords = amount_to_words_rm($thisBase);

            $cusSig = $canPaySig ? (string)($payment['payer_signature_image'] ?? '') : '';
            $ourSig = $canPaySig ? (string)($payment['receiver_signature_image'] ?? '') : '';

            $hasCustomerSig = ($cusSig !== '');
            $canCustomerSign = ($signPayer && $canPaySig && !$hasCustomerSig);
            // 只要任一侧需要签，或者 sign_mode=CHOP_ONLY（只盖章），就显示签名/盖章区域
            $needAnySign = ($signPayer || $signReceive || $signMode === 'CHOP_ONLY');
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
              <div class="receipt-label-row"><span class="label">Invoice No :</span><span><?= h($meta['docNo'] ?: '-') ?></span></div>

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
                    <td>Payment For Account</td>
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

              <?php if ($needAnySign): ?>
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
                          <?php if ($signReceive && $ourSig !== ''): ?>
                            <img src="<?= h($ourSig) ?>" class="receipt-sig-main" alt="Our Signature">
                          <?php endif; ?>
                          <?php if ($chopUrl): ?>
                            <img src="<?= h($chopUrl) ?>" class="receipt-chop" alt="Company Chop">
                          <?php endif; ?>
                        </div>
                        <div class="receipt-sign-line" style="margin-top:4px;border-top:1px solid #000;padding-top:2px;">
                          <?= $signReceive ? "Company's Stamp &amp; Signature" : "Company's Stamp" ?>
                        </div>
                      </div>
                    </td>
                  </tr>
                </table>
              <?php endif; ?>

            </div>
          </div>

          <?php if ($canCustomerSign): ?>
            <div class="form-section no-print" style="margin-top:16px;">
              <div class="form-section-header">
                <div>
                  <div class="form-section-title"><?= h(t('cust.txn.sign.sign_here_title', [], 'Sign here')) ?></div>
                  <div class="form-section-desc"><?= h(t('cust.txn.sign.sign_here_desc', [], 'Please sign inside the box below.')) ?></div>
                </div>
              </div>

              <form method="post" id="sign-form">
                <input type="hidden" name="_action" value="save_sign">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <input type="hidden" name="payment_id" value="<?= (int)$paymentId ?>">
                <input type="hidden" name="back" value="<?= h($rawBack) ?>">
                <input type="hidden" name="receiver_signature" id="receiver_signature">

                <div style="flex:1;min-width:260px;">
                  <canvas id="sig-customer" class="sig-canvas"></canvas>
                  <button type="button" class="btn btn-light btn-sm" id="btn-clear-customer" style="margin-top:6px;">
                    <?= h(t('cust.common.btn.clear', [], 'Clear')) ?>
                  </button>
                </div>

            <div class="no-print" style="margin-top:12px;display:flex;justify-content:flex-end;">
              <button type="submit" class="btn btn-primary"><?= h(t('cust.txn.sign.btn_save', [], 'Save signature')) ?></button>
            </div>
              </form>
            </div>
          <?php else: ?>
        <!-- no extra actions when signature not required -->
          <?php endif; ?>

      </div><!-- /print-area -->

    </div>
  </div>
</div>

<?php if (isset($canCustomerSign) && $canCustomerSign): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('sign-form');
  if (!form) return;

  const signRequiredMsg = <?= json_encode(t('cust.txn.sign.error_sign_box', [], 'Please sign inside the signature box before saving.')) ?>;

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

      canvas.width  = rect.width;
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

  const customerPad = setupPad("sig-customer","btn-clear-customer");

  form.addEventListener('submit', function (e) {
    if (!customerPad || !customerPad.hasDrawn()) {
      e.preventDefault();
      alert(signRequiredMsg);
      return;
    }
    document.getElementById('receiver_signature').value = customerPad.getImage();
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../include/footer.php'; ?>
