<?php
// public/admin/users/user_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
require_perm('USER.MNG');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

$id    = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;
$page_title = $isNew ? 'New User' : 'Edit User';

// 所有角色供勾选
$st = $pdo->query("SELECT * FROM roles WHERE is_active = 1 ORDER BY name ASC");
$allRoles = $st->fetchAll();

// 默认数据
$data = [
    'username'            => '',
    'full_name'           => '',
    'email'               => '',
    'phone'               => '',
    'nric'                => '',
    'role'                => 'ADMIN',   // 这里只管理后台 admin
    'is_active'           => 1,
    'must_change_password'=> 1,
];

$userRolesIds = []; // 这个 user 现在拥有哪些 role_id
$errors = [];
$ok     = $_GET['ok'] ?? '';

if (!$isNew) {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'ADMIN'");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit('User not found');
    }
    $data = array_merge($data, $row);

    // load roles
    $st2 = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = :uid");
    $st2->execute([':uid' => $id]);
    $userRolesIds = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN));
}

// ★ Audit：查看页面（GET）
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('audit_log')) {
    $act = $isNew ? 'ADMIN.USER.CREATE.VIEW' : 'ADMIN.USER.EDIT.VIEW';
    audit_log(
        $pdo,
        $act,
        [
            'user_id'  => $id,
            'username' => $data['username'] ?? '',
        ],
        'admin_user',
        $isNew ? null : $id
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['username']  = trim($_POST['username'] ?? '');
    $data['full_name'] = trim($_POST['full_name'] ?? '');
    $data['email']     = trim($_POST['email'] ?? '');
    $data['phone']     = trim($_POST['phone'] ?? '');
    $data['nric']      = trim($_POST['nric'] ?? '');
    $data['role']      = 'ADMIN'; // 这里固定 admin
    $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    $password          = $_POST['password'] ?? '';
    $password_confirm  = $_POST['password_confirm'] ?? '';

    $postedRoleIds = $_POST['roles'] ?? [];
    $newRoleIds    = [];
    foreach ($postedRoleIds as $rid) {
        $rid = (int)$rid;
        if ($rid > 0) {
            $newRoleIds[] = $rid;
        }
    }
    $userRolesIds = $newRoleIds;

    // 校验
    if ($data['username'] === '') {
        $errors['username'] = $hasT
            ? t('admin.users_admin.edit.error.username_required', [], 'Username is required')
            : 'Username is required';
    }
    if ($data['full_name'] === '') {
        $errors['full_name'] = $hasT
            ? t('admin.users_admin.edit.error.fullname_required', [], 'Full name is required')
            : 'Full name is required';
    }

    if ($isNew) {
        if ($password === '') {
            $errors['password'] = $hasT
                ? t('admin.users_admin.edit.error.password_required', [], 'Password is required')
                : 'Password is required';
        } elseif ($password !== $password_confirm) {
            $errors['password'] = $hasT
                ? t('admin.users_admin.edit.error.password_mismatch', [], 'Password and confirm do not match')
                : 'Password and confirm do not match';
        }
    } else {
        if ($password !== '' && $password !== $password_confirm) {
            $errors['password'] = $hasT
                ? t('admin.users_admin.edit.error.password_mismatch', [], 'Password and confirm do not match')
                : 'Password and confirm do not match';
        }
    }

    // username 唯一
    $sqlCheck = "SELECT COUNT(*) FROM users WHERE username = :u AND id <> :id";
    $stCheck  = $pdo->prepare($sqlCheck);
    $stCheck->execute([
        ':u'  => $data['username'],
        ':id' => $id,
    ]);
    if ((int)$stCheck->fetchColumn() > 0) {
        $errors['username'] = $hasT
            ? t('admin.users_admin.edit.error.username_used', [], 'Username already in use')
            : 'Username already in use';
    }

    if (!$errors) {
        if ($isNew) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stIns = $pdo->prepare("
                INSERT INTO users
                  (username, password_hash, full_name, email, phone, nric,
                   role, customer_id, must_change_password, is_active, created_at, updated_at)
                VALUES
                  (:username, :password_hash, :full_name, :email, :phone, :nric,
                   'ADMIN', NULL, 1, :is_active, NOW(), NOW())
            ");
            $stIns->execute([
                ':username'      => $data['username'],
                ':password_hash' => $hash,
                ':full_name'     => $data['full_name'],
                ':email'         => $data['email'] ?: null,
                ':phone'         => $data['phone'] ?: null,
                ':nric'          => $data['nric']  ?: null,
                ':is_active'     => $data['is_active'],
            ]);

            $id = (int)$pdo->lastInsertId();

            // 角色
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid")->execute([':uid' => $id]);
            if ($userRolesIds) {
                $insRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)");
                foreach ($userRolesIds as $rid) {
                    $insRole->execute([
                        ':uid' => $id,
                        ':rid' => $rid,
                    ]);
                }
            }

            // ★ Audit：新增 admin user
            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'ADMIN.USER.CREATE',
                    [
                        'user_id'   => $id,
                        'username'  => $data['username'],
                        'is_active' => $data['is_active'],
                        'roles'     => $userRolesIds,
                    ],
                    'admin_user',
                    $id
                );
            }

        } else {
            // update 基本资料
            $stUpd = $pdo->prepare("
                UPDATE users SET
                  username  = :username,
                  full_name = :full_name,
                  email     = :email,
                  phone     = :phone,
                  nric      = :nric,
                  is_active = :is_active,
                  updated_at= NOW()
                WHERE id = :id AND role = 'ADMIN'
            ");
            $stUpd->execute([
                ':username'  => $data['username'],
                ':full_name' => $data['full_name'],
                ':email'     => $data['email'] ?: null,
                ':phone'     => $data['phone'] ?: null,
                ':nric'      => $data['nric']  ?: null,
                ':is_active' => $data['is_active'],
                ':id'        => $id,
            ]);

            $passwordChanged = false;

            // 如有新密码 → 更新密码 & must_change_password=0
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("
                    UPDATE users
                    SET password_hash = :hash,
                        must_change_password = 0,
                        updated_at = NOW()
                    WHERE id = :id
                ")->execute([
                    ':hash' => $hash,
                    ':id'   => $id,
                ]);
                $passwordChanged = true;
            }

            // 更新角色
            $pdo->prepare("DELETE FROM user_roles WHERE user_id = :uid")->execute([':uid' => $id]);
            if ($userRolesIds) {
                $insRole = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)");
                foreach ($userRolesIds as $rid) {
                    $insRole->execute([
                        ':uid' => $id,
                        ':rid' => $rid,
                    ]);
                }
            }

            // ★ Audit：更新 admin user
            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'ADMIN.USER.UPDATE',
                    [
                        'user_id'          => $id,
                        'username'         => $data['username'],
                        'is_active'        => $data['is_active'],
                        'roles'            => $userRolesIds,
                        'password_changed' => $passwordChanged ? 1 : 0,
                    ],
                    'admin_user',
                    $id
                );
            }
        }

        header('Location: ' . url('admin/users/list.php?ok=1'));
        exit;
    }
}

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-narrow admin-card-elevated">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.users_admin.edit.eyebrow', [], 'Security') : 'Security') ?>
          </div>
          <h2 class="form-page-title">
            <?= $isNew
              ? h($hasT ? t('admin.users_admin.edit.title_new', [], 'New user') : 'New user')
              : h($hasT ? t('admin.users_admin.edit.title_edit', [], 'Edit user') : 'Edit user') ?>
          </h2>
          <div class="form-page-subtitle">
            <?= h($hasT
                ? t('admin.users_admin.edit.subtitle', [], 'Internal admin account and its roles.')
                : 'Internal admin account and its roles.'
            ) ?>
          </div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('admin/users/list.php')) ?>" class="btn btn-light">
            <?= h($hasT ? t('admin.users_admin.edit.back_to_list', [], '← Back to users') : '← Back to users') ?>
          </a>
        </div>
      </div>

      <form method="post" class="form-layout">

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.users_admin.edit.section.account_title', [], 'Account') : 'Account') ?>
            </div>
            <div class="form-section-desc">
              <?= h($hasT ? t('admin.users_admin.edit.section.account_desc', [], 'Login username & password.') : 'Login username & password.') ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.users_admin.edit.field.username', [], 'Username') : 'Username') ?>
              <span class="field-required">*</span>
            </label>
            <input type="text" name="username" class="form-control"
                   value="<?= h($data['username']) ?>">
            <?php if (isset($errors['username'])): ?>
              <div class="form-error"><?= h($errors['username']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">
                <?= $isNew
                    ? h($hasT ? t('admin.users_admin.edit.field.password_new', [], 'Password') : 'Password')
                    : h($hasT ? t('admin.users_admin.edit.field.password_change', [], 'New password') : 'New password') ?>
                <?php if ($isNew): ?>
                  <span class="field-required">*</span>
                <?php endif; ?>
              </label>
              <input type="password" name="password" class="form-control" autocomplete="off">
              <?php if (isset($errors['password'])): ?>
                <div class="form-error"><?= h($errors['password']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.users_admin.edit.field.confirm_password', [], 'Confirm password') : 'Confirm password') ?>
              </label>
              <input type="password" name="password_confirm" class="form-control" autocomplete="off">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.users_admin.edit.section.profile_title', [], 'Profile') : 'Profile') ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.users_admin.edit.field.full_name', [], 'Full name') : 'Full name') ?>
              <span class="field-required">*</span>
            </label>
            <input type="text" name="full_name" class="form-control"
                   value="<?= h($data['full_name']) ?>">
            <?php if (isset($errors['full_name'])): ?>
              <div class="form-error"><?= h($errors['full_name']) ?></div>
            <?php endif; ?>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.users_admin.edit.field.email', [], 'Email') : 'Email') ?>
              </label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($data['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.users_admin.edit.field.phone', [], 'Phone') : 'Phone') ?>
              </label>
              <input type="text" name="phone" class="form-control"
                     value="<?= h($data['phone'] ?? '') ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.users_admin.edit.field.nric', [], 'NRIC') : 'NRIC') ?>
            </label>
            <input type="text" name="nric" class="form-control"
                   value="<?= h($data['nric'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.users_admin.edit.field.status', [], 'Status') : 'Status') ?>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;">
              <input type="checkbox" name="is_active" value="1"
                     <?= (int)$data['is_active'] === 1 ? 'checked' : '' ?>>
              <span>
                <?= h($hasT ? t('admin.users_admin.edit.field.status_active', [], 'Active') : 'Active') ?>
              </span>
            </label>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.users_admin.edit.section.roles_title', [], 'Roles') : 'Roles') ?>
            </div>
            <div class="form-section-desc">
              <?= h($hasT
                  ? t('admin.users_admin.edit.section.roles_desc', [], 'One user can have multiple roles. Permissions come from roles.')
                  : 'One user can have multiple roles. Permissions come from roles.'
              ) ?>
            </div>
          </div>

          <?php if (!$allRoles): ?>
            <div style="font-size:13px;color:#b91c1c;">
              <?= h($hasT
                  ? t('admin.users_admin.edit.no_roles_defined', [], 'No roles defined yet. Please create at least one role first.')
                  : 'No roles defined yet. Please create at least one role first.'
              ) ?>
            </div>
          <?php else: ?>
            <div class="form-group">
              <?php foreach ($allRoles as $r): ?>
                <?php $rid = (int)$r['id']; ?>
                <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:4px;font-size:13px;">
                  <input type="checkbox" name="roles[]" value="<?= $rid ?>"
                         <?= in_array($rid, $userRolesIds, true) ? 'checked' : '' ?>>
                  <span>
                    <strong><?= h($r['name']) ?></strong>
                    <br>
                    <span style="color:#6b7280;font-size:12px;">
                      <?= h($r['description'] ?? '') ?>
                    </span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="form-footer-row">
          <div class="form-footer-left"></div>
          <div class="form-footer-right">
            <a href="<?= h(url('admin/users/list.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.cancel', [], 'Cancel') : 'Cancel') ?>
            </a>
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.common.save', [], 'Save') : 'Save') ?>
            </button>
          </div>
        </div>

      </form>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
