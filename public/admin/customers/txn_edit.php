<?php
// public/admin/customers/txn_edit.php
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

function tt(string $key, array $vars = [], string $fallback = ''): string {
  if (function_exists('t')) return t($key, $vars, $fallback);
  return $fallback !== '' ? $fallback : $key;
}

$id          = (int)($_GET['id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);
$back        = $_GET['back'] ?? '';

// 如果有 id = 编辑模式：直接根据 txn_type 跳去对应页面
if ($id > 0) {
    $st = $pdo->prepare("SELECT id, customer_id, txn_type FROM customer_txn WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();

    if (!$row) {
        http_response_code(404);
        exit('Transaction not found');
    }

    $customer_id = (int)$row['customer_id'];
    $direction   = ($row['txn_type'] === 'OUT') ? 'out' : 'in';

    $target = $direction === 'out'
        ? url('admin/customers/txn_edit_out.php?id=' . $row['id'])
        : url('admin/customers/txn_edit_in.php?id=' . $row['id']);

    if ($back !== '') {
        $join = (strpos($target, '?') === false) ? '?' : '&';
        $target .= $join . 'back=' . urlencode($back);
    }

    header('Location: ' . $target);
    exit;
}

// 新建模式必须有 customer_id
if ($customer_id <= 0) {
    http_response_code(400);
    exit('Missing customer_id');
}

// 载入 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $customer_id]);
$customer = $st->fetch();

if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

// 页面标题：沿用 admin.customer_txn 的 new 标题
$page_title = tt('admin.customer_txn.page_title.new', [], 'New Transaction');

include __DIR__ . '/../include/header.php';
?>

<style>
  /* 让 IN / OUT / Allocate 三个 block 高度一致、按钮贴底 */
  .txn-type-grid {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: stretch;
  }
  .txn-type-item {
    flex: 1 1 0;
    min-width: 220px;
    display: flex;
    flex-direction: column;
  }
  .txn-type-title {
    font-weight: 600;
    margin-bottom: 4px;
  }
  .txn-type-desc {
    font-size: 13px;
    color: #4b5563;
    margin-bottom: 8px;
  }
  .txn-type-actions {
    margin-top: auto; /* 把按钮推到底部，对齐一条线 */
  }
  .txn-type-actions .btn {
    width: 100%;
    justify-content: center;
  }

  /* 中等屏：2 列 + 1 列 */
  @media (max-width: 1024px) {
    .txn-type-item {
      flex: 1 1 calc(50% - 8px);
    }
  }

  /* 小屏：一行一个，上下排 */
  @media (max-width: 640px) {
    .txn-type-item {
      flex: 1 1 100%;
    }
  }
</style>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h(tt('admin.customer_txn.select.eyebrow', [], 'New transaction')) ?>
          </div>
          <h2 class="form-page-title">
            <?= h($customer['name'] ?: tt('admin.customers.edit.title_fallback', [], 'Customer')) ?>
          </h2>
          <div class="form-page-subtitle">
            <?= h(tt(
              'admin.customer_txn.select.subtitle',
              [],
              'Choose whether this is an IN (money coming in from customer), an OUT (payout to customer), or allocate remaining IN balance to another customer using FIFO.'
            )) ?>
          </div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $customer['id'])) ?>"
             class="btn btn-light">
            ← <?= h(tt('admin.customer_txn.back_to_list', [], 'Back to transactions')) ?>
          </a>
        </div>
      </div>

      <div class="form-section">
        <div class="form-section-header">
          <div>
            <div class="form-section-title">
              <?= h(tt('admin.customer_txn.select.section_title', [], 'Select action')) ?>
            </div>
            <div class="form-section-desc">
              <?= h(tt(
                'admin.customer_txn.select.section_desc',
                [],
                'IN = deposit / top-up / payment received. OUT = refund / withdrawal / settlement. Allocate = use remaining IN balance (FIFO) to contra with other customers.'
              )) ?>
            </div>
          </div>
        </div>

        <div class="txn-type-grid">

          <!-- IN -->
          <div class="txn-type-item">
            <div class="txn-type-title">
              <?= h(tt('admin.customer_txn.select.in_title', [], 'IN (money in)')) ?>
            </div>
            <p class="txn-type-desc">
              <?= h(tt(
                'admin.customer_txn.select.in_desc',
                [],
                'Use this when customer pays in. Can be full payment or partial payments.'
              )) ?>
            </p>
            <div class="txn-type-actions">
              <a href="<?= h(url('admin/customers/txn_edit_in.php?customer_id=' . $customer['id'])) ?>"
                 class="btn btn-light">
                <?= h(tt('admin.customer_txn.select.in_btn', [], '+ Create IN transaction')) ?>
              </a>
            </div>
          </div>

          <!-- OUT -->
          <div class="txn-type-item">
            <div class="txn-type-title">
              <?= h(tt('admin.customer_txn.select.out_title', [], 'OUT (payout)')) ?>
            </div>
            <p class="txn-type-desc">
              <?= h(tt(
                'admin.customer_txn.select.out_desc',
                [],
                'Use this when you pay out money to this customer (refund, withdrawal, settlement, etc.).'
              )) ?>
            </p>
            <div class="txn-type-actions">
              <a href="<?= h(url('admin/customers/txn_edit_out.php?customer_id=' . $customer['id'])) ?>"
                 class="btn btn-light">
                <?= h(tt('admin.customer_txn.select.out_btn', [], '+ Create OUT transaction')) ?>
              </a>
            </div>
          </div>

          <!-- ALLOCATE (FIFO) -->
          <div class="txn-type-item">
            <div class="txn-type-title">
              <?= h(tt('admin.customer_txn.select.alloc_title', [], 'Allocate (FIFO)')) ?>
            </div>
            <p class="txn-type-desc">
              <?= h(tt(
                'admin.customer_txn.select.alloc_desc',
                [],
                'Allocate remaining balance from this customer\'s IN transactions to another customer using FIFO (oldest IN used first).'
              )) ?>
            </p>
            <div class="txn-type-actions">
              <a href="<?= h(url('admin/customers/txn_allocate.php?customer_id=' . $customer['id'])) ?>"
                 class="btn btn-light">
                <?= h(tt('admin.customer_txn.select.alloc_btn', [], '→ Allocate using FIFO')) ?>
              </a>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
