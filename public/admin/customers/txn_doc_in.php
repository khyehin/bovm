<?php
// public/admin/customers/txn_doc_in.php — Unified INVOICE / QUOTATION / DO document (same format as PDF: INVOICE & DO)
// Quotation = same layout as Invoice, only title "QUOTATION"; Invoice = same; DO = delivery order layout below.
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

// 默认只允许后台管理员访问；如果从用户门户复用本文件，
// 会在入口文件里定义 ALLOW_TXN_DOC_FROM_PORTAL = true 来跳过 require_admin。
$allowFromPortal = defined('ALLOW_TXN_DOC_FROM_PORTAL') && ALLOW_TXN_DOC_FROM_PORTAL === true;
// 顾客端文档：只允许 Customer 一边签名
$portalCustomerOnly = defined('TXN_DOC_PORTAL_CUSTOMER_ONLY') && TXN_DOC_PORTAL_CUSTOMER_ONLY === true;

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
  function h(mixed $v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function parse_unit_marker(string $desc): array
{
  $desc = (string)$desc;
  if (preg_match('/^\[\[UNIT:(.*?)\]\]\s*(\r\n|\r|\n)?/u', $desc, $m)) {
    $unitLabel = trim((string)($m[1] ?? ''));
    $rest = substr($desc, strlen((string)$m[0]));
    return [$unitLabel, $rest];
  }
  return ['', $desc];
}

function table_columns(PDO $pdo, string $table): array
{
  static $cache = [];
  $k = strtolower($table);
  if (isset($cache[$k])) return $cache[$k];
  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $cols[strtolower((string)($r['Field'] ?? ''))] = true;
    }
  } catch (Throwable $e) {
  }
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

// per-doc signature columns（如果有的话就用各自的字段）
$hasQuoteCustSig = isset($txnCols['quotation_customer_signature_image']);
$hasQuoteCompSig = isset($txnCols['quotation_company_signature_image']);
$hasInvCustSig   = isset($txnCols['invoice_customer_signature_image']);
$hasInvCompSig   = isset($txnCols['invoice_company_signature_image']);
$hasDoCustSig    = isset($txnCols['do_customer_signature_image']);
$hasDoCompSig    = isset($txnCols['do_company_signature_image']);

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

// 是否已开始收款：一旦有收款记录，就不再允许从文档页进入编辑
$hasPaymentStarted = false;
try {
  $stPayChk = $pdo->prepare("SELECT 1 FROM customer_txn_payments WHERE customer_txn_id = :tid LIMIT 1");
  $stPayChk->execute([':tid' => $id]);
  $hasPaymentStarted = (bool)$stPayChk->fetchColumn();
} catch (Throwable $e) {
  $hasPaymentStarted = false;
}

// 未 process 成 invoice 时：打 path 也进不去 Invoice/DO，强制只能看 QUOTATION
$docFlowStatus = strtoupper(trim((string)($txn['doc_flow_status'] ?? '')));
$docFlowType   = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
$hasInvoiceNo  = trim((string)($txn['invoice_no'] ?? '')) !== '';
if ($docFlowStatus === 'REJECTED' || $docFlowType === 'QUOTATION' || !$hasInvoiceNo) {
  $doc = 'QUOTATION';
  $_GET['doc'] = 'QUOTATION';
}

$lines = [];
try {
  $pdo->query("SELECT 1 FROM customer_txn_lines LIMIT 1");
  $st = $pdo->prepare("SELECT * FROM customer_txn_lines WHERE customer_txn_id = :tid ORDER BY line_seq ASC, id ASC");
  $st->execute([':tid' => $id]);
  $lines = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

$customerName = (string)($customer['name'] ?? '');
// 如果单据已经 COMPLETED，则使用“完成时快照地址”，避免之后门店地址被修改导致打印内容变化
$hasAddrSnap1   = isset($txnCols['customer_addr1_snapshot']);
$hasAddrSnap2   = isset($txnCols['customer_addr2_snapshot']);
$hasAddrSnap3   = isset($txnCols['customer_addr3_snapshot']);
$hasAddrSnapMeta = isset($txnCols['customer_addr_city_state_postcode_snapshot']);

$snapCustomerAddr = [];
if ($docFlowStatus === 'COMPLETED' && ($hasAddrSnap1 || $hasAddrSnap2 || $hasAddrSnap3 || $hasAddrSnapMeta)) {
  $snapCustomerAddr = array_filter([
    $hasAddrSnap1 ? (string)($txn['customer_addr1_snapshot'] ?? '') : '',
    $hasAddrSnap2 ? (string)($txn['customer_addr2_snapshot'] ?? '') : '',
    $hasAddrSnap3 ? (string)($txn['customer_addr3_snapshot'] ?? '') : '',
    $hasAddrSnapMeta ? (string)($txn['customer_addr_city_state_postcode_snapshot'] ?? '') : '',
  ]);
}

$customerAddr = $snapCustomerAddr;
if (empty($customerAddr)) {
  $customerAddr = array_filter([
    (string)($customer['address1'] ?? ''),
    (string)($customer['address2'] ?? ''),
    (string)($customer['address3'] ?? ''),
    trim(implode(' ', array_filter([$customer['city'] ?? '', $customer['state'] ?? '', $customer['postcode'] ?? '']))),
  ]);
}
$customerTel = (string)($customer['contact_phone'] ?? '');
$customerAttn = (string)($customer['contact_name'] ?? '');

$order_total = (float)($txn['order_total'] ?? 0);
$discount = $hasDiscount ? (float)($txn['discount'] ?? 0) : 0;

// 如果 DB 没有 discount 字段：用「行合计 - order_total」推算折扣（让前端输入的 discount 不会显示回 0）
if (!$hasDiscount) {
  $lineSum = 0.0;
  if (!empty($lines)) {
    foreach ($lines as $ln) {
      $a = (float)($ln['amount'] ?? 0);
      if (abs($a) < 0.0001) {
        $q = (float)($ln['quantity'] ?? 0);
        $u = (float)($ln['unit_price'] ?? 0);
        $a = $q * $u;
      }
      if ($a > 0) $lineSum += $a;
    }
  }
  $discount = max(0, $lineSum - $order_total);
}
// 注意：当 DB 没有 discount 字段时，order_total 通常已经是“折后总额”，discount 只是为了显示
$grand = $hasDiscount ? max(0, $order_total - $discount) : max(0, $order_total);

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
  // fallback for older DB schema（没有 per-doc 字段时才用旧的 sign_payer）
  $needCustomerSign = !empty($txn['sign_payer']);
}

// 为了在下面使用，先计算当前 doc 对应的客户签名字段（兼容 per-doc 字段）
$customerSigFieldForFlag = 'signature_image';
if ($doc === 'QUOTATION' && $hasQuoteCustSig) {
  $customerSigFieldForFlag = 'quotation_customer_signature_image';
} elseif ($doc === 'INVOICE' && $hasInvCustSig) {
  $customerSigFieldForFlag = 'invoice_customer_signature_image';
} elseif ($doc === 'DO' && $hasDoCustSig) {
  $customerSigFieldForFlag = 'do_customer_signature_image';
}
$dbCustomerSigForFlag = (string)($txn[$customerSigFieldForFlag] ?? '');

// 顾客端文档：
// - 新 schema（有 require_sign_quotation/invoice/do 字段）时，只按 per-doc「是否需要签名」决定
// - 旧 schema（没有 per-doc 字段）时，为了兼容旧数据，若还没签名则总是显示签名格
$hasPerDocRequireFlags = $hasReqSignQuotation || $hasReqSignInvoice || $hasReqSignDo;
if ($portalCustomerOnly && $dbCustomerSigForFlag === '' && !$hasPerDocRequireFlags) {
  $needCustomerSign = true;
}
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
$preferredBanks = [[
  'bank_code' => 'HONG LEONG BANK',
  'account_name' => 'VISION MIX SDN BHD',
  'account_no' => '19400128208',
]];

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
// 文档页抬头只显示单据类型（INVOICE / QUOTATION / DELIVERY ORDER）
$docTitle = $doc;
if ($doc === 'DO') $docTitle = 'DELIVERY ORDER';
$doNumberVal = ($doc === 'DO' && $docNo !== '') ? $docNo : trim((string)($txn['do_number'] ?? ''));
$termsVal = trim((string)($txn['terms'] ?? ''));
$deliverToVal = trim((string)($txn['deliver_to'] ?? ''));

// 根据当前 doc 选择签名字段（如果有 per-doc 字段就优先用）
$customerSigField = 'signature_image';
$companySigField  = 'payer_signature_image';
if ($doc === 'QUOTATION') {
  if ($hasQuoteCustSig) $customerSigField = 'quotation_customer_signature_image';
  if ($hasQuoteCompSig) $companySigField  = 'quotation_company_signature_image';
} elseif ($doc === 'INVOICE') {
  if ($hasInvCustSig) $customerSigField = 'invoice_customer_signature_image';
  if ($hasInvCompSig) $companySigField  = 'invoice_company_signature_image';
} elseif ($doc === 'DO') {
  if ($hasDoCustSig) $customerSigField = 'do_customer_signature_image';
  if ($hasDoCompSig) $companySigField  = 'do_company_signature_image';
}

// DB 中已有的单据签名（客户 / 公司），用于初始化画板显示
$dbCustomerSig = (string)($txn[$customerSigField] ?? '');
$dbCompanySig  = (string)($txn[$companySigField] ?? '');

// 已签名日期（用于初次渲染时填入 DATE 文本）——必须真正「签过名」才显示
$customerSignDateRaw = (string)($txn['recipient_signed_at'] ?? '');
$companySignDateRaw  = (string)($txn['payer_signed_at'] ?? '');
function fmt_sign_date(?string $dt): string
{
  $dt = (string)($dt ?? '');
  if ($dt === '') return '';
  $ts = strtotime($dt);
  if ($ts === false) return $dt;
  return date('d/m/Y', $ts);
}
$customerSignDate = fmt_sign_date($customerSignDateRaw);
$companySignDate  = fmt_sign_date($companySignDateRaw);

// 如果还没有对应签名图片，则不显示日期（避免一生成单据就有日期）
if ($dbCustomerSig === '') {
  $customerSignDate = '';
}
if ($dbCompanySig === '') {
  $companySignDate = '';
}

