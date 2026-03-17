<?php
// public/user/company1/txn_view.php
// Company1 查看单笔交易：内容与 user/txn/txn_view.php 一致（按钮、付款列表、附件等），
// 但权限改为 Company1（category 1），并且只能查看 category 3 客户的交易。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/i18n.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

/** DB file_path -> browser href */
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
  if (!empty($b['currency'])) {
    $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
  }
  return $label ?: ('Account #' . ($b['id'] ?? ''));
}

$tid = (int)($_GET['id'] ?? 0);
if ($tid <= 0) { http_response_code(400); exit('Missing transaction id'); }

/** Back：默认回 Company1 交易列表 */
$backUrl = (string)($_GET['back'] ?? '');
if ($backUrl === '') {
  // 先拿 customer_id 给 back 用
  $st = $pdo->prepare("
    SELECT t.customer_id, c.category_id
    FROM customer_txn t
    JOIN customers c ON c.id = t.customer_id
    WHERE t.id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $tid]);
  $tmp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  if ((int)($tmp['category_id'] ?? 0) !== 3) { http_response_code(403); exit('Forbidden'); }
  $backUrl = url('user/company1/txn_list.php?customer_id=' . (int)($tmp['customer_id'] ?? 0));
}

// ===== Customer =====
// Company1：customer_id 来自 txn
$st = $pdo->prepare("
  SELECT c.*
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
  LIMIT 1
");
$st->execute([':id' => $tid]);
$customer = $st->fetch();
if (!$customer) { http_response_code(404); exit('Customer not found'); }
if ((int)($customer['category_id'] ?? 0) !== 3) { http_response_code(403); exit('Forbidden'); }
$cid = (int)($customer['id'] ?? 0);
if ($cid <= 0) { http_response_code(400); exit('Missing customer_id'); }
$currencyDefault = $customer['currency'] ?? 'MYR';

// ===== Txn =====
$st = $pdo->prepare("
  SELECT *
    FROM customer_txn
   WHERE id = :id
     AND customer_id = :cid
   LIMIT 1
");
$st->execute([':id' => $tid, ':cid' => $cid]);
$txn = $st->fetch();
if (!$txn) { http_response_code(404); exit('Transaction not found'); }

$adminType  = (string)($txn['txn_type'] ?? '');      // IN / OUT (admin)
$isContra   = (int)($txn['is_contra'] ?? 0) === 1;
$status     = (string)($txn['status'] ?? 'DRAFT');
$method     = (string)($txn['method'] ?? '');
$amount     = (float)($txn['amount'] ?? 0);
$refNo      = (string)($txn['ref_no'] ?? '');
$title      = (string)($txn['title'] ?? '');
$notes      = (string)($txn['notes'] ?? '');
$currencyTx = ($txn['currency'] ?: $currencyDefault);

$inKind  = strtoupper((string)($txn['in_kind'] ?? 'INVOICE')); // INVOICE / RETURN / BONUS / ...
$outKind = strtoupper((string)($txn['out_kind'] ?? 'NORMAL'));

$requireSignature = (int)($txn['require_signature'] ?? 0) === 1;

// ✅ 只有 admin normal IN (INVOICE) 才是 invoice
$isInvoiceIn       = ($adminType === 'IN' && $inKind === 'INVOICE');
$isBonusOrReturnIn = ($adminType === 'IN' && in_array($inKind, ['BONUS', 'RETURN'], true));
// admin IN + in_kind=ALLOCATE：在客户视角是 OUT (allocate)，不需要 receipt / quotation 按钮
$isAllocateIn      = ($adminType === 'IN' && $inKind === 'ALLOCATE');

// ✅ receipt 只给：admin OUT（非 allocate）/ bonus / return（invoice 不给）
$isAllocateOut = ($adminType === 'OUT' && $inKind === 'ALLOCATE');
$shouldShowReceiptBtn = (
  !$isInvoiceIn
  && !$isContra
  && !$isAllocateOut
  && (
    $adminType === 'OUT'
    || $isBonusOrReturnIn
  )
);

// ✅ Signature 规则：只要 require_signature=1 && 非 contra，都允许 customer 签
$hasCustomerSignature = !empty($txn['signature_image']);
$canCustomerSign = (
  !$isContra
  && $requireSignature
);

// 是否提示 pending
$pendingSignature = (
  $canCustomerSign
  && !$hasCustomerSignature
  && $status !== 'CONFIRMED'
);

// 主附件（旧逻辑兼容）
$attachmentPath = (string)($txn['attachment_path'] ?? '');
$attachmentName = $attachmentPath
  ? ((string)($txn['attachment_name'] ?? '') ?: basename($attachmentPath))
  : '';
$attachmentUrl  = '';
if ($attachmentPath) {
  $href = upload_href($attachmentPath);
  if ($href !== '') $attachmentUrl = url($href);
}

// 通用附件：customer_txn_files
$extraFiles = [];
try {
  $st = $pdo->prepare("
    SELECT id, file_path, file_name, file_mime, created_at
      FROM customer_txn_files
     WHERE txn_id = :tid
  ORDER BY id ASC
  ");
  $st->execute([':tid' => $tid]);
  $extraFiles = $st->fetchAll();
} catch (Throwable $e) {
  $extraFiles = [];
}

// IN 专用：payments / attachments / notes attachments
$paymentLines        = [];
$paymentAttachments  = [];
$txnAttachmentsNotes = [];
$bankMap             = [];
$payCols             = [];

if ($adminType === 'IN') {
  $st = $pdo->prepare("
    SELECT *
      FROM customer_txn_payments
     WHERE customer_txn_id = :tid
  ORDER BY payment_seq ASC, id ASC
  ");
  $st->execute([':tid' => (int)$txn['id']]);
  $paymentLines = $st->fetchAll();

  try {
    $stCols = $pdo->query("DESCRIBE `customer_txn_payments`");
    foreach ($stCols->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (!empty($r['Field'])) $payCols[$r['Field']] = true;
    }
  } catch (Throwable $e) {
    $payCols = [];
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
  } catch (Throwable $e) {}

  try {
    $bankRows = $pdo->query("
      SELECT id, bank_code, account_name, account_no, currency
        FROM company_bank_accounts
       WHERE is_active = 1
    ORDER BY bank_code, account_name, account_no, id
    ")->fetchAll();
    foreach ($bankRows as $b) {
      $bankMap[(int)$b['id']] = bank_label_view($b);
    }
  } catch (Throwable $e) {
    $bankMap = [];
  }
}

// 客户视角 type
if ($adminType === 'OUT') {
  $custType  = 'IN';
  $typeLabel = t('txn.view.type_label_in', [], 'IN — We paid you');
  $typeColor = '#166534';
} else {
  $custType  = 'OUT';
  $typeLabel = t('txn.view.type_label_out', [], 'OUT — You paid us');
  $typeColor = '#b91c1c';
}

$txnDate = $txn['txn_date'] ?? substr((string)($txn['created_at'] ?? ''), 0, 10);

// doc_flow_status：控制 REJECTED 显示、以及是否允许看 invoice / DO / receipt
$flowStat = strtoupper(trim((string)($txn['doc_flow_status'] ?? '')));
if (!in_array($flowStat, ['DRAFT','PROCESSING','COMPLETED','REJECTED'], true)) {
  $flowStat = 'DRAFT';
}

$page_title = t('txn.view.page_title', [], 'Transaction detail');
$active_nav = 'company1_invoices';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-card admin-card-elevated" style="margin-bottom:18px;">
  <div class="form-page-header">
    <div>
      <div class="form-page-eyebrow"><?= h(t('txn.view.eyebrow', [], 'Transaction')) ?></div>
      <h2 class="form-page-title"><?= h($typeLabel) ?></h2>
      <div class="form-page-subtitle">
        <?= h($customer['name']) ?>
        <?php if (!empty($customer['code'])): ?>(<?= h($customer['code']) ?>)<?php endif; ?>
      </div>
    </div>

    <div class="form-page-meta" style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
      <?php
        $backHere = url('user/company1/txn_view.php?id=' . (int)$tid);
        $docInvoiceUrl   = url('user/company1/txn_doc_in.php?id='.(int)$tid.'&customer_id='.$cid.'&doc=INVOICE&back='.rawurlencode($backHere));
        $docQuotationUrl = url('user/company1/txn_doc_in.php?id='.(int)$tid.'&customer_id='.$cid.'&doc=QUOTATION&back='.rawurlencode($backHere));
        $docDoUrl        = url('user/company1/txn_doc_in.php?id='.(int)$tid.'&customer_id='.$cid.'&doc=DO&back='.rawurlencode($backHere));
        $allReceiptsUrl  = url('user/company1/txn_receipt_in.php?id='.(int)$tid.'&customer_id='.(int)($txn['customer_id'] ?? 0).'&back='.rawurlencode($backHere));
        $outReceiptFull  = url('user/txn/txn_receipt_full.php?id='.(int)$tid.'&back='.rawurlencode($backHere));
        $outReceiptSign  = url('user/txn/txn_receipt_out.php?id='.(int)$tid.'&back='.rawurlencode($backHere));
      ?>

      <a href="<?= h($backUrl) ?>" class="btn btn-light">
        <?= h(t('txn.view.btn_back', [], 'Back')) ?>
      </a>

      <?php if ($adminType === 'IN' && !$isContra && !$isAllocateIn): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
          <?php if ($flowStat !== 'REJECTED'): ?>
            <a href="<?= h($allReceiptsUrl) ?>" class="btn btn-primary btn-sm">
              View receipts
            </a>
          <?php endif; ?>

          <?php if ($flowStat === 'COMPLETED' || $flowStat === 'PROCESSING'): ?>
            <a href="<?= h($docInvoiceUrl) ?>" class="btn btn-light btn-sm" target="_blank">
              Invoice
            </a>
            <a href="<?= h($docDoUrl) ?>" class="btn btn-light btn-sm" target="_blank">
              DO
            </a>
          <?php endif; ?>

          <a href="<?= h($docQuotationUrl) ?>" class="btn btn-light btn-sm" target="_blank">
            Quotation
          </a>
        </div>
      <?php elseif ($adminType === 'OUT' && !$isContra && $requireSignature): ?>
        <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
          <a href="<?= h($outReceiptFull) ?>" class="btn btn-light btn-sm" target="_blank">
            <?= h(t('cust.txn.receipt.btn_view', [], 'Receipt')) ?>
          </a>
          <?php if (!$hasCustomerSignature): ?>
            <a href="<?= h($outReceiptSign) ?>" class="btn btn-primary btn-sm">
              <?= h(t('cust.txn.receipt.btn_sign', [], 'Sign')) ?>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 顶部状态 / 金额 -->
  <div style="display:flex;flex-wrap:wrap;gap:18px;margin-bottom:18px;font-size:13px;">
    <div style="min-width:180px;">
      <div style="color:#6b7280;"><?= h(t('txn.view.field.type', [], 'Type')) ?></div>
      <div style="font-size:16px;font-weight:600;margin-top:4px;color:<?= h($typeColor) ?>;">
        <?= h($custType) ?>
        <?php if ($isContra): ?>
          <span style="font-size:11px;color:#6b7280;margin-left:6px;">
            (<?= h(t('txn.badge.contra', [], 'Contra')) ?>)
          </span>
        <?php endif; ?>
        <?php if ($adminType === 'IN' && $inKind !== 'INVOICE'): ?>
          <span style="font-size:11px;color:#6b7280;margin-left:6px;">
            (<?= h($inKind) ?>)
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div style="min-width:180px;">
      <div style="color:#6b7280;"><?= h(t('txn.view.field.amount', [], 'Amount')) ?></div>
      <div style="font-size:18px;font-weight:600;margin-top:4px;">
        <?= h($currencyTx) ?> <?= number_format($amount, 2) ?>
      </div>
    </div>

    <div style="min-width:160px;">
      <div style="color:#6b7280;"><?= h(t('txn.view.field.status', [], 'Status')) ?></div>
      <div style="margin-top:4px;">
        <?php
          // dashboard / txns 一致：doc_flow_status = REJECTED 时优先显示 REJECTED
          $rawStatus = strtoupper(trim($status));
          if ($flowStat === 'REJECTED') {
            $displayStatus = 'REJECTED';
          } else {
            $displayStatus = $rawStatus !== '' ? $rawStatus : 'DRAFT';
          }
        ?>
        <?php if ($displayStatus === 'CONFIRMED'): ?>
          <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#ecfdf5;color:#166534;"><?= h(t('status.confirmed', [], 'CONFIRMED')) ?></span>
        <?php elseif ($displayStatus === 'PENDING'): ?>
          <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#dbeafe;color:#1d4ed8;"><?= h(t('status.pending', [], 'PENDING')) ?></span>
        <?php elseif ($displayStatus === 'SENT'): ?>
          <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fef9c3;color:#854d0e;"><?= h(t('status.sent', [], 'SENT')) ?></span>
        <?php elseif ($displayStatus === 'REJECTED'): ?>
          <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fee2e2;color:#b91c1c;">REJECTED</span>
        <?php else: ?>
          <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#e5e7eb;color:#374151;"><?= h(t('status.draft', [], 'DRAFT')) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <div style="min-width:160px;">
      <div style="color:#6b7280;"><?= h(t('txn.view.field.date', [], 'Date')) ?></div>
      <div style="margin-top:4px;"><?= h($txnDate) ?></div>
    </div>
  </div>

  <?php if ($pendingSignature): ?>
    <div class="alert-error" style="margin-bottom:12px;">
      <?= h(t('txn.view.alert_pending', [], 'Pending your signature.')) ?>
    </div>
  <?php elseif ($canCustomerSign && $hasCustomerSignature): ?>
    <div class="alert-success" style="margin-bottom:12px;">
      <?= h(t('txn.view.alert_signed', [], 'Signature recorded.')) ?>
    </div>
  <?php elseif ($isContra): ?>
    <div class="alert-success" style="margin-bottom:12px;">
      <?= h(t('txn.view.alert_contra', [], 'This entry was created by allocation (contra). No signature is required.')) ?>
    </div>
  <?php endif; ?>

  <div class="form-layout">

    <?php if ($adminType === 'IN'): ?>
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-title"><?= h(t('txn.view.section_in_title', [], 'Transaction details')) ?></div>
          <div class="form-section-desc"><?= h(t('txn.view.section_in_desc', [], 'Order amount, payments and attachments.')) ?></div>
        </div>

        <div class="form-grid form-grid-3">
          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.date', [], 'Date')) ?></label>
            <div class="field-static"><?= h($txnDate) ?></div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.invoice_no', [], 'Invoice No')) ?></label>
            <div class="field-static">
              <?php if ($isInvoiceIn && !empty($txn['invoice_no'])): ?>
                <?= h($txn['invoice_no']) ?>
              <?php else: ?>
                <span style="color:#9ca3af;">—</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.order_total', [], 'Order total')) ?></label>
            <div class="field-static">
              <?php
              $orderTotalShow = $isInvoiceIn ? (float)($txn['order_total'] ?? 0) : (float)($txn['amount'] ?? 0);
              $curShow = $txn['currency'] ?: 'MYR';
              ?>
              <?= h($curShow . ' ' . number_format($orderTotalShow, 2)) ?>
            </div>
          </div>
        </div>

        <!-- Payments -->
        <div class="form-group" style="margin-top:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:8px;">
            <label class="field-label" style="margin:0;"><?= h(t('txn.view.section_payments_title', [], 'Payments')) ?></label>
          </div>

          <?php if ($paymentLines): ?>
            <div style="border:1px solid var(--border);border-radius:10px;overflow-x:auto;">
              <table class="table" style="margin:0;font-size:13px;">
                <thead>
                  <tr>
                    <th style="width:140px;"><?= h(t('txn.view.col.or_no', [], 'Official Receipt No')) ?></th>
                    <th style="width:110px;"><?= h(t('txn.view.col.pay_date', [], 'Date')) ?></th>
                    <th style="width:130px;"><?= h(t('txn.view.col.amount', [], 'Amount')) ?></th>
                    <th style="width:260px;"><?= h(t('txn.view.col.bank', [], 'Received via')) ?></th>
                    <th style="width:100px;"><?= h(t('txn.view.col.currency', [], 'Currency')) ?></th>
                    <th style="width:110px;"><?= h(t('txn.view.col.fx_rate', [], 'FX → MYR')) ?></th>
                    <th style="width:240px;"><?= h(t('txn.view.col.attach', [], 'Payment attachment')) ?></th>
                    <th style="width:180px;"><?= h(t('txn.view.col.docs', [], 'Documents')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paymentLines as $pl): ?>
                    <?php
                    $pid = (int)($pl['id'] ?? 0);
                    $bankId = (int)($pl['bank_account_id'] ?? 0);
                    $bankText = $bankId && isset($bankMap[$bankId]) ? $bankMap[$bankId] : '';
                    $fxRate = $pl['fx_rate'] ?? '';
                    $attList = $pid > 0 && isset($paymentAttachments[$pid]) ? $paymentAttachments[$pid] : [];

                    $receivedVia = $bankText !== '' ? $bankText : '—';

                    $backHere = url('user/company1/txn_view.php?id=' . (int)$txn['id']);
                    $invUrl = url('user/company1/txn_receipt_in.php?id=' . (int)$txn['id'] . '&customer_id='.(int)($txn['customer_id'] ?? 0).'&payment_id=' . (int)$pid . '&back=' . urlencode($backHere));
                    ?>
                    <tr>
                      <td><?= h($pl['or_no'] ?? '') ?></td>
                      <td><?= h($pl['pay_date'] ?? '') ?></td>
                      <td><?= h(number_format((float)($pl['amount'] ?? 0), 2)) ?></td>
                      <td><?= h($receivedVia) ?></td>
                      <td><?= h($pl['currency'] ?: ($txn['currency'] ?? 'MYR')) ?></td>
                      <td><?= ($fxRate !== null && $fxRate !== '') ? h((string)$fxRate) : '<span style=\"color:#9ca3af;\">—</span>' ?></td>

                      <td>
                        <?php if ($attList): ?>
                          <ul style="list-style:disc;padding-left:18px;margin:0;">
                            <?php foreach ($attList as $i => $a): ?>
                              <?php
                              $href = upload_href($a['file_path'] ?? '');
                              if ($href === '') continue;
                              $text = trim((string)($a['file_name'] ?? '')) ?: ('Attachment ' . ($i + 1));
                              ?>
                              <li><a href="<?= h(url($href)) ?>"><?= h($text) ?></a></li>
                            <?php endforeach; ?>
                          </ul>
                        <?php else: ?>
                          <span style="font-size:12px;color:#9ca3af;"><?= h(t('txn.view.attach_none_short', [], 'No attachment')) ?></span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <a href="<?= h($invUrl) ?>" class="btn btn-light btn-sm">
                          <?= h(t('txn.view.btn_receipt', [], 'Receipt')) ?>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="field-static">
              <span style="font-size:12px;color:#6b7280;"><?= h(t('txn.view.payments_none', [], 'No payment yet.')) ?></span>
            </div>
          <?php endif; ?>
        </div>

      </div>
    <?php else: ?>
      <!-- admin OUT 分支（保持你原本逻辑） -->
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-title"><?= h(t('txn.view.section_details_title', [], 'Details')) ?></div>
          <div class="form-section-desc"><?= h(t('txn.view.section_details_desc', [], 'Basic information for this transaction.')) ?></div>
        </div>

        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.ref_no', [], 'Reference no.')) ?></label>
            <div><?= $refNo !== '' ? h($refNo) : '-' ?></div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.method', [], 'Method')) ?></label>
            <div><?= h($method) ?></div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.title', [], 'Title')) ?></label>
            <div><?= $title !== '' ? h($title) : '-' ?></div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('txn.view.field.notes', [], 'Notes')) ?></label>
            <div style="white-space:pre-wrap;"><?= $notes !== '' ? h($notes) : '-' ?></div>
          </div>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-title"><?= h(t('txn.view.section_attach_title', [], 'Attachments')) ?></div>
          <div class="form-section-desc"><?= h(t('txn.view.section_attach_desc', [], 'Open or download the attached documents (PDF / image).')) ?></div>
        </div>

        <?php $hasMainAtt = ($attachmentUrl !== ''); $hasFiles = !empty($extraFiles); ?>
        <?php if ($hasMainAtt || $hasFiles): ?>
          <div class="form-group">
            <ul style="list-style:disc;padding-left:18px;font-size:13px;margin:0;">
              <?php if ($hasMainAtt): ?>
                <li><a href="<?= h($attachmentUrl) ?>"><?= h($attachmentName) ?></a></li>
              <?php endif; ?>
              <?php foreach ($extraFiles as $f): ?>
                <?php
                $fp   = (string)($f['file_path'] ?? '');
                $name = (string)($f['file_name'] ?? '') ?: basename($fp);
                $href = upload_href($fp);
                if ($href === '') continue;
                ?>
                <li><a href="<?= h(url($href)) ?>"><?= h($name) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <div style="font-size:13px;color:#6b7280;"><?= h(t('txn.view.attach_none', [], 'No attachment uploaded for this transaction.')) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

