<?php
// public/user/txn/txn_doc_in.php
// 用户门户版：复用 admin/doc 模板，但只允许查看自己公司的单据
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// 先取 transaction，确认是当前登录客户的
$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  exit('Missing id');
}

$st = $pdo->prepare("
  SELECT customer_id, doc_flow_status, doc_flow_type, invoice_no
  FROM customer_txn
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Transaction not found');
}

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0 || (int)$row['customer_id'] !== $cid) {
  http_response_code(403);
  exit('Forbidden');
}

// 通过后，引入 admin 的文档模板，但使用 user portal 的 layout（header/sidebar）
if (!defined('ALLOW_TXN_DOC_FROM_PORTAL')) {
  define('ALLOW_TXN_DOC_FROM_PORTAL', true);
}
// 顾客端文档：允许在 Customer 一边签名（公司一边只读）
if (!defined('TXN_DOC_PORTAL_CUSTOMER_ONLY')) {
  define('TXN_DOC_PORTAL_CUSTOMER_ONLY', true);
}

// 当前 doc 类型（INVOICE / QUOTATION / DO）
$doc = strtoupper(trim((string)($_GET['doc'] ?? 'INVOICE')));
if (!in_array($doc, ['INVOICE', 'QUOTATION', 'DO'], true)) {
  $doc = 'INVOICE';
}

// 根据后台流程控制：
// - 如果还是报价（doc_flow_type=QUOTATION 或没 invoice_no）-> 只能看 QUOTATION
// - 如果已被 REJECTED -> 只能看 QUOTATION
$flowStat = strtoupper(trim((string)($row['doc_flow_status'] ?? '')));
$flowType = strtoupper(trim((string)($row['doc_flow_type'] ?? '')));
$hasInvoiceNo = trim((string)($row['invoice_no'] ?? '')) !== '';
if ($flowStat === 'REJECTED' || $flowType === 'QUOTATION' || !$hasInvoiceNo) {
  $doc = 'QUOTATION';
  $_GET['doc'] = 'QUOTATION';
}

// 是否允许看 Invoice / DO（只有已经进 invoice，且未 REJECT）
$canViewInvoiceDo = ($flowStat !== 'REJECTED' && $hasInvoiceNo && $flowType !== 'QUOTATION');

// back 链接（默认回 user/txn/invoices.php）
$rawBack = (string)($_GET['back'] ?? '');
$backUrl = $rawBack !== '' ? $rawBack : url('user/txn/invoices.php');

$page_title = 'Document · #' . $id;
$active_nav = 'invoices';
include __DIR__ . '/../include/header.php';
?>
<style>
/* 让打印内容离页面顶和左边有一点空隙（只影响 user 端外壳） */
.doc-print-wrap {
  margin-top: 20px;
  padding: 20px 24px;
}
</style>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated" style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="<?= h($backUrl) ?>" class="btn btn-light btn-sm">← Back</a>
          <button type="button" class="btn btn-light btn-sm" onclick="(window.vmPrepareAndPrint ? window.vmPrepareAndPrint() : window.print());">Print / PDF</button>
        </div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <a href="<?= h(url('user/txn/txn_doc_in.php?id='.$id.'&customer_id='.$cid.'&doc=QUOTATION&back='.rawurlencode($backUrl))) ?>"
             class="btn btn-light btn-sm<?= $doc === 'QUOTATION' ? ' btn-primary' : '' ?>">
            Quotation
          </a>
          <?php if ($canViewInvoiceDo): ?>
            <a href="<?= h(url('user/txn/txn_doc_in.php?id='.$id.'&customer_id='.$cid.'&doc=INVOICE&back='.rawurlencode($backUrl))) ?>"
               class="btn btn-light btn-sm<?= $doc === 'INVOICE' ? ' btn-primary' : '' ?>">
              Invoice
            </a>
            <a href="<?= h(url('user/txn/txn_doc_in.php?id='.$id.'&customer_id='.$cid.'&doc=DO&back='.rawurlencode($backUrl))) ?>"
               class="btn btn-light btn-sm<?= $doc === 'DO' ? ' btn-primary' : '' ?>">
              DO
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="admin-card" style="padding:0;">
<?php
// admin 模板内部会检测 ALLOW_TXN_DOC_FROM_PORTAL=true：
// - 跳过 require_admin
// - 不再输出 admin header/sidebar，只输出文档内容本身
require __DIR__ . '/../../../public/admin/customers/txn_doc_in.php';
?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

