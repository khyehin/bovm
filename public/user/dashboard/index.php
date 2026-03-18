<?php
// public/user/dashboard/index.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';
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

// 当前登录用户的 customer_id
$cid = (int)($u['customer_id'] ?? 0);
if ($cid <= 0) {
  http_response_code(400);
  exit('Missing customer_id for this user');
}

// 取 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
  http_response_code(404);
  exit('Customer not found');
}

$currency = $customer['currency'] ?? 'MYR';

/**
 * ✅ Summary（照 admin txn_list.php 的算法）
 * total_in_normal  = IN, in_kind 非 BONUS/RETURN, (amount - allocated_amount)
 * total_out_normal = OUT, 非 contra, out_kind != LOAN, amount
 * bonus_total      = IN,  in_kind = BONUS,  (amount - allocated_amount)
 * repay_total      = IN,  in_kind = RETURN, (amount - allocated_amount) 或 title fallback
 * loan_total       = OUT, out_kind = LOAN, 非 contra, amount
 */
$sumSql = "SELECT
              SUM(CASE
                    WHEN txn_type = 'IN'
                     AND UPPER(COALESCE(in_kind,'')) NOT IN ('BONUS','RETURN')
                    THEN (amount - COALESCE(allocated_amount,0))
                    ELSE 0
                  END) AS total_in_normal,

              SUM(CASE
                    WHEN txn_type = 'OUT'
                     AND (is_contra IS NULL OR is_contra = 0)
                     AND UPPER(COALESCE(out_kind,'')) <> 'LOAN'
                    THEN amount
                    ELSE 0
                  END) AS total_out_normal,

              SUM(CASE
                    WHEN txn_type = 'IN'
                     AND UPPER(COALESCE(in_kind,'')) = 'BONUS'
                    THEN (amount - COALESCE(allocated_amount,0))
                    ELSE 0
                  END) AS bonus_total,

              SUM(CASE
                    WHEN txn_type = 'IN'
                     AND (
                          UPPER(COALESCE(in_kind,'')) = 'RETURN'
                          OR (
                               (COALESCE(order_total,0) = 0)
                               AND (amount > 0)
                               AND (
                                     title LIKE '%Repayment%' OR title LIKE '%repayment%'
                                     OR title LIKE '%Return%' OR title LIKE '%return%'
                                   )
                             )
                        )
                    THEN (amount - COALESCE(allocated_amount,0))
                    ELSE 0
                  END) AS repay_total,

              SUM(CASE
                    WHEN txn_type = 'OUT'
                     AND (is_contra IS NULL OR is_contra = 0)
                     AND UPPER(COALESCE(out_kind,'')) = 'LOAN'
                    THEN amount
                    ELSE 0
                  END) AS loan_total

           FROM customer_txn
           WHERE customer_id = :cid";

$st = $pdo->prepare($sumSql);
$st->execute([':cid' => $cid]);
$sumRow = $st->fetch() ?: [
  'total_in_normal'  => 0,
  'total_out_normal' => 0,
  'bonus_total'      => 0,
  'repay_total'      => 0,
  'loan_total'       => 0,
];

$total_in_normal  = (float)($sumRow['total_in_normal']  ?? 0);
$total_out_normal = (float)($sumRow['total_out_normal'] ?? 0);
$bonus_total      = (float)($sumRow['bonus_total']      ?? 0);
$repay_total      = (float)($sumRow['repay_total']      ?? 0);
$loan_total       = (float)($sumRow['loan_total']       ?? 0);

// admin：net_normal = IN - OUT
$net_normal = $total_in_normal - $total_out_normal;

// admin：summary totals
$summary_in_admin  = $total_in_normal + $bonus_total + $repay_total;
$summary_out_admin = $total_out_normal + $loan_total;
$summary_net_admin = $summary_in_admin - $summary_out_admin;

/**
 * ✅ User 视角（反方显示）：
 * - admin IN 代表客户 OUT（你付我们）
 * - admin OUT 代表客户 IN（我们付你）
 */
$user_total_out = $total_in_normal;   // 你付我们（normal）
$user_total_in  = $total_out_normal;  // 我们付你（normal）
$user_balance   = $net_normal;        // OUT - IN（normal）

