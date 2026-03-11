<?php
// public/user/txn/txn_receipt.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_login();
require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../app/txn_sign_status.php';

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

/* ===== amount to words (RM) ===== */
function amount_to_words_rm(float $amount): string
{
  $units = [
    0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
    10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
  ];
  $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];
  $scales = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 100 => 'Hundred'];

  $amount = round($amount, 2);
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

/* ===== upload href (same spirit as admin) ===== */
function upload_href(?string $fp): string
{
  $fp = trim((string)$fp);
  if ($fp === '') return '';
  $fp = ltrim($fp, '/');
  if (strpos($fp, 'public/uploads/') === 0) $fp = substr($fp, strlen('public/'));
  if (strpos($fp, 'uploads/uploads/') === 0) $fp = substr($fp, strlen('uploads/'));
  if (strpos($fp, 'uploads/') !== 0) $fp = 'uploads/' . $fp;
  return '../' . $fp;
}

function bank_label_view(array $b): string
{
  $parts = [];
  if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
  if (!empty($b['account_name'])) $parts[] = $b['account_name'];
  if (!empty($b['account_no']))   $parts[] = $b['account_no'];
  $label = implode(' · ', $parts);
  if (!empty($b['currency'])) $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
  return $label ?: ('Account #' . ($b['id'] ?? ''));
}

/* ===========================================================
   ✅ Back URL（哪里来回哪里去）
   =========================================================== */
$back = (string)($_GET['back'] ?? '');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');

$normalizeInternal = function (string $b) use ($host): string {
  $b = trim($b);
  if ($b === '') return '';
  if (preg_match('#^https?://#i', $b)) {
    $bHost = (string)(parse_url($b, PHP_URL_HOST) ?? '');
    if ($bHost !== '' && $bHost !== $host) return '';
    return $b;
  }
  if (strpos($b, '/') === 0) return $b;
  return url($b);
};

$back = $normalizeInternal($back);

if ($back === '') {
  $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
  $ref = $normalizeInternal($ref);
  if ($ref !== '') {
    $back = $ref;
  } else {
    $backKeys = ['date_from', 'date_to', 'type', 'status', 'method', 'q'];
    $q = [];
    foreach ($backKeys as $k) {
      if (isset($_GET[$k]) && $_GET[$k] !== '') $q[$k] = (string)$_GET[$k];
    }
    $back = url('user/txn/txns.php' . ($q ? ('?' . http_build_query($q)) : ''));
  }
}

/* ===== current customer ===== */
$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

$currencyDefault = $customer['currency'] ?? 'MYR';

