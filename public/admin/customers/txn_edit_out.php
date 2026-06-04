<?php
// public/admin/customers/txn_edit_out.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.E');

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (function_exists('app_ensure_customer_currency_schema')) {
  app_ensure_customer_currency_schema($pdo);
}

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

$baseCurrency = 'MYR';

$uploadBaseDir = __DIR__ . '/../../../uploads/attachments';
$allowedAttachMimes = [
  'application/pdf',
  'image/png',
  'image/jpeg',
  'image/jpg',
  'image/gif',
];

/* ===========================
   schema-safe helpers
=========================== */
function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
    }
  } catch (Throwable $e) {}
  $cache[$key] = $cols;
  return $cols;
}

function filter_fields_by_table(PDO $pdo, string $table, array $fields): array {
  $cols = table_columns($pdo, $table);
  $out = [];
  foreach ($fields as $k => $v) {
    if (isset($cols[$k])) $out[$k] = $v;
  }
  return $out;
}

function insert_row(PDO $pdo, string $table, array $fields): int {
  $fields = filter_fields_by_table($pdo, $table, $fields);
  if (!$fields) throw new RuntimeException("No valid fields to insert into {$table}");

  $cols = array_keys($fields);
  $phs  = array_map(fn($c) => ':' . $c, $cols);

  $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ")
          VALUES (" . implode(',', $phs) . ")";
  $st = $pdo->prepare($sql);

  $params = [];
  foreach ($fields as $k => $v) $params[':' . $k] = $v;
  $st->execute($params);

  return (int)$pdo->lastInsertId();
}

