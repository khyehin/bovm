<?php
// app/txn_sign_status.php
declare(strict_types=1);

/**
 * 标记某一边已签名；
 * 只有在【签名要求满足 +（IN 时：已给完钱）】时，才自动 CONFIRMED。
 *
 * ✅ 统一规则（你要的）：
 * - require_signature=1 表示进入“签名锁定模式”
 * - 需要签哪一边由 sign_receive / sign_payer 决定：
 *   - sign_receive=1 => 需要我方签（payer_*）
 *   - sign_payer=1   => 需要客户签（receiver_* / recipient_*）
 * - 兼容旧数据：require_signature=1 但 sign_* 都没有/为0 => 视为两边都要签
 *
 * 兼容签名存储：
 * - 新逻辑：优先看最后一笔 customer_txn_payments 的 payer/receiver 签名
 * - 旧逻辑：customer_txn 里 payer_signed_at / recipient_signed_at
 *
 * @param PDO    $pdo
 * @param int    $txnId  customer_txn.id
 * @param string $side   'payer'（我方） 或 'recipient'（客户）
 */
function txn_mark_signed_and_maybe_confirm(PDO $pdo, int $txnId, string $side): void
{
    if ($txnId <= 0) return;

    $side = strtolower(trim($side));
    if ($side !== 'payer' && $side !== 'recipient') return;

    $now = date('Y-m-d H:i:s');

    // 1) 写入 customer_txn 的 signed_at（兼容旧数据）
    if ($side === 'payer') {
        $pdo->prepare("
            UPDATE customer_txn
               SET payer_signed_at = COALESCE(payer_signed_at, :now),
                   updated_at = NOW()
             WHERE id = :id
        ")->execute([':now' => $now, ':id' => $txnId]);
    } else {
        $pdo->prepare("
            UPDATE customer_txn
               SET recipient_signed_at = COALESCE(recipient_signed_at, :now),
                   updated_at = NOW()
             WHERE id = :id
        ")->execute([':now' => $now, ':id' => $txnId]);
    }

    // 2) 读取交易
    $st = $pdo->prepare("
        SELECT
          id,
          customer_id,
          txn_type,
          in_kind,
          status,
          require_signature,
          sign_receive,
          sign_payer,
          payer_signed_at,
          recipient_signed_at,
          amount,
          order_total,
          currency
        FROM customer_txn
        WHERE id = :id
        LIMIT 1
    ");
    $st->execute([':id' => $txnId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    $needSign = (int)($row['require_signature'] ?? 0);
    $status   = strtoupper((string)($row['status'] ?? ''));

    // 不需要签名 / 已 confirmed -> 不处理
    if ($needSign !== 1 || $status === 'CONFIRMED') return;

    // ✅ 计算“哪一边必须签”
    $signReceive = (int)($row['sign_receive'] ?? 0); // 我方必须签
    $signPayer   = (int)($row['sign_payer'] ?? 0);   // 客户必须签

    // 兼容旧数据：require_signature=1 但 sign_* 都没设 => 默认两边都要
    if ($signReceive === 0 && $signPayer === 0) {
        $signReceive = 1;
        $signPayer   = 1;
    }

    $needOurSign = ($signReceive === 1); // 我方：payer_*
    $needCusSign = ($signPayer   === 1); // 客户：receiver_* / recipient_*

    $txnType    = strtoupper((string)($row['txn_type'] ?? ''));
    $orderTotal = (float)($row['order_total'] ?? 0);
    $mainCur    = strtoupper(trim((string)($row['currency'] ?? 'MYR')));
    if ($mainCur === '') $mainCur = 'MYR';

    // 3) 付款金额：IN 才算 payments（只算主币种）
    $paidTotal = 0.0;
    if ($txnType === 'IN') {
        $st = $pdo->prepare("
            SELECT currency, amount
            FROM customer_txn_payments
            WHERE customer_txn_id = :tid
        ");
        $st->execute([':tid' => $txnId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $cur = strtoupper(trim((string)($p['currency'] ?? '')));
            if ($cur === '') $cur = $mainCur;
            if ($cur === $mainCur) $paidTotal += (float)($p['amount'] ?? 0);
        }
    } else {
        // OUT：不让 helper 动金额
        $paidTotal = (float)($row['amount'] ?? 0);
    }

    // 4) 签名判断：优先 “最后一笔 payment”，没有才 fallback txn
    $ourDone = false;
    $cusDone = false;

    // last payment
    $stLast = $pdo->prepare("
        SELECT id, payer_signature_image, receiver_signature_image, payer_signed_at, receiver_signed_at
        FROM customer_txn_payments
        WHERE customer_txn_id = :tid
        ORDER BY pay_date DESC, id DESC
        LIMIT 1
    ");
    $stLast->execute([':tid' => $txnId]);
    $lastPay = $stLast->fetch(PDO::FETCH_ASSOC) ?: [];

    if ($lastPay) {
        // 我方：payer_*
        $ourDone = !empty($lastPay['payer_signature_image']) || !empty($lastPay['payer_signed_at']);
        // 客户：receiver_*
        $cusDone = !empty($lastPay['receiver_signature_image']) || !empty($lastPay['receiver_signed_at']);

        // 同步写入 payment signed_at（兼容：有些页面只调用 helper 没写 payment）
        $lastPayId = (int)($lastPay['id'] ?? 0);
        if ($lastPayId > 0) {
            if ($side === 'payer') {
                $pdo->prepare("
                    UPDATE customer_txn_payments
                       SET payer_signed_at = COALESCE(payer_signed_at, :now),
                           updated_at = NOW()
                     WHERE id = :pid
                ")->execute([':now' => $now, ':pid' => $lastPayId]);
            } else {
                $pdo->prepare("
                    UPDATE customer_txn_payments
                       SET receiver_signed_at = COALESCE(receiver_signed_at, :now),
                           updated_at = NOW()
                     WHERE id = :pid
                ")->execute([':now' => $now, ':pid' => $lastPayId]);
            }

            // 再读一次，确保刚写入后状态正确
            $stLast2 = $pdo->prepare("
                SELECT payer_signature_image, receiver_signature_image, payer_signed_at, receiver_signed_at
                FROM customer_txn_payments
                WHERE id = :pid
                LIMIT 1
            ");
            $stLast2->execute([':pid' => $lastPayId]);
            $lp2 = $stLast2->fetch(PDO::FETCH_ASSOC) ?: [];

            $ourDone = !empty($lp2['payer_signature_image']) || !empty($lp2['payer_signed_at']);
            $cusDone = !empty($lp2['receiver_signature_image']) || !empty($lp2['receiver_signed_at']);
        }
    }

    // fallback to txn signed_at (old)
    if (!$lastPay) {
        $ourDone = !empty($row['payer_signed_at']);
        $cusDone = !empty($row['recipient_signed_at']);
    }

    // ✅ 必签方未完成 -> 不允许 CONFIRM
    if (($needOurSign && !$ourDone) || ($needCusSign && !$cusDone)) return;

    // 5) IN：没给完钱 -> 不允许 CONFIRMED
    if ($txnType === 'IN') {
        if ($orderTotal > 0 && ($paidTotal + 0.0001) < $orderTotal) {
            $pdo->prepare("
                UPDATE customer_txn
                   SET status = 'PENDING',
                       updated_at = NOW()
                 WHERE id = :id
            ")->execute([':id' => $txnId]);
            return;
        }
    }

    // 6) 满足条件 -> CONFIRMED
    if ($txnType === 'IN') {
        // ✅ IN：确认时同步 amount=paidTotal（你 invoice 的 amount 逻辑需要）
        $pdo->prepare("
            UPDATE customer_txn
               SET status       = 'CONFIRMED',
                   amount       = :amt,
                   confirmed_at = IFNULL(confirmed_at, NOW()),
                   updated_at   = NOW()
             WHERE id = :id
        ")->execute([
            ':amt' => $paidTotal,
            ':id'  => $txnId,
        ]);
    } else {
        // ✅ OUT：只改状态，不动 amount
        $pdo->prepare("
            UPDATE customer_txn
               SET status       = 'CONFIRMED',
                   confirmed_at = IFNULL(confirmed_at, NOW()),
                   updated_at   = NOW()
             WHERE id = :id
        ")->execute([':id' => $txnId]);
    }

    // 7) Audit log（可选）
    if (function_exists('audit_log')) {
        try {
            audit_log(
                $pdo,
                'TXN.AUTO_CONFIRM_AFTER_SIGNATURES',
                [
                    'txn_id'      => $txnId,
                    'customer_id' => (int)($row['customer_id'] ?? 0),
                    'txn_type'    => $row['txn_type'] ?? '',
                    'in_kind'     => $row['in_kind'] ?? '',
                    'paid_total'  => $paidTotal,
                    'order_total' => $orderTotal,
                    'currency'    => $row['currency'] ?? '',
                    'require_signature' => $needSign,
                    'sign_receive' => $signReceive,
                    'sign_payer'   => $signPayer,
                    'auto_reason'  => ($txnType === 'IN')
                        ? 'required signatures satisfied & fully paid'
                        : 'required signatures satisfied (OUT amount unchanged)',
                ],
                'customer_txn',
                $txnId
            );
        } catch (Throwable $e) {
            // ignore
        }
    }
}
