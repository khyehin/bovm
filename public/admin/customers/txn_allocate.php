<?php
// public/admin/customers/txn_allocate.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_admin();
require_perm('TXN.ALLOC');   // 需要 TXN.ALLOC 才能做 allocation

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function tt(string $key, array $vars = [], string $fallback = ''): string {
  if (function_exists('t')) return (string)t($key, $vars, $fallback);
  return $fallback !== '' ? $fallback : $key;
}

// ✅ schema-safe columns detection
function table_columns(PDO $pdo, string $table): array {
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];

  $cols = [];
  try {
    $st = $pdo->query("DESCRIBE `$table`");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $f = (string)($r['Field'] ?? '');
      if ($f !== '') $cols[$f] = true;
    }
  } catch (Throwable $e) {}
  return $cache[$key] = $cols;
}

$txnCols = table_columns($pdo, 'customer_txn');

$customerId = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0); // source customer
$sourceId   = (int)($_GET['source_id'] ?? $_POST['source_id'] ?? 0);     // specific source txn（有值=单笔模式）

if ($customerId <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

// load source customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $customerId]);
$sourceCustomer = $st->fetch();
if (!$sourceCustomer) {
  http_response_code(404);
  exit('Source customer not found');
}

// load all other customers as targets
$st = $pdo->prepare("
    SELECT id, code, name, reg_no
    FROM customers
    WHERE id <> :id
    ORDER BY code ASC, name ASC
");
$st->execute([':id' => $customerId]);
$targetCustomers = $st->fetchAll();

$errors = [];

/**
 * =============== 模式 A：单笔 allocation（有 source_id） ===============
 */
if ($sourceId > 0) {

  // load source IN transaction (must be original IN)
  $st = $pdo->prepare("
      SELECT *
      FROM customer_txn
      WHERE id = :id
        AND customer_id = :cid
        AND txn_type = 'IN'
        AND (source_txn_id IS NULL OR source_txn_id = 0)
  ");
  $st->execute([':id' => $sourceId, ':cid' => $customerId]);
  $sourceTxn = $st->fetch();
  if (!$sourceTxn) {
    http_response_code(404);
    exit('Source IN transaction not found or not eligible for allocation');
  }

  $amount       = (float)$sourceTxn['amount'];
  $allocated    = (float)($sourceTxn['allocated_amount'] ?? 0);
  $remainingRaw = $amount - $allocated;
  $remaining    = $remainingRaw > 0 ? $remainingRaw : 0.0;

  if ($remaining <= 0) {
    http_response_code(400);
    exit('This transaction is already fully allocated.');
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetId    = (int)($_POST['target_customer_id'] ?? 0);
    $allocAmount = (float)($_POST['alloc_amount'] ?? 0);

    if ($targetId > 0 && $targetId === $customerId) {
      $errors['target_customer_id'] = tt('admin.txn_allocate.error_target_same_as_source', [], 'Target customer cannot be the same as source customer.');
    }
    if ($targetId <= 0) {
      $errors['target_customer_id'] = tt('admin.txn_allocate.error_target_customer_required', [], 'Target customer is required.');
    }
    if ($allocAmount <= 0) {
      $errors['alloc_amount'] = tt('admin.txn_allocate.error_amount_gt_zero', [], 'Amount must be greater than 0.');
    } elseif ($allocAmount > $remaining + 0.0001) {
      $errors['alloc_amount'] = tt('admin.txn_allocate.error_amount_exceeds_remaining', [], 'Amount exceeds remaining balance.');
    }

    // load target customer
    $targetCustomer = null;
    if ($targetId > 0) {
      $st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
      $st->execute([':id' => $targetId]);
      $targetCustomer = $st->fetch();
      if (!$targetCustomer) {
        $errors['target_customer_id'] = tt('admin.txn_allocate.error_target_not_found', [], 'Target customer not found.');
      }
    }

    if (!$errors && $targetCustomer) {
      try {
        $pdo->beginTransaction();

        // re-lock source row
        $st = $pdo->prepare("
              SELECT *
              FROM customer_txn
              WHERE id = :id
                AND customer_id = :cid
                AND txn_type = 'IN'
                AND (source_txn_id IS NULL OR source_txn_id = 0)
              FOR UPDATE
        ");
        $st->execute([':id' => $sourceId, ':cid' => $customerId]);
        $src = $st->fetch();
        if (!$src) throw new RuntimeException('Source transaction not found or not eligible anymore.');

        $srcAmount    = (float)$src['amount'];
        $srcAllocated = (float)($src['allocated_amount'] ?? 0);
        $srcRemain    = $srcAmount - $srcAllocated;
        if ($srcRemain <= 0 || $allocAmount > $srcRemain + 0.0001) {
          throw new RuntimeException('Allocation exceeds remaining amount.');
        }

        $now     = date('Y-m-d H:i:s');
        $txnDate = $src['txn_date'] ?: substr((string)$src['created_at'], 0, 10);

        // info from source customer for reference
        $fromName  = $sourceCustomer['name'] ?? '';
        $fromRegNo = $sourceCustomer['reg_no'] ?? '';
        $fromRep   = $sourceCustomer['contact_name'] ?? '';
        $fromNric  = $sourceCustomer['default_receipt_nric'] ?? '';

        // ===== 1) Insert IN for target customer (B) =====
        // ✅ 改：title 不再用 target name，而是写 Allocate
        $inTitleForB = tt('admin.txn_allocate.title_allocate', [], 'Allocate');

        $notesB = tt('admin.txn_allocate.note_allocated_from', [], 'Allocated from') . ' ' .
          ($sourceCustomer['name'] ?? ('Customer #' . $customerId)) .
          ' (' . tt('admin.txn_allocate.note_txn_hash', [], 'txn #') . $sourceId . ')';

        // ✅ schema-safe: in_kind
        $hasInKind = isset($txnCols['in_kind']);

        $colsIn = "
          customer_id,
          payer_company_id,
          source_txn_id,
          txn_date,
          from_name,
          from_reg_no,
          from_rep_name,
          from_rep_nric,
          ref_no,
          title," . ($hasInKind ? " in_kind," : "") . "
          txn_type,
          method,
          currency,
          amount,
          allocated_amount,
          status,
          sent_at,
          confirmed_at,
          confirmed_by_user_id,
          signature_image,
          notes,
          attachment_path,
          attachment_name,
          attachment_mime,
          recipient_name,
          recipient_nric,
          require_signature,
          is_contra,
          created_at,
          updated_at
        ";

        $valsIn = "
          :customer_id,
          :payer_company_id,
          :source_txn_id,
          :txn_date,
          :from_name,
          :from_reg_no,
          :from_rep_name,
          :from_rep_nric,
          :ref_no,
          :title," . ($hasInKind ? " :in_kind," : "") . "
          'IN',
          :method,
          :currency,
          :amount,
          0,
          'CONFIRMED',
          :sent_at,
          :confirmed_at,
          NULL,
          NULL,
          :notes,
          :attachment_path,
          :attachment_name,
          :attachment_mime,
          NULL,
          NULL,
          0,
          0,
          :created_at,
          :updated_at
        ";

        $insertInB = $pdo->prepare("INSERT INTO customer_txn ($colsIn) VALUES ($valsIn)");

        $paramsInB = [
          ':customer_id'      => (int)$targetCustomer['id'],
          ':payer_company_id' => null,
          ':source_txn_id'    => $sourceId,
          ':txn_date'         => $txnDate,
          ':from_name'        => $fromName ?: null,
          ':from_reg_no'      => $fromRegNo ?: null,
          ':from_rep_name'    => $fromRep ?: null,
          ':from_rep_nric'    => $fromNric ?: null,
          ':ref_no'           => $src['ref_no'] ?: null,
          ':title'            => $inTitleForB,
          ':method'           => $src['method'] ?: 'CASH',
          ':currency'         => $src['currency'] ?: 'MYR',
          ':amount'           => $allocAmount,
          ':sent_at'          => $now,
          ':confirmed_at'     => $now,
          ':notes'            => $notesB,
          ':attachment_path'  => $src['attachment_path'] ?? null,
          ':attachment_name'  => $src['attachment_name'] ?? null,
          ':attachment_mime'  => $src['attachment_mime'] ?? null,
          ':created_at'       => $now,
          ':updated_at'       => $now,
        ];
        if ($hasInKind) $paramsInB[':in_kind'] = 'ALLOCATE';

        $insertInB->execute($paramsInB);
        $targetInId = (int)$pdo->lastInsertId();

        // ===== 2) Insert OUT contra for source customer (A) =====
        $outTitleForA = tt('admin.txn_allocate.note_allocated_to', [], 'Allocated to') . ' ' .
          ($targetCustomer['name'] ?? ('Customer #' . $targetCustomer['id']));
        $notesA = tt('admin.txn_allocate.note_allocation_to', [], 'Allocation to') . ' ' .
          ($targetCustomer['name'] ?? ('Customer #' . $targetCustomer['id'])) .
          ' ' . tt('admin.txn_allocate.note_from_in_txn', [], 'from IN txn #') . $sourceId;

        $insertOutA = $pdo->prepare("
          INSERT INTO customer_txn (
            customer_id,
            payer_company_id,
            source_txn_id,
            txn_date,
            from_name,
            from_reg_no,
            from_rep_name,
            from_rep_nric,
            ref_no,
            title,
            txn_type,
            method,
            currency,
            amount,
            allocated_amount,
            status,
            sent_at,
            confirmed_at,
            confirmed_by_user_id,
            signature_image,
            notes,
            attachment_path,
            attachment_name,
            attachment_mime,
            recipient_name,
            recipient_nric,
            require_signature,
            is_contra,
            created_at,
            updated_at
          ) VALUES (
            :customer_id,
            :payer_company_id,
            :source_txn_id,
            :txn_date,
            :from_name,
            :from_reg_no,
            :from_rep_name,
            :from_rep_nric,
            :ref_no,
            :title,
            'OUT',
            :method,
            :currency,
            :amount,
            0,
            'CONFIRMED',
            :sent_at,
            :confirmed_at,
            NULL,
            NULL,
            :notes,
            :attachment_path,
            :attachment_name,
            :attachment_mime,
            NULL,
            NULL,
            0,
            1,
            :created_at,
            :updated_at
          )
        ");
        $insertOutA->execute([
          ':customer_id'      => $customerId,
          ':payer_company_id' => $src['payer_company_id'] ?? null,
          ':source_txn_id'    => $sourceId,
          ':txn_date'         => $txnDate,
          ':from_name'        => $src['from_name'] ?? null,
          ':from_reg_no'      => $src['from_reg_no'] ?? null,
          ':from_rep_name'    => $src['from_rep_name'] ?? null,
          ':from_rep_nric'    => $src['from_rep_nric'] ?? null,
          ':ref_no'           => $src['ref_no'] ?? null,
          ':title'            => $outTitleForA,
          ':method'           => $src['method'] ?: 'CASH',
          ':currency'         => $src['currency'] ?: 'MYR',
          ':amount'           => $allocAmount,
          ':sent_at'          => $now,
          ':confirmed_at'     => $now,
          ':notes'            => $notesA,
          ':attachment_path'  => $src['attachment_path'] ?? null,
          ':attachment_name'  => $src['attachment_name'] ?? null,
          ':attachment_mime'  => $src['attachment_mime'] ?? null,
          ':created_at'       => $now,
          ':updated_at'       => $now,
        ]);
        $sourceContraId = (int)$pdo->lastInsertId();

        // ===== 3) Update source allocated_amount =====
        $newAllocated = $srcAllocated + $allocAmount;
        $updateSrc = $pdo->prepare("
          UPDATE customer_txn
          SET allocated_amount = :alloc,
              updated_at       = :updated_at
          WHERE id = :id
        ");
        $updateSrc->execute([
          ':alloc'      => $newAllocated,
          ':updated_at' => $now,
          ':id'         => $sourceId,
        ]);

        // ===== 4) Copy multi attachments (customer_txn_files) =====
        try {
          $stFiles = $pdo->prepare("
            SELECT file_path, file_name, file_mime
            FROM customer_txn_files
            WHERE txn_id = :tid
          ");
          $stFiles->execute([':tid' => $sourceId]);
          $files = $stFiles->fetchAll();

          if ($files) {
            $insFile = $pdo->prepare("
              INSERT INTO customer_txn_files
                (txn_id, file_path, file_name, file_mime, created_at)
              VALUES
                (:txn_id, :file_path, :file_name, :file_mime, :created_at)
            ");

            foreach ($files as $f) {
              $baseParams = [
                ':file_path'  => $f['file_path'],
                ':file_name'  => $f['file_name'],
                ':file_mime'  => $f['file_mime'],
                ':created_at' => $now,
              ];
              $insFile->execute($baseParams + [':txn_id' => $targetInId]);
              $insFile->execute($baseParams + [':txn_id' => $sourceContraId]);
            }
          }
        } catch (Throwable $e) {}

        $pdo->commit();

        if (function_exists('audit_log')) {
          audit_log(
            $pdo,
            'TXN.ALLOCATE',
            [
              'source_txn'    => $sourceId,
              'from_customer' => $customerId,
              'to_customer'   => $targetId,
              'amount'        => $allocAmount
            ],
            'customer_txn',
            $sourceId
          );
        }

        $backUrl = url('admin/customers/txn_list.php?customer_id=' . $customerId . '&ok=alloc');
        header('Location: ' . $backUrl);
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors['general'] = tt('admin.txn_allocate.error_allocation_failed', [], 'Allocation failed') . ': ' . $e->getMessage();
      }
    }
  }

  $page_title = tt('admin.txn_allocate.page_title', [], 'Allocate IN');
  include __DIR__ . '/../include/header.php';
  ?>

  <div class="admin-main">
    <div class="admin-main-inner">
      <div class="admin-card admin-card-elevated admin-card-narrow">

        <div class="form-page-header">
          <div>
            <div class="form-page-eyebrow">
              <?= h(tt('admin.txn_allocate.eyebrow_allocation', [], 'Allocation')) ?>
            </div>
            <h2 class="form-page-title">
              <?= h(tt('admin.txn_allocate.heading_allocate_in_from', [], 'Allocate IN from')) ?>
              <?= ' ' . h($sourceCustomer['name']) ?>
            </h2>
            <div class="form-page-subtitle">
              <?= h(tt('admin.txn_allocate.subtitle_allocation', [], 'Allocate part of this IN transaction to another customer.')) ?>
            </div>
          </div>
          <div class="form-page-meta">
            <span class="badge-soft">
              <?= h(tt('admin.txn_allocate.label_txn_no', [], 'Txn #')) ?>
              <?= h($sourceTxn['id']) ?>
            </span>
          </div>
        </div>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert-error" style="margin-bottom:12px;">
            <?= h($errors['general']) ?>
          </div>
        <?php endif; ?>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title"><?= h(tt('admin.txn_allocate.section_source_txn_title', [], 'Source transaction')) ?></div>
            <div class="form-section-desc"><?= h(tt('admin.txn_allocate.section_source_txn_desc', [], 'You are allocating from this IN record.')) ?></div>
          </div>

          <div style="font-size:13px; line-height:1.6;">
            <div><strong><?= h(tt('admin.txn_allocate.label_customer', [], 'Customer:')) ?></strong>
              <?= h(($sourceCustomer['code'] ?? '') . ' - ' . $sourceCustomer['name']) ?></div>
            <div><strong><?= h(tt('admin.txn_allocate.label_date', [], 'Date:')) ?></strong>
              <?= h($sourceTxn['txn_date'] ?: substr((string)$sourceTxn['created_at'], 0, 10)) ?></div>
            <div><strong><?= h(tt('admin.txn_allocate.label_title', [], 'Title:')) ?></strong>
              <?= h($sourceTxn['title']) ?></div>
            <div><strong><?= h(tt('admin.txn_allocate.label_amount', [], 'Amount:')) ?></strong>
              <?= h($sourceTxn['currency'] ?: 'MYR') ?> <?= number_format((float)$sourceTxn['amount'], 2) ?></div>
            <div><strong><?= h(tt('admin.txn_allocate.label_allocated', [], 'Allocated:')) ?></strong>
              <?= h($sourceTxn['currency'] ?: 'MYR') ?> <?= number_format($allocated, 2) ?></div>
            <div><strong><?= h(tt('admin.txn_allocate.label_remaining', [], 'Remaining:')) ?></strong>
              <?= h($sourceTxn['currency'] ?: 'MYR') ?> <?= number_format($remaining, 2) ?></div>

            <?php if (!empty($sourceTxn['attachment_path'])): ?>
              <div style="margin-top:6px;">
                <strong><?= h(tt('admin.txn_allocate.label_attachment', [], 'Attachment:')) ?></strong>
                <a href="<?= h(upload_href((string)$sourceTxn['attachment_path'])) ?>">
                  <?= h($sourceTxn['attachment_name'] ?: tt('admin.txn_allocate.link_view_file', [], 'View file')) ?>
                </a>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <form method="post" class="form-layout" style="margin-top:18px;">
          <input type="hidden" name="customer_id" value="<?= h($customerId) ?>">
          <input type="hidden" name="source_id" value="<?= h($sourceId) ?>">

          <div class="form-section">
            <div class="form-section-header">
              <div class="form-section-title"><?= h(tt('admin.txn_allocate.section_allocate_to_title', [], 'Allocate to')) ?></div>
              <div class="form-section-desc"><?= h(tt('admin.txn_allocate.section_allocate_to_desc', [], 'Choose target customer and amount to allocate.')) ?></div>
            </div>

            <div class="form-grid form-grid-2">
              <div class="form-group">
                <label class="field-label">
                  <?= h(tt('admin.txn_allocate.field_target_customer', [], 'Target customer')) ?>
                  <span class="field-required">*</span>
                </label>
                <select name="target_customer_id" class="form-control">
                  <option value=""><?= h(tt('admin.txn_allocate.option_select_customer', [], '— Select customer —')) ?></option>
                  <?php foreach ($targetCustomers as $tc): ?>
                    <option value="<?= h($tc['id']) ?>"
                      <?= isset($_POST['target_customer_id']) && (int)$_POST['target_customer_id'] === (int)$tc['id'] ? 'selected' : '' ?>>
                      <?= h(($tc['code'] ? $tc['code'] . ' - ' : '') . $tc['name']) ?>
                      <?php if (!empty($tc['reg_no'])): ?> (<?= h($tc['reg_no']) ?>)<?php endif; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if (isset($errors['target_customer_id'])): ?>
                  <div class="form-error"><?= h($errors['target_customer_id']) ?></div>
                <?php endif; ?>
              </div>

              <div class="form-group">
                <label class="field-label">
                  <?= h(tt('admin.txn_allocate.field_alloc_amount', [], 'Amount to allocate')) ?>
                  <span class="field-required">*</span>
                </label>
                <input type="number" step="0.01" name="alloc_amount" class="form-control"
                  value="<?= h($_POST['alloc_amount'] ?? number_format($remaining, 2, '.', '')) ?>">
                <?php if (isset($errors['alloc_amount'])): ?>
                  <div class="form-error"><?= h($errors['alloc_amount']) ?></div>
                <?php endif; ?>
                <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                  <?= h(tt('admin.txn_allocate.label_max', [], 'Max:')) ?>
                  <?= h($sourceTxn['currency'] ?: 'MYR') ?> <?= number_format($remaining, 2) ?>
                </div>
              </div>
            </div>
          </div>

          <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;gap:8px;">
            <div>
              <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $customerId)) ?>" class="btn btn-light">
                <?= h(tt('admin.common.back', [], 'Back')) ?>
              </a>
            </div>
            <div>
              <button type="submit" class="btn btn-primary">
                <?= h(tt('admin.txn_allocate.btn_allocate', [], 'Allocate')) ?>
              </button>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>

  <?php
  include __DIR__ . '/../include/footer.php';
  exit;
}

/**
 * =============== 模式 B：FIFO allocation（没有 source_id） ===============
 * 同一次 FIFO 只用一种 currency 的余额来 allocate
 */

$sourceTxns = [];
$totalRemaining = 0.0;

$st = $pdo->prepare("
  SELECT *
  FROM customer_txn
  WHERE customer_id = :cid
    AND txn_type = 'IN'
    AND (is_contra IS NULL OR is_contra = 0)
    AND (source_txn_id IS NULL OR source_txn_id = 0)
    AND amount > COALESCE(allocated_amount, 0)
  ORDER BY txn_date ASC, id ASC
");
$st->execute([':cid' => $customerId]);

while ($row = $st->fetch()) {
  $amt       = (float)$row['amount'];
  $allocated = (float)($row['allocated_amount'] ?? 0);
  $remain    = $amt - $allocated;
  if ($remain <= 0) continue;
  $row['_remaining'] = $remain;
  $sourceTxns[] = $row;
  $totalRemaining += $remain;
}

// 每个 currency 的可用余额
$currencyTotals = [];
foreach ($sourceTxns as $row) {
  $cur = strtoupper($row['currency'] ?: 'MYR');
  $currencyTotals[$cur] = ($currencyTotals[$cur] ?? 0.0) + (float)$row['_remaining'];
}

// 当前选择要 allocate 的 currency
$allocCurrency = strtoupper(trim($_POST['alloc_currency'] ?? ''));
if ($allocCurrency === '' && count($currencyTotals) === 1) {
  $allocCurrency = array_key_first($currencyTotals);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $targetId    = (int)($_POST['target_customer_id'] ?? 0);
  $allocAmount = (float)($_POST['alloc_amount'] ?? 0);

  if ($targetId > 0 && $targetId === $customerId) {
    $errors['target_customer_id'] = tt('admin.txn_allocate_fifo.error_target_same_as_source', [], 'Target customer cannot be the same as source customer.');
  }
  if ($targetId <= 0) {
    $errors['target_customer_id'] = tt('admin.txn_allocate_fifo.error_target_customer_required', [], 'Target customer is required.');
  }

  if ($allocCurrency === '') {
    $errors['alloc_currency'] = tt('admin.txn_allocate_fifo.error_currency_required', [], 'Currency is required.');
  } elseif (!isset($currencyTotals[$allocCurrency])) {
    $errors['alloc_currency'] = tt('admin.txn_allocate_fifo.error_currency_no_balance', [], 'Selected currency has no remaining balance.');
  }

  $availableForCur = ($allocCurrency && isset($currencyTotals[$allocCurrency])) ? $currencyTotals[$allocCurrency] : 0.0;

  if ($allocAmount <= 0) {
    $errors['alloc_amount'] = tt('admin.txn_allocate_fifo.error_amount_gt_zero', [], 'Amount must be greater than 0.');
  } elseif ($allocAmount > $availableForCur + 0.0001) {
    $errors['alloc_amount'] = tt('admin.txn_allocate_fifo.error_amount_exceeds_available', [], 'Amount exceeds available remaining.');
  }

  // load target customer
  $targetCustomer = null;
  if ($targetId > 0) {
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
    $st->execute([':id' => $targetId]);
    $targetCustomer = $st->fetch();
    if (!$targetCustomer) {
      $errors['target_customer_id'] = tt('admin.txn_allocate_fifo.error_target_not_found', [], 'Target customer not found.');
    }
  }

  if (!$errors && $targetCustomer) {
    try {
      $pdo->beginTransaction();

      // lock pool rows (selected currency)
      $stLock = $pdo->prepare("
        SELECT *
        FROM customer_txn
        WHERE customer_id = :cid
          AND txn_type = 'IN'
          AND (is_contra IS NULL OR is_contra = 0)
          AND (source_txn_id IS NULL OR source_txn_id = 0)
          AND amount > COALESCE(allocated_amount, 0)
          AND UPPER(currency) = :cur
        ORDER BY txn_date ASC, id ASC
        FOR UPDATE
      ");
      $stLock->execute([
        ':cid' => $customerId,
        ':cur' => $allocCurrency ?: 'MYR',
      ]);
      $srcRows = $stLock->fetchAll();

      $remainingToAlloc = $allocAmount;
      $now              = date('Y-m-d H:i:s');

      $fromName  = $sourceCustomer['name'] ?? '';
      $fromRegNo = $sourceCustomer['reg_no'] ?? '';
      $fromRep   = $sourceCustomer['contact_name'] ?? '';
      $fromNric  = $sourceCustomer['default_receipt_nric'] ?? '';

      $allocatedTotal = 0.0;
      $usedSrcIds     = [];
      $segments       = [];

      foreach ($srcRows as $src) {
        if ($remainingToAlloc <= 0) break;

        $srcId        = (int)$src['id'];
        $srcAmount    = (float)$src['amount'];
        $srcAllocated = (float)($src['allocated_amount'] ?? 0);
        $srcRemain    = $srcAmount - $srcAllocated;
        if ($srcRemain <= 0) continue;

        $chunk = ($srcRemain >= $remainingToAlloc) ? $remainingToAlloc : $srcRemain;
        if ($chunk <= 0) continue;

        $upd = $pdo->prepare("
          UPDATE customer_txn
          SET allocated_amount = :alloc,
              updated_at       = :updated_at
          WHERE id = :id
        ");
        $upd->execute([
          ':alloc'      => $srcAllocated + $chunk,
          ':updated_at' => $now,
          ':id'         => $srcId,
        ]);

        $allocatedTotal += $chunk;
        $usedSrcIds[$srcId] = true;
        $segments[] = ['src_id' => $srcId, 'amount' => $chunk, 'currency' => $src['currency'] ?: $allocCurrency];

        $remainingToAlloc -= $chunk;
      }

      if ($allocatedTotal <= 0) throw new RuntimeException('Nothing allocated (no remaining balance).');

      $txnDate = date('Y-m-d');

      $segmentSummary = [];
      foreach ($segments as $seg) $segmentSummary[] = '#' . $seg['src_id'] . ' (' . number_format($seg['amount'], 2) . ')';
      $segmentText = implode(', ', $segmentSummary);

      // ✅ target IN title = Allocate
      $inTitleForB = tt('admin.txn_allocate.title_allocate', [], 'Allocate');
      $notesB = tt('admin.txn_allocate_fifo.note_fifo_alloc_from', [], 'FIFO allocation from') . ' ' .
        ($sourceCustomer['name'] ?? ('Customer #' . $customerId))
        . ' ' . tt('admin.txn_allocate_fifo.note_total', [], 'total') . ' ' . number_format($allocatedTotal, 2) . ' ' . $allocCurrency
        . ' ' . tt('admin.txn_allocate_fifo.note_from_in_txn', [], 'from IN txn') . ' ' . $segmentText;

      $firstSrc = $srcRows[0] ?? null;
      $methodForAll = $firstSrc['method'] ?? 'CASH';
      $refNoForAll  = $firstSrc['ref_no'] ?? null;
      $attachPath   = $firstSrc['attachment_path'] ?? null;
      $attachName   = $firstSrc['attachment_name'] ?? null;
      $attachMime   = $firstSrc['attachment_mime'] ?? null;

      $hasInKind = isset($txnCols['in_kind']);
      $colsIn = "
        customer_id,
        payer_company_id,
        source_txn_id,
        txn_date,
        from_name,
        from_reg_no,
        from_rep_name,
        from_rep_nric,
        ref_no,
        title," . ($hasInKind ? " in_kind," : "") . "
        txn_type,
        method,
        currency,
        amount,
        allocated_amount,
        status,
        sent_at,
        confirmed_at,
        confirmed_by_user_id,
        signature_image,
        notes,
        attachment_path,
        attachment_name,
        attachment_mime,
        recipient_name,
        recipient_nric,
        require_signature,
        is_contra,
        created_at,
        updated_at
      ";
      $valsIn = "
        :customer_id,
        :payer_company_id,
        :source_txn_id,
        :txn_date,
        :from_name,
        :from_reg_no,
        :from_rep_name,
        :from_rep_nric,
        :ref_no,
        :title," . ($hasInKind ? " :in_kind," : "") . "
        'IN',
        :method,
        :currency,
        :amount,
        0,
        'CONFIRMED',
        :sent_at,
        :confirmed_at,
        NULL,
        NULL,
        :notes,
        :attachment_path,
        :attachment_name,
        :attachment_mime,
        NULL,
        NULL,
        0,
        0,
        :created_at,
        :updated_at
      ";

      $insertInB = $pdo->prepare("INSERT INTO customer_txn ($colsIn) VALUES ($valsIn)");

      $paramsInB = [
        ':customer_id'      => (int)$targetCustomer['id'],
        ':payer_company_id' => null,
        ':source_txn_id'    => null, // 多来源 summary
        ':txn_date'         => $txnDate,
        ':from_name'        => $fromName ?: null,
        ':from_reg_no'      => $fromRegNo ?: null,
        ':from_rep_name'    => $fromRep ?: null,
        ':from_rep_nric'    => $fromNric ?: null,
        ':ref_no'           => $refNoForAll,
        ':title'            => $inTitleForB,
        ':method'           => $methodForAll,
        ':currency'         => $allocCurrency ?: ($firstSrc['currency'] ?? 'MYR'),
        ':amount'           => $allocatedTotal,
        ':sent_at'          => $now,
        ':confirmed_at'     => $now,
        ':notes'            => $notesB,
        ':attachment_path'  => $attachPath,
        ':attachment_name'  => $attachName,
        ':attachment_mime'  => $attachMime,
        ':created_at'       => $now,
        ':updated_at'       => $now,
      ];
      if ($hasInKind) $paramsInB[':in_kind'] = 'ALLOCATE';

      $insertInB->execute($paramsInB);
      $targetInId = (int)$pdo->lastInsertId();

      // source OUT summary contra（不改）
      $outTitleForA = tt('admin.txn_allocate_fifo.note_allocated_to', [], 'Allocated to') . ' ' .
        ($targetCustomer['name'] ?? ('Customer #' . $targetCustomer['id']));
      $notesA = tt('admin.txn_allocate_fifo.note_fifo_alloc_to', [], 'FIFO allocation to') . ' ' .
        ($targetCustomer['name'] ?? ('Customer #' . $targetCustomer['id']))
        . ' ' . tt('admin.txn_allocate_fifo.note_total', [], 'total') . ' ' . number_format($allocatedTotal, 2) . ' ' . $allocCurrency
        . ' ' . tt('admin.txn_allocate_fifo.note_from_in_txn', [], 'from IN txn') . ' ' . $segmentText;

      $insertOutA = $pdo->prepare("
        INSERT INTO customer_txn (
          customer_id, payer_company_id, source_txn_id, txn_date,
          from_name, from_reg_no, from_rep_name, from_rep_nric,
          ref_no, title, txn_type, method, currency, amount,
          allocated_amount, status, sent_at, confirmed_at,
          confirmed_by_user_id, signature_image, notes,
          attachment_path, attachment_name, attachment_mime,
          recipient_name, recipient_nric, require_signature,
          is_contra, created_at, updated_at
        ) VALUES (
          :customer_id, :payer_company_id, :source_txn_id, :txn_date,
          :from_name, :from_reg_no, :from_rep_name, :from_rep_nric,
          :ref_no, :title, 'OUT', :method, :currency, :amount,
          0, 'CONFIRMED', :sent_at, :confirmed_at,
          NULL, NULL, :notes,
          :attachment_path, :attachment_name, :attachment_mime,
          NULL, NULL, 0,
          1, :created_at, :updated_at
        )
      ");
      $insertOutA->execute([
        ':customer_id'      => $customerId,
        ':payer_company_id' => null,
        ':source_txn_id'    => null,
        ':txn_date'         => $txnDate,
        ':from_name'        => null,
        ':from_reg_no'      => null,
        ':from_rep_name'    => null,
        ':from_rep_nric'    => null,
        ':ref_no'           => $refNoForAll,
        ':title'            => $outTitleForA,
        ':method'           => $methodForAll,
        ':currency'         => $allocCurrency ?: ($firstSrc['currency'] ?? 'MYR'),
        ':amount'           => $allocatedTotal,
        ':sent_at'          => $now,
        ':confirmed_at'     => $now,
        ':notes'            => $notesA,
        ':attachment_path'  => $attachPath,
        ':attachment_name'  => $attachName,
        ':attachment_mime'  => $attachMime,
        ':created_at'       => $now,
        ':updated_at'       => $now,
      ]);
      $sourceContraId = (int)$pdo->lastInsertId();

      // copy attachments (optional)
      try {
        if ($usedSrcIds) {
          $insFile = $pdo->prepare("
            INSERT INTO customer_txn_files
              (txn_id, file_path, file_name, file_mime, created_at)
            VALUES
              (:txn_id, :file_path, :file_name, :file_mime, :created_at)
          ");
          $stFiles = $pdo->prepare("SELECT file_path, file_name, file_mime FROM customer_txn_files WHERE txn_id = :tid");

          foreach (array_keys($usedSrcIds) as $sid) {
            $stFiles->execute([':tid' => $sid]);
            $files = $stFiles->fetchAll();
            if (!$files) continue;

            foreach ($files as $f) {
              $baseParams = [
                ':file_path'  => $f['file_path'],
                ':file_name'  => $f['file_name'],
                ':file_mime'  => $f['file_mime'],
                ':created_at' => $now,
              ];
              $insFile->execute($baseParams + [':txn_id' => $targetInId]);
              $insFile->execute($baseParams + [':txn_id' => $sourceContraId]);
            }
          }
        }
      } catch (Throwable $e) {}

      if (function_exists('audit_log')) {
        audit_log(
          $pdo,
          'TXN.ALLOCATE.FIFO',
          [
            'from_customer'   => $customerId,
            'to_customer'     => $targetId,
            'alloc_amount'    => $allocatedTotal,
            'alloc_currency'  => $allocCurrency,
            'remaining_after' => $remainingToAlloc,
            'segments'        => $segments,
          ],
          'customer_txn',
          null
        );
      }

      $pdo->commit();
      $backUrl = url('admin/customers/txn_list.php?customer_id=' . $customerId . '&ok=alloc');
      header('Location: ' . $backUrl);
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors['general'] = tt('admin.txn_allocate_fifo.error_allocation_failed', [], 'FIFO allocation failed') . ': ' . $e->getMessage();
    }
  }
}

$page_title = tt('admin.txn_allocate_fifo.page_title', [], 'FIFO Allocation');
include __DIR__ . '/../include/header.php';

// max hint
$maxCur = null;
if ($allocCurrency && isset($currencyTotals[$allocCurrency])) $maxCur = $allocCurrency;
elseif (count($currencyTotals) === 1) $maxCur = array_key_first($currencyTotals);
$maxVal = $maxCur ? ($currencyTotals[$maxCur] ?? 0.0) : 0.0;
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.txn_allocate_fifo.eyebrow', [], 'Allocate IN balance (FIFO)')) ?></div>
          <h2 class="form-page-title"><?= h($sourceCustomer['name'] ?? 'Customer') ?></h2>
          <div class="form-page-subtitle">
            <?= h(tt('admin.txn_allocate_fifo.subtitle', [], 'Use remaining IN transactions for this customer and allocate to another customer using FIFO (oldest IN first).')) ?>
          </div>
        </div>
        <div class="form-page-meta">
          <span class="badge-soft"><?= h(tt('admin.txn_allocate_fifo.badge_id', [], 'ID:')) ?> <?= (int)$customerId ?></span>
        </div>
      </div>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:12px;"><?= h($errors['general']) ?></div>
      <?php endif; ?>

      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-title"><?= h(tt('admin.txn_allocate_fifo.section_source_title', [], 'Source IN transactions (FIFO pool)')) ?></div>
          <div class="form-section-desc"><?= h(tt('admin.txn_allocate_fifo.section_source_desc', [], 'These IN transactions still have remaining balance and will be used in FIFO order. Same currency will be allocated together.')) ?></div>
        </div>

        <?php if (!$sourceTxns): ?>
          <div style="font-size:13px;color:#6b7280;"><?= h(tt('admin.txn_allocate_fifo.no_source', [], 'No IN transactions with remaining balance. Nothing to allocate.')) ?></div>
        <?php else: ?>
          <table class="table" style="font-size:12px;">
            <thead>
              <tr>
                <th style="width:70px;"><?= h(tt('admin.txn_allocate_fifo.col.txn', [], 'Txn #')) ?></th>
                <th style="width:110px;"><?= h(tt('admin.txn_allocate_fifo.col.date', [], 'Date')) ?></th>
                <th><?= h(tt('admin.txn_allocate_fifo.col.title', [], 'Title')) ?></th>
                <th style="width:120px;text-align:right;"><?= h(tt('admin.txn_allocate_fifo.col.amount', [], 'Amount')) ?></th>
                <th style="width:120px;text-align:right;"><?= h(tt('admin.txn_allocate_fifo.col.allocated', [], 'Allocated')) ?></th>
                <th style="width:120px;text-align:right;"><?= h(tt('admin.txn_allocate_fifo.col.remaining', [], 'Remaining')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($sourceTxns as $t): ?>
                <tr>
                  <td>#<?= (int)$t['id'] ?></td>
                  <td><?= h($t['txn_date'] ?: substr((string)$t['created_at'], 0, 10)) ?></td>
                  <td><?= h((string)($t['title'] ?? '')) ?></td>
                  <td style="text-align:right;"><?= h($t['currency'] ?: 'MYR') ?> <?= number_format((float)$t['amount'], 2) ?></td>
                  <td style="text-align:right;"><?= h($t['currency'] ?: 'MYR') ?> <?= number_format((float)($t['allocated_amount'] ?? 0), 2) ?></td>
                  <td style="text-align:right;"><?= h($t['currency'] ?: 'MYR') ?> <?= number_format((float)$t['_remaining'], 2) ?></td>
                </tr>
              <?php endforeach; ?>

              <?php foreach ($currencyTotals as $cur => $sum): ?>
                <tr>
                  <td colspan="5" style="text-align:right;font-weight:600;">
                    <?= h(tt('admin.txn_allocate_fifo.total_available_in', ['cur' => $cur], 'Total available in')) ?> <?= h($cur) ?>
                  </td>
                  <td style="text-align:right;font-weight:600;color:#0f766e;"><?= h($cur) ?> <?= number_format($sum, 2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <form method="post" class="form-layout" style="margin-top:18px;">
        <input type="hidden" name="customer_id" value="<?= (int)$customerId ?>">

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title"><?= h(tt('admin.txn_allocate_fifo.section_allocate_to_title', [], 'Allocate to another customer')) ?></div>
            <div class="form-section-desc"><?= h(tt('admin.txn_allocate_fifo.section_allocate_to_desc', [], 'Choose target customer, currency and amount to allocate using FIFO. Only selected currency\'s IN will be used.')) ?></div>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.txn_allocate_fifo.field_target_customer', [], 'Target customer')) ?> <span class="field-required">*</span></label>
              <select name="target_customer_id" class="form-control">
                <option value=""><?= h(tt('admin.txn_allocate_fifo.option_select_customer', [], '— Select customer —')) ?></option>
                <?php foreach ($targetCustomers as $tc): ?>
                  <option value="<?= (int)$tc['id'] ?>"
                    <?= isset($_POST['target_customer_id']) && (int)$_POST['target_customer_id'] === (int)$tc['id'] ? 'selected' : '' ?>>
                    <?= h(($tc['code'] ? $tc['code'] . ' - ' : '') . $tc['name']) ?>
                    <?php if (!empty($tc['reg_no'])): ?> (<?= h($tc['reg_no']) ?>)<?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['target_customer_id'])): ?><div class="form-error"><?= h($errors['target_customer_id']) ?></div><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.txn_allocate_fifo.field_currency', [], 'Currency')) ?> <span class="field-required">*</span></label>
              <select name="alloc_currency" class="form-control">
                <option value=""><?= h(tt('admin.txn_allocate_fifo.option_select_currency', [], '— Select currency —')) ?></option>
                <?php foreach ($currencyTotals as $cur => $sum): ?>
                  <option value="<?= h($cur) ?>" <?= $allocCurrency === $cur ? 'selected' : '' ?>>
                    <?= h($cur) ?> (<?= h(tt('admin.txn_allocate_fifo.available', [], 'available')) ?> <?= number_format($sum, 2) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (isset($errors['alloc_currency'])): ?><div class="form-error"><?= h($errors['alloc_currency']) ?></div><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.txn_allocate_fifo.field_alloc_amount', [], 'Amount to allocate')) ?> <span class="field-required">*</span></label>
              <input type="number" step="0.01" min="0" name="alloc_amount" class="form-control"
                value="<?= h($_POST['alloc_amount'] ?? ($maxVal > 0 ? number_format($maxVal, 2, '.', '') : '0.00')) ?>">
              <?php if (isset($errors['alloc_amount'])): ?><div class="form-error"><?= h($errors['alloc_amount']) ?></div><?php endif; ?>
              <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                <?= h(tt('admin.txn_allocate_fifo.max_available', [], 'Max available:')) ?>
                <?php if ($maxCur): ?><?= h($maxCur) ?> <?= number_format($maxVal, 2) ?><?php else: ?>–<?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;gap:8px;">
          <div>
            <a href="<?= h(url('admin/customers/txn_list.php?customer_id=' . $customerId)) ?>" class="btn btn-light">
              <?= h(tt('admin.common.back', [], 'Back')) ?>
            </a>
          </div>
          <div>
            <button type="submit" class="btn btn-primary" <?= $totalRemaining <= 0 ? 'disabled' : '' ?>>
              <?= h(tt('admin.txn_allocate_fifo.btn_allocate', [], 'Allocate (FIFO)')) ?>
            </button>
          </div>
        </div>

      </form>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../include/footer.php'; ?>
