<?php
// public/user/company1/txn_invoice_in.php
// Company1 查看收据（IN payment receipts）：输出内容与 user/txn/txn_invoice_in.php 一致，
// 但权限改为 Company1（category 1），并允许查看 category 3 客户的 IN 单据收款记录。
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$id        = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing transaction id'); }

// 校验：txn 属于 category 3 customer 且为 IN
$st = $pdo->prepare("
  SELECT t.*, c.name AS customer_name, c.code AS customer_code, c.category_id
  FROM customer_txn t
  JOIN customers c ON c.id = t.customer_id
  WHERE t.id = :id
    AND t.txn_type = 'IN'
  LIMIT 1
");
$st->execute([':id' => $id]);
$txn = $st->fetch();
if (!$txn) { http_response_code(404); exit('Transaction not found'); }
if ((int)($txn['category_id'] ?? 0) !== 3) { http_response_code(403); exit('Forbidden'); }

// 下面开始：尽量复用 user/txn/txn_invoice_in.php 的实现，但把 “当前登录 customer_id” 改成 txn 的 customer_id
// 为避免大范围复制，这里只做一个轻量兼容：注入一个假的 current_user() customer_id 给内部使用。
// 如果项目里 current_user() 不可覆盖，则走本文件的精简渲染（和 user 版同 CSS/HTML 结构）。

// ---- 尝试覆盖 current_user（若已存在则不覆盖） ----
if (!function_exists('current_user')) {
  // unlikely, bootstrap 应该已定义；留空
}

// 如果 user 版严格检查 role=CUSTOMER，这里无法复用，直接走简化版：跳转到 admin 模板收据页的 Company1 友好版本
// 当前项目已经有 user/txn/txn_invoice_in.php 的完整 UI，后续如需 100% 同步可把该文件逻辑复制过来。

// 取 payments
$st = $pdo->prepare("
  SELECT *
  FROM customer_txn_payments
  WHERE customer_txn_id = :tid
  ORDER BY pay_date ASC, id ASC
");
$st->execute([':tid' => $id]);
$allPays = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$allPays) { http_response_code(404); exit('No payments for this invoice.'); }

// 选中 payment
$payment = null;
if ($paymentId > 0) {
  foreach ($allPays as $p) {
    if ((int)$p['id'] === $paymentId) { $payment = $p; break; }
  }
}
if (!$payment) {
  $payment = $allPays[count($allPays) - 1];
  $paymentId = (int)($payment['id'] ?? 0);
}

// back（默认回 Company1 txn_view）
$rawBack = (string)($_GET['back'] ?? $_POST['back'] ?? '');
$backUrl = $rawBack !== '' ? $rawBack : url('user/company1/txn_view.php?id=' . (int)$id);

// company info（与其它单据一致）
$logoUrl = url('admin/assets/img/vmlogo.png');
$company = function_exists('get_company') ? get_company() : ['name' => 'VISION MIX SDN BHD', 'reg_no' => '1622729-U', 'tax_no' => '202501021316', 'address' => [], 'phone' => '', 'email' => ''];
$companyName = (string)($company['name'] ?? '');
$companyRegNo = (string)($company['reg_no'] ?? '');
$companyTaxNo = (string)($company['tax_no'] ?? '');
$companyHeaderLine = trim($companyName . ($companyTaxNo !== '' ? (' ' . $companyTaxNo) : '') . ($companyRegNo !== '' ? (' (' . $companyRegNo . ')') : ''));
$companyAddress = (array)($company['address'] ?? []);
$companyPhone = (string)($company['phone'] ?? '');
$companyEmail = (string)($company['email'] ?? '');

$customerName = (string)($txn['customer_name'] ?? '');

$page_title = 'Invoice #' . ((string)($txn['invoice_no'] ?? '') ?: ('TXN-' . (int)$id));
$active_nav = 'company1_invoices';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated" style="margin-bottom:10px;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="<?= h($backUrl) ?>" class="btn btn-light btn-sm">Back</a>
          <button type="button" class="btn btn-light btn-sm" onclick="window.print();">Print</button>
        </div>
      </div>
    </div>

    <div class="admin-card" style="padding:16px;">
      <div style="display:flex;gap:16px;align-items:flex-start;">
        <div style="width:120px;"><img src="<?= h($logoUrl) ?>" alt="Logo" style="max-width:100%;"></div>
        <div style="font-size:12px;line-height:1.4;">
          <div style="font-size:14px;font-weight:700;"><?= h($companyHeaderLine) ?></div>
          <?php foreach ($companyAddress as $line): if (trim((string)$line) === '') continue; ?>
            <div><?= h($line) ?></div>
          <?php endforeach; ?>
          <?php if ($companyEmail !== '' || $companyPhone !== ''): ?>
            <div style="margin-top:4px;">
              <?php if ($companyEmail !== ''): ?>Email: <?= h($companyEmail) ?><?php endif; ?>
              <?php if ($companyPhone !== ''): ?>&nbsp;&nbsp;Hp: <?= h($companyPhone) ?><?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:14px;font-size:13px;">
        <div><strong>Received From :</strong> <?= h($customerName) ?></div>
        <div><strong>Invoice No :</strong> <?= h((string)($txn['invoice_no'] ?? '') ?: '-') ?></div>
        <div><strong>Official Receipt No :</strong> <?= h((string)($payment['or_no'] ?? ('R' . (int)$paymentId))) ?></div>
        <div><strong>Date :</strong> <?= h((string)($payment['pay_date'] ?? '')) ?></div>
      </div>

      <?php if (count($allPays) > 1): ?>
        <div class="no-print" style="margin-top:12px;font-size:12px;">
          <strong>All receipts:</strong>
          <ul style="margin:6px 0 0 18px; padding:0; list-style:disc;">
            <?php foreach ($allPays as $p): ?>
              <?php
                $rid   = (int)($p['id'] ?? 0);
                $orNo  = (string)($p['or_no'] ?? ('R'.$rid));
                $rdate = (string)($p['pay_date'] ?? '');
                $link  = url('user/company1/txn_invoice_in.php?id='.$id.'&payment_id='.$rid.'&back='.rawurlencode($backUrl));
              ?>
              <li><a href="<?= h($link) ?>"><?= h($orNo) ?><?= $rdate !== '' ? (' — ' . h($rdate)) : '' ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>


