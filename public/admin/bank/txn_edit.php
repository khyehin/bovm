<?php
// public/admin/bank/txn_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
  require_perm('BANK.TXN.E');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('tt')) {
  function tt(string $key, array $vars = [], string $fallback = ''): string {
    if (function_exists('t')) return t($key, $vars, $fallback);
    return $fallback !== '' ? $fallback : $key;
  }
}

/* ==========================
   Schema (based on your screenshot)
   company_bank_txn:
     - bank_id NOT NULL
     - bank_account_id nullable (optional)
     - txn_type enum('IN','OUT')
     - allocate_group_id nullable
     - txn_date date
     - ref_no, description
     - currency (default MYR)
     - amount, rate_to_myr, amount_myr
     - created_at, updated_at

   company_bank_txn_files:
     - bank_txn_id NOT NULL
     - txn_id nullable
     - file_path NOT NULL
     - file_name nullable
     - file_mime nullable
     - created_at default current_timestamp()
     - file_size nullable
========================== */

$id      = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$bank_id = (int)($_GET['bank_id'] ?? $_POST['bank_id'] ?? 0);

// Enter from all txns new page (no bank_id/id)
$enterFromAll = ($bank_id <= 0 && $id <= 0);

// If editing but bank_id not provided, load from txn
if ($bank_id <= 0 && $id > 0) {
  $st = $pdo->prepare("SELECT bank_id FROM company_bank_txn WHERE id = :id");
  $st->execute([':id' => $id]);
  $bank_id = (int)$st->fetchColumn();
}

