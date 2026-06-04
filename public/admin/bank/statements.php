<?php
// public/admin/bank/statements.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('BANK.STMT.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

// translate helper (3 languages)
if (!function_exists('tt')) {
    function tt(string $key, array $vars = [], string $fallback = ''): string {
        if (function_exists('t')) return t($key, $vars, $fallback);
        return $fallback !== '' ? $fallback : $key;
    }
}

$canDelete = function_exists('can') ? can('BANK.STMT.E') : true;

// -----------------------------
// 1) 拿 bank_id + bank 资料（允许 0 = Cash）
// -----------------------------
if (!array_key_exists('bank_id', $_GET)) {
    http_response_code(400);
    exit('Missing bank_id');
}

$bankId = (int)($_GET['bank_id'] ?? 0);
$isCash = ($bankId === 0);

$bank = null;

// ✅ 如果 bank_id=0，先尝试从 DB 读取（你目前 DB 真的有 id=0 Cash 才不会 FK 问题）
if ($isCash) {
    try {
        $st = $pdo->prepare("
            SELECT id, bank_name, bank_code, account_name, account_no, currency
              FROM company_bank_accounts
             WHERE id = 0
             LIMIT 1
        ");
        $st->execute();
        $bank = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $bank = null;
    }

    if (!$bank) {
        // fallback：虚拟 Cash account（仅当 DB 没有 id=0）
        $bank = [
            'id'           => 0,
            'bank_name'    => tt('admin.bank.cash.title', [], 'Cash account'),
            'bank_code'    => 'CASH',
            'account_name' => tt('admin.bank.cash.account_name', [], 'Cash'),
            'account_no'   => '',
            'currency'     => 'MYR',
        ];
    }
} else {
    $st = $pdo->prepare("
        SELECT id, bank_name, bank_code, account_name, account_no, currency
          FROM company_bank_accounts
         WHERE id = :id
         LIMIT 1
    ");
    $st->execute([':id' => $bankId]);
    $bank = $st->fetch();
    if (!$bank) {
        http_response_code(404);
        exit('Bank account not found');
    }
}

$baseCurrency = $bank['currency'] ?: 'MYR';

// -----------------------------
// 2) Opening / Current balance (MYR)
// -----------------------------
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to']   ?? '');
$q         = trim($_GET['q']         ?? '');

$openingMyr = 0.0;
$currentMyr = 0.0;

if ($date_from !== '') {
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(
            CASE WHEN txn_type = 'IN' THEN amount_myr ELSE -amount_myr END
        ), 0) AS bal
        FROM company_bank_txn
        WHERE bank_id = :bid
          AND txn_date < :df
    ");
    $st->execute([':bid' => $bankId, ':df' => $date_from]);
    $openingMyr = (float)($st->fetchColumn() ?? 0);
}

$st = $pdo->prepare("
    SELECT COALESCE(SUM(
        CASE WHEN txn_type = 'IN' THEN amount_myr ELSE -amount_myr END
    ), 0) AS bal
    FROM company_bank_txn
    WHERE bank_id = :bid
");
$st->execute([':bid' => $bankId]);
$currentMyr = (float)($st->fetchColumn() ?? 0);

// -----------------------------
// 3) 上传处理（month + label 自动） + ✅ statement_date
// -----------------------------
$errors      = [];
$upload_ok   = false;
$form_month  = $_POST['month']  ?? '';
$form_label  = $_POST['label']  ?? '';
$form_remark = $_POST['remark'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('app_upload_is_oversized_post') && app_upload_is_oversized_post()) {
        $errors['general'] = function_exists('app_upload_oversized_post_message')
            ? app_upload_oversized_post_message()
            : 'Upload failed: request too large.';
    }

    $month  = trim($_POST['month'] ?? '');
    $label  = trim($_POST['label'] ?? '');
    $remark = trim($_POST['remark'] ?? '');

    if ($month === '' || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        $errors['month'] = tt('admin.bank.stmt.err.month', [], 'Please select a valid month.');
    }

    if ($label === '' && $month !== '' && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $label = date('F Y', strtotime($month . '-01'));
    }

    $period_from = $month !== '' ? $month . '-01' : null;
    $period_to   = $period_from ? date('Y-m-t', strtotime($period_from)) : null;

    // ✅ statement_date：优先用 period_to（该月最后一天），否则 period_from，否则今天
    $statement_date = $period_to ?: ($period_from ?: date('Y-m-d'));

    $file = $_FILES['statement_file'] ?? null;
    if (!$errors && (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
        $errors['file'] = tt('admin.bank.stmt.err.file_required', [], 'Please choose a statement file.');
    }

    if (!$errors && $file) {
        $err  = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $type = $file['type'] ?? '';

        if ($err !== UPLOAD_ERR_OK) {
            $errors['file'] = function_exists('app_upload_error_message')
                ? app_upload_error_message((int)$err, (string)($file['name'] ?? ''))
                : tt('admin.bank.stmt.err.upload', ['code' => (string)$err], 'Upload error (code {code}).');
        } else {
            $allowed = ['application/pdf','image/png','image/jpeg','image/jpg','image/gif'];
            if ($type && !in_array($type, $allowed, true)) {
                $errors['file'] = tt('admin.bank.stmt.err.file_type', [], 'Invalid file type.');
            }
        }
    }

    if (!$errors && $file) {
        try {
            $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safeName = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
            if ($ext !== '') $safeName .= '.' . $ext;

            $destDir = __DIR__ . '/../../../uploads/bank_stmt';
            if (!is_dir($destDir)) @mkdir($destDir, 0777, true);
            $destPath = $destDir . '/' . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                throw new RuntimeException('Failed to move uploaded file.');
            }

            $relPath = '../uploads/bank_stmt/' . $safeName;

            // ✅ 加 statement_date
            $st = $pdo->prepare("
                INSERT INTO company_bank_statements
                    (bank_account_id, statement_date, period_from, period_to, label, remark,
                     file_path, file_name, file_mime, file_size, created_at)
                VALUES
                    (:bank_account_id, :statement_date, :pf, :pt, :label, :remark,
                     :path, :fname, :fmime, :fsize, NOW())
            ");
            $st->execute([
                ':bank_account_id' => $bankId,
                ':statement_date'  => $statement_date,
                ':pf'              => $period_from,
                ':pt'              => $period_to,
                ':label'           => $label,
                ':remark'          => $remark,
                ':path'            => $relPath,
                ':fname'           => $file['name'],
                ':fmime'           => $file['type'],
                ':fsize'           => $file['size'],
            ]);

            $upload_ok   = true;
            $form_month  = '';
            $form_label  = '';
            $form_remark = '';
        } catch (Throwable $e) {
            $errors['general'] = tt('admin.bank.stmt.err.general', [], 'Upload failed: ') . $e->getMessage();
        }
    } else {
        $form_month  = $month;
        $form_label  = $label;
        $form_remark = $remark;
    }
}

// -----------------------------
// 4) 列表 filter + 取 statement 记录
// -----------------------------
$where  = ["bank_account_id = :bid"];
$params = [':bid' => $bankId];

if ($date_from !== '') { $where[] = "period_from >= :df"; $params[':df'] = $date_from; }
if ($date_to !== '')   { $where[] = "period_to <= :dt";   $params[':dt'] = $date_to; }
if ($q !== '') {
    $where[] = "(label LIKE :q OR remark LIKE :q OR file_name LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);

$st = $pdo->prepare("
    SELECT id, bank_account_id, statement_date, period_from, period_to, label, remark,
           file_path, file_name, file_mime, file_size, created_at
      FROM company_bank_statements
     WHERE $whereSql
  ORDER BY period_from DESC, id DESC
");
$st->execute($params);
$rows = $st->fetchAll();

// -----------------------------
// 5) 页面
// -----------------------------
$page_title = tt('admin.bank.stmt.page_title', [], 'Bank statements')
    . ': ' . (!empty($bank['bank_code']) ? $bank['bank_code'] . ' · ' : '')
    . ($bank['account_name'] ?? '');

include __DIR__ . '/../include/header.php';

?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card" style="margin-bottom:18px;">
      <div class="admin-card-header">
        <div>
          <div class="form-page-eyebrow"><?= h(tt('admin.bank.stmt.eyebrow', [], 'BANK STATEMENT')) ?></div>

          <h1 class="page-title">
            <?= h($bank['bank_name']) ?>
            <?php if (!empty($bank['bank_code'])): ?>
              <span style="font-size:13px;color:#6b7280;">(<?= h($bank['bank_code']) ?>)</span>
            <?php endif; ?>
          </h1>

          <div class="form-page-subtitle">
            <?= h($bank['account_name']) ?>
            <?php if (!empty($bank['account_no'])): ?> · <?= h($bank['account_no']) ?><?php endif; ?>
            <?php if (!empty($bank['currency'])): ?> · <?= h($bank['currency']) ?><?php endif; ?>
          </div>
        </div>

        <div>
          <a href="<?= h(url('admin/bank/accounts.php')) ?>" class="btn btn-light">
            ← <?= h(tt('admin.common.back', [], 'Back')) ?>
          </a>
        </div>
      </div>

      <div style="display:flex;flex-wrap:wrap;gap:18px;font-size:13px;margin-top:8px;">
        <div style="min-width:200px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.stmt.opening', [], 'Opening balance')) ?></div>
          <div style="font-size:18px;font-weight:600;margin-top:4px;">MYR <?= number_format($openingMyr, 2) ?></div>
        </div>
        <div style="min-width:200px;">
          <div style="color:#6b7280;"><?= h(tt('admin.bank.stmt.current', [], 'Current balance')) ?></div>
          <div style="font-size:18px;font-weight:700;margin-top:4px;">MYR <?= number_format($currentMyr, 2) ?></div>
        </div>
      </div>
    </div>

    <!-- Upload form -->
    <div class="admin-card" style="margin-bottom:18px;">
      <?php if ($upload_ok): ?>
        <div class="alert-success" style="margin-bottom:10px;"><?= h(tt('admin.bank.stmt.upload_ok', [], 'Statement uploaded.')) ?></div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:10px;"><?= h($errors['general']) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" class="form-layout">
        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title"><?= h(tt('admin.bank.stmt.upload_title', [], 'Upload statement')) ?></div>
          </div>

          <div class="form-grid form-grid-3">
            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.stmt.month', [], 'Month')) ?> *</label>
              <input type="month" name="month" class="form-control" value="<?= h($form_month) ?>">
              <?php if (isset($errors['month'])): ?><div class="form-error"><?= h($errors['month']) ?></div><?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.stmt.label', [], 'Label')) ?> *</label>
              <input type="text" name="label" class="form-control" value="<?= h($form_label) ?>"
                     placeholder="<?= h(tt('admin.bank.stmt.label_ph', [], 'e.g. July 2025')) ?>">
              <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                <?= h(tt('admin.bank.stmt.label_tip', [], 'If empty, system will auto-fill like "July 2025".')) ?>
              </div>
            </div>

            <div class="form-group">
              <label class="field-label"><?= h(tt('admin.bank.stmt.remark', [], 'Remark')) ?></label>
              <input type="text" name="remark" class="form-control" value="<?= h($form_remark) ?>"
                     placeholder="<?= h(tt('admin.bank.stmt.remark_ph', [], 'e.g. Maybank e-statement')) ?>">
            </div>
          </div>

          <div class="form-group">
            <label class="field-label"><?= h(tt('admin.bank.stmt.file', [], 'Statement file')) ?> *</label>
            <input type="file" name="statement_file" class="form-control">
            <?php if (isset($errors['file'])): ?><div class="form-error"><?= h($errors['file']) ?></div><?php endif; ?>
            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
              <?= h(tt('admin.bank.stmt.file_tip', [], 'PDF, PNG, JPG, GIF')) ?>
              <?php if (function_exists('app_upload_limit_label')): ?>
                <?= h(' · Max ' . app_upload_limit_label()) ?>
              <?php endif; ?>
            </div>
          </div>

          <div>
            <button type="submit" class="btn btn-primary"><?= h(tt('admin.bank.stmt.upload_btn', [], 'Upload')) ?></button>
          </div>
        </div>
      </form>
    </div>

    <!-- Filter for list -->
    <div class="admin-card" style="margin-bottom:18px;">
      <form method="get" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;font-size:13px;">
        <input type="hidden" name="bank_id" value="<?= (int)$bankId ?>">

        <?php include __DIR__ . '/../../include/date_range.php'; ?>

        <div>
          <label class="field-label" style="margin-bottom:4px;"><?= h(tt('admin.bank.stmt.search', [], 'Search')) ?></label>
          <input type="text" name="q" class="form-control" style="min-width:220px;"
                 value="<?= h($q) ?>"
                 placeholder="<?= h(tt('admin.bank.stmt.search_ph', [], 'Search label / remark / file')) ?>">
        </div>

        <div style="margin-left:auto;display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary"><?= h(tt('admin.common.apply', [], 'Apply')) ?></button>
          <a href="<?= h(url('admin/bank/statements.php?bank_id='.(int)$bankId)) ?>" class="btn btn-light"><?= h(tt('admin.common.reset', [], 'Reset')) ?></a>
        </div>
      </form>
    </div>

    <!-- Statement list -->
    <div class="admin-card">
      <table class="table">
        <thead>
          <tr>
            <th style="width:160px;"><?= h(tt('admin.bank.stmt.col.month', [], 'Month')) ?></th>
            <th><?= h(tt('admin.bank.stmt.col.label', [], 'Label')) ?></th>
            <th><?= h(tt('admin.bank.stmt.col.remark', [], 'Remark')) ?></th>
            <th style="width:260px;"><?= h(tt('admin.bank.stmt.col.file', [], 'File')) ?></th>
            <th style="width:80px;"><?= h(tt('admin.bank.stmt.col.size', [], 'Size')) ?></th>
            <th style="width:150px;"><?= h(tt('admin.bank.stmt.col.uploaded_at', [], 'Uploaded at')) ?></th>
            <th style="width:70px;" class="table-actions-cell"><?= h(tt('admin.common.actions', [], 'Actions')) ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr>
            <td colspan="7" style="padding:16px;font-size:13px;color:#6b7280;">
              <?= h(tt('admin.bank.stmt.empty', [], 'No statements yet.')) ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $s): ?>
            <?php
              if (!empty($s['label'])) $monthLabel = $s['label'];
              elseif (!empty($s['period_from'])) $monthLabel = date('F Y', strtotime($s['period_from']));
              else $monthLabel = '';
            ?>
            <tr>
              <td><?= h($monthLabel) ?></td>
              <td><?= h($s['label'] ?? '') ?></td>
              <td><?= h($s['remark'] ?? '') ?></td>
              <td>
                <a href="<?= h(url($s['file_path'])) ?>" target="_blank" style="text-decoration:underline;">
                  <?= h($s['file_name']) ?>
                </a>
              </td>
              <td>
                <?php if (!empty($s['file_size'])): ?>
                  <?= number_format((int)$s['file_size'] / 1024, 1) ?> KB
                <?php else: ?>–<?php endif; ?>
              </td>
              <td><?= h($s['created_at']) ?></td>
              <td class="table-actions-cell">
                <div class="actions-menu">
                  <button type="button" class="actions-menu-trigger" aria-expanded="false">⋯</button>
                  <div class="actions-menu-dropdown">
                    <a href="<?= h(url($s['file_path'])) ?>" target="_blank" class="actions-menu-item">
                      <?= h(tt('admin.common.view', [], 'View')) ?>
                    </a>

                    <?php if ($canDelete): ?>
                      <a href="<?= h(url('admin/bank/statement_delete.php?id='.(int)$s['id'].'&bank_id='.(int)$bankId)) ?>"
                         class="actions-menu-item"
                         onclick="return confirm('<?= h(tt('admin.bank.stmt.confirm_delete', [], 'Delete this statement?')) ?>');">
                        <?= h(tt('admin.common.delete', [], 'Delete')) ?>
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
