<?php
// public/admin/customers/customer_detail_export.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/bootstrap.php';

require_login();
require_admin();
if (function_exists('require_perm')) {
    require_perm('TXN.V');
}

$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$hasT = function_exists('t');

$cid = (int)($_GET['customer_id'] ?? 0);
if ($cid <= 0) {
    http_response_code(400);
    exit('Missing customer_id');
}

// 载入 customer
$st = $pdo->prepare("SELECT * FROM customers WHERE id = :id");
$st->execute([':id' => $cid]);
$customer = $st->fetch();
if (!$customer) {
    http_response_code(404);
    exit('Customer not found');
}

/**
 * 银行 label 跟 txn_edit_in.php 一样
 */
function bank_label(array $b): string {
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

// 载入 company_bank_accounts（跟 txn_list 一样）
$bankRows = $pdo->query("
    SELECT id, bank_code, account_name, account_no, currency
    FROM company_bank_accounts
    WHERE is_active = 1
    ORDER BY bank_code, account_name, account_no, id
")->fetchAll();

$bankAccMap = [];
foreach ($bankRows as $b) {
    $bankAccMap[(int)$b['id']] = $b;
}

// ------- 过滤参数（跟 txn_list 一样） ------- //
$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
$type      = $_GET['type']      ?? 'ALL';      // ALL / IN / OUT
$status    = $_GET['status']    ?? 'ALL';      // ALL / DRAFT / SENT / PENDING / CONFIRMED
$method    = $_GET['method']    ?? 'ALL';      // ALL / BANK_<id> / ALLOCATE
$view      = $_GET['view']      ?? 'AFTER';    // AFTER / BEFORE（export 里一样保留）
$q         = trim($_GET['q']    ?? '');

if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

$type   = strtoupper((string)$type);
$status = strtoupper((string)$status);

// 解析 method → bank filter / allocate filter（跟 txn_list 一样）
$bankFilterId = 0;
$onlyContra   = false;

if (is_string($method) && strpos($method, 'BANK_') === 0) {
    $bankFilterId = (int)substr($method, 5);
    if ($bankFilterId <= 0) $bankFilterId = 0;
} elseif ($method === 'ALLOCATE') {
    $onlyContra = true;
}

$where  = ["t.customer_id = :cid"];
$params = [':cid' => $cid];

if ($date_from !== '') {
    $where[]       = "DATE(t.txn_date) >= :df";
    $params[':df'] = $date_from;
}
if ($date_to !== '') {
    $where[]       = "DATE(t.txn_date) <= :dt";
    $params[':dt'] = $date_to;
}

if ($type === 'IN') {
    $where[] = "t.txn_type = 'IN'";
} elseif ($type === 'OUT') {
    $where[] = "t.txn_type = 'OUT'";
}

if (in_array($status, ['DRAFT','SENT','PENDING','CONFIRMED'], true)) {
    $where[]           = "t.status = :status";
    $params[':status'] = $status;
}

if ($onlyContra) {
    $where[] = "(t.is_contra = 1)";
} elseif ($bankFilterId > 0) {
    $where[] = "EXISTS (
        SELECT 1
        FROM customer_txn_payments p
        WHERE p.customer_txn_id = t.id
          AND p.bank_account_id = :bank_filter_id
    )";
    $params[':bank_filter_id'] = $bankFilterId;
}

if ($q !== '') {
    $where[]      = "(t.title LIKE :q OR t.ref_no LIKE :q OR t.remark LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$whereSql = implode(' AND ', $where);

// ------- Summary（跟 txn_list 一样，全部 AFTER contra） ------- //
$sumSql = "
SELECT
  SUM(CASE
        WHEN t.txn_type = 'IN'
         AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%BONUS%' AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%RETURN%' AND UPPER(COALESCE(t.in_kind,'')) NOT LIKE '%REPAY%'
        THEN (t.amount - COALESCE(t.allocated_amount,0))
        ELSE 0
      END) AS total_in_normal,

  SUM(CASE
        WHEN t.txn_type = 'OUT'
         AND (t.is_contra IS NULL OR t.is_contra = 0)
         AND UPPER(COALESCE(t.out_kind,'')) <> 'LOAN'
        THEN t.amount
        ELSE 0
      END) AS total_out_normal,

  SUM(CASE
        WHEN t.txn_type = 'IN'
         AND UPPER(COALESCE(t.in_kind,'')) LIKE '%BONUS%'
        THEN (t.amount - COALESCE(t.allocated_amount,0))
        ELSE 0
      END) AS bonus_total,

  SUM(CASE
        WHEN t.txn_type = 'IN'
         AND (
              (UPPER(COALESCE(t.in_kind,'')) LIKE '%RETURN%' OR UPPER(COALESCE(t.in_kind,'')) LIKE '%REPAY%')
              OR (
                   (COALESCE(t.order_total,0) = 0)
                   AND (t.amount > 0)
                   AND (
                        t.title LIKE '%Repayment%' OR t.title LIKE '%repayment%'
                        OR t.title LIKE '%Return%' OR t.title LIKE '%return%'
                       )
                 )
            )
        THEN (t.amount - COALESCE(t.allocated_amount,0))
        ELSE 0
      END) AS repay_total,

  SUM(CASE
        WHEN t.txn_type = 'OUT'
         AND (t.is_contra IS NULL OR t.is_contra = 0)
         AND UPPER(COALESCE(t.out_kind,'')) = 'LOAN'
        THEN t.amount
        ELSE 0
      END) AS loan_total

FROM customer_txn t
WHERE $whereSql
";

$st = $pdo->prepare($sumSql);
$st->execute($params);
$sumRow = $st->fetch() ?: [];

$total_in_normal  = (float)($sumRow['total_in_normal']  ?? 0);
$total_out_normal = (float)($sumRow['total_out_normal'] ?? 0);
$bonus_total      = (float)($sumRow['bonus_total']      ?? 0);
$repay_total      = (float)($sumRow['repay_total']      ?? 0);
$loan_total       = (float)($sumRow['loan_total']       ?? 0);

$net_normal      = $total_in_normal - $total_out_normal;
$return_balance  = $loan_total - $repay_total;

$summary_in      = $total_in_normal + $bonus_total + $repay_total;
$summary_out     = $total_out_normal + $loan_total;
$summary_net     = $summary_in - $summary_out;

// ------- 主列表 ------- //
$sql = "
    SELECT
      t.*,
      COALESCE(t.txn_date, DATE(t.created_at)) AS txn_effective_date
    FROM customer_txn t
    WHERE $whereSql
    ORDER BY t.txn_date DESC, t.id DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

// ------- payer maps + payment（跟 txn_list 一样） ------- //
$payerCompanyIds = [];
$payerStaffIds   = [];
$txnIds          = [];
foreach ($rows as $r) {
    if (!empty($r['payer_company_id'])) $payerCompanyIds[(int)$r['payer_company_id']] = true;
    if (!empty($r['payer_staff_id']))   $payerStaffIds[(int)$r['payer_staff_id']] = true;
    $txnIds[(int)$r['id']] = true;
}

$payerCompaniesMap = [];
$payerStaffMap     = [];

if ($payerCompanyIds) {
    $ids = array_keys($payerCompanyIds);
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, name, reg_no FROM payer_companies WHERE id IN ($inClause)");
        $st->execute($ids);
        while ($row = $st->fetch()) $payerCompaniesMap[(int)$row['id']] = $row;
    } catch (Throwable $e) {
        $payerCompaniesMap = [];
    }
}

if ($payerStaffIds) {
    $ids = array_keys($payerStaffIds);
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT id, staff_name, ic_no FROM payer_company_staff WHERE id IN ($inClause)");
        $st->execute($ids);
        while ($row = $st->fetch()) $payerStaffMap[(int)$row['id']] = $row;
    } catch (Throwable $e) {
        $payerStaffMap = [];
    }
}

