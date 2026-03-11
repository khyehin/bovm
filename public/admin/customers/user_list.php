<?php
// public/admin/customers/user_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CUSTOMER.USER');   // ★ 需要 CUSTOMER.USER 才能看到 / 新增 login user

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$cid = (int)($_GET['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

// back URL：优先用 ?back=，否则回 customer list
$back = $_GET['back'] ?? url('admin/customers/list.php');

// 取 customer 信息
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

$page_title = t('admin.customer_user.list.page_title', [], 'Customer Logins: ') . ($customer['name'] ?? '');

$errors = [];
$ok = $_GET['ok'] ?? '';

// 处理新增 login user（快速新增）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username   = trim($_POST['username'] ?? '');
  $password   = $_POST['password'] ?? '';
  $full_name  = trim($_POST['full_name'] ?? '');
  $nric       = trim($_POST['nric'] ?? '');
  $email      = trim($_POST['email'] ?? '');
  $phone      = trim($_POST['phone'] ?? '');

  if ($username === '') {
      $errors['username']  = t('admin.customer_user.error.username_required', [], 'Username is required');
  }
  if ($password === '') {
      $errors['password']  = t('admin.customer_user.error.password_required', [], 'Password is required');
  }
  if ($full_name === '') {
      $errors['full_name'] = t('admin.customer_user.error.full_name_required', [], 'Name is required');
  }

  // 全局检查 username 不重复
  if ($username !== '') {
    $st = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $st->execute([':u' => $username]);
    if ($st->fetch()) {
      $errors['username'] = t('admin.customer_user.error.username_exists_global', [], 'Username already exists');
    }
  }

  if (!$errors) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $sql  = "INSERT INTO users
             (username, password_hash, full_name, nric, email, phone,
              role, customer_id, must_change_password, is_active, created_at)
             VALUES
             (:u, :p, :n, :nric, :e, :ph,
              'CUSTOMER', :cid, 1, 1, NOW())";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':u'    => $username,
      ':p'    => $hash,
      ':n'    => $full_name,
      ':nric' => $nric,
      ':e'    => $email,
      ':ph'   => $phone,
      ':cid'  => $cid,
    ]);

    // redirect 回自己，并带回 back
    header('Location: ' . url('admin/customers/user_list.php?customer_id=' . $cid . '&ok=1&back=' . rawurlencode($back)));
    exit;
  }
}

// 取现有 login users
$st = $pdo->prepare("
  SELECT *
  FROM users
  WHERE customer_id = :cid AND role = 'CUSTOMER'
  ORDER BY id ASC
");
$st->execute([':cid' => $cid]);
$users = $st->fetchAll();

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card">
      <div class="admin-card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
          <div class="form-page-eyebrow">
            <?= h(t('admin.customer_user.list.eyebrow', [], 'Customer logins')) ?>
          </div>
          <span>
            <?= h($customer['name']) ?>
            <?php if (!empty($customer['code'])): ?>
              (<?= h($customer['code']) ?>)
            <?php endif; ?>
          </span>
        </div>
        <div>
          <a class="btn btn-light btn-sm" href="<?= h($back) ?>">
            <?= h(t('admin.common.back_arrow', [], '← Back')) ?>
          </a>
        </div>
      </div>

      <?php if ($ok === '1'): ?>
        <div class="alert-success" style="margin-bottom:10px;padding:8px 10px;border-radius:6px;font-size:13px;">
          <?= h(t('admin.customer_user.list.saved', [], 'User saved.')) ?>
        </div>
      <?php endif; ?>

      <h3 style="font-size:15px;margin-bottom:8px;">
        <?= h(t('admin.customer_user.list.existing_title', [], 'Existing Logins')) ?>
      </h3>
      <table class="table">
        <thead>
          <tr>
            <th><?= h(t('admin.customer_user.col.id', [], 'ID')) ?></th>
            <th><?= h(t('admin.customer_user.col.username', [], 'Username')) ?></th>
            <th><?= h(t('admin.customer_user.col.name', [], 'Name')) ?></th>
            <th><?= h(t('admin.customer_user.col.nric', [], 'NRIC / IC')) ?></th>
            <th><?= h(t('admin.customer_user.col.email', [], 'Email')) ?></th>
            <th><?= h(t('admin.customer_user.col.phone', [], 'Phone')) ?></th>
            <th><?= h(t('admin.customer_user.col.active', [], 'Active')) ?></th>
            <th><?= h(t('admin.customer_user.col.action', [], 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr>
            <td colspan="8" style="padding:12px;font-size:13px;color:#6b7280;">
              <?= h(t('admin.customer_user.list.no_users', [], 'No login users yet.')) ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= h($u['id']) ?></td>
            <td><?= h($u['username']) ?></td>
            <td><?= h($u['full_name']) ?></td>
            <td><?= h($u['nric'] ?? '') ?></td>
            <td><?= h($u['email']) ?></td>
            <td><?= h($u['phone']) ?></td>
            <td>
              <?= $u['is_active']
                ? h(t('admin.common.yes', [], 'Yes'))
                : h(t('admin.common.no', [], 'No')) ?>
            </td>
            <td>
              <a class="btn btn-light btn-sm"
                 href="<?= h(url('admin/customers/user_edit.php?id='.$u['id'].'&customer_id='.$cid.'&back=' . rawurlencode($back))) ?>">
                <?= h(t('admin.customer_user.list.btn_edit', [], 'Edit / Reset')) ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="admin-card">
      <div class="admin-card-header" style="margin-bottom:10px;">
        <h3 style="font-size:15px;margin:0;">
          <?= h(t('admin.customer_user.list.add_title', [], 'Add New Login')) ?>
        </h3>
      </div>

      <form method="post" class="form-layout">
        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.username', [], 'Username')) ?> *
            </label>
            <input type="text" name="username" class="form-control"
                   value="<?= h($_POST['username'] ?? '') ?>">
            <?php if (isset($errors['username'])): ?>
              <div class="form-error"><?= h($errors['username']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.password_new', [], 'Password *')) ?>
            </label>
            <input type="password" name="password" class="form-control">
            <?php if (isset($errors['password'])): ?>
              <div class="form-error"><?= h($errors['password']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="form-grid form-grid-3">
          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.full_name', [], 'Full name')) ?> *
            </label>
            <input type="text" name="full_name" class="form-control"
                   value="<?= h($_POST['full_name'] ?? '') ?>">
            <?php if (isset($errors['full_name'])): ?>
              <div class="form-error"><?= h($errors['full_name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.nric', [], 'NRIC / IC')) ?>
            </label>
            <input type="text" name="nric" class="form-control"
                   value="<?= h($_POST['nric'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.email', [], 'Email')) ?>
            </label>
            <input type="email" name="email" class="form-control"
                   value="<?= h($_POST['email'] ?? '') ?>">
          </div>
        </div>

        <div class="form-grid form-grid-2">
          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.phone', [], 'Phone')) ?>
            </label>
            <input type="text" name="phone" class="form-control"
                   value="<?= h($_POST['phone'] ?? '') ?>">
          </div>
        </div>

        <button type="submit" class="btn btn-primary">
          <?= h(t('admin.customer_user.list.btn_create', [], 'Create Login')) ?>
        </button>
      </form>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
