<?php
// public/admin/customers/quotation_print.php — Print-friendly Quotation view (Print / Save as PDF)
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.E');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function parse_unit_marker(string $desc): array {
  $desc = (string)$desc;
  if (preg_match('/^\[\[UNIT:(.*?)\]\]\s*(\r\n|\r|\n)?/u', $desc, $m)) {
    $unitLabel = trim((string)($m[1] ?? ''));
    $rest = substr($desc, strlen((string)$m[0]));
    return [$unitLabel, $rest];
  }
  return ['', $desc];
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

$cid = (int)($_GET['customer_id'] ?? 0);
$id  = (int)($_GET['id'] ?? 0);

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

$signReceive = !empty($txn['sign_receive']);
$logoUrl = url('admin/assets/img/vmlogo.png');
$chopUrl = url('admin/assets/img/vmchop.png');
$company = function_exists('get_company') ? get_company() : ['name' => 'VISION MIX SDN BHD', 'reg_no' => '1622729-U', 'address' => ['LOT 3A-02A, 4TH FLOOR ENDAH PARADE,', 'NO.1 JALAN 1/149E, BANDAR BARU SRI PETALING,', '57000 KUALA LUMPUR'], 'phone' => '', 'email' => ''];
$companyName = (string)($company['name'] ?? '');
$companyRegNo = (string)($company['reg_no'] ?? '');
$companyTaxNo = (string)($company['tax_no'] ?? '');
$companyHeaderLine = trim($companyName . ($companyTaxNo !== '' ? (' ' . $companyTaxNo) : '') . ($companyRegNo !== '' ? (' (' . $companyRegNo . ')') : ''));
$companyAddress = (array)($company['address'] ?? []);
$companyPhone = (string)($company['phone'] ?? '');
$companyEmail = (string)($company['email'] ?? '');
$preferredBanks = [];
try {
  $stBank = $pdo->query("SELECT id, bank_code, account_name, account_no, currency FROM company_bank_accounts WHERE is_active = 1 ORDER BY id ASC");
  $allBanks = $stBank->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $pick = [];
  foreach ($allBanks as $b) {
    $code = strtoupper(trim((string)($b['bank_code'] ?? '')));
    if ($code === 'CIMB') $pick[] = $b;
  }
  foreach ($allBanks as $b) {
    $code = strtoupper(trim((string)($b['bank_code'] ?? '')));
    if ($code === 'HONG LEONG BANK') $pick[] = $b;
  }
  if (!$pick) $pick = $allBanks;
  $preferredBanks = array_slice($pick, 0, 2);
} catch (Throwable $e) {
  $preferredBanks = [];
}

