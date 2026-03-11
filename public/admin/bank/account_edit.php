<?php
// public/admin/bank/account_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.ACCOUNT.E');
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

if (!function_exists('tt')) {
    function tt(string $key, array $vars = [], string $fallback = ''): string {
        if (function_exists('t')) return t($key, $vars, $fallback);
        return $fallback !== '' ? $fallback : $key;
    }
}

// ------------------------
// 1) 读取 id & 载入现有数据
// ------------------------
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isNew = ($id <= 0);

if ($isNew) {
    $account = [
        'id'           => 0,
        'bank_name'    => '',
        'bank_code'    => '',
        'account_name' => '',
        'account_no'   => '',
        'currency'     => 'MYR',
        'is_active'    => 1,
        'sort_order'   => 0,
    ];
} else {
    $st = $pdo->prepare("
        SELECT
            id,
            bank_name,
            bank_code,
            account_name,
            account_no,
            currency,
            is_active,
            sort_order
        FROM company_bank_accounts
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $account = $st->fetch();
    if (!$account) {
        http_response_code(404);
        exit('Bank account not found');
    }
}

$page_title = $hasT
    ? t($isNew ? 'admin.bank.account.title_new' : 'admin.bank.account.title_edit', [], $isNew ? 'New bank account' : 'Edit bank account')
    : ($isNew ? 'New bank account' : 'Edit bank account');

$errors = [];
$saved  = false;

// ------------------------
// 2) 处理 POST 提交
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedId = (int)($_POST['id'] ?? 0);

    $bankName    = trim((string)($_POST['bank_name'] ?? ''));
    $bankCode    = strtoupper(trim((string)($_POST['bank_code'] ?? '')));
    $accountName = trim((string)($_POST['account_name'] ?? ''));
    $accountNo   = trim((string)($_POST['account_no'] ?? ''));
    $currency    = strtoupper(trim((string)($_POST['currency'] ?? 'MYR')));
    $isActive    = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder   = (int)($_POST['sort_order'] ?? 0);

    // 简单校验
    if ($bankName === '') {
        $errors['bank_name'] = $hasT
            ? t('admin.bank.account.error.bank_name_required', [], 'Bank name is required.')
            : 'Bank name is required.';
    }
    if ($accountName === '') {
        $errors['account_name'] = $hasT
            ? t('admin.bank.account.error.account_name_required', [], 'Account name is required.')
            : 'Account name is required.';
    }

    // currency 默认与长度限制
    if ($currency === '') $currency = 'MYR';
    if (strlen($currency) > 10) $currency = substr($currency, 0, 10);

    // ✅ sort_order 自动：如果没填 / 填 0，就用 max + 10
    if ($sortOrder <= 0) {
        $max = (int)($pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM company_bank_accounts")->fetchColumn() ?: 0);
        $sortOrder = $max + 10;
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($postedId > 0) {
                // UPDATE
                $sql = "
                    UPDATE company_bank_accounts
                       SET bank_name    = :bank_name,
                           bank_code    = :bank_code,
                           account_name = :account_name,
                           account_no   = :account_no,
                           currency     = :currency,
                           is_active    = :is_active,
                           sort_order   = :sort_order
                     WHERE id = :id
                ";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':bank_name'    => $bankName,
                    ':bank_code'    => $bankCode,
                    ':account_name' => $accountName,
                    ':account_no'   => $accountNo,
                    ':currency'     => $currency,
                    ':is_active'    => $isActive,
                    ':sort_order'   => $sortOrder,
                    ':id'           => $postedId,
                ]);
                $id = $postedId;

            } else {
                // INSERT（不写 id，让 AUTO_INCREMENT 负责）
                $sql = "
                    INSERT INTO company_bank_accounts
                        (bank_name, bank_code, account_name, account_no, currency, is_active, sort_order, created_at)
                    VALUES
                        (:bank_name, :bank_code, :account_name, :account_no, :currency, :is_active, :sort_order, NOW())
                ";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':bank_name'    => $bankName,
                    ':bank_code'    => $bankCode,
                    ':account_name' => $accountName,
                    ':account_no'   => $accountNo,
                    ':currency'     => $currency,
                    ':is_active'    => $isActive,
                    ':sort_order'   => $sortOrder,
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            $saved = true;

            // redirect 回列表，避免重复提交
            header('Location: ' . url('admin/bank/accounts.php?ok=1&id=' . (int)$id));
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $prefix = $hasT
                ? t('admin.bank.account.error.save_failed', [], 'Save failed')
                : 'Save failed';
            $errors['general'] = $prefix . ': ' . $e->getMessage();
        }
    } else {
        // 有错误：保留用户填写
        $account['bank_name']    = $bankName;
        $account['bank_code']    = $bankCode;
        $account['account_name'] = $accountName;
        $account['account_no']   = $accountNo;
        $account['currency']     = $currency;
        $account['is_active']    = $isActive;
        $account['sort_order']   = $sortOrder;
    }
}

