<?php
// public/admin/payers/payer_staff_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$hasT = function_exists('t');

// Search
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT s.*
      FROM payer_company_staff s
";
$params = [];
$where  = [];

if ($search !== '') {
    $where[]       = "(s.staff_name LIKE :q OR s.ic_no LIKE :q OR s.phone LIKE :q)";
    $params[':q']  = '%' . $search . '%';
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY s.staff_name ASC, s.id ASC";

if ($params) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
} else {
    $st = $pdo->query($sql);
}
$staff = $st->fetchAll();

// ---- Audit log：新签名 ----
if (function_exists('audit_log')) {
    audit_log(
        $pdo,
        'PAYER.STAFF.LIST',
        [
            'description' => 'View payer staff list',
            'search'      => $search !== '' ? $search : null,
        ],
        'payer_staff',
        null
    );
}

$page_title = $hasT
    ? t('admin.payer_staff.title', [], 'Payer Staff')
    : 'Payer Staff';

include __DIR__ . '/../include/header.php';

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.payer_staff.eyebrow', [], 'Master data') : 'Master data') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
        </div>
        <div>
          <a href="<?= h(url('admin/payers/payer_staff_edit.php?id=0')) ?>" class="btn btn-primary">
            <?= h($hasT ? t('admin.payer_staff.action.new', [], '+ New Staff') : '+ New Staff') ?>
          </a>
        </div>
      </div>

      <!-- Search row -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:10px;">
        <form method="get" action="<?= h(url('admin/payers/payer_staff_list.php')) ?>"
              style="display:flex;align-items:center;gap:6px;">
          <input
            type="text"
            name="q"
            class="form-control"
            style="width:260px;"
            placeholder="<?= h($hasT ? t('admin.payer_staff.search.ph', [], 'Search by name / IC / phone...') : 'Search by name / IC / phone...') ?>"
            value="<?= h($search) ?>"
          >
          <button type="submit" class="btn btn-light">
            <?= h($hasT ? t('admin.common.search', [], 'Search') : 'Search') ?>
          </button>
          <?php if ($search !== ''): ?>
            <a href="<?= h(url('admin/payers/payer_staff_list.php')) ?>" class="btn btn-light">
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
            <th style="width:70px;"><?= h($hasT ? t('admin.payer_staff.col.id', [], 'ID') : 'ID') ?></th>
            <th><?= h($hasT ? t('admin.payer_staff.col.name', [], 'Name') : 'Name') ?></th>
            <th style="width:160px;"><?= h($hasT ? t('admin.payer_staff.col.ic', [], 'IC / Passport') : 'IC / Passport') ?></th>
            <th style="width:130px;"><?= h($hasT ? t('admin.payer_staff.col.phone', [], 'Phone') : 'Phone') ?></th>
            <th style="width:220px;"><?= h($hasT ? t('admin.payer_staff.col.email', [], 'Email') : 'Email') ?></th>
            <th style="width:90px;"><?= h($hasT ? t('admin.payer_staff.col.status', [], 'Status') : 'Status') ?></th>
            <th style="width:120px;"><?= h($hasT ? t('admin.common.actions', [], 'Actions') : 'Actions') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$staff): ?>
          <tr>
            <td colspan="7" style="padding:16px; color:#6b7280; font-size:13px;">
              <?= h($hasT ? t('admin.payer_staff.empty', [], 'No payer staff found.') : 'No payer staff found.') ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($staff as $s): ?>
            <?php
              $isActive = isset($s['is_active']) ? (int)$s['is_active'] : 1;
              $editUrl  = url('admin/payers/payer_staff_edit.php?id=' . (int)$s['id']);
            ?>
            <tr>
              <td><?= (int)$s['id'] ?></td>
              <td>
                <div style="font-size:13px; font-weight:600;">
                  <?= h($s['staff_name'] ?? '') ?>
                </div>
              </td>
              <td><?= h($s['ic_no'] ?? '') ?></td>
              <td><?= h($s['phone'] ?? '') ?></td>
              <td><?= h($s['email'] ?? '') ?></td>
              <td>
                <?php if ($isActive === 1): ?>
                  <span style="font-size:11px; padding:3px 9px; border-radius:999px;
                               background:#ecfdf5; color:#166534;">
                    <?= h($hasT ? t('admin.payer_staff.status.active', [], 'Active') : 'Active') ?>
                  </span>
                <?php else: ?>
                  <span style="font-size:11px; padding:3px 9px; border-radius:999px;
                               background:#fee2e2; color:#b91c1c;">
                    <?= h($hasT ? t('admin.payer_staff.status.inactive', [], 'Inactive') : 'Inactive') ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <a href="<?= h($editUrl) ?>" class="btn btn-light btn-sm">
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
