<?php
// public/admin/roles/list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
require_perm('ROLE.MNG');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

$st = $pdo->query("SELECT * FROM roles ORDER BY is_active DESC, name ASC, id ASC");
$roles = $st->fetchAll();

$page_title = $hasT
    ? t('admin.roles.list.title', [], 'Roles & Permissions')
    : 'Roles & Permissions';

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- Page Header -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.roles.list.eyebrow', [], 'Security') : 'Security') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h($hasT
                ? t('admin.roles.list.subtitle', [], 'Define groups of permissions and assign them to users.')
                : 'Define groups of permissions and assign them to users.'
            ) ?>
          </div>
        </div>
        <div>
          <a href="<?= h(url('admin/roles/role_edit.php?id=0')) ?>" 
             class="btn btn-primary">
            <?= h($hasT ? t('admin.roles.list.new_btn', [], '+ New Role') : '+ New Role') ?>
          </a>
        </div>
      </div>
    </div>


    <!-- Role List -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:80px;">
              <?= h($hasT ? t('admin.roles.list.col.code', [], 'Code') : 'Code') ?>
            </th>
            <th>
              <?= h($hasT ? t('admin.roles.list.col.name', [], 'Name') : 'Name') ?>
            </th>
            <th style="width:110px;">
              <?= h($hasT ? t('admin.roles.list.col.status', [], 'Status') : 'Status') ?>
            </th>
            <th style="width:120px;">
              <?= h($hasT ? t('admin.roles.list.col.actions', [], 'Actions') : 'Actions') ?>
            </th>
          </tr>
        </thead>
        <tbody>

        <?php if (!$roles): ?>
          <tr>
            <td colspan="4" style="padding:18px; color:#6b7280; font-size:13px;">
              <?= h($hasT ? t('admin.roles.list.empty', [], 'No roles found.') : 'No roles found.') ?>
            </td>
          </tr>
        <?php else: ?>

          <?php foreach ($roles as $r): ?>
            <tr>

              <!-- role code -->
              <td style="font-size:13px; font-weight:600;">
                <?= h($r['code']) ?>
              </td>

              <!-- role name + description -->
              <td>
                <div style="font-size:13px; font-weight:600;">
                  <?= h($r['name']) ?>
                </div>

                <?php if (!empty($r['description'])): ?>
                  <div style="font-size:12px; color:#6b7280;">
                    <?= h($r['description']) ?>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Active badge -->
              <td>
                <?php if ((int)$r['is_active'] === 1): ?>
                  <span style="
                    font-size:11px;
                    padding:3px 9px;
                    border-radius:999px;
                    background:#ecfdf5;
                    color:#166534;
                    border:1px solid #a7f3d0;
                  ">
                    <?= h($hasT ? t('admin.common.active', [], 'Active') : 'Active') ?>
                  </span>
                <?php else: ?>
                  <span style="
                    font-size:11px;
                    padding:3px 9px;
                    border-radius:999px;
                    background:#fee2e2;
                    color:#b91c1c;
                    border:1px solid #fecaca;
                  ">
                    <?= h($hasT ? t('admin.common.inactive', [], 'Inactive') : 'Inactive') ?>
                  </span>
                <?php endif; ?>
              </td>

              <!-- Edit -->
              <td>
                <a href="<?= h(url('admin/roles/role_edit.php?id='.(int)$r['id'])) ?>"
                   class="btn btn-light btn-sm">
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