$page_title = 'Quotation · ' . $customerName;
include __DIR__ . '/../include/header.php';
?>
<style>
.quotation-print-wrap { max-width:800px; margin:0 auto; padding:20px; }
.quotation-print-wrap table { width:100%; border-collapse:collapse; }
.quotation-print-wrap th, .quotation-print-wrap td { border:0; padding:8px; text-align:left; }
.quotation-print-wrap table.table { border:1px solid #333; }
.quotation-print-wrap table.table thead th { border-bottom:1px solid #333; }
.quotation-print-wrap table.table td, .quotation-print-wrap table.table th { border-right:1px solid #333; }
.quotation-print-wrap table.table td:last-child, .quotation-print-wrap table.table th:last-child { border-right:0; }
.quotation-print-wrap table.table tbody td { border-top:0; } /* 去掉行内横线 */
.quotation-print-wrap th { background:#eee; }
.quotation-print-wrap .col-no { width:50px; }
.quotation-print-wrap .col-desc { min-width:200px; }
.quotation-print-wrap .col-qty { width:90px; }
.quotation-print-wrap .col-unit { width:110px; }
.quotation-print-wrap .col-amount { width:120px; text-align:right; }
.quotation-print-wrap .text-right { text-align:right; }
.quotation-print-wrap th.col-qty, .quotation-print-wrap td.col-qty { text-align:center; }
.quotation-print-wrap th.col-unit, .quotation-print-wrap td.col-unit { text-align:center; }
.doc-title-print { font-size:20px; font-weight:700; margin-bottom:12px; }
.no-print { margin-bottom:16px; }
.print-sign-row { width:100%; margin-top:24px; border-collapse:collapse; }
.print-sign-row td { vertical-align:top; padding:8px 12px 0 0; }
.print-chop { max-height:55px; opacity:0.95; }
@media print {
  .no-print { display:none !important; }
  .quotation-print-wrap { padding:0; }
}
</style>

<div class="no-print">
  <a href="<?= h(url('admin/customers/quotation_edit.php?id=' . $id . '&customer_id=' . $cid)) ?>" class="btn btn-light">← Back to Quotation</a>
  <button type="button" class="btn btn-primary" onclick="window.print();">Print / Save as PDF</button>
</div>

<div class="quotation-print-wrap">
  <table style="width:100%; margin-bottom:16px;">
    <tr>
      <td style="width:25%; vertical-align:top;"><img src="<?= h($logoUrl) ?>" alt="Logo" style="max-height:55px;"></td>
      <td style="width:45%; font-size:12px; line-height:1.4;">
        <div style="font-size:14px; font-weight:bold;"><?= h($companyHeaderLine) ?></div>
        <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
        <div><?= h($line) ?></div>
        <?php endforeach; ?>
        <?php if ($companyPhone !== ''): ?>
        <div style="margin-top:4px;"><strong>Tel:</strong> <?= h($companyPhone) ?></div>
        <?php endif; ?>
        <?php if ($companyEmail !== ''): ?>
        <div><strong>Email:</strong> <?= h($companyEmail) ?></div>
        <?php endif; ?>
      </td>
      <td style="width:30%;"></td>
    </tr>
  </table>
  <div style="display:flex; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
    <div>
      <div style="font-weight:600; margin-bottom:4px;">To</div>
      <div><?= h($customerName) ?></div>
      <?php if (!empty($customerAddr)): ?>
        <div style="font-weight:600; margin:8px 0 4px;">Address</div>
        <div><?= h(implode(', ', $customerAddr)) ?></div>
      <?php endif; ?>
      <?php if ($customerTel !== ''): ?>
        <div style="font-weight:600; margin:8px 0 4px;">Tel</div>
        <div><?= h($customerTel) ?></div>
      <?php endif; ?>
      <?php if ($customerAttn !== ''): ?>
        <div style="font-weight:600; margin:8px 0 4px;">Attn.</div>
        <div><?= h($customerAttn) ?></div>
      <?php endif; ?>
    </div>
    <div>
      <div class="doc-title-print">QUOTATION</div>
      <div><strong>Date:</strong> <?= h($txn['txn_date'] ?? date('Y-m-d')) ?></div>
      <?php if ($hasDoNumber && !empty(trim((string)($txn['do_number'] ?? '')))): ?>
        <div><strong>DO Number:</strong> <?= h($txn['do_number']) ?></div>
      <?php endif; ?>
      <?php if ($hasDeliverTo && !empty(trim((string)($txn['deliver_to'] ?? '')))): ?>
        <div><strong>Deliver To:</strong> <?= h($txn['deliver_to']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div style="margin-bottom:12px;"><strong>Title:</strong> <?= h($txn['title'] ?? 'Quotation') ?></div>

  <table class="table">
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
          [$unitLabel, $descShown] = parse_unit_marker($desc);
          $qty = (float)($line['quantity'] ?? 1);
          $unit = (float)($line['unit_price'] ?? 0);
          $amt = (float)($line['amount'] ?? 0);
      ?>
      <tr>
        <td><?= (int)$no ?></td>
        <td style="white-space:pre-wrap;"><?= h($descShown) ?></td>
        <td class="col-qty"><?= h($qty) ?></td>
        <td class="col-unit">
          <?php if ($unitLabel !== '' && abs($unit) < 0.0001): ?>
            <?= h($unitLabel) ?>
          <?php elseif (abs($unit) < 0.0001): ?>
            &nbsp;
          <?php else: ?>
            <?= number_format($unit, 2) ?>
          <?php endif; ?>
        </td>
        <td class="text-right"><?= abs($amt) < 0.0001 ? '&nbsp;' : number_format($amt, 2) ?></td>
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
        <td class="text-right"><?= number_format($tot, 2) ?></td>
        <td class="text-right"><?= number_format($tot, 2) ?></td>
      </tr>
      <?php } ?>
    </tbody>
  </table>

  <div style="max-width:320px; margin-left:auto; margin-top:12px;">
    <?php if ($hasDiscount && $discount > 0): ?>
      <div><strong>Discount:</strong> <?= number_format($discount, 2) ?></div>
    <?php endif; ?>
    <div style="font-size:16px; font-weight:700; margin-top:8px;">Total Amount: <?= number_format($grand, 2) ?></div>
  </div>

  <?php if ($hasTerms && !empty(trim((string)($txn['terms'] ?? '')))): ?>
    <div style="margin-top:16px;">
      <strong>Terms</strong>
      <div style="white-space:pre-wrap;"><?= h($txn['terms']) ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty(trim((string)($txn['notes'] ?? '')))): ?>
    <div style="margin-top:12px;">
      <strong>Notes</strong>
      <div style="white-space:pre-wrap;"><?= h($txn['notes']) ?></div>
    </div>
  <?php endif; ?>

  <div style="margin-top:20px; font-size:12px; color:#374151;">
    <p style="margin:0 0 6px;">All sales, delivery and payment are subject to the terms and conditions of VISION MIX SDN. BHD. Additional copies available on request.</p>
    <p style="margin:0 0 6px;">Submission of order for products/services constitutes acceptance of these terms and conditions.</p>
    <p style="margin:0 0 12px;">All overdue accounts will be subject to an additional charge of 1.5% per month. Payment to be made to VISION MIX SDN. BHD.</p>
  </div>

  <?php if ($preferredBanks): ?>
  <div style="margin-top:12px;">
    <strong>PREFERRED BANK</strong>
    <?php foreach ($preferredBanks as $b): ?>
      <div style="margin-top:4px;">
        <span class="label">BANK:</span> <?= h($b['bank_code'] ?? '') ?> &nbsp;|&nbsp;
        <span class="label">ACCOUNT NAME:</span> <?= h($b['account_name'] ?? '') ?> &nbsp;|&nbsp;
        <span class="label">ACCOUNT NO.:</span> <?= h($b['account_no'] ?? '') ?>
      </div>
    <?php endforeach; ?>
    <div style="margin-top:4px;">
      <span class="label">BANK:</span> HONG LEONG BANK &nbsp;|&nbsp;
      <span class="label">ACCOUNT NAME:</span> VISION MIX SDN BHD &nbsp;|&nbsp;
      <span class="label">ACCOUNT NO.:</span> 19400128208
    </div>
  </div>
  <?php endif; ?>

  <table class="print-sign-row">
    <tr>
      <td style="width:50%;">
        <div style="margin-bottom:4px;">DATE:</div>
        <div style="border-bottom:1px solid #000; min-height:22px; margin-bottom:12px;"></div>
        <div style="font-weight:bold; margin-bottom:4px;">RECEIVED BY AND COMPANY STAMP:</div>
        <div style="border:1px solid #ccc; min-height:70px;"></div>
      </td>
      <td style="width:50%; text-align:right;">
        <div style="font-weight:bold; margin-bottom:4px;"><?= h($companyName) ?></div>
        <div style="position:relative; min-height:70px;">
          <?php if ($chopUrl): ?>
            <img src="<?= h($chopUrl) ?>" class="print-chop" alt="Company Chop" style="position:absolute; right:0; bottom:0;">
          <?php endif; ?>
        </div>
        <div style="margin-top:4px; border-top:1px solid #000; padding-top:2px; font-size:12px;">
          <?= $signReceive ? "Company's Stamp &amp; Signature" : "Company's Stamp" ?>
        </div>
      </td>
    </tr>
  </table>
</div>
