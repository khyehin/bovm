<?php
// public/admin/payers/payer_staff_edit.php
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
    $id     = (int)($_POST['id'] ?? 0);
    $name   = trim($_POST['staff_name'] ?? '');
    $ic     = trim($_POST['ic_no'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $active = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $error = $hasT
            ? t('admin.payer_staff.error.name_required', [], 'Staff name is required.')
            : 'Staff name is required.';
    } else {
        if ($id > 0) {
            $st = $pdo->prepare("
                UPDATE payer_company_staff
                   SET staff_name = ?, ic_no = ?, phone = ?, email = ?, is_active = ?
                 WHERE id = ?
            ");
            $st->execute([$name, $ic, $phone, $email, $active, $id]);

            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'PAYER.STAFF.UPDATE',
                    [
                        'description' => 'Update payer staff',
                        'staff_id'    => $id,
                        'name'        => $name,
                        'ic_no'       => $ic,
                        'phone'       => $phone,
                        'email'       => $email,
                        'is_active'   => $active,
                    ],
                    'payer_staff',
                    $id
                );
            }

        } else {
            // 全局 staff pool：payer_company_id 设 NULL
            $st = $pdo->prepare("
                INSERT INTO payer_company_staff
                  (payer_company_id, staff_name, ic_no, phone, email, is_active, created_at)
                VALUES (NULL, ?, ?, ?, ?, ?, NOW())
            ");
            $st->execute([$name, $ic, $phone, $email, $active]);
            $id = (int)$pdo->lastInsertId();

            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    'PAYER.STAFF.CREATE',
                    [
                        'description' => 'Create payer staff',
                        'staff_id'    => $id,
                        'name'        => $name,
                        'ic_no'       => $ic,
                        'phone'       => $phone,
                        'email'       => $email,
                        'is_active'   => $active,
                    ],
                    'payer_staff',
                    $id
                );
            }
        }

        header('Location: ' . url('admin/payers/payer_staff_list.php?ok=1'));
        exit;
    }
}

$staff = [
    'id'         => $id,
    'staff_name' => '',
    'ic_no'      => '',
    'phone'      => '',
    'email'      => '',
    'is_active'  => 1,
];

if ($id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare("
        SELECT * FROM payer_company_staff WHERE id = ?
    ");
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $staff['staff_name'] = $row['staff_name'] ?? '';
        $staff['ic_no']      = $row['ic_no']      ?? '';
        $staff['phone']      = $row['phone']      ?? '';
        $staff['email']      = $row['email']      ?? '';
        $staff['is_active']  = isset($row['is_active']) ? (int)$row['is_active'] : 1;
    } else {
        http_response_code(404);
        exit('Payer staff not found.');
    }
}

$page_title = $id > 0
    ? ($hasT ? t('admin.payer_staff.edit_title', [], 'Edit Payer Staff') : 'Edit Payer Staff')
    : ($hasT ? t('admin.payer_staff.new_title', [], 'New Payer Staff')  : 'New Payer Staff');

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="max-width:640px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.payer_staff.eyebrow', [], 'Master data') : 'Master data') ?>
          </div>
          <h1 class="page-title"><?= h($page_title) ?></h1>
          <div class="form-page-subtitle">
            <?= h($hasT ? t('admin.payer_staff.subtitle', [], 'Signatory staff that can be selected for any payer company.') : 'Signatory staff that can be selected for any payer company.') ?>
          </div>
        </div>
      </div>

      <div class="admin-card-body">
        <?php if ($error !== ''): ?>
          <div class="alert-error" style="margin-bottom:12px;"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <input type="hidden" name="id" value="<?= (int)$staff['id'] ?>">

          <div class="form-group">
            <label>
              <?= h($hasT ? t('admin.payer_staff.field.name', [], 'Name') : 'Name') ?>
              <span style="color:#dc2626">*</span>
            </label>
            <input
              type="text"
              name="staff_name"
              class="form-control"
              value="<?= h($staff['staff_name']) ?>"
              required
            >
          </div>

          <div class="form-group">
            <label><?= h($hasT ? t('admin.payer_staff.field.ic', [], 'IC / Passport') : 'IC / Passport') ?></label>
            <input
              type="text"
              name="ic_no"
              class="form-control"
              value="<?= h($staff['ic_no']) ?>"
            >
          </div>

          <div class="form-group">
            <label><?= h($hasT ? t('admin.payer_staff.field.phone', [], 'Phone') : 'Phone') ?></label>
            <input
              type="text"
              name="phone"
              class="form-control"
              value="<?= h($staff['phone']) ?>"
            >
          </div>

          <div class="form-group">
            <label><?= h($hasT ? t('admin.payer_staff.field.email', [], 'Email') : 'Email') ?></label>
            <input
              type="email"
              name="email"
              class="form-control"
              value="<?= h($staff['email']) ?>"
            >
          </div>

          <div class="form-group" style="margin-top:4px;">
            <label>
              <input type="checkbox" name="is_active" value="1"
                <?= $staff['is_active'] ? 'checked' : '' ?>>
              <?= h($hasT ? t('admin.payer_staff.field.active', [], 'Active') : 'Active') ?>
            </label>
          </div>

          <div style="margin-top:18px; display:flex; gap:8px;">
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.common.save', [], 'Save') : 'Save') ?>
            </button>
            <a href="<?= h(url('admin/payers/payer_staff_list.php')) ?>" class="btn btn-light">
              <?= h($hasT ? t('admin.common.cancel', [], 'Cancel') : 'Cancel') ?>
            </a>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
