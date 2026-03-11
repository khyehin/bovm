<?php
// public/admin/payers/payer_company_list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$hasT = function_exists('t');

// ---- Search ----
$search = trim($_GET['q'] ?? '');

$sql    = "SELECT c.* FROM payer_companies c";
$params = [];
$where  = [];

if ($search !== '') {
    $where[]       = "(c.name LIKE :q OR c.reg_no LIKE :q)";
    $params[':q']  = '%' . $search . '%';
}

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY c.name ASC, c.id ASC";

if ($params) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
} else {
    $st = $pdo->query($sql);
}
$companies = $st->fetchAll();

// ---- Audit log：新签名 ----
if (function_exists('audit_log')) {
    audit_log(
        $pdo,
        'PAYER.COMPANY.LIST',
        [
            'description' => 'View payer companies list',
            'search'      => $search !== '' ? $search : null,
        ],
        'payer_company',
        null
    );
}

$page_title = $hasT
    ? t('admin.payer_company.title', [], 'Payer Companies')
    : 'Payer Companies';

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
            <?= h($hasT ? t('admin.payer_company.eyebrow', [], 'Master data') : 'Master data') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
        </div>
        <div>
          <a href="<?= h(url('admin/payers/payer_company_edit.php?id=0')) ?>" class="btn btn-primary">
            <?= h($hasT ? t('admin.payer_company.action.new', [], '+ New Company') : '+ New Company') ?>
          </a>
        </div>
      </div>

      <!-- Search row -->
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;gap:10px;">
        <form method="get" action="<?= h(url('admin/payers/payer_company_list.php')) ?>"
              style="display:flex;align-items:center;gap:6px;">
          <input
            type="text"
            name="q"
            class="form-control"
            style="width:260px;"
            placeholder="<?= h($hasT ? t('admin.payer_company.search.ph', [], 'Search by name / reg no...') : 'Search by name / reg no...') ?>"
            value="<?= h($search) ?>"
          >
          <button type="submit" class="btn btn-light">
            <?= h($hasT ? t('admin.common.search', [], 'Search') : 'Search') ?>
          </button>
          <?php if ($search !== ''): ?>
            <a href="<?= h(url('admin/payers/payer_company_list.php')) ?>" class="btn btn-light">
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
            <th style="width:70px;"><?= h($hasT ? t('admin.payer_company.col.id', [], 'ID') : 'ID') ?></th>
            <th><?= h($hasT ? t('admin.payer_company.col.name', [], 'Company Name') : 'Company Name') ?></th>
            <th style="width:200px;"><?= h($hasT ? t('admin.payer_company.col.reg_no', [], 'Registration No') : 'Registration No') ?></th>
            <th style="width:200px;"><?= h($hasT ? t('admin.payer_company.col.created_at', [], 'Created At') : 'Created At') ?></th>
            <th style="width:120px;"><?= h($hasT ? t('admin.common.actions', [], 'Actions') : 'Actions') ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$companies): ?>
          <tr>
            <td colspan="5" style="padding:16px; color:#6b7280; font-size:13px;">
              <?= h($hasT ? t('admin.payer_company.empty', [], 'No payer companies found.') : 'No payer companies found.') ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($companies as $c): ?>
            <?php
              $name      = $c['name']      ?? ($c['company_name'] ?? '');
              $reg_no    = $c['reg_no']    ?? ($c['company_reg_no'] ?? '');
              $createdAt = $c['created_at'] ?? ($c['CreatedAt'] ?? '');
              $editUrl   = url('admin/payers/payer_company_edit.php?id=' . (int)$c['id']);
            ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td>
                <div style="font-size:13px; font-weight:600;">
                  <?= h($name) ?>
                </div>
              </td>
              <td><?= h($reg_no) ?></td>
              <td><?= h($createdAt) ?></td>
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