$page_title = $docTitle . ' · ' . $customerName;
if (!$allowFromPortal) {
  include __DIR__ . '/../include/header.php';
}
?>
<style>
  .doc-print-wrap {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
    font-size: 13px;
    color: #111827;
  }

  .doc-print-wrap table {
    width: 100%;
    border-collapse: collapse;
  }

  /* 只保留物品表格线；其余区块（抬头/客户资料/签名）不显示外框格子 */
  .doc-table-items {
    width: 100%;
    border-collapse: collapse;
  }

  .doc-table-items th,
  .doc-table-items td {
    border: 0;
    padding: 6px 8px;
    text-align: left;
  }

  .doc-table-items {
    border: 1px solid #333;
  }

  .doc-table-items thead th {
    border-bottom: 1px solid #333;
  }

  .doc-table-items td,
  .doc-table-items th {
    border-right: 1px solid #333;
  }

  .doc-table-items td:last-child,
  .doc-table-items th:last-child {
    border-right: 0;
  }

  .doc-table-items tbody td {
    border-top: 0;
  }

  /* 去掉行内横线 */
  .doc-table-items th {
    background: #f3f4f6;
  }

  .doc-row-to-meta,
  .doc-row-to-meta td,
  .doc-sign-row,
  .doc-sign-row td {
    border: 0 !important;
  }

  .doc-top-center {
    text-align: center;
    margin-bottom: 18px;
  }

  .doc-top-center .doc-top-inner {
    display: inline-block;
    text-align: left;
    max-width: 100%;
  }

  .doc-top-logo {
    vertical-align: top;
    padding-right: 12px;
  }

  .doc-top-logo img {
    max-height: 55px;
  }

  .doc-top-company {
    font-size: 11px;
    line-height: 1.45;
    vertical-align: top;
  }

  .doc-top-company .doc-company-name {
    font-size: 14px;
    font-weight: bold;
    text-transform: uppercase;
  }

  .doc-row-to-meta {
    width: 100%;
    margin-bottom: 14px;
  }

  .doc-row-to-meta td {
    vertical-align: top;
    padding: 0 12px 0 0;
  }

  .doc-to-block {
    width: 50%;
  }

  .doc-meta-block {
    width: 50%;
    text-align: right;
  }

  .doc-meta-block .doc-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 8px;
  }

  .doc-meta-block .label {
    font-weight: bold;
  }

  .doc-line {
    border-top: 1px solid #000;
    margin: 10px 0;
  }

  .doc-table-items th.col-no {
    width: 50px;
  }

  .doc-table-items th.col-desc {
    min-width: 200px;
  }

  .doc-table-items th.col-qty {
    width: 80px;
  }

  .doc-table-items th.col-unit {
    width: 100px;
  }

  .doc-table-items th.col-amount {
    width: 110px;
    text-align: right;
  }

  .doc-table-items td.text-right {
    text-align: right;
  }

  .doc-table-items th.col-qty,
  .doc-table-items td.col-qty {
    text-align: center;
  }

  .doc-table-items th.col-unit,
  .doc-table-items td.col-unit {
    text-align: center;
  }

  .doc-sign-row {
    width: 100%;
    margin-top: 22px;
  }

  .doc-sign-row td {
    vertical-align: top;
    padding: 8px 12px 0 0;
    width: 50%;
  }

  .doc-sig-area {
    position: relative;
    min-height: 70px;
    overflow: hidden;
  }

  .doc-chop {
    position: absolute;
    right: 4px;
    bottom: 3px;
    max-height: 55px;
    opacity: 0.95;
  }

  .doc-sign-flex {
    display: flex;
    gap: 24px;
    margin-top: 22px;
    align-items: flex-end;
  }

  .doc-sign-col {
    flex: 1;
  }

  .doc-sign-col.right {
    text-align: right;
  }

  .doc-underline {
    border-top: 1px solid #000;
    height: 0;
    margin-top: 6px;
    width: 220px;
    max-width: 100%;
  }

  .doc-sign-col.right .doc-underline {
    margin-left: auto;
  }

  .doc-sign-label {
    font-weight: bold;
    font-size: 12px;
  }

  .doc-sign-date-label {
    font-size: 12px;
    margin-top: 10px;
  }

  .doc-sign-top-spacer {
    height: 18px;
  }

  .doc-sign-box {
    border: 1px solid #000;
    min-height: 70px;
  }

  .sigpad-wrap {
    border: 1px solid #000;
    width: 260px;
    max-width: 100%;
    height: 90px;
    background: #fff;
  }

  .sigpad-canvas {
    width: 100%;
    height: 100%;
    display: block;
  }

  .sigpad-actions {
    margin-top: 6px;
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .sigpad-actions .btn {
    padding: 4px 10px;
    font-size: 12px;
  }

  .sigimg {
    width: 260px;
    max-width: 100%;
    height: 90px;
    object-fit: contain;
    display: block;
  }

  #sigpadSigned .sigimg,
  #sigpadCompanySigned .sigimg {
    height: 90px;
  }

  .sigbox {
    width: 260px;
    max-width: 100%;
    height: 90px;
    position: relative;
  }

  #sigpadSigned,
  #sigpadCompanySigned {
    width: 260px;
    max-width: 100%;
    height: 90px;
  }

  #sigpadCompanyInput .sigpad-wrap {
    margin-left: auto;
  }

  #companySignCol .doc-sig-area {
    position: relative;
  }

  #sigpadCompanySigned {
    position: absolute;
    right: 0;
    bottom: 0;
    z-index: 1;
  }

  #companySignCol .doc-chop {
    z-index: 2;
  }

  .doc-chop {
    pointer-events: none;
  }

  .sigdate {
    font-weight: 600;
    font-size: 12px;
  }

  .sig-controls {
    margin-top: 10px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }

  .sig-controls label {
    display: flex;
    gap: 6px;
    align-items: center;
    font-size: 12px;
  }

  .sig-controls select {
    font-size: 12px;
    padding: 3px 6px;
  }

  .no-print {
    margin-bottom: 16px;
  }

  .print-only {
    display: none;
  }

  .print-header .h-row {
    display: flex;
    gap: 12px;
    align-items: flex-start;
  }

  .print-header .h-logo img {
    max-height: 55px;
  }

  .print-header .h-company {
    font-size: 11px;
    line-height: 1.45;
  }

  .print-header .h-company .name {
    font-size: 14px;
    font-weight: 800;
    text-transform: uppercase;
  }

  .print-header .h-meta {
    margin-left: auto;
    text-align: right;
    font-size: 12px;
    min-width: 220px;
  }

  .print-header .h-meta .title {
    font-size: 18px;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .print-header .h-to {
    margin-top: 10px;
    display: flex;
    justify-content: space-between;
    gap: 18px;
  }

  .print-header .h-to .block {
    font-size: 12px;
    line-height: 1.55;
  }

  .print-header .lbl {
    font-weight: 800;
  }

  .print-footer {
    font-size: 11px;
    color: #374151;
    line-height: 1.55;
  }

  .print-footer .banks {
    margin-top: 8px;
  }

  .print-footer .banks .row {
    margin-top: 2px;
  }

  .print-footer .sig {
    margin-top: 10px;
    text-align: right;
    color: #111827;
    font-weight: 800;
  }

  .print-footer .sig-line {
    margin-top: 18px;
    border-top: 1px solid #111827;
    width: 220px;
    margin-left: auto;
  }

  .print-footer .sig-cap {
    font-size: 11px;
    font-weight: 700;
    margin-top: 4px;
    text-align: right;
    color: #111827;
  }

  @media print {

    /* 强制 A4：所有页面都固定在同一张 A4 内 */
    @page {
      size: A4 portrait;
      margin: 0;
    }

    body {
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }

    html,
    body {
      margin: 0;
      padding: 0;
      /* 不强制 html/body 固定为 297mm，避免在部分 portal 外壳场景出现额外空白页 */
      width: auto !important;
      height: auto !important;
      min-height: 0 !important;
    }

    .no-print {
      display: none !important;
    }

    .sigpad-actions {
      display: none !important;
    }

    #sigpadInput,
    #sigpadCompanyInput {
      display: none !important;
    }

    .sigpad-wrap {
      border: 0 !important;
    }

    .sigimg {
      border: 0 !important;
    }

    .doc-print-wrap button,
    .doc-print-wrap select,
    .doc-print-wrap input {
      display: none !important;
    }

    /* 默认仍允许打印原始单据；当分页版准备好后再切换到分页版 */
    body * {
      visibility: hidden;
    }

    .doc-print-wrap,
    .doc-print-wrap * {
      visibility: visible;
    }

    #printPages,
    #printPages * {
      visibility: hidden;
    }

    #printPages {
      position: absolute;
      left: 0;
      top: 0;
      width: 100%;
    }

    body.vm-print-ready .doc-print-wrap {
      /* 仅用 visibility:hidden 仍会占据版面高度，可能导致多出来的“空白页” */
      display: none !important;
    }
    body.vm-print-ready .doc-print-wrap * {
      display: none !important;
    }

    body.vm-print-ready #printPages,
    body.vm-print-ready #printPages * {
      visibility: visible;
    }

    /* portal/company1 外层壳会占据打印高度，可能导致多出空白页；
       vm-print-ready 时只保留分页容器 */
    body.vm-print-ready>*:not(#printPages) {
      display: none !important;
    }
    body.vm-print-ready #printPages {
      display: block !important;
      position: fixed !important;
      left: 0 !important;
      top: 0 !important;
      width: 100% !important;
    }

    /* 关闭固定页眉页脚方案 */
    .print-only {
      display: none !important;
    }

    /* 避免底部信息被拆到下一页只剩“尾巴” */
    .doc-bottom-terms,
    .doc-bottom-banks,
    .doc-sign-flex,
    .doc-sign-row {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    /* 让银行区块两行尽量保持在同一页 */
    .doc-bottom-banks>div {
      break-inside: avoid;
      page-break-inside: avoid;
    }

    /* 打印时稍微压缩底部间距，减少提前分页 */
    .doc-bottom-terms {
      margin-top: 12px !important;
    }

    .doc-bottom-banks {
      margin-top: 8px !important;
    }

    .doc-sign-flex {
      margin-top: 14px !important;
    }

    /* 打印分页版：每页 header/footer 固定，中间区域自动塞 items */
    #printPages {
      display: block !important;
    }

    /* 让分页版宽度跟原本单据一致（避免右边被裁切/标题被截断） */
    #printPages {
      left: 0;
      top: 0;
    }

    .print-page {
      --page-pad: 25.4mm;
      /* 1 inch all around */
      width: 210mm;
      height: 297mm;
      /* 居中整张 A4，避免 admin 端靠左贴边 */
      margin: 0 auto;
      /* 用绝对定位布局，不用 padding，避免 header/footer 绕过留白 */
      padding: 0;
      box-sizing: border-box;
    }

    .print-page table {
      width: 100%;
      border-collapse: collapse;
    }

    .print-page .doc-table-items {
      width: 100%;
      table-layout: fixed;
    }

    .print-page .doc-table-items th.col-desc {
      width: auto;
    }

    .print-page .doc-table-items td,
    .print-page .doc-table-items th {
      word-wrap: break-word;
    }

    /* 目标：1.5inch 边距下尽量多放表格内容 */
    .print-page .doc-table-items th,
    .print-page .doc-table-items td {
      padding: 2px 4px !important;
      line-height: 1.2 !important;
    }

    .print-page .doc-table-items th {
      font-size: 10.5px !important;
      line-height: 1.2 !important;
    }

    .print-page .doc-table-items td {
      font-size: 10.5px !important;
      line-height: 1.2 !important;
    }

    .print-page .doc-table-items tbody tr {
      height: 16px !important;
    }

    /* 非最后一页把表格拉到接近 footer，避免大空白 */
    .print-page .doc-table-items.doc-table-fill {
      height: calc(100% - 0.5in) !important;
    }

    /* 大幅压缩 header/footer，把空间让给表格行数 */
    .print-page-header .doc-top-logo img {
      max-height: 40px !important;
    }

    .print-page-header .doc-top-company {
      font-size: 9px !important;
      line-height: 1.2 !important;
    }

    .print-page-header .doc-top-company .doc-company-name {
      font-size: 11px !important;
      line-height: 1.1 !important;
    }

    .print-page-header .doc-meta-block .doc-title {
      font-size: 14px !important;
      margin-bottom: 3px !important;
    }

    .print-page-header .doc-row-to-meta td {
      font-size: 9.5px !important;
      line-height: 1.2 !important;
    }

    .print-page-footer {
      font-size: 7.6px !important;
      line-height: 1.1 !important;
    }

    .print-page-footer p {
      margin: 0 0 1px !important;
    }

    .print-page-footer .doc-bottom-terms {
      margin-top: 1px !important;
    }

    .print-page-footer .doc-bottom-banks {
      margin-top: 5px !important;
    }

    .print-page-footer .doc-bottom-banks strong {
      font-size: 9px !important;
      letter-spacing: 0.2px;
    }

    .print-page-footer .doc-sign-flex {
      margin-top: 1px !important;
    }

    .print-page-footer .doc-sign-top-spacer {
      height: 0 !important;
    }

    .print-page-footer .sigimg {
      height: 34px !important;
    }

    .print-page-footer .sigpad-wrap {
      height: 34px !important;
    }

    .print-page-footer .doc-chop {
      max-height: 22px !important;
    }

    .print-page-footer .doc-underline {
      margin-top: 1px !important;
    }

    .print-page-footer .doc-sign-date-label {
      margin-top: 1px !important;
      font-size: 8px !important;
    }

    .print-page-footer .doc-sign-label {
      font-size: 8px !important;
    }

    .print-page-header .doc-top-center {
      margin-bottom: 2px !important;
    }

    .print-page-header .doc-row-to-meta {
      margin-bottom: 2px !important;
    }

    .print-page-header .doc-line {
      margin: 2px 0 !important;
    }

    .print-page-header .doc-content-title {
      margin-bottom: 2px !important;
      font-size: 10px !important;
    }

    .print-page {
      position: relative;
      overflow: hidden;
      /* 关键：避免 footer(签名) 被推到下一页 */
    }

    /* 只在“页与页之间”断页，避免最后一页仍然强制断页导致多一张空白页 */
    .print-page:not(:last-of-type) {
      page-break-after: always;
      break-after: page;
    }

    #printPages.single-page .print-page {
      page-break-after: auto !important;
      break-after: auto !important;
    }

    .print-page-header {
      position: absolute;
      left: var(--page-pad);
      right: var(--page-pad);
      top: calc(var(--page-pad) + var(--hdrDrop, 0px));
    }

    .print-page-footer {
      position: absolute;
      left: var(--page-pad);
      right: var(--page-pad);
      bottom: calc(var(--page-pad) + var(--lift, 0px));
      margin-top: 0;
      padding-bottom: 2px;
    }

    .print-page-no {
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      /* 页码允许放在页面最下方中间，不占用内容区 */
      bottom: 4mm;
      font-size: 10px;
      line-height: 1;
      color: #6b7280;
      pointer-events: none;
    }

    .print-page-body {
      position: absolute;
      left: var(--page-pad);
      right: var(--page-pad);
      top: calc(var(--page-pad) + var(--ph, 0px));
      bottom: calc(var(--page-pad) + var(--pf, 0px));
      overflow: hidden;
      display: block;
      box-sizing: border-box;
    }

    .print-items-wrap {
      min-height: 0;
      overflow: hidden;
    }

    /* Totals 固定在最后一页 footer 上方 */
    .print-page-body {
      position: absolute;
    }

    .print-totals-wrap {
      position: absolute;
      left: 0;
      right: 0;
      bottom: var(--lift, 0px);
      margin: 0;
    }

    .print-items-wrap {
      position: absolute;
      left: 0;
      right: 0;
      top: 0;
      bottom: calc(var(--totalsH, 0px) + var(--lift, 0px));
      overflow: hidden;
    }
  }

  /* 打印分页构建模式：用于 beforeprint 期间稳定测量高度（避免 header 高度=0 导致重叠） */
  body.vm-print-build #printPages {
    display: block !important;
    position: absolute;
    left: -10000px;
    /* 屏幕上不打扰排版 */
    top: 0;
    width: 210mm;
  }

  body.vm-print-build #doc-print-area {
    display: none !important;
  }

  body.vm-print-build .print-page {
    --page-pad: 25.4mm;
    /* 1 inch all around */
    width: 210mm;
    height: 297mm;
    margin: 0 auto;
    padding: 0;
    box-sizing: border-box;
    position: relative;
    overflow: hidden;
  }

  body.vm-print-build .print-page-header {
    position: absolute;
    left: var(--page-pad);
    right: var(--page-pad);
    top: calc(var(--page-pad) + var(--hdrDrop, 0px));
  }

  body.vm-print-build .print-page-footer {
    position: absolute;
    left: var(--page-pad);
    right: var(--page-pad);
    bottom: calc(var(--page-pad) + var(--lift, 0px));
    padding-bottom: 1px;
    font-size: 7.6px !important;
    line-height: 1.1 !important;
  }

  body.vm-print-build .print-page-no {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    bottom: 4mm;
    font-size: 10px;
    line-height: 1;
    color: #6b7280;
  }

  body.vm-print-build .print-page-header .doc-top-logo img {
    max-height: 40px !important;
  }

  body.vm-print-build .print-page-header .doc-top-company {
    font-size: 9px !important;
    line-height: 1.2 !important;
  }

  body.vm-print-build .print-page-header .doc-top-company .doc-company-name {
    font-size: 11px !important;
    line-height: 1.1 !important;
  }

  body.vm-print-build .print-page-header .doc-meta-block .doc-title {
    font-size: 14px !important;
    margin-bottom: 3px !important;
  }

  body.vm-print-build .print-page-header .doc-row-to-meta td {
    font-size: 9.5px !important;
    line-height: 1.2 !important;
  }

  body.vm-print-build .print-page-header .doc-top-center {
    margin-bottom: 2px !important;
  }

  body.vm-print-build .print-page-header .doc-row-to-meta {
    margin-bottom: 2px !important;
  }

  body.vm-print-build .print-page-header .doc-line {
    margin: 2px 0 !important;
  }

  body.vm-print-build .print-page-header .doc-content-title {
    margin-bottom: 2px !important;
    font-size: 10px !important;
  }

  body.vm-print-build .print-page-footer .doc-sign-top-spacer {
    height: 0 !important;
  }

  body.vm-print-build .print-page-footer .doc-bottom-terms {
    margin-top: 1px !important;
  }

  body.vm-print-build .print-page-footer .doc-bottom-banks {
    margin-top: 5px !important;
  }

  body.vm-print-build .print-page-footer .doc-bottom-banks strong {
    font-size: 9px !important;
    letter-spacing: 0.2px;
  }

  body.vm-print-build .print-page-footer .doc-sign-flex {
    margin-top: 1px !important;
  }

  body.vm-print-build .print-page-footer .sigimg {
    height: 34px !important;
  }

  body.vm-print-build .print-page-footer .sigpad-wrap {
    height: 34px !important;
  }

  body.vm-print-build .print-page-footer .doc-chop {
    max-height: 22px !important;
  }

  body.vm-print-build .print-page-footer .doc-sign-date-label {
    margin-top: 1px !important;
    font-size: 8px !important;
  }

  body.vm-print-build .print-page-footer .doc-sign-label {
    font-size: 8px !important;
  }

  body.vm-print-build .print-page-footer .sigpad-actions {
    display: none !important;
  }

  body.vm-print-build .print-page-body {
    position: absolute;
    left: var(--page-pad);
    right: var(--page-pad);
    top: calc(var(--page-pad) + var(--ph, 0px));
    bottom: calc(var(--page-pad) + var(--pf, 0px));
    overflow: hidden;
    display: block;
  }

  body.vm-print-build .print-totals-wrap {
    position: absolute;
    left: 0;
    right: 0;
    bottom: var(--lift, 0px);
    margin: 0;
  }

  body.vm-print-build .print-items-wrap {
    position: absolute;
    left: 0;
    right: 0;
    top: 0;
    bottom: calc(var(--totalsH, 0px) + var(--lift, 0px));
    overflow: hidden;
  }

  body.vm-print-build .print-page .doc-table-items th,
  body.vm-print-build .print-page .doc-table-items td {
    padding: 2px 4px !important;
    line-height: 1.2 !important;
    font-size: 10.5px !important;
  }

  body.vm-print-build .print-page .doc-table-items tbody tr {
    height: 16px !important;
  }

  body.vm-print-build .print-page .doc-table-items.doc-table-fill {
    height: calc(100% - 0.5in) !important;
  }

  /* 打印微调：保持 table 与上方内容左右对齐 */
  @media print {
    .doc-table-items {
      width: 100% !important;
      margin: 0 !important;
    }

    .print-page .doc-table-items {
      width: 100% !important;
      margin: 0 !important;
    }
  }
