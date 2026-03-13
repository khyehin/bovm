<?php
// public/admin/customers/txn_doc_in.php — Unified INVOICE / QUOTATION / DO document (same format as PDF: INVOICE & DO)
// Quotation = same layout as Invoice, only title "QUOTATION"; Invoice = same; DO = delivery order layout below.
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

// 默认只允许后台管理员访问；如果从用户门户复用本文件，
// 会在入口文件里定义 ALLOW_TXN_DOC_FROM_PORTAL = true 来跳过 require_admin。
$allowFromPortal = defined('ALLOW_TXN_DOC_FROM_PORTAL') && ALLOW_TXN_DOC_FROM_PORTAL === true;

if (!$allowFromPortal) {
  require_admin();
  if (function_exists('require_perm')) {
    require_perm('TXN.V');
  }
} else {
  // 用户门户已经做过 customer 身份 & 归属校验，这里只保证已登录即可。
  if (function_exists('require_login')) {
    require_login();
  }
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
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
$hasDiscount = isset($txnCols['discount']);
$hasDeliverTo = isset($txnCols['deliver_to']);
$hasTerms = isset($txnCols['terms']);
$hasDoNumber = isset($txnCols['do_number']);
$hasReqSignQuotation = isset($txnCols['require_sign_quotation']);
$hasReqSignInvoice = isset($txnCols['require_sign_invoice']);
$hasReqSignDo = isset($txnCols['require_sign_do']);

$cid = (int)($_GET['customer_id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);
$doc = strtoupper(trim((string)($_GET['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['INVOICE', 'QUOTATION', 'DO'], true)) $doc = 'INVOICE';

if ($cid <= 0 || $id <= 0) {
  header('Location: ' . url('admin/customers/invoices.php'));
  exit;
}

$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id LIMIT 1");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

$st = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id AND customer_id = :cid AND txn_type = 'IN' LIMIT 1");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();
if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}

$lines = [];
try {
  $pdo->query("SELECT 1 FROM customer_txn_lines LIMIT 1");
  $st = $pdo->prepare("SELECT * FROM customer_txn_lines WHERE customer_txn_id = :tid ORDER BY line_seq ASC, id ASC");
  $st->execute([':tid' => $id]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$customerName = (string)($customer['name'] ?? '');
$customerAddr = array_filter([
  (string)($customer['address1'] ?? ''),
  (string)($customer['address2'] ?? ''),
  (string)($customer['address3'] ?? ''),
  trim(implode(' ', array_filter([$customer['city'] ?? '', $customer['state'] ?? '', $customer['postcode'] ?? '']))),
]);
$customerTel = (string)($customer['contact_phone'] ?? '');
$customerAttn = (string)($customer['contact_name'] ?? '');

$order_total = (float)($txn['order_total'] ?? 0);
$discount = $hasDiscount ? (float)($txn['discount'] ?? 0) : 0;
$grand = max(0, $order_total - $discount);

$currencyCode = strtoupper(trim((string)($txn['currency'] ?? 'MYR')));
if ($currencyCode === '') $currencyCode = 'MYR';
$moneyPrefix = ($currencyCode === 'MYR') ? 'RM ' : ($currencyCode . ' ');

$signReceive = !empty($txn['sign_receive']);
$signMode = strtoupper(trim((string)($txn['sign_mode'] ?? 'SIGN_AND_CHOP')));
if (!in_array($signMode, ['CHOP_ONLY', 'SIGN_AND_CHOP', 'SIGN_ONLY'], true)) $signMode = 'SIGN_AND_CHOP';

$needCompanySign = ($signMode !== 'CHOP_ONLY') && $signReceive;
$showCompanyChop = ($signMode !== 'SIGN_ONLY');
$needCustomerSign = false;
if ($doc === 'QUOTATION' && $hasReqSignQuotation) {
  $needCustomerSign = !empty($txn['require_sign_quotation']);
} elseif ($doc === 'INVOICE' && $hasReqSignInvoice) {
  $needCustomerSign = !empty($txn['require_sign_invoice']);
} elseif ($doc === 'DO' && $hasReqSignDo) {
  $needCustomerSign = !empty($txn['require_sign_do']);
} else {
  // fallback for older DB schema
  $needCustomerSign = !empty($txn['sign_payer']);
}
$logoUrl = url('admin/assets/img/vmlogo.png');
$chopUrl = url('admin/assets/img/vmchop.png');
$company = function_exists('get_company') ? get_company() : ['name' => 'VISION MIX SDN BHD', 'reg_no' => '1622729-U', 'address' => ['LOT 3A-02A, 4TH FLOOR ENDAH PARADE,', 'NO.1 JALAN 1/149E, BANDAR BARU SRI PETALING,', '57000 KUALA LUMPUR'], 'phone' => '', 'email' => ''];
$companyName = (string)($company['name'] ?? '');
$companyRegNo = (string)($company['reg_no'] ?? '');
$companyAddress = (array)($company['address'] ?? []);
$companyPhone = (string)($company['phone'] ?? '');
$companyEmail = (string)($company['email'] ?? '');
$preferredBank = null;
try {
  $stBank = $pdo->query("SELECT id, bank_code, account_name, account_no, currency FROM company_bank_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
  $preferredBank = $stBank->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {}

$docNo = ($doc === 'DO') ? trim((string)($txn['do_number'] ?? '')) : (($doc === 'INVOICE') ? trim((string)($txn['invoice_no'] ?? '')) : '');
// DO Number auto-generate (print-time fallback) when empty
if ($doc === 'DO' && $docNo === '') {
  $doDateForNo = !empty($txn['do_date']) ? (string)$txn['do_date'] : (string)($txn['txn_date'] ?? date('Y-m-d'));
  $ts = strtotime($doDateForNo);
  if ($ts !== false) {
    $ym = date('ym', $ts);
    $prefix = 'VMDO' . $ym . '-';
    try {
      $seqNo = 1;
      if ($hasDoNumber) {
        $stDo = $pdo->prepare("SELECT do_number FROM customer_txn WHERE do_number LIKE :pfx ORDER BY do_number DESC LIMIT 1");
        $stDo->execute([':pfx' => $prefix . '%']);
        if ($rDo = $stDo->fetch(PDO::FETCH_ASSOC)) {
          $last3 = (int)substr((string)($rDo['do_number'] ?? ''), -3);
          if ($last3 > 0) $seqNo = $last3 + 1;
        }
      }
      $docNo = $prefix . str_pad((string)$seqNo, 3, '0', STR_PAD_LEFT);
      if ($hasDoNumber) {
        $pdo->prepare("UPDATE customer_txn SET do_number = :dn, updated_at = NOW() WHERE id = :id")->execute([':dn' => $docNo, ':id' => $id]);
        $txn['do_number'] = $docNo;
      }
    } catch (Throwable $e) {
      // ignore
    }
  }
}
$docDate = ($doc === 'DO' && !empty($txn['do_date'])) ? (string)$txn['do_date'] : (string)($txn['txn_date'] ?? date('Y-m-d'));
$docDateFormatted = (strtotime($docDate) !== false) ? date('d/m/Y', strtotime($docDate)) : $docDate;
$docTitle = $doc; // INVOICE | QUOTATION | DELIVERY ORDER
if ($doc === 'DO') $docTitle = 'DELIVERY ORDER';
$doNumberVal = ($doc === 'DO' && $docNo !== '') ? $docNo : trim((string)($txn['do_number'] ?? ''));
$termsVal = trim((string)($txn['terms'] ?? ''));
$deliverToVal = trim((string)($txn['deliver_to'] ?? ''));

$page_title = $docTitle . ' · ' . $customerName;
if (!$allowFromPortal) {
  include __DIR__ . '/../include/header.php';
}
?>
<style>
.doc-print-wrap { max-width:800px; margin:0 auto; padding:20px; font-size:13px; color:#111827; }
.doc-print-wrap table { width:100%; border-collapse:collapse; }

/* 只保留物品表格线；其余区块（抬头/客户资料/签名）不显示外框格子 */
.doc-table-items { width:100%; border-collapse:collapse; }
.doc-table-items th, .doc-table-items td { border:1px solid #333; padding:6px 8px; text-align:left; }
.doc-table-items th { background:#f3f4f6; }
.doc-row-to-meta, .doc-row-to-meta td,
.doc-sign-row, .doc-sign-row td { border:0 !important; }

.doc-top-center { text-align:center; margin-bottom:18px; }
.doc-top-center .doc-top-inner { display:inline-block; text-align:left; max-width:100%; }
.doc-top-logo { vertical-align:top; padding-right:12px; }
.doc-top-logo img { max-height:55px; }
.doc-top-company { font-size:11px; line-height:1.45; vertical-align:top; }
.doc-top-company .doc-company-name { font-size:14px; font-weight:bold; text-transform:uppercase; }
.doc-row-to-meta { width:100%; margin-bottom:14px; }
.doc-row-to-meta td { vertical-align:top; padding:0 12px 0 0; }
.doc-to-block { width:50%; }
.doc-meta-block { width:50%; text-align:right; }
.doc-meta-block .doc-title { font-size:18px; font-weight:700; margin-bottom:8px; }
.doc-meta-block .label { font-weight:bold; }
.doc-line { border-top:1px solid #000; margin:10px 0; }
.doc-table-items th.col-no { width:50px; }
.doc-table-items th.col-desc { min-width:200px; }
.doc-table-items th.col-qty { width:80px; }
.doc-table-items th.col-unit { width:100px; }
.doc-table-items th.col-amount { width:110px; text-align:right; }
.doc-table-items td.text-right { text-align:right; }
.doc-sign-row { width:100%; margin-top:22px; }
.doc-sign-row td { vertical-align:top; padding:8px 12px 0 0; width:50%; }
.doc-sig-area { position:relative; min-height:70px; overflow:hidden; }
.doc-chop { position:absolute; right:4px; bottom:3px; max-height:55px; opacity:0.95; }
.doc-sign-flex { display:flex; gap:24px; margin-top:22px; align-items:flex-end; }
.doc-sign-col { flex:1; }
.doc-sign-col.right { text-align:right; }
.doc-underline { border-top:1px solid #000; height:0; margin-top:6px; width:220px; max-width:100%; }
.doc-sign-col.right .doc-underline { margin-left:auto; }
.doc-sign-label { font-weight:bold; font-size:12px; }
.doc-sign-date-label { font-size:12px; margin-top:10px; }
.doc-sign-top-spacer { height:18px; }
.doc-sign-box { border:1px solid #000; min-height:70px; }
.sigpad-wrap { border:1px solid #000; width:260px; max-width:100%; height:90px; background:#fff; }
.sigpad-canvas { width:100%; height:100%; display:block; }
.sigpad-actions { margin-top:6px; display:flex; gap:8px; align-items:center; }
.sigpad-actions .btn { padding:4px 10px; font-size:12px; }
.sigimg { width:260px; max-width:100%; height:90px; object-fit:contain; display:block; }
#sigpadSigned .sigimg, #sigpadCompanySigned .sigimg { height:90px; }
.sigbox { width:260px; max-width:100%; height:90px; position:relative; }
#sigpadSigned, #sigpadCompanySigned { width:260px; max-width:100%; height:90px; }
#sigpadCompanyInput .sigpad-wrap { margin-left:auto; }
#companySignCol .doc-sig-area { position:relative; }
#sigpadCompanySigned { position:absolute; right:0; bottom:0; z-index:1; }
#companySignCol .doc-chop { z-index:2; }
.doc-chop { pointer-events:none; }
.sigdate { font-weight:600; font-size:12px; }
.sig-controls { margin-top:10px; display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
.sig-controls label { display:flex; gap:6px; align-items:center; font-size:12px; }
.sig-controls select { font-size:12px; padding:3px 6px; }
.no-print { margin-bottom:16px; }
@media print {
  .no-print { display:none !important; }
  .sigpad-actions { display:none !important; }
  #sigpadInput, #sigpadCompanyInput { display:none !important; }
  .sigpad-wrap { border:0 !important; }
  .sigimg { border:0 !important; }
  .doc-print-wrap button,
  .doc-print-wrap select,
  .doc-print-wrap input { display:none !important; }
  .doc-print-wrap { padding:0; }
  body * { visibility:hidden; }
  .doc-print-wrap, .doc-print-wrap * { visibility:visible; }
  .doc-print-wrap { position:absolute; left:0; top:0; width:100%; }
}
</style>

<?php if (!$allowFromPortal): ?>
  <!-- Admin 后台：完整控制条（Back + Print + 签名控制），顾客看不到 -->
  <div class="no-print">
    <a href="<?= h(url('admin/customers/txn_edit_in.php?id=' . $id . '&customer_id=' . $cid)) ?>" class="btn btn-light">← Back</a>
    <button type="button" class="btn btn-primary" onclick="window.print();">Print / PDF</button>
    <div class="sig-controls" style="margin-top:10px;">
      <label><input type="checkbox" id="optNeedCustomer" <?= $needCustomerSign ? 'checked' : '' ?>>Customer signature</label>
      <label><input type="checkbox" id="optNeedCompany" <?= $needCompanySign ? 'checked' : '' ?>>Company signature</label>
      <label>Company mode
        <select id="optCompanyMode">
          <option value="CHOP_ONLY" <?= $signMode === 'CHOP_ONLY' ? 'selected' : '' ?>>Chop only</option>
          <option value="SIGN_AND_CHOP" <?= $signMode === 'SIGN_AND_CHOP' ? 'selected' : '' ?>>Sign &amp; Chop</option>
          <option value="SIGN_ONLY" <?= $signMode === 'SIGN_ONLY' ? 'selected' : '' ?>>Sign only</option>
        </select>
      </label>
    </div>
  </div>
<?php else: ?>
  <?php if (!empty($GLOBALS['ALLOW_TXN_DOC_FROM_COMPANY1'] ?? false)): ?>
    <!-- Company1 入口：有 Back / Print / 签名控制 + Quotation·Invoice·DO -->
    <div class="no-print" style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <?php
      $backUrlDoc = (string)($GLOBALS['_TXN_DOC_BACK_URL_FROM_PORTAL'] ?? url('user/company1/invoices.php?customer_id=' . $cid));
      ?>
      <a href="<?= h($backUrlDoc) ?>" class="btn btn-light btn-sm">← Back</a>
      <button type="button" class="btn btn-light btn-sm" onclick="window.print();">Print / PDF</button>
      <div class="sig-controls" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:12px;">
        <label style="display:flex;align-items:center;gap:4px;">
          <input type="checkbox" id="optNeedCustomer" <?= $needCustomerSign ? 'checked' : '' ?>>Customer signature
        </label>
        <label style="display:flex;align-items:center;gap:4px;">
          <input type="checkbox" id="optNeedCompany" <?= $needCompanySign ? 'checked' : '' ?>>Company signature
        </label>
        <label style="display:flex;align-items:center;gap:4px;">
          Company mode
          <select id="optCompanyMode">
            <option value="CHOP_ONLY" <?= $signMode === 'CHOP_ONLY' ? 'selected' : '' ?>>Chop only</option>
            <option value="SIGN_AND_CHOP" <?= $signMode === 'SIGN_AND_CHOP' ? 'selected' : '' ?>>Sign &amp; Chop</option>
            <option value="SIGN_ONLY" <?= $signMode === 'SIGN_ONLY' ? 'selected' : '' ?>>Sign only</option>
          </select>
        </label>
        <div style="margin-left:8px;">
          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=QUOTATION')) ?>" class="btn btn-xs btn-light">Quotation</a>
          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=INVOICE')) ?>" class="btn btn-xs btn-light">Invoice</a>
          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=DO')) ?>" class="btn btn-xs btn-light">DO</a>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- 顾客 portal：外层 user/txn/txn_doc_in.php 已经有 Back / Print，这里就不重复显示任何按钮 -->
  <?php endif; ?>
<?php endif; ?>

<div id="doc-print-area" class="doc-print-wrap">
  <div class="doc-top-center">
    <div class="doc-top-inner">
      <table style="margin:0 auto; border:0;">
        <tr>
          <td class="doc-top-logo"><img src="<?= h($logoUrl) ?>" alt="Logo"></td>
          <td class="doc-top-company">
            <div class="doc-company-name"><?= h($companyName) ?><?= $companyRegNo !== '' ? ' ' . h($companyRegNo) : '' ?></div>
            <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
            <div><?= h($line) ?></div>
            <?php endforeach; ?>
            <?php if ($companyEmail !== '' || $companyPhone !== ''): ?>
            <div style="margin-top:4px;">
              <?php if ($companyEmail !== ''): ?>
                <span>Email: <?= h($companyEmail) ?></span><?= $companyPhone !== '' ? ' &nbsp; ' : '' ?>
              <?php endif; ?>
              <?php if ($companyPhone !== ''): ?>
                <span>Hp: <?= h($companyPhone) ?></span>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      </table>
    </div>
  </div>

  <table class="doc-row-to-meta">
    <tr>
      <td class="doc-to-block">
        <div style="font-weight:bold; margin-bottom:4px;">To: <?= h($customerName) ?></div>
        <?php if (!empty($customerAddr)): ?>
        <div><span class="label">Add.:</span> <?= h(implode(' ', $customerAddr)) ?></div>
        <?php endif; ?>
        <?php if ($customerTel !== ''): ?>
        <div><span class="label">Tel:</span> <?= h($customerTel) ?></div>
        <?php endif; ?>
        <?php if ($customerAttn !== ''): ?>
        <div><span class="label">Attn.:</span> <?= h($customerAttn) ?></div>
        <?php endif; ?>
        <?php if (!empty($customer['contact_email'] ?? '')): ?>
        <div><span class="label">Email:</span> <?= h($customer['contact_email']) ?></div>
        <?php endif; ?>
      </td>
      <td class="doc-meta-block">
        <div class="doc-title"><?= h($docTitle) ?></div>
        <?php if ($doc === 'DO'): ?>
          <div><span class="label">D/Order No :</span> <?= h($docNo !== '' ? $docNo : '—') ?></div>
          <div><span class="label">Date :</span> <?= h($docDateFormatted) ?></div>
          <div><span class="label">From Doc No. :</span> <?= h(trim((string)($txn['invoice_no'] ?? '')) ?: '—') ?></div>
          <div><span class="label">Terms :</span> <?= h($termsVal !== '' ? $termsVal : 'C.O.D') ?></div>
          <?php if ($hasDeliverTo): ?>
            <div><span class="label">Deliver To :</span> <?= h($deliverToVal !== '' ? $deliverToVal : '') ?></div>
          <?php endif; ?>
        <?php else: ?>
          <?php if ($doc === 'INVOICE'): ?>
            <div><span class="label">Invoice No :</span> <?= h(trim((string)($txn['invoice_no'] ?? '')) ?: '—') ?></div>
          <?php endif; ?>
          <div><span class="label">Date :</span> <?= h($docDateFormatted) ?></div>
          <?php if ($hasDoNumber): ?>
            <div><span class="label">DO. Number :</span> <?= h($doNumberVal !== '' ? $doNumberVal : '—') ?></div>
          <?php endif; ?>
          <div><span class="label">Terms :</span> <?= h($termsVal !== '' ? $termsVal : 'C.O.D') ?></div>
          <?php if ($hasDeliverTo): ?>
            <div><span class="label">Deliver To :</span> <?= h($deliverToVal !== '' ? $deliverToVal : '') ?></div>
          <?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  </table>

  <div class="doc-line"></div>

  <?php if ($doc === 'DO'): ?>
    <div style="margin-bottom:12px;"><strong>Title:</strong> <?= h($txn['title'] ?? 'Delivery Order') ?></div>
    <table class="doc-table-items" style="margin-top:8px;">
      <thead>
        <tr>
          <th class="col-no">NO.</th>
          <th class="col-desc">Description</th>
          <th class="col-qty">Quantity</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($lines) {
          foreach ($lines as $i => $line) {
            $no = $i + 1;
            $desc = (string)($line['description'] ?? '');
            $qty = (float)($line['quantity'] ?? 1);
        ?>
        <tr>
          <td><?= (int)$no ?></td>
          <td><?= h($desc) ?></td>
          <td><?= h($qty) ?></td>
        </tr>
        <?php
          }
        } else {
          $tit = (string)($txn['title'] ?? '');
        ?>
        <tr>
          <td>1</td>
          <td><?= h($tit ?: '—') ?></td>
          <td>1</td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  <?php else: ?>
    <div style="margin-bottom:10px;"><strong>Title:</strong> <?= h($txn['title'] ?? ($doc === 'QUOTATION' ? 'Quotation' : 'Invoice')) ?></div>
    <table class="doc-table-items">
      <thead>
        <tr>
          <th class="col-no">NO.</th>
          <th class="col-desc">Description</th>
          <th class="col-qty">Quantity</th>
          <th class="col-unit">Unit Price</th>
          <th class="col-amount">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php
        if ($lines) {
          foreach ($lines as $i => $line) {
            $no = $i + 1;
            $desc = (string)($line['description'] ?? '');
            $qty = (float)($line['quantity'] ?? 1);
            $unit = (float)($line['unit_price'] ?? 0);
            $amt = (float)($line['amount'] ?? 0);
        ?>
        <tr>
          <td><?= (int)$no ?></td>
          <td><?= h($desc) ?></td>
          <td><?= h($qty) ?></td>
          <td class="text-right"><?= h($moneyPrefix) ?><?= number_format($unit, 2) ?></td>
          <td class="text-right"><?= h($moneyPrefix) ?><?= number_format($amt, 2) ?></td>
        </tr>
        <?php
          }
        } else {
          $tit = (string)($txn['title'] ?? '');
          $tot = (float)($txn['order_total'] ?? 0);
        ?>
        <tr>
          <td>1</td>
          <td><?= h($tit ?: '—') ?></td>
          <td>1</td>
          <td class="text-right"><?= h($moneyPrefix) ?><?= number_format($tot, 2) ?></td>
          <td class="text-right"><?= h($moneyPrefix) ?><?= number_format($tot, 2) ?></td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
    <div style="max-width:320px; margin-left:auto; margin-top:12px;">
      <?php if ($hasDiscount && $discount > 0): ?>
        <div><strong>Discount:</strong> <?= h($moneyPrefix) ?><?= number_format($discount, 2) ?></div>
      <?php endif; ?>
      <div style="font-size:15px; font-weight:700; margin-top:6px;">Total Amount: <?= h($moneyPrefix) ?><?= number_format($grand, 2) ?></div>
    </div>
    <?php if ($hasTerms && !empty(trim((string)($txn['terms'] ?? '')))): ?>
      <div style="margin-top:14px;"><strong>Terms</strong><div style="white-space:pre-wrap; font-size:12px;"><?= h($txn['terms']) ?></div></div>
    <?php endif; ?>
    <?php if (!empty(trim((string)($txn['notes'] ?? '')))): ?>
      <div style="margin-top:10px;"><strong>Notes</strong><div style="white-space:pre-wrap; font-size:12px;"><?= h($txn['notes']) ?></div></div>
    <?php endif; ?>
  <?php endif; ?>

  <div style="margin-top:18px; font-size:12px; color:#374151;">
    <p style="margin:0 0 6px;">All sales, delivery and payment are subject to the terms and conditions of VISION MIX SDN. BHD. Additional copies available on request.</p>
    <p style="margin:0 0 6px;">Submission of order for products/services constitutes acceptance of these terms and conditions.</p>
    <p style="margin:0 0 12px;">All overdue accounts will be subject to an additional charge of 1.5% per month. Payment to be made to VISION MIX SDN. BHD.</p>
  </div>
  <?php if ($preferredBank): ?>
  <div style="margin-top:10px;">
    <strong>PREFERRED BANK</strong>
    <div style="margin-top:4px; font-size:12px;">
      <span class="label">BANK:</span> <?= h($preferredBank['bank_code'] ?? '') ?> &nbsp;|&nbsp;
      <span class="label">ACCOUNT NAME:</span> <?= h($preferredBank['account_name'] ?? '') ?> &nbsp;|&nbsp;
      <span class="label">ACCOUNT NO.:</span> <?= h($preferredBank['account_no'] ?? '') ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="doc-sign-flex">
    <div class="doc-sign-col" id="customerSignCol" style="<?= $needCustomerSign ? '' : 'display:none;' ?>">
        <div class="doc-sign-top-spacer"></div>
        <div id="sigpadMount" class="doc-sig-area">
          <div id="sigpadSigned" style="display:none;"></div>
          <div id="sigpadInput">
            <div class="sigpad-wrap"><canvas id="sigpadCanvas" class="sigpad-canvas"></canvas></div>
            <div class="sigpad-actions">
              <button type="button" class="btn btn-light" id="sigpadClear">Clear</button>
              <button type="button" class="btn btn-primary" id="sigpadDone">Done</button>
            </div>
          </div>
        </div>
        <div class="doc-underline"></div>
        <div class="doc-sign-label" style="margin-top:12px;">RECEIVED BY AND COMPANY STAMP:</div>
      <div class="doc-sign-date-label" style="margin-top:12px;">DATE: <span class="sigdate" id="sigpadDateText"></span></div>
    </div>

    <div class="doc-sign-col right" id="companySignCol">
      <div style="font-weight:bold; margin-bottom:4px;"><?= h($companyName) ?></div>
      <div class="doc-sig-area" style="min-height:70px;">
        <div id="sigpadCompanySigned" style="display:none; margin-left:auto;"></div>
        <div id="sigpadCompanyInput" style="<?= $needCompanySign ? '' : 'display:none;' ?>">
          <div class="sigpad-wrap" style="margin-left:auto;">
            <canvas id="sigpadCompanyCanvas" class="sigpad-canvas"></canvas>
          </div>
          <div class="sigpad-actions" style="justify-content:flex-end;">
            <button type="button" class="btn btn-light" id="sigpadCompanyClear">Clear</button>
            <button type="button" class="btn btn-primary" id="sigpadCompanyDone">Done</button>
          </div>
        </div>
        <?php if ($chopUrl && $showCompanyChop): ?>
          <img src="<?= h($chopUrl) ?>" class="doc-chop" alt="">
        <?php endif; ?>
      </div>
      <div class="doc-underline"></div>
      <div style="margin-top:6px; font-size:12px;">
        <?= ($needCompanySign ? "Company's Stamp &amp; Signature" : "Company's Stamp") ?>
        <span class="sigdate" id="sigpadCompanyDateText" style="margin-left:10px;"></span>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var docKeyBase = 'vm_doc_' + <?= (int)$id ?> + '_' + <?= json_encode($doc, JSON_UNESCAPED_SLASHES) ?>;

  function fmtNow(){
    var d = new Date();
    function p(n){ return (n < 10 ? '0' : '') + n; }
    return p(d.getDate()) + '/' + p(d.getMonth()+1) + '/' + d.getFullYear();
  }

  function normalizeDateText(s){
    if (!s) return '';
    // 兼容旧缓存：可能是 "dd/mm/yyyy HH:MM"
    var i = String(s).indexOf(' ');
    return i > 0 ? String(s).slice(0, i) : String(s);
  }

  function readJson(key){
    try {
      var v = localStorage.getItem(key);
      if (!v) return null;
      return JSON.parse(v);
    } catch(e){ return null; }
  }

  function writeJson(key, obj){
    try { localStorage.setItem(key, JSON.stringify(obj)); } catch(e){}
  }

  function createSigPad(opts){
    var mount = document.getElementById(opts.mountId);
    var canvas = document.getElementById(opts.canvasId);
    var inputWrap = document.getElementById(opts.inputId);
    var signedWrap = document.getElementById(opts.signedId);
    var btnClear = document.getElementById(opts.clearId);
    var btnDone = document.getElementById(opts.doneId);
    var dateEl = document.getElementById(opts.dateId);
    if (!mount || !canvas || !inputWrap || !signedWrap) return null;

    var key = opts.storageKey;
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasInk = false;

    function fitCanvas(){
      if (inputWrap.style.display === 'none') return;
      var rect = canvas.getBoundingClientRect();
      var ratio = window.devicePixelRatio || 1;
      var w = Math.max(1, Math.floor(rect.width * ratio));
      var h = Math.max(1, Math.floor(rect.height * ratio));
      if (canvas.width === w && canvas.height === h) return;
      canvas.width = w;
      canvas.height = h;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.lineWidth = 2.2 * ratio;
      ctx.strokeStyle = '#111';
    }

    function pos(e){
      var r = canvas.getBoundingClientRect();
      var x = (e.clientX - r.left) * (canvas.width / r.width);
      var y = (e.clientY - r.top) * (canvas.height / r.height);
      return {x:x, y:y};
    }

    function start(e){
      drawing = true;
      var p = pos(e);
      ctx.beginPath();
      ctx.moveTo(p.x, p.y);
      hasInk = true;
      e.preventDefault();
    }
    function move(e){
      if (!drawing) return;
      var p = pos(e);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      hasInk = true;
      e.preventDefault();
    }
    function end(e){
      drawing = false;
      if (e && e.preventDefault) e.preventDefault();
    }

    function showSigned(dataUrl, signedAt){
      var img = new Image();
      img.src = dataUrl;
    img.className = 'sigimg';
      signedWrap.innerHTML = '';
      signedWrap.appendChild(img);
      signedWrap.style.display = '';
      inputWrap.style.display = 'none';
      if (dateEl) dateEl.textContent = normalizeDateText(signedAt || '');
    }

    function clear(){
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      hasInk = false;
      try { localStorage.removeItem(key); } catch(e){}
      if (dateEl) dateEl.textContent = '';
      signedWrap.style.display = 'none';
      inputWrap.style.display = '';
      fitCanvas();
    }

    function done(){
      if (!hasInk) return;
      var dataUrl = canvas.toDataURL('image/png');
      var signedAt = fmtNow();
      writeJson(key, { img: dataUrl, at: signedAt });
      showSigned(dataUrl, signedAt);
    }

    canvas.addEventListener('pointerdown', start);
    canvas.addEventListener('pointermove', move);
    window.addEventListener('pointerup', end);
    canvas.addEventListener('pointercancel', end);
    canvas.addEventListener('contextmenu', function(e){ e.preventDefault(); });
    if (btnClear) btnClear.addEventListener('click', clear);
    if (btnDone) btnDone.addEventListener('click', done);

    // load saved
    var saved = readJson(key);
    if (saved && saved.img) {
      showSigned(saved.img, saved.at || '');
    } else {
      fitCanvas();
    }

    return { fitCanvas: fitCanvas, showInput: function(on){ inputWrap.style.display = on ? '' : 'none'; } , clear: clear };
  }

  var padCustomer = createSigPad({
    mountId: 'sigpadMount',
    canvasId: 'sigpadCanvas',
    inputId: 'sigpadInput',
    signedId: 'sigpadSigned',
    clearId: 'sigpadClear',
    doneId: 'sigpadDone',
    dateId: 'sigpadDateText',
    storageKey: docKeyBase + '_sig_customer'
  });
  var padCompany = createSigPad({
    mountId: 'companySignCol',
    canvasId: 'sigpadCompanyCanvas',
    inputId: 'sigpadCompanyInput',
    signedId: 'sigpadCompanySigned',
    clearId: 'sigpadCompanyClear',
    doneId: 'sigpadCompanyDone',
    dateId: 'sigpadCompanyDateText',
    storageKey: docKeyBase + '_sig_company'
  });

  function applyOptions(){
    var optKey = docKeyBase + '_sig_opts';
    var opt = readJson(optKey) || {};

    var cbCus = document.getElementById('optNeedCustomer');
    var cbCom = document.getElementById('optNeedCompany');
    var selMode = document.getElementById('optCompanyMode');
    var cusCol = document.getElementById('customerSignCol');
    var comInput = document.getElementById('sigpadCompanyInput');
    var chop = document.querySelector('#companySignCol .doc-chop');

    // init from UI state first time
    if (opt.init !== 1) {
      opt.init = 1;
      opt.needCustomer = cbCus ? cbCus.checked : false;
      opt.needCompany = cbCom ? cbCom.checked : false;
      opt.mode = selMode ? selMode.value : 'SIGN_AND_CHOP';
      writeJson(optKey, opt);
    } else {
      if (cbCus) cbCus.checked = !!opt.needCustomer;
      if (cbCom) cbCom.checked = !!opt.needCompany;
      if (selMode && opt.mode) selMode.value = opt.mode;
    }

    var needCustomer = cbCus ? cbCus.checked : false;
    var needCompany = cbCom ? cbCom.checked : false;
    var mode = selMode ? selMode.value : 'SIGN_AND_CHOP';

    if (cusCol) cusCol.style.display = needCustomer ? '' : 'none';
    if (comInput) comInput.style.display = (needCompany && mode !== 'CHOP_ONLY') ? '' : 'none';

    if (chop) {
      chop.style.display = (mode === 'SIGN_ONLY') ? 'none' : '';
    }

    // ensure canvases scale after toggles
    if (padCustomer) padCustomer.fitCanvas();
    if (padCompany) padCompany.fitCanvas();
  }

  function saveOptions(){
    var optKey = docKeyBase + '_sig_opts';
    var cbCus = document.getElementById('optNeedCustomer');
    var cbCom = document.getElementById('optNeedCompany');
    var selMode = document.getElementById('optCompanyMode');
    var opt = {
      init: 1,
      needCustomer: cbCus ? cbCus.checked : false,
      needCompany: cbCom ? cbCom.checked : false,
      mode: selMode ? selMode.value : 'SIGN_AND_CHOP'
    };
    writeJson(optKey, opt);
    applyOptions();
  }

  var cbCus = document.getElementById('optNeedCustomer');
  var cbCom = document.getElementById('optNeedCompany');
  var selMode = document.getElementById('optCompanyMode');
  if (cbCus) cbCus.addEventListener('change', saveOptions);
  if (cbCom) cbCom.addEventListener('change', saveOptions);
  if (selMode) selMode.addEventListener('change', saveOptions);

  applyOptions();
  window.addEventListener('resize', function(){
    if (padCustomer) padCustomer.fitCanvas();
    if (padCompany) padCompany.fitCanvas();
  });
})();
</script>
