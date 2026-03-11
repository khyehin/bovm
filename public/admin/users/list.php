<?php
// public/admin/users/list.php
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

$hasT   = function_exists('t');
$search = trim($_GET['q'] ?? '');

// 这里只显示后台 admin 用户（role = ADMIN）
$sql = "
  SELECT u.*
  FROM users u
  WHERE u.role = 'ADMIN'
";
$params = [];

if ($search !== '') {
    $sql .= " AND (u.username LIKE :q OR u.full_name LIKE :q OR u.email LIKE :q)";
    $params[':q'] = '%' . $search . '%';
}

$sql .= " ORDER BY u.username ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll();

// 取每个 user 的 roles 名字
$userIds    = array_column($users, 'id');
$rolesByUser = [];

if ($userIds) {
    $in = implode(',', array_fill(0, count($userIds), '?'));

    $sql2 = "
      SELECT ur.user_id, r.name
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      WHERE ur.user_id IN ($in)
      ORDER BY r.name ASC
    ";
    $st2 = $pdo->prepare($sql2);
    $st2->execute($userIds);
    $rows = $st2->fetchAll();

    foreach ($rows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($rolesByUser[$uid])) {
            $rolesByUser[$uid] = [];
        }
        $rolesByUser[$uid][] = $row['name'];
    }
}

$page_title = $hasT
    ? t('admin.users_admin.list.title', [], 'Internal Users')
    : 'Internal Users';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.users_admin.list.eyebrow', [], 'Security') : 'Security') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h($hasT
                ? t('admin.users_admin.list.subtitle', [], 'Manage admin accounts and their roles.')
                : 'Manage admin accounts and their roles.'
            ) ?>
          </div>
        </div>
        <div>
          <a href="<?= h(url('admin/users/user_edit.php?id=0')) ?>" class="btn btn-primary">
            <?= h($hasT ? t('admin.users_admin.list.new_btn', [], '+ New User') : '+ New User') ?>
          </a>
        </div>
      </div>

      <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <form method="get" action="<?= h(url('admin/users/list.php')) ?>" style="display:flex;gap:6px;">
          <input
            type="text"
            name="q"
            class="form-control"
            placeholder="<?= h($hasT
                ? t('admin.users_admin.list.search_ph', [], 'Search username / name / email')
                : 'Search username / name / email'
            ) ?>"
            value="<?= h($search) ?>"
            style="width:260px;"
          >
          <button type="submit" class="btn btn-light">
            <?= h($hasT ? t('admin.common.search', [], 'Search') : 'Search') ?>
          </button>
          <?php if ($search !== ''): ?>
            <a href="<?= h(url('admin/users/list.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.reset', [], 'Reset') : 'Reset') ?>
            </a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th>
              <?= h($hasT ? t('admin.users_admin.list.col.username', [], 'Username') : 'Username') ?>
            </th>
            <th>
              <?= h($hasT ? t('admin.users_admin.list.col.fullname', [], 'Full name') : 'Full name') ?>
            </th>
            <th>
              <?= h($hasT ? t('admin.users_admin.list.col.email', [], 'Email') : 'Email') ?>
            </th>
            <th>
              <?= h($hasT ? t('admin.users_admin.list.col.roles', [], 'Roles') : 'Roles') ?>
            </th>
            <th>
              <?= h($hasT ? t('admin.users_admin.list.col.status', [], 'Status') : 'Status') ?>
            </th>
            <th style="width:120px;">
              <?= h($hasT ? t('admin.users_admin.list.col.actions', [], 'Actions') : 'Actions') ?>
            </th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr>
            <td colspan="6" style="padding:16px; color:#6b7280; font-size:13px;">
              <?= h($hasT ? t('admin.users_admin.list.empty', [], 'No users found.') : 'No users found.') ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $uid   = (int)$u['id'];
              $roles = $rolesByUser[$uid] ?? [];
            ?>
            <tr>
              <td><?= h($u['username']) ?></td>
              <td><?= h($u['full_name']) ?></td>
              <td><?= h($u['email'] ?? '') ?></td>
              <td>
                <?php if ($roles): ?>
                  <span style="font-size:12px;"><?= h(implode(', ', $roles)) ?></span>
                <?php else: ?>
                  <span style="font-size:12px;color:#9ca3af;">
                    <?= h($hasT ? t('admin.users_admin.list.no_roles', [], '(no roles)') : '(no roles)') ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ((int)$u['is_active'] === 1): ?>
                  <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#ecfdf5; color:#166534;">
                    <?= h($hasT ? t('admin.common.active', [], 'Active') : 'Active') ?>
                  </span>
                <?php else: ?>
                  <span style="font-size:11px; padding:3px 9px; border-radius:999px; background:#fee2e2; color:#b91c1c;">
                    <?= h($hasT ? t('admin.common.inactive', [], 'Inactive') : 'Inactive') ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <a href="<?= h(url('admin/users/user_edit.php?id='.$uid)) ?>" class="btn btn-light btn-sm">
                  <?= h($hasT ? t('admin.common.edit', [], 'Edit') : 'Edit') ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
