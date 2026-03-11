<?php
// public/admin/bank/bank_txn_edit.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
// 你可以改成更细的权限，比如 BANK.TXN.E
if (function_exists('require_perm')) {
    require_perm('BANK.MNG');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

// ---------- 读取 bank_id / txn id ----------
$bankId = (int)($_GET['bank_id'] ?? $_POST['bank_id'] ?? 0);
$txnId  = (int)($_GET['id']      ?? $_POST['id']      ?? 0);
$isNew  = ($txnId <= 0);

// 必须有 bank
if ($bankId <= 0) {
    http_response_code(400);
    exit('Missing bank_id');
}

// 载入 bank
$st = $pdo->prepare("SELECT * FROM company_banks WHERE id = :id");
$st->execute([':id' => $bankId]);
$bank = $st->fetch();

if (!$bank) {
    http_response_code(404);
    exit('Bank account not found');
}

// 读取原有交易
if ($isNew) {
    $txn = [
        'id'          => 0,
        'bank_id'     => $bankId,
        'txn_date'    => date('Y-m-d'),
        'txn_type'    => 'IN',
        'ref_no'      => '',
        'description' => '',
        'amount'      => 0,
        'currency'    => $bank['currency'] ?? 'MYR',
        'rate_to_myr' => $bank['rate_to_myr'] ?? null,
        'amount_myr'  => null,
    ];
} else {
    $st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id AND bank_id = :bid");
    $st->execute([':id' => $txnId, ':bid' => $bankId]);
    $txn = $st->fetch();
    if (!$txn) {
        http_response_code(404);
        exit('Bank transaction not found');
    }
}

// 用来显示 title
$bankLabel = trim(($bank['bank_name'] ?? '') . ' ' . ($bank['account_no'] ?? ''));
if ($bankLabel === '') {
    $bankLabel = 'Bank #' . $bankId;
}

$page_title = $hasT
    ? t('admin.bank.txn.edit.title', [], ($isNew ? 'New Bank Transaction' : 'Edit Bank Transaction') . ' – ' . $bankLabel)
    : (($isNew ? 'New Bank Transaction' : 'Edit Bank Transaction') . ' – ' . $bankLabel);

$errors = [];
$saved  = false;

// ---------- 处理提交 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedId   = (int)($_POST['id'] ?? 0);
    $postedBank = (int)($_POST['bank_id'] ?? 0);
    if ($postedBank !== $bankId) {
        // 防止乱改 bank_id
        $errors['general'] = 'Invalid bank id.';
    } else {
        $date   = trim($_POST['txn_date'] ?? '');
        $type   = trim($_POST['txn_type'] ?? 'IN');
        $refNo  = trim($_POST['ref_no'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $currency = trim($_POST['currency'] ?? ($bank['currency'] ?? 'MYR'));
        $rateToMyrInput = $_POST['rate_to_myr'] ?? null;

        // 基本验证
        if ($date === '') {
            $errors['txn_date'] = 'Date is required.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors['txn_date'] = 'Invalid date format (YYYY-MM-DD).';
        }

        if (!in_array($type, ['IN','OUT'], true)) {
            $errors['txn_type'] = 'Invalid transaction type.';
        }

        if ($amount == 0) {
            $errors['amount'] = 'Amount cannot be zero.';
        }

        // 货币逻辑：如果 currency = MYR，固定 rate=1, amount_myr = amount
        //           否则 rate 用用户填写，amount_myr = amount * rate
        $rateToMyr = null;
        $amountMyr = null;

        if (strtoupper($currency) === 'MYR') {
            $currency  = 'MYR';
            $rateToMyr = 1.0;
            $amountMyr = $amount;
        } else {
            // 外币：需要有 rate
            $rateToMyr = $rateToMyrInput !== null && $rateToMyrInput !== ''
                ? (float)$rateToMyrInput
                : null;

            if ($rateToMyr === null || $rateToMyr <= 0) {
                $errors['rate_to_myr'] = 'Rate to MYR is required and must be > 0 for non-MYR currency.';
            } else {
                $amountMyr = $amount * $rateToMyr;
            }
        }

        if (!$errors) {
            try {
                if ($postedId > 0) {
                    // update
                    $sql = "UPDATE company_bank_txn
                               SET txn_date    = :d,
                                   txn_type    = :t,
                                   ref_no      = :r,
                                   description = :ds,
                                   amount      = :a,
                                   currency    = :cur,
                                   rate_to_myr = :rtm,
                                   amount_myr  = :am
                             WHERE id = :id AND bank_id = :bid";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        ':d'   => $date,
                        ':t'   => $type,
                        ':r'   => $refNo,
                        ':ds'  => $desc,
                        ':a'   => $amount,
                        ':cur' => $currency,
                        ':rtm' => $rateToMyr,
                        ':am'  => $amountMyr,
                        ':id'  => $postedId,
                        ':bid' => $bankId,
                    ]);
                    $txnId = $postedId;
                } else {
                    // insert
                    $sql = "INSERT INTO company_bank_txn
                                (bank_id, txn_date, txn_type, ref_no, description,
                                 amount, currency, rate_to_myr, amount_myr, created_at)
                            VALUES
                                (:bid, :d, :t, :r, :ds, :a, :cur, :rtm, :am, NOW())";
                    $st = $pdo->prepare($sql);
                    $st->execute([
                        ':bid' => $bankId,
                        ':d'   => $date,
                        ':t'   => $type,
                        ':r'   => $refNo,
                        ':ds'  => $desc,
                        ':a'   => $amount,
                        ':cur' => $currency,
                        ':rtm' => $rateToMyr,
                        ':am'  => $amountMyr,
                    ]);
                    $txnId = (int)$pdo->lastInsertId();
                }

                $saved = true;

                // 重新读取
                $st = $pdo->prepare("SELECT * FROM company_bank_txn WHERE id = :id AND bank_id = :bid");
                $st->execute([':id' => $txnId, ':bid' => $bankId]);
                $txn = $st->fetch();

            } catch (Throwable $e) {
                $errors['general'] = 'Save failed: ' . $e->getMessage();
            }
        } else {
            // 保留用户输入
            $txn['txn_date']    = $date;
            $txn['txn_type']    = $type;
            $txn['ref_no']      = $refNo;
            $txn['description'] = $desc;
            $txn['amount']      = $amount;
            $txn['currency']    = $currency;
            $txn['rate_to_myr'] = $rateToMyrInput;
            $txn['amount_myr']  = $amountMyr;
        }
    }
}