// payment details
$paidRawByTxn = [];   // tid => sum(amount)
$bankIdsByTxn = [];   // tid => [bank_id => true]

if ($txnIds) {
    $ids = array_keys($txnIds);
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("
            SELECT customer_txn_id, bank_account_id, amount
            FROM customer_txn_payments
            WHERE customer_txn_id IN ($inClause)
            ORDER BY customer_txn_id, id
        ");
        $st->execute($ids);
        while ($row = $st->fetch()) {
            $tid = (int)$row['customer_txn_id'];
            $amt = (float)($row['amount'] ?? 0);

            if (!isset($paidRawByTxn[$tid])) $paidRawByTxn[$tid] = 0.0;
            $paidRawByTxn[$tid] += $amt;

            $bid = (int)($row['bank_account_id'] ?? 0);
            if ($bid > 0) {
                if (!isset($bankIdsByTxn[$tid])) $bankIdsByTxn[$tid] = [];
                $bankIdsByTxn[$tid][$bid] = true;
            }
        }
    } catch (Throwable $e) {
        $paidRawByTxn = [];
        $bankIdsByTxn = [];
    }
}

// ------- Pending total（跟 txn_list 一样） ------- //
$pending_total = 0.0;
foreach ($rows as $r) {
    if (($r['txn_type'] ?? '') !== 'IN') continue;

    $inKind = strtoupper((string)($r['in_kind'] ?? 'INVOICE'));
    if ($inKind !== 'INVOICE') continue;

    if (($r['status'] ?? '') === 'CONFIRMED') continue;

    $tid         = (int)$r['id'];
    $order_total = (float)($r['order_total'] ?? 0);
    $paid_raw    = (float)($paidRawByTxn[$tid] ?? 0);
    $unpaid      = $order_total - $paid_raw;

    if ($unpaid > 0.0001 && (int)($r['is_contra'] ?? 0) === 0) {
        $pending_total += $unpaid;
    }
}

