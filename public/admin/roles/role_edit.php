<?php
// public/admin/roles/role_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('ROLE.MNG');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string
  {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$hasT = function_exists('t');

/* -------------------------------------------------
 * 1) 读取 role (支持新建 id=0)
 * ------------------------------------------------- */
$roleId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isNew  = ($roleId <= 0);

if ($isNew) {
  // 新建角色默认值
  $role = [
    'id'          => 0,
    'code'        => '',
    'name'        => '',
    'description' => '',
    'is_active'   => 1,
  ];
} else {
  $st = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
  $st->execute([':id' => $roleId]);
  $role = $st->fetch();
  if (!$role) {
    http_response_code(404);
    exit('Role not found');
  }
}

$roleCode = $role['code'] ?? '';
$roleName = $role['name']
  ?? ($role['role_name'] ?? ('Role #' . ($role['id'] ?? '')));

/**
 * 不再用 language 里面带 %s 的 title_new / title_edit
 */
if ($isNew) {
  $page_title = $hasT
    ? t('admin.roles.edit.title_new_label', [], 'New Role')
    : 'New Role';
} else {
  $baseTitle = $hasT
    ? t('admin.roles.edit.title_edit_label', [], 'Edit Role')
    : 'Edit Role';
  $page_title = $baseTitle . ': ' . $roleName;
}

/* -------------------------------------------------
 * 2) 定义全站权限列表
 * ------------------------------------------------- */

$ALL_PERMS = [
  // Dashboard & 通用
  'APP.DASH.V'          => 'View dashboard',

  // Audit / 日志
  'AUDIT.V'             => 'View audit logs',

  // Customer + portal user
  'CUSTOMER.V'          => 'View customers & transactions',
  'CUSTOMER.E'          => 'Create / edit customers',
  'CUSTOMER.USER'       => 'Manage customer portal logins',

  // Txn / 收支（Customer Txn）
  'TXN.V'               => 'View IN / OUT transactions',
  'TXN.E'               => 'Create / edit IN / OUT transactions',
  'TXN.D'               => 'Delete IN / OUT transactions',
  'TXN.ALLOC'           => 'Allocate IN between customers (contra)',

  // Payers 相关
  'PAYER.MNG'           => 'Manage all payer master data',
  'PAYER.COMPANY.V'     => 'View payer companies',
  'PAYER.COMPANY.E'     => 'Create / edit payer companies',
  'PAYER.STAFF.V'       => 'View payer staff',
  'PAYER.STAFF.E'       => 'Create / edit payer staff',

  // 公司银行（新 bank module）
  'BANK.V'              => 'View bank module',
  'BANK.E'              => 'Manage bank module settings',

  'BANK.ACCOUNT.V'      => 'View bank accounts',
  'BANK.ACCOUNT.E'      => 'Create / edit bank accounts',
  'BANK.ACCOUNT.D'      => 'Delete bank accounts',

  'BANK.TXN.V'          => 'View company bank transactions',
  'BANK.TXN.E'          => 'Create / edit company bank transactions',

  'BANK.STMT.V'         => 'View bank statements',
  'BANK.STMT.E'         => 'Upload / manage bank statements',

  // 报表
  'REPORT.V'            => 'View reports',

  // Admin users & roles
  'USER.MNG'            => 'Manage admin users',
  'ROLE.MNG'            => 'Manage roles & permissions',
];

$PERM_GROUPS = [
  'Dashboard' => [
    'APP.DASH.V',
    'REPORT.V',
  ],
  'Audit / Logs' => [
    'AUDIT.V',
  ],
  'Customers & Login Users' => [
    'CUSTOMER.V',
    'CUSTOMER.E',
    'CUSTOMER.USER',
  ],
  'Transactions' => [
    'TXN.V',
    'TXN.E',
    'TXN.D',
    'TXN.ALLOC',
  ],
  'Payer Management' => [
    'PAYER.MNG',
    'PAYER.COMPANY.V',
    'PAYER.COMPANY.E',
    'PAYER.STAFF.V',
    'PAYER.STAFF.E',
  ],
  'Bank Management' => [
    'BANK.V',
    'BANK.E',
    'BANK.ACCOUNT.V',
    'BANK.ACCOUNT.E',
    'BANK.ACCOUNT.D',
    'BANK.TXN.V',
    'BANK.TXN.E',
    'BANK.STMT.V',
    'BANK.STMT.E',
  ],
  'Admin & Roles' => [
    'USER.MNG',
    'ROLE.MNG',
  ],
];

/* -------------------------------------------------
 * 3) 当前 role 拥有哪些权限
 * ------------------------------------------------- */
$currentPerms = [];

if (!$isNew) {
  $st = $pdo->prepare("SELECT perm_code FROM role_permissions WHERE role_id = :rid");
  $st->execute([':rid' => $role['id']]);
  $rows = $st->fetchAll();
  foreach ($rows as $r) {
    if (!empty($r['perm_code'])) {
      $currentPerms[$r['perm_code']] = true;
    }
  }
}

// Audit：查看页面（GET）
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && function_exists('audit_log')) {
  $act = $isNew ? 'ROLE.CREATE.VIEW' : 'ROLE.EDIT.VIEW';
  audit_log(
    $pdo,
    $act,
    [
      'role_id' => $roleId,
      'code'    => $roleCode,
      'name'    => $roleName,
    ],
    'role',
    $isNew ? null : $roleId
  );
}