// 页面
include __DIR__ . '/../include/header.php';
?>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">

      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow">
            <?= h($hasT ? t('admin.bank.txn.edit.eyebrow', [], 'Company Bank') : 'Company Bank') ?>
          </div>
          <h2 class="form-page-title">
            <?php if ($isNew): ?>
              <?= h($hasT ? t('admin.bank.txn.edit.title_new', [], 'New Bank Transaction') : 'New Bank Transaction') ?>
            <?php else: ?>
              <?= h($hasT ? t('admin.bank.txn.edit.title_edit', [], 'Edit Bank Transaction') : 'Edit Bank Transaction') ?>
            <?php endif; ?>
          </h2>
          <div class="form-page-subtitle">
            <?= h($bankLabel) ?>
          </div>
        </div>
      </div>

      <?php if ($saved): ?>
        <div class="alert-success" style="margin-bottom:10px;">
          <?= h($hasT ? t('admin.bank.txn.edit.saved', [], 'Bank transaction saved.') : 'Bank transaction saved.') ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert-error" style="margin-bottom:10px;">
          <?= h($errors['general']) ?>
        </div>
      <?php endif; ?>

      <form method="post" class="form-layout">
        <input type="hidden" name="id" value="<?= (int)($txn['id'] ?? 0) ?>">
        <input type="hidden" name="bank_id" value="<?= (int)$bankId ?>">

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.bank.txn.edit.section.details', [], 'Transaction details') : 'Transaction details') ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.bank.txn.edit.field.bank', [], 'Bank account') : 'Bank account') ?>
            </label>
            <input type="text" class="form-control" value="<?= h($bankLabel) ?>" disabled>
          </div>

          <div class="form-grid form-grid-2">
            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.bank.txn.edit.field.date', [], 'Date') : 'Date') ?>
                <span class="field-required">*</span>
              </label>
              <input
                type="date"
                name="txn_date"
                class="form-control"
                value="<?= h($txn['txn_date'] ?? date('Y-m-d')) ?>"
              >
              <?php if (!empty($errors['txn_date'])): ?>
                <div class="form-error"><?= h($errors['txn_date']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.bank.txn.edit.field.type', [], 'Type') : 'Type') ?>
                <span class="field-required">*</span>
              </label>
              <select name="txn_type" class="form-control">
                <option value="IN"  <?= ($txn['txn_type'] ?? 'IN') === 'IN'  ? 'selected' : '' ?>>
                  <?= h($hasT ? t('admin.bank.txn.edit.type.in', [], 'IN (money in)') : 'IN (money in)') ?>
                </option>
                <option value="OUT" <?= ($txn['txn_type'] ?? 'IN') === 'OUT' ? 'selected' : '' ?>>
                  <?= h($hasT ? t('admin.bank.txn.edit.type.out', [], 'OUT (money out)') : 'OUT (money out)') ?>
                </option>
              </select>
              <?php if (!empty($errors['txn_type'])): ?>
                <div class="form-error"><?= h($errors['txn_type']) ?></div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.bank.txn.edit.field.ref_no', [], 'Reference no.') : 'Reference no.') ?>
            </label>
            <input
              type="text"
              name="ref_no"
              class="form-control"
              value="<?= h($txn['ref_no'] ?? '') ?>"
            >
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.bank.txn.edit.field.description', [], 'Description') : 'Description') ?>
            </label>
            <textarea
              name="description"
              class="form-control"
              rows="2"
            ><?= h($txn['description'] ?? '') ?></textarea>
          </div>
        </div>

        <div class="form-section">
          <div class="form-section-header">
            <div class="form-section-title">
              <?= h($hasT ? t('admin.bank.txn.edit.section.amount', [], 'Amount & FX') : 'Amount & FX') ?>
            </div>
          </div>

          <div class="form-grid form-grid-3">
            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.bank.txn.edit.field.amount', [], 'Amount') : 'Amount') ?>
                <span class="field-required">*</span>
              </label>
              <input
                type="number"
                step="0.01"
                name="amount"
                class="form-control"
                value="<?= h($txn['amount'] ?? 0) ?>"
              >
              <?php if (!empty($errors['amount'])): ?>
                <div class="form-error"><?= h($errors['amount']) ?></div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.bank.txn.edit.field.currency', [], 'Currency') : 'Currency') ?>
              </label>
              <select name="currency" class="form-control" id="currencySelect">
                <?php
                  $cur = strtoupper((string)($txn['currency'] ?? $bank['currency'] ?? 'MYR'));
                ?>
                <option value="MYR" <?= $cur === 'MYR' ? 'selected' : '' ?>>MYR</option>
                <option value="USD" <?= $cur === 'USD' ? 'selected' : '' ?>>USD</option>
                <option value="USDT"<?= $cur === 'USDT'? 'selected' : '' ?>>USDT</option>
                <option value="SGD" <?= $cur === 'SGD' ? 'selected' : '' ?>>SGD</option>
                <option value="<?= h($cur) ?>" <?= !in_array($cur, ['MYR','USD','USDT','SGD'], true) ? 'selected' : '' ?>>
                  <?= h($cur) ?>
                </option>
              </select>
            </div>

            <div class="form-group">
              <label class="field-label">
                <?= h($hasT ? t('admin.bank.txn.edit.field.rate_to_myr', [], 'Rate to MYR') : 'Rate to MYR') ?>
                <span id="rateRequiredStar" class="field-required" style="<?= $cur === 'MYR' ? 'display:none;' : '' ?>">*</span>
              </label>
              <input
                type="number"
                step="0.00000001"
                name="rate_to_myr"
                id="rateInput"
                class="form-control"
                value="<?= h($txn['rate_to_myr'] ?? $bank['rate_to_myr'] ?? '') ?>"
              >
              <?php if (!empty($errors['rate_to_myr'])): ?>
                <div class="form-error"><?= h($errors['rate_to_myr']) ?></div>
              <?php else: ?>
                <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                  <?= h($hasT
                    ? t('admin.bank.txn.edit.helper.rate', [], 'If currency is not MYR, please fill the rate to convert into MYR.')
                    : 'If currency is not MYR, please fill the rate to convert into MYR.'
                  ) ?>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label class="field-label">
              <?= h($hasT ? t('admin.bank.txn.edit.field.amount_myr', [], 'Amount in MYR (calculated)') : 'Amount in MYR (calculated)') ?>
            </label>
            <input
              type="text"
              id="amountMyrDisplay"
              class="form-control"
              value="<?= ($txn['amount_myr'] !== null ? number_format((float)$txn['amount_myr'], 2) : '') ?>"
              disabled
            >
            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
              <?= h($hasT
                ? t('admin.bank.txn.edit.helper.amount_myr', [], 'Calculated as Amount × Rate to MYR. Saved to amount_myr for reporting.')
                : 'Calculated as Amount × Rate to MYR. Saved to amount_myr for reporting.'
              ) ?>
            </div>
          </div>
        </div>

        <div class="form-footer-row">
          <div class="form-footer-left">
            <a href="<?= h(url('admin/bank/bank_txn_list.php?bank_id=' . $bankId)) ?>"
               class="btn btn-light">
              <?= h($hasT ? t('admin.common.back', [], 'Back') : 'Back') ?>
            </a>
          </div>
          <div class="form-footer-right">
            <button type="submit" class="btn btn-primary">
              <?= h($hasT ? t('admin.bank.txn.edit.save_btn', [], 'Save transaction') : 'Save transaction') ?>
            </button>
          </div>
        </div>

      </form>
    </div>

  </div>
</div>

<script>
// 小 JS：根据 currency 决定 rate 是否必填 & 预览 Amount in MYR
document.addEventListener('DOMContentLoaded', function () {
  const currencySelect = document.getElementById('currencySelect');
  const rateInput      = document.getElementById('rateInput');
  const rateStar       = document.getElementById('rateRequiredStar');
  const amountInput    = document.querySelector('input[name="amount"]');
  const amountMyrDisp  = document.getElementById('amountMyrDisplay');

  function updateRateRequired() {
    if (!currencySelect || !rateInput || !rateStar) return;
    const cur = (currencySelect.value || '').toUpperCase();
    if (cur === 'MYR') {
      rateStar.style.display = 'none';
      if (!rateInput.value) {
        rateInput.value = '1.00000000';
      }
    } else {
      rateStar.style.display = 'inline';
      if (rateInput.value === '1.00000000') {
        rateInput.value = '';
      }
    }
  }

  function updateAmountMyrDisplay() {
    if (!amountInput || !rateInput || !amountMyrDisp) return;
    const amt = parseFloat(amountInput.value || '0');
    const r   = parseFloat(rateInput.value || '0');
    if (!isNaN(amt) && !isNaN(r) && r > 0) {
      const myr = amt * r;
      amountMyrDisp.value = myr.toFixed(2);
    } else {
      amountMyrDisp.value = '';
    }
  }

  if (currencySelect) {
    currencySelect.addEventListener('change', function () {
      updateRateRequired();
      updateAmountMyrDisplay();
    });
  }
  if (rateInput)   rateInput.addEventListener('input', updateAmountMyrDisplay);
  if (amountInput) amountInput.addEventListener('input', updateAmountMyrDisplay);

  updateRateRequired();
  updateAmountMyrDisplay();
});
</script>

<?php include __DIR__ . '/../include/footer.php'; ?>