/* ===== id optional: no id => show list ===== */
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  $rows = [];
  try {
    $st = $pdo->prepare("
      SELECT id, txn_type, in_kind, title, status, txn_date, created_at, currency, amount, order_total
      FROM customer_txn
      WHERE customer_id = :cid
      ORDER BY COALESCE(txn_date, DATE(created_at)) DESC, id DESC
      LIMIT 5000
    ");
    $st->execute([':cid' => $cid]);
    $rows = $st->fetchAll();
  } catch (Throwable $e) {
    $rows = [];
  }

  $page_title = '';
  $active_nav = 'txns';
  include __DIR__ . '/../include/header.php';
?>
  <div class="admin-card admin-card-elevated admin-card-narrow">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div style="font-size:16px;font-weight:700;"><?= h(t('admin.customer_txn.view.page_title', [], 'Receipt / Confirmation')) ?></div>
      <a href="<?= h($back) ?>" class="btn btn-light btn-sm"><?= h(t('admin.common.back', [], 'Back')) ?></a>
    </div>

    <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden; background:#fff;">
      <table style="width:100%; border-collapse:collapse;">
        <thead>
          <tr style="background:#f3f4f6;">
            <th style="padding:10px; text-align:left; font-size:12px;"><?= h(t('admin.customer_txn.field.date', [], 'Date')) ?></th>
            <th style="padding:10px; text-align:left; font-size:12px;"><?= h(t('admin.customer_txn.view.txn_label', [], 'Transaction')) ?></th>
            <th style="padding:10px; text-align:left; font-size:12px;"><?= h(t('admin.customer_txn.field.title', [], 'Title')) ?></th>
            <th style="padding:10px; text-align:right; font-size:12px;"><?= h(t('admin.customer_txn.field.amount', [], 'Amount')) ?></th>
            <th style="padding:10px; text-align:left; font-size:12px;"><?= h(t('admin.customer_txn.field.status', [], 'Status')) ?></th>
            <th style="padding:10px; text-align:right; font-size:12px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="6" style="padding:12px;color:#6b7280;"><?= h(t('admin.customer_txn.view.no_items', [], 'No receipt items to display.')) ?></td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
              $d = (string)($r['txn_date'] ?: substr((string)$r['created_at'], 0, 10));
              $isInRow = ((string)$r['txn_type'] === 'IN');
              $cur = (string)($r['currency'] ?: $currencyDefault);
              $amt = $isInRow ? (float)($r['order_total'] ?? 0) : (float)($r['amount'] ?? 0);
              $viewUrl = url('user/txn/txn_receipt.php?id=' . (int)$r['id'] . '&back=' . rawurlencode($back));
              ?>
              <tr style="border-top:1px solid var(--border);">
                <td style="padding:10px;font-size:12px;"><?= h($d) ?></td>
                <td style="padding:10px;font-size:12px;">#<?= (int)$r['id'] ?> · <?= h((string)$r['txn_type']) ?></td>
                <td style="padding:10px;font-size:12px;"><?= h((string)($r['title'] ?? '-') ?: '-') ?></td>
                <td style="padding:10px;font-size:12px;text-align:right;"><?= h($cur) . ' ' . h(number_format($amt, 2)) ?></td>
                <td style="padding:10px;font-size:12px;"><?= h(strtoupper((string)$r['status'])) ?></td>
                <td style="padding:10px;text-align:right;">
                  <a class="btn btn-primary btn-sm" href="<?= h($viewUrl) ?>"><?= h(t('admin.common.view', [], 'View')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php
  include __DIR__ . '/../include/footer.php';
  exit;
}

/* ===== txn ===== */
$payment_id = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);

$st = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id AND customer_id = :cid LIMIT 1");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();
if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}

$isIn     = (($txn['txn_type'] ?? '') === 'IN');
$isOut    = (($txn['txn_type'] ?? '') === 'OUT');
$isContra = (int)($txn['is_contra'] ?? 0) === 1;

$inKind = strtoupper(trim((string)($txn['in_kind'] ?? 'INVOICE')));
if (!in_array($inKind, ['INVOICE', 'RETURN', 'BONUS'], true)) $inKind = 'INVOICE';

$isInInvoice    = ($isIn && $inKind === 'INVOICE');
$isInNonInvoice = ($isIn && !$isInInvoice);

$status   = strtoupper((string)($txn['status'] ?? 'DRAFT'));
$txn_date = (string)($txn['txn_date'] ?: substr((string)($txn['created_at'] ?? ''), 0, 10));

