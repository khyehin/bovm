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

$errors = [];
$ok = (string)($_GET['ok'] ?? '');

// create new customer (force category_id=3)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['_action'] ?? '') === 'create_customer') {
  $code = trim((string)($_POST['code'] ?? ''));
  $name = trim((string)($_POST['name'] ?? ''));
  $contact_name = trim((string)($_POST['contact_name'] ?? ''));
  $contact_phone = trim((string)($_POST['contact_phone'] ?? ''));
  $contact_email = trim((string)($_POST['contact_email'] ?? ''));

  if ($code === '') $errors['code'] = 'Code is required';
  if ($name === '') $errors['name'] = 'Name is required';

  if (!$errors) {
    try {
      $st = $pdo->prepare("
        INSERT INTO customers
          (category_id, code, name, contact_name, contact_phone, contact_email, is_active, created_at)
        VALUES
          (3, :code, :name, :contact_name, :contact_phone, :contact_email, 1, NOW())
      ");
      $st->execute([
        ':code' => $code,
        ':name' => $name,
        ':contact_name' => $contact_name,
        ':contact_phone' => $contact_phone,
        ':contact_email' => $contact_email,
      ]);
      header('Location: ' . url('user/company1/customers.php?ok=1'));
      exit;
    } catch (Throwable $e) {
      $errors['global'] = 'Create failed.';
    }
  }
}

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
        <div class="form-page-meta">
          <a href="<?= h(url('user/company1/invoices.php')) ?>" class="btn btn-light">Invoices &amp; Quotations</a>
        </div>
      </div>

      <?php if ($ok === '1'): ?>
        <div class="alert-success" style="margin-bottom:10px;">Customer created.</div>
      <?php endif; ?>
      <?php if (!empty($errors['global'])): ?>
        <div class="alert-error" style="margin-bottom:10px;"><?= h($errors['global']) ?></div>
      <?php endif; ?>

      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;">
        <input type="text" name="q" class="form-control" style="min-width:240px;" placeholder="Search code / name" value="<?= h($_GET['q'] ?? '') ?>">
        <button type="submit" class="btn btn-primary">Search</button>
        <a class="btn btn-light" href="<?= h(url('user/company1/customers.php')) ?>">Reset</a>
      </form>

      <div style="border:1px solid var(--border); border-radius:12px; overflow:hidden; background:#fff; margin-bottom:16px;">
        <table style="width:100%; border-collapse:collapse;">
          <thead>
            <tr style="background:#f3f4f6;">
              <th style="padding:10px; text-align:left; font-size:12px;">Code</th>
              <th style="padding:10px; text-align:left; font-size:12px;">Name</th>
              <th style="padding:10px; text-align:left; font-size:12px;">Contact</th>
              <th style="padding:10px; text-align:left; font-size:12px;">Email</th>
              <th style="padding:10px; text-align:left; font-size:12px;">Status</th>
              <th style="padding:10px; text-align:right; font-size:12px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" style="padding:12px;color:#6b7280;">No customers.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr style="border-top:1px solid var(--border);">
                  <td style="padding:10px;font-size:12px;"><?= h($r['code'] ?? '') ?></td>
                  <td style="padding:10px;font-size:12px;"><?= h($r['name'] ?? '') ?></td>
                  <td style="padding:10px;font-size:12px;"><?= h($r['contact_name'] ?? '') ?> <?= h($r['contact_phone'] ?? '') ?></td>
                  <td style="padding:10px;font-size:12px;"><?= h($r['contact_email'] ?? '') ?></td>
                  <td style="padding:10px;font-size:12px;"><?= !empty($r['is_active']) ? 'Active' : 'Inactive' ?></td>
                  <td style="padding:10px;font-size:12px;text-align:right;display:flex;gap:6px;justify-content:flex-end;">
                    <a class="btn btn-light btn-sm" href="<?= h(url('user/company1/invoices.php?customer_id=' . (int)$r['id'])) ?>">
                      Invoices / Quotations
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="font-weight:700; margin-bottom:8px;">Add new customer (force Category 3)</div>
      <form method="post" style="display:grid; gap:10px; max-width:520px;">
        <input type="hidden" name="_action" value="create_customer">

        <div>
          <label class="field-label">Code</label>
          <input type="text" name="code" class="form-control" value="<?= h($_POST['code'] ?? '') ?>">
          <?php if (!empty($errors['code'])): ?><div class="form-error"><?= h($errors['code']) ?></div><?php endif; ?>
        </div>
        <div>
          <label class="field-label">Name</label>
          <input type="text" name="name" class="form-control" value="<?= h($_POST['name'] ?? '') ?>">
          <?php if (!empty($errors['name'])): ?><div class="form-error"><?= h($errors['name']) ?></div><?php endif; ?>
        </div>
        <div>
          <label class="field-label">Contact name</label>
          <input type="text" name="contact_name" class="form-control" value="<?= h($_POST['contact_name'] ?? '') ?>">
        </div>
        <div>
          <label class="field-label">Contact phone</label>
          <input type="text" name="contact_phone" class="form-control" value="<?= h($_POST['contact_phone'] ?? '') ?>">
        </div>
        <div>
          <label class="field-label">Contact email</label>
          <input type="email" name="contact_email" class="form-control" value="<?= h($_POST['contact_email'] ?? '') ?>">
        </div>
        <div>
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

