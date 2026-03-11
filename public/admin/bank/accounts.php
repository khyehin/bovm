<?php
// public/admin/bank/accounts.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.ACCOUNT.V');
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
$canFn = function_exists('can') ? 'can' : null;

$canEdit   = $canFn ? $canFn('BANK.ACCOUNT.E') : true;
$canDelete = $canFn ? $canFn('BANK.ACCOUNT.D') : true;
$canStmt   = $canFn ? $canFn('BANK.STMT.V')   : true;

// ------------------------------
// 1) 取所有 bank accounts
// ------------------------------
$st = $pdo->query("
    SELECT
      id,
      bank_name,
      bank_code,
      account_name,
      account_no,
      currency,
      is_active,
      sort_order,
      created_at
    FROM company_bank_accounts
    ORDER BY is_active DESC,
             sort_order ASC,
             bank_name ASC,
             id ASC
");
$rows = $st->fetchAll();

// ------------------------------
// 2) 计算每个 bank 的 Opening / Current balance（用原币 amount 来算）
// Opening = 本月1号之前所有交易余额
// Current = 所有交易余额
// IN = +amount, OUT = -amount
// ------------------------------
$firstOfMonth = date('Y-m-01');

$balanceMap = []; // [bank_id => ['opening' => ..., 'current' => ...]]

$st2 = $pdo->prepare("
    SELECT
      bank_id,
      -- 当前总余额 (所有交易，原币)
      SUM(
        CASE
          WHEN txn_type = 'IN'  THEN amount
          WHEN txn_type = 'OUT' THEN -amount
          ELSE 0
        END
      ) AS bal_current,
      -- 本月1号前的余额 = Opening（原币）
      SUM(
        CASE
          WHEN txn_date < :first_of_month THEN
            CASE
              WHEN txn_type = 'IN'  THEN amount
              WHEN txn_type = 'OUT' THEN -amount
              ELSE 0
            END
          ELSE 0
        END
      ) AS bal_opening
    FROM company_bank_txn
    GROUP BY bank_id
");
$st2->execute([':first_of_month' => $firstOfMonth]);
while ($r = $st2->fetch()) {
    $bid = (int)$r['bank_id'];
    $balanceMap[$bid] = [
        'opening' => (float)($r['bal_opening'] ?? 0),
        'current' => (float)($r['bal_current'] ?? 0),
    ];
}

$page_title = $hasT
    ? t('admin.bank.accounts.page_title', [], 'Company bank accounts')
    : 'Company bank accounts';


include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
    <div class="admin-main-inner">

        <div class="admin-card" style="margin-bottom:18px;">
            <div class="admin-card-header">
                <div>
                    <div class="form-page-eyebrow">
                        <?= h($hasT ? t('admin.bank.accounts.eyebrow', [], 'Bank') : 'Bank') ?>
                    </div>
                    <h1 class="page-title"><?= h($page_title) ?></h1>
                    <div class="form-page-subtitle">
                        <?= h(
                            $hasT
                                ? t('admin.bank.accounts.subtitle', [], 'Manage internal bank accounts used for IN / OUT transactions.')
                                : 'Manage internal bank accounts used for IN / OUT transactions.'
                        ) ?>
                    </div>
                </div>

                <?php if ($canEdit): ?>
                    <div>
                        <a href="<?= h(url('admin/bank/account_edit.php?id=0')) ?>"
                            class="btn btn-primary">
                            <?= h($hasT ? t('admin.bank.accounts.new_btn', [], '+ New account') : '+ New account') ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="admin-card">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th><?= h($hasT ? t('admin.bank.accounts.col.bank', [], 'Bank') : 'Bank') ?></th>
                        <th><?= h($hasT ? t('admin.bank.accounts.col.account_name', [], 'Account name') : 'Account name') ?></th>
                        <th><?= h($hasT ? t('admin.bank.accounts.col.account_no', [], 'Account no.') : 'Account no.') ?></th>
                        <th style="width:140px;text-align:right;">
                            <?= h($hasT ? t('admin.bank.accounts.col.opening', [], 'Opening balance') : 'Opening balance') ?>
                        </th>
                        <th style="width:140px;text-align:right;">
                            <?= h($hasT ? t('admin.bank.accounts.col.current', [], 'Current balance') : 'Current balance') ?>
                        </th>
                        <th style="width:90px;">
                            <?= h($hasT ? t('admin.bank.accounts.col.currency', [], 'Currency') : 'Currency') ?>
                        </th>
                        <th style="width:80px;">
                            <?= h($hasT ? t('admin.bank.accounts.col.status', [], 'Status') : 'Status') ?>
                        </th>
                        <th style="width:80px;" class="table-actions-cell">
                            <?= h($hasT ? t('admin.common.actions', [], 'Actions') : 'Actions') ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="9" style="padding:16px; font-size:13px; color:#6b7280;">
                                <?= h(
                                    $hasT
                                        ? t('admin.bank.accounts.empty', [], 'No bank accounts yet.')
                                        : 'No bank accounts yet.'
                                ) ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                            $bid = (int)$r['id'];
                            $opening = $balanceMap[$bid]['opening'] ?? 0.0;
                            $current = $balanceMap[$bid]['current'] ?? 0.0;
                            $cur     = $r['currency'] ?: 'MYR';
                            ?>
                            <tr>
                                <td><?= $bid ?></td>
                                <td><?= h($r['bank_name']) ?></td>
                                <td><?= h($r['account_name']) ?></td>
                                <td><?= h($r['account_no']) ?></td>
                                <td style="text-align:right;">
                                    <?= h($cur) ?> <?= number_format($opening, 2) ?>
                                </td>
                                <td style="text-align:right;">
                                    <?= h($cur) ?> <?= number_format($current, 2) ?>
                                </td>
                                <td><?= h($cur) ?></td>
                                <td>
                                    <?php if ((int)$r['is_active'] === 1): ?>
                                        <span style="font-size:11px;padding:3px 8px;border-radius:999px;
                               background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;">
                                            <?= h($hasT ? t('admin.common.active', [], 'Active') : 'Active') ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="font-size:11px;padding:3px 8px;border-radius:999px;
                               background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;">
                                            <?= h($hasT ? t('admin.common.inactive', [], 'Inactive') : 'Inactive') ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="table-actions-cell">
                                    <div class="actions-menu">
                                        <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                                        <div class="actions-menu-dropdown">
                                            <!-- View account transactions -->
                                            <a href="<?= h(url('admin/bank/transactions.php?bank_id=' . $bid)) ?>"
                                                class="actions-menu-item">
                                                <?= h(
                                                    $hasT
                                                        ? t('admin.bank.accounts.action.view_txn', [], 'View transactions')
                                                        : 'View transactions'
                                                ) ?>
                                            </a>

                                            <!-- View statements -->
                                            <?php if ($canStmt): ?>
                                                <a href="<?= h(url('admin/bank/statements.php?bank_id=' . $bid)) ?>"
                                                    class="actions-menu-item">
                                                    <?= h(
                                                        $hasT
                                                            ? t('admin.bank.accounts.action.view_stmt', [], 'View statements')
                                                            : 'View statements'
                                                    ) ?>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Edit account -->
                                            <?php if ($canEdit): ?>
                                                <a href="<?= h(url('admin/bank/account_edit.php?id=' . $bid)) ?>"
                                                    class="actions-menu-item">
                                                    <?= h($hasT ? t('admin.common.edit', [], 'Edit') : 'Edit') ?>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Delete account -->
                                            <?php if ($canDelete): ?>
                                                <a href="<?= h(url('admin/bank/account_delete.php?id=' . (int)$r['id'])) ?>"
                                                    class="actions-menu-item"
                                                    onclick="return confirm('Are you sure you want to delete this bank account?');">
                                                    Delete
                                                </a>
                                            <?php endif; ?>
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