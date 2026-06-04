<?php
// public/user/company1/customers.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$ok = (string)($_GET['ok'] ?? '');

// list customers category_id=3 only
$rows = [];
try {
  $q = trim((string)($_GET['q'] ?? ''));
  $where = ["category_id = 3"];
  $params = [];
  if ($q !== '') {
    $where[] = "(code LIKE :q OR name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
  }
  $st = $pdo->prepare("SELECT id, code, name, contact_name, contact_phone, contact_email, is_active FROM customers WHERE " . implode(' AND ', $where) . " ORDER BY name ASC, id ASC LIMIT 2000");
  $st->execute($params);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  $rows = [];
}

// 聚合每个 customer 的 IN / Pending（简化版：只看 IN invoice）
$summary = [];
if ($rows) {
  $ids = array_map(fn($r) => (int)($r['id'] ?? 0), $rows);
  $ids = array_values(array_filter($ids, fn($v) => $v > 0));
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
      $sql = "
        SELECT t.customer_id,
               SUM(
                 CASE
                   WHEN t.txn_type='IN'
                        AND UPPER(COALESCE(t.in_kind,'')) LIKE '%INVOICE%'
                        AND UPPER(COALESCE(t.doc_flow_status,'')) <> 'REJECTED'
                   THEN COALESCE(t.order_total, t.amount)
                   ELSE 0
                 END
               ) AS total_in,
               SUM(
                 CASE
                   WHEN t.txn_type='IN'
                        AND UPPER(COALESCE(t.in_kind,'')) LIKE '%INVOICE%'
                        AND t.status <> 'CONFIRMED'
                        AND UPPER(COALESCE(t.doc_flow_status,'')) <> 'REJECTED'
                   THEN GREATEST(0, COALESCE(t.order_total, t.amount) - COALESCE(p.paid_total, 0))
                   ELSE 0
                 END
               ) AS pending_in
        FROM customer_txn t
        LEFT JOIN (
          SELECT customer_txn_id, SUM(amount) AS paid_total
          FROM customer_txn_payments
          GROUP BY customer_txn_id
        ) p ON p.customer_txn_id = t.id
        WHERE t.customer_id IN ($in)
        GROUP BY t.customer_id
      ";
      $st = $pdo->prepare($sql);
      $st->execute($ids);
      foreach ($st->fetchAll() as $s) {
        $cid = (int)($s['customer_id'] ?? 0);
        if ($cid > 0) {
          $summary[$cid] = [
            'in'      => (float)($s['total_in'] ?? 0),
            'pending' => (float)($s['pending_in'] ?? 0),
          ];
        }
      }
    } catch (Throwable $e) {
      $summary = [];
    }
  }
}

$page_title = 'Customers';
$active_nav = 'company1_customers';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow">
      <div class="form-page-header" style="margin-bottom:14px;">
        <div>
          <div class="form-page-eyebrow">Customers</div>
          <h2 class="form-page-title">Customers (Category 3)</h2>
          <div class="form-page-subtitle">Customers that Company1 can create quotations / invoices for.</div>
        </div>
        <div class="form-page-meta" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
          <a href="<?= h(url('user/company1/invoices.php')) ?>" class="btn btn-light">Invoices &amp; Quotations</a>
          <a href="<?= h(url('user/company1/customer_add.php')) ?>" class="btn btn-primary">+ Add customer</a>
        </div>
      </div>

      <?php if ($ok === '1'): ?>
        <div class="alert-success" style="margin-bottom:10px;">Customer created.</div>
      <?php elseif ($ok === '2'): ?>
        <div class="alert-success" style="margin-bottom:10px;">Customer updated.</div>
      <?php endif; ?>

      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="text" name="q" class="form-control" style="min-width:240px;" placeholder="Search code / name" value="<?= h($_GET['q'] ?? '') ?>">
        <button type="submit" class="btn btn-primary">Search</button>
        <a class="btn btn-light" href="<?= h(url('user/company1/customers.php')) ?>">Reset</a>
      </form>

      <div style="border:1px solid var(--border); border-radius:12px; background:#fff; overflow-x:auto; overflow-y:visible;">
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#f3f4f6;">
              <th style="padding:10px; text-align:left; font-size:12px;">Name</th>
              <th style="padding:10px; text-align:right; font-size:12px;">IN</th>
              <th style="padding:10px; text-align:right; font-size:12px;">Pending</th>
              <th style="padding:10px; text-align:right; font-size:12px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="4" style="padding:12px;color:#6b7280;">No customers.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <?php
                  $cidRow = (int)($r['id'] ?? 0);
                  $sum = $summary[$cidRow] ?? ['in' => 0.0, 'pending' => 0.0];
                ?>
                <tr style="border-top:1px solid var(--border);">
                  <td style="padding:10px;font-size:12px;">
                    <div style="font-weight:600;"><?= h($r['name'] ?? '') ?></div>
                    <div style="font-size:11px;color:#6b7280;"><?= h($r['code'] ?? '') ?></div>
                  </td>
                  <td style="padding:10px;font-size:12px;text-align:right;">
                    MYR <?= number_format($sum['in'], 2) ?>
                  </td>
                  <td style="padding:10px;font-size:12px;text-align:right;">
                    MYR <?= number_format($sum['pending'], 2) ?>
                  </td>
                  <td style="padding:10px;font-size:12px;text-align:right;">
                    <div class="actions-menu">
                      <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                      <div class="actions-menu-dropdown">
                        <a class="actions-menu-item" href="<?= h(url('user/company1/txn_list.php?customer_id=' . $cidRow)) ?>">Transactions</a>
                        <a class="actions-menu-item" href="<?= h(url('user/company1/invoices.php?customer_id=' . $cidRow)) ?>">Invoices</a>
                        <a class="actions-menu-item" href="<?= h(url('user/company1/customer_edit.php?id=' . $cidRow)) ?>">Edit details</a>
                        <a class="actions-menu-item" href="<?= h(url('user/users/users.php?customer_id=' . $cidRow)) ?>">Add login user</a>
                      </div>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