// ------- Contra summary（跟 txn_list 一样） ------- //
$normalRows     = [];
$contraSumByKey = []; // date#payer_company_id => amount

foreach ($rows as $r) {
    $isContra = (int)($r['is_contra'] ?? 0) === 1;

    if ($isContra) {
        $dtRaw = '';
        if (!empty($r['txn_date'])) $dtRaw = (string)$r['txn_date'];
        elseif (!empty($r['created_at'])) $dtRaw = substr((string)$r['created_at'], 0, 10);

        $dateKey = substr($dtRaw, 0, 10);
        $pcId    = (int)($r['payer_company_id'] ?? 0);

        if ($dateKey === '' || $pcId <= 0) {
            $normalRows[] = $r;
            continue;
        }

        $key = $dateKey . '#' . $pcId;
        if (!isset($contraSumByKey[$key])) {
            $contraSumByKey[$key] = [
                'date'   => $dateKey,
                'pc_id'  => $pcId,
                'amount' => 0.0,
            ];
        }
        $contraSumByKey[$key]['amount'] += (float)($r['amount'] ?? 0);
    } else {
        $normalRows[] = $r;
    }
}

$summaryRows  = [];
$baseCurrency = $customer['currency'] ?? 'MYR';

foreach ($contraSumByKey as $info) {
    $summaryRows[] = [
        '_is_summary_contra' => true,
        'txn_date'           => $info['date'],
        'created_at'         => $info['date'] . ' 00:00:00',
        'txn_type'           => 'OUT',
        'method'             => 'OTHER',
        'title'              => $hasT ? t('admin.customer_txn.list.contra_summary_title', [], 'Transaction allocate (contra total)') : 'Transaction allocate (contra total)',
        'amount'             => $info['amount'],
        'currency'           => $baseCurrency,
        'status'             => 'CONFIRMED',
        'is_contra'          => 1,
        'payer_company_id'   => $info['pc_id'],
        'id'                 => 0,
    ];
}