$user_summary_out = $summary_in_admin;     // 你付我们（含 bonus/repay）
$user_summary_in  = $summary_out_admin;    // 我们付你（含 loan）
$user_summary_net = $summary_net_admin;    // OUT - IN（summary）

/*
 * Pending signatures（客户视角）：
 * - 只显示「客户自己还没签」的单
 * - 签完后即从 pendingSign 消失（不再只依赖 status）
 */
$st = $pdo->prepare("
    SELECT *
      FROM customer_txn
     WHERE customer_id = :cid
       AND (is_contra IS NULL OR is_contra = 0)
       AND require_signature = 1
     ORDER BY txn_date ASC, id ASC
     LIMIT 10
");
$st->execute([':cid' => $cid]);
$pendingCandidates = $st->fetchAll() ?: [];

$pendingSign = [];
if ($pendingCandidates) {
  $inTxnIds = [];
  foreach ($pendingCandidates as $r) {
    if (($r['txn_type'] ?? '') === 'IN') $inTxnIds[] = (int)($r['id'] ?? 0);
  }
  $inTxnIds = array_values(array_unique(array_filter($inTxnIds)));

  $lastPayByTxn = [];
  if ($inTxnIds) {
    $in = implode(',', array_fill(0, count($inTxnIds), '?'));
    $stp = $pdo->prepare("
      SELECT p.*
      FROM customer_txn_payments p
      JOIN (
        SELECT customer_txn_id, MAX(id) AS max_id
        FROM customer_txn_payments
        WHERE customer_txn_id IN ($in)
        GROUP BY customer_txn_id
      ) x ON x.max_id = p.id
    ");
    $stp->execute($inTxnIds);
    foreach ($stp->fetchAll() as $p) {
      $tid = (int)($p['customer_txn_id'] ?? 0);
      if ($tid > 0) $lastPayByTxn[$tid] = $p;
    }
  }

  foreach ($pendingCandidates as $r) {
    $txnType = strtoupper((string)($r['txn_type'] ?? ''));
    $signReceive = (int)($r['sign_receive'] ?? 0);
    $signPayer   = (int)($r['sign_payer'] ?? 0);
    $legacyBoth  = ($signReceive === 0 && $signPayer === 0);
    $needCusSign = ($signPayer === 1) || $legacyBoth;

    // 客户这边本来就不需要签 -> 不应出现在 pending signatures
    if (!$needCusSign) continue;

    $cusSigned = false;
    if ($txnType === 'IN') {
      $tid = (int)($r['id'] ?? 0);
      $lp = $lastPayByTxn[$tid] ?? null;
      if ($lp) {
        // 有 payment：看最后一张收据上客户是否已签
        $cusSigned = !empty($lp['payer_signature_image'] ?? '') || !empty($lp['payer_signed_at'] ?? '');
      } else {
        // 没有 payment：这是纯 quotation 场景，客户签名在单据本身
        $cusSigned = !empty($r['quotation_customer_signature_image'] ?? '') || !empty($r['recipient_signed_at'] ?? '');
      }
    } else {
      // OUT：客户签名在 txn.signature_image / recipient_signed_at
      $cusSigned = !empty($r['signature_image'] ?? '') || !empty($r['recipient_signed_at'] ?? '');
    }

    if (!$cusSigned) $pendingSign[] = $r;
  }
}

// 最近 10 条 payout 给客户（admin OUT -> customer IN）
$st = $pdo->prepare("
    SELECT *
    FROM customer_txn
    WHERE customer_id = :cid
      AND txn_type = 'OUT'
      AND (is_contra IS NULL OR is_contra = 0)
    ORDER BY txn_date DESC, id DESC
    LIMIT 10
");
$st->execute([':cid' => $cid]);
$recentIn = $st->fetchAll();

// 最近 10 条客户付款（admin IN -> customer OUT）
$st = $pdo->prepare("
    SELECT *
    FROM customer_txn
    WHERE customer_id = :cid
      AND txn_type = 'IN'
      AND (is_contra IS NULL OR is_contra = 0)
    ORDER BY txn_date DESC, id DESC
    LIMIT 10
");
$st->execute([':cid' => $cid]);
$recentOut = $st->fetchAll();

$page_title = t('portal.header.app_title', [], 'Customer Portal');
$active_nav = 'dashboard';

include __DIR__ . '/../include/header.php';
?>

<?php if ($pendingSign): ?>
  <div class="admin-card admin-card-elevated" style="margin-bottom: 20px;">
    <div class="admin-card-header">
      <div>
        <div class="form-page-eyebrow"><?= h(t('portal.dashboard.pending_eyebrow', [], 'Pending')) ?></div>
        <div class="form-page-title"><?= h(t('portal.dashboard.pending_title', [], 'Pending signatures')) ?></div>
        <div class="form-page-subtitle">
          <?= h(t('portal.dashboard.pending_subtitle', [], 'These invoices and payouts are waiting for your signature.')) ?>
        </div>
      </div>
      <div>
        <a href="<?= h(url('user/txn/txns.php?filter=pending')) ?>" class="btn btn-light">
          <?= h(t('portal.dashboard.pending_view_all', [], 'View all pending')) ?>
        </a>
      </div>
    </div>

    <table class="table">
      <thead>
        <tr>
          <th style="width:110px;"><?= h(t('portal.dashboard.table.date', [], 'Date')) ?></th>
          <th style="width:110px;"><?= h(t('portal.dashboard.table.type', [], 'Type')) ?></th>
          <th><?= h(t('portal.dashboard.table.title', [], 'Title')) ?></th>
          <th style="width:120px;text-align:right;"><?= h(t('portal.dashboard.table.amount', [], 'Amount')) ?></th>
          <th style="width:90px;"><?= h(t('portal.dashboard.table.status', [], 'Status')) ?></th>
          <th style="width:90px;"><?= h(t('portal.dashboard.table.actions', [], 'Actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingSign as $r): ?>
          <?php
            $amount  = (float)$r['amount'];
            $rowDate = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);
            $ccy     = $r['currency'] ?: $currency;

            if (($r['txn_type'] ?? '') === 'OUT') {
              $typeLabel   = t('portal.dashboard.pending_type_receipt', [], 'Receipt (we pay you)');
              $typeColor   = '#166534';
              $amountColor = '#166534';
              $signUrl     = url('user/txn/txn_sign.php?id=' . (int)$r['id']);
            } else {
              $typeLabel   = t('portal.dashboard.pending_type_invoice', [], 'Invoice (you pay us)');
              $typeColor   = '#b91c1c';
              $amountColor = '#b91c1c';
              // IN 类型：若还没有任何 payment，则先让客户在 QUOTATION 文档上签名；
              // 只有有 payment 时才进入收据签名页。
              $tidForSign = (int)($r['id'] ?? 0);
              $hasPayment = false;
              if ($tidForSign > 0) {
                try {
                  $stPayChk = $pdo->prepare("SELECT 1 FROM customer_txn_payments WHERE customer_txn_id = :tid LIMIT 1");
                  $stPayChk->execute([':tid' => $tidForSign]);
                  $hasPayment = (bool)$stPayChk->fetchColumn();
                } catch (Throwable $e) {
                  $hasPayment = false;
                }
              }
              if ($hasPayment) {
                $signUrl = url('user/txn/txn_invoice_in.php?id=' . $tidForSign);
              } else {
                $signUrl = url('user/txn/txn_doc_in.php?id=' . $tidForSign . '&customer_id=' . (int)$cid . '&doc=QUOTATION');
              }

              // pending 列表金额：若已有 payment，则显示「最后一张收据金额」而不是累计金额
              $lp = $lastPayByTxn[$tidForSign] ?? null;
              if ($lp) {
                $amount = (float)($lp['amount'] ?? $amount);
                $ccy = (string)($lp['currency'] ?? '') !== '' ? (string)$lp['currency'] : $ccy;
              }
            }
          ?>
          <tr>
            <td><?= h($rowDate) ?></td>
            <td><span style="font-size:12px;font-weight:600;color:<?= h($typeColor) ?>;"><?= h($typeLabel) ?></span></td>
            <td>
              <div style="font-size:13px;font-weight:500;"><?= h($r['title'] ?: (($r['txn_type'] ?? '') === 'OUT' ? 'Payout' : 'Invoice')) ?></div>
              <?php if (!empty($r['ref_no'])): ?><div style="font-size:11px;color:#6b7280;">Ref: <?= h($r['ref_no']) ?></div><?php endif; ?>
            </td>
            <td style="text-align:right; font-weight:600; color:<?= h($amountColor) ?>;"><?= h($ccy) ?> <?= number_format($amount, 2) ?></td>
            <?php
              $rawStatus = (string)($r['status'] ?? '');
              $flowStat  = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
              if ($flowStat === 'REJECTED') {
                $displayStatus = 'REJECTED';
              } else {
                $displayStatus = strtoupper($rawStatus ?: 'DRAFT');
              }
            ?>
            <td>
              <?php if ($displayStatus === 'SENT'): ?>
                <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fef9c3;color:#854d0e;"><?= h(t('portal.status.sent', [], 'SENT')) ?></span>
              <?php elseif ($displayStatus === 'CONFIRMED'): ?>
                <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#ecfdf5;color:#166534;"><?= h(t('portal.status.confirmed', [], 'CONFIRMED')) ?></span>
              <?php elseif ($displayStatus === 'REJECTED'): ?>
                <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fee2e2;color:#b91c1c;">REJECTED</span>
              <?php else: ?>
                <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#e5e7eb;color:#374151;"><?= h(t('portal.status.draft', [], 'DRAFT')) ?></span>
              <?php endif; ?>
            </td>
            <td><a href="<?= h($signUrl) ?>" class="btn btn-primary" style="font-size:12px;padding:5px 10px;"><?= h(t('portal.dashboard.pending_sign_btn', [], 'Sign')) ?></a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="admin-card admin-card-elevated" style="margin-bottom: 20px;">
  <div class="admin-card-header">
    <div>
      <div class="form-page-eyebrow"><?= h(t('portal.dashboard.customer_eyebrow', [], 'Customer')) ?></div>
      <h1 class="page-title">
        <?= h($customer['name']) ?>
        <?php if (!empty($customer['code'])): ?>
          <span style="font-size:13px;font-weight:500;color:#6b7280;">(<?= h($customer['code']) ?>)</span>
        <?php endif; ?>
      </h1>
      <div class="form-page-subtitle"><?= h(t('portal.dashboard.customer_subtitle', [], 'Overall summary of your transactions and balance.')) ?></div>
    </div>
  </div>
</div>

<!-- ✅ Summary 卡片：结构跟 admin txn_list 一样 -->
<div class="admin-card" style="margin-bottom: 20px;">
  <div class="admin-card-header" style="margin-bottom:16px;">
    <div>
      <div class="form-page-eyebrow"><?= h(t('portal.dashboard.summary_eyebrow', [], 'Summary')) ?></div>
      <div class="form-page-title" style="font-size:18px;">
        <?= h(t('portal.dashboard.summary_title', [], 'After contra (all figures)')) ?>
      </div>
    </div>
  </div>

  <!-- 行 1：total out / total in / balance -->
  <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">

    <div style="min-width:170px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row1.total_out', [], 'Total OUT (you paid us)')) ?></div>
      <div style="font-size:18px;font-weight:600;margin-top:4px;">
        <?= h($currency) ?> <?= number_format($user_total_out, 2) ?>
      </div>
    </div>

    <div style="min-width:170px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row1.total_in', [], 'Total IN (we paid you)')) ?></div>
      <div style="font-size:18px;font-weight:600;margin-top:4px;">
        <?= h($currency) ?> <?= number_format($user_total_in, 2) ?>
      </div>
    </div>

    <div style="min-width:210px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row1.balance', [], 'Balance (OUT - IN)')) ?></div>

      <?php if ($user_balance > 0): ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;">
          <?= h($currency) ?> <?= number_format($user_balance, 2) ?>
        </div>
        <div style="font-size:12px;color:#b91c1c;"><?= h(t('portal.dashboard.summary_we_owe', [], 'We owe you')) ?></div>
      <?php elseif ($user_balance < 0): ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;">
          <?= h($currency) ?> <?= number_format(abs($user_balance), 2) ?>
        </div>
        <div style="font-size:12px;color:#166534;"><?= h(t('portal.dashboard.summary_you_owe', [], 'You owe us')) ?></div>
      <?php else: ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">
          <?= h($currency) ?> 0.00
        </div>
        <div style="font-size:12px;color:#6b7280;"><?= h(t('portal.dashboard.summary_balanced', [], 'Balanced')) ?></div>
      <?php endif; ?>
    </div>

  </div>

  <!-- 行 2：repayment / bonus -->
  <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px; margin-bottom:12px;">

    <div style="min-width:210px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row2.repayment', [], 'Repayment')) ?></div>
      <!-- repayment 在你的规则里就是 repay_total -->
      <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">
        <?= h($currency) ?> <?= number_format($repay_total, 2) ?>
      </div>
    </div>

    <div style="min-width:210px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row2.bonus', [], 'Bonus')) ?></div>
      <!-- bonus 颜色：照 admin（黑） -->
      <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">
        <?= h($currency) ?> <?= number_format($bonus_total, 2) ?>
      </div>
    </div>

  </div>

  <!-- 行 3：summary in/out/net -->
  <div style="display:flex; gap:18px; flex-wrap:wrap; font-size:13px;">

    <div style="min-width:170px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row3.summary_out', [], 'Summary total OUT')) ?></div>
      <div style="font-size:18px;font-weight:600;margin-top:4px;">
        <?= h($currency) ?> <?= number_format($user_summary_out, 2) ?>
      </div>
    </div>

    <div style="min-width:170px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row3.summary_in', [], 'Summary total IN')) ?></div>
      <div style="font-size:18px;font-weight:600;margin-top:4px;">
        <?= h($currency) ?> <?= number_format($user_summary_in, 2) ?>
      </div>
    </div>

    <div style="min-width:210px;">
      <div style="color:#6b7280;"><?= h(t('portal.dashboard.row3.summary_net', [], 'Summary net (OUT - IN)')) ?></div>

      <?php if ($user_summary_net > 0): ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#b91c1c;">
          <?= h($currency) ?> <?= number_format($user_summary_net, 2) ?>
        </div>
        <div style="font-size:12px;color:#b91c1c;"><?= h(t('portal.dashboard.summary_we_owe', [], 'We owe you')) ?></div>
      <?php elseif ($user_summary_net < 0): ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#166534;">
          <?= h($currency) ?> <?= number_format(abs($user_summary_net), 2) ?>
        </div>
        <div style="font-size:12px;color:#166534;"><?= h(t('portal.dashboard.summary_you_owe', [], 'You owe us')) ?></div>
      <?php else: ?>
        <div style="font-size:18px;font-weight:600;margin-top:4px;color:#111827;">
          <?= h($currency) ?> 0.00
        </div>
        <div style="font-size:12px;color:#6b7280;"><?= h(t('portal.dashboard.summary_balanced', [], 'Balanced')) ?></div>
      <?php endif; ?>
    </div>

  </div>
</div>

<!-- 最近 10 条（保留你原本 layout） -->
<div class="admin-card">
  <div class="admin-card-header" style="margin-bottom:10px;">
    <div>
      <div class="form-page-eyebrow"><?= h(t('portal.dashboard.recent_eyebrow', [], 'Recent')) ?></div>
      <div class="form-page-title"><?= h(t('portal.dashboard.recent_title', [], 'Last 10 transactions')) ?></div>
    </div>
    <div>
      <a href="<?= h(url('user/txn/txns.php')) ?>" class="btn btn-light">
        <?= h(t('portal.dashboard.recent_full_report', [], 'Full report')) ?>
      </a>
    </div>
  </div>

  <div style="display:flex; flex-wrap:wrap; gap:24px;">
    <!-- 左：IN = 我们付你 -->
    <div style="flex:1; min-width:280px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:6px;"><?= h(t('portal.dashboard.recent_in_title', [], 'IN — We paid you')) ?></div>
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;"><?= h(t('portal.dashboard.table.date', [], 'Date')) ?></th>
            <th><?= h(t('portal.dashboard.table.title', [], 'Title')) ?></th>
            <th style="width:120px;text-align:right;"><?= h(t('portal.dashboard.table.amount', [], 'Amount')) ?></th>
            <th style="width:80px;"><?= h(t('portal.dashboard.table.status', [], 'Status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$recentIn): ?>
            <tr><td colspan="4" style="padding:10px;font-size:12px;color:#6b7280;"><?= h(t('portal.dashboard.recent_no_payout', [], 'No payout records.')) ?></td></tr>
          <?php else: ?>
            <?php foreach ($recentIn as $r): ?>
              <?php $amount = (float)$r['amount']; ?>
              <tr>
                <td><?= h($r['txn_date'] ?? substr((string)$r['created_at'], 0, 10)) ?></td>
                <td>
                  <div style="font-size:13px;font-weight:500;"><?= h($r['title']) ?></div>
                  <?php if (!empty($r['ref_no'])): ?><div style="font-size:11px;color:#6b7280;">Ref: <?= h($r['ref_no']) ?></div><?php endif; ?>
                </td>
                <td style="text-align:right; color:#166534; font-weight:600;"><?= h($r['currency'] ?: $currency) ?> <?= number_format($amount, 2) ?></td>
                <?php
                  $rawStatus = (string)($r['status'] ?? '');
                  $flowStat  = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
                  if ($flowStat === 'REJECTED') {
                    $displayStatus = 'REJECTED';
                  } else {
                    $displayStatus = strtoupper($rawStatus ?: 'DRAFT');
                  }
                ?>
                <td>
                  <?php if ($displayStatus === 'CONFIRMED'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#ecfdf5;color:#166534;"><?= h(t('portal.status.confirmed', [], 'CONFIRMED')) ?></span>
                  <?php elseif ($displayStatus === 'SENT'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fef9c3;color:#854d0e;"><?= h(t('portal.status.sent', [], 'SENT')) ?></span>
                  <?php elseif ($displayStatus === 'REJECTED'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fee2e2;color:#b91c1c;">REJECTED</span>
                  <?php else: ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#e5e7eb;color:#374151;"><?= h(t('portal.status.draft', [], 'DRAFT')) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- 右：OUT = 你付我们 -->
    <div style="flex:1; min-width:280px;">
      <div style="font-size:13px;font-weight:600;margin-bottom:6px;"><?= h(t('portal.dashboard.recent_out_title', [], 'OUT — You paid us')) ?></div>
      <table class="table">
        <thead>
          <tr>
            <th style="width:110px;"><?= h(t('portal.dashboard.table.date', [], 'Date')) ?></th>
            <th><?= h(t('portal.dashboard.table.title', [], 'Title')) ?></th>
            <th style="width:120px;text-align:right;"><?= h(t('portal.dashboard.table.amount', [], 'Amount')) ?></th>
            <th style="width:80px;"><?= h(t('portal.dashboard.table.status', [], 'Status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$recentOut): ?>
            <tr><td colspan="4" style="padding:10px;font-size:12px;color:#6b7280;"><?= h(t('portal.dashboard.recent_no_payment', [], 'No payment records.')) ?></td></tr>
          <?php else: ?>
            <?php foreach ($recentOut as $r): ?>
              <?php $amount = (float)$r['amount']; ?>
              <tr>
                <td><?= h($r['txn_date'] ?? substr((string)$r['created_at'], 0, 10)) ?></td>
                <td>
                  <div style="font-size:13px;font-weight:500;"><?= h($r['title']) ?></div>
                  <?php if (!empty($r['ref_no'])): ?><div style="font-size:11px;color:#6b7280;">Ref: <?= h($r['ref_no']) ?></div><?php endif; ?>
                </td>
                <td style="text-align:right; color:#b91c1c; font-weight:600;"><?= h($r['currency'] ?: $currency) ?> <?= number_format($amount, 2) ?></td>
                <?php
                  $rawStatus = (string)($r['status'] ?? '');
                  $flowStat  = strtoupper(trim((string)($r['doc_flow_status'] ?? '')));
                  if ($flowStat === 'REJECTED') {
                    $displayStatus = 'REJECTED';
                  } else {
                    $displayStatus = strtoupper($rawStatus ?: 'DRAFT');
                  }
                ?>
                <td>
                  <?php if ($displayStatus === 'CONFIRMED'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#ecfdf5;color:#166534;"><?= h(t('portal.status.confirmed', [], 'CONFIRMED')) ?></span>
                  <?php elseif ($displayStatus === 'SENT'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fef9c3;color:#854d0e;"><?= h(t('portal.status.sent', [], 'SENT')) ?></span>
                  <?php elseif ($displayStatus === 'REJECTED'): ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#fee2e2;color:#b91c1c;">REJECTED</span>
                  <?php else: ?>
                    <span style="font-size:11px;padding:3px 9px;border-radius:999px;background:#e5e7eb;color:#374151;"><?= h(t('portal.status.draft', [], 'DRAFT')) ?></span>
                  <?php endif; ?>
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
