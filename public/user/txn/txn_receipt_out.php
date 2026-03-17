<?php
// public/user/txn/txn_receipt_out.php
// 客户端查看 OUT 类型收据并签名（只允许客户自己签名一次，不可签公司）
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
require_once __DIR__ . '/../../../config/i18n.php';
require_once __DIR__ . '/../../../app/txn_sign_status.php';
require_login();

$u = current_user();
if (($u['role'] ?? '') !== 'CUSTOMER') {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
  function h($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
  }
}

function amount_to_words_simple(float $amount, string $currencyCode): string
{
  $currencyCode = strtoupper(trim($currencyCode ?: 'MYR'));

  $units = [
    0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four',
    5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
    10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen',
    14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
    18 => 'Eighteen', 19 => 'Nineteen'
  ];
  $tens = [2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'];
  $scales = [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 100 => 'Hundred'];

  $prefix = 'Ringgit Malaysia';
  $unitWord = 'Ringgit';
  $centWord = 'Sen';

  if ($currencyCode === 'USD') {
    $prefix = 'United States Dollar'; $unitWord = 'Dollar'; $centWord = 'Cent';
  } elseif ($currencyCode === 'SGD') {
    $prefix = 'Singapore Dollar'; $unitWord = 'Dollar'; $centWord = 'Cent';
  } elseif ($currencyCode !== 'MYR') {
    $prefix = $currencyCode; $unitWord = 'Unit'; $centWord = 'Cent';
  }

  $amount  = round($amount, 2);
  $whole   = (int)$amount;
  $decimal = (int)round(($amount - $whole) * 100);

  $toWords = function ($n) use ($units, $tens, $scales, &$toWords): string {
    if ($n < 20) return $units[$n];
    if ($n < 100) {
      $t = intdiv($n, 10);
      $r = $n % 10;
      return $tens[$t] . ($r ? ' ' . $units[$r] : '');
    }
    foreach ($scales as $value => $name) {
      if ($n >= $value) {
        $count = intdiv($n, $value);
        $rem   = $n % $value;
        $res   = $toWords($count) . ' ' . $name;
        if ($rem) $res .= $rem < 100 ? ' and ' . $toWords($rem) : ' ' . $toWords($rem);
        return $res;
      }
    }
    return '';
  };

  $parts = [];
  if ($whole > 0) $parts[] = $toWords($whole) . ' ' . $unitWord;
  if ($decimal > 0) $parts[] = $toWords($decimal) . ' ' . $centWord;
  if (!$parts) $parts[] = 'Zero ' . $unitWord;

  return $prefix . ' ' . implode(' and ', $parts) . ' Only';
}

$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Missing transaction id');
}

// back：来自 GET/POST，默认回 txns 列表
$rawBack = (string)($_GET['back'] ?? $_POST['back'] ?? '');
if ($rawBack === '') {
  $rawBack = url('user/txn/txns.php');
}