$rows = array_merge($normalRows, $summaryRows);

usort($rows, function (array $a, array $b): int {
    $da = '';
    $db = '';
    if (!empty($a['txn_date'])) $da = substr((string)$a['txn_date'], 0, 10);
    elseif (!empty($a['created_at'])) $da = substr((string)$a['created_at'], 0, 10);

    if (!empty($b['txn_date'])) $db = substr((string)$b['txn_date'], 0, 10);
    elseif (!empty($b['created_at'])) $db = substr((string)$b['created_at'], 0, 10);

    if ($da === $db) return 0;
    return $da < $db ? 1 : -1;
});

// ------- Enrich rows（用于 export：amount/pending/balance） ------- //
$enrichedRows = [];
$runningBalance = 0.0;

// 你 export 要的 totals（沿用你原本那 5 行的概念）
$totalIn        = 0.0; // INVOICE 的 invoice total 合计（跟你原 export）
$totalOut       = 0.0; // OUT amount 合计
$totalPaymentIn = 0.0; // sum(payments)
$totalPending   = 0.0; // sum(unpaid invoice)

foreach ($rows as $r) {
    $isSummaryContra = !empty($r['_is_summary_contra']);

    $txnType  = (string)($r['txn_type'] ?? '');
    $date     = $r['txn_date'] ?? substr((string)($r['created_at'] ?? ''), 0, 10);

    // contra summary 行：当成 OUT，amount 正数显示，但 signedAmount = -amount 才会影响 balance
    if ($isSummaryContra) {
        $displayCurrency = (string)($r['currency'] ?? $baseCurrency);
        $displayAmount   = (float)($r['amount'] ?? 0);

        $signedAmount = -$displayAmount;
        $runningBalance += $signedAmount;

        $enrichedRows[] = [
            'date'           => $date,
            'txn_type'        => 'OUT',
            'method'          => 'ALLOCATE',
            'status'          => 'CONFIRMED',
            'currency'        => $displayCurrency,
            'display_amount'  => $displayAmount,
            'signed_amount'   => $signedAmount,
            'unpaid'          => 0.0,
            'balance'         => $runningBalance,
            'ref_no'          => '',
            'title'           => (string)($r['title'] ?? ''),
            'notes'           => '',
        ];
        // contra summary 不计入 TOTAL OUT（你原本 totals 是真实 payout；contra 是 allocate）
        continue;
    }

    $tid       = (int)($r['id'] ?? 0);
    $inKind    = strtoupper((string)($r['in_kind'] ?? 'INVOICE'));
    $outKind   = strtoupper((string)($r['out_kind'] ?? 'NORMAL'));
    $isContra  = (int)($r['is_contra'] ?? 0) === 1;

    $order_total   = (float)($r['order_total'] ?? 0);
    $orderCurrency = (string)($r['order_currency'] ?? '');
    $txnCurrency   = (string)($r['currency'] ?: ($customer['currency'] ?? 'MYR'));

    // ✅ txn_list 显示规则：INVOICE 用 order_total，其它 IN 用 amount，OUT 用 amount
    if ($txnType === 'IN') {
        if ($inKind === 'INVOICE') {
            $displayAmount   = $order_total;
            $displayCurrency = $orderCurrency !== '' ? $orderCurrency : $txnCurrency;
        } else {
            $displayAmount   = (float)($r['amount'] ?? 0);
            $displayCurrency = $txnCurrency;
        }
    } else { // OUT
        $displayAmount   = (float)($r['amount'] ?? 0);
        $displayCurrency = $txnCurrency;
    }

    // ✅ paid_raw：INVOICE 用 payments；非 INVOICE IN（RETURN/BONUS）视为 paid=amount
    $paid_raw = 0.0;
    if ($txnType === 'IN' && $inKind !== 'INVOICE') {
        $paid_raw = (float)($r['amount'] ?? 0);
    } else {
        $paid_raw = (float)($paidRawByTxn[$tid] ?? 0);
    }

    // ✅ unpaid：只对 INVOICE、且非 CONFIRMED
    $unpaid = 0.0;
    if ($txnType === 'IN' && $inKind === 'INVOICE') {
        $unpaid = max(0.0, $order_total - $paid_raw);
        if (($r['status'] ?? '') === 'CONFIRMED') $unpaid = 0.0;
        if (!$isContra && $unpaid > 0.0001) $totalPending += $unpaid;
    }

    // ✅ signedAmount：IN 正，OUT 负（用于 balance）
    $signedAmount = ($txnType === 'OUT') ? -$displayAmount : $displayAmount;
    $runningBalance += $signedAmount;

    // totals（沿用你原本 export 的 totals 逻辑）
    if ($txnType === 'IN' && $inKind === 'INVOICE') {
        $totalIn += $order_total;
        $totalPaymentIn += $paid_raw; // payments only
    } elseif ($txnType === 'OUT' && !$isContra) {
        // 真实 payout 才算 totalOut（contra 不算）
        $totalOut += $displayAmount;
    }

    // method label（export 简化：跟 txn_list 一样能带 bank）
    $methodLabel = (string)($r['method'] ?? '');
    $bankLabels = [];

    if ($txnType === 'IN') {
        if ($inKind === 'INVOICE') {
            if (!empty($bankIdsByTxn[$tid])) {
                foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                    if (isset($bankAccMap[$bid])) $bankLabels[] = bank_label($bankAccMap[$bid]);
                }
            }
        } else {
            $m = strtoupper((string)($r['method'] ?? ($r['pay_source_type'] ?? '')));
            $methodLabel = $m !== '' ? $m : $methodLabel;
        }
    } else {
        $outBankId = (int)($r['bank_account_id'] ?? 0);
        if ($outBankId > 0 && isset($bankAccMap[$outBankId])) $bankLabels[] = bank_label($bankAccMap[$outBankId]);
        if (!empty($bankIdsByTxn[$tid])) {
            foreach (array_keys($bankIdsByTxn[$tid]) as $bid) {
                if (isset($bankAccMap[$bid])) $bankLabels[] = bank_label($bankAccMap[$bid]);
            }
        }
    }

    $bankLabels = array_values(array_unique(array_filter($bankLabels, 'strlen')));
    if ($bankLabels) $methodLabel = implode(' / ', $bankLabels);
    if ($methodLabel === '') $methodLabel = '-';

    $enrichedRows[] = [
        'date'           => $date,
        'txn_type'       => $txnType,
        'method'         => $methodLabel,
        'status'         => (string)($r['status'] ?? ''),
        'currency'       => $displayCurrency,
        'display_amount' => $displayAmount,
        'signed_amount'  => $signedAmount,
        'unpaid'         => $unpaid,
        'balance'        => $runningBalance,
        'ref_no'         => (string)($r['ref_no'] ?? ''),
        'title'          => (string)($r['title'] ?? ''),
        'notes'          => (string)($r['notes'] ?? ''),
    ];
}