</style>

<?php
// 为前端签名保存准备接口 URL（admin / Company1 / Customer）
$docSignCustomerUrl = null;
$docSignCompanyUrl  = null;
// 勾选「需要签名」的保存接口
$docRequireUrl = null;
if (!$allowFromPortal) {
  $docSignCustomerUrl = url('admin/customers/txn_doc_sign.php?id=' . $id . '&customer_id=' . $cid . '&side=customer&doc=' . $doc);
  $docSignCompanyUrl  = url('admin/customers/txn_doc_sign.php?id=' . $id . '&customer_id=' . $cid . '&side=company&doc=' . $doc);
  $docRequireUrl      = url('admin/customers/txn_doc_require.php?id=' . $id . '&customer_id=' . $cid . '&doc=' . $doc);
} elseif (defined('ALLOW_TXN_DOC_FROM_COMPANY1') && ALLOW_TXN_DOC_FROM_COMPANY1 === true) {
  $docSignCustomerUrl = url('user/company1/txn_doc_sign.php?id=' . $id . '&customer_id=' . $cid . '&side=customer&doc=' . $doc);
  $docSignCompanyUrl  = url('user/company1/txn_doc_sign.php?id=' . $id . '&customer_id=' . $cid . '&side=company&doc=' . $doc);
  $docRequireUrl      = url('user/company1/txn_doc_require.php?id=' . $id . '&customer_id=' . $cid . '&doc=' . $doc);
} elseif ($portalCustomerOnly) {
  // 顾客端文档：只允许 Customer 一边签名
  $docSignCustomerUrl = url('user/txn/txn_doc_sign.php?id=' . $id . '&customer_id=' . $cid . '&doc=' . $doc);
  $docSignCompanyUrl  = null;
  $docRequireUrl      = null;
}

