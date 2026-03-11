<?php
// public/admin/customers/user_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CUSTOMER.USER');   // ★ 需要有 CUSTOMER.USER 才能管理客户登录

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/*
   表： users
   字段：
     id, username, password_hash, full_name, nric, email, phone,
     role, customer_id, must_change_password, is_active, created_at
*/

// ---- 参数 ----
$cid  = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$uid  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

// from list 带来的 back，如果没有就回到该客户 login 列表
$back = $_GET['back'] ?? url('admin/customers/user_list.php?customer_id=' . $cid);

if ($cid <= 0) {
    http_response_code(400);
    exit('Missing customer_id');
}

// ---- 载入 Customer ----
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

// ---- 载入 User（users 表，role = CUSTOMER）----
// 用于 audit 的当前 user 资料（如果是 new 会是空）
if ($uid > 0) {
    $st = $pdo->prepare("
        SELECT *
        FROM users
        WHERE id = :id
          AND customer_id = :cid
          AND role = 'CUSTOMER'
        LIMIT 1
    ");
    $st->execute([':id' => $uid, ':cid' => $cid]);
    $user = $st->fetch();
    if (!$user) {
        http_response_code(404);
        exit('Login user not found');
    }
} else {
    // new
    $user = [
        'id'                  => 0,
        'username'            => '',
        'full_name'           => '',
        'nric'                => '',
        'email'               => '',
        'phone'               => '',
        'is_active'           => 1,
        'must_change_password'=> 1,
    ];
}

// ★ Audit：查看页面（GET）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $act = $user['id'] ? 'CUSTOMER.USER.EDIT.VIEW' : 'CUSTOMER.USER.CREATE.VIEW';
    if (function_exists('audit_log')) {
        audit_log(
            $pdo,
            $act,
            [
                'customer_id'   => $cid,
                'customer_code' => $customer['code'] ?? null,
                'user_id'       => $user['id'] ?? 0,
                'username'      => $user['username'] ?? null,
            ],
            'customer_user',
            $user['id'] ?: null
        );
    }
}

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']   ?? '');
    $fullName = trim($_POST['full_name']  ?? '');
    $nric     = trim($_POST['nric']       ?? '');
    $email    = trim($_POST['email']      ?? '');
    $phone    = trim($_POST['phone']      ?? '');
    $password = (string)($_POST['password'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    // 验证
    if ($username === '') {
        $errors['username'] = t('admin.customer_user.error.username_required', [], 'Username is required');
    }
    if ($fullName === '') {
        $errors['full_name'] = t('admin.customer_user.error.full_name_required', [], 'Name is required');
    }

    // 检查 username 是否重复（同一客户内，别人不能用）
    if ($username !== '') {
        $st = $pdo->prepare("
          SELECT id
          FROM users
          WHERE username = :u
            AND customer_id = :cid
            AND id <> :id
          LIMIT 1
        ");
        $st->execute([
          ':u'   => $username,
          ':cid' => $cid,
          ':id'  => $uid,
        ]);
        if ($st->fetch()) {
            $errors['username'] = t('admin.customer_user.error.username_exists', [], 'Username already exists');
        }
    }

    if (!$errors) {
        try {
            if ($uid > 0) {
                // ===== UPDATE =====
                if ($password !== '') {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $sql = "UPDATE users
                            SET username = :username,
                                full_name = :full_name,
                                nric = :nric,
                                email = :email,
                                phone = :phone,
                                is_active = :is_active,
                                password_hash = :password_hash,
                                must_change_password = 1
                            WHERE id = :id
                              AND customer_id = :cid
                              AND role = 'CUSTOMER'";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        ':username'       => $username,
                        ':full_name'      => $fullName,
                        ':nric'           => $nric,
                        ':email'          => $email,
                        ':phone'          => $phone,
                        ':is_active'      => $isActive,
                        ':password_hash'  => $hash,
                        ':id'             => $uid,
                        ':cid'            => $cid,
                    ]);
                } else {
                    $sql = "UPDATE users
                            SET username = :username,
                                full_name = :full_name,
                                nric = :nric,
                                email = :email,
                                phone = :phone,
                                is_active = :is_active
                            WHERE id = :id
                              AND customer_id = :cid
                              AND role = 'CUSTOMER'";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        ':username'  => $username,
                        ':full_name' => $fullName,
                        ':nric'      => $nric,
                        ':email'     => $email,
                        ':phone'     => $phone,
                        ':is_active' => $isActive,
                        ':id'        => $uid,
                        ':cid'       => $cid,
                    ]);
                }

                // ★ Audit：更新 login user
                if (function_exists('audit_log')) {
                    audit_log(
                        $pdo,
                        'CUSTOMER.USER.UPDATE',
                        [
                            'customer_id'      => $cid,
                            'customer_code'    => $customer['code'] ?? null,
                            'user_id'          => $uid,
                            'username'         => $username,
                            'nric'             => $nric,
                            'email'            => $email,
                            'is_active'        => $isActive,
                            'password_changed' => $password !== '' ? 1 : 0,
                        ],
                        'customer_user',
                        $uid
                    );
                }
            } else {
                // ===== INSERT =====
                if ($password === '') {
                    $errors['password'] = t('admin.customer_user.error.password_required_new', [], 'Password is required for new user');
                    throw new RuntimeException('Password required');
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $sql = "INSERT INTO users
                        (username, password_hash, full_name, nric, email, phone,
                         role, customer_id, must_change_password, is_active, created_at)
                        VALUES
                        (:username, :password_hash, :full_name, :nric, :email, :phone,
                         'CUSTOMER', :customer_id, 1, :is_active, NOW())";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':username'      => $username,
                    ':password_hash' => $hash,
                    ':full_name'     => $fullName,
                    ':nric'          => $nric,
                    ':email'         => $email,
                    ':phone'         => $phone,
                    ':customer_id'   => $cid,
                    ':is_active'     => $isActive,
                ]);
                $uid = (int)$pdo->lastInsertId();

                // ★ Audit：新增 login user
                if (function_exists('audit_log')) {
                    audit_log(
                        $pdo,
                        'CUSTOMER.USER.CREATE',
                        [
                            'customer_id'   => $cid,
                            'customer_code' => $customer['code'] ?? null,
                            'user_id'       => $uid,
                            'username'      => $username,
                            'nric'          => $nric,
                            'email'         => $email,
                            'is_active'     => $isActive,
                        ],
                        'customer_user',
                        $uid
                    );
                }
            }

            // 重新载入数据
            $st = $pdo->prepare("
                SELECT *
                FROM users
                WHERE id = :id AND customer_id = :cid AND role = 'CUSTOMER'
            ");
            $st->execute([':id' => $uid, ':cid' => $cid]);
            $user  = $st->fetch();
            $saved = true;

        } catch (RuntimeException $e) {
            // 已在上面设置 errors，这里不处理
        } catch (Throwable $e) {
            $errors['general'] = $e->getMessage();
        }
    } else {
        // 有错误，用 POST 的值覆盖 user
        $user['username']  = $username;
        $user['full_name'] = $fullName;
        $user['nric']      = $nric;
        $user['email']     = $email;
        $user['phone']     = $phone;
        $user['is_active'] = $isActive;
    }
}