// 你原 export 的 net（INVOICE total - OUT payout）
$net = $totalIn - $totalOut;

// Audit
if (function_exists('audit_log')) {
    audit_log(
        $pdo,
        'REPORT.CUSTOMER_DETAIL.EXPORT',
        [
            'description' => 'Export customer detail report',
            'date_from'   => $date_from ?: null,
            'date_to'     => $date_to ?: null,
            'type'        => $type,
            'status'      => $status,
            'method'      => $method,
            'view'        => $view,
            'q'           => ($q !== '') ? $q : null,
        ],
        'report_customer_detail',
        $cid
    );
}

$nowSlug      = date('Ymd_His');
$customerCode = $customer['code'] ?? ('cust_'.$cid);
$customerCode = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $customerCode);
$filenameBase = "customer_detail_{$customerCode}_{$nowSlug}";

// ---------------- CSV ----------------
if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filenameBase.'.csv"');
    header('Cache-Control: max-age=0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $title = 'Customer Detail Report: '.trim(($customer['name'] ?? '').' ('.($customer['code'] ?? '').')');
    fputcsv($out, [$title]);
    fputcsv($out, ['Exported at: '.date('Y-m-d H:i:s')]);
    fputcsv($out, []);

    // ✅ 把 txn_list 的 summary 也写进 export 头部（你要 “export 要有 txnlist 里面的东西”）
    fputcsv($out, ['SUMMARY (AFTER CONTRA)']);
    fputcsv($out, ['Total IN (normal)', number_format($total_in_normal, 2, '.', '')]);
    fputcsv($out, ['Total OUT (normal)', number_format($total_out_normal, 2, '.', '')]);
    fputcsv($out, ['Net (normal)', number_format($net_normal, 2, '.', '')]);
    fputcsv($out, ['Return (loan - repay)', number_format($return_balance, 2, '.', '')]);
    fputcsv($out, ['Total BONUS', number_format($bonus_total, 2, '.', '')]);
    fputcsv($out, ['Summary IN', number_format($summary_in, 2, '.', '')]);
    fputcsv($out, ['Summary OUT', number_format($summary_out, 2, '.', '')]);
    fputcsv($out, ['Summary NET', number_format($summary_net, 2, '.', '')]);
    fputcsv($out, []);

    $header = [
        'Date',
        'Type',
        'Method',
        'Status',
        'Currency',
        'Amount',          // IN 正数，OUT 负数
        'Balance',         // running balance
        'Pending payment', // 每一行 unpaid（INVOICE 才有）
        'Ref No',
        'Title',
        'Notes',
    ];
    fputcsv($out, $header);

    foreach ($enrichedRows as $er) {
        fputcsv($out, [
            $er['date'],
            $er['txn_type'],
            $er['method'],
            $er['status'],
            $er['currency'],
            number_format((float)$er['signed_amount'], 2, '.', ''),
            number_format((float)$er['balance'], 2, '.', ''),
            number_format((float)$er['unpaid'], 2, '.', ''),
            $er['ref_no'],
            $er['title'],
            $er['notes'],
        ]);
    }

    // totals（保留你原本 5 行）
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', 'TOTAL PENDING',    number_format($totalPending,   2, '.', ''), '', '', '', '', '']);
    fputcsv($out, ['', '', '', '', 'TOTAL PAYMENT IN', number_format($totalPaymentIn,2, '.', ''), '', '', '', '', '']);
    fputcsv($out, ['', '', '', '', 'TOTAL IN',         number_format($totalIn,       2, '.', ''), '', '', '', '', '']);
    fputcsv($out, ['', '', '', '', 'TOTAL OUT',        number_format(-$totalOut,     2, '.', ''), '', '', '', '', '']);
    fputcsv($out, ['', '', '', '', 'NET',              number_format($net,           2, '.', ''), '', '', '', '', '']);

    fclose($out);
    exit;
}