/* ===== attachments ===== */
$attachments = [];
try {
  $st = $pdo->prepare("
    SELECT id, file_path, file_name, file_mime, created_at
      FROM customer_txn_files
     WHERE txn_id = :tid
  ORDER BY id ASC
  ");
  $st->execute([':tid' => (int)$txn['id']]);
  $attachments = $st->fetchAll();
} catch (Throwable $e) {
  $attachments = [];
}

$attachmentPath = (string)($txn['attachment_path'] ?? '');
$attachmentName = (string)($txn['attachment_name'] ?? '');
if ($attachmentName === '' && $attachmentPath !== '') $attachmentName = basename($attachmentPath);
$attachmentUrl  = '';
if ($attachmentPath !== '') {
  $href = upload_href($attachmentPath);
  if ($href !== '') $attachmentUrl = url($href);
}

/* ===== IN payments ===== */
$paymentLines = [];
$paymentAttachments = [];
$txnAttachmentsNotes = [];
$bankMap = [];
$currentPayment = null;

if ($isIn) {
  $st = $pdo->prepare("
    SELECT *
      FROM customer_txn_payments
     WHERE customer_txn_id = :tid
  ORDER BY payment_seq ASC, id ASC
  ");
  $st->execute([':tid' => (int)$txn['id']]);
  $paymentLines = $st->fetchAll();

  try {
    $bankRows = $pdo->query("
      SELECT id, bank_code, account_name, account_no, currency
        FROM company_bank_accounts
       WHERE is_active = 1
    ORDER BY bank_code, account_name, account_no, id
    ")->fetchAll();
    foreach ($bankRows as $b) $bankMap[(int)$b['id']] = bank_label_view($b);
  } catch (Throwable $e) {
    $bankMap = [];
  }

  // ✅ 有 id 就只显示一张：payment_id 优先，否则取最后一笔
  if ($isInNonInvoice && $paymentLines) {
    if ($payment_id > 0) {
      foreach ($paymentLines as $pl) {
        if ((int)$pl['id'] === $payment_id) { $currentPayment = $pl; break; }
      }
    }
    if (!$currentPayment) {
      $currentPayment = $paymentLines[count($paymentLines) - 1];
      $payment_id = (int)($currentPayment['id'] ?? 0);
    }
  }

  // attachments for payments
  if ($paymentLines) {
    $payIds = [];
    foreach ($paymentLines as $pl) {
      $pid = (int)($pl['id'] ?? 0);
      if ($pid > 0) $payIds[] = $pid;
    }
    $payIds = array_values(array_unique($payIds));
    if ($payIds) {
      $in = implode(',', array_fill(0, count($payIds), '?'));
      try {
        $stA = $pdo->prepare("
          SELECT *
            FROM customer_txn_payment_attachments
           WHERE payment_id IN ($in)
        ORDER BY id ASC
        ");
        $stA->execute($payIds);
        foreach ($stA->fetchAll() as $ra) {
          $pid = (int)($ra['payment_id'] ?? 0);
          if ($pid > 0) $paymentAttachments[$pid][] = $ra;
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
    $stT->execute([':tid' => (int)$txn['id']]);
    $txnAttachmentsNotes = $stT->fetchAll();
  } catch (Throwable $e) {
    $txnAttachmentsNotes = [];
  }
}

/* ===== payer company/staff ===== */
$payerCompany = null;
$payerStaff   = null;

if (!empty($txn['payer_company_id'])) {
  $st = $pdo->prepare("SELECT id, name, reg_no FROM payer_companies WHERE id = :id");
  $st->execute([':id' => (int)$txn['payer_company_id']]);
  $payerCompany = $st->fetch();
}
if (!empty($txn['payer_staff_id'])) {
  $st = $pdo->prepare("SELECT id, staff_name, ic_no FROM payer_company_staff WHERE id = :id");
  $st->execute([':id' => (int)$txn['payer_staff_id']]);
  $payerStaff = $st->fetch();
}

$company_name     = $payerCompany['name']     ?? 'Vision Mix Sdn. Bhd.';
$company_reg_no   = $payerCompany['reg_no']   ?? '1622729-U';
$company_rep_name = $payerStaff['staff_name'] ?? 'Chong Ngan Xiong';
$company_rep_nric = $payerStaff['ic_no']      ?? '830204-10-5115';

$vmChopUrl = url('admin/assets/img/vmchop.png');

/* ===== signature logic ===== */
$needSignature = (
  (($isOut) || $isInNonInvoice)
  && (int)($txn['require_signature'] ?? 0) === 1
  && !$isContra
);

/* customer representative */
$rec_name = (string)($txn['recipient_name'] ?: ($customer['default_receipt_name'] ?? $customer['contact_name'] ?? ''));
$rec_nric = (string)($txn['recipient_nric'] ?: ($customer['default_receipt_nric'] ?? ''));

/* signature title keys */
if ($isIn) {
  $leftSigKey  = 'admin.customer_txn.view.sig_customer_title_in';
  $rightSigKey = 'admin.customer_txn.view.sig_payer_title_in';
} else {
  $leftSigKey  = 'admin.customer_txn.view.sig_customer_title_out';
  $rightSigKey = 'admin.customer_txn.view.sig_payer_title_out';
}

$all = (int)($_GET['all'] ?? $_POST['all'] ?? 0);
$isPrintAll = ($all === 1);

/* ✅ receipt items */
$receiptItems = [null];
if ($isInNonInvoice) {
  if ($all === 1) {
    $receiptItems = $paymentLines ?: [];
  } elseif ($payment_id > 0 && $currentPayment) {
    $receiptItems = [$currentPayment];
  } elseif ($paymentLines) {
    $receiptItems = [$paymentLines[count($paymentLines) - 1]];
  } else {
    $receiptItems = [];
  }
}

/* ===== signed? customer side ===== */
$customerSigned = false;
if ($isInNonInvoice && $payment_id > 0 && $currentPayment) {
  $customerSigned = !empty($currentPayment['payer_signature_image'] ?? '');
} else {
  $customerSigned = $isIn ? !empty($txn['payer_signature_image'] ?? '') : !empty($txn['signature_image'] ?? '');
}

$errors = [];
$canSignHere = ($needSignature && !$customerSigned && $status !== 'CONFIRMED');

/* ===== POST: save customer signature ONLY ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sign']) && $needSignature) {

  $payment_id = (int)($_POST['payment_id'] ?? $payment_id);
  $custSig = $_POST['customer_signature'] ?? '';

  if (strpos((string)$custSig, 'data:image/png;base64,') !== 0) {
    $errors['customer_signature'] = t('admin.customer_txn.view.error.sign_required', [], 'Please sign before saving.');
  }

  if (!$errors) {
    if ($isInNonInvoice && $payment_id > 0) {
      $stU = $pdo->prepare("
        UPDATE customer_txn_payments
           SET payer_signature_image = :sig,
               updated_at = NOW()
         WHERE id = :pid AND customer_txn_id = :tid
      ");
      $stU->execute([
        ':sig' => $custSig,
        ':pid' => $payment_id,
        ':tid' => (int)$txn['id'],
      ]);

      if (function_exists('txn_mark_signed_and_maybe_confirm')) {
        txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'payer');
      }
    } else {
      if ($isIn) {
        $stU = $pdo->prepare("
          UPDATE customer_txn
             SET payer_signature_image = :sig,
                 updated_at = NOW()
           WHERE id = :id AND customer_id = :cid
        ");
        $stU->execute([':sig' => $custSig, ':id' => $id, ':cid' => $cid]);

        if (function_exists('txn_mark_signed_and_maybe_confirm')) {
          txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'payer');
        }
      } else {
        $stU = $pdo->prepare("
          UPDATE customer_txn
             SET signature_image = :sig,
                 updated_at = NOW()
           WHERE id = :id AND customer_id = :cid
        ");
        $stU->execute([':sig' => $custSig, ':id' => $id, ':cid' => $cid]);

        if (function_exists('txn_mark_signed_and_maybe_confirm')) {
          txn_mark_signed_and_maybe_confirm($pdo, (int)$txn['id'], 'recipient');
        }
      }
    }

    header('Location: ' . $back);
    exit;
  }
}

/* ===== header display vars ===== */
$mainCur = $txn['currency'] ?: $currencyDefault;
$displayAmount = 0.0;

if ($isInNonInvoice && $payment_id > 0 && $currentPayment) {
  $mainCur = $currentPayment['currency'] ?: ($txn['currency'] ?: $currencyDefault);
  $displayAmount = (float)($currentPayment['amount'] ?? 0);
} else {
  $displayAmount = $isInNonInvoice ? (float)($txn['order_total'] ?? 0) : (float)($txn['amount'] ?? 0);
}
$amountStrHeader = $mainCur . ' ' . number_format($displayAmount, 2);

/* payer/receiver blocks */
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

/* none message keys */
$leftNoneKey  = $isIn ? 'admin.customer_txn.view.sig_payer_none'    : 'admin.customer_txn.view.sig_customer_none';
$rightNoneKey = $isIn ? 'admin.customer_txn.view.sig_customer_none' : 'admin.customer_txn.view.sig_payer_none';

/* name lines (same mapping idea as admin) */
$leftName  = $isIn ? $company_rep_name : ($rec_name ?: '-');
$leftNric  = $isIn ? $company_rep_nric : $rec_nric;
$rightName = $isIn ? ($rec_name ?: '-') : $company_rep_name;
$rightNric = $isIn ? $rec_nric : $company_rep_nric;

$page_title = '';
$active_nav = 'txns';

include __DIR__ . '/../include/header.php';
?>

<style>
/* ===== SIGNATURE LAYOUT (same row, same height) ===== */
.sig-row{
  display:flex;
  gap:24px;
  align-items:stretch;
  flex-wrap:nowrap; /* ✅ 不换行 */
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
  position:relative; /* ✅ 给 chop absolute 用 */
}
.sig-image-box img.sig-img{
  max-width:100%;
  max-height:170px;
  object-fit:contain;
  display:block;
}
.sig-placeholder{
  font-size:12px;
  color:#9ca3af;
}
.sig-meta{ margin-top:auto; }
.sig-meta-line{
  font-size:11px;
  color:#4b5563;
  line-height:1.35;
}

/* ===== CHOP (SAME AS ADMIN) ===== */
.sig-chop{
  position:absolute;
  right:10px;
  bottom:6px;
  max-height:80px;
  opacity:0.9;
  pointer-events:none;
}
</style>

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

    <div class="form-page-meta" style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
      <span class="badge-soft"><?= h(strtoupper((string)$txn['status'])) ?></span>

      <?php if (!$isInInvoice): ?>
        <div style="display:flex;gap:8px;">
          <a href="<?= h($back) ?>" class="btn btn-light btn-sm"><?= h(t('admin.common.back', [], 'Back')) ?></a>
          <button type="button" class="btn btn-light btn-sm" onclick="printReceipt();">
            <?= h(t('admin.customer_txn.view.print_btn', [], 'Print / PDF')) ?>
          </button>
        </div>
      <?php else: ?>
        <a href="<?= h($back) ?>" class="btn btn-light btn-sm"><?= h(t('admin.common.back', [], 'Back')) ?></a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($errors['customer_signature'])): ?>
    <div class="alert-error" style="margin-bottom:14px;"><?= h($errors['customer_signature']) ?></div>
  <?php endif; ?>

  <?php if ($isInInvoice): ?>
    <div class="alert-info">
      <?= nl2br(h(t('admin.customer_txn.view.invoice_info', [], "This view is mainly for OUT / RETURN / BONUS receipts.\nFor INVOICE type, please continue to use your existing invoice print page."))) ?>
    </div>
  <?php else: ?>

    <div id="receipt-print-area" style="border:1px solid var(--border); border-radius:14px; padding:24px 28px; background:#ffffff; margin-bottom:22px;">

      <?php if (empty($receiptItems)): ?>
        <p style="font-size:13px;color:#6b7280;"><?= h(t('admin.customer_txn.view.no_items', [], 'No receipt items to display.')) ?></p>
      <?php else: ?>
        <?php foreach ($receiptItems as $idx => $onePayment): ?>
          <?php
          // ===== per receipt (handle payment)
          if ($isInNonInvoice && is_array($onePayment)) {
            $curLocal    = (string)($onePayment['currency'] ?: ($txn['currency'] ?: $currencyDefault));
            $amountLocal = (float)($onePayment['amount'] ?? 0.0);

            // IN non-invoice: left = company(receiver) signature, right = customer(payer) signature
            $sigLeft  = (string)($onePayment['receiver_signature_image'] ?? '');
            $sigRight = (string)($onePayment['payer_signature_image'] ?? '');
          } else {
            $curLocal    = (string)($txn['currency'] ?: $currencyDefault);
            $amountLocal = $isInNonInvoice ? (float)($txn['order_total'] ?? 0.0) : (float)($txn['amount'] ?? 0.0);

            // OUT (or single txn): left = customer (recipient), right = company (payer)
            $sigLeft  = (string)($txn['signature_image'] ?? '');
            $sigRight = (string)($txn['payer_signature_image'] ?? '');
          }

          $amountStrLocal  = $curLocal . ' ' . number_format($amountLocal, 2);
          $amountWordLocal = amount_to_words_rm($amountLocal);

          // method label
          $methodLabelLocal = '';
          if ($isInNonInvoice && is_array($onePayment)) {
            $bid = (int)($onePayment['bank_account_id'] ?? 0);
            $methodLabelLocal = ($bid > 0 && isset($bankMap[$bid])) ? $bankMap[$bid] : '';
          } else {
            $m = (string)($txn['method'] ?? '');
            if ($m === 'CASH') $methodLabelLocal = t('admin.customer_txn.method.cash', [], 'Cash');
            elseif ($m === 'BANK') $methodLabelLocal = t('admin.customer_txn.view.method_bank', [], 'Bank transfer');
            elseif ($m === 'USDT') $methodLabelLocal = t('admin.customer_txn.method.usdt', [], 'USDT');
            elseif ($m === 'OTHER') $methodLabelLocal = t('admin.customer_txn.view.method_other', [], 'Other');
            else $methodLabelLocal = $m;
          }

          // ✅ chop logic same as admin:
          // IN: company is LEFT (receiver)
          // OUT: company is RIGHT (payer)
          $showChopLeft  = ($isIn  && $sigLeft  !== '' && $vmChopUrl);
          $showChopRight = ($isOut && $sigRight !== '' && $vmChopUrl);
          ?>

          <div class="single-receipt-block">

            <div style="text-align:center; font-size:16px; font-weight:600; letter-spacing:0.12em; color:#1f2937; margin-bottom:18px;">
              <?= h(t('admin.customer_txn.view.receipt_title', [], 'RECEIPT')) ?>
              <?php if ($all === 1 && $isInNonInvoice && is_array($onePayment)): ?>
                <span style="font-size:11px;color:#6b7280;margin-left:6px;">#<?= (int)($idx + 1) ?></span>
              <?php endif; ?>
            </div>

            <p style="font-size:13px; margin-bottom:4px;">
              <strong><?= h(t('admin.customer_txn.field.date', [], 'Date')) ?>:</strong>
              <?= ' ' . h($txn_date) ?>
            </p>

            <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
              <strong><?= h(t('admin.customer_txn.view.received_from', [], 'Received from (Payer):')) ?></strong><br>
              <?= h($payerNameBlock) ?><?php if ($payerRegBlock): ?> (<?= h($payerRegBlock) ?>)<?php endif; ?><br>

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
              <?= h($receiverNameBlock) ?><?php if ($receiverRegBlock): ?> (<?= h($receiverRegBlock) ?>)<?php endif; ?><br>
            </p>

            <?php if ($needSignature): ?>
              <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                <strong><?= h(t('admin.customer_txn.view.recipient', [], 'Customer representative (who signs):')) ?></strong><br>
                <?php if ($rec_name): ?>
                  • <?= h($rec_name) ?>
                  <?= $rec_nric ? ' (' . h(t('admin.customer_txn.view.nric', [], 'NRIC:')) . ' ' . h($rec_nric) . ')' : '' ?><br>
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

            <?php if (!empty($txn['notes'])): ?>
              <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                <strong><?= h(t('admin.customer_txn.field.notes', [], 'Notes')) ?>:</strong><br>
                <?= nl2br(h((string)$txn['notes'])) ?>
              </p>
            <?php endif; ?>

            <?php
            $hasMainAtt = ($attachmentUrl !== '');
            $hasFiles   = !empty($attachments);
            ?>
            <?php if ($hasMainAtt || $hasFiles): ?>
              <p style="font-size:13px; margin-top:12px; margin-bottom:4px;">
                <strong><?= h(t('admin.customer_txn.view.attach.title', [], 'Attachments:')) ?></strong><br>
              <ul style="list-style:disc;padding-left:18px;margin:4px 0 0 0;">
                <?php if ($hasMainAtt): ?>
                  <li><a href="<?= h($attachmentUrl) ?>"><?= h($attachmentName ?: 'Attachment') ?></a></li>
                <?php endif; ?>
                <?php foreach ($attachments as $f): ?>
                  <?php
                  $fp = (string)($f['file_path'] ?? '');
                  $nm = (string)($f['file_name'] ?? '') ?: basename($fp);
                  $href = upload_href($fp);
                  if ($href === '') continue;
                  ?>
                  <li><a href="<?= h(url($href)) ?>"><?= h($nm) ?></a></li>
                <?php endforeach; ?>
              </ul>
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
                      <?php if ($sigLeft !== ''): ?>
                        <img class="sig-img" src="<?= h($sigLeft) ?>" alt="Signature">
                        <?php if ($showChopLeft): ?>
                          <img class="sig-chop" src="<?= h($vmChopUrl) ?>" alt="Company chop">
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="sig-placeholder">
                          <?= h(t($leftNoneKey, [], $isIn ? 'No company signature yet.' : 'No customer signature yet.')) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="sig-meta">
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.name_label', [], 'Name:')) ?></strong> <?= h($leftName ?: '-') ?></div>
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.nric_label', [], 'NRIC:')) ?></strong> <?= h($leftNric ?: '-') ?></div>
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.date_label', [], 'Date:')) ?></strong> <?= h($txn_date) ?></div>
                    </div>
                  </div>

                  <!-- RIGHT -->
                  <div class="sig-col">
                    <p style="font-size:13px; font-weight:600; margin-bottom:6px;">
                      <?= h(t($rightSigKey, [], 'Signature')) ?>
                    </p>

                    <div class="sig-image-box">
                      <?php if ($sigRight !== ''): ?>
                        <img class="sig-img" src="<?= h($sigRight) ?>" alt="Signature">
                        <?php if ($showChopRight): ?>
                          <img class="sig-chop" src="<?= h($vmChopUrl) ?>" alt="Company chop">
                        <?php endif; ?>
                      <?php else: ?>
                        <div class="sig-placeholder">
                          <?= h(t($rightNoneKey, [], $isIn ? 'No customer signature yet.' : 'No company signature yet.')) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="sig-meta">
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.name_label', [], 'Name:')) ?></strong> <?= h($rightName ?: '-') ?></div>
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.nric_label', [], 'NRIC:')) ?></strong> <?= h($rightNric ?: '-') ?></div>
                      <div class="sig-meta-line"><strong><?= h(t('admin.customer_txn.view.date_label', [], 'Date:')) ?></strong> <?= h($txn_date) ?></div>
                    </div>
                  </div>

                </div>
              </div>
            <?php endif; ?>

          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div>

    <?php if ($canSignHere): ?>
      <div class="form-section">
        <div class="form-section-header">
          <div>
            <div class="form-section-title"><?= h(t('admin.customer_txn.view.sign_here_title', [], 'Sign here')) ?></div>
            <div class="form-section-desc">
              <?= h(t('admin.customer_txn.view.sign_here_desc', [], 'Status will become CONFIRMED only after customer signs.')) ?>
            </div>
          </div>
        </div>

        <form method="post" id="sign-form">
          <input type="hidden" name="id" value="<?= h($id) ?>">
          <input type="hidden" name="payment_id" value="<?= h($payment_id) ?>">
          <input type="hidden" name="save_sign" value="1">
          <input type="hidden" name="customer_signature" id="customer_signature" value="">

          <div style="display:flex; flex-wrap:wrap; gap:20px;">
            <div style="flex:1; min-width:260px;">
              <div style="font-size:12px; color:var(--muted); margin-bottom:6px;">
                <?= h(t('admin.customer_txn.view.canvas_customer_tip', [], $isIn ? 'Customer (Payer) – sign inside the box' : 'Customer (Receiver) – sign inside the box')) ?>
              </div>
              <canvas id="sig-customer"
                style="width:100%; max-width:100%; height:180px; border-radius:10px; background:#f9fafb; border:1px dashed #d1d5db;"></canvas>

              <?php if (!empty($errors['customer_signature'])): ?>
                <div class="form-error"><?= h($errors['customer_signature']) ?></div>
              <?php endif; ?>

              <button type="button" class="btn btn-light" id="btn-clear-customer" style="margin-top:6px;">
                <?= h(t('admin.customer_txn.view.clear_btn', [], 'Clear')) ?>
              </button>
            </div>
          </div>

          <div style="margin-top:10px; display:flex; justify-content:space-between;">
            <a href="<?= h($back) ?>" class="btn btn-light"><?= h(t('admin.common.back', [], 'Back')) ?></a>
            <button type="submit" class="btn btn-primary"><?= h(t('admin.customer_txn.view.save_signatures', [], 'Save signature')) ?></button>
          </div>
        </form>
      </div>

      <script>
        document.addEventListener("DOMContentLoaded", function() {
          const form = document.getElementById("sign-form");
          const canvas = document.getElementById("sig-customer");
          const clearBtn = document.getElementById("btn-clear-customer");
          if (!form || !canvas) return;

          const ctx = canvas.getContext("2d");

          function resizeCanvas() {
            const rect = canvas.getBoundingClientRect();
            canvas.width = Math.max(1, Math.floor(rect.width));
            canvas.height = Math.max(1, Math.floor(rect.height));
            ctx.clearRect(0, 0, canvas.width, canvas.height);
          }
          resizeCanvas();
          window.addEventListener('resize', resizeCanvas);

          let drawing = false, lastX = 0, lastY = 0, hasDrawn = false;

          function getPos(e) {
            const rect = canvas.getBoundingClientRect();
            if (e.touches && e.touches.length > 0) {
              return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
            }
            return { x: e.clientX - rect.left, y: e.clientY - rect.top };
          }

          function startDraw(e) {
            e.preventDefault();
            drawing = true;
            const p = getPos(e);
            lastX = p.x; lastY = p.y;
          }

          function draw(e) {
            if (!drawing) return;
            e.preventDefault();
            const p = getPos(e);
            ctx.strokeStyle = "#111827";
            ctx.lineWidth = 2;
            ctx.lineCap = "round";
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
            hasDrawn = true;
          }

          function endDraw(e) {
            if (!drawing) return;
            e.preventDefault();
            drawing = false;
          }

          canvas.addEventListener("mousedown", startDraw);
          canvas.addEventListener("mousemove", draw);
          canvas.addEventListener("mouseup", endDraw);
          canvas.addEventListener("mouseleave", endDraw);

          canvas.addEventListener("touchstart", startDraw, { passive: false });
          canvas.addEventListener("touchmove", draw, { passive: false });
          canvas.addEventListener("touchend", endDraw, { passive: false });
          canvas.addEventListener("touchcancel", endDraw, { passive: false });

          if (clearBtn) {
            clearBtn.addEventListener("click", function() {
              ctx.clearRect(0, 0, canvas.width, canvas.height);
              hasDrawn = false;
            });
          }

          const msg = <?= json_encode(t('admin.customer_txn.view.error.sign_required', [], 'Please sign before saving.')) ?>;

          form.addEventListener("submit", function(e) {
            if (!hasDrawn) {
              e.preventDefault();
              alert(msg);
              return;
            }
            document.getElementById("customer_signature").value = canvas.toDataURL("image/png");
          });
        });
      </script>
    <?php else: ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
        <?php if ($needSignature): ?>
          <span style="font-size:12px;color:#6b7280;">
            <?= h(t('admin.customer_txn.view.sign_done', [], 'Signature captured / no further signature required here.')) ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <script>
      // ✅ Print/PDF: chop + same-row + same-height (same as admin)
      function printReceipt() {
        const el = document.getElementById('receipt-print-area');
        if (!el) { alert('Receipt not found'); return; }

        const isAll = <?= json_encode($all === 1) ?>;

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

    #receipt-print-area{
      border:0 !important; box-shadow:none !important; border-radius:0 !important;
      padding:0 !important; margin:0 !important; background:#fff !important;
    }

    img { max-width:100%; }
    a { color:#111; text-decoration:none; }

    /* ✅ keep LEFT/RIGHT in one row */
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
    .sig-image-box img.sig-img{
      max-width:100% !important;
      max-height:170px !important;
      object-fit:contain !important;
      display:block !important;
    }
    .sig-placeholder{ font-size:12px !important; color:#9ca3af !important; }
    .sig-meta{ margin-top:auto !important; }
    .sig-meta-line{ font-size:11px !important; color:#4b5563 !important; line-height:1.35 !important; }

    /* ✅ CHOP same as admin */
    .sig-chop{
      position:absolute !important;
      right:10px !important;
      bottom:6px !important;
      max-height:80px !important;
      opacity:0.9 !important;
      pointer-events:none !important;
    }

    ${isAll ? `
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
    ` : ``}
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

  <?php endif; ?>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