$page_title = t('admin.customer_user.edit.page_title', [], 'Customer login user');

include __DIR__ . '/../include/header.php';

?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h(($customer['code'] ?? '') . ' · ' . t('admin.customer_user.edit.eyebrow', [], 'Login user')) ?>
          </div>
          <h2 class="form-page-title">
            <?= $user['id']
              ? h(t('admin.customer_user.edit.title_edit', [], 'Edit login user'))
              : h(t('admin.customer_user.edit.title_new', [], 'New login user')) ?>
          </h2>
          <div class="form-page-subtitle">
            <?= h(t('admin.customer_user.edit.subtitle', [], 'Manage portal login for this customer.')) ?>
          </div>
        </div>
      </div>

      <?php if ($saved): ?>
        <div class="alert-success" style="margin-bottom:10px;">
          <?= h(t('admin.customer_user.edit.saved', [], 'User saved.')) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:10px;">
          <?= h($errors['general']) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="form-layout">
        <input type="hidden" name="customer_id" value="<?= h($cid) ?>">
        <input type="hidden" name="id" value="<?= h($user['id']) ?>">

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h(t('admin.customer_user.edit.section_account', [], 'Account')) ?>
            </div>
            <div class="form-section-desc">
              <?= h(t(
                'admin.customer_user.edit.section_account_desc',
                [],
                'Username and basic contact info for this login.'
              )) ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.username', [], 'Username')) ?> *
            </label>
            <input
              type="text"
              name="username"
              class="form-control"
              value="<?= h($user['username']) ?>"
            >
            <?php if (isset($errors['username'])): ?>
              <div class="form-error"><?= h($errors['username']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.full_name', [], 'Full name')) ?> *
            </label>
            <input
              type="text"
              name="full_name"
              class="form-control"
              value="<?= h($user['full_name']) ?>"
            >
            <?php if (isset($errors['full_name'])): ?>
              <div class="form-error"><?= h($errors['full_name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.nric', [], 'NRIC / IC')) ?>
            </label>
            <input
              type="text"
              name="nric"
              class="form-control"
              value="<?= h($user['nric'] ?? '') ?>"
            >
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.email', [], 'Email')) ?>
            </label>
            <input
              type="email"
              name="email"
              class="form-control"
              value="<?= h($user['email']) ?>"
            >
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h(t('admin.customer_user.field.phone', [], 'Phone')) ?>
            </label>
            <input
              type="text"
              name="phone"
              class="form-control"
              value="<?= h($user['phone']) ?>"
            >
          </div>

          <div class="form-group">
            <label class="field-label">
              <?php if ($user['id']): ?>
                <?= h(t(
                  'admin.customer_user.field.password_edit',
                  [],
                  'Password (leave blank = no change)'
                )) ?>
              <?php else: ?>
                <?= h(t('admin.customer_user.field.password_new', [], 'Password *')) ?>
              <?php endif; ?>
            </label>
            <input type="password" name="password" class="form-control">
            <?php if (isset($errors['password'])): ?>
              <div class="form-error"><?= h($errors['password']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-group" style="margin-top:10px;">
            <label class="switch-label">
              <span class="switch-text">
                <?= h(t('admin.customer_user.field.active', [], 'Active')) ?>
              </span>
              <label class="switch">
                <input type="checkbox" name="is_active" value="1"
                  <?= ($user['is_active'] ? 'checked' : '') ?>>
                <span class="slider"></span>
              </label>
            </label>
          </div>
        </div>

        <div class="form-footer-row">
          <div class="form-footer-left">
            <a href="<?= h($back) ?>"
               class="btn btn-light">
              <?= h(t('admin.common.back', [], 'Back')) ?>
            </a>
          </div>
          <div class="form-footer-right">
            <button type="submit" class="btn btn-primary">
              <?= h(t('admin.common.save', [], 'Save')) ?>
            </button>
          </div>
        </div>

      </form>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
