<?php
// public/admin/customers/edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('CUSTOMER.E');   // 需要 CUSTOMER.E 才能新增/修改 customer

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

$id    = (int)($_GET['id'] ?? 0);
$isNew = $id === 0;

// back：如果有传，就回去原页；否则回 customers list
$back = $_GET['back'] ?? url('admin/customers/list.php');

// 页面标题（传给 header）
$page_title = $isNew
  ? ($hasT ? t('admin.customers.edit.title_new', [], 'New Customer') : 'New Customer')
  : ($hasT ? t('admin.customers.edit.title_edit', [], 'Edit Customer') : 'Edit Customer');

$errors = [];
$data = [
  'code'                 => '',
  'name'                 => '',
  'reg_no'               => '',
  'billing_name'         => '',
  'contact_name'         => '',
  'contact_phone'        => '',
  'contact_email'        => '',
  'default_receipt_name' => '',
  'default_receipt_nric' => '',
  'address1'             => '',
  'address2'             => '',
  'address3'             => '',
  'city'                 => '',
  'state'                => '',
  'postcode'             => '',
  'country'              => 'Malaysia',
  'is_active'            => 1,
];

if (!$isNew) {
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Customer not found');
    }
    $data = array_merge($data, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($data as $k => $v) {
        if ($k === 'is_active') continue;
        $data[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $data['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($data['code'] === '') {
        $errors['code'] = $hasT
            ? t('admin.customers.edit.error.code_required', [], 'Code is required')
            : 'Code is required';
    }
    if ($data['name'] === '') {
        $errors['name'] = $hasT
            ? t('admin.customers.edit.error.name_required', [], 'Name is required')
            : 'Name is required';
    }

    if (!$errors) {
        try {
            if ($isNew) {
                $sql = "INSERT INTO customers
                        (code, name, reg_no, billing_name,
                         contact_name, contact_phone, contact_email,
                         default_receipt_name, default_receipt_nric,
                         address1, address2, address3,
                         city, state, postcode, country,
                         is_active, created_at)
                        VALUES
                        (:code,:name,:reg_no,:billing_name,
                         :contact_name,:contact_phone,:contact_email,
                         :default_receipt_name,:default_receipt_nric,
                         :address1,:address2,:address3,
                         :city,:state,:postcode,:country,
                         :is_active, NOW())";
            } else {
                $sql = "UPDATE customers SET
                          code = :code,
                          name = :name,
                          reg_no = :reg_no,
                          billing_name = :billing_name,
                          contact_name = :contact_name,
                          contact_phone = :contact_phone,
                          contact_email = :contact_email,
                          default_receipt_name = :default_receipt_name,
                          default_receipt_nric = :default_receipt_nric,
                          address1 = :address1,
                          address2 = :address2,
                          address3 = :address3,
                          city = :city,
                          state = :state,
                          postcode = :postcode,
                          country = :country,
                          is_active = :is_active,
                          updated_at = NOW()
                        WHERE id = :id";
            }

            $params = [
                ':code'                 => $data['code'],
                ':name'                 => $data['name'],
                ':reg_no'               => $data['reg_no'],
                ':billing_name'         => $data['billing_name'],
                ':contact_name'         => $data['contact_name'],
                ':contact_phone'        => $data['contact_phone'],
                ':contact_email'        => $data['contact_email'],
                ':default_receipt_name' => $data['default_receipt_name'],
                ':default_receipt_nric' => $data['default_receipt_nric'],
                ':address1'             => $data['address1'],
                ':address2'             => $data['address2'],
                ':address3'             => $data['address3'],
                ':city'                 => $data['city'],
                ':state'                => $data['state'],
                ':postcode'             => $data['postcode'],
                ':country'              => $data['country'],
                ':is_active'            => $data['is_active'],
            ];
            if (!$isNew) {
                $params[':id'] = $id;
            }

            $st = $pdo->prepare($sql);
            $st->execute($params);

            if ($isNew) {
                $id = (int)$pdo->lastInsertId();
            }

            // 🔍 Audit log: CUSTOMER.CREATE / CUSTOMER.UPDATE
            if (function_exists('audit_log')) {
                $action = $isNew ? 'CUSTOMER.CREATE' : 'CUSTOMER.UPDATE';
                $extra  = [
                    'customer_id' => $id,
                    'code'        => $data['code'],
                    'name'        => $data['name'],
                    'is_active'   => (int)$data['is_active'],
                ];
                audit_log(
                    $pdo,
                    $action,
                    $extra,
                    'customer',
                    $id
                );
            }

            // 保存后回 customers list
            header('Location: ' . url('admin/customers/list.php?ok=1'));
            exit;

        } catch (PDOException $e) {
            // UNIQUE(code) 冲突
            if ($e->getCode() === '23000') {
                $errors['code'] = $hasT
                    ? t('admin.customers.edit.error.code_unique', [], 'Code already exists')
                    : 'Code already exists';
            } else {
                throw $e;
            }
        }
    }
}

include __DIR__ . '/../include/header.php';
?>

<!-- 顶部 Back 键 -->
<div style="margin-bottom:10px;">
  <a href="<?= h($back) ?>" class="btn btn-light">
    <?= h($hasT ? t('admin.common.back', [], 'Back') : 'Back') ?>
  </a>
</div>

<div class="admin-card admin-card-elevated admin-card-narrow">
  <div class="form-page-header">
    <div>
      <div class="form-page-eyebrow">
        <?php if ($isNew): ?>
          <?= h($hasT
            ? t('admin.customers.edit.eyebrow_new', [], 'Create customer profile')
            : 'Create customer profile') ?>
        <?php else: ?>
          <?= h($hasT
            ? t('admin.customers.edit.eyebrow_edit', [], 'Update customer profile')
            : 'Update customer profile') ?>
        <?php endif; ?>
      </div>
      <h2 class="form-page-title">
        <?php if ($isNew): ?>
          <?= h($hasT
            ? t('admin.customers.edit.title_new_label', [], 'New Customer')
            : 'New Customer') ?>
        <?php else: ?>
          <?= h($data['name'] ?: ($hasT
            ? t('admin.customers.edit.title_fallback', [], 'Customer')
            : 'Customer')) ?>
        <?php endif; ?>
      </h2>
      <div class="form-page-subtitle">
        <?= h($hasT
          ? t('admin.customers.edit.subtitle', [], 'Basic company details, contact person and default receipt info.')
          : 'Basic company details, contact person and default receipt info.'
        ) ?>
      </div>
    </div>
    <div class="form-page-meta" style="display:flex;gap:8px;align-items:center;">
      <?php if (!$isNew): ?>
        <span class="badge-soft"><?= h($data['code']) ?></span>
      <?php endif; ?>
    </div>
  </div>

  <form method="post" class="form-layout">

    <!-- Section 1: Basic info -->
    <div class="form-section">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">
            <?= h($hasT
              ? t('admin.customers.edit.section.basic_title', [], 'Basic information')
              : 'Basic information'
            ) ?>
          </div>
          <div class="form-section-desc">
            <?= h($hasT
              ? t('admin.customers.edit.section.basic_desc', [], 'Internal code and legal name of the company.')
              : 'Internal code and legal name of the company.'
            ) ?>
          </div>
        </div>
      </div>

      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.code', [], 'Code')
              : 'Code') ?>
            <span class="field-required">*</span>
          </label>
          <input
            type="text"
            id="code"
            name="code"
            class="form-control"
            value="<?= h($data['code']) ?>"
            required
          >
          <?php if (isset($errors['code'])): ?>
            <div class="form-error"><?= h($errors['code']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.name', [], 'Name')
              : 'Name') ?>
            <span class="field-required">*</span>
          </label>
          <input
            type="text"
            id="name"
            name="name"
            class="form-control"
            value="<?= h($data['name']) ?>"
            required
          >
          <?php if (isset($errors['name'])): ?>
            <div class="form-error"><?= h($errors['name']) ?></div>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.reg_no', [], 'Reg. No.')
              : 'Reg. No.') ?>
          </label>
          <input type="text" name="reg_no" class="form-control"
                 value="<?= h($data['reg_no']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.billing_name', [], 'Billing Name')
              : 'Billing Name') ?>
          </label>
          <input type="text" name="billing_name" class="form-control"
                 value="<?= h($data['billing_name']) ?>">
        </div>
      </div>
    </div>

    <!-- Section 2: Contact & receipt -->
    <div class="form-section">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">
            <?= h($hasT
              ? t('admin.customers.edit.section.contact_title', [], 'Contact & receipt')
              : 'Contact & receipt') ?>
          </div>
          <div class="form-section-desc">
            <?= h($hasT
              ? t('admin.customers.edit.section.contact_desc', [], 'Default person to contact and who signs on receipts.')
              : 'Default person to contact and who signs on receipts.') ?>
          </div>
        </div>
      </div>

      <div class="form-grid form-grid-3">
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.contact_name', [], 'Contact Person')
              : 'Contact Person') ?>
          </label>
          <input type="text" name="contact_name" class="form-control"
                 value="<?= h($data['contact_name']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.contact_phone', [], 'Contact Phone')
              : 'Contact Phone') ?>
          </label>
          <input type="text" name="contact_phone" class="form-control"
                 value="<?= h($data['contact_phone']) ?>">
        </div>

        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.contact_email', [], 'Contact Email')
              : 'Contact Email') ?>
          </label>
          <input type="email" name="contact_email" class="form-control"
                 value="<?= h($data['contact_email']) ?>">
        </div>
      </div>

      <div class="form-grid form-grid-2">
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.default_receipt_name', [], 'Default Receipt Name')
              : 'Default Receipt Name') ?>
          </label>
          <input type="text" name="default_receipt_name" class="form-control"
                 value="<?= h($data['default_receipt_name']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.default_receipt_nric', [], 'Default Receipt NRIC')
              : 'Default Receipt NRIC') ?>
          </label>
          <input type="text" name="default_receipt_nric" class="form-control"
                 value="<?= h($data['default_receipt_nric']) ?>">
        </div>
      </div>
    </div>

    <!-- Section 3: Address -->
    <div class="form-section">
      <div class="form-section-header">
        <div>
          <div class="form-section-title">
            <?= h($hasT
              ? t('admin.customers.edit.section.address_title', [], 'Address')
              : 'Address') ?>
          </div>
          <div class="form-section-desc">
            <?= h($hasT
              ? t('admin.customers.edit.section.address_desc', [], 'Registered or billing address for this customer.')
              : 'Registered or billing address for this customer.') ?>
          </div>
        </div>
      </div>

      <div class="form-grid form-grid-1">
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.address1', [], 'Address line 1')
              : 'Address line 1') ?>
          </label>
          <input type="text" name="address1" class="form-control"
                 value="<?= h($data['address1']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.address2', [], 'Address line 2')
              : 'Address line 2') ?>
          </label>
          <input type="text" name="address2" class="form-control"
                 value="<?= h($data['address2']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.address3', [], 'Address line 3')
              : 'Address line 3') ?>
          </label>
          <input type="text" name="address3" class="form-control"
                 value="<?= h($data['address3']) ?>">
        </div>
      </div>

      <div class="form-grid form-grid-4">
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.postcode', [], 'Postcode')
              : 'Postcode') ?>
          </label>
          <input type="text" name="postcode" class="form-control"
                 value="<?= h($data['postcode']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.city', [], 'City')
              : 'City') ?>
          </label>
          <input type="text" name="city" class="form-control"
                 value="<?= h($data['city']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.state', [], 'State')
              : 'State') ?>
          </label>
          <input type="text" name="state" class="form-control"
                 value="<?= h($data['state']) ?>">
        </div>
        <div class="form-group">
          <label class="field-label">
            <?= h($hasT
              ? t('admin.customers.edit.field.country', [], 'Country')
              : 'Country') ?>
          </label>
          <input type="text" name="country" class="form-control"
                 value="<?= h($data['country']) ?>">
        </div>
      </div>
    </div>

    <!-- Section 4: Status & actions -->
    <div class="form-footer-row">
      <div class="form-footer-left">
        <label class="switch-label">
          <span class="switch-text">
            <?= h($hasT
              ? t('admin.customers.edit.field.status_active', [], 'Active')
              : 'Active') ?>
          </span>
          <label class="switch">
            <input type="checkbox" name="is_active" value="1" <?= $data['is_active'] ? 'checked' : '' ?>>
            <span class="slider"></span>
          </label>
        </label>
      </div>
      <div class="form-footer-right">
        <a href="<?= h($back) ?>" class="btn btn-light">
          <?= h($hasT ? t('admin.common.cancel', [], 'Cancel') : 'Cancel') ?>
        </a>
        <button type="submit" class="btn btn-primary">
          <?= h($hasT ? t('admin.common.save', [], 'Save') : 'Save') ?>
        </button>
      </div>
    </div>

  </form>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const nameInput = document.getElementById("name");
    const codeInput = document.getElementById("code");
    const isNew = <?= $isNew ? 'true' : 'false' ?>;

    if (!nameInput || !codeInput) return;

    nameInput.addEventListener("input", function () {
        if (!isNew) return; // 只有新增时自动生成

        const name = nameInput.value.trim();
        if (name === "") {
            codeInput.value = "";
            return;
        }

        // 取名字里所有英文字母
        let alpha = name.replace(/[^A-Za-z]/g, "");

        let code = "";
        if (alpha.length >= 4) {
            code = alpha.substring(0, 4).toUpperCase();
        } else if (alpha.length > 0) {
            // 不足 4 个，用 X 补到 3~4 位
            code = alpha.toUpperCase();
            while (code.length < 3) {
                code += "X";
            }
        } else {
            // 全中文或符号 → fallback
            code = "CUST";
        }

        codeInput.value = code;
    });
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
