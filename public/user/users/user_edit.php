<?php
// public/user/users/user_edit.php
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

$baseCid = (int)($u['customer_id'] ?? 0);
if ($baseCid <= 0) {
    http_response_code(400);
    exit('Missing customer_id');
}

// 当前登录的 customer（用来判断是否为 Company1）
$st = $pdo->prepare("SELECT id, category_id FROM customers WHERE id = :id");
$st->execute([':id' => $baseCid]);
$currentCustomer = $st->fetch();
if (!$currentCustomer) {
    http_response_code(404);
    exit('Customer not found');
}

// 如果是 Company1（category_id = 1），允许通过 ?customer_id=xx 管理其他 customer 的 login users
$cidParam = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
if ((int)($currentCustomer['category_id'] ?? 0) === 1 && $cidParam > 0) {
    $cid = $cidParam;
} else {
    $cid = $baseCid;
}

$user_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(400);
    exit('Missing user id');
}

// 载入被管理的 customer
$st = $pdo->prepare("SELECT id, name, code FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

// 载入 user（必须属于本 customer 且 role=CUSTOMER）
$st = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = :id
      AND customer_id = :cid
      AND role = 'CUSTOMER'
    LIMIT 1
");
$st->execute([':id' => $user_id, ':cid' => $cid]);
$user = $st->fetch();
if (!$user) {
    http_response_code(404);
    exit('Login user not found');
}

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']   ?? '');
    $fullName = trim($_POST['full_name']  ?? '');
    $email    = trim($_POST['email']      ?? '');
    $phone    = trim($_POST['phone']      ?? '');
    $nric     = trim($_POST['nric']       ?? '');
    $password = (string)($_POST['password'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($username === '') {
        $errors['username'] = t('users.error.username_required');
    }
    if ($fullName === '') {
        $errors['full_name'] = t('users.error.full_name_required');
    }

    // username 不重复（全系统，排除自己）
    if ($username !== '') {
        $st = $pdo->prepare("
            SELECT id
            FROM users
            WHERE username = :u
              AND id <> :id
            LIMIT 1
        ");
        $st->execute([
          ':u'  => $username,
          ':id' => $user_id,
        ]);
        if ($st->fetch()) {
            $errors['username'] = t('users.error.username_exists');
        }
    }

    if (!$errors) {
        try {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "UPDATE users
                        SET username = :username,
                            full_name = :full_name,
                            email = :email,
                            phone = :phone,
                            nric  = :nric,
                            is_active = :is_active,
                            password_hash = :password_hash,
                            must_change_password = 1
                        WHERE id = :id
                          AND customer_id = :cid
                          AND role = 'CUSTOMER'";
                $stUpdate = $pdo->prepare($sql);
                $stUpdate->execute([
                    ':username'      => $username,
                    ':full_name'     => $fullName,
                    ':email'         => $email,
                    ':phone'         => $phone,
                    ':nric'          => $nric,
                    ':is_active'     => $isActive,
                    ':password_hash' => $hash,
                    ':id'            => $user_id,
                    ':cid'           => $cid,
                ]);
            } else {
                $sql = "UPDATE users
                        SET username = :username,
                            full_name = :full_name,
                            email = :email,
                            phone = :phone,
                            nric  = :nric,
                            is_active = :is_active
                        WHERE id = :id
                          AND customer_id = :cid
                          AND role = 'CUSTOMER'";
                $stUpdate = $pdo->prepare($sql);
                $stUpdate->execute([
                    ':username'  => $username,
                    ':full_name' => $fullName,
                    ':email'     => $email,
                    ':phone'     => $phone,
                    ':nric'      => $nric,
                    ':is_active' => $isActive,
                    ':id'        => $user_id,
                    ':cid'       => $cid,
                ]);
            }

            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'CUSTOMER.SELF.USER.UPDATE',
                    [
                        'customer_id'      => $cid,
                        'customer_code'    => $customer['code'] ?? null,
                        'user_id'          => $user_id,
                        'username'         => $username,
                        'email'            => $email,
                        'nric'             => $nric,
                        'is_active'        => $isActive,
                        'password_changed' => $password !== '' ? 1 : 0,
                    ],
                    'customer_user',
                    $user_id
                );
            }

            // 重新载入
            $st = $pdo->prepare("
                SELECT *
                FROM users
                WHERE id = :id AND customer_id = :cid AND role = 'CUSTOMER'
            ");
            $st->execute([':id' => $user_id, ':cid' => $cid]);
            $user  = $st->fetch();
            $saved = true;

        } catch (Throwable $e) {
            $errors['general'] = t('users.error.general_failed');
        }
    } else {
        $user['username']  = $username;
        $user['full_name'] = $fullName;
        $user['email']     = $email;
        $user['phone']     = $phone;
        $user['nric']      = $nric;
        $user['is_active'] = $isActive;
    }
}

$page_title = t('users.edit.page_title');
$active_nav = 'users';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main-inner">
  <div class="admin-card admin-card-elevated admin-card-narrow">
    <div class="form-page-header">
      <div>
        <div class="form-page-eyebrow">
          <?= h($customer['code'] ?? '') ?> · <?= h(t('users.edit.eyebrow')) ?>
        </div>
        <h2 class="form-page-title">
          <?= h(t('users.edit.title')) ?>
        </h2>
        <div class="form-page-subtitle">
          <?= h(t('users.edit.subtitle')) ?>
        </div>
      </div>
    </div>

    <?php if ($saved): ?>
      <div class="alert-success" style="margin-bottom:10px;">
        <?= h(t('users.edit.saved')) ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert-error" style="margin-bottom:10px;">
        <?= h($errors['general']) ?>
      </div>
    <?php endif; ?>

    <form method="post" class="form-layout">
      <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
      <input type="hidden" name="customer_id" value="<?= (int)$cid ?>">

      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-title"><?= h(t('users.edit.section_account_title')) ?></div>
          <div class="form-section-desc">
            <?= h(t('users.edit.section_account_desc')) ?>
          </div>
        </div>

        <div class="form-group">
          <label class="field-label"><?= h(t('users.field.username')) ?> *</label>
          <input
            type="text"
            name="username"
            class="form-control"
            value="<?= h($user['username']) ?>">
          <?php if (isset($errors['username'])): ?>
            <div class="form-error"><?= h($errors['username']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="field-label"><?= h(t('users.field.full_name')) ?> *</label>
          <input
            type="text"
            name="full_name"
            class="form-control"
            value="<?= h($user['full_name']) ?>">
          <?php if (isset($errors['full_name'])): ?>
            <div class="form-error"><?= h($errors['full_name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="field-label"><?= h(t('users.field.email')) ?></label>
          <input
            type="email"
            name="email"
            class="form-control"
            value="<?= h($user['email']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label"><?= h(t('users.field.phone')) ?></label>
          <input
            type="text"
            name="phone"
            class="form-control"
            value="<?= h($user['phone']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label"><?= h(t('users.field.nric')) ?></label>
          <input
            type="text"
            name="nric"
            class="form-control"
            value="<?= h($user['nric']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h(t('users.field.password_hint')) ?>
          </label>
          <input type="password" name="password" class="form-control">
        </div>

        <div class="form-group" style="margin-top:10px;">
          <label class="switch-label">
            <span class="switch-text"><?= h(t('users.field.active')) ?></span>
            <label class="switch">
              <input type="checkbox" name="is_active" value="1"
                <?= ($user['is_active'] ? 'checked' : '') ?>>
              <span class="slider"></span>
            </label>
          </label>
        </div>
      </div>

      <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;">
        <div class="form-footer-left">
          <a href="<?= h(url('user/users/users.php')) ?>" class="btn btn-light"><?= h(t('users.btn.back')) ?></a>
        </div>
        <div class="form-footer-right">
          <button type="submit" class="btn btn-primary"> <?= h(t('users.btn.save')) ?></button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