$adminEditUrl = url('admin/customers/quotation_edit.php?id=' . $id . '&customer_id=' . $cid);
$company1EditUrl = url('user/company1/quotation_edit.php?id=' . $id . '&customer_id=' . $cid);
?>

<?php if (!$allowFromPortal): ?>
  <!-- Admin 后台：完整控制条（Back + Print + 签名控制），顾客看不到 -->
  <div class="no-print">
    <a href="<?= h(url('admin/customers/txn_edit_in.php?id=' . $id . '&customer_id=' . $cid)) ?>" class="btn btn-light">← Back</a>
    <button type="button" class="btn btn-primary" onclick="vmPrepareAndPrint();">Print / PDF</button>
    <?php if (!$hasPaymentStarted): ?>
      <a href="<?= h($adminEditUrl) ?>" class="btn btn-light">Edit Quotation</a>
    <?php endif; ?>
    <div class="sig-controls" style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <label><input type="checkbox" id="optNeedCustomer" <?= $needCustomerSign ? 'checked' : '' ?>>Customer signature</label>
      <label><input type="checkbox" id="optNeedCompany" <?= $needCompanySign ? 'checked' : '' ?>>Company signature</label>
      <button type="button" class="btn btn-sm btn-primary" id="btnSigOptsSave" style="padding:2px 10px;">
        Save signature request
      </button>
      <span id="sigOptsSavedText" style="font-size:11px;color:#16a34a;display:none;">Saved</span>
      <div style="margin-left:8px;">
        <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=QUOTATION')) ?>" class="btn btn-xs btn-light">Quotation</a>
        <?php
        $flowTypeAdmin = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
        $flowStatAdmin = strtoupper(trim((string)($txn['doc_flow_status'] ?? '')));
        $hasInvNoAdmin = trim((string)($txn['invoice_no'] ?? '')) !== '';
        $canViewInvoiceDoAdmin = ($flowStatAdmin !== 'REJECTED' && $hasInvNoAdmin && $flowTypeAdmin !== 'QUOTATION');
        ?>
        <?php if ($canViewInvoiceDoAdmin): ?>
          <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=INVOICE')) ?>" class="btn btn-xs btn-light">Invoice</a>
          <a href="<?= h(url('admin/customers/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=DO')) ?>" class="btn btn-xs btn-light">DO</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <?php if (defined('ALLOW_TXN_DOC_FROM_COMPANY1') && ALLOW_TXN_DOC_FROM_COMPANY1 === true): ?>
    <!-- Company1 入口：有 Back / Print / 签名控制 + Quotation·Invoice·DO（未进发票前隐藏 Invoice / DO） -->
    <div class="no-print" style="margin-bottom:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
      <?php
      $backUrlDoc = (string)($GLOBALS['_TXN_DOC_BACK_URL_FROM_PORTAL'] ?? url('user/company1/invoices.php?customer_id=' . $cid));
      ?>
      <a href="<?= h($backUrlDoc) ?>" class="btn btn-light btn-sm">← Back</a>
      <button type="button" class="btn btn-light btn-sm" onclick="vmPrepareAndPrint();">Print / PDF</button>
      <?php if (!$hasPaymentStarted): ?>
        <a href="<?= h($company1EditUrl) ?>" class="btn btn-light btn-sm">Edit Quotation</a>
      <?php endif; ?>
      <div class="sig-controls" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:12px;">
        <label style="display:flex;align-items:center;gap:4px;">
          <input type="checkbox" id="optNeedCustomer" <?= $needCustomerSign ? 'checked' : '' ?>>Customer signature
        </label>
        <label style="display:flex;align-items:center;gap:4px;">
          <input type="checkbox" id="optNeedCompany" <?= $needCompanySign ? 'checked' : '' ?>>Company signature
        </label>
        <button type="button" class="btn btn-xs btn-primary" id="btnSigOptsSave" style="padding:1px 8px;">
          Save signature request
        </button>
        <span id="sigOptsSavedText" style="font-size:11px;color:#16a34a;display:none;">Saved</span>
        <div style="margin-left:8px;">
          <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=QUOTATION')) ?>" class="btn btn-xs btn-light">Quotation</a>
          <?php
          // Company1：只有在已进发票流程（有 invoice_no 且 doc_flow_type != QUOTATION 且未 REJECT）时，才显示 Invoice / DO
          $flowTypeNav = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
          $flowStatNav = strtoupper(trim((string)($txn['doc_flow_status'] ?? '')));
          $hasInvNoNav = trim((string)($txn['invoice_no'] ?? '')) !== '';
          $canViewInvoiceDoNav = ($flowStatNav !== 'REJECTED' && $hasInvNoNav && $flowTypeNav !== 'QUOTATION');
          ?>
          <?php if ($canViewInvoiceDoNav): ?>
            <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=INVOICE')) ?>" class="btn btn-xs btn-light">Invoice</a>
            <a href="<?= h(url('user/company1/txn_doc_in.php?id=' . $id . '&customer_id=' . $cid . '&doc=DO')) ?>" class="btn btn-xs btn-light">DO</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <!-- 顾客 portal：外层 user/txn/txn_doc_in.php 已经有 Back / Print，这里就不重复显示任何按钮 -->
  <?php endif; ?>
<?php endif; ?>

<?php
$custEmail = trim((string)($customer['email'] ?? ''));
?>
<div class="print-only print-header">
  <div class="h-row">
    <div class="h-logo"><img src="<?= h($logoUrl) ?>" alt="Logo"></div>
    <div class="h-company">
      <div class="name"><?= h($companyHeaderLine) ?></div>
      <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
        <div><?= h($line) ?></div>
      <?php endforeach; ?>
      <?php if ($companyEmail !== '' || $companyPhone !== ''): ?>
        <div style="margin-top:4px;">
          <?php if ($companyEmail !== ''): ?><span class="lbl">Email :</span> <?= h($companyEmail) ?><?php endif; ?>
            <?php if ($companyPhone !== ''): ?> &nbsp; <span class="lbl">Hp :</span> <?= h($companyPhone) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="h-meta">
      <div class="title"><?= h($docTitle) ?></div>
      <div><span class="lbl">Date :</span> <?= h($docDateFormatted) ?></div>
      <?php if ($termsVal !== ''): ?>
        <div><span class="lbl">Terms :</span> <?= h($termsVal) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="h-to">
    <div class="block">
      <div><span class="lbl">To:</span> <?= h($customerName) ?></div>
      <?php if (!empty($customerAddr)): ?>
        <div><span class="lbl">Add.:</span> <?= h(implode(', ', $customerAddr)) ?></div>
      <?php endif; ?>
      <?php if ($customerTel !== ''): ?>
        <div><span class="lbl">Tel:</span> <?= h($customerTel) ?></div>
      <?php endif; ?>
      <?php if ($customerAttn !== ''): ?>
        <div><span class="lbl">Attn.:</span> <?= h($customerAttn) ?></div>
      <?php endif; ?>
      <?php if ($custEmail !== ''): ?>
        <div><span class="lbl">Email:</span> <?= h($custEmail) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div style="border-top:1px solid #111827; margin:10px 0 10px;"></div>
  <div style="font-size:12px;">
    <span class="lbl">Title:</span>
    <?php if ($doc === 'DO'): ?>
      <?= h($txn['title'] ?? 'Delivery Order') ?>
    <?php else: ?>
      <?= h($txn['title'] ?? ($doc === 'QUOTATION' ? 'Quotation' : 'Invoice')) ?>
    <?php endif; ?>
  </div>
</div>

<div class="print-only print-footer">
  <div>
    <div>All sales, delivery and payment are subject to the terms and conditions of VISION MIX SDN. BHD. Additional copies available on request.</div>
    <div>Submission of order for products/services constitutes acceptance of these terms and conditions.</div>
    <div>All overdue accounts will be subject to an additional charge of 1.5% per month. Payment to be made to VISION MIX SDN. BHD.</div>
  </div>


  <div style="display:flex; gap:18px; margin-top:10px; align-items:flex-end;">
    <?php if ($needCustomerSign): ?>
      <div style="flex:1;">
        <div style="height:44px; border:1px solid #111827; background:#fff; overflow:hidden;">
          <?php if ($dbCustomerSig !== ''): ?>
            <img src="<?= h($dbCustomerSig) ?>" alt="" style="height:44px; width:100%; object-fit:contain; display:block;">
          <?php endif; ?>
        </div>
        <div style="border-top:1px solid #111827; margin-top:6px; width:220px; max-width:100%;"></div>
        <div style="font-weight:800; font-size:10.5px; margin-top:6px;">RECEIVED BY AND COMPANY STAMP:</div>
        <div style="font-size:10.5px; margin-top:2px;">DATE: <span style="font-weight:700;"><?= h($customerSignDate) ?></span></div>
      </div>
    <?php endif; ?>

    <div style="flex:1; text-align:right;">
      <div style="font-weight:800; color:#111827;"><?= h($companyName) ?></div>
      <div style="height:44px; border:1px solid #111827; background:#fff; overflow:hidden; position:relative; margin-top:4px;">
        <?php if ($dbCompanySig !== ''): ?>
          <img src="<?= h($dbCompanySig) ?>" alt="" style="height:44px; width:100%; object-fit:contain; display:block;">
        <?php endif; ?>
        <?php if ($chopUrl && $showCompanyChop): ?>
          <img src="<?= h($chopUrl) ?>" alt="" style="position:absolute; right:4px; bottom:2px; height:34px; opacity:0.95;">
        <?php endif; ?>
      </div>
      <div style="border-top:1px solid #111827; margin-top:6px; width:220px; max-width:100%; margin-left:auto;"></div>
      <div style="font-size:10.5px; font-weight:800; margin-top:4px;">
        <?= ($needCompanySign ? "Company's Stamp &amp; Signature" : "Company's Stamp") ?>
        <span style="font-weight:700; margin-left:8px;"><?= h($companySignDate) ?></span>
      </div>
    </div>
  </div>

  <div class="print-page-num"></div>
