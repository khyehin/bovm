<?php
// public/admin/banks/list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_admin();
require_perm('BANK.V'); // 你在 permissions.php 里加过 BANK.V / BANK.E 等

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// Filters
$q        = trim($_GET['q'] ?? '');
$currency = trim($_GET['currency'] ?? '');

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[]        = '(bank_name LIKE :q OR account_name LIKE :q OR account_no LIKE :q)';
    $params[':q']   = '%' . $q . '%';
}
if ($currency !== '') {
    $where[]          = 'currency = :cur';
    $params[':cur']   = $currency;
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT *
        FROM company_banks
        WHERE $whereSql
        ORDER BY is_active DESC, bank_name ASC, id ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$page_title = t('admin.banks.list.title', [], 'Company Banks');

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <!-- Page header -->
    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h(t('admin.banks.list.eyebrow', [], 'Finance')) ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h(t(
              'admin.banks.list.subtitle',
              [],
              'Manage internal company bank accounts used in IN / OUT transactions.'
            )) ?>
          </div>
        </div>
        <div style="display:flex; gap:8px;">
          <?php if (can('BANK.E')): ?>
            <a href="<?= h(url('admin/banks/txn_edit.php?id=')) ?>"
               class="btn btn-primary">
              <?= h(t('admin.banks.list.new_btn', [], '+ New Bank')) ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get"
            style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; font-size:13px;">

        <div>
          <label class="field-label" style="margin-bottom:4px;">
            <?= h(t('admin.banks.list.filter.q', [], 'Search')) ?>
          </label>
          <input type="text"
                 name="q"
                 class="form-control"
                 style="min-width:220px;"
                 value="<?= h($q) ?>"
                 placeholder="<?= h(t('admin.banks.list.filter.q_ph', [], 'Bank / account name / number')) ?>">
        </div>

        <div>
          <label class="field-label" style="margin-bottom:4px;">
            <?= h(t('admin.banks.field.currency', [], 'Currency')) ?>
          </label>
          <select name="currency" class="form-control" style="min-width:120px;">
            <option value="">
              <?= h(t('admin.common.all', [], 'All')) ?>
            </option>
            <option value="MYR" <?= $currency==='MYR' ? 'selected' : '' ?>>MYR</option>
            <option value="USD" <?= $currency==='USD' ? 'selected' : '' ?>>USD</option>
            <option value="USDT"<?= $currency==='USDT'? 'selected' : '' ?>>USDT</option>
            <option value="OTHER"<?= $currency==='OTHER'? 'selected' : '' ?>>OTHER</option>
          </select>
        </div>

        <div style="margin-left:auto; display:flex; gap:8px;">
          <button type="submit" class="btn btn-primary">
            <?= h(t('admin.common.apply', [], 'Apply')) ?>
          </button>
          <a href="<?= h(url('admin/banks/list.php')) ?>"
             class="btn btn-light">
            <?= h(t('admin.common.reset', [], 'Reset')) ?>
          </a>
        </div>
      </form>
    </div>

    <!-- Bank table -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th><?= h(t('admin.banks.field.bank_name', [], 'Bank name')) ?></th>
            <th><?= h(t('admin.banks.field.account_name', [], 'Account name')) ?></th>
            <th><?= h(t('admin.banks.field.account_no', [], 'Account no.')) ?></th>
            <th style="width:80px;"><?= h(t('admin.banks.field.currency', [], 'Currency')) ?></th>
            <th style="width:90px;"><?= h(t('admin.common.status', [], 'Status')) ?></th>
            <th style="width:60px;" class="table-actions-cell">
              <?= h(t('admin.common.actions', [], 'Actions')) ?>
            </th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="7" style="padding:16px; font-size:13px; color:#6b7280;">
              <?= h(t('admin.banks.list.empty', [], 'No bank accounts found.')) ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= (int)$row['id'] ?></td>
              <td><?= h($row['bank_name'] ?? '') ?></td>
              <td><?= h($row['account_name'] ?? '') ?></td>
              <td><?= h($row['account_no'] ?? '') ?></td>
              <td><?= h($row['currency'] ?? 'MYR') ?></td>
              <td>
                <?php if ((int)($row['is_active'] ?? 1) === 1): ?>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;
                               background:#ecfdf5;color:#166534;">
                    <?= h(t('admin.common.active', [], 'Active')) ?>
                  </span>
                <?php else: ?>
                  <span style="font-size:11px;padding:3px 9px;border-radius:999px;
                               background:#fee2e2;color:#b91c1c;">
                    <?= h(t('admin.common.inactive', [], 'Inactive')) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="table-actions-cell">
                <div class="actions-menu">
                  <button type="button"
                          class="actions-menu-trigger"
                          aria-expanded="false">
                    ⋯
                  </button>
                  <div class="actions-menu-dropdown">
                    <!-- View transactions -->
                    <a href="<?= h(url('admin/banks/txn_list.php?bank_id='.(int)$row['id'])) ?>"
                       class="actions-menu-item">
                      <?= h(t('admin.banks.list.action_txn', [], 'Transactions')) ?>
                    </a>

                    <!-- Edit bank -->
                    <?php if (can('BANK.E')): ?>
                      <a href="<?= h(url('admin/banks/edit.php?id='.(int)$row['id'])) ?>"
                         class="actions-menu-item">
                        <?= h(t('admin.common.edit', [], 'Edit')) ?>
                      </a>
                    <?php endif; ?>

                    <!-- Statements page（可用来上传 / 导出） -->
                    <a href="<?= h(url('admin/banks/statements.php?bank_id='.(int)$row['id'])) ?>"
                       class="actions-menu-item">
                      <?= h(t('admin.banks.list.action_statements', [], 'Statements')) ?>
                    </a>
                  </div>
                </div>
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
