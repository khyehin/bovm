<?php
// public/admin/customers/txn_edit_in.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

// 允许从 Company1 入口复用本页面：
// 若定义 ALLOW_TXN_IN_FROM_COMPANY1=true，则跳过 require_admin，并由外层负责 header/footer。
$allowFromCompany1 = false;
if (defined('ALLOW_TXN_IN_FROM_COMPANY1') && constant('ALLOW_TXN_IN_FROM_COMPANY1') === true) {
    $allowFromCompany1 = true;
}

if (!$allowFromCompany1) {
    require_admin();
    require_perm('TXN.E');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
if (function_exists('app_ensure_customer_currency_schema')) {
    app_ensure_customer_currency_schema($pdo);
}

if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/**
 * i18n helper:
 * - if t() exists -> use it
 * - else fallback to provided English text
 */
if (!function_exists('tt')) {
    function tt(string $key, string $fallback, array $params = []): string
    {
        if (function_exists('t')) {
            return (string)t($key, $params, $fallback);
        }
        return $fallback;
    }
}

/** ---------- schema-safe helpers ---------- */
function table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    $k = strtolower($table);
    if (isset($cache[$k])) return $cache[$k];

    $cols = [];
    try {
        $st = $pdo->query("DESCRIBE `$table`");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)($r['Field'] ?? ''))] = true;
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cache[$k] = $cols;
}
function has_col(PDO $pdo, string $table, string $col): bool
{
    $cols = table_columns($pdo, $table);
    return isset($cols[strtolower($col)]);
}

/**
 * Convert DB file_path to browser URL
 */
function upload_href(?string $fp): string
{
    $fp = trim((string)$fp);
    if ($fp === '') return '';

    $fp = ltrim($fp, '/');

    if (strpos($fp, 'public/uploads/') === 0) {
        $fp = substr($fp, strlen('public/'));
    }
    if (strpos($fp, 'uploads/uploads/') === 0) {
        $fp = substr($fp, strlen('uploads/'));
    }
    if (strpos($fp, 'uploads/') !== 0) {
        $fp = 'uploads/' . $fp;
    }
    return '../' . $fp;
}

/* ===========================
   ✅ schema-safe bank ledger sync (for IN payments)
   - supports multiple schemas of company_bank_txn
   - FIX: if bank_id is NOT NULL but bank_account_id exists too, set BOTH
   - FIX: if bank not selected (0), do NOT insert bank txn
=========================== */

function col_exists(array $cols, string $name): bool
{
    return isset($cols[strtolower($name)]);
}

function bank_txn_delete_by_marker(PDO $pdo, string $marker, ?int $bankAccountId = null): void
{
    $cols = table_columns($pdo, 'company_bank_txn');
    if (!$cols) return;

    $noteCol = col_exists($cols, 'notes') ? 'notes' : (col_exists($cols, 'description') ? 'description' : null);
    if (!$noteCol) return;

    $where  = ["`$noteCol` LIKE :mk"];
    $params = [':mk' => '%' . $marker . '%'];

    if ($bankAccountId) {
        // ✅ if both exist, match either (more safe)
        if (col_exists($cols, 'bank_account_id') && col_exists($cols, 'bank_id')) {
            $where[] = "(`bank_account_id` = :bid OR `bank_id` = :bid)";
            $params[':bid'] = $bankAccountId;
        } elseif (col_exists($cols, 'bank_account_id')) {
            $where[] = "`bank_account_id` = :bid";
            $params[':bid'] = $bankAccountId;
        } elseif (col_exists($cols, 'bank_id')) {
            $where[] = "`bank_id` = :bid";
            $params[':bid'] = $bankAccountId;
        }
    }

    $sql = "DELETE FROM company_bank_txn WHERE " . implode(' AND ', $where);
    $pdo->prepare($sql)->execute($params);
}

function bank_txn_insert(PDO $pdo, array $fields): int
{
    $cols = table_columns($pdo, 'company_bank_txn');
    if (!$cols) throw new RuntimeException('company_bank_txn not found');

    $filtered = [];
    foreach ($fields as $k => $v) {
        if (col_exists($cols, $k)) $filtered[$k] = $v;
    }
    if (!$filtered) throw new RuntimeException('No valid fields for company_bank_txn');

    $names = array_keys($filtered);
    $phs   = array_map(fn($c) => ':' . $c, $names);

    $sql = "INSERT INTO company_bank_txn (`" . implode('`,`', $names) . "`) VALUES (" . implode(',', $phs) . ")";
    $st  = $pdo->prepare($sql);

    $params = [];
    foreach ($filtered as $k => $v) $params[':' . $k] = $v;
    $st->execute($params);

    return (int)$pdo->lastInsertId();
}

function bank_txn_update(PDO $pdo, int $id, array $fields): void
{
    if ($id <= 0) return;

    $cols = table_columns($pdo, 'company_bank_txn');
    if (!$cols) return;

    $filtered = [];
    foreach ($fields as $k => $v) {
        if (col_exists($cols, $k)) $filtered[$k] = $v;
    }
    unset($filtered['id']);
    if (!$filtered) return;

    $sets   = [];
    $params = [':id' => $id];
    foreach ($filtered as $k => $v) {
        $sets[] = "`$k` = :$k";
        $params[":$k"] = $v;
    }

    $sql = "UPDATE company_bank_txn SET " . implode(', ', $sets) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
}

/**
 * ✅ Upsert a bank ledger row for a payment line.
 * - Uses source_table+source_id if exists; else uses a stable marker in notes/description
 * - FIX: set BOTH bank_account_id & bank_id if both exist
 * - FIX: if bankAccountId <= 0 -> do nothing (return 0)
 */
function bank_txn_sync_for_in_payment(
    PDO $pdo,
    int $txnId,
    int $paymentId,
    int $bankAccountId,
    string $txnDate,
    string $refNo,
    string $currency,
    float $amount,
    ?float $fxRate,
    string $desc,
    int $customerId
): int {
    if ($bankAccountId <= 0) return 0; // ✅ FIX: no bank selected => skip

    $cols = table_columns($pdo, 'company_bank_txn');
    if (!$cols) return 0;

    $now = date('Y-m-d H:i:s');
    $currency = strtoupper(trim($currency ?: 'MYR'));

    $marker = "[INPAY#{$paymentId}]";

    // detect columns
    $hasBankAcc = col_exists($cols, 'bank_account_id');
    $hasBankId  = col_exists($cols, 'bank_id');

    $titleCol = col_exists($cols, 'title') ? 'title' : null;
    $noteCol  = col_exists($cols, 'notes') ? 'notes' : (col_exists($cols, 'description') ? 'description' : null);
    $typeCol  = col_exists($cols, 'txn_type') ? 'txn_type' : (col_exists($cols, 'direction') ? 'direction' : null);

    $existingId = 0;

    if (col_exists($cols, 'source_table') && col_exists($cols, 'source_id')) {
        $st = $pdo->prepare("SELECT id FROM company_bank_txn WHERE source_table='customer_txn_payments' AND source_id=:sid LIMIT 1");
        $st->execute([':sid' => $paymentId]);
        $existingId = (int)($st->fetchColumn() ?: 0);
    }

    if ($existingId <= 0 && $noteCol) {
        $st = $pdo->prepare("SELECT id FROM company_bank_txn WHERE `$noteCol` LIKE :mk ORDER BY id DESC LIMIT 1");
        $st->execute([':mk' => '%' . $marker . '%']);
        $existingId = (int)($st->fetchColumn() ?: 0);
    }

    $fields = [];

    // ✅ FIX: if both exist, set both
    if ($hasBankAcc) $fields['bank_account_id'] = $bankAccountId;
    if ($hasBankId)  $fields['bank_id'] = $bankAccountId;

    if ($typeCol) $fields[$typeCol] = 'IN';

    if (col_exists($cols, 'txn_date')) $fields['txn_date'] = $txnDate;
    if (col_exists($cols, 'ref_no'))   $fields['ref_no']   = $refNo;
    if (col_exists($cols, 'currency')) $fields['currency'] = $currency;
    if (col_exists($cols, 'amount'))   $fields['amount']   = $amount;

    if (col_exists($cols, 'fx_rate')) {
        $fields['fx_rate'] = ($currency === 'MYR') ? null : ($fxRate ?: null);
    }
    if (col_exists($cols, 'rate_to_myr')) {
        $fields['rate_to_myr'] = ($currency === 'MYR') ? 1.0 : ($fxRate ?: 0);
    }
    if (col_exists($cols, 'amount_myr')) {
        $fields['amount_myr'] = ($currency === 'MYR') ? $amount : (($fxRate ?: 0) > 0 ? $amount * $fxRate : 0);
    }

    if ($titleCol) $fields[$titleCol] = 'Customer Payment';
    if ($noteCol)  $fields[$noteCol]  = $marker . ' ' . $desc;

    if (col_exists($cols, 'customer_id')) $fields['customer_id'] = $customerId;

    if (col_exists($cols, 'source_table')) $fields['source_table'] = 'customer_txn_payments';
    if (col_exists($cols, 'source_id'))    $fields['source_id']    = $paymentId;

    if (col_exists($cols, 'status')) $fields['status'] = 'SENT';

    if (col_exists($cols, 'updated_at')) $fields['updated_at'] = $now;
    if ($existingId <= 0 && col_exists($cols, 'created_at')) $fields['created_at'] = $now;

    if ($existingId > 0) {
        bank_txn_update($pdo, $existingId, $fields);
        return $existingId;
    }
    return bank_txn_insert($pdo, $fields);
}