$errors = [];
$saved  = false;

/* -------------------------------------------------
 * 4) 处理提交
 * ------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  $postedId    = (int)($_POST['id'] ?? 0);       // 可能是 0（新建）
  $code        = trim($_POST['code'] ?? '');
  $name        = trim($_POST['name'] ?? '');
  $desc        = trim($_POST['description'] ?? '');
  $isActive    = isset($_POST['is_active']) ? 1 : 0;
  $postedPerms = $_POST['perms'] ?? [];

  if ($code === '') {
    $errors['code'] = $hasT
      ? t('admin.roles.edit.error.code_required', [], 'Code is required.')
      : 'Code is required.';
  }
  if ($name === '') {
    $errors['name'] = $hasT
      ? t('admin.roles.edit.error.name_required', [], 'Role name is required.')
      : 'Role name is required.';
  }

  // 检查 code 唯一
  if ($code !== '') {
    if ($postedId > 0) {
      $st = $pdo->prepare("SELECT id FROM roles WHERE code = :c AND id <> :id LIMIT 1");
      $st->execute([':c' => $code, ':id' => $postedId]);
    } else {
      $st = $pdo->prepare("SELECT id FROM roles WHERE code = :c LIMIT 1");
      $st->execute([':c' => $code]);
    }
    if ($st->fetch()) {
      $errors['code'] = $hasT
        ? t('admin.roles.edit.error.code_unique', [], 'This code is already used by another role.')
        : 'This code is already used by another role.';
    }
  }

  // 清洗 perms
  if (!is_array($postedPerms)) {
    $postedPerms = [];
  }
  $finalPerms = [];
  foreach ($postedPerms as $pc) {
    $pc = trim((string)$pc);
    if ($pc !== '' && isset($ALL_PERMS[$pc])) {
      $finalPerms[$pc] = true;
    }
  }

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // 4a) 保存 / 更新 roles
      if ($postedId > 0) {
        // 更新
        $sql = "UPDATE roles
                   SET code = :code,
                       name = :name,
                       description = :description,
                       is_active = :is_active
                 WHERE id = :id";
        $st = $pdo->prepare($sql);
        $st->execute([
          ':code'        => $code,
          ':name'        => $name,
          ':description' => $desc,
          ':is_active'   => $isActive,
          ':id'          => $postedId,
        ]);
        $roleId = $postedId;
      } else {
        // 新建
        $sql = "INSERT INTO roles (code, name, description, is_active, created_at)
                VALUES (:code, :name, :description, :is_active, NOW())";
        $st = $pdo->prepare($sql);
        $st->execute([
          ':code'        => $code,
          ':name'        => $name,
          ':description' => $desc,
          ':is_active'   => $isActive,
        ]);
        $roleId = (int)$pdo->lastInsertId();
      }

      // 4b) 重建 role_permissions
      $del = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = :rid");
      $del->execute([':rid' => $roleId]);

      if ($finalPerms) {
        $ins = $pdo->prepare("
          INSERT INTO role_permissions (role_id, perm_code)
          VALUES (:rid, :code)
        ");
        foreach (array_keys($finalPerms) as $codeKey) {
          $ins->execute([
            ':rid'  => $roleId,
            ':code' => $codeKey,
          ]);
        }
      }

      $pdo->commit();
      $saved = true;

      // Audit：保存角色 & 权限
      if (function_exists('audit_log')) {
        $act = $postedId > 0 ? 'ROLE.UPDATE' : 'ROLE.CREATE';
        audit_log(
          $pdo,
          $act,
          [
            'role_id'   => $roleId,
            'code'      => $code,
            'name'      => $name,
            'is_active' => $isActive,
            'perm_count'=> count($finalPerms),
          ],
          'role',
          $roleId
        );
      }

      // 重新载入 role + perms
      $st = $pdo->prepare("SELECT * FROM roles WHERE id = :id");
      $st->execute([':id' => $roleId]);
      $role = $st->fetch();
      $isNew = false;

      $roleCode = $role['code'] ?? '';
      $roleName = $role['name'] ?? ('Role #' . $roleId);

      $currentPerms = $finalPerms;

      // 更新 page_title（编辑状态）
      $baseTitle = $hasT
        ? t('admin.roles.edit.title_edit_label', [], 'Edit Role')
        : 'Edit Role';
      $page_title = $baseTitle . ': ' . $roleName;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      $prefix = $hasT
        ? t('admin.roles.edit.error.save_failed', [], 'Save failed')
        : 'Save failed';
      $errors['general'] = $prefix . ': ' . $e->getMessage();
    }
  } else {
    // 有错误时保持用户输入
    $role['id']          = $postedId;
    $role['code']        = $code;
    $role['name']        = $name;
    $role['description'] = $desc;
    $role['is_active']   = $isActive;
    $roleCode            = $code;
    $roleName            = $name;
    $currentPerms        = $finalPerms;

    if ($postedId > 0) {
      $baseTitle = $hasT
        ? t('admin.roles.edit.title_edit_label', [], 'Edit Role')
        : 'Edit Role';
      $page_title = $baseTitle . ': ' . $roleName;
    } else {
      $page_title = $hasT
        ? t('admin.roles.edit.title_new_label', [], 'New Role')
        : 'New Role';
    }
  }
}

/* -------------------------------------------------
 * 5) 页面
 * ------------------------------------------------- */