</div>

<div id="doc-print-area" class="doc-print-wrap">
  <div class="doc-top-center">
    <div class="doc-top-inner">
      <table style="margin:0 auto; border:0;">
        <tr>
          <td class="doc-top-logo"><img src="<?= h($logoUrl) ?>" alt="Logo"></td>
          <td class="doc-top-company">
            <div class="doc-company-name"><?= h($companyHeaderLine) ?></div>
            <?php foreach ($companyAddress as $line): if (trim($line) === '') continue; ?>
              <div><?= h($line) ?></div>
            <?php endforeach; ?>
            <?php
            $printCompanyEmail = trim((string)($companyEmail ?? '')) ?: 'visionmix55@gmail.com';
            $printCompanyTel   = trim((string)($companyPhone ?? '')) ?: '+6012-3139 918';
            ?>
            <div style="margin-top:4px;">
              <div>Email : <?= h($printCompanyEmail) ?></div>
              <div>Tel : <?= h($printCompanyTel) ?></div>
            </div>
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
    <div class="doc-content-title" style="margin-bottom:12px;"><strong>Title:</strong> <?= h($txn['title'] ?? 'Delivery Order') ?></div>
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
              <td class="col-qty"><?= h($qty) ?></td>
            </tr>
          <?php
          }
        } else {
          $tit = (string)($txn['title'] ?? '');
          ?>
          <tr>
            <td>1</td>
            <td><?= h($tit ?: '—') ?></td>
            <td class="col-qty">1</td>
          </tr>
        <?php } ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="doc-content-title" style="margin-bottom:10px;"><strong>Title:</strong> <?= h($txn['title'] ?? ($doc === 'QUOTATION' ? 'Quotation' : 'Invoice')) ?></div>
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
                  <?= h($moneyPrefix) ?><?= number_format($unit, 2) ?>
                <?php endif; ?>
              </td>
              <td class="text-right"><?= abs($amt) < 0.0001 ? '&nbsp;' : (h($moneyPrefix) . number_format($amt, 2)) ?></td>
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
    <div class="doc-totals-block" style="max-width:320px; margin-left:auto; margin-top:12px;">
      <div><strong>Total Discount:</strong> <?= h($moneyPrefix) ?><?= number_format($discount, 2) ?></div>
      <div style="font-size:15px; font-weight:700; margin-top:6px;">Total Amount: <?= h($moneyPrefix) ?><?= number_format($grand, 2) ?></div>
    </div>
    <?php if ($hasTerms && !empty(trim((string)($txn['terms'] ?? '')))): ?>
      <div style="margin-top:14px;"><strong>Terms</strong>
        <div style="white-space:pre-wrap; font-size:12px;"><?= h($txn['terms']) ?></div>
      </div>
    <?php endif; ?>
    <?php if (!empty(trim((string)($txn['notes'] ?? '')))): ?>
      <div class="doc-notes-block" style="margin-top:10px;"><strong>Notes</strong>
        <div style="white-space:pre-wrap; font-size:12px;"><?= h($txn['notes']) ?></div>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="doc-bottom-terms" style="margin-top:18px; font-size:12px; color:#374151;">
    <p style="margin:0 0 6px;">All sales, delivery and payment are subject to the terms and conditions of VISION MIX SDN. BHD. Additional copies available on request.</p>
    <p style="margin:0 0 6px;">Submission of order for products/services constitutes acceptance of these terms and conditions.</p>
    <p style="margin:0 0 12px;">All overdue accounts will be subject to an additional charge of 1.5% per month. Payment to be made to VISION MIX SDN. BHD.</p>
  </div>
  <?php if ($preferredBanks): ?>
    <div class="doc-bottom-banks" style="margin-top:10px;">
      <strong>PREFERRED BANK</strong>
      <?php foreach ($preferredBanks as $b): ?>
        <?php if (strtoupper(trim((string)($b['bank_code'] ?? ''))) === 'CIMB') continue; ?>
        <div style="margin-top:4px; font-size:12px;">
          <span class="label">BANK:</span> <?= h($b['bank_code'] ?? '') ?> &nbsp;|&nbsp;
          <span class="label">ACCOUNT NAME:</span> <?= h($b['account_name'] ?? '') ?> &nbsp;|&nbsp;
          <span class="label">ACCOUNT NO.:</span> <?= h($b['account_no'] ?? '') ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="doc-sign-flex doc-bottom-sign">
    <div class="doc-sign-col" id="customerSignCol" style="<?= $needCustomerSign ? '' : 'display:none;' ?>">
      <div class="doc-sign-top-spacer"></div>
      <div id="sigpadMount" class="doc-sig-area">
        <div id="sigpadSigned" style="<?= $dbCustomerSig !== '' ? '' : 'display:none;' ?>">
          <?php if ($dbCustomerSig !== ''): ?>
            <img src="<?= h($dbCustomerSig) ?>" class="sigimg" alt="">
          <?php endif; ?>
        </div>
        <div id="sigpadInput" style="<?= $dbCustomerSig !== '' ? 'display:none;' : '' ?>">
          <div class="sigpad-wrap"><canvas id="sigpadCanvas" class="sigpad-canvas"></canvas></div>
          <div class="sigpad-actions">
            <button type="button" class="btn btn-light" id="sigpadClear">Clear</button>
            <button type="button" class="btn btn-primary" id="sigpadDone">Done</button>
          </div>
        </div>
      </div>
      <div class="doc-underline"></div>
      <div class="doc-sign-label" style="margin-top:6px;">RECEIVED BY AND COMPANY STAMP:</div>
      <div class="doc-sign-date-label" style="margin-top:4px;">DATE: <span class="sigdate" id="sigpadDateText"><?= h($customerSignDate) ?></span></div>
    </div>

    <div class="doc-sign-col right" id="companySignCol">
      <div style="font-weight:bold; margin-bottom:4px;"><?= h($companyName) ?></div>
      <div class="doc-sig-area" style="min-height:70px;">
        <div id="sigpadCompanySigned" style="<?= $dbCompanySig !== '' ? 'margin-left:auto;' : 'display:none; margin-left:auto;' ?>">
          <?php if ($dbCompanySig !== ''): ?>
            <img src="<?= h($dbCompanySig) ?>" class="sigimg" alt="">
          <?php endif; ?>
        </div>
        <div id="sigpadCompanyInput" style="<?= $portalCustomerOnly ? 'display:none;' : (($needCompanySign && $dbCompanySig === '') ? '' : 'display:none;') ?>">
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
        <span class="sigdate" id="sigpadCompanyDateText" style="margin-left:10px;"><?= h($companySignDate) ?></span>
      </div>
    </div>
  </div>
</div>

<!-- 打印专用分页容器（JS 在 beforeprint 动态生成） -->
<div id="printPages" style="display:none;"></div>