// Load active banks
$allBanks = $pdo->query("
  SELECT id, bank_name, bank_code, account_name, account_no, currency, is_active, sort_order
    FROM company_bank_accounts
   WHERE is_active = 1
   ORDER BY sort_order ASC, bank_name ASC, account_name ASC, id ASC
")->fetchAll();

$bankMap = [];
foreach ($allBanks as $b) $bankMap[(int)$b['id']] = $b;

$bank = null;
$baseCurrency = 'MYR';
if ($bank_id > 0) {
  $bank = $bankMap[$bank_id] ?? null;
  if (!$bank) {
    $st = $pdo->prepare("SELECT * FROM company_bank_accounts WHERE id = :id");
    $st->execute([':id' => $bank_id]);
    $bank = $st->fetch();
  }
  if (!$bank) {
    http_response_code(404);
    exit(tt('admin.bank.txn.err_bank_not_found', [], 'Bank account not found'));
  }
  $baseCurrency = $bank['currency'] ?: 'MYR';
}

$isNew = ($id <= 0);
$txn = [];
$attachments = [];

if ($isNew) {
  $txn = [
    'id'              => 0,
    'bank_id'         => $bank_id,
    'bank_account_id' => $bank_id,
    'txn_date'        => date('Y-m-d'),
    'txn_type'        => 'IN',
    'ref_no'          => '',
    'description'     => '',
    'currency'        => $baseCurrency,
    'amount'          => 0,
    'rate_to_myr'     => ($baseCurrency === 'MYR' ? 1.0 : 1.0),
    'amount_myr'      => 0,
  ];
} else {
  $st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id");
  $st->execute([':id' => $id]);
  $txn = $st->fetch();
  if (!$txn) {
    http_response_code(404);
    exit(tt('admin.bank.txn_edit.err_txn_not_found', [], 'Bank transaction not found'));
  }

  // Attachments
  $st = $pdo->prepare("
    SELECT id, file_name, file_path, file_mime, file_size, created_at
      FROM company_bank_txn_files
     WHERE bank_txn_id = :tid
     ORDER BY id ASC
  ");
  $st->execute([':tid' => $id]);
  $attachments = $st->fetchAll();

  if ($bank_id <= 0) $bank_id = (int)($txn['bank_id'] ?? 0);
  if ($bank_id > 0 && !$bank && isset($bankMap[$bank_id])) {
    $bank = $bankMap[$bank_id];
    $baseCurrency = $bank['currency'] ?: 'MYR';
  }
}

$form_txn_type  = (string)($txn['txn_type'] ?? 'IN');
$target_bank_id = 0;

$page_title = $isNew
  ? tt('admin.bank.txn_edit.title_new', [], 'New bank transaction')
  : tt('admin.bank.txn_edit.title_edit', [], 'Edit bank transaction');

$errors = [];
$saved  = false;

// Upload dir
$uploadBaseDir = __DIR__ . '/../../../uploads/bank_txn';
if (!is_dir($uploadBaseDir)) @mkdir($uploadBaseDir, 0777, true);

$allowedExt = ['pdf','png','jpg','jpeg','gif'];

// Detect mime safely
function detect_mime(string $path): string {
  if (function_exists('finfo_open')) {
    $f = finfo_open(FILEINFO_MIME_TYPE);
    if ($f) {
      $m = finfo_file($f, $path) ?: '';
      finfo_close($f);
      return (string)$m;
    }
  }
  return '';
}

function ini_to_bytes(string $val): int {
  $val = trim($val);
  if ($val === '') return 0;
  $last = strtolower($val[strlen($val)-1] ?? '');
  $num = (float)$val;
  switch ($last) {
    case 'g': $num *= 1024;
    case 'm': $num *= 1024;
    case 'k': $num *= 1024;
  }
  return (int)$num;
}

/**
 * Insert into company_bank_txn_files MUST include bank_txn_id (NOT NULL)
 * Supports multiple files.
 */
function handle_multi_upload(PDO $pdo, int $bankTxnId, string $uploadBaseDir, array $allowedExt, array &$errors): void
{
  if (empty($_FILES['attachments']) || !is_array($_FILES['attachments']['name'])) return;

  $files = $_FILES['attachments'];
  $count = count($files['name']);

  $ins = $pdo->prepare("
    INSERT INTO company_bank_txn_files
      (bank_txn_id, txn_id, file_path, file_name, file_mime, file_size, created_at)
    VALUES
      (:bank_txn_id, :txn_id, :file_path, :file_name, :file_mime, :file_size, NOW())
  ");

  for ($i = 0; $i < $count; $i++) {
    $name = (string)($files['name'][$i] ?? '');
    $tmp  = (string)($files['tmp_name'][$i] ?? '');
    $err  = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    $size = (int)($files['size'][$i] ?? 0);

    if ($err === UPLOAD_ERR_NO_FILE) continue;

    if ($err !== UPLOAD_ERR_OK) {
      $errors['attach_multi'][] = tt('admin.bank.txn_edit.attach.err_upload', [
        'name' => $name,
        'code' => (string)$err
      ], 'File "{name}" upload error (code {code}).');
      continue;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
      $errors['attach_multi'][] = tt('admin.bank.txn_edit.attach.err_type', [
        'name' => $name,
        'type' => $ext
      ], 'File "{name}" skipped (invalid type: {type}).');
      continue;
    }

    $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = rtrim($uploadBaseDir, '/\\') . '/' . $safeName;

    if (!move_uploaded_file($tmp, $destPath)) {
      $errors['attach_multi'][] = tt('admin.bank.txn_edit.attach.err_move', [
        'name' => $name
      ], 'File "{name}" failed to move.');
      continue;
    }

    $relPath = 'uploads/bank_txn/' . $safeName;
    $mime = detect_mime($destPath);

    $ins->execute([
      ':bank_txn_id' => $bankTxnId,
      ':txn_id'      => $bankTxnId,
      ':file_path'   => $relPath,
      ':file_name'   => $name,
      ':file_mime'   => $mime,
      ':file_size'   => $size,
    ]);
  }
}

/**
 * Delete attachment safely (DB + file).
 * Ensures the file belongs to the txn.
 */
function delete_attachment(PDO $pdo, int $txnId, int $fileId, string $uploadBaseDir, array &$errors): bool
{
  if ($txnId <= 0 || $fileId <= 0) return false;

  $st = $pdo->prepare("
    SELECT id, file_path
      FROM company_bank_txn_files
     WHERE id = :fid AND bank_txn_id = :tid
     LIMIT 1
  ");
  $st->execute([':fid' => $fileId, ':tid' => $txnId]);
  $row = $st->fetch();
  if (!$row) {
    $errors['general'] = tt('admin.bank.txn_edit.attach.del_not_found', [], 'Attachment not found.');
    return false;
  }

  $filePath = (string)($row['file_path'] ?? '');
  // Build absolute path from project root (same pattern you used to store: "uploads/bank_txn/xxx")
  $projectRoot = realpath(__DIR__ . '/../../../');
  $abs = $projectRoot ? ($projectRoot . '/' . ltrim($filePath, '/')) : '';

  // Restrict deletion to uploadBaseDir only
  $uploadReal = realpath($uploadBaseDir);
  $absReal    = ($abs !== '' ? realpath($abs) : false);

  // Delete DB row first (so if file missing, still remove record)
  $del = $pdo->prepare("DELETE FROM company_bank_txn_files WHERE id = :fid AND bank_txn_id = :tid");
  $del->execute([':fid' => $fileId, ':tid' => $txnId]);

  // Then attempt to delete physical file if inside uploads dir
  if ($uploadReal && $absReal && strpos($absReal, $uploadReal) === 0) {
    @unlink($absReal);
  }

  return true;
}

/* ==========================
   POST save / delete
========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // 1) Detect oversized POST (post_max_size exceeded) -> PHP will give empty $_POST and $_FILES
  if (empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH'])) {
    $postMax = ini_get('post_max_size') ?: 'unknown';
    $upMax   = ini_get('upload_max_filesize') ?: 'unknown';
    $errors['general'] = "Upload failed: request too large. Server limits: post_max_size={$postMax}, upload_max_filesize={$upMax}.";
  }

  // 2) Attachment delete action (do it early, before save)
  if (!$errors && (string)($_POST['action'] ?? '') === 'delete_attachment') {
    $postedId = (int)($_POST['id'] ?? 0);
    $bank_id  = (int)($_POST['bank_id'] ?? 0);
    $fileId   = (int)($_POST['file_id'] ?? 0);

    if ($postedId <= 0) {
      $errors['general'] = tt('admin.bank.txn_edit.attach.del_need_txn', [], 'Please save transaction first before deleting attachments.');
    } else {
      try {
        $pdo->beginTransaction();
        delete_attachment($pdo, $postedId, $fileId, $uploadBaseDir, $errors);
        $pdo->commit();

        // Reload txn + attachments after delete
        $st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id");
        $st->execute([':id' => $postedId]);
        $txn = $st->fetch() ?: $txn;
        $isNew = false;

        $st = $pdo->prepare("
          SELECT id, file_name, file_path, file_mime, file_size, created_at
            FROM company_bank_txn_files
           WHERE bank_txn_id = :tid
           ORDER BY id ASC
        ");
        $st->execute([':tid' => $postedId]);
        $attachments = $st->fetchAll();

        $id = $postedId;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['general'] = tt('admin.bank.txn_edit.attach.del_failed', [], 'Delete failed') . ': ' . $e->getMessage();
      }
    }
  }

  // 3) Normal save action
  if (!$errors && (string)($_POST['action'] ?? '') !== 'delete_attachment') {

    $postedId = (int)($_POST['id'] ?? 0);
    $bank_id  = (int)($_POST['bank_id'] ?? 0);

    $txn_date = trim((string)($_POST['txn_date'] ?? ''));
    $rawType  = strtoupper(trim((string)($_POST['txn_type'] ?? 'IN')));
    if (!in_array($rawType, ['IN','OUT','ALLOCATE'], true)) $rawType = 'IN';

    // edit mode: lock ALLOCATE
    if ($postedId > 0 && $rawType === 'ALLOCATE') {
      $rawType = (string)($txn['txn_type'] ?? 'IN');
    }

    $form_txn_type  = $rawType;
    $txn_mode       = $rawType;
    $target_bank_id = (int)($_POST['target_bank_id'] ?? 0);

    $ref_no      = trim((string)($_POST['ref_no'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $amount      = (float)($_POST['amount'] ?? 0);
    $currency    = trim((string)($_POST['currency'] ?? $baseCurrency));
    $rate_to_myr = (isset($_POST['rate_to_myr']) && (string)$_POST['rate_to_myr'] !== '') ? (float)$_POST['rate_to_myr'] : null;

    if ($bank_id <= 0) $errors['general'] = tt('admin.bank.txn.err_missing_bank', [], 'Missing bank_id');
    if ($txn_date === '') $errors['txn_date'] = tt('admin.bank.txn_edit.error.date_required', [], 'Date is required.');
    if ($amount == 0.0) $errors['amount'] = tt('admin.bank.txn_edit.error.amount_required', [], 'Amount cannot be zero.');

    if ($bank_id > 0 && isset($bankMap[$bank_id])) {
      $bank = $bankMap[$bank_id];
      $baseCurrency = $bank['currency'] ?: 'MYR';
    }

    if ($currency === '') $currency = $baseCurrency;
    if ($txn_mode === 'ALLOCATE') $currency = $baseCurrency;

    // allocate checks
    $targetBank = null;
    if ($txn_mode === 'ALLOCATE') {
      if ($target_bank_id <= 0) {
        $errors['target_bank_id'] = tt('admin.bank.txn_edit.error.target_required', [], 'Please select target bank.');
      } elseif ($target_bank_id === $bank_id) {
        $errors['target_bank_id'] = tt('admin.bank.txn_edit.error.target_same', [], 'Cannot allocate to the same bank.');
      } elseif (!isset($bankMap[$target_bank_id])) {
        $errors['target_bank_id'] = tt('admin.bank.txn_edit.error.target_invalid', [], 'Invalid target bank.');
      } else {
        $targetBank = $bankMap[$target_bank_id];
        $targetCur = $targetBank['currency'] ?: 'MYR';
        if ($targetCur !== $currency) {
          $errors['target_bank_id'] = tt('admin.bank.txn_edit.error.allocate_same_currency', [], 'Allocate only supports same-currency accounts for now.');
        }
      }
    }

    // calc MYR
    $amount_myr = 0.0;
    if (strtoupper($currency) === 'MYR') {
      $rate_to_myr = 1.0;
      $amount_myr  = $amount;
    } else {
      if ($rate_to_myr === null) {
        $errors['rate_to_myr'] = tt('admin.bank.txn_edit.error.rate_required', [], 'Rate to MYR is required for non-MYR currency.');
      } else {
        $amount_myr = $amount * $rate_to_myr;
      }
    }

    if (!$errors) {
      try {
        $pdo->beginTransaction();

        // ========== ALLOCATE new only ==========
        if ($txn_mode === 'ALLOCATE' && $postedId === 0) {

          // OUT
          $stOut = $pdo->prepare("
            INSERT INTO company_bank_txn
              (bank_id, bank_account_id, allocate_group_id, txn_date, txn_type, ref_no, description,
               currency, amount, rate_to_myr, amount_myr, created_at, updated_at)
            VALUES
              (:bank_id, :bank_account_id, NULL, :txn_date, 'OUT', :ref_no, :description,
               :currency, :amount, :rate_to_myr, :amount_myr, NOW(), NOW())
          ");
          $stOut->execute([
            ':bank_id'         => $bank_id,
            ':bank_account_id' => $bank_id,
            ':txn_date'        => $txn_date,
            ':ref_no'          => $ref_no,
            ':description'     => $description,
            ':currency'        => $currency,
            ':amount'          => $amount,
            ':rate_to_myr'     => $rate_to_myr,
            ':amount_myr'      => $amount_myr,
          ]);

          $outId = (int)$pdo->lastInsertId();

          // set allocate_group_id = outId
          $pdo->prepare("UPDATE company_bank_txn SET allocate_group_id = :gid WHERE id = :id")
            ->execute([':gid' => $outId, ':id' => $outId]);

          // IN
          $descTarget = $description;
          if ($descTarget === '' && !empty($bank['bank_name'])) {
            $descTarget = tt('admin.bank.txn_edit.allocate_from', ['name' => (string)$bank['bank_name']], 'Allocate from {name}');
          }

          $stIn = $pdo->prepare("
            INSERT INTO company_bank_txn
              (bank_id, bank_account_id, allocate_group_id, txn_date, txn_type, ref_no, description,
               currency, amount, rate_to_myr, amount_myr, created_at, updated_at)
            VALUES
              (:bank_id, :bank_account_id, :gid, :txn_date, 'IN', :ref_no, :description,
               :currency, :amount, :rate_to_myr, :amount_myr, NOW(), NOW())
          ");
          $stIn->execute([
            ':bank_id'         => $target_bank_id,
            ':bank_account_id' => $target_bank_id,
            ':gid'             => $outId,
            ':txn_date'        => $txn_date,
            ':ref_no'          => $ref_no,
            ':description'     => $descTarget,
            ':currency'        => $currency,
            ':amount'          => $amount,
            ':rate_to_myr'     => $rate_to_myr,
            ':amount_myr'      => $amount_myr,
          ]);

          // attachments bind to OUT txn
          handle_multi_upload($pdo, $outId, $uploadBaseDir, $allowedExt, $errors);

          $pdo->commit();
          $saved = true;
          $id = $outId;

        } else {

          // normal IN/OUT
          $finalType = ($txn_mode === 'OUT') ? 'OUT' : 'IN';

          if ($postedId > 0) {
            $st = $pdo->prepare("
              UPDATE company_bank_txn
                 SET bank_id = :bank_id,
                     bank_account_id = :bank_account_id,
                     txn_date = :txn_date,
                     txn_type = :txn_type,
                     ref_no = :ref_no,
                     description = :description,
                     currency = :currency,
                     amount = :amount,
                     rate_to_myr = :rate_to_myr,
                     amount_myr = :amount_myr,
                     updated_at = NOW()
               WHERE id = :id
            ");
            $st->execute([
              ':bank_id'         => $bank_id,
              ':bank_account_id' => $bank_id,
              ':txn_date'        => $txn_date,
              ':txn_type'        => $finalType,
              ':ref_no'          => $ref_no,
              ':description'     => $description,
              ':currency'        => $currency,
              ':amount'          => $amount,
              ':rate_to_myr'     => $rate_to_myr,
              ':amount_myr'      => $amount_myr,
              ':id'              => $postedId,
            ]);
            $id = $postedId;
          } else {
            $st = $pdo->prepare("
              INSERT INTO company_bank_txn
                (bank_id, bank_account_id, txn_date, txn_type, ref_no, description,
                 currency, amount, rate_to_myr, amount_myr, created_at, updated_at)
              VALUES
                (:bank_id, :bank_account_id, :txn_date, :txn_type, :ref_no, :description,
                 :currency, :amount, :rate_to_myr, :amount_myr, NOW(), NOW())
            ");
            $st->execute([
              ':bank_id'         => $bank_id,
              ':bank_account_id' => $bank_id,
              ':txn_date'        => $txn_date,
              ':txn_type'        => $finalType,
              ':ref_no'          => $ref_no,
              ':description'     => $description,
              ':currency'        => $currency,
              ':amount'          => $amount,
              ':rate_to_myr'     => $rate_to_myr,
              ':amount_myr'      => $amount_myr,
            ]);
            $id = (int)$pdo->lastInsertId();
          }

          // attachments
          handle_multi_upload($pdo, $id, $uploadBaseDir, $allowedExt, $errors);

          $pdo->commit();
          $saved = true;
        }

        // reload txn + attachments
        $st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id");
        $st->execute([':id' => $id]);
        $txn = $st->fetch() ?: $txn;
        $isNew = false;

        $st = $pdo->prepare("
          SELECT id, file_name, file_path, file_mime, file_size, created_at
            FROM company_bank_txn_files
           WHERE bank_txn_id = :tid
           ORDER BY id ASC
        ");
        $st->execute([':tid' => $id]);
        $attachments = $st->fetchAll();

        $form_txn_type = (string)($txn['txn_type'] ?? $form_txn_type);

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['general'] = tt('admin.bank.txn_edit.error.save_failed', [], 'Save failed') . ': ' . $e->getMessage();
      }
    } else {
      // keep user input
      $txn['bank_id'] = $bank_id;
      $txn['bank_account_id'] = $bank_id;
      $txn['txn_date'] = $txn_date;
      $txn['txn_type'] = ($txn_mode === 'OUT') ? 'OUT' : 'IN';
      $txn['ref_no'] = $ref_no;
      $txn['description'] = $description;
      $txn['currency'] = $currency;
      $txn['amount'] = $amount;
      $txn['rate_to_myr'] = $rate_to_myr ?? 1.0;
      $txn['amount_myr'] = $amount_myr ?? 0.0;
    }
  }
}

include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.bank.txn_edit.eyebrow', [], 'BANK TRANSACTION')) ?></div>
          <h2 class="form-page-title"><?= h($page_title) ?></h2>
          <div class="form-page-subtitle"><?= h(tt('admin.bank.txn_edit.subtitle', [], 'Record bank / USDT movements for this account.')) ?></div>
        </div>
      </div>

      <?php if ($saved): ?>
        <div class="alert-success" style="margin-bottom:10px;"><?= h(tt('admin.bank.txn_edit.saved', [], 'Transaction saved.')) ?></div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:10px;"><?= h($errors['general']) ?></div>
      <?php endif; ?>

      <?php if (!empty($errors['attach_multi'])): ?>
        <div class="alert-error" style="margin-bottom:10px;font-size:12px;">
          <?php foreach ($errors['attach_multi'] as $msg): ?>
            <div><?= h($msg) ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($enterFromAll && $bank_id <= 0): ?>

        <form method="get" class="form-layout">
          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-title"><?= h(tt('admin.bank.txn_edit.pick.title', [], 'Select bank account')) ?></div>
              <div class="form-section-desc"><?= h(tt('admin.bank.txn_edit.pick.desc', [], 'Please choose which bank / wallet you want to create transaction for.')) ?></div>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h(tt('admin.bank.txn_edit.pick.field', [], 'Bank account')) ?>
                <span class="field-required">*</span>
              </label>

              <select class="form-control" name="bank_id"
                onchange="if(this.value>0) location.href='<?= h(url('admin/bank/txn_edit.php')) ?>?bank_id='+this.value;">
                <option value="0"><?= h(tt('admin.bank.txn_edit.pick.ph', [], '— Please select —')) ?></option>

                <?php foreach ($allBanks as $b): ?>
                  <?php
                    $labelParts = [];
                    if (!empty($b['bank_code'])) $labelParts[] = '[' . $b['bank_code'] . ']';
                    if (!empty($b['bank_name'])) $labelParts[] = $b['bank_name'];
                    if (!empty($b['account_name'])) $labelParts[] = '· ' . $b['account_name'];
                    if (!empty($b['account_no'])) $labelParts[] = '(' . $b['account_no'] . ')';
                    $label = trim(implode(' ', $labelParts));
                    if ($label === '') $label = 'Bank #' . (int)$b['id'];
                    $label .= ' · ' . ($b['currency'] ?: 'MYR');
                  ?>
                  <option value="<?= (int)$b['id'] ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </form>

      <?php else: ?>

        <form method="post" class="form-layout" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?= (int)($txn['id'] ?? 0) ?>">
          <input type="hidden" name="bank_id" value="<?= (int)$bank_id ?>">

          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-title"><?= h(tt('admin.bank.txn_edit.section.main', [], 'Transaction details')) ?></div>
              <?php if ($bank): ?>
                <div class="form-section-desc">
                  <?= h(($bank['bank_code'] ? '['.$bank['bank_code'].'] ' : '') . $bank['bank_name'] . ' · ' . $bank['account_name']) ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="form-grid form-grid-2">
              <div class="form-group">
                <label class="field-label">
                  <?= h(tt('admin.bank.txn_edit.field.date', [], 'Date')) ?>
                  <span class="field-required">*</span>
                </label>
                <input type="date" name="txn_date" class="form-control"
                       value="<?= h($txn['txn_date'] ?? date('Y-m-d')) ?>">
                <?php if (isset($errors['txn_date'])): ?><div class="form-error"><?= h($errors['txn_date']) ?></div><?php endif; ?>
              </div>

              <div class="form-group">
                <label class="field-label"><?= h(tt('admin.bank.txn_edit.field.type', [], 'Type')) ?></label>
                <select name="txn_type" class="form-control" id="txn_type_select">
                  <option value="IN"  <?= $form_txn_type === 'IN' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_in', [], 'IN')) ?></option>
                  <option value="OUT" <?= $form_txn_type === 'OUT' ? 'selected' : '' ?>><?= h(tt('admin.bank.txn.type_out', [], 'OUT')) ?></option>
                  <?php if ($isNew): ?>
                    <option value="ALLOCATE" <?= $form_txn_type === 'ALLOCATE' ? 'selected' : '' ?>>
                      <?= h(tt('admin.bank.txn_edit.type_allocate', [], 'Allocate to another bank')) ?>
                    </option>
                  <?php endif; ?>
                </select>
              </div>
            </div>

            <div class="form-group" id="allocate-target-row" style="display:none;">
              <label class="field-label">
                <?= h(tt('admin.bank.txn_edit.allocate.target_label', [], 'Transfer to bank account')) ?>
                <span class="field-required">*</span>
              </label>
              <select name="target_bank_id" class="form-control">
                <option value="0"><?= h(tt('admin.bank.txn_edit.allocate.target_ph', [], '— Select target bank —')) ?></option>
                <?php foreach ($allBanks as $b): ?>
                  <?php if ((int)$b['id'] === (int)$bank_id) continue; ?>
                  <option value="<?= (int)$b['id'] ?>" <?= $target_bank_id === (int)$b['id'] ? 'selected' : '' ?>>
                    <?= h(($b['bank_name'] ?: 'Bank') . ' · ' . ($b['account_name'] ?: '-') . ' · ' . ($b['currency'] ?: 'MYR')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['target_bank_id'])): ?><div class="form-error"><?= h($errors['target_bank_id']) ?></div><?php endif; ?>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(tt('admin.bank.txn_edit.allocate.tip', [], 'Same-currency accounts only. System will auto-create OUT (this bank) and IN (target bank).')) ?>
              </div>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.txn_edit.field.description', [], 'Description')) ?></label>
              <input type="text" name="description" class="form-control" value="<?= h($txn['description'] ?? '') ?>">
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.txn_edit.field.ref_no', [], 'Reference no.')) ?></label>
              <input type="text" name="ref_no" class="form-control" value="<?= h($txn['ref_no'] ?? '') ?>">
            </div>

            <div class="form-grid form-grid-3">
              <div class="form-group">
                <label class="field-label">
                  <?= h(tt('admin.bank.txn_edit.field.amount', [], 'Amount')) ?>
                  <span class="field-required">*</span>
                </label>
                <input type="number" step="0.01" name="amount" class="form-control" value="<?= h((string)($txn['amount'] ?? 0)) ?>">
                <?php if (isset($errors['amount'])): ?><div class="form-error"><?= h($errors['amount']) ?></div><?php endif; ?>
              </div>

              <div class="form-group">
                <label class="field-label"><?= h(tt('admin.bank.txn_edit.field.currency', [], 'Currency')) ?></label>
                <input type="text" name="currency" class="form-control"
                       value="<?= h($txn['currency'] ?? $baseCurrency) ?>"
                       <?= $form_txn_type === 'ALLOCATE' ? 'readonly' : '' ?>>
              </div>

              <div class="form-group">
                <label class="field-label"><?= h(tt('admin.bank.txn_edit.field.rate_to_myr', [], 'Rate → MYR')) ?></label>
                <input type="number" step="0.0001" name="rate_to_myr" class="form-control"
                       value="<?= h(($txn['rate_to_myr'] ?? null) === null ? '' : (string)$txn['rate_to_myr']) ?>">
                <?php if (isset($errors['rate_to_myr'])): ?><div class="form-error"><?= h($errors['rate_to_myr']) ?></div><?php endif; ?>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                  <?= h(tt('admin.bank.txn_edit.help.rate', [], 'For USDT, enter 1 USDT = ? MYR.')) ?>
                </div>
              </div>
            </div>

          </div>

          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-title"><?= h(tt('admin.bank.txn_edit.attach.title', [], 'Attachments')) ?></div>
              <div class="form-section-desc"><?= h(tt('admin.bank.txn_edit.attach.desc', [], 'Upload PDF / image files as supporting documents.')) ?></div>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.txn_edit.attach.upload', [], 'Upload files')) ?></label>
              <input type="file" name="attachments[]" class="form-control" multiple>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(tt('admin.bank.txn_edit.attach.tip_types', [], 'PDF, PNG, JPG, GIF')) ?>
              </div>
            </div>

            <?php if ($attachments): ?>
              <div class="form-group">
                <label class="field-label"><?= h(tt('admin.bank.txn_edit.attach.existing', [], 'Existing files')) ?></label>
                <ul style="list-style:none;padding-left:0;font-size:13px;margin:0;">
                  <?php foreach ($attachments as $f): ?>
                    <li style="margin-bottom:8px;display:flex;gap:10px;align-items:center;justify-content:space-between;">
                      <div style="min-width:0;">
                        <a href="<?= h(url($f['file_path'])) ?>" target="_blank" style="text-decoration:underline;word-break:break-word;">
                          <?= h($f['file_name'] ?: basename((string)$f['file_path'])) ?>
                        </a>
                        <?php if (!empty($f['file_size'])): ?>
                          <span style="color:#6b7280;font-size:11px;">
                            (<?= number_format((int)$f['file_size'] / 1024, 1) ?> KB)
                          </span>
                        <?php endif; ?>
                      </div>

                      <div style="flex:0 0 auto;">
                        <button type="submit"
                                name="action"
                                value="delete_attachment"
                                class="btn btn-light"
                                onclick="return confirm('Delete this attachment?');"
                                style="padding:6px 10px;">
                          Delete
                        </button>
                        <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <div style="font-size:11px;color:#6b7280;margin-top:6px;">
                  Tip: Click “Delete” will remove the record and try to delete the file from server.
                </div>
              </div>
            <?php endif; ?>
          </div>

          <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;">
            <div>
              <?php
                $backUrl = ($bank_id > 0)
                  ? url('admin/bank/transactions.php?bank_id=' . $bank_id)
                  : url('admin/bank/transactions_all.php');
              ?>
              <a href="<?= h($backUrl) ?>" class="btn btn-light">
                <?= h(tt('admin.common.back', [], 'Back')) ?>
              </a>
            </div>
            <div>
              <button type="submit" class="btn btn-primary" name="action" value="save">
                <?= h(tt('admin.bank.txn_edit.save_btn', [], 'Save transaction')) ?>
              </button>
            </div>
          </div>

        </form>

      <?php endif; ?>

    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var sel = document.getElementById('txn_type_select');
  var row = document.getElementById('allocate-target-row');
  function syncAllocateRow() {
    if (!sel || !row) return;
    row.style.display = (sel.value === 'ALLOCATE') ? 'block' : 'none';
  }
  if (sel && row) {
    sel.addEventListener('change', syncAllocateRow);
    syncAllocateRow();
  }
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