include __DIR__ . '/../include/header.php';
?>

<style>
  .perm-toggle-chip {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    color: #4b5563;
    cursor: pointer;
    text-decoration: none;
  }
  .perm-toggle-chip:hover {
    background: #e5e7eb;
  }
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.roles.edit.eyebrow', [], 'Roles & Permissions') : 'Roles & Permissions') ?>
          </div>
          <h2 class="form-page-title">
            <?php if ($isNew): ?>
              <?= h($hasT ? t('admin.roles.edit.title_new_label', [], 'New Role') : 'New Role') ?>
            <?php else: ?>
              <?= h($roleName) ?>
            <?php endif; ?>
          </h2>
          <div class="form-page-subtitle">
            <?= h(
              $hasT
                ? t('admin.roles.edit.subtitle', [], 'Manage role details and tick which permissions this role should have.')
                : 'Manage role details and tick which permissions this role should have.'
            ) ?>
          </div>
        </div>
        <div class="form-page-meta">
          <?php if (!$isNew): ?>
            <span class="badge-soft">
              <?php
              $badge = $hasT
                ? t('admin.roles.edit.badge_id', [], 'ID: %d')
                : 'ID: %d';
              echo h(sprintf($badge, (int)$role['id']));
              ?>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($saved): ?>
        <div class="alert-success" style="margin-bottom:10px;">
          <?= h($hasT ? t('admin.roles.edit.saved', [], 'Role & permissions saved.') : 'Role & permissions saved.') ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:10px;">
          <?= h($errors['general']) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="form-layout">
        <input type="hidden" name="id" value="<?= (int)($role['id'] ?? 0) ?>">

        <!-- Role 基本资料 -->
        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.roles.edit.section.details_title', [], 'Role details') : 'Role details') ?>
            </div>
            <div class="form-section-desc">
              <?= h(
                $hasT
                  ? t('admin.roles.edit.section.details_desc', [], 'Code and name are used to identify this role.')
                  : 'Code and name are used to identify this role.'
              ) ?>
            </div>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.roles.edit.field.code', [], 'Code') : 'Code') ?>
                <span class="field-required">*</span>
              </label>
              <input
                type="text"
                name="code"
                class="form-control"
                value="<?= h($role['code'] ?? '') ?>"
                autocomplete="off">
              <?php if (isset($errors['code'])): ?>
                <div class="form-error"><?= h($errors['code']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.roles.edit.field.name', [], 'Role name') : 'Role name') ?>
                <span class="field-required">*</span>
              </label>
              <input
                type="text"
                name="name"
                class="form-control"
                value="<?= h($role['name'] ?? '') ?>">
              <?php if (isset($errors['name'])): ?>
                <div class="form-error"><?= h($errors['name']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.roles.edit.field.description', [], 'Description') : 'Description') ?>
            </label>
            <textarea
              name="description"
              class="form-control"
              rows="2"><?= h($role['description'] ?? '') ?></textarea>
          </div>

          <div class="form-group" style="margin-top:4px;">
            <label class="switch-label">
              <span class="switch-text">
                <?= h($hasT ? t('admin.roles.edit.field.active', [], 'Active') : 'Active') ?>
              </span>
              <label class="switch">
                <input type="checkbox" name="is_active" value="1"
                  <?= ((int)($role['is_active'] ?? 1) === 1 ? 'checked' : '') ?>>
                <span class="slider"></span>
              </label>
            </label>
          </div>
        </div>

        <!-- 权限勾选 -->
        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.roles.edit.section.perms_title', [], 'Permissions') : 'Permissions') ?>
            </div>
            <div class="form-section-desc">
              <?= h(
                $hasT
                  ? t('admin.roles.edit.section.perms_desc', [], 'Tick / untick the permissions below.')
                  : 'Tick / untick the permissions below.'
              ) ?>
            </div>
          </div>

          <?php foreach ($PERM_GROUPS as $groupLabel => $codes): ?>
            <div class="admin-card" style="margin-bottom:10px; padding:10px 14px;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <div style="font-weight:600;font-size:13px;">
                  <?= h($groupLabel) ?>
                </div>
                <div style="display:flex;gap:6px;">
                  <a href="#"
                     class="perm-toggle-chip perm-group-toggle"
                     data-group="<?= h($groupLabel) ?>"
                     data-action="check">
                    <?= h($hasT ? t('admin.roles.edit.group.all', [], 'All') : 'All') ?>
                  </a>
                  <a href="#"
                     class="perm-toggle-chip perm-group-toggle"
                     data-group="<?= h($groupLabel) ?>"
                     data-action="uncheck">
                    <?= h($hasT ? t('admin.roles.edit.group.none', [], 'None') : 'None') ?>
                  </a>
                </div>
              </div>

              <div style="display:flex;flex-wrap:wrap;gap:6px 16px;">
                <?php foreach ($codes as $codeKey): ?>
                  <?php if (!isset($ALL_PERMS[$codeKey])) continue; ?>
                  <label style="font-size:12px;display:flex;align-items:center;gap:5px;"
                         data-group-item="<?= h($groupLabel) ?>">
                    <input
                      type="checkbox"
                      name="perms[]"
                      value="<?= h($codeKey) ?>"
                      <?= isset($currentPerms[$codeKey]) ? 'checked' : '' ?>>
                    <span>
                      <strong><?= h($codeKey) ?></strong>
                      <span style="color:#6b7280;"> – <?= h($ALL_PERMS[$codeKey]) ?></span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>

        </div>

        <div class="form-footer-row">
          <div class="form-footer-right">
            <a href="<?= h(url('admin/roles/list.php')) ?>" class="btn btn-light" style="margin-right:8px;">
              <?= h($hasT ? t('admin.common.back', [], 'Back') : 'Back') ?>
            </a>
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.roles.edit.save_btn', [], 'Save permissions') : 'Save permissions') ?>
            </button>
          </div>
        </div>
      </form>
    </div>

  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".perm-group-toggle").forEach(function(btn) {
      btn.addEventListener("click", function(e) {
        e.preventDefault();
        var group = this.getAttribute("data-group");
        var action = this.getAttribute("data-action");
        var items = document.querySelectorAll('[data-group-item="' + group + '"] input[type="checkbox"]');
        items.forEach(function(cb) {
          if (action === "check") cb.checked = true;
          if (action === "uncheck") cb.checked = false;
        });
      });
    });
  });
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