// ---------------- XLSX ----------------
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Customer Detail');

$title = 'Customer Detail Report';
$sub1  = 'Customer: '.trim(($customer['code'] ?? '').' · '.($customer['name'] ?? ''));
$sub2  = 'Exported at: '.date('Y-m-d H:i:s');

$sheet->setCellValue('A1', $title);
$sheet->mergeCells('A1:K1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A2', $sub1);
$sheet->mergeCells('A2:K2');
$sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$sheet->setCellValue('A3', $sub2);
$sheet->mergeCells('A3:K3');
$sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// ✅ Summary block（写在表格前）
$sheet->setCellValue('A5', 'SUMMARY (AFTER CONTRA)');
$sheet->mergeCells('A5:K5');
$sheet->getStyle('A5')->getFont()->setBold(true);
$sheet->getStyle('A5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');

$sumStart = 6;
$sumLines = [
    ['Total IN (normal)', $total_in_normal],
    ['Total OUT (normal)', $total_out_normal],
    ['Net (normal)', $net_normal],
    ['Return (loan - repay)', $return_balance],
    ['Total BONUS', $bonus_total],
    ['Summary IN', $summary_in],
    ['Summary OUT', $summary_out],
    ['Summary NET', $summary_net],
];
$i = 0;
foreach ($sumLines as $line) {
    $sheet->setCellValue("A".($sumStart+$i), $line[0]);
    $sheet->setCellValue("B".($sumStart+$i), (float)$line[1]);
    $i++;
}
$sheet->getStyle("B{$sumStart}:B".($sumStart+count($sumLines)-1))
      ->getNumberFormat()->setFormatCode('#,##0.00;[Red]-#,##0.00');