<script>
  // 后端签名保存接口（admin / Company1）
  var vmDocSignCustomerUrl = <?= $docSignCustomerUrl ? json_encode($docSignCustomerUrl, JSON_UNESCAPED_SLASHES) : 'null' ?>;
  var vmDocSignCompanyUrl = <?= $docSignCompanyUrl ? json_encode($docSignCompanyUrl, JSON_UNESCAPED_SLASHES) : 'null' ?>;
  var vmDocRequireUrl = <?= $docRequireUrl ? json_encode($docRequireUrl, JSON_UNESCAPED_SLASHES) : 'null' ?>;
  // 当前 doc 是否已经有 DB 签名（签过后就只读，不允许再签）
  var vmHasCustomerSig = <?= $dbCustomerSig !== '' ? 'true' : 'false' ?>;
  var vmHasCompanySig = <?= $dbCompanySig !== '' ? 'true' : 'false' ?>;
  // 顾客端文档：只允许 Customer 一边签
  var vmPortalCustomerOnly = <?= $portalCustomerOnly ? 'true' : 'false' ?>;
  (function() {
    var docKeyBase = 'vm_doc_' + <?= (int)$id ?> + '_' + <?= json_encode($doc, JSON_UNESCAPED_SLASHES) ?>;

    function fmtNow() {
      var d = new Date();

      function p(n) {
        return (n < 10 ? '0' : '') + n;
      }
      return p(d.getDate()) + '/' + p(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    function normalizeDateText(s) {
      if (!s) return '';
      // 兼容旧缓存：可能是 "dd/mm/yyyy HH:MM"
      var i = String(s).indexOf(' ');
      return i > 0 ? String(s).slice(0, i) : String(s);
    }

    function readJson(key) {
      try {
        var v = localStorage.getItem(key);
        if (!v) return null;
        return JSON.parse(v);
      } catch (e) {
        return null;
      }
    }

    function writeJson(key, obj) {
      try {
        localStorage.setItem(key, JSON.stringify(obj));
      } catch (e) {}
    }

    function createSigPad(opts) {
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

      function fitCanvas() {
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

      function pos(e) {
        var r = canvas.getBoundingClientRect();
        var x = (e.clientX - r.left) * (canvas.width / r.width);
        var y = (e.clientY - r.top) * (canvas.height / r.height);
        return {
          x: x,
          y: y
        };
      }

      function start(e) {
        drawing = true;
        var p = pos(e);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        hasInk = true;
        e.preventDefault();
      }

      function move(e) {
        if (!drawing) return;
        var p = pos(e);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        hasInk = true;
        e.preventDefault();
      }

      function end(e) {
        drawing = false;
        if (e && e.preventDefault) e.preventDefault();
      }

      function showSigned(dataUrl, signedAt) {
        var img = new Image();
        img.src = dataUrl;
        img.className = 'sigimg';
        signedWrap.innerHTML = '';
        signedWrap.appendChild(img);
        signedWrap.style.display = '';
        inputWrap.style.display = 'none';
        if (dateEl) dateEl.textContent = normalizeDateText(signedAt || '');
      }

      function clear() {
        if (opts.readonly) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
        try {
          localStorage.removeItem(key);
        } catch (e) {}
        if (dateEl) dateEl.textContent = '';
        signedWrap.style.display = 'none';
        inputWrap.style.display = '';
        fitCanvas();
      }

      function done() {
        if (opts.readonly) return;
        if (!hasInk) return;
        var dataUrl = canvas.toDataURL('image/png');
        var signedAt = fmtNow();
        writeJson(key, {
          img: dataUrl,
          at: signedAt
        });
        showSigned(dataUrl, signedAt);
        if (opts.saveUrl) {
          try {
            var body = 'image=' + encodeURIComponent(dataUrl);
            fetch(opts.saveUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
              },
              body: body
            }).catch(function() {});
          } catch (e) {}
        }
      }

      canvas.addEventListener('pointerdown', start);
      canvas.addEventListener('pointermove', move);
      window.addEventListener('pointerup', end);
      canvas.addEventListener('pointercancel', end);
      canvas.addEventListener('contextmenu', function(e) {
        e.preventDefault();
      });
      if (btnClear) btnClear.addEventListener('click', clear);
      if (btnDone) btnDone.addEventListener('click', done);

      // load saved：如果 DOM 里已有 DB 签名图，就优先用 DB，不覆盖
      var hasDbImg = !!signedWrap.querySelector('img');
      if (!hasDbImg) {
        var saved = readJson(key);
        if (saved && saved.img) {
          showSigned(saved.img, saved.at || '');
        } else {
          fitCanvas();
        }
      }

      return {
        fitCanvas: fitCanvas,
        showInput: function(on) {
          inputWrap.style.display = on ? '' : 'none';
        },
        clear: clear
      };
    }

    var padCustomer = createSigPad({
      mountId: 'sigpadMount',
      canvasId: 'sigpadCanvas',
      inputId: 'sigpadInput',
      signedId: 'sigpadSigned',
      clearId: 'sigpadClear',
      doneId: 'sigpadDone',
      dateId: 'sigpadDateText',
      storageKey: docKeyBase + '_sig_customer',
      saveUrl: vmDocSignCustomerUrl,
      readonly: vmHasCustomerSig
    });
    var padCompany = createSigPad({
      mountId: 'companySignCol',
      canvasId: 'sigpadCompanyCanvas',
      inputId: 'sigpadCompanyInput',
      signedId: 'sigpadCompanySigned',
      clearId: 'sigpadCompanyClear',
      doneId: 'sigpadCompanyDone',
      dateId: 'sigpadCompanyDateText',
      storageKey: docKeyBase + '_sig_company',
      saveUrl: vmDocSignCompanyUrl,
      // 顾客端或已有公司签名时，公司画板只读
      readonly: vmHasCompanySig || vmPortalCustomerOnly
    });

    function applyOptions() {
      var cbCus = document.getElementById('optNeedCustomer');
      var cbCom = document.getElementById('optNeedCompany');
      var selMode = document.getElementById('optCompanyMode');
      var cusCol = document.getElementById('customerSignCol');
      var comInput = document.getElementById('sigpadCompanyInput');
      var chop = document.querySelector('#companySignCol .doc-chop');

      // 顾客 portal：尊重后端根据「是否需要签名」设置的初始显示状态，
      // 这里只负责自适应画布，不再强行改动显示 / 隐藏逻辑
      if (vmPortalCustomerOnly) {
        if (padCustomer) padCustomer.fitCanvas();
        if (padCompany) padCompany.fitCanvas();
        return;
      }

      // 如果已经有 DB 签名，则强制视为需要签名且锁定勾选
      var needCustomer = cbCus ? cbCus.checked : false;
      var needCompany = cbCom ? cbCom.checked : false;
      if (vmHasCustomerSig) {
        needCustomer = true;
        if (cbCus) {
          cbCus.checked = true;
          cbCus.disabled = true;
        }
      }
      if (vmHasCompanySig) {
        needCompany = true;
        if (cbCom) {
          cbCom.checked = true;
          cbCom.disabled = true;
        }
      }
      var mode = selMode ? selMode.value : 'SIGN_AND_CHOP';

      if (cusCol) cusCol.style.display = needCustomer ? '' : 'none';
      // 已有公司签名时，不再显示画板输入，只展示图片
      if (comInput) comInput.style.display = (!vmHasCompanySig && needCompany && mode !== 'CHOP_ONLY') ? '' : 'none';

      if (chop) {
        chop.style.display = (mode === 'SIGN_ONLY') ? 'none' : '';
      }

      // ensure canvases scale after toggles
      if (padCustomer) padCustomer.fitCanvas();
      if (padCompany) padCompany.fitCanvas();
    }

    function saveOptions() {
      var cbCus = document.getElementById('optNeedCustomer');
      var cbCom = document.getElementById('optNeedCompany');
      var selMode = document.getElementById('optCompanyMode');
      applyOptions();
    }

    var cbCus = document.getElementById('optNeedCustomer');
    var cbCom = document.getElementById('optNeedCompany');
    var selMode = document.getElementById('optCompanyMode');
    if (cbCus) cbCus.addEventListener('change', saveOptions);
    if (cbCom) cbCom.addEventListener('change', saveOptions);
    if (selMode) selMode.addEventListener('change', saveOptions);

    // 显式 Save 按钮：按下才把「需要签名」状态写入 DB
    var btnSave = document.getElementById('btnSigOptsSave');
    var savedText = document.getElementById('sigOptsSavedText');
    if (btnSave && vmDocRequireUrl) {
      btnSave.addEventListener('click', function() {
        if (!cbCus || !cbCom) return;
        var needCustomer = cbCus.checked ? '1' : '0';
        var needCompany = cbCom.checked ? '1' : '0';
        var body = 'need_customer=' + encodeURIComponent(needCustomer) +
          '&need_company=' + encodeURIComponent(needCompany);
        btnSave.disabled = true;
        if (savedText) savedText.style.display = 'none';
        fetch(vmDocRequireUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
          body: body
        }).then(function() {
          if (savedText) savedText.style.display = '';
          setTimeout(function() {
            if (savedText) savedText.style.display = 'none';
            btnSave.disabled = false;
          }, 1500);
        }).catch(function() {
          btnSave.disabled = false;
        });
      });
    }

    applyOptions();
    window.addEventListener('resize', function() {
      if (padCustomer) padCustomer.fitCanvas();
      if (padCompany) padCompany.fitCanvas();
    });
  })();
</script>