// ✅ 统一重算 status（跟 receipt 同规则）
function recompute_in_txn_status(PDO $pdo, int $txnId): void
{
    $colsTxn = table_columns($pdo, 'customer_txn');
    $st = $pdo->prepare("
        SELECT id, customer_id, in_kind, currency, order_total, sign_receive, sign_payer, require_signature, doc_flow_type, doc_flow_status
        FROM customer_txn
        WHERE id=:id LIMIT 1
    ");
    $st->execute([':id' => $txnId]);
    $txn = $st->fetch(PDO::FETCH_ASSOC);
    if (!$txn) return;

    $mainCur  = strtoupper(trim((string)($txn['currency'] ?? 'MYR')));
    if ($mainCur === '') $mainCur = 'MYR';

    $orderTotal = (float)($txn['order_total'] ?? 0);

    // ✅ 付款总额：只算主币种
    $paid = 0.0;
    $stp = $pdo->prepare("SELECT currency, amount FROM customer_txn_payments WHERE customer_txn_id=:id");
    $stp->execute([':id' => $txnId]);
    foreach ($stp->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $cur = strtoupper(trim((string)($p['currency'] ?? '')));
        if ($cur === '') $cur = $mainCur;
        if ($cur === $mainCur) $paid += (float)($p['amount'] ?? 0);
    }
    $isPaidEnough = ($orderTotal > 0 && ($paid + 0.0001) >= $orderTotal);

    // ✅ 需要签名？（与 txn_receipt_in.php 对齐）
    // - sign_receive = 我们签 => receiver_*
    // - sign_payer   = 客户签 => payer_*
    $needOur = ((int)($txn['sign_receive'] ?? 0) === 1); // 我们：receiver_*
    $needCus = ((int)($txn['sign_payer'] ?? 0) === 1);   // 客户：payer_*

    $requireSignature = ((int)($txn['require_signature'] ?? 0) === 1);
    // 兼容旧数据：require_signature=1 但 sign_* 都没设时，按双方都要签
    if ($requireSignature && !$needOur && !$needCus) {
        $needOur = true;
        $needCus = true;
    }

    $signOk = true;

    if ($needOur || $needCus) {

        // --- 1) 先看最后一笔 payment 的签名 ---
        $stLast = $pdo->prepare("
          SELECT payer_signature_image, receiver_signature_image, payer_signed_at, receiver_signed_at
          FROM customer_txn_payments
          WHERE customer_txn_id=:id
          ORDER BY pay_date DESC, id DESC
          LIMIT 1
        ");
        $stLast->execute([':id' => $txnId]);
        $last = $stLast->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($needCus) {
            // customer side
            $cusDone = !empty($last['payer_signature_image']) || !empty($last['payer_signed_at']);
            if (!$cusDone) $signOk = false;
        }

        if ($needOur) {
            // our side
            $ourDone = !empty($last['receiver_signature_image']) || !empty($last['receiver_signed_at']);
            if (!$ourDone) $signOk = false;
        }

        // 不再 fallback 到 customer_txn 级别签名：
        // IN 的签名完成度只认最后一笔 payment，避免“报价单签过就提前 CONFIRMED”。
    }

    // ✅ 最终规则：
    // 1) 先看付款是否足够：没付够，一律 PENDING
    // 2) 付够后：
    //    - require_signature=0：直接 CONFIRMED
    //    - require_signature=1：只要勾选的签名方都在最后一笔 payment 签完，才 CONFIRMED
    if (!$isPaidEnough) {
        $newStatus = 'PENDING';
    } else {
        if ($requireSignature) {
            $newStatus = $signOk ? 'CONFIRMED' : 'PENDING';
        } else {
            $newStatus = 'CONFIRMED';
        }
    }
    $flowType = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
    if (!in_array($flowType, ['NORMAL', 'QUOTATION'], true)) $flowType = 'NORMAL';
    $hasFlowStat = isset($colsTxn['doc_flow_status']);
    $hasAddrSnap1    = isset($colsTxn['customer_addr1_snapshot']);
    $hasAddrSnap2    = isset($colsTxn['customer_addr2_snapshot']);
    $hasAddrSnap3    = isset($colsTxn['customer_addr3_snapshot']);
    $hasAddrSnapMeta = isset($colsTxn['customer_addr_city_state_postcode_snapshot']);

    if ($newStatus === 'CONFIRMED') {
        $set = [
            "status='CONFIRMED'",
            "confirmed_at=IFNULL(confirmed_at,NOW())",
            "updated_at=NOW()",
        ];
        $params = [':id' => $txnId];
        // paid 完成后：自动把 invoice 的 flow status 设为 COMPLETED
        if ($hasFlowStat && $flowType === 'NORMAL') {
            $set[] = "doc_flow_status='COMPLETED'";
            // 写入“完成时地址快照”：之后门店地址更新不会影响已完成单据打印
            $cid = (int)($txn['customer_id'] ?? 0);
            if ($cid > 0 && ($hasAddrSnap1 || $hasAddrSnap2 || $hasAddrSnap3 || $hasAddrSnapMeta)) {
                $stC = $pdo->prepare("
                    SELECT address1, address2, address3, city, state, postcode
                    FROM customers
                    WHERE id = :id
                    LIMIT 1
                ");
                $stC->execute([':id' => $cid]);
                $c = $stC->fetch(PDO::FETCH_ASSOC) ?: [];

                $addrLine4 = trim(implode(' ', array_filter([
                    (string)($c['city'] ?? ''),
                    (string)($c['state'] ?? ''),
                    (string)($c['postcode'] ?? ''),
                ])));

                $params[':cas1'] = (string)($c['address1'] ?? '');
                $params[':cas2'] = (string)($c['address2'] ?? '');
                $params[':cas3'] = (string)($c['address3'] ?? '');
                $params[':cam']  = $addrLine4;

                if ($hasAddrSnap1)    $set[] = "customer_addr1_snapshot = IFNULL(customer_addr1_snapshot, :cas1)";
                if ($hasAddrSnap2)    $set[] = "customer_addr2_snapshot = IFNULL(customer_addr2_snapshot, :cas2)";
                if ($hasAddrSnap3)    $set[] = "customer_addr3_snapshot = IFNULL(customer_addr3_snapshot, :cas3)";
                if ($hasAddrSnapMeta) $set[] = "customer_addr_city_state_postcode_snapshot = IFNULL(customer_addr_city_state_postcode_snapshot, :cam)";
            }
        }
        $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id=:id")
            ->execute($params);
    } else {
        $set = [
            "status='PENDING'",
            "updated_at=NOW()",
        ];
        // 未完成付款时：invoice 保持 PROCESSING（不要自动变 COMPLETED）
        if ($hasFlowStat && $flowType === 'NORMAL') {
            $set[] = "doc_flow_status='PROCESSING'";
        }
        $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $set) . " WHERE id=:id")
            ->execute([':id' => $txnId]);
    }
}

// IN kinds
$validInKinds = ['INVOICE', 'RETURN', 'BONUS'];

// extra columns (schema-safe flags)
$txnCols           = table_columns($pdo, 'customer_txn');
$hasColDoDate      = isset($txnCols['do_date']);
$hasColDoNumber    = isset($txnCols['do_number']);
$hasColSignMode    = isset($txnCols['sign_mode']);
$hasColDocFlowType = isset($txnCols['doc_flow_type']);
$hasColDocFlowStat = isset($txnCols['doc_flow_status']);
$hasColRequireSignQuotation = isset($txnCols['require_sign_quotation']);
$hasColRequireSignInvoice   = isset($txnCols['require_sign_invoice']);
$hasColRequireSignDo        = isset($txnCols['require_sign_do']);

// ---------- params ----------
$customer_id = (int)($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
$id          = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$ok          = (string)($_GET['ok'] ?? '');
$requestedFlowType = strtoupper(trim((string)($_GET['doc_flow_type'] ?? $_POST['doc_flow_type'] ?? '')));
if (!in_array($requestedFlowType, ['NORMAL', 'QUOTATION'], true)) {
    $requestedFlowType = 'NORMAL';
}

// load txn if id exists
$txn = null;
if ($id > 0) {
    $st = $pdo->prepare("
        SELECT *
        FROM customer_txn
        WHERE id = :id
          AND txn_type = 'IN'
        LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if (!$row) {
        http_response_code(404);
        exit(tt('admin.txn_in.err.not_found', 'IN transaction not found'));
    }
    $txn = $row;

    $curInKind = strtoupper(trim((string)($row['in_kind'] ?? 'INVOICE')));
    if (!in_array($curInKind, $validInKinds, true)) {
        $curInKind = 'INVOICE';
    }
    $txn['in_kind'] = $curInKind;

    $txnCustomerId = (int)$row['customer_id'];
    if ($customer_id <= 0) {
        $customer_id = $txnCustomerId;
    } elseif ($customer_id !== $txnCustomerId) {
        http_response_code(400);
        exit(tt('admin.txn_in.err.customer_mismatch', 'Customer mismatch'));
    }

    // 还在 Quotation（未生成 invoice_no）时，不允许进入 txn_edit_in，直接回到 quotation_edit
    $invNoNow = trim((string)($txn['invoice_no'] ?? ''));
    $flowNow = strtoupper(trim((string)($txn['doc_flow_type'] ?? 'NORMAL')));
    if ($invNoNow === '' && $flowNow === 'QUOTATION') {
        header('Location: ' . url('admin/customers/quotation_edit.php?id=' . (int)$id . '&customer_id=' . (int)$customer_id));
        exit;
    }
}

if ($customer_id <= 0) {
    http_response_code(400);
    exit(tt('admin.txn_in.err.missing_customer', 'Missing customer_id'));
}

// ---------- Customer ----------
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $customer_id]);
$customer = $st->fetch();
if (!$customer) {
    http_response_code(404);
    exit(tt('admin.txn_in.err.customer_not_found', 'Customer not found'));
}
if (empty($customer['currency']) && !empty($customer['category_id'])) {
    try {
        $catCols = table_columns($pdo, 'customer_categories');
        if (isset($catCols['currency'])) {
            $stCur = $pdo->prepare("SELECT currency FROM customer_categories WHERE id = :id LIMIT 1");
            $stCur->execute([':id' => (int)$customer['category_id']]);
            $customer['currency'] = strtoupper(trim((string)($stCur->fetchColumn() ?: '')));
        }
    } catch (Throwable $e) {
    }
}
if (empty($customer['currency'])) $customer['currency'] = 'MYR';

// ---------- Bank Accounts ----------
$bankSql = "
    SELECT id, bank_code, account_name, account_no, currency
    FROM company_bank_accounts
    WHERE is_active = 1
";
// 如果从 Company1 入口过来，并且定义了白名单常量，则限制 bank id
if ($allowFromCompany1 && defined('TXN_IN_BANK_ID_WHITELIST')) {
    $idsConst = (string)constant('TXN_IN_BANK_ID_WHITELIST');
    $ids = preg_replace('/[^0-9,]/', '', $idsConst);
    if ($ids !== '') {
        $bankSql .= " AND id IN (" . $ids . ")";
    }
}
$bankSql .= " ORDER BY bank_code, account_name, account_no, id";
$bankRows = $pdo->query($bankSql)->fetchAll();

function bank_label(array $b): string
{
    $parts = [];
    if (!empty($b['bank_code']))    $parts[] = $b['bank_code'];
    if (!empty($b['account_name'])) $parts[] = $b['account_name'];
    if (!empty($b['account_no']))   $parts[] = $b['account_no'];
    $label = implode(' · ', $parts);
    if (!empty($b['currency'])) {
        $label .= $label !== '' ? ' [' . $b['currency'] . ']' : '[' . $b['currency'] . ']';
    }
    return $label ?: ('Account #' . ($b['id'] ?? ''));
}

// ---------- Our companies / staff ----------
$payer_companies = [];
$payer_staff     = [];

try {
    $st = $pdo->query("
        SELECT id, name, reg_no
          FROM payer_companies
      ORDER BY name ASC, id ASC
    ");
    $payer_companies = $st->fetchAll();
} catch (Throwable $e) {
    $payer_companies = [];
}

try {
    $st = $pdo->query("
        SELECT id, staff_name, ic_no
          FROM payer_company_staff
         WHERE is_active = 1
      ORDER BY staff_name ASC, id ASC
    ");
    $payer_staff = $st->fetchAll();
} catch (Throwable $e) {
    $payer_staff = [];
}

// ---------- Customer linked login users ----------
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
    $st->execute([':cid' => (int)$customer['id']]);
    $loginUsers = $st->fetchAll();
} catch (Throwable $e) {
    $loginUsers = [];
}

// recipient options
$recipientOptions = [];
if (!empty($customer['contact_name'] ?? '')) $recipientOptions[] = $customer['contact_name'];
if (!empty($customer['default_receipt_name'] ?? '') && $customer['default_receipt_name'] !== ($recipientOptions[0] ?? '')) {
    $recipientOptions[] = $customer['default_receipt_name'];
}

// loginUsers -> JS array
$loginUsersJs = [];
foreach ($loginUsers as $lu) {
    $loginUsersJs[] = [
        'name' => (string)($lu['full_name'] ?? ''),
        'nric' => (string)($lu['nric'] ?? ''),
    ];
}

// ---------- default txn ----------
if ($txn === null) {
    $txn = [
        'id'                => 0,
        'customer_id'       => $customer_id,
        'txn_type'          => 'IN',
        'in_kind'           => 'INVOICE',
        'txn_date'          => date('Y-m-d'),
        'currency'          => $customer['currency'] ?? 'MYR',
        'title'             => '',
        'amount'            => 0,
        'order_total'       => 0,
        'invoice_no'        => '',
        'status'            => 'PENDING',
        'doc_flow_type'     => $requestedFlowType,
        'doc_flow_status'   => 'DRAFT',
        'notes'             => '',
        'sign_receive'      => 1,
        'sign_payer'        => 0,
        'require_signature' => 0,
        'do_date'           => null,
        'do_number'         => '',
        'sign_mode'         => 'SIGN_AND_CHOP',
        'recipient_name'    => '',
        'recipient_nric'    => '',
        'payer_company_id'  => null,
        'payer_staff_id'    => null,
    ];
}

// ---------- Auto-fill suggested invoice_no for display (user can change; save uses submitted or auto-generate if blank) ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $isInvoiceKindDisplay = (strtoupper((string)($txn['in_kind'] ?? 'INVOICE')) === 'INVOICE');
    if ($isInvoiceKindDisplay && trim((string)($txn['invoice_no'] ?? '')) === '') {
        $txnDate = $txn['txn_date'] ?? date('Y-m-d');
        $ym = date('ym', strtotime($txnDate));
        $prefix = "VM{$ym}-";
        $stInv = $pdo->prepare("SELECT invoice_no FROM customer_txn WHERE invoice_no LIKE :pfx ORDER BY id DESC LIMIT 1");
        $stInv->execute([':pfx' => $prefix . '%']);
        $seqNo = 1;
        if ($rInv = $stInv->fetch()) {
            $last3 = (int)substr((string)$rInv['invoice_no'], -3);
            $seqNo = $last3 + 1;
        }
        $txn['invoice_no'] = $prefix . str_pad((string)$seqNo, 3, '0', STR_PAD_LEFT);
    }
}

// ---------- load payment lines ----------
$paymentLines = [];
if ((int)($txn['id'] ?? 0) > 0) {
    $st = $pdo->prepare("
        SELECT *
        FROM customer_txn_payments
        WHERE customer_txn_id = :tid
        ORDER BY payment_seq ASC, id ASC
    ");
    $st->execute([':tid' => (int)$txn['id']]);
    $paymentLines = $st->fetchAll();
}

// ---------- calc Paid / Pending (same currency only) ----------
$order_total = (float)($txn['order_total'] ?? 0);
$paid_total  = 0.0;
$txnCurrency = strtoupper(trim((string)($txn['currency'] ?? 'MYR')));
if ($txnCurrency === '') $txnCurrency = 'MYR';

foreach ($paymentLines as $p) {
    $amount = (float)($p['amount'] ?? 0);
    $cur    = strtoupper(trim((string)($p['currency'] ?? '')));
    if ($cur === '') $cur = $txnCurrency;
    if ($cur === $txnCurrency) $paid_total += $amount;
}
$pending = max(0, $order_total - $paid_total);

// ======================================================
// POST: Save
// ======================================================
$errors = [];
$uploadNotes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('app_upload_is_oversized_post') && app_upload_is_oversized_post()) {
        $errors['global'] = function_exists('app_upload_oversized_post_message')
            ? app_upload_oversized_post_message()
            : 'Upload failed: request too large.';
    } else {

    $txn_date       = (string)($_POST['txn_date'] ?? ($txn['txn_date'] ?? ''));
    $do_date        = (string)($_POST['do_date'] ?? ($txn['do_date'] ?? ''));
    $do_number      = $hasColDoNumber ? trim((string)($_POST['do_number'] ?? ($txn['do_number'] ?? ''))) : '';
    $invoice_no     = trim((string)($_POST['invoice_no'] ?? ($txn['invoice_no'] ?? '')));
    $order_total    = (float)($_POST['order_total'] ?? ($txn['order_total'] ?? 0));
    $title          = trim((string)($_POST['title'] ?? ($txn['title'] ?? '')));
    $notes          = trim((string)($_POST['notes'] ?? ($txn['notes'] ?? '')));
    // 注意：此页面没有单独的 sign_receive checkbox。
    // 我们这边默认需要签名/盖章（sign_receive=1），只有 CHOP_ONLY 才视为不需要我们签名。
    $sign_receive   = 1;
    $sign_payer     = isset($_POST['sign_payer'])   ? 1 : 0;
    $recipient_name = trim((string)($_POST['recipient_name'] ?? ($txn['recipient_name'] ?? '')));
    $recipient_nric = trim((string)($_POST['recipient_nric'] ?? ($txn['recipient_nric'] ?? '')));

    // sign mode (our side: signature + chop / chop only)
    $sign_mode = (string)($_POST['sign_mode'] ?? ($txn['sign_mode'] ?? 'SIGN_AND_CHOP'));
    $sign_mode = strtoupper(trim($sign_mode));
    if (!in_array($sign_mode, ['CHOP_ONLY', 'SIGN_AND_CHOP', 'SIGN_ONLY'], true)) {
        $sign_mode = 'SIGN_AND_CHOP';
    }

    // when CHOP_ONLY, we don't require our signature (only company chop)
    if ($sign_mode === 'CHOP_ONLY') $sign_receive = 0;

    $postInKind = strtoupper(trim((string)($_POST['in_kind'] ?? ($txn['in_kind'] ?? 'INVOICE'))));
    if (!in_array($postInKind, $validInKinds, true)) $postInKind = 'INVOICE';

    // Company1 入口：IN 类型和金额从现有交易锁定，不能被前端篡改
    if ($allowFromCompany1) {
        if (isset($txn['in_kind'])) {
            $postInKind = strtoupper(trim((string)$txn['in_kind']));
            if (!in_array($postInKind, $validInKinds, true)) {
                $postInKind = 'INVOICE';
            }
        }
        if (isset($txn['order_total'])) {
            $order_total = (float)$txn['order_total'];
        }
    }

    // ✅ 默认 title（server side：避免空）
    if ($title === '') {
        if ($postInKind === 'RETURN') $title = 'Repayment';
        elseif ($postInKind === 'BONUS') $title = 'Bonus';
        else $title = 'Invoice';
    }

    $payer_company_id = (isset($_POST['payer_company_id']) && $_POST['payer_company_id'] !== '')
        ? (int)$_POST['payer_company_id'] : null;
    $payer_staff_id = (isset($_POST['payer_staff_id']) && $_POST['payer_staff_id'] !== '')
        ? (int)$_POST['payer_staff_id'] : null;

    // document flow type (NORMAL / QUOTATION) if column exists
    $doc_flow_type = (string)($txn['doc_flow_type'] ?? 'NORMAL');
    if ($hasColDocFlowType) {
        $doc_flow_type = strtoupper(trim((string)($_POST['doc_flow_type'] ?? $doc_flow_type)));
        if (!in_array($doc_flow_type, ['NORMAL', 'QUOTATION'], true)) {
            $doc_flow_type = 'NORMAL';
        }
    }

    // signature requirement: if any side signature checked
    $need_signature = ($sign_receive || $sign_payer) ? 1 : 0;

    $txn_currency_in = strtoupper(trim((string)($_POST['txn_currency'] ?? ($txn['currency'] ?? ''))));
    if ($txn_currency_in === '') $txn_currency_in = 'MYR';

    if ($txn_date === '') $errors['txn_date'] = tt('admin.txn_in.err.date_required', 'Date is required');
    if ($order_total <= 0) $errors['order_total'] = tt('admin.txn_in.err.amount_gt_zero', 'Amount must be greater than 0');

    // DO Number: format VMDOyyMM-00X
    // - 只要 do_number 还是空，就按「DO date > txn_date > 今天」选一个日期来自动生成
    if ($hasColDoNumber && $do_number === '') {
        $baseDate = $do_date !== '' ? $do_date : ($txn_date !== '' ? $txn_date : date('Y-m-d'));
        $ym = date('ym', strtotime($baseDate));
        $prefix = 'VMDO' . $ym . '-';
        $stDo = $pdo->prepare("SELECT do_number FROM customer_txn WHERE do_number LIKE :pfx ORDER BY do_number DESC LIMIT 1");
        $stDo->execute([':pfx' => $prefix . '%']);
        $seqNo = 1;
        if ($rDo = $stDo->fetch()) {
            $last3 = (int)substr((string)$rDo['do_number'], -3);
            $seqNo = $last3 + 1;
        }
        $do_number = $prefix . str_pad((string)$seqNo, 3, '0', STR_PAD_LEFT);
    }

    $payPosts = $_POST['pay'] ?? [];

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // delete payment attachments
            $deletePayAttIds = [];
            if (!empty($_POST['delete_pay_att']) && is_array($_POST['delete_pay_att'])) {
                foreach ($_POST['delete_pay_att'] as $xid) {
                    $xid = (int)$xid;
                    if ($xid > 0) $deletePayAttIds[] = $xid;
                }
            }
            if ($deletePayAttIds) {
                $in = implode(',', array_fill(0, count($deletePayAttIds), '?'));
                try {
                    $stDelPayAtt = $pdo->prepare("DELETE FROM customer_txn_payment_attachments WHERE id IN ($in)");
                    $stDelPayAtt->execute(array_values($deletePayAttIds));
                } catch (Throwable $e) {
                }
            }

            // delete txn attachments
            $deleteTxnAttIds = [];
            if (!empty($_POST['delete_txn_att']) && is_array($_POST['delete_txn_att'])) {
                foreach ($_POST['delete_txn_att'] as $xid) {
                    $xid = (int)$xid;
                    if ($xid > 0) $deleteTxnAttIds[] = $xid;
                }
            }
            if ($deleteTxnAttIds) {
                $in = implode(',', array_fill(0, count($deleteTxnAttIds), '?'));
                try {
                    $stDel = $pdo->prepare("DELETE FROM customer_txn_attachments WHERE id IN ($in)");
                    $stDel->execute(array_values($deleteTxnAttIds));
                } catch (Throwable $e) {
                }
            }

            // auto invoice_no (only for INVOICE)
            if ($invoice_no === '' && $postInKind === 'INVOICE') {
                $ym = date('ym', strtotime($txn_date));
                $prefix = "VM{$ym}-";
                $st = $pdo->prepare("
                    SELECT invoice_no
                    FROM customer_txn
                    WHERE invoice_no LIKE :pfx
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $st->execute([':pfx' => $prefix . '%']);
                $seqNo = 1;
                if ($row = $st->fetch()) {
                    $last3 = (int)substr((string)$row['invoice_no'], -3);
                    $seqNo = $last3 + 1;
                }
                $invoice_no = $prefix . str_pad((string)$seqNo, 3, '0', STR_PAD_LEFT);
            }

            if ($postInKind !== 'INVOICE') {
                $invoice_no = '';
            }

            $hasTitle = has_col($pdo, 'customer_txn', 'title');

            // insert/update txn
            if ((int)($txn['id'] ?? 0) <= 0) {
                // 用数组拼 INSERT，避免动态 str_replace 造成 column/value 不一致（1136 / HY093）
                $colsIns = [
                    'customer_id',
                    'txn_type',
                    'in_kind',
                    'txn_date',
                ];
                $valsIns = [
                    ':customer_id',
                    "'IN'",
                    ':in_kind',
                    ':txn_date',
                ];

                if ($hasColDoDate) {
                    $colsIns[] = 'do_date';
                    $valsIns[] = ':do_date';
                }
                if ($hasColDoNumber) {
                    $colsIns[] = 'do_number';
                    $valsIns[] = ':do_number';
                }

                $colsIns[] = 'currency';
                $valsIns[] = ':currency';

                if ($hasTitle) {
                    $colsIns[] = 'title';
                    $valsIns[] = ':title';
                }

                $colsIns = array_merge($colsIns, [
                    'amount',
                    'order_total',
                    'payer_company_id',
                    'payer_staff_id',
                    'invoice_no',
                    'status',
                    'notes',
                    'sign_receive',
                    'sign_payer',
                    'require_signature',
                ]);
                $valsIns = array_merge($valsIns, [
                    '0',
                    ':order_total',
                    ':payer_company_id',
                    ':payer_staff_id',
                    ':invoice_no',
                    "'PENDING'",
                    ':notes',
                    ':sign_receive',
                    ':sign_payer',
                    ':require_signature',
                ]);

                if ($hasColSignMode) {
                    $colsIns[] = 'sign_mode';
                    $valsIns[] = ':sign_mode';
                }

                $colsIns = array_merge($colsIns, [
                    'recipient_name',
                    'recipient_nric',
                    'created_at',
                    'updated_at',
                ]);
                $valsIns = array_merge($valsIns, [
                    ':recipient_name',
                    ':recipient_nric',
                    'NOW()',
                    'NOW()',
                ]);

                $sqlIns = "INSERT INTO customer_txn (`" . implode('`,`', $colsIns) . "`) VALUES (" . implode(',', $valsIns) . ")";
                $bind = [
                    ':customer_id'       => $customer_id,
                    ':in_kind'           => $postInKind,
                    ':txn_date'          => $txn_date,
                    ':currency'          => $txn_currency_in,
                    ':order_total'       => $order_total,
                    ':payer_company_id'  => $payer_company_id,
                    ':payer_staff_id'    => $payer_staff_id,
                    ':invoice_no'        => $invoice_no,
                    ':notes'             => $notes,
                    ':sign_receive'      => $sign_receive,
                    ':sign_payer'        => $sign_payer,
                    ':require_signature' => $need_signature,
                    ':recipient_name'    => $recipient_name,
                    ':recipient_nric'    => $recipient_nric,
                ];
                if ($hasTitle) $bind[':title'] = $title;
                if ($hasColDoDate) $bind[':do_date'] = ($do_date !== '') ? $do_date : null;
                if ($hasColDoNumber) $bind[':do_number'] = $do_number;
                if ($hasColSignMode) $bind[':sign_mode'] = $sign_mode;

                $pdo->prepare($sqlIns)->execute($bind);
                $txnId = (int)$pdo->lastInsertId();
            } else {
                $txnId = (int)$txn['id'];

                if ($hasTitle) {
                    $sqlUpd = "
                        UPDATE customer_txn SET
                          txn_date          = :txn_date,
                          in_kind           = :in_kind,
                          order_total       = :order_total,
                          payer_company_id  = :payer_company_id,
                          payer_staff_id    = :payer_staff_id,
                          invoice_no        = :invoice_no,
                          title             = :title,
                          notes             = :notes,
                          sign_receive      = :sign_receive,
                          sign_payer        = :sign_payer,
                          require_signature = :require_signature,
                          currency          = :currency,
                          recipient_name    = :recipient_name,
                          recipient_nric    = :recipient_nric,
                          updated_at        = NOW()
                        WHERE id = :id
                          AND customer_id = :cid
                    ";
                } else {
                    $sqlUpd = "
                        UPDATE customer_txn SET
                          txn_date          = :txn_date,
                          in_kind           = :in_kind,
                          order_total       = :order_total,
                          payer_company_id  = :payer_company_id,
                          payer_staff_id    = :payer_staff_id,
                          invoice_no        = :invoice_no,
                          notes             = :notes,
                          sign_receive      = :sign_receive,
                          sign_payer        = :sign_payer,
                          require_signature = :require_signature,
                          currency          = :currency,
                          recipient_name    = :recipient_name,
                          recipient_nric    = :recipient_nric,
                          updated_at        = NOW()
                        WHERE id = :id
                          AND customer_id = :cid
                    ";
                }

                $st = $pdo->prepare($sqlUpd);
                $bind = [
                    ':txn_date'          => $txn_date,
                    ':in_kind'           => $postInKind,
                    ':order_total'       => $order_total,
                    ':payer_company_id'  => $payer_company_id,
                    ':payer_staff_id'    => $payer_staff_id,
                    ':invoice_no'        => $invoice_no,
                    ':notes'             => $notes,
                    ':sign_receive'      => $sign_receive,
                    ':sign_payer'        => $sign_payer,
                    ':require_signature' => $need_signature,
                    ':currency'          => $txn_currency_in,
                    ':recipient_name'    => $recipient_name,
                    ':recipient_nric'    => $recipient_nric,
                    ':id'                => $txnId,
                    ':cid'               => $customer_id,
                ];
                if ($hasTitle) $bind[':title'] = $title;

                // optional columns
                if ($hasColDoDate) {
                    $sqlUpd = str_replace(
                        "txn_date          = :txn_date,",
                        "txn_date          = :txn_date,\n                          do_date           = :do_date,",
                        $sqlUpd
                    );
                }
                if ($hasColDoNumber) {
                    if ($hasColDoDate) {
                        $sqlUpd = str_replace("do_date           = :do_date,", "do_date           = :do_date,\n                          do_number         = :do_number,", $sqlUpd);
                    } else {
                        $sqlUpd = str_replace("txn_date          = :txn_date,", "txn_date          = :txn_date,\n                          do_number         = :do_number,", $sqlUpd);
                    }
                }
                if ($hasColSignMode) {
                    $sqlUpd = str_replace(
                        "require_signature = :require_signature,",
                        "require_signature = :require_signature,\n                          sign_mode        = :sign_mode,",
                        $sqlUpd
                    );
                }

                if ($hasColDoDate || $hasColSignMode || $hasColDoNumber) {
                    $st = $pdo->prepare($sqlUpd);
                    if ($hasColDoDate) {
                        $bind[':do_date'] = ($do_date !== '') ? $do_date : null;
                    }
                    if ($hasColDoNumber) {
                        $bind[':do_number'] = $do_number;
                    }
                    if ($hasColSignMode) {
                        $bind[':sign_mode'] = $sign_mode;
                    }
                }

                $st->execute($bind);
            }

            // load existing payments
            $existingPays = [];
            if ($txnId > 0) {
                $stEP = $pdo->prepare("
                    SELECT *
                    FROM customer_txn_payments
                    WHERE customer_txn_id = :tid
                    ORDER BY payment_seq ASC, id ASC
                ");
                $stEP->execute([':tid' => $txnId]);
                foreach ($stEP->fetchAll() as $ep) {
                    $existingPays[(int)$ep['id']] = $ep;
                }
            }

            $existingPayIds = array_keys($existingPays);
            $usedPayIds     = [];
            $toDeletePayIds = [];

            $totalPaidMain   = 0.0;
            $txnMainCurrency = $txn_currency_in;

            $seq = 1;

            $payAttachDir = __DIR__ . '/../../../uploads/txn_payment';
            if (!is_dir($payAttachDir)) @mkdir($payAttachDir, 0777, true);

            foreach ($payPosts as $idx => $row) {

                $payId  = isset($row['id']) ? (int)$row['id'] : 0;

                $pay_date        = trim((string)($row['pay_date'] ?? ''));
                $bank_account_id = (int)($row['bank_account_id'] ?? 0);
                $currency        = strtoupper(trim((string)($row['currency'] ?? '')));
                if ($currency === '') $currency = $txnMainCurrency;

                $amount  = (float)($row['amount'] ?? 0);
                $fx_rate = ($currency === 'MYR') ? null : (float)($row['fx_rate'] ?? 0);
                $or_no   = trim((string)($row['or_no'] ?? ''));

                $isBlankRow = ($pay_date === '' && $amount <= 0 && $bank_account_id <= 0);

                // ---------- blank row = delete ----------
                if ($isBlankRow) {
                    if ($payId > 0 && in_array($payId, $existingPayIds, true)) $toDeletePayIds[] = $payId;
                    continue;
                }

                // ---------- auto OR ----------
                if ($or_no === '') {
                    $ym    = date('ym', strtotime($pay_date ?: $txn_date));
                    $last4 = ($invoice_no !== '' ? substr($invoice_no, -4) : '0000');
                    $or_no = 'OR' . $ym . $last4 . '/' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                }

                // ======================
                // SAVE PAYMENT (UPsert)
                // ======================
                if ($payId > 0 && isset($existingPays[$payId])) {

                    // update
                    $pdo->prepare("
            UPDATE customer_txn_payments SET
              payment_seq     = :seq,
              or_no           = :or_no,
              pay_date        = :pay_date,
              bank_account_id = :bank_account_id,
              currency        = :currency,
              fx_rate         = :fx_rate,
              amount          = :amount,
              updated_at      = NOW()
            WHERE id = :id AND customer_txn_id = :tid
        ")->execute([
                        ':seq' => $seq,
                        ':or_no' => $or_no,
                        ':pay_date' => $pay_date,
                        ':bank_account_id' => $bank_account_id,
                        ':currency' => $currency,
                        ':fx_rate' => $fx_rate,
                        ':amount' => $amount,
                        ':id' => $payId,
                        ':tid' => $txnId,
                    ]);
                } else {

                    // insert
                    $pdo->prepare("
            INSERT INTO customer_txn_payments
            (customer_txn_id, payment_seq, or_no, pay_date, bank_account_id, currency, fx_rate, amount, created_at, updated_at)
            VALUES
            (:tid, :seq, :or_no, :pay_date, :bank_account_id, :currency, :fx_rate, :amount, NOW(), NOW())
        ")->execute([
                        ':tid' => $txnId,
                        ':seq' => $seq,
                        ':or_no' => $or_no,
                        ':pay_date' => $pay_date,
                        ':bank_account_id' => $bank_account_id,
                        ':currency' => $currency,
                        ':fx_rate' => $fx_rate,
                        ':amount' => $amount,
                    ]);

                    $payId = (int)$pdo->lastInsertId();
                }

                $usedPayIds[] = $payId;

                // ======================
                // 🔥 FULL BANK SYNC (old + new)
                // ======================
                $marker = "[INPAY#{$payId}]";

                // 1) delete old bank txn by bank_txn_id (if exists)
                $old = $existingPays[$payId] ?? [];
                $oldBid = (int)($old['bank_txn_id'] ?? 0);
                if ($oldBid > 0) {
                    try {
                        $pdo->prepare("DELETE FROM company_bank_txn WHERE id=?")->execute([$oldBid]);
                    } catch (Throwable $e) {
                    }
                }

                // 2) fallback delete by marker (clean duplicates)
                bank_txn_delete_by_marker($pdo, $marker, null);

                // 3) rebuild bank txn if bank selected
                $newBankTxnId = null;
                if ($bank_account_id > 0) {
                    $desc = 'Customer payment for ' . ($invoice_no ?: ('IN txn #' . $txnId));

                    $newBankTxnId = bank_txn_sync_for_in_payment(
                        $pdo,
                        (int)$txnId,
                        (int)$payId,
                        (int)$bank_account_id,
                        (string)($pay_date ?: $txn_date),
                        (string)$or_no,
                        (string)$currency,
                        (float)$amount,
                        ($currency === 'MYR') ? null : (float)$fx_rate,
                        (string)$desc,
                        (int)$customer_id
                    );
                }

                // 4) save bank_txn_id back (NULL if no bank)
                try {
                    $pdo->prepare("UPDATE customer_txn_payments SET bank_txn_id = :bid WHERE id = :pid")
                        ->execute([':bid' => $newBankTxnId, ':pid' => $payId]);
                } catch (Throwable $e) {
                }

                // ======================
                // attachments upload (both old + new)
                // ======================
                if (!empty($_FILES['pay']['name'][$idx]['attachments']) && is_array($_FILES['pay']['name'][$idx]['attachments'])) {
                    $names = $_FILES['pay']['name'][$idx]['attachments'];
                    $tmpn  = $_FILES['pay']['tmp_name'][$idx]['attachments'];
                    $types = $_FILES['pay']['type'][$idx]['attachments'];
                    $errs  = $_FILES['pay']['error'][$idx]['attachments'];
                    $cnt   = count($names);

                    for ($i = 0; $i < $cnt; $i++) {
                        $orig = (string)($names[$i] ?? '');
                        if ($orig === '') continue;
                        $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
                        if ($err !== UPLOAD_ERR_OK) {
                            $uploadNotes[] = function_exists('app_upload_error_message')
                                ? app_upload_error_message($err, $orig)
                                : 'File "' . $orig . '" upload error (code ' . $err . ').';
                            continue;
                        }
                        $tmpF = (string)($tmpn[$i] ?? '');
                        if (!is_uploaded_file($tmpF)) continue;

                        $ext  = pathinfo($orig, PATHINFO_EXTENSION);
                        $safe = 'pay_' . $txnId . '_' . $payId . '_' . uniqid('', true);
                        if ($ext !== '') $safe .= '.' . $ext;

                        $absPath = $payAttachDir . '/' . $safe;
                        if (!@move_uploaded_file($tmpF, $absPath)) {
                            $uploadNotes[] = 'File "' . $orig . '" failed to move.';
                            continue;
                        }

                        $relPath = 'uploads/txn_payment/' . $safe;
                        $mime    = (string)($types[$i] ?? '');

                        try {
                            $pdo->prepare("
                    INSERT INTO customer_txn_payment_attachments
                      (payment_id, file_path, file_name, mime_type, created_at)
                    VALUES
                      (:payment_id, :file_path, :file_name, :mime_type, NOW())
                ")->execute([
                                ':payment_id' => $payId,
                                ':file_path'  => $relPath,
                                ':file_name'  => $orig,
                                ':mime_type'  => $mime,
                            ]);
                        } catch (Throwable $e) {
                        }
                    }
                }

                // sum main currency only
                if (strtoupper($currency) === strtoupper($txnMainCurrency)) $totalPaidMain += $amount;

                $seq++;
            }

            // delete removed payments
            $usedPayIds = array_values(array_unique($usedPayIds));
            foreach ($existingPayIds as $epid) {
                if (!in_array($epid, $usedPayIds, true) && !in_array($epid, $toDeletePayIds, true)) {
                    $toDeletePayIds[] = $epid;
                }
            }

            if ($toDeletePayIds) {
                $toDeletePayIds = array_values($toDeletePayIds);
                $in = implode(',', array_fill(0, count($toDeletePayIds), '?'));

                // delete bank txn by bank_txn_id if possible
                $stGetBank = $pdo->prepare("SELECT bank_txn_id, id FROM customer_txn_payments WHERE id IN ($in)");
                $stGetBank->execute($toDeletePayIds);
                $rowsBank = $stGetBank->fetchAll();
                $bankIdsToDelete = [];
                $payIdsToMarker = [];
                foreach ($rowsBank as $rb) {
                    $pid = (int)($rb['id'] ?? 0);
                    if ($pid > 0) $payIdsToMarker[] = $pid;

                    $bid = (int)($rb['bank_txn_id'] ?? 0);
                    if ($bid > 0) $bankIdsToDelete[] = $bid;
                }
                if ($bankIdsToDelete) {
                    $bankIdsToDelete = array_values($bankIdsToDelete);
                    $inB = implode(',', array_fill(0, count($bankIdsToDelete), '?'));
                    try {
                        $pdo->prepare("DELETE FROM company_bank_txn WHERE id IN ($inB)")->execute($bankIdsToDelete);
                    } catch (Throwable $e) {
                    }
                }

                // ✅ fallback delete by marker
                foreach ($payIdsToMarker as $pidDel) {
                    $marker = "[INPAY#" . (int)$pidDel . "]";
                    bank_txn_delete_by_marker($pdo, $marker, null);
                }

                // delete payment attachments
                try {
                    $pdo->prepare("DELETE FROM customer_txn_payment_attachments WHERE payment_id IN ($in)")->execute($toDeletePayIds);
                } catch (Throwable $e) {
                }

                // delete payment rows
                $pdo->prepare("DELETE FROM customer_txn_payments WHERE id IN ($in)")->execute($toDeletePayIds);
            }

            // notes attachments
            if (!empty($_FILES['notes_attachments']['name']) && is_array($_FILES['notes_attachments']['name'])) {
                $notesDir = __DIR__ . '/../../../uploads/txn_notes';
                if (!is_dir($notesDir)) @mkdir($notesDir, 0777, true);

                $names = $_FILES['notes_attachments']['name'];
                $tmpn  = $_FILES['notes_attachments']['tmp_name'];
                $types = $_FILES['notes_attachments']['type'];
                $errs  = $_FILES['notes_attachments']['error'];
                $cnt   = count($names);

                for ($i = 0; $i < $cnt; $i++) {
                    $orig = (string)($names[$i] ?? '');
                    if ($orig === '') continue;
                    $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
                    if ($err !== UPLOAD_ERR_OK) {
                        $uploadNotes[] = function_exists('app_upload_error_message')
                            ? app_upload_error_message($err, $orig)
                            : 'File "' . $orig . '" upload error (code ' . $err . ').';
                        continue;
                    }
                    $tmpF = (string)($tmpn[$i] ?? '');
                    if (!is_uploaded_file($tmpF)) continue;

                    $ext  = pathinfo($orig, PATHINFO_EXTENSION);
                    $safe = 'txn_' . $txnId . '_' . uniqid('', true);
                    if ($ext !== '') $safe .= '.' . $ext;

                    $absPath = $notesDir . '/' . $safe;
                    if (!@move_uploaded_file($tmpF, $absPath)) {
                        $uploadNotes[] = 'File "' . $orig . '" failed to move.';
                        continue;
                    }

                    $relPath = 'uploads/txn_notes/' . $safe;
                    $mime    = (string)($types[$i] ?? '');

                    try {
                        $pdo->prepare("
                            INSERT INTO customer_txn_attachments
                              (customer_txn_id, file_path, file_name, mime_type, created_at)
                            VALUES
                              (:txn_id, :file_path, :file_name, :mime_type, NOW())
                        ")->execute([
                            ':txn_id'    => $txnId,
                            ':file_path' => $relPath,
                            ':file_name' => $orig,
                            ':mime_type' => $mime,
                        ]);
                    } catch (Throwable $e) {
                    }
                }
            }

            // ✅ amount 存储规则（保留你原本那套）
            $amountStore = ($postInKind === 'INVOICE') ? $totalPaidMain : $order_total;
            $pdo->prepare("UPDATE customer_txn SET amount = :amt WHERE id = :id")
                ->execute([':amt' => $amountStore, ':id' => $txnId]);

            // ✅ 最关键：统一重算 status（勾签名才锁confirm）
            recompute_in_txn_status($pdo, (int)$txnId);

            // ✅ 文档流转状态（INVOICE / QUOTATION）
            if ($hasColDocFlowType || $hasColDocFlowStat) {
                $flowType   = $doc_flow_type;                           // NORMAL / QUOTATION
                $flowStatus = (string)($txn['doc_flow_status'] ?? '');  // keep existing if any
                if ((int)($txn['id'] ?? 0) <= 0 || $flowStatus === '') {
                    // new record or previously empty -> start as DRAFT
                    $flowStatus = 'DRAFT';
                }

                $sqlFlow = "UPDATE customer_txn SET ";
                $sets    = [];
                $paramsF = [':id' => $txnId];
                if ($hasColDocFlowType) {
                    $sets[]              = "doc_flow_type = :dft";
                    $paramsF[':dft']     = $flowType;
                }
                if ($hasColDocFlowStat) {
                    $sets[]              = "doc_flow_status = :dfs";
                    $paramsF[':dfs']     = $flowStatus;
                }
                if ($sets) {
                    $sqlFlow .= implode(', ', $sets) . " WHERE id = :id";
                    $pdo->prepare($sqlFlow)->execute($paramsF);
                }
            }

            // Require customer signature per document (Quotation / Invoice / DO)
            if ($hasColRequireSignQuotation || $hasColRequireSignInvoice || $hasColRequireSignDo) {
                $signQuotation = isset($_POST['require_sign_quotation']) ? 1 : 0;
                $signInvoice   = isset($_POST['require_sign_invoice']) ? 1 : 0;
                $signDo        = isset($_POST['require_sign_do']) ? 1 : 0;
                $setsDoc = [];
                $paramsDoc = [':id' => $txnId];
                if ($hasColRequireSignQuotation) { $setsDoc[] = "require_sign_quotation = :rq"; $paramsDoc[':rq'] = $signQuotation; }
                if ($hasColRequireSignInvoice)   { $setsDoc[] = "require_sign_invoice = :ri";   $paramsDoc[':ri'] = $signInvoice; }
                if ($hasColRequireSignDo)        { $setsDoc[] = "require_sign_do = :rd";        $paramsDoc[':rd'] = $signDo; }
                if ($setsDoc) {
                    $pdo->prepare("UPDATE customer_txn SET " . implode(', ', $setsDoc) . " WHERE id = :id")->execute($paramsDoc);
                }
            }

            if (function_exists('audit_log')) {
                audit_log(
                    $pdo,
                    (((int)($txn['id'] ?? 0) > 0) ? 'TXN.IN.UPDATE' : 'TXN.IN.CREATE'),
                    [
                        'customer_id'       => $customer_id,
                        'customer_name'     => $customer['name'] ?? '',
                        'txn_id'            => $txnId,
                        'title'             => $title,
                        'invoice_no'        => $invoice_no,
                        'order_total'       => $order_total,
                        'paid_total'        => $totalPaidMain,
                        'require_signature' => $need_signature,
                        'in_kind'           => $postInKind,
                        'payer_company_id'  => $payer_company_id,
                        'payer_staff_id'    => $payer_staff_id,
                    ],
                    'customer_txn',
                    $txnId
                );
            }

            $pdo->commit();

            // 保存成功后，根据入口返回不同页面：
            // - admin 入口：回 admin/customers/txn_edit_in.php
            // - Company1 入口：回 user/company1/txn_edit_in.php
            if ($allowFromCompany1) {
                header('Location: ' . url('user/company1/txn_edit_in.php?customer_id=' . $customer_id . '&id=' . $txnId . '&ok=1'));
            } else {
                header('Location: ' . url('admin/customers/txn_edit_in.php?customer_id=' . $customer_id . '&id=' . $txnId . '&ok=1'));
            }
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            // 快速定位 HY093：把完整异常(含行号/堆栈)写进 error_log
            // 这样你不用盲猜是哪一条 SQL/execute 出问题
            try {
                error_log("[TXN_EDIT_IN SAVE ERROR] " . (string)$e);
            } catch (Throwable $e2) {
            }
            $errors['global'] = tt('admin.txn_in.err.save_failed', 'Save error: %s', ['s' => $e->getMessage()]);
            if ($errors['global'] === 'Save error: %s') $errors['global'] = 'Save error: ' . $e->getMessage();
            // 同时把文件行号带出来（本地更快定位）
            $errors['global'] .= ' @ ' . basename((string)$e->getFile()) . ':' . (int)$e->getLine();
        }
    }

    // rewrite txn for re-render
    $txn['txn_date']          = $txn_date;
    $txn['do_date']           = $do_date;
    $txn['do_number']         = $do_number;
    $txn['invoice_no']        = $invoice_no;
    $txn['order_total']       = $order_total;
    $txn['title']             = $title;
    $txn['notes']             = $notes;
    $txn['sign_receive']      = $sign_receive;
    $txn['sign_payer']        = $sign_payer;
    $txn['require_signature'] = $need_signature;
    $txn['currency']          = $txn_currency_in;
    $txn['in_kind']           = $postInKind;
    $txn['doc_flow_type']     = $doc_flow_type;
    $txn['sign_mode']         = $sign_mode;
    $txn['recipient_name']    = $recipient_name;
    $txn['recipient_nric']    = $recipient_nric;
    $txn['payer_company_id']  = $payer_company_id;
    $txn['payer_staff_id']    = $payer_staff_id;

    // rebuild paymentLines for UI
    $paymentLines = [];
    foreach ($payPosts as $row) {
        $paymentLines[] = [
            'id'              => $row['id'] ?? '',
            'or_no'           => $row['or_no'] ?? '',
            'pay_date'        => $row['pay_date'] ?? '',
            'bank_account_id' => $row['bank_account_id'] ?? '',
            'currency'        => $row['currency'] ?? '',
            'fx_rate'         => $row['fx_rate'] ?? '',
            'amount'          => $row['amount'] ?? '',
        ];
    }

    // recalc paid/pending
    $paid_total = 0.0;
    $txnCurrency = strtoupper(trim((string)$txn_currency_in));
    if ($txnCurrency === '') $txnCurrency = 'MYR';
    foreach ($paymentLines as $p) {
        $amount = (float)($p['amount'] ?? 0);
        $cur    = strtoupper(trim((string)($p['currency'] ?? '')));
        if ($cur === '') $cur = $txnCurrency;
        if ($cur === $txnCurrency) $paid_total += $amount;
    }
    $pending = max(0, (float)($txn['order_total'] ?? 0) - $paid_total);
    }
}

