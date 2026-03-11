<?php
// public/user/users/users.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/i18n.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
    http_response_code(403);
    exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
    http_response_code(400);
    exit('Missing customer_id');
}

// 取 customer，只用 name/code
$st = $pdo->prepare("SELECT id, name, code FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

$page_title = t('users.list.page_title');
$active_nav = 'users';

$errors = [];
$ok = $_GET['ok'] ?? '';

// 处理新增 login user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $password  = (string)($_POST['password'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $nric      = trim($_POST['nric'] ?? '');

    if ($username === '')  $errors['username']  = t('users.error.username_required');
    if ($password === '')  $errors['password']  = t('users.error.password_required');
    if ($full_name === '') $errors['full_name'] = t('users.error.full_name_required');

    // 全系统检查 username 不重复
    if ($username !== '') {
        $st = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
        $st->execute([':u' => $username]);
        if ($st->fetch()) {
            $errors['username'] = t('users.error.username_exists');
        }
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $sql  = "INSERT INTO users
                 (username, password_hash, full_name, email, phone, nric,
                  role, customer_id, must_change_password, is_active, created_at)
                 VALUES
                 (:u, :p, :n, :e, :ph, :nric,
                  'CUSTOMER', :cid, 1, 1, NOW())";
        $st = $pdo->prepare($sql);
        $st->execute([
          ':u'    => $username,
          ':p'    => $hash,
          ':n'    => $full_name,
          ':e'    => $email,
          ':ph'   => $phone,
          ':nric' => $nric,
          ':cid'  => $cid,
        ]);

        $newId = (int)$pdo->lastInsertId();

        // 审计
        if (function_exists('audit_log')) {
            audit_log(
                $pdo,
                'CUSTOMER.SELF.USER.CREATE',
                [
                    'customer_id'   => $cid,
                    'customer_code' => $customer['code'] ?? null,
                    'user_id'       => $newId,
                    'username'      => $username,
                    'email'         => $email,
                    'nric'          => $nric,
                ],
                'customer_user',
                $newId
            );
        }

        header('Location: ' . url('user/users/users.php?ok=1'));
        exit;
    }
}

// 取现有 login users（只看自己 customer）
$st = $pdo->prepare("
    SELECT id, username, full_name, email, phone, nric, is_active, last_login_at
    FROM users
    WHERE customer_id = :cid
      AND role = 'CUSTOMER'
    ORDER BY id ASC
");
$st->execute([':cid' => $cid]);
$users = $st->fetchAll();

include __DIR__ . '/../include/header.php';
?>

<div class="admin-card">
  <div class="admin-card-header" style="display:flex;justify-content:space-between;align-items:center;">
    <div>
      <div class="form-page-eyebrow"><?= h(t('users.list.eyebrow')) ?></div>
      <h2 class="form-page-title" style="font-size:18px;">
        <?= h($customer['name']) ?>
        <?php if (!empty($customer['code'])): ?>
          <span style="font-size:13px;font-weight:500;color:#6b7280;">
            (<?= h($customer['code']) ?>)
          </span>
        <?php endif; ?>
      </h2>
      <div class="form-page-subtitle">
        <?= h(t('users.list.subtitle')) ?>
      </div>
    </div>
  </div>

  <?php if ($ok === '1'): ?>
    <div class="alert-success" style="margin-bottom:10px;">
      <?= h(t('users.list.created')) ?>
    </div>
  <?php endif; ?>

  <h3 style="font-size:15px;margin-bottom:8px;"><?= h(t('users.list.existing_title')) ?></h3>
  <table class="table">
    <thead>
      <tr>
        <th style="width:50px;"><?= h(t('users.col.id')) ?></th>
        <th style="width:140px;"><?= h(t('users.col.username')) ?></th>
        <th><?= h(t('users.col.name')) ?></th>
        <th style="width:180px;"><?= h(t('users.col.email')) ?></th>
        <th style="width:120px;"><?= h(t('users.col.phone')) ?></th>
        <th style="width:120px;"><?= h(t('users.col.nric')) ?></th>
        <th style="width:70px;"><?= h(t('users.col.active')) ?></th>
        <th style="width:80px;"><?= h(t('users.col.action')) ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$users): ?>
      <tr>
        <td colspan="8" style="padding:12px;font-size:13px;color:#6b7280;">
          <?= h(t('users.list.no_users')) ?>
        </td>
      </tr>
    <?php else: ?>
      <?php foreach ($users as $usr): ?>
        <tr>
          <td><?= (int)$usr['id'] ?></td>
          <td><?= h($usr['username']) ?></td>
          <td><?= h($usr['full_name']) ?></td>
          <td><?= h($usr['email']) ?></td>
          <td><?= h($usr['phone']) ?></td>
          <td><?= h($usr['nric']) ?></td>
          <td><?= $usr['is_active'] ? h(t('common.yes')) : h(t('common.no')) ?></td>
          <td>
            <a class="btn btn-light btn-sm"
               href="<?= h(url('user/users/user_edit.php?id='.(int)$usr['id'])) ?>">
              <?= h(t('users.list.btn_edit')) ?>
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
    <h3 style="font-size:15px;margin:0;"><?= h(t('users.list.add_title')) ?></h3>
  </div>

  <form method="post" class="form-layout">
    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.username')) ?> *</label>
        <input type="text" name="username" class="form-control"
               value="<?= h($_POST['username'] ?? '') ?>">
        <?php if (isset($errors['username'])): ?>
          <div class="form-error"><?= h($errors['username']) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.password')) ?> *</label>
        <input type="password" name="password" class="form-control">
        <?php if (isset($errors['password'])): ?>
          <div class="form-error"><?= h($errors['password']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="form-grid form-grid-2">
      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.full_name')) ?> *</label>
        <input type="text" name="full_name" class="form-control"
               value="<?= h($_POST['full_name'] ?? '') ?>">
        <?php if (isset($errors['full_name'])): ?>
          <div class="form-error"><?= h($errors['full_name']) ?></div>
        <?php endif; ?>
      </div>

      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.email')) ?></label>
        <input type="email" name="email" class="form-control"
               value="<?= h($_POST['email'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.phone')) ?></label>
        <input type="text" name="phone" class="form-control"
               value="<?= h($_POST['phone'] ?? '') ?>">
      </div>

      <div class="form-group">
        <label class="field-label"><?= h(t('users.field.nric')) ?></label>
        <input type="text" name="nric" class="form-control"
               value="<?= h($_POST['nric'] ?? '') ?>">
      </div>
    </div>

    <button type="submit" class="btn btn-primary">
      <?= h(t('users.list.btn_create')) ?>
    </button>
  </form>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