// 载入 txn（限定当前登录 customer，且必须是 OUT）
$st = $pdo->prepare("
  SELECT *
    FROM customer_txn
   WHERE id = :id
     AND customer_id = :cid
     AND txn_type = 'OUT'
   LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch(PDO::FETCH_ASSOC);
if (!$txn) {
  http_response_code(404);
  exit('Transaction not found');
}

$isContra = ((int)($txn['is_contra'] ?? 0) === 1);
$needSignature = (
  !$isContra &&
  (int)($txn['require_signature'] ?? 0) === 1
);

// 客户端只能签自己的那一边：OUT 的 customer 对应 receiver_* / signature_image
$existingSig = (string)($txn['signature_image'] ?? '');
$alreadySigned = ($existingSig !== '');

$errors = [];

// POST: 保存客户签名（只能一次）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_sign') {
  if (!$needSignature) {
    $errors['signature'] = t('cust.txn.sign.error_not_required', [], 'Signature is not required for this receipt.');
  } elseif ($alreadySigned) {
    $errors['signature'] = t('cust.txn.sign.error_already', [], 'Signature was already captured and cannot be changed.');
  } else {
    $sig = (string)($_POST['receiver_signature'] ?? '');
    if (strpos($sig, 'data:image/png;base64,') !== 0) {
      $errors['signature'] = t('cust.txn.sign.error_sign_box', [], 'Please sign inside the signature box before saving.');
    } else {
      // 写入客户签名，只更新 receiver 一侧
      $stUp = $pdo->prepare("
        UPDATE customer_txn
           SET signature_image = :sig,
               updated_at      = NOW()
         WHERE id = :id
           AND customer_id = :cid
           AND signature_image IS NULL
           OR (id = :id AND customer_id = :cid AND signature_image = '')
      ");
      $stUp->execute([':sig' => $sig, ':id' => $id, ':cid' => $cid]);

      // 调用 helper：标记 recipient 已签名，并根据规则自动 CONFIRMED（不会动金额）
      txn_mark_signed_and_maybe_confirm($pdo, $id, 'recipient');

      header('Location: ' . url('user/txn/txn_receipt_out.php?id=' . $id . '&back=' . rawurlencode($rawBack)));
      exit;
    }
  }
}

// 重新读取最新 txn（防止刚刚 helper 修改状态）
$st = $pdo->prepare("
  SELECT *
    FROM customer_txn
   WHERE id = :id
     AND customer_id = :cid
     AND txn_type = 'OUT'
   LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch(PDO::FETCH_ASSOC) ?: $txn;
$existingSig = (string)($txn['signature_image'] ?? '');
$alreadySigned = ($existingSig !== '');

// 载入 customer（用于名称等）
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch(PDO::FETCH_ASSOC);

$amount = (float)($txn['amount'] ?? 0.0);
$currency = (string)($txn['currency'] ?? ($customer['currency'] ?? 'MYR'));
$amountStr = $currency . ' ' . number_format($amount, 2);
$amountWords = amount_to_words_simple($amount, $currency);

$txnDate = (string)($txn['txn_date'] ?? substr((string)($txn['created_at'] ?? ''), 0, 10));
$title = (string)($txn['title'] ?? '');
if ($title === '') $title = 'Payment / Receipt';

$page_title = 'Receipt · #' . $id;
$active_nav = 'txns';

include __DIR__ . '/../include/header.php';
?>

<style>
.receipt-box {
  border:1px solid #e5e7eb;
  border-radius:16px;
  padding:18px 22px;
  background:#fff;
  font-size:13px;
  color:#111827;
}
.sig-canvas {
  width:100%;
  max-width:100%;
  height:180px;
  border-radius:10px;
  background:#f9fafb;
  border:1px dashed #d1d5db;
  touch-action:none;
}
</style>

<div class="admin-main">
  <div class="admin-main-inner">

    <div class="admin-card admin-card-elevated admin-card-narrow">
      <div class="form-page-header">
        <div>
          <div class="form-page-eyebrow"><?= h(t('cust.txn.receipt.eyebrow', [], 'Receipt')) ?></div>
          <h2 class="form-page-title"><?= h($customer['name'] ?? '') ?></h2>
          <div class="form-page-subtitle">
            Txn #<?= (int)$txn['id'] ?> · <?= h($amountStr) ?>
          </div>
        </div>
        <div class="form-page-meta" style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
          <span class="badge-soft"><?= h(strtoupper((string)($txn['status'] ?? ''))) ?></span>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
            <a href="<?= h($rawBack) ?>" class="btn btn-light btn-sm"><?= h(t('portal.common.back', [], 'Back')) ?></a>
            <button type="button" class="btn btn-light btn-sm" onclick="window.print();">Print</button>
          </div>
        </div>
      </div>

      <?php if ($isContra): ?>
        <div class="alert-success" style="margin-bottom:10px;">
          <?= h(t('cust.txn.receipt.contra_info', [], 'This entry was created by allocation (contra). No customer signature is required.')) ?>
        </div>
      <?php elseif (!$needSignature): ?>
        <div class="alert-info" style="margin-bottom:10px;">
          <?= h(t('cust.txn.receipt.no_sign_needed', [], 'This receipt does not require your signature.')) ?>
        </div>
      <?php elseif ($alreadySigned): ?>
        <div class="alert-success" style="margin-bottom:10px;">
          <?= h(t('cust.txn.receipt.signed', [], 'Your signature has been recorded.')) ?>
        </div>
      <?php endif; ?>

      <div class="receipt-box" id="receipt-print-area">
        <div style="text-align:center;font-size:16px;font-weight:600;letter-spacing:0.12em;margin-bottom:16px;">
          <?= h(t('cust.txn.receipt.title', [], 'RECEIPT')) ?>
        </div>

        <p style="margin-bottom:4px;">
          <strong><?= h(t('txn.view.field.date', [], 'Date')) ?>:</strong>
          <?= ' ' . h($txnDate) ?>
        </p>

        <p style="margin-top:10px;margin-bottom:4px;">
          <strong><?= h(t('admin.customer_txn.view.received_from', [], 'Received from (Payer):')) ?></strong><br>
          <?= h($customer['name'] ?? '') ?>
          <?php if (!empty($customer['reg_no'])): ?>
            (<?= h($customer['reg_no']) ?>)
          <?php endif; ?>
        </p>

        <p style="margin-top:10px;margin-bottom:4px;">
          <strong><?= h(t('admin.customer_txn.field.title', [], 'Title')) ?>:</strong>
          <?= ' ' . h($title) ?>
        </p>

        <p style="margin-top:10px;margin-bottom:4px;">
          <strong><?= h(t('admin.customer_txn.field.amount', [], 'Amount')) ?>:</strong>
          <?= ' ' . h($amountStr) ?><br>
          (<?= h($amountWords) ?>)
        </p>

        <p style="margin-top:10px;margin-bottom:16px;">
          <?= h(t('admin.customer_txn.view.receipt_confirm', [], 'This receipt confirms the above amount has been received.')) ?>
        </p>

        <?php if ($existingSig !== ''): ?>
          <div style="margin-top:20px;">
            <div style="font-weight:600;margin-bottom:6px;">
              <?= h(t('cust.txn.receipt.customer_sig_label', [], 'Your signature')) ?>
            </div>
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:8px;display:flex;justify-content:center;align-items:center;min-height:120px;">
              <img src="<?= h($existingSig) ?>" alt="Signature" style="max-width:100%;max-height:200px;object-fit:contain;">
            </div>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($needSignature && !$alreadySigned && !$isContra): ?>
        <div class="form-section no-print" style="margin-top:18px;">
          <div class="form-section-header">
            <div>
              <div class="form-section-title"><?= h(t('cust.txn.sign.sign_here_title', [], 'Sign here')) ?></div>
              <div class="form-section-desc"><?= h(t('cust.txn.sign.sign_here_desc', [], 'Please sign inside the box below.')) ?></div>
            </div>
          </div>

          <?php if (!empty($errors['signature'])): ?>
            <div class="alert-error" style="margin-bottom:8px;"><?= h($errors['signature']) ?></div>
          <?php endif; ?>

          <form method="post" id="sign-form">
            <input type="hidden" name="_action" value="save_sign">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="back" value="<?= h($rawBack) ?>">
            <input type="hidden" name="receiver_signature" id="receiver_signature">

            <canvas id="sig-customer" class="sig-canvas"></canvas>
            <button type="button" class="btn btn-light btn-sm" id="btn-clear-customer" style="margin-top:6px;">
              <?= h(t('cust.common.btn.clear', [], 'Clear')) ?>
            </button>

            <div style="margin-top:12px;display:flex;justify-content:space-between;align-items:center;gap:8px;">
              <a href="<?= h($rawBack) ?>" class="btn btn-light btn-sm"><?= h(t('portal.common.back', [], 'Back')) ?></a>
              <button type="submit" class="btn btn-primary btn-sm">
                <?= h(t('cust.txn.sign.btn_save', [], 'Save signature')) ?>
              </button>
            </div>
          </form>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php if ($needSignature && !$alreadySigned && !$isContra): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('sign-form');
  if (!form) return;

  const signRequiredMsg = <?= json_encode(t('cust.txn.sign.error_sign_box', [], 'Please sign inside the signature box before saving.')) ?>;

  function setupPad(canvasId, clearBtnId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;

    const ctx = canvas.getContext('2d');

    function resizeCanvasKeep() {
      const rect = canvas.getBoundingClientRect();

      const temp = document.createElement('canvas');
      temp.width = canvas.width;
      temp.height = canvas.height;
      temp.getContext('2d').drawImage(canvas, 0, 0);

      canvas.width  = rect.width;
      canvas.height = rect.height;

      ctx.clearRect(0, 0, canvas.width, canvas.height);
      ctx.drawImage(temp, 0, 0, temp.width, temp.height, 0, 0, canvas.width, canvas.height);
    }

    resizeCanvasKeep();
    window.addEventListener('resize', resizeCanvasKeep);

    let drawing=false, lastX=0, lastY=0, hasDrawn=false;

    function getPos(e) {
      const rect = canvas.getBoundingClientRect();
      if (e.touches && e.touches.length > 0) {
        return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
      }
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function startDraw(e){ e.preventDefault(); drawing=true; const p=getPos(e); lastX=p.x; lastY=p.y; }
    function draw(e){
      if(!drawing) return;
      e.preventDefault();
      const p=getPos(e);
      ctx.strokeStyle='#111827';
      ctx.lineWidth=2;
      ctx.lineCap='round';
      ctx.beginPath(); ctx.moveTo(lastX,lastY); ctx.lineTo(p.x,p.y); ctx.stroke();
      lastX=p.x; lastY=p.y; hasDrawn=true;
    }
    function endDraw(e){ if(!drawing) return; e.preventDefault(); drawing=false; }

    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);

    canvas.addEventListener('touchstart', startDraw, { passive:false });
    canvas.addEventListener('touchmove',  draw,      { passive:false });
    canvas.addEventListener('touchend',   endDraw,   { passive:false });
    canvas.addEventListener('touchcancel',endDraw,   { passive:false });

    const clearBtn = document.getElementById(clearBtnId);
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        hasDrawn = false;
      });
    }

    return {
      hasDrawn:()=>hasDrawn,
      getImage:()=>hasDrawn ? canvas.toDataURL('image/png') : ''
    };
  }

  const customerPad = setupPad("sig-customer","btn-clear-customer");

  form.addEventListener('submit', function (e) {
    if (!customerPad || !customerPad.hasDrawn()) {
      e.preventDefault();
      alert(signRequiredMsg);
      return;
    }
    document.getElementById('receiver_signature').value = customerPad.getImage();
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../include/footer.php'; ?>

