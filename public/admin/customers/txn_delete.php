<?php
// public/admin/customers/txn_delete.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('TXN.D');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function back_url(int $cid): string
{
    $back = trim((string)($_GET['back'] ?? ''));
    if ($back !== '' && strpos($back, "\n") === false && strpos($back, "\r") === false) {
        return $back;
    }
    if (!empty($_SERVER['HTTP_REFERER'])) return $_SERVER['HTTP_REFERER'];
    return url('admin/customers/txn_list.php?customer_id=' . $cid);
}

/* ===== schema-safe ===== */
function table_exists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare("SHOW TABLES LIKE ?");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $k = strtolower($table);
    if (isset($cache[$k])) return $cache[$k];

    $cols = [];
    try {
        $st = $pdo->query("DESCRIBE `$table`");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!empty($r['Field'])) $cols[(string)$r['Field']] = true;
        }
    } catch (Throwable $e) {
    }
    $cache[$k] = $cols;
    return $cols;
}

/* ===== params ===== */
$id  = (int)($_GET['id'] ?? 0);
$cid = (int)($_GET['customer_id'] ?? 0);

if ($id <= 0 || $cid <= 0) {
    http_response_code(400);
    exit('Missing id or customer_id');
}

/* ===== load txn ===== */
$st = $pdo->prepare("
  SELECT *
  FROM customer_txn
  WHERE id = :id AND customer_id = :cid
  LIMIT 1
");
$st->execute([':id' => $id, ':cid' => $cid]);
$txn = $st->fetch();

if (!$txn) {
    http_response_code(404);
    exit('Transaction not found');
}

$txnType   = strtoupper((string)($txn['txn_type'] ?? ''));
$status    = strtoupper((string)($txn['status'] ?? ''));
$isContra  = (int)($txn['is_contra'] ?? 0) === 1;
$allocated = (float)($txn['allocated_amount'] ?? 0);

/* ===== blocks ===== */
if ($isContra) {
    header('Location: ' . back_url($cid));
    exit;
}
if ($txnType === 'IN' && $allocated > 0.0001) {
    header('Location: ' . back_url($cid));
    exit;
}

/* ===== delete flow ===== */
try {
    $pdo->beginTransaction();

    // 1) delete attachments rows + unlink files
    if (table_exists($pdo, 'customer_txn_attachments')) {
        $pdo->prepare("DELETE FROM customer_txn_attachments WHERE customer_txn_id = :tid")->execute([':tid' => $id]);
    }

    if (table_exists($pdo, 'customer_txn_lines')) {
        $pdo->prepare("DELETE FROM customer_txn_lines WHERE customer_txn_id = :tid")->execute([':tid' => $id]);
    }

    if (table_exists($pdo, 'customer_txn_files')) {
        $stFiles = $pdo->prepare("SELECT file_path FROM customer_txn_files WHERE txn_id = :tid");
        $stFiles->execute([':tid' => $id]);
        $files = $stFiles->fetchAll();

        $pdo->prepare("DELETE FROM customer_txn_files WHERE txn_id = :tid")->execute([':tid' => $id]);

        foreach ($files as $f) {
            $fp = (string)($f['file_path'] ?? '');
            if ($fp === '') continue;
            $full = __DIR__ . '/../../../' . ltrim($fp, '/');
            if (is_file($full)) @unlink($full);
        }
    }

    if (table_exists($pdo, 'customer_txn_allocations')) {
        $pdo->prepare("DELETE FROM customer_txn_allocations WHERE source_txn_id = :tid OR target_txn_id = :tid")
            ->execute([':tid' => $id]);
    }

    // 2) if IN: delete payments + related bank ledger
    if ($txnType === 'IN' && table_exists($pdo, 'customer_txn_payments')) {

        $colsPay  = table_columns($pdo, 'customer_txn_payments');
        $colsBank = table_exists($pdo, 'company_bank_txn') ? table_columns($pdo, 'company_bank_txn') : [];

        $payRows = [];
        $stPay = $pdo->prepare("SELECT * FROM customer_txn_payments WHERE customer_txn_id = :tid ORDER BY id ASC");
        $stPay->execute([':tid' => $id]);
        $payRows = $stPay->fetchAll();

        // delete bank txn by source link (best) or bank_txn_id (legacy)
        if ($payRows && $colsBank) {
            $payIds = [];
            $bankTxnIds = [];

            foreach ($payRows as $p) {
                $payIds[] = (int)($p['id'] ?? 0);
                if (isset($colsPay['bank_txn_id'])) {
                    $bid = (int)($p['bank_txn_id'] ?? 0);
                    if ($bid > 0) $bankTxnIds[] = $bid;
                }
            }
            $payIds = array_values(array_filter(array_unique($payIds)));
            $bankTxnIds = array_values(array_filter(array_unique($bankTxnIds)));

            // source_table/source_id
            if ($payIds && isset($colsBank['source_table'], $colsBank['source_id'])) {
                $in = implode(',', array_fill(0, count($payIds), '?'));
                $pdo->prepare("DELETE FROM company_bank_txn WHERE source_table='customer_txn_payments' AND source_id IN ($in)")
                    ->execute($payIds);
            }

            // legacy bank_txn_id link
            if ($bankTxnIds && isset($colsBank['id'])) {
                $in = implode(',', array_fill(0, count($bankTxnIds), '?'));
                $pdo->prepare("DELETE FROM company_bank_txn WHERE id IN ($in)")
                    ->execute($bankTxnIds);
            }
        }

        // finally delete payment attachments and payments
        if (table_exists($pdo, 'customer_txn_payment_attachments')) {
            $pdo->prepare("
                DELETE pa FROM customer_txn_payment_attachments pa
                JOIN customer_txn_payments p ON p.id = pa.payment_id
                WHERE p.customer_txn_id = :tid
            ")->execute([':tid' => $id]);
        }

        $pdo->prepare("DELETE FROM customer_txn_payments WHERE customer_txn_id = :tid")->execute([':tid' => $id]);
    }

    // 3) delete related bank ledger
    if (table_exists($pdo, 'company_bank_txn')) {
        $pdo->prepare("DELETE FROM company_bank_txn WHERE txn_id = :tid")
            ->execute([':tid' => $id]);

        $colsBank = table_columns($pdo, 'company_bank_txn');
        if (isset($colsBank['source_table'], $colsBank['source_id'])) {
            $pdo->prepare("DELETE FROM company_bank_txn WHERE source_table='customer_txn' AND source_id = :tid")
                ->execute([':tid' => $id]);
        }
    }

    // 4) delete main txn
    $pdo->prepare("DELETE FROM customer_txn WHERE id = :id AND customer_id = :cid")
        ->execute([':id' => $id, ':cid' => $cid]);

    $pdo->commit();

    header('Location: ' . back_url($cid));
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('Delete failed: ' . h($e->getMessage()));
}