// ======================================================
// reload attachments after save
// ======================================================
$paymentAttachments = [];
$txnAttachments     = [];

if ((int)($txn['id'] ?? 0) > 0) {
    $st = $pdo->prepare("
        SELECT *
        FROM customer_txn_payments
        WHERE customer_txn_id = :tid
        ORDER BY payment_seq ASC, id ASC
    ");
    $st->execute([':tid' => (int)($txn['id'] ?? 0)]);
    $paymentLines = $st->fetchAll();

    if ($paymentLines) {
        $payIds = [];
        foreach ($paymentLines as $pl) {
            $pid = (int)($pl['id'] ?? 0);
            if ($pid > 0) $payIds[] = $pid;
        }
        $payIds = array_values(array_unique($payIds));
        if ($payIds) {
            $in = implode(',', array_fill(0, count($payIds), '?'));
            try {
                $stA = $pdo->prepare("
                    SELECT *
                    FROM customer_txn_payment_attachments
                    WHERE payment_id IN ($in)
                    ORDER BY id ASC
                ");
                $stA->execute($payIds);
                $rowsA = $stA->fetchAll();
                foreach ($rowsA as $ra) {
                    $pid = (int)($ra['payment_id'] ?? 0);
                    if ($pid > 0) $paymentAttachments[$pid][] = $ra;
                }
            } catch (Throwable $e) {
            }
        }
    }

    try {
        $stT = $pdo->prepare("
            SELECT *
            FROM customer_txn_attachments
            WHERE customer_txn_id = :tid
            ORDER BY id ASC
        ");
        $stT->execute([':tid' => (int)($txn['id'] ?? 0)]);
        $txnAttachments = $stT->fetchAll();
    } catch (Throwable $e) {
    }
}

// ---------- page ----------
$page_title = (tt('admin.txn_in.page_title', 'IN Transaction') . ' · ' . ($customer['name'] ?? 'Customer'));
if (!$allowFromCompany1) {
    include __DIR__ . '/../include/header.php';
}

$isInvoiceKind = (($txn['in_kind'] ?? 'INVOICE') === 'INVOICE');
?>
<style>
    .admin-card-narrow {
        max-width: 1200px;
    }

    .txn-notes-textarea {
        min-height: 260px;
        resize: vertical;
    }

    .amount-row {
        display: flex;
        gap: 6px;
    }

    .amount-row .amount-input {
        flex: 2;
    }

    .amount-row .amount-currency-input {
        flex: 1;
    }

    .attach-cell {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-size: 12px;
    }

    .btn-attach-add {
        align-self: flex-start;
        font-size: 12px;
        padding: 2px 10px;
    }

    .attach-inputs {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .attach-input-row {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    .attach-file-input {
        flex: 1;
        padding: 2px 6px;
        font-size: 11px;
        height: auto;
    }

    .attach-remove {
        border: 1px solid #9ca3af;
        background: #e5e7eb;
        color: #111827;
        cursor: pointer;
        font-size: 12px;
        padding: 0 8px;
        border-radius: 999px;
        line-height: 18px;
    }

    .attach-existing {
        border-top: 1px dashed #e5e7eb;
        margin-top: 4px;
        padding-top: 3px;
        font-size: 11px;
        color: #4b5563;
    }

    .attach-existing a {
        display: inline-block;
        max-width: 180px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .attach-existing-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    @media (max-width:768px) {

        .form-page-header,
        .form-section-header {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }

        .form-page-meta {
            width: 100%;
        }

        .payment-table-wrapper {
            overflow-x: auto;
        }

        .amount-row {
            flex-direction: column;
        }
    }
</style>

<div class="admin-main">
    <div class="admin-main-inner">
        <div class="admin-card admin-card-elevated admin-card-narrow">

            <div class="form-page-header">
                <div>
                    <div class="form-page-eyebrow"><?= h(tt('admin.txn_in.eyebrow', 'IN transaction')) ?></div>
                    <h2 class="form-page-title"><?= h($customer['name'] ?? '') ?></h2>
                    <div class="form-page-subtitle"><?= h(tt('admin.txn_in.subtitle', 'Create / edit one IN order with multiple payments.')) ?></div>
                </div>
                <div class="form-page-meta">
                    <?php
                    $flowTypeNow = strtoupper((string)($txn['doc_flow_type'] ?? 'NORMAL'));
                    if (!in_array($flowTypeNow, ['NORMAL', 'QUOTATION'], true)) $flowTypeNow = 'NORMAL';
                    $flowLabelNow = ($flowTypeNow === 'QUOTATION') ? 'Quotation' : 'Invoice';

                    // Back：优先用调用方传入的 back（例如 user 端），否则退回默认列表
                    $backPath = '';
                    if (!empty($GLOBALS['_TXN_IN_BACK_URL_FROM_PORTAL'] ?? '')) {
                        $backPath = (string)$GLOBALS['_TXN_IN_BACK_URL_FROM_PORTAL']; // 这里通常已经是完整 URL
                    } else {
                        $backPath = $allowFromCompany1
                            ? 'user/company1/txn_list.php?customer_id=' . (int)$customer_id
                            : 'admin/customers/txn_list.php?customer_id=' . (int)$customer_id;
                    }
                    $docBase = $allowFromCompany1
                        ? 'user/company1/txn_doc_in.php'
                        : 'admin/customers/txn_doc_in.php';
                    ?>
                    <div style="margin-bottom:8px;font-size:12px;color:#6b7280;">
                        Flow: <strong><?= h($flowLabelNow) ?></strong>
                    </div>
                    <?php
                    // 如果 backPath 已经是绝对 URL，就直接用；否则通过 url() 生成
                    $backHref = (preg_match('~^https?://~i', $backPath) || str_starts_with($backPath, '/'))
                        ? $backPath
                        : url($backPath);
                    ?>
                    <a href="<?= h($backHref) ?>" class="btn btn-light">
                        <?= h(tt('admin.txn_in.back', '← Back to transactions')) ?>
                    </a>
                    <?php if (!empty($txn['id'])): ?>
                        <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=QUOTATION')) ?>" class="btn btn-light" style="margin-top:6px;">
                            View Quotation
                        </a>
                        <?php if ($flowTypeNow === 'NORMAL' || trim((string)($txn['invoice_no'] ?? '')) !== ''): ?>
                            <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=INVOICE')) ?>" class="btn btn-light" style="margin-top:6px;">View Invoice</a>
                            <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=DO')) ?>" class="btn btn-light" style="margin-top:6px;">View DO</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($errors['global'])): ?>
                <div class="alert-error" style="margin-bottom:12px;"><?= h($errors['global']) ?></div>
            <?php elseif ($ok === '1'): ?>
                <div class="alert-success" style="margin-bottom:12px;"><?= h(tt('admin.txn_in.saved', 'IN transaction saved.')) ?></div>
            <?php endif; ?>
            <?php if ($uploadNotes): ?>
                <div class="alert-error" style="margin-bottom:12px;">
                    <?php foreach ($uploadNotes as $note): ?>
                        <div><?= h($note) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="form-layout">
                <input type="hidden" name="customer_id" value="<?= (int)$customer_id ?>">
                <input type="hidden" name="id" value="<?= (int)($txn['id'] ?? 0) ?>">
                <input type="hidden" name="doc_flow_type" value="<?= h((string)($txn['doc_flow_type'] ?? 'NORMAL')) ?>">

                <div class="form-section">
                    <div class="form-section-header">
                        <div>
                            <div class="form-section-title"><?= h(tt('admin.txn_in.basic.title', 'Basic details')) ?></div>
                            <div class="form-section-desc"><?= h(tt('admin.txn_in.basic.desc', 'Date, type, amount and main currency.')) ?></div>
                        </div>
                    </div>

                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label class="field-label">
                                <?= h(tt('admin.txn_in.field.date', 'Date')) ?> <span class="field-required">*</span>
                            </label>
                            <input type="date" name="txn_date" class="form-control" value="<?= h($txn['txn_date'] ?? '') ?>">
                            <?php if (isset($errors['txn_date'])): ?>
                                <div class="form-error"><?= h($errors['txn_date']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="field-label"><?= h(tt('admin.txn_in.field.in_type', 'IN type')) ?></label>
                            <select name="in_kind" id="in_kind_select" class="form-control" <?= $allowFromCompany1 ? 'disabled' : '' ?>>
                                <option value="INVOICE" <?= (($txn['in_kind'] ?? 'INVOICE') === 'INVOICE') ? 'selected' : '' ?>>
                                    <?= h(tt('admin.txn_in.in_kind.invoice', 'Invoice / normal IN')) ?>
                                </option>
                                <option value="RETURN" <?= (($txn['in_kind'] ?? 'INVOICE') === 'RETURN') ? 'selected' : '' ?>>
                                    <?= h(tt('admin.txn_in.in_kind.return', 'Repayment / return capital')) ?>
                                </option>
                                <option value="BONUS" <?= (($txn['in_kind'] ?? 'INVOICE') === 'BONUS') ? 'selected' : '' ?>>
                                    <?= h(tt('admin.txn_in.in_kind.bonus', 'Bonus / profit share')) ?>
                                </option>
                            </select>
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                <?= h(tt('admin.txn_in.in_kind.help', 'INVOICE = invoice; RETURN = capital; BONUS = others')) ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="field-label"><?= h(tt('admin.txn_in.field.invoice_no', 'Invoice No')) ?></label>
                            <input type="text"
                                name="invoice_no"
                                id="invoice_no_input"
                                class="form-control"
                                value="<?= h($txn['invoice_no'] ?? '') ?>"
                                placeholder="<?= h(tt('admin.txn_in.invoice_ph', 'Auto-filled; change if needed')) ?>"
                                <?= $isInvoiceKind ? '' : 'readonly style="background:#f9fafb;"' ?>>
                            <?php if (!$isInvoiceKind): ?>
                                <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                    <?= h(tt('admin.txn_in.invoice_disabled_help', 'RETURN / BONUS do not use invoice no.')) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="field-label"><?= h(tt('admin.txn_in.field.title', 'Title')) ?></label>
                            <input type="text"
                                name="title"
                                id="title_input"
                                class="form-control"
                                value="<?= h($txn['title'] ?? '') ?>"
                                placeholder="<?= h(tt('admin.txn_in.title_ph', 'Auto: Invoice / Repayment / Bonus')) ?>">
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                <?= h(tt('admin.txn_in.title_help', 'If empty / default, it will auto-fill based on IN type.')) ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="field-label">
                                <?= h(tt('admin.txn_in.field.amount', 'Amount')) ?> <span class="field-required">*</span>
                            </label>
                            <div class="amount-row">
                                <input type="number" step="0.01" min="0" name="order_total" class="form-control amount-input"
                                    value="<?= h($txn['order_total'] ?? 0) ?>" <?= $allowFromCompany1 ? 'readonly style="background:#f9fafb;"' : '' ?>>
                                <input type="text" name="txn_currency" class="form-control amount-currency-input"
                                    value="<?= h($txn['currency'] ?? 'MYR') ?>"
                                    placeholder="<?= h(tt('admin.txn_in.currency_ph', 'MYR / USD / SGD ...')) ?>">
                            </div>
                            <?php if (isset($errors['order_total'])): ?>
                                <div class="form-error"><?= h($errors['order_total']) ?></div>
                            <?php endif; ?>
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                <?= h(tt('admin.txn_in.amount_help', 'Paid / Pending are calculated in the same currency.')) ?>
                            </div>
                        </div>

                        <?php if ($hasColDoDate): ?>
                        <div class="form-group">
                            <label class="field-label">DO date</label>
                            <input type="date" name="do_date" class="form-control" value="<?= h($txn['do_date'] ?? '') ?>">
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">Optional. Only used for Invoice / DO documents.</div>
                        </div>
                        <?php endif; ?>
                        <?php if ($hasColDoNumber): ?>
                        <div class="form-group">
                            <label class="field-label">DO. Number</label>
                            <input type="text" name="do_number" class="form-control" value="<?= h($txn['do_number'] ?? '') ?>" placeholder="e.g. VMDO2601-001">
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">Format: VMDOyyMM-00X (e.g. VMDO2601-001). Leave blank with DO date set to auto-generate.</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div style="margin-top:8px;font-size:13px;">
                        <div>
                            <?= h(tt('admin.txn_in.paid_so_far', 'Paid so far')) ?> (<?= h($txn['currency'] ?? 'MYR') ?>):
                            <strong><?= number_format((float)$paid_total, 2) ?></strong>
                        </div>
                        <div>
                            <?= h(tt('admin.txn_in.pending', 'Pending')) ?> (<?= h($txn['currency'] ?? 'MYR') ?>):
                            <strong style="color:<?= $pending <= 0 ? '#059669' : '#b91c1c' ?>;">
                                <?= number_format((float)$pending, 2) ?>
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- Payments -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div>
                            <div class="form-section-title"><?= h(tt('admin.txn_in.payments.title', 'Payment for this order')) ?></div>
                            <div class="form-section-desc"><?= h(tt('admin.txn_in.payments.desc', 'Add one or more payment lines. Each line will also create a bank transaction.')) ?></div>
                        </div>
                        <div>
                            <button type="button" id="btnAddPayment" class="btn btn-light"><?= h(tt('admin.txn_in.payments.add_line', '+ Add line')) ?></button>
                        </div>
                    </div>

                    <div class="payment-table-wrapper">
                        <table class="table" id="paymentTable">
                            <thead>
                                <tr>
                                    <th style="width:140px;"><?= h(tt('admin.txn_in.col.or_no', 'Official Receipt No')) ?></th>
                                    <th style="width:120px;"><?= h(tt('admin.txn_in.col.pay_date', 'Date')) ?></th>
                                    <th style="width:130px;"><?= h(tt('admin.txn_in.col.amount', 'Amount')) ?></th>
                                    <th style="width:220px;"><?= h(tt('admin.txn_in.col.bank', 'Bank')) ?></th>
                                    <th style="width:110px;"><?= h(tt('admin.txn_in.col.currency', 'Currency')) ?></th>
                                    <th style="width:120px;"><?= h(tt('admin.txn_in.col.fx_rate', 'FX → MYR')) ?></th>
                                    <th style="width:210px;"><?= h(tt('admin.txn_in.col.attach', 'Payment Attachment')) ?></th>
                                    <th style="width:120px;"><?= h(tt('admin.txn_in.col.receipt', 'Receipt / View')) ?></th>
                                    <th style="width:80px;"><?= h(tt('admin.txn_in.col.action', 'Action')) ?></th>
                                </tr>
                            </thead>
                            <tbody id="paymentTableBody">
                                <?php
                                $rowIndex = 0;
                                if ($paymentLines):
                                    foreach ($paymentLines as $pl):
                                        $rowIndex++;
                                        $curFromDb = $pl['currency'] ?? ($txn['currency'] ?? 'MYR');
                                        $cur       = $curFromDb ?: 'MYR';
                                        $isMyr     = strtoupper($cur) === 'MYR';
                                        $pid       = (int)($pl['id'] ?? 0);
                                        $savedAtts = ($pid > 0 && isset($paymentAttachments[$pid])) ? $paymentAttachments[$pid] : [];
                                ?>
                                        <tr data-row-index="<?= $rowIndex ?>">
                                            <td>
                                                <input type="hidden" name="pay[<?= $rowIndex ?>][id]" value="<?= $pid ?>">
                                                <input type="text"
                                                    name="pay[<?= $rowIndex ?>][or_no]"
                                                    class="form-control"
                                                    value="<?= h($pl['or_no'] ?? '') ?>"
                                                    placeholder="<?= h(tt('admin.txn_in.or_ph', 'Auto if empty')) ?>">
                                            </td>
                                            <td>
                                                <input type="date" name="pay[<?= $rowIndex ?>][pay_date]" class="form-control" value="<?= h($pl['pay_date'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" name="pay[<?= $rowIndex ?>][amount]" class="form-control" value="<?= h($pl['amount'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <select name="pay[<?= $rowIndex ?>][bank_account_id]" class="form-control">
                                                    <option value=""><?= h(tt('admin.txn_in.select', '— Select —')) ?></option>
                                                    <?php foreach ($bankRows as $b): ?>
                                                        <option value="<?= (int)$b['id'] ?>" <?= ((int)($pl['bank_account_id'] ?? 0) === (int)$b['id']) ? 'selected' : '' ?>>
                                                            <?= h(bank_label($b)) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text"
                                                    name="pay[<?= $rowIndex ?>][currency]"
                                                    class="form-control currency-input"
                                                    value="<?= h($curFromDb) ?>"
                                                    placeholder="<?= h($txn['currency'] ?? 'MYR') ?>">
                                            </td>
                                            <td>
                                                <input type="number"
                                                    step="0.000001"
                                                    min="0"
                                                    name="pay[<?= $rowIndex ?>][fx_rate]"
                                                    class="form-control fx-input"
                                                    value="<?= h($pl['fx_rate'] ?? '') ?>"
                                                    style="<?= $isMyr ? 'display:none;' : '' ?>"
                                                    placeholder="<?= h(tt('admin.txn_in.fx_ph', 'e.g. 4.700000')) ?>">
                                            </td>
                                            <td>
                                                <div class="attach-cell" data-pay-index="<?= $rowIndex ?>">
                                                    <button type="button" class="btn btn-xs btn-light btn-attach-add"><?= h(tt('admin.txn_in.attach.add', '+ Add')) ?></button>
                                                    <div class="attach-inputs"></div>

                                                    <?php if ($savedAtts): ?>
                                                        <div class="attach-existing">
                                                            <?= h(tt('admin.txn_in.attach.saved', 'Saved:')) ?>
                                                            <?php
                                                            $num = 0;
                                                            foreach ($savedAtts as $att):
                                                                $num++;
                                                                $hrefPath = upload_href($att['file_path'] ?? '');
                                                                if ($hrefPath === '') continue;

                                                                $text = trim((string)($att['file_name'] ?? ''));
                                                                if ($text === '' || preg_match('/^(pay|txn)_\d+_/i', $text)) {
                                                                    $text = sprintf(tt('admin.txn_in.attach.default_name', 'Attachment %d'), $num);
                                                                }
                                                                $attId = (int)($att['id'] ?? 0);
                                                            ?>
                                                                <div class="attach-existing-item">
                                                                    <a href="<?= h(url($hrefPath)) ?>"><?= h($text) ?></a>
                                                                    <?php if ($attId > 0): ?>
                                                                        <label style="font-size:11px;color:#b91c1c;cursor:pointer;">
                                                                            <input type="checkbox" name="delete_pay_att[]" value="<?= $attId ?>"> <?= h(tt('admin.txn_in.attach.delete', 'Delete')) ?>
                                                                        </label>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (!empty($pl['id'] ?? null) && !empty($txn['id'])): ?>
                                                    <?php if (($txn['in_kind'] ?? 'INVOICE') === 'INVOICE'): ?>
                                                        <?php
                                                          $backUrl = $_SERVER['REQUEST_URI'] ?? '';
                                                          $receiptUrl = url('admin/customers/txn_receipt_in.php?id=' . (int)$txn['id'] . '&payment_id=' . (int)$pl['id']);
                                                          if (defined('ALLOW_TXN_IN_FROM_COMPANY1') && ALLOW_TXN_IN_FROM_COMPANY1 === true) {
                                                            $receiptUrl = url('user/company1/txn_receipt_in.php?id=' . (int)$txn['id'] . '&customer_id=' . (int)$customer_id . '&payment_id=' . (int)$pl['id'] . '&back=' . rawurlencode($backUrl));
                                                          }
                                                        ?>
                                                        <a href="<?= h($receiptUrl) ?>" class="btn btn-xs btn-light">
                                                          <?= h(tt('admin.txn_in.view_receipt', 'View receipt')) ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <?php $backUrl = $_SERVER['REQUEST_URI'] ?? ''; ?>
                                                        <a href="<?= h(url('admin/customers/txn_view.php?customer_id=' . (int)$customer_id . '&id=' . (int)$txn['id'] . '&payment_id=' . (int)$pl['id'] . '&back=' . rawurlencode($backUrl))) ?>"
                                                            class="btn btn-xs btn-light">
                                                            <?= h(tt('admin.txn_in.view_receipt', 'View receipt')) ?>
                                                        </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span style="font-size:11px;color:#6b7280;"><?= h(tt('admin.txn_in.after_save', 'After save')) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align:center;">
                                                <button type="button" class="btn btn-xs btn-light btn-remove-line">✕</button>
                                            </td>
                                        </tr>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($rowIndex === 0): $rowIndex = 1; ?>
                        <script>
                            /* first row will be added by JS */
                        </script>
                    <?php endif; ?>
                    <input type="hidden" id="paymentNextIndex" value="<?= $rowIndex + 1 ?>">
                </div>

                <!-- Notes & Signatures -->
                <div class="form-section">
                    <div class="form-section-header">
                        <div>
                            <div class="form-section-title"><?= h(tt('admin.txn_in.notes_sign.title', 'Notes & Signatures')) ?></div>
                            <div class="form-section-desc"><?= h(tt('admin.txn_in.notes_sign.desc', 'Internal notes, attachments and signatures.')) ?></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="field-label"><?= h(tt('admin.txn_in.notes', 'Notes')) ?></label>
                        <textarea name="notes" class="form-control txn-notes-textarea"><?= h($txn['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group" style="margin-top:10px;">
                        <label class="field-label"><?= h(tt('admin.txn_in.txn_attach.label', 'Attachments (for this IN)')) ?></label>
                        <div class="attach-cell" data-notes-attach="1">
                            <button type="button" class="btn btn-xs btn-light btn-attach-add"><?= h(tt('admin.txn_in.attach.add', '+ Add')) ?></button>
                            <div class="attach-inputs"></div>

                            <?php if (!empty($txnAttachments)): ?>
                                <div class="attach-existing">
                                    <?= h(tt('admin.txn_in.attach.saved', 'Saved:')) ?>
                                    <?php
                                    $num = 0;
                                    foreach ($txnAttachments as $att):
                                        $num++;
                                        $hrefPath = upload_href($att['file_path'] ?? '');
                                        if ($hrefPath === '') continue;

                                        $text = trim((string)($att['file_name'] ?? ''));
                                        if ($text === '' || preg_match('/^(pay|txn)_\d+_/i', $text)) {
                                            $text = sprintf(tt('admin.txn_in.attach.default_name', 'Attachment %d'), $num);
                                        }
                                        $attId = (int)($att['id'] ?? 0);
                                    ?>
                                        <div class="attach-existing-item">
                                            <a href="<?= h(url($hrefPath)) ?>"><?= h($text) ?></a>
                                            <?php if ($attId > 0): ?>
                                                <label style="font-size:11px;color:#b91c1c;cursor:pointer;">
                                                    <input type="checkbox" name="delete_txn_att[]" value="<?= $attId ?>"> <?= h(tt('admin.txn_in.attach.delete', 'Delete')) ?>
                                                </label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                            <?= h(tt('admin.txn_in.txn_attach.help', 'Attach invoice, DO, contract, etc. (multiple files allowed).')) ?>
                        </div>
                    </div>

                    <?php if ((int)($txn['id'] ?? 0) > 0): ?>
                    <div class="form-group" style="margin-top:14px;">
                        <div style="font-weight:600;margin-bottom:6px;">Quotation / Invoice / DO</div>
                        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
                            <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=QUOTATION')) ?>" class="btn btn-light btn-sm">View Quotation</a>
                            <?php if ($flowTypeNow === 'NORMAL' || trim((string)($txn['invoice_no'] ?? '')) !== ''): ?>
                                <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=INVOICE')) ?>" class="btn btn-light btn-sm">View Invoice</a>
                                <a href="<?= h(url($docBase . '?id=' . (int)$txn['id'] . '&customer_id=' . $customer_id . '&doc=DO')) ?>" class="btn btn-light btn-sm">View DO</a>
                            <?php endif; ?>
                        </div>
                        <?php if ($hasColRequireSignQuotation || $hasColRequireSignInvoice || $hasColRequireSignDo): ?>
                        <div style="font-size:12px;color:#6b7280;margin-bottom:4px;">Require customer signature for:</div>
                        <div style="display:flex;flex-wrap:wrap;gap:14px;">
                            <?php if ($hasColRequireSignQuotation): ?>
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" name="require_sign_quotation" value="1" <?= !empty($txn['require_sign_quotation']) ? 'checked' : '' ?>>
                                    <span>Quotation</span>
                                </label>
                            <?php endif; ?>
                            <?php if ($hasColRequireSignInvoice): ?>
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" name="require_sign_invoice" value="1" <?= !empty($txn['require_sign_invoice']) ? 'checked' : '' ?>>
                                    <span>Invoice</span>
                                </label>
                            <?php endif; ?>
                            <?php if ($hasColRequireSignDo): ?>
                                <label style="display:flex;align-items:center;gap:6px;">
                                    <input type="checkbox" name="require_sign_do" value="1" <?= !empty($txn['require_sign_do']) ? 'checked' : '' ?>>
                                    <span>DO</span>
                                </label>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Our side -->
                    <div class="form-group" style="margin-top:12px;">
                        <div style="font-weight:600;margin-bottom:4px;"><?= h(tt('admin.txn_in.our.title', 'Our side (who receive)')) ?></div>
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="field-label"><?= h(tt('admin.txn_in.our.company', 'Our company')) ?></label>
                                <select name="payer_company_id" class="form-control">
                                    <option value=""><?= h(tt('admin.txn_in.our.company_ph', '— Select company —')) ?></option>
                                    <?php foreach ($payer_companies as $pc): ?>
                                        <?php
                                        $pcId  = (int)($pc['id'] ?? 0);
                                        $label = (string)($pc['name'] ?? '');
                                        if (!empty($pc['reg_no'])) $label .= ' · ' . $pc['reg_no'];
                                        ?>
                                        <option value="<?= $pcId ?>" <?= ((int)($txn['payer_company_id'] ?? 0) === $pcId) ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="field-label"><?= h(tt('admin.txn_in.our.staff', 'Our staff / signatory')) ?></label>
                                <select name="payer_staff_id" class="form-control">
                                    <option value=""><?= h(tt('admin.txn_in.our.staff_ph', '— Select staff —')) ?></option>
                                    <?php foreach ($payer_staff as $ps): ?>
                                        <?php
                                        $psId  = (int)($ps['id'] ?? 0);
                                        $label = (string)($ps['staff_name'] ?? '');
                                        if (!empty($ps['ic_no'])) $label .= ' · ' . $ps['ic_no'];
                                        ?>
                                        <option value="<?= $psId ?>" <?= ((int)($txn['payer_staff_id'] ?? 0) === $psId) ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                    <?= h(tt('admin.txn_in.our.staff_help', 'Select who receives / signs on our side. Used together with “Sign receive”.')) ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recipient signer -->
                    <div id="signer-block" style="margin-top:10px;">
                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label class="field-label"><?= h(tt('admin.txn_in.recipient.name', 'Recipient name (who signs)')) ?></label>
                                <input
                                    list="recipient_list"
                                    type="text"
                                    id="recipient_name"
                                    name="recipient_name"
                                    class="form-control"
                                    value="<?= h($txn['recipient_name'] ?? '') ?>"
                                    placeholder="<?= h(tt('admin.txn_in.recipient.name_ph', 'Type or pick from login users...')) ?>">
                                <datalist id="recipient_list">
                                    <?php foreach ($recipientOptions as $opt): ?>
                                        <option value="<?= h($opt) ?>"></option>
                                    <?php endforeach; ?>
                                    <?php foreach ($loginUsers as $lu): ?>
                                        <?php
                                        $nric  = trim((string)($lu['nric'] ?? ''));
                                        $label = (string)($lu['full_name'] ?? '') . ($nric !== '' ? ' (' . $nric . ')' : '');
                                        ?>
                                        <option value="<?= h($lu['full_name'] ?? '') ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <div class="form-group">
                                <label class="field-label"><?= h(tt('admin.txn_in.recipient.nric', 'Recipient NRIC / passport')) ?></label>
                                <input
                                    type="text"
                                    id="recipient_nric"
                                    name="recipient_nric"
                                    class="form-control"
                                    value="<?= h($txn['recipient_nric'] ?? '') ?>">
                            </div>
                        </div>

                        <?php if ($loginUsers): ?>
                            <div style="font-size:11px;color:#6b7280;margin-top:4px;">
                                <?= h(tt('admin.txn_in.recipient.tip', 'When you pick a login user name, NRIC will auto-fill. You can also type both name and NRIC manually.')) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-grid form-grid-2" style="margin-top:10px;">
                        <div class="form-group">
                            <label class="field-label" style="margin-bottom:4px;">
                                Our side on receipt
                            </label>
                            <div style="font-size:12px;margin-bottom:4px;">
                                <?php
                                $curMode = strtoupper((string)($txn['sign_mode'] ?? 'SIGN_AND_CHOP'));
                                if (!in_array($curMode, ['CHOP_ONLY', 'SIGN_ONLY', 'SIGN_AND_CHOP'], true)) {
                                    $curMode = 'SIGN_AND_CHOP';
                                }
                                ?>
                                <label style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                                    <input type="radio" name="sign_mode" value="CHOP_ONLY" <?= $curMode === 'CHOP_ONLY' ? 'checked' : '' ?>>
                                    <span>Company chop only (no signature)</span>
                                </label>
                                <label style="display:flex;align-items:center;gap:6px;margin-bottom:3px;">
                                    <input type="radio" name="sign_mode" value="SIGN_AND_CHOP" <?= $curMode === 'SIGN_AND_CHOP' ? 'checked' : '' ?>>
                                    <span>Signature + company chop (default)</span>
                                </label>
                            </div>
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                If you choose “chop only”, our signature is not required, but the company stamp will still show on the receipt.
                            </div>
                        </div>

                        <div class="form-group" id="sign-payer-toggle">
                            <label class="switch-label">
                                <span class="switch-text"><?= h(tt('admin.txn_in.sign.need_customer', 'Require customer signature')) ?></span>
                                <label class="switch">
                                    <input type="checkbox" name="sign_payer" value="1" <?= ($txn['sign_payer'] ?? 0) ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </label>
                            <div style="font-size:11px;color:#6b7280;margin-top:2px;">
                                <?= h(tt('admin.txn_in.sign.need_customer_help', 'Checked = customer must sign (name / NRIC above). Unchecked = no signature required and status will not be locked.')) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="form-footer-row" style="display:flex;justify-content:space-between;align-items:center;margin-top:18px;gap:8px;">
                    <div>
                        <?php
                          $cancelUrl = url('admin/customers/txn_list.php?customer_id=' . $customer_id);
                          if (defined('ALLOW_TXN_IN_FROM_COMPANY1') && ALLOW_TXN_IN_FROM_COMPANY1 === true) {
                            $cancelUrl = url('user/company1/txn_list.php?customer_id=' . $customer_id);
                          }
                        ?>
                        <a href="<?= h($cancelUrl) ?>" class="btn btn-light">
                            <?= h(tt('admin.txn_in.btn.cancel', 'Cancel')) ?>
                        </a>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <?= h(tt('admin.txn_in.btn.save', 'Save')) ?>
                        </button>
                    </div>
                </div>

            </form>
        </div>
    </div>
</div>

<script>
    const loginUsers = <?= json_encode($loginUsersJs) ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const tbody = document.getElementById('paymentTableBody');
        const btnAdd = document.getElementById('btnAddPayment');
        const nextIndexEl = document.getElementById('paymentNextIndex');
        const inKindSel = document.getElementById('in_kind_select');
        const signerBlock = document.getElementById('signer-block');
        const signPayerBlock = document.getElementById('sign-payer-toggle');
        const invoiceNoInput = document.getElementById('invoice_no_input');
        const titleInput = document.getElementById('title_input');

        const recipientNameInput = document.getElementById('recipient_name');
        const recipientNricInput = document.getElementById('recipient_nric');

        if (recipientNameInput && recipientNricInput && Array.isArray(loginUsers)) {
            function syncRecipientNric() {
                const v = (recipientNameInput.value || '').trim();
                if (!v) return;
                const found = loginUsers.find(u => u.name === v);
                if (found) recipientNricInput.value = found.nric || '';
            }
            recipientNameInput.addEventListener('change', syncRecipientNric);
            recipientNameInput.addEventListener('blur', syncRecipientNric);
        }

        function initAttachCell(cell) {
            if (!cell || cell.dataset.attachInited === '1') return;

            const inputsWrap = cell.querySelector('.attach-inputs');
            const addBtn = cell.querySelector('.btn-attach-add');
            if (!inputsWrap || !addBtn) return;

            cell.dataset.attachInited = '1';

            const payIndex = cell.getAttribute('data-pay-index');
            const isNotes = cell.getAttribute('data-notes-attach') === '1';

            function addInputRow() {
                const row = document.createElement('div');
                row.className = 'attach-input-row';

                const input = document.createElement('input');
                input.type = 'file';
                input.className = 'form-control attach-file-input';

                if (isNotes) input.name = 'notes_attachments[]';
                else if (payIndex) input.name = 'pay[' + payIndex + '][attachments][]';

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'attach-remove';
                btn.textContent = '×';
                btn.addEventListener('click', function() {
                    row.remove();
                });

                row.appendChild(input);
                row.appendChild(btn);
                inputsWrap.appendChild(row);
            }

            addBtn.addEventListener('click', addInputRow);
        }

        function initAllAttachCells() {
            document.querySelectorAll('.attach-cell').forEach(initAttachCell);
        }

        function bindRowEvents(root) {
            const rows = (root.tagName === 'TR') ? [root] : root.querySelectorAll('tr');

            rows.forEach(function(row) {
                const curInput = row.querySelector('.currency-input');
                const fxInput = row.querySelector('.fx-input');

                if (curInput && fxInput) {
                    const syncFxVisibility = function() {
                        let v = (curInput.value || '').toUpperCase().trim();
                        if (!v) v = (<?= json_encode(strtoupper($txn['currency'] ?? 'MYR')) ?>);
                        if (v === 'MYR') {
                            fxInput.style.display = 'none';
                            fxInput.value = '';
                        } else {
                            fxInput.style.display = '';
                        }
                    };
                    curInput.addEventListener('input', syncFxVisibility);
                    curInput.addEventListener('blur', syncFxVisibility);
                    syncFxVisibility();
                }

                const btnRemove = row.querySelector('.btn-remove-line');
                if (btnRemove) {
                    btnRemove.addEventListener('click', function() {
                        if (row.parentNode) row.parentNode.removeChild(row);
                    });
                }
            });
        }

        function addNewPaymentRow() {
            if (!tbody || !nextIndexEl) return;
            const idx = parseInt(nextIndexEl.value || '1', 10);
            const tr = document.createElement('tr');
            tr.setAttribute('data-row-index', idx);

            tr.innerHTML = `
          <td>
            <input type="hidden" name="pay[${idx}][id]" value="">
            <input type="text" name="pay[${idx}][or_no]" class="form-control" placeholder="<?= h(tt('admin.txn_in.or_ph', 'Auto if empty')) ?>">
          </td>
          <td><input type="date" name="pay[${idx}][pay_date]" class="form-control"></td>
          <td><input type="number" step="0.01" min="0" name="pay[${idx}][amount]" class="form-control"></td>
          <td>
            <select name="pay[${idx}][bank_account_id]" class="form-control">
              <option value=""><?= h(tt('admin.txn_in.select', '— Select —')) ?></option>
              <?php foreach ($bankRows as $b): ?>
                <option value="<?= (int)$b['id'] ?>"><?= h(bank_label($b)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="text" name="pay[${idx}][currency]" class="form-control currency-input" placeholder="<?= h($txn['currency'] ?? 'MYR') ?>">
          </td>
          <td>
            <input type="number" step="0.000001" min="0" name="pay[${idx}][fx_rate]" class="form-control fx-input" style="display:none;" placeholder="<?= h(tt('admin.txn_in.fx_ph', 'e.g. 4.700000')) ?>">
          </td>
          <td>
            <div class="attach-cell" data-pay-index="${idx}">
              <button type="button" class="btn btn-xs btn-light btn-attach-add"><?= h(tt('admin.txn_in.attach.add', '+ Add')) ?></button>
              <div class="attach-inputs"></div>
            </div>
          </td>
          <td><span style="font-size:11px;color:#6b7280;"><?= h(tt('admin.txn_in.after_save', 'After save')) ?></span></td>
          <td style="text-align:center;">
            <button type="button" class="btn btn-xs btn-light btn-remove-line">✕</button>
          </td>
        `;

            tbody.appendChild(tr);
            bindRowEvents(tr);
            initAllAttachCells();
            nextIndexEl.value = idx + 1;
        }

        if (tbody && tbody.children.length === 0) addNewPaymentRow();
        else if (tbody) bindRowEvents(tbody);

        initAllAttachCells();
        if (btnAdd) btnAdd.addEventListener('click', addNewPaymentRow);

        // ✅ Title auto logic (smart)
        function defaultTitleByKind(kind) {
            kind = (kind || 'INVOICE').toUpperCase();
            if (kind === 'RETURN') return 'Repayment';
            if (kind === 'BONUS') return 'Bonus';
            return 'Invoice';
        }

        function isDefaultTitle(v) {
            v = (v || '').trim();
            return (v === '' || v === 'Invoice' || v === 'Repayment' || v === 'Bonus');
        }
        let lastAutoTitle = titleInput ? (titleInput.value || '').trim() : '';

        function applyInKindUI() {
            if (!inKindSel) return;
            const val = (inKindSel.value || 'INVOICE').toUpperCase();
            const isInvoice = (val === 'INVOICE');

            if (invoiceNoInput) {
                if (isInvoice) {
                    invoiceNoInput.readOnly = false;
                    invoiceNoInput.style.background = '';
                } else {
                    invoiceNoInput.readOnly = true;
                    invoiceNoInput.style.background = '#f9fafb';
                    invoiceNoInput.value = '';
                }
            }

            if (titleInput) {
                const cur = (titleInput.value || '').trim();
                if (isDefaultTitle(cur) || cur === lastAutoTitle) {
                    const next = defaultTitleByKind(val);
                    titleInput.value = next;
                    lastAutoTitle = next;
                }
            }

            if (signerBlock) signerBlock.style.display = '';
            if (signPayerBlock) signPayerBlock.style.display = '';
        }

        if (inKindSel) {
            inKindSel.addEventListener('change', applyInKindUI);
            applyInKindUI();
        }
    });
</script>

<?php if (!$allowFromCompany1) include __DIR__ . '/../include/footer.php'; ?>