function update_row(PDO $pdo, string $table, int $id, array $fields, string $pk = 'id'): void {
  $fields = filter_fields_by_table($pdo, $table, $fields);
  unset($fields[$pk]);
  if (!$fields) return;

  $sets = [];
  $params = [':_id' => $id];
  foreach ($fields as $k => $v) {
    $sets[] = "`$k` = :$k";
    $params[":$k"] = $v;
  }

  $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$pk` = :_id";
  $st  = $pdo->prepare($sql);
  $st->execute($params);
}

/* ===========================
   attachments
=========================== */
function insert_new_files(PDO $pdo, int $txnId, array $newAttachments): void {
  if ($txnId <= 0 || !$newAttachments) return;

  foreach ($newAttachments as $f) {
    $fields = [
      'txn_id'     => $txnId,
      'file_path'  => $f['file_path'] ?? null,
      'file_name'  => $f['file_name'] ?? null,
      'file_mime'  => $f['file_mime'] ?? null,
      'created_at' => date('Y-m-d H:i:s'),
    ];
    insert_row($pdo, 'customer_txn_files', $fields);
  }
}

/* ===========================
   Pay on behalf (B pays C)
   - create B's IN repayment (RETURN)
=========================== */
function delete_pob_in_by_marker(PDO $pdo, string $marker, ?int $customerId = null): void {
  $cols = table_columns($pdo, 'customer_txn');
  if (!isset($cols['notes'])) return;

  $where = ["notes LIKE :mk"];
  if (isset($cols['txn_type'])) $where[] = "txn_type = 'IN'";
  if ($customerId && isset($cols['customer_id'])) $where[] = "customer_id = :cid";

  $sql = "DELETE FROM customer_txn WHERE " . implode(' AND ', $where);

  $params = [':mk' => '%' . $marker . '%'];
  if ($customerId && isset($cols['customer_id'])) $params[':cid'] = $customerId;

  $pdo->prepare($sql)->execute($params);
}

function sync_pob_in_repayment(PDO $pdo, int $outId, array $outData, array $customerC): void {
  if ($outId <= 0) return;

  $pst      = strtoupper((string)($outData['pay_source_type'] ?? 'BANK'));
  $payerCid = (int)($outData['pay_source_customer_id'] ?? 0); // B
  $amount   = (float)($outData['amount'] ?? 0);
  $status   = strtoupper((string)($outData['status'] ?? 'DRAFT'));

  if ($pst !== 'CUSTOMER') return;
  if ($payerCid <= 0 || $amount <= 0) return;

  // 只允许 SENT/CONFIRMED 才写入
  if (!in_array($status, ['SENT','CONFIRMED'], true)) return;

  $marker = '[POB OUT#' . $outId . ']';

  // edit: 先删旧
  delete_pob_in_by_marker($pdo, $marker, $payerCid);

  $txnDate = (string)($outData['txn_date'] ?? date('Y-m-d'));
  $cName   = (string)($customerC['name'] ?? 'Customer');

  $inStatus = ($status === 'CONFIRMED') ? 'CONFIRMED' : 'SENT';

  $fields = [
    'customer_id' => $payerCid,
    'txn_type'    => 'IN',
    'txn_date'    => $txnDate,
    'amount'      => $amount,
    'status'      => $inStatus,

    'in_kind'          => 'RETURN',
    'order_total'      => 0,
    'allocated_amount' => 0,

    'title' => 'IN Repayment (Paid to ' . $cName . ')',
    'notes' => $marker . ' Repayment via OUT #' . $outId,

    'is_contra' => 0,

    // UI / legacy columns
    'method'             => 'CUSTOMER',
    'require_signature'  => 0,
    'sign_receive'       => 0,
    'sign_payer'         => 0,
  ];

  insert_row($pdo, 'customer_txn', $fields);
}

/* =========================================================
   BANK LEDGER SYNC (OUT) — FINAL CORRECT VERSION
   Schema used:
   - company_bank_txn.bank_id
   - company_bank_txn.txn_id
   - customer_txn.bank_txn_id
========================================================= */

/**
 * 删除 OUT 对应的 bank ledger（最准：用 txn_id）
 */
function delete_company_bank_txn_for_out(PDO $pdo, int $outId): void {
  if ($outId <= 0) return;
  $pdo->prepare("DELETE FROM company_bank_txn WHERE txn_id = :tid")
      ->execute([':tid' => $outId]);
}

/**
 * 同步 OUT → company_bank_txn
 */
function sync_company_bank_txn_for_out(PDO $pdo, int $outId, array $outData, array $customer): void {
  if ($outId <= 0) return;

  $paySource = strtoupper((string)($outData['pay_source_type'] ?? 'BANK'));
  $status    = strtoupper((string)($outData['status'] ?? 'DRAFT'));
  $bankId    = (int)($outData['bank_account_id'] ?? 0);
  $amount    = (float)($outData['amount'] ?? 0);
  $currency  = strtoupper((string)($outData['currency'] ?? 'MYR'));
  $fx        = (float)($outData['fx_rate'] ?? 0);
  $txnDate   = (string)($outData['txn_date'] ?? date('Y-m-d'));

  // 只处理 BANK + SENT/CONFIRMED
  if ($paySource !== 'BANK') return;
  if ($bankId <= 0 || $amount <= 0) return;
  if (!in_array($status, ['SENT','CONFIRMED'], true)) return;

  // 先删旧
  delete_company_bank_txn_for_out($pdo, $outId);

  // MYR conversion
  if ($currency === 'MYR') {
    $rateToMyr = 1;
    $amountMyr = $amount;
  } else {
    $rateToMyr = $fx > 0 ? $fx : 0;
    $amountMyr = $rateToMyr > 0 ? ($amount * $rateToMyr) : 0;
  }

  $refNo = trim((string)($outData['ref_no'] ?? 'OUT' . $outId));
  $title = trim((string)($outData['title'] ?? 'OUT'));
  $custName = trim((string)($customer['name'] ?? 'Customer'));

  $desc = 'To ' . $custName;
  if ($title !== '') $desc .= ' · ' . $title;

  // 插入 bank ledger
  $sql = "
    INSERT INTO company_bank_txn
    (
      txn_id,
      bank_id,
      txn_date,
      txn_type,
      ref_no,
      description,
      currency,
      amount,
      rate_to_myr,
      amount_myr,
      customer_id,
      status,
      source_table,
      source_id,
      created_at,
      updated_at
    )
    VALUES
    (
      :txn_id,
      :bank_id,
      :txn_date,
      'OUT',
      :ref_no,
      :description,
      :currency,
      :amount,
      :rate_to_myr,
      :amount_myr,
      :customer_id,
      :status,
      'customer_txn',
      :source_id,
      NOW(),
      NOW()
    )
  ";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':txn_id'      => $outId,
    ':bank_id'     => $bankId,
    ':txn_date'    => $txnDate,
    ':ref_no'      => $refNo,
    ':description' => $desc,
    ':currency'    => $currency,
    ':amount'      => $amount,
    ':rate_to_myr' => $rateToMyr,
    ':amount_myr'  => $amountMyr,
    ':customer_id' => (int)($customer['id'] ?? 0),
    ':status'      => $status,
    ':source_id'   => $outId,
  ]);

  // 回写 customer_txn.bank_txn_id
  $bankTxnId = (int)$pdo->lastInsertId();
  if ($bankTxnId > 0) {
    $pdo->prepare("UPDATE customer_txn SET bank_txn_id = :bid WHERE id = :tid")
        ->execute([':bid' => $bankTxnId, ':tid' => $outId]);
  }
}

/* ===========================
   data loading
=========================== */
$payer_companies = [];
$payer_staff     = [];

try {
  $st = $pdo->query("SELECT id, name, reg_no FROM payer_companies ORDER BY name ASC, id ASC");
  $payer_companies = $st->fetchAll();
} catch (Throwable $e) {}

try {
  $st = $pdo->query("
    SELECT id, staff_name, ic_no
    FROM payer_company_staff
    WHERE is_active = 1
    ORDER BY staff_name ASC, id ASC
  ");
  $payer_staff = $st->fetchAll();
} catch (Throwable $e) {}

$bankAccounts  = [];
$bankLoadError = '';
$bankCurrencyById = [];

try {
  $st = $pdo->query("
    SELECT *
    FROM company_bank_accounts
    WHERE is_active = 1
    ORDER BY bank_code ASC, account_name ASC, account_no ASC, id ASC
  ");
  $bankAccounts = $st->fetchAll();
  foreach ($bankAccounts as $ba) {
    $bid = (int)($ba['id'] ?? 0);
    if ($bid > 0) $bankCurrencyById[$bid] = strtoupper(trim((string)($ba['currency'] ?? '')));
  }
} catch (Throwable $e) {
  $bankLoadError = $e->getMessage();
  $bankAccounts  = [];
  $bankCurrencyById = [];
}

$colsTxn = table_columns($pdo, 'customer_txn');
$hasSignReceive = isset($colsTxn['sign_receive']);
$hasSignPayer   = isset($colsTxn['sign_payer']);

$id          = (int)($_GET['id'] ?? 0);
$customer_id = (int)($_GET['customer_id'] ?? 0);
$back        = $_GET['back'] ?? '';
$isNew       = $id === 0;

$txnRow = null;
if (!$isNew) {
  $st = $pdo->prepare("SELECT * FROM customer_txn WHERE id = :id");
  $st->execute([':id' => $id]);
  $txnRow = $st->fetch();
  if (!$txnRow || strtoupper(trim((string)($txnRow['txn_type'] ?? ''))) !== 'OUT') {
    http_response_code(404);
    exit('OUT transaction not found');
  }
  $customer_id = (int)$txnRow['customer_id'];
}

if ($isNew && $customer_id <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $customer_id]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

if (empty($customer['currency']) && !empty($customer['category_id'])) {
  try {
    $catCols = table_columns($pdo, 'customer_categories');
    if (isset($catCols['currency'])) {
      $stCur = $pdo->prepare("SELECT currency FROM customer_categories WHERE id = :id LIMIT 1");
      $stCur->execute([':id' => (int)$customer['category_id']]);
      $customer['currency'] = strtoupper(trim((string)($stCur->fetchColumn() ?: '')));
    }
  } catch (Throwable $e) {}
}
$baseCurrency = strtoupper(trim((string)($customer['currency'] ?? $baseCurrency))) ?: 'MYR';

$paySourceCustomers = [];
try {
  $st = $pdo->prepare("
    SELECT id, code, name, reg_no
    FROM customers
    WHERE id <> :id
    ORDER BY code ASC, name ASC, id ASC
  ");
  $st->execute([':id' => $customer['id']]);
  $paySourceCustomers = $st->fetchAll();
} catch (Throwable $e) {}

$loginUsers = [];
try {
  $st = $pdo->prepare("
    SELECT id, full_name, nric
    FROM users
    WHERE role = 'CUSTOMER'
      AND customer_id = :cid
      AND is_active = 1
    ORDER BY full_name ASC, id ASC
  ");
  $st->execute([':cid' => $customer['id']]);
  $loginUsers = $st->fetchAll();
} catch (Throwable $e) {}

$extraFiles = [];
if (!$isNew) {
  try {
    $st = $pdo->prepare("
      SELECT id, file_path, file_name, file_mime, created_at
      FROM customer_txn_files
      WHERE txn_id = :tid
      ORDER BY id ASC
    ");
    $st->execute([':tid' => $id]);
    $extraFiles = $st->fetchAll();
  } catch (Throwable $e) {}
}

/* ===========================
   default OUT data
=========================== */
$data = [
  'customer_id'            => (int)$customer['id'],
  'bank_account_id'        => null,
  'pay_source_type'        => 'BANK',     // BANK / CUSTOMER
  'pay_source_customer_id' => null,       // B
  'txn_date'               => date('Y-m-d'),
  'txn_type'               => 'OUT',
  'out_kind'               => 'NORMAL',   // NORMAL / LOAN
  'method'                 => 'BANK',     // ✅ no user input anymore
  'currency'               => $baseCurrency,
  'fx_rate'                => null,
  'amount'                 => '0.00',
  'allocated_amount'       => '0.00',
  'payer_company_id'       => null,
  'payer_staff_id'         => null,
  'status'                 => 'DRAFT',    // DRAFT / SENT / CONFIRMED
  'ref_no'                 => '',
  'title'                  => 'Receipt',
  'notes'                  => '',
  'recipient_name'         => '',
  'recipient_nric'         => '',
  'require_signature'      => 0,
  'sign_receive'           => 0,
  'sign_payer'             => 0,
  'attachment_path'        => null,
  'attachment_name'        => null,
  'attachment_mime'        => null,
  'is_contra'              => 0,
];

if (!$isNew && $txnRow) {
  $data = array_merge($data, $txnRow);

  $data['require_signature']      = (int)($txnRow['require_signature'] ?? 0);
  $data['is_contra']              = (int)($txnRow['is_contra'] ?? 0);
  $data['fx_rate']                = isset($txnRow['fx_rate']) ? (float)$txnRow['fx_rate'] : null;
  $data['bank_account_id']        = isset($txnRow['bank_account_id']) ? (int)$txnRow['bank_account_id'] : null;
  $data['payer_company_id']       = isset($txnRow['payer_company_id']) ? (int)$txnRow['payer_company_id'] : null;
  $data['payer_staff_id']         = isset($txnRow['payer_staff_id']) ? (int)$txnRow['payer_staff_id'] : null;
  $data['out_kind']               = strtoupper($txnRow['out_kind'] ?? 'NORMAL');
  $data['pay_source_type']        = strtoupper((string)($txnRow['pay_source_type'] ?? 'BANK'));
  $data['pay_source_customer_id'] = isset($txnRow['pay_source_customer_id']) ? (int)$txnRow['pay_source_customer_id'] : null;

  if ($hasSignReceive) $data['sign_receive'] = (int)($txnRow['sign_receive'] ?? 0);
  if ($hasSignPayer)   $data['sign_payer']   = (int)($txnRow['sign_payer'] ?? 0);
}

// page title
$page_title = $isNew
  ? t('admin.customer_txn.page_title.new', [], 'New Transaction')
  : t('admin.customer_txn.page_title.edit', [], 'Edit Transaction');

$errors           = [];
$multiUploadNotes = [];
$newAttachments   = [];

if (!is_dir($uploadBaseDir)) {
  @mkdir($uploadBaseDir, 0777, true);
}

/* ===========================
   POST
=========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (function_exists('app_upload_is_oversized_post') && app_upload_is_oversized_post()) {
    $errors['general'] = function_exists('app_upload_oversized_post_message')
      ? app_upload_oversized_post_message()
      : 'Upload failed: request too large.';
  } else {

  $data['txn_date'] = $_POST['txn_date'] ?? $data['txn_date'];
  $data['txn_type'] = 'OUT';

  // Payment Source
  $paySourceType = strtoupper(trim((string)($_POST['pay_source_type'] ?? ($data['pay_source_type'] ?? 'BANK'))));
  if (!in_array($paySourceType, ['BANK', 'CUSTOMER'], true)) $paySourceType = 'BANK';
  $data['pay_source_type'] = $paySourceType;

  $paySourceCustomerId = (isset($_POST['pay_source_customer_id']) && $_POST['pay_source_customer_id'] !== '')
    ? (int)$_POST['pay_source_customer_id']
    : null;
  $data['pay_source_customer_id'] = ($paySourceType === 'CUSTOMER') ? ($paySourceCustomerId ?: null) : null;

  // bank
  $bankAccountId = (isset($_POST['bank_account_id']) && $_POST['bank_account_id'] !== '')
    ? (int)$_POST['bank_account_id']
    : null;

  if ($paySourceType === 'CUSTOMER') {
    $bankAccountId = null;
    $data['method'] = 'CUSTOMER'; // ✅ no UI
  } else {
    $data['method'] = 'BANK';     // ✅ no UI
  }
  $data['bank_account_id'] = $bankAccountId;

  // currency (server-side force to bank currency if BANK selected)
  $postedCurrency = strtoupper(trim((string)($_POST['currency'] ?? $data['currency'])));
  if ($postedCurrency === '') $postedCurrency = $baseCurrency;

  if ($paySourceType === 'BANK' && $bankAccountId) {
    $bankCur = $bankCurrencyById[$bankAccountId] ?? '';
    $data['currency'] = ($bankCur !== '') ? $bankCur : $postedCurrency;
  } else {
    $data['currency'] = $postedCurrency;
  }

  // fx_rate
  if (isset($_POST['fx_rate']) && $_POST['fx_rate'] !== '') {
    $data['fx_rate'] = (float)$_POST['fx_rate'];
  } else {
    $data['fx_rate'] = null;
  }

  $outKind = strtoupper(trim((string)($_POST['out_kind'] ?? ($data['out_kind'] ?? 'NORMAL'))));
  if (!in_array($outKind, ['NORMAL', 'LOAN'], true)) $outKind = 'NORMAL';
  $data['out_kind'] = $outKind;

  $data['amount']         = trim((string)($_POST['amount'] ?? '0'));
  $data['ref_no']         = trim((string)($_POST['ref_no'] ?? $data['ref_no']));
  $data['title']          = trim((string)($_POST['title'] ?? $data['title']));
  $data['notes']          = trim((string)($_POST['notes'] ?? ''));
  $data['recipient_name'] = trim((string)($_POST['recipient_name'] ?? ''));
  $data['recipient_nric'] = trim((string)($_POST['recipient_nric'] ?? ''));

  $amount = (float)$data['amount'];

  // OUT signatures
  $requireSignature = isset($_POST['require_signature']) ? 1 : 0;

  // payer
  $payer_company_id = (isset($_POST['payer_company_id']) && $_POST['payer_company_id'] !== '')
    ? (int)$_POST['payer_company_id'] : 0;
  $payer_staff_id = (isset($_POST['payer_staff_id']) && $_POST['payer_staff_id'] !== '')
    ? (int)$_POST['payer_staff_id'] : 0;
  $data['payer_company_id'] = $payer_company_id ?: null;
  $data['payer_staff_id']   = $payer_staff_id ?: null;

  // validation
  if ($data['txn_date'] === '') {
    $errors['txn_date'] = t('admin.customer_txn.error.date_required', [], 'Date is required');
  }
  if ($amount <= 0) {
    $errors['amount'] = t('admin.customer_txn.error.amount_gt_zero', [], 'Amount must be greater than 0');
  }

  if ($paySourceType === 'CUSTOMER') {
    if (!$data['pay_source_customer_id']) {
      $errors['pay_source_customer_id'] = t('admin.customer_txn.error.paying_customer_required', [], 'Paying customer is required.');
    } elseif ((int)$data['pay_source_customer_id'] === (int)$customer['id']) {
      $errors['pay_source_customer_id'] = t('admin.customer_txn.error.paying_customer_same', [], 'Paying customer cannot be the same as the counterparty.');
    }
  } else {
    // BANK: must select bank account (otherwise cannot hit bank ledger)
    if (!$data['bank_account_id']) {
      $errors['bank_account_id'] = t('admin.customer_txn.error.bank_required', [], 'Bank / cash account is required.');
    }
  }

  // fx required when currency != base
  if (strtoupper($data['currency']) !== strtoupper($baseCurrency)) {
    if (!$data['fx_rate'] || (float)$data['fx_rate'] <= 0) {
      $errors['fx_rate'] = t(
        'admin.customer_txn.error.fx_rate_required',
        ['base' => $baseCurrency],
        'FX rate is required when currency is not ' . $baseCurrency . '.'
      );
    }
  } else {
    $data['fx_rate'] = null;
  }

  // status
  $status = $_POST['status'] ?? $data['status'] ?? 'DRAFT';
  if (!in_array($status, ['DRAFT','SENT','CONFIRMED'], true)) $status = 'DRAFT';

  // need signature but choose CONFIRMED => force SENT
  if (!(int)($data['is_contra'] ?? 0) && $requireSignature && $status === 'CONFIRMED') {
    $status = 'SENT';
  }
  $data['status'] = $status;

  // pay on behalf: cannot be DRAFT
  if ($data['pay_source_type'] === 'CUSTOMER' && $data['status'] === 'DRAFT') {
    $data['status'] = 'SENT';
    $status = 'SENT';
  }

  // contra never sign
  if ((int)($data['is_contra'] ?? 0) === 1) {
    $data['require_signature'] = 0;
    $data['sign_receive'] = 0;
    $data['sign_payer']   = 0;
  } else {
    $data['require_signature'] = $requireSignature;
    if ($requireSignature) {
      $data['sign_receive'] = 1;
      $data['sign_payer']   = 1;
    } else {
      $data['sign_receive'] = 0;
      $data['sign_payer']   = 0;
    }
  }

  if ($data['title'] === '') {
    if ($data['out_kind'] === 'LOAN') $data['title'] = t('admin.customer_txn.out_kind.loan', [], 'Loan / Advance to customer');
    else $data['title'] = t('admin.customer_txn.title.placeholder_out', [], 'Receipt');
  }

  // attachments upload
  if (!empty($_FILES['attachments']['name']) && is_array($_FILES['attachments']['name'])) {
    $names   = $_FILES['attachments']['name'];
    $types   = $_FILES['attachments']['type'];
    $tmp     = $_FILES['attachments']['tmp_name'];
    $errorsF = $_FILES['attachments']['error'];

    $count = count($names);
    for ($i = 0; $i < $count; $i++) {
      $origName = (string)($names[$i] ?? '');
      if ($origName === '') continue;

      $err  = $errorsF[$i] ?? UPLOAD_ERR_OK;
      $t    = $types[$i] ?? '';
      $tmpN = $tmp[$i] ?? '';

      if ($err !== UPLOAD_ERR_OK) {
        $multiUploadNotes[] = function_exists('app_upload_error_message')
          ? app_upload_error_message((int)$err, $origName)
          : sprintf(
            t('admin.customer_txn.attach.multi_error', [], 'File "%s" upload error (code %d).'),
            $origName,
            (int)$err
          );
        continue;
      }

      if (!in_array($t, $allowedAttachMimes, true)) {
        $multiUploadNotes[] = sprintf(
          t('admin.customer_txn.attach.multi_invalid', [], 'File "%s" skipped (invalid type).'),
          $origName
        );
        continue;
      }

      $ext      = pathinfo($origName, PATHINFO_EXTENSION);
      $safeName = uniqid('att_', true) . ($ext ? '.' . $ext : '');
      $destPath = $uploadBaseDir . '/' . $safeName;

      if (!move_uploaded_file($tmpN, $destPath)) {
        $multiUploadNotes[] = sprintf(
          t('admin.customer_txn.attach.multi_move_fail', [], 'File "%s" failed to move.'),
          $origName
        );
        continue;
      }

      $newAttachments[] = [
        'file_path' => 'uploads/attachments/' . $safeName,
        'file_name' => $origName,
        'file_mime' => $t,
      ];
    }
  }

  // delete attachments
  if (!$isNew && !empty($_POST['delete_files']) && is_array($_POST['delete_files'])) {
    $idsToDelete = array_filter(array_map('intval', $_POST['delete_files']));
    if ($idsToDelete) {
      $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
      try {
        $paramsDelSel = array_merge([$id], $idsToDelete);
        $st = $pdo->prepare("
          SELECT id, file_path
          FROM customer_txn_files
          WHERE txn_id = ?
            AND id IN ($placeholders)
        ");
        $st->execute($paramsDelSel);
        $filesToRemove = $st->fetchAll();

        foreach ($filesToRemove as $rf) {
          $fp = (string)($rf['file_path'] ?? '');
          if ($fp !== '') {
            $full = dirname(__DIR__, 3) . '/' . ltrim($fp, '/');
            if (is_file($full)) @unlink($full);
          }
        }

        $stDel = $pdo->prepare("
          DELETE FROM customer_txn_files
          WHERE txn_id = ?
            AND id IN ($placeholders)
        ");
        $stDel->execute($paramsDelSel);
      } catch (Throwable $e) {}
    }
  }

  // save
  if (!$errors) {
    try {
      $pdo->beginTransaction();

      if ($isNew) {
        $sql = "INSERT INTO customer_txn
          (
            customer_id,
            bank_account_id,
            pay_source_type,
            pay_source_customer_id,
            txn_date,
            txn_type,
            out_kind,
            method,
            currency,
            fx_rate,
            amount,
            allocated_amount,
            payer_company_id,
            payer_staff_id,
            status,
            ref_no,
            title,
            notes,
            recipient_name,
            recipient_nric,
            require_signature," .
            ($hasSignReceive ? " sign_receive," : "") .
            ($hasSignPayer   ? " sign_payer,"   : "") . "
            attachment_path,
            attachment_name,
            attachment_mime,
            is_contra,
            created_at,
            updated_at
          )
          VALUES
          (
            :customer_id,
            :bank_account_id,
            :pay_source_type,
            :pay_source_customer_id,
            :txn_date,
            'OUT',
            :out_kind,
            :method,
            :currency,
            :fx_rate,
            :amount,
            0,
            :payer_company_id,
            :payer_staff_id,
            :status,
            :ref_no,
            :title,
            :notes,
            :recipient_name,
            :recipient_nric,
            :require_signature," .
            ($hasSignReceive ? " :sign_receive," : "") .
            ($hasSignPayer   ? " :sign_payer,"   : "") . "
            :attachment_path,
            :attachment_name,
            :attachment_mime,
            :is_contra,
            NOW(),
            NOW()
          )";

        $params = [
          ':customer_id'            => (int)$customer['id'],
          ':bank_account_id'        => $data['bank_account_id'],
          ':pay_source_type'        => $data['pay_source_type'],
          ':pay_source_customer_id' => $data['pay_source_customer_id'],
          ':txn_date'               => $data['txn_date'],
          ':out_kind'               => $data['out_kind'],
          ':method'                 => $data['method'], // ✅ no UI
          ':currency'               => $data['currency'],
          ':fx_rate'                => $data['fx_rate'] ?: null,
          ':amount'                 => $amount,
          ':payer_company_id'       => $data['payer_company_id'],
          ':payer_staff_id'         => $data['payer_staff_id'],
          ':status'                 => $data['status'],
          ':ref_no'                 => $data['ref_no'],
          ':title'                  => $data['title'],
          ':notes'                  => $data['notes'],
          ':recipient_name'         => $data['recipient_name'],
          ':recipient_nric'         => $data['recipient_nric'],
          ':require_signature'      => ((int)($data['is_contra'] ?? 0) ? 0 : (int)$data['require_signature']),
          ':attachment_path'        => $data['attachment_path'],
          ':attachment_name'        => $data['attachment_name'],
          ':attachment_mime'        => $data['attachment_mime'],
          ':is_contra'              => (int)$data['is_contra'],
        ];
        if ($hasSignReceive) $params[':sign_receive'] = (int)($data['sign_receive'] ?? 0);
        if ($hasSignPayer)   $params[':sign_payer']   = (int)($data['sign_payer'] ?? 0);

      } else {
        $sql = "UPDATE customer_txn SET
            bank_account_id        = :bank_account_id,
            pay_source_type        = :pay_source_type,
            pay_source_customer_id = :pay_source_customer_id,
            txn_date               = :txn_date,
            out_kind               = :out_kind,
            method                 = :method,
            currency               = :currency,
            fx_rate                = :fx_rate,
            amount                 = :amount,
            payer_company_id       = :payer_company_id,
            payer_staff_id         = :payer_staff_id,
            status                 = :status,
            ref_no                 = :ref_no,
            title                  = :title,
            notes                  = :notes,
            recipient_name         = :recipient_name,
            recipient_nric         = :recipient_nric,
            require_signature      = :require_signature," .
            ($hasSignReceive ? " sign_receive = :sign_receive," : "") .
            ($hasSignPayer   ? " sign_payer   = :sign_payer,"   : "") . "
            attachment_path        = :attachment_path,
            attachment_name        = :attachment_name,
            attachment_mime        = :attachment_mime,
            is_contra              = :is_contra,
            updated_at             = NOW()
          WHERE id = :id";

        $params = [
          ':bank_account_id'        => $data['bank_account_id'],
          ':pay_source_type'        => $data['pay_source_type'],
          ':pay_source_customer_id' => $data['pay_source_customer_id'],
          ':txn_date'               => $data['txn_date'],
          ':out_kind'               => $data['out_kind'],
          ':method'                 => $data['method'], // ✅ no UI
          ':currency'               => $data['currency'],
          ':fx_rate'                => $data['fx_rate'] ?: null,
          ':amount'                 => $amount,
          ':payer_company_id'       => $data['payer_company_id'],
          ':payer_staff_id'         => $data['payer_staff_id'],
          ':status'                 => $data['status'],
          ':ref_no'                 => $data['ref_no'],
          ':title'                  => $data['title'],
          ':notes'                  => $data['notes'],
          ':recipient_name'         => $data['recipient_name'],
          ':recipient_nric'         => $data['recipient_nric'],
          ':require_signature'      => ((int)($data['is_contra'] ?? 0) ? 0 : (int)$data['require_signature']),
          ':attachment_path'        => $data['attachment_path'],
          ':attachment_name'        => $data['attachment_name'],
          ':attachment_mime'        => $data['attachment_mime'],
          ':is_contra'              => (int)$data['is_contra'],
          ':id'                     => $id,
        ];
        if ($hasSignReceive) $params[':sign_receive'] = (int)($data['sign_receive'] ?? 0);
        if ($hasSignPayer)   $params[':sign_payer']   = (int)($data['sign_payer'] ?? 0);
      }

      $st = $pdo->prepare($sql);
      $st->execute($params);

      if ($isNew) $id = (int)$pdo->lastInsertId();

      if ($newAttachments) insert_new_files($pdo, $id, $newAttachments);

      // create / sync B's IN repayment
      $tmpOut = $data;
      $tmpOut['amount'] = $amount;
      sync_pob_in_repayment($pdo, $id, $tmpOut, $customer);

      // ✅ KEY: write into company_bank_txn (BANK only)
      sync_company_bank_txn_for_out($pdo, $id, $tmpOut, $customer);

      $pdo->commit();

      $redirectTo = $back ? $back : url('admin/customers/txn_list.php?customer_id=' . $customer['id'] . '&ok=1');
      header('Location: ' . $redirectTo);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['general'] = 'Save failed: ' . $e->getMessage();
    }
  }
  }
}

/* ===========================
   UI
=========================== */
include __DIR__ . '/../include/header.php';

$recipientOptions = [];
if (!empty($customer['contact_name'] ?? '')) $recipientOptions[] = $customer['contact_name'];
if (!empty($customer['default_receipt_name'] ?? '') && $customer['default_receipt_name'] !== ($recipientOptions[0] ?? '')) {
  $recipientOptions[] = $customer['default_receipt_name'];
}

$loginUsersJs = [];
foreach ($loginUsers as $lu) {
  $loginUsersJs[] = [
    'name' => $lu['full_name'],
    'nric' => $lu['nric'] ?? '',
  ];
}

// stable base for uploads
$uploadBaseUrl = (defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '') . '/../';
?>

<div class="admin-main">
  <div class="admin-main-inner">
    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?php if ($isNew): ?>
              <?= h(t('admin.customer_txn.header.eyebrow_new', [], 'New transaction')) ?>
            <?php else: ?>
              <?= h(t('admin.customer_txn.header.eyebrow_edit', [], 'Edit transaction')) ?>
            <?php endif; ?>
          </div>
          <h2 class="form-page-title"><?= h($customer['name']) ?></h2>
          <div class="form-page-subtitle">
            <?= h(t('admin.customer_txn.out.subtitle', [], 'Record or update a payout to this customer (refund, withdrawal, settlement, etc.).')) ?>
          </div>
        </div>
        <div class="form-page-meta">
          <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $customer['id'])) ?>" class="btn btn-light">
            ← <?= h(t('admin.customer_txn.back_to_list', [], 'Back to transactions')) ?>
          </a>
        </div>
      </div>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:12px;">
          <?= h($errors['general']) ?>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="form-layout">

        <!-- Basic OUT info -->
        <div class="form-section">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('admin.customer_txn.basic.title', [], 'Basic details')) ?></div>
              <div class="form-section-desc"><?= h(t('admin.customer_txn.basic.desc', [], 'Date, OUT type, payment source, bank account, currency and amount.')) ?></div>
            </div>
          </div>

          <div class="form-grid form-grid-3">
            <div class="form-group">
              <label class="field-label">
                <?= h(t('admin.customer_txn.field.date', [], 'Date')) ?>
                <span class="field-required">*</span>
              </label>
              <input type="date" name="txn_date" class="form-control" value="<?= h($data['txn_date']) ?>">
              <?php if (isset($errors['txn_date'])): ?>
                <div class="form-error"><?= h($errors['txn_date']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.out_kind.label', [], 'OUT type')) ?></label>
              <select name="out_kind" class="form-control">
                <option value="NORMAL" <?= (strtoupper((string)($data['out_kind'] ?? 'NORMAL')) === 'NORMAL') ? 'selected' : '' ?>>
                  <?= h(t('admin.customer_txn.out_kind.normal', [], 'Normal OUT')) ?>
                </option>
                <option value="LOAN" <?= (strtoupper((string)($data['out_kind'] ?? 'NORMAL')) === 'LOAN') ? 'selected' : '' ?>>
                  <?= h(t('admin.customer_txn.out_kind.loan', [], 'Loan / Advance to customer')) ?>
                </option>
              </select>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(t('admin.customer_txn.out_kind.help', [], 'NORMAL = payout；LOAN = loan / advance payment')) ?>
              </div>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.field.status', [], 'Status')) ?></label>
              <select name="status" class="form-control">
                <option value="DRAFT"     <?= (strtoupper((string)$data['status']) === 'DRAFT') ? 'selected' : '' ?>>DRAFT</option>
                <option value="SENT"      <?= (strtoupper((string)$data['status']) === 'SENT') ? 'selected' : '' ?>>SENT (Pending)</option>
                <option value="CONFIRMED" <?= (strtoupper((string)$data['status']) === 'CONFIRMED') ? 'selected' : '' ?>>CONFIRMED</option>
              </select>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(t('admin.customer_txn.status_help.customer_autosent', [], 'If "Another customer" is selected, saving will auto set to SENT (pending).')) ?>
              </div>
            </div>
          </div>

          <!-- Payment source row -->
          <div class="form-grid form-grid-3" style="margin-top:10px;">
            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.pay_source.label', [], 'Payment source')) ?></label>
              <select name="pay_source_type" id="pay_source_type" class="form-control">
                <option value="BANK" <?= (strtoupper((string)($data['pay_source_type'] ?? 'BANK')) === 'BANK') ? 'selected' : '' ?>>
                  <?= h(t('admin.customer_txn.pay_source.bank', [], 'Bank / Cash')) ?>
                </option>
                <option value="CUSTOMER" <?= (strtoupper((string)($data['pay_source_type'] ?? 'BANK')) === 'CUSTOMER') ? 'selected' : '' ?>>
                  <?= h(t('admin.customer_txn.pay_source.customer', [], 'Another customer (Pay on behalf)')) ?>
                </option>
              </select>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(t('admin.customer_txn.pay_source.help', [], 'Choose "Another customer" = system will create a NEW IN repayment for that customer (B).')) ?>
              </div>
            </div>

            <div class="form-group" id="pay_source_customer_wrap" style="display:none;">
              <label class="field-label">
                <?= h(t('admin.customer_txn.pay_source.paying_customer', [], 'Paying customer')) ?>
                <span class="field-required">*</span>
              </label>
              <select name="pay_source_customer_id" class="form-control">
                <option value=""><?= h(t('admin.customer_txn.pay_source.paying_customer_ph', [], '— Select customer —')) ?></option>
                <?php foreach ($paySourceCustomers as $pc): ?>
                  <?php
                    $pcId = (int)$pc['id'];
                    $label = trim((string)($pc['code'] ?? ''));
                    if ($label !== '') $label .= ' - ';
                    $label .= (string)$pc['name'];
                    if (!empty($pc['reg_no'])) $label .= ' (' . $pc['reg_no'] . ')';
                  ?>
                  <option value="<?= $pcId ?>" <?= ((int)($data['pay_source_customer_id'] ?? 0) === $pcId) ? 'selected' : '' ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['pay_source_customer_id'])): ?>
                <div class="form-error"><?= h($errors['pay_source_customer_id']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group" id="bank_block_wrap">
              <label class="field-label"><?= h(t('admin.customer_txn.field.bank_account', [], 'Bank / cash account')) ?> <span class="field-required">*</span></label>
              <select name="bank_account_id" id="bank_account_id" class="form-control">
                <option value=""><?= h(t('admin.customer_txn.field.bank_placeholder', [], '— Select bank / cash —')) ?></option>
                <?php foreach ($bankAccounts as $ba): ?>
                  <?php
                    $baId        = (int)($ba['id'] ?? 0);
                    $bankCode    = trim((string)($ba['bank_code']     ?? ''));
                    $accountName = trim((string)($ba['account_name']  ?? ''));
                    $accNo       = trim((string)($ba['account_no']    ?? ''));
                    $accCur      = strtoupper(trim((string)($ba['currency'] ?? '')));

                    $parts = [];
                    if ($bankCode    !== '') $parts[] = $bankCode;
                    if ($accountName !== '') $parts[] = $accountName;
                    if ($accNo       !== '') $parts[] = $accNo;
                    $label = implode(' · ', $parts);
                    if ($accCur !== '') $label .= $label !== '' ? " [{$accCur}]" : "[{$accCur}]";
                    if ($label === '') $label = 'Account #' . $baId;
                  ?>
                  <option value="<?= $baId ?>"
                          data-currency="<?= h($accCur) ?>"
                          <?= ((int)($data['bank_account_id'] ?? 0) === $baId) ? 'selected' : '' ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <?php if (isset($errors['bank_account_id'])): ?>
                <div class="form-error" style="margin-top:4px;"><?= h($errors['bank_account_id']) ?></div>
              <?php endif; ?>

              <?php if ($bankLoadError !== ''): ?>
                <div class="form-error" style="margin-top:4px;"><?= h(sprintf(t('admin.customer_txn.bank.load_error', [], 'Bank account load error: %s'), $bankLoadError)) ?></div>
              <?php elseif (!$bankAccounts): ?>
                <div style="font-size:11px;color:#b91c1c;margin-top:4px;"><?= h(t('admin.customer_txn.bank.none', [], 'No bank accounts found in company_bank_accounts.')) ?></div>
              <?php else: ?>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;"><?= h(t('admin.customer_txn.bank.helper_out', [], 'Choose which bank / cash account this payout is from.')) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-grid form-grid-3" style="margin-top:10px;">
            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.field.currency', [], 'Currency')) ?></label>
              <input type="text" id="currency_input" name="currency" class="form-control" value="<?= h($data['currency']) ?>">

              <div id="fx_rate_block" style="margin-top:8px;<?= (strtoupper((string)($data['currency'] ?? '')) === strtoupper($baseCurrency) ? 'display:none;' : '') ?>">
                <label class="field-label" style="font-size:12px;">
                  (1 <span id="fx_rate_ccy_label"><?= h($data['currency'] ?: $baseCurrency) ?></span> = ? <?= h($baseCurrency) ?>)
                </label>
                <input type="number" step="0.000001" min="0" name="fx_rate" id="fx_rate_input" class="form-control" value="<?= h($data['fx_rate'] ?? '') ?>">
                <?php if (isset($errors['fx_rate'])): ?>
                  <div class="form-error" style="margin-top:4px;"><?= h($errors['fx_rate']) ?></div>
                <?php endif; ?>
                <div style="margin-top:6px;">
                  <label class="field-label" style="font-size:12px;">
                    <?= h(t('admin.customer_txn.fx.amount_in_base_label', ['base'=>$baseCurrency], 'Amount in '.$baseCurrency.' (for info)')) ?>
                  </label>
                  <input type="text" id="amount_in_base_display" class="form-control" value="" readonly>
                </div>
              </div>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h(t('admin.customer_txn.field.amount', [], 'Amount')) ?>
                <span class="field-required">*</span>
              </label>
              <input type="number" step="0.01" min="0" name="amount" id="amount_input" class="form-control" value="<?= h($data['amount']) ?>">
              <?php if (isset($errors['amount'])): ?>
                <div class="form-error"><?= h($errors['amount']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Payer section -->
        <div class="form-section">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('admin.customer_txn.payer.title', [], 'Payer (our side)')) ?></div>
              <div class="form-section-desc"><?= h(t('admin.customer_txn.payer.desc', [], 'Which company is paying out, and who signs on our side.')) ?></div>
            </div>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.payer.company', [], 'Payer company')) ?></label>
              <select name="payer_company_id" class="form-control">
                <option value=""><?= h(t('admin.customer_txn.payer.company_placeholder', [], '— Select company —')) ?></option>
                <?php foreach ($payer_companies as $pc): ?>
                  <?php
                    $label = (string)$pc['name'];
                    if (!empty($pc['reg_no'])) $label .= ' · ' . $pc['reg_no'];
                    $pcId = (int)$pc['id'];
                  ?>
                  <option value="<?= $pcId ?>" <?= ((int)($data['payer_company_id'] ?? 0) === $pcId) ? 'selected' : '' ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.payer.staff', [], 'Payer staff / signatory')) ?></label>
              <select name="payer_staff_id" class="form-control">
                <option value=""><?= h(t('admin.customer_txn.payer.staff_placeholder', [], '— Select staff —')) ?></option>
                <?php foreach ($payer_staff as $ps): ?>
                  <?php
                    $psId  = (int)$ps['id'];
                    $label = (string)$ps['staff_name'];
                    if (!empty($ps['ic_no'])) $label .= ' · ' . $ps['ic_no'];
                  ?>
                  <option value="<?= $psId ?>" <?= ((int)($data['payer_staff_id'] ?? 0) === $psId) ? 'selected' : '' ?>>
                    <?= h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        </div>

        <!-- Parties -->
        <div class="form-section">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('admin.customer_txn.parties.title', [], 'Parties')) ?></div>
              <div class="form-section-desc"><?= h(t('admin.customer_txn.parties.desc', [], 'Counterparty is fixed from customer; recipient (signer) only needed for OUT.')) ?></div>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('admin.customer_txn.parties.counterparty', [], 'Counterparty (fixed)')) ?></label>
            <div style="font-size:13px;padding:8px 10px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb;">
              <?= h($customer['name']) ?>
              <?php if (!empty($customer['reg_no'])): ?>
                (<?= h($customer['reg_no']) ?>)
              <?php endif; ?>
            </div>
          </div>

          <div class="form-grid form-grid-2" style="margin-top:8px;">
            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.recipient.name', [], 'Recipient name (who signs)')) ?></label>
              <input list="recipient_list" type="text" id="recipient_name" name="recipient_name" class="form-control"
                     value="<?= h($data['recipient_name']) ?>"
                     placeholder="<?= h(t('admin.customer_txn.recipient.placeholder', [], 'Type or pick from login users...')) ?>">
              <datalist id="recipient_list">
                <?php foreach ($recipientOptions as $opt): ?>
                  <option value="<?= h($opt) ?>"></option>
                <?php endforeach; ?>
                <?php foreach ($loginUsers as $lu): ?>
                  <?php
                    $nric  = trim((string)($lu['nric'] ?? ''));
                    $label = $lu['full_name'] . ($nric !== '' ? ' (' . $nric . ')' : '');
                  ?>
                  <option value="<?= h($lu['full_name']) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.recipient.nric', [], 'Recipient NRIC')) ?></label>
              <input type="text" id="recipient_nric" name="recipient_nric" class="form-control" value="<?= h($data['recipient_nric']) ?>">
            </div>
          </div>
        </div>

        <!-- Description & attachments -->
        <div class="form-section">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('admin.customer_txn.desc.title', [], 'Description & attachment')) ?></div>
              <div class="form-section-desc"><?= h(t('admin.customer_txn.desc.desc', [], 'Reference, title shown in list, notes, and PDF / image attachment.')) ?></div>
            </div>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.field.title', [], 'Title')) ?></label>
              <input type="text" name="title" class="form-control" value="<?= h($data['title']) ?>">
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(t('admin.customer_txn.field.ref_no', [], 'Reference no.')) ?></label>
              <input type="text" name="ref_no" class="form-control" value="<?= h($data['ref_no']) ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('admin.customer_txn.field.notes', [], 'Notes')) ?></label>
            <textarea name="notes" class="form-control" rows="3"><?= h($data['notes']) ?></textarea>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(t('admin.customer_txn.attach.all', [], 'Attachments (PDF / image)')) ?></label>
            <input type="file" name="attachments[]" class="form-control" accept=".pdf,image/*" multiple>
            <?php if (function_exists('app_upload_limit_label')): ?>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;"><?= h('Max ' . app_upload_limit_label()) ?></div>
            <?php endif; ?>

            <?php if ($extraFiles): ?>
              <div style="margin-top:8px;">
                <div style="font-size:12px;font-weight:600;margin-bottom:4px;"><?= h(t('admin.customer_txn.attach.existing', [], 'Existing files')) ?></div>
                <ul style="font-size:12px;list-style:none;padding-left:0;margin:0;">
                  <?php foreach ($extraFiles as $ef): ?>
                    <?php
                      $path = (string)$ef['file_path'];
                      $name = $ef['file_name'] ?? basename($path);
                      $href = $uploadBaseUrl . ltrim($path, '/');
                    ?>
                    <li style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
                      <a href="<?= h($href) ?>" style="flex:1 1 auto;"><?= h($name) ?></a>
                      <label style="font-size:11px;color:#b91c1c;white-space:nowrap;">
                        <input type="checkbox" name="delete_files[]" value="<?= (int)$ef['id'] ?>" style="margin-right:3px;">
                        <?= h(t('admin.customer_txn.attach.delete', [], 'Delete')) ?>
                      </label>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <?php if ($multiUploadNotes): ?>
              <div style="margin-top:6px;font-size:11px;color:#6b7280;">
                <?php foreach ($multiUploadNotes as $note): ?>
                  <div>• <?= h($note) ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Signature -->
        <div class="form-section">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('admin.customer_txn.sign.title', [], 'Signature requirement')) ?></div>
              <div class="form-section-desc">
                <?= h(t('admin.customer_txn.sign.desc', [], 'For OUT: if enabled, BOTH sides must sign (our side + customer). If disabled, no signature required.')) ?>
              </div>
            </div>
          </div>

          <div class="form-group">
            <?php if ((int)($data['is_contra'] ?? 0) === 1): ?>
              <div style="font-size:13px;color:#6b7280;">
                <?= h(t('admin.customer_txn.sign.contra_note', [], 'This OUT transaction is marked as contra. Signature is not required.')) ?>
              </div>
            <?php else: ?>
              <label class="switch-label">
                <span class="switch-text"><?= h(t('admin.customer_txn.sign.require', [], 'Require signatures (both sides)')) ?></span>
                <label class="switch">
                  <input type="checkbox" name="require_signature" value="1" <?= ((int)($data['require_signature'] ?? 0) === 1) ? 'checked' : '' ?>>
                  <span class="slider"></span>
                </label>
              </label>
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(t('admin.customer_txn.sign.require_help', [], 'If checked: our side + customer must sign before it can become CONFIRMED.')) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Footer -->
        <div class="form-footer-row">
          <div class="form-footer-left">
            <?php if ((int)($data['is_contra'] ?? 0) === 1): ?>
              <span class="badge-soft"><?= h(t('admin.customer_txn.badge.contra', [], 'Contra')) ?></span>
            <?php endif; ?>
            <?php if (strtoupper((string)($data['out_kind'] ?? 'NORMAL')) === 'LOAN'): ?>
              <span class="badge-soft"><?= h(t('admin.customer_txn.badge.loan', [], 'Loan / Advance')) ?></span>
            <?php endif; ?>
            <?php if (strtoupper((string)($data['pay_source_type'] ?? 'BANK')) === 'CUSTOMER'): ?>
              <span class="badge-soft"><?= h(t('admin.customer_txn.badge.paid_by_customer', [], 'Paid by customer (B)')) ?></span>
            <?php endif; ?>
          </div>
          <div class="form-footer-right">
            <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $customer['id'])) ?>" class="btn btn-light">
              <?= h(t('admin.common.cancel', [], 'Cancel')) ?>
            </a>
            <button type="submit" class="btn btn-primary"><?= h(t('admin.customer_txn.out.save_btn', [], 'Save OUT')) ?></button>
          </div>
        </div>

      </form>
    </div>
  </div>
</div>

<script>
const loginUsers   = <?= json_encode($loginUsersJs) ?>;
const baseCurrency = <?= json_encode((string)$baseCurrency) ?>;

document.addEventListener('DOMContentLoaded', function () {
  const nameInput      = document.getElementById('recipient_name');
  const nricInput      = document.getElementById('recipient_nric');

  const paySourceType  = document.getElementById('pay_source_type');
  const paySourceWrap  = document.getElementById('pay_source_customer_wrap');
  const bankWrap       = document.getElementById('bank_block_wrap');

  const bankSelect     = document.getElementById('bank_account_id');
  const currencyInput  = document.getElementById('currency_input');
  const fxRateBlock    = document.getElementById('fx_rate_block');
  const fxCcyLabelSpan = document.getElementById('fx_rate_ccy_label');
  const fxRateInput    = document.getElementById('fx_rate_input');
  const amountInput    = document.getElementById('amount_input');
  const amountBaseDisp = document.getElementById('amount_in_base_display');

  function refreshPaySourceUI() {
    const v = (paySourceType && paySourceType.value) ? paySourceType.value.toUpperCase() : 'BANK';
    if (v === 'CUSTOMER') {
      if (paySourceWrap) paySourceWrap.style.display = '';
      if (bankWrap) bankWrap.style.display = 'none';
      if (bankSelect) bankSelect.value = '';
      if (currencyInput) currencyInput.readOnly = false;
    } else {
      if (paySourceWrap) paySourceWrap.style.display = 'none';
      if (bankWrap) bankWrap.style.display = '';
    }
    updateCurrencyFromBank();
  }
  if (paySourceType) {
    paySourceType.addEventListener('change', refreshPaySourceUI);
    refreshPaySourceUI();
  }

  if (nameInput && nricInput && Array.isArray(loginUsers)) {
    function syncNric() {
      const v = nameInput.value.trim();
      if (!v) return;
      const found = loginUsers.find(u => u.name === v);
      if (found) nricInput.value = found.nric || '';
    }
    nameInput.addEventListener('change', syncNric);
    nameInput.addEventListener('blur', syncNric);
  }

  function recomputeAmountInBase() {
    if (!amountInput || !fxRateInput || !amountBaseDisp || !currencyInput) return;
    const cur  = (currencyInput.value || '').toUpperCase();
    const base = (baseCurrency || '').toUpperCase();
    if (!cur || cur === base) { amountBaseDisp.value = ''; return; }
    const amt = parseFloat(amountInput.value || '0');
    const fx  = parseFloat(fxRateInput.value || '0');
    if (!amt || !fx) { amountBaseDisp.value = ''; return; }
    amountBaseDisp.value = (amt * fx).toFixed(2);
  }

  function updateFxBlockForCurrency() {
    if (!currencyInput || !fxRateBlock) return;
    const cur  = (currencyInput.value || '').toUpperCase();
    const base = (baseCurrency || '').toUpperCase();

    if (!cur || cur === base) {
      fxRateBlock.style.display = 'none';
      if (fxRateInput) fxRateInput.value = '';
      if (amountBaseDisp) amountBaseDisp.value = '';
    } else {
      fxRateBlock.style.display = '';
    }

    if (fxCcyLabelSpan) fxCcyLabelSpan.textContent = cur || baseCurrency;
    recomputeAmountInBase();
  }

  function updateCurrencyFromBank() {
    if (!currencyInput) return;

    const src = (paySourceType && paySourceType.value) ? paySourceType.value.toUpperCase() : 'BANK';

    if (src === 'CUSTOMER') {
      currencyInput.readOnly = false;
      updateFxBlockForCurrency();
      return;
    }

    if (!bankSelect) { currencyInput.readOnly = false; updateFxBlockForCurrency(); return; }

    const opt = bankSelect.options[bankSelect.selectedIndex];
    if (!opt || !opt.value) { currencyInput.readOnly = false; updateFxBlockForCurrency(); return; }

    const bankCurrency = (opt.getAttribute('data-currency') || '').toUpperCase();
    if (bankCurrency) {
      currencyInput.value = bankCurrency;
      currencyInput.readOnly = true;
    } else {
      currencyInput.readOnly = false;
    }
    updateFxBlockForCurrency();
  }

  if (bankSelect) bankSelect.addEventListener('change', updateCurrencyFromBank);
  if (currencyInput) currencyInput.addEventListener('input', updateFxBlockForCurrency);
  if (amountInput) amountInput.addEventListener('input', recomputeAmountInBase);
  if (fxRateInput) fxRateInput.addEventListener('input', recomputeAmountInBase);

  updateCurrencyFromBank();
  updateFxBlockForCurrency();
  recomputeAmountInBase();
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
