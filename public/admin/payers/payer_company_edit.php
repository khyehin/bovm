<?php
// public/admin/payers/payer_company_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $reg  = trim($_POST['reg_no'] ?? '');

    if ($name === '') {
        $error = $hasT
            ? t('admin.payer_company.error.name_required', [], 'Company name is required.')
            : 'Company name is required.';
    } else {
        if ($id > 0) {
            $st = $pdo->prepare("
                UPDATE payer_companies
                   SET name = ?, reg_no = ?
                 WHERE id = ?
            ");
            $st->execute([$name, $reg, $id]);

            // ✅ 新签名 audit_log
            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'PAYER.COMPANY.UPDATE',
                    [
                        'description'       => 'Update payer company',
                        'payer_company_id'  => $id,
                        'name'              => $name,
                        'reg_no'            => $reg,
                    ],
                    'payer_company',
                    $id
                );
            }

        } else {
            $st = $pdo->prepare("
                INSERT INTO payer_companies (name, reg_no, created_at)
                VALUES (?, ?, NOW())
            ");
            $st->execute([$name, $reg]);
            $id = (int)$pdo->lastInsertId();

            // ✅ 新签名 audit_log
            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'PAYER.COMPANY.CREATE',
                    [
                        'description'       => 'Create payer company',
                        'payer_company_id'  => $id,
                        'name'              => $name,
                        'reg_no'            => $reg,
                    ],
                    'payer_company',
                    $id
                );
            }
        }

        header('Location: ' . url('admin/payers/payer_company_list.php?ok=1'));
        exit;
    }
}

$company = [
    'id'     => $id,
    'name'   => '',
    'reg_no' => '',
];

if ($id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare("SELECT * FROM payer_companies WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $company['name']   = $row['name']   ?? ($row['company_name'] ?? '');
        $company['reg_no'] = $row['reg_no'] ?? ($row['company_reg_no'] ?? '');
    } else {
        http_response_code(404);
        exit('Payer company not found.');
    }
}

$page_title = $id > 0
    ? ($hasT ? t('admin.payer_company.edit_title', [], 'Edit Payer Company') : 'Edit Payer Company')
    : ($hasT ? t('admin.payer_company.new_title', [], 'New Payer Company')  : 'New Payer Company');

include __DIR__ . '/../include/header.php';

?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="max-width:640px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.payer_company.eyebrow', [], 'Master data') : 'Master data') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h($hasT ? t('admin.payer_company.subtitle', [], 'Basic payer company information.') : 'Basic payer company information.') ?>
          </div>
        </div>
      </div>

      <div class="admin-card-body">
        <?php if ($error !== ''): ?>
          <div class="alert-error" style="margin-bottom:12px;"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$company['id'] ?>">

          <div class="form-group">
            <label>
              <?= h($hasT ? t('admin.payer_company.field.name', [], 'Company Name') : 'Company Name') ?>
              <span style="color:#dc2626">*</span>
            </label>
            <input
              type="text"
              name="name"
              class="form-control"
              value="<?= h($company['name']) ?>"
              required
            >
          </div>

          <div class="form-group">
            <label>
              <?= h($hasT ? t('admin.payer_company.field.reg_no', [], 'Registration No') : 'Registration No') ?>
            </label>
            <input
              type="text"
              name="reg_no"
              class="form-control"
              value="<?= h($company['reg_no']) ?>"
            >
          </div>

          <div style="margin-top:18px; display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.common.save', [], 'Save') : 'Save') ?>
            </button>
            <a href="<?= h(url('admin/payers/payer_company_list.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.cancel', [], 'Cancel') : 'Cancel') ?>
            </a>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