<script>
  (function() {
    function qs(sel, root) {
      return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
      return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    function cloneNodeDeep(el) {
      if (!el) return null;
      return el.cloneNode(true);
    }

    function buildHeaderClone(docRoot) {
      var frag = document.createElement('div');
      var top = qs('.doc-top-center', docRoot);
      var meta = qs('.doc-row-to-meta', docRoot);
      var line = qs('.doc-line', docRoot);
      var title = qs('.doc-content-title', docRoot);
      if (top) frag.appendChild(cloneNodeDeep(top));
      if (meta) frag.appendChild(cloneNodeDeep(meta));
      if (line) frag.appendChild(cloneNodeDeep(line));
      if (title) frag.appendChild(cloneNodeDeep(title));
      return frag;
    }

    function buildFooterClone(docRoot) {
      var frag = document.createElement('div');
      var terms = qs('.doc-bottom-terms', docRoot);
      var banks = qs('.doc-bottom-banks', docRoot);
      var sign = qs('.doc-bottom-sign', docRoot);
      if (terms) frag.appendChild(cloneNodeDeep(terms));
      if (banks) frag.appendChild(cloneNodeDeep(banks));
      if (sign) frag.appendChild(cloneNodeDeep(sign));

      // 去掉 footer 里的交互按钮，测量时更接近真实打印高度，避免“明明很空却提前翻页”
      qsa('.sigpad-actions', frag).forEach(function(el) {
        if (el && el.parentNode) el.parentNode.removeChild(el);
      });
      qsa('button', frag).forEach(function(el) {
        if (el && el.parentNode) el.parentNode.removeChild(el);
      });
      return frag;
    }

    function buildTotalsClone(docRoot) {
      var totals = qs('.doc-totals-block', docRoot);
      return totals ? cloneNodeDeep(totals) : null;
    }

    function buildNotesClone(docRoot) {
      var notes = qs('.doc-notes-block', docRoot);
      return notes ? cloneNodeDeep(notes) : null;
    }

    function extractNotesText(notesClone) {
      if (!notesClone) return '';
      var body = qs('div', notesClone);
      var txt = body ? body.textContent : notesClone.textContent;
      return String(txt || '').trim();
    }

    function appendNoteRowToTable(tableEl, noteText) {
      if (!tableEl || !noteText) return;
      var tbody = qs('tbody', tableEl);
      if (!tbody) return;

      var colCount = qsa('thead th', tableEl).length;
      if (!colCount) {
        var firstRow = qs('tbody tr', tableEl);
        colCount = firstRow ? qsa('td,th', firstRow).length : 1;
      }

      var tr = document.createElement('tr');
      tr.className = 'doc-note-row';
      var td = document.createElement('td');
      td.colSpan = Math.max(1, colCount);
      td.style.textAlign = 'left';
      td.style.whiteSpace = 'pre-wrap';
      td.style.paddingTop = '6px';
      td.style.paddingBottom = '6px';

      var strong = document.createElement('strong');
      strong.textContent = 'NOTE : ';
      td.appendChild(strong);
      td.appendChild(document.createTextNode(noteText));
      tr.appendChild(td);
      tbody.appendChild(tr);
    }

    function appendFillerRowsToTable(tableEl, fillPx) {
      if (!tableEl || !(fillPx > 1)) return;
      var tbody = qs('tbody', tableEl);
      if (!tbody) return;

      var colCount = qsa('thead th', tableEl).length;
      if (!colCount) {
        var firstRow = qs('tbody tr', tableEl);
        colCount = firstRow ? qsa('td,th', firstRow).length : 1;
      }
      colCount = Math.max(1, colCount);

      var noteRow = qs('tr.doc-note-row', tbody);
      var baseRowH = 16; // px
      var fullRows = Math.floor(fillPx / baseRowH);
      var remain = Math.max(0, fillPx - (fullRows * baseRowH));

      function makeRow(h) {
        var tr = document.createElement('tr');
        tr.className = 'doc-filler-row';
        tr.style.height = Math.max(1, Math.floor(h)) + 'px';
        for (var i = 0; i < colCount; i++) {
          var td = document.createElement('td');
          td.innerHTML = '&nbsp;';
          td.style.paddingTop = '0';
          td.style.paddingBottom = '0';
          td.style.fontSize = '1px';
          td.style.lineHeight = '1';
          td.style.height = Math.max(1, Math.floor(h)) + 'px';
          td.style.minHeight = Math.max(1, Math.floor(h)) + 'px';
          tr.appendChild(td);
        }
        if (noteRow) tbody.insertBefore(tr, noteRow);
        else tbody.appendChild(tr);
      }

      for (var r = 0; r < fullRows; r++) makeRow(baseRowH);
      if (remain > 2) makeRow(remain);
    }

    function ensureTotalsClass(docRoot) {
      var candidate = null;
      var divs = qsa('div', docRoot);
      for (var i = 0; i < divs.length; i++) {
        var d = divs[i];
        if (d && d.style && typeof d.style.maxWidth === 'string' && d.style.maxWidth.indexOf('320px') >= 0) {
          if (d.textContent && d.textContent.indexOf('Total Amount') >= 0) {
            candidate = d;
            break;
          }
        }
      }
      if (candidate && !candidate.classList.contains('doc-totals-block')) candidate.classList.add('doc-totals-block');
    }

    function makePage(headerClone, footerClone) {
      var page = document.createElement('div');
      page.className = 'print-page';

      var header = document.createElement('div');
      header.className = 'print-page-header';
      header.appendChild(cloneNodeDeep(headerClone));

      var body = document.createElement('div');
      body.className = 'print-page-body';
      var itemsWrap = document.createElement('div');
      itemsWrap.className = 'print-items-wrap';
      body.appendChild(itemsWrap);

      var totalsWrap = document.createElement('div');
      totalsWrap.className = 'print-totals-wrap';
      body.appendChild(totalsWrap);

      var footer = document.createElement('div');
      footer.className = 'print-page-footer';
      footer.appendChild(cloneNodeDeep(footerClone));
      var pageNo = document.createElement('div');
      pageNo.className = 'print-page-no';
      pageNo.textContent = '';
      page.appendChild(pageNo);

      page.appendChild(header);
      page.appendChild(body);
      page.appendChild(footer);

      return {
        page: page,
        body: body,
        itemsWrap: itemsWrap,
        totalsWrap: totalsWrap
      };
    }

    function cloneTableSkeleton(srcTable) {
      var t = srcTable.cloneNode(true);
      // 清空 tbody
      var tb = qs('tbody', t);
      if (tb) tb.innerHTML = '';
      return t;
    }

    function paginate() {
      var docRoot = qs('#doc-print-area');
      var printRoot = qs('#printPages');
      if (!docRoot || !printRoot) return;

      // 把分页容器提升到 body 直系层级，避免被 portal/admin 外层容器影响分页高度
      if (printRoot.parentNode !== document.body) {
        document.body.appendChild(printRoot);
      }

      ensureTotalsClass(docRoot);

      var srcTable = qs('table.doc-table-items', docRoot);
      if (!srcTable) return;
      var srcTbody = qs('tbody', srcTable);
      if (!srcTbody) return;

      var rows = Array.prototype.slice.call(srcTbody.children).filter(function(n) {
        return n && n.tagName === 'TR';
      });
      var headerClone = buildHeaderClone(docRoot);
      var footerClone = buildFooterClone(docRoot);
      var totalsClone = buildTotalsClone(docRoot);
      var notesClone = buildNotesClone(docRoot);
      var noteText = extractNotesText(notesClone);
      var hasNoteRow = (noteText !== '');
      var targetGapToFooterPx = 0.5 * 96;

      printRoot.innerHTML = '';

      // 用一个“测量页”得到 itemsWrap 的可用高度（这就是每页绿色区域固定容量）
      var probe = makePage(headerClone, footerClone);
      var probeTable = cloneTableSkeleton(srcTable);
      probe.itemsWrap.appendChild(probeTable);
      printRoot.appendChild(probe.page);

      // 先量 header/footer 高度，固定在页内上下，避免 footer 溢出到下一页
      var headerEl = qs('.print-page-header', probe.page);
      var footerEl = qs('.print-page-footer', probe.page);
      // 用 offsetHeight（更稳定，避免在 print/build 模式下量到 0 导致分页错乱）
      var headerH = headerEl ? Math.ceil(headerEl.offsetHeight || 0) : 0;
      var footerH = footerEl ? Math.ceil(footerEl.offsetHeight || 0) : 0;
      // 版心上下留白已经由 .print-page padding 提供，这里不再额外偏移
      var hdrDrop = 0; // px
      probe.page.style.setProperty('--hdrDrop', hdrDrop + 'px');
      probe.page.style.setProperty('--ph', (headerH + hdrDrop) + 'px');
      probe.page.style.setProperty('--pf', footerH + 'px');

      // 绿色区域固定容量（每页相同）：用“整页高度 - header - footer”计算
      var pageH = Math.ceil(probe.page.offsetHeight || 0);
      var rawItemsMaxH = Math.max(0, pageH - (headerH + hdrDrop) - footerH);
      // footer 固定贴底（在版心内），不额外上抬，保持上下对称
      var liftNormal = 0;
      var liftLast = 0;
      // 预留极小安全边距，避免浮点误差导致刚好溢出
      var safetyPad = 0; // px
      // 普通页可用高度：必须和真实渲染（items-wrap bottom 含 --lift）一致
      var itemsMaxH = Math.max(0, rawItemsMaxH - liftNormal - safetyPad);
      // 用 probeTable 量出“空表格骨架”高度（thead + 边框），以及每一行的高度
      var baseTableH = Math.ceil(probeTable.offsetHeight || 0);
      var rowHeights = [];
      var measureTbody = qs('tbody', probeTable);
      if (!measureTbody) measureTbody = probeTable.appendChild(document.createElement('tbody'));
      rows.forEach(function(r) {
        var rr = r.cloneNode(true);
        measureTbody.appendChild(rr);
        rowHeights.push(Math.ceil(rr.offsetHeight || 0));
        measureTbody.removeChild(rr);
      });

      // 高度测量异常保护：如果算出来可用高度太小/为 0，就不要用分页版（避免“跑很多页/白屏”）
      if (!pageH || itemsMaxH < 120 || baseTableH < 10) {
        printRoot.innerHTML = '';
        return;
      }

      // 清空 probe，重新正式分页（后续只做计算 + 一次性渲染，避免卡顿/白屏）
      printRoot.innerHTML = '';

      function buildAllIdx() {
        var all = [];
        for (var i = 0; i < rowHeights.length; i++) all.push(i);
        return all;
      }

      function appendTailToWrap(wrap, includeTotals) {
        if (includeTotals && totalsClone) {
          var t = cloneNodeDeep(totalsClone);
          t.style.marginTop = '6px';
          wrap.appendChild(t);
        }
      }

      // 真实渲染检测：指定行 + 尾部内容，是否能在单页放下
      function evaluateRowsOnSinglePage(rowIndexList, includeTotals, includeNoteRow) {
        var test = makePage(headerClone, footerClone);
        test.page.style.setProperty('--hdrDrop', hdrDrop + 'px');
        test.page.style.setProperty('--ph', (headerH + hdrDrop) + 'px');
        test.page.style.setProperty('--pf', footerH + 'px');
        test.page.style.setProperty('--totalsH', '0px');
        test.page.style.setProperty('--lift', (includeTotals ? liftLast : liftNormal) + 'px');

        var tt = cloneTableSkeleton(srcTable);
        var ttb = qs('tbody', tt);
        rowIndexList.forEach(function(idx) {
          ttb.appendChild(rows[idx].cloneNode(true));
        });
        if (includeNoteRow) {
          appendNoteRowToTable(tt, noteText);
        }
        test.itemsWrap.appendChild(tt);

        appendTailToWrap(test.itemsWrap, includeTotals);

        test.totalsWrap.style.display = 'none';
        printRoot.appendChild(test.page);

        var availH = Math.ceil(test.itemsWrap.clientHeight || test.itemsWrap.offsetHeight || 0);
        var usedH = Math.ceil(test.itemsWrap.scrollHeight || test.itemsWrap.offsetHeight || 0);
        var gapH = Math.max(0, availH - usedH);

        printRoot.removeChild(test.page);

        var fitHeight = availH > 0 && usedH <= (availH + 8);
        return { fit: fitHeight, gap: gapH, avail: availH, used: usedH };
      }

      // 能一页放下就强制一页（严格按真实渲染高度）
      function canFitSinglePage() {
        var allIdx = buildAllIdx();
        return evaluateRowsOnSinglePage(allIdx, !!totalsClone, hasNoteRow).fit;
      }

      function splitIntoPages(availableH) {
        var pagesIdx = [];
        var cur = [];
        var used = baseTableH;

        for (var i = 0; i < rowHeights.length; i++) {
          var rh = rowHeights[i];
          var rowLimitH = availableH;

          if (used + rh <= rowLimitH) {
            cur.push(i);
            used += rh;
          } else {
            if (cur.length === 0) {
              // 单行超高也要塞进去，避免死循环
              cur.push(i);
              pagesIdx.push(cur);
              cur = [];
              used = baseTableH;
            } else {
              pagesIdx.push(cur);
              cur = [i];
              used = baseTableH + rh;
            }
          }
        }

        if (cur.length) pagesIdx.push(cur);
        return pagesIdx;
      }

      var normalPages = splitIntoPages(itemsMaxH);

      // 优先：真实可一页就一页（避免“明明放得下却被拆两页”）
      var pagesIdx = normalPages.slice();
      var allIdx = buildAllIdx();
      var singlePageConfirmed = canFitSinglePage();
      if (singlePageConfirmed) {
        pagesIdx = [allIdx];
      }

      // 多页时：优先最少页数。notes / totals 只在最后一页出现，避免重复占用页面空间。
      if (!singlePageConfirmed) {
        // 防卡死：给分页校正一个可控预算
        var guard = 0;
        var movedCount = 0;
        var maxMoves = Math.max(80, rows.length * 4);
        var adjustStartTs = Date.now();
        var adjustTimeBudgetMs = 900;
        while (guard++ < 800 && movedCount < maxMoves && (Date.now() - adjustStartTs) < adjustTimeBudgetMs) {
          var adjusted = false;
          for (var pi = 0; pi < pagesIdx.length; pi++) {
            var isLastPage = (pi === pagesIdx.length - 1);
            var fitInfo = evaluateRowsOnSinglePage(
              pagesIdx[pi],
              isLastPage && !!totalsClone,
              isLastPage && hasNoteRow
            );
            if (fitInfo.fit) continue;

            var moved = pagesIdx[pi].pop();
            if (typeof moved === 'undefined') {
              // 当前页已空，跳过本轮避免无效循环
              continue;
            }
            if (!pagesIdx[pi + 1]) pagesIdx[pi + 1] = [];
            pagesIdx[pi + 1].unshift(moved);
            movedCount++;
            if (pagesIdx[pi].length === 0) {
              pagesIdx.splice(pi, 1);
            }
            adjusted = true;
            break;
          }
          if (!adjusted) break;
        }
      }

      // 页是否可放下（自动 detect）
      function pageFits(pageRows, isLastPage) {
        return evaluateRowsOnSinglePage(pageRows, isLastPage && !!totalsClone, isLastPage && hasNoteRow).fit;
      }

      // 关键：把每一页尽量“塞满”，能放就继续放，优先减少总页数
      function packPagesToCapacity() {
        var guard = 0;
        while (guard++ < 2000 && pagesIdx.length > 1) {
          var changed = false;
          for (var i = 0; i < pagesIdx.length - 1; i++) {
            var cur = pagesIdx[i];
            var next = pagesIdx[i + 1];
            if (!cur || !next || !next.length) continue;

            // 只要下一页首行还能塞进当前页，就持续搬运
            while (next.length) {
              var candidate = cur.concat([next[0]]);
              if (!pageFits(candidate, false)) break;
              cur.push(next.shift());
              changed = true;
            }

            if (next.length === 0) {
              pagesIdx.splice(i + 1, 1);
              changed = true;
              break;
            }
          }
          if (!changed) break;
        }
      }

      if (pagesIdx.length > 1) {
        packPagesToCapacity();
      }

      // 防止最后一页“空白页”：有些情况下分页算法会生成尾部空页
      // （上一页其实就能放下 totals/notes），导致第二页只有页眉/页脚。
      if (pagesIdx.length > 1) {
        var last = pagesIdx[pagesIdx.length - 1];
        if (!last || last.length === 0) {
          var prev = pagesIdx[pagesIdx.length - 2] || [];
          var prevFit = evaluateRowsOnSinglePage(prev, true, hasNoteRow);
          if (prevFit && prevFit.fit) {
            pagesIdx.pop();
          }
        }
      }

      // 进一步：有些浏览器/字号组合会让“应该能一页放下”的单据被拆成两页，
      // 导致最后一页几乎空白。这里用一次“宽松判定”把它合并回一页（仅在最后一页内容很少时触发）。
      if (pagesIdx.length > 1) {
        var last2 = pagesIdx[pagesIdx.length - 1] || [];
        if (last2.length <= 1) {
          var tryAll = buildAllIdx();
          var fitInfo = evaluateRowsOnSinglePage(tryAll, !!totalsClone, hasNoteRow);
          // 给一点容错，避免因测量误差多拆页
          if (!fitInfo.fit) {
            // 重新计算一次：放宽阈值（用于“避免空白页”目的）
            var test = makePage(headerClone, footerClone);
            test.page.style.setProperty('--hdrDrop', hdrDrop + 'px');
            test.page.style.setProperty('--ph', (headerH + hdrDrop) + 'px');
            test.page.style.setProperty('--pf', footerH + 'px');
            test.page.style.setProperty('--totalsH', '0px');
            test.page.style.setProperty('--lift', (true ? liftLast : liftNormal) + 'px');
            var tt = cloneTableSkeleton(srcTable);
            var ttb = qs('tbody', tt);
            tryAll.forEach(function(idx) { ttb.appendChild(rows[idx].cloneNode(true)); });
            if (hasNoteRow) appendNoteRowToTable(tt, noteText);
            test.itemsWrap.appendChild(tt);
            appendTailToWrap(test.itemsWrap, true);
            test.totalsWrap.style.display = 'none';
            printRoot.appendChild(test.page);
            var availH2 = Math.ceil(test.itemsWrap.clientHeight || test.itemsWrap.offsetHeight || 0);
            var usedH2 = Math.ceil(test.itemsWrap.scrollHeight || test.itemsWrap.offsetHeight || 0);
            var fit2 = availH2 > 0 && usedH2 <= (availH2 + 28); // 比 evaluateRowsOnSinglePage 的 +8 更宽松
            printRoot.removeChild(test.page);
            if (fit2) pagesIdx = [tryAll];
          } else {
            pagesIdx = [tryAll];
          }
        }
      }

      // 一次性渲染所有页面
      function renderPage(rowIndexList, isLast) {
        var p = makePage(headerClone, footerClone);
        p.page.style.setProperty('--hdrDrop', hdrDrop + 'px');
        p.page.style.setProperty('--ph', (headerH + hdrDrop) + 'px');
        p.page.style.setProperty('--pf', footerH + 'px');
        // totals 改成“紧跟 table 后面”显示，不再固定贴底
        // 这里设 0，避免 items 区被额外裁掉，留白更自然。
        p.page.style.setProperty('--totalsH', '0px');
        // 与上面的高度计算保持一致，避免提前换页导致留白
        p.page.style.setProperty('--lift', (isLast ? liftLast : liftNormal) + 'px');

        var t = cloneTableSkeleton(srcTable);
        var tb = qs('tbody', t);
        rowIndexList.forEach(function(idx) {
          tb.appendChild(rows[idx].cloneNode(true));
        });
        if (isLast && hasNoteRow) {
          appendNoteRowToTable(t, noteText);
        }
        if (!isLast) {
          t.classList.add('doc-table-fill');
        }

        // 非最后一页：按预量行高把 table 直接撑到接近 footer（留约 0.5in）
        // 避免“页面很空却不补线”的情况。
        if (!isLast) {
          var usedEst = baseTableH;
          rowIndexList.forEach(function(idx) {
            usedEst += (rowHeights[idx] || 0);
          });
          var fillEst = Math.max(0, itemsMaxH - usedEst - targetGapToFooterPx);
          if (fillEst > 2) appendFillerRowsToTable(t, fillEst);
        }

        p.itemsWrap.appendChild(t);
        appendTailToWrap(p.itemsWrap, (isLast && !!totalsClone));
        p.totalsWrap.style.display = 'none';
        return p;
      }

      var totalPages = pagesIdx.length || 1;
      pagesIdx.forEach(function(list, pi) {
        var pageObj = renderPage(list, (pi === pagesIdx.length - 1));
        var pageEl = pageObj.page;
        var noEl = qs('.print-page-no', pageEl);
        if (noEl) noEl.textContent = 'Page ' + (pi + 1) + '/' + totalPages;
        printRoot.appendChild(pageEl);
      });
      if (!pagesIdx.length) {
        // 没有行的情况也要有一页（最后一页有 totals）
        var emptyPage = makePage(headerClone, footerClone);
        emptyPage.page.style.setProperty('--hdrDrop', hdrDrop + 'px');
        emptyPage.page.style.setProperty('--ph', (headerH + hdrDrop) + 'px');
        emptyPage.page.style.setProperty('--pf', footerH + 'px');
        emptyPage.page.style.setProperty('--totalsH', '0px');
        emptyPage.page.style.setProperty('--lift', (totalsClone ? liftLast : liftNormal) + 'px');
        var emptyTable = cloneTableSkeleton(srcTable);
        if (hasNoteRow) {
          appendNoteRowToTable(emptyTable, noteText);
        }
        emptyPage.itemsWrap.appendChild(emptyTable);
        appendTailToWrap(emptyPage.itemsWrap, !!totalsClone);
        emptyPage.totalsWrap.style.display = 'none';
        var eno = qs('.print-page-no', emptyPage.page);
        if (eno) eno.textContent = 'Page 1/1';
        printRoot.appendChild(emptyPage.page);
      }

      // 最后兜底：移除“完全空白页”（公司端有时会出现第二页空白，但其实没有任何内容）
      // 规则：当且仅当页面没有任何“真实表格行”(排除 filler) 且没有 totals 且没有 NOTE 行时，才认为是空白页。
      (function removeBlankPrintPages() {
        if (!printRoot || !printRoot.children || printRoot.children.length <= 1) return;
        var pages = Array.prototype.slice.call(printRoot.children).filter(function(n) {
          return n && n.classList && n.classList.contains('print-page');
        });
        if (pages.length <= 1) return;

        function isBlankPage(pageEl) {
          var table = qs('table.doc-table-items', pageEl);
          var tbody = table ? qs('tbody', table) : null;
          var hasTotals = !!qs('.doc-totals-block', pageEl);
          var hasNote = !!(tbody && qs('tr.doc-note-row', tbody));
          if (hasTotals || hasNote) return false;
          if (!tbody) return true;
          var trs = Array.prototype.slice.call(tbody.children).filter(function(n) {
            return n && n.tagName === 'TR';
          });
          // 真实行：不是 filler，也不是全空白占位
          var real = trs.filter(function(tr) {
            if (tr.classList && tr.classList.contains('doc-filler-row')) return false;
            var txt = (tr.textContent || '').replace(/\s+/g, '');
            return txt !== '';
          });
          return real.length === 0;
        }

        // 只移除末尾的空白页（最常见：第二页空白）
        for (var i = pages.length - 1; i >= 0; i--) {
          if (!isBlankPage(pages[i])) break;
          printRoot.removeChild(pages[i]);
        }

        // 重新编号
        var finalPages = Array.prototype.slice.call(printRoot.children).filter(function(n) {
          return n && n.classList && n.classList.contains('print-page');
        });
        var tp = finalPages.length || 1;
        if (printRoot.classList) {
          printRoot.classList.toggle('single-page', tp <= 1);
        }
        finalPages.forEach(function(p, idx) {
          var no = qs('.print-page-no', p);
          if (no) no.textContent = 'Page ' + (idx + 1) + '/' + tp;
        });
      })();
    }

    var built = false;

    function preparePagesSafely() {
      try {
        document.body.classList.add('vm-print-build');
        paginate();
        // 只有真的生成了分页页，才切换到分页版打印
        var pr = qs('#printPages');
        var hasPages = !!(pr && pr.children && pr.children.length);
        if (hasPages) document.body.classList.add('vm-print-ready');
      } catch (e) {
        // 不阻塞打印：分页失败时就退回原本的 doc-print-area 打印
        var printRoot = qs('#printPages');
        if (printRoot) printRoot.innerHTML = '';
        document.body.classList.remove('vm-print-ready');
      } finally {
        document.body.classList.remove('vm-print-build');
      }
    }

    // 给页面上的 Print 按钮用：先生成分页，再打开打印对话框
    window.vmPrepareAndPrint = function() {
      preparePagesSafely();
      // 让 DOM 有机会布局完成再打开打印（避免白屏/卡住）
      requestAnimationFrame(function() {
        requestAnimationFrame(function() {
          window.print();
        });
      });
    };

    function onBeforePrint() {
      // 每次打印都重建一次，确保数据最新
      built = true;
      // 不要让 beforeprint 卡死导致“点打印没反应”
      preparePagesSafely();
    }

    function onAfterPrint() {
      // 不要影响屏幕显示
      // 保留生成的 printPages 也没关系，但这里清掉更干净
      var printRoot = qs('#printPages');
      if (printRoot) printRoot.innerHTML = '';
      built = false;
      document.body.classList.remove('vm-print-ready');
    }

    window.addEventListener('beforeprint', onBeforePrint);
    window.addEventListener('afterprint', onAfterPrint);

    // 某些浏览器不触发 beforeprint：点 Print 按钮时先生成
    // 不强绑按钮，避免影响现有逻辑：当页面 load 完后预生成一次（不会显示）
    window.addEventListener('load', function() {
      // 不抢占性能：只在有 print preview 时才需要；这里不自动生成
    });
  })();
</script>