$headerRow = $sumStart + count($sumLines) + 2;

// headers
$headers = [
    'A' => 'Date',
    'B' => 'Type',
    'C' => 'Method',
    'D' => 'Status',
    'E' => 'Currency',
    'F' => 'Amount',
    'G' => 'Balance',
    'H' => 'Pending payment',
    'I' => 'Ref No',
    'J' => 'Title',
    'K' => 'Notes',
];
foreach ($headers as $col => $text) {
    $sheet->setCellValue($col.$headerRow, $text);
}
$sheet->getStyle("A{$headerRow}:K{$headerRow}")->getFont()->setBold(true);
$sheet->getStyle("A{$headerRow}:K{$headerRow}")
      ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle("A{$headerRow}:K{$headerRow}")
      ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE5E7EB');

$rowIndex = $headerRow + 1;

foreach ($enrichedRows as $er) {
    $sheet->setCellValue("A{$rowIndex}", $er['date']);
    $sheet->setCellValue("B{$rowIndex}", $er['txn_type']);
    $sheet->setCellValue("C{$rowIndex}", $er['method']);
    $sheet->setCellValue("D{$rowIndex}", $er['status']);
    $sheet->setCellValue("E{$rowIndex}", $er['currency']);
    $sheet->setCellValue("F{$rowIndex}", (float)$er['signed_amount']);
    $sheet->setCellValue("G{$rowIndex}", (float)$er['balance']);
    $sheet->setCellValue("H{$rowIndex}", (float)$er['unpaid']);
    $sheet->setCellValue("I{$rowIndex}", $er['ref_no']);
    $sheet->setCellValue("J{$rowIndex}", $er['title']);
    $sheet->setCellValue("K{$rowIndex}", $er['notes']);
    $rowIndex++;
}

// totals
$sumRow = $rowIndex + 1;

$sheet->setCellValue("E{$sumRow}", 'TOTAL PENDING');
$sheet->setCellValue("F{$sumRow}", (float)$totalPending);

$sheet->setCellValue("E".($sumRow+1), 'TOTAL PAYMENT IN');
$sheet->setCellValue("F".($sumRow+1), (float)$totalPaymentIn);

$sheet->setCellValue("E".($sumRow+2), 'TOTAL IN');
$sheet->setCellValue("F".($sumRow+2), (float)$totalIn);

$sheet->setCellValue("E".($sumRow+3), 'TOTAL OUT');
$sheet->setCellValue("F".($sumRow+3), (float)(-$totalOut));

$sheet->setCellValue("E".($sumRow+4), 'NET');
$sheet->setCellValue("F".($sumRow+4), (float)$net);

$sheet->getStyle("E{$sumRow}:F".($sumRow+4))->getFont()->setBold(true);

// number format
$sheet->getStyle("F".($headerRow+1).":H".($sumRow+4))
      ->getNumberFormat()
      ->setFormatCode('#,##0.00;[Red]-#,##0.00');

// autosize
foreach (range('A','K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// borders
$lastRow = max($rowIndex - 1, $headerRow);
$sheet->getStyle("A{$headerRow}:K{$lastRow}")
      ->getBorders()->getAllBorders()
      ->setBorderStyle(Border::BORDER_THIN);

$sheet->freezePane('A'.($headerRow+1));

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filenameBase.'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