// ------------------------
// 3) 页面
// ------------------------
include __DIR__ . '/../include/header.php';
?>
<div class="admin-main">
    <div class="admin-main-inner">

        <div class="admin-card admin-card-elevated admin-card-narrow">

            <div class="form-page-header">
                <div>
                    <div class="form-page-eyebrow">
                        <?= h($hasT ? t('admin.bank.account.eyebrow', [], 'Bank') : 'Bank') ?>
                    </div>
                    <h2 class="form-page-title">
                        <?php if ($isNew): ?>
                            <?= h($hasT ? t('admin.bank.account.title_new_label', [], 'New bank account') : 'New bank account') ?>
                        <?php else: ?>
                            <?= h($account['bank_name'] . ' - ' . ($account['account_name'] ?? '')) ?>
                        <?php endif; ?>
                    </h2>
                    <div class="form-page-subtitle">
                        <?= h(
                            $hasT
                                ? t('admin.bank.account.subtitle', [], 'Define bank account and currency for internal use.')
                                : 'Define bank account and currency for internal use.'
                        ) ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($errors['general'])): ?>
                <div class="alert-error" style="margin-bottom:10px;">
                    <?= h($errors['general']) ?>
                </div>
            <?php endif; ?>

            <form method="post" class="form-layout">
                <input type="hidden" name="id" value="<?= (int)($account['id'] ?? 0) ?>">

                <div class="form-section">
                    <div class="form-section-header">
                        <div class="form-section-title">
                            <?= h($hasT ? t('admin.bank.account.section.details_title', [], 'Account details') : 'Account details') ?>
                        </div>
                        <div class="form-section-desc">
                            <?= h(
                                $hasT
                                    ? t('admin.bank.account.section.details_desc', [], 'Bank name, code, account name and currency.')
                                    : 'Bank name, code, account name and currency.'
                            ) ?>
                        </div>
                    </div>

                    <div class="form-grid form-grid-2">
                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.bank_name', [], 'Bank name') : 'Bank name') ?>
                                <span class="field-required">*</span>
                            </label>
                            <input type="text"
                                name="bank_name"
                                class="form-control"
                                value="<?= h($account['bank_name'] ?? '') ?>">
                            <?php if (isset($errors['bank_name'])): ?>
                                <div class="form-error"><?= h($errors['bank_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.bank_code', [], 'Bank code') : 'Bank code') ?>
                            </label>
                            <input type="text"
                                name="bank_code"
                                class="form-control"
                                placeholder="e.g. MBB, HLB, PBB"
                                value="<?= h($account['bank_code'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-grid form-grid-2">
                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.account_name', [], 'Account name') : 'Account name') ?>
                                <span class="field-required">*</span>
                            </label>
                            <input type="text"
                                name="account_name"
                                class="form-control"
                                value="<?= h($account['account_name'] ?? '') ?>">
                            <?php if (isset($errors['account_name'])): ?>
                                <div class="form-error"><?= h($errors['account_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.account_no', [], 'Account no.') : 'Account no.') ?>
                            </label>
                            <input type="text"
                                name="account_no"
                                class="form-control"
                                value="<?= h($account['account_no'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-grid form-grid-2">
                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.currency', [], 'Currency') : 'Currency') ?>
                            </label>
                            <input type="text"
                                name="currency"
                                class="form-control"
                                placeholder="e.g. MYR, USD, USDT"
                                value="<?= h($account['currency'] ?? 'MYR') ?>">
                        </div>

                        <div class="form-group">
                            <label class="field-label">
                                <?= h($hasT ? t('admin.bank.account.field.sort_order', [], 'Sort order') : 'Sort order') ?>
                            </label>
                            <input type="number"
                                name="sort_order"
                                class="form-control"
                                value="<?= (int)($account['sort_order'] ?? 0) ?>">
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                <?= h(tt('admin.bank.account.sort_tip', [], 'Leave 0/empty to auto set (max + 10).')) ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:4px;">
                        <label class="switch-label">
                            <span class="switch-text">
                                <?= h($hasT ? t('admin.bank.account.field.active', [], 'Active') : 'Active') ?>
                            </span>
                            <label class="switch">
                                <input type="checkbox" name="is_active" value="1"
                                    <?= ((int)($account['is_active'] ?? 1) === 1 ? 'checked' : '') ?>>
                                <span class="slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

                <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;">
                    <div class="form-footer-left">
                        <a href="<?= h(url('admin/bank/accounts.php')) ?>" class="btn btn-light">
                            <?= h($hasT ? t('admin.common.back', [], 'Back') : 'Back') ?>
                        </a>
                    </div>

                    <div class="form-footer-right">
                        <button type="submit" class="btn btn-primary">
                            <?= h($hasT ? t('admin.bank.account.save_btn', [], 'Save account') : 'Save account') ?>
                        </button>
                    </div>
                </div>

            </form>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
