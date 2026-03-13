<?php
// public/user/company1/customer_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_customer_category(1);

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(404);
  exit('Customer not found');
}

// 只允许编辑 category_id = 3 的 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND category_id = 3");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) {
  http_response_code(404);
  exit('Customer not found');
}

$errors = [];
$data = [
  'code'                 => (string)($row['code'] ?? ''),
  'name'                 => (string)($row['name'] ?? ''),
  'reg_no'               => (string)($row['reg_no'] ?? ''),
  'billing_name'         => (string)($row['billing_name'] ?? ''),
  'contact_name'         => (string)($row['contact_name'] ?? ''),
  'contact_phone'        => (string)($row['contact_phone'] ?? ''),
  'contact_email'        => (string)($row['contact_email'] ?? ''),
  'default_receipt_name' => (string)($row['default_receipt_name'] ?? ''),
  'default_receipt_nric' => (string)($row['default_receipt_nric'] ?? ''),
  'address1'             => (string)($row['address1'] ?? ''),
  'address2'             => (string)($row['address2'] ?? ''),
  'address3'             => (string)($row['address3'] ?? ''),
  'city'                 => (string)($row['city'] ?? ''),
  'state'                => (string)($row['state'] ?? ''),
  'postcode'             => (string)($row['postcode'] ?? ''),
  'country'              => (string)($row['country'] ?? 'Malaysia'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($data as $k => $v) {
    $data[$k] = trim((string)($_POST[$k] ?? ''));
  }

  if ($data['code'] === '') {
    $errors['code'] = 'Code is required';
  }
  if ($data['name'] === '') {
    $errors['name'] = 'Name is required';
  }

  if (!$errors) {
    try {
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
                updated_at = NOW()
              WHERE id = :id AND category_id = 3";

      $st = $pdo->prepare($sql);
      $st->execute([
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
        ':id'                   => $id,
      ]);

      header('Location: ' . url('user/company1/customers.php?ok=2'));
      exit;
    } catch (Throwable $e) {
      $errors['global'] = 'Update failed.';
    }
  }
}

$page_title = 'Edit customer';
$active_nav = 'company1_customers';
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow">
      <div class="form-page-header" style="margin-bottom:14px;">
        <div>
          <div class="form-page-eyebrow">Customers</div>
          <h2 class="form-page-title">Edit customer</h2>
          <div class="form-page-subtitle">Update basic company details, contact person and address.</div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('user/company1/customers.php')) ?>" class="btn btn-light">← Back to list</a>
        </div>
      </div>

      <?php if (!empty($errors['global'])): ?>
        <div class="alert-error" style="margin-bottom:10px;"><?= h($errors['global']) ?></div>
      <?php endif; ?>

      <form method="post" class="form-layout">
        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">Basic information</div>
            <div class="form-section-desc">Internal code and legal name of the company.</div>
          </div>
          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">Code<span style="color:#dc2626;"> *</span></label>
              <input type="text" name="code" class="form-control" value="<?= h($data['code']) ?>">
              <?php if (!empty($errors['code'])): ?><div class="form-error"><?= h($errors['code']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="field-label">Name<span style="color:#dc2626;"> *</span></label>
              <input type="text" name="name" class="form-control" value="<?= h($data['name']) ?>">
              <?php if (!empty($errors['name'])): ?><div class="form-error"><?= h($errors['name']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="field-label">Registration No</label>
              <input type="text" name="reg_no" class="form-control" value="<?= h($data['reg_no']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Billing name</label>
              <input type="text" name="billing_name" class="form-control" value="<?= h($data['billing_name']) ?>">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">Contact & receipt</div>
            <div class="form-section-desc">Contact person and default receipt info.</div>
          </div>
          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">Contact name</label>
              <input type="text" name="contact_name" class="form-control" value="<?= h($data['contact_name']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Contact phone</label>
              <input type="text" name="contact_phone" class="form-control" value="<?= h($data['contact_phone']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Contact email</label>
              <input type="email" name="contact_email" class="form-control" value="<?= h($data['contact_email']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Default receipt name</label>
              <input type="text" name="default_receipt_name" class="form-control" value="<?= h($data['default_receipt_name']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Default receipt NRIC/ID</label>
              <input type="text" name="default_receipt_nric" class="form-control" value="<?= h($data['default_receipt_nric']) ?>">
            </div>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">Address</div>
            <div class="form-section-desc">Mailing address used on invoices and receipts.</div>
          </div>
          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">Address line 1</label>
              <input type="text" name="address1" class="form-control" value="<?= h($data['address1']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Address line 2</label>
              <input type="text" name="address2" class="form-control" value="<?= h($data['address2']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Address line 3</label>
              <input type="text" name="address3" class="form-control" value="<?= h($data['address3']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">City</label>
              <input type="text" name="city" class="form-control" value="<?= h($data['city']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">State</label>
              <input type="text" name="state" class="form-control" value="<?= h($data['state']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Postcode</label>
              <input type="text" name="postcode" class="form-control" value="<?= h($data['postcode']) ?>">
            </div>
            <div class="form-group">
              <label class="field-label">Country</label>
              <input type="text" name="country" class="form-control" value="<?= h($data['country']) ?>">
            </div>
          </div>
        </div>

        <div style="margin-top:8px;">
          <button type="submit" class="btn btn-primary">Save changes</button>
          <a href="<?= h(url('user/company1/customers.php')) ?>" class="btn btn-light" style="margin-left:8px;">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>

